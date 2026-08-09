using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.Json;

namespace MoonYa.Services
{
    /// <summary>
    /// 管理 MoonYa-Python/main.py 进程（统一爬虫+搜索后端）
    /// </summary>
    public class WebCrawlerService
    {
        private Process? _process;
        private readonly string _scriptPath;
        private readonly int _port;
        private readonly string _logPath;
        private readonly string _configPath;
        private readonly string _pythonExecutable;

        public int Port => _port;
        public bool IsRunning => _process != null && !_process.HasExited;

        public WebCrawlerService(int port, string configPath)
        {
            _port = port;
            var baseDir = AppDomain.CurrentDomain.BaseDirectory;
            (_scriptPath, _pythonExecutable, _configPath) = LoadRuntime(configPath, baseDir);
            _logPath = Path.Combine(baseDir, "python_backend.log");
        }

        /// <summary>
        /// 写入日志文件（带时间戳）
        /// </summary>
        private void Log(string message, string level = "INFO")
        {
            try
            {
                File.AppendAllText(_logPath,
                    $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}] [{level}] {message}{Environment.NewLine}",
                    Encoding.UTF8);
            }
            catch { }
        }

        private static (string ScriptPath, string PythonExecutable, string ServiceConfigPath) LoadRuntime(string configPath, string baseDir)
        {
            using var document = JsonDocument.Parse(File.ReadAllText(configPath));
            if (!document.RootElement.TryGetProperty("python_service", out var service) ||
                service.ValueKind != JsonValueKind.Object)
                throw new InvalidOperationException("launcher_config.json 缺少 python_service 配置段");

            var scriptOverride = Environment.GetEnvironmentVariable("MOONYA_PYTHON_SCRIPT");
            var pythonOverride = Environment.GetEnvironmentVariable("MOONYA_PYTHON_EXECUTABLE");
            var configOverride = Environment.GetEnvironmentVariable("MOONYA_PYTHON_SERVICE_CONFIG");
            var rootsOverride = Environment.GetEnvironmentVariable("MOONYA_PYTHON_PATH_ROOTS");
            var candidateRoots = !string.IsNullOrWhiteSpace(rootsOverride)
                ? rootsOverride.Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
                : ReadStringArray(service, "candidate_roots");
            if (candidateRoots.Length == 0)
                throw new InvalidOperationException("缺少 python_service.candidate_roots（环境变量 MOONYA_PYTHON_PATH_ROOTS 可覆盖）");
            var scriptCandidates = !string.IsNullOrWhiteSpace(scriptOverride)
                ? new[] { scriptOverride }
                : ReadStringArray(service, "script_candidate_paths");
            var pythonCandidates = !string.IsNullOrWhiteSpace(pythonOverride)
                ? new[] { pythonOverride }
                : ReadStringArray(service, "python_candidates");
            var configCandidates = !string.IsNullOrWhiteSpace(configOverride)
                ? new[] { configOverride }
                : ReadStringArray(service, "config_candidate_paths");

            var script = ResolveFirstExisting(scriptCandidates, candidateRoots, configPath, baseDir);
            if (script == null)
                throw new InvalidOperationException("缺少可用的 python_service.script_candidate_paths 或 MOONYA_PYTHON_SCRIPT");
            var python = ResolvePython(pythonCandidates, candidateRoots, configPath, baseDir);
            if (python == null)
                throw new InvalidOperationException("缺少可用的 python_service.python_candidates 或 MOONYA_PYTHON_EXECUTABLE");
            var serviceConfig = ResolveFirstExisting(configCandidates, candidateRoots, configPath, baseDir);
            if (serviceConfig == null)
                throw new InvalidOperationException("缺少可用的 python_service.config_candidate_paths 或 MOONYA_PYTHON_SERVICE_CONFIG");
            return (script, python, serviceConfig);
        }

        public void Start()
        {
            if (IsRunning) return;

            Log("启动 Python 后端服务...");

            if (!File.Exists(_scriptPath))
            {
                Log($"SCRIPT_NOT_FOUND: {_scriptPath}", "ERROR");
                return;
            }

            Log($"Python: {_pythonExecutable}");
            Log($"Script: {_scriptPath}");

            // 传递给 main.py 的 config.json 路径
            var args = $"\"{_scriptPath}\" \"{_configPath}\"";

            Log($"Args: {args}");

            var startInfo = new ProcessStartInfo
            {
                FileName = _pythonExecutable,
                Arguments = args,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            };

            _process = new Process { StartInfo = startInfo };
            _process.EnableRaisingEvents = true;

            _process.OutputDataReceived += (_, e) =>
            {
                if (!string.IsNullOrEmpty(e.Data))
                {
                    System.Diagnostics.Debug.WriteLine($"[python] {e.Data}");
                    Log(e.Data, "PYTHON");
                }
            };
            _process.ErrorDataReceived += (_, e) =>
            {
                if (!string.IsNullOrEmpty(e.Data))
                {
                    System.Diagnostics.Debug.WriteLine($"[python-err] {e.Data}");
                    Log(e.Data, "PYTHON_ERR");
                }
            };

            _process.Exited += (_, _) =>
            {
                Log($"进程退出，退出码: {_process.ExitCode}", _process.ExitCode == 0 ? "INFO" : "ERROR");
            };

            try
            {
                _process.Start();
                _process.BeginOutputReadLine();
                _process.BeginErrorReadLine();
                Log($"Python 后端服务已启动 (PID: {_process.Id}, port: {_port})");
            }
            catch (Exception ex)
            {
                Log($"启动失败: {ex.Message}", "ERROR");
                _process = null;
            }
        }

        public void Stop()
        {
            if (_process == null) return;

            Log("正在停止 Python 后端服务...");
            try
            {
                if (!_process.HasExited)
                {
                    _process.Kill(entireProcessTree: true);
                    _process.WaitForExit(3000);
                }
            }
            catch { }
            finally
            {
                _process.Dispose();
                _process = null;
            }

            Log("Python 后端服务已停止");
        }

        private static string[] ReadStringArray(JsonElement parent, string name)
        {
            if (!parent.TryGetProperty(name, out var value) || value.ValueKind != JsonValueKind.Array)
                return Array.Empty<string>();
            return value.EnumerateArray()
                .Where(item => item.ValueKind == JsonValueKind.String)
                .Select(item => item.GetString() ?? string.Empty)
                .Where(item => !string.IsNullOrWhiteSpace(item))
                .ToArray();
        }

        private static string? ResolveFirstExisting(
            IEnumerable<string> candidates,
            IEnumerable<string> roots,
            string configPath,
            string baseDir)
        {
            var configDirectory = Path.GetDirectoryName(configPath) ?? baseDir;
            foreach (var raw in candidates)
            {
                var expanded = Environment.ExpandEnvironmentVariables(raw);
                if (Path.IsPathRooted(expanded))
                {
                    var absoluteCandidate = Path.GetFullPath(expanded);
                    if (File.Exists(absoluteCandidate)) return absoluteCandidate;
                    continue;
                }

                foreach (var rawRoot in roots)
                {
                    var expandedRoot = Environment.ExpandEnvironmentVariables(rawRoot);
                    var root = Path.IsPathRooted(expandedRoot)
                        ? Path.GetFullPath(expandedRoot)
                        : Path.GetFullPath(Path.Combine(configDirectory, expandedRoot));
                    var candidate = Path.GetFullPath(Path.Combine(root, expanded));
                    if (File.Exists(candidate)) return candidate;
                }
            }
            return null;
        }

        private static string? ResolvePython(
            IEnumerable<string> candidates,
            IEnumerable<string> roots,
            string configPath,
            string baseDir)
        {
            foreach (var raw in candidates)
            {
                var file = ResolveFirstExisting(new[] { raw }, roots, configPath, baseDir);
                if (file != null) return file;
                if (raw.IndexOfAny(new[] { Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar }) >= 0) continue;
                try
                {
                    using var process = Process.Start(new ProcessStartInfo
                    {
                        FileName = raw,
                        Arguments = "--version",
                        UseShellExecute = false,
                        CreateNoWindow = true,
                        RedirectStandardOutput = true,
                        RedirectStandardError = true,
                    });
                    if (process == null) continue;
                    process.WaitForExit(3000);
                    if (process.ExitCode == 0) return raw;
                }
                catch { }
            }
            return null;
        }
    }
}
