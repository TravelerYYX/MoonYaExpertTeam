using System;
using System.Diagnostics;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading.Tasks;
using System.Windows;

namespace MoonYa.Services
{
    public class PythonExecutionService
    {
        private readonly RiskAssessmentService _riskService;
        private readonly SandboxService _sandboxService;
        private readonly int _pythonTimeoutSec;
        private readonly IReadOnlyList<string> _pythonCandidates;
        private string? _pythonExePath;
        private DateTime _lastFindAttempt = DateTime.MinValue;
        private static readonly TimeSpan FindRetryInterval = TimeSpan.FromSeconds(60);
        private static readonly IReadOnlyDictionary<string, (string Distribution, string Version)> RequiredOfficeDependencies =
            new Dictionary<string, (string Distribution, string Version)>(StringComparer.OrdinalIgnoreCase)
            {
                ["docx"] = ("python-docx", "1.2.0"),
                ["openpyxl"] = ("openpyxl", "3.1.5"),
                ["pptx"] = ("python-pptx", "1.0.2"),
                ["pypdf"] = ("pypdf", "6.14.2"),
            };
        private readonly Dictionary<string, string> _officeDependencyStatus =
            new(StringComparer.OrdinalIgnoreCase);

        public IReadOnlyDictionary<string, string> OfficeDependencyStatus => _officeDependencyStatus;
        public bool OfficeDependenciesReady => RequiredOfficeDependencies.All(required =>
            _officeDependencyStatus.TryGetValue(required.Value.Distribution, out var installed)
            && installed == required.Value.Version);

        public PythonExecutionService(
            RiskAssessmentService riskService,
            SandboxService sandboxService,
            IReadOnlyList<string> pythonCandidates,
            int pythonTimeoutSec)
        {
            _riskService = riskService;
            _sandboxService = sandboxService;
            _pythonTimeoutSec = pythonTimeoutSec;
            _pythonCandidates = pythonCandidates?.Where(candidate => !string.IsNullOrWhiteSpace(candidate)).ToArray()
                ?? Array.Empty<string>();
            if (_pythonCandidates.Count == 0)
                throw new InvalidOperationException("Missing required configuration: python_service.python_candidates");
            _pythonExePath = FindPythonExe(_pythonCandidates);
            RefreshOfficeDependencyStatus();
        }

        private static string? FindPythonExe(IEnumerable<string> configuredCandidates)
        {
            var baseDir = AppDomain.CurrentDomain.BaseDirectory;

            foreach (var configuredCandidate in configuredCandidates)
            {
                var expanded = Environment.ExpandEnvironmentVariables(configuredCandidate.Trim());
                var candidate = IsBareCommand(expanded)
                    ? expanded
                    : Path.GetFullPath(Path.IsPathRooted(expanded) ? expanded : Path.Combine(baseDir, expanded));
                if (TestPythonCandidate(candidate))
                    return candidate;
            }

            return null;
        }

        private static bool IsBareCommand(string candidate) =>
            !candidate.Contains(Path.DirectorySeparatorChar) &&
            !candidate.Contains(Path.AltDirectorySeparatorChar) &&
            !Path.IsPathRooted(candidate);

        private static bool TestPythonCandidate(string candidate)
        {
            if (!IsBareCommand(candidate) && !File.Exists(candidate))
                return false;
            try
            {
                var startInfo = new ProcessStartInfo
                {
                    FileName = candidate,
                    Arguments = "--version",
                    UseShellExecute = false,
                    CreateNoWindow = true,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                };
                using var process = Process.Start(startInfo);
                if (process == null || !process.WaitForExit(5000))
                    return false;
                var output = process.StandardOutput.ReadToEnd() + process.StandardError.ReadToEnd();
                return process.ExitCode == 0 && output.Contains("Python 3.", StringComparison.OrdinalIgnoreCase);
            }
            catch
            {
                return false;
            }
        }

