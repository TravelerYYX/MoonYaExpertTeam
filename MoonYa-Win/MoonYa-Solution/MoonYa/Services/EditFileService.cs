// ┌─────────────────────────────────────────────────────────┐
// │  EditFileService — edit_file 工具（view/str_replace/insert）│
// │  仿 Trae Agent TextEditorTool：精确编辑文件，避免整文件重写 │
// │  通过 FileOperationApiServer /file-op action=edit_file 暴露 │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Generic;
using System.IO;
using System.Text;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    /// <summary>
    /// edit_file 工具实现：支持 view / str_replace / insert 三个子命令。
    /// 让 AI 精确编辑文件而非整文件重写，降低 token 消耗与出错概率。
    /// 路径安全校验复用 FileOperationService.IsPathAllowed。
    /// </summary>
    public class EditFileService
    {
        private readonly FileOperationService _fileService;

        public EditFileService(FileOperationService fileService)
        {
            _fileService = fileService;
        }

        /// <summary>
        /// edit_file 入口分发：根据 req.Command 路由到 ViewAsync/StrReplaceAsync/InsertAsync。
        /// </summary>
        public async Task<object> HandleEditFile(FileOpRequest req)
        {
            try
            {
                var command = req.Command ?? "";
                var path = req.Path ?? "";
                var cwd = req.Cwd;

                return command switch
                {
                    "view" => await ViewAsync(path, req.ViewRange, cwd),
                    "str_replace" => await StrReplaceAsync(path, req.OldStr ?? "", req.NewStr ?? "", cwd),
                    "insert" => await InsertAsync(path, req.InsertLine ?? 0, req.NewStr ?? "", cwd),
                    _ => new { success = false, error = $"未知 command: {command}，支持 view/str_replace/insert" }
                };
            }
            catch (Exception ex)
            {
                return new { success = false, error = $"内部错误: {ex.Message}" };
            }
        }

        /// <summary>
        /// view 命令：查看文件内容（带行号，cat -n 格式）或目录列表（深度 2 层）。
        /// viewRange 参数：
        ///   - 文件：[start, end] 行号范围，end=-1 表示到文件末尾
        ///   - 目录：viewRange[1] 作为最大深度（默认 2）
        /// </summary>
        public async Task<object> ViewAsync(string path, int[]? viewRange, string? cwd = null)
        {
            return await Task.Run<object>(() =>
            {
                try
                {
                    var fullPath = ResolvePathWithCwd(path, cwd);

                    if (string.IsNullOrWhiteSpace(fullPath))
                        return new { success = false, error = "路径不能为空" };

                    // 目录：返回深度 2 层的目录树
                    if (Directory.Exists(fullPath))
                        return ViewDirectory(fullPath, viewRange);

                    // 文件：返回带行号内容
                    if (File.Exists(fullPath))
                    {
                        if (!_fileService.IsPathAllowed(fullPath))
                            return new { success = false, error = $"路径不被允许: {fullPath}。文件操作仅限白名单目录。" };
                        return ViewFile(fullPath, viewRange);
                    }

                    return new { success = false, error = $"路径不存在: {fullPath}" };
                }
                catch (UnauthorizedAccessException)
                {
                    return new { success = false, error = "权限不足" };
                }
                catch (Exception ex)
                {
                    return new { success = false, error = $"查看失败: {ex.Message}" };
                }
            });
        }

        /// <summary>
        /// str_replace 命令：精确字符串替换，要求 oldStr 在文件中唯一匹配。
        /// 不唯一时返回所有匹配行号，让 AI 用更长上下文重试。
        /// </summary>
        public async Task<object> StrReplaceAsync(string path, string oldStr, string newStr, string? cwd = null)
        {
            return await Task.Run<object>(() =>
            {
                try
                {
                    var fullPath = ResolvePathWithCwd(path, cwd);

                    if (string.IsNullOrWhiteSpace(fullPath))
                        return new { success = false, error = "路径不能为空" };

                    if (string.IsNullOrEmpty(oldStr))
                        return new { success = false, error = "old_str 不能为空" };

                    if (!File.Exists(fullPath))
                        return new { success = false, error = $"文件不存在: {fullPath}" };

                    if (!_fileService.IsPathAllowed(fullPath))
                        return new { success = false, error = $"路径不被允许: {fullPath}。文件操作仅限白名单目录。" };

                    // 读取文件内容并展开 tab 为 4 空格（与 view 一致，便于 AI 复制匹配）
                    var content = File.ReadAllText(fullPath);
                    content = content.Replace("\t", "    ");

                    // 统计 oldStr 出现次数及起始行号
                    var matches = new List<int>();
                    int idx = 0;
                    while (idx <= content.Length - oldStr.Length)
                    {
                        int pos = content.IndexOf(oldStr, idx, StringComparison.Ordinal);
                        if (pos < 0) break;
                        // 计算起始行号（1-based）：统计 pos 之前的换行符数量
                        int lineNum = 1;
                        for (int i = 0; i < pos; i++)
                            if (content[i] == '\n') lineNum++;
                        matches.Add(lineNum);
                        idx = pos + oldStr.Length;
                    }

                    if (matches.Count == 0)
                        return new
                        {
                            success = false,
                            error = "old_str 在文件中未找到",
                            match_lines = Array.Empty<int>(),
                            suggestion = "请先用 edit_file view 查看文件当前内容，确认 old_str 拼写、缩进与文件实际一致后重试"
                        };

                    if (matches.Count > 1)
                        return new
                        {
                            success = false,
                            error = $"old_str 不唯一，匹配到 {matches.Count} 处",
                            match_lines = matches,
                            // 保留 matches 字段向后兼容（旧调用方可能读取此字段）
                            matches,
                            suggestion = "请使用更长的上下文（包含前后行）作为 old_str 重试，确保 old_str 在文件中唯一"
                        };

                    // 唯一匹配：执行替换
                    int matchPos = content.IndexOf(oldStr, StringComparison.Ordinal);
                    int startLine = matches[0];
                    // 计算 endLine（oldStr 末尾所在行）
                    int endLine = startLine;
                    for (int i = matchPos; i < matchPos + oldStr.Length; i++)
                        if (content[i] == '\n') endLine++;

                    var newContent = content.Substring(0, matchPos) + newStr + content.Substring(matchPos + oldStr.Length);

                    // 自动创建父目录（mkdir -p）
                    var dir = Path.GetDirectoryName(fullPath);
                    if (!string.IsNullOrEmpty(dir) && !Directory.Exists(dir))
                        Directory.CreateDirectory(dir);

                    File.WriteAllText(fullPath, newContent, new UTF8Encoding(false));

                    return new
                    {
                        success = true,
                        path = fullPath,
                        lines_changed = new[] { startLine, endLine }
                    };
                }
                catch (UnauthorizedAccessException)
                {
                    return new { success = false, error = "权限不足" };
                }
                catch (Exception ex)
                {
                    return new { success = false, error = $"替换失败: {ex.Message}" };
                }
            });
        }

        /// <summary>
        /// insert 命令：在指定行号后插入内容。
        /// insertLine=0 表示文件开头插入；insertLine=N 表示第 N 行后插入。
        /// 返回 inserted_at = insertLine + 1（新内容所在行号）。
        /// </summary>
        public async Task<object> InsertAsync(string path, int insertLine, string newStr, string? cwd = null)
        {
            return await Task.Run<object>(() =>
            {
                try
                {
                    var fullPath = ResolvePathWithCwd(path, cwd);

                    if (string.IsNullOrWhiteSpace(fullPath))
                        return new { success = false, error = "路径不能为空" };

                    if (insertLine < 0)
                        return new { success = false, error = "insert_line 不能为负数" };

                    if (!_fileService.IsPathAllowed(fullPath))
                        return new { success = false, error = $"路径不被允许: {fullPath}。文件操作仅限白名单目录。" };

                    // 自动创建父目录（mkdir -p）
                    var dir = Path.GetDirectoryName(fullPath);
                    if (!string.IsNullOrEmpty(dir) && !Directory.Exists(dir))
                        Directory.CreateDirectory(dir);

                    // 读取现有内容（文件不存在则视为空，相当于 create_file）
                    string content = File.Exists(fullPath) ? File.ReadAllText(fullPath) : "";

                    // 定位插入位置（基于 1-based 行号）
                    int insertPos;
                    if (insertLine == 0)
                    {
                        // 文件开头插入
                        insertPos = 0;
                    }
                    else
                    {
                        // 在第 insertLine 行的换行符之后插入
                        int lineNum = 0;
                        insertPos = -1;
                        for (int i = 0; i < content.Length; i++)
                        {
                            if (content[i] == '\n')
                            {
                                lineNum++;
                                if (lineNum == insertLine)
                                {
                                    insertPos = i + 1; // 换行符之后
                                    break;
                                }
                            }
                        }
                        if (insertPos < 0)
                        {
                            // insertLine 超过总行数：追加到文件末尾
                            insertPos = content.Length;
                            // 若内容非空且不以换行结尾，补一个换行以保证新内容独占一行
                            if (content.Length > 0 && content[content.Length - 1] != '\n')
                                content += "\n";
                        }
                    }

                    // 确保 newStr 以换行结尾（让插入内容独立成行，不与下一行粘连）
                    string insertion = newStr.EndsWith("\n") ? newStr : newStr + "\n";

                    var newContent = content.Substring(0, insertPos) + insertion + content.Substring(insertPos);

                    File.WriteAllText(fullPath, newContent, new UTF8Encoding(false));

                    return new
                    {
                        success = true,
                        path = fullPath,
                        inserted_at = insertLine + 1
                    };
                }
                catch (UnauthorizedAccessException)
                {
                    return new { success = false, error = "权限不足" };
                }
                catch (Exception ex)
                {
                    return new { success = false, error = $"插入失败: {ex.Message}" };
                }
            });
        }

        // ── 内部辅助方法 ───────────────────────────────────

        /// <summary>
        /// 解析路径：支持相对路径（基于 cwd）、环境变量、~/、中文路径。
        /// 若 cwd 为空且 path 为相对路径，回退到 FileOperationService.ResolvePath（基于桌面）。
        /// </summary>
        private string ResolvePathWithCwd(string path, string? cwd)
        {
            if (string.IsNullOrWhiteSpace(path))
                return path;

            // 1. 展开环境变量（%USERPROFILE% 等）
            try { path = Environment.ExpandEnvironmentVariables(path); } catch { }

            // 2. 处理 ~/xxx 和 ~\（用户主目录）
            var trimmed = path.Trim();
            if (trimmed.StartsWith("~/") || trimmed.StartsWith("~\\"))
            {
                var home = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
                path = home + trimmed[1..];
            }

            // 3. 统一分隔符为 Windows 风格
            path = path.Replace('/', '\\');

            // 4. 绝对路径直接规范化返回（支持中文路径，Path.GetFullPath 原生支持）
            if (Path.IsPathRooted(path) || path.StartsWith("\\\\"))
                return Path.GetFullPath(path);

            // 5. 相对路径：优先用 cwd 解析；否则回退到 FileOperationService.ResolvePath（基于桌面）
            if (!string.IsNullOrWhiteSpace(cwd))
            {
                try
                {
                    return Path.GetFullPath(Path.Combine(cwd, path));
                }
                catch
                {
                    // cwd 无效，回退到桌面解析
                }
            }

            // ResolvePath 为静态方法，需通过类型名调用
            return FileOperationService.ResolvePath(path);
        }

        /// <summary>
        /// 查看目录：返回深度 2 层的目录树，单层超 100 项截断并提示。
        /// </summary>
        private object ViewDirectory(string path, int[]? viewRange)
        {
            // viewRange 第二个值作为最大深度（默认 2），兼容 [depth] 或 [start, depth] 两种写法
            int maxDepth = 2;
            if (viewRange != null && viewRange.Length >= 2 && viewRange[1] > 0)
                maxDepth = viewRange[1];
            else if (viewRange != null && viewRange.Length >= 1 && viewRange[0] > 0)
                maxDepth = viewRange[0];

            var node = BuildDirectoryNode(path, 0, maxDepth);
            return new
            {
                success = true,
                type = "directory",
                path = path,
                entries = node.Entries,
                truncated = node.Truncated,
                total = node.Total
            };
        }

        /// <summary>
        /// 递归构建目录节点。返回 (entries, truncated, total)。
        /// 单层超过 100 项时截断（保留前 100 项）并标记 truncated=true。
        /// </summary>
        private (List<object> Entries, bool Truncated, int Total) BuildDirectoryNode(string path, int currentDepth, int maxDepth)
        {
            var entries = new List<object>();
            string[] dirs;
            string[] files;
            try
            {
                dirs = Directory.GetDirectories(path);
                files = Directory.GetFiles(path);
            }
            catch (UnauthorizedAccessException)
            {
                // 无权限访问该目录：返回空列表
                return (entries, false, 0);
            }

            var allItems = new List<(string FullPath, bool IsDir)>();
            foreach (var d in dirs) allItems.Add((d, true));
            foreach (var f in files) allItems.Add((f, false));

            int total = allItems.Count;
            bool truncated = false;
            if (allItems.Count > 100)
            {
                truncated = true;
                allItems = allItems.GetRange(0, 100);
            }

            foreach (var (fullPath, isDir) in allItems)
            {
                var name = Path.GetFileName(fullPath);
                if (isDir)
                {
                    object? children = null;
                    // 未达到最大深度时递归展开子目录
                    if (currentDepth + 1 < maxDepth)
                    {
                        var childNode = BuildDirectoryNode(fullPath, currentDepth + 1, maxDepth);
                        children = new
                        {
                            entries = childNode.Entries,
                            truncated = childNode.Truncated,
                            total = childNode.Total
                        };
                    }
                    entries.Add(new
                    {
                        name,
                        type = "directory",
                        children
                    });
                }
                else
                {
                    long size = 0;
                    try { size = new FileInfo(fullPath).Length; } catch { }
                    entries.Add(new
                    {
                        name,
                        type = "file",
                        size
                    });
                }
            }

            return (entries, truncated, total);
        }

        /// <summary>
        /// 查看文件：返回带行号内容（cat -n 格式：行号右对齐 6 位 + 制表符 + 内容）。
        /// viewRange=[start, end] 行号范围（1-based），end=-1 表示到文件末尾。
        /// </summary>
        private object ViewFile(string path, int[]? viewRange)
        {
            // 使用 ReadAllLines 自动处理 \r\n / \n / \r 三种换行符，且不产生末尾空行
            var lines = File.ReadAllLines(path);
            // 展开制表符为 4 空格（与 str_replace 一致，便于 AI 复制匹配）
            for (int i = 0; i < lines.Length; i++)
                lines[i] = lines[i].Replace("\t", "    ");

            int totalLines = lines.Length;
            int startLine = 1;
            int endLine = totalLines;

            if (viewRange != null && viewRange.Length >= 2)
            {
                startLine = Math.Max(1, viewRange[0]);
                endLine = viewRange[1] == -1 ? totalLines : Math.Min(totalLines, viewRange[1]);
            }
            else if (viewRange != null && viewRange.Length >= 1)
            {
                startLine = Math.Max(1, viewRange[0]);
            }

            // 起始行超出文件总行数：返回空内容
            if (startLine > totalLines)
            {
                return new
                {
                    success = true,
                    type = "file",
                    path = path,
                    total_lines = totalLines,
                    start_line = startLine,
                    end_line = startLine - 1,
                    content = ""
                };
            }

            var sb = new StringBuilder();
            int actualEnd = Math.Min(endLine, totalLines);
            for (int i = startLine - 1; i < actualEnd; i++)
            {
                // cat -n 格式：行号右对齐 6 位 + 制表符 + 内容 + 换行
                sb.Append((i + 1).ToString().PadLeft(6));
                sb.Append('\t');
                sb.AppendLine(lines[i]);
            }

            return new
            {
                success = true,
                type = "file",
                path = path,
                total_lines = totalLines,
                start_line = startLine,
                end_line = actualEnd,
                content = sb.ToString()
            };
        }
    }
}
