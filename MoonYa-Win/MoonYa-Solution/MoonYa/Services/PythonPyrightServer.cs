// ┌─────────────────────────────────────────────────────────┐
// │  PythonPyrightServer — Python Pyright LSP 集成           │
// │  - 启动方式：pyright-langserver --stdio                  │
// │  - 检测方式：where pyright-langserver (Win) /            │
// │              which pyright-langserver (Linux)             │
// │  - 通信协议：LSP Content-Length JSON-RPC（继承基类）      │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Diagnostics;
using System.IO;
using System.Runtime.InteropServices;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    /// <summary>
    /// Python Pyright LSP 实现。
    /// 通过全局 pip 安装 pyright 后启动 pyright-langserver，stdio 双向通信。
    /// </summary>
    public class PythonPyrightServer : LspJsonRpcServerBase
    {
        public override string Language => "python";

        /// <summary>
        /// 启动 pyright-langserver 子进程。
        /// 步骤：
        ///   1. 在 PATH 中查找 pyright-langserver（Windows: where / Linux: which）
        ///   2. 启动 pyright-langserver --stdio
        /// 返回 null 表示成功；返回字符串表示错误信息。
        /// </summary>
        protected override async Task<string?> StartProcessAsync(string workspaceRoot)
        {
            // 1. 检测 pyright-langserver 是否在 PATH
            var isWindows = RuntimeInformation.IsOSPlatform(OSPlatform.Windows);
            var whichCmd = isWindows ? "where" : "which";
            var lookup = await RunShellAsync(whichCmd, "pyright-langserver");

            string? exePath = null;
            if (!string.IsNullOrWhiteSpace(lookup))
            {
                // where 可能返回多行，取第一个非空行
                foreach (var line in lookup.Split(new[] { '\r', '\n' }, StringSplitOptions.RemoveEmptyEntries))
                {
                    var trimmed = line.Trim();
                    if (File.Exists(trimmed))
                    {
                        exePath = trimmed;
                        break;
                    }
                }
            }

            if (string.IsNullOrEmpty(exePath))
            {
                return "Python LSP 未安装，请先执行 pip install pyright";
            }

            // 2. 启动 pyright-langserver --stdio
            var psi = new ProcessStartInfo
            {
                FileName = exePath,
                Arguments = "--stdio",
                UseShellExecute = false,
                RedirectStandardInput = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true,
                WorkingDirectory = workspaceRoot
            };

            try
            {
                _process = new Process { StartInfo = psi, EnableRaisingEvents = true };
                _process.ErrorDataReceived += (s, e) =>
                {
                    if (!string.IsNullOrEmpty(e.Data))
                        Debug.WriteLine($"[python] stderr: {e.Data}");
                };

                if (!_process.Start())
                {
                    return "无法启动 pyright-langserver 进程";
                }

                _process.BeginErrorReadLine();
                // 注：EnableRaisingEvents = true + Exited 事件由基类 StartAsync 统一注册
                // （基类内部使用代数检查，避免旧进程 Exited 事件触发后误清理新进程状态）

                return null;
            }
            catch (Exception ex)
            {
                return $"启动 pyright-langserver 失败: {ex.Message}";
            }
        }
    }
}
