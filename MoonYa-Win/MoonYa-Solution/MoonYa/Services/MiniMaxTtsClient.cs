// ┌─────────────────────────────────────────────────────────┐
// │  MiniMaxTtsClient — 项目后端 api/tts.php 语音合成        │
// │  桌面端不直接持有 MiniMax 密钥，复用后端 voice_config     │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    public static class MiniMaxTtsClient
    {
        private static readonly HttpClient _http = new HttpClient
        {
            Timeout = TimeSpan.FromSeconds(20)
        };

        /// <summary>
        /// POST { text } 到后端 api/tts.php，解析 { success, audio(base64-mp3) }。
        /// 成功返回 mp3 字节，失败返回 null（不抛异常）。
        /// </summary>
        public static async Task<byte[]?> SynthesizeAsync(string text, string endpoint)
        {
            if (string.IsNullOrWhiteSpace(text) || string.IsNullOrWhiteSpace(endpoint))
                return null;

            try
            {
                var body = JsonSerializer.Serialize(new { text });
                using var content = new StringContent(body, Encoding.UTF8, "application/json");
                using var resp = await _http.PostAsync(endpoint, content).ConfigureAwait(false);
                var json = await resp.Content.ReadAsStringAsync().ConfigureAwait(false);
                if (!resp.IsSuccessStatusCode)
                {
                    System.Diagnostics.Debug.WriteLine($"[TTS] HTTP {(int)resp.StatusCode}: {json}");
                    return null;
                }

                using var doc = JsonDocument.Parse(json);
                var root = doc.RootElement;
                if (root.TryGetProperty("success", out var ok) && ok.GetBoolean() &&
                    root.TryGetProperty("audio", out var audioEl))
                {
                    var b64 = audioEl.GetString();
                    if (string.IsNullOrEmpty(b64)) return null;
                    return Convert.FromBase64String(b64);
                }

                System.Diagnostics.Debug.WriteLine($"[TTS] 响应无音频: {json}");
                return null;
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[TTS] 合成失败: {ex.Message}");
                return null;
            }
        }
    }
}
