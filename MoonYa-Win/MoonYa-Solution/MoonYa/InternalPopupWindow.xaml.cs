using System;
using System.IO;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Interop;
using CefSharp;
using CefSharp.Wpf;

namespace MoonYa
{
    /// <summary>
    /// 内部链接弹窗：用于 C# 端 LifeSpanHandler 拦截到的"内部 URL"
    /// （如 community/、MoonYa-main/ 等）以"新窗口"形式打开。
    /// - 不走系统浏览器
    /// - 不替换主窗口
    /// - 关闭时释放 ChromiumWebBrowser
    /// </summary>
    public partial class InternalPopupWindow : Window
    {
        public ChromiumWebBrowser WebView => PopupWebView;

        // ── P/Invoke for dark title bar ──────────────────
        [DllImport("dwmapi.dll", PreserveSig = true)]
        private static extern int DwmSetWindowAttribute(
            IntPtr hwnd, int attr, ref int attrValue, int attrSize);

        private const int DWMWA_USE_IMMERSIVE_DARK_MODE = 20;
        private const int DWMWA_CAPTION_COLOR = 35;
        private const int DWMWA_BORDER_COLOR = 34;

        public InternalPopupWindow(string url)
        {
            InitializeComponent();

            // 阻止 popup 自身内的 window.open 再次弹出新系统浏览器，
            // 改为用同样的 InternalPopupWindow 显示。
            PopupWebView.LifeSpanHandler = new MoonYaLifeSpanHandler(PopupWebView);

            // 等 browser 初始化完成后设置 OSR 帧率
            PopupWebView.IsBrowserInitializedChanged += (s, e) =>
            {
                if (PopupWebView.IsBrowserInitialized)
                {
                    try
                    {
                        var host = PopupWebView.GetBrowser()?.GetHost();
                        if (host != null) host.WindowlessFrameRate = 60;
                    }
                    catch { }
                }
            };

            Loaded += (s, e) =>
            {
                ApplyDarkTitleBar();
                if (!string.IsNullOrEmpty(url) && url != "about:blank")
                {
                    try { PopupWebView.Address = url; }
                    catch (Exception ex) { System.Diagnostics.Debug.WriteLine("[Popup] WebView 加载失败: " + ex.Message); }
                }
            };

            Closed += InternalPopupWindow_Closed;
        }

        private void InternalPopupWindow_Closed(object? sender, EventArgs e)
        {
            try
            {
                // 主动释放 WebView，避免 Chromium 进程持续持有页面资源
                if (PopupWebView != null)
                {
                    try { PopupWebView.Address = "about:blank"; } catch { }
                    PopupWebView.Dispose();
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine("[Popup] 释放 WebView 失败: " + ex.Message);
            }
        }

        private void ApplyDarkTitleBar()
        {
            try
            {
                var hwnd = new WindowInteropHelper(this).Handle;
                int useDark = 1;
                DwmSetWindowAttribute(hwnd, DWMWA_USE_IMMERSIVE_DARK_MODE, ref useDark, sizeof(int));
                int captionColor = 0x00FAF7F7; // BGR
                DwmSetWindowAttribute(hwnd, DWMWA_CAPTION_COLOR, ref captionColor, sizeof(int));
                int borderColor = 0x00E0E0E0;
                DwmSetWindowAttribute(hwnd, DWMWA_BORDER_COLOR, ref borderColor, sizeof(int));
            }
            catch { }
        }
    }
}
