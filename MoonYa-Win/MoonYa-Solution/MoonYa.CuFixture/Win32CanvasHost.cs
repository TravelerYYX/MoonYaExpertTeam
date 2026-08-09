using System.Runtime.InteropServices;
using System.Windows.Interop;

namespace MoonYa.CuFixture;

public sealed class Win32CanvasHost : HwndHost
{
    private const string ClassName = "MoonYaCuSelfDrawnFixture";
    private const uint WsChild = 0x40000000;
    private const uint WsVisible = 0x10000000;
    private const uint WmPaint = 0x000F;
    private const uint WmLButtonUp = 0x0202;
    private static readonly NativeWndProc WindowProcedure = WindowProc;
    private static bool _registered;
    private IntPtr _hwnd;

    protected override HandleRef BuildWindowCore(HandleRef hwndParent)
    {
        EnsureClass();
        _hwnd = CreateWindowEx(0, ClassName, "", WsChild | WsVisible,
            0, 0, Math.Max(1, (int)ActualWidth), Math.Max(1, (int)ActualHeight),
            hwndParent.Handle, IntPtr.Zero, GetModuleHandle(null), IntPtr.Zero);
        return new HandleRef(this, _hwnd);
    }

    protected override void DestroyWindowCore(HandleRef hwnd) => DestroyWindow(hwnd.Handle);

    private static void EnsureClass()
    {
        if (_registered) return;
        var cls = new WndClass
        {
            style = 0,
            lpfnWndProc = WindowProcedure,
            hInstance = GetModuleHandle(null),
            hCursor = LoadCursor(IntPtr.Zero, new IntPtr(32512)),
            lpszClassName = ClassName
        };
        _registered = RegisterClass(ref cls) != 0;
        if (!_registered && Marshal.GetLastWin32Error() != 1410)
            throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
        _registered = true;
    }

    private static IntPtr WindowProc(IntPtr hwnd, uint message, IntPtr wParam, IntPtr lParam)
    {
        if (message == WmLButtonUp)
        {
            SetWindowLongPtr(hwnd, -21, GetWindowLongPtr(hwnd, -21) == new IntPtr(1) ? IntPtr.Zero : new IntPtr(1));
            InvalidateRect(hwnd, IntPtr.Zero, true);
            return IntPtr.Zero;
        }
        if (message == WmPaint)
        {
            BeginPaint(hwnd, out var paint);
            GetClientRect(hwnd, out var rect);
            bool active = GetWindowLongPtr(hwnd, -21) == new IntPtr(1);
            var background = CreateSolidBrush(0x00F8F4EE);
            FillRect(paint.hdc, ref rect, background);
            DeleteObject(background);
            var button = new Rect { Left = 34, Top = 58, Right = Math.Max(160, rect.Right - 34), Bottom = 122 };
            var brush = CreateSolidBrush((uint)(active ? 0x0075B238 : 0x00E8675C));
            FillRect(paint.hdc, ref button, brush);
            DeleteObject(brush);
            SetBkMode(paint.hdc, 1);
            SetTextColor(paint.hdc, 0x00FFFFFFu);
            var label = active ? "Win32 target active" : "Click Win32 target";
            DrawText(paint.hdc, label, label.Length, ref button, 0x00000025);
            EndPaint(hwnd, ref paint);
            return IntPtr.Zero;
        }
        return DefWindowProc(hwnd, message, wParam, lParam);
    }

    private delegate IntPtr NativeWndProc(IntPtr hwnd, uint message, IntPtr wParam, IntPtr lParam);

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct WndClass
    {
        public uint style;
        [MarshalAs(UnmanagedType.FunctionPtr)] public NativeWndProc lpfnWndProc;
        public int cbClsExtra;
        public int cbWndExtra;
        public IntPtr hInstance;
        public IntPtr hIcon;
        public IntPtr hCursor;
        public IntPtr hbrBackground;
        public string? lpszMenuName;
        public string lpszClassName;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct PaintStruct
    {
        public IntPtr hdc;
        public bool fErase;
        public Rect rcPaint;
        public bool fRestore;
        public bool fIncUpdate;
        [MarshalAs(UnmanagedType.ByValArray, SizeConst = 32)] public byte[] rgbReserved;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct Rect { public int Left, Top, Right, Bottom; }

    [DllImport("user32.dll", CharSet = CharSet.Unicode, SetLastError = true)] private static extern ushort RegisterClass(ref WndClass wndClass);
    [DllImport("user32.dll", CharSet = CharSet.Unicode, SetLastError = true)] private static extern IntPtr CreateWindowEx(uint exStyle, string className, string name, uint style, int x, int y, int width, int height, IntPtr parent, IntPtr menu, IntPtr instance, IntPtr parameter);
    [DllImport("user32.dll")] private static extern bool DestroyWindow(IntPtr hwnd);
    [DllImport("user32.dll")] private static extern IntPtr DefWindowProc(IntPtr hwnd, uint message, IntPtr wParam, IntPtr lParam);
    [DllImport("user32.dll")] private static extern bool InvalidateRect(IntPtr hwnd, IntPtr rect, bool erase);
    [DllImport("user32.dll")] private static extern IntPtr BeginPaint(IntPtr hwnd, out PaintStruct paint);
    [DllImport("user32.dll")] private static extern bool EndPaint(IntPtr hwnd, ref PaintStruct paint);
    [DllImport("user32.dll")] private static extern bool GetClientRect(IntPtr hwnd, out Rect rect);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] private static extern int DrawText(IntPtr hdc, string text, int count, ref Rect rect, uint format);
    [DllImport("user32.dll")] private static extern int FillRect(IntPtr hdc, ref Rect rect, IntPtr brush);
    [DllImport("user32.dll")] private static extern int SetBkMode(IntPtr hdc, int mode);
    [DllImport("gdi32.dll")] private static extern IntPtr CreateSolidBrush(uint color);
    [DllImport("gdi32.dll")] private static extern bool DeleteObject(IntPtr obj);
    [DllImport("gdi32.dll")] private static extern uint SetTextColor(IntPtr hdc, uint color);
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode)] private static extern IntPtr GetModuleHandle(string? module);
    [DllImport("user32.dll")] private static extern IntPtr LoadCursor(IntPtr instance, IntPtr cursor);
    [DllImport("user32.dll", EntryPoint = "GetWindowLongPtrW")] private static extern IntPtr GetWindowLongPtr(IntPtr hwnd, int index);
    [DllImport("user32.dll", EntryPoint = "SetWindowLongPtrW")] private static extern IntPtr SetWindowLongPtr(IntPtr hwnd, int index, IntPtr value);
}
