using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;
using System.Text.Json.Serialization;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Threading;

namespace MoonYa.Services
{
    public class ExecutionResult
    {
        public string Status { get; set; } = "";     // "success", "error", "rejected"
        public string Output { get; set; } = "";      // combined stdout
        public string Error { get; set; } = "";       // stderr or error message
        public int ExitCode { get; set; }
        public long DurationMs { get; set; }
        public string RiskLevel { get; set; } = "";   // "low", "medium", "high"
        public List<string> MatchedRules { get; set; } = new();
        public string FullCommand { get; set; } = "";  // the actual command that was/would be executed
        public string ErrorCode { get; set; } = "";    // stable machine-readable error code
        public string Stage { get; set; } = "execute";
        public string Shell { get; set; } = "";
        public List<CommandDiagnostic> Diagnostics { get; set; } = new();
        public string CommandFingerprint { get; set; } = "";
        public object? OperationReceipt { get; set; }
        public List<object> AssertionResults { get; set; } = new();
    }

    public class ShellSuccessCriteria
    {
        [JsonPropertyName("expected_exit_code")]
        public int? ExpectedExitCode { get; set; }
        [JsonPropertyName("stderr_empty")]
        public bool? StderrEmpty { get; set; }
        [JsonPropertyName("stdout_contains")]
        public List<string>? StdoutContains { get; set; }
        [JsonPropertyName("stdout_regex")]
        public List<string>? StdoutRegex { get; set; }
        [JsonPropertyName("stderr_contains")]
        public List<string>? StderrContains { get; set; }
        [JsonPropertyName("stderr_not_contains")]
        public List<string>? StderrNotContains { get; set; }

        public bool HasMachineOutputAssertion =>
            (StdoutContains?.Count ?? 0) > 0 ||
            (StdoutRegex?.Count ?? 0) > 0 ||
            (StderrContains?.Count ?? 0) > 0 ||
            (StderrNotContains?.Count ?? 0) > 0;
    }

    public class CommandExecutionService
    {
        private const int MaxOutputBytes = 1048576; // 1MB
        private const int BackgroundOutputMaxBytes = 100 * 1024; // 100KB - 后台命令输出缓冲区上限
        private static readonly TimeSpan BackgroundCleanupInterval = TimeSpan.FromMinutes(5); // 后台命令完成后 5 分钟自动清理

        private readonly RiskAssessmentService _riskService;
        private readonly SandboxService _sandboxService;
        private readonly CommandPreflightService _preflightService;
        private readonly int _commandTimeoutSec;

        // 后台命令进程池：command_id → BackgroundCommand
        private readonly Dictionary<string, BackgroundCommand> _backgroundCommands = new();
        private readonly object _bgLock = new();

        public CommandExecutionService(RiskAssessmentService riskService, SandboxService sandboxService, int commandTimeoutSec = 60)
        {
            _riskService = riskService;
            _sandboxService = sandboxService;
            _preflightService = new CommandPreflightService();
            _commandTimeoutSec = commandTimeoutSec;
        }

        // 后台命令上下文：保存进程、输出缓冲区、状态及清理定时器
        private class BackgroundCommand
        {
            public string CommandId { get; set; } = "";
            public Process? Process { get; set; }
            public StringBuilder StdoutBuffer { get; set; } = new();
            public StringBuilder StderrBuffer { get; set; } = new();
            public long OutputRevision { get; set; }
            public DateTime StartedAt { get; set; }
            public DateTime? FinishedAt { get; set; }
            public int? ExitCode { get; set; }
            public string Status { get; set; } = "running";  // running / done / killed
            public Timer? CleanupTimer { get; set; }  // 完成后 5 分钟自动清理
            public string? SandboxDir { get; set; }  // 后台命令独占的沙箱目录，进程退出后清理
            public string CompletionMode { get; set; } = "finite";
            public string Phase { get; set; } = "inspect";
            public string OperationId { get; set; } = "";
            public string Intent { get; set; } = "";
            public string CommandFingerprint { get; set; } = "";
            public ShellSuccessCriteria? SuccessCriteria { get; set; }
            public List<object> AssertionResults { get; set; } = new();
            public bool? CommandOk { get; set; }
        }

        /// <summary>
        /// 主入口：根据 blocking 参数分发到同步或异步执行路径。
        /// blocking=null/true → 走原同步逻辑（风险评估 → 确认 → 沙箱执行），返回 ExecutionResult
        /// blocking=false     → 调用 ExecuteAsyncBackground 立即返回 command_id，不等待完成
        /// 返回类型为 object 以兼容两种模式（ExecutionResult 或后台命令的 {success, command_id, status} 对象）
        /// </summary>
        public Task<CommandPreflightResult> ValidateCommandAsync(string command, string? shell = "auto", string? phase = null) =>
            _preflightService.ValidateAsync(command, shell, phase);