        public bool IsPythonAvailable()
        {
            if (_pythonExePath != null && (IsBareCommand(_pythonExePath) || File.Exists(_pythonExePath)))
                return true;

            // Re-detect if enough time has passed since last attempt
            if (DateTime.UtcNow - _lastFindAttempt >= FindRetryInterval)
            {
                _lastFindAttempt = DateTime.UtcNow;
                _pythonExePath = FindPythonExe(_pythonCandidates);
                if (_pythonExePath != null)
                    RefreshOfficeDependencyStatus();
            }
            return _pythonExePath != null;
        }

        private void RefreshOfficeDependencyStatus()
        {
            _officeDependencyStatus.Clear();
            foreach (var dependency in RequiredOfficeDependencies.Values.Distinct())
            {
                _officeDependencyStatus[dependency.Distribution] = GetInstalledDistributionVersion(dependency.Distribution);
            }
        }

        private string GetInstalledDistributionVersion(string distribution)
        {
            if (_pythonExePath == null)
                return "missing";

            try
            {
                var startInfo = new ProcessStartInfo
                {
                    FileName = _pythonExePath,
                    Arguments = $"-c \"import importlib.metadata as m; print(m.version('{distribution}'))\"",
                    UseShellExecute = false,
                    CreateNoWindow = true,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                };
                using var process = Process.Start(startInfo);
                if (process == null || !process.WaitForExit(5000) || process.ExitCode != 0)
                    return "missing";
                var version = process.StandardOutput.ReadToEnd().Trim();
                return string.IsNullOrWhiteSpace(version) ? "missing" : version;
            }
            catch
            {
                return "missing";
            }
        }

        private static List<string> MissingOfficeDependenciesForCode(string code)
        {
            var missingCandidates = new List<string>();
            foreach (var dependency in RequiredOfficeDependencies)
            {
                var module = Regex.Escape(dependency.Key);
                var usesModule = Regex.IsMatch(
                    code,
                    $@"(?im)^\s*(?:from\s+{module}(?:\.|\s)|import\s+[^\r\n]*\b{module}\b)"
                );
                if (usesModule)
                    missingCandidates.Add(dependency.Key);
            }
            return missingCandidates;
        }

        // Main execution method
        public async Task<ExecutionResult> ExecuteAsync(string code, string? args = null, bool autoApproveMediumRisk = true, string? cwd = null, int? timeoutSec = null, bool managedWork = false, bool approvalGranted = false)
        {
            // 1. Pre-execution syntax check
            var syntaxResult = await CheckSyntaxAsync(code);
            if (syntaxResult != null)
            {
                return syntaxResult; // syntax error with details
            }

            // 2. Risk assessment for Python code
            var riskResult = _riskService.AssessPythonScript(code);

            // 3. Check if Python interpreter is available
            if (!IsPythonAvailable())
            {
                return new ExecutionResult
                {
                    Status = "error",
                    Error = "Python 3.11 interpreter not found. Please ensure python.exe is available in the embedded python directory.",
                    FullCommand = code,
                    RiskLevel = riskResult.Level.ToString().ToLower(),
                    MatchedRules = riskResult.MatchedRules
                };
            }

            // 4. Office scripts fail before execution if the managed runtime is incomplete.
            var requestedOfficeModules = MissingOfficeDependenciesForCode(code);
            var missingOfficePackages = requestedOfficeModules
                .Select(module => RequiredOfficeDependencies[module])
                .Where(required =>
                    !_officeDependencyStatus.TryGetValue(required.Distribution, out var installed)
                    || installed != required.Version)
                .Select(required =>
                {
                    var installed = _officeDependencyStatus.TryGetValue(required.Distribution, out var value)
                        ? value
                        : "missing";
                    return $"{required.Distribution}=={required.Version} (installed: {installed})";
                })
                .Distinct()
                .ToList();
            if (missingOfficePackages.Count > 0)
            {
                return new ExecutionResult
                {
                    Status = "error",
                    ErrorCode = "missing_runtime_dependency",
                    Error = "missing_runtime_dependency: MoonYa 管理的 Office 运行环境缺少固定依赖或版本不匹配："
                        + string.Join(", ", missingOfficePackages)
                        + "。请通过 MoonYa 安装/升级流程修复环境；Agent 不得临时执行 pip install。",
                    FullCommand = code,
                    RiskLevel = riskResult.Level.ToString().ToLower(),
                    MatchedRules = riskResult.MatchedRules
                };
            }

            // 5. User confirmation: High risk always confirms; Medium risk only if not auto-approved
            var needsConfirmation = !approvalGranted && (riskResult.Level == RiskLevel.High ||
                (riskResult.RequiresConfirmation && !autoApproveMediumRisk));
            if (needsConfirmation)
            {
                var confirmed = await ShowConfirmationDialog(code, riskResult);
                if (!confirmed)
                {
                    return new ExecutionResult
                    {
                        Status = "rejected",
                        Error = "User rejected the Python script execution.\n建议：该命令被判定为高风险，请简化命令或拆分为低风险子步骤",
                        FullCommand = code,
                        RiskLevel = riskResult.Level.ToString().ToLower(),
                        MatchedRules = riskResult.MatchedRules
                    };
                }
            }

            // 6. Execute in sandbox
            return await ExecuteInSandboxAsync(code, args, riskResult, autoApproveMediumRisk, cwd, timeoutSec, managedWork);
        }

