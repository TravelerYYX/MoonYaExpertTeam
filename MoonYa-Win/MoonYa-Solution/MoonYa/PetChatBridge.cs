// ┌─────────────────────────────────────────────────────────┐
// │  PetChatBridge — CefSharp JS 桥接对象                    │
// │  前端 AI 回答流式输出时把原文推过来，桌宠气泡清洗后显示   │
// └─────────────────────────────────────────────────────────┘

using System;

namespace MoonYa
{
    /// <summary>
    /// JS 桥：script-1e-rest.php 在 SSE content 事件里 350ms 节流调用
    /// petChat.updateReply(fullReply) 推原文，done / 流结束时调用
    /// petChat.finishReply(fullReply) 推最终全文（此时桌宠朗读摘要）。
    /// 文本清洗/摘要提炼统一在 PetWindow 里做。
    /// </summary>
    public class PetChatBridge
    {
        private readonly PetWindow _petWindow;

        public PetChatBridge(PetWindow petWindow)
        {
            _petWindow = petWindow ?? throw new ArgumentNullException(nameof(petWindow));
        }

        // 由 JS 调用：petChat.updateReply(fullReply) —— 流式更新，不朗读
        public void updateReply(string text)
        {
            try
            {
                if (string.IsNullOrWhiteSpace(text)) return;
                _petWindow.ShowAiReply(text);
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[PetChat] updateReply failed: {ex.Message}");
            }
        }

        // 由 JS 调用：petChat.finishReply(fullReply, speak) —— 回答完成
        //   speak 由前端按规则计算：实时语音对话发送 → 恒 true（无论语音播报开关）；
        //   PTT/打字发送 → 跟随「语音播报」开关状态
        public void finishReply(string text, bool speak)
        {
            try
            {
                if (string.IsNullOrWhiteSpace(text)) return;
                _petWindow.FinishAiReply(text, speak);
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[PetChat] finishReply failed: {ex.Message}");
            }
        }

        // 由 JS 调用：const yes = await petChat.shouldSpeakForPet();
        //   桌宠可见且桌宠 TTS 开启时返回 true —— 应用侧短句播报（trySpeakShortReply）让位给桌宠
        public bool shouldSpeakForPet()
        {
            try
            {
                return _petWindow.IsAiReadingEnabled;
            }
            catch
            {
                return false;
            }
        }
    }
}
