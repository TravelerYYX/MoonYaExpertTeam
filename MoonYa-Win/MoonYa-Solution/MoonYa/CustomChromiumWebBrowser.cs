using System.Windows;
using System.Windows.Input;
using CefSharp;
using CefSharp.Wpf;

namespace MoonYa
{
    /// <summary>
    /// 继承 ChromiumWebBrowser，覆盖 OnLostMouseCapture 以阻止
    /// SendCaptureLostEvent() 在 mouseup 后清空文字选区。
    /// 根因：CefSharp.Wpf 的 OnMouseUp 在发送 mouseup 给 CEF 后调用
    /// ReleaseMouseCapture()，触发 OnLostMouseCapture → SendCaptureLostEvent()，
    /// CEF 收到捕获丢失信号后会清空 Blink 选区。
    /// </summary>
    public class CustomChromiumWebBrowser : ChromiumWebBrowser
    {
        public CustomChromiumWebBrowser()
        {
            // ★ 在 OSR（无窗口渲染）模式下，CefSharp.Wpf 默认不会把 WPF 的 IME 合成事件
            //   转发给 CEF。这里通过 TextCompositionManager 的 preview 事件监听整个
            //   合成生命周期：Start/Update/End，并对应调用 ImeSetComposition /
            //   ImeCommitText，使中文/日文/韩文输入法能在 textarea/input 中正常输入。
            TextCompositionManager.AddPreviewTextInputStartHandler(this, OnPreviewTextInputStart);
            TextCompositionManager.AddPreviewTextInputUpdateHandler(this, OnPreviewTextInputUpdate);
            TextCompositionManager.AddPreviewTextInputHandler(this, OnPreviewTextInput);
        }

        protected override void OnLostMouseCapture(MouseEventArgs e)
        {
            e.Handled = true;
            base.OnLostMouseCapture(e);
        }

        /// <summary>
        /// 获取浏览器宿主，用于 IME 操作。
        /// </summary>
        private IBrowserHost? GetBrowserHost()
        {
            return GetBrowser()?.GetHost();
        }

        /// <summary>
        /// IME 合成开始/更新：把 WPF 的 composition text 转发给 CEF。
        /// </summary>
        private void OnPreviewTextInputStart(object sender, TextCompositionEventArgs e)
        {
            var host = GetBrowserHost();
            if (host == null) return;

            var text = e.TextComposition?.CompositionText ?? e.Text;
            if (string.IsNullOrEmpty(text)) return;

            System.Diagnostics.Debug.WriteLine($"[IME] StartComposition: '{text}'");
            host.ImeSetComposition(text, null, null, null);
            e.Handled = true;
        }

        /// <summary>
        /// IME 合成更新：候选词变化时同步到 CEF。
        /// </summary>
        private void OnPreviewTextInputUpdate(object sender, TextCompositionEventArgs e)
        {
            var host = GetBrowserHost();
            if (host == null) return;

            var text = e.TextComposition?.CompositionText ?? e.Text;
            if (string.IsNullOrEmpty(text)) return;

            System.Diagnostics.Debug.WriteLine($"[IME] UpdateComposition: '{text}'");
            host.ImeSetComposition(text, null, null, null);
            e.Handled = true;
        }

        /// <summary>
        /// IME 合成结束/提交：把最终文本通过 ImeCommitText 写入 CEF。
        /// </summary>
        private void OnPreviewTextInput(object sender, TextCompositionEventArgs e)
        {
            var host = GetBrowserHost();
            if (host == null) return;

            var text = e.Text;
            if (string.IsNullOrEmpty(text)) return;

            System.Diagnostics.Debug.WriteLine($"[IME] CommitText: '{text}'");
            host.ImeCommitText(text, null, 0);
            e.Handled = true;
        }
    }
}