        // Pre-execution Python syntax check: writes code to a temp .py file and uses
        // python -m py_compile to validate, avoiding command-line argument escaping hell.
        private async Task<ExecutionResult?> CheckSyntaxAsync(string code)
        {
            if (!IsPythonAvailable()) return null; // skip syntax check if no python

            var tempDir = Path.Combine(Path.GetTempPath(), $"moonya_syn_{Guid.NewGuid():N}");
            var scriptPath = Path.Combine(tempDir, "check.py");

            try
            {
                Directory.CreateDirectory(tempDir);
                await File.WriteAllTextAsync(scriptPath, code, Encoding.UTF8);

                var startInfo = new ProcessStartInfo
                {
                    FileName = _pythonExePath!,
                    Arguments = $"-m py_compile \"{scriptPath}\"",
                    UseShellExecute = false,
                    CreateNoWindow = true,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    WorkingDirectory = tempDir,
                };

                using var process = Process.Start(startInfo);
                if (process == null) return null;

                var completed = process.WaitForExit(10000);
                var errorOutput = process.StandardError.ReadToEnd();

                if (!completed || process.ExitCode != 0)
                {
                    // Parse error message for line number info
                    var cleanError = ParsePythonError(errorOutput, code);
                    return new ExecutionResult
                    {
                        Status = "error",
                        Error = cleanError,
                        FullCommand = code,
                        RiskLevel = "low"
                    };
                }
            }
            catch (Exception ex)
            {
                // If syntax check itself fails, just skip it and try execution
                System.Diagnostics.Debug.WriteLine($"Python syntax check failed: {ex.Message}");
            }
            finally
            {
                try
                {
                    if (Directory.Exists(tempDir))
                        Directory.Delete(tempDir, true);
                }
                catch { }
            }

            return null; // no syntax error
        }

