using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using System.Windows.Automation;
using WpfPoint = System.Windows.Point;
using WpfRect = System.Windows.Rect;

namespace MoonYa.Services
{
    /// <summary>
    /// High-level desktop business API. The model supplies semantic targets only;
    /// UIA providers run on bounded MTA workers and GUI/VLM coordinates never
    /// escape this process.
    /// </summary>
    public sealed class DesktopComputerService
    {
        private const int UiaTimeoutMs = 8000;
        private const int MaxNodes = 600;
        private static readonly TimeSpan VisualLease = TimeSpan.FromSeconds(30);
        private readonly ComputerUseService _input;
        private readonly ConcurrentDictionary<string, VisualSnapshot> _snapshots = new();
        private readonly object _observationGate = new();
        private ObservationLease? _lastObservation;

        public DesktopComputerService(ComputerUseService input)
        {
            _input = input ?? throw new ArgumentNullException(nameof(input));
        }

        public object Observe(string goal, string? scope, bool visualFallback)
            => RunOnMta(() => ObserveCore(goal, scope, visualFallback), UiaTimeoutMs, false);

        public object Interact(
            string operation,
            string target,
            string? value,
            string? direction,
            int? amount,
            string? expectedEffect)
            => RunOnMta(
                () => InteractCore(operation, target, value, direction, amount, expectedEffect),
                UiaTimeoutMs + 4000,
                true);

        public object CreateVisualMarks(string snapshotVersion, JsonElement candidates)
        {
            if (!TryGetFreshSnapshot(snapshotVersion, out var snapshot, out var failure))
            {
                return Failure("vlm", "som_render", failure, "视觉裁剪已过期，必须重新观察。", 1);
            }
            var parsed = ParseCandidates(candidates);
            if (parsed.Count == 0)
            {
                return Failure("vlm", "som_render", "invalid_candidates", "没有合法的归一化候选框。", 1);
            }
            try
            {
                using var bitmap = Decode(snapshot.ImageBase64);
                using var graphics = Graphics.FromImage(bitmap);
                graphics.SmoothingMode = System.Drawing.Drawing2D.SmoothingMode.AntiAlias;
                using var pen = new Pen(Color.FromArgb(255, 66, 133, 244), 3);
                using var fill = new SolidBrush(Color.FromArgb(230, 22, 22, 26));
                using var text = new SolidBrush(Color.White);
                using var font = new Font("Segoe UI", 12, FontStyle.Bold, GraphicsUnit.Pixel);
                for (int i = 0; i < parsed.Count; i++)
                {
                    var rect = NormalizedToImageRect(parsed[i].Box, bitmap.Width, bitmap.Height);
                    graphics.DrawRectangle(pen, rect);
                    var label = (i + 1).ToString();
                    var size = graphics.MeasureString(label, font);
                    var badge = new RectangleF(rect.Left, Math.Max(0, rect.Top - size.Height), size.Width + 8, size.Height + 2);
                    graphics.FillRectangle(fill, badge);
                    graphics.DrawString(label, font, text, badge.Left + 4, badge.Top + 1);
                }
                return new
                {
                    success = true,
                    ok = true,
                    layer = "vlm",
                    method = "som_render",
                    attempts = 1,
                    image = Encode(bitmap),
                    snapshot_version = snapshot.Version,
                    verification = new { crop_fresh = true, mark_count = parsed.Count }
                };
            }
            catch (Exception ex)
            {
                return Failure("vlm", "som_render", "som_render_failed", ex.Message, 1);
            }
        }

        public object VisualInteract(
            string snapshotVersion,
            string operation,
            string target,
            string? value,
            string? direction,
            int? amount,
            string? expectedEffect,
            JsonElement candidate,
            int markId,
            double confidence)
        {
            if (confidence < 0.7)
            {
                return Failure("vlm", "two_stage_som", "vlm_confidence_too_low", "置信度低于 0.7，未执行输入。", 1);
            }
            if (!TryGetFreshSnapshot(snapshotVersion, out var snapshot, out var failure))
            {
                return Failure("vlm", "two_stage_som", failure, "窗口或裁剪版本已经变化，未执行输入。", 1);
            }
            var parsed = ParseCandidate(candidate);
            if (parsed == null || parsed.Confidence < 0.7)
            {
                return Failure("vlm", "two_stage_som", "invalid_candidate", "候选框无效或第一阶段置信度不足。", 1);
            }

            var physical = NormalizedToPhysicalRect(parsed.Box, snapshot);
            int x = physical.Left + physical.Width / 2;
            int y = physical.Top + physical.Height / 2;
            if (!HitBelongsToProcess(x, y, snapshot.ProcessId))
            {
                return Failure("vlm", "two_stage_som", "target_occluded", "候选点当前被其他窗口遮挡。", 1);
            }
            // A visual crop is single-use. Consume it before input so a lost
            // response cannot cause the same side effect to be replayed.
            if (!_snapshots.TryRemove(snapshotVersion, out _))
            {
                return Failure("vlm", "two_stage_som", "crop_expired", "Visual crop was already consumed; observe again.", 1);
            }
            InvalidateObservation();
            if (!InputSucceeded(_input.FocusWindow(snapshot.Hwnd.ToInt64())))
            {
                return Failure("gui", "SendInput", "foreground_unavailable", "无法安全激活目标窗口。", 1);
            }

            var before = CaptureWindow(snapshot.Hwnd, snapshot.ProcessId, false);
            bool executed = ExecutePhysical(operation, x, y, value, direction, amount);
            if (!executed)
            {
                return Failure("gui", "SendInput", "uipi_or_input_blocked", "物理输入未被系统接受；不会尝试绕过 UAC/UIPI。", 1);
            }
            Thread.Sleep(180);
            var after = CaptureWindow(snapshot.Hwnd, snapshot.ProcessId, true);
            if (before == null || after == null)
            {
                return Failure("gui", "SendInput", "verification_unavailable", "动作已发送但无法取得验证帧；禁止盲目重放。", 1, true);
            }

            Thread.Sleep(160);
            var settled = CaptureWindow(snapshot.Hwnd, snapshot.ProcessId, true) ?? after;
            var roi = PhysicalToImageRect(physical, settled);
            var visual = ManagedVisualVerifier.CompareRegion(before.ImageBase64, settled.ImageBase64, roi);
            var stability = ManagedVisualVerifier.CompareRegion(after.ImageBase64, settled.ImageBase64, roi);
            bool frameStable = !stability.Changed;
            bool windowChanged = before.Hwnd != settled.Hwnd && before.ProcessId == settled.ProcessId;
            bool verified = (visual.Changed && frameStable) || windowChanged;
            if (!verified)
            {
                return new
                {
                    success = false,
                    ok = false,
                    layer = "vlm",
                    method = "two_stage_som+SendInput",
                    attempts = 1,
                    physical_fallback = true,
                    side_effect_unknown = true,
                    failure_code = "execution_state_unknown",
                    message = "输入已发送，但目标区域没有可证明的变化；禁止自动重放。",
                    evidence_image = settled.ImageBase64,
                    verification = new
                    {
                        executed = true,
                        verified = false,
                        window_changed = windowChanged,
                        target_changed = visual.Changed,
                        frame_stable = frameStable,
                        changed_pixel_ratio = visual.ChangedPixelRatio,
                        correlation = visual.Correlation,
                        mark_id = markId,
                        confidence
                    }
                };
            }
            return new
            {
                success = true,
                ok = true,
                layer = "vlm",
                method = "two_stage_som+SendInput",
                attempts = 1,
                physical_fallback = true,
                evidence_image = settled.ImageBase64,
                verification = new
                {
                    executed = true,
                    verified = true,
                    window_changed = windowChanged,
                    target_changed = visual.Changed,
                    frame_stable = frameStable,
                    changed_pixel_ratio = visual.ChangedPixelRatio,
                    correlation = visual.Correlation,
                    mark_id = markId,
                    confidence,
                    expected_effect = expectedEffect
                }
            };
        }

