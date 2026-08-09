using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace MoonYa.Services
{
    public enum RiskLevel
    {
        Low,
        Medium,
        High
    }

    public class RiskAssessmentResult
    {
        public RiskLevel Level { get; set; }
        public List<string> MatchedRules { get; set; } = new();
        public string Description { get; set; } = "";
        public bool RequiresConfirmation => Level == RiskLevel.Medium || Level == RiskLevel.High;
    }

    public class RiskAssessmentService
    {
        private readonly RiskRulesConfig _rulesConfig;

        // Cached compiled regex for each rule (keyed by "level|name")
        private readonly Dictionary<string, List<Regex>> _compiledRegex = new();
        private readonly Dictionary<string, List<string>> _lowerKeywords = new();

        public RiskAssessmentService()
        {
            _rulesConfig = LoadConfig();
            CompileRules();
        }

        // ── Config ─────────────────────────────────────────

        private static RiskRulesConfig LoadConfig()
        {
            var configPath = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "launcher_config.json");

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
                    var config = JsonSerializer.Deserialize<LauncherConfig>(json,
                        new JsonSerializerOptions { PropertyNameCaseInsensitive = true });

                    if (config?.ExecutionTools?.RiskRules != null)
                        return config.ExecutionTools.RiskRules;
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"RiskAssessmentService: Failed to load config: {ex.Message}");
                }
            }

            // Default (safe) config — conservative, marks unknown commands as Low
            System.Diagnostics.Debug.WriteLine("RiskAssessmentService: Using default config.");
            return new RiskRulesConfig
            {
                High = new List<RiskRule>(),
                Medium = new List<RiskRule>(),
                Low = new List<RiskRule>()
            };
        }

        private void CompileRules()
        {
            CompileLevel("high", _rulesConfig.High);
            CompileLevel("medium", _rulesConfig.Medium);
            CompileLevel("low", _rulesConfig.Low);
        }

        private void CompileLevel(string levelName, List<RiskRule>? rules)
        {
            if (rules == null) return;

            foreach (var rule in rules)
            {
                var key = $"{levelName}|{rule.Name}";

                // Compile regex patterns (case-insensitive)
                var regexes = new List<Regex>();
                if (rule.Regex != null)
                {
                    foreach (var pattern in rule.Regex)
                    {
                        try
                        {
                            regexes.Add(new Regex(pattern, RegexOptions.IgnoreCase | RegexOptions.Compiled));
                        }
                        catch (Exception ex)
                        {
                            System.Diagnostics.Debug.WriteLine(
                                $"RiskAssessmentService: Invalid regex '{pattern}' in rule '{rule.Name}': {ex.Message}");
                        }
                    }
                }
                _compiledRegex[key] = regexes;

                // Lowercase keywords for case-insensitive matching
                var kws = new List<string>();
                if (rule.Keywords != null)
                {
                    foreach (var kw in rule.Keywords)
                    {
                        kws.Add(kw.ToLowerInvariant());
                    }
                }
                _lowerKeywords[key] = kws;
            }
        }

        // ── Public API ─────────────────────────────────────

        public RiskAssessmentResult AssessCommand(string command)
        {
            if (string.IsNullOrWhiteSpace(command))
            {
                return new RiskAssessmentResult
                {
                    Level = RiskLevel.Low,
                    Description = "Empty command"
                };
            }

            var normalized = command.Trim();
            var lowerCommand = normalized.ToLowerInvariant();

            // Check HIGH rules first
            var highResult = CheckLevel("high", _rulesConfig.High, normalized, lowerCommand);
            if (highResult != null)
                return highResult;

            // Then MEDIUM rules
            var mediumResult = CheckLevel("medium", _rulesConfig.Medium, normalized, lowerCommand);
            if (mediumResult != null)
                return mediumResult;

            // Finally LOW rules (return matched low rules, or a generic LOW)
            var lowResult = CheckLevel("low", _rulesConfig.Low, normalized, lowerCommand);
            if (lowResult != null)
                return lowResult;

            return new RiskAssessmentResult
            {
                Level = RiskLevel.Low,
                Description = "No risk rule matched"
            };
        }

        private RiskAssessmentResult? CheckLevel(string levelName, List<RiskRule>? rules,
            string normalized, string lowerCommand)
        {
            if (rules == null) return null;

            var matchedRuleNames = new List<string>();
            var descriptions = new List<string>();

            foreach (var rule in rules)
            {
                var key = $"{levelName}|{rule.Name}";

                // Check regex
                if (_compiledRegex.TryGetValue(key, out var regexes))
                {
                    foreach (var regex in regexes)
                    {
                        if (regex.IsMatch(normalized))
                        {
                            matchedRuleNames.Add(rule.Name);
                            descriptions.Add(rule.Description);
                            goto NextRule; // Matched, skip keyword check for this rule
                        }
                    }
                }

                // Check keywords (case-insensitive substring match)
                if (_lowerKeywords.TryGetValue(key, out var keywords))
                {
                    foreach (var keyword in keywords)
                    {
                        if (!string.IsNullOrEmpty(keyword) && lowerCommand.Contains(keyword))
                        {
                            matchedRuleNames.Add(rule.Name);
                            descriptions.Add(rule.Description);
                            goto NextRule;
                        }
                    }
                }

            NextRule:;
            }

            if (matchedRuleNames.Count == 0)
                return null;

            var riskLevel = levelName switch
            {
                "high" => RiskLevel.High,
                "medium" => RiskLevel.Medium,
                _ => RiskLevel.Low
            };

            return new RiskAssessmentResult
            {
                Level = riskLevel,
                MatchedRules = matchedRuleNames,
                Description = string.Join("; ", descriptions)
            };
        }

        // ── Python Script Assessment ───────────────────────

        public RiskAssessmentResult AssessPythonScript(string code)
        {
            if (string.IsNullOrWhiteSpace(code))
            {
                return new RiskAssessmentResult
                {
                    Level = RiskLevel.Low,
                    Description = "Empty script"
                };
            }

            var lowerCode = code.ToLowerInvariant();
            var matchedReasons = new List<string>();

            // --- HIGH risk: direct OS-level execution ---

            // os.system, os.popen, subprocess with shell=True
            if (Regex.IsMatch(code, @"os\.system\s*\("))
                matchedReasons.Add("os.system() call - High risk: direct shell command execution");

            if (Regex.IsMatch(code, @"os\.popen\s*\("))
                matchedReasons.Add("os.popen() call - High risk: pipe to shell command");

            if (Regex.IsMatch(code, @"subprocess\.(call|Popen|run|check_call|check_output)\s*\([^)]*shell\s*=\s*True",
                RegexOptions.IgnoreCase | RegexOptions.Singleline))
                matchedReasons.Add("subprocess with shell=True - High risk: arbitrary shell execution");

            // eval/exec on potentially dangerous strings (basic heuristic)
            if (Regex.IsMatch(code, @"\beval\s*\([^)]*input\s*\("))
                matchedReasons.Add("eval(input()) - High risk: arbitrary code execution");

            if (Regex.IsMatch(code, @"\bexec\s*\([^)]*input\s*\("))
                matchedReasons.Add("exec(input()) - High risk: arbitrary code execution");

            // shutil.rmtree - destructive file operation
            if (Regex.IsMatch(code, @"shutil\.rmtree\s*\("))
                matchedReasons.Add("shutil.rmtree() - High risk: recursive directory deletion");

            // os.remove / os.unlink on wildcard or variable paths
            if (Regex.IsMatch(code, @"os\.remove\s*\([^)]*\*"))
                matchedReasons.Add("os.remove() with wildcard - High risk: batch file deletion");

            // --- MEDIUM risk: potentially risky operations ---

            // import os or subprocess (potential for dangerous use)
            if (Regex.IsMatch(lowerCode, @"\bimport\s+os\b") || Regex.IsMatch(lowerCode, @"\bfrom\s+os\s+import\b"))
                matchedReasons.Add("os module imported - Medium risk: enables system operations");

            if (Regex.IsMatch(lowerCode, @"\bimport\s+subprocess\b") || Regex.IsMatch(lowerCode, @"\bfrom\s+subprocess\s+import\b"))
                matchedReasons.Add("subprocess module imported - Medium risk: enables process spawning");

            if (Regex.IsMatch(lowerCode, @"\bimport\s+shutil\b") || Regex.IsMatch(lowerCode, @"\bfrom\s+shutil\s+import\b"))
                matchedReasons.Add("shutil module imported - Medium risk: enables file system operations");

            // __import__ dynamic import
            if (Regex.IsMatch(lowerCode, @"__import__\s*\(\s*['""](?:os|subprocess|shutil|sys)"))
                matchedReasons.Add("Dynamic import of sensitive module - Medium risk");

            // socket for network access
            if (Regex.IsMatch(lowerCode, @"\bimport\s+socket\b") || Regex.IsMatch(lowerCode, @"socket\.socket\s*\("))
                matchedReasons.Add("socket usage - Medium risk: network access");

            // file write operations
            if (Regex.IsMatch(code, @"\bopen\s*\([^)]*['""][wa]"))
                matchedReasons.Add("File write/open in write mode - Medium risk");

            // --- LOW risk: info-only ---

            if (Regex.IsMatch(lowerCode, @"print\s*\(") && matchedReasons.Count == 0)
                matchedReasons.Add("print() statement - Low risk: output only");

            // Determine risk level
            RiskLevel level;
            if (matchedReasons.Any(r => r.Contains("High risk")))
                level = RiskLevel.High;
            else if (matchedReasons.Count > 0)
                level = RiskLevel.Medium;
            else
                level = RiskLevel.Low;

            return new RiskAssessmentResult
            {
                Level = level,
                MatchedRules = matchedReasons,
                Description = matchedReasons.Count > 0
                    ? string.Join("; ", matchedReasons)
                    : "No dangerous patterns detected"
            };
        }
    }

    // ── Config model classes ──────────────────────────────

    public class LauncherConfig
    {
        public ExecutionToolsConfig? ExecutionTools { get; set; }
    }

    public class ExecutionToolsConfig
    {
        public RiskRulesConfig? RiskRules { get; set; }
    }

    public class RiskRulesConfig
    {
        public List<RiskRule> High { get; set; } = new();
        public List<RiskRule> Medium { get; set; } = new();
        public List<RiskRule> Low { get; set; } = new();
    }

    public class RiskRule
    {
        public string Name { get; set; } = "";
        public string Description { get; set; } = "";
        public List<string> Regex { get; set; } = new();
        public List<string> Keywords { get; set; } = new();
    }
}
