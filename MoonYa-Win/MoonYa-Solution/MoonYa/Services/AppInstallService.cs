// ┌─────────────────────────────────────────────────────────┐
// │  AppInstallService — 应用安装检测与安装服务              │
// │  从 launcher_config.json 加载 app_install_sources 配置   │
// │  支持检测软件是否安装、下载并执行安装包                   │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Text.Json;
using System.Threading.Tasks;
using Microsoft.Win32;

namespace MoonYa.Services
{
    public class AppInstallService
    {
        private readonly Dictionary<string, AppInstallSource> _installSources;

        // ── Constructor ────────────────────────────────────

        /// <summary>
        /// 构造函数：从 launcher_config.json 加载 app_install_sources 配置。
        /// 配置文件查找逻辑与 FileOperationService.LoadConfig 相同。
        /// </summary>
        public AppInstallService()
        {
            _installSources = LoadConfig();
        }

        // ── Config Loading ─────────────────────────────────

        /// <summary>
        /// 加载 launcher_config.json 中的 app_install_sources 配置。
        /// 查找顺序：1) 程序运行目录；2) 项目根目录（开发环境）。
        /// </summary>
        private static Dictionary<string, AppInstallSource> LoadConfig()
        {
            var configPath = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "launcher_config.json");

            // Also try project root (for development)
            if (!File.Exists(configPath))
            {
                configPath = Path.GetFullPath(Path.Combine(
                    AppDomain.CurrentDomain.BaseDirectory, "..", "..", "..", "launcher_config.json"));
            }

            if (File.Exists(configPath))
            {
                try
                {
                    var json = File.ReadAllText(configPath);
                    using var doc = JsonDocument.Parse(json);
                    if (doc.RootElement.TryGetProperty("app_install_sources", out var sourcesEl))
                    {
                        var result = new Dictionary<string, AppInstallSource>(StringComparer.OrdinalIgnoreCase);
                        foreach (var prop in sourcesEl.EnumerateObject())
                        {
                            try
                            {
                                var source = JsonSerializer.Deserialize<AppInstallSource>(
                                    prop.Value.GetRawText(),
                                    new JsonSerializerOptions { PropertyNameCaseInsensitive = true });
                                if (source != null)
                                    result[prop.Name] = source;
                            }
                            catch (Exception ex)
                            {
                                Debug.WriteLine($"AppInstallService: Failed to parse source '{prop.Name}': {ex.Message}");
                            }
                        }
                        Debug.WriteLine($"AppInstallService: Loaded {result.Count} install sources.");
                        return result;
                    }
                }
                catch (Exception ex)
                {
                    Debug.WriteLine($"AppInstallService: Failed to load config: {ex.Message}");
                }
            }

            Debug.WriteLine("AppInstallService: No install sources config found, using empty config.");
            return new Dictionary<string, AppInstallSource>(StringComparer.OrdinalIgnoreCase);
        }

        // ── Public API ─────────────────────────────────────