        private object ObserveCore(string goal, string? scope, bool visualFallback)
        {
            var hwnd = GetForegroundWindow();
            if (hwnd == IntPtr.Zero)
            {
                return Failure("uia", "semantic_tree", "no_active_window", "没有活动窗口。", 1);
            }
            var root = AutomationElement.FromHandle(hwnd);
            if (root == null)
            {
                return Failure("uia", "semantic_tree", "uia_unavailable", "活动窗口未公开 UIA 根节点。", 1);
            }
            int processId = ProcessIdForWindow(hwnd);
            if (processId <= 0)
                return Failure("uia", "semantic_tree", "process_unavailable", "Unable to resolve the target process.", 1);
            uint dpi = GetDpiForWindow(hwnd);
            if (!GetWindowRect(hwnd, out var observedRect))
            {
                return Failure(
                    "uia", "semantic_tree", "window_rect_unavailable",
                    $"Unable to read physical bounds for HWND 0x{hwnd.ToInt64():x}.", 1);
            }
            var nodes = EnumerateNodes(root);
            var modal = FindModal(root);
            var shot = visualFallback ? CaptureWindow(hwnd, processId, false) : null;
            string? version = null;
            if (shot != null)
            {
                version = Guid.NewGuid().ToString("N");
                shot.Version = version;
                _snapshots[version] = shot;
                PruneSnapshots();
            }
            if (GetForegroundWindow() != hwnd
                || !GetWindowRect(hwnd, out var confirmedRect)
                || !SameRect(observedRect, confirmedRect)
                || GetDpiForWindow(hwnd) != dpi)
            {
                if (version != null) _snapshots.TryRemove(version, out _);
                return Failure("uia", "semantic_tree", "observation_stale", "Window bounds, foreground state, or DPI changed while observing.", 1);
            }
            lock (_observationGate)
            {
                _lastObservation = new ObservationLease(
                    hwnd, processId, confirmedRect, dpi, DateTimeOffset.UtcNow);
            }
            return new
            {
                success = true,
                ok = true,
                layer = nodes.Count > 1 ? "uia" : "gui",
                method = nodes.Count > 1 ? "semantic_tree" : "window_crop",
                attempts = 1,
                goal,
                scope = string.IsNullOrWhiteSpace(scope) ? "active_window" : scope,
                snapshot_version = version,
                image = shot?.ImageBase64,
                window = new
                {
                    hwnd = hwnd.ToInt64(),
                    title = Safe(() => root.Current.Name, ""),
                    process_id = processId,
                    physical_bounds = RectObject(SafeRect(root)),
                    dpi = GetDpiForWindow(hwnd)
                },
                accessibility = new
                {
                    provider = "uia-net-mta",
                    node_count = nodes.Count,
                    nodes
                },
                verification = new
                {
                    observed = true,
                    modal_blocking = modal != null,
                    visual_crop = shot != null,
                    coordinate_space = "physical_screen"
                }
            };
        }

