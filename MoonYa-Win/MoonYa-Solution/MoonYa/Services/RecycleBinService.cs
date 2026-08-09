using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Threading.Tasks;

namespace MoonYa.Services
{
    /// <summary>
    /// Reads the logical Windows Recycle Bin state through the Shell API.
    /// This deliberately does not enumerate the implementation directory,
    /// whose account folders and desktop.ini files are not deleted items.
    /// </summary>
    public sealed class RecycleBinService
    {
        private const string StatusSource = "SHQueryRecycleBin";

        public Task<object> GetStatusAsync(string? rootPath = null)
        {
            var queryRoot = string.IsNullOrWhiteSpace(rootPath) ? null : rootPath.Trim();
            var info = new SHQUERYRBINFO
            {
                cbSize = (uint)Marshal.SizeOf<SHQUERYRBINFO>()
            };

            var hresult = SHQueryRecycleBin(queryRoot, ref info);
            if (hresult != 0)
            {
                var exception = Marshal.GetExceptionForHR(hresult);
                var message = exception?.Message ?? new Win32Exception(hresult).Message;
                return Task.FromResult<object>(new
                {
                    success = false,
                    status = "error",
                    stage = "inspect",
                    source = StatusSource,
                    queriedRoot = queryRoot,
                    errorCode = "recycle_bin_query_failed",
                    hresult = $"0x{unchecked((uint)hresult):X8}",
                    message = $"Windows 回收站状态查询失败：{message}"
                });
            }

            var itemCount = Math.Max(0L, info.i64NumItems);
            var sizeBytes = Math.Max(0L, info.i64Size);
            return Task.FromResult<object>(new
            {
                success = true,
                status = "success",
                stage = "inspect",
                source = StatusSource,
                queriedRoot = queryRoot,
                isEmpty = itemCount == 0,
                itemCount,
                sizeBytes,
                message = $"Windows 回收站包含 {itemCount} 个已删除项目，共 {sizeBytes} 字节。"
            });
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct SHQUERYRBINFO
        {
            public uint cbSize;
            public long i64Size;
            public long i64NumItems;
        }

        [DllImport("shell32.dll", EntryPoint = "SHQueryRecycleBinW", CharSet = CharSet.Unicode, ExactSpelling = true)]
        private static extern int SHQueryRecycleBin(
            [MarshalAs(UnmanagedType.LPWStr)] string? pszRootPath,
            ref SHQUERYRBINFO pSHQueryRBInfo);
    }
}