        public async Task<object> ExecuteAsync(string command, bool autoApproveMediumRisk = true, string? cwd = null, int? timeoutSec = null, bool? blocking = null, string? shell = "auto", string? phase = null, string? operationId = null, string? intent = null, ShellSuccessCriteria? successCriteria = null, bool approvalGranted = false, string? completionMode = null)
        {
            var preflight = await _preflightService.ValidateAsync(command, shell, phase);
            if (!preflight.Valid)
            {
                return FromPreflightFailure(command, preflight, operationId, phase);
            }

            if (phase == "verify" && successCriteria?.HasMachineOutputAssertion != true)
            {
                return new ExecutionResult
                {
                    Status = "error",
                    Stage = "preflight",
                    ErrorCode = "shell_verify_assertion_required",
                    Error = "verify 阶段至少需要一个 stdout/stderr 的机器可判定断言，不能只检查退出码。",
                    FullCommand = command,
                    Shell = preflight.Shell,
                    RiskLevel = preflight.RiskLevel,
                    CommandFingerprint = preflight.CommandFingerprint,
                    OperationReceipt = Receipt(operationId, phase, "action_succeeded_unverified", preflight.CommandFingerprint)
                };
            }

            if (blocking == false && completionMode != "finite" && completionMode != "persistent")
            {
                return new ExecutionResult
                {
                    Status = "error",
                    Stage = "preflight",
                    ErrorCode = "completion_mode_required",
                    Error = "blocking=false 时必须显式指定 completion_mode=finite 或 persistent。",
                    FullCommand = command,
                    Shell = preflight.Shell,
                    RiskLevel = preflight.RiskLevel,
                    CommandFingerprint = preflight.CommandFingerprint,
                    OperationReceipt = Receipt(operationId, phase, "not_started", preflight.CommandFingerprint)
                };
            }
            if (blocking == false && completionMode == "persistent" && phase != "act")
            {
                return new ExecutionResult
                {
                    Status = "error",
                    Stage = "preflight",
                    ErrorCode = "persistent_phase_forbidden",
                    Error = "completion_mode=persistent 只允许用于 act 阶段。",
                    FullCommand = command,
                    Shell = preflight.Shell,
                    RiskLevel = preflight.RiskLevel,
                    CommandFingerprint = preflight.CommandFingerprint,
                    OperationReceipt = Receipt(operationId, phase, "not_started", preflight.CommandFingerprint)
                };
            }

            // 1. Pre-check: basic command syntax validation (quote matching)
            // 2. Risk assessment
            var riskResult = _riskService.AssessCommand(preflight.NormalizedCommand);

            // Managed background Acts are fail-closed. Only the backend gateway
            // may set this bit after preflight, policy approval and idempotency.
            if (blocking == false && phase == "act" && !approvalGranted)
            {
                return new ExecutionResult
                {
                    Status = "error",
                    Stage = "approval",
                    ErrorCode = "background_act_not_authorized",
                    Error = "后台 act 缺少可信 approval_granted 回执。",
                    FullCommand = command,
                    Shell = preflight.Shell,
                    RiskLevel = preflight.RiskLevel,
                    CommandFingerprint = preflight.CommandFingerprint,
                    OperationReceipt = Receipt(operationId, phase, "not_started", preflight.CommandFingerprint, intent)
                };
            }

            // 3. User confirmation: High risk always confirms; Medium risk only if not auto-approved
            var needsConfirmation = !approvalGranted && (riskResult.Level == RiskLevel.High ||
                (riskResult.Level == RiskLevel.Medium && !autoApproveMediumRisk));
            if (needsConfirmation)
            {
                var confirmed = await ShowConfirmationDialog(preflight.NormalizedCommand, riskResult);
                if (!confirmed)
                {
                    return new ExecutionResult
                    {
                        Status = "rejected",
                        Output = "",
                        Error = "User rejected the command execution.\n建议：该命令被判定为高风险，请简化命令或拆分为低风险子步骤",
                        FullCommand = command,
                        RiskLevel = riskResult.Level.ToString().ToLower(),
                        MatchedRules = riskResult.MatchedRules
                    };
                }
            }

            // Background inspect/act/verify all use the same preflight and risk
            // path. Their final assertions are evaluated by the status service.
            if (blocking == false)
            {
                return await ExecuteAsyncBackground(
                    preflight.NormalizedCommand,
                    cwd,
                    timeoutSec,
                    preflight.Shell,
                    completionMode!,
                    phase ?? "inspect",
                    operationId,
                    intent,
                    preflight.CommandFingerprint,
                    successCriteria);
            }

            // 4. Execute in sandbox
            return await ExecuteInSandboxAsync(preflight.NormalizedCommand, preflight, riskResult, cwd, timeoutSec, operationId, phase, intent, successCriteria);
        }

