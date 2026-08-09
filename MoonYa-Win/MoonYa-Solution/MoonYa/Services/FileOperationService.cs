// ┌─────────────────────────────────────────────────────────┐
// │  FileOperationService — Local file system operations    │
// │  Exposed to JS via CefSharp bridge for Agent tools      │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Diagnostics;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Interop;
using Microsoft.Win32;

namespace MoonYa.Services
{
    public class FileOperationService
    {
        private readonly FileOperationConfig _config;
        private readonly object _localPathGrantLock = new();
        private readonly HashSet<string> _locallyGrantedFiles = new(StringComparer.OrdinalIgnoreCase);
        private readonly HashSet<string> _locallyGrantedFolders = new(StringComparer.OrdinalIgnoreCase);

        public FileOperationService(string configPath)
        {
            _config = LoadConfig(configPath);
        }

        // ── Config ─────────────────────────────────────────

        private static FileOperationConfig LoadConfig(string configPath)
        {
            if (string.IsNullOrWhiteSpace(configPath) || !File.Exists(configPath))
                throw new FileNotFoundException("Missing required component configuration launcher_config.json", configPath);

            FileOperationConfig config;
            try
            {
                var json = File.ReadAllText(configPath);
                config = JsonSerializer.Deserialize<FileOperationConfig>(json,
                    new JsonSerializerOptions { PropertyNameCaseInsensitive = true, PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower })
                    ?? throw new InvalidOperationException("launcher_config.json could not be parsed");
            }
            catch (JsonException ex)
            {
                throw new InvalidOperationException("Invalid launcher_config.json", ex);
            }

            var fileOperations = config.FileOperations
                ?? throw new InvalidOperationException("Missing required configuration: file_operations");

            var allowedRootsOverride = Environment.GetEnvironmentVariable("MOONYA_FILE_ALLOWED_ROOTS");
            if (!string.IsNullOrWhiteSpace(allowedRootsOverride))
                fileOperations.AllowedRoots = SplitConfiguredPaths(allowedRootsOverride);

            var blockedPathsOverride = Environment.GetEnvironmentVariable("MOONYA_FILE_BLOCKED_PATHS");
            if (!string.IsNullOrWhiteSpace(blockedPathsOverride))
                fileOperations.BlockedPaths = SplitConfiguredPaths(blockedPathsOverride);

            var maxSizeOverride = Environment.GetEnvironmentVariable("MOONYA_FILE_MAX_SIZE_MB");
            if (!string.IsNullOrWhiteSpace(maxSizeOverride))
            {
                if (!int.TryParse(maxSizeOverride, out var maxSizeMb))
                    throw new InvalidOperationException("Invalid environment configuration: MOONYA_FILE_MAX_SIZE_MB");
                fileOperations.MaxFileSizeMb = maxSizeMb;
            }

            var deleteOverride = Environment.GetEnvironmentVariable("MOONYA_FILE_ENABLE_DELETE");
            if (!string.IsNullOrWhiteSpace(deleteOverride))
            {
                if (!bool.TryParse(deleteOverride, out var enableDelete))
                    throw new InvalidOperationException("Invalid environment configuration: MOONYA_FILE_ENABLE_DELETE");
                fileOperations.EnableDelete = enableDelete;
            }

            if (fileOperations.AllowedRoots.Count == 0)
                throw new InvalidOperationException("Missing required configuration: file_operations.allowed_roots");
            if (fileOperations.MaxFileSizeMb < 1)
                throw new InvalidOperationException("Invalid required configuration: file_operations.max_file_size_mb");
            return config;
        }

        private static List<string> SplitConfiguredPaths(string value) => value
            .Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Where(path => !string.IsNullOrWhiteSpace(path))
            .ToList();

        // ── Path Resolution ────────────────────────────────

        /// <summary>
        /// 解析路径中的特殊名称和环境变量，让 AI 无需知道实际用户名也能操作
        /// 支持：桌面/Desktop、%VAR%、相对路径等
        /// 注：改为 internal 以便 EditFileService 复用同一套路径解析逻辑（edit_file 工具）
        /// </summary>
        internal static string ResolvePath(string path)
        {
            if (string.IsNullOrWhiteSpace(path))
                return path;

            // 1. 展开环境变量 (%USERPROFILE%, %HOMEPATH% 等)
            try { path = Environment.ExpandEnvironmentVariables(path); } catch { }

            // 2. 处理以 "桌面" 或 "Desktop" 开头的相对路径
            var desktop = Environment.GetFolderPath(Environment.SpecialFolder.Desktop);
            var trimmed = path.Trim();

            if (trimmed.StartsWith("桌面", StringComparison.OrdinalIgnoreCase))
            {
                path = desktop + trimmed[2..];
            }
            else if (trimmed.StartsWith("Desktop", StringComparison.OrdinalIgnoreCase) &&
                     (trimmed.Length == 7 || trimmed[7] == '\\' || trimmed[7] == '/'))
            {
                path = desktop + trimmed[7..];
            }
            else if (trimmed == "桌面" || trimmed == "Desktop")
            {
                path = desktop;
            }
            // 3. 处理 "~/xxx" 和 "~\" 
            else if (trimmed.StartsWith("~/") || trimmed.StartsWith("~\\"))
            {
                var home = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
                path = home + trimmed[1..];
            }
            // 4. 以 \ 或 / 分隔符替换为系统分隔符
            path = path.Replace('/', '\\');

            // 5. 如果不是绝对路径且不在根目录，尝试相对于桌面
            if (!Path.IsPathRooted(path) && !path.StartsWith("\\\\"))
            {
                path = Path.Combine(desktop, path);
            }

            return path;
        }

        // ── Security ───────────────────────────────────────

