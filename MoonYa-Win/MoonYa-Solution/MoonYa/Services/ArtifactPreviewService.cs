using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.Json;

namespace MoonYa.Services
{
    /// <summary>
    /// Produces safe preview payloads without exposing file:// URLs to the web view.
    /// </summary>
    public sealed class ArtifactPreviewService
    {
        private const long MaximumPreviewBytes = 20L * 1024L * 1024L;
        private const long MaximumTextBytes = 2L * 1024L * 1024L;

        private static readonly Dictionary<string, string> MimeTypes = new(StringComparer.OrdinalIgnoreCase)
        {
            [".txt"] = "text/plain",
            [".md"] = "text/markdown",
            [".json"] = "application/json",
            [".csv"] = "text/csv",
            [".log"] = "text/plain",
            [".xml"] = "application/xml",
            [".html"] = "text/html",
            [".htm"] = "text/html",
            [".css"] = "text/css",
            [".js"] = "text/javascript",
            [".ts"] = "text/typescript",
            [".php"] = "text/x-php",
            [".py"] = "text/x-python",
            [".cs"] = "text/x-csharp",
            [".png"] = "image/png",
            [".jpg"] = "image/jpeg",
            [".jpeg"] = "image/jpeg",
            [".gif"] = "image/gif",
            [".webp"] = "image/webp",
            [".svg"] = "image/svg+xml",
            [".pdf"] = "application/pdf",
            [".mp3"] = "audio/mpeg",
            [".wav"] = "audio/wav",
            [".ogg"] = "audio/ogg",
            [".mp4"] = "video/mp4",
            [".webm"] = "video/webm",
            [".doc"] = "application/msword",
            [".docx"] = "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            [".xls"] = "application/vnd.ms-excel",
            [".xlsx"] = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            [".ppt"] = "application/vnd.ms-powerpoint",
            [".pptx"] = "application/vnd.openxmlformats-officedocument.presentationml.presentation"
        };

        public string Preview(string path, IEnumerable<string>? allowedRoots = null)
        {
            try
            {
                var fullPath = Path.GetFullPath(path);
                if (!File.Exists(fullPath))
                {
                    return Error("file_not_found", "文件不存在。");
                }

                var roots = BuildAllowedRoots(allowedRoots).ToArray();
                if (!roots.Any(root => IsWithin(fullPath, root)))
                {
                    return Error("path_not_allowed", "该文件不在允许预览的目录中。");
                }

                var info = new FileInfo(fullPath);
                var extension = info.Extension;
                var mime = MimeTypes.TryGetValue(extension, out var known) ? known : "application/octet-stream";
                var kind = Classify(mime, extension);
                var payload = new Dictionary<string, object?>
                {
                    ["ok"] = true,
                    ["name"] = info.Name,
                    ["path"] = fullPath,
                    ["size"] = info.Length,
                    ["mime"] = mime,
                    ["kind"] = kind,
                    ["modified_at"] = info.LastWriteTimeUtc.ToString("O")
                };

                if (kind == "office" || kind == "unsupported")
                {
                    payload["metadata_only"] = true;
                }
                else if (kind == "text")
                {
                    if (info.Length > MaximumTextBytes)
                    {
                        return Error("preview_too_large", "文本文件超过 2 MB，仅可使用原生应用打开。", payload);
                    }
                    var text = File.ReadAllText(fullPath, DetectEncoding(fullPath));
                    payload["text"] = text;
                    payload["sandbox"] = mime == "text/html" ? "allow-same-origin" : null;
                }
                else
                {
                    if (info.Length > MaximumPreviewBytes)
                    {
                        return Error("preview_too_large", "文件超过 20 MB，仅可使用原生应用打开。", payload);
                    }
                    payload["data_url"] = $"data:{mime};base64,{Convert.ToBase64String(File.ReadAllBytes(fullPath))}";
                }

                return JsonSerializer.Serialize(payload);
            }
            catch (Exception ex)
            {
                return Error("preview_failed", ex.Message);
            }
        }

        private static IEnumerable<string> BuildAllowedRoots(IEnumerable<string>? supplied)
        {
            var roots = new List<string>
            {
                Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory),
                Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments),
                Environment.GetFolderPath(Environment.SpecialFolder.MyPictures),
                Environment.GetFolderPath(Environment.SpecialFolder.MyMusic),
                Environment.GetFolderPath(Environment.SpecialFolder.MyVideos),
                Path.GetTempPath(),
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "MoonYa")
            };
            if (supplied != null)
            {
                roots.AddRange(supplied.Where(p => !string.IsNullOrWhiteSpace(p)));
            }
            return roots.Where(Directory.Exists).Select(Path.GetFullPath).Distinct(StringComparer.OrdinalIgnoreCase);
        }

        private static bool IsWithin(string path, string root)
        {
            var normalizedRoot = root.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)
                + Path.DirectorySeparatorChar;
            return path.StartsWith(normalizedRoot, StringComparison.OrdinalIgnoreCase);
        }

        private static string Classify(string mime, string extension)
        {
            if (mime.StartsWith("text/", StringComparison.OrdinalIgnoreCase) ||
                extension.Equals(".json", StringComparison.OrdinalIgnoreCase) ||
                extension.Equals(".xml", StringComparison.OrdinalIgnoreCase))
                return "text";
            if (mime.StartsWith("image/", StringComparison.OrdinalIgnoreCase)) return "image";
            if (mime.StartsWith("audio/", StringComparison.OrdinalIgnoreCase)) return "audio";
            if (mime.StartsWith("video/", StringComparison.OrdinalIgnoreCase)) return "video";
            if (mime == "application/pdf") return "pdf";
            if (extension is ".doc" or ".docx" or ".xls" or ".xlsx" or ".ppt" or ".pptx") return "office";
            return "unsupported";
        }

        private static Encoding DetectEncoding(string path)
        {
            using var stream = File.OpenRead(path);
            Span<byte> bom = stackalloc byte[4];
            var count = stream.Read(bom);
            if (count >= 3 && bom[0] == 0xEF && bom[1] == 0xBB && bom[2] == 0xBF) return Encoding.UTF8;
            if (count >= 2 && bom[0] == 0xFF && bom[1] == 0xFE) return Encoding.Unicode;
            if (count >= 2 && bom[0] == 0xFE && bom[1] == 0xFF) return Encoding.BigEndianUnicode;
            return new UTF8Encoding(false, false);
        }

        private static string Error(string code, string message, Dictionary<string, object?>? extra = null)
        {
            var payload = extra ?? new Dictionary<string, object?>();
            payload["ok"] = false;
            payload["error"] = new { code, message };
            return JsonSerializer.Serialize(payload);
        }
    }
}