        // Parse Python error output into user-friendly format with suggestions
        private static string ParsePythonError(string errorOutput, string originalCode)
        {
            if (string.IsNullOrWhiteSpace(errorOutput))
                return "Python syntax error detected, but error details are unavailable.";

            var lines = errorOutput.Split('\n');
            var result = new StringBuilder();
            result.AppendLine("Python语法检查错误:");

            foreach (var line in lines)
            {
                var trimmed = line.Trim();
                if (!string.IsNullOrEmpty(trimmed))
                {
                    result.AppendLine($"  {trimmed}");
                }
            }

            // Extract line number and provide context
            var lineMatch = Regex.Match(errorOutput, @"line (\d+)");
            if (lineMatch.Success)
            {
                result.AppendLine();
                result.AppendLine($"错误位置: 第 {lineMatch.Groups[1].Value} 行");

                if (errorOutput.Contains("SyntaxError"))
                {
                    result.AppendLine("修改建议: 请检查该行代码的语法，确保括号匹配、引号正确、缩进使用空格等。");
                }
                else if (errorOutput.Contains("IndentationError"))
                {
                    result.AppendLine("修改建议: 请检查缩进是否一致，建议统一使用4个空格缩进。");
                }
                else if (errorOutput.Contains("NameError"))
                {
                    result.AppendLine("修改建议: 请检查变量或函数名是否拼写正确，是否在使用前已定义。");
                }
            }

            return result.ToString().TrimEnd();
        }

        // Execute Python code in sandbox
        private async Task<ExecutionResult> ExecuteInSandboxAsync(string code, string? args, RiskAssessmentResult riskResult, bool autoApproveMediumRisk, string? cwd, int? timeoutSec, bool managedWork)
        {
            var sandboxDir = _sandboxService.CreateSandboxDirectory();
            var scriptPath = Path.Combine(sandboxDir, $"script_{Guid.NewGuid():N}.py");
            var stopwatch = Stopwatch.StartNew();
            var effectiveTimeout = timeoutSec ?? (managedWork ? 0 : _pythonTimeoutSec);

            try
            {
                // Write the Python script to a temp file in sandbox
                await File.WriteAllTextAsync(scriptPath, code, Encoding.UTF8);

                var arguments = $"\"{scriptPath}\"";
                if (!string.IsNullOrWhiteSpace(args))
                {
                    arguments += $" {args}";
                }

                // Determine working directory: caller-provided cwd (if it exists) wins, else sandbox
                var workingDir = (!string.IsNullOrEmpty(cwd) && Directory.Exists(cwd)) ? cwd : sandboxDir;

                var startInfo = _sandboxService.CreateSandboxedProcessStartInfo(_pythonExePath!, arguments, workingDir);

                using var process = new Process { StartInfo = startInfo };
                var outputBuilder = new StringBuilder();
                var errorBuilder = new StringBuilder();
                const int MaxOutputBytes = 1048576;
                bool outputTruncated = false, errorTruncated = false;

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
                process.BeginOutputReadLine();
                process.BeginErrorReadLine();

                // Close stdin immediately to send EOF — prevents scripts that call input() from blocking forever
                try { process.StandardInput.Close(); } catch { }

                _sandboxService.ApplyResourceLimits(process, sandboxDir);

                var completedNormally = true;
                if (effectiveTimeout > 0)
                    completedNormally = process.WaitForExit(effectiveTimeout * 1000);
                else
                    process.WaitForExit();
                stopwatch.Stop();

                if (!completedNormally)
                {
                    try { process.Kill(entireProcessTree: true); } catch { }
                    // Cancel async reads to flush any remaining buffered data
                    try { process.CancelOutputRead(); } catch { }
                    try { process.CancelErrorRead(); } catch { }
                    process.WaitForExit(3000);
                    _sandboxService.ReleaseJobHandle(process.Id);

                    _sandboxService.CleanupSandbox(sandboxDir);
                    return new ExecutionResult
                    {
                        Status = "error",
                        Output = outputBuilder.ToString(),
                        Error = $"Python script timed out after {effectiveTimeout} seconds and was terminated.\n建议：检查脚本是否存在死循环，或通过 params.timeout_sec 参数延长超时时间",
                        DurationMs = stopwatch.ElapsedMilliseconds,
                        RiskLevel = riskResult.Level.ToString().ToLower(),
                        MatchedRules = riskResult.MatchedRules,
                        FullCommand = code
                    };
                }

                // Ensure all async output has been delivered to the event handlers
                process.CancelOutputRead();
                process.CancelErrorRead();
                _sandboxService.ReleaseJobHandle(process.Id);

                var status = process.ExitCode == 0 ? "success" : "error";
                var errorMsg = errorBuilder.ToString();
                if (process.ExitCode != 0 && string.IsNullOrWhiteSpace(errorMsg))
                {
                    errorMsg = $"Python process exited with code {process.ExitCode}";
                }

                return new ExecutionResult
                {
                    Status = status,
                    Output = outputBuilder.ToString(),
                    Error = errorMsg,
                    ExitCode = process.ExitCode,
                    DurationMs = stopwatch.ElapsedMilliseconds,
                    RiskLevel = riskResult.Level.ToString().ToLower(),
                    MatchedRules = riskResult.MatchedRules,
                    FullCommand = code
                };
            }
            catch (Exception ex)
            {
                stopwatch.Stop();
                return new ExecutionResult
                {
                    Status = "error",
                    Output = "",
                    Error = $"Python execution failed: {ex.Message}",
                    DurationMs = stopwatch.ElapsedMilliseconds,
                    RiskLevel = riskResult.Level.ToString().ToLower(),
                    MatchedRules = riskResult.MatchedRules,
                    FullCommand = code
                };
            }
            finally
            {
                _sandboxService.CleanupSandbox(sandboxDir);
            }
        }

