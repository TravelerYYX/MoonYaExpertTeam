// ┌─────────────────────────────────────────────────────────────┐
// │  AsrClient — 阿里云 Fun-ASR 实时语音识别 WebSocket 客户端     │
// │  1. 从 PHP 后端获取 API Key                                  │
// │  2. 连接后端配置返回的 WebSocket 服务                         │
// │  3. run-task → 发送音频流 → 接收实时识别结果 → finish-task    │
// │  4. 通过 ExecuteScriptAsync 回传前端                         │
// └─────────────────────────────────────────────────────────────┘

using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Net.WebSockets;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using CefSharp;
using CefSharp.Wpf;

namespace MoonYa.Services
{
    public class AsrClient
    {
        private ClientWebSocket? _ws;
        private string? _taskId;
        private string _apiKey;
        private string[] _models = Array.Empty<string>();  // ★ 动态从 asr_key.php 加载
        private string _model = "";
        private string _websocketUrl = "";
        private CancellationTokenSource? _cts;
        private bool _taskStarted;
        private bool _taskFinished;
        private bool _inStartPhase;   // ★ Start() 阶段：task-failed 抑制回调，交给 Start 循环自动 fallback
        private string _finalText = "";
        private string _interimText = "";

        private int _modelIndex;

        private readonly CefSharp.Wpf.ChromiumWebBrowser _webView;
        private readonly string _backendUrl;

        public AsrClient(CefSharp.Wpf.ChromiumWebBrowser webView, string backendUrl)
        {
            _webView = webView;
            _backendUrl = backendUrl ?? "";
        }

        /// <summary>从 PHP 后端获取 API Key（仅首次调用时获取）</summary>
        private async Task<bool> EnsureInitialized()
        {
            if (!string.IsNullOrEmpty(_apiKey) && _models.Length > 0) return true;

            try
            {
                using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
                var url = _backendUrl.TrimEnd('/') + "/api/asr_key.php";
                var resp = await http.GetStringAsync(url);
                var data = JsonSerializer.Deserialize<JsonElement>(resp);
                if (data.TryGetProperty("success", out var successProp) && !successProp.GetBoolean())
                {
                    var configError = data.TryGetProperty("error", out var errorProp)
                        ? errorProp.GetString()
                        : "ASR configuration is unavailable";
                    throw new InvalidOperationException(configError);
                }
                _apiKey = data.GetProperty("api_key").GetString() ?? "";
                if (string.IsNullOrEmpty(_apiKey))
                {
                    System.Diagnostics.Debug.WriteLine("[ASR] API Key 为空");
                    return false;
                }

                // 解析后端按配置优先级返回的动态模型列表。
                if (data.TryGetProperty("fallback_models", out var modelsProp)
                    && modelsProp.ValueKind == JsonValueKind.Array
                    && modelsProp.GetArrayLength() > 0)
                {
                    var list = new List<string>();
                    foreach (var m in modelsProp.EnumerateArray())
                    {
                        var name = m.GetString();
                        if (!string.IsNullOrWhiteSpace(name) && !list.Contains(name))
                            list.Add(name);
                    }
                    if (list.Count > 0) _models = list.ToArray();
                }

                if (_models.Length == 0)
                    throw new InvalidOperationException("Missing required configuration: aliyun_asr.fallback_models");

                // 解析主模型名（仅用于显示日志）
                if (data.TryGetProperty("model", out var modelProp))
                {
                    var name = modelProp.GetString();
                    if (!string.IsNullOrWhiteSpace(name)) _model = name;
                }
                _websocketUrl = data.GetProperty("websocket_url").GetString() ?? "";
                if (!Uri.TryCreate(_websocketUrl, UriKind.Absolute, out var websocketUri)
                    || (websocketUri.Scheme != Uri.UriSchemeWs && websocketUri.Scheme != Uri.UriSchemeWss))
                    throw new InvalidOperationException("Missing or invalid required configuration: aliyun_asr.websocket_url");

                System.Diagnostics.Debug.WriteLine($"[ASR] API Key 已获取: {(_apiKey.Length > 10 ? _apiKey.Substring(0, 10) + "..." : _apiKey)}，模型数={_models.Length}（{string.Join(",", _models)}）");
                return true;
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[ASR] 获取配置失败: {ex.Message}");
                return false;
            }
        }