        /// <summary>
        /// 校验路径是否在白名单根目录下且不在黑名单中。
        /// 注：改为 internal 以便 EditFileService 复用同一套安全校验（edit_file 工具）
        /// </summary>
        internal bool IsPathAllowed(string path)
        {
            if (string.IsNullOrWhiteSpace(path))
                return false;

            var normalized = Path.GetFullPath(path);

            // Check blocked paths
            foreach (var blocked in _config.FileOperations.BlockedPaths)
            {
                var normalizedBlocked = Path.GetFullPath(blocked);
                if (normalized.StartsWith(normalizedBlocked, StringComparison.OrdinalIgnoreCase))
                    return false;
            }

            // Check allowed roots
            foreach (var root in _config.FileOperations.AllowedRoots)
            {
                try
                {
                    var normalizedRoot = Path.GetFullPath(root);
                    if (normalized.StartsWith(normalizedRoot, StringComparison.OrdinalIgnoreCase))
                        return true;
                }
                catch
                {
                    // Invalid root path, skip
                }
            }

            // 用户从“文件和文件夹”选择器中显式授予的路径，只在本次启动期间有效。
            // 文件授权仅匹配该文件；文件夹授权允许访问其下级项目。
            lock (_localPathGrantLock)
            {
                if (_locallyGrantedFiles.Contains(normalized))
                    return true;

                foreach (var folder in _locallyGrantedFolders)
                {
                    if (IsSameOrChildPath(normalized, folder))
                        return true;
                }
            }

            return false;
        }

        private static bool IsSameOrChildPath(string candidatePath, string parentPath)
        {
            try
            {
                var relative = Path.GetRelativePath(parentPath, candidatePath);
                return relative == "." ||
                       (!relative.StartsWith(".." + Path.DirectorySeparatorChar, StringComparison.Ordinal) &&
                        !relative.StartsWith(".." + Path.AltDirectorySeparatorChar, StringComparison.Ordinal) &&
                        !Path.IsPathRooted(relative));
            }
            catch
            {
                return false;
            }
        }

        // ── Public methods (exposed to JS) ─────────────────

        public async Task<Dictionary<string, object>> CreateFile(string path, string? content = null)
        {
            return await Task.Run(() =>
            {
                try
                {
                    path = ResolvePath(path);

                    if (string.IsNullOrWhiteSpace(path))
                        return Result(false, "路径不能为空");

                    if (content != null && content.Length > _config.FileOperations.MaxFileSizeMb * 1024 * 1024)
                        return Result(false, $"文件内容超过最大限制 {_config.FileOperations.MaxFileSizeMb}MB");

                    if (!IsPathAllowed(path))
                        return Result(false, $"路径不被允许: {path}。文件操作仅限白名单目录。");

                    // Create parent directory if needed
                    var dir = Path.GetDirectoryName(path);
                    if (!string.IsNullOrEmpty(dir) && !Directory.Exists(dir))
                        Directory.CreateDirectory(dir);

                    // 流式写入：使用 FileStream + StreamWriter 分块写入并周期性 Flush，
                    // 避免大文件一次性缓存到内存（原 File.WriteAllText 的行为）。
                    var fileContent = content ?? string.Empty;
                    var encoding = new System.Text.UTF8Encoding(encoderShouldEmitUTF8Identifier: false);
                    const int ChunkSize = 8192;
                    using (var fs = new FileStream(path, FileMode.Create, FileAccess.Write, FileShare.None, bufferSize: ChunkSize))
                    using (var writer = new StreamWriter(fs, encoding, bufferSize: ChunkSize))
                    {
                        var buffer = new char[ChunkSize];
                        int total = fileContent.Length;
                        for (int i = 0; i < total; i += ChunkSize)
                        {
                            int len = Math.Min(ChunkSize, total - i);
                            fileContent.CopyTo(i, buffer, 0, len);
                            writer.Write(buffer, 0, len);
                            writer.Flush();   // 将当前分块落盘
                            fs.Flush(true);   // 同步刷新到磁盘
                        }
                    }

                    return Result(true, $"文件创建成功: {path}");
                }
                catch (UnauthorizedAccessException)
                {
                    return Result(false, $"权限不足，无法创建文件: {path}");
                }
                catch (Exception ex)
                {
                    return Result(false, $"创建文件失败: {ex.Message}");
                }
            });
        }

        public async Task<Dictionary<string, object>> CreateFolder(string path)
        {
            return await Task.Run(() =>
            {
                try
                {
                    path = ResolvePath(path);

                    if (string.IsNullOrWhiteSpace(path))
                        return Result(false, "路径不能为空");

                    if (!IsPathAllowed(path))
                        return Result(false, $"路径不被允许: {path}。文件操作仅限白名单目录。");

                    if (Directory.Exists(path))
                        return Result(false, $"文件夹已存在: {path}");

                    Directory.CreateDirectory(path);

                    return Result(true, $"文件夹创建成功: {path}");
                }
                catch (UnauthorizedAccessException)
                {
                    return Result(false, $"权限不足，无法创建文件夹: {path}");
                }
                catch (Exception ex)
                {
                    return Result(false, $"创建文件夹失败: {ex.Message}");
                }
            });
        }

        public async Task<Dictionary<string, object>> DeleteFile(string path)
        {
            return await Task.Run(() =>
            {
                try
                {
                    path = ResolvePath(path);

                    if (string.IsNullOrWhiteSpace(path))
                        return Result(false, "路径不能为空");

                    if (!_config.FileOperations.EnableDelete)
                        return Result(false, "删除操作已被禁用");

                    if (!IsPathAllowed(path))
                        return Result(false, $"路径不被允许: {path}。文件操作仅限白名单目录。");

                    // Check if it's a directory
                    if (Directory.Exists(path))
                    {
                        // Only delete if empty
                        if (Directory.GetFiles(path).Length > 0 || Directory.GetDirectories(path).Length > 0)
                            return Result(false, $"文件夹不为空，无法删除: {path}。请先清空文件夹内容。");

                        Directory.Delete(path);
                        return Result(true, $"文件夹删除成功: {path}");
                    }

                    if (File.Exists(path))
                    {
                        File.Delete(path);
                        return Result(true, $"文件删除成功: {path}");
                    }

                    return Result(false, $"路径不存在: {path}");
                }
                catch (UnauthorizedAccessException)
                {
                    return Result(false, $"权限不足，无法删除: {path}");
                }
                catch (Exception ex)
                {
                    return Result(false, $"删除失败: {ex.Message}");
                }
            });
        }

