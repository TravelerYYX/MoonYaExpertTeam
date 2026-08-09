// ┌─────────────────────────────────────────────────────────┐
// │  SystemStatusService — 系统环境感知服务                  │
// │  提供 CPU / 内存 / 磁盘 / 网络状态监控                   │
// └─────────────────────────────────────────────────────────┘

using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net.NetworkInformation;
using System.Threading;
using System.Threading.Tasks;
using Microsoft.VisualBasic.Devices;

namespace MoonYa.Services
{
    public class SystemStatusService
    {
        // ── Public API ─────────────────────────────────────

        /// <summary>
        /// 获取系统综合状态，包含 CPU 使用率、内存使用率、磁盘可用空间、网络连接状态。
        /// 所有字段使用 snake_case 命名，与 PHP 端约定一致。
        /// 任何子项失败时返回合理默认值（-1 或 false），不影响整体响应。
        /// </summary>
        public async Task<object> GetSystemStatusAsync()
        {
            return await Task.Run(() =>
            {
                // 使用 Dictionary 以确保 snake_case 键在序列化时被保留
                // （FileOperationApiServer 使用 CamelCase 命名策略，会影响匿名对象属性名但不影响字典键）
                return new Dictionary<string, object>
                {
                    ["cpu_usage_percent"] = GetCpuUsagePercent(),
                    ["memory_usage_percent"] = GetMemoryUsagePercent(),
                    ["disk_free_gb"] = GetDiskFreeGb(),
                    ["network_connected"] = GetNetworkConnected()
                };
            });
        }

        // ── CPU ────────────────────────────────────────────

        /// <summary>
        /// 通过 PerformanceCounter 获取 CPU 使用率百分比。
        /// 注意：首次调用 NextValue() 返回 0，需要 sleep 100ms 后再调用一次。
        /// 失败时返回 -1。
        /// </summary>
        private static double GetCpuUsagePercent()
        {
            try
            {
                using var counter = new PerformanceCounter("Processor", "% Processor Time", "_Total");
                // 首次调用返回 0，触发计数器初始化
                _ = counter.NextValue();
                Thread.Sleep(100);
                var value = counter.NextValue();
                return Math.Round(value, 2);
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"SystemStatusService: GetCpuUsagePercent failed: {ex.Message}");
                return -1;
            }
        }

        // ── Memory ─────────────────────────────────────────

        /// <summary>
        /// 通过 PerformanceCounter 获取内存使用率百分比（% Committed Bytes In Use）。
        /// 失败时尝试用 ComputerInfo 估算，再失败用当前进程 WorkingSet64 估算，最终返回 -1。
        /// </summary>
        private static double GetMemoryUsagePercent()
        {
            try
            {
                using var counter = new PerformanceCounter("Memory", "% Committed Bytes In Use");
                var value = counter.NextValue();
                return Math.Round(value, 2);
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"SystemStatusService: PerformanceCounter Memory failed: {ex.Message}");
                try
                {
                    // 兜底方案1：使用 ComputerInfo 获取物理内存使用情况
                    var computerInfo = new ComputerInfo();
                    var totalBytes = (long)computerInfo.TotalPhysicalMemory;
                    var availableBytes = (long)computerInfo.AvailablePhysicalMemory;
                    if (totalBytes > 0)
                    {
                        var usedBytes = totalBytes - availableBytes;
                        var percent = (double)usedBytes / totalBytes * 100;
                        return Math.Round(Math.Min(percent, 100), 2);
                    }
                }
                catch (Exception ex2)
                {
                    Debug.WriteLine($"SystemStatusService: ComputerInfo fallback failed: {ex2.Message}");
                    try
                    {
                        // 兜底方案2：用当前进程 WorkingSet64 估算（仅作粗略参考）
                        var workingSet = Process.GetCurrentProcess().WorkingSet64;
                        // 假设系统总内存约 8GB 作为估算基准（仅作 fallback）
                        const long assumedTotalBytes = 8L * 1024 * 1024 * 1024;
                        var estimated = (double)workingSet / assumedTotalBytes * 100;
                        return Math.Round(Math.Min(estimated, 100), 2);
                    }
                    catch (Exception ex3)
                    {
                        Debug.WriteLine($"SystemStatusService: WorkingSet fallback failed: {ex3.Message}");
                    }
                }
                return -1;
            }
        }

        // ── Disk ───────────────────────────────────────────

        /// <summary>
        /// 获取系统盘（C:）可用空间（GB）。失败时返回 -1。
        /// </summary>
        private static double GetDiskFreeGb()
        {
            try
            {
                var drive = new DriveInfo("C");
                var freeBytes = drive.AvailableFreeSpace;
                return Math.Round(freeBytes / 1024.0 / 1024.0 / 1024.0, 2);
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"SystemStatusService: GetDiskFreeGb failed: {ex.Message}");
                return -1;
            }
        }

        // ── Network ────────────────────────────────────────

        /// <summary>
        /// 获取网络是否连接。失败时返回 false。
        /// </summary>
        private static bool GetNetworkConnected()
        {
            try
            {
                return NetworkInterface.GetIsNetworkAvailable();
            }
            catch (Exception ex)
            {
                Debug.WriteLine($"SystemStatusService: GetNetworkConnected failed: {ex.Message}");
                return false;
            }
        }
    }
}
