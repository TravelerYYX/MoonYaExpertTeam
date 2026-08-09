// ┌─────────────────────────────────────────────────────────┐
// │  ComputerUseService — 视觉 Agent 任务4：电脑操作服务      │
// │  截屏 / 鼠标移动 / 鼠标点击 / 滚轮 / 键盘输入 / 组合键    │
// │  通过 P/Invoke 调用 user32.dll 中的 SendInput 等 API     │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Runtime.InteropServices;

namespace MoonYa.Services
{
    public class ComputerUseService
    {
        // TODO: 未来从 cu_runtime_config (DB) 读取这两个阈值
        private const int SCREENSHOT_MAX_LONG_EDGE = 1568;
        private const long SCREENSHOT_MAX_TOTAL_PIXELS = 1150000;

        // ── Public API ─────────────────────────────────────

        /// <summary>
        /// 截屏：按 target 截取当前活动窗口或全屏，按 Anthropic 官方规范（长边 ≤1568px、总像素 ≤1.15MP）
        /// 等比缩放后编码为 PNG base64。
        /// target="window"（默认）：截取前台窗口区域，使用 window-relative 坐标系；前置检查失败时回退到 screen。
        /// target="screen"：保持 v1 行为，截取主屏幕全屏。
        /// 返回字段：original_width/height（物理分辨率）、scaled_width/height（实际图尺寸）、scale_ratio（缩放比）、
        ///   coordinate_system（"window-relative" / "screen"）、origin_x/origin_y（窗口左上角屏幕坐标）、
        ///   window_bounds（{x, y, width, height}）、window_title、process_name、process_id、hwnd、target、fallback_reason?。
        /// 失败时返回 { success: false, message: ex.Message }。
        /// 类常量 SCREENSHOT_MAX_LONG_EDGE / SCREENSHOT_MAX_TOTAL_PIXELS 仅作为兜底默认值，
        /// 运行时参数应从 cu_runtime_config DB 表读取（待后续接入 launcher 配置或 /cu/config 端点）。
        /// </summary>
        public object TakeScreenshot(
            string? target = null,
            int? maxLongEdge = null,
            long? maxTotalPixels = null)
        {
            try
            {
                int effectiveMaxLongEdge = Math.Clamp(
                    maxLongEdge ?? SCREENSHOT_MAX_LONG_EDGE,
                    512,
                    SCREENSHOT_MAX_LONG_EDGE);
                long effectiveMaxTotalPixels = Math.Clamp(
                    maxTotalPixels ?? SCREENSHOT_MAX_TOTAL_PIXELS,
                    250000,
                    SCREENSHOT_MAX_TOTAL_PIXELS);

                var requestedTarget = string.IsNullOrEmpty(target) ? "window" : target.ToLowerInvariant();
                if (requestedTarget != "screen")
                {
                    requestedTarget = "window";
                }

                if (requestedTarget == "window")
                {
                    return TakeWindowScreenshotWithFallback(
                        effectiveMaxLongEdge,
                        effectiveMaxTotalPixels);
                }

                return TakeScreenScreenshot(
                    fallbackReason: null,
                    requestedTarget: "screen",
                    effectiveMaxLongEdge,
                    effectiveMaxTotalPixels);
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"ComputerUseService: TakeScreenshot failed: {ex.Message}");
                return new { success = false, message = ex.Message };
            }
        }