        public async Task<Dictionary<string, object>> ListFiles(string path)
        {
            return await Task.Run(() =>
            {
                try
                {
                    path = ResolvePath(path);

                    if (string.IsNullOrWhiteSpace(path))
                        return Result(false, "路径不能为空");

                    if (!Directory.Exists(path))
                        return Result(false, "路径不存在");

                    var folders = Directory.GetDirectories(path)
                        .Select(d => new Dictionary<string, object>
                        {
                            ["name"] = Path.GetFileName(d),
                            ["modified"] = Directory.GetLastWriteTime(d).ToString("yyyy-MM-dd HH:mm:ss")
                        })
                        .OrderBy(f => (string)f["name"])
                        .ToList<Dictionary<string, object>>();

                    var files = Directory.GetFiles(path)
                        .Select(f =>
                        {
                            var fi = new FileInfo(f);
                            return new Dictionary<string, object>
                            {
                                ["name"] = fi.Name,
                                ["size"] = fi.Length,
                                ["modified"] = fi.LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss")
                            };
                        })
                        .OrderBy(f => (string)f["name"])
                        .ToList<Dictionary<string, object>>();

                    var totalFolders = folders.Count;
                    var totalFiles = files.Count;
                    var totalItems = totalFolders + totalFiles;

                    return new Dictionary<string, object>
                    {
                        ["success"] = true,
                        ["message"] = $"共 {totalItems} 个项目（{totalFolders} 个文件夹，{totalFiles} 个文件）",
                        ["path"] = path,
                        ["folders"] = folders,
                        ["files"] = files,
                        ["total_folders"] = totalFolders,
                        ["total_files"] = totalFiles,
                        ["total_items"] = totalItems
                    };
                }
                catch (UnauthorizedAccessException)
                {
                    return Result(false, "权限不足");
                }
                catch (Exception ex)
                {
                    return Result(false, $"列出文件失败: {ex.Message}");
                }
            });
        }

        public async Task<Dictionary<string, object>> CopyFile(string sourcePath, string destPath)
        {
            return await Task.Run(() =>
            {
                try
                {
                    var src = ResolvePath(sourcePath);
                    var dst = ResolvePath(destPath);

                    if (string.IsNullOrWhiteSpace(src))
                        return Result(false, "源路径不能为空");
                    if (string.IsNullOrWhiteSpace(dst))
                        return Result(false, "目标路径不能为空");

                    if (!IsPathAllowed(src))
                        return Result(false, $"源路径不被允许: {src}。文件操作仅限白名单目录。");
                    if (!IsPathAllowed(dst))
                        return Result(false, $"目标路径不被允许: {dst}。文件操作仅限白名单目录。");

                    if (Directory.Exists(src))
                    {
                        // Source is a directory: copy its contents to destination
                        if (!Directory.Exists(dst))
                            Directory.CreateDirectory(dst);

                        var files = Directory.GetFiles(src, "*", SearchOption.AllDirectories);
                        int copied = 0;
                        int overwritten = 0;
                        foreach (var file in files)
                        {
                            var relativePath = file.Substring(src.Length).TrimStart('\\', '/');
                            var targetFile = Path.Combine(dst, relativePath);
                            var targetDir = Path.GetDirectoryName(targetFile);
                            if (!string.IsNullOrEmpty(targetDir) && !Directory.Exists(targetDir))
                                Directory.CreateDirectory(targetDir);

                            bool exists = File.Exists(targetFile);
                            File.Copy(file, targetFile, true);
                            if (exists) overwritten++;
                            else copied++;
                        }

                        return new Dictionary<string, object>
                        {
                            ["success"] = true,
                            ["message"] = $"共复制 {files.Length} 个文件（{copied} 个新增，{overwritten} 个覆盖），从 {src} 到 {dst}"
                        };
                    }
                    else if (File.Exists(src))
                    {
                        bool exists = File.Exists(dst);
                        var dstDir = Path.GetDirectoryName(dst);
                        if (!string.IsNullOrEmpty(dstDir) && !Directory.Exists(dstDir))
                            Directory.CreateDirectory(dstDir);

                        File.Copy(src, dst, true);
                        return Result(true, $"文件复制成功: {src} → {dst}" + (exists ? "（已覆盖）" : ""));
                    }
                    else
                    {
                        return Result(false, $"源路径不存在: {src}");
                    }
                }
                catch (UnauthorizedAccessException)
                {
                    return Result(false, "权限不足，无法复制文件");
                }
                catch (Exception ex)
                {
                    return Result(false, $"复制失败: {ex.Message}");
                }
            });
        }

        public async Task<Dictionary<string, object>> MoveFile(string sourcePath, string destPath)
        {
            return await Task.Run(() =>
            {
                try
                {
                    var src = ResolvePath(sourcePath);
                    var dst = ResolvePath(destPath);

                    if (string.IsNullOrWhiteSpace(src))
                        return Result(false, "源路径不能为空");
                    if (string.IsNullOrWhiteSpace(dst))
                        return Result(false, "目标路径不能为空");

                    if (!IsPathAllowed(src))
                        return Result(false, $"源路径不被允许: {src}。文件操作仅限白名单目录。");
                    if (!IsPathAllowed(dst))
                        return Result(false, $"目标路径不被允许: {dst}。文件操作仅限白名单目录。");

                    if (Directory.Exists(src))
                    {
                        // If destination directory exists, move source directory INTO it
                        if (Directory.Exists(dst))
                        {
                            var targetDir = Path.Combine(dst, Path.GetFileName(src));
                            if (Directory.Exists(targetDir))
                            {
                                // Merge: move files from src into existing targetDir
                                var files = Directory.GetFiles(src, "*", SearchOption.AllDirectories);
                                foreach (var file in files)
                                {
                                    var relativePath = file.Substring(src.Length).TrimStart('\\', '/');
                                    var targetFile = Path.Combine(targetDir, relativePath);
                                    var targetFileDir = Path.GetDirectoryName(targetFile);
                                    if (!string.IsNullOrEmpty(targetFileDir) && !Directory.Exists(targetFileDir))
                                        Directory.CreateDirectory(targetFileDir);
                                    File.Move(file, targetFile, true);
                                }
                                // Clean up empty subdirectories
                                try { Directory.Delete(src, true); } catch { }
                                return Result(true, $"文件夹移动并合并: {src} → {targetDir}");
                            }
                            else
                            {
                                Directory.Move(src, targetDir);
                                return Result(true, $"文件夹移动成功: {src} → {targetDir}");
                            }
                        }
                        else
                        {
                            var dstDir = Path.GetDirectoryName(dst);
                            if (!string.IsNullOrEmpty(dstDir) && !Directory.Exists(dstDir))
                                Directory.CreateDirectory(dstDir);
                            Directory.Move(src, dst);
                            return Result(true, $"文件夹移动成功: {src} → {dst}");
                        }
                    }
                    else if (File.Exists(src))
                    {
                        var dstDir = Path.GetDirectoryName(dst);
                        if (!string.IsNullOrEmpty(dstDir) && !Directory.Exists(dstDir))
                            Directory.CreateDirectory(dstDir);

                        bool exists = File.Exists(dst);
                        File.Move(src, dst, true);
                        return Result(true, $"文件移动成功: {src} → {dst}" + (exists ? "（已覆盖）" : ""));
                    }
                    else
                    {
                        return Result(false, $"源路径不存在: {src}");
                    }
                }
                catch (UnauthorizedAccessException)
                {
                    return Result(false, "权限不足，无法移动文件");
                }
                catch (Exception ex)
                {
                    return Result(false, $"移动失败: {ex.Message}");
                }
            });
        }