        /// <summary>开始 ASR 会话：连接 WebSocket + 发送 run-task</summary>
        /// <returns>空字符串表示成功，否则返回错误信息</returns>
        public async Task<string> Start()
        {
            if (!await EnsureInitialized())
                return "API Key 未配置，请检查后端 asr_key.php";

            if (_models == null || _models.Length == 0)
                return "无可用 ASR 模型，请检查后端 aliyun_asr.fallback_models 配置";

            // 重置状态
            _cts?.Cancel();
            _cts = new CancellationTokenSource();
            _taskStarted = false;
            _taskFinished = false;
            _inStartPhase = true;   // ★ 进入 Start 阶段：task-failed 不回调前端
            _finalText = "";
            _interimText = "";
            _modelIndex = 0;

            // ★ 自动 fallback：依次尝试所有模型直到有一个成功
            string lastError = "";
            for (int attempt = 0; attempt < _models.Length; attempt++)
            {
                try
                {
                    // 关闭旧连接
                    if (_ws != null)
                    {
                        try { _ws.Dispose(); } catch { }
                        _ws = null;
                    }

                    // 重置本次尝试的标记
                    _taskStarted = false;
                    _taskFinished = false;

                    // 连接 WebSocket
                    _ws = new ClientWebSocket();
                    _ws.Options.SetRequestHeader("Authorization", $"bearer {_apiKey}");
                    await _ws.ConnectAsync(new Uri(_websocketUrl), _cts.Token);

                    // 生成 32 位 hex task_id
                    _taskId = Guid.NewGuid().ToString("N");

                    // 发送 run-task
                    await SendRunTaskAsync(_models[attempt]);

                    // 启动接收循环（后台任务）
                    _ = ReceiveLoopAsync();

                    // 等待 task-started（最多 5 秒），期间收到 task-failed 则尝试下一模型
                    for (int i = 0; i < 50 && !_taskStarted && !_taskFinished && !_cts.IsCancellationRequested; i++)
                    {
                        await Task.Delay(100);
                    }

                    if (_taskStarted)
                    {
                        _modelIndex = attempt;
                        _inStartPhase = false;  // ★ 启动成功即退出 Start 阶段：后续 task-failed 要正常回调前端
                        System.Diagnostics.Debug.WriteLine($"[ASR] 模型 {_models[attempt]} 启动成功");
                        return "";  // ✅ 成功
                    }

                    if (_taskFinished)
                    {
                        lastError = $"模型 {_models[attempt]} 启动失败";
                        System.Diagnostics.Debug.WriteLine($"[ASR] 模型 {_models[attempt]} 失败，尝试下一个");
                        continue;  // 尝试下一模型
                    }

                    // 超时：WebSocket 已连接但 server 未响应，继续尝试下一模型
                    lastError = $"模型 {_models[attempt]} 连接超时";
                    System.Diagnostics.Debug.WriteLine($"[ASR] 模型 {_models[attempt]} 超时，尝试下一个");
                    continue;
                }
                catch (Exception ex)
                {
                    lastError = ex.Message;
                    System.Diagnostics.Debug.WriteLine($"[ASR] 模型 {_models[attempt]} 连接异常: {ex.Message}，尝试下一个");
                    continue;
                }
            }

            // 所有模型都失败
            System.Diagnostics.Debug.WriteLine($"[ASR] 所有 {_models.Length} 个模型均失败: {lastError}");
            _inStartPhase = false;  // ★ 退出 Start 阶段
            return lastError;
        }

