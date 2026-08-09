using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace MoonYa.Services
{
    public class SandboxConfig
    {
        [JsonPropertyName("allowed_paths")]
        public List<string> AllowedPaths { get; set; } = new();

        [JsonPropertyName("network_enabled")]
        public bool NetworkEnabled { get; set; } = false;

        [JsonPropertyName("max_cpu_time_sec")]
        public int MaxCpuTimeSec { get; set; } = 30;

        [JsonPropertyName("max_memory_mb")]
        public int MaxMemoryMb { get; set; } = 512;

        [JsonPropertyName("temp_root")]
        public string TempRoot { get; set; } = "sandbox_temp";

        [JsonPropertyName("python_timeout_sec")]
        public int PythonTimeoutSec { get; set; } = 30;

        [JsonPropertyName("command_timeout_sec")]
        public int CommandTimeoutSec { get; set; } = 60;
    }

    public class SandboxService
    {
        [DllImport("kernel32.dll", CharSet = CharSet.Unicode)]
        private static extern IntPtr CreateJobObject(IntPtr lpJobAttributes, string? lpName);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool SetInformationJobObject(IntPtr hJob, JOBOBJECTINFOCLASS JobObjectInfoClass, IntPtr lpJobObjectInfo, uint cbJobObjectInfoLength);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool AssignProcessToJobObject(IntPtr hJob, IntPtr hProcess);

        private enum JOBOBJECTINFOCLASS { ExtendedLimitInformation = 9 }

        [StructLayout(LayoutKind.Sequential)]
        private struct JOBOBJECT_EXTENDED_LIMIT_INFORMATION
        {
            public JOBOBJECT_BASIC_LIMIT_INFORMATION BasicLimitInformation;
            public IO_COUNTERS IoInfo;
            public UIntPtr ProcessMemoryLimit;
            public UIntPtr JobMemoryLimit;
            public UIntPtr PeakProcessMemoryUsed;
            public UIntPtr PeakJobMemoryUsed;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct JOBOBJECT_BASIC_LIMIT_INFORMATION
        {
            public long PerProcessUserTimeLimit;
            public long PerJobUserTimeLimit;
            public uint LimitFlags;
            public UIntPtr MinimumWorkingSetSize;
            public UIntPtr MaximumWorkingSetSize;
            public uint ActiveProcessLimit;
            public IntPtr Affinity;
            public uint PriorityClass;
            public uint SchedulingClass;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct IO_COUNTERS
        {
            public ulong ReadOperationCount;
            public ulong WriteOperationCount;
            public ulong OtherOperationCount;
            public ulong ReadTransferCount;
            public ulong WriteTransferCount;
            public ulong OtherTransferCount;
        }

        private const uint JOB_OBJECT_LIMIT_PROCESS_TIME = 0x00000002;
        private const uint JOB_OBJECT_LIMIT_PROCESS_MEMORY = 0x00000100;
        private const uint JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE = 0x00002000;

        private readonly SandboxConfig _config;
        private readonly string _tempRootFullPath;
        private readonly ConcurrentBag<string> _sandboxDirectories = new();
        private readonly ConcurrentDictionary<int, IntPtr> _jobHandles = new(); // processId -> jobObject handle

        public SandboxService()
        {
            _config = LoadConfig();
            _tempRootFullPath = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, _config.TempRoot);
        }

        private static SandboxConfig LoadConfig()
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
                    using var doc = JsonDocument.Parse(json);
                    if (doc.RootElement.TryGetProperty("execution_tools", out var execTools) &&
                        execTools.TryGetProperty("sandbox", out var sandboxElement))
                    {
                        var config = JsonSerializer.Deserialize<SandboxConfig>(
                            sandboxElement.GetRawText(),
                            new JsonSerializerOptions { PropertyNameCaseInsensitive = true });
                        if (config != null)
                            return config;
                    }
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"SandboxService: Failed to load config: {ex.Message}");
                }
            }

            System.Diagnostics.Debug.WriteLine("SandboxService: Using default config.");
            return new SandboxConfig();
        }

        public string CreateSandboxDirectory()
        {
            var dirName = $"task_{Guid.NewGuid():N}";
            var fullPath = Path.Combine(_tempRootFullPath, dirName);
            Directory.CreateDirectory(fullPath);
            _sandboxDirectories.Add(fullPath);
            return fullPath;
        }

        public void CleanupSandbox(string directoryPath)
        {
            if (string.IsNullOrEmpty(directoryPath)) return;
            try
            {
                if (Directory.Exists(directoryPath))
                {
                    Directory.Delete(directoryPath, true);
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"SandboxService: Failed to cleanup {directoryPath}: {ex.Message}");
            }
        }

        public ProcessStartInfo CreateSandboxedProcessStartInfo(string command, string arguments, string workingDirectory)
        {
            var startInfo = new ProcessStartInfo
            {
                FileName = command,
                Arguments = arguments,
                WorkingDirectory = workingDirectory,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true
            };

            // Redirect stdin to provide clean EOF (prevents child processes from inheriting
            // the parent's invalid stdin handle, which can cause hangs)
            startInfo.RedirectStandardInput = true;
            startInfo.EnvironmentVariables["PYTHONPATH"] = workingDirectory;
            return startInfo;
        }

        public void ApplyResourceLimits(Process process, string sandboxDir)
        {
            var hJob = CreateJobObject(IntPtr.Zero, null);
            if (hJob == IntPtr.Zero) return;

            var cpuLimit100ns = _config.MaxCpuTimeSec * 10_000_000L;
            var memLimitBytes = (UIntPtr)((ulong)_config.MaxMemoryMb * 1024 * 1024);

            var info = new JOBOBJECT_EXTENDED_LIMIT_INFORMATION
            {
                BasicLimitInformation = new JOBOBJECT_BASIC_LIMIT_INFORMATION
                {
                    PerProcessUserTimeLimit = cpuLimit100ns,
                    LimitFlags = JOB_OBJECT_LIMIT_PROCESS_TIME | JOB_OBJECT_LIMIT_PROCESS_MEMORY | JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
                },
                ProcessMemoryLimit = memLimitBytes
            };

            int size = Marshal.SizeOf(typeof(JOBOBJECT_EXTENDED_LIMIT_INFORMATION));
            IntPtr ptr = Marshal.AllocHGlobal(size);
            Marshal.StructureToPtr(info, ptr, false);
            SetInformationJobObject(hJob, JOBOBJECTINFOCLASS.ExtendedLimitInformation, ptr, (uint)size);
            Marshal.FreeHGlobal(ptr);

            AssignProcessToJobObject(hJob, process.Handle);

            // Keep handle alive – store it keyed by process ID so GC doesn't destroy the job object
            _jobHandles[process.Id] = hJob;
        }

        /// <summary>Release the job object handle after the process has exited (or on cleanup).</summary>
        public void ReleaseJobHandle(int processId)
        {
            if (_jobHandles.TryRemove(processId, out var handle))
            {
                CloseHandle(handle);
            }
        }

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool CloseHandle(IntPtr hObject);

        public void CleanupAll()
        {
            foreach (var dir in _sandboxDirectories)
            {
                CleanupSandbox(dir);
            }

            // Clean up any remaining job object handles
            foreach (var kvp in _jobHandles)
            {
                CloseHandle(kvp.Value);
            }
            _jobHandles.Clear();
        }

        public bool IsPathAllowed(string path)
        {
            if (string.IsNullOrEmpty(path)) return false;
            return _config.AllowedPaths.Any(allowed =>
                path.StartsWith(allowed, StringComparison.OrdinalIgnoreCase));
        }
    }
}
