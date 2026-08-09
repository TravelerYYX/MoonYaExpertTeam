using MoonYa.Services;
using System;
using System.IO;
using System.Text.Json;
using System.Threading.Tasks;

static void Check(bool condition, string message)
{
    if (!condition) throw new InvalidOperationException(message);
}

static async Task<ExecutionResult> Execute(
    CommandExecutionService executor,
    string command,
    string shell,
    string phase,
    string operationId,
    ShellSuccessCriteria criteria)
{
    var raw = await executor.ExecuteAsync(
        command,
        autoApproveMediumRisk: true,
        cwd: null,
        timeoutSec: 20,
        blocking: true,
        shell: shell,
        phase: phase,
        operationId: operationId,
        intent: "Windows smoke",
        successCriteria: criteria,
        approvalGranted: true);
    return raw as ExecutionResult
        ?? throw new InvalidOperationException($"Unexpected execution result: {raw.GetType().Name}");
}

static JsonElement JsonOf(object value) =>
    JsonSerializer.SerializeToElement(value, value.GetType());

static async Task<JsonElement> WaitForDone(CommandExecutionService executor, string commandId)
{
    for (var i = 0; i < 200; i++)
    {
        var status = JsonOf(await executor.GetCommandStatusAsync(commandId));
        if (status.GetProperty("status").GetString() != "running") return status;
        await Task.Delay(25);
    }
    throw new TimeoutException($"Background command did not finish: {commandId}");
}

var preflight = new CommandPreflightService();

var launcherConfigPath = Path.Combine(AppContext.BaseDirectory, "launcher_config.json");
Check(File.Exists(launcherConfigPath), "launcher_config.json was not deployed beside the executable");
_ = new WebCrawlerService(1, launcherConfigPath);
using var launcherConfigDocument = JsonDocument.Parse(File.ReadAllText(launcherConfigPath));
var configuredPythonCandidates = launcherConfigDocument.RootElement
    .GetProperty("python_service")
    .GetProperty("python_candidates")
    .EnumerateArray()
    .Select(item => item.GetString() ?? string.Empty)
    .Where(item => item.Length > 0)
    .ToArray();

var recycleBinStatus = JsonOf(await new RecycleBinService().GetStatusAsync());
Check(recycleBinStatus.GetProperty("success").GetBoolean(),
    $"Windows Recycle Bin status API failed: {recycleBinStatus.GetProperty("message").GetString()}");
Check(recycleBinStatus.GetProperty("source").GetString() == "SHQueryRecycleBin"
    && recycleBinStatus.GetProperty("itemCount").GetInt64() >= 0
    && recycleBinStatus.GetProperty("sizeBytes").GetInt64() >= 0,
    "Recycle Bin status did not come from the authoritative Windows Shell API");

var wrappedPowerShell = await preflight.ValidateAsync(
    "powershell -NoProfile -Command \"Write-Output '包装正常'\"",
    "auto",
    "inspect");
Check(wrappedPowerShell.Valid, wrappedPowerShell.Error);
Check(wrappedPowerShell.Shell == "powershell", "PowerShell wrapper selected the wrong shell");
Check(!wrappedPowerShell.NormalizedCommand.Contains("-NoProfile", StringComparison.OrdinalIgnoreCase),
    "PowerShell launcher switches leaked into script content");

var wrappedCmd = await preflight.ValidateAsync("cmd /c dir", "auto", "inspect");
Check(wrappedCmd.Valid && wrappedCmd.Shell == "cmd" && wrappedCmd.NormalizedCommand == "dir",
    "cmd /c wrapper was not decomposed");

var parserError = await preflight.ValidateAsync("Write-Output '第一行'\nif ($true {\nWrite-Output '不会执行'", "powershell", "act");
Check(!parserError.Valid && parserError.ErrorCode == "shell_preflight_failed", "Missing parenthesis was not rejected");
Check(parserError.Diagnostics.Count > 0 && parserError.Diagnostics[0].StartLineNumber >= 2 && parserError.Diagnostics[0].StartColumnNumber > 0,
    "PowerShell parser did not return precise line/column diagnostics");