        private object InteractCore(
            string operation,
            string target,
            string? value,
            string? direction,
            int? amount,
            string? expectedEffect)
        {
            string[] allowed = { "invoke", "set_value", "select", "toggle", "expand", "collapse", "scroll", "key_chord" };
            operation = (operation ?? "").Trim().ToLowerInvariant();
            target = (target ?? "").Trim();
            if (!allowed.Contains(operation) || target.Length == 0 || LooksLikePixelTarget(target))
            {
                return Failure("uia", "semantic_target", "invalid_semantic_operation", "operation 或语义 target 不合法；禁止绝对像素。", 1);
            }

            var hwnd = GetForegroundWindow();
            if (hwnd == IntPtr.Zero)
            {
                return Failure("uia", "semantic_target", "no_active_window", "没有活动窗口。", 1);
            }
            var root = AutomationElement.FromHandle(hwnd);
            if (root == null)
            {
                return Failure("uia", "semantic_target", "uia_unavailable", "窗口没有可用 UIA Provider。", 1);
            }
            int processId = ProcessIdForWindow(hwnd);
            if (processId <= 0)
                return Failure("uia", "semantic_target", "process_unavailable", "Unable to resolve the target process.", 1);
            if (!TryConsumeObservation(hwnd, processId, out var observationFailure))
            {
                return Failure(
                    "uia", "observation_guard", observationFailure,
                    "Observe the active window again before interacting.", 1);
            }
            var modal = FindModal(root);
            if (modal != null)
            {
                return Failure("uia", "modal_guard", "blocked_by_modal", "检测到模态对话框，需要用户确认。", 1);
            }
            // A key chord is scoped to the semantic active window rather than a
            // child coordinate target. It still passes foreground/process checks.
            var element = operation == "key_chord" ? root : FindSemanticElement(root, target);
            if (element == null)
            {
                return Failure("uia", "semantic_target", "uia_target_not_found", "UIA 未找到语义目标，可进入局部视觉定位。", 1);
            }
            if (!Safe(() => element.Current.IsEnabled, false) || Safe(() => element.Current.IsOffscreen, true))
            {
                TryRealizeAndScroll(element);
            }
            if (!Safe(() => element.Current.IsEnabled, false) || Safe(() => element.Current.IsOffscreen, true))
            {
                return Failure("uia", "semantic_target", "uia_target_not_visible", "目标不可见或不可用。", 1);
            }

            var beforeFrame = CaptureWindow(hwnd, processId, false);
            var beforeState = SemanticState(element);
            var beforeWindowState = SemanticWindowState(root);
            var targetRect = SafeRect(element);
            string method = "";
            bool executed = ExecuteNative(element, operation, value, direction, amount, out method);
            bool physicalFallback = false;
            if (!executed)
            {
                if (!PreparePhysicalTarget(root, element, targetRect, out int x, out int y, out string blockCode))
                {
                    return Failure("gui", "SendInput", blockCode, "目标命中或遮挡验证失败，未执行物理输入。", 1);
                }
                if (!InputSucceeded(_input.FocusWindow(hwnd.ToInt64())))
                {
                    return Failure("gui", "SendInput", "foreground_unavailable", "无法安全取得前台窗口。", 1);
                }
                executed = ExecutePhysical(operation, x, y, value, direction, amount);
                method = "SendInput";
                physicalFallback = true;
            }
            if (!executed)
            {
                return Failure("gui", method == "" ? "unsupported" : method, "uipi_or_input_blocked", "动作未执行；不会绕过 UIPI/UAC。", 1);
            }

            Thread.Sleep(180);
            var afterState = SemanticState(element);
            var afterWindowState = SemanticWindowState(root);
            var afterFrame = CaptureWindow(hwnd, processId, true);
            Thread.Sleep(160);
            var settledState = SemanticState(element);
            var settledWindowState = SemanticWindowState(root);
            var settledFrame = CaptureWindow(hwnd, processId, true) ?? afterFrame;
            bool semanticChanged = beforeState != settledState && afterState == settledState;
            bool semanticWindowChanged = beforeWindowState != settledWindowState
                && afterWindowState == settledWindowState;
            bool expectedState = VerifyExpectedState(element, operation, value, beforeState);
            bool sameProcessFrames = settledFrame != null && beforeFrame != null
                && settledFrame.ProcessId == beforeFrame.ProcessId;
            bool windowChanged = sameProcessFrames && settledFrame!.Hwnd != beforeFrame!.Hwnd;
            ManagedVisualVerifier.Result? visual = null;
            ManagedVisualVerifier.Result? stability = null;
            if (beforeFrame != null && settledFrame != null && sameProcessFrames)
            {
                var roi = PhysicalToImageRect(ToDrawingRect(targetRect), settledFrame);
                visual = ManagedVisualVerifier.CompareRegion(
                    beforeFrame.ImageBase64,
                    settledFrame.ImageBase64,
                    roi);
                if (afterFrame != null)
                {
                    stability = ManagedVisualVerifier.CompareRegion(
                        afterFrame.ImageBase64,
                        settledFrame.ImageBase64,
                        roi);
                }
            }
            bool frameStable = stability?.Changed != true;
            bool verified = expectedState || semanticChanged || semanticWindowChanged
                || windowChanged || (visual?.Changed == true && frameStable);
            string layer = physicalFallback ? "gui" : "uia";
            if (!verified)
            {
                return new
                {
                    success = false,
                    ok = false,
                    layer,
                    method,
                    attempts = 1,
                    physical_fallback = physicalFallback,
                    side_effect_unknown = true,
                    failure_code = "execution_state_unknown",
                    message = "动作已执行，但未取得目标相关的成功证据；禁止自动重放。",
                    evidence_image = settledFrame?.ImageBase64,
                    verification = new
                    {
                        executed = true,
                        verified = false,
                        semantic_changed = semanticChanged,
                        semantic_window_changed = semanticWindowChanged,
                        expected_state = expectedState,
                        window_changed = windowChanged,
                        target_changed = visual?.Changed ?? false,
                        frame_stable = frameStable,
                        changed_pixel_ratio = visual?.ChangedPixelRatio ?? 0,
                        correlation = visual?.Correlation ?? 1,
                        expected_effect = expectedEffect
                    }
                };
            }
            return new
            {
                success = true,
                ok = true,
                layer,
                method,
                attempts = 1,
                physical_fallback = physicalFallback,
                evidence_image = settledFrame?.ImageBase64,
                verification = new
                {
                    executed = true,
                    verified = true,
                    semantic_changed = semanticChanged,
                    semantic_window_changed = semanticWindowChanged,
                    expected_state = expectedState,
                    window_changed = windowChanged,
                    target_changed = visual?.Changed ?? false,
                    frame_stable = frameStable,
                    changed_pixel_ratio = visual?.ChangedPixelRatio ?? 0,
                    correlation = visual?.Correlation ?? 1,
                    expected_effect = expectedEffect
                }
            };
        }

