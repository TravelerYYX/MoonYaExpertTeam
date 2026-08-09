using System;
using System.Collections.Generic;
using System.Globalization;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Media.Animation;
using System.Windows.Threading;
using Microsoft.Win32;
using MoonYa.Services;
using WinForms = System.Windows.Forms;

namespace MoonYa
{
    /// <summary>
    /// 语音输入时的屏幕边缘发光覆盖层。
    /// 覆盖主显示器工作区，不接收输入、不窃取焦点。
    /// 支持水波入场动画与实时音量驱动的光效缩放/亮度变化。
    /// </summary>
    public partial class PttGlowOverlay : Window
    {
        private readonly Storyboard _fadeOutStoryboard;
        private readonly Storyboard _entranceStoryboard;
        private readonly ScaleTransform _glowScale;
        private readonly ScaleTransform _topGlowScale;
        private readonly ScaleTransform _bottomGlowScale;
        private readonly DispatcherTimer _volumeTimer;
        private readonly DispatcherTimer _statusTimer;

        private bool _isHiding;
        private bool _entranceCompleted;
        private double _targetVolume;
        private double _currentVolume;
        private bool _windowInitDone;

        // 缓存 Owner 以便订阅主窗口状态变化；最小化时临时断开 Owner 避免光效被一起隐藏
        private Window? _ownerWindow;

        // ── Win32 API：Show() 后若 WPF 仍未能使窗口可见，用 Win32 强制显示 ──
        [DllImport("user32.dll")]
        private static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
        [DllImport("user32.dll")]
        private static extern bool SetForegroundWindow(IntPtr hWnd);
        [DllImport("user32.dll")]
        private static extern bool IsWindowVisible(IntPtr hWnd);
        [DllImport("user32.dll")]
        private static extern bool SetWindowPos(IntPtr hWnd, IntPtr hWndInsertAfter,
            int X, int Y, int cx, int cy, uint uFlags);
        private const int SW_SHOW = 5;
        private const int SW_RESTORE = 9;
        private static readonly IntPtr HWND_TOPMOST = new IntPtr(-1);
        private const uint SWP_NOACTIVATE = 0x0010;
        private const uint SWP_NOMOVE = 0x0002;
        private const uint SWP_NOSIZE = 0x0001;
        private const uint SWP_SHOWWINDOW = 0x0040;

        // ── 语音对话模式相关字段 ──────────────────────────────
        // 当前语音对话模式，默认 off。合法值见 SetVoiceChatMode。
        private readonly InteractionVisualState _desiredVisual = new();
        private string _voiceChatMode
        {
            get => _desiredVisual.VoiceMode;
            set => _desiredVisual.VoiceMode = value;
        }

        // 各模式 Storyboard 缓存，便于切换时停止上一模式动画
        private readonly Dictionary<string, Storyboard> _modeStoryboards = new();

        // ── Computer Use 模式相关字段 ─────────────────────────
        // 当前 CU 模式，默认 off。合法值见 SetCuMode。
        private string _cuMode
        {
            get => _desiredVisual.CuMode;
            set => _desiredVisual.CuMode = value;
        }
        // Independent desired states. Render priority: PTT > voice > CU > off.
        private bool _pttActive
        {
            get => _desiredVisual.PttActive;
            set => _desiredVisual.PttActive = value;
        }
        private readonly Storyboard _cuBadgeFadeIn;
        private readonly Storyboard _cuBadgeFadeOut;

        private static void ReportDebug(string hypothesisId, string location, string msg, object? data = null)
        {
            // Optional development tracing is supplied through ReportDebugLog.
        }

        // XAML 中定义的原始渐变笔刷缓存，用于退出语音模式或 capturing 模式时恢复
        private readonly Brush _originalTopBrush;
        private readonly Brush _originalBottomBrush;
        private readonly Brush _originalLeftBrush;
        private readonly Brush _originalRightBrush;

        // ── 语音对话模式视觉参数（避免魔法数字） ────────────────
        // listening：青蓝色，慢呼吸缩放（提高透明度确保在明亮背景下可见）
        private const double ListeningBaseOpacity = 0.65;          // 整体透明度：0.65 确保高可见
        private const double ListeningBreathMinScale = 1.0;        // 呼吸最小缩放
        private const double ListeningBreathMaxScale = 1.15;       // 呼吸最大缩放（+15%）
        private const int ListeningBreathPeriodMs = 2500;          // 呼吸完整周期（一次扩张+收缩）

        // recognizing：琥珀色中速稳定脉冲
        private const double RecognizingMinOpacity = 0.4;
        private const double RecognizingMaxOpacity = 0.7;
        private const int RecognizingPulsePeriodMs = 1200;         // 脉冲完整周期

        // thinking：蓝色慢脉冲
        private const double ThinkingMinOpacity = 0.3;
        private const double ThinkingMaxOpacity = 0.6;
        private const int ThinkingPulsePeriodMs = 1800;            // 脉冲完整周期

        // ai_speaking：紫粉色快速脉冲
        private const double AiSpeakingMinOpacity = 0.5;
        private const double AiSpeakingMaxOpacity = 0.9;
        private const int AiSpeakingPulsePeriodMs = 600;           // 脉冲完整周期

        // ── 语音对话模式配色（ARGB） ─────────────────────────
        private static readonly Color ListeningColor = Color.FromArgb(0xFF, 0x22, 0xD3, 0xEE);   // 亮青 #22D3EE
        private static readonly Color ListeningColor2 = Color.FromArgb(0xFF, 0x60, 0xA5, 0xFA);  // 亮蓝 #60A5FA
        private static readonly Color RecognizingColor = Color.FromArgb(0xFF, 0xFB, 0xBF, 0x24); // 琥珀 #FBBF24
        private static readonly Color ThinkingColor = Color.FromArgb(0xFF, 0x60, 0xA5, 0xFA);    // 蓝 #60A5FA
        private static readonly Color AiSpeakingColor1 = Color.FromArgb(0xFF, 0xC4, 0xB5, 0xFD); // 薰衣草 #C4B5FD
        private static readonly Color AiSpeakingColor2 = Color.FromArgb(0xFF, 0xF9, 0xA8, 0xD4); // 粉 #F9A8D4

        // 调试日志委托（由 MainWindow 注入）
        public Action<string, string, string, string>? ReportDebugLog { get; set; }