        /// <summary>
        /// Capture one already-validated HWND without any screen fallback. This
        /// is used by the semantic desktop engine so an unrelated foreground
        /// window can never become VLM or work-log evidence.
        /// </summary>
        public object TakeWindowScreenshot(
            long windowHandle,
            int? maxLongEdge = null,
            long? maxTotalPixels = null)
        {
            try
            {
                var hwnd = new IntPtr(windowHandle);
                if (hwnd == IntPtr.Zero || !IsWindow(hwnd))
                    return new { success = false, failure_code = "invalid_window" };
                if (IsIconic(hwnd))
                    return new { success = false, failure_code = "window_minimized" };
                if (!IsWindowVisible(hwnd))
                    return new { success = false, failure_code = "window_invisible" };
                if (!GetWindowRect(hwnd, out var rect) || rect.Width < 100 || rect.Height < 100)
                    return new { success = false, failure_code = "invalid_window_rect" };

                int effectiveMaxLongEdge = Math.Clamp(
                    maxLongEdge ?? SCREENSHOT_MAX_LONG_EDGE,
                    512,
                    SCREENSHOT_MAX_LONG_EDGE);
                long effectiveMaxTotalPixels = Math.Clamp(
                    maxTotalPixels ?? SCREENSHOT_MAX_TOTAL_PIXELS,
                    250000,
                    SCREENSHOT_MAX_TOTAL_PIXELS);
                return CaptureWindowRegion(hwnd, rect, effectiveMaxLongEdge, effectiveMaxTotalPixels);
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"ComputerUseService: explicit window screenshot failed: {ex.Message}");
                return new { success = false, failure_code = "window_capture_failed", message = ex.Message };
            }
        }

        /// <summary>
        /// 窗口模式截图：尝试截取前台窗口，前置检查失败时回退到全屏截图并在响应中标记 fallback_reason。
        /// </summary>
        private object TakeWindowScreenshotWithFallback(int maxLongEdge, long maxTotalPixels)
        {
            var hwnd = GetForegroundWindow();

            // 1. 前台窗口存在性
            if (hwnd == IntPtr.Zero)
            {
                return TakeScreenScreenshot(
                    fallbackReason: "no_foreground_window",
                    requestedTarget: "window",
                    maxLongEdge,
                    maxTotalPixels);
            }

            // 2. 最小化检测
            if (IsIconic(hwnd))
            {
                return TakeScreenScreenshot(
                    fallbackReason: "foreground_window_minimized",
                    requestedTarget: "window",
                    maxLongEdge,
                    maxTotalPixels);
            }

            // 3. 可见性检测
            if (!IsWindowVisible(hwnd))
            {
                return TakeScreenScreenshot(
                    fallbackReason: "foreground_window_invisible",
                    requestedTarget: "window",
                    maxLongEdge,
                    maxTotalPixels);
            }

            // 4. 矩形有效性
            if (!GetWindowRect(hwnd, out var rect))
            {
                return TakeScreenScreenshot(
                    fallbackReason: "invalid_window_rect",
                    requestedTarget: "window",
                    maxLongEdge,
                    maxTotalPixels);
            }
            if (rect.Width < 100 || rect.Height < 100)
            {
                return TakeScreenScreenshot(
                    fallbackReason: "invalid_window_rect",
                    requestedTarget: "window",
                    maxLongEdge,
                    maxTotalPixels);
            }

            // 所有前置检查通过 → 执行窗口区域截图
            return CaptureWindowRegion(hwnd, rect, maxLongEdge, maxTotalPixels);
        }

        /// <summary>
        /// 实际截取窗口矩形区域，附加窗口元数据（window-relative 坐标系）。
        /// </summary>
        private object CaptureWindowRegion(
            IntPtr hwnd,
            RECT rect,
            int maxLongEdge,
            long maxTotalPixels)
        {
            int originX = rect.Left;
            int originY = rect.Top;
            int width = rect.Width;
            int height = rect.Height;

            using var bitmap = new Bitmap(width, height, PixelFormat.Format32bppArgb);
            using (var graphics = Graphics.FromImage(bitmap))
            {
                graphics.CopyFromScreen(originX, originY, 0, 0, new Size(width, height), CopyPixelOperation.SourceCopy);
            }

            int originalWidth = width;
            int originalHeight = height;
            double scaleRatio = ComputeScaleRatio(
                originalWidth,
                originalHeight,
                maxLongEdge,
                maxTotalPixels);

            var (scaledWidth, scaledHeight, imageBytes) = ScaleAndEncode(bitmap, originalWidth, originalHeight, scaleRatio);
            var base64 = Convert.ToBase64String(imageBytes);

            string? windowTitle = GetWindowTitle(hwnd);
            var (processName, processId) = GetWindowProcessInfo(hwnd);

            return new
            {
                success = true,
                image = base64,
                width = scaledWidth,
                height = scaledHeight,
                original_width = originalWidth,
                original_height = originalHeight,
                scaled_width = scaledWidth,
                scaled_height = scaledHeight,
                scale_ratio = scaleRatio,
                coordinate_system = "window-relative",
                origin_x = originX,
                origin_y = originY,
                window_bounds = new { x = originX, y = originY, width, height },
                window_title = windowTitle,
                process_name = processName,
                process_id = processId,
                hwnd = (long?)hwnd.ToInt64(),
                target = "window",
                fallback_reason = (string?)null
            };
        }

        /// <summary>
        /// 全屏截图：target="screen" 模式或窗口截图回退时使用。
        /// fallbackReason 非 null 时表示请求了 window 但回退到 screen，target 字段标记为 "screen"。
        /// </summary>
        private object TakeScreenScreenshot(
            string? fallbackReason,
            string requestedTarget,
            int maxLongEdge,
            long maxTotalPixels)
        {
            var originX = GetSystemMetrics(SM_XVIRTUALSCREEN);
            var originY = GetSystemMetrics(SM_YVIRTUALSCREEN);
            var screenWidth = GetSystemMetrics(SM_CXVIRTUALSCREEN);
            var screenHeight = GetSystemMetrics(SM_CYVIRTUALSCREEN);
            if (screenWidth <= 0 || screenHeight <= 0)
            {
                return new { success = false, message = "无法获取屏幕分辨率" };
            }

            using var bitmap = new Bitmap(screenWidth, screenHeight, PixelFormat.Format32bppArgb);
            using (var graphics = Graphics.FromImage(bitmap))
            {
                graphics.CopyFromScreen(originX, originY, 0, 0, new Size(screenWidth, screenHeight), CopyPixelOperation.SourceCopy);
            }

            int originalWidth = screenWidth;
            int originalHeight = screenHeight;
            double scaleRatio = ComputeScaleRatio(
                originalWidth,
                originalHeight,
                maxLongEdge,
                maxTotalPixels);

            var (scaledWidth, scaledHeight, imageBytes) = ScaleAndEncode(bitmap, originalWidth, originalHeight, scaleRatio);
            var base64 = Convert.ToBase64String(imageBytes);

            // 实际 target：回退时为 "screen"，否则按请求
            string actualTarget = fallbackReason != null ? "screen" : requestedTarget;

            return new
            {
                success = true,
                image = base64,
                width = scaledWidth,
                height = scaledHeight,
                original_width = originalWidth,
                original_height = originalHeight,
                scaled_width = scaledWidth,
                scaled_height = scaledHeight,
                scale_ratio = scaleRatio,
                coordinate_system = "screen",
                origin_x = originX,
                origin_y = originY,
                window_bounds = new { x = originX, y = originY, width = screenWidth, height = screenHeight },
                window_title = "Virtual Desktop",
                process_name = (string?)null,
                process_id = (int?)null,
                hwnd = (long?)null,
                target = actualTarget,
                fallback_reason = fallbackReason
            };
        }

        /// <summary>
        /// 复用 ComputeScaleRatio 计算的缩放比，对原始位图执行 HighQualityBicubic 缩放并编码为 PNG。
        /// scale_ratio ≥ 1.0 时直接保存原图。
        /// </summary>
        private static (int scaledWidth, int scaledHeight, byte[] imageBytes) ScaleAndEncode(Bitmap bitmap, int originalWidth, int originalHeight, double scaleRatio)
        {
            if (scaleRatio < 1.0)
            {
                int scaledWidth = Math.Max(1, (int)Math.Round(originalWidth * scaleRatio));
                int scaledHeight = Math.Max(1, (int)Math.Round(originalHeight * scaleRatio));

                using var scaled = new Bitmap(scaledWidth, scaledHeight, PixelFormat.Format32bppArgb);
                using (var g = Graphics.FromImage(scaled))
                {
                    g.InterpolationMode = System.Drawing.Drawing2D.InterpolationMode.HighQualityBicubic;
                    g.PixelOffsetMode = System.Drawing.Drawing2D.PixelOffsetMode.HighQuality;
                    g.CompositingQuality = System.Drawing.Drawing2D.CompositingQuality.HighQuality;
                    g.DrawImage(bitmap, 0, 0, scaledWidth, scaledHeight);
                }
                using var ms = new MemoryStream();
                scaled.Save(ms, ImageFormat.Png);
                return (scaledWidth, scaledHeight, ms.ToArray());
            }
            else
            {
                using var ms = new MemoryStream();
                bitmap.Save(ms, ImageFormat.Png);
                return (originalWidth, originalHeight, ms.ToArray());
            }
        }

        /// <summary>
        /// 读取窗口标题，失败返回 null。
        /// </summary>
        private static string? GetWindowTitle(IntPtr hwnd)
        {
            try
            {
                const int MAX_TITLE = 512;
                var sb = new System.Text.StringBuilder(MAX_TITLE);
                if (GetWindowTextW(hwnd, sb, MAX_TITLE) > 0)
                {
                    return sb.ToString();
                }
            }
            catch { /* 标题获取非关键 */ }
            return null;
        }

        /// <summary>
        /// 通过 HWND 获取进程名与 PID。
        /// GetWindowThreadProcessId 失败时返回 (null, null)；
        /// Process.GetProcessById 抛 ArgumentException（进程已退出）时 process_name=null，process_id 仍返回。
        /// </summary>
        private static (string? processName, int? processId) GetWindowProcessInfo(IntPtr hwnd)
        {
            try
            {
                uint pid;
                uint tid = GetWindowThreadProcessId(hwnd, out pid);
                if (tid == 0 || pid == 0)
                {
                    return (null, null);
                }

                string? processName = null;
                try
                {
                    var proc = Process.GetProcessById((int)pid);
                    processName = proc.ProcessName;
                }
                catch (ArgumentException)
                {
                    // PID 已退出 → process_name 留 null
                }
                catch
                {
                    // 其他异常 → process_name 留 null
                }

                return (processName, (int)pid);
            }
            catch
            {
                return (null, null);
            }
        }

        /// <summary>
        /// 按 Anthropic 规范计算缩放比：长边 ≤1568 且 总像素 ≤1.15MP。
        /// 取两者中更小的比例，未超阈值返回 1.0。
        /// </summary>
        private static double ComputeScaleRatio(int width, int height)
        {
            return ComputeScaleRatio(
                width,
                height,
                SCREENSHOT_MAX_LONG_EDGE,
                SCREENSHOT_MAX_TOTAL_PIXELS);
        }

        private static double ComputeScaleRatio(
            int width,
            int height,
            int maxLongEdge,
            long maxTotalPixels)
        {
            long totalPixels = (long)width * height;
            int longEdge = Math.Max(width, height);

            if (longEdge <= maxLongEdge && totalPixels <= maxTotalPixels)
            {
                return 1.0;
            }

            double longEdgeScale = (double)maxLongEdge / longEdge;
            double pixelScale = Math.Sqrt((double)maxTotalPixels / totalPixels);
            double ratio = Math.Min(longEdgeScale, pixelScale);
            return ratio < 1.0 ? ratio : 1.0;
        }

        /// <summary>
        /// 将 AI 返回的缩放坐标系坐标还原为物理坐标系坐标。
        /// scale_ratio = scaled_long_edge / original_long_edge，物理坐标 = AI坐标 / scale_ratio。
        /// </summary>
        private static (int physX, int physY) RestoreCoords(int x, int y, double scaleRatio)
        {
            if (scaleRatio <= 0 || scaleRatio == 1.0) return (x, y);
            return ((int)Math.Round(x / scaleRatio), (int)Math.Round(y / scaleRatio));
        }

        /// <summary>
        /// 获取当前鼠标光标在屏幕上的坐标（像素，左上角为 0,0）。
        /// 用于让 AI 在点击前确认鼠标当前位置，辅助精确定位。
        /// </summary>
        public object GetCursorPosition()
        {
            try
            {
                if (!GetCursorPos(out var pt))
                {
                    return new { success = false, message = "GetCursorPos 调用失败" };
                }
                return new { success = true, x = pt.X, y = pt.Y };
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"ComputerUseService: GetCursorPosition failed: {ex.Message}");
                return new { success = false, message = ex.Message };
            }
        }

        /// <summary>
        /// 将鼠标光标移动到指定坐标。
        /// 使用 SendInput 的绝对坐标模式（MOUSEEVENTF_ABSOLUTE|MOVE）以确保
        /// 即使从后台线程调用也能可靠移动光标。
        /// scaleRatio != 1.0 时，将 AI 返回的缩放坐标系坐标还原为物理坐标。
        /// </summary>
        public object MouseMove(int x, int y, double scaleRatio = 1.0)
        {
            try
            {
                var (screenWidth, screenHeight) = GetScreenSize();
                if (screenWidth <= 0 || screenHeight <= 0)
                {
                    return new { success = false, message = "无法获取屏幕分辨率" };
                }

                var (physX, physY) = RestoreCoords(x, y, scaleRatio);
                var (absX, absY) = ToAbsoluteCoords(physX, physY, screenWidth, screenHeight);
                var inputs = new[]
                {
                    BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE, 0, absX, absY),
                };

                var sent = SendInput((uint)inputs.Length, inputs, Marshal.SizeOf<INPUT>());
                if (sent != inputs.Length)
                {
                    return new { success = false, message = $"SendInput 返回 {sent}（期望 {inputs.Length}）" };
                }
                return new { success = true };
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"ComputerUseService: MouseMove failed: {ex.Message}");
                return new { success = false, message = ex.Message };
            }
        }

        /// <summary>
        /// 在 (x, y) 处执行鼠标点击。button 取值 left/right/middle（默认 left）；
        /// click 取值 single/double（默认 single）。
        /// 使用 SendInput 的绝对坐标模式（MOUSEEVENTF_ABSOLUTE|MOVE）以替代 SetCursorPos，
        /// 确保鼠标真正移动到目标位置后触发点击事件。
        /// scaleRatio != 1.0 时，将 AI 返回的缩放坐标系坐标还原为物理坐标。
        /// </summary>
        public object MouseClick(int x, int y, string button = "left", string click = "single", double scaleRatio = 1.0)
        {
            try
            {
                var (screenWidth, screenHeight) = GetScreenSize();
                if (screenWidth <= 0 || screenHeight <= 0)
                {
                    return new { success = false, message = "无法获取屏幕分辨率" };
                }

                // 1. 解析按键
                var (downFlag, upFlag) = (button ?? "left").ToLowerInvariant() switch
                {
                    "right" => (MOUSEEVENTF_RIGHTDOWN, MOUSEEVENTF_RIGHTUP),
                    "middle" => (MOUSEEVENTF_MIDDLEDOWN, MOUSEEVENTF_MIDDLEUP),
                    _ => (MOUSEEVENTF_LEFTDOWN, MOUSEEVENTF_LEFTUP),
                };

                // 2. 解析点击次数
                int clicks = string.Equals(click, "double", StringComparison.OrdinalIgnoreCase) ? 2 : 1;

                // 3. 还原坐标 + 计算绝对坐标（0-65535 映射到主屏幕）
                var (physX, physY) = RestoreCoords(x, y, scaleRatio);
                var (absX, absY) = ToAbsoluteCoords(physX, physY, screenWidth, screenHeight);

                // 4. 发送 SendInput（down/up 均带 ABSOLUTE|MOVE，确保鼠标在目标位置触发点击）
                var inputs = new INPUT[clicks * 2];
                for (int i = 0; i < clicks; i++)
                {
                    inputs[i * 2] = BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE | downFlag, 0, absX, absY);
                    inputs[i * 2 + 1] = BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE | upFlag, 0, absX, absY);
                }

                var sent = SendInput((uint)inputs.Length, inputs, Marshal.SizeOf<INPUT>());
                if (sent != inputs.Length)
                {
                    return new { success = false, message = $"SendInput 返回 {sent}（期望 {inputs.Length}）" };
                }
                return new { success = true };
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"ComputerUseService: MouseClick failed: {ex.Message}");
                return new { success = false, message = ex.Message };
            }
        }

        /// <summary>
        /// 滚动鼠标滚轮，delta 为正向上滚、为负向下滚（标准 WHEEL_DELTA = 120）。
        /// </summary>
        public object MouseScroll(int delta)
        {
            try
            {
                var inputs = new[]
                {
                    BuildMouseInput(MOUSEEVENTF_WHEEL, (uint)delta),
                };

                var sent = SendInput((uint)inputs.Length, inputs, Marshal.SizeOf<INPUT>());
                if (sent != inputs.Length)
                {
                    return new { success = false, message = $"SendInput 返回 {sent}（期望 {inputs.Length}）" };
                }
                return new { success = true };
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"ComputerUseService: MouseScroll failed: {ex.Message}");
                return new { success = false, message = ex.Message };
            }
        }

        /// <summary>
        /// 鼠标拖动操作：从 (fromX, fromY) 按住左键拖动到 (toX, toY)。
        /// 用于画图、拖拽文件等需要按住鼠标移动的场景。
        /// 实现步骤：移动起点 → 按下 → 插值移动（平滑） → 释放。
        /// 插值点数 = max(10, 距离/5)，确保拖动轨迹平滑，画图线条连续。
        ///
        /// 支持曲线模式：传入 points 路径点数组时，使用 Catmull-Rom 样条插值生成平滑曲线，
        /// 而非简单的线性连线。points 非空时忽略 from/to。
        /// scaleRatio != 1.0 时，将 AI 返回的缩放坐标系坐标还原为物理坐标（含 from/to 和 points）。
        /// </summary>
        public object MouseDrag(int fromX, int fromY, int toX, int toY, string button = "left", List<DragPoint>? points = null, double scaleRatio = 1.0)
        {
            try
            {
                var (screenWidth, screenHeight) = GetScreenSize();
                if (screenWidth <= 0 || screenHeight <= 0)
                {
                    return new { success = false, message = "无法获取屏幕分辨率" };
                }

                var (downFlag, upFlag) = (button ?? "left").ToLowerInvariant() switch
                {
                    "right" => (MOUSEEVENTF_RIGHTDOWN, MOUSEEVENTF_RIGHTUP),
                    "middle" => (MOUSEEVENTF_MIDDLEDOWN, MOUSEEVENTF_MIDDLEUP),
                    _ => (MOUSEEVENTF_LEFTDOWN, MOUSEEVENTF_LEFTUP),
                };

                // 还原 from/to 到物理坐标系
                var (physFromX, physFromY) = RestoreCoords(fromX, fromY, scaleRatio);
                var (physToX, physToY) = RestoreCoords(toX, toY, scaleRatio);

                var inputList = new List<INPUT>();

                // 曲线模式：points 非空时用 Catmull-Rom 样条插值生成平滑曲线
                if (points != null && points.Count >= 2)
                {
                    // 还原每个路径点坐标到物理坐标系
                    var physPoints = new List<DragPoint>(points.Count);
                    foreach (var p in points)
                    {
                        var (ppx, ppy) = RestoreCoords(p.X, p.Y, scaleRatio);
                        physPoints.Add(new DragPoint { X = ppx, Y = ppy });
                    }

                    // 起点：移动到第一个点并按下
                    var (startAbsX, startAbsY) = ToAbsoluteCoords(physPoints[0].X, physPoints[0].Y, screenWidth, screenHeight);
                    inputList.Add(BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE, 0, startAbsX, startAbsY));
                    inputList.Add(BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE | downFlag, 0, startAbsX, startAbsY));

                    // Catmull-Rom 样条插值：对每对相邻控制点 P1-P2，
                    // 使用 P0(前一点) 和 P3(后一点) 作为切线参考，生成平滑曲线
                    // 首段 P0=P1（复制首点），末段 P3=P2（复制末点）
                    int splineSteps = 20; // 每段插值步数（越大越平滑）
                    for (int seg = 0; seg < physPoints.Count - 1; seg++)
                    {
                        // 四个控制点：P0, P1, P2, P3
                        var p0 = seg == 0 ? physPoints[0] : physPoints[seg - 1];
                        var p1 = physPoints[seg];
                        var p2 = physPoints[seg + 1];
                        var p3 = (seg + 2 < physPoints.Count) ? physPoints[seg + 2] : physPoints[physPoints.Count - 1];

                        double p0x = p0.X, p0y = p0.Y;
                        double p1x = p1.X, p1y = p1.Y;
                        double p2x = p2.X, p2y = p2.Y;
                        double p3x = p3.X, p3y = p3.Y;

                        for (int i = 1; i <= splineSteps; i++)
                        {
                            double t = (double)i / splineSteps;
                            double t2 = t * t;
                            double t3 = t2 * t;

                            // Catmull-Rom 样条公式
                            double x = 0.5 * (
                                (2 * p1x) +
                                (-p0x + p2x) * t +
                                (2 * p0x - 5 * p1x + 4 * p2x - p3x) * t2 +
                                (-p0x + 3 * p1x - 3 * p2x + p3x) * t3);
                            double y = 0.5 * (
                                (2 * p1y) +
                                (-p0y + p2y) * t +
                                (2 * p0y - 5 * p1y + 4 * p2y - p3y) * t2 +
                                (-p0y + 3 * p1y - 3 * p2y + p3y) * t3);

                            int absX = (int)Math.Round(x);
                            int absY = (int)Math.Round(y);
                            int virtualLeft = GetSystemMetrics(SM_XVIRTUALSCREEN);
                            int virtualTop = GetSystemMetrics(SM_YVIRTUALSCREEN);
                            absX = Math.Max(virtualLeft, Math.Min(virtualLeft + screenWidth - 1, absX));
                            absY = Math.Max(virtualTop, Math.Min(virtualTop + screenHeight - 1, absY));

                            var (aAbsX, aAbsY) = ToAbsoluteCoords(absX, absY, screenWidth, screenHeight);
                            inputList.Add(BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE, 0, aAbsX, aAbsY));
                        }
                    }

                    // 在最后一个点释放
                    var last = physPoints[physPoints.Count - 1];
                    var (endAbsX, endAbsY) = ToAbsoluteCoords(last.X, last.Y, screenWidth, screenHeight);
                    inputList.Add(BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE | upFlag, 0, endAbsX, endAbsY));

                    var inputs = inputList.ToArray();
                    var sent = SendInput((uint)inputs.Length, inputs, Marshal.SizeOf<INPUT>());
                    if (sent != inputs.Length)
                    {
                        return new { success = false, message = $"SendInput 返回 {sent}（期望 {inputs.Length}）" };
                    }
                    return new { success = true, mode = "curve", points_count = physPoints.Count, interpolation = "catmull-rom", total_inputs = inputs.Length };
                }

                // 直线模式（原逻辑）
                var (fromAbsX, fromAbsY) = ToAbsoluteCoords(physFromX, physFromY, screenWidth, screenHeight);
                var (toAbsX, toAbsY) = ToAbsoluteCoords(physToX, physToY, screenWidth, screenHeight);

                // 插值点数：至少 10 个，距离越大点越多（每 5 像素一个点）
                double dist = Math.Sqrt((physToX - physFromX) * (physToX - physFromX) + (physToY - physFromY) * (physToY - physFromY));
                int steps = Math.Max(10, (int)(dist / 5));

                // 1. 移动到起点
                inputList.Add(BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE, 0, fromAbsX, fromAbsY));

                // 2. 按下左键（在起点位置）
                inputList.Add(BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE | downFlag, 0, fromAbsX, fromAbsY));

                // 3. 插值移动到终点（按住左键）
                for (int i = 1; i <= steps; i++)
                {
                    double t = (double)i / steps;
                    int absX = (int)(fromAbsX + (toAbsX - fromAbsX) * t);
                    int absY = (int)(fromAbsY + (toAbsY - fromAbsY) * t);
                    absX = Math.Max(0, Math.Min(65535, absX));
                    absY = Math.Max(0, Math.Min(65535, absY));
                    inputList.Add(BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE, 0, absX, absY));
                }

                // 4. 释放左键（在终点位置）
                inputList.Add(BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE | upFlag, 0, toAbsX, toAbsY));

                var inputsArr = inputList.ToArray();
                var sentArr = SendInput((uint)inputsArr.Length, inputsArr, Marshal.SizeOf<INPUT>());
                if (sentArr != inputsArr.Length)
                {
                    return new { success = false, message = $"SendInput 返回 {sentArr}（期望 {inputsArr.Length}）" };
                }
                return new { success = true, mode = "line", from = new { x = physFromX, y = physFromY }, to = new { x = physToX, y = physToY }, steps = steps };
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"ComputerUseService: MouseDrag failed: {ex.Message}");
                return new { success = false, message = ex.Message };
            }
        }

        /// <summary>
        /// 鼠标长按：在 (x, y) 位置按下指定按键并保持 duration 毫秒后释放。
        /// 用于长按左键/右键/中键场景，如长按弹出菜单、长按文件、长按预备拖动等。
        /// duration 限制在 100-5000 毫秒，防止异常长按阻塞 UI 线程。
        /// </summary>
        public object MouseHold(int x, int y, string button = "left", int duration = 500)
        {
            try
            {
                // 限制时长范围，防止异常值
                duration = Math.Max(100, Math.Min(5000, duration));

                var (screenWidth, screenHeight) = GetScreenSize();
                if (screenWidth <= 0 || screenHeight <= 0)
                {
                    return new { success = false, message = "无法获取屏幕分辨率" };
                }

                var (downFlag, upFlag) = (button ?? "left").ToLowerInvariant() switch
                {
                    "right" => (MOUSEEVENTF_RIGHTDOWN, MOUSEEVENTF_RIGHTUP),
                    "middle" => (MOUSEEVENTF_MIDDLEDOWN, MOUSEEVENTF_MIDDLEUP),
                    _ => (MOUSEEVENTF_LEFTDOWN, MOUSEEVENTF_LEFTUP),
                };

                var (absX, absY) = ToAbsoluteCoords(x, y, screenWidth, screenHeight);

                var inputs = new INPUT[3];
                // 1. 移动到目标位置
                inputs[0] = BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE, 0, absX, absY);
                // 2. 按下按键
                inputs[1] = BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE | downFlag, 0, absX, absY);
                // 3. 保持 duration 毫秒后释放（在当前线程 sleep）
                var sent = SendInput(2, inputs, Marshal.SizeOf<INPUT>());
                if (sent != 2)
                {
                    return new { success = false, message = $"SendInput (down) 返回 {sent}" };
                }

                // 在按下和释放之间保持指定时长
                System.Threading.Thread.Sleep(duration);

                // 释放按键
                var upInput = BuildMouseInput(MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_MOVE | upFlag, 0, absX, absY);
                sent = SendInput(1, new[] { upInput }, Marshal.SizeOf<INPUT>());
                if (sent != 1)
                {
                    return new { success = false, message = $"SendInput (up) 返回 {sent}" };
                }
                return new { success = true, x, y, button, duration_ms = duration };
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"ComputerUseService: MouseHold failed: {ex.Message}");
                return new { success = false, message = ex.Message };
            }
        }

        /// <summary>
        /// 通过三重降级策略精确激活指定窗口，彻底消除 Alt+Tab 回退。
        ///
        /// 降级链：
        ///   Level 1: AllowSetForegroundWindow(ASFW_ANY) + SetForegroundWindow
        ///   Level 2: AttachThreadInput 绕过前台线程归属限制
        ///   Level 3: SwitchToThisWindow（最终兜底）
        /// </summary>
        /// <param name="hwnd">目标窗口句柄（来自 GetWindowSnapshot 返回的 hwnd 字段）</param>
        /// <returns>{ success, message, title? }</returns>
        public object FocusWindow(long hwnd)
        {
            try
            {
                if (hwnd == 0)
                {
                    return new { success = false, message = "无效的窗口句柄 (hwnd=0)" };
                }
                var h = new IntPtr(hwnd);

                // 窗口最小化则还原
                if (IsIconic(h))
                {
                    ShowWindow(h, SW_RESTORE);
                }

                string? lastError = null;

                // ── Level 1: AllowSetForegroundWindow + SetForegroundWindow ──
                // 授予调用线程前台切换权限后 SetForegroundWindow
                AllowSetForegroundWindow(ASFW_ANY);
                ShowWindow(h, SW_SHOW);
                if (SetForegroundWindow(h))
                {
                    System.Threading.Thread.Sleep(80);
                    if (GetForegroundWindow() == h)
                        return SuccessResult(h);
                }
                lastError = "Level1(SetForegroundWindow) 失败";

                // ── Level 2: AttachThreadInput 前台窃取 ──
                // 将当前线程附加到目标窗口的输入线程，窃取前台权限后激活
                uint targetThreadId = GetWindowThreadProcessId(h, out _);
                IntPtr foregroundHwnd = GetForegroundWindow();
                uint foregroundThreadId = GetWindowThreadProcessId(foregroundHwnd, out _);
                uint currentThreadId = GetCurrentThreadId();

                if (targetThreadId != 0 && foregroundThreadId != 0 && currentThreadId != 0)
                {
                    bool attachedForeground = currentThreadId == foregroundThreadId
                        || AttachThreadInput(currentThreadId, foregroundThreadId, true);
                    bool attachedTarget = currentThreadId == targetThreadId
                        || AttachThreadInput(currentThreadId, targetThreadId, true);
                    if (attachedForeground && attachedTarget)
                    {
                        BringWindowToTop(h);
                        SetForegroundWindow(h);
                        SetFocus(h);
                        if (currentThreadId != targetThreadId)
                            AttachThreadInput(currentThreadId, targetThreadId, false);
                        if (currentThreadId != foregroundThreadId)
                            AttachThreadInput(currentThreadId, foregroundThreadId, false);

                        // 验证是否激活成功
                        System.Threading.Thread.Sleep(80);
                        if (GetForegroundWindow() == h)
                        {
                            return SuccessResult(h);
                        }
                    }
                    else
                    {
                        if (attachedTarget && currentThreadId != targetThreadId)
                            AttachThreadInput(currentThreadId, targetThreadId, false);
                        if (attachedForeground && currentThreadId != foregroundThreadId)
                            AttachThreadInput(currentThreadId, foregroundThreadId, false);
                    }
                }
                lastError = "Level2(AttachThreadInput) 失败";

                // ── Level 3: SwitchToThisWindow（最终兜底） ──
                // 非官方文档但广泛使用的 API，绝大部分情况下有效
                try
                {
                    SwitchToThisWindow(h, true);
                    ShowWindow(h, SW_SHOW);
                    // 短暂等待窗口响应
                    System.Threading.Thread.Sleep(100);
                    if (GetForegroundWindow() == h)
                    {
                        return SuccessResult(h);
                    }
                }
                catch
                {
                    // SwitchToThisWindow 在某些旧 Windows 版本上可能不支持
                }
                lastError = "Level3(SwitchToThisWindow) 也失败";

                return new { success = false, message = $"窗口切换失败（{lastError}），可尝试手动点击任务栏切换" };
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"ComputerUseService: FocusWindow failed: {ex.Message}");
                return new { success = false, message = ex.Message };
            }
        }

        /// <summary>
        /// 获取当前前台窗口句柄。
        /// </summary>
        [DllImport("user32.dll", SetLastError = true)]
        private static extern IntPtr GetForegroundWindow();

        /// <summary>
        /// 构建 FocusWindow 成功响应，读窗口标题。
        /// </summary>
        private object SuccessResult(IntPtr h)
        {
            string? title = null;
            try
            {
                const int MAX_TITLE = 256;
                var sb = new System.Text.StringBuilder(MAX_TITLE);
                if (GetWindowTextW(h, sb, MAX_TITLE) > 0)
                    title = sb.ToString();
            }
            catch { /* 标题获取非关键 */ }
            return new { success = true, message = "窗口已激活", title };
        }

        /// <summary>
        /// 逐字符输入文本。优先通过 VkKeyScan 映射为虚拟键（支持 Shift 修饰键）；
        /// 对于 VkKeyScan 无法映射的字符（如中文、emoji 等），改用 KEYEVENTF_UNICODE
        /// 直接发送 Unicode 扫描码，绕过键盘布局和输入法限制。
        /// </summary>
        public object KeyboardType(string text)
        {
            try
            {
                if (string.IsNullOrEmpty(text))
                {
                    return new { success = true };
                }

                foreach (var ch in text)
                {
                    var vks = VkKeyScanW(ch);
                    if (vks == -1)
                    {
                        // VkKeyScan 无法映射（中文等非 ASCII 字符）→ 使用 Unicode 直接输入
                        var uniInputs = new[]
                        {
                            BuildUnicodeKeyInput(ch, false),
                            BuildUnicodeKeyInput(ch, true),
                        };
                        SendInput((uint)uniInputs.Length, uniInputs, Marshal.SizeOf<INPUT>());
                        continue;
                    }

                    var vk = (ushort)(vks & 0xFF);
                    var shiftState = (byte)((vks >> 8) & 0xFF);

                    var inputs = new List<INPUT>(capacity: 4);
                    bool needShift = (shiftState & 0x01) != 0;
                    if (needShift)
                    {
                        inputs.Add(BuildKeyInput(VK_SHIFT, false));
                    }

                    inputs.Add(BuildKeyInput(vk, false));
                    inputs.Add(BuildKeyInput(vk, true));

                    if (needShift)
                    {
                        inputs.Add(BuildKeyInput(VK_SHIFT, true));
                    }

                    var arr = inputs.ToArray();
                    SendInput((uint)arr.Length, arr, Marshal.SizeOf<INPUT>());
                }

                return new { success = true };
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"ComputerUseService: KeyboardType failed: {ex.Message}");
                return new { success = false, message = ex.Message };
            }
        }

        /// <summary>
        /// 输入组合键，如 "ctrl+c" / "alt+f4" / "win+e"。
        /// 多个修饰键以 '+' 分隔，最后一个为普通键。
        /// 支持修饰键：ctrl, alt, shift, win；特殊键：enter, esc, tab, backspace,
        /// delete, space, up, down, left, right, f1-f12；其他字符用 VkKeyScan 映射。
        /// </summary>
        public object KeyPress(string keys)
        {
            try
            {
                if (string.IsNullOrWhiteSpace(keys))
                {
                    return new { success = false, message = "keys 参数为空" };
                }

                var parts = keys.Split('+');
                var modifiers = new List<ushort>();
                var normalKeys = new List<ushort>();

                for (int i = 0; i < parts.Length; i++)
                {
                    var part = parts[i].Trim().ToLowerInvariant();
                    if (part.Length == 0) continue;

                    bool isLast = (i == parts.Length - 1);
                    var vk = MapKeyNameToVk(part);

                    // 如果是最后一个且不是已知修饰键，作为普通键；否则视为修饰键
                    if (isLast && vk != 0 && !IsModifierKey(part))
                    {
                        normalKeys.Add(vk);
                    }
                    else if (IsModifierKey(part))
                    {
                        modifiers.Add(GetModifierVk(part));
                    }
                    else if (vk != 0)
                    {
                        normalKeys.Add(vk);
                    }
                    else if (part.Length == 1)
                    {
                        var vks = VkKeyScanW(part[0]);
                        if (vks != -1)
                        {
                            normalKeys.Add((ushort)(vks & 0xFF));
                        }
                    }
                }

                if (normalKeys.Count == 0)
                {
                    // 单独按修饰键（如 win、shift、ctrl、alt）：作为独立按键发送 down+up
                    if (modifiers.Count == 0)
                    {
                        return new { success = false, message = "未识别出可输入的按键" };
                    }
                    var modInputs = new List<INPUT>();
                    foreach (var m in modifiers)
                    {
                        modInputs.Add(BuildKeyInput(m, false));
                        modInputs.Add(BuildKeyInput(m, true));
                    }
                    var modArr = modInputs.ToArray();
                    var modSent = SendInput((uint)modArr.Length, modArr, Marshal.SizeOf<INPUT>());
                    if (modSent != modArr.Length)
                    {
                        return new { success = false, message = $"SendInput 返回 {modSent}（期望 {modArr.Length}）" };
                    }
                    return new { success = true };
                }

                var inputs = new List<INPUT>();

                // 修饰键按下（按顺序）
                foreach (var m in modifiers)
                {
                    inputs.Add(BuildKeyInput(m, false));
                }

                // 普通键按下+抬起
                foreach (var k in normalKeys)
                {
                    inputs.Add(BuildKeyInput(k, false));
                    inputs.Add(BuildKeyInput(k, true));
                }

                // 修饰键抬起（逆序）
                for (int i = modifiers.Count - 1; i >= 0; i--)
                {
                    inputs.Add(BuildKeyInput(modifiers[i], true));
                }

                var arr = inputs.ToArray();
                var sent = SendInput((uint)arr.Length, arr, Marshal.SizeOf<INPUT>());
                if (sent != arr.Length)
                {
                    return new { success = false, message = $"SendInput 返回 {sent}（期望 {arr.Length}）" };
                }
                return new { success = true };
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"ComputerUseService: KeyPress failed: {ex.Message}");
                return new { success = false, message = ex.Message };
            }
        }

        // ── Helpers ───────────────────────────────────────

        private static INPUT BuildMouseInput(uint flags, uint mouseData = 0, int dx = 0, int dy = 0)
        {
            if ((flags & MOUSEEVENTF_ABSOLUTE) != 0)
            {
                flags |= MOUSEEVENTF_VIRTUALDESK;
            }
            return new INPUT
            {
                type = INPUT_MOUSE,
                u = new INPUT_UNION
                {
                    mi = new MOUSEINPUT
                    {
                        dx = dx,
                        dy = dy,
                        mouseData = mouseData,
                        dwFlags = flags,
                        time = 0,
                        dwExtraInfo = IntPtr.Zero
                    }
                }
            };
        }

        /// <summary>
        /// 获取主屏幕的物理像素尺寸（用于将像素坐标映射为绝对坐标）。
        /// 注意：在 DPI 缩放环境下，GetSystemMetrics 返回的是物理像素，与 SendInput 绝对坐标系一致。
        /// </summary>
        private static (int width, int height) GetScreenSize()
        {
            var w = GetSystemMetrics(SM_CXVIRTUALSCREEN);
            var h = GetSystemMetrics(SM_CYVIRTUALSCREEN);
            return (w, h);
        }

        /// <summary>
        /// 将像素坐标 (x, y) 转换为 SendInput 使用的绝对坐标（0-65535 映射到主屏幕）。
        /// 使用浮点计算避免整数截断误差。
        /// </summary>
        private static (int absX, int absY) ToAbsoluteCoords(int x, int y, int screenWidth, int screenHeight)
        {
            int virtualLeft = GetSystemMetrics(SM_XVIRTUALSCREEN);
            int virtualTop = GetSystemMetrics(SM_YVIRTUALSCREEN);
            int absX = (int)Math.Round(((double)(x - virtualLeft) * 65535.0) / Math.Max(1, screenWidth - 1));
            int absY = (int)Math.Round(((double)(y - virtualTop) * 65535.0) / Math.Max(1, screenHeight - 1));
            // 限制范围
            absX = Math.Max(0, Math.Min(65535, absX));
            absY = Math.Max(0, Math.Min(65535, absY));
            return (absX, absY);
        }

        private static INPUT BuildKeyInput(ushort vk, bool keyUp)
        {
            return new INPUT
            {
                type = INPUT_KEYBOARD,
                u = new INPUT_UNION
                {
                    ki = new KEYBDINPUT
                    {
                        wVk = vk,
                        wScan = 0,
                        dwFlags = keyUp ? KEYEVENTF_KEYUP : 0u,
                        time = 0,
                        dwExtraInfo = IntPtr.Zero
                    }
                }
            };
        }

        /// <summary>
        /// 构造 Unicode 字符输入：通过 KEYEVENTF_UNICODE 标志直接发送字符的 Unicode 码点，
        /// 绕过键盘布局和输入法，可输入中文、emoji 等 VkKeyScan 无法映射的字符。
        /// </summary>
        private static INPUT BuildUnicodeKeyInput(char ch, bool keyUp)
        {
            return new INPUT
            {
                type = INPUT_KEYBOARD,
                u = new INPUT_UNION
                {
                    ki = new KEYBDINPUT
                    {
                        wVk = 0,
                        wScan = (ushort)ch,
                        dwFlags = (keyUp ? KEYEVENTF_KEYUP : 0u) | KEYEVENTF_UNICODE,
                        time = 0,
                        dwExtraInfo = IntPtr.Zero
                    }
                }
            };
        }

        private static bool IsModifierKey(string name)
        {
            return name == "ctrl" || name == "control" || name == "alt" || name == "shift" || name == "win";
        }

        private static ushort GetModifierVk(string name)
        {
            return name switch
            {
                "ctrl" or "control" => VK_CONTROL,
                "alt" => VK_MENU,
                "shift" => VK_SHIFT,
                "win" => VK_LWIN,
                _ => 0
            };
        }

        private static ushort MapKeyNameToVk(string name)
        {
            return name switch
            {
                "enter" or "return" => VK_RETURN,
                "esc" or "escape" => VK_ESCAPE,
                "tab" => VK_TAB,
                "backspace" or "back" => VK_BACK,
                "delete" or "del" => VK_DELETE,
                "space" => VK_SPACE,
                "up" => VK_UP,
                "down" => VK_DOWN,
                "left" => VK_LEFT,
                "right" => VK_RIGHT,
                "ctrl" or "control" => VK_CONTROL,
                "alt" => VK_MENU,
                "shift" => VK_SHIFT,
                "win" => VK_LWIN,
                "f1" => VK_F1,
                "f2" => (ushort)(VK_F1 + 1),
                "f3" => (ushort)(VK_F1 + 2),
                "f4" => (ushort)(VK_F1 + 3),
                "f5" => (ushort)(VK_F1 + 4),
                "f6" => (ushort)(VK_F1 + 5),
                "f7" => (ushort)(VK_F1 + 6),
                "f8" => (ushort)(VK_F1 + 7),
                "f9" => (ushort)(VK_F1 + 8),
                "f10" => (ushort)(VK_F1 + 9),
                "f11" => (ushort)(VK_F1 + 10),
                "f12" => (ushort)(VK_F1 + 11),
                _ => 0
            };
        }

        // ── P/Invoke ──────────────────────────────────────

        [DllImport("user32.dll", SetLastError = true)]
        private static extern uint SendInput(uint nInputs, INPUT[] pInputs, int cbSize);

        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool SetCursorPos(int x, int y);

        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool GetCursorPos(out POINT lpPoint);

        [DllImport("user32.dll", CharSet = CharSet.Unicode)]
        private static extern short VkKeyScanW(char ch);

        [DllImport("user32.dll")]
        private static extern uint MapVirtualKeyW(uint uCode, uint uMapType);

        [DllImport("user32.dll")]
        private static extern int GetSystemMetrics(int nIndex);

        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool SetForegroundWindow(IntPtr hWnd);

        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);

        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool IsIconic(IntPtr hWnd);

        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool IsWindowVisible(IntPtr hWnd);

        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool IsWindow(IntPtr hWnd);

        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool GetWindowRect(IntPtr hWnd, out RECT lpRect);

        [DllImport("user32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        private static extern int GetWindowTextW(IntPtr hWnd, System.Text.StringBuilder lpString, int nMaxCount);

        // ── TakeScreenshot 窗口截图相关结构 ─────────────
        [StructLayout(LayoutKind.Sequential)]
        private struct RECT
        {
            public int Left;
            public int Top;
            public int Right;
            public int Bottom;
            public int Width => Right - Left;
            public int Height => Bottom - Top;
            public Size Size => new Size(Width, Height);
        }

        // ── FocusWindow 三重降级激活 ─────────────────
        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool AllowSetForegroundWindow(uint dwProcessId);

        [DllImport("user32.dll", SetLastError = true)]
        private static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);

        [DllImport("kernel32.dll")]
        private static extern uint GetCurrentThreadId();

        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool AttachThreadInput(uint idAttach, uint idAttachTo, [MarshalAs(UnmanagedType.Bool)] bool fAttach);

        [DllImport("user32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool BringWindowToTop(IntPtr hWnd);

        [DllImport("user32.dll", SetLastError = true)]
        private static extern IntPtr SetFocus(IntPtr hWnd);

        [DllImport("user32.dll", SetLastError = true)]
        private static extern void SwitchToThisWindow(IntPtr hWnd, [MarshalAs(UnmanagedType.Bool)] bool fAltTab);

        private const int SW_RESTORE = 9;
        private const int SW_SHOW = 5;
        private const uint ASFW_ANY = 0xFFFFFFFF;

        // ── Constants ─────────────────────────────────────

        private const int SM_CXSCREEN = 0;
        private const int SM_CYSCREEN = 1;
        private const int SM_XVIRTUALSCREEN = 76;
        private const int SM_YVIRTUALSCREEN = 77;
        private const int SM_CXVIRTUALSCREEN = 78;
        private const int SM_CYVIRTUALSCREEN = 79;

        private const uint INPUT_MOUSE = 0;
        private const uint INPUT_KEYBOARD = 1;
        private const uint INPUT_HARDWARE = 2;

        private const uint MOUSEEVENTF_MOVE = 0x0001;
        private const uint MOUSEEVENTF_LEFTDOWN = 0x0002;
        private const uint MOUSEEVENTF_LEFTUP = 0x0004;
        private const uint MOUSEEVENTF_RIGHTDOWN = 0x0008;
        private const uint MOUSEEVENTF_RIGHTUP = 0x0010;
        private const uint MOUSEEVENTF_MIDDLEDOWN = 0x0020;
        private const uint MOUSEEVENTF_MIDDLEUP = 0x0040;
        private const uint MOUSEEVENTF_WHEEL = 0x0800;
        private const uint MOUSEEVENTF_VIRTUALDESK = 0x4000;
        private const uint MOUSEEVENTF_ABSOLUTE = 0x8000;

        private const uint KEYEVENTF_KEYUP = 0x0002;
        private const uint KEYEVENTF_UNICODE = 0x0004;

        // Virtual key codes
        private const ushort VK_SHIFT = 0x10;
        private const ushort VK_CONTROL = 0x11;
        private const ushort VK_MENU = 0x12;
        private const ushort VK_LWIN = 0x5B;
        private const ushort VK_RETURN = 0x0D;
        private const ushort VK_ESCAPE = 0x1B;
        private const ushort VK_TAB = 0x09;
        private const ushort VK_BACK = 0x08;
        private const ushort VK_DELETE = 0x2E;
        private const ushort VK_SPACE = 0x20;
        private const ushort VK_UP = 0x26;
        private const ushort VK_DOWN = 0x28;
        private const ushort VK_LEFT = 0x25;
        private const ushort VK_RIGHT = 0x27;
        private const ushort VK_F1 = 0x70;

        // ── Structs ──────────────────────────────────────

        [StructLayout(LayoutKind.Sequential)]
        private struct POINT
        {
            public int X;
            public int Y;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct MOUSEINPUT
        {
            public int dx;
            public int dy;
            public uint mouseData;
            public uint dwFlags;
            public uint time;
            public IntPtr dwExtraInfo;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct KEYBDINPUT
        {
            public ushort wVk;
            public ushort wScan;
            public uint dwFlags;
            public uint time;
            public IntPtr dwExtraInfo;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct HARDWAREINPUT
        {
            public uint uMsg;
            public ushort wParamL;
            public ushort wParamH;
        }

        [StructLayout(LayoutKind.Explicit)]
        private struct INPUT_UNION
        {
            [FieldOffset(0)] public MOUSEINPUT mi;
            [FieldOffset(0)] public KEYBDINPUT ki;
            [FieldOffset(0)] public HARDWAREINPUT hi;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct INPUT
        {
            public uint type;
            public INPUT_UNION u;
        }
    }
}