        /// <summary>
        /// 异步启动后台命令：生成 command_id、启动进程、注册输出缓冲与退出回调，立即返回。
        /// 不等待进程完成，长运行命令（如 dev server）通过此方法启动后由 get_command_status 查询状态。
        /// </summary>
        public async Task<object> ExecuteAsyncBackground(
            string command,
            string? cwd,
            int? timeoutSec,
            string? resolvedShell = null,
            string completionMode = "finite",
            string phase = "inspect",
            string? operationId = null,
            string? intent = null,
            string commandFingerprint = "",
            ShellSuccessCriteria? successCriteria = null)
        {
            // 1. 语法校验
            var syntaxError = ValidateCommandSyntax(command);
            if (syntaxError != null)
            {
                return new { success = false, error = $"Command syntax error: {syntaxError}" };
            }

            string? sandboxDir = null;
            try
            {
                // 2. 构造 startInfo（复用 ExecuteInSandboxAsync 的 startInfo 构造逻辑，但不等待完成）
                sandboxDir = _sandboxService.CreateSandboxDirectory();
                var workingDir = (!string.IsNullOrEmpty(cwd) && Directory.Exists(cwd)) ? cwd : sandboxDir;

                bool isPowerShell = string.Equals(resolvedShell, "powershell", StringComparison.OrdinalIgnoreCase);

                string shell, scriptPath, arguments;
                if (isPowerShell)
                {
                    shell = "powershell.exe";
                    scriptPath = Path.Combine(sandboxDir, "script.ps1");
                    await File.WriteAllTextAsync(scriptPath, command, new UTF8Encoding(true));
                    arguments = PowerShellRunnerArguments(scriptPath);
                }
                else
                {
                    shell = "cmd.exe";
                    scriptPath = Path.Combine(sandboxDir, "script.bat");
                    await File.WriteAllTextAsync(scriptPath, "@chcp 65001>nul\r\n" + command + "\r\n", new UTF8Encoding(false));
                    arguments = $"/c \"{scriptPath}\"";
                }

                var startInfo = _sandboxService.CreateSandboxedProcessStartInfo(shell, arguments, workingDir);
                ConfigureUtf8(startInfo);

                // 3. 生成 command_id（12 位十六进制）
                var commandId = Guid.NewGuid().ToString("N").Substring(0, 12);

                // 4. 创建 BackgroundCommand 上下文
                var bgCmd = new BackgroundCommand
                {
                    CommandId = commandId,
                    StartedAt = DateTime.Now,
                    Status = "running",
                    SandboxDir = sandboxDir,
                    CompletionMode = completionMode,
                    Phase = phase,
                    OperationId = operationId ?? "",
                    Intent = intent ?? "",
                    CommandFingerprint = commandFingerprint,
                    SuccessCriteria = successCriteria
                };

                // 5. 启动进程（EnableRaisingEvents 用于订阅 Exited 事件）
                var process = new Process { StartInfo = startInfo, EnableRaisingEvents = true };
                bgCmd.Process = process;

                // stdout / stderr are kept separate and revisioned so callers
                // can render incremental progress without conflating channels.
                process.OutputDataReceived += (_, e) =>
                {
                    if (e.Data != null)
                    {
                        lock (_bgLock)
                        {
                            AppendOutput(bgCmd.StdoutBuffer, e.Data);
                            bgCmd.OutputRevision++;
                        }
                    }
                };
                process.ErrorDataReceived += (_, e) =>
                {
                    if (e.Data != null)
                    {
                        lock (_bgLock)
                        {
                            AppendOutput(bgCmd.StderrBuffer, e.Data);
                            bgCmd.OutputRevision++;
                        }
                    }
                };
                // 进程退出：标记完成状态、记录 ExitCode、启动 5 分钟清理定时器
                process.Exited += (_, _) =>
                {
                    try
                    {
                        // Drain asynchronous stdout/stderr callbacks before
                        // assertions and the final output revision are frozen.
                        try { process.WaitForExit(); } catch { }
                        lock (_bgLock)
                        {
                            // 仅当未被停止（killed）时才标记为 done
                            if (bgCmd.Status == "running")
                            {
                                bgCmd.Status = "done";
                            }
                            bgCmd.FinishedAt = DateTime.Now;
                            try { bgCmd.ExitCode = process.ExitCode; } catch { bgCmd.ExitCode = -1; }
                            var stdout = bgCmd.StdoutBuffer.ToString();
                            var stderr = string.Equals(resolvedShell, "powershell", StringComparison.OrdinalIgnoreCase)
                                ? NormalizePowerShellStderr(bgCmd.StderrBuffer.ToString())
                                : bgCmd.StderrBuffer.ToString();
                            bgCmd.AssertionResults = EvaluateSuccessCriteria(bgCmd.ExitCode ?? -1, stdout, stderr, bgCmd.SuccessCriteria);
                            var assertionsPassed = bgCmd.AssertionResults.All(AssertionPassed);
                            bgCmd.CommandOk = bgCmd.CompletionMode == "persistent" ? false : assertionsPassed;

                            // 启动 5 分钟自动清理定时器
                            bgCmd.CleanupTimer?.Dispose();
                            bgCmd.CleanupTimer = new Timer(_ =>
                            {
                                lock (_bgLock)
                                {
                                    try { bgCmd.Process?.Dispose(); } catch { }
                                    if (bgCmd.SandboxDir != null)
                                    {
                                        _sandboxService.CleanupSandbox(bgCmd.SandboxDir);
                                    }
                                    _backgroundCommands.Remove(commandId);
                                }
                            }, null, BackgroundCleanupInterval, Timeout.InfiniteTimeSpan);
                        }
                    }
                    catch (Exception ex)
                    {
                        System.Diagnostics.Debug.WriteLine($"ExecuteAsyncBackground: Exited handler error: {ex.Message}");
                    }
                };

                process.Start();
                // 立即开始异步读取输出，避免 4KB 管道缓冲区被填满导致进程阻塞
                process.BeginOutputReadLine();
                process.BeginErrorReadLine();
                // 关闭 stdin 发送 EOF，防止等待输入的脚本无限挂起
                try { process.StandardInput.Close(); } catch { }

                // 注：后台长运行命令不应用 CPU/内存限制（Job Object），否则 dev server 可能被误杀。
                // 进程退出时通过 Exited 事件清理沙箱目录。

                // 6. 加入进程池
                lock (_bgLock)
                {
                    _backgroundCommands[commandId] = bgCmd;
                }

                // 7. 立即返回 command_id 和 running 状态
                return new
                {
                    success = true,
                    command_id = commandId,
                    status = "running",
                    command_ok = (bool?)null,
                    stdout = "",
                    stderr = "",
                    output_revision = 0,
                    completion_mode = completionMode,
                    operation_receipt = Receipt(operationId, phase, RunningState(phase), commandFingerprint, intent),
                    started_at = bgCmd.StartedAt.ToString("yyyy-MM-ddTHH:mm:sszzz")
                };
            }
            catch (Exception ex)
            {
                // 启动失败：清理已创建的沙箱目录
                if (sandboxDir != null)
                {
                    try { _sandboxService.CleanupSandbox(sandboxDir); } catch { }
                }
                // 不自动重试（避免重复启动失败，如端口冲突、权限不足、文件不存在），
                // 返回结构化错误 + suggestion 让 AI 决策下一步
                return new
                {
                    success = false,
                    error = $"启动后台命令失败: {ex.Message}",
                    suggestion = "请改用 blocking=true 同步模式重试，或检查命令语法和路径"
                };
            }
        }

