using System;
using System.Diagnostics;
using System.Windows;
using CefSharp;
using CefSharp.Wpf;

namespace MoonYa
{

    public class MoonYaLifeSpanHandler : ILifeSpanHandler
    {
        private readonly ChromiumWebBrowser _webView;

        public MoonYaLifeSpanHandler(ChromiumWebBrowser webView)
        {
            _webView = webView;
        }

        public bool OnBeforePopup(
            IWebBrowser chromiumWebBrowser,
            IBrowser browser,
            IFrame frame,
            string targetUrl,
            string targetFrameName,
            WindowOpenDisposition targetDisposition,
            bool userGesture,
            IPopupFeatures popupFeatures,
            IWindowInfo windowInfo,
            IBrowserSettings browserSettings,
            ref bool noJavascriptAccess,
            out IWebBrowser? newBrowser)
        {
            newBrowser = null;

            // 空地址 / about:blank 直接取消
            if (string.IsNullOrWhiteSpace(targetUrl) || targetUrl == "about:blank")
                return true;

            // 当前页内的锚点跳转（#xxx）交给浏览器自身处理
            if (targetUrl.StartsWith("#", StringComparison.Ordinal))
                return false;

            // ★ 需要新窗口弹出的内部 URL（如办公室"单独弹出"按钮 office/index.php）：
            //   不在当前 WebView 中加载（避免替换启动器内容），
            //   也不用系统浏览器打开，而是使用 InternalPopupWindow 新开一个窗口。
            //   该路径在 STANDALONE 模式下会隐藏"单独弹出"按钮，因此仅在启动器中触发。
            if (IsPopoutUrl(targetUrl))
            {
                var fullUrl = ResolveFullUrl(targetUrl);
                System.Diagnostics.Debug.WriteLine($"[LifeSpan] 弹出新窗口: {fullUrl}");
                try
                {
                    _webView.Dispatcher.BeginInvoke(new Action(() =>
                    {
                        try { OpenInNewWindow(fullUrl); }
                        catch (Exception ex) { System.Diagnostics.Debug.WriteLine($"[LifeSpan] 新窗口弹出失败: {ex.Message}"); }
                    }));
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"[LifeSpan] 弹出新窗口失败: {ex.Message}");
                }
                return true; // 取消原生弹窗
            }

            // ★ 内部 URL（同源）改为在当前 WebView 中加载，避免社区等内部链接
            //   误用系统浏览器打开。判断方式：
            //     1) 已知内部路径前缀（社区等 MoonYa 后端的子页面）→ 内部
            //     2) 相对路径（/开头、或直接是文件路径）→ 内部
            //     3) 绝对 URL 但与 WebView 当前 origin 同源 → 内部
            //     4) 否则 → 外部链接，用系统浏览器打开
            if (IsInternalUrl(targetUrl))
            {
                try
                {
                    System.Diagnostics.Debug.WriteLine($"[LifeSpan] 内部 URL 在 WebView 内加载: {targetUrl}");
                    _webView.Dispatcher.BeginInvoke(new Action(() =>
                    {
                        try { _webView.Address = targetUrl; }
                        catch (Exception ex) { System.Diagnostics.Debug.WriteLine($"[LifeSpan] WebView 导航失败: {ex.Message}"); }
                    }));
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"[LifeSpan] 内部 URL 导航失败: {ex.Message}");
                }
                return true; // 取消原生弹窗
            }