        /// <summary>发送音频数据（前端传来的 base64 编码 PCM）</summary>
        public async Task SendAudio(string base64Pcm)
        {
            if (_ws == null || _ws.State != WebSocketState.Open || !_taskStarted) return;

            try
            {
                var bytes = Convert.FromBase64String(base64Pcm);
                if (bytes.Length == 0) return;
                await _ws.SendAsync(new ArraySegment<byte>(bytes), WebSocketMessageType.Binary, true, _cts!.Token);
            }
            catch (Exception ex)
            {
                // 静默处理单个 chunk 发送失败，不打断整体流程
                System.Diagnostics.Debug.WriteLine($"[ASR] 发送音频失败: {ex.Message}");
            }
        }

        /// <summary>停止 ASR 会话：发送 finish-task + 等待最终结果。
        /// ★ 保证必定回调 __onAsrFinished，即使连接已断/超时/异常，避免前端卡死</summary>
        public async Task Stop()
        {
            // 连接已断：直接回调当前已识别文本
            if (_ws == null || _ws.State != WebSocketState.Open)
            {
                System.Diagnostics.Debug.WriteLine("[ASR] Stop: 连接已断，强制回调");
                CallJs($"window.__onAsrFinished({JsonSerializer.Serialize(_finalText + _interimText)})");
                return;
            }

            try
            {
                // 发送 finish-task
                var finishTask = new
                {
                    header = new
                    {
                        action = "finish-task",
                        task_id = _taskId,
                        streaming = "duplex"
                    },
                    payload = new { input = new { } }
                };
                await SendJsonAsync(finishTask);

                // 等待 task-finished（最多 5 秒）
                for (int i = 0; i < 50 && !_taskFinished && !_cts!.IsCancellationRequested; i++)
                {
                    await Task.Delay(100);
                }

                // 超时未收到 task-finished，强制回调
                if (!_taskFinished)
                {
                    System.Diagnostics.Debug.WriteLine("[ASR] task-finished 超时，强制回调");
                    CallJs($"window.__onAsrFinished({JsonSerializer.Serialize(_finalText + _interimText)})");
                }

                // 关闭连接
                if (_ws?.State == WebSocketState.Open)
                {
                    try
                    {
                        await _ws.CloseAsync(WebSocketCloseStatus.NormalClosure, "Closing", CancellationToken.None);
                    }
                    catch { }
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[ASR] Stop 异常: {ex.Message}");
                // 异常时也要回调，避免前端卡死
                CallJs($"window.__onAsrFinished({JsonSerializer.Serialize(_finalText + _interimText)})");
            }
        }

        // ═══ 内部方法 ═══

        private async Task SendRunTaskAsync(string model)
        {
            var runTask = new
            {
                header = new
                {
                    action = "run-task",
                    task_id = _taskId,
                    streaming = "duplex"
                },
                payload = new
                {
                    task_group = "audio",
                    task = "asr",
                    function = "recognition",
                    model = model,
                    parameters = new
                    {
                        sample_rate = 16000,
                        format = "pcm",
                        enable_interim_result = true
                    },
                    input = new { }
                }
            };
            await SendJsonAsync(runTask);
            System.Diagnostics.Debug.WriteLine($"[ASR] run-task 已发送, model={model}, task_id={_taskId}");
        }

        private async Task SendJsonAsync(object obj)
        {
            if (_ws == null || _ws.State != WebSocketState.Open) return;
            var json = JsonSerializer.Serialize(obj);
            var bytes = Encoding.UTF8.GetBytes(json);
            await _ws.SendAsync(new ArraySegment<byte>(bytes), WebSocketMessageType.Text, true, _cts!.Token);
        }

        private async Task ReceiveLoopAsync()
        {
            var buffer = new byte[16384];
            var sb = new StringBuilder();

            try
            {
                while (_ws != null && _ws.State == WebSocketState.Open && !_cts!.IsCancellationRequested)
                {
                    sb.Clear();
                    WebSocketReceiveResult result;

                    do
                    {
                        result = await _ws.ReceiveAsync(new ArraySegment<byte>(buffer), _cts.Token);
                        if (result.MessageType == WebSocketMessageType.Close) return;
                        sb.Append(Encoding.UTF8.GetString(buffer, 0, result.Count));
                    } while (!result.EndOfMessage);

                    ProcessMessage(sb.ToString());
                }
            }
            catch (OperationCanceledException) { }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[ASR] 接收错误: {ex.Message}");
                // 有部分识别结果，走正常完成流程；否则走错误流程
                if (!_taskFinished && !string.IsNullOrEmpty(_finalText))
                {
                    CallJs($"window.__onAsrFinished({JsonSerializer.Serialize(_finalText + _interimText)})");
                }
                else if (!_taskFinished)
                {
                    CallJs($"window.__onAsrError({JsonSerializer.Serialize("连接异常: " + ex.Message)})");
                }
            }
        }