        private static bool ExecuteNative(AutomationElement element, string operation, string? value, string? direction, int? amount, out string method)
        {
            method = "";
            try
            {
                TryRealizeAndScroll(element);
                switch (operation)
                {
                    case "invoke" when element.TryGetCurrentPattern(InvokePattern.Pattern, out var invoke):
                        ((InvokePattern)invoke).Invoke(); method = "InvokePattern"; return true;
                    case "set_value" when element.TryGetCurrentPattern(ValuePattern.Pattern, out var val):
                        ((ValuePattern)val).SetValue(value ?? ""); method = "ValuePattern"; return true;
                    case "select" when element.TryGetCurrentPattern(SelectionItemPattern.Pattern, out var select):
                        ((SelectionItemPattern)select).Select(); method = "SelectionItemPattern"; return true;
                    case "toggle" when element.TryGetCurrentPattern(TogglePattern.Pattern, out var toggle):
                        ((TogglePattern)toggle).Toggle(); method = "TogglePattern"; return true;
                    case "expand" when element.TryGetCurrentPattern(ExpandCollapsePattern.Pattern, out var expand):
                        ((ExpandCollapsePattern)expand).Expand(); method = "ExpandCollapsePattern"; return true;
                    case "collapse" when element.TryGetCurrentPattern(ExpandCollapsePattern.Pattern, out var collapse):
                        ((ExpandCollapsePattern)collapse).Collapse(); method = "ExpandCollapsePattern"; return true;
                    case "scroll":
                        var scrollElement = FindPatternOwner(element, ScrollPattern.Pattern);
                        if (scrollElement != null && scrollElement.TryGetCurrentPattern(ScrollPattern.Pattern, out var scrollObj))
                        {
                            var scroll = (ScrollPattern)scrollObj;
                            int steps = Math.Clamp(amount ?? 1, 1, 20);
                            var vertical = (direction ?? "down").Equals("up", StringComparison.OrdinalIgnoreCase)
                                ? ScrollAmount.SmallDecrement : ScrollAmount.SmallIncrement;
                            var horizontal = (direction ?? "").Equals("left", StringComparison.OrdinalIgnoreCase)
                                ? ScrollAmount.SmallDecrement
                                : (direction ?? "").Equals("right", StringComparison.OrdinalIgnoreCase)
                                    ? ScrollAmount.SmallIncrement : ScrollAmount.NoAmount;
                            for (int i = 0; i < steps; i++)
                            {
                                scroll.Scroll(horizontal, horizontal == ScrollAmount.NoAmount ? vertical : ScrollAmount.NoAmount);
                            }
                            method = "ScrollPattern"; return true;
                        }
                        break;
                }
            }
            catch (Exception ex)
            {
                Debug.WriteLine("DesktopComputerService native action failed: " + ex.Message);
            }
            return false;
        }

        private bool ExecutePhysical(string operation, int x, int y, string? value, string? direction, int? amount)
        {
            if (operation == "key_chord")
            {
                return InputSucceeded(_input.KeyPress(value ?? ""));
            }
            if (operation == "scroll")
            {
                if (!InputSucceeded(_input.MouseMove(x, y))) return false;
                int delta = Math.Clamp(amount ?? 1, 1, 20) * 120;
                if (!string.Equals(direction, "up", StringComparison.OrdinalIgnoreCase)) delta = -delta;
                return InputSucceeded(_input.MouseScroll(delta));
            }
            if (!InputSucceeded(_input.MouseClick(x, y, "left", "single"))) return false;
            if (operation == "set_value")
            {
                return InputSucceeded(_input.KeyPress("ctrl+a"))
                    && InputSucceeded(_input.KeyboardType(value ?? ""));
            }
            if (operation == "select" && !string.IsNullOrWhiteSpace(value))
            {
                return InputSucceeded(_input.KeyboardType(value)) && InputSucceeded(_input.KeyPress("enter"));
            }
            return true;
        }

        private static bool PreparePhysicalTarget(AutomationElement root, AutomationElement target, WpfRect rect, out int x, out int y, out string failure)
        {
            x = (int)Math.Round(rect.X + rect.Width / 2);
            y = (int)Math.Round(rect.Y + rect.Height / 2);
            failure = "target_occluded";
            try
            {
                if (target.TryGetClickablePoint(out var point))
                {
                    x = (int)Math.Round(point.X);
                    y = (int)Math.Round(point.Y);
                }
                var hit = AutomationElement.FromPoint(new WpfPoint(x, y));
                if (hit == null) return false;
                if (SameOrDescendant(hit, target) && SameProcess(hit, root)) return true;
                if (TryCollapseSafeOverlay(hit))
                {
                    Thread.Sleep(80);
                    hit = AutomationElement.FromPoint(new WpfPoint(x, y));
                    return hit != null && SameOrDescendant(hit, target) && SameProcess(hit, root);
                }
                return false;
            }
            catch
            {
                failure = "from_point_failed";
                return false;
            }
        }