        public PttGlowOverlay()
        {
            InitializeComponent();

            // XAML 已设置 WindowStyle/AllowsTransparency/ShowActivated，
            // 窗口 source 创建后再设置这些属性会抛 InvalidOperationException。
            // 因此标记为已完成，避免 EnsureVisibleWithoutEntrance 重复设置。
            _windowInitDone = true;

            _fadeOutStoryboard = (Storyboard)FindResource("FadeOutStoryboard");
            _fadeOutStoryboard.Completed += OnFadeOutCompleted;

            _entranceStoryboard = (Storyboard)FindResource("WaterEntranceStoryboard");
            _glowScale = (ScaleTransform)GlowRoot.RenderTransform;
            _topGlowScale = (ScaleTransform)TopGlow.RenderTransform;
            _bottomGlowScale = (ScaleTransform)BottomGlow.RenderTransform;

            // 缓存 XAML 中定义的原始渐变笔刷，供语音对话模式退出/capturing 时恢复
            _originalTopBrush = TopGlow.Background;
            _originalBottomBrush = BottomGlow.Background;
            _originalLeftBrush = LeftGlow.Background;
            _originalRightBrush = RightGlow.Background;

            _volumeTimer = new DispatcherTimer(
                TimeSpan.FromMilliseconds(16),
                DispatcherPriority.Render,
                OnVolumeTick,
                Dispatcher);
            _volumeTimer.Stop();

            _statusTimer = new DispatcherTimer(
                TimeSpan.FromMilliseconds(400),
                DispatcherPriority.Render,
                OnStatusTick,
                Dispatcher);
            _statusTimer.Stop();

            _entranceStoryboard.Completed += OnEntranceCompleted;

            _cuBadgeFadeIn = (Storyboard)FindResource("CuBadgeFadeInStoryboard");
            _cuBadgeFadeOut = (Storyboard)FindResource("CuBadgeFadeOutStoryboard");
            _cuBadgeFadeOut.Completed += OnCuBadgeFadeOutCompleted;

            RecalculateBounds();

            // 监听显示设置变化，分辨率/DPI/任务栏位置变化时重新定位
            SystemEvents.DisplaySettingsChanged += OnDisplaySettingsChanged;
        }

        /// <summary>
        /// 播放水波入场动画并开始监听音量变化。
        /// </summary>
        public void PlayEntranceAnimation()
        {
            if (Dispatcher.CheckAccess() == false)
            {
                Dispatcher.Invoke(PlayEntranceAnimation);
                return;
            }

            _pttActive = true;
            StopAllModeStoryboards();
            if (_cuMode == "active") ShowCuBadge();

            _isHiding = false;
            _entranceCompleted = false;

            // 清除上一轮动画的 HoldEnd 状态，否则本地 Opacity 重置会被旧的动画时钟覆盖
            try
            {
                _fadeOutStoryboard.Remove(GlowRoot);
                _entranceStoryboard.Remove(GlowRoot);
            }
            catch { }

            RecalculateBounds();
            OverlayLog("E", "PttGlowOverlay.xaml.cs:PlayEntranceAnimation", "Enter", GetOverlayStatusJson());

            // 重置状态：全屏水波和各边从隐藏开始，由 Storyboard 唤醒
            GlowRoot.Opacity = 1.0;
            WaterFill.Visibility = Visibility.Visible;
            WaterFill.Opacity = 0.0;
            WaterFillScale.ScaleY = 0.0;
            TopGlow.Opacity = 0.0;
            BottomGlow.Opacity = 0.0;
            LeftGlow.Opacity = 0.0;
            RightGlow.Opacity = 0.0;
            _glowScale.ScaleX = 1.0;
            _glowScale.ScaleY = 1.0;
            _topGlowScale.ScaleX = 1.0;
            _topGlowScale.ScaleY = 1.0;
            _bottomGlowScale.ScaleX = 1.0;
            _bottomGlowScale.ScaleY = 1.0;
            LeftGlowScale.ScaleY = 0.0;
            RightGlowScale.ScaleY = 0.0;
            _currentVolume = 0.0;
            _targetVolume = 0.0;

            _fadeOutStoryboard.Stop();
            _volumeTimer.Stop();
            OverlayLog("E", "PttGlowOverlay.xaml.cs:PlayEntranceAnimation", "Before Show", GetOverlayStatusJson());

            if (!IsVisible)
            {
                // 不激活窗口，避免抢夺焦点
                Show();
            }

            // 复用 EnsureWindowVisibleAndTopmost 中的 Visibility/WindowState/Win32 兜底，
            // 解决单纯 Show() 后透明 Owned 窗口仍无 WS_VISIBLE 导致光效不显示的问题。
            EnsureWindowVisibleAndTopmost();

            OverlayLog("E", "PttGlowOverlay.xaml.cs:PlayEntranceAnimation", "After EnsureVisible", GetOverlayStatusJson());
            _entranceStoryboard.Begin(GlowRoot, true);
            _volumeTimer.Start();
            _statusTimer.Start();
            OverlayLog("E", "PttGlowOverlay.xaml.cs:PlayEntranceAnimation", "Animation started", GetOverlayStatusJson());
        }

        /// <summary>
        /// 播放水波入场动画，但不启动 PTT 的音量/状态计时器。
        /// 用于实时语音对话模式入口；动画完成后由 OnEntranceCompleted 续接 listening 呼吸动画。
        /// </summary>
        private void PlayVoiceChatEntranceAnimation()
        {
            OverlayLog("E", "PttGlowOverlay.xaml.cs:PlayVoiceChatEntranceAnimation",
                $"Enter: IsVisible={IsVisible}, OwnerIsNull={Owner == null}, WindowState={WindowState}",
                GetOverlayStatusJson());

            EnsureWindowVisibleAndTopmost();

            OverlayLog("E", "PttGlowOverlay.xaml.cs:PlayVoiceChatEntranceAnimation",
                $"After EnsureWindowVisibleAndTopmost: IsVisible={IsVisible}, OwnerIsNull={Owner == null}, WindowState={WindowState}",
                GetOverlayStatusJson());

            _entranceCompleted = false;

            // 清除上一轮动画的 HoldEnd 状态，避免本地 Opacity 重置被旧动画时钟覆盖
            try
            {
                _fadeOutStoryboard.Remove(GlowRoot);
                _entranceStoryboard.Remove(GlowRoot);
            }
            catch { }

            // 重置状态：全屏水波和各边从隐藏开始，由 Storyboard 唤醒
            GlowRoot.Opacity = 1.0;
            WaterFill.Visibility = Visibility.Visible;
            WaterFill.Opacity = 0.0;
            WaterFillScale.ScaleY = 0.0;
            TopGlow.Opacity = 0.0;
            BottomGlow.Opacity = 0.0;
            LeftGlow.Opacity = 0.0;
            RightGlow.Opacity = 0.0;
            _glowScale.ScaleX = 1.0;
            _glowScale.ScaleY = 1.0;
            _topGlowScale.ScaleX = 1.0;
            _topGlowScale.ScaleY = 1.0;
            _bottomGlowScale.ScaleX = 1.0;
            _bottomGlowScale.ScaleY = 1.0;
            LeftGlowScale.ScaleY = 0.0;
            RightGlowScale.ScaleY = 0.0;

            OverlayLog("E", "PttGlowOverlay.xaml.cs:PlayVoiceChatEntranceAnimation",
                $"Before entrance begin: IsVisible={IsVisible}, OwnerIsNull={Owner == null}, WindowState={WindowState}",
                GetOverlayStatusJson());

            _entranceStoryboard.Begin(GlowRoot, true);
        }