        /// <summary>
        /// 查询后台命令状态：返回 running/done/killed、exit_code、当前输出缓冲区内容。
        /// 命令不存在或已被 5 分钟清理定时器回收时返回错误。
        /// </summary>
        public Task<object> GetCommandStatusAsync(string commandId)
        {
            lock (_bgLock)
            {
                if (string.IsNullOrEmpty(commandId) ||
                    !_backgroundCommands.TryGetValue(commandId, out var bgCmd))
                {
                    return Task.FromResult<object>(new { success = false, error = "命令不存在或已被清理" });
                }

                return Task.FromResult<object>(new
                {
                    success = true,
                    command_id = bgCmd.CommandId,
                    status = bgCmd.Status,
                    command_ok = bgCmd.CommandOk,
                    exit_code = bgCmd.ExitCode,
                    output = bgCmd.StdoutBuffer.ToString() + bgCmd.StderrBuffer.ToString(),
                    stdout = bgCmd.StdoutBuffer.ToString(),
                    stderr = bgCmd.StderrBuffer.ToString(),
                    output_revision = bgCmd.OutputRevision,
                    completion_mode = bgCmd.CompletionMode,
                    assertion_results = bgCmd.AssertionResults,
                    operation_receipt = Receipt(
                        bgCmd.OperationId,
                        bgCmd.Phase,
                        BackgroundReceiptState(bgCmd),
                        bgCmd.CommandFingerprint,
                        bgCmd.Intent),
                    started_at = bgCmd.StartedAt.ToString("yyyy-MM-ddTHH:mm:sszzz"),
                    finished_at = bgCmd.FinishedAt?.ToString("yyyy-MM-ddTHH:mm:sszzz")
                });
            }
        }

        /// <summary>
        /// 停止后台命令：调用 Process.Kill(entireProcessTree: true) 终止整个进程树，
        /// 标记 Status=killed，启动 5 分钟清理定时器回收资源。
        /// </summary>
        public Task<object> StopCommandAsync(string commandId)
        {
            try
            {
                lock (_bgLock)
                {
                    if (string.IsNullOrEmpty(commandId) ||
                        !_backgroundCommands.TryGetValue(commandId, out var bgCmd))
                    {
                        return Task.FromResult<object>(new { success = false, error = "命令不存在或已被清理" });
                    }

                    // 终止整个进程树（含子进程，如 npm run dev 启动的 vite 子进程）
                    if (bgCmd.Process != null && !bgCmd.Process.HasExited)
                    {
                        try
                        {
                            bgCmd.Process.Kill(entireProcessTree: true);
                        }
                        catch (Exception ex)
                        {
                            System.Diagnostics.Debug.WriteLine($"StopCommandAsync: Kill failed: {ex.Message}");
                        }
                    }

                    bgCmd.Status = "killed";
                    bgCmd.FinishedAt = DateTime.Now;
                    bgCmd.ExitCode = -1;
                    bgCmd.CommandOk = false;

                    // 启动 5 分钟自动清理定时器
                    bgCmd.CleanupTimer?.Dispose();
                    bgCmd.CleanupTimer = new Timer(_ =>
                    {
                        lock (_bgLock)
                        {
                            try { bgCmd.Process?.Dispose(); } catch { }
                            if (bgCmd.SandboxDir != null)
                            {
                                _sandboxService.CleanupSandbox(bgCmd.SandboxDir);
                            }
                            _backgroundCommands.Remove(commandId);
                        }
                    }, null, BackgroundCleanupInterval, Timeout.InfiniteTimeSpan);

                    return Task.FromResult<object>(new
                    {
                        success = true,
                        command_id = commandId,
                        status = "killed",
                        command_ok = false,
                        exit_code = -1,
                        stdout = bgCmd.StdoutBuffer.ToString(),
                        stderr = bgCmd.StderrBuffer.ToString(),
                        output_revision = bgCmd.OutputRevision,
                        completion_mode = bgCmd.CompletionMode,
                        operation_receipt = Receipt(
                            bgCmd.OperationId,
                            bgCmd.Phase,
                            FailureState(bgCmd.Phase),
                            bgCmd.CommandFingerprint,
                            bgCmd.Intent)
                    });
                }
            }
            catch (Exception ex)
            {
                return Task.FromResult<object>(new { success = false, error = $"停止命令失败: {ex.Message}" });
            }
        }

