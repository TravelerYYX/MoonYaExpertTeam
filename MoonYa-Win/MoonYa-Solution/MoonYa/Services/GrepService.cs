// ┌─────────────────────────────────────────────────────────┐
// │  GrepService — 基于 ripgrep 的内容搜索服务              │
// │  支持：正则、文件类型过滤、输出模式、上下文行 -A/-B/-C │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    public class GrepService
    {
        // 单文件最大返回行数（超过则截断）
        private const int MaxLinesPerFile = 100;

        private readonly GrepServiceConfig _config;
        private readonly string _toolsDir;
        private readonly string _rgPath;

        // 防止重复触发后台下载
        private static readonly object DownloadLock = new object();
        private static bool _downloadTriggered = false;

        public GrepService(GrepServiceConfig config)
        {
            _config = config ?? throw new ArgumentNullException(nameof(config));
            if (string.IsNullOrWhiteSpace(config.DownloadDirectory))
                throw new InvalidOperationException("缺少必填配置字段 grep.download_directory");
            if (string.IsNullOrWhiteSpace(config.DownloadUrl))
                throw new InvalidOperationException("缺少必填配置字段 grep.download_url");
            if (!Uri.TryCreate(config.DownloadUrl, UriKind.Absolute, out var downloadUri) || downloadUri.Scheme != Uri.UriSchemeHttps)
                throw new InvalidOperationException("配置字段 grep.download_url 必须是 HTTPS URL");
            if (config.DownloadTimeoutSeconds < 1)
                throw new InvalidOperationException("缺少或无效的必填配置字段 grep.download_timeout_seconds");
            if (config.ExecutionTimeoutSeconds < 1)
                throw new InvalidOperationException("缺少或无效的必填配置字段 grep.execution_timeout_seconds");
            if (config.ExecutableCandidatePaths == null || config.ExecutableCandidatePaths.Count == 0)
                throw new InvalidOperationException("缺少必填配置字段 grep.executable_candidate_paths");

            _toolsDir = ResolveConfiguredPath(config.DownloadDirectory);
            _rgPath = Path.Combine(_toolsDir, "rg.exe");
        }

        /// <summary>
        /// 执行 grep 搜索。基于 ripgrep，支持正则/文件类型/上下文行等多种参数。
        /// </summary>
        public async Task<object> SearchAsync(GrepParams p)
        {
            try
            {
                // 参数校验：pattern 必填
                if (string.IsNullOrWhiteSpace(p.Pattern))
                    return new { success = false, error = "pattern 不能为空" };

                // 定位 ripgrep 可执行文件
                var rgExe = ResolveRipgrep();
                if (rgExe == null)
                {
                    // ripgrep 不存在，触发后台下载并返回提示
                    TriggerBackgroundDownload();
                    return new { success = false, error = "ripgrep 未安装，正在下载..." };
                }

                // 解析搜索根目录（复用 FileOperationService 的 ResolvePath 逻辑）
                var searchPath = string.IsNullOrWhiteSpace(p.Path) || p.Path == "."
                    ? Environment.CurrentDirectory
                    : FileOperationService.ResolvePath(p.Path);

                if (!Directory.Exists(searchPath))
                    return new { success = false, error = $"搜索路径不存在: {searchPath}" };

                // 构建 ripgrep 命令行参数
                var args = BuildRipgrepArgs(p, searchPath);

                // 启动 ripgrep 进程并捕获 stdout
                var (output, exitCode) = await RunRipgrepAsync(rgExe, args);

                // ripgrep 退出码：0=有匹配 1=无匹配 2=错误
                if (exitCode == 2)
                    return new { success = false, error = $"ripgrep 执行错误: {output}" };

                // 按输出模式解析结果
                var outputMode = string.IsNullOrEmpty(p.OutputMode) ? "content" : p.OutputMode;
                object result = outputMode switch
                {
                    "files_with_matches" => ParseFilesWithMatches(output),
                    "count" => ParseCount(output),
                    _ => ParseContent(output)
                };
                return result;
            }
            catch (Exception ex)
            {
                return new { success = false, error = $"grep 执行失败: {ex.Message}" };
            }
        }

        // ── ripgrep 可执行文件定位 ────────────────────────

        /// <summary>
        /// 按优先级定位 ripgrep 可执行文件：
        /// 1. %LOCALAPPDATA%\MoonYa\tools\rg.exe
        /// 2. bin\rg.exe（应用程序基目录）
        /// 3. 返回 null（需动态下载）
        /// </summary>
        private string? ResolveRipgrep()
        {
            foreach (var candidate in _config.ExecutableCandidatePaths)
            {
                var resolved = ResolveConfiguredPath(candidate);
                if (File.Exists(resolved)) return resolved;
            }
            if (File.Exists(_rgPath)) return _rgPath;
            return null;
        }

        private static string ResolveConfiguredPath(string configuredPath)
        {
            var expanded = Environment.ExpandEnvironmentVariables(configuredPath.Trim());
            return Path.GetFullPath(Path.IsPathRooted(expanded)
                ? expanded
                : Path.Combine(AppDomain.CurrentDomain.BaseDirectory, expanded));
        }

        /// <summary>
        /// 触发后台下载 ripgrep（fire-and-forget），防止重复下载。
        /// </summary>
        private void TriggerBackgroundDownload()
        {
            lock (DownloadLock)
            {
                if (_downloadTriggered) return;
                _downloadTriggered = true;
                _ = Task.Run(DownloadRipgrepAsync);
            }
        }

        /// <summary>
        /// 从 GitHub Releases 下载 ripgrep 并解压到 %LOCALAPPDATA%\MoonYa\tools\rg.exe。
        /// </summary>
        private async Task DownloadRipgrepAsync()
        {
            try
            {
                Directory.CreateDirectory(_toolsDir);
                var zipPath = Path.Combine(_toolsDir, "rg.zip");

                using var client = new HttpClient
                {
                    Timeout = TimeSpan.FromSeconds(_config.DownloadTimeoutSeconds)
                };

                var bytes = await client.GetByteArrayAsync(_config.DownloadUrl);
                await File.WriteAllBytesAsync(zipPath, bytes);

                // 解压并提取 rg.exe
                using var archive = ZipFile.OpenRead(zipPath);
                var entry = archive.Entries.FirstOrDefault(e => e.Name == "rg.exe");
                if (entry != null)
                {
                    entry.ExtractToFile(_rgPath, overwrite: true);
                    Debug.WriteLine($"GrepService: ripgrep 下载完成 -> {_rgPath}");
                }

                try { File.Delete(zipPath); } catch { }
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"GrepService: ripgrep 下载失败: {ex.Message}");
                // 重置标志，允许下次重试
                lock (DownloadLock) { _downloadTriggered = false; }
            }
        }

        // ── 命令行参数构建 ────────────────────────────────

        /// <summary>
        /// 根据 GrepParams 构建 ripgrep 命令行参数列表。
        /// </summary>
        private static List<string> BuildRipgrepArgs(GrepParams p, string searchPath)
        {
            var args = new List<string>();
            var outputMode = string.IsNullOrEmpty(p.OutputMode) ? "content" : p.OutputMode;

            // 输出模式标志
            switch (outputMode)
            {
                case "files_with_matches":
                    args.Add("--files-with-matches");
                    break;
                case "count":
                    args.Add("--count");
                    break;
                default:
                    // content 模式使用 --json 输出结构化数据，便于解析
                    args.Add("--json");
                    break;
            }

            // 大小写不敏感
            if (p.CaseInsensitive)
                args.Add("-i");

            // 上下文行参数（仅 content 模式有意义）
            if (outputMode == "content")
            {
                if (p.Context.HasValue)
                {
                    args.Add("-C");
                    args.Add(p.Context.Value.ToString());
                }
                else
                {
                    if (p.ContextAfter.HasValue)
                    {
                        args.Add("-A");
                        args.Add(p.ContextAfter.Value.ToString());
                    }
                    if (p.ContextBefore.HasValue)
                    {
                        args.Add("-B");
                        args.Add(p.ContextBefore.Value.ToString());
                    }
                }
            }

            // 文件名 glob 过滤（如 *.php）
            if (!string.IsNullOrWhiteSpace(p.GlobFilter))
            {
                args.Add("--glob");
                args.Add(p.GlobFilter);
            }

            // 文件类型过滤（如 php/py/js）
            if (!string.IsNullOrWhiteSpace(p.TypeFilter))
            {
                args.Add("--type");
                args.Add(p.TypeFilter);
            }

            // 使用 -e 指定 pattern，避免以 - 开头的正则被解析为选项
            args.Add("-e");
            args.Add(p.Pattern);

            // 搜索路径
            args.Add(searchPath);

            return args;
        }

        // ── 进程执行 ──────────────────────────────────────

        /// <summary>
        /// 启动 ripgrep 进程并捕获 stdout。带 60 秒超时保护。
        /// </summary>
        private async Task<(string output, int exitCode)> RunRipgrepAsync(string rgExe, List<string> args)
        {
            var psi = new ProcessStartInfo
            {
                FileName = rgExe,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                StandardOutputEncoding = Encoding.UTF8,
                StandardErrorEncoding = Encoding.UTF8
            };

            // 使用 ArgumentList 自动处理参数转义
            foreach (var arg in args)
            {
                psi.ArgumentList.Add(arg);
            }

            using var process = new Process { StartInfo = psi };
            process.Start();

            var stdoutTask = process.StandardOutput.ReadToEndAsync();
            var stderrTask = process.StandardError.ReadToEndAsync();

            // 60 秒超时保护，避免超大目录搜索卡死
            using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(_config.ExecutionTimeoutSeconds));
            try
            {
                await process.WaitForExitAsync(cts.Token);
            }
            catch (OperationCanceledException)
            {
                try { process.Kill(); } catch { }
                return ($"ripgrep 执行超时（{_config.ExecutionTimeoutSeconds} 秒）", 2);
            }

            var stdout = await stdoutTask;
            var stderr = await stderrTask;

            // 退出码 2 表示错误，返回 stderr 作为错误信息
            if (process.ExitCode == 2)
                return (stderr, 2);

            return (stdout, process.ExitCode);
        }

        // ── 输出解析 ──────────────────────────────────────

        /// <summary>
        /// 解析 content 模式输出（ripgrep --json 的 NDJSON 格式）。
        /// 输出结构：{ success, matches: [{path, line, content, type}], truncated, truncated_files }
        /// </summary>
        private static object ParseContent(string output)
        {
            var matches = new List<object>();
            var fileLineCount = new Dictionary<string, int>(StringComparer.Ordinal);
            var truncatedFiles = new HashSet<string>(StringComparer.Ordinal);

            foreach (var line in output.Split('\n'))
            {
                if (string.IsNullOrWhiteSpace(line)) continue;

                JsonElement doc;
                try
                {
                    doc = JsonSerializer.Deserialize<JsonElement>(line);
                }
                catch
                {
                    continue;
                }

                if (!doc.TryGetProperty("type", out var typeProp)) continue;
                var type = typeProp.GetString();
                // 只关心 match 和 context 类型（忽略 begin/end/summary）
                if (type != "match" && type != "context") continue;

                if (!doc.TryGetProperty("data", out var data)) continue;

                // 提取文件路径（path.text）
                string? path = null;
                if (data.TryGetProperty("path", out var pathProp))
                {
                    path = pathProp.TryGetProperty("text", out var textProp)
                        ? textProp.GetString()
                        : pathProp.GetString();
                }
                if (string.IsNullOrEmpty(path)) continue;

                // 单文件匹配超过 100 行则截断
                if (fileLineCount.TryGetValue(path!, out var count) && count >= MaxLinesPerFile)
                {
                    truncatedFiles.Add(path!);
                    continue;
                }
                fileLineCount[path!] = count + 1;

                // 提取行号
                int lineNum = 0;
                if (data.TryGetProperty("line_number", out var lnProp) &&
                    lnProp.ValueKind == JsonValueKind.Number)
                {
                    lineNum = lnProp.GetInt32();
                }

                // 提取行内容（lines.text），去掉末尾换行符
                string? content = null;
                if (data.TryGetProperty("lines", out var linesProp))
                {
                    content = linesProp.TryGetProperty("text", out var linesText)
                        ? linesText.GetString()
                        : linesProp.GetString();
                }
                if (content != null) content = content.TrimEnd('\r', '\n');

                matches.Add(new
                {
                    path,
                    line = lineNum,
                    content,
                    type = type == "context" ? "context" : "match"
                });
            }

            return new
            {
                success = true,
                matches,
                truncated = truncatedFiles.Count > 0,
                truncated_files = truncatedFiles
            };
        }

        /// <summary>
        /// 解析 files_with_matches 模式输出（纯文本，每行一个文件路径）。
        /// 输出结构：{ success, files: [path...] }
        /// </summary>
        private static object ParseFilesWithMatches(string output)
        {
            var files = new List<string>();
            foreach (var line in output.Split('\n'))
            {
                var trimmed = line.TrimEnd('\r');
                if (!string.IsNullOrEmpty(trimmed))
                    files.Add(trimmed);
            }
            return new { success = true, files };
        }

        /// <summary>
        /// 解析 count 模式输出（纯文本，格式 path:count）。
        /// 输出结构：{ success, counts: [{path, count}] }
        /// </summary>
        private static object ParseCount(string output)
        {
            var counts = new List<object>();
            foreach (var line in output.Split('\n'))
            {
                var trimmed = line.TrimEnd('\r');
                if (string.IsNullOrEmpty(trimmed)) continue;

                // 格式：path:count（按最后一个冒号分割，兼容 Windows 路径中的冒号）
                var lastColon = trimmed.LastIndexOf(':');
                if (lastColon > 0)
                {
                    var path = trimmed.Substring(0, lastColon);
                    if (int.TryParse(trimmed.Substring(lastColon + 1), out var count))
                        counts.Add(new { path, count });
                }
            }
            return new { success = true, counts };
        }
    }

    public sealed class GrepServiceConfig
    {
        public string DownloadDirectory { get; init; } = string.Empty;
        public string DownloadUrl { get; init; } = string.Empty;
        public int DownloadTimeoutSeconds { get; init; }
        public int ExecutionTimeoutSeconds { get; init; }
        public IReadOnlyList<string> ExecutableCandidatePaths { get; init; } = Array.Empty<string>();
    }

    /// <summary>
    /// grep 工具参数。与 PHP 端 agent_config.php 中 grep 工具定义对应。
    /// </summary>
    public class GrepParams
    {
        /// <summary>正则表达式（必填）</summary>
        public string Pattern { get; set; } = "";

        /// <summary>搜索根目录，默认当前目录</summary>
        public string Path { get; set; } = ".";

        /// <summary>输出模式：content / files_with_matches / count，默认 content</summary>
        public string OutputMode { get; set; } = "content";

        /// <summary>上下文行数（-B，匹配行之前）</summary>
        public int? ContextBefore { get; set; }

        /// <summary>上下文行数（-A，匹配行之后）</summary>
        public int? ContextAfter { get; set; }

        /// <summary>上下文行数（-C，同时设置 -A 和 -B）</summary>
        public int? Context { get; set; }

        /// <summary>是否显示行号（-n，默认 true）</summary>
        public bool ShowLineNumbers { get; set; } = true;

        /// <summary>是否大小写不敏感（-i，默认 false）</summary>
        public bool CaseInsensitive { get; set; } = false;

        /// <summary>文件名 glob 过滤（--glob，如 *.php）</summary>
        public string? GlobFilter { get; set; }

        /// <summary>文件类型过滤（--type，如 php/py/js）</summary>
        public string? TypeFilter { get; set; }
    }
}
