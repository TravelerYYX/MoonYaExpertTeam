using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    public sealed class CommandDiagnostic
    {
        public string ErrorId { get; set; } = "";
        public string Message { get; set; } = "";
        public int StartLineNumber { get; set; }
        public int StartColumnNumber { get; set; }
        public int EndColumnNumber { get; set; }
        public string Text { get; set; } = "";
        public string Suggestion { get; set; } = "";
    }

    public sealed class CommandPreflightResult
    {
        public bool Valid { get; set; }
        public string Status { get; set; } = "error";
        public string Stage { get; set; } = "preflight";
        public string Shell { get; set; } = "";
        public string Effect { get; set; } = "unknown";
        public string RiskLevel { get; set; } = "high";
        public string ErrorCode { get; set; } = "";
        public string Error { get; set; } = "";
        public int? ExitCode { get; set; }
        public List<CommandDiagnostic> Diagnostics { get; set; } = new();
        public string CommandFingerprint { get; set; } = "";
        public string NormalizedCommand { get; set; } = "";
        public string CommandPreview { get; set; } = "";
        public string AuthoritativeVerifier { get; set; } = "";
        public List<string> DeterministicFixes { get; set; } = new();
        public string CommandPreflightVersion { get; set; } = CommandPreflightService.Version;
    }

    public sealed class CommandPreflightService
    {
        public const string Version = "shell-preflight-v1";

        private static readonly Regex PowerShellWrapper = new(
            @"^\s*(?:powershell(?:\.exe)?)\b(?<rest>[\s\S]*)$",
            RegexOptions.IgnoreCase | RegexOptions.Compiled);
        private static readonly Regex CmdWrapper = new(
            @"^\s*(?:cmd(?:\.exe)?)\s+/(?:c|k)\b(?<rest>[\s\S]*)$",
            RegexOptions.IgnoreCase | RegexOptions.Compiled);
        private static readonly Regex PowerShellCommandSwitch = new(
            @"(?:^|\s)-(?:command|c)\s+(?<payload>[\s\S]+)$",
            RegexOptions.IgnoreCase | RegexOptions.Compiled);

        private static readonly HashSet<string> ReadOnlyPowerShellCommands = new(StringComparer.OrdinalIgnoreCase)
        {
            "Get-ChildItem", "Get-Content", "Get-Item", "Get-ItemProperty", "Get-Process",
            "Get-Service", "Get-Date", "Get-Location", "Get-Command", "Get-CimInstance",
            "Test-Path", "Test-Connection", "Measure-Object", "Select-Object", "Where-Object",
            "Sort-Object", "Group-Object", "Compare-Object", "Format-List", "Format-Table",
            "Out-String", "Write-Output", "Write-Host", "Write-Verbose", "Write-Warning",
            "ConvertTo-Json", "ConvertFrom-Json", "Resolve-Path", "Split-Path", "Join-Path",
            "ForEach-Object", "Get-Acl", "Get-AuthenticodeSignature",
            "dir", "ls", "gci", "cat", "type", "echo", "pwd", "%", "?",
            "whoami", "hostname", "tasklist", "where.exe", "rg", "git", "dotnet"
        };

        private static readonly string[] MutatingPowerShellPrefixes =
        {
            "Set-", "New-", "Add-", "Remove-", "Clear-", "Move-", "Copy-", "Rename-",
            "Start-", "Stop-", "Restart-", "Enable-", "Disable-", "Install-", "Uninstall-",
            "Register-", "Unregister-", "Import-", "Export-", "Update-", "Publish-"
        };

        private static readonly HashSet<string> DestructivePowerShellCommands = new(StringComparer.OrdinalIgnoreCase)
        {
            "Remove-Item", "Clear-RecycleBin", "Format-Volume", "Clear-Disk", "Remove-Partition",
            "Stop-Process", "Stop-Computer", "Restart-Computer", "Uninstall-Package"
        };

        public async Task<CommandPreflightResult> ValidateAsync(
            string command,
            string? requestedShell = "auto",
            string? phase = null)
        {
            var result = new CommandPreflightResult();
            if (string.IsNullOrWhiteSpace(command))
            {
                return Fail(result, "shell_command_empty", "命令不能为空。", new CommandDiagnostic
                {
                    ErrorId = "CommandEmpty",
                    Message = "命令不能为空。",
                    StartLineNumber = 1,
                    StartColumnNumber = 1,
                    EndColumnNumber = 1,
                    Suggestion = "提供需要检查或执行的完整命令。"
                });
            }

            var normalized = Normalize(command, requestedShell, result.DeterministicFixes);
            if (normalized.Error != null)
            {
                return Fail(result, "shell_wrapper_invalid", normalized.Error);
            }

            result.Shell = normalized.Shell;
            result.NormalizedCommand = normalized.Command;
            result.CommandFingerprint = Fingerprint(normalized.Shell, normalized.Command);
            result.CommandPreview = SafePreview(normalized.Command);

            if (normalized.Shell == "powershell")
            {
                await ValidatePowerShellAsync(result);
            }
            else
            {
                ValidateCmd(result);
            }

            if (!result.Valid)
            {
                return result;
            }

            if ((string.Equals(phase, "inspect", StringComparison.OrdinalIgnoreCase) ||
                 string.Equals(phase, "verify", StringComparison.OrdinalIgnoreCase)) &&
                result.Effect != "read")
            {
                return Fail(result, "shell_readonly_violation",
                    $"{phase} 阶段只允许已证明为只读的命令；当前分类为 {result.Effect}。",
                    new CommandDiagnostic
                    {
                        ErrorId = "ReadOnlyPhaseViolation",
                        Message = "检查或验证命令包含写操作、动态调用，或无法证明为只读。",
                        StartLineNumber = 1,
                        StartColumnNumber = 1,
                        EndColumnNumber = Math.Max(1, result.CommandPreview.Length),
                        Text = result.CommandPreview,
                        Suggestion = "把变更移到 act 阶段，并将 verify 改成只读查询与确定性输出。"
                    });
            }

            return result;
        }

        private static (string Shell, string Command, string? Error) Normalize(
            string command,
            string? requestedShell,
            List<string> fixes)
        {
            var requested = (requestedShell ?? "auto").Trim().ToLowerInvariant();
            if (requested is not ("auto" or "powershell" or "cmd"))
            {
                return ("", "", "shell 必须是 powershell、cmd 或 auto。");
            }

            var trimmed = command.Trim();
            var psMatch = PowerShellWrapper.Match(trimmed);
            if (psMatch.Success)
            {
                var rest = psMatch.Groups["rest"].Value.Trim();
                var switchMatch = PowerShellCommandSwitch.Match(rest);
                if (!switchMatch.Success)
                {
                    return ("", "", "PowerShell 包装器缺少 -Command 参数；请提供 powershell -Command <脚本>。");
                }
                var payload = StripOneOuterQuotePair(switchMatch.Groups["payload"].Value.Trim());
                fixes.Add("已拆解 powershell 启动参数，仅将 -Command 后的脚本交给解析器和执行器。");
                return ("powershell", payload, null);
            }

            var cmdMatch = CmdWrapper.Match(trimmed);
            if (cmdMatch.Success)
            {
                var payload = StripOneOuterQuotePair(cmdMatch.Groups["rest"].Value.Trim());
                fixes.Add("已拆解 cmd /c 包装，仅执行 /c 后的命令正文。");
                return ("cmd", payload, null);
            }

            var shell = requested == "auto" ? DetectShell(trimmed) : requested;
            if (requested == "auto")
            {
                fixes.Add($"已自动选择 {shell}。 ");
            }
            return (shell, trimmed, null);
        }

        private static string StripOneOuterQuotePair(string value)
        {
            if (value.Length >= 2 &&
                ((value[0] == '"' && value[^1] == '"') || (value[0] == '\'' && value[^1] == '\'')))
            {
                return value.Substring(1, value.Length - 2);
            }
            return value;
        }

        private static string DetectShell(string command)
        {
            if (Regex.IsMatch(command, @"\$[A-Za-z_{]|\b[A-Za-z]+-[A-Za-z]+\b|\|\s*(?:Where|Select|ForEach|Measure|Sort)-Object\b", RegexOptions.IgnoreCase) ||
                command.Contains("::", StringComparison.Ordinal) || command.Contains("@{", StringComparison.Ordinal))
            {
                return "powershell";
            }
            return "cmd";
        }

        private static async Task ValidatePowerShellAsync(CommandPreflightResult result)
        {
            var tempDir = Path.Combine(Path.GetTempPath(), "MoonYaPreflight", Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(tempDir);
            var scriptPath = Path.Combine(tempDir, "target.ps1");
            await File.WriteAllTextAsync(scriptPath, result.NormalizedCommand, new UTF8Encoding(true));

            try
            {
                var driver = @"
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$tokens = $null
$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($env:MOONYA_PREFLIGHT_TARGET, [ref]$tokens, [ref]$parseErrors)
$diagnostics = @($parseErrors | ForEach-Object {
    [ordered]@{
        errorId = $_.ErrorId
        message = $_.Message
        startLineNumber = $_.Extent.StartLineNumber
        startColumnNumber = $_.Extent.StartColumnNumber
        endColumnNumber = $_.Extent.EndColumnNumber
        text = $_.Extent.Text
    }
})
$commands = @($ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.CommandAst] }, $true) | ForEach-Object {
    [ordered]@{ name = $_.GetCommandName(); text = $_.Extent.Text }
})
[ordered]@{ diagnostics = $diagnostics; commands = $commands; astText = $ast.Extent.Text } | ConvertTo-Json -Depth 6 -Compress
";
                var encoded = Convert.ToBase64String(Encoding.Unicode.GetBytes(driver));
                var startInfo = new ProcessStartInfo
                {
                    FileName = "powershell.exe",
                    Arguments = $"-NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand {encoded}",
                    UseShellExecute = false,
                    CreateNoWindow = true,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    StandardOutputEncoding = Encoding.UTF8,
                    StandardErrorEncoding = Encoding.UTF8
                };
                startInfo.Environment["MOONYA_PREFLIGHT_TARGET"] = scriptPath;

                using var process = Process.Start(startInfo);
                if (process == null)
                {
                    Fail(result, "shell_preflight_unavailable", "无法启动 Windows PowerShell 5.1 Parser。");
                    return;
                }

                var stdoutTask = process.StandardOutput.ReadToEndAsync();
                var stderrTask = process.StandardError.ReadToEndAsync();
                await process.WaitForExitAsync();
                var stdout = await stdoutTask;
                var stderr = await stderrTask;
                if (process.ExitCode != 0 || string.IsNullOrWhiteSpace(stdout))
                {
                    Fail(result, "shell_preflight_unavailable",
                        "Windows PowerShell 5.1 Parser 未返回有效结果。" +
                        (string.IsNullOrWhiteSpace(stderr) ? "" : $" {stderr.Trim()}"));
                    return;
                }

                using var json = JsonDocument.Parse(stdout);
                var root = json.RootElement;
                if (root.TryGetProperty("diagnostics", out var diagnostics))
                {
                    foreach (var item in EnumerateArrayOrSingle(diagnostics))
                    {
                        var errorId = JsonString(item, "errorId");
                        result.Diagnostics.Add(new CommandDiagnostic
                        {
                            ErrorId = errorId,
                            Message = JsonString(item, "message"),
                            StartLineNumber = JsonInt(item, "startLineNumber"),
                            StartColumnNumber = JsonInt(item, "startColumnNumber"),
                            EndColumnNumber = JsonInt(item, "endColumnNumber"),
                            Text = JsonString(item, "text"),
                            Suggestion = SuggestionFor(errorId)
                        });
                    }
                }

                if (result.Diagnostics.Count > 0)
                {
                    Fail(result, "shell_preflight_failed", "PowerShell 语法检查失败。", result.Diagnostics.ToArray());
                    return;
                }

                var commands = new List<(string Name, string Text)>();
                if (root.TryGetProperty("commands", out var commandItems))
                {
                    foreach (var item in EnumerateArrayOrSingle(commandItems))
                    {
                        commands.Add((JsonString(item, "name"), JsonString(item, "text")));
                    }
                }
                ClassifyPowerShell(result, commands);
                if (commands.Any(command =>
                    command.Name.Equals("Clear-RecycleBin", StringComparison.OrdinalIgnoreCase)))
                {
                    result.AuthoritativeVerifier = "recycle_bin_status";
                }
                result.Valid = true;
                result.Status = "success";
                result.ErrorCode = "";
                result.Error = "";
            }
            catch (JsonException ex)
            {
                Fail(result, "shell_preflight_unavailable", $"PowerShell Parser 响应无法解析：{ex.Message}");
            }
            catch (Exception ex)
            {
                Fail(result, "shell_preflight_unavailable", $"PowerShell 预检失败：{ex.Message}");
            }
            finally
            {
                try { Directory.Delete(tempDir, true); } catch { }
            }
        }

        private static void ClassifyPowerShell(CommandPreflightResult result, List<(string Name, string Text)> commands)
        {
            var script = result.NormalizedCommand;
            if (Regex.IsMatch(script, @"(?<![<>=])>{1,2}(?![=>])") ||
                Regex.IsMatch(script, "\\b(?:Invoke-Expression|Invoke-Command|Start-Process)\\b|(^|[;|\\s])&\\s*[$'\\\"]", RegexOptions.IgnoreCase) ||
                Regex.IsMatch(script, @"\bInvokeVerb\b|\bSHEmptyRecycleBin\b", RegexOptions.IgnoreCase) ||
                Regex.IsMatch(script, @"::\s*(?:Write|WriteAll|Append|AppendAll|Delete|Move|Copy|Create|Set|Replace|Encrypt|Decrypt)\w*\s*\(", RegexOptions.IgnoreCase) ||
                Regex.IsMatch(script, @"\.\s*(?:Delete|MoveTo|CopyTo|Create|SetValue|Save|InvokeMember)\s*\(", RegexOptions.IgnoreCase))
            {
                result.Effect = Regex.IsMatch(script, @"\b(?:InvokeVerb|SHEmptyRecycleBin|Remove-|Clear-|Format-)\b", RegexOptions.IgnoreCase)
                    ? "destructive"
                    : "unknown";
                result.RiskLevel = "high";
                return;
            }

            var effect = "read";
            foreach (var (name, text) in commands)
            {
                if (string.IsNullOrWhiteSpace(name))
                {
                    effect = "unknown";
                    break;
                }
                if (DestructivePowerShellCommands.Contains(name))
                {
                    effect = "destructive";
                    break;
                }
                if (MutatingPowerShellPrefixes.Any(prefix => name.StartsWith(prefix, StringComparison.OrdinalIgnoreCase)))
                {
                    if (name.Equals("New-Object", StringComparison.OrdinalIgnoreCase) &&
                        Regex.IsMatch(text, @"\b-ComObject\s+Shell\.Application\b", RegexOptions.IgnoreCase))
                    {
                        continue;
                    }
                    effect = "write";
                    continue;
                }
                if (name.Equals("git", StringComparison.OrdinalIgnoreCase) &&
                    !Regex.IsMatch(text, @"^\s*git\s+(?:status|diff|log|show|rev-parse)(?:\s|$)", RegexOptions.IgnoreCase))
                {
                    effect = "unknown";
                    break;
                }
                if (name.Equals("dotnet", StringComparison.OrdinalIgnoreCase) &&
                    !Regex.IsMatch(text, @"^\s*dotnet\s+(?:--info|--version|--list-sdks|--list-runtimes)(?:\s|$)", RegexOptions.IgnoreCase))
                {
                    effect = "unknown";
                    break;
                }
                if (!ReadOnlyPowerShellCommands.Contains(name))
                {
                    effect = "unknown";
                    break;
                }
            }

            result.Effect = effect;
            result.RiskLevel = effect == "read" ? "low" : "high";
        }

        private static void ValidateCmd(CommandPreflightResult result)
        {
            var command = result.NormalizedCommand;
            var diagnostics = new List<CommandDiagnostic>();
            var inQuote = false;
            var parenDepth = 0;
            for (var i = 0; i < command.Length; i++)
            {
                var c = command[i];
                if (c == '"') inQuote = !inQuote;
                if (inQuote || (i > 0 && command[i - 1] == '^')) continue;
                if (c == '(') parenDepth++;
                if (c == ')')
                {
                    if (parenDepth == 0)
                    {
                        diagnostics.Add(CmdDiagnostic("UnexpectedClosingParenthesis", "出现多余的右括号。", i + 1, ")", "删除多余的右括号，或补上对应的左括号。"));
                        break;
                    }
                    parenDepth--;
                }
            }
            if (inQuote)
                diagnostics.Add(CmdDiagnostic("TerminatorExpectedAtEndOfString", "双引号字符串没有结束。", command.Length, "\"", "补齐缺失的双引号。"));
            if (parenDepth > 0)
                diagnostics.Add(CmdDiagnostic("MissingEndParenthesis", "命令块缺少右括号。", command.Length, "(", "补齐缺失的右括号。"));
            if (Regex.IsMatch(command, @"(?:\||&&|\|\||[<>])\s*$"))
                diagnostics.Add(CmdDiagnostic("DanglingOperator", "命令以未完成的管道、连接符或重定向结束。", command.Length, command[^1].ToString(), "在操作符后补齐目标命令或路径。"));

            if (diagnostics.Count > 0)
            {
                Fail(result, "shell_preflight_failed", "CMD 词法检查失败。", diagnostics.ToArray());
                return;
            }

            var lower = command.Trim().ToLowerInvariant();
            var destructive = Regex.IsMatch(lower, @"(^|[&|()]\s*)(del|erase|rd|rmdir|format|diskpart|shutdown|taskkill)\b");
            var write = destructive || Regex.IsMatch(lower, @"(^|[&|()]\s*)(copy|xcopy|robocopy|move|ren|rename|mkdir|md|setx|reg\s+(add|delete)|sc\s+(create|delete|start|stop))\b") ||
                        Regex.IsMatch(command, @"(?<![<>=])>{1,2}(?![=>])");
            var segments = Regex.Split(lower, @"(?<!\^)(?:&&|\|\||[&|])")
                .Select(segment => segment.Trim().TrimStart('(').TrimEnd(')'))
                .Where(segment => segment.Length > 0)
                .ToArray();
            var read = segments.Length > 0 && segments.All(segment =>
                Regex.IsMatch(segment, @"^(dir|type|echo|where|whoami|hostname|tasklist|ver|set(?:\s|$)|pathping|ping|ipconfig|systeminfo|git\s+(status|diff|log|show)(?:\s|$)|rg(?:\s|$)|dotnet\s+--info(?:\s|$))"));

            result.Effect = destructive ? "destructive" : write ? "write" : read ? "read" : "unknown";
            result.RiskLevel = result.Effect == "read" ? "low" : "high";
            result.Valid = true;
            result.Status = "success";
        }

        private static CommandDiagnostic CmdDiagnostic(string id, string message, int column, string text, string suggestion) => new()
        {
            ErrorId = id,
            Message = message,
            StartLineNumber = 1,
            StartColumnNumber = Math.Max(1, column),
            EndColumnNumber = Math.Max(1, column + 1),
            Text = text,
            Suggestion = suggestion
        };

        private static IEnumerable<JsonElement> EnumerateArrayOrSingle(JsonElement value)
        {
            if (value.ValueKind == JsonValueKind.Array)
                return value.EnumerateArray().ToArray();
            if (value.ValueKind == JsonValueKind.Null || value.ValueKind == JsonValueKind.Undefined)
                return Array.Empty<JsonElement>();
            return new[] { value };
        }

        private static string JsonString(JsonElement item, string property) =>
            item.TryGetProperty(property, out var value) && value.ValueKind != JsonValueKind.Null
                ? value.ToString()
                : "";

        private static int JsonInt(JsonElement item, string property) =>
            item.TryGetProperty(property, out var value) && value.TryGetInt32(out var number) ? number : 0;

        private static string SuggestionFor(string errorId) => errorId switch
        {
            "MissingEndCurlyBrace" => "补齐缺失的右花括号 `}`；不要改写其他业务逻辑。",
            "MissingEndParenthesisInExpression" => "补齐缺失的右括号 `)`；不要改写其他业务逻辑。",
            "TerminatorExpectedAtEndOfString" => "补齐缺失的字符串引号，并确认引号内仍是原始业务参数。",
            "UnexpectedToken" => "检查标出的意外标记及其前一个标记；只修正语法，不改写业务逻辑。",
            _ => "按错误位置修正一次语法；不要重复执行此前已成功的 act 操作。"
        };

        private static CommandPreflightResult Fail(CommandPreflightResult result, string errorCode, string error, params CommandDiagnostic[] diagnostics)
        {
            result.Valid = false;
            result.Status = "error";
            result.ErrorCode = errorCode;
            result.Error = error;
            if (diagnostics.Length > 0 && !ReferenceEquals(result.Diagnostics, diagnostics))
            {
                foreach (var diagnostic in diagnostics)
                {
                    if (!result.Diagnostics.Contains(diagnostic)) result.Diagnostics.Add(diagnostic);
                }
            }
            return result;
        }

        private static string Fingerprint(string shell, string command)
        {
            var normalized = command.Replace("\r\n", "\n", StringComparison.Ordinal).Trim();
            var bytes = SHA256.HashData(Encoding.UTF8.GetBytes($"{shell}\n{normalized}"));
            return Convert.ToHexString(bytes).ToLowerInvariant();
        }

        private static string SafePreview(string command)
        {
            var singleLine = Regex.Replace(command, @"\s+", " ").Trim();
            return singleLine.Length <= 240 ? singleLine : singleLine.Substring(0, 237) + "...";
        }
    }
}
