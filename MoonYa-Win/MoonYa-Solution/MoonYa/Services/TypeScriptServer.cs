// ┌─────────────────────────────────────────────────────────┐
// │  TypeScriptServer — tsserver LSP 适配层                 │
// │  - 启动方式：项目本地 node_modules/typescript/bin/tsserver │
// │              （其次全局 tsserver）                        │
// │  - 通信协议：tsserver 自定义协议（行分隔 JSON）          │
// │    请求：{"seq":N,"type":"request","command":"...","arguments":{...}}\n
// │    响应：{"seq":0,"type":"response","command":"...","request_seq":N,"success":true,"body":...}\n
// │    事件：{"seq":0,"type":"event","event":"syntaxDiag","body":{...}}\n
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    /// <summary>
    /// TypeScript / JavaScript tsserver 集成。
    /// tsserver 不使用 LSP Content-Length 协议，而是行分隔 JSON，
    /// 因此独立实现 ILanguageServer 接口（不复用 LspJsonRpcServerBase）。
    /// </summary>
    public class TypeScriptServer : ILanguageServer
    {
        public string Language => "typescript";

        private Process? _process;
        private string _workspaceRoot = "";
        private int _nextSeq = 1;
        private readonly object _sendLock = new();
        // seq -> 等待响应的 TaskCompletionSource
        private readonly ConcurrentDictionary<int, TaskCompletionSource<JsonElement?>> _pending = new();
        // 文件路径 -> 诊断数组（合并 syntaxDiag + semanticDiag）
        private readonly ConcurrentDictionary<string, JsonElement> _syntaxDiag = new();
        private readonly ConcurrentDictionary<string, JsonElement> _semanticDiag = new();
        // 已打开的文件路径（崩溃重启后需重新打开）
        private readonly HashSet<string> _openedFiles = new();
        private readonly object _openedFilesLock = new();

        private bool _initialized = false;
        private Task? _receiveTask;
        private CancellationTokenSource? _cts;
        // 进程代数：与 LspJsonRpcServerBase 同样的代数检查机制
        // 避免旧进程的 Exited 事件 / receive loop 在新进程启动后误清理 _pending
        private int _generation = 0;

        // ── 启动/重启 ──────────────────────────────────────────

        public async Task<bool> StartAsync(string workspaceRoot)
        {
            if (_process != null && !_process.HasExited && _initialized) return true;

            _workspaceRoot = Path.GetFullPath(workspaceRoot);
            var gen = Interlocked.Increment(ref _generation);
            var err = await StartProcessAsync(_workspaceRoot);
            if (err != null)
            {
                Debug.WriteLine($"[typescript] 启动失败: {err}");
                return false;
            }

            // 注册进程退出事件：代数检查避免误清理新进程状态
            var capturedProcess = _process;
            if (capturedProcess != null)
            {
                capturedProcess.EnableRaisingEvents = true;
                capturedProcess.Exited += (s, e) =>
                {
                    if (gen == Volatile.Read(ref _generation) && ReferenceEquals(_process, capturedProcess))
                    {
                        Debug.WriteLine($"[typescript] 进程退出事件 ExitCode={capturedProcess.ExitCode}");
                        OnProcessExited();
                    }
                };
            }

            _cts = new CancellationTokenSource();
            _receiveTask = Task.Run(() => ReceiveLoop(_cts.Token, gen));

            // 发送 configure 请求：开启诊断 + 完整分析
            await SendRequestAsync("configure", new
            {
                hostInfo = "MoonYa",
                preferences = new
                {
                    providePrefixAndSuffixTextForRename = true,
                    allowRenameOfImportPath = true
                },
                watchOptions = new { }
            });

            _initialized = true;

            // 重新打开已注册的文件（崩溃重启场景）
            List<string> filesToReopen;
            lock (_openedFilesLock) filesToReopen = _openedFiles.ToList();
            _openedFiles.Clear();
            foreach (var filePath in filesToReopen)
            {
                if (File.Exists(filePath))
                {
                    try
                    {
                        var content = await File.ReadAllTextAsync(filePath);
                        await NotifyDidOpenAsync(filePath, content);
                    }
                    catch (Exception ex)
                    {
                        Debug.WriteLine($"[typescript] 重新打开 {filePath} 失败: {ex.Message}");
                    }
                }
            }

            return true;
        }

        public async Task<bool> EnsureRunningAsync(string workspaceRoot)
        {
            if (_process != null && !_process.HasExited && _initialized)
                return true;

            if (_process != null && _process.HasExited)
            {
                Debug.WriteLine($"[typescript] 进程已退出 (ExitCode={_process.ExitCode})，重启中...");

                // 取消旧 receive loop 并等待退出，避免与新 receive loop 竞争 _pending
                try { _cts?.Cancel(); } catch { }
                if (_receiveTask != null)
                {
                    try { await Task.WhenAny(_receiveTask, Task.Delay(2000)); } catch { }
                }

                try { _process.Dispose(); } catch { }
                _process = null;
                _initialized = false;
                foreach (var kv in _pending) kv.Value.TrySetCanceled();
                _pending.Clear();
            }

            return await StartAsync(workspaceRoot);
        }

        private async Task<string?> StartProcessAsync(string workspaceRoot)
        {
            string? tsserverPath = null;
            string? nodePath = null;

            // 1. 优先：项目本地 node_modules/typescript/bin/tsserver
            var localTsserver = Path.Combine(workspaceRoot, "node_modules", "typescript", "bin", "tsserver");
            if (File.Exists(localTsserver))
            {
                tsserverPath = localTsserver;
                // tsserver 是 JS 文件，需要通过 node 启动
                nodePath = await FindNodeAsync();
                if (nodePath == null)
                {
                    return "未检测到 Node.js，请先安装 Node.js 运行时";
                }
            }

            // 2. 兜底：检测项目本地 typescript 包是否安装
            if (tsserverPath == null)
            {
                var localPkg = Path.Combine(workspaceRoot, "node_modules", "typescript", "package.json");
                if (!File.Exists(localPkg))
                {
                    return "TypeScript 未安装，请在项目根目录执行 npm install typescript";
                }
            }

            var psi = new ProcessStartInfo
            {
                UseShellExecute = false,
                RedirectStandardInput = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true,
                WorkingDirectory = workspaceRoot
            };

            if (nodePath != null && tsserverPath != null)
            {
                psi.FileName = nodePath;
                psi.Arguments = $"\"{tsserverPath}\"";
            }
            else
            {
                return "无法定位 tsserver 可执行文件";
            }

            try
            {
                _process = new Process { StartInfo = psi, EnableRaisingEvents = true };
                _process.ErrorDataReceived += (s, e) =>
                {
                    if (!string.IsNullOrEmpty(e.Data))
                        Debug.WriteLine($"[typescript] stderr: {e.Data}");
                };

                if (!_process.Start())
                {
                    return "无法启动 tsserver 进程";
                }

                _process.BeginErrorReadLine();
                // 注：EnableRaisingEvents = true + Exited 事件由 StartAsync 统一注册
                // （StartAsync 内部使用代数检查，避免旧进程 Exited 事件触发后误清理新进程状态）

                return null;
            }
            catch (Exception ex)
            {
                return $"启动 tsserver 失败: {ex.Message}";
            }
        }

        private static async Task<string?> FindNodeAsync()
        {
            // 优先 PATH 中的 node
            try
            {
                var psi = new ProcessStartInfo
                {
                    FileName = RuntimeInformation.IsOSPlatform(OSPlatform.Windows) ? "where" : "which",
                    Arguments = "node",
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    CreateNoWindow = true
                };
                using var p = Process.Start(psi);
                if (p == null) return null;
                var output = await p.StandardOutput.ReadToEndAsync();
                p.WaitForExit(3000);
                var first = output.Split(new[] { '\r', '\n' }, StringSplitOptions.RemoveEmptyEntries)
                                  .Select(s => s.Trim())
                                  .FirstOrDefault(File.Exists);
                return first;
            }
            catch { return null; }
        }

        // ── tsserver 自定义协议（行分隔 JSON） ──────────────

        private async Task ReceiveLoop(CancellationToken ct, int gen)
        {
            var reader = _process?.StandardOutput;
            if (reader == null) return;

            while (!ct.IsCancellationRequested && _process?.HasExited == false)
            {
                string? line;
                try
                {
                    line = await reader.ReadLineAsync(ct);
                }
                catch (OperationCanceledException) { return; }
                catch (Exception ex)
                {
                    Debug.WriteLine($"[typescript] ReadLine 异常: {ex.Message}");
                    return;
                }

                if (line == null)
                {
                    Debug.WriteLine("[typescript] stdout EOF, 进程已退出");
                    // 仅当代数匹配时才通知退出，避免旧 receive loop 误清理新进程状态
                    if (gen == Volatile.Read(ref _generation))
                        OnProcessExited();
                    return;
                }

                if (string.IsNullOrWhiteSpace(line)) continue;

                JsonElement root;
                try
                {
                    using var doc = JsonDocument.Parse(line);
                    root = doc.RootElement.Clone();
                }
                catch (Exception ex)
                {
                    Debug.WriteLine($"[typescript] JSON 解析失败: {ex.Message}");
                    continue;
                }

                DispatchMessage(root);
            }
        }

        private void DispatchMessage(JsonElement msg)
        {
            if (!msg.TryGetProperty("type", out var typeProp)) return;
            var type = typeProp.GetString();

            // 1. 响应
            if (type == "response")
            {
                if (msg.TryGetProperty("request_seq", out var reqSeqProp) &&
                    reqSeqProp.ValueKind == JsonValueKind.Number)
                {
                    var seq = reqSeqProp.GetInt32();
                    if (_pending.TryRemove(seq, out var tcs))
                    {
                        if (msg.TryGetProperty("success", out var successProp) &&
                            successProp.ValueKind == JsonValueKind.False)
                        {
                            // 失败响应：body 中可能含 message
                            tcs.TrySetResult(msg.Clone());
                        }
                        else if (msg.TryGetProperty("body", out var bodyProp))
                        {
                            tcs.TrySetResult(bodyProp.Clone());
                        }
                        else
                        {
                            tcs.TrySetResult(null);
                        }
                    }
                }
                return;
            }

            // 2. 事件
            if (type == "event")
            {
                if (msg.TryGetProperty("event", out var eventProp))
                {
                    var eventName = eventProp.GetString();
                    if (eventName == "syntaxDiag")
                    {
                        HandleDiagEvent(msg, _syntaxDiag);
                    }
                    else if (eventName == "semanticDiag")
                    {
                        HandleDiagEvent(msg, _semanticDiag);
                    }
                    // 其他事件（requestStarted / requestCompleted 等）忽略
                }
            }
        }

        private void HandleDiagEvent(JsonElement msg, ConcurrentDictionary<string, JsonElement> store)
        {
            if (!msg.TryGetProperty("body", out var body)) return;
            if (!body.TryGetProperty("file", out var fileProp)) return;
            var file = fileProp.GetString();
            if (string.IsNullOrEmpty(file)) return;

            if (body.TryGetProperty("diagnostics", out var diagProp))
                store[file] = diagProp.Clone();
            else
            {
                using var empty = JsonDocument.Parse("[]");
                store[file] = empty.RootElement.Clone();
            }
        }

        protected virtual void OnProcessExited()
        {
            foreach (var kv in _pending) kv.Value.TrySetResult(null);
            _pending.Clear();
        }

        private async Task<JsonElement?> SendRequestAsync(string command, object? arguments, int timeoutMs = 15000)
        {
            if (_process == null || _process.HasExited) return null;

            var seq = Interlocked.Increment(ref _nextSeq);
            var tcs = new TaskCompletionSource<JsonElement?>(TaskCreationOptions.RunContinuationsAsynchronously);
            _pending[seq] = tcs;

            var msg = new Dictionary<string, object?>
            {
                ["seq"] = seq,
                ["type"] = "request",
                ["command"] = command
            };
            if (arguments != null) msg["arguments"] = arguments;

            try
            {
                await SendRawAsync(msg);
            }
            catch (Exception ex)
            {
                _pending.TryRemove(seq, out _);
                Debug.WriteLine($"[typescript] SendRequest {command} 发送失败: {ex.Message}");
                return null;
            }

            using var cts = new CancellationTokenSource(timeoutMs);
            cts.Token.Register(() =>
            {
                if (_pending.TryRemove(seq, out var t))
                    t.TrySetCanceled();
            });

            try
            {
                return await tcs.Task;
            }
            catch (OperationCanceledException)
            {
                Debug.WriteLine($"[typescript] SendRequest {command} 超时");
                return null;
            }
        }

        private Task SendRawAsync(object msg)
        {
            if (_process == null) return Task.CompletedTask;
            var json = JsonSerializer.Serialize(msg);
            var line = json + "\n";
            var bytes = Encoding.UTF8.GetBytes(line);

            lock (_sendLock)
            {
                try
                {
                    var stream = _process.StandardInput.BaseStream;
                    stream.Write(bytes, 0, bytes.Length);
                    stream.Flush();
                }
                catch (Exception ex)
                {
                    Debug.WriteLine($"[typescript] 写入 stdin 失败: {ex.Message}");
                    throw;
                }
            }
            return Task.CompletedTask;
        }

        // ── 文档同步（tsserver 协议：open / close / change）──

        public Task NotifyDidOpenAsync(string filePath, string content)
        {
            // tsserver 文件路径使用本地路径（Windows 反斜杠会被解析器接受，但正斜杠更兼容）
            var normalizedPath = filePath.Replace('\\', '/');

            bool alreadyOpen;
            lock (_openedFilesLock) alreadyOpen = _openedFiles.Contains(normalizedPath);

            if (alreadyOpen)
            {
                // 已打开，发送 change 更新（version 必须递增）
                return SendRawAsync(new
                {
                    seq = Interlocked.Increment(ref _nextSeq),
                    type = "request",
                    command = "change",
                    arguments = new
                    {
                        file = normalizedPath,
                        line = 1,
                        offset = 1,
                        endLine = 1000000,
                        endOffset = 1,
                        insertString = content
                    }
                });
            }

            lock (_openedFilesLock) _openedFiles.Add(normalizedPath);

            return SendRawAsync(new
            {
                seq = Interlocked.Increment(ref _nextSeq),
                type = "request",
                command = "open",
                arguments = new
                {
                    file = normalizedPath,
                    fileContent = content,
                    scriptKind = GetScriptKind(filePath)
                }
            });
        }

        public Task NotifyDidChangeAsync(string filePath, string content)
        {
            // tsserver 没有完整 didChange，使用 change + 全量替换
            return NotifyDidOpenAsync(filePath, content);
        }

        public Task NotifyDidCloseAsync(string filePath)
        {
            var normalizedPath = filePath.Replace('\\', '/');
            lock (_openedFilesLock) _openedFiles.Remove(normalizedPath);

            return SendRawAsync(new
            {
                seq = Interlocked.Increment(ref _nextSeq),
                type = "request",
                command = "close",
                arguments = new { file = normalizedPath }
            });
        }

        private static string GetScriptKind(string filePath)
        {
            var ext = Path.GetExtension(filePath).ToLowerInvariant();
            return ext switch
            {
                ".ts" => "TS",
                ".tsx" => "TSX",
                ".js" => "JS",
                ".jsx" => "JSX",
                _ => "TS"
            };
        }

        // ── LSP 高层 API（适配 tsserver 命令） ────────────────

        public async Task<object> GetDiagnosticsAsync(string filePath)
        {
            var normalizedPath = filePath.Replace('\\', '/');

            // 触发 geterr 让 tsserver 推送 syntaxDiag / semanticDiag 事件
            await SendRequestAsync("geterr", new { files = new[] { normalizedPath } });

            // 等待诊断推送（tsserver 异步推送 syntaxDiag + semanticDiag）
            await Task.Delay(1000);

            // 合并 syntax + semantic 诊断
            var combined = new List<JsonElement>();
            if (_syntaxDiag.TryGetValue(normalizedPath, out var syn))
                combined.Add(syn);
            if (_semanticDiag.TryGetValue(normalizedPath, out var sem))
                combined.Add(sem);

            // 合并两个诊断数组为单个 JSON
            var allDiags = new List<JsonElement>();
            foreach (var arr in combined)
            {
                if (arr.ValueKind == JsonValueKind.Array)
                {
                    foreach (var item in arr.EnumerateArray())
                        allDiags.Add(item.Clone());
                }
            }

            var json = JsonSerializer.Serialize(allDiags);
            using var doc = JsonDocument.Parse(json);
            return new { success = true, file = normalizedPath, diagnostics = doc.RootElement.Clone() };
        }

        public async Task<object> FindReferencesAsync(string filePath, int line, int column)
        {
            var normalizedPath = filePath.Replace('\\', '/');

            // tsserver position：line 1-based，offset 1-based
            var body = await SendRequestAsync("references", new
            {
                file = normalizedPath,
                line = line + 1,
                offset = column + 1
            });

            if (body == null)
                return new { success = false, error = "tsserver 请求失败或超时" };

            return new { success = true, references = body };
        }

        public async Task<object> GotoDefinitionAsync(string filePath, int line, int column)
        {
            var normalizedPath = filePath.Replace('\\', '/');

            // tsserver definition 命令：返回 fileSpans 数组
            var body = await SendRequestAsync("definition", new
            {
                file = normalizedPath,
                line = line + 1,
                offset = column + 1
            });

            if (body == null)
                return new { success = false, error = "tsserver 请求失败或超时" };

            return new { success = true, definition = body };
        }

        public void Stop()
        {
            try { _cts?.Cancel(); } catch { }
            try
            {
                if (_process != null && !_process.HasExited)
                {
                    try
                    {
                        SendRawAsync(new
                        {
                            seq = Interlocked.Increment(ref _nextSeq),
                            type = "request",
                            command = "exit"
                        }).Wait(TimeSpan.FromSeconds(2));
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
    }
}