        private static bool TryCollapseSafeOverlay(AutomationElement hit)
        {
            var current = hit;
            for (int i = 0; i < 6 && current != null; i++)
            {
                var type = Safe(() => current.Current.ControlType, ControlType.Custom);
                if (type == ControlType.Menu || type == ControlType.MenuItem || type == ControlType.ToolTip)
                {
                    if (current.TryGetCurrentPattern(ExpandCollapsePattern.Pattern, out var obj))
                    {
                        ((ExpandCollapsePattern)obj).Collapse();
                        return true;
                    }
                }
                current = TreeWalker.RawViewWalker.GetParent(current);
            }
            return false;
        }

        private static void TryRealizeAndScroll(AutomationElement element)
        {
            try
            {
                if (element.TryGetCurrentPattern(VirtualizedItemPattern.Pattern, out var virtualized))
                    ((VirtualizedItemPattern)virtualized).Realize();
            }
            catch { }
            try
            {
                if (element.TryGetCurrentPattern(ScrollItemPattern.Pattern, out var scrollItem))
                    ((ScrollItemPattern)scrollItem).ScrollIntoView();
            }
            catch { }
        }

        private static AutomationElement? FindSemanticElement(AutomationElement root, string target)
        {
            string wanted = Normalize(target);
            try
            {
                var exactId = root.FindFirst(
                    TreeScope.Descendants,
                    new PropertyCondition(AutomationElement.AutomationIdProperty, target.Trim()));
                if (exactId != null) return exactId;
            }
            catch { }
            try
            {
                var exactName = root.FindFirst(
                    TreeScope.Descendants,
                    new PropertyCondition(AutomationElement.NameProperty, target.Trim()));
                if (exactName != null) return exactName;
            }
            catch { }
            AutomationElement? best = null;
            int bestScore = 0;
            var queue = new Queue<AutomationElement>();
            queue.Enqueue(root);
            int visited = 0;
            while (queue.Count > 0 && visited++ < MaxNodes)
            {
                var current = queue.Dequeue();
                bool isItemContainer = false;
                try
                {
                    // Virtualized containers may not expose off-screen items through the
                    // raw tree. Ask the provider for the named item before walking only
                    // the currently-realized children.
                    if (current.TryGetCurrentPattern(ItemContainerPattern.Pattern, out var containerObject))
                    {
                        isItemContainer = true;
                        var item = ((ItemContainerPattern)containerObject).FindItemByProperty(
                            null,
                            AutomationElement.NameProperty,
                            target.Trim());
                        if (item != null)
                            return item;
                    }
                }
                catch { }
                string name = Normalize(Safe(() => current.Current.Name, ""));
                string automationId = Normalize(Safe(() => current.Current.AutomationId, ""));
                string type = Normalize(Safe(() => current.Current.ControlType.ProgrammaticName, ""));
                int score = 0;
                if (name == wanted || automationId == wanted) score = 120;
                else if (wanted.Contains(name, StringComparison.OrdinalIgnoreCase) && name.Length >= 2) score = 95;
                else if (name.Contains(wanted, StringComparison.OrdinalIgnoreCase) && wanted.Length >= 2) score = 90;
                else if (wanted.Contains(automationId, StringComparison.OrdinalIgnoreCase) && automationId.Length >= 2) score = 85;
                if (wanted.Contains(type.Replace("controltype", ""), StringComparison.OrdinalIgnoreCase)) score += 10;
                if (score > bestScore && !Safe(() => current.Current.IsOffscreen, true))
                {
                    best = current;
                    bestScore = score;
                }
                // ItemContainerPattern is the provider-supported way to address
                // virtual items; walking every virtual child defeats virtualization
                // and can stall the provider.
                if (isItemContainer) continue;
                try
                {
                    var child = TreeWalker.ControlViewWalker.GetFirstChild(current);
                    while (child != null)
                    {
                        queue.Enqueue(child);
                        child = TreeWalker.ControlViewWalker.GetNextSibling(child);
                    }
                }
                catch { }
            }
            return bestScore >= 80 ? best : null;
        }

        private static List<object> EnumerateNodes(AutomationElement root)
        {
            var result = new List<object>();
            var queue = new Queue<AutomationElement>();
            queue.Enqueue(root);
            while (queue.Count > 0 && result.Count < MaxNodes)
            {
                var current = queue.Dequeue();
                var rect = SafeRect(current);
                string name = Safe(() => current.Current.Name, "");
                string automationId = Safe(() => current.Current.AutomationId, "");
                string type = Safe(() => current.Current.ControlType.ProgrammaticName.Replace("ControlType.", ""), "Custom");
                if (!string.IsNullOrWhiteSpace(name) || !string.IsNullOrWhiteSpace(automationId) || result.Count == 0)
                {
                    result.Add(new
                    {
                        name,
                        automation_id = automationId,
                        control_type = type,
                        physical_bounds = RectObject(rect),
                        enabled = Safe(() => current.Current.IsEnabled, false),
                        offscreen = Safe(() => current.Current.IsOffscreen, true),
                        patterns = SupportedPatterns(current)
                    });
                }
                try
                {
                    if (current.TryGetCurrentPattern(ItemContainerPattern.Pattern, out _))
                        continue;
                }
                catch { }
                try
                {
                    var child = TreeWalker.ControlViewWalker.GetFirstChild(current);
                    while (child != null)
                    {
                        queue.Enqueue(child);
                        child = TreeWalker.ControlViewWalker.GetNextSibling(child);
                    }
                }
                catch { }
            }
            return result;
        }