        private void ProcessMessage(string json)
        {
            try
            {
                var msg = JsonSerializer.Deserialize<JsonElement>(json);
                var header = msg.GetProperty("header");
                var eventType = header.GetProperty("event").GetString();

                switch (eventType)
                {
                    case "task-started":
                        _taskStarted = true;
                        System.Diagnostics.Debug.WriteLine("[ASR] 任务已启动");
                        CallJs("window.__onAsrReady()");
                        break;

                    case "result-generated":
                        try
                        {
                            var sentence = msg.GetProperty("payload").GetProperty("output").GetProperty("sentence");
                            var text = sentence.GetProperty("text").GetString() ?? "";
                            var isEnd = sentence.TryGetProperty("is_end", out var ieProp) && ieProp.GetBoolean();

                            if (isEnd)
                            {
                                _finalText += text;
                                _interimText = "";
                            }
                            else
                            {
                                _interimText = text;
                            }

                            var display = _finalText + _interimText;
                            CallJs($"window.__onAsrResult({JsonSerializer.Serialize(display)}, {(isEnd ? "true" : "false")})");
                        }
                        catch (Exception ex)
                        {
                            System.Diagnostics.Debug.WriteLine($"[ASR] 解析结果失败: {ex.Message}");
                        }
                        break;

                    case "task-finished":
                        _taskFinished = true;
                        // 短句可能只有 interim 没有 final，必须合并传，否则前端拿到空串
                        var finishedDisplay = _finalText + _interimText;
                        System.Diagnostics.Debug.WriteLine("[ASR] 任务完成, 最终文本: " + finishedDisplay);
                        // 直接把最终文本作为参数传给前端，避免依赖前端累计
                        CallJs($"window.__onAsrResult({JsonSerializer.Serialize(finishedDisplay)}, true)");
                        CallJs($"window.__onAsrFinished({JsonSerializer.Serialize(finishedDisplay)})");
                        break;

                    case "task-failed":
                        _taskFinished = true;
                        var errMsg = header.TryGetProperty("error_message", out var emProp)
                            ? emProp.GetString() ?? "识别失败"
                            : "识别失败";
                        var errorCode = header.TryGetProperty("error_code", out var ecProp)
                            ? ecProp.GetString() ?? ""
                            : "";

                        System.Diagnostics.Debug.WriteLine($"[ASR] 任务失败: {errorCode} {errMsg}");

                        // ★ Start 阶段：抑制回调，让 Start 循环自动 fallback 到下一个模型
                        if (_inStartPhase)
                        {
                            System.Diagnostics.Debug.WriteLine($"[ASR] Start 阶段任务失败，交由 Start 循环处理（不回调前端）");
                            break;
                        }

                        // 有部分识别结果（含 interim），走正常完成流程；否则走错误流程
                        if (!string.IsNullOrEmpty(_finalText + _interimText))
                        {
                            CallJs($"window.__onAsrFinished({JsonSerializer.Serialize(_finalText + _interimText)})");
                        }
                        else
                        {
                            CallJs($"window.__onAsrError({JsonSerializer.Serialize(errMsg)})");
                        }
                        break;
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[ASR] 解析消息失败: {ex.Message}");
            }
        }

        private void CallJs(string script)
        {
            _webView.Dispatcher.Invoke(() =>
            {
                try { _webView.ExecuteScriptAsync(script); }
                catch { }
            });
        }
    }
}
