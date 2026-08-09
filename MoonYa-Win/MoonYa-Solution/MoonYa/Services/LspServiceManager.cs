// ┌─────────────────────────────────────────────────────────┐
// │  LspServiceManager — LSP 服务管理核心                    │
// │  - ILanguageServer 抽象接口                              │
// │  - LspJsonRpcServerBase 共享 Content-Length JSON-RPC 协议 │
// │  - LspServiceManager 进程池（按语言+工作区复用 LSP 进程）│
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    /// <summary>
    /// LSP 语言服务器抽象接口：PHP / Python / TypeScript 三种实现
    /// </summary>
    public interface ILanguageServer
    {
        string Language { get; }  // "php" / "python" / "typescript"
        Task<bool> StartAsync(string workspaceRoot);
        Task<bool> EnsureRunningAsync(string workspaceRoot);
        Task<object> GetDiagnosticsAsync(string filePath);
        Task<object> FindReferencesAsync(string filePath, int line, int column);
        Task<object> GotoDefinitionAsync(string filePath, int line, int column);
        Task NotifyDidOpenAsync(string filePath, string content);
        Task NotifyDidChangeAsync(string filePath, string content);
        Task NotifyDidCloseAsync(string filePath);
        void Stop();
    }

    /// <summary>
    /// LSP JSON-RPC 基类：实现 Content-Length header 协议、请求/响应关联、通知分发。
    /// PHP Intelephense / Python Pyright 均继承此类（协议相同）。
    /// TypeScript tsserver 协议不同（行分隔 JSON），由 TypeScriptServer 独立实现。
    /// </summary>
    public abstract class LspJsonRpcServerBase : ILanguageServer
    {
        public abstract string Language { get; }

        protected Process? _process;
        protected string _workspaceRoot = "";
        protected int _nextId = 1;
        protected readonly object _sendLock = new();
        // 请求 id -> 等待响应的 TaskCompletionSource
        protected readonly ConcurrentDictionary<int, TaskCompletionSource<JsonElement?>> _pending = new();
        // 文件 URI -> 最新诊断数组（由 publishDiagnostics 通知更新）
        protected readonly ConcurrentDictionary<string, JsonElement> _diagnostics = new();
        // 已 didOpen 的文件 URI 集合（崩溃重启后需重新打开）
        protected readonly HashSet<string> _openedFiles = new();
        protected readonly object _openedFilesLock = new();

        protected bool _initialized = false;
        protected Task? _receiveTask;
        protected CancellationTokenSource? _cts;
        // 进程代数：每次启动新进程时递增；用于检测 Exited 事件是否来自当前进程
        // （避免旧进程的 Exited 事件触发后误清理新进程的 _pending）
        protected int _generation = 0;

        // ── 启动/重启 ──────────────────────────────────────────

        public async Task<bool> StartAsync(string workspaceRoot)
        {
            if (_process != null && !_process.HasExited && _initialized) return true;

            _workspaceRoot = Path.GetFullPath(workspaceRoot);
            var gen = Interlocked.Increment(ref _generation);
            var err = await StartProcessAsync(_workspaceRoot);
            if (err != null)
            {
                Debug.WriteLine($"[{Language}] 启动失败: {err}");
                return false;
            }

            // 注册进程退出事件：仅在当前代数匹配时触发 OnProcessExited（避免误清理新进程状态）
            var capturedProcess = _process;
            if (capturedProcess != null)
            {
                capturedProcess.EnableRaisingEvents = true;
                capturedProcess.Exited += (s, e) =>
                {
                    if (gen == Volatile.Read(ref _generation) && ReferenceEquals(_process, capturedProcess))
                    {
                        Debug.WriteLine($"[{Language}] 进程退出事件 ExitCode={capturedProcess.ExitCode}");
                        OnProcessExited();
                    }
                };
            }

            _cts = new CancellationTokenSource();
            _receiveTask = Task.Run(() => ReceiveLoop(_cts.Token, gen));

            // 发送 initialize 请求
            var initParams = new
            {
                processId = Environment.ProcessId,
                rootUri = PathToUri(_workspaceRoot),
                capabilities = new { },
                workspaceFolders = new[]
                {
                    new { uri = PathToUri(_workspaceRoot), name = Path.GetFileName(_workspaceRoot) }
                }
            };

            var resp = await SendRequestAsync("initialize", initParams);
            if (resp == null)
            {
                Debug.WriteLine($"[{Language}] initialize 失败");
                return false;
            }

            // 发送 initialized 通知
            await SendNotificationAsync("initialized", new { });
            _initialized = true;

            // 崩溃重启后：重新 didOpen 已注册的文件
            List<string> filesToReopen;
            lock (_openedFilesLock) filesToReopen = _openedFiles.ToList();
            _openedFiles.Clear();
            foreach (var fileUri in filesToReopen)
            {
                var filePath = UriToPath(fileUri);
                if (File.Exists(filePath))
                {
                    try
                    {
                        var content = await File.ReadAllTextAsync(filePath);
                        await NotifyDidOpenAsync(filePath, content);
                    }
                    catch (Exception ex)
                    {
                        Debug.WriteLine($"[{Language}] 重新打开 {filePath} 失败: {ex.Message}");
                    }
                }
            }

            return true;
        }

        public async Task<bool> EnsureRunningAsync(string workspaceRoot)
        {
            if (_process != null && !_process.HasExited && _initialized)
                return true;

            // 进程崩溃 -> 重启
            if (_process != null && _process.HasExited)
            {
                Debug.WriteLine($"[{Language}] 进程已退出 (ExitCode={_process.ExitCode})，重启中...");

                // 1. 取消旧 receive loop 的 cts（让旧循环退出，不再分发消息）
                try { _cts?.Cancel(); } catch { }
                // 2. 等待旧 receive loop 退出（最多 2 秒），避免与新进程的 receive loop 竞争 _pending
                if (_receiveTask != null)
                {
                    try { await Task.WhenAny(_receiveTask, Task.Delay(2000)); } catch { }
                }

                try { _process.Dispose(); } catch { }
                _process = null;
                _initialized = false;
                // 清理未完成的 pending 请求（旧请求全部置为取消）
                foreach (var kv in _pending)
                {
                    kv.Value.TrySetCanceled();
                }
                _pending.Clear();
            }

            return await StartAsync(workspaceRoot);
        }

        // 子类实现：启动对应语言的 LSP 进程。返回 null 表示成功，返回字符串表示错误信息
        protected abstract Task<string?> StartProcessAsync(string workspaceRoot);

        // ── JSON-RPC 通信 ─────────────────────────────────────

        private async Task ReceiveLoop(CancellationToken ct, int gen)
        {
            var stream = _process?.StandardOutput.BaseStream;
            if (stream == null) return;

            while (!ct.IsCancellationRequested)
            {
                try
                {
                    // 1. 读取 header（逐字节直到空行）
                    var headers = new Dictionary<string, string>();
                    var lineBuf = new StringBuilder();
                    bool gotEmptyLine = false;

                    while (!ct.IsCancellationRequested)
                    {
                        int b = stream.ReadByte();
                        if (b == -1)
                        {
                            // EOF - 进程退出
                            Debug.WriteLine($"[{Language}] stdout EOF, 进程已退出");
                            // 仅当代数匹配时才通知退出，避免旧 receive loop 误清理新进程状态
                            if (gen == Volatile.Read(ref _generation))
                                OnProcessExited();
                            return;
                        }
                        if (b == '\r')
                        {
                            int next = stream.ReadByte();
                            if (next == '\n')
                            {
                                if (lineBuf.Length == 0)
                                {
                                    gotEmptyLine = true;
                                    break;
                                }
                                var line = lineBuf.ToString();
                                var idx = line.IndexOf(':');
                                if (idx > 0)
                                    headers[line.Substring(0, idx).Trim()] = line.Substring(idx + 1).Trim();
                                lineBuf.Clear();
                            }
                            // 单独 \r 忽略
                        }
                        else if (b != '\n')
                        {
                            lineBuf.Append((char)b);
                        }
                    }

                    if (!gotEmptyLine || ct.IsCancellationRequested) break;

                    if (!headers.TryGetValue("Content-Length", out var lenStr) ||
                        !int.TryParse(lenStr, out var contentLength))
                    {
                        continue;
                    }

                    // 2. 读取 body
                    var bodyBuf = new byte[contentLength];
                    int totalRead = 0;
                    while (totalRead < contentLength)
                    {
                        int read = await stream.ReadAsync(bodyBuf, totalRead, contentLength - totalRead, ct);
                        if (read == 0)
                        {
                            if (gen == Volatile.Read(ref _generation))
                                OnProcessExited();
                            return;
                        }
                        totalRead += read;
                    }

                    var json = Encoding.UTF8.GetString(bodyBuf);
                    JsonElement root;
                    try
                    {
                        using var doc = JsonDocument.Parse(json);
                        root = doc.RootElement.Clone();
                    }
                    catch (Exception ex)
                    {
                        Debug.WriteLine($"[{Language}] JSON 解析失败: {ex.Message}");
                        continue;
                    }

                    DispatchMessage(root);
                }
                catch (OperationCanceledException) { return; }
                catch (Exception ex)
                {
                    Debug.WriteLine($"[{Language}] ReceiveLoop 异常: {ex.Message}");
                    if (_process?.HasExited != false) return;
                }
            }
        }

        protected virtual void OnProcessExited()
        {
            // 通知所有等待响应的请求失败
            foreach (var kv in _pending)
            {
                kv.Value.TrySetResult(null);
            }
            _pending.Clear();
        }

        private void DispatchMessage(JsonElement msg)
        {
            // 1. 响应（有 id 字段）
            if (msg.TryGetProperty("id", out var idProp) &&
                (idProp.ValueKind == JsonValueKind.Number))
            {
                var id = idProp.GetInt32();
                if (_pending.TryRemove(id, out var tcs))
                {
                    if (msg.TryGetProperty("error", out var err))
                        tcs.TrySetResult(err);
                    else if (msg.TryGetProperty("result", out var result))
                        tcs.TrySetResult(result);
                    else
                        tcs.TrySetResult(null);
                }
                return;
            }

            // 2. 通知（无 id，有 method）
            if (msg.TryGetProperty("method", out var methodProp))
            {
                var method = methodProp.GetString();
                if (method == "textDocument/publishDiagnostics")
                {
                    HandlePublishDiagnostics(msg);
                }
                // 其他通知（window/logMessage 等）忽略
            }
        }

        protected virtual void HandlePublishDiagnostics(JsonElement msg)
        {
            if (!msg.TryGetProperty("params", out var p)) return;
            if (!p.TryGetProperty("uri", out var uriProp)) return;
            var uri = uriProp.GetString();
            if (string.IsNullOrEmpty(uri)) return;

            if (p.TryGetProperty("diagnostics", out var diagProp))
                _diagnostics[uri] = diagProp.Clone();
            else
            {
                using var empty = JsonDocument.Parse("[]");
                _diagnostics[uri] = empty.RootElement.Clone();
            }
        }

        protected async Task<JsonElement?> SendRequestAsync(string method, object? @params, int timeoutMs = 15000)
        {
            if (_process == null || _process.HasExited) return null;

            var id = Interlocked.Increment(ref _nextId);
            var tcs = new TaskCompletionSource<JsonElement?>(TaskCreationOptions.RunContinuationsAsynchronously);
            _pending[id] = tcs;

            var msg = new Dictionary<string, object?>
            {
                ["jsonrpc"] = "2.0",
                ["id"] = id,
                ["method"] = method
            };
            if (@params != null) msg["params"] = @params;

            try
            {
                await SendRawAsync(msg);
            }
            catch (Exception ex)
            {
                _pending.TryRemove(id, out _);
                Debug.WriteLine($"[{Language}] SendRequest {method} 发送失败: {ex.Message}");
                return null;
            }

            // 超时
            using var cts = new CancellationTokenSource(timeoutMs);
            cts.Token.Register(() =>
            {
                if (_pending.TryRemove(id, out var t))
                    t.TrySetCanceled();
            });

            try
            {
                return await tcs.Task;
            }
            catch (OperationCanceledException)
            {
                Debug.WriteLine($"[{Language}] SendRequest {method} 超时");
                return null;
            }
        }

        protected async Task SendNotificationAsync(string method, object? @params)
        {
            var msg = new Dictionary<string, object?>
            {
                ["jsonrpc"] = "2.0",
                ["method"] = method
            };
            if (@params != null) msg["params"] = @params;
            await SendRawAsync(msg);
        }

        protected Task SendRawAsync(object msg)
        {
            if (_process == null) return Task.CompletedTask;
            var json = JsonSerializer.Serialize(msg);
            var bytes = Encoding.UTF8.GetBytes(json);
            var header = $"Content-Length: {bytes.Length}\r\n\r\n";
            var headerBytes = Encoding.ASCII.GetBytes(header);

            lock (_sendLock)
            {
                try
                {
                    var stream = _process.StandardInput.BaseStream;
                    stream.Write(headerBytes, 0, headerBytes.Length);
                    stream.Write(bytes, 0, bytes.Length);
                    stream.Flush();
                }
                catch (Exception ex)
                {
                    Debug.WriteLine($"[{Language}] 写入 stdin 失败: {ex.Message}");
                    throw;
                }
            }
            return Task.CompletedTask;
        }

        // ── 文档同步通知 ─────────────────────────────────────

        public async Task NotifyDidOpenAsync(string filePath, string content)
        {
            var uri = PathToUri(filePath);
            bool alreadyOpen;
            lock (_openedFilesLock) alreadyOpen = _openedFiles.Contains(uri);

            if (alreadyOpen)
            {
                // 已打开，调用 didChange 更新内容
                await SendNotificationAsync("textDocument/didChange", new
                {
                    textDocument = new { uri = uri, version = Math.Abs(DateTime.Now.Ticks) },
                    contentChanges = new[] { new { text = content } }
                });
                return;
            }

            lock (_openedFilesLock) _openedFiles.Add(uri);

            var @params = new
            {
                textDocument = new
                {
                    uri = uri,
                    languageId = Language,
                    version = 1,
                    text = content
                }
            };
            await SendNotificationAsync("textDocument/didOpen", @params);
        }

        public async Task NotifyDidChangeAsync(string filePath, string content)
        {
            var uri = PathToUri(filePath);
            var @params = new
            {
                textDocument = new { uri = uri, version = Math.Abs(DateTime.Now.Ticks) },
                contentChanges = new[] { new { text = content } }
            };
            await SendNotificationAsync("textDocument/didChange", @params);
        }

        public async Task NotifyDidCloseAsync(string filePath)
        {
            var uri = PathToUri(filePath);
            var @params = new { textDocument = new { uri = uri } };
            await SendNotificationAsync("textDocument/didClose", @params);
            lock (_openedFilesLock) _openedFiles.Remove(uri);
        }

        // ── LSP 高层 API ──────────────────────────────────────

        public async Task<object> GetDiagnosticsAsync(string filePath)
        {
            var uri = PathToUri(filePath);
            // 等待 LSP 推送诊断（didOpen 后异步触发）
            await Task.Delay(800);
            if (_diagnostics.TryGetValue(uri, out var diag))
                return new { success = true, uri = uri, diagnostics = diag };
            return new { success = true, uri = uri, diagnostics = Array.Empty<object>() };
        }

        public async Task<object> FindReferencesAsync(string filePath, int line, int column)
        {
            var result = await SendRequestAsync("textDocument/references", new
            {
                textDocument = new { uri = PathToUri(filePath) },
                position = new { line = line, character = column },
                context = new { includeDeclaration = true }
            });

            if (result == null)
                return new { success = false, error = "LSP 请求失败或超时" };

            return new { success = true, references = result };
        }

        public async Task<object> GotoDefinitionAsync(string filePath, int line, int column)
        {
            var result = await SendRequestAsync("textDocument/definition", new
            {
                textDocument = new { uri = PathToUri(filePath) },
                position = new { line = line, character = column }
            });

            if (result == null)
                return new { success = false, error = "LSP 请求失败或超时" };

            return new { success = true, definition = result };
        }

        public void Stop()
        {
            try { _cts?.Cancel(); } catch { }
            try
            {
                if (_process != null && !_process.HasExited)
                {
                    // 优雅关闭：先 shutdown 再 exit
                    try
                    {
                        var id = Interlocked.Increment(ref _nextId);
                        var shutdownMsg = new Dictionary<string, object?>
                        {
                            ["jsonrpc"] = "2.0",
                            ["id"] = id,
                            ["method"] = "shutdown"
                        };
                        SendRawAsync(shutdownMsg).Wait(TimeSpan.FromSeconds(2));
                        SendNotificationAsync("exit", null).Wait(TimeSpan.FromSeconds(2));
                    }
                    catch { }

                    try { _process.Kill(entireProcessTree: true); } catch { }
                }
            }
            finally
            {
                try { _process?.Dispose(); } catch { }
                _process = null;
                _initialized = false;
            }
        }

        // ── 路径 / URI 互转工具 ───────────────────────────────

        protected static string PathToUri(string path)
        {
            try
            {
                var full = Path.GetFullPath(path);
                return new Uri(full).AbsoluteUri;
            }
            catch
            {
                return "file:///" + path.Replace('\\', '/').TrimStart('/');
            }
        }

        protected static string UriToPath(string uri)
        {
            try
            {
                return new Uri(uri).LocalPath;
            }
            catch
            {
                if (uri.StartsWith("file:///"))
                    return uri.Substring(8).Replace('/', '\\');
                if (uri.StartsWith("file://"))
                    return uri.Substring(7).Replace('/', '\\');
                return uri;
            }
        }

        // ── 辅助：执行 shell 命令（检测 LSP 是否安装）────────

        protected static async Task<string> RunShellAsync(string fileName, string arguments, int timeoutMs = 10000)
        {
            try
            {
                var psi = new ProcessStartInfo
                {
                    FileName = fileName,
                    Arguments = arguments,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };
                using var p = Process.Start(psi);
                if (p == null) return "";
                var outputTask = p.StandardOutput.ReadToEndAsync();
                if (!p.WaitForExit(timeoutMs))
                {
                    try { p.Kill(); } catch { }
                    return "";
                }
                return await outputTask;
            }
            catch { return ""; }
        }
    }

    /// <summary>
    /// LSP 服务管理器：按语言+工作区根缓存 LSP 进程，按需启动。
    /// 同一语言+同一工作区 = 复用同一 LSP 进程；
    /// 不同语言或不同工作区 = 启动新进程；
    /// 进程崩溃 -> 下次调用 EnsureRunningAsync 自动重启。
    /// </summary>
    public class LspServiceManager
    {
        private readonly Dictionary<string, ILanguageServer> _servers = new();
        private readonly object _lock = new();

        /// <summary>
        /// 按语言+工作区获取 LSP 服务（按需启动）。
        /// 已有进程则复用并 EnsureRunningAsync；否则新建并 StartAsync。
        /// </summary>
        public async Task<ILanguageServer?> GetServerAsync(string language, string workspaceRoot)
        {
            if (string.IsNullOrEmpty(language) || string.IsNullOrEmpty(workspaceRoot))
                return null;

            var key = $"{language.ToLowerInvariant()}|{Path.GetFullPath(workspaceRoot).ToLowerInvariant()}";

            ILanguageServer? server;
            lock (_lock)
            {
                _servers.TryGetValue(key, out server);
            }

            if (server != null)
            {
                // EnsureRunningAsync 内部判断是否需要重启
                var ok = await server.EnsureRunningAsync(workspaceRoot);
                if (ok) return server;

                // 重启失败，从池中移除
                lock (_lock) _servers.Remove(key);
                try { server.Stop(); } catch { }
                return null;
            }

            // 新建并启动
            ILanguageServer? newServer = language.ToLowerInvariant() switch
            {
                "php" => new PhpIntelephenseServer(),
                "python" => new PythonPyrightServer(),
                "typescript" or "javascript" => new TypeScriptServer(),
                _ => null
            };

            if (newServer == null) return null;

            var started = await newServer.StartAsync(workspaceRoot);
            if (!started)
            {
                try { newServer.Stop(); } catch { }
                return null;
            }

            lock (_lock) _servers[key] = newServer;
            return newServer;
        }

        /// <summary>
        /// 高层 API：获取文件诊断。自动检测语言、推断工作区、按需启动 LSP。
        /// 错误恢复：LSP 调用失败时（进程刚崩溃等），自动调用 EnsureRunningAsync 重启后重试一次；
        /// 仍失败则返回兜底建议（改用 execute_command 调用语言原生语法检查工具）。
        /// </summary>
        public async Task<object> GetDiagnosticsAsync(string filePath)
        {
            try
            {
                if (string.IsNullOrWhiteSpace(filePath) || !File.Exists(filePath))
                    return new { success = false, error = $"文件不存在: {filePath}" };

                var language = DetectLanguage(filePath);
                if (language == null)
                    return new { success = false, error = $"不支持的文件类型: {Path.GetExtension(filePath)}" };

                var workspaceRoot = DetectWorkspaceRoot(filePath);
                if (workspaceRoot == null)
                    return new { success = false, error = "无法确定工作区根目录" };

                var server = await GetServerAsync(language, workspaceRoot);
                if (server == null)
                    return new { success = false, error = BuildLspFallbackError(language) };

                var content = await File.ReadAllTextAsync(filePath);

                // 第一次尝试：try-catch 包裹 LSP 调用（didOpen + getDiagnostics）
                try
                {
                    await server.NotifyDidOpenAsync(filePath, content);
                    return await server.GetDiagnosticsAsync(filePath);
                }
                catch (Exception ex)
                {
                    Debug.WriteLine($"[{language}] GetDiagnostics 第一次失败: {ex.Message}，尝试重启 LSP 后重试一次");
                }

                // LSP 调用抛异常：调用 EnsureRunningAsync 重启 LSP 进程
                var restarted = await server.EnsureRunningAsync(workspaceRoot);
                if (!restarted)
                    return new { success = false, error = BuildLspFallbackError(language) };

                // 重试一次（仅一次，避免重复失败）
                try
                {
                    await server.NotifyDidOpenAsync(filePath, content);
                    return await server.GetDiagnosticsAsync(filePath);
                }
                catch (Exception ex2)
                {
                    Debug.WriteLine($"[{language}] GetDiagnostics 重试后仍失败: {ex2.Message}");
                    return new { success = false, error = BuildLspFallbackError(language) };
                }
            }
            catch (Exception ex)
            {
                return new { success = false, error = ex.Message };
            }
        }

        /// <summary>
        /// 构建 LSP 不可用时的兜底错误信息，引导 AI 改用 execute_command 调用语言原生语法检查工具。
        /// </summary>
        private static string BuildLspFallbackError(string? language)
        {
            var hint = (language ?? "").ToLowerInvariant() switch
            {
                "php" => "php -l <file>（PHP）",
                "python" => "pyright <file>（Python）",
                "typescript" or "javascript" => "tsc --noEmit（TypeScript）",
                _ => "php -l <file>（PHP）/ pyright <file>（Python）/ tsc --noEmit（TypeScript）"
            };
            return $"LSP 服务不可用，请改用 execute_command 调用 {hint} 兜底";
        }

        public async Task<object> FindReferencesAsync(string filePath, int line, int column)
        {
            try
            {
                if (string.IsNullOrWhiteSpace(filePath) || !File.Exists(filePath))
                    return new { success = false, error = $"文件不存在: {filePath}" };

                var language = DetectLanguage(filePath);
                if (language == null)
                    return new { success = false, error = $"不支持的文件类型: {Path.GetExtension(filePath)}" };

                var workspaceRoot = DetectWorkspaceRoot(filePath);
                if (workspaceRoot == null)
                    return new { success = false, error = "无法确定工作区根目录" };

                var server = await GetServerAsync(language, workspaceRoot);
                if (server == null)
                    return new { success = false, error = $"{language} LSP 启动失败，请确认已安装对应 LSP 服务" };

                var content = await File.ReadAllTextAsync(filePath);
                await server.NotifyDidOpenAsync(filePath, content);

                return await server.FindReferencesAsync(filePath, line, column);
            }
            catch (Exception ex)
            {
                return new { success = false, error = ex.Message };
            }
        }

        public async Task<object> GotoDefinitionAsync(string filePath, int line, int column)
        {
            try
            {
                if (string.IsNullOrWhiteSpace(filePath) || !File.Exists(filePath))
                    return new { success = false, error = $"文件不存在: {filePath}" };

                var language = DetectLanguage(filePath);
                if (language == null)
                    return new { success = false, error = $"不支持的文件类型: {Path.GetExtension(filePath)}" };

                var workspaceRoot = DetectWorkspaceRoot(filePath);
                if (workspaceRoot == null)
                    return new { success = false, error = "无法确定工作区根目录" };

                var server = await GetServerAsync(language, workspaceRoot);
                if (server == null)
                    return new { success = false, error = $"{language} LSP 启动失败，请确认已安装对应 LSP 服务" };

                var content = await File.ReadAllTextAsync(filePath);
                await server.NotifyDidOpenAsync(filePath, content);

                return await server.GotoDefinitionAsync(filePath, line, column);
            }
            catch (Exception ex)
            {
                return new { success = false, error = ex.Message };
            }
        }

        /// <summary>根据文件扩展名推断语言</summary>
        public static string? DetectLanguage(string filePath)
        {
            var ext = Path.GetExtension(filePath).ToLowerInvariant();
            return ext switch
            {
                ".php" => "php",
                ".py" => "python",
                ".ts" or ".tsx" => "typescript",
                ".js" or ".jsx" or ".mjs" or ".cjs" => "javascript",
                _ => null
            };
        }

        /// <summary>推断工作区根（从文件路径向上查找 .git / composer.json / package.json / pyproject.toml）</summary>
        public static string? DetectWorkspaceRoot(string filePath)
        {
            try
            {
                var dir = Path.GetDirectoryName(filePath);
                while (!string.IsNullOrEmpty(dir))
                {
                    if (Directory.Exists(Path.Combine(dir, ".git")) ||
                        File.Exists(Path.Combine(dir, ".git")) ||
                        File.Exists(Path.Combine(dir, "composer.json")) ||
                        File.Exists(Path.Combine(dir, "package.json")) ||
                        File.Exists(Path.Combine(dir, "pyproject.toml")) ||
                        File.Exists(Path.Combine(dir, "setup.py")) ||
                        File.Exists(Path.Combine(dir, "tsconfig.json")))
                    {
                        return dir;
                    }
                    var parent = Directory.GetParent(dir);
                    if (parent == null) break;
                    dir = parent.FullName;
                }
                // 兜底：使用文件所在目录
                return Path.GetDirectoryName(filePath);
            }
            catch
            {
                return null;
            }
        }

        /// <summary>停止所有 LSP 进程（应用退出时调用）</summary>
        public void StopAll()
        {
            lock (_lock)
            {
                foreach (var s in _servers.Values)
                {
                    try { s.Stop(); } catch { }
                }
                _servers.Clear();
            }
        }
    }
}