        private Task<bool> ShowConfirmationDialog(string code, RiskAssessmentResult risk)
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
                var displayCode = code.Length > 500 ? code[..500] + "\n... (truncated)" : code;

                var message = $"风险等级: {riskLabel}\n\n" +
                              $"检测到以下风险规则:\n{rulesText}\n\n" +
                              $"Python代码:\n{displayCode}\n\n" +
                              $"说明: {risk.Description}\n\n" +
                              $"是否确认执行此Python脚本？\n\n（30秒后自动拒绝）";

                var window = new System.Windows.Window
                {
                    Title = "Python脚本执行确认 - MoonYa",
                    Width = 500,
                    Height = 450,
                    WindowStartupLocation = System.Windows.WindowStartupLocation.CenterScreen,
                    Topmost = true,
                    ResizeMode = System.Windows.ResizeMode.CanResize,
                };

                var panel = new System.Windows.Controls.StackPanel { Margin = new System.Windows.Thickness(15) };
                var msgBlock = new System.Windows.Controls.TextBlock
                {
                    Text = message,
                    TextWrapping = System.Windows.TextWrapping.Wrap,
                    MaxHeight = 300,
                };
                var btnPanel = new System.Windows.Controls.StackPanel
                {
                    Orientation = System.Windows.Controls.Orientation.Horizontal,
                    HorizontalAlignment = System.Windows.HorizontalAlignment.Center,
                    Margin = new System.Windows.Thickness(0, 15, 0, 0),
                };
                var yesBtn = new System.Windows.Controls.Button { Content = "确认执行", Padding = new System.Windows.Thickness(20, 8, 20, 8), Margin = new System.Windows.Thickness(5) };
                var noBtn = new System.Windows.Controls.Button { Content = "拒绝", Padding = new System.Windows.Thickness(20, 8, 20, 8), Margin = new System.Windows.Thickness(5) };
                btnPanel.Children.Add(yesBtn);
                btnPanel.Children.Add(noBtn);
                panel.Children.Add(msgBlock);
                panel.Children.Add(btnPanel);
                window.Content = panel;

                yesBtn.Click += (_, _) => { tcs.TrySetResult(true); window.Close(); };
                noBtn.Click += (_, _) => { tcs.TrySetResult(false); window.Close(); };
                window.Closed += (_, _) => { tcs.TrySetResult(false); };

                var timer = new System.Windows.Threading.DispatcherTimer
                {
                    Interval = TimeSpan.FromSeconds(30)
                };
                timer.Tick += (_, _) => { timer.Stop(); tcs.TrySetResult(false); window.Close(); };
                timer.Start();

                window.ShowDialog();
            });

            return tcs.Task;
        }
    }
}