        private static AutomationElement? FindModal(AutomationElement root)
        {
            try
            {
                if (root.TryGetCurrentPattern(WindowPattern.Pattern, out var rootWindow)
                    && ((WindowPattern)rootWindow).Current.IsModal)
                    return root;

                int processId = Safe(() => root.Current.ProcessId, 0);
                if (processId <= 0) return null;
                // Owned modal dialogs are top-level HWNDs, not reliable
                // descendants of the owner's provider tree. Query only desktop
                // children for the same process so a virtualized/custom subtree
                // cannot stall modal detection.
                var windows = AutomationElement.RootElement.FindAll(
                    TreeScope.Children,
                    new PropertyCondition(AutomationElement.ProcessIdProperty, processId));
                foreach (AutomationElement window in windows)
                {
                    if (Automation.Compare(window, root)) continue;
                    if (window.TryGetCurrentPattern(WindowPattern.Pattern, out var obj) && ((WindowPattern)obj).Current.IsModal)
                        return window;
                }
            }
            catch { }
            return null;
        }

        private static string[] SupportedPatterns(AutomationElement element)
        {
            var result = new List<string>();
            var names = new Dictionary<int, string>
            {
                [InvokePattern.Pattern.Id] = "Invoke",
                [ValuePattern.Pattern.Id] = "Value",
                [SelectionItemPattern.Pattern.Id] = "SelectionItem",
                [TogglePattern.Pattern.Id] = "Toggle",
                [ExpandCollapsePattern.Pattern.Id] = "ExpandCollapse",
                [ScrollPattern.Pattern.Id] = "Scroll",
                [VirtualizedItemPattern.Pattern.Id] = "VirtualizedItem",
                [ScrollItemPattern.Pattern.Id] = "ScrollItem",
                [ItemContainerPattern.Pattern.Id] = "ItemContainer"
            };
            try
            {
                foreach (var pattern in element.GetSupportedPatterns())
                {
                    if (names.TryGetValue(pattern.Id, out var name)) result.Add(name);
                }
            }
            catch { }
            return result.ToArray();
        }

        private VisualSnapshot? CaptureWindow(IntPtr expectedHwnd, int expectedProcessId, bool allowRelatedForeground)
        {
            var foreground = GetForegroundWindow();
            if (foreground == IntPtr.Zero) return null;
            var targetHwnd = expectedHwnd;
            if (foreground != expectedHwnd)
            {
                if (!allowRelatedForeground) return null;
                try
                {
                    var foregroundElement = AutomationElement.FromHandle(foreground);
                    if (foregroundElement == null
                        || Safe(() => foregroundElement.Current.ProcessId, -1) != expectedProcessId)
                        return null;
                    targetHwnd = foreground;
                }
                catch { return null; }
            }

            // Capture the validated HWND directly. The low-level screenshot API
            // must not fall back to a full virtual-desktop image.
            var raw = _input.TakeWindowScreenshot(targetHwnd.ToInt64(), 1568, 1_150_000);
            using var document = JsonDocument.Parse(JsonSerializer.Serialize(raw));
            var root = document.RootElement;
            if (!root.TryGetProperty("success", out var success) || !success.GetBoolean()) return null;
            if (!root.TryGetProperty("coordinate_system", out var coordinate)
                || coordinate.GetString() != "window-relative") return null;
            if (!root.TryGetProperty("hwnd", out var capturedHwnd)
                || capturedHwnd.ValueKind != JsonValueKind.Number
                || capturedHwnd.GetInt64() != targetHwnd.ToInt64()) return null;
            return new VisualSnapshot
            {
                Version = "",
                CreatedAt = DateTimeOffset.UtcNow,
                ImageBase64 = root.GetProperty("image").GetString() ?? "",
                Hwnd = new IntPtr(root.GetProperty("hwnd").GetInt64()),
                ProcessId = root.TryGetProperty("process_id", out var pid) && pid.ValueKind == JsonValueKind.Number ? pid.GetInt32() : 0,
                OriginX = root.GetProperty("origin_x").GetInt32(),
                OriginY = root.GetProperty("origin_y").GetInt32(),
                OriginalWidth = root.GetProperty("original_width").GetInt32(),
                OriginalHeight = root.GetProperty("original_height").GetInt32(),
                ImageWidth = root.GetProperty("scaled_width").GetInt32(),
                ImageHeight = root.GetProperty("scaled_height").GetInt32(),
                Scale = root.GetProperty("scale_ratio").GetDouble(),
                Dpi = GetDpiForWindow(new IntPtr(root.GetProperty("hwnd").GetInt64()))
            };
        }

        private bool TryGetFreshSnapshot(string version, out VisualSnapshot snapshot, out string failure)
        {
            failure = "crop_expired";
            if (!_snapshots.TryGetValue(version ?? "", out snapshot!)
                || DateTimeOffset.UtcNow - snapshot.CreatedAt > VisualLease) return false;
            if (GetForegroundWindow() != snapshot.Hwnd) { failure = "window_changed"; return false; }
            if (!GetWindowRect(snapshot.Hwnd, out var rect)
                || rect.Left != snapshot.OriginX || rect.Top != snapshot.OriginY
                || rect.Width != snapshot.OriginalWidth || rect.Height != snapshot.OriginalHeight)
            { failure = "crop_stale"; return false; }
            if (GetDpiForWindow(snapshot.Hwnd) != snapshot.Dpi) { failure = "dpi_changed"; return false; }
            return true;
        }

        private void PruneSnapshots()
        {
            foreach (var entry in _snapshots)
            {
                if (DateTimeOffset.UtcNow - entry.Value.CreatedAt > VisualLease)
                    _snapshots.TryRemove(entry.Key, out _);
            }
        }

        private bool TryConsumeObservation(
            IntPtr hwnd,
            int processId,
            out string failure)
        {
            ObservationLease? observation;
            lock (_observationGate)
            {
                observation = _lastObservation;
                _lastObservation = null;
            }
            failure = "observation_required";
            if (observation == null) return false;
            if (DateTimeOffset.UtcNow - observation.CreatedAt > VisualLease)
            {
                failure = "observation_stale";
                return false;
            }
            if (observation.Hwnd != hwnd || observation.ProcessId != processId)
            {
                failure = "window_changed";
                return false;
            }
            if (!GetWindowRect(hwnd, out var rect) || !SameRect(observation.Rect, rect))
            {
                failure = "window_rect_changed";
                return false;
            }
            if (GetDpiForWindow(hwnd) != observation.Dpi)
            {
                failure = "dpi_changed";
                return false;
            }
            return true;
        }

