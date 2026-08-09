using System;
using System.Collections.Generic;
using System.IO;
using System.Text.Json;
using System.Linq;

namespace MoonYa.Services
{
    public class ExecutionHistoryRecord
    {
        public string Id { get; set; } = Guid.NewGuid().ToString("N")[..12];
        public DateTime Timestamp { get; set; } = DateTime.Now;
        public string Type { get; set; } = "";          // "command" or "python"
        public string Code { get; set; } = "";           // the command or python code
        public string RiskLevel { get; set; } = "";      // "low", "medium", "high"
        public List<string> MatchedRules { get; set; } = new();
        public string Status { get; set; } = "";         // "success", "error", "rejected"
        public string Output { get; set; } = "";         // stdout output
        public string Error { get; set; } = "";          // stderr or error message
        public int ExitCode { get; set; }
        public long DurationMs { get; set; }
    }

    public class ExecutionHistoryService
    {
        private readonly string _logFilePath;
        private readonly object _lock = new();

        public ExecutionHistoryService()
        {
            var baseDir = AppDomain.CurrentDomain.BaseDirectory;
            _logFilePath = Path.Combine(baseDir, "execution_history.json");
        }

        // Record a new execution entry
        public void Record(string type, string code, string riskLevel, List<string> matchedRules,
                          string status, string output, string error, int exitCode, long durationMs)
        {
            var record = new ExecutionHistoryRecord
            {
                Type = type,
                Code = code,
                RiskLevel = riskLevel,
                MatchedRules = matchedRules ?? new List<string>(),
                Status = status,
                Output = Truncate(output, 10000),   // limit output size
                Error = Truncate(error, 5000),       // limit error size
                ExitCode = exitCode,
                DurationMs = durationMs
            };

            AppendRecord(record);
        }

        // Record from ExecutionResult
        public void Record(string type, ExecutionResult result)
        {
            Record(type, result.FullCommand, result.RiskLevel, result.MatchedRules,
                   result.Status, result.Output, result.Error, result.ExitCode, result.DurationMs);
        }

        // Append a single record to the JSON log file
        private void AppendRecord(ExecutionHistoryRecord record)
        {
            lock (_lock)
            {
                try
                {
                    var dir = Path.GetDirectoryName(_logFilePath);
                    if (!string.IsNullOrEmpty(dir) && !Directory.Exists(dir))
                        Directory.CreateDirectory(dir);

                    var json = JsonSerializer.Serialize(record, new JsonSerializerOptions
                    {
                        WriteIndented = false,
                        Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping
                    });

                    // Use JSON Lines format: one JSON object per line
                    File.AppendAllText(_logFilePath, json + Environment.NewLine, System.Text.Encoding.UTF8);
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"ExecutionHistoryService: Failed to write record: {ex.Message}");
                }
            }
        }

        // Get all history records (most recent first)
        public List<ExecutionHistoryRecord> GetHistory(int limit = 100)
        {
            var records = new List<ExecutionHistoryRecord>();

            lock (_lock)
            {
                try
                {
                    if (!File.Exists(_logFilePath))
                        return records;

                    var lines = File.ReadAllLines(_logFilePath, System.Text.Encoding.UTF8);
                    foreach (var line in lines)
                    {
                        if (string.IsNullOrWhiteSpace(line)) continue;
                        try
                        {
                            var record = JsonSerializer.Deserialize<ExecutionHistoryRecord>(line);
                            if (record != null)
                                records.Add(record);
                        }
                        catch { }
                    }
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"ExecutionHistoryService: Failed to read history: {ex.Message}");
                }
            }

            // Return most recent first, limited
            records.Reverse();
            return records.Take(limit).ToList();
        }

        // Get history filtered by type
        public List<ExecutionHistoryRecord> GetHistoryByType(string type, int limit = 50)
        {
            return GetHistory(1000)
                .Where(r => r.Type.Equals(type, StringComparison.OrdinalIgnoreCase))
                .Take(limit)
                .ToList();
        }

        // Clear all history (used for maintenance)
        public void Clear()
        {
            lock (_lock)
            {
                try
                {
                    if (File.Exists(_logFilePath))
                        File.Delete(_logFilePath);
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"ExecutionHistoryService: Failed to clear history: {ex.Message}");
                }
            }
        }

        // Get history file path
        public string GetHistoryFilePath() => _logFilePath;

        // Truncate string to max length
        private static string Truncate(string text, int maxLength)
        {
            if (string.IsNullOrEmpty(text) || text.Length <= maxLength)
                return text;
            return text[..maxLength] + $"\n... [truncated, original length: {text.Length} chars]";
        }
    }
}
