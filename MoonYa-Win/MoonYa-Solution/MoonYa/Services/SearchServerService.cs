using System;
using System.Diagnostics;
using System.IO;

namespace MoonYa.Services
{
    public sealed class SearchServerService
    {
        private Process? _process;
        private readonly string _host;
        private readonly int _port;
        private readonly string _scriptPath;
        private readonly string _pythonExecutable;

        public int Port => _port;
        public bool IsRunning => _process != null && !_process.HasExited;

        public SearchServerService(string host, int port, string scriptPath, string pythonExecutable)
        {
            _host = string.IsNullOrWhiteSpace(host) ? throw new ArgumentException("host is required", nameof(host)) : host;
            _port = port is > 0 and <= 65535 ? port : throw new ArgumentOutOfRangeException(nameof(port));
            _scriptPath = Path.GetFullPath(Environment.ExpandEnvironmentVariables(scriptPath));
            _pythonExecutable = string.IsNullOrWhiteSpace(pythonExecutable)
                ? throw new ArgumentException("pythonExecutable is required", nameof(pythonExecutable))
                : Environment.ExpandEnvironmentVariables(pythonExecutable);
        }

        public void Start()
        {
            if (IsRunning) return;
            if (!File.Exists(_scriptPath))
                throw new FileNotFoundException("Search service script was not found", _scriptPath);

            var startInfo = new ProcessStartInfo
            {
                FileName = _pythonExecutable,
                Arguments = $"\"{_scriptPath}\" {_host} {_port}",
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            };

            _process = new Process { StartInfo = startInfo };
            _process.OutputDataReceived += (_, e) =>
            {
                if (!string.IsNullOrEmpty(e.Data)) Debug.WriteLine($"[search-server] {e.Data}");
            };
            _process.ErrorDataReceived += (_, e) =>
            {
                if (!string.IsNullOrEmpty(e.Data)) Debug.WriteLine($"[search-server-err] {e.Data}");
            };
            _process.Start();
            _process.BeginOutputReadLine();
            _process.BeginErrorReadLine();
        }

        public void Stop()
        {
            if (_process == null) return;
            try
            {
                if (!_process.HasExited)
                {
                    _process.Kill(entireProcessTree: true);
                    _process.WaitForExit();
                }
            }
            finally
            {
                _process.Dispose();
                _process = null;
            }
        }
    }
}
