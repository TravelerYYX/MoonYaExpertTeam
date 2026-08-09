// ┌─────────────────────────────────────────────────────────┐
// │  PetWindow — 芙宁娜 Q版桌宠桌面覆盖窗口                │
// │  功能：拖拽 / 缩放 / 散步 / 闲置动作 / 聊天气泡 / 右键菜单 │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Generic;
using System.IO;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Media.Animation;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
using MoonYa.Services;

namespace MoonYa
{
    public partial class PetWindow : Window
    {
        // ───── 配置常量 ─────
        private const double DefaultSize = 200;
        private const double MinSize = 80;
        private const double MaxSize = 600;
        private const double SpriteAspect = 941.0 / 1672.0; // 立绘宽高比
        private const double StrollSpeedPxPerSec = 90;
        private const double StrollTopOffset = 10;
        private const int IdleTimeoutMs = 20000;
        private const int BubbleDurationMs = 4500;
        private const int AiReplyHideMs = 7000;   // AI 回答气泡停留时长（流式结束不再更新后）
        private const int AiSummaryMaxChars = 60; // AI 回答摘要最多字数（按句子边界截取）
        private const int BlinkHoldMs = 120;      // 闭眼保持时长
        private const int TalkFrameMs = 150;      // 说话嘴型切换间隔

        // 芙宁娜文案（pet_bubbles.json 读取失败时的兜底）
        private static readonly string[] DefaultBubbleMessages =
        {
            "本小姐的水之印章，可不是闹着玩的！",
            "欢迎来到芙宁娜的舞台，请为我喝彩！",
            "今晚的剧目，由我来为你独演。",
            "哼哼，这份喜悦就让水神来收下吧。",
            "不要太过沉迷于我的身影哦？",
            "欧庇克莱歌剧院的灯光，依旧为我而亮。",
            "我可是能召唤万千水流的大明星。",
            "旅行者，你也来为我鼓掌了吗？",
            "这幕短剧的主角，永远是本小姐。",
            "啊哈~ 今天的水流也格外清澈呢。",
            "让暴风雨来得更猛烈些吧！",
            "别忘了，我也是有粉丝团的。",
            "你的注视，让本小姐很是受用呢。",
            "让水神为你献上一支舞吧。",
            "有什么心事尽管告诉我，我会替水带走。",
            "走吧，去见证下一场辉煌的演出。"
        };

        // 闲置姿态（已移除 turn —— 用户不喜欢绕竖直中轴翻转的效果）
        private static readonly string[] IdlePoses = { "bounce", "sway", "wiggle" };

        // ───── 气泡文案配置（Assets/pet_bubbles.json）─────
        private string _greeting = "本小姐芙宁娜，闪亮登场！";
        private string _strollStartText = "让本小姐为你走两步~";
        private string _sizeFormat = "尺寸·{0}px";
        private string[] _messages = DefaultBubbleMessages;

        // 文件拖拽删除文案（pet_bubbles.json 可覆盖；{0} = 数量）
        private string _fileDropHint = "哦？要交给本小姐处理吗？松手就丢掉咯～";
        private string _fileDeleteFail = "呜……有 {0} 件连本小姐都搬不动！";
        private string[] _fileDeleteMessages = { "帮你丢掉了 {0} 件，不用谢本小姐～" };
        private bool _isDropHintShowing;   // 当前气泡是拖入提示（DragLeave 时需要收起）

        // AI 回答同步气泡（pet_bubbles.json 可覆盖兜底文案）
        private string _aiCodeOnlyText = "（这段回答全是代码，本小姐就不念啦～）";
        private bool _isAiReplyShowing;    // 当前气泡是 AI 回答（流式更新时只换文字）

        // ───── TTS 配置（走项目后端 api/tts.php，复用其 MiniMax 配置）─────
        private bool _ttsEnabled = true;
        private readonly string _ttsEndpoint;
        private MediaPlayer? _ttsPlayer;
        private readonly Queue<string> _ttsTempFiles = new Queue<string>();

        // ───── 立绘帧图 ─────
        private BitmapImage? _headMain;   // 正面·睁眼·张嘴
        private BitmapImage? _headEc;     // 正面·闭眼（眨眼帧）
        private BitmapImage? _headMc;     // 正面·闭嘴（说话帧）
        private BitmapImage? _sideLa;     // 侧面·朝左·步态A
        private BitmapImage? _sideLb;     // 侧面·朝左·步态B
        private BitmapImage? _sideRa;     // 侧面·朝右·步态A
        private BitmapImage? _sideRb;     // 侧面·朝右·步态B

        // ───── 状态 ─────
        private bool _isPetVisible;
        private double _petSize = DefaultSize;
        private bool _isStrolling;
        private int _strollDir = 1;
        private double _walkPhase;
        private bool _walkFrameA;         // 当前步态帧（A/B 交替）
        private DateTime _lastInteract = DateTime.Now;
        private string? _currentPose;
        private bool _isDragging;
        private Point _dragStartOrigin;
        private readonly Random _rnd = new Random();
        private bool _isUpdatingSize;
        private bool _isExiting;          // 退场动画播放中
        private bool _isBlinking;         // 闭眼帧显示中
        private bool _talkMouthClosed;    // 说话帧交替状态

        // ───── 定时器 ─────
        private readonly DispatcherTimer _strollTimer;
        private readonly DispatcherTimer _idleTimer;
        private readonly DispatcherTimer _poseTimer;
        private readonly DispatcherTimer _bubbleHideTimer;
        private readonly DispatcherTimer _blinkTimer;      // 随机眨眼触发
        private readonly DispatcherTimer _blinkOpenTimer;  // 闭眼后睁开
        private readonly DispatcherTimer _talkTimer;       // 说话嘴型交替
        private DateTime _strollLastTick;

        // ───── 设置文件 ─────
        private static string SettingsFilePath
        {
            get
            {
                var dir = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
                    "MoonYa");
                Directory.CreateDirectory(dir);
                return Path.Combine(dir, "pet_settings.json");
            }
        }

        public bool IsPetVisible => _isPetVisible;

        /// <summary>桌宠可见且 TTS 开启 → 由桌宠朗读 AI 回答摘要（应用侧短句播报让位）。</summary>
        public bool IsAiReadingEnabled => _isPetVisible && _ttsEnabled;

