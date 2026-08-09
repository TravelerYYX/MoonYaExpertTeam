// ┌─────────────────────────────────────────────────────────┐
// │  ViewDirectoryService — 目录树查看服务                 │
// │  递归遍历目录树，返回结构化 JSON（文件/文件夹区分）   │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    public class ViewDirectoryService
    {
        // 单层最大条目数（超过则截断）
        private const int MaxEntriesPerLevel = 100;

        // 默认排除的噪声目录（避免 .git/node_modules 等干扰视图）
        private static readonly HashSet<string> DefaultExcludes = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
        {
            ".git", "node_modules", "vendor", "bin", "obj",
            ".venv", "__pycache__", ".vs", ".idea"
        };

        /// <summary>
        /// 递归查看目录树，返回结构化 JSON。
        /// 深度参数控制递归层数（默认 2 层），支持自定义排除模式。
        /// 单层超过 100 项截断，顶层 truncated=true。
        /// </summary>
        public async Task<object> ViewAsync(string path, int depth, List<string>? excludePatterns)
        {
            return await Task.Run<object>(() =>
            {
                try
                {
                    // 解析目录路径（复用 FileOperationService 的 ResolvePath 逻辑）
                    var resolvedPath = string.IsNullOrWhiteSpace(path) || path == "."
                        ? Environment.CurrentDirectory
                        : FileOperationService.ResolvePath(path);

                    if (!Directory.Exists(resolvedPath))
                        return new { success = false, error = $"目录不存在: {resolvedPath}" };

                    // 合并默认排除与自定义排除
                    var excludes = BuildExcludeSet(excludePatterns);

                    // 深度参数校验（至少 1 层，默认 2 层）
                    var effectiveDepth = depth < 1 ? 2 : depth;

                    // 递归构建目录树，收集是否发生截断
                    var (entries, truncated) = BuildEntries(resolvedPath, effectiveDepth, excludes);

                    return new
                    {
                        success = true,
                        root = resolvedPath,
                        entries,
                        truncated
                    };
                }
                catch (Exception ex)
                {
                    return new { success = false, error = $"查看目录失败: {ex.Message}" };
                }
            });
        }

        // ── 构建排除集合 ──────────────────────────────────

        /// <summary>
        /// 合并默认排除目录与用户自定义排除模式。
        /// 自定义模式支持精确名称匹配（不区分大小写）。
        /// </summary>
        private static HashSet<string> BuildExcludeSet(List<string>? excludePatterns)
        {
            var set = new HashSet<string>(DefaultExcludes, StringComparer.OrdinalIgnoreCase);
            if (excludePatterns == null) return set;

            foreach (var p in excludePatterns)
            {
                if (string.IsNullOrWhiteSpace(p)) continue;
                // 去除可能的路径分隔符，只保留名称部分
                var name = p.Replace('/', '\\').Trim('\\');
                if (!string.IsNullOrEmpty(name))
                    set.Add(name);
            }
            return set;
        }

        // ── 递归构建目录条目 ──────────────────────────────

        /// <summary>
        /// 递归构建目录条目列表。
        /// depth=1 表示只列出当前层的文件和文件夹（不递归）。
        /// 单层超过 100 项则截断，并通过 truncated 返回值向上传递。
        /// 返回：(条目列表, 是否任一层发生截断)
        /// </summary>
        private static (List<object> entries, bool truncated) BuildEntries(
            string dir, int depth, HashSet<string> excludes)
        {
            var entries = new List<object>();
            var truncated = false;

            // 收集子条目（目录在前，文件在后，便于查看）
            List<string> subDirs;
            List<string> files;

            try
            {
                subDirs = Directory.GetDirectories(dir).ToList();
            }
            catch
            {
                subDirs = new List<string>();
            }

            try
            {
                files = Directory.GetFiles(dir).ToList();
            }
            catch
            {
                files = new List<string>();
            }

            // 过滤排除目录
            subDirs = subDirs
                .Where(d => !excludes.Contains(Path.GetFileName(d)))
                .ToList();

            // 合并并按类型排序（目录优先，各自按名称字母序）
            var allItems = new List<(string path, bool isDir)>();
            foreach (var d in subDirs.OrderBy(d => Path.GetFileName(d), StringComparer.OrdinalIgnoreCase))
                allItems.Add((d, true));
            foreach (var f in files.OrderBy(f => Path.GetFileName(f), StringComparer.OrdinalIgnoreCase))
                allItems.Add((f, false));

            // 单层截断检查
            if (allItems.Count > MaxEntriesPerLevel)
            {
                truncated = true;
                allItems = allItems.Take(MaxEntriesPerLevel).ToList();
            }

            foreach (var (itemPath, isDir) in allItems)
            {
                var name = Path.GetFileName(itemPath);

                if (isDir)
                {
                    object? children = null;
                    // depth > 1 时递归到下一层
                    if (depth > 1)
                    {
                        var (childEntries, childTruncated) = BuildEntries(itemPath, depth - 1, excludes);
                        children = childEntries;
                        if (childTruncated) truncated = true;
                    }

                    DateTime modified;
                    try { modified = Directory.GetLastWriteTime(itemPath); }
                    catch { modified = DateTime.MinValue; }

                    entries.Add(new
                    {
                        name,
                        type = "dir",
                        modified_at = modified.ToString("yyyy-MM-ddTHH:mm:sszzz"),
                        children
                    });
                }
                else
                {
                    long size = 0;
                    DateTime modified;
                    try
                    {
                        var fi = new FileInfo(itemPath);
                        size = fi.Length;
                        modified = fi.LastWriteTime;
                    }
                    catch
                    {
                        modified = DateTime.MinValue;
                    }

                    entries.Add(new
                    {
                        name,
                        type = "file",
                        size,
                        modified_at = modified.ToString("yyyy-MM-ddTHH:mm:sszzz")
                    });
                }
            }

            return (entries, truncated);
        }
    }
}