        private void InvalidateObservation()
        {
            lock (_observationGate) _lastObservation = null;
        }

        private static bool SameRect(RECT left, RECT right)
            => left.Left == right.Left && left.Top == right.Top
                && left.Right == right.Right && left.Bottom == right.Bottom;

        private static object RunOnMta(Func<object> action, int timeoutMs, bool timeoutMayHaveSideEffect)
        {
            var completion = new TaskCompletionSource<object>(TaskCreationOptions.RunContinuationsAsynchronously);
            var thread = new Thread(() =>
            {
                try { completion.TrySetResult(action()); }
                catch (Exception ex) { completion.TrySetResult(Failure("uia", "mta_worker", "uia_provider_error", ex.Message, 1)); }
            }) { IsBackground = true, Name = "MoonYa.UIA.MTA" };
            thread.SetApartmentState(ApartmentState.MTA);
            thread.Start();
            if (!completion.Task.Wait(timeoutMs))
                return Failure("uia", "mta_worker", "uia_timeout", "UIA Provider 超时，主界面未被阻塞。", 1, timeoutMayHaveSideEffect);
            return completion.Task.Result;
        }

        private static object Failure(string layer, string method, string code, string message, int attempts, bool unknownSideEffect = false)
            => new
            {
                success = false,
                ok = false,
                layer,
                method,
                attempts,
                failure_code = code,
                message,
                side_effect_unknown = unknownSideEffect,
                verification = new { executed = unknownSideEffect, verified = false }
            };

        private static bool VerifyExpectedState(AutomationElement element, string operation, string? value, string before)
        {
            try
            {
                if (operation == "set_value" && element.TryGetCurrentPattern(ValuePattern.Pattern, out var val))
                    return ((ValuePattern)val).Current.Value == (value ?? "");
                if (operation == "select" && element.TryGetCurrentPattern(SelectionItemPattern.Pattern, out var sel))
                    return ((SelectionItemPattern)sel).Current.IsSelected;
                if (operation == "expand" && element.TryGetCurrentPattern(ExpandCollapsePattern.Pattern, out var exp))
                    return ((ExpandCollapsePattern)exp).Current.ExpandCollapseState == ExpandCollapseState.Expanded;
                if (operation == "collapse" && element.TryGetCurrentPattern(ExpandCollapsePattern.Pattern, out var col))
                    return ((ExpandCollapsePattern)col).Current.ExpandCollapseState == ExpandCollapseState.Collapsed;
                if (operation == "toggle" && element.TryGetCurrentPattern(TogglePattern.Pattern, out var tog))
                    return !before.Contains(((TogglePattern)tog).Current.ToggleState.ToString(), StringComparison.Ordinal);
            }
            catch { }
            return false;
        }

        private static string SemanticState(AutomationElement element)
        {
            var parts = new List<string>
            {
                Safe(() => element.Current.Name, ""),
                Safe(() => element.Current.IsEnabled, false).ToString(),
                Safe(() => element.Current.IsOffscreen, true).ToString(),
                Safe(() => element.Current.BoundingRectangle.ToString(), "")
            };
            try { if (element.TryGetCurrentPattern(ValuePattern.Pattern, out var value)) parts.Add(((ValuePattern)value).Current.Value); } catch { }
            try { if (element.TryGetCurrentPattern(TogglePattern.Pattern, out var toggle)) parts.Add(((TogglePattern)toggle).Current.ToggleState.ToString()); } catch { }
            try { if (element.TryGetCurrentPattern(SelectionItemPattern.Pattern, out var selection)) parts.Add(((SelectionItemPattern)selection).Current.IsSelected.ToString()); } catch { }
            try { if (element.TryGetCurrentPattern(ExpandCollapsePattern.Pattern, out var expand)) parts.Add(((ExpandCollapsePattern)expand).Current.ExpandCollapseState.ToString()); } catch { }
            return string.Join("|", parts);
        }