        public PetWindow(string ttsEndpoint)
        {
            if (!Uri.TryCreate(ttsEndpoint, UriKind.Absolute, out var endpointUri) ||
                (endpointUri.Scheme != Uri.UriSchemeHttp && endpointUri.Scheme != Uri.UriSchemeHttps))
                throw new ArgumentException("ttsEndpoint 必须是有效的 HTTP URL", nameof(ttsEndpoint));
            _ttsEndpoint = endpointUri.AbsoluteUri;
            InitializeComponent();

            // 加载持久化设置
            LoadSettings();

            // 应用初始尺寸
            ApplySize();

            // 默认位置：屏幕顶部偏左
            if (double.IsNaN(Left) || Left == 0) Left = 80;
            if (double.IsNaN(Top) || Top == 0) Top = 80;

            // 散步定时器（约 60fps）
            _strollTimer = new DispatcherTimer(DispatcherPriority.Render)
            {
                Interval = TimeSpan.FromMilliseconds(16)
            };
            _strollTimer.Tick += OnStrollTick;

            // 闲置检测定时器
            _idleTimer = new DispatcherTimer
            {
                Interval = TimeSpan.FromMilliseconds(1000)
            };
            _idleTimer.Tick += OnIdleCheck;
            _idleTimer.Start();

            // 姿态切换定时器（闲置时使用）
            _poseTimer = new DispatcherTimer
            {
                Interval = TimeSpan.FromSeconds(5)
            };
            _poseTimer.Tick += OnPoseChange;

            // 气泡自动隐藏定时器
            _bubbleHideTimer = new DispatcherTimer
            {
                Interval = TimeSpan.FromMilliseconds(BubbleDurationMs)
            };
            _bubbleHideTimer.Tick += (_, _) =>
            {
                HideBubble();
                _bubbleHideTimer.Stop();
            };

            // 眨眼：随机 2.5~6 秒触发一次，闭眼 120ms
            _blinkTimer = new DispatcherTimer();
            _blinkTimer.Tick += OnBlinkTick;
            ResetBlinkInterval();
            _blinkTimer.Start();
            _blinkOpenTimer = new DispatcherTimer
            {
                Interval = TimeSpan.FromMilliseconds(BlinkHoldMs)
            };
            _blinkOpenTimer.Tick += (_, _) =>
            {
                _blinkOpenTimer.Stop();
                _isBlinking = false;
                // 说话中恢复到当前嘴型帧，否则回到睁眼张嘴
                var src = _talkMouthClosed ? (_headMc ?? _headMain) : _headMain;
                if (src != null) HeadImg.Source = src;
            };

            // 说话嘴型交替（气泡显示期间）
            _talkTimer = new DispatcherTimer
            {
                Interval = TimeSpan.FromMilliseconds(TalkFrameMs)
            };
            _talkTimer.Tick += (_, _) =>
            {
                if (_isBlinking || _isStrolling || _isExiting) return;
                if (_headMain == null || _headMc == null) return;
                // 不规则断续嘴型：间隔随机 120~440ms、65% 张嘴，模拟说话音节而不是匀速打拍子
                _talkTimer.Interval = TimeSpan.FromMilliseconds(120 + _rnd.NextDouble() * 320);
                _talkMouthClosed = _rnd.NextDouble() < 0.35;
                HeadImg.Source = _talkMouthClosed ? _headMc : _headMain;
            };

            // 加载立绘帧图与气泡文案配置
            LoadSpriteFrames();
            LoadBubbleConfig();

            // 启动呼吸动画 + 常驻轻微摇头
            StartBreathAnimation();
            StartHeadSway();

            // 事件绑定
            PetCanvas.MouseLeftButtonDown += PetImage_MouseLeftButtonDown;
            PetCanvas.MouseRightButtonDown += PetImage_MouseRightButtonDown;
            PetCanvas.MouseMove += PetImage_MouseMove;
            PetCanvas.MouseLeftButtonUp += PetImage_MouseLeftButtonUp;

            StrollMenuItem.Click += (_, _) => ToggleStroll();
            SizeSmallItem.Click += (_, _) => SetSize(120);
            SizeMediumItem.Click += (_, _) => SetSize(200);
            SizeLargeItem.Click += (_, _) => SetSize(280);
            SizeHugeItem.Click += (_, _) => SetSize(400);
            CloseMenuItem.Click += (_, _) => HidePet();

            // 尺寸滑条：x:Name 字段直接可用
            // （滑条位于子菜单 Popup 的独立可视化树中，VisualTreeHelper 查找不到，必须用字段引用）
            SizeSlider.ValueChanged += OnSizeSliderValueChanged;

            // 右键菜单打开时同步滑条位置
            PetContextMenu.Opened += OnContextMenuOpened;

            // 默认隐藏（由 App.xaml.cs / PetController 决定是否显示）
            Visibility = Visibility.Hidden;
            _isPetVisible = false;
        }

        // ───── 设置持久化 ─────
        private void LoadSettings()
        {
            try
            {
                if (File.Exists(SettingsFilePath))
                {
                    var json = File.ReadAllText(SettingsFilePath);
                    var opts = JsonSerializer.Deserialize<JsonElement>(json);
                    if (opts.TryGetProperty("x", out var x) && x.TryGetDouble(out var dx)) Left = dx;
                    if (opts.TryGetProperty("y", out var y) && y.TryGetDouble(out var dy)) Top = dy;
                    if (opts.TryGetProperty("size", out var s) && s.TryGetDouble(out var ds))
                        _petSize = Math.Clamp(ds, MinSize, MaxSize);
                    if (opts.TryGetProperty("stroll", out var st) && st.ValueKind == JsonValueKind.False)
                        _isStrolling = false;
                    else if (opts.TryGetProperty("stroll", out var st2) && st2.ValueKind == JsonValueKind.True)
                        _isStrolling = true;
                }
            }
            catch { /* 忽略损坏数据 */ }
        }

        private void SaveSettings()
        {
            try
            {
                var data = new
                {
                    enabled = _isPetVisible,
                    x = Left,
                    y = Top,
                    size = _petSize,
                    stroll = _isStrolling
                };
                var json = JsonSerializer.Serialize(data, new JsonSerializerOptions { WriteIndented = true });
                File.WriteAllText(SettingsFilePath, json);
            }
            catch { /* 持久化失败不阻塞 UI */ }
        }

        // ───── 显隐控制 ─────
        public void ShowPet()
        {
            if (_isPetVisible) return;
            // 若退场动画还在播放，立即清理并复位
            if (_isExiting)
            {
                BeginAnimation(OpacityProperty, null);
                PetScale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
                PetScale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
                PetRotate.BeginAnimation(RotateTransform.AngleProperty, null);
                Opacity = 1;
                PetScale.ScaleX = 1;
                PetScale.ScaleY = 1;
                PetRotate.Angle = 0;
                _isExiting = false;
            }
            // 位置校验：若保存的位置中心已不在虚拟屏幕内（如副屏拔出/分辨率变更），回到默认位置
            var vsl = SystemParameters.VirtualScreenLeft;
            var vst = SystemParameters.VirtualScreenTop;
            var vsr = vsl + SystemParameters.VirtualScreenWidth;
            var vsb = vst + SystemParameters.VirtualScreenHeight;
            var cx = Left + Width / 2;
            var cy = Top + Height / 2;
            if (cx < vsl || cx > vsr || cy < vst || cy > vsb)
            {
                Left = 80;
                Top = 80;
            }
            Show();
            Visibility = Visibility.Visible;
            _isPetVisible = true;
            StrollMenuItem.IsChecked = _isStrolling;
            if (_isStrolling) StartStroll();
            _lastInteract = DateTime.Now;
            _idleTimer.Start();
            SaveSettings();

            // ── 入场动画：淡入 + 从 20% 弹性放大 + 从上方落下轻弹 ──
            StopBreathAnimation();
            Opacity = 0;
            PetScale.ScaleX = 0.2;
            PetScale.ScaleY = 0.2;
            PetTranslate.Y = -26;

            var fadeIn = new DoubleAnimation(1, TimeSpan.FromMilliseconds(320))
            {
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseOut }
            };
            fadeIn.Completed += (_, _) =>
            {
                BeginAnimation(OpacityProperty, null);
                Opacity = 1;
            };
            BeginAnimation(OpacityProperty, fadeIn);