        /// <summary>
        /// 追加输出到缓冲区，超过 100KB 时循环覆盖旧内容（保留最新 100KB）。
        /// 调用方需持有 _bgLock 以保证线程安全。
        /// </summary>
        private static void AppendOutput(StringBuilder buffer, string data)
        {
            buffer.AppendLine(data);
            if (buffer.Length > BackgroundOutputMaxBytes)
            {
                // 保留最新的 100KB：从头删除超出部分
                var excess = buffer.Length - BackgroundOutputMaxBytes;
                buffer.Remove(0, excess);
            }
        }

        private async Task<ExecutionResult> ExecuteInSandboxAsync(
            string command,
            CommandPreflightResult preflight,
            RiskAssessmentResult riskResult,
            string? cwd,
            int? timeoutSec,
            string? operationId,
            string? phase,
            string? intent,
            ShellSuccessCriteria? successCriteria)
        {
            var sandboxDir = _sandboxService.CreateSandboxDirectory();
            var workingDir = (!string.IsNullOrEmpty(cwd) && Directory.Exists(cwd)) ? cwd : sandboxDir;
            // Managed Work calls (identified by an explicit phase) have no
            // implicit total deadline. Legacy calls retain the configured cap.
            var effectiveTimeout = timeoutSec ?? (phase != null ? 0 : _commandTimeoutSec);
            var stopwatch = Stopwatch.StartNew();

            try
            {
                bool isPowerShell = preflight.Shell == "powershell";

                string shell, scriptPath, arguments;
                if (isPowerShell)
                {
                    shell = "powershell.exe";
                    scriptPath = Path.Combine(sandboxDir, "script.ps1");
                    await File.WriteAllTextAsync(scriptPath, command, new UTF8Encoding(true));
                    arguments = PowerShellRunnerArguments(scriptPath);
                }
                else
                {
                    shell = "cmd.exe";
                    scriptPath = Path.Combine(sandboxDir, "script.bat");
                    await File.WriteAllTextAsync(scriptPath, "@chcp 65001>nul\r\n" + command + "\r\n", new UTF8Encoding(false));
                    arguments = $"/c \"{scriptPath}\"";
                }

                var startInfo = _sandboxService.CreateSandboxedProcessStartInfo(shell, arguments, workingDir);
                ConfigureUtf8(startInfo);

                var outputTruncated = false;
                var errorTruncated = false;
                using var process = new Process { StartInfo = startInfo };
                var outputBuilder = new StringBuilder();
                var errorBuilder = new StringBuilder();

                process.OutputDataReceived += (_, e) =>
                {
                    if (e.Data != null && !outputTruncated)
                    {
                        if (outputBuilder.Length + e.Data.Length > MaxOutputBytes)
                        {
                            outputBuilder.AppendLine("\n[输出已截断，超过 1MB 上限]");
                            outputTruncated = true;
                        }
                        else
                        {
                            outputBuilder.AppendLine(e.Data);
                        }
                    }
                };
                process.ErrorDataReceived += (_, e) =>
                {
                    if (e.Data != null && !errorTruncated)
                    {
                        if (errorBuilder.Length + e.Data.Length > MaxOutputBytes)
                        {
                            errorBuilder.AppendLine("\n[输出已截断，超过 1MB 上限]");
                            errorTruncated = true;
                        }
                        else
                        {
                            errorBuilder.AppendLine(e.Data);
                        }
                    }
                };

                process.Start();

                // Start async output reading IMMEDIATELY to drain the pipe buffer.
                // Must happen BEFORE ApplyResourceLimits to avoid a window where the
                // process fills the 4KB pipe buffer and blocks permanently.
                process.BeginOutputReadLine();
                process.BeginErrorReadLine();

                // Close stdin immediately to send EOF - prevents scripts waiting for input from hanging forever
                try { process.StandardInput.Close(); } catch { }

                // Apply resource limits via job object
                _sandboxService.ApplyResourceLimits(process, sandboxDir);

                // A positive timeout is honored only when explicitly supplied
                // (or on the legacy non-Work path). Zero means wait indefinitely.
                var completedNormally = true;
                if (effectiveTimeout > 0)
                    completedNormally = process.WaitForExit(effectiveTimeout * 1000);
                else
                    process.WaitForExit();

                stopwatch.Stop();

                if (!completedNormally)
                {
                    // Kill on timeout
                    try { process.Kill(entireProcessTree: true); } catch { }
                    // Cancel async reads to flush any remaining buffered data
                    try { process.CancelOutputRead(); } catch { }
                    try { process.CancelErrorRead(); } catch { }
                    process.WaitForExit(3000); // Ensure process is fully dead
                    _sandboxService.ReleaseJobHandle(process.Id);
                    _sandboxService.CleanupSandbox(sandboxDir);

                    return new ExecutionResult
                    {
                        Status = "error",
                        Stage = "execute",
                        ErrorCode = "shell_timeout",
                        ExitCode = -1,
                        Output = outputBuilder.ToString(),
                        Error = $"Command timed out after {effectiveTimeout} seconds and was terminated.\n建议：检查脚本是否存在死循环，或通过 params.timeout_sec 参数延长超时时间",
                        DurationMs = stopwatch.ElapsedMilliseconds,
                        RiskLevel = preflight.RiskLevel,
                        MatchedRules = riskResult.MatchedRules,
                        FullCommand = command,
                        Shell = preflight.Shell,
                        CommandFingerprint = preflight.CommandFingerprint,
                        OperationReceipt = Receipt(operationId, phase, FailureState(phase), preflight.CommandFingerprint, intent)
                    };
                }

                // Ensure all async output has been delivered to the event handlers
                process.WaitForExit();
                _sandboxService.ReleaseJobHandle(process.Id);

                var output = outputBuilder.ToString();
                var stderr = isPowerShell
                    ? NormalizePowerShellStderr(errorBuilder.ToString())
                    : errorBuilder.ToString();
                var assertionResults = EvaluateSuccessCriteria(process.ExitCode, output, stderr, successCriteria);
                var assertionsPassed = assertionResults.All(AssertionPassed);
                var processHealthy = assertionResults
                    .Where(result =>
                    {
                        var name = AssertionName(result);
                        return name == "exit_code" || name == "stderr_empty";
                    })
                    .All(AssertionPassed);
                var status = assertionsPassed ? "success" : "error";
                var errorMsg = stderr;
                if (!assertionsPassed && string.IsNullOrWhiteSpace(errorMsg))
                    errorMsg = "命令输出未满足 success_criteria。";
                var failureCode = phase == "verify"
                    ? (processHealthy ? "verification_assertion_failed" : "verification_runtime_failed")
                    : (processHealthy ? "success_criteria_failed" : "shell_execution_failed");

                return new ExecutionResult
                {
                    Status = status,
                    Stage = phase == "verify" ? "verify" : "execute",
                    ErrorCode = assertionsPassed ? "" : failureCode,
                    Output = output,
                    Error = errorMsg,
                    ExitCode = process.ExitCode,
                    DurationMs = stopwatch.ElapsedMilliseconds,
                    RiskLevel = preflight.RiskLevel,
                    MatchedRules = riskResult.MatchedRules,
                    FullCommand = command,
                    Shell = preflight.Shell,
                    Diagnostics = preflight.Diagnostics,
                    CommandFingerprint = preflight.CommandFingerprint,
                    AssertionResults = assertionResults,
                    OperationReceipt = Receipt(operationId, phase,
                        assertionsPassed
                            ? (phase == "verify" ? "verified_completed" : phase == "act" ? "action_succeeded_unverified" : "inspected")
                            : FailureState(phase),
                        preflight.CommandFingerprint,
                        intent)
                };
            }
            catch (Exception ex)
            {
                stopwatch.Stop();
                return new ExecutionResult
                {
                    Status = "error",
                    Stage = "execute",
                    ErrorCode = "shell_execution_failed",
                    Output = "",
                    Error = $"Execution failed: {ex.Message}",
                    DurationMs = stopwatch.ElapsedMilliseconds,
                    RiskLevel = riskResult.Level.ToString().ToLower(),
                    MatchedRules = riskResult.MatchedRules,
                    FullCommand = command,
                    Shell = preflight.Shell,
                    CommandFingerprint = preflight.CommandFingerprint,
                    OperationReceipt = Receipt(operationId, phase, FailureState(phase), preflight.CommandFingerprint, intent)
                };
            }
            finally
            {
                _sandboxService.CleanupSandbox(sandboxDir);
            }
        }

