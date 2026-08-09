// ┌─────────────────────────────────────────────────────────┐
// │  PhpIntelephenseServer — PHP Intelephense LSP 集成       │
// │  - 启动方式：node intelephense.js --stdio                │
// │  - 检测方式：npm root -g + 文件存在性检查                 │
// │  - 通信协议：LSP Content-Length JSON-RPC（继承基类）      │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Diagnostics;
using System.IO;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    /// <summary>
    /// PHP Intelephense LSP 实现。
    /// 通过全局 npm 安装 intelephense 后以 node 进程方式启动，stdio 双向通信。
    /// </summary>
    public class PhpIntelephenseServer : LspJsonRpcServerBase
    {
        public override string Language => "php";

        /// <summary>
        /// 启动 Intelephense 子进程。
        /// 步骤：
        ///   1. 执行 `npm root -g` 获取全局 node_modules 路径
        ///   2. 拼接 intelephense/intelephense.js 检查文件存在
        ///   3. 启动 node intelephense.js --stdio
        /// 返回 null 表示成功；返回字符串表示错误信息。
        /// </summary>
        protected override async Task<string?> StartProcessAsync(string workspaceRoot)
        {
            // 1. 检测 node 是否安装
            var nodeCheck = await RunShellAsync("node", "--version");
            if (string.IsNullOrWhiteSpace(nodeCheck))
            {
                return "未检测到 Node.js，请先安装 Node.js 运行时";
            }

            // 2. 获取全局 node_modules 路径
            var npmRoot = await RunShellAsync("npm", "root -g");
            if (string.IsNullOrWhiteSpace(npmRoot))
            {
                return "无法执行 npm root -g，请确认 npm 在 PATH 中";
            }

            npmRoot = npmRoot.Trim();
            var intelephensePath = Path.Combine(npmRoot, "intelephense", "intelephense.js");
            if (!File.Exists(intelephensePath))
            {
                return "PHP LSP 未安装，请先执行 npm install -g intelephense";
            }

            // 3. 启动 node 进程（--stdio 标志表示使用标准输入输出通信）
            var psi = new ProcessStartInfo
            {
                FileName = "node",
                Arguments = $"\"{intelephensePath}\" --stdio",
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
                        Debug.WriteLine($"[php] stderr: {e.Data}");
                };

                if (!_process.Start())
                {
                    return "无法启动 node 进程";
                }

                _process.BeginErrorReadLine();
                // 注：EnableRaisingEvents = true + Exited 事件由基类 StartAsync 统一注册
                // （基类内部使用代数检查，避免旧进程 Exited 事件触发后误清理新进程状态）

                return null;  // 成功
            }
            catch (Exception ex)
            {
                return $"启动 node 失败: {ex.Message}";
            }
        }
    }
}