            try
            {
                // 外链 / 下载链接：用系统默认浏览器在新窗口中打开
                Process.Start(new ProcessStartInfo(targetUrl) { UseShellExecute = true });
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"[LifeSpan] 用系统浏览器打开新窗口失败: {ex.Message}");
            }

            return true; // 取消原生弹窗
        }

        public void OnAfterCreated(IWebBrowser chromiumWebBrowser, IBrowser browser)
        {
        }

        public bool DoClose(IWebBrowser chromiumWebBrowser, IBrowser browser)
        {
            return false;
        }

        public void OnBeforeClose(IWebBrowser chromiumWebBrowser, IBrowser browser)
        {
        }

        /// <summary>
        /// 已知属于 MoonYa 后端的内部路径前缀。任何以这些前缀开头的
        /// URL 都会被强制在当前 WebView 中加载（不通过系统浏览器打开）。
        /// 修复：launcher_config.json 的 backend_url 与社区页面可能不同源
        /// 本地桥地址由运行时服务清单解析，
        /// 仅靠"同源判断"会被误判为外链。
        /// </summary>
        private static readonly string[] _internalPathPrefixes =
        {
            "community/",
            "community/index.php",
            "MoonYa-main/",
            "script/MoonYa-index/",
        };

        /// <summary>
        /// 需要新窗口弹出的内部 URL 路径前缀。
        /// 匹配这些路径的 URL 会使用 InternalPopupWindow 新开一个窗口，
        /// 而不是在当前 WebView 中加载或用系统浏览器打开。
        /// 典型场景：办公室"单独弹出"按钮（office/index.php）。
        /// </summary>
        private static readonly string[] _popoutPathPrefixes =
        {
            "office/index.php",
            "office/",
        };

        /// <summary>
        /// 判断 targetUrl 是否属于 MoonYa 后端的内部 URL。
        /// 规则（按优先级）：
        ///   1) 命中已知内部路径前缀（如 community/）→ 内部
        ///   2) 相对路径（/开头、或直接是文件路径）→ 内部
        ///   3) 绝对 URL：与 WebView 当前 origin 同源 → 内部
        ///   4) 否则 → 外部链接，用系统浏览器打开
        /// </summary>
        private bool IsInternalUrl(string targetUrl)
        {
            if (string.IsNullOrEmpty(targetUrl)) return false;

            // 1) 已知内部路径前缀（最高优先级，避免被 origin 误判）
            foreach (var prefix in _internalPathPrefixes)
            {
                if (targetUrl.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
                    return true;
            }

            // 2) 相对路径
            if (targetUrl.StartsWith("/") || targetUrl.StartsWith("./") || targetUrl.StartsWith("../"))
                return true;

            // 没有协议也不是相对路径（极少见）→ 当作内部处理
            if (!targetUrl.Contains("://")) return true;

            // 3) 绝对 URL：与当前 WebView origin 比对
            string currentOrigin = GetCurrentOrigin();
            if (string.IsNullOrEmpty(currentOrigin)) return false;

            try
            {
                var targetUri = new Uri(targetUrl);
                var currentUri = new Uri(currentOrigin);
                return string.Equals(targetUri.Scheme, currentUri.Scheme, StringComparison.OrdinalIgnoreCase)
                    && string.Equals(targetUri.Host, currentUri.Host, StringComparison.OrdinalIgnoreCase)
                    && targetUri.Port == currentUri.Port;
            }
            catch
            {
                return false;
            }
        }

        private string GetCurrentOrigin()
        {
            try
            {
                var addr = _webView?.Address;
                if (string.IsNullOrEmpty(addr)) return "";
                var uri = new Uri(addr);
                return uri.Scheme + "://" + uri.Host + ":" + uri.Port;
            }
            catch
            {
                return "";
            }
        }

        /// <summary>
        /// 判断 targetUrl 是否属于需要"新窗口弹出"的内部 URL。
        /// 匹配规则：提取 URL 的 path 部分，检查是否以 _popoutPathPrefixes 中的任一前缀开头。
        /// 兼容相对路径（如 office/index.php）与绝对 URL（如 http://host/office/index.php）。
        /// </summary>
        private bool IsPopoutUrl(string targetUrl)
        {
            if (string.IsNullOrEmpty(targetUrl)) return false;

            string path = targetUrl;
            try
            {
                if (targetUrl.Contains("://"))
                {
                    var uri = new Uri(targetUrl);
                    path = uri.AbsolutePath;
                }
            }
            catch
            {
                // Uri 解析失败则用原始字符串匹配
            }

            // 统一去掉前导 '/'，便于前缀匹配（兼容 /office/index.php 与 office/index.php）
            path = path.TrimStart('/');

            foreach (var prefix in _popoutPathPrefixes)
            {
                var p = prefix.TrimStart('/');
                if (path.StartsWith(p, StringComparison.OrdinalIgnoreCase))
                    return true;
            }
            return false;
        }

        /// <summary>
        /// 将相对 URL 解析为完整 URL（基于当前 WebView 的 origin）。
        /// 如果 targetUrl 已经是完整 URL，则原样返回。
        /// 用于把 window.open('office/index.php') 这种相对路径补全为
        /// http://host:port/office/index.php，以便 InternalPopupWindow 能正确加载。
        /// </summary>
        private string ResolveFullUrl(string targetUrl)
        {
            if (string.IsNullOrEmpty(targetUrl)) return targetUrl;
            if (targetUrl.Contains("://")) return targetUrl;

            string origin = GetCurrentOrigin();
            if (string.IsNullOrEmpty(origin)) return targetUrl;

            try
            {
                if (targetUrl.StartsWith("/"))
                    return origin + targetUrl;
                return origin + "/" + targetUrl;
            }
            catch
            {
                return targetUrl;
            }
        }

        /// <summary>
        /// 在新窗口中打开 targetUrl。
        /// 如果 _webView 自身在主窗口中，Owner 为主窗口；
        /// 如果 _webView 本身在 InternalPopupWindow 中，Owner 为该 popup 自身。
        /// </summary>
        private void OpenInNewWindow(string targetUrl)
        {
            var owner = Window.GetWindow(_webView);
            var popup = new InternalPopupWindow(targetUrl);
            if (owner != null)
            {
                popup.Owner = owner;
            }
            popup.Show();
        }
    }
}