        private static string SemanticWindowState(AutomationElement root)
        {
            var builder = new StringBuilder(16 * 1024);
            var queue = new Queue<AutomationElement>();
            queue.Enqueue(root);
            int visited = 0;
            while (queue.Count > 0 && visited++ < MaxNodes)
            {
                var current = queue.Dequeue();
                builder.Append(Safe(() => current.Current.AutomationId, ""))
                    .Append(':')
                    .Append(SemanticState(current))
                    .Append('\n');
                try
                {
                    if (current.TryGetCurrentPattern(ItemContainerPattern.Pattern, out _))
                        continue;
                }
                catch { }
                try
                {
                    var child = TreeWalker.ControlViewWalker.GetFirstChild(current);
                    while (child != null)
                    {
                        queue.Enqueue(child);
                        child = TreeWalker.ControlViewWalker.GetNextSibling(child);
                    }
                }
                catch { }
            }
            return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(builder.ToString())));
        }

        private static AutomationElement? FindPatternOwner(AutomationElement element, AutomationPattern pattern)
        {
            var current = element;
            for (int i = 0; i < 12 && current != null; i++)
            {
                try { if (current.TryGetCurrentPattern(pattern, out _)) return current; } catch { }
                try { current = TreeWalker.RawViewWalker.GetParent(current); } catch { return null; }
            }
            return null;
        }

        private static bool SameOrDescendant(AutomationElement element, AutomationElement ancestor)
        {
            var current = element;
            for (int i = 0; i < 40 && current != null; i++)
            {
                try { if (Automation.Compare(current, ancestor)) return true; } catch { }
                try { current = TreeWalker.RawViewWalker.GetParent(current); } catch { break; }
            }
            return false;
        }

        private static bool SameProcess(AutomationElement left, AutomationElement right)
            => Safe(() => left.Current.ProcessId, -1) == Safe(() => right.Current.ProcessId, -2);

        private static int ProcessIdForWindow(IntPtr hwnd)
        {
            GetWindowThreadProcessId(hwnd, out var processId);
            return processId > int.MaxValue ? 0 : (int)processId;
        }

        private static bool HitBelongsToProcess(int x, int y, int processId)
        {
            try
            {
                var hit = AutomationElement.FromPoint(new WpfPoint(x, y));
                return hit != null && Safe(() => hit.Current.ProcessId, -1) == processId;
            }
            catch { return false; }
        }

        private static bool InputSucceeded(object result)
        {
            try
            {
                using var doc = JsonDocument.Parse(JsonSerializer.Serialize(result));
                return doc.RootElement.TryGetProperty("success", out var success) && success.GetBoolean();
            }
            catch { return false; }
        }

        private static bool LooksLikePixelTarget(string target)
            => System.Text.RegularExpressions.Regex.IsMatch(target, @"^\s*[\[(]?\s*-?\d+\s*[,，]\s*-?\d+");

        private static string Normalize(string text)
            => new string((text ?? "").Where(c => !char.IsWhiteSpace(c) && c != '_' && c != '-').ToArray()).ToLowerInvariant();

        private static T Safe<T>(Func<T> getter, T fallback)
        {
            try { return getter(); } catch { return fallback; }
        }

        private static WpfRect SafeRect(AutomationElement element)
            => Safe(() => element.Current.BoundingRectangle, WpfRect.Empty);

        private static object RectObject(WpfRect rect)
            => new { x = (int)rect.X, y = (int)rect.Y, width = (int)rect.Width, height = (int)rect.Height };

        private static Rectangle ToDrawingRect(WpfRect rect)
            => new((int)Math.Floor(rect.X), (int)Math.Floor(rect.Y), Math.Max(1, (int)Math.Ceiling(rect.Width)), Math.Max(1, (int)Math.Ceiling(rect.Height)));

        private static Rectangle PhysicalToImageRect(Rectangle physical, VisualSnapshot snapshot)
            => DesktopCoordinateService.PhysicalToImage(
                physical, snapshot.OriginX, snapshot.OriginY, snapshot.Scale);

        private static Rectangle NormalizedToPhysicalRect(double[] box, VisualSnapshot snapshot)
            => DesktopCoordinateService.NormalizedToPhysical(
                box, snapshot.OriginX, snapshot.OriginY,
                snapshot.OriginalWidth, snapshot.OriginalHeight);

        private static Rectangle NormalizedToImageRect(double[] box, int width, int height)
            => DesktopCoordinateService.NormalizedToImage(box, width, height);

        private static List<VisualCandidate> ParseCandidates(JsonElement candidates)
        {
            var result = new List<VisualCandidate>();
            if (candidates.ValueKind != JsonValueKind.Array) return result;
            foreach (var candidate in candidates.EnumerateArray())
            {
                var parsed = ParseCandidate(candidate);
                if (parsed != null) result.Add(parsed);
            }
            return result.Take(12).ToList();
        }

        private static VisualCandidate? ParseCandidate(JsonElement candidate)
        {
            try
            {
                var box = candidate.GetProperty("bbox").EnumerateArray().Select(v => v.GetDouble()).ToArray();
                double confidence = candidate.GetProperty("confidence").GetDouble();
                if (box.Length != 4 || box.Any(v => v < 0 || v > 1000)
                    || box[2] <= box[0] || box[3] <= box[1]) return null;
                return new VisualCandidate(box, confidence);
            }
            catch { return null; }
        }

        private static Bitmap Decode(string base64)
        {
            using var stream = new MemoryStream(Convert.FromBase64String(base64), writable: false);
            using var source = new Bitmap(stream);
            return new Bitmap(source);
        }

        private static string Encode(Bitmap bitmap)
        {
            using var stream = new MemoryStream();
            bitmap.Save(stream, ImageFormat.Png);
            return Convert.ToBase64String(stream.ToArray());
        }

        private sealed class VisualSnapshot
        {
            public string Version { get; set; } = "";
            public DateTimeOffset CreatedAt { get; init; }
            public string ImageBase64 { get; init; } = "";
            public IntPtr Hwnd { get; init; }
            public int ProcessId { get; init; }
            public int OriginX { get; init; }
            public int OriginY { get; init; }
            public int OriginalWidth { get; init; }
            public int OriginalHeight { get; init; }
            public int ImageWidth { get; init; }
            public int ImageHeight { get; init; }
            public double Scale { get; init; }
            public uint Dpi { get; init; }
        }

        private sealed record ObservationLease(
            IntPtr Hwnd,
            int ProcessId,
            RECT Rect,
            uint Dpi,
            DateTimeOffset CreatedAt);

        private sealed record VisualCandidate(double[] Box, double Confidence);

        [StructLayout(LayoutKind.Sequential)]
        private struct RECT
        {
            public int Left, Top, Right, Bottom;
            public int Width => Right - Left;
            public int Height => Bottom - Top;
        }

        [DllImport("user32.dll")]
        private static extern IntPtr GetForegroundWindow();

        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool GetWindowRect(IntPtr hwnd, out RECT rect);

        [DllImport("user32.dll")]
        private static extern uint GetDpiForWindow(IntPtr hwnd);

        [DllImport("user32.dll")]
        private static extern uint GetWindowThreadProcessId(IntPtr hwnd, out uint processId);
    }
}
