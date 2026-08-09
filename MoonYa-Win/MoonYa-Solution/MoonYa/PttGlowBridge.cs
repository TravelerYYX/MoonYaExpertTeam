namespace MoonYa
{
    /// <summary>
    /// 供前端 JavaScript 调用的 Push-to-Talk 光效桥接对象。
    /// 通过 CefSharp JavascriptObjectRepository 注册为 moonYaPttGlow。
    /// </summary>
    public class PttGlowBridge
    {
        private readonly MainWindow _mainWindow;

        public PttGlowBridge(MainWindow mainWindow)
        {
            _mainWindow = mainWindow;
        }

        /// <summary>
        /// 前端上报实时音量，范围 [0.0, 1.0]。
        /// </summary>
        public void UpdateVolume(double level)
        {
            System.Diagnostics.Debug.WriteLine($"[PttGlowBridge] UpdateVolume called, level={level}");
            _mainWindow.UpdatePttGlowVolume(level);
        }

        /// <summary>
        /// 设置实时语音对话模式的光效状态。
        /// mode 取值：off / listening / capturing / recognizing / thinking / ai_speaking
        /// </summary>
        public void SetVoiceChatMode(string mode)
        {
            System.Diagnostics.Debug.WriteLine($"[PttGlowBridge] SetVoiceChatMode called, mode={mode}");
            _mainWindow.SetPttGlowVoiceChatMode(mode);
        }

        /// <summary>
        /// 设置 Computer Use 模式的光效状态。
        /// mode 取值：off / active
        /// </summary>
        public void SetCuMode(string mode)
        {
            System.Diagnostics.Debug.WriteLine($"[PttGlowBridge] SetCuMode called, mode={mode}");
            _mainWindow.SetPttGlowCuMode(mode);
        }

        /// <summary>
        /// 前端在 ASR 启动失败、识别异常等场景下主动调用，强制释放 C# 端 PTT 状态：
        /// 停止轮询计时器、隐藏屏幕光效，避免出现"长按时屏幕一直闪"或"光效卡住不消失"。
        /// </summary>
        public void CancelPtt()
        {
            System.Diagnostics.Debug.WriteLine("[PttGlowBridge] CancelPtt called");
            _mainWindow.CancelPttFromJs();
        }

        /// <summary>
        /// 前端显式进入 PTT 冷却期。ASR 启动失败时由前端调用，
        /// 让 C# hook 在 3 秒内吞掉 Space keydown，避免 OS 键盘自动重复
        /// 反复触发 startRecording 导致屏幕闪烁。
        /// </summary>
        public void EnterCooldown()
        {
            System.Diagnostics.Debug.WriteLine("[PttGlowBridge] EnterCooldown called");
            _mainWindow.EnterPttCooldown();
        }

        /// <summary>
        /// 前端显式清除 PTT 冷却期。ASR 启动成功（__onAsrReady）时由前端调用，
        /// 让 C# hook 恢复正常响应。
        /// </summary>
        public void ClearCooldown()
        {
            System.Diagnostics.Debug.WriteLine("[PttGlowBridge] ClearCooldown called");
            _mainWindow.ClearPttCooldownFromJs();
        }
    }
}