        /// <summary>
        /// 更新当前音量目标值，范围 [0.0, 1.0]。
        /// </summary>
        public void UpdateVolume(double level)
        {
            if (Dispatcher.CheckAccess() == false)
            {
                Dispatcher.Invoke(() => UpdateVolume(level));
                return;
            }

            _targetVolume = Math.Clamp(level, 0.0, 1.0);
        }

        /// <summary>
        /// 隐藏光效：先淡出再关闭窗口。
        /// </summary>
        public void HideGlow()
        {
            if (Dispatcher.CheckAccess() == false)
            {
                Dispatcher.Invoke(HideGlow);
                return;
            }

            _pttActive = false;
            OverlayLog("E", "PttGlowOverlay.xaml.cs:HideGlow", "Enter", GetOverlayStatusJson());

            if (_voiceChatMode != "off" || _cuMode == "active")
            {
                _isHiding = false;
                ResumeDesiredVisual();
                return;
            }

            if (!IsVisible || _isHiding)
            {
                OverlayLog("E", "PttGlowOverlay.xaml.cs:HideGlow", "Skip: not visible or hiding", GetOverlayStatusJson());
                _statusTimer.Stop();
                return;
            }

            _isHiding = true;
            _entranceCompleted = false;
            _volumeTimer.Stop();
            _statusTimer.Stop();
            _targetVolume = 0.0;
            _currentVolume = 0.0;
            _entranceStoryboard.Stop();
            _fadeOutStoryboard.Begin(GlowRoot, true);
        }

        // ── 语音对话模式 (Voice Chat Mode) ────────────────────────

        /// <summary>
        /// 设置实时语音对话模式的光效状态。
        /// <para>mode 取值：</para>
        /// <para>  off          - 淡出隐藏</para>
        /// <para>  listening    - 青蓝色低强度 + 慢呼吸缩放</para>
        /// <para>  capturing    - 交由 UpdateVolume 驱动</para>
        /// <para>  recognizing  - 琥珀色中速脉冲</para>
        /// <para>  thinking     - 蓝色慢脉冲</para>
        /// <para>  ai_speaking  - 紫粉色快速脉冲</para>
        /// 非法值忽略并记录日志。线程安全：非 UI 线程自动 Dispatcher.Invoke。
        /// </summary>
        public void SetVoiceChatMode(string mode)
        {
            if (Dispatcher.CheckAccess() == false)
            {
                Dispatcher.Invoke(() => SetVoiceChatMode(mode));
                return;
            }

            OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                $"方法进入: mode={mode}, IsVisible={IsVisible}, GlowRoot.Opacity={GlowRoot.Opacity:F3}, " +
                $"Top={TopGlow.Opacity:F3}, Bottom={BottomGlow.Opacity:F3}, Left={LeftGlow.Opacity:F3}, Right={RightGlow.Opacity:F3}",
                GetOverlayStatusJson());

            // 合法模式白名单
            var validModes = new HashSet<string>
            {
                "off", "listening", "capturing", "recognizing", "thinking", "ai_speaking"
            };
            if (!validModes.Contains(mode))
            {
                OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                    $"非法模式忽略: {mode}", GetOverlayStatusJson());
                return;
            }

            string previousMode = _voiceChatMode;