        /// <summary>
        /// 检测软件是否安装。多层检测策略，任一命中即返回 installed=true：
        /// 1. 运行进程检测 (process)
        /// 2. 注册表 Uninstall (registry)
        /// 3. App Paths 注册表模糊匹配 (app_paths)
        /// 4. 开始菜单快捷方式 (shortcut)
        /// 5. PATH 可执行文件 (path)
        /// 6. UWP/Store 应用 (uwp)
        /// 7. 常见安装目录扫描 (filesystem)
        /// 返回对象：{ installed, executable_path, version, detection_method }
        /// </summary>
        public async Task<object> CheckInstalled(string appName)
        {
            return await Task.Run(() =>
            {
                try
                {
                    if (string.IsNullOrWhiteSpace(appName))
                        return CheckInstalledResult(false, null, null, null);

                    // 1. 运行进程检测
                    var (procFound, procPath) = CheckRunningProcess(appName);
                    if (procFound)
                        return CheckInstalledResult(true, procPath, null, "process");

                    // 2. 注册表 Uninstall
                    var (registryFound, version) = CheckRegistryInstalled(appName);
                    if (registryFound)
                        return CheckInstalledResult(true, null, version, "registry");

                    // 3. App Paths 注册表（模糊匹配）
                    var appPathsPath = CheckAppPaths(appName);
                    if (appPathsPath != null)
                        return CheckInstalledResult(true, appPathsPath, null, "app_paths");

                    // 4. 开始菜单快捷方式
                    var appPath = FindAppPath(appName);
                    if (appPath != null)
                        return CheckInstalledResult(true, appPath, null, "shortcut");

                    // 5. PATH 可执行文件
                    var source = FindInstallSource(appName);
                    var verifyExe = source?.VerifyExecutable;
                    string? executablePath;
                    if (!string.IsNullOrWhiteSpace(verifyExe))
                        executablePath = FindExecutableInPath(verifyExe);
                    else
                    {
                        var guessExe = appName.EndsWith(".exe", StringComparison.OrdinalIgnoreCase)
                            ? appName
                            : appName + ".exe";
                        executablePath = FindExecutableInPath(guessExe);
                    }
                    if (executablePath != null)
                        return CheckInstalledResult(true, executablePath, null, "path");

                    // 6. UWP 应用检测
                    var (uwpFound, uwpPath) = CheckUwpApp(appName);
                    if (uwpFound)
                        return CheckInstalledResult(true, uwpPath, null, "uwp");

                    // 7. 文件系统扫描（3层深度）
                    var fallbackPath = FindAppByFallback(appName);
                    if (fallbackPath != null)
                        return CheckInstalledResult(true, fallbackPath, null, "filesystem");

                    return CheckInstalledResult(false, null, null, null);
                }
                catch (Exception ex)
                {
                    Debug.WriteLine($"AppInstallService: CheckInstalled failed: {ex.Message}");
                    return CheckInstalledResult(false, null, null, null);
                }
            });
        }