        // Show a WPF confirmation dialog that auto-rejects after 30 seconds.
        // Uses a custom Window instead of MessageBox.Show because MessageBox blocks
        // the Dispatcher and cannot be programmatically closed.
        private Task<bool> ShowConfirmationDialog(string command, RiskAssessmentResult risk)
        {
            var tcs = new TaskCompletionSource<bool>();

            Application.Current.Dispatcher.Invoke(() =>
            {
                var riskLabel = risk.Level switch
                {
                    RiskLevel.High => "高风险 (HIGH)",
                    RiskLevel.Medium => "中风险 (MEDIUM)",
                    _ => ""
                };

                var rulesText = string.Join("\n", risk.MatchedRules.Select(r => $"  - {r}"));

                var message = $"风险等级: {riskLabel}\n\n" +
                              $"检测到以下风险规则:\n{rulesText}\n\n" +
                              $"命令内容:\n{command}\n\n" +
                              $"说明: {risk.Description}\n\n" +
                              $"是否确认执行此命令？\n\n" +
                              $"（30秒后自动拒绝）";

                var window = new Window
                {
                    Title = "命令执行确认 - MoonYa",
                    Width = 500,
                    Height = 450,
                    WindowStartupLocation = WindowStartupLocation.CenterScreen,
                    Topmost = true,
                    ResizeMode = ResizeMode.CanResize,
                };

                var panel = new StackPanel { Margin = new Thickness(15) };
                var msgBlock = new TextBlock
                {
                    Text = message,
                    TextWrapping = TextWrapping.Wrap,
                    MaxHeight = 300,
                };
                var btnPanel = new StackPanel
                {
                    Orientation = Orientation.Horizontal,
                    HorizontalAlignment = HorizontalAlignment.Center,
                    Margin = new Thickness(0, 15, 0, 0),
                };
                var yesBtn = new Button { Content = "确认执行", Padding = new Thickness(20, 8, 20, 8), Margin = new Thickness(5) };
                var noBtn = new Button { Content = "拒绝", Padding = new Thickness(20, 8, 20, 8), Margin = new Thickness(5) };
                btnPanel.Children.Add(yesBtn);
                btnPanel.Children.Add(noBtn);
                panel.Children.Add(msgBlock);
                panel.Children.Add(btnPanel);
                window.Content = panel;

                yesBtn.Click += (_, _) => { tcs.TrySetResult(true); window.Close(); };
                noBtn.Click += (_, _) => { tcs.TrySetResult(false); window.Close(); };
                window.Closed += (_, _) => { tcs.TrySetResult(false); };

                var timer = new DispatcherTimer
                {
                    Interval = TimeSpan.FromSeconds(30)
                };
                timer.Tick += (_, _) => { timer.Stop(); tcs.TrySetResult(false); window.Close(); };
                timer.Start();

                window.ShowDialog();
            });

            return tcs.Task;
        }

