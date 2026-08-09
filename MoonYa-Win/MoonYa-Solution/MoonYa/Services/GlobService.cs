// ┌─────────────────────────────────────────────────────────┐
// │  GlobService — 文件名 glob 模式匹配服务                │
// │  支持：**、*、?、{a,b} 语法，按修改时间倒序返回       │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    public class GlobService
    {
        // 单次最大返回文件数（避免超大项目爆栈）
        private const int MaxResults = 200;

        // 枚举时跳过的噪声目录（提升性能，避免 .git 等目录拖慢搜索）
        private static readonly HashSet<string> SkipDirs = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
        {
            ".git", "node_modules", ".svn", ".hg"
        };

        /// <summary>
        /// 按 glob 模式匹配文件，返回按修改时间倒序排序的文件列表。
        /// 支持语法：**（跨目录）、*（单层通配）、?（单字符）、{a,b}（分组）。
        /// </summary>
        public async Task<object> SearchAsync(string pattern, string? path)
        {
            return await Task.Run<object>(() =>
            {
                try
                {
                    // 参数校验
                    if (string.IsNullOrWhiteSpace(pattern))
                        return new { success = false, error = "pattern 不能为空" };

                    // 解析搜索根目录（复用 FileOperationService 的 ResolvePath 逻辑）
                    var searchPath = string.IsNullOrWhiteSpace(path) || path == "."
                        ? Environment.CurrentDirectory
                        : FileOperationService.ResolvePath(path);

                    if (!Directory.Exists(searchPath))
                        return new { success = false, error = $"搜索路径不存在: {searchPath}" };

                    // 将 glob 模式转换为正则表达式
                    var regex = GlobToRegex(pattern);

                    // 递归枚举文件并匹配
                    var matchedFiles = new List<(string fullPath, DateTime modified)>();
                    foreach (var filePath in EnumerateFilesSkipNoise(searchPath))
                    {
                        // 计算相对路径并规范化为正斜杠（与 glob 模式一致）
                        // 使用 Path.GetRelativePath 健壮处理路径格式差异
                        string relativePath;
                        try
                        {
                            relativePath = Path.GetRelativePath(searchPath, filePath).Replace('\\', '/');
                        }
                        catch
                        {
                            // 跨卷或路径格式异常时，退回到全路径匹配
                            relativePath = filePath.Replace('\\', '/');
                        }

                        if (regex.IsMatch(relativePath))
                        {
                            try
                            {
                                var fi = new FileInfo(filePath);
                                matchedFiles.Add((filePath, fi.LastWriteTime));
                            }
                            catch
                            {
                                // 文件可能已被删除或无权限，跳过
                            }
                        }

                        // 提前终止：已达上限 + buffer（buffer 用于后续排序截断）
                        if (matchedFiles.Count >= MaxResults * 2) break;
                    }

                    // 按修改时间倒序排序
                    var sorted = matchedFiles
                        .OrderByDescending(f => f.modified)
                        .Take(MaxResults)
                        .Select(f => new
                        {
                            path = f.fullPath,
                            modified_at = f.modified.ToString("yyyy-MM-ddTHH:mm:sszzz")
                        })
                        .ToList();

                    return new
                    {
                        success = true,
                        files = sorted,
                        truncated = matchedFiles.Count >= MaxResults * 2
                    };
                }
                catch (Exception ex)
                {
                    return new { success = false, error = $"glob 执行失败: {ex.Message}" };
                }
            });
        }

        // ── 文件枚举（跳过噪声目录）────────────────────────

        /// <summary>
        /// 递归枚举文件，跳过 .git/node_modules 等噪声目录以提升性能。
        /// 使用 Stack 避免 DFS 递归过深。
        /// </summary>
        private static IEnumerable<string> EnumerateFilesSkipNoise(string root)
        {
            var stack = new Stack<string>();
            stack.Push(root);

            while (stack.Count > 0)
            {
                var dir = stack.Pop();

                // 枚举子目录
                string[] subDirs;
                try { subDirs = Directory.GetDirectories(dir); }
                catch { continue; }

                foreach (var sub in subDirs)
                {
                    var name = Path.GetFileName(sub);
                    if (SkipDirs.Contains(name)) continue;
                    stack.Push(sub);
                }

                // 枚举当前目录的文件
                string[] files;
                try { files = Directory.GetFiles(dir); }
                catch { continue; }

                foreach (var f in files)
                    yield return f;
            }
        }

        // ── glob 模式转正则表达式 ─────────────────────────

        /// <summary>
        /// 将 glob 模式转换为正则表达式。
        /// 支持：**（跨目录通配）、*（单层通配）、?（单字符）、{a,b}（分组）。
        /// 路径分隔符统一使用正斜杠 /。
        /// </summary>
        private static Regex GlobToRegex(string glob)
        {
            // 规范化：反斜杠转正斜杠
            glob = glob.Replace('\\', '/');

            var sb = new StringBuilder();
            sb.Append('^');

            int i = 0;
            while (i < glob.Length)
            {
                char c = glob[i];

                // ** 双星通配
                if (c == '*' && i + 1 < glob.Length && glob[i + 1] == '*')
                {
                    // **/ 匹配零或多个路径段
                    if (i + 2 < glob.Length && glob[i + 2] == '/')
                    {
                        sb.Append("(?:.*/)?");
                        i += 3;
                    }
                    else
                    {
                        // ** 匹配任意字符（含路径分隔符）
                        sb.Append(".*");
                        i += 2;
                    }
                }
                else if (c == '*')
                {
                    // * 匹配除路径分隔符外的任意字符
                    sb.Append("[^/]*");
                    i++;
                }
                else if (c == '?')
                {
                    // ? 匹配除路径分隔符外的单个字符
                    sb.Append("[^/]");
                    i++;
                }
                else if (c == '{')
                {
                    // {a,b,c} → (a|b|c)
                    var end = glob.IndexOf('}', i);
                    if (end > i)
                    {
                        var content = glob.Substring(i + 1, end - i - 1);
                        var parts = content.Split(',').Select(p => ConvertGlobPart(p));
                        sb.Append("(").Append(string.Join("|", parts)).Append(")");
                        i = end + 1;
                    }
                    else
                    {
                        sb.Append("\\{");
                        i++;
                    }
                }
                else if (c == '/')
                {
                    sb.Append('/');
                    i++;
                }
                else
                {
                    // 其他字符转义（处理 . + ( ) ^ $ 等正则特殊字符）
                    sb.Append(Regex.Escape(c.ToString()));
                    i++;
                }
            }

            sb.Append('$');
            return new Regex(sb.ToString(), RegexOptions.Compiled);
        }

        /// <summary>
        /// 转换 {a,b} 分组内的单部分为正则（支持嵌套 * 和 ?）。
        /// </summary>
        private static string ConvertGlobPart(string part)
        {
            var sb = new StringBuilder();
            foreach (char c in part)
            {
                if (c == '*')
                {
                    sb.Append("[^/]*");
                }
                else if (c == '?')
                {
                    sb.Append("[^/]");
                }
                else if ("+()^$.{}|\\".IndexOf(c) >= 0)
                {
                    sb.Append('\\').Append(c);
                }
                else
                {
                    sb.Append(c);
                }
            }
            return sb.ToString();
        }
    }
}