        public async Task<Dictionary<string, object>> OpenFile(string path)
        {
            try
            {
                var fullPath = ResolvePath(path);
                bool isDir = Directory.Exists(fullPath);
                if (!isDir && !File.Exists(fullPath))
                {
                    return new Dictionary<string, object>
                    {
                        ["success"] = false,
                        ["message"] = $"路径不存在: {fullPath}"
                    };
                }

                ProcessStartInfo psi;
                string typeLabel;

                if (isDir)
                {
                    psi = new ProcessStartInfo("explorer.exe", fullPath)
                    {
                        UseShellExecute = true
                    };
                    typeLabel = "文件夹";
                }
                else
                {
                    psi = new ProcessStartInfo(fullPath)
                    {
                        UseShellExecute = true
                    };
                    typeLabel = "文件";
                }

                await Task.Run(() => Process.Start(psi));

                return new Dictionary<string, object>
                {
                    ["success"] = true,
                    ["message"] = $"{typeLabel}已打开: {fullPath}"
                };
            }
            catch (Exception ex)
            {
                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["message"] = $"打开失败: {ex.Message}"
                };
            }
        }

        // ── Project Folder Management ──────────────────────

        /// <summary>
        /// 弹出原生文件夹选择对话框。在 WPF UI 线程上显示 OpenFolderDialog，
        /// 确保对话框正确置顶且模态于主窗口（CefSharp OSR 模式下跨线程对话框会被遮挡）。
        /// </summary>
        public async Task<Dictionary<string, object>> PickFolder()
        {
            var dispatcher = Application.Current?.Dispatcher;
            if (dispatcher == null || dispatcher.HasShutdownStarted || dispatcher.HasShutdownFinished)
            {
                return Result(false, "选择文件夹失败: 应用窗口已关闭");
            }

            try
            {
                if (dispatcher.CheckAccess())
                    return PickFolderOnUiThread();

                return await dispatcher.InvokeAsync(PickFolderOnUiThread);
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"PickFolder: {ex}");
                return Result(false, $"选择文件夹失败: {ex.Message}");
            }
        }

        private static Dictionary<string, object> PickFolderOnUiThread()
        {
            var dialog = new OpenFolderDialog
            {
                Title = "请选择项目文件夹",
                InitialDirectory = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile)
            };

            // Application.MainWindow 不一定是实际主界面：启动时先创建的桌宠窗口
            // 可能尚未显示，因此没有 HWND。WPF 在 ShowDialog(owner) 收到零句柄时
            // 会抛出无具体说明的 InvalidOperationException。
            var owner = FindDialogOwner();
            bool? result = owner != null
                ? dialog.ShowDialog(owner)
                : dialog.ShowDialog();

            if (result != true || string.IsNullOrWhiteSpace(dialog.FolderName))
            {
                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["cancelled"] = true
                };
            }

            return new Dictionary<string, object>
            {
                ["success"] = true,
                ["path"] = dialog.FolderName
            };
        }

        /// <summary>
        /// Select files or a folder for the current AI request. The selected path is
        /// granted only to this running launcher; file contents never leave the device
        /// as part of the selection itself.
        /// </summary>
        public async Task<Dictionary<string, object>> PickLocalPaths()
        {
            var dispatcher = Application.Current?.Dispatcher;
            if (dispatcher == null || dispatcher.HasShutdownStarted || dispatcher.HasShutdownFinished)
                return Result(false, "无法打开本地路径选择器：应用窗口已关闭。");

            try
            {
                if (dispatcher.CheckAccess())
                    return PickLocalPathsOnUiThread();

                return await dispatcher.InvokeAsync(PickLocalPathsOnUiThread);
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"PickLocalPaths: {ex}");
                return Result(false, $"选择本地路径失败：{ex.Message}");
            }
        }

        /// <summary>
        /// 直接弹出原生文件选择对话框（无 MessageBox 中间确认），由前端 UI 决定调用入口。
        /// </summary>
        public async Task<Dictionary<string, object>> PickLocalFiles()
        {
            var dispatcher = Application.Current?.Dispatcher;
            if (dispatcher == null || dispatcher.HasShutdownStarted || dispatcher.HasShutdownFinished)
                return Result(false, "无法打开本地路径选择器：应用窗口已关闭。");

            try
            {
                if (dispatcher.CheckAccess())
                    return PickLocalFilesOnUiThread();

                return await dispatcher.InvokeAsync(PickLocalFilesOnUiThread);
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"PickLocalFiles: {ex}");
                return Result(false, $"选择本地路径失败：{ex.Message}");
            }
        }

        /// <summary>
        /// 直接弹出原生文件夹选择对话框（无 MessageBox 中间确认），由前端 UI 决定调用入口。
        /// </summary>
        public async Task<Dictionary<string, object>> PickLocalFolders()
        {
            var dispatcher = Application.Current?.Dispatcher;
            if (dispatcher == null || dispatcher.HasShutdownStarted || dispatcher.HasShutdownFinished)
                return Result(false, "无法打开本地路径选择器：应用窗口已关闭。");

            try
            {
                if (dispatcher.CheckAccess())
                    return PickLocalFolderOnUiThread();

                return await dispatcher.InvokeAsync(PickLocalFolderOnUiThread);
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"PickLocalFolders: {ex}");
                return Result(false, $"选择本地路径失败：{ex.Message}");
            }
        }

        private Dictionary<string, object> PickLocalPathsOnUiThread()
        {
            var selectionKind = MessageBox.Show(
                "请选择要提供给 AI 的本地路径。\n\n“是”选择文件；“否”选择文件夹。\n文件内容不会上传，AI 仅会收到路径。",
                "添加文件和文件夹",
                MessageBoxButton.YesNoCancel,
                MessageBoxImage.Question);

            return selectionKind switch
            {
                MessageBoxResult.Yes => PickLocalFilesOnUiThread(),
                MessageBoxResult.No => PickLocalFolderOnUiThread(),
                _ => new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["cancelled"] = true
                }
            };
        }

        private Dictionary<string, object> PickLocalFilesOnUiThread()
        {
            var dialog = new OpenFileDialog
            {
                Title = "选择要提供给 AI 的文件",
                Filter = "所有文件 (*.*)|*.*",
                Multiselect = true,
                CheckFileExists = true,
                InitialDirectory = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile)
            };

            var owner = FindDialogOwner();
            bool? result = owner != null ? dialog.ShowDialog(owner) : dialog.ShowDialog();
            if (result != true || dialog.FileNames.Length == 0)
            {
                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["cancelled"] = true
                };
            }

            return GrantLocalPaths(dialog.FileNames, isFolder: false);
        }

        private Dictionary<string, object> PickLocalFolderOnUiThread()
        {
            var dialog = new OpenFolderDialog
            {
                Title = "选择要提供给 AI 的文件夹",
                Multiselect = true,
                InitialDirectory = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile)
            };

            var owner = FindDialogOwner();
            bool? result = owner != null ? dialog.ShowDialog(owner) : dialog.ShowDialog();
            if (result != true || dialog.FolderNames.Length == 0)
            {
                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["cancelled"] = true
                };
            }

            return GrantLocalPaths(dialog.FolderNames, isFolder: true);
        }

        private Dictionary<string, object> GrantLocalPaths(IEnumerable<string> paths, bool isFolder)
        {
            var granted = new List<Dictionary<string, object>>();
            lock (_localPathGrantLock)
            {
                foreach (var rawPath in paths)
                {
                    if (string.IsNullOrWhiteSpace(rawPath))
                        continue;

                    string fullPath;
                    try
                    {
                        fullPath = Path.GetFullPath(rawPath);
                    }
                    catch
                    {
                        continue;
                    }

                    if (isFolder)
                    {
                        if (!Directory.Exists(fullPath))
                            continue;
                        _locallyGrantedFolders.Add(fullPath);
                    }
                    else
                    {
                        if (!File.Exists(fullPath))
                            continue;
                        _locallyGrantedFiles.Add(fullPath);
                    }

                    granted.Add(new Dictionary<string, object>
                    {
                        ["path"] = fullPath,
                        ["kind"] = isFolder ? "folder" : "file",
                        ["name"] = Path.GetFileName(fullPath)
                    });
                }
            }

            if (granted.Count == 0)
                return Result(false, "未选择到可用的本地路径。");

            return new Dictionary<string, object>
            {
                ["success"] = true,
                ["items"] = granted
            };
        }

        public Dictionary<string, object> RevokeLocalPath(string path)
        {
            if (string.IsNullOrWhiteSpace(path))
                return Result(false, "路径不能为空。");

            try
            {
                var fullPath = Path.GetFullPath(path);
                lock (_localPathGrantLock)
                {
                    _locallyGrantedFiles.Remove(fullPath);
                    _locallyGrantedFolders.Remove(fullPath);
                }
                return new Dictionary<string, object> { ["success"] = true };
            }
            catch (Exception ex)
            {
                return Result(false, $"撤销本地路径授权失败：{ex.Message}");
            }
        }

        private static Window? FindDialogOwner()
        {
            var app = Application.Current;
            if (app == null)
                return null;

            // 优先使用当前激活、显示在任务栏中的正常窗口，避免桌宠和光效覆盖层。
            var windows = app.Windows
                .OfType<Window>()
                .Where(window => window.IsVisible && window.WindowState != WindowState.Minimized)
                .OrderByDescending(window => window.IsActive)
                .ThenByDescending(window => window.ShowInTaskbar);

            foreach (var window in windows)
            {
                if (new WindowInteropHelper(window).Handle != IntPtr.Zero)
                    return window;
            }

            return null;
        }

        /// <summary>
        /// 验证路径非空、存在且可写（在目标路径下创建临时文件并删除以验证可写性）。
        /// </summary>
        public async Task<Dictionary<string, object>> ValidatePath(string path)
        {
            return await Task.Run(() =>
            {
                try
                {
                    if (string.IsNullOrWhiteSpace(path))
                        return Result(false, "路径不能为空");

                    if (!Directory.Exists(path))
                        return Result(false, "路径不存在");

                    // 尝试在 path 下创建临时文件并写入测试字节后删除，验证可写
                    var tempFile = Path.Combine(path, Guid.NewGuid().ToString("N") + ".tmp");
                    try
                    {
                        File.WriteAllBytes(tempFile, new byte[] { 0x00 });
                        File.Delete(tempFile);
                    }
                    catch (UnauthorizedAccessException ex)
                    {
                        return Result(false, "路径不可写: " + ex.Message);
                    }
                    catch (IOException ex)
                    {
                        return Result(false, "路径不可写: " + ex.Message);
                    }

                    return new Dictionary<string, object>
                    {
                        ["success"] = true,
                        ["path"] = path
                    };
                }
                catch (Exception ex)
                {
                    return Result(false, $"路径验证失败: {ex.Message}");
                }
            });
        }

        /// <summary>
        /// 创建项目文件夹（已存在不报错）。
        /// </summary>
        public async Task<Dictionary<string, object>> CreateProjectFolder(string path)
        {
            return await Task.Run(() =>
            {
                try
                {
                    if (string.IsNullOrWhiteSpace(path))
                        return Result(false, "路径不能为空");

                    Directory.CreateDirectory(path);

                    return new Dictionary<string, object>
                    {
                        ["success"] = true,
                        ["path"] = path
                    };
                }
                catch (Exception ex)
                {
                    return Result(false, ex.Message);
                }
            });
        }

        // ── Read file content (returns text to AI) ────────
        // 返回内容带行号（cat -n 格式：行号右对齐 6 位 + \t + 内容），便于后续 edit_file 精确引用行号。
        // 支持 offset（起始行号，1-based，默认 1）和 limit（读取行数，默认全部）。
        // 大文件（>1MB）自动分段，返回时附 truncated/total_lines/returned_lines 字段。

        public async Task<Dictionary<string, object>> ReadFile(string path, int? offset = null, int? limit = null)
        {
            return await Task.Run(() =>
            {
                try
                {
                    path = ResolvePath(path);

                    if (string.IsNullOrWhiteSpace(path))
                        return Result(false, "路径不能为空");

                    if (!File.Exists(path))
                        return Result(false, $"文件不存在: {path}");

                    if (!IsPathAllowed(path))
                        return Result(false, $"路径不被允许: {path}。文件操作仅限白名单目录。");

                    var fi = new FileInfo(path);
                    var maxBytes = (_config.FileOperations.MaxFileSizeMb > 0 ? _config.FileOperations.MaxFileSizeMb : 10) * 1024L * 1024L;
                    if (fi.Length > maxBytes)
                        return Result(false, $"文件过大（{fi.Length / 1024.0 / 1024.0:F2}MB），超过上限 {_config.FileOperations.MaxFileSizeMb}MB，无法读取");

                    // 仅允许读取文本类文件，避免误读二进制
                    var ext = Path.GetExtension(path).ToLowerInvariant();
                    var binaryExts = new HashSet<string> { ".exe", ".dll", ".so", ".dylib", ".bin", ".obj", ".lib", ".a", ".class", ".jar", ".war", ".pyc", ".pyo", ".png", ".jpg", ".jpeg", ".gif", ".bmp", ".ico", ".webp", ".zip", ".rar", ".7z", ".gz", ".tar", ".bz2", ".xz", ".mp3", ".mp4", ".avi", ".mov", ".mkv", ".flv", ".wav", ".flac", ".ogg", ".pdf", ".doc", ".docx", ".xls", ".xlsx", ".ppt", ".pptx" };
                    if (binaryExts.Contains(ext))
                        return Result(false, $"不支持读取二进制文件 ({ext})，请使用 open_file 用默认程序打开");

                    string content;
                    try
                    {
                        content = File.ReadAllText(path);
                    }
                    catch (OutOfMemoryException)
                    {
                        return Result(false, "文件内容过大，内存不足");
                    }

                    // 按行分割（保留 \r 之外的字符），统一行尾便于 cat -n 格式化
                    // 注意：StringSplitOptions.None 保留空行，确保行号与原文件一致
                    var allLines = content.Split('\n');
                    for (int i = 0; i < allLines.Length; i++)
                        allLines[i] = allLines[i].TrimEnd('\r');

                    // 文件末尾若以 \n 结尾，Split 会产生一个空尾行，移除以保持行号与文本编辑器一致
                    if (allLines.Length > 0 && allLines[allLines.Length - 1].Length == 0 && content.EndsWith("\n"))
                    {
                        Array.Resize(ref allLines, allLines.Length - 1);
                    }

                    var totalLines = allLines.Length;
                    var totalChars = content.Length;

                    // 解析 offset（1-based，默认 1）和 limit（默认 0 表示无限制）
                    int startLine = (offset.HasValue && offset.Value > 0) ? offset.Value : 1;
                    int startIdx = startLine - 1;  // 转 0-based
                    if (startIdx >= totalLines)
                    {
                        return Result(false, $"起始行 {startLine} 超出文件总行数 {totalLines}");
                    }

                    int effectiveLimit = (limit.HasValue && limit.Value > 0) ? limit.Value : 0;
                    bool truncated = false;
                    bool largeFile = fi.Length > 1 * 1024L * 1024L;  // >1MB 视为大文件

                    // 返回内容字符上限（避免回填给 AI 时 token 超限）
                    const int maxReturnedChars = 50000;

                    // 大文件自动分段：若未指定 limit，自动截取前 N 行使返回内容 ≈ maxReturnedChars
                    if (largeFile && effectiveLimit == 0)
                    {
                        int estimatedChars = 0;
                        int autoLimit = 0;
                        for (int i = startIdx; i < totalLines; i++)
                        {
                            // 每行格式：行号(6) + \t(1) + 内容 + \n(1) = 8 + 内容长度
                            estimatedChars += 8 + allLines[i].Length;
                            if (estimatedChars > maxReturnedChars) break;
                            autoLimit++;
                        }
                        effectiveLimit = autoLimit;
                        if (startIdx + effectiveLimit < totalLines)
                            truncated = true;
                    }

                    int endIdx = effectiveLimit > 0
                        ? Math.Min(startIdx + effectiveLimit, totalLines)
                        : totalLines;

                    // 无 limit 模式下仍需检查总字符数上限，避免回填超大内容
                    if (effectiveLimit == 0)
                    {
                        int estimatedChars = 0;
                        int actualEndIdx = startIdx;
                        for (int i = startIdx; i < endIdx; i++)
                        {
                            estimatedChars += 8 + allLines[i].Length;
                            if (estimatedChars > maxReturnedChars)
                            {
                                truncated = true;
                                break;
                            }
                            actualEndIdx = i + 1;
                        }
                        endIdx = actualEndIdx;
                    }

                    int returnedLines = endIdx - startIdx;

                    // 格式化为带行号（cat -n 格式：行号右对齐 6 位 + \t + 内容）
                    var sb = new StringBuilder(returnedLines * 80);
                    for (int i = startIdx; i < endIdx; i++)
                    {
                        sb.Append((i + 1).ToString().PadLeft(6));
                        sb.Append('\t');
                        sb.Append(allLines[i]);
                        sb.Append('\n');
                    }
                    var formattedContent = sb.ToString();

                    return new Dictionary<string, object>
                    {
                        ["success"] = true,
                        ["message"] = $"读取成功：共 {totalLines} 行，返回 {returnedLines} 行" + (truncated ? "（已截断）" : ""),
                        ["path"] = path,
                        ["content"] = formattedContent,
                        ["size"] = fi.Length,
                        ["total_lines"] = totalLines,
                        ["returned_lines"] = returnedLines,
                        ["offset"] = startLine,
                        ["lines"] = totalLines,  // 兼容旧字段
                        ["chars"] = totalChars,
                        ["truncated"] = truncated,
                        ["modified"] = fi.LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss")
                    };
                }
                catch (UnauthorizedAccessException)
                {
                    return Result(false, "权限不足");
                }
                catch (Exception ex)
                {
                    return Result(false, $"读取文件失败: {ex.Message}");
                }
            });
        }

        // ── Helpers ────────────────────────────────────────

        private static Dictionary<string, object> Result(bool success, string message)
        {
            return new Dictionary<string, object>
            {
                { "success", success },
                { "message", message }
            };
        }

        // ── App Launcher ─────────────────────────────────────

        /// <summary>
        /// 按名称搜索并启动应用程序。
        /// 先全局收集所有快捷方式 → 评分排序 → 选最优，避免目录间早退问题。
        /// </summary>
        public async Task<Dictionary<string, object>> OpenApp(string name)
        {
            if (string.IsNullOrWhiteSpace(name))
            {
                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["message"] = "应用名称不能为空"
                };
            }

            try
            {
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

                    await Task.Run(() => Process.Start(new ProcessStartInfo(best.path)
                    {
                        UseShellExecute = true
                    }));

                    return new Dictionary<string, object>
                    {
                        ["success"] = true,
                        ["message"] = $"已启动应用: {best.fileName}"
                    };
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

                    await Task.Run(() => Process.Start(new ProcessStartInfo(best.path)
                    {
                        UseShellExecute = true
                    }));

                    return new Dictionary<string, object>
                    {
                        ["success"] = true,
                        ["message"] = $"已启动应用: {best.fileName}"
                    };
                }

                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["message"] = $"未找到应用「{name}」，请确认应用已安装且存在快捷方式"
                };
            }
            catch (Exception ex)
            {
                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["message"] = $"启动应用失败: {ex.Message}"
                };
            }
        }

        // ── Close App ───────────────────────────────────

        public async Task<Dictionary<string, object>> CloseApp(string name)
        {
            if (string.IsNullOrWhiteSpace(name))
            {
                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["message"] = "应用名称不能为空"
                };
            }

            try
            {
                // 阶段1: 直接按进程名匹配
                var killed = KillProcessesByName(name);

                if (killed.Count > 0)
                {
                    return new Dictionary<string, object>
                    {
                        ["success"] = true,
                        ["message"] = $"已关闭 {killed.Count} 个进程: {string.Join(", ", killed)}",
                        ["killed"] = killed
                    };
                }

                // 阶段2: 通过 .lnk 快捷方式解析真实进程名（解决中文名→英文进程名的映射）
                var resolvedNames = ResolveAppToProcessNames(name);
                foreach (var pn in resolvedNames)
                {
                    killed = KillProcessesByName(pn);
                    if (killed.Count > 0)
                    {
                        return new Dictionary<string, object>
                        {
                            ["success"] = true,
                            ["message"] = $"已关闭 {killed.Count} 个进程: {string.Join(", ", killed)}",
                            ["killed"] = killed
                        };
                    }
                }

                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["message"] = $"未找到正在运行的「{name}」进程"
                };
            }
            catch (Exception ex)
            {
                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["message"] = $"关闭应用失败: {ex.Message}"
                };
            }
        }

        // ── Uninstall App ───────────────────────────────────

        public async Task<Dictionary<string, object>> UninstallApp(string name)
        {
            if (string.IsNullOrWhiteSpace(name))
            {
                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["message"] = "应用名称不能为空"
                };
            }

            try
            {
                var registryPaths = new List<(RegistryKey? root, string subKey)>
                {
                    (Registry.LocalMachine, @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"),
                    (Registry.LocalMachine, @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall"),
                    (Registry.CurrentUser, @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall")
                };

                foreach (var (root, subKey) in registryPaths)
                {
                    using var key = root.OpenSubKey(subKey);
                    if (key == null) continue;

                    foreach (var subKeyName in key.GetSubKeyNames())
                    {
                        using var sub = key.OpenSubKey(subKeyName);
                        if (sub == null) continue;

                        var displayName = sub.GetValue("DisplayName") as string;
                        if (string.IsNullOrWhiteSpace(displayName)) continue;
                        if (!displayName.Contains(name, StringComparison.OrdinalIgnoreCase)) continue;

                        // Try QuietUninstallString first, then UninstallString
                        var uninstallCmd = sub.GetValue("QuietUninstallString") as string
                                        ?? sub.GetValue("UninstallString") as string;

                        if (string.IsNullOrWhiteSpace(uninstallCmd))
                        {
                            return new Dictionary<string, object>
                            {
                                ["success"] = false,
                                ["message"] = $"找到应用「{displayName}」，但缺少卸载命令"
                            };
                        }

                        // Wrap msiexec commands in cmd.exe /c start /wait for compatibility
                        string fileName;
                        string arguments;
                        if (uninstallCmd.TrimStart().StartsWith("msiexec", StringComparison.OrdinalIgnoreCase)
                            || uninstallCmd.TrimStart().StartsWith("\"", StringComparison.OrdinalIgnoreCase))
                        {
                            fileName = "cmd.exe";
                            arguments = $"/c start /wait {uninstallCmd}";
                        }
                        else
                        {
                            // Parse executable and arguments
                            var parts = ParseCommandLine(uninstallCmd);
                            fileName = parts.exe;
                            arguments = parts.args;
                        }

                        await Task.Run(() =>
                        {
                            var psi = new ProcessStartInfo(fileName, arguments)
                            {
                                UseShellExecute = true
                            };
                            Process.Start(psi);
                        });

                        return new Dictionary<string, object>
                        {
                            ["success"] = true,
                            ["message"] = $"已启动卸载: {displayName}"
                        };
                    }
                }

                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["message"] = $"未找到「{name}」的卸载程序"
                };
            }
            catch (Exception ex)
            {
                return new Dictionary<string, object>
                {
                    ["success"] = false,
                    ["message"] = $"卸载失败: {ex.Message}"
                };
            }
        }

        private static (string exe, string args) ParseCommandLine(string command)
        {
            command = command.Trim();
            if (command.StartsWith("\""))
            {
                var endQuote = command.IndexOf('"', 1);
                if (endQuote > 0)
                {
                    var exe = command.Substring(1, endQuote - 1);
                    var args = command.Substring(endQuote + 1).Trim();
                    return (exe, args);
                }
            }

            var firstSpace = command.IndexOf(' ');
            if (firstSpace > 0)
            {
                var exe = command.Substring(0, firstSpace);
                var args = command.Substring(firstSpace + 1).Trim();
                return (exe, args);
            }

            return (command, "");
        }

        private List<string> KillProcessesByName(string processName)
        {
            var killed = new List<string>();
            var procs = Process.GetProcesses()
                .Where(p =>
                {
                    try { return p.ProcessName.Contains(processName, StringComparison.OrdinalIgnoreCase); }
                    catch { return false; }
                })
                .ToList();

            foreach (var p in procs)
            {
                try
                {
                    if (!p.HasExited)
                    {
                        p.CloseMainWindow();
                        if (!p.WaitForExit(3000))
                            p.Kill();
                        killed.Add(p.ProcessName);
                    }
                }
                catch { }
            }
            return killed;
        }

        private HashSet<string> ResolveAppToProcessNames(string searchName)
        {
            var result = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

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
                    if (!fn.Contains(searchName, StringComparison.OrdinalIgnoreCase))
                        continue;

                    try
                    {
                        var shellType = Type.GetTypeFromCLSID(new Guid("72C24DD5-D70A-438B-8A42-98424B88AFB8"));
                        if (shellType == null) continue;
                        dynamic shell = Activator.CreateInstance(shellType);
                        dynamic shortcut = shell.CreateShortcut(f);
                        var target = (string)shortcut.TargetPath;
                        if (!string.IsNullOrEmpty(target) && target.EndsWith(".exe", StringComparison.OrdinalIgnoreCase))
                        {
                            var exeName = Path.GetFileNameWithoutExtension(target);
                            result.Add(exeName);
                        }
                    }
                    catch { }
                }
            }

            return result;
        }

        // ── File Download ───────────────────────────────────

        /// <summary>
        /// 从指定URL下载文件到本地路径，支持流式下载和进度回调
        /// </summary>
        public async Task<Dictionary<string, object>> Download(string url, string savePath, Action<double>? onProgress = null)
        {
            return await Task.Run(async () =>
            {
                try
                {
                    if (string.IsNullOrWhiteSpace(url))
                        return Result(false, "下载URL不能为空");

                    if (string.IsNullOrWhiteSpace(savePath))
                        return Result(false, "保存路径不能为空");

                    // Validate URL
                    if (!Uri.TryCreate(url, UriKind.Absolute, out var uri) ||
                        (uri.Scheme != "http" && uri.Scheme != "https"))
                        return Result(false, $"无效的下载URL: {url}。仅支持 http/https 协议。");

                    // Resolve and validate save path
                    savePath = ResolvePath(savePath);

                    if (string.IsNullOrWhiteSpace(savePath))
                        return Result(false, "保存路径无效");

                    // Create parent directory if needed
                    var dir = Path.GetDirectoryName(savePath);
                    if (!string.IsNullOrEmpty(dir) && !Directory.Exists(dir))
                        Directory.CreateDirectory(dir);

                    // Download with progress
                    using var client = new HttpClient
                    {
                        Timeout = TimeSpan.FromSeconds(60)
                    };
                    client.DefaultRequestHeaders.UserAgent.ParseAdd("MoonYa-Agent/1.0");

                    var response = await client.GetAsync(url, HttpCompletionOption.ResponseHeadersRead);
                    response.EnsureSuccessStatusCode();

                    var totalBytes = response.Content.Headers.ContentLength ?? -1;

                    using var contentStream = await response.Content.ReadAsStreamAsync();
                    using var fileStream = new FileStream(savePath, FileMode.Create, FileAccess.Write, FileShare.None, 8192, true);

                    var buffer = new byte[8192];
                    long totalRead = 0;
                    int bytesRead;

                    while ((bytesRead = await contentStream.ReadAsync(buffer, 0, buffer.Length)) > 0)
                    {
                        await fileStream.WriteAsync(buffer, 0, bytesRead);
                        totalRead += bytesRead;

                        if (totalBytes > 0 && onProgress != null)
                        {
                            onProgress((double)totalRead / totalBytes * 100);
                        }
                    }

                    var fileInfo = new FileInfo(savePath);
                    var result = new Dictionary<string, object>
                    {
                        ["success"] = true,
                        ["message"] = $"文件下载成功: {Path.GetFileName(savePath)}",
                        ["file"] = new Dictionary<string, object>
                        {
                            ["name"] = Path.GetFileName(savePath),
                            ["size"] = fileInfo.Length,
                            ["type"] = response.Content.Headers.ContentType?.MediaType ?? "application/octet-stream",
                            ["path"] = savePath,
                            ["modified_at"] = fileInfo.LastWriteTime.ToString("yyyy-MM-ddTHH:mm:sszzz")
                        }
                    };
                    return result;
                }
                catch (HttpRequestException ex)
                {
                    return Result(false, $"下载失败（网络错误）: {ex.Message}");
                }
                catch (TaskCanceledException)
                {
                    return Result(false, "下载超时，文件可能过大或网络不稳定");
                }
                catch (UnauthorizedAccessException)
                {
                    return Result(false, $"权限不足，无法写入文件: {savePath}");
                }
                catch (Exception ex)
                {
                    return Result(false, $"下载失败: {ex.Message}");
                }
            });
        }
    }

    // ── Config model classes ──────────────────────────────

    public class FileOperationConfig
    {
        public FileOperationsConfig? FileOperations { get; set; }
    }

    public class FileOperationsConfig
    {
        public List<string> AllowedRoots { get; set; } = new();
        public List<string> BlockedPaths { get; set; } = new();
        public int MaxFileSizeMb { get; set; } = 10;
        public bool EnableDelete { get; set; } = true;
    }
}
