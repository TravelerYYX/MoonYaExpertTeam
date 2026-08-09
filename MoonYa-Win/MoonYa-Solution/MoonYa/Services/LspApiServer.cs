// ┌─────────────────────────────────────────────────────────┐
// │  LspApiServer — LSP HTTP API                            │
// │  监听地址由 launcher_config.json 与环境变量共同决定。  │
// │  路由：POST /lsp-op，按 action 分发到 LspServiceManager │
// │  复用 FileOperationApiServer 的 CORS + 安全检查模式     │
// └─────────────────────────────────────────────────────────┘

using System;
using System.IO;
using System.Net;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Threading;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    public class LspApiServer
    {
        private readonly LspServiceManager _lspManager;
        private readonly string _host;
        private readonly int _port;
        private HttpListener? _listener;
        private CancellationTokenSource? _cts;
        private Task? _listenTask;

        public int Port => _port;
        public bool IsRunning => _listener?.IsListening ?? false;

        public LspApiServer(LspServiceManager lspManager, string host, int port)
        {
            _lspManager = lspManager;
            _host = host;
            _port = port;
        }

        public void Start()
        {
            if (IsRunning) return;

            _cts = new CancellationTokenSource();
            _listener = new HttpListener();
            _listener.Prefixes.Add($"http://{_host}:{_port}/");

            try
            {
                _listener.Start();
            }
            catch (HttpListenerException ex)
            {
                System.Diagnostics.Debug.WriteLine($"LspApiServer: Failed to start on port {_port}: {ex.Message}");
                return;
            }

            _listenTask = Task.Run(() => ListenLoop(_cts.Token));
            System.Diagnostics.Debug.WriteLine($"LspApiServer: Started on http://{_host}:{_port}/");
        }

        public void Stop()
        {
            try { _cts?.Cancel(); } catch { }
            try { _listener?.Stop(); } catch { }
            try { _listener?.Close(); } catch { }

            // 停止所有 LSP 子进程
            try { _lspManager.StopAll(); } catch { }

            System.Diagnostics.Debug.WriteLine("LspApiServer: Stopped.");
        }

        private async Task ListenLoop(CancellationToken ct)
        {
            while (!ct.IsCancellationRequested && _listener?.IsListening == true)
            {
                try
                {
                    var contextTask = _listener.GetContextAsync();
                    var completedTask = await Task.WhenAny(contextTask, Task.Delay(-1, ct));

                    if (ct.IsCancellationRequested) break;

                    var context = await contextTask;
                    _ = Task.Run(() => HandleRequest(context), ct);
                }
                catch (OperationCanceledException) { break; }
                catch (HttpListenerException) { break; }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"LspApiServer: {ex.Message}");
                }
            }
        }

        private async Task HandleRequest(HttpListenerContext context)
        {
            var request = context.Request;
            var response = context.Response;

            try
            {
                // CORS preflight
                if (request.HttpMethod == "OPTIONS")
                {
                    SetCorsHeaders(response);
                    response.StatusCode = 204;
                    response.Close();
                    return;
                }

                SetCorsHeaders(response);

                // Security: 只允许本地访问（与 FileOperationApiServer 一致）
                if (!request.RemoteEndPoint!.Address.Equals(IPAddress.Loopback) &&
                    !request.RemoteEndPoint!.Address.Equals(IPAddress.IPv6Loopback))
                {
                    await Respond(response, 403, Json(new { success = false, message = "只允许本地访问" }));
                    return;
                }

                var path = request.Url!.AbsolutePath;
                bool isLspOp = path.Equals("/lsp-op", StringComparison.OrdinalIgnoreCase);

                if (request.HttpMethod != "POST" || !isLspOp)
                {
                    await Respond(response, 404, Json(new { success = false, message = "Not Found" }));
                    return;
                }

                // 读取请求体
                string body;
                using (var reader = new StreamReader(request.InputStream, request.ContentEncoding))
                {
                    body = await reader.ReadToEndAsync();
                }

                var req = JsonSerializer.Deserialize<LspOpRequest>(body,
                    new JsonSerializerOptions { PropertyNameCaseInsensitive = true });

                if (req == null || string.IsNullOrWhiteSpace(req.Action))
                {
                    await Respond(response, 400, Json(new { success = false, message = "缺少 action 参数" }));
                    return;
                }

                // 按 action 分发到 LspServiceManager
                object result = req.Action switch
                {
                    "get_diagnostics" => await _lspManager.GetDiagnosticsAsync(req.Path ?? ""),
                    "find_references" => await _lspManager.FindReferencesAsync(
                        req.Path ?? "", req.Line ?? 0, req.Column ?? 0),
                    "goto_definition" => await _lspManager.GotoDefinitionAsync(
                        req.Path ?? "", req.Line ?? 0, req.Column ?? 0),
                    _ => new { success = false, message = $"未知操作: {req.Action}" }
                };

                await Respond(response, 200, Json(result));
            }
            catch (Exception ex)
            {
                await Respond(response, 500, Json(new { success = false, message = $"内部错误: {ex.Message}" }));
            }
        }

        private static async Task Respond(HttpListenerResponse response, int statusCode, string body)
        {
            response.StatusCode = statusCode;
            response.ContentType = "application/json; charset=utf-8";
            var bytes = Encoding.UTF8.GetBytes(body);
            response.ContentLength64 = bytes.Length;
            await response.OutputStream.WriteAsync(bytes, 0, bytes.Length);
            response.OutputStream.Close();
        }

        // CORS 头：与 FileOperationApiServer.SetCorsHeaders 一致
        private static void SetCorsHeaders(HttpListenerResponse response)
        {
            response.Headers.Add("Access-Control-Allow-Origin", "*");
            response.Headers.Add("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
            response.Headers.Add("Access-Control-Allow-Headers", "Content-Type, Accept");
            // Private Network Access：允许 HTTPS 页面跨域请求本地 HTTP API
            response.Headers.Add("Access-Control-Allow-Private-Network", "true");
        }

        private static string Json(object obj) =>
            JsonSerializer.Serialize(obj, new JsonSerializerOptions { PropertyNamingPolicy = JsonNamingPolicy.CamelCase });
    }

    /// <summary>
    /// /lsp-op 请求体。
    /// action: get_diagnostics / find_references / goto_definition
    /// path: 文件绝对路径
    /// line/column: 0-based（仅 find_references / goto_definition 使用）
    /// </summary>
    public class LspOpRequest
    {
        [JsonPropertyName("action")]
        public string? Action { get; set; }
        [JsonPropertyName("path")]
        public string? Path { get; set; }
        [JsonPropertyName("line")]
        public int? Line { get; set; }
        [JsonPropertyName("column")]
        public int? Column { get; set; }
    }
}
