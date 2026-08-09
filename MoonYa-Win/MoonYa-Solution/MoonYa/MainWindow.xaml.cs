// ┌─────────────────────────────────────────────────────────┐
// │  MainWindow — Launcher orchestration                    │
// │  1. Start PHP built-in server (port auto-detect)        │
// │  2. Load MoonYa-main/index.php via CefSharp              │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Drawing;
using System.IO;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Media.Animation;
using System.Windows.Threading;
using CefSharp;
using MoonYa.Services;
using WinForms = System.Windows.Forms;

namespace MoonYa
{
    public partial class MainWindow : Window
    {
        private bool _isMaximized;
        private bool _initialized;
        private double _restoreLeft, _restoreTop, _restoreWidth, _restoreHeight;
        private bool _isClosing;
        private bool _isExiting;
        private bool _isMinimizing;
        private readonly FileOperationService _fileService;
        private bool _isMouseOperationInProgress;
        private bool _fileOpsBridgeRegistered;
        private MoonYaFileOpsBridge? _fileOpsBridge;

        // Push-to-Talk: low-level keyboard hook state
        private IntPtr _keyboardHookId = IntPtr.Zero;
        private LowLevelKeyboardProc? _keyboardProc;
        private bool _pttPressed;

        private static void ReportDebug(string hypothesisId, string location, string message, object? data = null)
        {
            // Optional development tracing is supplied by an injected listener; production performs no network call.
        }

        // 物理按键状态跟踪（防止 vkCode 左右 Ctrl 不一致导致 release 丢失）
        private bool _ctrlPhysicallyDown;
        private bool _spacePhysicallyDown;
        private bool _pttActive;

        // 输入框焦点状态：输入框聚焦时 Ctrl+Space 应留给输入法切换，不触发 PTT
        private bool _isInputFocused;
        private readonly object _inputFocusLock = new object();

        // ★ PTT 冷却期：ASR 启动失败后，3 秒内忽略所有 PTT 触发，防止 OS 键盘自动重复
        //   造成"长按 Ctrl+空格时屏幕一直闪"。
        private DateTime _pttCooldownUntil = DateTime.MinValue;
        private const int PTT_COOLDOWN_MS = 3000;
        private readonly object _pttCooldownLock = new();

        // 轮询兜底：防止 keyup 事件丢失导致 _pttActive 卡住
        private System.Threading.Timer? _pttPollTimer;
        private int _pttPollReleaseCount;

        // Push-to-Talk: 屏幕边缘发光覆盖层
        private PttGlowOverlay? _pttGlowOverlay;
        private readonly object _pttGlowLock = new();

        // ASR: 实时语音识别客户端（WebSocket → 阿里云 Fun-ASR）
        private string _backendUrl = "";
        private AsrClient? _asrClient;

        private HwndSource? _hwndSource;

        // ── P/Invoke for dark title bar ──────────────────
        [DllImport("dwmapi.dll", PreserveSig = true)]
        private static extern int DwmSetWindowAttribute(
            IntPtr hwnd, int attr, ref int attrValue, int attrSize);

        private const int DWMWA_USE_IMMERSIVE_DARK_MODE = 20;
        private const int DWMWA_CAPTION_COLOR = 35;
        private const int DWMWA_BORDER_COLOR = 34;
        private const int DWMWA_WINDOW_CORNER_PREFERENCE = 33;
        private const int DWMWCP_ROUND = 2;

        // ── P/Invoke for per-monitor maximize (fix WindowChrome overlap taskbar) ──
        private const int WM_GETMINMAXINFO = 0x0024;
        private const uint MONITOR_DEFAULTTONEAREST = 0x00000002;

        [DllImport("user32.dll")]
        private static extern IntPtr MonitorFromWindow(IntPtr hwnd, uint dwFlags);

        [DllImport("user32.dll", CharSet = CharSet.Auto)]
        private static extern bool GetMonitorInfo(IntPtr hMonitor, ref MONITORINFO lpmi);

        [StructLayout(LayoutKind.Sequential)]
        private struct POINT { public int x; public int y; }