var readRisk = await preflight.ValidateAsync("Get-ChildItem | Measure-Object | Write-Output", "powershell", "inspect");
Check(readRisk.Valid && readRisk.Effect == "read" && readRisk.RiskLevel == "low", "Read-only PowerShell risk classification failed");
var writeRisk = await preflight.ValidateAsync("Remove-Item -LiteralPath 'x' -Force", "powershell", "act");
Check(writeRisk.Valid && writeRisk.Effect == "destructive" && writeRisk.RiskLevel == "high", "Mutating PowerShell risk classification failed");
var dynamicRisk = await preflight.ValidateAsync("$command = 'Get-Date'; & $command", "powershell", "act");
Check(dynamicRisk.Valid && dynamicRisk.Effect == "unknown" && dynamicRisk.RiskLevel == "high", "Dynamic PowerShell risk classification failed");
var unsafeVerify = await preflight.ValidateAsync("Remove-Item -LiteralPath 'x'", "powershell", "verify");
Check(!unsafeVerify.Valid && unsafeVerify.ErrorCode == "shell_readonly_violation", "Mutating verify was not rejected");
var recycleMutation = await preflight.ValidateAsync("Clear-RecycleBin -Force", "powershell", "act");
Check(recycleMutation.Valid
    && recycleMutation.Effect == "destructive"
    && recycleMutation.AuthoritativeVerifier == "recycle_bin_status",
    "Clear-RecycleBin was not bound to its authoritative logical-state verifier");