            // 与当前模式重复时直接忽略，避免二次确认等场景重复播放入场动画
            if (mode == previousMode)
            {
                OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                    $"模式未变化，忽略: {mode}", GetOverlayStatusJson());
                return;
            }

            _voiceChatMode = mode;
            if (_cuMode == "active") ShowCuBadge();
            OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                $"切换 {previousMode} -> {mode}, IsVisible={IsVisible}, Opacity={GlowRoot.Opacity}, " +
                $"Top={TopGlow.Opacity}, Bottom={BottomGlow.Opacity}, Left={LeftGlow.Opacity}, Right={RightGlow.Opacity}",
                GetOverlayStatusJson());

            // #region debug-point H2:mode-called
            ReportDebug("H2", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                $"SetVoiceChatMode called: {previousMode} -> {mode}",
                new { IsVisible = IsVisible, GlowRootOpacity = GlowRoot.Opacity, Top = TopGlow.Opacity, Bottom = BottomGlow.Opacity, Left = LeftGlow.Opacity, Right = RightGlow.Opacity });
            // #endregion

            // PTT temporarily owns the edge animation. Desired voice/CU states
            // are retained and will be restored when PTT ends.
            if (_pttActive) return;

            // 切换前先停止上一模式的动画与计时器
            StopAllModeStoryboards();

            switch (mode)
            {
                case "off":
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                        $"case off: 准备恢复笔刷并隐藏, IsVisible={IsVisible}, Opacity={GlowRoot.Opacity:F3}", GetOverlayStatusJson());
                    // 恢复原始笔刷并淡出隐藏
                    RestoreOriginalBrushes();
                    if (_cuMode == "active") StartCuSteadyGlow();
                    else HideGlow();
                    break;
                case "listening":
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                        $"case listening: 准备启动, IsVisible={IsVisible}, Opacity={GlowRoot.Opacity:F3}", GetOverlayStatusJson());
                    StartListeningMode(previousMode);
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                        $"listening 模式启动后: Opacity={GlowRoot.Opacity}", GetOverlayStatusJson());
                    break;
                case "capturing":
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                        $"case capturing: 准备启动, IsVisible={IsVisible}, Opacity={GlowRoot.Opacity:F3}", GetOverlayStatusJson());
                    StartCapturingMode();
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                        $"capturing 模式启动后: Opacity={GlowRoot.Opacity}", GetOverlayStatusJson());
                    break;
                case "recognizing":
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                        $"case recognizing: 准备启动, IsVisible={IsVisible}, Opacity={GlowRoot.Opacity:F3}", GetOverlayStatusJson());
                    StartRecognizingMode();
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                        $"recognizing 模式启动后: Opacity={GlowRoot.Opacity}", GetOverlayStatusJson());
                    break;
                case "thinking":
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                        $"case thinking: 准备启动, IsVisible={IsVisible}, Opacity={GlowRoot.Opacity:F3}", GetOverlayStatusJson());
                    StartThinkingMode();
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                        $"thinking 模式启动后: Opacity={GlowRoot.Opacity}", GetOverlayStatusJson());
                    break;
                case "ai_speaking":
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                        $"case ai_speaking: 准备启动, IsVisible={IsVisible}, Opacity={GlowRoot.Opacity:F3}", GetOverlayStatusJson());
                    StartAiSpeakingMode();
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:SetVoiceChatMode",
                        $"ai_speaking 模式启动后: Opacity={GlowRoot.Opacity}", GetOverlayStatusJson());
                    break;
            }
        }

        /// <summary>
        /// 停止所有语音对话模式 Storyboard 并清理引用。
        /// </summary>
        private void StopAllModeStoryboards()
        {
            foreach (var kv in _modeStoryboards)
            {
                try
                {
                    kv.Value.Stop(GlowRoot);
                    kv.Value.Remove(GlowRoot);
                }
                catch { }
            }
            _modeStoryboards.Clear();

            // 语音模式下不使用 PTT 的音量/状态计时器，统一停止
            _volumeTimer.Stop();
            _statusTimer.Stop();
        }

        /// <summary>
        /// 监听主窗口状态变化。WPF 默认会在 Owner 最小化时自动隐藏 Owned 窗口，
        /// 导致语音对话时光效随主窗口一起消失。此处临时断开 Owner 关系并强制显示，
        /// 使光效作为独立 Topmost 窗口继续显示；主窗口恢复后再重新建立 Owner 关系。
        /// </summary>
        private void OnOwnerStateChanged(object? sender, EventArgs e)
        {
            if (_ownerWindow == null) return;

            if (_ownerWindow.WindowState == WindowState.Minimized
                && (_pttActive || _voiceChatMode != "off" || _cuMode == "active"))
            {
                Owner = null;
                if (!IsVisible)
                {
                    Show();
                }
                Topmost = true;
                WindowState = WindowState.Normal;
                OverlayLog("E", "PttGlowOverlay.xaml.cs:OnOwnerStateChanged",
                    "主窗口最小化，断开 Owner 保持语音对话光效显示", GetOverlayStatusJson());
            }
            else if (_ownerWindow.WindowState != WindowState.Minimized &&
                     _ownerWindow.Visibility == Visibility.Visible &&
                     (_pttActive || _voiceChatMode != "off" || _cuMode == "active") && Owner != _ownerWindow)
            {
                // 主窗口从最小化恢复，重新建立 Owner 关系
                Owner = _ownerWindow;
                Topmost = true;
                OverlayLog("E", "PttGlowOverlay.xaml.cs:OnOwnerStateChanged",
                    "主窗口恢复，重新建立 Owner 关系", GetOverlayStatusJson());
            }
        }

        /// <summary>
        /// 确保窗口可见并置顶，但不重置四边 Opacity。
        /// 提取自 EnsureVisibleWithoutEntrance，供入场动画与模式切换共用。
        /// </summary>
        private void EnsureWindowVisibleAndTopmost()
        {
            StopAllModeStoryboards();

            _isHiding = false;

            // 停止并移除淡出动画，避免其继续锁定 Opacity
            try
            {
                _fadeOutStoryboard.Stop(GlowRoot);
                _fadeOutStoryboard.Remove(GlowRoot);
            }
            catch { }

            // 确保窗口以无边框、透明、不激活、置顶方式显示
            Topmost = true;
            try
            {
                WindowStyle = WindowStyle.None;
                AllowsTransparency = true;
                ShowActivated = false;
            }
            catch { }

            // 基本窗口属性已在 XAML 中定义。ShowActivated/WindowStyle/AllowsTransparency
            // 在窗口已经显示过后再设置会抛出 InvalidOperationException，因此仅在首次
            // 显示前设置一次；Topmost 与 WindowState 可在显示后安全调整。
            if (!_windowInitDone)
            {
                WindowStyle = WindowStyle.None;
                AllowsTransparency = true;
                ShowActivated = false;
                _windowInitDone = true;
            }
            Topmost = true;
            WindowState = WindowState.Normal;

            // 必须依附于主窗口，否则 ShowActivated=false 的透明窗口在某些情况下无法显示。
            // 但如果 Owner 当前最小化/隐藏，Owned 窗口 Show() 后会被 WPF 强制隐藏，
            // 导致光效不可见。因此先临时断开 Owner，让光效窗口作为独立 Topmost 窗口显示；
            // 等主窗口恢复后再由 OnOwnerStateChanged 重新建立 Owner 关系。
            Window? ownerBeforeShow = Owner;
            Window? mainWindow = Application.Current?.MainWindow;
            if (Owner != null && (Owner.WindowState == WindowState.Minimized || Owner.Visibility != Visibility.Visible))
            {
                // 保留 _ownerWindow 引用，继续监听主窗口状态变化（恢复时重建 Owner）
                Owner = null;
                OverlayLog("E", "PttGlowOverlay.xaml.cs:EnsureWindowVisibleAndTopmost",
                    $"Owner 非可见({ownerBeforeShow?.WindowState}/{ownerBeforeShow?.Visibility})，临时断开 Owner 后 Show", GetOverlayStatusJson());
            }

            // 只有在主窗口可见且未最小化时才设置 Owner；否则保持独立 Topmost 窗口
            if (Owner == null && mainWindow != null &&
                mainWindow.WindowState != WindowState.Minimized &&
                mainWindow.Visibility == Visibility.Visible)
            {
                Owner = mainWindow;
                OverlayLog("E", "PttGlowOverlay.xaml.cs:EnsureWindowVisibleAndTopmost",
                    $"主窗口可见，设置 Owner=MainWindow", GetOverlayStatusJson());
            }

            // 订阅主窗口状态变化：最小化时 WPF 会自动隐藏 Owned 窗口，导致光效消失。
            // 需要在最小化时临时断开 Owner 关系以保持光效显示。
            if (Owner != _ownerWindow)
            {
                if (_ownerWindow != null)
                {
                    _ownerWindow.StateChanged -= OnOwnerStateChanged;
                }
                _ownerWindow = Owner;
                if (_ownerWindow != null)
                {
                    _ownerWindow.StateChanged += OnOwnerStateChanged;
                }
            }
            // 如果当前没有 Owner 但主窗口存在，也订阅主窗口状态变化以便恢复时重建 Owner
            else if (Owner == null && mainWindow != null && mainWindow != _ownerWindow)
            {
                if (_ownerWindow != null)
                {
                    _ownerWindow.StateChanged -= OnOwnerStateChanged;
                }
                _ownerWindow = mainWindow;
                _ownerWindow.StateChanged += OnOwnerStateChanged;
                OverlayLog("E", "PttGlowOverlay.xaml.cs:EnsureWindowVisibleAndTopmost",
                    $"无主窗口，订阅 MainWindow 状态变化以便恢复", GetOverlayStatusJson());
            }

            if (!IsVisible)
            {
                // 不激活窗口，避免抢夺焦点。
                // 注意：在 Hide() 之后必须直接调用 Show()，而不是先设置 Visibility=Visible，
                // 否则 WPF 可能认为窗口已经可见，导致第二次显示失败。
                Show();
            }

            // Show() 已将 Visibility 置为 Visible；如果因某些状态仍不可见，再强制置为 Visible。
            if (Visibility != Visibility.Visible)
            {
                Visibility = Visibility.Visible;
            }

            // 兜底：若 WPF 层面仍未真正显示，使用 Win32 API 强制窗口显示并置顶。
            // 透明 Owned 窗口在 Owner 状态异常或 DWM 未刷新时可能出现这种情况。
            try
            {
                var hwnd = new WindowInteropHelper(this).Handle;
                if (hwnd != IntPtr.Zero && !IsWindowVisible(hwnd))
                {
                    ShowWindow(hwnd, SW_RESTORE);
                    ShowWindow(hwnd, SW_SHOW);
                    SetWindowPos(hwnd, HWND_TOPMOST, 0, 0, 0, 0,
                        SWP_NOACTIVATE | SWP_NOMOVE | SWP_NOSIZE | SWP_SHOWWINDOW);
                    OverlayLog("E", "PttGlowOverlay.xaml.cs:EnsureWindowVisibleAndTopmost",
                        "执行 Win32 强制显示兜底", GetOverlayStatusJson());
                }
            }
            catch (Exception ex)
            {
                OverlayLog("E", "PttGlowOverlay.xaml.cs:EnsureWindowVisibleAndTopmost",
                    $"Win32 强制显示异常: {ex.Message}", GetOverlayStatusJson());
            }

            // 强制布局刷新，确保窗口位置和大小生效
            UpdateLayout();
            RecalculateBounds();
            UpdateLayout();

            // 强制完成一次渲染，避免透明 Owned 窗口 Show() 后内容未立即合成
            ForceRender();
        }

        /// <summary>
        /// 显示窗口但不播放水波入场动画，供语音对话模式直接显示光效。
        /// 重置缩放/水波/计时器到干净状态，四边光效默认完全可见，
        /// 整体强度由各模式通过 GlowRoot.Opacity 或脉冲动画控制。
        /// </summary>
        private void EnsureVisibleWithoutEntrance()
        {
            EnsureWindowVisibleAndTopmost();

            // 标记入场已完成，避免 OnVolumeTick 触碰 WaterFill
            _entranceCompleted = true;

            // 四边光效默认完全可见，整体强度由后续模式动画控制
            TopGlow.Opacity = 1.0;
            BottomGlow.Opacity = 1.0;
            LeftGlow.Opacity = 1.0;
            RightGlow.Opacity = 1.0;
            GlowRoot.Opacity = 1.0;

            // 强制清除四边与 GlowRoot 上可能残留的动画时钟，确保后续直接设置 Opacity 生效
            TopGlow.BeginAnimation(Border.OpacityProperty, null);
            BottomGlow.BeginAnimation(Border.OpacityProperty, null);
            LeftGlow.BeginAnimation(Border.OpacityProperty, null);
            RightGlow.BeginAnimation(Border.OpacityProperty, null);
            GlowRoot.BeginAnimation(Grid.OpacityProperty, null);

            // 同样清除各缩放变换上的动画时钟，避免呼吸/音量动画 hold 住变换值
            _glowScale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
            _glowScale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
            _topGlowScale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
            _topGlowScale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
            _bottomGlowScale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
            _bottomGlowScale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
            LeftGlowScale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
            RightGlowScale.BeginAnimation(ScaleTransform.ScaleYProperty, null);

            // 隐藏全屏水波填充
            WaterFill.Visibility = Visibility.Collapsed;
            WaterFill.Opacity = 0.0;
            WaterFillScale.ScaleY = 0.0;

            // 重置所有缩放变换
            _glowScale.ScaleX = 1.0;
            _glowScale.ScaleY = 1.0;
            _topGlowScale.ScaleX = 1.0;
            _topGlowScale.ScaleY = 1.0;
            _bottomGlowScale.ScaleX = 1.0;
            _bottomGlowScale.ScaleY = 1.0;
            LeftGlowScale.ScaleY = 1.0;
            RightGlowScale.ScaleY = 1.0;

            // 语音模式自身管理计时器，先停止 PTT 计时器
            _volumeTimer.Stop();
            _statusTimer.Stop();

            // 四边光效默认完全可见，由 GlowRoot.Opacity 统一控制强度
            TopGlow.Opacity = 1.0;
            BottomGlow.Opacity = 1.0;
            LeftGlow.Opacity = 1.0;
            RightGlow.Opacity = 1.0;
            GlowRoot.Opacity = 1.0;

            // 强制完成一次渲染，避免透明 Owned 窗口 Show() 后内容未立即合成
            ForceRender();

            OverlayLog("E", "PttGlowOverlay.xaml.cs:EnsureVisibleWithoutEntrance",
                $"窗口已确保可见: IsVisible={IsVisible}, GlowRoot.Opacity={GlowRoot.Opacity}, " +
                $"Left={Left}, Top={Top}, Width={Width}, Height={Height}, " +
                $"TopGlowSize={TopGlow.ActualWidth:F0}x{TopGlow.ActualHeight:F0}, " +
                $"LeftGlowSize={LeftGlow.ActualWidth:F0}x{LeftGlow.ActualHeight:F0}, " +
                $"TopBrush={TopGlow.Background?.GetType().Name}", GetOverlayStatusJson());

            // #region debug-point H1:window-visibility
            ReportDebug("H1", "PttGlowOverlay.xaml.cs:EnsureVisibleWithoutEntrance",
                "Window visibility ensured",
                new
                {
                    IsVisible = IsVisible,
                    GlowRootOpacity = GlowRoot.Opacity,
                    Left = Left,
                    Top = Top,
                    Width = Width,
                    Height = Height,
                    OwnerHandle = Owner != null ? Owner.GetType().Name : null
                });
            // #endregion
        }

        /// <summary>
        /// 强制 WPF 完成一次渲染，用于透明窗口 Show() 后内容未立即刷新时的兜底。
        /// </summary>
        private void ForceRender()
        {
            try
            {
                Dispatcher.Invoke(() => { }, DispatcherPriority.Render);
            }
            catch { }
        }

        /// <summary>
        /// 将同一笔刷实例应用到四个边缘光效。
        /// </summary>
        private void ApplyBrushToAllGlows(Brush brush)
        {
            TopGlow.Background = brush;
            BottomGlow.Background = brush;
            LeftGlow.Background = brush;
            RightGlow.Background = brush;
        }

        /// <summary>
        /// 恢复 XAML 中定义的原始渐变笔刷。
        /// </summary>
        private void RestoreOriginalBrushes()
        {
            TopGlow.Background = _originalTopBrush;
            BottomGlow.Background = _originalBottomBrush;
            LeftGlow.Background = _originalLeftBrush;
            RightGlow.Background = _originalRightBrush;
        }

        /// <summary>
        /// listening 模式入口。
        /// 若从 off 进入，先播放 Ctrl+Space 同款水波入场动画，完成后续接 listening 呼吸动画；
        /// 若从其他模式返回 listening，直接启动呼吸动画，避免重复入场。
        /// </summary>
        private void StartListeningMode(string previousMode)
        {
            OverlayLog("E", "PttGlowOverlay.xaml.cs:StartListeningMode",
                $"Enter: previousMode={previousMode}, IsVisible={IsVisible}, OwnerIsNull={Owner == null}, WindowState={WindowState}",
                GetOverlayStatusJson());

            // 使用 PTT 原始笔刷（已验证 Ctrl+Space 能正常显示），确保入场光效可见
            RestoreOriginalBrushes();

            if (previousMode == "off")
            {
                // 播放水波入场动画；不启动 PTT 音量计时器，避免与语音对话状态机冲突
                PlayVoiceChatEntranceAnimation();
                // 呼吸动画在入场完成后由 OnEntranceCompleted -> StartListeningBreathing 启动
            }
            else
            {
                StartListeningBreathing();
            }
        }

        /// <summary>
        /// listening 模式呼吸动画：青蓝色低强度光效 + 慢呼吸缩放（1.0↔1.18，周期 2.5s）。
        /// 由水波入场动画完成后调用。
        /// </summary>
        private void StartListeningBreathing()
        {
            // 清理入场动画对四边 Opacity 的锁定，确保后续直接设置 Opacity 生效
            TopGlow.BeginAnimation(Border.OpacityProperty, null);
            BottomGlow.BeginAnimation(Border.OpacityProperty, null);
            LeftGlow.BeginAnimation(Border.OpacityProperty, null);
            RightGlow.BeginAnimation(Border.OpacityProperty, null);
            GlowRoot.BeginAnimation(Grid.OpacityProperty, null);

            TopGlow.Opacity = 1.0;
            BottomGlow.Opacity = 1.0;
            LeftGlow.Opacity = 1.0;
            RightGlow.Opacity = 1.0;
            GlowRoot.Opacity = ListeningBaseOpacity;

            // 慢呼吸：缩放 1.0↔1.18，AutoReverse 使完整周期 = 2 * Duration
            var sb = new Storyboard();
            TimeSpan halfPeriod = TimeSpan.FromMilliseconds(ListeningBreathPeriodMs / 2.0);

            var scaleX = new DoubleAnimation
            {
                From = ListeningBreathMinScale,
                To = ListeningBreathMaxScale,
                Duration = halfPeriod,
                AutoReverse = true,
                RepeatBehavior = RepeatBehavior.Forever,
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            };
            Storyboard.SetTarget(scaleX, _glowScale);
            Storyboard.SetTargetProperty(scaleX, new PropertyPath(ScaleTransform.ScaleXProperty));
            sb.Children.Add(scaleX);

            var scaleY = new DoubleAnimation
            {
                From = ListeningBreathMinScale,
                To = ListeningBreathMaxScale,
                Duration = halfPeriod,
                AutoReverse = true,
                RepeatBehavior = RepeatBehavior.Forever,
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            };
            Storyboard.SetTarget(scaleY, _glowScale);
            Storyboard.SetTargetProperty(scaleY, new PropertyPath(ScaleTransform.ScaleYProperty));
            sb.Children.Add(scaleY);

            sb.Begin(GlowRoot, true);
            _modeStoryboards["listening"] = sb;

            OverlayLog("E", "PttGlowOverlay.xaml.cs:StartListeningBreathing",
                $"listening 呼吸动画已启动: GlowRootOpacity={GlowRoot.Opacity:F3}, IsVisible={IsVisible}, " +
                $"TopGlowSize={TopGlow.ActualWidth:F0}x{TopGlow.ActualHeight:F0}, TopBrush={TopGlow.Background?.GetType().Name}",
                GetOverlayStatusJson());

            // #region debug-point H3:listening-started
            ReportDebug("H3", "PttGlowOverlay.xaml.cs:StartListeningBreathing",
                "listening breathing storyboard started",
                new { GlowRootOpacity = GlowRoot.Opacity, IsVisible = IsVisible, ScaleMin = ListeningBreathMinScale, ScaleMax = ListeningBreathMaxScale, TopGlowSize = $"{TopGlow.ActualWidth:F0}x{TopGlow.ActualHeight:F0}" });
            // #endregion
        }

        /// <summary>
        /// capturing 模式：恢复原始笔刷，由 UpdateVolume/OnVolumeTick 驱动光效。
        /// 确保 _volumeTimer 启动以接收音量变化。
        /// </summary>
        private void StartCapturingMode()
        {
            EnsureVisibleWithoutEntrance();

            // 恢复 PTT 原始渐变笔刷，让音量驱动的颜色生效
            RestoreOriginalBrushes();

            // 整体不透明，由 OnVolumeTick 控制各边 opacity
            GlowRoot.Opacity = 1.0;

            // 初始化音量为 0，等待 UpdateVolume 驱动
            _currentVolume = 0.0;
            _targetVolume = 0.0;

            // 启动音量驱动计时器
            _volumeTimer.Start();
            _statusTimer.Start();
        }

        /// <summary>
        /// recognizing 模式：琥珀色中速稳定脉冲（opacity 0.4↔0.7，周期 1.2s）。
        /// </summary>
        private void StartRecognizingMode()
        {
            EnsureVisibleWithoutEntrance();

            ApplyBrushToAllGlows(new SolidColorBrush(RecognizingColor));

            // 动画驱动前的基线透明度（动画移除后保持低调）
            GlowRoot.Opacity = RecognizingMinOpacity;

            var sb = new Storyboard();
            TimeSpan halfPeriod = TimeSpan.FromMilliseconds(RecognizingPulsePeriodMs / 2.0);

            var opacityAnim = new DoubleAnimation
            {
                From = RecognizingMinOpacity,
                To = RecognizingMaxOpacity,
                Duration = halfPeriod,
                AutoReverse = true,
                RepeatBehavior = RepeatBehavior.Forever,
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            };
            Storyboard.SetTarget(opacityAnim, GlowRoot);
            Storyboard.SetTargetProperty(opacityAnim, new PropertyPath(UIElement.OpacityProperty));
            sb.Children.Add(opacityAnim);

            sb.Begin(GlowRoot, true);
            _modeStoryboards["recognizing"] = sb;
        }

        /// <summary>
        /// thinking 模式：蓝色慢脉冲（opacity 0.3↔0.6，周期 1.8s）。
        /// </summary>
        private void StartThinkingMode()
        {
            EnsureVisibleWithoutEntrance();

            ApplyBrushToAllGlows(new SolidColorBrush(ThinkingColor));

            GlowRoot.Opacity = ThinkingMinOpacity;

            var sb = new Storyboard();
            TimeSpan halfPeriod = TimeSpan.FromMilliseconds(ThinkingPulsePeriodMs / 2.0);

            var opacityAnim = new DoubleAnimation
            {
                From = ThinkingMinOpacity,
                To = ThinkingMaxOpacity,
                Duration = halfPeriod,
                AutoReverse = true,
                RepeatBehavior = RepeatBehavior.Forever,
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            };
            Storyboard.SetTarget(opacityAnim, GlowRoot);
            Storyboard.SetTargetProperty(opacityAnim, new PropertyPath(UIElement.OpacityProperty));
            sb.Children.Add(opacityAnim);

            sb.Begin(GlowRoot, true);
            _modeStoryboards["thinking"] = sb;
        }

        /// <summary>
        /// ai_speaking 模式：紫粉色快速脉冲（opacity 0.5↔0.9，周期 0.6s），
        /// 颜色在薰衣草紫与粉之间交替。
        /// </summary>
        private void StartAiSpeakingMode()
        {
            EnsureVisibleWithoutEntrance();

            // 为四个边各创建独立 SolidColorBrush，便于挂接颜色动画
            var glowElements = new[] { TopGlow, BottomGlow, LeftGlow, RightGlow };
            foreach (var glow in glowElements)
            {
                glow.Background = new SolidColorBrush(AiSpeakingColor1);
            }

            GlowRoot.Opacity = AiSpeakingMinOpacity;

            var sb = new Storyboard();
            TimeSpan halfPeriod = TimeSpan.FromMilliseconds(AiSpeakingPulsePeriodMs / 2.0);

            // 透明度脉冲
            var opacityAnim = new DoubleAnimation
            {
                From = AiSpeakingMinOpacity,
                To = AiSpeakingMaxOpacity,
                Duration = halfPeriod,
                AutoReverse = true,
                RepeatBehavior = RepeatBehavior.Forever,
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            };
            Storyboard.SetTarget(opacityAnim, GlowRoot);
            Storyboard.SetTargetProperty(opacityAnim, new PropertyPath(UIElement.OpacityProperty));
            sb.Children.Add(opacityAnim);

            // 颜色交替：紫↔粉，对四个边各挂一个 ColorAnimation
            foreach (var glow in glowElements)
            {
                var colorAnim = new ColorAnimation
                {
                    From = AiSpeakingColor1,
                    To = AiSpeakingColor2,
                    Duration = halfPeriod,
                    AutoReverse = true,
                    RepeatBehavior = RepeatBehavior.Forever,
                    EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
                };
                Storyboard.SetTarget(colorAnim, glow);
                Storyboard.SetTargetProperty(colorAnim,
                    new PropertyPath("(Border.Background).(SolidColorBrush.Color)"));
                sb.Children.Add(colorAnim);
            }

            sb.Begin(GlowRoot, true);
            _modeStoryboards["ai_speaking"] = sb;
        }

        // ── 语音对话模式结束 ────────────────────────────────────

        // ── CU（Computer Use）模式 ────────────────────────────

        /// <summary>
        /// 设置 CU（Computer Use）模式的光效状态。
        /// <para>mode 取值：</para>
        /// <para>  active  - 水波入场 + 四边光效稳定显示 + 顶部"MoonYa正在操作电脑"徽标</para>
        /// <para>  off     - 隐藏徽标并淡出光效</para>
        /// CU、语音与 PTT 分别保存期望状态，渲染优先级为 PTT > 语音 > CU。
        /// 重复调用 active 幂等忽略，非法值忽略并记录日志。
        /// 线程安全：非 UI 线程自动 Dispatcher.Invoke。
        /// </summary>
        public void SetCuMode(string mode)
        {
            if (Dispatcher.CheckAccess() == false)
            {
                Dispatcher.Invoke(() => SetCuMode(mode));
                return;
            }

            OverlayLog("E", "PttGlowOverlay.xaml.cs:SetCuMode",
                $"方法进入: mode={mode}, IsVisible={IsVisible}, GlowRoot.Opacity={GlowRoot.Opacity:F3}",
                GetOverlayStatusJson());

            if (mode != "active" && mode != "off")
            {
                OverlayLog("E", "PttGlowOverlay.xaml.cs:SetCuMode",
                    $"非法模式忽略: {mode}", GetOverlayStatusJson());
                return;
            }

            if (mode == _cuMode)
            {
                OverlayLog("E", "PttGlowOverlay.xaml.cs:SetCuMode",
                    $"模式未变化，忽略: {mode}", GetOverlayStatusJson());
                return;
            }

            OverlayLog("E", "PttGlowOverlay.xaml.cs:SetCuMode",
                $"切换 {_cuMode} -> {mode}", GetOverlayStatusJson());

            _cuMode = mode;

            if (mode == "off")
            {
                HideCuBadge();
                if (_pttActive) return;
                if (_voiceChatMode != "off")
                {
                    ResumeDesiredVisual();
                    return;
                }
                StopAllModeStoryboards();
                RestoreOriginalBrushes();
                HideGlow();
                return;
            }

            ShowCuBadge();
            if (_pttActive || _voiceChatMode != "off") return;
            StopAllModeStoryboards();
            StartCuMode();
        }

        private void ResumeDesiredVisual()
        {
            if (_pttActive) return;
            StopAllModeStoryboards();
            switch (_voiceChatMode)
            {
                case "listening": StartListeningMode("off"); break;
                case "capturing": StartCapturingMode(); break;
                case "recognizing": StartRecognizingMode(); break;
                case "thinking": StartThinkingMode(); break;
                case "ai_speaking": StartAiSpeakingMode(); break;
                default:
                    if (_cuMode == "active") StartCuSteadyGlow();
                    break;
            }
            if (_cuMode == "active") ShowCuBadge();
        }

        private void StartCuMode()
        {
            RestoreOriginalBrushes();
            PlayVoiceChatEntranceAnimation();
        }

        private void StartCuSteadyGlow()
        {
            TopGlow.BeginAnimation(Border.OpacityProperty, null);
            BottomGlow.BeginAnimation(Border.OpacityProperty, null);
            LeftGlow.BeginAnimation(Border.OpacityProperty, null);
            RightGlow.BeginAnimation(Border.OpacityProperty, null);
            GlowRoot.BeginAnimation(Grid.OpacityProperty, null);

            TopGlow.Opacity = 1.0;
            BottomGlow.Opacity = 1.0;
            LeftGlow.Opacity = 1.0;
            RightGlow.Opacity = 1.0;
            GlowRoot.Opacity = 1.0;

            WaterFill.Visibility = Visibility.Collapsed;
            WaterFill.Opacity = 0.0;
            WaterFillScale.ScaleY = 0.0;

            ShowCuBadge();
            OverlayLog("E", "PttGlowOverlay.xaml.cs:StartCuSteadyGlow",
                "CU 稳定光效已启动", GetOverlayStatusJson());
        }

        private void ShowCuBadge()
        {
            _cuBadgeFadeOut.Stop(CuBadge);
            CuBadge.Visibility = Visibility.Visible;
            _cuBadgeFadeIn.Begin(CuBadge, true);
        }

        private void HideCuBadge()
        {
            _cuBadgeFadeIn.Stop(CuBadge);
            _cuBadgeFadeOut.Begin(CuBadge, true);
        }

        private void OnCuBadgeFadeOutCompleted(object? sender, EventArgs e)
        {
            if (Dispatcher.CheckAccess() == false)
            {
                Dispatcher.Invoke(() => OnCuBadgeFadeOutCompleted(sender, e));
                return;
            }

            CuBadge.Visibility = Visibility.Collapsed;
        }

        /// <summary>
        /// 重新计算窗口边界为主显示器工作区。
        /// </summary>
        public void RecalculateBounds()
        {
            if (Dispatcher.CheckAccess() == false)
            {
                Dispatcher.Invoke(RecalculateBounds);
                return;
            }

            try
            {
                var screen = WinForms.Screen.PrimaryScreen;
                if (screen == null)
                    return;

                Left = screen.WorkingArea.Left;
                Top = screen.WorkingArea.Top;
                Width = screen.WorkingArea.Width;
                Height = screen.WorkingArea.Height;
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[PTT Glow] RecalculateBounds failed: {ex.Message}");
            }
        }

        private void OnVolumeTick(object? sender, EventArgs e)
        {
            if (_isHiding)
                return;

            // 低通平滑：让音量变化更柔和，避免麦克风噪音导致光效抖动
            _currentVolume += (_targetVolume - _currentVolume) * 0.15;

            // 声波驱动：仅作用于屏幕四边边缘光效。
            // 全屏水波（WaterFill）在入场结束后已被 Collapsed，此处绝不再触碰。
            if (_entranceCompleted)
            {
                if (WaterFill.Visibility != Visibility.Collapsed)
                    WaterFill.Visibility = Visibility.Collapsed;
                WaterFill.Opacity = 0.0;
                WaterFillScale.ScaleY = 0.0;
            }

            const double baseScale = 1.0;
            const double baseOpacity = 0.75;

            double scaleBoost = _currentVolume * 0.18;  // 最大放大 18%
            double opacityBoost = _currentVolume * 0.22; // 最大提亮 22%

            double edgeScale = baseScale + scaleBoost;
            double edgeOpacity = Math.Min(1.0, baseOpacity + opacityBoost);

            // 上下边缘：横向轻微缩放
            _topGlowScale.ScaleX = edgeScale;
            _bottomGlowScale.ScaleX = edgeScale;

            // 左右边缘：纵向缩放（从底部向上涨的效果）
            LeftGlowScale.ScaleY = edgeScale;
            RightGlowScale.ScaleY = edgeScale;

            // 四边透明度统一脉冲
            TopGlow.Opacity = edgeOpacity;
            BottomGlow.Opacity = edgeOpacity;
            LeftGlow.Opacity = edgeOpacity;
            RightGlow.Opacity = edgeOpacity;
        }

        // #region debug-point E:overlay-status
        private void OverlayLog(string hypothesisId, string location, string msg, string dataJson = "{}")
        {
            try { ReportDebugLog?.Invoke(hypothesisId, location, msg, dataJson); }
            catch { }
        }

        private string GetOverlayStatusJson()
        {
            try
            {
                return $"{{" +
                    $"\"isVisible\":{IsVisible.ToString().ToLowerInvariant()}," +
                    $"\"windowState\":\"{WindowState}\"," +
                    $"\"isHiding\":{_isHiding.ToString().ToLowerInvariant()}," +
                    $"\"topmost\":{Topmost.ToString().ToLowerInvariant()}," +
                    $"\"left\":{Left.ToString(CultureInfo.InvariantCulture)}," +
                    $"\"top\":{Top.ToString(CultureInfo.InvariantCulture)}," +
                    $"\"width\":{ActualWidth.ToString(CultureInfo.InvariantCulture)}," +
                    $"\"height\":{ActualHeight.ToString(CultureInfo.InvariantCulture)}," +
                    $"\"glowRootOpacity\":{GlowRoot.Opacity.ToString("F3", CultureInfo.InvariantCulture)}," +
                    $"\"waterOpacity\":{WaterFill.Opacity.ToString("F3", CultureInfo.InvariantCulture)}," +
                    $"\"topOpacity\":{TopGlow.Opacity.ToString("F3", CultureInfo.InvariantCulture)}," +
                    $"\"bottomOpacity\":{BottomGlow.Opacity.ToString("F3", CultureInfo.InvariantCulture)}," +
                    $"\"leftOpacity\":{LeftGlow.Opacity.ToString("F3", CultureInfo.InvariantCulture)}," +
                    $"\"rightOpacity\":{RightGlow.Opacity.ToString("F3", CultureInfo.InvariantCulture)}" +
                    $"}}";
            }
            catch (Exception ex)
            {
                return $"{{\"error\":\"{ex.Message.Replace("\\", "\\\\").Replace("\"", "\\\"")}\"}}";
            }
        }

        private void OnStatusTick(object? sender, EventArgs e)
        {
            if (_isHiding)
            {
                _statusTimer.Stop();
                return;
            }
            OverlayLog("E", "PttGlowOverlay.xaml.cs:OnStatusTick", "Status while visible", GetOverlayStatusJson());
        }

        private void OnEntranceCompleted(object? sender, EventArgs e)
        {
            OverlayLog("E", "PttGlowOverlay.xaml.cs:OnEntranceCompleted", "Entrance storyboard completed", GetOverlayStatusJson());

            // 入场动画结束：确保全屏水波完全静止并隐藏，避免后续音量响应时产生任何全屏抖动
            try
            {
                _entranceStoryboard.Remove(WaterFill);
                WaterFill.Opacity = 0.0;
                WaterFillScale.ScaleY = 0.0;
                WaterFill.Visibility = Visibility.Collapsed;
                _entranceCompleted = true;
            }
            catch { }

            // 若当前处于实时语音对话 listening 模式，续接 listening 呼吸动画
            if (_pttActive)
            {
                if (_cuMode == "active") ShowCuBadge();
            }
            else if (_voiceChatMode == "listening")
            {
                StartListeningBreathing();
            }
            else if (_cuMode == "active")
            {
                StartCuSteadyGlow();
            }
        }
        // #endregion

        private void OnDisplaySettingsChanged(object? sender, EventArgs e)
        {
            Dispatcher.InvokeAsync(() =>
            {
                if (IsVisible && !_isHiding)
                {
                    RecalculateBounds();
                }
            });
        }

        private void OnFadeOutCompleted(object? sender, EventArgs e)
        {
            if (Dispatcher.CheckAccess() == false)
            {
                Dispatcher.Invoke(() => OnFadeOutCompleted(sender, e));
                return;
            }

            try
            {
                OverlayLog("E", "PttGlowOverlay.xaml.cs:OnFadeOutCompleted", "Enter", GetOverlayStatusJson());
                _statusTimer.Stop();
                if (_pttActive || _voiceChatMode != "off" || _cuMode == "active")
                {
                    _isHiding = false;
                    ResumeDesiredVisual();
                    return;
                }
                CuBadge.Visibility = Visibility.Collapsed;
                if (_isHiding)
                {
                    Hide();
                    _isHiding = false;
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[PTT Glow] Fade out completed handler failed: {ex.Message}");
            }
        }

        protected override void OnClosed(EventArgs e)
        {
            _statusTimer.Stop();
            _entranceStoryboard.Completed -= OnEntranceCompleted;
            SystemEvents.DisplaySettingsChanged -= OnDisplaySettingsChanged;
            _fadeOutStoryboard.Completed -= OnFadeOutCompleted;
            _cuBadgeFadeOut.Completed -= OnCuBadgeFadeOutCompleted;
            _volumeTimer.Stop();
            if (_ownerWindow != null)
            {
                _ownerWindow.StateChanged -= OnOwnerStateChanged;
            }
            base.OnClosed(e);
        }
    }
}