        [StructLayout(LayoutKind.Sequential)]
        private struct MINMAXINFO
        {
            public POINT ptReserved;
            public POINT ptMaxSize;
            public POINT ptMaxPosition;
            public POINT ptMinTrackSize;
            public POINT ptMaxTrackSize;
        }

        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Auto)]
        private struct MONITORINFO
        {
            public uint cbSize;
            public RECT rcMonitor;
            public RECT rcWork;
            public uint dwFlags;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct RECT { public int left, top, right, bottom; }

        // Push-to-Talk: Windows 消息常量
        private const int WM_KEYDOWN = 0x0100;
        private const int WM_KEYUP = 0x0101;
        private const int WM_SYSKEYDOWN = 0x0104;
        private const int WM_SYSKEYUP = 0x0105;
        private const int VK_CONTROL = 0x11;
        private const int VK_LCONTROL = 0xA2;
        private const int VK_RCONTROL = 0xA3;
        private const int VK_SPACE = 0x20;
        private const int WH_KEYBOARD_LL = 13;

        [DllImport("user32.dll")]
        private static extern short GetKeyState(int nVirtKey);

        [DllImport("user32.dll")]
        private static extern short GetAsyncKeyState(int nVirtKey);

        [DllImport("user32.dll")]
        private static extern IntPtr GetForegroundWindow();

        // Low-level keyboard hook (全局键盘钩子)
        private delegate IntPtr LowLevelKeyboardProc(int nCode, IntPtr wParam, IntPtr lParam);

        [DllImport("user32.dll", SetLastError = true)]
        private static extern IntPtr SetWindowsHookEx(int idHook, LowLevelKeyboardProc lpfn, IntPtr hMod, uint dwThreadId);

        [DllImport("user32.dll", SetLastError = true)]
        private static extern bool UnhookWindowsHookEx(IntPtr hhk);

        [DllImport("user32.dll", SetLastError = true)]
        private static extern IntPtr CallNextHookEx(IntPtr hhk, int nCode, IntPtr wParam, IntPtr lParam);

        [DllImport("user32.dll")]
        private static extern IntPtr GetKeyboardLayout(uint idThread);

        [DllImport("user32.dll")]
        private static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);

        /// <summary>
        /// 检测当前前台窗口（即接收键盘输入的窗口）的键盘布局是否为中文/日文/韩文输入法。
        /// 用于避免 PTT 快捷键 Ctrl+Space 与 IME 切换/中英切换冲突。
        /// </summary>
        private bool IsCjkInputLanguage()
        {
            IntPtr hwnd = GetForegroundWindow();
            if (hwnd == IntPtr.Zero)
                hwnd = new WindowInteropHelper(this).Handle;
            uint tid = GetWindowThreadProcessId(hwnd, out _);
            IntPtr hkl = GetKeyboardLayout(tid);
            int langId = (int)hkl.ToInt64() & 0xFFFF;
            int primaryLang = langId & 0x3FF;
            // 0x04 = 中文, 0x11 = 日文, 0x12 = 韩文
            return primaryLang == 0x04 || primaryLang == 0x11 || primaryLang == 0x12;
        }

        /// <summary>
        /// 判断本窗口当前是否处于前台（拥有键盘输入焦点）。
        /// 全局键盘钩子只在自家窗口前台时才应消费快捷键。
        /// </summary>
        private bool IsOurWindowForeground()
        {
            IntPtr hwnd = new WindowInteropHelper(this).Handle;
            return hwnd != IntPtr.Zero && GetForegroundWindow() == hwnd;
        }

        /// <summary>
        /// 由前端通过 JS Bridge 同步输入框焦点状态。
        /// 输入框聚焦时，Ctrl+Space 应交给系统输入法切换，不触发 PTT。
        /// </summary>
        public void SetInputFocusState(bool focused)
        {
            lock (_inputFocusLock)
            {
                _isInputFocused = focused;
            }
            System.Diagnostics.Debug.WriteLine($"[IME] Input focus state changed: {focused}");
        }

        public MainWindow()
        {
            InitializeComponent();

            // 与本地 FileOperationApiServer 共享同一实例，确保用户在 UI 中选中的
            // 文件/文件夹授权能够被后续 Agent 工具调用识别。
            _fileService = App.LocalFileService
                ?? throw new InvalidOperationException("Local file service was not initialized from launcher configuration.");

            // 阻止页面弹窗在系统浏览器中打开，统一在当前 WebView 内加载
            WebView.LifeSpanHandler = new MoonYaLifeSpanHandler(WebView);

            // ★ 捕获前端控制台输出到日志文件，便于诊断启动器 WebView 中的问题
            WebView.ConsoleMessage += OnWebViewConsoleMessage;

            // CefSharp 147 已将 CamelCaseJavascriptNames 从 BindingOptions 移除，
            // 改为通过 IJavascriptObjectRepository.NameConverter 控制命名转换。
            // 必须在底层 browser 创建前设置，否则后续修改会抛异常。
            WebView.JavascriptObjectRepository.NameConverter = new CefSharp.JavascriptBinding.CamelCaseJavascriptNameConverter();

            Loaded += OnLoaded;
            Activated += OnActivated;
            Closing += OnClosing;
            SourceInitialized += OnSourceInitialized;
            StateChanged += OnStateChanged;
        }

        private void OnSourceInitialized(object? sender, EventArgs e)
        {
            _hwndSource = HwndSource.FromHwnd(new WindowInteropHelper(this).Handle);
            _hwndSource?.AddHook(WndProc);
            InstallKeyboardHook();
        }

        private void InstallKeyboardHook()
        {
            if (_keyboardHookId != IntPtr.Zero) return;
            _keyboardProc = KeyboardHookCallback;
            var module = System.Reflection.Assembly.GetExecutingAssembly().GetModules()[0];
            var hMod = System.Runtime.InteropServices.Marshal.GetHINSTANCE(module);
            _keyboardHookId = SetWindowsHookEx(WH_KEYBOARD_LL, _keyboardProc, hMod, 0);
            System.Diagnostics.Debug.WriteLine($"[PTT] KeyboardHook installed: {_keyboardHookId != IntPtr.Zero}");
        }

        private void UninstallKeyboardHook()
        {
            if (_keyboardHookId != IntPtr.Zero)
            {
                UnhookWindowsHookEx(_keyboardHookId);
                _keyboardHookId = IntPtr.Zero;
            }
            StopPttPollTimer();
        }

        private void StartPttPollTimer()
        {
            StopPttPollTimer();
            _pttPollReleaseCount = 0;
            _pttPollTimer = new System.Threading.Timer(_ =>
            {
                bool ctrlDown = (GetAsyncKeyState(VK_CONTROL) & 0x8000) != 0 ||
                                (GetAsyncKeyState(VK_LCONTROL) & 0x8000) != 0 ||
                                (GetAsyncKeyState(VK_RCONTROL) & 0x8000) != 0;
                bool spaceDown = (GetAsyncKeyState(VK_SPACE) & 0x8000) != 0;

                if (!_pttActive)
                {
                    _ctrlPhysicallyDown = ctrlDown;
                    _spacePhysicallyDown = spaceDown;
                    _pttPollReleaseCount = 0;
                    return;
                }

                if (!ctrlDown && !spaceDown)
                {
                    _pttPollReleaseCount++;
                    if (_pttPollReleaseCount >= 2)
                    {
                        System.Diagnostics.Debug.WriteLine("[HOOK] Poll detected both keys released, forcing release");
                        _pttActive = false;
                        _pttPressed = false;
                        _ctrlPhysicallyDown = false;
                        _spacePhysicallyDown = false;
                        _pttPollReleaseCount = 0;
                        HidePttGlow();
                        Dispatcher.InvokeAsync(() =>
                        {
                            try
                            {
                                if (WebView != null && WebView.IsBrowserInitialized)
                                    WebView.ExecuteScriptAsync("window.__onPttRelease && window.__onPttRelease()");
                            }
                            catch (Exception ex) { System.Diagnostics.Debug.WriteLine("[HOOK] __onPttRelease 异常: " + ex.Message); }
                        });
                    }
                }
                else
                {
                    _pttPollReleaseCount = 0;
                    _ctrlPhysicallyDown = ctrlDown;
                    _spacePhysicallyDown = spaceDown;
                }
            }, null, TimeSpan.FromMilliseconds(50), TimeSpan.FromMilliseconds(50));
        }

        private void StopPttPollTimer()
        {
            _pttPollTimer?.Dispose();
            _pttPollTimer = null;
            _pttPollReleaseCount = 0;
        }

        private bool IsCtrlKey(int vkCode)
        {
            return vkCode == VK_CONTROL || vkCode == VK_LCONTROL || vkCode == VK_RCONTROL;
        }

        /// <summary>检查当前是否处于 PTT 冷却期（ASR 失败后 3 秒内）。</summary>
        private bool IsInPttCooldown()
        {
            lock (_pttCooldownLock)
            {
                return DateTime.UtcNow < _pttCooldownUntil;
            }
        }

        /// <summary>设置 PTT 冷却期，durationMs 毫秒内忽略所有 Ctrl+Space 触发。</summary>
        private void SetPttCooldown(int durationMs = PTT_COOLDOWN_MS)
        {
            lock (_pttCooldownLock)
            {
                _pttCooldownUntil = DateTime.UtcNow.AddMilliseconds(durationMs);
                System.Diagnostics.Debug.WriteLine($"[PTT] 进入冷却期 {durationMs}ms 截止至 {_pttCooldownUntil.ToLocalTime():HH:mm:ss.fff}");
            }
        }

        /// <summary>清除 PTT 冷却期（ASR 启动成功后由前端显式调用）。</summary>
        private void ClearPttCooldown()
        {
            lock (_pttCooldownLock)
            {
                _pttCooldownUntil = DateTime.MinValue;
            }
        }

        private IntPtr KeyboardHookCallback(int nCode, IntPtr wParam, IntPtr lParam)
        {
            if (nCode < 0)
                return CallNextHookEx(_keyboardHookId, nCode, wParam, lParam);

            bool isKeyDown = wParam == (IntPtr)WM_KEYDOWN || wParam == (IntPtr)WM_SYSKEYDOWN;
            bool isKeyUp = wParam == (IntPtr)WM_KEYUP || wParam == (IntPtr)WM_SYSKEYUP;
            if (!isKeyDown && !isKeyUp)
                return CallNextHookEx(_keyboardHookId, nCode, wParam, lParam);

            int vkCode = System.Runtime.InteropServices.Marshal.ReadInt32(lParam);
            bool isCtrl = IsCtrlKey(vkCode);
            bool isSpace = vkCode == VK_SPACE;

            // ★ 冷却期检查：ASR 启动失败后 3 秒内，吞掉 Space keydown 防止反复触发
            //   仅在 PTT 未激活时生效；PTT 已激活的释放逻辑不受影响。
            //   输入框聚焦时让 Space 正常通过，避免影响打字。
            if (isSpace && isKeyDown && !_pttActive && IsInPttCooldown())
            {
                bool inputFocused;
                lock (_inputFocusLock) { inputFocused = _isInputFocused; }
                if (!inputFocused)
                {
                    return (IntPtr)1;
                }
            }

            // 更新物理按键状态（左右 Ctrl 均识别为 Ctrl）
            if (isCtrl)
            {
                _ctrlPhysicallyDown = isKeyDown;
            }
            else if (isSpace)
            {
                _spacePhysicallyDown = isKeyDown;
            }

            // 防御性重置：状态标志卡住但两键实际都已释放时，强制触发 release
            // 注意：Ctrl 事件必须继续传递给系统，否则 Ctrl 会被“粘住”，导致后续按键被识别为 Ctrl+X
            if (_pttActive && !_ctrlPhysicallyDown && !_spacePhysicallyDown)
            {
                _pttActive = false;
                _pttPressed = false;
                StopPttPollTimer();
                HidePttGlow();
                Dispatcher.InvokeAsync(() =>
                {
                    try
                    {
                        if (WebView != null && WebView.IsBrowserInitialized)
                            WebView.ExecuteScriptAsync("window.__onPttRelease && window.__onPttRelease()");
                        else
                            System.Diagnostics.Debug.WriteLine("[HOOK] WebView 未就绪，__onPttRelease 丢失");
                    }
                    catch (Exception ex) { System.Diagnostics.Debug.WriteLine("[HOOK] __onPttRelease 异常: " + ex.Message); }
                });
                return isCtrl ? CallNextHookEx(_keyboardHookId, nCode, wParam, lParam) : (IntPtr)1;
            }

            // PTT 未激活且按住 Ctrl 时按下 Space：开始录音，并消费 Space（防止输入框多出一个空格）
            if (!_pttActive && isSpace && isKeyDown && _ctrlPhysicallyDown)
            {
                bool ourWindowActive = IsOurWindowForeground();
                bool inputFocused;
                lock (_inputFocusLock) { inputFocused = _isInputFocused; }
                bool cjk = IsCjkInputLanguage();
                System.Diagnostics.Debug.WriteLine($"[PTT] Ctrl+Space detected: ourWindowActive={ourWindowActive}, inputFocused={inputFocused}, CJK={cjk}");

                // 以下情况交给系统处理，不触发 PTT：
                // 1. 本窗口不在前台（快捷键不应在别的窗口生效）
                // 2. 输入框聚焦（用户要切换输入法/输入空格）
                // 3. 当前已是 CJK 输入法（Ctrl+Space 常用于 IME 内部中英切换或关闭 IME）
                if (!ourWindowActive || inputFocused || cjk)
                {
                    System.Diagnostics.Debug.WriteLine("[PTT] Ctrl+Space 让给系统/输入法");
                    return CallNextHookEx(_keyboardHookId, nCode, wParam, lParam);
                }

                if (WebView != null && WebView.IsBrowserInitialized)
                {
                    _pttActive = true;
                    _pttPressed = true;
                    System.Diagnostics.Debug.WriteLine("[HOOK] Ctrl+Space 按下，触发 __onPttTrigger");
                    ShowPttGlow();
                    StartPttPollTimer();
                    Dispatcher.InvokeAsync(() =>
                    {
                        try { WebView.ExecuteScriptAsync("window.__onPttTrigger && window.__onPttTrigger()"); }
                        catch (Exception ex) { System.Diagnostics.Debug.WriteLine("[HOOK] __onPttTrigger 异常: " + ex.Message); }
                    });
                    return (IntPtr)1;
                }
            }

            // ★ 修复：PTT 已激活时吞掉 Space 的所有自动重复 keydown。
            //   根因：OS 键盘自动重复会持续产生 WM_KEYDOWN，第一次按 Space 被消费但
            //   自动重复的 keydown 会落到浏览器 → 触发 onKeyDown → startRecording 反复调用，
            //   配合 ASR 启动失败后状态回到 IDLE，造成 ptt-input-glow / 提示音在 30~50ms
            //   周期内反复出现/消失，用户看到"长按 Ctrl+空格时屏幕一直闪"。
            if (_pttActive && isSpace && isKeyDown)
            {
                return (IntPtr)1;
            }

            // PTT 激活时松开 Space：结束录音，并消费 Space
            if (_pttActive && isSpace && isKeyUp)
            {
                _pttActive = false;
                _pttPressed = false;
                System.Diagnostics.Debug.WriteLine("[HOOK] Ctrl+Space 松开，触发 __onPttRelease");
                StopPttPollTimer();
                HidePttGlow();
                Dispatcher.InvokeAsync(() =>
                {
                    try
                    {
                        if (WebView != null && WebView.IsBrowserInitialized)
                            WebView.ExecuteScriptAsync("window.__onPttRelease && window.__onPttRelease()");
                        else
                            System.Diagnostics.Debug.WriteLine("[HOOK] WebView 未就绪，__onPttRelease 丢失");
                    }
                    catch (Exception ex) { System.Diagnostics.Debug.WriteLine("[HOOK] __onPttRelease 异常: " + ex.Message); }
                });
                return (IntPtr)1;
            }

            // PTT 激活时松开 Ctrl：同样结束录音，但 Ctrl 事件必须继续传递，避免 Ctrl 被粘住
            if (_pttActive && isCtrl && isKeyUp)
            {
                _pttActive = false;
                _pttPressed = false;
                System.Diagnostics.Debug.WriteLine("[HOOK] Ctrl 先松开，触发 __onPttRelease");
                StopPttPollTimer();
                HidePttGlow();
                Dispatcher.InvokeAsync(() =>
                {
                    try
                    {
                        if (WebView != null && WebView.IsBrowserInitialized)
                            WebView.ExecuteScriptAsync("window.__onPttRelease && window.__onPttRelease()");
                        else
                            System.Diagnostics.Debug.WriteLine("[HOOK] WebView 未就绪，__onPttRelease 丢失");
                    }
                    catch (Exception ex) { System.Diagnostics.Debug.WriteLine("[HOOK] __onPttRelease 异常: " + ex.Message); }
                });
                return CallNextHookEx(_keyboardHookId, nCode, wParam, lParam);
            }

            return CallNextHookEx(_keyboardHookId, nCode, wParam, lParam);
        }

        // ── Push-to-Talk: 屏幕边缘发光覆盖层控制 ─────────────

        private void ShowPttGlow()
        {
            Dispatcher.InvokeAsync(() =>
            {
                try
                {
                    lock (_pttGlowLock)
                    {
                        if (_pttGlowOverlay == null)
                        {
                            _pttGlowOverlay = new PttGlowOverlay();
                            // 不设 Owner：避免作为 OwnedWindow 跟随主窗口最小化而隐藏；overlay 已 Topmost，关闭时由 OnClosed 统一 Close
                            _pttGlowOverlay.ReportDebugLog = (h, loc, msg, dataJson) => ReportDebug(h, loc, msg, dataJson);
                            // #region debug-point H1:overlay-created
                            ReportDebug("H1", "MainWindow.xaml.cs:ShowPttGlow", "PttGlowOverlay instance created");
                            // #endregion
                        }
                        _pttGlowOverlay.PlayEntranceAnimation();
                    }
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"[PTT Glow] ShowPttGlow failed: {ex.Message}");
                }
            }, DispatcherPriority.Render);
        }

        private void HidePttGlow()
        {
            Dispatcher.InvokeAsync(() =>
            {
                try
                {
                    lock (_pttGlowLock)
                    {
                        _pttGlowOverlay?.HideGlow();
                    }
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"[PTT Glow] HidePttGlow failed: {ex.Message}");
                }
            }, DispatcherPriority.Render);
        }

        /// <summary>
        /// 前端通过 moonYaPttGlow.updateVolume(level) 回传实时音量。
        /// </summary>
        public void UpdatePttGlowVolume(double level)
        {
            Dispatcher.InvokeAsync(() =>
            {
                try
                {
                    lock (_pttGlowLock)
                    {
                        _pttGlowOverlay?.UpdateVolume(level);
                    }
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"[PTT Glow] UpdateVolume failed: {ex.Message}");
                }
            });
        }

        /// <summary>
        /// 转发语音对话模式光效状态到 PttGlowOverlay。
        /// </summary>
        public void SetPttGlowVoiceChatMode(string mode)
        {
            try
            {
                System.Diagnostics.Debug.WriteLine($"[PTT Glow] SetPttGlowVoiceChatMode 被调用, mode={mode}, overlayExists={_pttGlowOverlay != null}");
                // #region debug-point H2:bridge-called
                ReportDebug("H2", "MainWindow.xaml.cs:SetPttGlowVoiceChatMode",
                    $"SetPttGlowVoiceChatMode called from JS",
                    new { mode = mode, overlayExists = _pttGlowOverlay != null });
                // #endregion

                // CefSharp JS Bridge 回调跑在 CEF UI 线程，必须切到 WPF Dispatcher 线程
                // 才能创建/操作 PttGlowOverlay 窗口，否则窗口线程亲和性错误，光效无法显示。
                if (Dispatcher.CheckAccess())
                {
                    SetPttGlowVoiceChatModeCore(mode);
                }
                else
                {
                    Dispatcher.Invoke(() => SetPttGlowVoiceChatModeCore(mode));
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[PTT Glow] SetPttGlowVoiceChatMode 异常: {ex.Message}\n{ex.StackTrace}");
                ReportDebug("H2", "MainWindow.xaml.cs:SetPttGlowVoiceChatMode",
                    "SetPttGlowVoiceChatMode outer exception",
                    new { message = ex.Message, stack = ex.StackTrace });
            }
        }

        private void SetPttGlowVoiceChatModeCore(string mode)
        {
            try
            {
                System.Diagnostics.Debug.WriteLine($"[PTT Glow] SetPttGlowVoiceChatModeCore 进入, _pttGlowOverlay is null: {_pttGlowOverlay == null}");

                // 语音对话模式可能在没有按过 PTT 的情况下直接开启，此时 _pttGlowOverlay 尚未实例化。
                // 必须在此处懒创建，否则光效永远不会显示。
                if (_pttGlowOverlay == null)
                {
                    lock (_pttGlowLock)
                    {
                        if (_pttGlowOverlay == null)
                        {
                            _pttGlowOverlay = new PttGlowOverlay();
                            // 不设 Owner：避免作为 OwnedWindow 跟随主窗口最小化而隐藏；overlay 已 Topmost，关闭时由 OnClosed 统一 Close
                            _pttGlowOverlay.ReportDebugLog = (h, loc, msg, dataJson) => ReportDebug(h, loc, msg, dataJson);
                            System.Diagnostics.Debug.WriteLine($"[PTT Glow] SetPttGlowVoiceChatMode 懒创建 PttGlowOverlay 实例 (无 Owner, 独立置顶窗口)");
                            // #region debug-point H1:overlay-lazy-created
                            ReportDebug("H1", "MainWindow.xaml.cs:SetPttGlowVoiceChatMode", "Lazy-created PttGlowOverlay in voice chat mode");
                            // #endregion
                        }
                    }
                }

                System.Diagnostics.Debug.WriteLine($"[PTT Glow] 即将调用 _pttGlowOverlay.SetVoiceChatMode(mode={mode})");
                _pttGlowOverlay.SetVoiceChatMode(mode);
                System.Diagnostics.Debug.WriteLine($"[PTT Glow] SetVoiceChatMode 已转发到 overlay, mode={mode}");
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine("[PTT Glow] SetVoiceChatMode 异常: " + ex.Message);
                ReportDebug("H2", "MainWindow.xaml.cs:SetPttGlowVoiceChatMode",
                    "SetVoiceChatMode exception",
                    new { message = ex.Message, stack = ex.StackTrace });
            }
        }

        /// <summary>
        /// 转发 CU（Computer Use）模式光效状态到 PttGlowOverlay。
        /// </summary>
        public void SetPttGlowCuMode(string mode)
        {
            try
            {
                System.Diagnostics.Debug.WriteLine($"[PTT Glow] SetPttGlowCuMode 被调用, mode={mode}, overlayExists={_pttGlowOverlay != null}");
                // #region debug-point H2:bridge-called
                ReportDebug("H2", "MainWindow.xaml.cs:SetPttGlowCuMode",
                    $"SetPttGlowCuMode called from JS",
                    new { mode = mode, overlayExists = _pttGlowOverlay != null });
                // #endregion

                // CefSharp JS Bridge 回调跑在 CEF UI 线程，必须切到 WPF Dispatcher 线程
                // 才能创建/操作 PttGlowOverlay 窗口，否则窗口线程亲和性错误，光效无法显示。
                if (Dispatcher.CheckAccess())
                {
                    SetPttGlowCuModeCore(mode);
                }
                else
                {
                    Dispatcher.Invoke(() => SetPttGlowCuModeCore(mode));
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[PTT Glow] SetPttGlowCuMode 异常: {ex.Message}\n{ex.StackTrace}");
                ReportDebug("H2", "MainWindow.xaml.cs:SetPttGlowCuMode",
                    "SetPttGlowCuMode outer exception",
                    new { message = ex.Message, stack = ex.StackTrace });
            }
        }

        private void SetPttGlowCuModeCore(string mode)
        {
            try
            {
                System.Diagnostics.Debug.WriteLine($"[PTT Glow] SetPttGlowCuModeCore 进入, _pttGlowOverlay is null: {_pttGlowOverlay == null}");

                // CU 模式可能在没有触发过 PTT 或语音对话模式的情况下直接开启，此时 _pttGlowOverlay 尚未实例化。
                // 必须在此处懒创建，否则光效永远不会显示。
                if (_pttGlowOverlay == null)
                {
                    lock (_pttGlowLock)
                    {
                        if (_pttGlowOverlay == null)
                        {
                            _pttGlowOverlay = new PttGlowOverlay();
                            // 不设 Owner：避免作为 OwnedWindow 跟随主窗口最小化而隐藏；overlay 已 Topmost，关闭时由 OnClosed 统一 Close
                            _pttGlowOverlay.ReportDebugLog = (h, loc, msg, dataJson) => ReportDebug(h, loc, msg, dataJson);
                            System.Diagnostics.Debug.WriteLine($"[PTT Glow] SetPttGlowCuMode 懒创建 PttGlowOverlay 实例 (无 Owner, 独立置顶窗口)");
                            // #region debug-point H1:overlay-lazy-created
                            ReportDebug("H1", "MainWindow.xaml.cs:SetPttGlowCuMode", "Lazy-created PttGlowOverlay in cu mode");
                            // #endregion
                        }
                    }
                }

                System.Diagnostics.Debug.WriteLine($"[PTT Glow] 即将调用 _pttGlowOverlay.SetCuMode(mode={mode})");
                _pttGlowOverlay.SetCuMode(mode);
                System.Diagnostics.Debug.WriteLine($"[PTT Glow] SetCuMode 已转发到 overlay, mode={mode}");
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine("[PTT Glow] SetCuMode 异常: " + ex.Message);
                ReportDebug("H2", "MainWindow.xaml.cs:SetPttGlowCuMode",
                    "SetCuMode exception",
                    new { message = ex.Message, stack = ex.StackTrace });
            }
        }

        /// <summary>
        /// 前端通过 PttGlowBridge.CancelPtt() 调用，强制释放 C# 端 PTT 状态：
        /// 1. 停止 PTT 物理按键轮询计时器
        /// 2. 隐藏屏幕边缘光效
        /// 3. 复位物理按键状态标志
        /// 场景：ASR 启动失败、识别异常、识别空结果等，避免长按 Ctrl+空格时光效卡住不消失
        /// 或屏幕持续闪烁。该方法幂等：未激活状态下调用是安全的。
        /// </summary>
        public void CancelPttFromJs()
        {
            try
            {
                if (Dispatcher.CheckAccess())
                {
                    CancelPttFromJsCore();
                }
                else
                {
                    Dispatcher.Invoke(CancelPttFromJsCore);
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine("[PTT Glow] CancelPttFromJs 异常: " + ex.Message);
            }
        }

        private void CancelPttFromJsCore()
        {
            try
            {
                System.Diagnostics.Debug.WriteLine("[PTT Glow] CancelPttFromJs 释放 PTT 状态, _pttActive=" + _pttActive);
                if (_pttActive)
                {
                    _pttActive = false;
                    _pttPressed = false;
                    StopPttPollTimer();
                    HidePttGlow();
                }
                else
                {
                    // 未激活也确保停止计时器与光效
                    StopPttPollTimer();
                    HidePttGlow();
                }
                _ctrlPhysicallyDown = false;
                _spacePhysicallyDown = false;
                _pttPollReleaseCount = 0;

                // ★ 设置冷却期：3 秒内忽略所有 Ctrl+Space 触发，避免 ASR 失败后长按闪屏
                SetPttCooldown(PTT_COOLDOWN_MS);
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine("[PTT Glow] CancelPttFromJsCore 异常: " + ex.Message);
            }
        }

        /// <summary>公开：JS 调用进入 PTT 冷却期（ASR 启动失败时）。</summary>
        public void EnterPttCooldown()
        {
            try
            {
                if (Dispatcher.CheckAccess())
                {
                    SetPttCooldown(PTT_COOLDOWN_MS);
                }
                else
                {
                    Dispatcher.Invoke(() => SetPttCooldown(PTT_COOLDOWN_MS));
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine("[PTT] EnterPttCooldown 异常: " + ex.Message);
            }
        }

        /// <summary>公开：JS 调用清除 PTT 冷却期（ASR 启动成功时）。</summary>
        public void ClearPttCooldownFromJs()
        {
            try
            {
                if (Dispatcher.CheckAccess())
                {
                    ClearPttCooldown();
                }
                else
                {
                    Dispatcher.Invoke(ClearPttCooldown);
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine("[PTT] ClearPttCooldownFromJs 异常: " + ex.Message);
            }
        }

        // ── window lifecycle ─────────────────────────────

        private async void OnLoaded(object sender, RoutedEventArgs e)
        {
            if (_initialized) return;
            _initialized = true;

            Loaded -= OnLoaded;

            // Apply light title bar
            ApplyLightTitleBar();

            // Apply Windows 11 rounded corners
            ApplyRoundedCorners();

            // Wire tray icon events
            WireTrayIcon();

            await StartApplicationAsync();
        }

        private async Task StartApplicationAsync()
        {
            try
            {
                var backendUrl = App.BackendBaseAddress?.AbsoluteUri
                    ?? throw new InvalidOperationException("运行时服务清单缺少 backend_url");
                System.Diagnostics.Debug.WriteLine($"前后端分离模式：导航到 {backendUrl}");

                // 保存后端地址供 ASR 等服务使用
                _backendUrl = backendUrl;

                // 文件/文件夹选择必须在首次页面脚本执行前即可绑定。
                // 旧实现等 LoadingStateChanged(IsLoading=false) 后才注册，页面在此之前
                // 调用 BindObjectAsync 会得到“仅支持桌面端”的误判。
                RegisterFileOpsBridgeOnce();

                // Wire CefSharp events
                WebView.LoadingStateChanged += OnLoadingStateChanged;
                WebView.AddressChanged += OnAddressChanged;

                // ═══ 性能：OSR 模式默认帧率上限 30fps，提升到 60fps ═══
                WebView.IsBrowserInitializedChanged += OnBrowserInitializedChanged;

                // CefSharp 直接导航到独立后端地址
                // ★ 添加 ?_t={ticks} 时间戳，防止 CefSharp 缓存 index.php 导致加载旧 JS/CSS
                var navUrl = backendUrl;
                if (!string.IsNullOrEmpty(navUrl))
                {
                    var sep = navUrl.Contains("?") ? "&" : "?";
                    navUrl += sep + "_t=" + DateTime.UtcNow.Ticks;
                }
                WebView.Address = navUrl;

                await Task.CompletedTask;
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"启动失败: {ex.Message}");
            }
        }

        // ── event handlers ───────────────────────────────

        private void OnBrowserInitializedChanged(object? sender, DependencyPropertyChangedEventArgs e)
        {
            // 浏览器初始化完成后将 OSR 帧率上限从默认 30 提升到 60
            if (WebView.IsBrowserInitialized)
            {
                try
                {
                    var host = WebView.GetBrowser()?.GetHost();
                    if (host != null)
                        host.WindowlessFrameRate = 60;
                }
                catch (Exception ex) { System.Diagnostics.Debug.WriteLine($"SetWindowlessFrameRate failed: {ex.Message}"); }
            }
        }

        private void OnLoadingStateChanged(object? sender, LoadingStateChangedEventArgs e)
        {
            Dispatcher.Invoke(() =>
            {
                if (!e.IsLoading)
                {
                    // 启动阶段已预注册；若 CefSharp 当时尚未就绪，这里再安全重试。
                    RegisterFileOpsBridgeOnce();

                    // Register JS bridge for ASR (实时语音识别)
                    try
                    {
                        _asrClient = new AsrClient(WebView, _backendUrl);
                        WebView.JavascriptObjectRepository.Register(
                            "moonYaAsr",
                            _asrClient,
                            BindingOptions.DefaultBinder);
                        System.Diagnostics.Debug.WriteLine("[ASR] JS Bridge moonYaAsr 已注册");
                    }
                    catch (Exception ex)
                    {
                        System.Diagnostics.Debug.WriteLine($"ASR Bridge registration failed: {ex.Message}");
                    }

                    // Register JS bridge for PTT glow volume
                    // #region debug-point H3:cs-registration
                    ReportDebug("H3", "MainWindow.xaml.cs:OnLoadingStateChanged", "Start registering moonYaPttGlow");
                    // #endregion
                    try
                    {
                        // NameConverter 已在构造函数中于 browser 创建前设置。
                        WebView.JavascriptObjectRepository.Register(
                            "moonYaPttGlow",
                            new PttGlowBridge(this),
                            BindingOptions.DefaultBinder);
                        System.Diagnostics.Debug.WriteLine("[PTT Glow] JS Bridge moonYaPttGlow 已注册");
                        // #region debug-point H3:cs-registration
                        ReportDebug("H3", "MainWindow.xaml.cs:OnLoadingStateChanged", "moonYaPttGlow registered successfully");
                        // #endregion
                    }
                    catch (Exception ex)
                    {
                        System.Diagnostics.Debug.WriteLine($"PTT Glow Bridge registration failed: {ex.Message}");
                        // #region debug-point H3:cs-registration
                        ReportDebug("H3", "MainWindow.xaml.cs:OnLoadingStateChanged", "moonYaPttGlow registration failed",
                            new { message = ex.Message, stack = ex.StackTrace, type = ex.GetType().FullName });
                        // #endregion
                    }

                    // ── Register JS bridge for Pet controller ──
                    //   前端 user_xinxi.php 中桌宠开关通过 await CefSharp.BindObjectAsync('petController')
                    //   然后 petController.setEnabled(true/false) 控制桌面桌宠显隐。
                    try
                    {
                        var petWin = App.PetWindow;
                        if (petWin != null)
                        {
                            WebView.JavascriptObjectRepository.Register(
                                "petController",
                                new PetController(petWin),
                                BindingOptions.DefaultBinder);
                            System.Diagnostics.Debug.WriteLine("[Pet] JS Bridge petController 已注册");
                        }
                        else
                        {
                            System.Diagnostics.Debug.WriteLine("[Pet] PetWindow 未初始化，跳过 petController 注册");
                        }
                    }
                    catch (Exception ex)
                    {
                        System.Diagnostics.Debug.WriteLine($"[Pet] petController 注册失败: {ex.Message}");
                    }

                    // ── Register JS bridge for Pet chat bubble（AI 回答同步到桌宠气泡）──
                    //   前端 script-1e-rest.php 流式接收 AI 回答时节流调用
                    //   petChat.updateReply(fullReply)，由 PetWindow 清洗后显示到气泡。
                    try
                    {
                        var petWinForChat = App.PetWindow;
                        if (petWinForChat != null)
                        {
                            WebView.JavascriptObjectRepository.Register(
                                "petChat",
                                new PetChatBridge(petWinForChat),
                                BindingOptions.DefaultBinder);
                            System.Diagnostics.Debug.WriteLine("[Pet] JS Bridge petChat 已注册");
                        }
                    }
                    catch (Exception ex)
                    {
                        System.Diagnostics.Debug.WriteLine($"[Pet] petChat 注册失败: {ex.Message}");
                    }

                    // ── Register JS bridge for input focus state ──
                    //   前端输入框聚焦/失焦时同步状态到 C#，键盘 hook 据此判断 Ctrl+Space
                    //   应触发 PTT 还是交给输入法切换。
                    try
                    {
                        WebView.JavascriptObjectRepository.Register(
                            "moonyaInputFocus",
                            new InputFocusBridge(this),
                            BindingOptions.DefaultBinder);
                        System.Diagnostics.Debug.WriteLine("[IME] JS Bridge moonyaInputFocus 已注册");
                    }
                    catch (Exception ex)
                    {
                        System.Diagnostics.Debug.WriteLine($"[IME] moonyaInputFocus 注册失败: {ex.Message}");
                    }

                    // 本地服务只以相对协议路由进入 C# 桥。实际监听地址由 launcher_config.json
                    // 的运行时服务配置解析，网页不能把任意 URL 交给本地桥转发。
                    try
                    {
                        WebView.ExecuteScriptAsync(@"
(function(){
    if(window.__moonYaFetchPatched)return;
    window.__moonYaFetchPatched=true;
    console.log('[MoonYa Fetch Patch] Installing at', window.location.href);

    // Only intercept the declared relative local-service routes.
    function shouldIntercept(url){
        if(typeof url!=='string')return false;
        if(/^\/(file-op|cu-op|execute|browser|mcp-op)\b/.test(url))return true;
        return false;
    }

    function classifyOp(url){
        if(url.indexOf('/browser/')!==-1)return 'browser';
        if(url.indexOf('/execute')!==-1)return 'exec';
        if(url.indexOf('/cu-op')!==-1)return 'cu';
        if(url.indexOf('/mcp-op')!==-1)return 'mcp';
        return 'file';
    }

    // ★ BindObjectAsync 超时：3 秒后放弃等待（避免 Promise 永不 resolve）
    var bridgeReady;
    if(typeof CefSharp!=='undefined'&&CefSharp.BindObjectAsync){
        bridgeReady=Promise.race([
            CefSharp.BindObjectAsync('moonYaFileOps').then(function(){
                if(window.moonYaFileOps){
                    console.log('[MoonYa Fetch Patch] Bridge bound, methods:', Object.keys(window.moonYaFileOps).join(','));
                } else {
                    console.warn('[MoonYa Fetch Patch] BindObjectAsync resolved but window.moonYaFileOps is undefined');
                }
            }),
            new Promise(function(resolve){
                setTimeout(function(){
                    console.warn('[MoonYa Fetch Patch] BindObjectAsync timeout (3s), will retry on demand');
                    resolve();
                },3000);
            })
        ]).catch(function(e){
            console.warn('[MoonYa Fetch Patch] BindObjectAsync rejected:', e);
        });
    } else {
        console.warn('[MoonYa Fetch Patch] CefSharp or BindObjectAsync not available, fallback only');
        bridgeReady=Promise.resolve();
    }

    // ★ 确保桥接可用：带重试（最多 5 次，间隔 200ms），每次重试都重新调用 BindObjectAsync
    function ensureBridge(maxRetry){
        maxRetry=maxRetry||5;
        var attempts=0;
        function check(){
            if(window.moonYaFileOps&&typeof window.moonYaFileOps.browserOp==='function'){
                return Promise.resolve(window.moonYaFileOps);
            }
            attempts++;
            if(attempts>=maxRetry){
                console.warn('[MoonYa Fetch Patch] Bridge unavailable after '+maxRetry+' attempts');
                return Promise.resolve(null);
            }
            return new Promise(function(resolve){
                setTimeout(function(){
                    var p;
                    try{
                        if(typeof CefSharp!=='undefined'&&CefSharp.BindObjectAsync){
                            p=CefSharp.BindObjectAsync('moonYaFileOps').catch(function(){});
                        } else {
                            p=Promise.resolve();
                        }
                    }catch(e){
                        p=Promise.resolve();
                    }
                    p.then(function(){ check().then(resolve); });
                },200);
            });
        }

        return check();
    }

    var origFetch=window.fetch;
    window.fetch=function(input,init){
        // 兼容 fetch(url, opt) 和 fetch(Request, opt) 两种调用形式
        var urlStr='';
        var reqObj=null;
        if(typeof input==='string'){
            urlStr=input;
        } else if(input&&typeof input.url==='string'){
            urlStr=input.url;
            reqObj=input;
        }
        if(!shouldIntercept(urlStr)){
            return origFetch.apply(this,arguments);
        }
        init=init||{};
        // 从 Request 对象或 init 提取 body
        var body=init.body;
        if(body===undefined&&reqObj&&reqObj.body){body=reqObj.body;}
        if(body===undefined||body===null)body='';
        // 非字符串 body（如 Blob/FormData）转字符串
        if(typeof body!=='string'){
            try{body=JSON.stringify(body);}catch(e){body=String(body);}
        }

        var targetUrl=urlStr;
        var opType=classifyOp(targetUrl);
        console.log('[MoonYa Fetch Patch] Intercept', targetUrl, 'type='+opType, 'hasBody='+(body.length>0));

        return bridgeReady.then(function(){
            return ensureBridge(5);
        }).then(function(bridge){
            if(!bridge){
                console.warn('[MoonYa Fetch Patch] Bridge unavailable, fallback to origFetch (may fail on HTTPS→HTTP)');
                return null;
            }
            if(opType==='browser'&&typeof bridge.browserOp==='function'){
                return bridge.browserOp(targetUrl, body);
            }
            if(opType==='exec'&&typeof bridge.execOp==='function'){
                return bridge.execOp(body);
            }
            if(opType==='cu'&&typeof bridge.cuOp==='function'){
                return bridge.cuOp(body);
            }
            if(opType==='mcp'&&typeof bridge.mcpOp==='function'){
                return bridge.mcpOp(body);
            }
            if(typeof bridge.fileOp==='function'){
                return bridge.fileOp(body);
            }
            console.warn('[MoonYa Fetch Patch] No matching bridge method for op='+opType);
            return null;
        }).then(function(r){
            if(r!==null&&r!==undefined){
                var bodyStr=typeof r==='string'?r:JSON.stringify(r);
                console.log('[MoonYa Fetch Patch] Bridge responded len='+bodyStr.length);
                return new Response(bodyStr,{status:200,headers:{'Content-Type':'application/json'}});
            }
            // 桥接不可用，fallback 到原始 fetch（依赖 CefSharp 命令行参数放行）
            return origFetch.apply(this,arguments);
        }.bind(this)).catch(function(e){
            console.error('[MoonYa Fetch Patch] Error:', e&&e.message?e.message:e);
            // 兜底：仍然返回 origFetch，让浏览器原始错误暴露给调用方
            return origFetch.apply(this,arguments);
        }.bind(this));
    };
    console.log('[MoonYa Fetch Patch] Installed successfully');
})();");
                    }
                    catch (Exception ex)
                    {
                        System.Diagnostics.Debug.WriteLine($"Fetch patch injection failed: {ex.Message}");
                    }

                    HideLoadingOverlay();

                    // ═══ 修复：页面加载完成后延迟聚焦浏览器，确保键盘输入正常工作 ═══
                    EnsureBrowserFocus();
                }
            });
        }

        private void RegisterFileOpsBridgeOnce()
        {
            if (_fileOpsBridgeRegistered)
                return;

            try
            {
                _fileOpsBridge = new MoonYaFileOpsBridge(_fileService, WebView, _backendUrl);
                WebView.JavascriptObjectRepository.Register(
                    "moonYaFileOps",
                    _fileOpsBridge,
                    BindingOptions.DefaultBinder);
                _fileOpsBridgeRegistered = true;
                System.Diagnostics.Debug.WriteLine("[FileOps] JS bridge registered before navigation.");
            }
            catch (ArgumentException ex) when (ex.Message.Contains("already", StringComparison.OrdinalIgnoreCase))
            {
                // CefSharp 已持有同名对象时视为注册成功，避免重复导航反复注册。
                _fileOpsBridgeRegistered = true;
                System.Diagnostics.Debug.WriteLine("[FileOps] JS bridge was already registered.");
            }
            catch (Exception ex)
            {
                // 浏览器初始化尚未完成时允许 LoadingStateChanged 再次尝试。
                System.Diagnostics.Debug.WriteLine($"[FileOps] JS bridge registration deferred: {ex.Message}");
            }
        }

        private void OnAddressChanged(object? sender, DependencyPropertyChangedEventArgs e)
        {
            // Address changed — status logging removed with status bar
            // 同步当前地址到 JS 桥接，避免桥接线程访问 WPF 依赖属性触发跨线程异常
            _fileOpsBridge?.UpdateCurrentAddress(e.NewValue as string);
        }

        // ★ 捕获前端控制台输出到日志文件，便于诊断启动器 WebView 中的问题
        private static readonly object _consoleLogLock = new object();
        private void OnWebViewConsoleMessage(object? sender, ConsoleMessageEventArgs e)
        {
            try
            {
                var logPath = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "webconsole.log");
                var line = $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff}] [{e.Level}] {e.Message}";
                lock (_consoleLogLock)
                {
                    File.AppendAllText(logPath, line + Environment.NewLine);
                }
            }
            catch { }
        }

        // ═══ 修复：CefSharp WPF 需要同时设置 WPF 焦点并通知 CEF Host，
        //          否则浏览器内部输入框（如账号管理页）无法接收键盘事件 ═══
        private void EnsureBrowserFocus(bool immediate = false)
        {
            Action focusAction = () =>
            {
                try
                {
                    WebView.Focus();
                    WebView.GetBrowser()?.GetHost()?.SendFocusEvent(true);
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"EnsureBrowserFocus failed: {ex.Message}");
                }
            };

            if (immediate)
                focusAction();
            else
                Dispatcher.BeginInvoke(focusAction, DispatcherPriority.Render);
        }

        private void WebView_GotFocus(object sender, RoutedEventArgs e)
        {
            try
            {
                WebView.GetBrowser()?.GetHost()?.SendFocusEvent(true);
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"WebView_GotFocus failed: {ex.Message}");
            }
        }

        private void WebView_LostFocus(object sender, RoutedEventArgs e)
        {
            // 鼠标操作期间（如拖选文字后松开鼠标）不发送失焦事件，
            // 否则 CEF 收到 SendFocusEvent(false) 会清空文字选区
            if (_isMouseOperationInProgress)
            {
                return;
            }

            try
            {
                WebView.GetBrowser()?.GetHost()?.SendFocusEvent(false);
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"WebView_LostFocus failed: {ex.Message}");
            }
        }

        private void WebView_PreviewMouseDown(object sender, MouseButtonEventArgs e)
        {
            _isMouseOperationInProgress = true;
            if (!WebView.IsFocused)
            {
                // 首次点击立即同步聚焦，确保当前这次点击对应的输入框能立即接收键盘事件
                EnsureBrowserFocus(immediate: true);
            }
        }

        private void WebView_PreviewMouseUp(object sender, MouseButtonEventArgs e)
        {
            // 延迟清除标志：mouseup 后 CefSharp 释放鼠标捕获会触发 LostFocus，
            // 该 LostFocus 仍在当前调度周期内，BeginInvoke(Background) 会排在其后执行
            Dispatcher.BeginInvoke(new Action(() =>
            {
                _isMouseOperationInProgress = false;
            }), System.Windows.Threading.DispatcherPriority.Background);
        }

        // ── loading UI helpers ───────────────────────────

        private void HideLoadingOverlay()
        {
            var fadeOut = new DoubleAnimation
            {
                From = 1.0,
                To = 0.0,
                Duration = TimeSpan.FromMilliseconds(250),
                EasingFunction = new CubicEase { EasingMode = EasingMode.EaseOut }
            };

            fadeOut.Completed += (_, _) =>
            {
                LoadingOverlay.Visibility = Visibility.Collapsed;
                TitleBarGrid.Visibility = Visibility.Visible;
            };

            LoadingOverlay.BeginAnimation(OpacityProperty, fadeOut);
        }

        // ── window chrome (dark mode, rounded corners) ────

        private void ApplyLightTitleBar()
        {
            var hwnd = new WindowInteropHelper(this).EnsureHandle();
            int useDarkMode = 0;
            DwmSetWindowAttribute(hwnd, DWMWA_USE_IMMERSIVE_DARK_MODE, ref useDarkMode, sizeof(int));

            // Light caption / border colors
            int lightColor = 0x00FFFFFF;
            DwmSetWindowAttribute(hwnd, DWMWA_CAPTION_COLOR, ref lightColor, sizeof(int));
            int borderColor = 0x00E0E0E0;
            DwmSetWindowAttribute(hwnd, DWMWA_BORDER_COLOR, ref borderColor, sizeof(int));
        }

        private void ApplyRoundedCorners()
        {
            try
            {
                var hwnd = new WindowInteropHelper(this).EnsureHandle();
                int cornerPref = DWMWCP_ROUND;
                DwmSetWindowAttribute(hwnd, DWMWA_WINDOW_CORNER_PREFERENCE, ref cornerPref, sizeof(int));
            }
            catch
            {
                // Not supported on older Windows versions; no-op
            }
        }

        // ── resize edge detection ─────────────────────────

        private void TitleBar_MouseLeftButtonDown(object sender, MouseButtonEventArgs e)
        {
            if (e.ClickCount == 2)
            {
                MaximizeRestore_Click(sender, e);
            }
            else
            {
                DragMove();
            }
        }

        private void Refresh_Click(object sender, RoutedEventArgs e)
        {
            try
            {
                WebView.Reload();
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"刷新失败: {ex.Message}");
            }
        }

        // ── WndProc: intercept taskbar minimize / restore / maximize ──

        private IntPtr WndProc(IntPtr hwnd, int msg, IntPtr wParam, IntPtr lParam, ref bool handled)
        {
            const int WM_SYSCOMMAND = 0x0112;
            const int SC_MINIMIZE = 0xF020;
            const int SC_RESTORE = 0xF120;
            const int SC_MAXIMIZE = 0xF030;

            // 让 WPF 知道当前显示器的工作区，避免 WindowChrome 最大化时遮住任务栏
            if (msg == WM_GETMINMAXINFO)
            {
                var hMonitor = MonitorFromWindow(hwnd, MONITOR_DEFAULTTONEAREST);
                if (hMonitor != IntPtr.Zero)
                {
                    var mi = new MONITORINFO { cbSize = (uint)Marshal.SizeOf(typeof(MONITORINFO)) };
                    if (GetMonitorInfo(hMonitor, ref mi))
                    {
                        var mmi = Marshal.PtrToStructure<MINMAXINFO>(lParam);
                        mmi.ptMaxPosition.x = mi.rcWork.left;
                        mmi.ptMaxPosition.y = mi.rcWork.top;
                        mmi.ptMaxSize.x = mi.rcWork.right - mi.rcWork.left;
                        mmi.ptMaxSize.y = mi.rcWork.bottom - mi.rcWork.top;
                        Marshal.StructureToPtr(mmi, lParam, true);
                        handled = true;
                    }
                }
                return IntPtr.Zero;
            }

            if (msg == WM_SYSCOMMAND)
            {
                int cmd = (int)(wParam.ToInt64() & 0xFFF0);
                if (cmd == SC_MINIMIZE)
                {
                    handled = true;
                    StartMinimize();
                    return IntPtr.Zero;
                }
                if (cmd == SC_RESTORE && _isMaximized)
                {
                    handled = true;
                    StartRestoreFromMax();
                    return IntPtr.Zero;
                }
                if (cmd == SC_MAXIMIZE && !_isMaximized)
                {
                    handled = true;
                    StartMaximize();
                    return IntPtr.Zero;
                }
            }
            return IntPtr.Zero;
        }

        // ── minimize / restore ─────────────────────────────

        private void Minimize_Click(object sender, RoutedEventArgs e) => StartMinimize();

        private void StartMinimize()
        {
            if (_isMinimizing) return;
            _isMinimizing = true;

            // 不再用 CompositionTarget.Rendering + SetWindowPos 每帧重绘窗口。
            // 直接交给 Windows DWM 执行原生最小化动画，避免 CefSharp OSR 每帧重排重绘导致掉帧。
            WindowState = WindowState.Minimized;
        }

        private void OnActivated(object? sender, EventArgs e)
        {
            _isMinimizing = false;
            EnsureBrowserFocus();
        }

        // ── maximize / restore ─────────────────────────────

        private void MaximizeRestore_Click(object sender, RoutedEventArgs e)
        {
            if (_isMaximized) StartRestoreFromMax();
            else StartMaximize();
        }

        private void StartMaximize()
        {
            if (_isMaximized) return;

            _restoreLeft = Left;
            _restoreTop = Top;
            _restoreWidth = Width;
            _restoreHeight = Height;

            // 交给 WPF + WM_GETMINMAXINFO 处理，能正确适配多显示器/DPI/任务栏
            WindowState = WindowState.Maximized;
        }

        private void StartRestoreFromMax()
        {
            if (!_isMaximized) return;
            WindowState = WindowState.Normal;
        }

        private void OnStateChanged(object? sender, EventArgs e)
        {
            if (WindowState == WindowState.Maximized && !_isMaximized)
            {
                _isMaximized = true;
                MaxCanvas.Visibility = Visibility.Collapsed;
                RestoreCanvas.Visibility = Visibility.Visible;
                MaxRestoreBtn.ToolTip = "还原";
                RootBorder.CornerRadius = new CornerRadius(0);
                RootBorder.BorderThickness = new Thickness(0);
            }
            else if (WindowState == WindowState.Normal && _isMaximized)
            {
                _isMaximized = false;
                MaxCanvas.Visibility = Visibility.Visible;
                RestoreCanvas.Visibility = Visibility.Collapsed;
                MaxRestoreBtn.ToolTip = "最大化";
                RootBorder.CornerRadius = new CornerRadius(8);
                RootBorder.BorderThickness = new Thickness(1);

                // 恢复原来的位置和尺寸
                if (_restoreWidth > 0 && _restoreHeight > 0)
                {
                    Left = _restoreLeft;
                    Top = _restoreTop;
                    Width = _restoreWidth;
                    Height = _restoreHeight;
                }
            }
        }

        private void Close_Click(object sender, RoutedEventArgs e)
        {
            if (_isClosing) return;
            _isClosing = true;

            // 缩短淡出时长，避免 CefSharp OSR 在隐藏期间持续重绘
            var fadeOut = new DoubleAnimation(0, TimeSpan.FromMilliseconds(120))
            {
                EasingFunction = new CubicEase { EasingMode = EasingMode.EaseIn }
            };
            fadeOut.Completed += (_, _) =>
            {
                // Hide to system tray instead of closing
                Hide();
                var icon = App.TrayIcon;
                if (icon != null)
                {
                    icon.Visible = true;
                }
            };
            BeginAnimation(OpacityProperty, fadeOut);
        }

        /// <summary>Exit the application completely — stop services, remove tray icon, shutdown.</summary>
        internal void ExitApplication()
        {
            if (_isExiting) return;
            _isExiting = true;

            // Remove tray icon
            var icon = App.TrayIcon;
            if (icon != null)
            {
                icon.Visible = false;
                icon.Dispose();
            }

            // Force application shutdown (triggers App.Exit which stops API server + Cef.Shutdown)
            Application.Current.Shutdown();
        }

        /// <summary>Show and activate the main window (called from tray icon).</summary>
        internal void ShowWindow()
        {
            if (_isExiting) return;
            _isClosing = false;

            if (WindowState == WindowState.Minimized)
            {
                WindowState = WindowState.Normal;
            }

            if (!IsVisible)
            {
                Opacity = 0;
                Show();
            }

            Activate();

            // ═══ 修复：窗口显示时聚焦浏览器，确保键盘输入正常工作 ═══
            EnsureBrowserFocus();

            // Fade in（缩短时长，降低 CefSharp OSR 合成开销）
            var fadeIn = new DoubleAnimation(1, TimeSpan.FromMilliseconds(120))
            {
                EasingFunction = new CubicEase { EasingMode = EasingMode.EaseOut }
            };
            BeginAnimation(OpacityProperty, fadeIn);
        }

        private static readonly SolidColorBrush CloseIconDark = new(System.Windows.Media.Color.FromRgb(0x1A, 0x1A, 0x1A));
        private static readonly SolidColorBrush CloseIconWhite = new(System.Windows.Media.Color.FromRgb(0xFF, 0xFF, 0xFF));

        private void CloseBtn_MouseEnter(object sender, MouseEventArgs e)
        {
            CloseLine1.Stroke = CloseIconWhite;
            CloseLine2.Stroke = CloseIconWhite;
        }

        private void CloseBtn_MouseLeave(object sender, MouseEventArgs e)
        {
            CloseLine1.Stroke = CloseIconDark;
            CloseLine2.Stroke = CloseIconDark;
        }

        // ── cleanup on close ──────────────────────────────

        /// <summary>Wire system tray icon mouse events.</summary>
        private void WireTrayIcon()
        {
            var icon = App.TrayIcon;
            if (icon == null) return;

            // Left-click: show window（MouseDown 可区分左右键）
            icon.MouseDown += (_, args) =>
            {
                if (args.Button == WinForms.MouseButtons.Left)
                {
                    Dispatcher.Invoke(() => ShowWindow());
                }
            };

            // Right-click: intercept via dummy ContextMenuStrip, then show WPF menu
            var dummyMenu = new WinForms.ContextMenuStrip();
            dummyMenu.Opening += (_, args) =>
            {
                args.Cancel = true; // suppress WinForms menu
                var screenPos = WinForms.Cursor.Position;
                Dispatcher.Invoke(() => ShowTrayMenu(screenPos.X, screenPos.Y));
            };
            icon.ContextMenuStrip = dummyMenu;
        }

        private void ShowTrayMenu(int screenX, int screenY)
        {
            var menu = new TrayMenuWindow(
                onShow: () => Dispatcher.Invoke(() => ShowWindow()),
                onExit: () => Dispatcher.BeginInvoke(() => ExitApplication())
            );

            menu.PositionNearTray(screenX, screenY);
            menu.Show();
        }

        private void OnClosing(object? sender, CancelEventArgs e)
        {
            // Only stop services when actually exiting (not when hiding to tray)
            if (_isExiting)
            {
            }
        }

        protected override void OnClosed(EventArgs e)
        {
            UninstallKeyboardHook();

            lock (_pttGlowLock)
            {
                try
                {
                    _pttGlowOverlay?.Close();
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"[PTT Glow] Close failed: {ex.Message}");
                }
                _pttGlowOverlay = null;
            }

            base.OnClosed(e);
        }
    }

    // ── JS Bridge for File Operations ─────────────────────

    public class MoonYaFileOpsBridge
    {
        private readonly FileOperationService _service;
        private readonly CefSharp.Wpf.ChromiumWebBrowser _webView;
        private readonly Uri? _allowedOrigin;
        // 当前 WebView 地址的线程安全缓存。WPF 依赖属性只能在 UI 线程读取，
        // 但 CefSharp 桥接方法可能在工作线程调用 IsTrustedOrigin，直接访问
        // _webView.Address 会抛 InvalidOperationException。
        // 由 MainWindow.OnAddressChanged 在 UI 线程上更新。
        private volatile string? _currentAddress;
        private static readonly System.Net.Http.HttpClient _httpClient = new System.Net.Http.HttpClient();

        public MoonYaFileOpsBridge(
            FileOperationService service,
            CefSharp.Wpf.ChromiumWebBrowser webView,
            string backendUrl)
        {
            _service = service;
            _webView = webView;
            _allowedOrigin = Uri.TryCreate(backendUrl, UriKind.Absolute, out var parsed) ? parsed : null;
            // 构造发生在 UI 线程，可安全读取一次初始地址（导航前可能为空，后续由 OnAddressChanged 补齐）
            _currentAddress = webView.Address;
        }

        /// <summary>
        /// 由 UI 线程在 WebView 地址变化时调用，更新缓存以便工作线程安全读取。
        /// </summary>
        public void UpdateCurrentAddress(string? address)
        {
            _currentAddress = address;
        }

        public async Task<Dictionary<string, object>> downloadFile(string url, string savePath)
        {
            return await _service.Download(url, savePath);
        }

        public async Task<Dictionary<string, object>> openFolder(string path)
        {
            return await _service.OpenFile(path);
        }

        public async Task<Dictionary<string, object>> pickFolder()
        {
            return await _service.PickFolder();
        }

        public async Task<Dictionary<string, object>> pickLocalPaths()
        {
            if (!IsTrustedOrigin())
                return new Dictionary<string, object> { ["success"] = false, ["message"] = "当前页面无权选择本地路径。" };

            return await _service.PickLocalPaths();
        }

        /// <summary>
        /// 直接打开原生文件选择对话框（无 MessageBox 中间确认），由前端 UI 决定调用。
        /// </summary>
        public async Task<Dictionary<string, object>> pickLocalFiles()
        {
            if (!IsTrustedOrigin())
                return new Dictionary<string, object> { ["success"] = false, ["message"] = "当前页面无权选择本地路径。" };

            return await _service.PickLocalFiles();
        }

        /// <summary>
        /// 直接打开原生文件夹选择对话框（无 MessageBox 中间确认），由前端 UI 决定调用。
        /// </summary>
        public async Task<Dictionary<string, object>> pickLocalFolders()
        {
            if (!IsTrustedOrigin())
                return new Dictionary<string, object> { ["success"] = false, ["message"] = "当前页面无权选择本地路径。" };

            return await _service.PickLocalFolders();
        }

        public Dictionary<string, object> revokeLocalPath(string path)
        {
            if (!IsTrustedOrigin())
                return new Dictionary<string, object> { ["success"] = false, ["message"] = "当前页面无权管理本地路径。" };

            return _service.RevokeLocalPath(path);
        }

        /// <summary>
        /// 通用文件操作中继：浏览器将 /file-op 请求体转发给 C#，
        /// 直接在当前进程调用 FileOperationApiServer 的分发逻辑，
        /// 不依赖 HTTP.sys 回环监听，同时绕过 CefSharp 的 CORS / PNA 限制。
        /// </summary>
        public async Task<string> fileOp(string body)
        {
            try
            {
                return await App.ExecuteFileOpAsync(body);
            }
            catch (Exception ex)
            {
                return System.Text.Json.JsonSerializer.Serialize(new { success = false, message = $"C# 文件操作失败: {ex.Message}" });
            }
        }

        /// <summary>
        /// 通用 CU 操作中继：与 fileOp 相同机制，路由到 /cu-op。
        /// </summary>
        public async Task<string> cuOp(string body)
        {
            try
            {
                if (App.FileServiceBaseAddress == null) throw new InvalidOperationException("文件服务清单尚未初始化");
                var content = new System.Net.Http.StringContent(body, System.Text.Encoding.UTF8, "application/json");
                var resp = await _httpClient.PostAsync(
                    new Uri(App.FileServiceBaseAddress, LocalServiceProtocol.ComputerUseRoute), content);
                return await resp.Content.ReadAsStringAsync();
            }
            catch (Exception ex)
            {
                return System.Text.Json.JsonSerializer.Serialize(new { success = false, message = $"C# CU API 调用失败: {ex.Message}" });
            }
        }

        /// <summary>
        /// 执行服务中继：浏览器将 /execute 请求体转发给 C#，
        /// 由 C# 通过运行时服务清单调用 ExecutionApiServer，
        /// 用于 Python / CLI 代码执行，绕过远程后端无法访问用户本机的问题。
        /// </summary>
        public async Task<string> execOp(string body)
        {
            try
            {
                if (App.ExecutionServiceBaseAddress == null) throw new InvalidOperationException("执行服务清单尚未初始化");
                var content = new System.Net.Http.StringContent(body, System.Text.Encoding.UTF8, "application/json");
                using var cts = new System.Threading.CancellationTokenSource(
                    TimeSpan.FromMilliseconds(App.ExecutionRelayTimeoutMs));
                var resp = await _httpClient.PostAsync(
                    new Uri(App.ExecutionServiceBaseAddress, LocalServiceProtocol.ExecutionRoute), content, cts.Token);
                return await resp.Content.ReadAsStringAsync();
            }
            catch (System.Threading.Tasks.TaskCanceledException)
            {
                return System.Text.Json.JsonSerializer.Serialize(new { status = "error", error = "C# Execution API 调用超时，命令可能仍在后台执行", duration_ms = App.ExecutionRelayTimeoutMs, risk_level = "low" });
            }
            catch (Exception ex)
            {
                return System.Text.Json.JsonSerializer.Serialize(new { status = "error", error = $"C# Execution API 调用失败: {ex.Message}", duration_ms = 0, risk_level = "low" });
            }
        }

        /// <summary>
        /// 浏览器自动化中继：浏览器将 /browser/* 请求转发给 C#，
        /// 由 C# 通过运行时服务清单调用 BrowserApiServer，
        /// 绕过 CefSharp HTTPS 页面无法直接 fetch HTTP 本地 API 的 mixed content / PNA 限制。
        /// </summary>
        public async Task<string> browserOp(string url, string body)
        {
            if (!IsTrustedOrigin())
            {
                return System.Text.Json.JsonSerializer.Serialize(new
                {
                    success = false,
                    error_code = BrowserProtocol.Errors.OriginDenied,
                    error = "当前页面无权访问本机浏览器服务。"
                });
            }

            try
            {
                var relativeRoute = string.IsNullOrWhiteSpace(url) ? BrowserProtocol.StatusRoute : url;
                if (!Uri.TryCreate(relativeRoute, UriKind.Relative, out _) ||
                    !BrowserProtocol.IsAllowedRoute(relativeRoute) ||
                    App.BrowserServiceBaseAddress == null)
                {
                    return System.Text.Json.JsonSerializer.Serialize(new
                    {
                        success = false,
                        error_code = BrowserProtocol.Errors.RouteDenied,
                        error = "浏览器桥只接受运行时协议清单声明的相对路由。"
                    });
                }

                var targetUrl = new Uri(App.BrowserServiceBaseAddress, relativeRoute);
                var content = new System.Net.Http.StringContent(body ?? "{}", System.Text.Encoding.UTF8, "application/json");
                using var cts = new System.Threading.CancellationTokenSource(
                    TimeSpan.FromMilliseconds(App.BrowserRelayTimeoutMs));
                var resp = await _httpClient.PostAsync(targetUrl, content, cts.Token);
                return await resp.Content.ReadAsStringAsync();
            }
            catch (System.Threading.Tasks.TaskCanceledException)
            {
                return System.Text.Json.JsonSerializer.Serialize(new
                {
                    success = false,
                    error_code = BrowserProtocol.Errors.Timeout,
                    error = "浏览器自动化服务调用超时。"
                });
            }
            catch (Exception ex)
            {
                return System.Text.Json.JsonSerializer.Serialize(new { success = false, error = $"浏览器自动化 API 调用失败: {ex.Message}" });
            }
        }

        /// <summary>
        /// Executes a native MCP operation through the long-lived official SDK client.
        /// </summary>
        public Task<string> mcpOp(string body)
        {
            if (!IsTrustedOrigin())
            {
                return Task.FromResult(System.Text.Json.JsonSerializer.Serialize(new
                {
                    ok = false,
                    error = new { code = "origin_denied", message = "当前页面无权访问本机 MCP 网关。" }
                }));
            }
            return App.ExecuteMcpOpAsync(body ?? "{}");
        }

        /// <summary>
        /// Reads a local artifact through an allow-list and MIME/size gate.
        /// </summary>
        public string previewArtifact(string path, string allowedRootsJson)
        {
            if (!IsTrustedOrigin())
            {
                return System.Text.Json.JsonSerializer.Serialize(new
                {
                    ok = false,
                    error = new { code = "origin_denied", message = "当前页面无权预览本机文件。" }
                });
            }
            return App.PreviewArtifact(path, allowedRootsJson ?? "[]");
        }

        private bool IsTrustedOrigin()
        {
            if (_allowedOrigin == null ||
                !Uri.TryCreate(_currentAddress, UriKind.Absolute, out var current))
            {
                return false;
            }
            return string.Equals(current.Scheme, _allowedOrigin.Scheme, StringComparison.OrdinalIgnoreCase) &&
                   string.Equals(current.Host, _allowedOrigin.Host, StringComparison.OrdinalIgnoreCase) &&
                   current.Port == _allowedOrigin.Port;
        }
    }

    /// <summary>
    /// JS Bridge：向前端公开输入框焦点状态同步接口。
    /// 前端输入框聚焦/失焦时调用 setInputFocused，C# 端据此决定 Ctrl+Space 是否触发 PTT。
    /// </summary>
    public class InputFocusBridge
    {
        private readonly MainWindow _window;

        public InputFocusBridge(MainWindow window)
        {
            _window = window;
        }

        public void setInputFocused(bool focused)
        {
            _window.SetInputFocusState(focused);
        }
    }
}