var sandbox = new SandboxService();
var executor = new CommandExecutionService(new RiskAssessmentService(), sandbox, 20);
try
{
    var chinese = await Execute(
        executor,
        "Write-Output '中文输出正常'",
        "powershell",
        "inspect",
        "unicode-output",
        new ShellSuccessCriteria { ExpectedExitCode = 0, StdoutContains = new() { "中文输出正常" } });
    Check(chinese.Status == "success" && chinese.Output.Contains("中文输出正常", StringComparison.Ordinal),
        $"UTF-8 output failed: {chinese.Error} / {chinese.Output}");

    var cmdChinese = await Execute(
        executor,
        "echo CMD中文输出正常",
        "cmd",
        "inspect",
        "cmd-unicode-output",
        new ShellSuccessCriteria { ExpectedExitCode = 0, StdoutContains = new() { "CMD中文输出正常" } });
    Check(cmdChinese.Status == "success" && cmdChinese.Output.Contains("CMD中文输出正常", StringComparison.Ordinal),
        $"CMD UTF-8 output failed: {cmdChinese.Error} / {cmdChinese.Output}");

    var nonTerminatingError = await Execute(
        executor,
        "Write-Error '精确失败'; Write-Output '不应假成功'",
        "powershell",
        "act",
        "write-error",
        new ShellSuccessCriteria { ExpectedExitCode = 0 });
    Check(nonTerminatingError.Status == "error" && nonTerminatingError.ExitCode != 0,
        $"Write-Error incorrectly returned success: status={nonTerminatingError.Status}, exit={nonTerminatingError.ExitCode}, error={nonTerminatingError.Error}, output={nonTerminatingError.Output}");

    var missingCompletion = await executor.ExecuteAsync(
        "Write-Output 'x'", blocking: false, shell: "powershell", phase: "inspect",
        operationId: "missing-completion", intent: "contract", successCriteria: new ShellSuccessCriteria());
    Check(missingCompletion is ExecutionResult missingCompletionResult &&
        missingCompletionResult.ErrorCode == "completion_mode_required",
        "blocking=false without completion_mode was not rejected");

    var finiteStart = JsonOf(await executor.ExecuteAsync(
        "Write-Output 'ASYNC-OUT'; [Console]::Error.WriteLine('ASYNC-ERR')",
        blocking: false,
        shell: "powershell",
        phase: "inspect",
        operationId: "finite-inspect",
        intent: "finite inspect",
        successCriteria: new ShellSuccessCriteria
        {
            ExpectedExitCode = 0,
            StderrEmpty = false,
            StdoutContains = new() { "ASYNC-OUT" }
        },
        completionMode: "finite"));
    var finiteId = finiteStart.GetProperty("command_id").GetString() ?? "";
    Check(finiteStart.GetProperty("completion_mode").GetString() == "finite", "finite start omitted completion_mode");
    var finiteDone = await WaitForDone(executor, finiteId);
    Check(finiteDone.GetProperty("command_ok").GetBoolean(), "finite inspect did not report command_ok");
    Check(finiteDone.GetProperty("stdout").GetString()!.Contains("ASYNC-OUT", StringComparison.Ordinal), "stdout was not separated");
    Check(finiteDone.GetProperty("stderr").GetString()!.Contains("ASYNC-ERR", StringComparison.Ordinal), "stderr was not separated");
    Check(finiteDone.GetProperty("output_revision").GetInt64() >= 2, "output_revision did not advance");
    Check(finiteDone.GetProperty("operation_receipt").GetProperty("state").GetString() == "inspected", "finite inspect receipt state is wrong");

    var unauthorized = await executor.ExecuteAsync(
        "Set-Content -LiteralPath 'never.txt' -Value 'x'",
        blocking: false,
        shell: "powershell",
        phase: "act",
        operationId: "unauthorized-act",
        intent: "must fail closed",
        successCriteria: new ShellSuccessCriteria(),
        approvalGranted: false,
        completionMode: "finite");
    Check(unauthorized is ExecutionResult unauthorizedResult &&
        unauthorizedResult.ErrorCode == "background_act_not_authorized",
        "Untrusted background act did not fail closed");

    var backgroundFile = Path.Combine(Path.GetTempPath(), "moonya-bg-" + Guid.NewGuid().ToString("N") + ".txt");
    var escapedBackgroundFile = backgroundFile.Replace("'", "''", StringComparison.Ordinal);
    try
    {
        var actStart = JsonOf(await executor.ExecuteAsync(
            $"Set-Content -LiteralPath '{escapedBackgroundFile}' -Value 'BACKGROUND-VALUE'",
            blocking: false,
            shell: "powershell",
            phase: "act",
            operationId: "finite-act-verify",
            intent: "background act",
            successCriteria: new ShellSuccessCriteria { ExpectedExitCode = 0 },
            approvalGranted: true,
            completionMode: "finite"));
        var actDone = await WaitForDone(executor, actStart.GetProperty("command_id").GetString() ?? "");
        Check(actDone.GetProperty("command_ok").GetBoolean() &&
            actDone.GetProperty("operation_receipt").GetProperty("state").GetString() == "action_succeeded_unverified",
            "Background finite act state is wrong");

        var verifyStart = JsonOf(await executor.ExecuteAsync(
            $"Get-Content -LiteralPath '{escapedBackgroundFile}'",
            blocking: false,
            shell: "powershell",
            phase: "verify",
            operationId: "finite-act-verify",
            intent: "background verify",
            successCriteria: new ShellSuccessCriteria
            {
                ExpectedExitCode = 0,
                StdoutContains = new() { "BACKGROUND-VALUE" }
            },
            completionMode: "finite"));
        var verifyDone = await WaitForDone(executor, verifyStart.GetProperty("command_id").GetString() ?? "");
        Check(verifyDone.GetProperty("command_ok").GetBoolean() &&
            verifyDone.GetProperty("operation_receipt").GetProperty("state").GetString() == "verified_completed",
            "Background finite verify state is wrong");
    }
    finally
    {
        try { File.Delete(backgroundFile); } catch { }
    }

    var forbiddenPersistentVerify = await executor.ExecuteAsync(
        "Write-Output 'READY'",
        blocking: false,
        shell: "powershell",
        phase: "verify",
        operationId: "persistent-verify-forbidden",
        intent: "forbidden persistent verify",
        successCriteria: new ShellSuccessCriteria { ExpectedExitCode = 0, StdoutContains = new() { "READY" } },
        completionMode: "persistent");
    Check(forbiddenPersistentVerify is ExecutionResult forbiddenPersistentResult &&
        forbiddenPersistentResult.ErrorCode == "persistent_phase_forbidden",
        "persistent verify was not rejected");

    var nonZeroStart = JsonOf(await executor.ExecuteAsync(
        "Write-Output 'before-failure'; exit 7",
        blocking: false,
        shell: "powershell",
        phase: "inspect",
        operationId: "finite-nonzero",
        intent: "nonzero",
        successCriteria: new ShellSuccessCriteria { ExpectedExitCode = 0 },
        completionMode: "finite"));
    var nonZeroDone = await WaitForDone(executor, nonZeroStart.GetProperty("command_id").GetString() ?? "");
    Check(!nonZeroDone.GetProperty("command_ok").GetBoolean() && nonZeroDone.GetProperty("exit_code").GetInt32() == 7,
        "Non-zero background exit was not reported deterministically");

    var persistentStart = JsonOf(await executor.ExecuteAsync(
        "while ($true) { Write-Output 'READY'; Start-Sleep -Milliseconds 200 }",
        blocking: false,
        shell: "powershell",
        phase: "act",
        operationId: "persistent-service",
        intent: "persistent readiness",
        successCriteria: new ShellSuccessCriteria { ExpectedExitCode = 0 },
        approvalGranted: true,
        completionMode: "persistent"));
    var persistentId = persistentStart.GetProperty("command_id").GetString() ?? "";
    await Task.Delay(300);
    var persistentStatus = JsonOf(await executor.GetCommandStatusAsync(persistentId));
    Check(persistentStatus.GetProperty("status").GetString() == "running", "Persistent act exited before readiness verify");
    Check(persistentStatus.GetProperty("completion_mode").GetString() == "persistent", "Persistent mode was not retained");
    Check(persistentStatus.GetProperty("operation_receipt").GetProperty("state").GetString() == "action_running", "Persistent running receipt is wrong");

    var readiness = await Execute(
        executor,
        "Write-Output 'READY'",
        "powershell",
        "verify",
        "persistent-service",
        new ShellSuccessCriteria { ExpectedExitCode = 0, StdoutContains = new() { "READY" } });
    Check(readiness.Status == "success", "Independent readiness verify failed");
    persistentStatus = JsonOf(await executor.GetCommandStatusAsync(persistentId));
    Check(persistentStatus.GetProperty("status").GetString() == "running", "Readiness verify stopped persistent process");
    var stopped = JsonOf(await executor.StopCommandAsync(persistentId));
    Check(stopped.GetProperty("status").GetString() == "killed" &&
        !stopped.GetProperty("command_ok").GetBoolean(), "stop_command did not terminate persistent process");

    var noDeadlineSandbox = new SandboxService();
    var noDeadlineExecutor = new CommandExecutionService(new RiskAssessmentService(), noDeadlineSandbox, 1);
    try
    {
        var noDeadlineRaw = await noDeadlineExecutor.ExecuteAsync(
            "Start-Sleep -Milliseconds 1300; Write-Output 'NO-DEADLINE'",
            blocking: true,
            shell: "powershell",
            phase: "act",
            operationId: "managed-no-deadline",
            intent: "managed calls ignore legacy timeout",
            successCriteria: new ShellSuccessCriteria { ExpectedExitCode = 0, StdoutContains = new() { "NO-DEADLINE" } },
            approvalGranted: true);
        Check(noDeadlineRaw is ExecutionResult noDeadlineResult && noDeadlineResult.Status == "success",
            "Managed Work command inherited the legacy one-second timeout: " +
            (noDeadlineRaw is ExecutionResult detail ? $"{detail.ErrorCode} / {detail.Error}" : noDeadlineRaw.GetType().Name));
    }
    finally
    {
        noDeadlineExecutor.Shutdown();
    }

    var pythonSandbox = new SandboxService();
    var pythonExecutor = new PythonExecutionService(
        new RiskAssessmentService(), pythonSandbox, configuredPythonCandidates, 1);
    try
    {
        if (pythonExecutor.IsPythonAvailable())
        {
            var pythonNoDeadline = await pythonExecutor.ExecuteAsync(
                "import time\ntime.sleep(1.3)\nprint('PY-NO-DEADLINE')",
                managedWork: true,
                approvalGranted: true);
            Check(pythonNoDeadline.Status == "success" &&
                pythonNoDeadline.Output.Contains("PY-NO-DEADLINE", StringComparison.Ordinal),
                $"Managed Python inherited the legacy one-second timeout: {pythonNoDeadline.Error}");
        }
    }
    finally
    {
        pythonSandbox.CleanupAll();
    }

    var fakeRecycle = Path.Combine(Path.GetTempPath(), "MoonYaFakeRecycle", Guid.NewGuid().ToString("N"));
    Directory.CreateDirectory(fakeRecycle);
    await File.WriteAllTextAsync(Path.Combine(fakeRecycle, "一.txt"), "1");
    await File.WriteAllTextAsync(Path.Combine(fakeRecycle, "二.txt"), "2");
    try
    {
        var quoted = fakeRecycle.Replace("'", "''", StringComparison.Ordinal);
        var actionCount = 0;
        var action = await Execute(
            executor,
            $"Get-ChildItem -LiteralPath '{quoted}' | Remove-Item -Force",
            "powershell",
            "act",
            "fake-recycle-empty",
            new ShellSuccessCriteria { ExpectedExitCode = 0 });
        actionCount++;
        Check(action.Status == "success", $"Fake recycle action failed: {action.Error}");

        var verify = await Execute(
            executor,
            $"(Get-ChildItem -LiteralPath '{quoted}' | Measure-Object).Count | Write-Output",
            "powershell",
            "verify",
            "fake-recycle-empty",
            new ShellSuccessCriteria { ExpectedExitCode = 0, StdoutRegex = new() { @"(?m)^0\r?$" } });
        Check(verify.Status == "success" && Directory.GetFileSystemEntries(fakeRecycle).Length == 0,
            $"Fake recycle verification failed: {verify.Error} / {verify.Output}");
        Check(actionCount == 1, "Fake recycle mutation executed more than once");
    }
    finally
    {
        try { Directory.Delete(fakeRecycle, true); } catch { }
    }
}
finally
{
    executor.Shutdown();
}

Console.WriteLine("MoonYa Shell execution smoke passed.");
return 0;