            var pop = new DoubleAnimation(1, TimeSpan.FromMilliseconds(520))
            {
                EasingFunction = new BackEase { EasingMode = EasingMode.EaseOut, Amplitude = 0.6 }
            };
            pop.Completed += (_, _) =>
            {
                PetScale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
                PetScale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
                PetScale.ScaleX = 1;
                PetScale.ScaleY = 1;
                StartBreathAnimation();
            };
            PetScale.BeginAnimation(ScaleTransform.ScaleXProperty, pop);
            PetScale.BeginAnimation(ScaleTransform.ScaleYProperty, pop);

            var drop = new DoubleAnimation(0, TimeSpan.FromMilliseconds(520))
            {
                EasingFunction = new BounceEase { EasingMode = EasingMode.EaseOut, Bounces = 1, Bounciness = 2.2 }
            };
            drop.Completed += (_, _) =>
            {
                PetTranslate.BeginAnimation(TranslateTransform.YProperty, null);
                PetTranslate.Y = 0;
            };
            PetTranslate.BeginAnimation(TranslateTransform.YProperty, drop);

            // 闪亮登场问候（动画落幕后弹出）
            var greetTimer = new DispatcherTimer { Interval = TimeSpan.FromMilliseconds(650) };
            greetTimer.Tick += (_, _) =>
            {
                greetTimer.Stop();
                if (_isPetVisible && !_isExiting)
                    ShowBubble(_greeting);
            };
            greetTimer.Start();
        }

        public void HidePet()
        {
            if (!_isPetVisible || _isExiting) return;
            _isPetVisible = false;   // 状态立即翻转，避免开关抖动
            _isExiting = true;
            _blinkTimer.Stop();
            _blinkOpenTimer.Stop();
            _talkTimer.Stop();
            _isBlinking = false;
            _talkMouthClosed = false;
            StopStroll();
            StopPoseAnimation();
            HideBubble();
            SaveSettings();

            // ── 退场动画：回收缩小 + 轻微倾斜 + 淡出，结束后真正隐藏 ──
            StopBreathAnimation();
            var shrink = new DoubleAnimation(0.1, TimeSpan.FromMilliseconds(380))
            {
                EasingFunction = new BackEase { EasingMode = EasingMode.EaseIn, Amplitude = 0.5 }
            };
            shrink.Completed += (_, _) =>
            {
                Visibility = Visibility.Hidden;
                // 复位，供下次入场使用
                BeginAnimation(OpacityProperty, null);
                Opacity = 1;
                PetScale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
                PetScale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
                PetScale.ScaleX = 1;
                PetScale.ScaleY = 1;
                PetRotate.BeginAnimation(RotateTransform.AngleProperty, null);
                PetRotate.Angle = 0;
                HeadImg.Source = _headMain;
                StartBreathAnimation();
                _isExiting = false;
            };
            var fadeOut = new DoubleAnimation(0, TimeSpan.FromMilliseconds(330))
            {
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseIn }
            };
            var tilt = new DoubleAnimation(-10, TimeSpan.FromMilliseconds(380))
            {
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseIn }
            };
            PetScale.BeginAnimation(ScaleTransform.ScaleXProperty, shrink);
            PetScale.BeginAnimation(ScaleTransform.ScaleYProperty, shrink);
            PetRotate.BeginAnimation(RotateTransform.AngleProperty, tilt);
            BeginAnimation(OpacityProperty, fadeOut);
        }

        // ───── 尺寸 ─────
        private void ApplySize()
        {
            // 立绘盒按 941:1672 等比缩放，size 即桌宠显示高度(px)
            var boxH = _petSize;
            var boxW = _petSize * SpriteAspect;
            PetCanvas.Width = boxW;
            PetCanvas.Height = boxH;
            Width = Math.Max(boxW + 60, 190);
            Height = boxH + 70;
            UpdateSizeUI();
        }

        private void SetSize(double newSize, bool save = true, bool showBubble = true)
        {
            _isUpdatingSize = true;
            try
            {
                _petSize = Math.Clamp(newSize, MinSize, MaxSize);
                ApplySize();
                // 保持在屏幕内
                var wa = SystemParameters.WorkArea;
                if (Left + Width > wa.Width) Left = Math.Max(0, wa.Width - Width);
                if (Top + Height > wa.Height) Top = Math.Max(0, wa.Height - Height);
                if (save) SaveSettings();
                if (showBubble) ShowBubble(string.Format(_sizeFormat, _petSize.ToString("F0")));
            }
            finally
            {
                _isUpdatingSize = false;
            }
        }

        private void UpdateSizeUI()
        {
            // 高亮最接近的预设
            SizeSmallItem.FontWeight = Math.Abs(_petSize - 120) < 5 ? FontWeights.Bold : FontWeights.Medium;
            SizeMediumItem.FontWeight = Math.Abs(_petSize - 200) < 5 ? FontWeights.Bold : FontWeights.Medium;
            SizeLargeItem.FontWeight = Math.Abs(_petSize - 280) < 5 ? FontWeights.Bold : FontWeights.Medium;
            SizeHugeItem.FontWeight = Math.Abs(_petSize - 400) < 5 ? FontWeights.Bold : FontWeights.Medium;

            if (!_isUpdatingSize)
            {
                SizeSlider.Value = _petSize;
            }
        }

        private void OnContextMenuOpened(object? sender, RoutedEventArgs e)
        {
            UpdateSizeUI();
        }

        private void OnSizeSliderValueChanged(object? sender, RoutedPropertyChangedEventArgs<double> e)
        {
            if (_isUpdatingSize) return;
            // 同步滑条位置时值未变化，避免冗余 SetSize / 保存
            if (Math.Abs(e.NewValue - _petSize) < 0.01) return;
            SetSize(e.NewValue, save: true, showBubble: false);
        }

        // ───── 鼠标事件 ─────
        private void PetImage_MouseLeftButtonDown(object sender, MouseButtonEventArgs e)
        {
            if (e.ChangedButton != MouseButton.Left) return;
            _isDragging = true;
            MarkInteract();
            // 记录拖拽起点（用于区分点击与拖拽）
            _dragStartOrigin = new Point(Left, Top);
            try { DragMove(); } catch { /* DragMove 可能抛 InvalidOperationException */ }
            _isDragging = false;
            // DragMove 返回后检查是否为点击
            var dx = Math.Abs(Left - _dragStartOrigin.X);
            var dy = Math.Abs(Top - _dragStartOrigin.Y);
            if (dx < 5 && dy < 5)
            {
                ShowRandomBubble();
            }
            SaveSettings();
        }

        private void PetImage_MouseRightButtonDown(object sender, MouseButtonEventArgs e)
        {
            // 让 Window 的 ContextMenu 自动打开
            MarkInteract();
            // 不设 e.Handled = true，让 ContextMenu 默认行为生效
        }

        private void PetImage_MouseMove(object sender, MouseEventArgs e)
        {
            if (_isDragging) MarkInteract();
        }

        private void PetImage_MouseLeftButtonUp(object sender, MouseButtonEventArgs e)
        {
            MarkInteract();
        }

        private void MarkInteract()
        {
            _lastInteract = DateTime.Now;
            // 退出闲置姿态
            if (_currentPose != null)
            {
                StopPoseAnimation();
            }
        }

        // ───── 散步模式 ─────
        private void ToggleStroll()
        {
            if (_isStrolling) StopStroll();
            else StartStroll();
        }

        private void StartStroll()
        {
            _isStrolling = true;
            StrollMenuItem.IsChecked = true;
            // 清掉待机漂移动画对 Left/Top 的持有，否则散步时直接赋值不生效（不移动的根因）
            BeginAnimation(LeftProperty, null);
            BeginAnimation(TopProperty, null);
            // 移到屏幕顶部
            Top = StrollTopOffset;
            _walkPhase = 0;
            _strollLastTick = DateTime.Now;
            _strollTimer.Start();
            StopPoseAnimation();
            // 切换到侧身行走图（按当前移动方向选左/右步态帧）
            SetFrontLayersVisible(false);
            _walkFrameA = false;
            SideImg.Source = SideFrame(_strollDir, _walkFrameA);
            SideImg.Visibility = Visibility.Visible;
            SaveSettings();
            ShowBubble(_strollStartText);
        }

        // 按方向与步态相位选侧身帧图
        private ImageSource? SideFrame(int dir, bool frameA)
        {
            if (dir > 0) return frameA ? _sideRa : _sideRb;
            return frameA ? _sideLa : _sideLb;
        }

        private void StopStroll()
        {
            if (!_isStrolling) return;
            _isStrolling = false;
            StrollMenuItem.IsChecked = false;
            _strollTimer.Stop();
            // 侧身图收起，恢复正面立绘
            SideImg.Visibility = Visibility.Collapsed;
            SideRotate.Angle = 0;
            SetFrontLayersVisible(true);
            HeadImg.Source = _headMain;
            PetTranslate.Y = 0;
            PetTurnScale.ScaleX = 1;
            SaveSettings();
        }

        // 正面立绘整体显隐（散步时切侧身图用）
        private void SetFrontLayersVisible(bool visible)
        {
            var v = visible ? Visibility.Visible : Visibility.Collapsed;
            BaseImg.Visibility = v;
            HeadImg.Visibility = v;
        }

        private void OnStrollTick(object? sender, EventArgs e)
        {
            if (!_isStrolling) return;
            var now = DateTime.Now;
            var dt = (now - _strollLastTick).TotalSeconds;
            _strollLastTick = now;
            if (dt <= 0 || dt > 1) return;

            var wa = SystemParameters.WorkArea;
            var step = StrollSpeedPxPerSec * dt * _strollDir;
            var newLeft = Left + step;

            // 走到屏幕左/右边缘后掉头，并换成对应朝向的侧身帧
            if (newLeft + ActualWidth >= wa.Width)
            {
                newLeft = wa.Width - ActualWidth;
                _strollDir = -1;
                SideImg.Source = SideFrame(_strollDir, _walkFrameA); // 朝左走
            }
            else if (newLeft <= 0)
            {
                newLeft = 0;
                _strollDir = 1;
                SideImg.Source = SideFrame(_strollDir, _walkFrameA); // 朝右走
            }
            Left = newLeft;

            // 行走循环：A/B 步态帧交替 + 轻微摇晃起伏
            _walkPhase += dt * 7.0;
            var s = Math.Sin(_walkPhase);
            var fa = s > 0;
            if (fa != _walkFrameA)
            {
                _walkFrameA = fa;
                SideImg.Source = SideFrame(_strollDir, _walkFrameA);
            }
            SideRotate.Angle = s * 1.5;
            PetTranslate.Y = -Math.Abs(s) * 4;
        }

        // ───── 聊天气泡 ─────
        private void ShowRandomBubble()
        {
            ShowBubble(_messages[_rnd.Next(_messages.Length)]);
        }

        private void ShowBubble(string text, bool speak = true)
        {
            Dispatcher.Invoke(() =>
            {
                _isDropHintShowing = false;
                _isAiReplyShowing = false;
                _bubbleHideTimer.Interval = TimeSpan.FromMilliseconds(BubbleDurationMs);
                BubbleText.Text = text;
                BubbleBorder.Visibility = Visibility.Visible;

                // 渐入 + 弹性放大
                var fadeIn = new DoubleAnimation(0, 1, TimeSpan.FromMilliseconds(220))
                {
                    EasingFunction = new SineEase { EasingMode = EasingMode.EaseOut }
                };

                // 设置气泡的 RenderTransform 用于缩放
                ScaleTransform rt;
                if (BubbleBorder.RenderTransform is ScaleTransform st)
                {
                    rt = st;
                }
                else
                {
                    rt = new ScaleTransform(0.6, 0.6);
                    BubbleBorder.RenderTransform = rt;
                    BubbleBorder.RenderTransformOrigin = new Point(0.5, 1);
                }
                rt.ScaleX = 0.6;
                rt.ScaleY = 0.6;

                var scaleUp = new DoubleAnimation(0.6, 1, TimeSpan.FromMilliseconds(280))
                {
                    EasingFunction = new ElasticEase
                    {
                        Oscillations = 1,
                        Springiness = 6,
                        EasingMode = EasingMode.EaseOut
                    }
                };

                BubbleBorder.BeginAnimation(OpacityProperty, fadeIn);
                rt.BeginAnimation(ScaleTransform.ScaleXProperty, scaleUp);
                rt.BeginAnimation(ScaleTransform.ScaleYProperty, scaleUp);

                _bubbleHideTimer.Stop();
                _bubbleHideTimer.Start();
                MarkInteract();

                // 说话嘴型动画 + MiniMax 语音播报
                StartTalkAnimation();
                if (speak) SpeakBubble(text);
            });
        }

        private void HideBubble()
        {
            Dispatcher.Invoke(() =>
            {
                _isAiReplyShowing = false;
                _isDropHintShowing = false;
                StopTalkAnimation();
                var fadeOut = new DoubleAnimation(1, 0, TimeSpan.FromMilliseconds(220));
                fadeOut.Completed += (_, _) => BubbleBorder.Visibility = Visibility.Collapsed;
                BubbleBorder.BeginAnimation(OpacityProperty, fadeOut);
            });
        }

        // ───── 文件拖入删除（丢进回收站，可恢复）─────
        private static bool HasDroppedFiles(DragEventArgs e)
            => e.Data.GetDataPresent(DataFormats.FileDrop);

        private void OnFileDragEnter(object sender, DragEventArgs e)
        {
            if (HasDroppedFiles(e))
            {
                e.Effects = DragDropEffects.Move;
                ShowBubble(_fileDropHint, speak: false);
                _isDropHintShowing = true;
            }
            else
            {
                e.Effects = DragDropEffects.None;
            }
            e.Handled = true;
        }

        private void OnFileDragOver(object sender, DragEventArgs e)
        {
            e.Effects = HasDroppedFiles(e) ? DragDropEffects.Move : DragDropEffects.None;
            // 悬停期间保持提示气泡不自动消失
            if (_isDropHintShowing) { _bubbleHideTimer.Stop(); _bubbleHideTimer.Start(); }
            e.Handled = true;
        }

        private void OnFileDragLeave(object sender, DragEventArgs e)
        {
            if (_isDropHintShowing)
            {
                _isDropHintShowing = false;
                HideBubble();
            }
            e.Handled = true;
        }

        private async void OnFileDrop(object sender, DragEventArgs e)
        {
            _isDropHintShowing = false;
            if (!HasDroppedFiles(e)) return;
            e.Handled = true;

            var paths = e.Data.GetData(DataFormats.FileDrop) as string[];
            if (paths == null || paths.Length == 0) return;

            // 删除可能耗时（大目录），放到线程池避免卡住桌宠动画
            var (ok, fail) = await Task.Run(() =>
            {
                int okCount = 0, failCount = 0;
                foreach (var p in paths)
                {
                    if (SendToRecycleBin(p)) okCount++;
                    else failCount++;
                }
                return (okCount, failCount);
            });

            if (ok > 0)
            {
                var tpl = _fileDeleteMessages[_rnd.Next(_fileDeleteMessages.Length)];
                ShowBubble(string.Format(tpl, ok));
            }
            else
            {
                ShowBubble(string.Format(_fileDeleteFail, fail));
            }
        }

        /// <summary>把文件/文件夹丢进回收站（不彻底粉碎，误拖可还原）。</summary>
        private static bool SendToRecycleBin(string path)
        {
            try
            {
                if (File.Exists(path))
                {
                    Microsoft.VisualBasic.FileIO.FileSystem.DeleteFile(
                        path,
                        Microsoft.VisualBasic.FileIO.UIOption.OnlyErrorDialogs,
                        Microsoft.VisualBasic.FileIO.RecycleOption.SendToRecycleBin);
                    return true;
                }
                if (Directory.Exists(path))
                {
                    Microsoft.VisualBasic.FileIO.FileSystem.DeleteDirectory(
                        path,
                        Microsoft.VisualBasic.FileIO.UIOption.OnlyErrorDialogs,
                        Microsoft.VisualBasic.FileIO.RecycleOption.SendToRecycleBin);
                    return true;
                }
                return false;
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[Pet] 回收站删除失败 {path}: {ex.Message}");
                return false;
            }
        }

        // ───── AI 聊天回答同步气泡（CefSharp 桥 petChat 调用）─────
        /// <summary>流式更新：只刷新气泡文字，不语音播报。</summary>
        public void ShowAiReply(string rawText) => ShowAiReplyCore(rawText, speak: false);

        /// <summary>回答完成：刷新气泡文字；speak 为 true 时 MiniMax 朗读摘要（仍受 tts.enabled 总开关约束）。</summary>
        public void FinishAiReply(string rawText, bool speak) => ShowAiReplyCore(rawText, speak);

        private void ShowAiReplyCore(string rawText, bool speak)
        {
            Dispatcher.Invoke(() =>
            {
                if (!_isPetVisible || _isExiting) return;

                var text = BuildSpeechSummary(rawText);
                if (text.Length == 0) text = _aiCodeOnlyText;

                if (_isAiReplyShowing && BubbleBorder.Visibility == Visibility.Visible)
                {
                    // 流式更新：只换文字 + 续命隐藏计时，不重放弹出动画
                    BubbleText.Text = text;
                    _bubbleHideTimer.Stop();
                    _bubbleHideTimer.Start();
                    if (speak) SpeakBubble(text);
                }
                else
                {
                    ShowBubble(text, speak);
                    _isAiReplyShowing = true;
                    // AI 回答气泡停留更久（ShowBubble 里已把时长重置为默认值）
                    _bubbleHideTimer.Stop();
                    _bubbleHideTimer.Interval = TimeSpan.FromMilliseconds(AiReplyHideMs);
                    _bubbleHideTimer.Start();
                }
            });
        }

        // ── AI 输出 → 可朗读的自然语言摘要 ──
        // 全部是通用规则，不针对具体回答硬编码：
        //   A) 去代码块/命令/链接/markdown（保留行结构）
        //   B) 按行丢弃：工具状态行（「读取文件：」及其输出块）、表格行、日期列表行、
        //      枚举行、emoji 标题行、Agent 状态行、纯英文行
        //   C) 去 emoji / 口水前缀（「好的，」）
        //   D) 按句子边界截取到 AiSummaryMaxChars
        private static readonly Regex RxFencePair = new Regex(@"```[\s\S]*?```", RegexOptions.Compiled);
        private static readonly Regex RxFenceTail = new Regex(@"```[\s\S]*$", RegexOptions.Compiled);
        private static readonly Regex RxInlineCode = new Regex(@"`[^`\n]*`", RegexOptions.Compiled);
        private static readonly Regex RxImage = new Regex(@"!\[[^\]]*\]\([^)]*\)", RegexOptions.Compiled);
        private static readonly Regex RxLink = new Regex(@"\[([^\]]*)\]\([^)]*\)", RegexOptions.Compiled);
        private static readonly Regex RxUrl = new Regex(@"https?://\S+", RegexOptions.Compiled);
        private static readonly Regex RxHtmlTag = new Regex(@"<[^>\n]+>", RegexOptions.Compiled);
        private static readonly Regex RxMdHeading = new Regex(@"^[ \t]{0,3}#{1,6}[ \t]*", RegexOptions.Compiled | RegexOptions.Multiline);
        private static readonly Regex RxMdBullet = new Regex(@"^[ \t]*[-*+>][ \t]+", RegexOptions.Compiled | RegexOptions.Multiline);
        private static readonly Regex RxMdOrdered = new Regex(@"^[ \t]*\d+[.)、][ \t]+", RegexOptions.Compiled | RegexOptions.Multiline);
        private static readonly Regex RxMdHr = new Regex(@"^[ \t]*[-*_]{3,}[ \t]*$", RegexOptions.Compiled | RegexOptions.Multiline);
        private static readonly Regex RxDateRow = new Regex(@"\d{1,2}[-/月]\d{1,2}|\d{1,2}:\d{2}", RegexOptions.Compiled);
        private static readonly Regex RxHasCjk = new Regex(@"[一-鿿]", RegexOptions.Compiled);
        private static readonly Regex RxSentenceEnd = new Regex(@"[。！？!?]", RegexOptions.Compiled);
        private static readonly Regex RxEnumeration = new Regex(@"、", RegexOptions.Compiled);
        private static readonly Regex RxEmoji = new Regex("(?:[\\uD83C-\\uDBFF][\\uDC00-\\uDFFF]|[\\u2600-\\u27BF\\u2B00-\\u2BFF]\\uFE0F?|[\\uFE00-\\uFE0F])", RegexOptions.Compiled);
        private static readonly Regex RxLeadFiller = new Regex(@"^(好的|好哒|好嘞|嗯|呃|哦)[，,！!～~\s]*", RegexOptions.Compiled);
        private static readonly Regex RxSpaces = new Regex(@"[ \t　]+", RegexOptions.Compiled);
        private static readonly Regex RxTrailClause = new Regex(@"[，,][^，,。！？!?]{0,20}[：:]\s*$", RegexOptions.Compiled);
        private static readonly Regex RxSpaceNearPunct = new Regex(@"\s*([。！？!?～~])\s*", RegexOptions.Compiled);
        private static readonly Regex RxSentenceSplit = new Regex(@"(?<=[。！？!?])", RegexOptions.Compiled);

        /// <summary>把 AI 原始回答提炼成可朗读的自然语言摘要（≤ AiSummaryMaxChars，句子边界）。</summary>
        private static string BuildSpeechSummary(string raw)
        {
            if (string.IsNullOrWhiteSpace(raw)) return string.Empty;
            var t = raw;
            // 阶段 A：去代码/命令/链接/markdown
            t = RxFencePair.Replace(t, " ");
            t = RxFenceTail.Replace(t, " ");        // 流式中未闭合的代码块
            t = RxInlineCode.Replace(t, " ");
            t = RxImage.Replace(t, " ");
            t = RxLink.Replace(t, "$1");
            t = RxUrl.Replace(t, " ");
            t = RxHtmlTag.Replace(t, " ");
            t = RxMdHeading.Replace(t, "");
            t = RxMdBullet.Replace(t, "");
            t = RxMdOrdered.Replace(t, "");
            t = RxMdHr.Replace(t, " ");

            // 阶段 B：按行过滤
            var kept = new List<string>();
            var lines = t.Replace("\r", "").Split('\n');
            bool skipBlock = false;   // 「读取文件：」这类工具标签行之后的输出块，到空行为止
            foreach (var rawLine in lines)
            {
                var line = RxSpaces.Replace(rawLine.Replace("|", " "), " ").Trim();
                if (line.Length == 0) { skipBlock = false; continue; }
                if (skipBlock) { skipBlock = false; continue; }   // 工具输出只跳过紧随的一行，其余交给行规则

                // 标签行：「…，整理如下：」截到最后一个逗号前保留主干；
                // 无逗号的纯标签（「读取文件：」）连同后续工具输出块一起丢弃
                if (line.EndsWith("：") || line.EndsWith(":"))
                {
                    var cut = RxTrailClause.Replace(line, "");
                    if (cut.Length > 0 && cut != line) { line = cut.Trim(); }
                    else { skipBlock = true; continue; }
                }

                if (line.Contains("Agent") && !RxSentenceEnd.IsMatch(line)) continue;      // Agent 状态行
                if (RxDateRow.IsMatch(line) && !RxSentenceEnd.IsMatch(line)) continue;      // 「07-16 19:27」列表行
                if (RxEnumeration.IsMatch(line) && !RxSentenceEnd.IsMatch(line)) continue;  // 「A、B、C」枚举行
                if (!RxHasCjk.IsMatch(line)) continue;                                      // 纯英文/符号行
                if (!RxSentenceEnd.IsMatch(line) && line.Length <= 15) continue;            // emoji 标题/短标签行

                if (!RxSentenceEnd.IsMatch(line)) line += "。";   // 保留行缺句号结尾则补上（标签行截断后的主干）
                kept.Add(line);
            }

            var flat = kept.Count > 0
                ? string.Join(" ", kept)
                : RxSpaces.Replace(t.Replace("\r", " ").Replace("\n", " "), " ").Trim();  // 全被过滤时兜底用整段

            // 阶段 C：emoji / 口水前缀 / 空白
            flat = RxEmoji.Replace(flat, "");
            flat = RxLeadFiller.Replace(flat, "");
            flat = RxSpaces.Replace(flat, " ").Trim();
            flat = RxSpaceNearPunct.Replace(flat, "$1");   // CJK 标点旁的拼接空格
            if (flat.Length == 0) return string.Empty;

            // 阶段 D：按句截取（至少保留第一句）
            var sentences = RxSentenceSplit.Split(flat);
            var sb = new System.Text.StringBuilder();
            foreach (var s in sentences)
            {
                var sentence = s.Trim();
                if (sentence.Length == 0) continue;
                if (sb.Length > 0 && sb.Length + sentence.Length > AiSummaryMaxChars) break;
                sb.Append(sentence);   // CJK 句子间无需空格
                if (sb.Length >= AiSummaryMaxChars) break;
            }
            var summary = sb.ToString();
            if (summary.Length == 0)
            {
                summary = flat.Length <= AiSummaryMaxChars ? flat : flat.Substring(0, AiSummaryMaxChars).TrimEnd() + "…";
            }
            else if (summary.Length < flat.Length && !RxSentenceEnd.IsMatch(summary.Substring(summary.Length - 1)))
            {
                summary += "…";
            }
            return summary;
        }

        // ───── 立绘帧图与文案加载 ─────
        private void LoadSpriteFrames()
        {
            BitmapImage Load(string name)
            {
                var bi = new BitmapImage();
                bi.BeginInit();
                bi.UriSource = new Uri($"pack://application:,,,/Assets/{name}", UriKind.Absolute);
                bi.CacheOption = BitmapCacheOption.OnLoad;
                bi.EndInit();
                bi.Freeze();
                return bi;
            }
            try
            {
                _headMain = Load("pet_head.png");
                _headEc = Load("pet_head_ec.png");
                _headMc = Load("pet_head_mc.png");
                _sideLa = Load("pet_side_l_a.png");
                _sideLb = Load("pet_side_l_b.png");
                _sideRa = Load("pet_side_r_a.png");
                _sideRb = Load("pet_side_r_b.png");
                HeadImg.Source = _headMain;
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[Pet] 立绘帧加载失败: {ex.Message}");
            }
        }

        private void LoadBubbleConfig()
        {
            try
            {
                var path = Path.Combine(AppContext.BaseDirectory, "Assets", "pet_bubbles.json");
                if (!File.Exists(path)) return;
                using var doc = JsonDocument.Parse(File.ReadAllText(path));
                var root = doc.RootElement;

                if (root.TryGetProperty("greeting", out var g) && !string.IsNullOrWhiteSpace(g.GetString()))
                    _greeting = g.GetString()!;
                if (root.TryGetProperty("strollStart", out var ss) && !string.IsNullOrWhiteSpace(ss.GetString()))
                    _strollStartText = ss.GetString()!;
                if (root.TryGetProperty("sizeFormat", out var sf) && !string.IsNullOrWhiteSpace(sf.GetString()))
                    _sizeFormat = sf.GetString()!;
                if (root.TryGetProperty("messages", out var msgs) && msgs.ValueKind == JsonValueKind.Array)
                {
                    var list = new List<string>();
                    foreach (var m in msgs.EnumerateArray())
                    {
                        var s = m.GetString();
                        if (!string.IsNullOrWhiteSpace(s)) list.Add(s!);
                    }
                    if (list.Count > 0) _messages = list.ToArray();
                }

                if (root.TryGetProperty("fileDropHint", out var dh) && !string.IsNullOrWhiteSpace(dh.GetString()))
                    _fileDropHint = dh.GetString()!;
                if (root.TryGetProperty("fileDeleteFail", out var df) && !string.IsNullOrWhiteSpace(df.GetString()))
                    _fileDeleteFail = df.GetString()!;
                if (root.TryGetProperty("fileDelete", out var fd) && fd.ValueKind == JsonValueKind.Array)
                {
                    var fdList = new List<string>();
                    foreach (var m in fd.EnumerateArray())
                    {
                        var s = m.GetString();
                        if (!string.IsNullOrWhiteSpace(s)) fdList.Add(s!);
                    }
                    if (fdList.Count > 0) _fileDeleteMessages = fdList.ToArray();
                }

                if (root.TryGetProperty("aiCodeOnly", out var ac) && !string.IsNullOrWhiteSpace(ac.GetString()))
                    _aiCodeOnlyText = ac.GetString()!;

                if (root.TryGetProperty("tts", out var tts))
                {
                    if (tts.TryGetProperty("enabled", out var en)) _ttsEnabled = en.GetBoolean();
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[Pet] pet_bubbles.json 读取失败: {ex.Message}");
            }
        }

        // ───── 眨眼（帧切换）─────
        private void ResetBlinkInterval()
        {
            _blinkTimer.Interval = TimeSpan.FromSeconds(2.5 + _rnd.NextDouble() * 3.5);
        }

        private void OnBlinkTick(object? sender, EventArgs e)
        {
            ResetBlinkInterval();
            if (!_isPetVisible || _isStrolling || _isDragging || _isExiting) return;
            if (_headEc == null) return;
            _isBlinking = true;
            HeadImg.Source = _headEc;
            _blinkOpenTimer.Stop();
            _blinkOpenTimer.Start();
        }

        // ───── 说话嘴型（气泡显示期间张嘴/闭嘴交替）─────
        private void StartTalkAnimation()
        {
            if (_isStrolling || _isExiting) return;
            if (_headMain == null || _headMc == null) return;
            _talkMouthClosed = false;
            if (!_isBlinking) HeadImg.Source = _headMain;
            _talkTimer.Start();
        }

        private void StopTalkAnimation()
        {
            _talkTimer.Stop();
            _talkMouthClosed = false;
            if (!_isBlinking && _headMain != null) HeadImg.Source = _headMain;
        }

        // ───── MiniMax 语音播报（后端不可达时静默跳过）─────
        private async void SpeakBubble(string text)
        {
            if (!_ttsEnabled) return;
            try
            {
                var bytes = await MiniMaxTtsClient.SynthesizeAsync(text, _ttsEndpoint);
                if (bytes == null || bytes.Length == 0) return;

                // 存临时 mp3 并播放（当前仍在 UI 上下文）
                var file = Path.Combine(Path.GetTempPath(), $"moonya_tts_{Guid.NewGuid():N}.mp3");
                await File.WriteAllBytesAsync(file, bytes);
                _ttsPlayer ??= new MediaPlayer();
                _ttsPlayer.Open(new Uri(file));
                _ttsPlayer.Play();

                // 只保留最近 3 个临时文件，旧的清掉
                _ttsTempFiles.Enqueue(file);
                while (_ttsTempFiles.Count > 3)
                {
                    var old = _ttsTempFiles.Dequeue();
                    try { if (File.Exists(old)) File.Delete(old); } catch { }
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[Pet] TTS 播放失败: {ex.Message}");
            }
        }

        // ───── 闲置检测 ─────
        private void OnIdleCheck(object? sender, EventArgs e)
        {
            if (!_isPetVisible) return;
            if (_isStrolling || _isDragging) return;

            var idleMs = (DateTime.Now - _lastInteract).TotalMilliseconds;
            if (idleMs >= IdleTimeoutMs && _currentPose == null)
            {
                StartIdleSequence();
            }
        }

        private void StartIdleSequence()
        {
            _poseTimer.Interval = TimeSpan.FromSeconds(4 + _rnd.NextDouble() * 3);
            _poseTimer.Start();
            OnPoseChange(null, EventArgs.Empty);
        }

        private void OnPoseChange(object? sender, EventArgs e)
        {
            if (!_isPetVisible || _isStrolling || _isDragging)
            {
                _poseTimer.Stop();
                return;
            }

            // 重置上一次的姿态
            StopPoseAnimation();

            var pose = IdlePoses[_rnd.Next(IdlePoses.Length)];
            _currentPose = pose;
            PlayPoseAnimation(pose);

            // 30% 概率随机位移
            if (_rnd.NextDouble() < 0.3)
            {
                IdleDrift();
            }

            // 25% 概率自言自语
            if (_rnd.NextDouble() < 0.25)
            {
                ShowRandomBubble();
            }

            // 重新调度下一次姿态切换
            _poseTimer.Interval = TimeSpan.FromSeconds(4 + _rnd.NextDouble() * 4);
        }

        private void IdleDrift()
        {
            var wa = SystemParameters.WorkArea;
            var dx = (_rnd.NextDouble() - 0.5) * 200;
            var dy = (_rnd.NextDouble() - 0.5) * 100;
            var newLeft = Math.Max(0, Math.Min(wa.Width - ActualWidth, Left + dx));
            var newTop = Math.Max(0, Math.Min(wa.Height - ActualHeight, Top + dy));

            var animX = new DoubleAnimation(Left, newLeft, TimeSpan.FromSeconds(1.5))
            {
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            };
            var animY = new DoubleAnimation(Top, newTop, TimeSpan.FromSeconds(1.5))
            {
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            };
            // ★ 动画默认 FillBehavior=HoldEnd，播放完后会一直“持有” Left/Top，
            //   导致此后所有直接赋值（散步移动、拖拽归位等）全部失效。
            //   完成后必须移除动画并把终值写回本地值。
            animX.Completed += (_, _) =>
            {
                BeginAnimation(LeftProperty, null);
                BeginAnimation(TopProperty, null);
                Left = newLeft;
                Top = newTop;
                SaveSettings();
            };
            BeginAnimation(LeftProperty, animX);
            BeginAnimation(TopProperty, animY);
        }

        // ───── 动画 ─────
        private void StartBreathAnimation()
        {
            // 持续呼吸：身体轻微缩放
            var breathScale = new DoubleAnimation(1, 1.012, TimeSpan.FromSeconds(1.8))
            {
                AutoReverse = true,
                RepeatBehavior = RepeatBehavior.Forever,
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            };
            PetScale.BeginAnimation(ScaleTransform.ScaleXProperty, breathScale);
            PetScale.BeginAnimation(ScaleTransform.ScaleYProperty, breathScale);
        }

        private void StopBreathAnimation()
        {
            PetScale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
            PetScale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
            PetScale.ScaleX = 1;
            PetScale.ScaleY = 1;
        }

        // 常驻轻微摇头（颈部支点，±2.2°）
        private void StartHeadSway()
        {
            var sway = new DoubleAnimation(-2.2, 2.2, TimeSpan.FromSeconds(2.8))
            {
                AutoReverse = true,
                RepeatBehavior = RepeatBehavior.Forever,
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            };
            HeadRotate.BeginAnimation(RotateTransform.AngleProperty, sway);
        }

        private void PlayPoseAnimation(string pose)
        {
            // 暂停呼吸避免冲突
            StopBreathAnimation();
            // 重置变换
            ResetTransforms();

            switch (pose)
            {
                case "bounce":
                {
                    // 上下弹跳
                    var bounce = new DoubleAnimationUsingKeyFrames
                    {
                        Duration = TimeSpan.FromSeconds(1.2),
                        RepeatBehavior = RepeatBehavior.Forever
                    };
                    bounce.KeyFrames.Add(new EasingDoubleKeyFrame(0, TimeSpan.FromSeconds(0)));
                    bounce.KeyFrames.Add(new EasingDoubleKeyFrame(-16, TimeSpan.FromSeconds(0.5))
                    {
                        EasingFunction = new SineEase { EasingMode = EasingMode.EaseOut }
                    });
                    bounce.KeyFrames.Add(new EasingDoubleKeyFrame(0, TimeSpan.FromSeconds(1.2))
                    {
                        EasingFunction = new SineEase { EasingMode = EasingMode.EaseIn }
                    });
                    PetTranslate.BeginAnimation(TranslateTransform.YProperty, bounce);
                    break;
                }
                case "sway":
                {
                    // 轻柔摇摆（±2.5°）
                    var sway = new DoubleAnimation(-2.5, 2.5, TimeSpan.FromSeconds(3.0))
                    {
                        AutoReverse = true,
                        RepeatBehavior = RepeatBehavior.Forever,
                        EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
                    };
                    PetRotate.BeginAnimation(RotateTransform.AngleProperty, sway);
                    break;
                }
                case "turn":
                {
                    // 转身一周（Y 轴翻转视觉效果，始终以脚为中心不出窗口）
                    var turn = new DoubleAnimationUsingKeyFrames
                    {
                        Duration = TimeSpan.FromSeconds(5.5),
                        RepeatBehavior = RepeatBehavior.Forever
                    };
                    turn.KeyFrames.Add(new EasingDoubleKeyFrame(1, TimeSpan.FromSeconds(0)));
                    turn.KeyFrames.Add(new EasingDoubleKeyFrame(0, TimeSpan.FromSeconds(0.55))
                    {
                        EasingFunction = new SineEase { EasingMode = EasingMode.EaseIn }
                    });
                    turn.KeyFrames.Add(new EasingDoubleKeyFrame(-1, TimeSpan.FromSeconds(1.1))
                    {
                        EasingFunction = new SineEase { EasingMode = EasingMode.EaseOut }
                    });
                    turn.KeyFrames.Add(new EasingDoubleKeyFrame(0, TimeSpan.FromSeconds(1.65))
                    {
                        EasingFunction = new SineEase { EasingMode = EasingMode.EaseIn }
                    });
                    turn.KeyFrames.Add(new EasingDoubleKeyFrame(1, TimeSpan.FromSeconds(2.2))
                    {
                        EasingFunction = new SineEase { EasingMode = EasingMode.EaseOut }
                    });
                    turn.KeyFrames.Add(new EasingDoubleKeyFrame(1, TimeSpan.FromSeconds(5.5)));
                    PetTurnScale.BeginAnimation(ScaleTransform.ScaleXProperty, turn);
                    break;
                }
                case "wiggle":
                {
                    // 轻柔扭动（±3° 倾斜）
                    var wiggle = new DoubleAnimation(-3, 3, TimeSpan.FromSeconds(1.6))
                    {
                        AutoReverse = true,
                        RepeatBehavior = RepeatBehavior.Forever,
                        EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
                    };
                    PetSkew.BeginAnimation(SkewTransform.AngleXProperty, wiggle);
                    break;
                }
            }
        }

        private void ResetTransforms()
        {
            PetTranslate.X = 0; PetTranslate.Y = 0;
            PetRotate.Angle = 0;
            PetSkew.AngleX = 0;
            PetTurnScale.ScaleX = 1;
        }

        private void StopPoseAnimation()
        {
            _poseTimer.Stop();
            // 清除姿态动画
            PetTranslate.BeginAnimation(TranslateTransform.YProperty, null);
            PetRotate.BeginAnimation(RotateTransform.AngleProperty, null);
            PetSkew.BeginAnimation(SkewTransform.AngleXProperty, null);
            PetTurnScale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
            ResetTransforms();
            _currentPose = null;
            // 恢复呼吸
            StartBreathAnimation();
        }

        protected override void OnClosed(EventArgs e)
        {
            try
            {
                _strollTimer.Stop();
                _idleTimer.Stop();
                _poseTimer.Stop();
                _bubbleHideTimer.Stop();
                _blinkTimer.Stop();
                _blinkOpenTimer.Stop();
                _talkTimer.Stop();
                if (_ttsPlayer != null)
                {
                    _ttsPlayer.Stop();
                    _ttsPlayer.Close();
                    _ttsPlayer = null;
                }
                while (_ttsTempFiles.Count > 0)
                {
                    var f = _ttsTempFiles.Dequeue();
                    try { if (File.Exists(f)) File.Delete(f); } catch { }
                }
            }
            catch { }
            base.OnClosed(e);
        }
    }
}