        private static ExecutionResult FromPreflightFailure(string command, CommandPreflightResult preflight, string? operationId, string? phase) => new()
        {
            Status = "error",
            Stage = "preflight",
            ErrorCode = preflight.ErrorCode,
            ExitCode = -1,
            Error = preflight.Error,
            FullCommand = command,
            Shell = preflight.Shell,
            RiskLevel = preflight.RiskLevel,
            Diagnostics = preflight.Diagnostics,
            CommandFingerprint = preflight.CommandFingerprint,
            OperationReceipt = Receipt(operationId, phase, "not_started", preflight.CommandFingerprint)
        };

        private static object Receipt(string? operationId, string? phase, string state, string fingerprint, string? intent = null) => new
        {
            operation_id = operationId ?? "",
            phase = phase ?? "legacy",
            state,
            command_fingerprint = fingerprint,
            intent = intent ?? ""
        };

        private static string FailureState(string? phase) => phase switch
        {
            "verify" => "verification_failed",
            "inspect" => "inspection_failed",
            _ => "action_failed"
        };

        private static string RunningState(string? phase) => phase switch
        {
            "inspect" => "inspection_running",
            "verify" => "verification_running",
            _ => "action_running"
        };

        private static string CompletedState(string? phase) => phase switch
        {
            "inspect" => "inspected",
            "verify" => "verified_completed",
            _ => "action_succeeded_unverified"
        };

        private static string BackgroundReceiptState(BackgroundCommand command)
        {
            if (command.Status == "running")
                return RunningState(command.Phase);
            if (command.Status == "killed" || command.CommandOk != true)
                return FailureState(command.Phase);
            return CompletedState(command.Phase);
        }

        private static string PowerShellRunnerArguments(string scriptPath)
        {
            var escapedPath = scriptPath.Replace("'", "''", StringComparison.Ordinal);
            var runner = $@"
[Console]::InputEncoding = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$OutputEncoding = [Console]::OutputEncoding
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
try {{
    & '{escapedPath}'
    if ($null -ne $LASTEXITCODE -and $LASTEXITCODE -ne 0) {{ exit $LASTEXITCODE }}
    exit 0
}} catch {{
    [Console]::Error.WriteLine(($_ | Out-String).TrimEnd())
    exit 1
}}";
            var encoded = Convert.ToBase64String(Encoding.Unicode.GetBytes(runner));
            return $"-NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand {encoded}";
        }