        /// <summary>
        /// 安装软件。
        /// 从配置的 app_install_sources 查找 appName（支持模糊匹配，如 "vscode" 匹配 "VSCode"）。
        /// 下载安装包到临时目录，通过 progress 回调上报下载进度。
        /// 下载完成后用 Process.Start 执行安装包，传入配置的 install_args。
        /// 等待安装进程完成（超时 5 分钟），安装后验证 verify_executable 是否可用。
        /// 返回对象：{ success: bool, message: string, installed_path: string? }
        /// </summary>
        public async Task<object> InstallApp(string appName, IProgress<InstallProgress>? progress = null)
        {
            if (string.IsNullOrWhiteSpace(appName))
            {
                return InstallResult(false, "应用名称不能为空", null);
            }

            try
            {
                // 1. 查找配置（模糊匹配）
                var source = FindInstallSource(appName);
                if (source == null)
                {
                    return InstallResult(false, "未找到该软件的安装源配置", null);
                }

                // 2. 下载安装包到临时目录
                var tempDir = Path.Combine(Path.GetTempPath(), "MoonYa_Install");
                if (!Directory.Exists(tempDir))
                    Directory.CreateDirectory(tempDir);

                string installerPath;
                try
                {
                    installerPath = await DownloadInstallerAsync(source.DownloadUrl, tempDir, progress);
                }
                catch (Exception ex)
                {
                    return InstallResult(false, $"下载安装包失败: {ex.Message}", null);
                }

                // 3. 执行安装包
                progress?.Report(new InstallProgress
                {
                    Percent = 100,
                    DownloadedBytes = 0,
                    TotalBytes = 0,
                    SpeedBytesPerSec = 0,
                    Message = "下载完成，正在执行安装..."
                });

                var installArgs = source.InstallArgs ?? "";
                int exitCode;
                try
                {
                    exitCode = await RunInstallerAsync(installerPath, installArgs, TimeSpan.FromMinutes(5));
                }
                catch (Exception ex)
                {
                    return InstallResult(false, $"安装进程执行失败: {ex.Message}", null);
                }

                // 4. 验证安装结果
                string? installedPath = null;
                bool verified = false;
                if (!string.IsNullOrWhiteSpace(source.VerifyExecutable))
                {
                    installedPath = FindExecutableInPath(source.VerifyExecutable);
                    verified = installedPath != null;
                }

                // 清理临时安装包
                try { if (File.Exists(installerPath)) File.Delete(installerPath); } catch { }

                if (exitCode != 0 && !verified)
                {
                    return InstallResult(false, $"安装失败，安装进程退出码: {exitCode}", null);
                }

                if (!verified && exitCode == 0)
                {
                    // 进程成功退出但未在 PATH 中找到（可能需要重启 PATH 缓存或安装到非 PATH 目录）
                    return InstallResult(true,
                        $"安装进程已执行完成（退出码 0），但未在 PATH 中找到 {source.VerifyExecutable}。可能需要重启系统或手动添加到 PATH。",
                        null);
                }

                return InstallResult(true, $"安装成功: {appName}", installedPath);
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"AppInstallService: InstallApp failed: {ex.Message}");
                return InstallResult(false, $"安装失败: {ex.Message}", null);
            }
        }

        // ── Registry Check ─────────────────────────────────

        /// <summary>
        /// 构造 CheckInstalled 的返回对象（使用 Dictionary 以保留 snake_case 键）。
        /// detectionMethod 标识命中的检测策略，取值：
        ///   process / registry / app_paths / shortcut / path / uwp / filesystem
        /// </summary>
        private static object CheckInstalledResult(bool installed, string? executablePath, string? version, string? detectionMethod = null)
        {
            return new Dictionary<string, object?>
            {
                ["installed"] = installed,
                ["executable_path"] = executablePath,
                ["version"] = version,
                ["detection_method"] = detectionMethod
            };
        }

        /// <summary>
        /// 构造 InstallApp 的返回对象（使用 Dictionary 以保留 snake_case 键）。
        /// </summary>
        private static object InstallResult(bool success, string message, string? installedPath)
        {
            return new Dictionary<string, object?>
            {
                ["success"] = success,
                ["message"] = message,
                ["installed_path"] = installedPath
            };
        }

        /// <summary>
        /// 查注册表 Uninstall 项，匹配 DisplayName 包含 appName（不区分大小写）。
        /// 返回 (是否找到, 版本号)。
        /// </summary>
        private static (bool found, string? version) CheckRegistryInstalled(string appName)
        {
            var registryPaths = new (RegistryKey root, string subKey)[]
            {
                (Registry.LocalMachine, @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"),
                (Registry.LocalMachine, @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall"),
                (Registry.CurrentUser, @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall")
            };

            foreach (var (root, subKey) in registryPaths)
            {
                try
                {
                    using var key = root.OpenSubKey(subKey);
                    if (key == null) continue;

                    foreach (var subKeyName in key.GetSubKeyNames())
                    {
                        try
                        {
                            using var sub = key.OpenSubKey(subKeyName);
                            if (sub == null) continue;

                            var displayName = sub.GetValue("DisplayName") as string;
                            if (string.IsNullOrWhiteSpace(displayName)) continue;
                            if (!displayName.Contains(appName, StringComparison.OrdinalIgnoreCase)) continue;

                            var version = sub.GetValue("DisplayVersion") as string;
                            return (true, version);
                        }
                        catch { }
                    }
                }
                catch { }
            }

            return (false, null);
        }

        // ── PATH Lookup ────────────────────────────────────

        /// <summary>
        /// 在 PATH 环境变量中查找指定可执行文件。
        /// 优先使用 where 命令，失败时遍历 PATH 目录。
        /// 返回完整路径，未找到返回 null。
        /// </summary>
        private static string? FindExecutableInPath(string executableName)
        {
            if (string.IsNullOrWhiteSpace(executableName))
                return null;

            // 方案1：使用 where 命令
            try
            {
                var psi = new ProcessStartInfo("where", executableName)
                {
                    RedirectStandardOutput = true,
                    UseShellExecute = false,
                    CreateNoWindow = true
                };
                using var proc = Process.Start(psi);
                if (proc != null)
                {
                    proc.WaitForExit(3000);
                    if (proc.ExitCode == 0)
                    {
                        var output = proc.StandardOutput.ReadLine();
                        if (!string.IsNullOrWhiteSpace(output) && File.Exists(output.Trim()))
                            return output.Trim();
                    }
                }
            }
            catch { }

            // 方案2：遍历 PATH 目录
            try
            {
                var pathVar = Environment.GetEnvironmentVariable("PATH") ?? "";
                var exeName = executableName.EndsWith(".exe", StringComparison.OrdinalIgnoreCase)
                    ? executableName
                    : executableName + ".exe";

                foreach (var dir in pathVar.Split(';', StringSplitOptions.RemoveEmptyEntries))
                {
                    var trimmed = dir.Trim();
                    if (string.IsNullOrEmpty(trimmed)) continue;
                    try
                    {
                        var candidate = Path.Combine(trimmed, exeName);
                        if (File.Exists(candidate))
                            return candidate;
                    }
                    catch { }
                }
            }
            catch { }

            return null;
        }

        // ── App Path Lookup (复制自 FileOperationService.OpenApp) ──

        /// <summary>
        /// 按名称搜索应用，返回最佳匹配的可执行路径（快捷方式或 exe）。
        /// 代码直接复制自 FileOperationService.OpenApp 的阶段1+阶段2，仅去掉启动逻辑，
        /// 保证"检测"与"打开"使用完全相同的搜索逻辑。
        /// 阶段1: 全局收集开始菜单/桌面所有 .lnk 匹配项 → 评分排序 → 选最优
        /// 阶段2: 搜索 PATH 中的 exe（支持模糊匹配）
        /// </summary>
        private static string? FindAppPath(string name)
        {
            if (string.IsNullOrWhiteSpace(name))
                return null;

            // 阶段1: 全局收集所有目录的 .lnk 匹配项
            var matches = new List<(string path, string fileName, int score)>();

            var lnkDirs = new (string dir, SearchOption opt)[]
            {
                (Environment.ExpandEnvironmentVariables(@"%APPDATA%\Microsoft\Windows\Start Menu\Programs"), SearchOption.AllDirectories),
                (Environment.ExpandEnvironmentVariables(@"%PROGRAMDATA%\Microsoft\Windows\Start Menu\Programs"), SearchOption.AllDirectories),
                (Environment.GetFolderPath(Environment.SpecialFolder.Desktop), SearchOption.TopDirectoryOnly)
            };

            foreach (var (dir, opt) in lnkDirs)
            {
                if (!Directory.Exists(dir)) continue;

                string[] files;
                try { files = Directory.GetFiles(dir, "*.lnk", opt); }
                catch { continue; }

                foreach (var f in files)
                {
                    var fn = Path.GetFileNameWithoutExtension(f);
                    if (!fn.Contains(name, StringComparison.OrdinalIgnoreCase))
                        continue;

                    int score = fn.Equals(name, StringComparison.OrdinalIgnoreCase) ? 3
                              : fn.StartsWith(name, StringComparison.OrdinalIgnoreCase) ? 2
                              : 1;

                    matches.Add((f, fn, score));
                }
            }

            // 阶段2: 选最优 — 得分高优先，同分文件名短优先
            if (matches.Count > 0)
            {
                var best = matches
                    .OrderByDescending(m => m.score)
                    .ThenBy(m => m.fileName.Length)
                    .First();
                return best.path;
            }

            // 阶段3: 搜索 PATH 中的 exe（也支持模糊匹配）
            var pathVar = Environment.GetEnvironmentVariable("PATH") ?? "";
            var exeMatches = new List<(string path, string fileName, int score)>();

            foreach (var pdir in pathVar.Split(';', StringSplitOptions.RemoveEmptyEntries))
            {
                var trimmed = pdir.Trim();
                if (string.IsNullOrEmpty(trimmed)) continue;

                string[] exeFiles;
                try { exeFiles = Directory.GetFiles(trimmed, "*.exe", SearchOption.TopDirectoryOnly); }
                catch { continue; }

                foreach (var exe in exeFiles)
                {
                    var fn = Path.GetFileNameWithoutExtension(exe);
                    if (!fn.Contains(name, StringComparison.OrdinalIgnoreCase))
                        continue;

                    int score = fn.Equals(name, StringComparison.OrdinalIgnoreCase) ? 3
                              : fn.StartsWith(name, StringComparison.OrdinalIgnoreCase) ? 2
                              : 1;

                    exeMatches.Add((exe, fn, score));
                }
            }

            if (exeMatches.Count > 0)
            {
                var best = exeMatches
                    .OrderByDescending(m => m.score)
                    .ThenBy(m => m.fileName.Length)
                    .First();
                return best.path;
            }

            return null;
        }

        // ── Running Process Detection ─────────────────────

        /// <summary>
        /// 通过运行进程检测应用是否安装。
        /// GetProcessesByName 不需要 .exe 后缀（传 "QQ" 会匹配 "QQ.exe" 进程）。
        /// 命中时尝试获取进程主模块文件路径，访问系统进程可能抛 Win32Exception，需 try-catch。
        /// 返回 (是否找到, 可执行文件路径)。
        /// </summary>
        private static (bool found, string? path) CheckRunningProcess(string appName)
        {
            try
            {
                // GetProcessesByName 不需要 .exe 后缀
                var exeName = appName.EndsWith(".exe", StringComparison.OrdinalIgnoreCase)
                    ? Path.GetFileNameWithoutExtension(appName)
                    : appName;
                var procs = Process.GetProcessesByName(exeName);
                if (procs.Length == 0)
                {
                    // 也尝试带 .exe 的形式（有些进程名匹配规则不同）
                    procs = Process.GetProcessesByName(appName);
                }
                if (procs.Length > 0)
                {
                    try
                    {
                        var path = procs[0].MainModule?.FileName;
                        procs[0].Dispose();
                        return (true, path);
                    }
                    catch
                    {
                        // 访问系统进程的 MainModule 可能抛 Win32Exception
                        procs[0].Dispose();
                        return (true, null);
                    }
                }
            }
            catch { }
            return (false, null);
        }

        // ── App Paths Registry (模糊匹配) ────────────────

        /// <summary>
        /// 查 App Paths 注册表（Windows 按名找应用的系统机制，运行对话框就是用这个）。
        /// 位置：HKLM/HKCU\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\&lt;exe&gt;
        /// 精确匹配 exeCandidates，或子键名 Contains(appName)（不区分大小写）也算匹配。
        /// 例如 appName="QQ" 能匹配到 "QQ.exe" 子键。
        /// 返回首个命中路径，未找到返回 null。
        /// </summary>
        private static string? CheckAppPaths(string appName)
        {
            var appPathsRoots = new (RegistryKey root, string subKey)[]
            {
                (Registry.LocalMachine, @"SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths"),
                (Registry.LocalMachine, @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\App Paths"),
                (Registry.CurrentUser, @"SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths")
            };

            // 精确匹配候选
            var exeCandidates = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            exeCandidates.Add(appName.EndsWith(".exe", StringComparison.OrdinalIgnoreCase) ? appName : appName + ".exe");
            exeCandidates.Add(appName);

            foreach (var (root, subKey) in appPathsRoots)
            {
                try
                {
                    using var key = root.OpenSubKey(subKey);
                    if (key == null) continue;

                    foreach (var subKeyName in key.GetSubKeyNames())
                    {
                        // 精确匹配 或 模糊匹配（子键名包含 appName）
                        bool match = exeCandidates.Contains(subKeyName)
                            || subKeyName.Contains(appName, StringComparison.OrdinalIgnoreCase);
                        if (!match) continue;

                        using var sub = key.OpenSubKey(subKeyName);
                        if (sub == null) continue;

                        // (default) 值通常指向完整 exe 路径
                        var val = sub.GetValue(null) as string;
                        if (!string.IsNullOrWhiteSpace(val))
                        {
                            var cleaned = val.Trim('"').Trim();
                            if (File.Exists(cleaned))
                                return cleaned;
                        }
                    }
                }
                catch { }
            }
            return null;
        }

        // ── UWP/Store App Detection ──────────────────────

        /// <summary>
        /// 通过 PowerShell Get-AppxPackage 检测 UWP/Store 应用。
        /// 命令：Get-AppxPackage | Where-Object { $_.Name -like '*appName*' } | Select-Object -First 1 -ExpandProperty InstallLocation
        /// 超时 5 秒，错误处理。返回 (是否找到, 安装目录)。
        /// </summary>
        private static (bool found, string? path) CheckUwpApp(string appName)
        {
            try
            {
                var psi = new ProcessStartInfo("powershell",
                    $"-NoProfile -Command \"Get-AppxPackage | Where-Object {{ $_.Name -like '*{appName}*' }} | Select-Object -First 1 -ExpandProperty InstallLocation\"")
                {
                    RedirectStandardOutput = true,
                    UseShellExecute = false,
                    CreateNoWindow = true
                };
                using var proc = Process.Start(psi);
                if (proc == null) return (false, null);
                proc.WaitForExit(5000);
                var output = proc.StandardOutput.ReadToEnd().Trim();
                if (!string.IsNullOrWhiteSpace(output) && Directory.Exists(output))
                    return (true, output);
            }
            catch { }
            return (false, null);
        }

        // ── Filesystem Fallback (3 层深度扫描) ───────────

        /// <summary>
        /// 兜底搜索：扫描常见安装目录下的 exe，文件名模糊匹配 appName。
        /// 深度 3 层：baseDir\&lt;vendor&gt;\&lt;app&gt;\&lt;app.exe&gt;，
        /// 同时保留 2 层兼容（baseDir\&lt;vendor&gt;\&lt;app.exe&gt;），
        /// 覆盖 QQNT 的 Programs\Tencent\QQNT\QQ.exe 等场景。
        /// 返回首个命中路径，未找到返回 null。
        /// </summary>
        private static string? FindAppByFallback(string appName)
        {
            if (string.IsNullOrWhiteSpace(appName))
                return null;

            var installDirs = new List<string>();
            void AddIfExists(string? p)
            {
                if (!string.IsNullOrEmpty(p) && Directory.Exists(p))
                    installDirs.Add(p);
            }

            AddIfExists(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles));
            AddIfExists(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86));
            AddIfExists(Environment.ExpandEnvironmentVariables(@"%LOCALAPPDATA%\Programs"));
            AddIfExists(Environment.ExpandEnvironmentVariables(@"%APPDATA%\Programs"));
            AddIfExists(Environment.ExpandEnvironmentVariables(@"%LOCALAPPDATA%\Apps"));

            foreach (var baseDir in installDirs)
            {
                // 第一层：vendor 目录
                string[] vendorDirs;
                try { vendorDirs = Directory.GetDirectories(baseDir); }
                catch { continue; }

                foreach (var vendorDir in vendorDirs)
                {
                    // 第二层先找 exe（保留原 2 层兼容：baseDir\<vendor>\<app.exe>）
                    try
                    {
                        var exeFiles = Directory.GetFiles(vendorDir, "*.exe", SearchOption.TopDirectoryOnly);
                        foreach (var exe in exeFiles)
                        {
                            var fn = Path.GetFileNameWithoutExtension(exe);
                            if (fn.Contains(appName, StringComparison.OrdinalIgnoreCase))
                                return exe;
                        }
                    }
                    catch { }

                    // 第三层：app 子目录找 exe（baseDir\<vendor>\<app>\<app.exe>）
                    string[] appDirs;
                    try { appDirs = Directory.GetDirectories(vendorDir); }
                    catch { continue; }

                    foreach (var appDir in appDirs)
                    {
                        try
                        {
                            var exeFiles = Directory.GetFiles(appDir, "*.exe", SearchOption.TopDirectoryOnly);
                            foreach (var exe in exeFiles)
                            {
                                var fn = Path.GetFileNameWithoutExtension(exe);
                                if (fn.Contains(appName, StringComparison.OrdinalIgnoreCase))
                                    return exe;
                            }
                        }
                        catch { }
                    }
                }
            }

            return null;
        }

        // ── Source Lookup ──────────────────────────────────

        /// <summary>
        /// 从配置中查找 appName 对应的安装源（支持模糊匹配，如 "vscode" 匹配 "VSCode"）。
        /// </summary>
        private AppInstallSource? FindInstallSource(string appName)
        {
            if (_installSources.Count == 0)
                return null;

            // 1. 精确匹配（不区分大小写）
            if (_installSources.TryGetValue(appName, out var exact))
                return exact;

            // 2. 模糊匹配：配置 key 包含 appName 或 appName 包含 key
            var lower = appName.ToLowerInvariant();
            var match = _installSources.FirstOrDefault(kv =>
                kv.Key.ToLowerInvariant().Contains(lower) ||
                lower.Contains(kv.Key.ToLowerInvariant()));

            return match.Value;
        }

        // ── Download ───────────────────────────────────────

        /// <summary>
        /// 下载安装包到指定目录，通过 progress 回调上报下载进度。
        /// </summary>
        private async Task<string> DownloadInstallerAsync(
            string url,
            string targetDir,
            IProgress<InstallProgress>? progress)
        {
            if (string.IsNullOrWhiteSpace(url))
                throw new ArgumentException("下载 URL 不能为空");

            if (!Uri.TryCreate(url, UriKind.Absolute, out var uri) ||
                (uri.Scheme != "http" && uri.Scheme != "https"))
                throw new ArgumentException($"无效的下载 URL: {url}");

            // 从 URL 推断文件名
            string fileName;
            try
            {
                var seg = uri.Segments.LastOrDefault();
                fileName = string.IsNullOrWhiteSpace(seg) || seg == "/"
                    ? "installer.exe"
                    : Uri.UnescapeDataString(seg);
            }
            catch
            {
                fileName = "installer.exe";
            }

            var savePath = Path.Combine(targetDir, fileName);

            using var client = new HttpClient
            {
                Timeout = TimeSpan.FromMinutes(10)
            };
            client.DefaultRequestHeaders.UserAgent.ParseAdd("MoonYa-Agent/1.0");

            using var response = await client.GetAsync(url, HttpCompletionOption.ResponseHeadersRead);
            response.EnsureSuccessStatusCode();

            var totalBytes = response.Content.Headers.ContentLength ?? -1;

            using var contentStream = await response.Content.ReadAsStreamAsync();
            using var fileStream = new FileStream(savePath, FileMode.Create, FileAccess.Write, FileShare.None, 8192, true);

            var buffer = new byte[8192];
            long totalRead = 0;
            int bytesRead;
            var lastReport = DateTime.UtcNow;
            long lastRead = 0;

            while ((bytesRead = await contentStream.ReadAsync(buffer, 0, buffer.Length)) > 0)
            {
                await fileStream.WriteAsync(buffer, 0, bytesRead);
                totalRead += bytesRead;

                if (progress != null)
                {
                    var now = DateTime.UtcNow;
                    if ((now - lastReport).TotalMilliseconds >= 200 || totalBytes == -1)
                    {
                        var elapsed = (now - lastReport).TotalSeconds;
                        var speed = elapsed > 0
                            ? (totalRead - lastRead) / elapsed
                            : 0;
                        var percent = totalBytes > 0
                            ? (double)totalRead / totalBytes * 100
                            : -1;

                        progress.Report(new InstallProgress
                        {
                            Percent = percent,
                            DownloadedBytes = totalRead,
                            TotalBytes = totalBytes,
                            SpeedBytesPerSec = speed,
                            Message = $"下载中... {(totalBytes > 0 ? $"{totalRead}/{totalBytes}" : $"{totalRead}")}"
                        });

                        lastReport = now;
                        lastRead = totalRead;
                    }
                }
            }

            return savePath;
        }

        // ── Run Installer ──────────────────────────────────

        /// <summary>
        /// 执行安装包，等待完成（带超时）。
        /// </summary>
        private static async Task<int> RunInstallerAsync(string installerPath, string arguments, TimeSpan timeout)
        {
            if (!File.Exists(installerPath))
                throw new FileNotFoundException($"安装包不存在: {installerPath}");

            var psi = new ProcessStartInfo(installerPath, arguments)
            {
                UseShellExecute = false,
                CreateNoWindow = true
            };

            using var proc = new Process { StartInfo = psi, EnableRaisingEvents = true };
            if (!proc.Start())
                throw new InvalidOperationException("无法启动安装进程");

            var tcs = new TaskCompletionSource<int>();
            proc.Exited += (s, e) =>
            {
                try { tcs.TrySetResult(proc.ExitCode); } catch { }
            };

            var completedTask = await Task.WhenAny(tcs.Task, Task.Delay(timeout));
            if (completedTask == tcs.Task)
                return await tcs.Task;

            // 超时：尝试终止进程
            try
            {
                if (!proc.HasExited)
                    proc.Kill(entireProcessTree: true);
            }
            catch { }

            throw new TimeoutException($"安装进程超时（{timeout.TotalMinutes} 分钟）");
        }
    }

    // ── DTO / Model classes ────────────────────────────────

    /// <summary>
    /// 安装进度信息，用于 IProgress&lt;T&gt; 回调。
    /// </summary>
    public class InstallProgress
    {
        /// <summary>下载百分比（0-100），未知大小时为 -1</summary>
        public double Percent { get; set; }

        /// <summary>已下载字节数</summary>
        public long DownloadedBytes { get; set; }

        /// <summary>总字节数（未知时为 -1）</summary>
        public long TotalBytes { get; set; }

        /// <summary>下载速度（字节/秒）</summary>
        public double SpeedBytesPerSec { get; set; }

        /// <summary>进度消息</summary>
        public string Message { get; set; } = string.Empty;
    }

    /// <summary>
    /// 单个应用的安装源配置（来自 launcher_config.json 的 app_install_sources 项）。
    /// </summary>
    public class AppInstallSource
    {
        /// <summary>下载 URL</summary>
        public string DownloadUrl { get; set; } = string.Empty;

        /// <summary>安装参数（静默安装等）</summary>
        public string? InstallArgs { get; set; }

        /// <summary>安装后用于验证的可执行文件名（如 "code.exe"）</summary>
        public string? VerifyExecutable { get; set; }
    }
}