        private static void ConfigureUtf8(ProcessStartInfo startInfo)
        {
            startInfo.StandardOutputEncoding = Encoding.UTF8;
            startInfo.StandardErrorEncoding = Encoding.UTF8;
            startInfo.Environment["PYTHONIOENCODING"] = "utf-8";
            startInfo.Environment["DOTNET_SYSTEM_CONSOLE_ALLOW_ANSI_COLOR_REDIRECTION"] = "1";
        }

        private static string NormalizePowerShellStderr(string stderr)
        {
            if (!stderr.TrimStart().StartsWith("#< CLIXML", StringComparison.OrdinalIgnoreCase))
                return stderr;

            // Windows PowerShell may serialize its own module-initialization progress
            // record to stderr when -EncodedCommand is redirected. It is transport
            // noise, not target-command stderr. Never discard Error/Warning records.
            if (Regex.IsMatch(stderr, "\\bS=\"(?:Error|Warning|Verbose|Debug)\"", RegexOptions.IgnoreCase))
                return stderr;
            return Regex.IsMatch(stderr, "\\bS=\"progress\"", RegexOptions.IgnoreCase)
                ? ""
                : stderr;
        }

        private static List<object> EvaluateSuccessCriteria(
            int exitCode,
            string stdout,
            string stderr,
            ShellSuccessCriteria? criteria)
        {
            var results = new List<object>();
            var expectedExitCode = criteria?.ExpectedExitCode ?? 0;
            results.Add(new
            {
                assertion = "exit_code",
                expected = expectedExitCode,
                actual = exitCode,
                passed = exitCode == expectedExitCode
            });

            var requireEmptyStderr = criteria?.StderrEmpty ?? true;
            if (requireEmptyStderr)
            {
                results.Add(new
                {
                    assertion = "stderr_empty",
                    expected = true,
                    actual = string.IsNullOrWhiteSpace(stderr),
                    passed = string.IsNullOrWhiteSpace(stderr)
                });
            }

            foreach (var expected in criteria?.StdoutContains ?? Enumerable.Empty<string>())
            {
                results.Add(new
                {
                    assertion = "stdout_contains",
                    expected,
                    actual = stdout.Length > 500 ? stdout.Substring(0, 500) : stdout,
                    passed = stdout.Contains(expected, StringComparison.Ordinal)
                });
            }
            foreach (var pattern in criteria?.StdoutRegex ?? Enumerable.Empty<string>())
            {
                var passed = false;
                string? diagnostic = null;
                try { passed = Regex.IsMatch(stdout, pattern, RegexOptions.CultureInvariant, TimeSpan.FromSeconds(1)); }
                catch (ArgumentException ex) { diagnostic = ex.Message; }
                results.Add(new { assertion = "stdout_regex", expected = pattern, actual = passed, passed, diagnostic });
            }
            foreach (var expected in criteria?.StderrContains ?? Enumerable.Empty<string>())
            {
                results.Add(new
                {
                    assertion = "stderr_contains",
                    expected,
                    actual = stderr.Length > 500 ? stderr.Substring(0, 500) : stderr,
                    passed = stderr.Contains(expected, StringComparison.Ordinal)
                });
            }
            foreach (var forbidden in criteria?.StderrNotContains ?? Enumerable.Empty<string>())
            {
                results.Add(new
                {
                    assertion = "stderr_not_contains",
                    expected = forbidden,
                    actual = stderr.Length > 500 ? stderr.Substring(0, 500) : stderr,
                    passed = !stderr.Contains(forbidden, StringComparison.Ordinal)
                });
            }
            return results;
        }

        private static bool AssertionPassed(object result) =>
            result.GetType().GetProperty("passed")?.GetValue(result) is true;

        private static string AssertionName(object result) =>
            result.GetType().GetProperty("assertion")?.GetValue(result)?.ToString() ?? "";

        // Legacy background-call guard. Managed Work calls always use the parser preflight above.
        private static string? ValidateCommandSyntax(string command)
        {
            if (string.IsNullOrWhiteSpace(command))
                return "Command is empty.";

            // Check for balanced double quotes
            int dqCount = command.Count(c => c == '"');
            if (dqCount % 2 != 0)
                return "Unbalanced double quotes (\").";

            // Check for balanced single quotes (only if it looks like PowerShell)
            if (command.Contains("$") || command.Contains("-Command"))
            {
                int sqCount = command.Count(c => c == '\'');
                if (sqCount % 2 != 0)
                    return "Unbalanced single quotes (').";
            }

            return null; // no syntax error detected
        }

        // Quick cleanup：清理沙箱目录并终止所有未退出的后台命令进程
        public void Shutdown()
        {
            _sandboxService.CleanupAll();

            // 终止所有后台进程并回收资源
            lock (_bgLock)
            {
                foreach (var bgCmd in _backgroundCommands.Values)
                {
                    try
                    {
                        if (bgCmd.Process != null && !bgCmd.Process.HasExited)
                        {
                            bgCmd.Process.Kill(entireProcessTree: true);
                        }
                        bgCmd.CleanupTimer?.Dispose();
                        try { bgCmd.Process?.Dispose(); } catch { }
                    }
                    catch { }
                }
                _backgroundCommands.Clear();
            }
        }
    }
}
