// BrowserAutomationService — 基于 PuppeteerSharp 的浏览器操控引擎
// 独立于 CU 组件（不调用 ComputerUseService / UiAutomationService / Graphics.CopyFromScreen）
// 失败直接抛异常，不降级到坐标点击 / 键盘 / CU 兜底；不自动重试

using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using PuppeteerSharp;
using PuppeteerSharp.Input;

namespace MoonYa.Services
{
    /// <summary>浏览器自动化配置（由调用方从 launcher_config.json 的 browser_automation 段读取后注入）</summary>
    public class BrowserAutomationConfig
    {
        public string LoopbackHost { get; set; } = string.Empty;
        public int Port { get; set; }
        public System.Collections.Generic.List<string> TrustedDomains { get; set; } = new();
        public System.Collections.Generic.List<string> BlockedDomains { get; set; } = new();
        public string ChromiumExecutablePath { get; set; } = string.Empty;
        public System.Collections.Generic.List<string> ChromiumCandidatePaths { get; set; } = new();
        public bool Headless { get; set; } = false;
        public bool AutoDownloadChromium { get; set; }
        public int DefaultTimeoutMs { get; set; }
        public int ElementTimeoutMs { get; set; }
        public int ViewportWidth { get; set; }
        public int ViewportHeight { get; set; }
        public int ClickInitialDelayMs { get; set; }
        public int NetworkIdleTimeoutMs { get; set; }
        public int NetworkIdleTimeMs { get; set; }
        public int DomStableMaxWaitMs { get; set; }
        public int DomStableThresholdMs { get; set; }
        public int DomStablePollMs { get; set; }
        public int MutationStableMs { get; set; }
        public int MutationTimeoutMs { get; set; }
        public int FrameStableMs { get; set; }
        public int FrameStableMaxWaitMs { get; set; }
        public int FrameRetryCount { get; set; }
        public int PerDocumentElementLimit { get; set; }
        public int MaxReturnedElements { get; set; }
        public int PageTextLimit { get; set; }
        public int DefaultWaitMs { get; set; }
        public int DefaultScrollAmount { get; set; }
        public int RelayTimeoutMs { get; set; }
        public int ShutdownTimeoutMs { get; set; }
        public int CleanupDelayMs { get; set; }
        public string DownloadDirectory { get; set; } = string.Empty;
        public string DiagnosticsFile { get; set; } = string.Empty;
    }

    public class BrowserAutomationService : IDisposable
    {
        private readonly BrowserAutomationConfig _config;
        private IBrowser? _browser;
        private IPage? _page;
        private bool _disposed;
        private long _pageVersion;
        private string _sessionId = string.Empty;
        private readonly Dictionary<string, (long Version, string Selector, string FrameUrl, string FrameName, string RuntimeId)> _elementRegistry = new(StringComparer.Ordinal);
        private int _launchedBrowserProcessId;

        public bool IsRunning => _browser != null && !_browser.IsClosed && _page != null;
        public string CurrentUrl => _page?.Url ?? string.Empty;
        public string SessionId => _sessionId;
        public long PageVersion => _pageVersion;
        public string CurrentTabId => IsRunning ? _page!.Target.TargetId : string.Empty;
        public int DefaultWaitMs => _config.DefaultWaitMs;
        public int DefaultScrollAmount => _config.DefaultScrollAmount;

        // ★ 跨 frame 元素采集脚本：在主 document 和每个 iframe 内执行
        //   注意：不再遍历 iframe.contentDocument（同源限制），改为由 C# 端调用 page.Frames 分别执行
        private static readonly string CollectElementsJsTemplate = @"() => {
            const elements = [];
            const interactiveTags = new Set(['input', 'button', 'a', 'select', 'textarea', 'summary', 'option']);
            const interactiveRoles = new Set(['button', 'link', 'checkbox', 'radio', 'switch', 'tab', 'menuitem', 'option', 'combobox', 'textbox', 'slider', 'spinbutton', 'treeitem']);
            // ★ 判断元素是否可见
            function isVisible(el) {
                if (!el) return false;
                const rect = el.getBoundingClientRect();
                if (rect.width === 0 || rect.height === 0) return false;
                const style = (el.ownerDocument && el.ownerDocument.defaultView)
                    ? el.ownerDocument.defaultView.getComputedStyle(el)
                    : window.getComputedStyle(el);
                return !(style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0');
            }

            function isInteractive(el) {
                if (!el || !el.tagName) return false;
                const tag = el.tagName.toLowerCase();
                if (interactiveTags.has(tag)) return tag !== 'input' || (el.type || '').toLowerCase() !== 'hidden';
                if (tag === 'label' && el.htmlFor) return true;
                const role = (el.getAttribute('role') || '').toLowerCase();
                if (interactiveRoles.has(role)) return true;
                if (el.isContentEditable || el.getAttribute('contenteditable') === 'true') return true;
                const tabindex = el.getAttribute('tabindex');
                if (tabindex !== null && Number(tabindex) >= 0) return true;
                if (typeof el.onclick === 'function' || el.hasAttribute('onclick')) return true;
                try { return window.getComputedStyle(el).cursor === 'pointer'; } catch (e) { return false; }
            }

            function isModalContainer(el) {
                if (!el || !el.matches) return false;
                const tag = (el.tagName || '').toLowerCase();
                if (tag === 'dialog' && (el.open || el.hasAttribute('open'))) return true;
                if ((el.getAttribute('role') || '').toLowerCase() === 'dialog') return true;
                if ((el.getAttribute('aria-modal') || '').toLowerCase() === 'true') return true;
                try { if (el.matches(':modal') || el.matches(':popover-open')) return true; } catch (e) { }
                return false;
            }

            // Determine modal ownership from platform semantics rather than vendor classes.
            function isInModal(el, doc) {
                let node = el;
                while (node && node !== doc.body) {
                    if (isModalContainer(node)) return true;
                    node = node.parentElement;
                }
                return false;
            }

            // ★ 过滤框架生成的动态 ID
            function isStableId(id) {
                if (!id) return false;
                if (/^\d+$/.test(id)) return false;
                if (id.length < 2) return false;
                if (id.length > 96) return false;
                if (/^[a-f0-9-]{24,}$/i.test(id)) return false;
                return true;
            }

            // ★ 生成 CSS 选择器：优先稳定可靠的属性
            function buildSelector(el) {
                const tagLower = el.tagName.toLowerCase();
                // 表单元素优先 name 属性
                if ((tagLower === 'input' || tagLower === 'textarea' || tagLower === 'select') && el.name) {
                    return tagLower + '[name=""' + CSS.escape(el.name) + '""]';                }
                if (isStableId(el.id)) {
                    return '#' + CSS.escape(el.id);
                }
                if (el.name) {
                    return tagLower + '[name=""' + CSS.escape(el.name) + '""]';                }
                if (el.getAttribute('data-testid')) {
                    return '[data-testid=""' + CSS.escape(el.getAttribute('data-testid')) + '""]';                }
                if (el.placeholder && (tagLower === 'input' || tagLower === 'textarea')) {
                    return tagLower + '[placeholder=""' + CSS.escape(el.placeholder) + '""]';                }
                if (el.className && typeof el.className === 'string' && el.className.trim()) {
                    const classes = el.className.trim().split(/\s+/);
                    const meaningful = classes.filter(c =>
                        c.length >= 2 &&
                        c.length <= 64 &&
                        !/^\d+$/.test(c) &&
                        !/^[a-f0-9_-]{20,}$/i.test(c)
                    ).slice(0, 2);
                    if (meaningful.length > 0) {
                        return tagLower + meaningful.map(c => '.' + CSS.escape(c)).join('');
                    }
                }
                // ★ 兜底：用父元素+标签路径
                const parent = el.parentNode;
                if (parent) {
                    const siblings = Array.from(parent.children).filter(s => s.tagName === el.tagName);
                    if (siblings.length > 1) {
                        return tagLower + ':nth-of-type(' + (siblings.indexOf(el) + 1) + ')';
                    }
                }
                return tagLower;
            }

            // ★ 唯一性验证：生成 selector 后若 querySelectorAll().length > 1，
            //   逐级添加父元素路径 + :nth-of-type() 直到唯一（最多 5 级，避免选择器过长）
                // This prevents a non-unique selector from clicking the first sibling by accident.
            function makeUnique(el, baseSelector, doc) {
                try {
                    if (doc.querySelectorAll(baseSelector).length === 1) return baseSelector;
                } catch (e) { return baseSelector; }

                // First distinguish same-tag siblings, then add ancestor context.
                let effectiveBase = baseSelector;
                const selfParent = el.parentElement;
                if (selfParent) {
                    const selfSiblings = Array.from(selfParent.children).filter(s => s.tagName === el.tagName);
                    if (selfSiblings.length > 1) {
                        const selfIdx = selfSiblings.indexOf(el);
                        if (selfIdx >= 0) {
                            effectiveBase = baseSelector + ':nth-of-type(' + (selfIdx + 1) + ')';
                            try {
                                if (doc.querySelectorAll(effectiveBase).length === 1) return effectiveBase;
                            } catch (e) { /* nth-of-type 不适用，回退到原 base 继续加祖先 */ }
                        }
                    }
                }

                const parts = [effectiveBase];
                let node = el.parentElement;
                let depth = 0;
                const maxDepth = 5;

                while (node && depth < maxDepth) {
                    depth++;
                    const parentTag = node.tagName ? node.tagName.toLowerCase() : '';
                    if (!parentTag || parentTag === 'html' || parentTag === 'body') break;

                    // 父元素在同标签兄弟中的位置
                    const grandParent = node.parentElement;
                    let parentPart;
                    if (grandParent) {
                        const parentSiblings = Array.from(grandParent.children).filter(s => s.tagName === node.tagName);
                        const parentIdx = parentSiblings.indexOf(node);
                        if (parentSiblings.length > 1 && parentIdx >= 0) {
                            parentPart = parentTag + ':nth-of-type(' + (parentIdx + 1) + ')';
                        } else {
                            parentPart = parentTag;
                        }
                    } else {
                        parentPart = parentTag;
                    }

                    parts.unshift(parentPart);
                    const candidate = parts.join(' > ');
                    try {
                        if (doc.querySelectorAll(candidate).length === 1) return candidate;
                    } catch (e) { break; }
                    node = node.parentElement;
                }
                // 仍未唯一，返回最深路径（比原 base 更精确）
                return parts.join(' > ');
            }

            function collectFromDoc(doc) {
                const nodes = Array.from(doc.querySelectorAll('*')).filter(isInteractive);
                // ★ 两遍收集：先弹窗内元素，后弹窗外元素
                const modalEls = [];
                const otherEls = [];
                nodes.forEach((el) => {
                    if (!isVisible(el)) return;
                    if (isInModal(el, doc)) {
                        modalEls.push(el);
                    } else {
                        otherEls.push(el);
                    }
                });

                // 处理单个元素
                function processEl(el) {
                    if (elements.length >= __ELEMENT_LIMIT__) return;
                    const rect = el.getBoundingClientRect();
                    const tagLower = el.tagName.toLowerCase();
                    let runtimeId = el.getAttribute('data-moonya-runtime-id');
                    if (!runtimeId) {
                        window.__moonyaRuntimeElementCounter = (window.__moonyaRuntimeElementCounter || 0) + 1;
                        runtimeId = (globalThis.crypto && typeof globalThis.crypto.randomUUID === 'function')
                            ? globalThis.crypto.randomUUID()
                            : [Date.now(), Math.random(), window.__moonyaRuntimeElementCounter].join('-');
                        el.setAttribute('data-moonya-runtime-id', runtimeId);
                    }
                    let cssSelector = buildSelector(el);

                    // ★ 对 <a> 标签，用 pathname 关键词增强选择器
                    if (tagLower === 'a' && el.href && el.href !== '#' && !el.href.startsWith('javascript:')) {
                        try {
                            var hrefPath = new URL(el.href).pathname;
                            var seg = hrefPath.split('/').filter(function(s) { return s.length >= 2; })[0];
                            if (seg) {
                                cssSelector += '[href*=""' + seg + '""]';
                            }
                        } catch (e) { }
                    }

                    // Verify uniqueness in the current document before exposing the selector.
                    cssSelector = makeUnique(el, cssSelector, doc);

                    // ★ 提取元素文本：优先 innerText，回退到 value/placeholder/aria-label/title/关联 label
                    let text = '';
                    if (el.innerText && el.innerText.trim()) {
                        text = el.innerText.trim();
                    } else if (el.textContent && el.textContent.trim()) {
                        text = el.textContent.trim();
                    } else {
                        text = (el.value || el.placeholder || el.getAttribute('aria-label') || el.title || '').trim();
                    }
                    // 如果是输入框且没有文本，尝试找关联 label
                    if (!text && (tagLower === 'input' || tagLower === 'textarea' || tagLower === 'select')) {
                        // aria-labelledby
                        const labelledBy = el.getAttribute('aria-labelledby');
                        if (labelledBy) {
                            const labelEl = doc.getElementById(labelledBy);
                            if (labelEl) text = (labelEl.innerText || labelEl.textContent || '').trim();
                        }
                        // 找 for=id 的 label
                        if (!text && el.id) {
                            const labels = doc.querySelectorAll('label[for=""' + CSS.escape(el.id) + '""]');
                            if (labels.length > 0) text = (labels[0].innerText || labels[0].textContent || '').trim();
                        }
                        // 找相邻 label
                        if (!text) {
                            const prevLabel = el.parentElement && el.parentElement.tagName.toLowerCase() === 'label' ? el.parentElement : null;
                            if (prevLabel) text = (prevLabel.innerText || prevLabel.textContent || '').trim();
                        }
                    }

                    elements.push({
                        tag: tagLower,
                        type: el.type || '',
                        role: el.getAttribute('role') || '',
                        accessible_name: el.getAttribute('aria-label') || text,
                        runtime_id: runtimeId,
                        css_selector: cssSelector,
                        text: text.slice(0, 120),
                        name: el.name || '',
                        id: el.id || '',
                        placeholder: el.placeholder || '',
                        href: el.href || '',
                        value: (el.value || '').toString().slice(0, 80),
                        position: { x: Math.round(rect.x), y: Math.round(rect.y), w: Math.round(rect.width), h: Math.round(rect.height) },
                        disabled: el.disabled || false,
                        checked: typeof el.checked === 'boolean' ? el.checked : null,
                        selected: typeof el.selected === 'boolean' ? el.selected : null,
                        focused: doc.activeElement === el,
                        visible: true,
                        in_modal: isInModal(el, doc),
                        in_iframe: false
                    });
                }
                // 先处理弹窗内元素（确保不被数量限制截断）
                modalEls.forEach(processEl);
                // 再处理弹窗外元素
                otherEls.forEach(processEl);
            }

            // 主文档
            collectFromDoc(document);

            // 穿透 shadow DOM
            try {
                function walkShadow(node) {
                    if (!node || !node.querySelectorAll) return;
                    node.querySelectorAll('*').forEach((el) => {
                        if (el.shadowRoot) {
                            try {
                                collectFromDoc(el.shadowRoot);
                                walkShadow(el.shadowRoot);
                            } catch (e) { }
                        }
                    });
                }
                walkShadow(document);
            } catch (e) { }

            // ★ 兜底：从主文档 JS 直接访问同源 iframe 的 contentDocument
            //    C# 端虽有 _page.Frames 遍历，但某些场景下 iframe 可能不在 Frames 集合中
            //    或 frames 遍历时 iframe 内容尚未就绪。此处作为兜底，确保同源 iframe 内元素必被捕获
            try {
                var iframes = document.querySelectorAll('iframe, frame');
                iframes.forEach(function(ifr) {
                    try {
                        var ifrDoc = ifr.contentDocument || (ifr.contentWindow ? ifr.contentWindow.document : null);
                        if (ifrDoc && ifrDoc !== document && ifrDoc.body && ifrDoc.body.querySelectorAll) {
                            collectFromDoc(ifrDoc);
                        }
                    } catch (e) { }
                });
            } catch (e) { }

            // 提取全页面可见文本
            const pageText = (function() {
                try {
                    const body = document.body;
                    if (!body) return '';
                    const results = [];
                    const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT, {
                        acceptNode: function(node) {
                            if (!node.parentElement) return NodeFilter.FILTER_REJECT;
                            const tag = node.parentElement.tagName || '';
                            if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT' || tag === 'SVG' || tag === 'IFRAME') return NodeFilter.FILTER_REJECT;
                            const style = window.getComputedStyle(node.parentElement);
                            if (style.display === 'none' || style.visibility === 'hidden') return NodeFilter.FILTER_REJECT;
                            const text = node.textContent.trim();
                            if (!text) return NodeFilter.FILTER_REJECT;
                            return NodeFilter.FILTER_ACCEPT;
                        }
                    }, false);
                    let node;
                    while ((node = walker.nextNode())) {
                        const t = node.textContent.trim();
                        if (t) results.push(t);
                    }
                    const unique = [];
                    for (const r of results) {
                        if (unique.length === 0 || r !== unique[unique.length - 1]) unique.push(r);
                    }
                    return unique.join(' ').replace(/\s{2,}/g, ' ').slice(0, __PAGE_TEXT_LIMIT__);
                } catch (e) { return ''; }
            })();

            return JSON.stringify({elements: elements, page_text: pageText});
        }";

        private readonly string _collectElementsJs;

        // Standards-based visible interactive element check used for frame readiness.
        private static readonly string VisibleInteractiveCheckJs = @"() => {
            const tags = new Set(['input','button','textarea','select','a','summary','option']);
            const roles = new Set(['button','link','checkbox','radio','switch','tab','menuitem','option','combobox','textbox','slider','spinbutton','treeitem']);
            return Array.from(document.querySelectorAll('*')).some(function(el){
                const tag = (el.tagName || '').toLowerCase();
                const role = (el.getAttribute('role') || '').toLowerCase();
                const tabindex = el.getAttribute('tabindex');
                const interactive = tags.has(tag) || roles.has(role) || el.isContentEditable ||
                    (tabindex !== null && Number(tabindex) >= 0) || typeof el.onclick === 'function' ||
                    el.hasAttribute('onclick') || getComputedStyle(el).cursor === 'pointer';
                if (!interactive || (tag === 'input' && (el.type || '').toLowerCase() === 'hidden')) return false;
                var rect = el.getBoundingClientRect();
                if (rect.width <= 0 || rect.height <= 0) return false;
                var cs = getComputedStyle(el);
                return cs.display !== 'none' && cs.visibility !== 'hidden';
            });
        }";

        public BrowserAutomationService(BrowserAutomationConfig config)
        {
            _config = config ?? throw new ArgumentNullException(nameof(config));
            ValidateConfig(_config);
            _collectElementsJs = CollectElementsJsTemplate
                .Replace("__ELEMENT_LIMIT__", _config.PerDocumentElementLimit.ToString(System.Globalization.CultureInfo.InvariantCulture))
                .Replace("__PAGE_TEXT_LIMIT__", _config.PageTextLimit.ToString(System.Globalization.CultureInfo.InvariantCulture));
        }

        // 启动有头 Chromium；如未指定 ExecutablePath 且开启 AutoDownloadChromium，调用 BrowserFetcher 下载
        public async Task<bool> StartAsync(string url)
        {
            if (_browser != null && !_browser.IsClosed)
            {
                if (!string.IsNullOrWhiteSpace(url)) await NavigateAsync(url);
                return true;
            }

            var launchOpts = new LaunchOptions
            {
                Headless = _config.Headless,
                AcceptInsecureCerts = true,
                DefaultViewport = new ViewPortOptions
                {
                    Width = _config.ViewportWidth,
                    Height = _config.ViewportHeight,
                },
            };

            var executablePath = ResolveBrowserExecutablePath();
            if (!string.IsNullOrEmpty(executablePath))
            {
                launchOpts.ExecutablePath = executablePath;
            }
            else if (_config.AutoDownloadChromium)
            {
                // 不指定 ExecutablePath 时，PuppeteerSharp 自身不会自动下载，需显式触发
                // PuppeteerSharp 20.0 中 BrowserFetcher 不再实现 IDisposable，使用工厂方法创建实例
                var browserFetcher = Puppeteer.CreateBrowserFetcher(new BrowserFetcherOptions());
                await browserFetcher.DownloadAsync();
            }
            else
            {
                throw new InvalidOperationException("browser_launch_failed: 未找到可用浏览器。请检查 browser_automation.chromium_executable_path 或 chromium_candidate_paths");
            }

            _browser = await Puppeteer.LaunchAsync(launchOpts);
            _launchedBrowserProcessId = _browser.Process?.Id ?? 0;
            var initialPages = await _browser.PagesAsync();
            _page = initialPages.FirstOrDefault() ?? await _browser.NewPageAsync();
            _sessionId = Guid.NewGuid().ToString("N");
            _pageVersion = 0;
            _elementRegistry.Clear();
            _page.DefaultTimeout = _config.DefaultTimeoutMs;
            _page.DefaultNavigationTimeout = _config.DefaultTimeoutMs;

            var downloadDirectory = ResolveConfiguredPath(_config.DownloadDirectory);
            if (!string.IsNullOrWhiteSpace(downloadDirectory))
            {
                Directory.CreateDirectory(downloadDirectory);
                try
                {
                    var session = await _page.CreateCDPSessionAsync();
                    await session.SendAsync("Browser.setDownloadBehavior", new
                    {
                        behavior = "allow",
                        downloadPath = downloadDirectory,
                        eventsEnabled = true,
                    });
                }
                catch (Exception ex)
                {
                    Debug.WriteLine($"Browser download configuration failed: {ex.Message}");
                }
            }

            if (!string.IsNullOrWhiteSpace(url))
            {
                await _page.GoToAsync(url, new NavigationOptions
                {
                    Timeout = _config.DefaultTimeoutMs,
                    ReferrerPolicy = BrowserProtocol.NavigationReferrerPolicy,
                    WaitUntil = new[] { WaitUntilNavigation.Load },
                });
                await WaitForNetworkIdleSafeAsync();
                await WaitForDomStableAsync();
            }

            return true;
        }

        public async Task BackAsync()
        {
            EnsureStarted();
            await _page!.GoBackAsync(new NavigationOptions { Timeout = _config.DefaultTimeoutMs, WaitUntil = new[] { WaitUntilNavigation.Load } });
            await WaitForPageSettledAsync();
        }

        public async Task ForwardAsync()
        {
            EnsureStarted();
            await _page!.GoForwardAsync(new NavigationOptions { Timeout = _config.DefaultTimeoutMs, WaitUntil = new[] { WaitUntilNavigation.Load } });
            await WaitForPageSettledAsync();
        }

        public async Task ReloadAsync()
        {
            EnsureStarted();
            await _page!.ReloadAsync(new NavigationOptions { Timeout = _config.DefaultTimeoutMs, WaitUntil = new[] { WaitUntilNavigation.Load } });
            await WaitForPageSettledAsync();
        }

        // 导航到新 url（超时由 default_timeout_ms 控制）
        public async Task<bool> NavigateAsync(string url)
        {
            EnsureStarted();
            await _page!.GoToAsync(url, new NavigationOptions
            {
                Timeout = _config.DefaultTimeoutMs,
                ReferrerPolicy = BrowserProtocol.NavigationReferrerPolicy,
                WaitUntil = new[] { WaitUntilNavigation.Load },
            });
            // ★ 组合等待：networkidle + DOM 稳定
            // Load 事件后页面仍可能异步渲染，需等网络空闲与 DOM 稳定。
            await WaitForPageSettledAsync();
            return true;
        }

        // 点击 CSS 选择器元素；元素未找到抛 InvalidOperationException
        public async Task ClickAsync(string selector)
        {
            EnsureStarted();
            var element = await WaitForSelectorSafe(selector);
            await element.ClickAsync();
            // 组合等待策略：networkidle + DOM 稳定
            //   1. 初始延迟：让点击事件传播和动画开始
            //   2. networkidle：等待 AJAX 请求完成（弹窗表单异步加载的根因）
            //   3. MutationObserver DOM 稳定：监听真实 mutation，300ms 无变化视为渲染完成（比轮询更精准）
            //   4. 轮询 DOM 稳定：兜底确认元素数量稳定
            //   不用 WaitForNavigationAsync：SPA pushState 不触发 navigation 事件
            await Task.Delay(_config.ClickInitialDelayMs);
            await WaitForNetworkIdleSafeAsync();
            await WaitForDomStableMutationAsync();
            await WaitForDomStableAsync();
            // ★ 弹窗内容 settled 等待：若点击后页面存在弹窗/浮层，额外等待让表单/iframe 渲染完成
            await WaitForModalContentAsync();
        }

        // Fill native form controls and contenteditable targets, then emit standard events.
        public async Task FillAsync(string selector, string text)
        {
            EnsureStarted();
            var element = await WaitForSelectorSafe(selector);
            await element.FocusAsync();

            await element.EvaluateFunctionAsync(@"(el, val) => {
                if (el.isContentEditable) {
                    el.textContent = val;
                } else {
                    const prototype = Object.getPrototypeOf(el);
                    const descriptor = prototype ? Object.getOwnPropertyDescriptor(prototype, 'value') : null;
                    if (descriptor && typeof descriptor.set === 'function') descriptor.set.call(el, val);
                    else el.value = val;
                }
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }", text ?? string.Empty);
        }

        public async Task HoverAsync(string selector)
        {
            EnsureStarted();
            var element = await WaitForSelectorSafe(selector);
            await element.HoverAsync();
            await WaitForDomStableMutationAsync();
        }

        public async Task PressAsync(string? selector, string key)
        {
            EnsureStarted();
            if (!string.IsNullOrWhiteSpace(selector))
            {
                var element = await WaitForSelectorSafe(selector);
                await element.PressAsync(key);
            }
            else
            {
                await _page!.Keyboard.PressAsync(key);
            }
            await WaitForPageSettledAsync();
        }

        public async Task<string[]> SelectAsync(string selector, string[] values)
        {
            EnsureStarted();
            var element = await WaitForSelectorSafe(selector);
            var selected = await element.SelectAsync(values ?? Array.Empty<string>());
            await WaitForDomStableMutationAsync();
            return selected;
        }

        public async Task SetCheckedAsync(string selector, bool isChecked)
        {
            EnsureStarted();
            var element = await WaitForSelectorSafe(selector);
            var current = await element.EvaluateFunctionAsync<bool>("el => !!el.checked");
            if (current != isChecked)
            {
                await element.ClickAsync();
                await WaitForDomStableMutationAsync();
            }
        }

        // 核心方法：仅截视口（FullPage=false），返回 base64 字符串供调用方包装为 data URL
        public async Task<string> ScreenshotBase64Async()
        {
            EnsureStarted();
            // PuppeteerSharp 20.0 中 ScreenshotAsync 仅支持写入文件路径；如需 byte[] 须改用 ScreenshotDataAsync
            var bytes = await _page!.ScreenshotDataAsync(new ScreenshotOptions
            {
                Type = ScreenshotType.Png,
                FullPage = false,
            });
            return Convert.ToBase64String(bytes);
        }

        // 通过 window.scrollBy 实现 up/down 滚动，避免依赖元素滚动 API
        public async Task ScrollAsync(string direction, int amount)
        {
            EnsureStarted();
            var dir = (direction ?? string.Empty).ToLowerInvariant();
            int dy = dir == "up" ? -Math.Abs(amount) : Math.Abs(amount);
            await _page!.EvaluateExpressionAsync($"window.scrollBy(0, {dy})");
            // ★ 组合等待：networkidle + DOM 稳定（懒加载可能触发 AJAX + IntersectionObserver 渲染）
            await WaitForNetworkIdleSafeAsync();
            await WaitForDomStableAsync();
        }

        public async Task WaitForConditionAsync(string condition, int ms, string? selector, string? text, string? url, string? state)
        {
            EnsureStarted();
            var normalized = (condition ?? string.Empty).Trim().ToLowerInvariant();
            if (normalized == "time" || normalized == "delay" || normalized == string.Empty)
            {
                await WaitAsync(ms);
                return;
            }
            if (normalized == "dom_stable")
            {
                await WaitForDomStableMutationAsync();
                await WaitForDomStableAsync();
                return;
            }
            if (normalized == "navigation")
            {
                await WaitForPageSettledAsync();
                return;
            }
            if (normalized == "element")
            {
                if (string.IsNullOrWhiteSpace(selector)) throw new ArgumentException("等待元素时缺少目标");
                var hidden = string.Equals(state, "hidden", StringComparison.OrdinalIgnoreCase);
                if (!hidden)
                {
                    await WaitForSelectorSafe(selector, ms);
                    return;
                }

                var hiddenDeadline = DateTime.UtcNow.AddMilliseconds(ms);
                while (DateTime.UtcNow < hiddenDeadline)
                {
                    try
                    {
                        await WaitForSelectorSafe(selector, Math.Min(_config.DomStablePollMs, 250));
                    }
                    catch (InvalidOperationException)
                    {
                        return;
                    }
                    await Task.Delay(_config.DomStablePollMs);
                }
                throw new TimeoutException("等待元素隐藏超时");
            }

            var deadline = DateTime.UtcNow.AddMilliseconds(ms);
            while (DateTime.UtcNow < deadline)
            {
                if (normalized == "text" && !string.IsNullOrEmpty(text))
                {
                    var content = await _page!.EvaluateExpressionAsync<string>("document.body ? document.body.innerText : ''");
                    if ((content ?? string.Empty).Contains(text, StringComparison.Ordinal)) return;
                }
                else if (normalized == "url" && !string.IsNullOrEmpty(url) && (_page!.Url ?? string.Empty).Contains(url, StringComparison.Ordinal))
                {
                    return;
                }
                await Task.Delay(_config.DomStablePollMs);
            }
            throw new TimeoutException($"等待条件 {normalized} 超时");
        }

        public async Task<object[]> ListTabsAsync()
        {
            EnsureStarted();
            var pages = await _browser!.PagesAsync();
            var tabs = new List<object>();
            foreach (var page in pages)
            {
                var title = string.Empty;
                try { title = await page.GetTitleAsync(); } catch (Exception) { }
                tabs.Add(new
                {
                    tab_id = page.Target.TargetId,
                    url = page.Url ?? string.Empty,
                    title,
                    active = ReferenceEquals(page, _page),
                });
            }
            return tabs.ToArray();
        }

        public async Task<string> NewTabAsync(string? url)
        {
            EnsureStarted();
            var page = await _browser!.NewPageAsync();
            page.DefaultTimeout = _config.DefaultTimeoutMs;
            page.DefaultNavigationTimeout = _config.DefaultTimeoutMs;
            _page = page;
            if (!string.IsNullOrWhiteSpace(url)) await NavigateAsync(url);
            return page.Target.TargetId;
        }

        public async Task SwitchTabAsync(string tabId)
        {
            EnsureStarted();
            var pages = await _browser!.PagesAsync();
            var target = pages.FirstOrDefault(page => string.Equals(page.Target.TargetId, tabId, StringComparison.Ordinal));
            if (target == null) throw new InvalidOperationException("element_not_found: 标签页不存在");
            _page = target;
            await _page.BringToFrontAsync();
            _elementRegistry.Clear();
        }

        public async Task CloseTabAsync(string? tabId)
        {
            EnsureStarted();
            var pages = await _browser!.PagesAsync();
            var currentPage = _page!;
            var currentTabId = currentPage.Target.TargetId;
            var target = string.IsNullOrWhiteSpace(tabId)
                ? _page
                : pages.FirstOrDefault(page => string.Equals(page.Target.TargetId, tabId, StringComparison.Ordinal));
            if (target == null) throw new InvalidOperationException("element_not_found: 标签页不存在");
            var closingCurrentPage = string.Equals(target.Target.TargetId, currentTabId, StringComparison.Ordinal);
            await target.CloseAsync();
            var remaining = await _browser.PagesAsync();
            _page = closingCurrentPage
                ? remaining.LastOrDefault()
                : remaining.FirstOrDefault(page => string.Equals(page.Target.TargetId, currentTabId, StringComparison.Ordinal));
            _page ??= remaining.LastOrDefault();
            _elementRegistry.Clear();
            if (_page != null) await _page.BringToFrontAsync();
        }

        public Task<object[]> ListDownloadsAsync()
        {
            var directory = ResolveConfiguredPath(_config.DownloadDirectory);
            if (string.IsNullOrWhiteSpace(directory) || !Directory.Exists(directory)) return Task.FromResult(Array.Empty<object>());
            var files = Directory.EnumerateFiles(directory)
                .Select(path => new FileInfo(path))
                .OrderByDescending(info => info.LastWriteTimeUtc)
                .Take(_config.MaxReturnedElements)
                .Select(info => (object)new
                {
                    name = info.Name,
                    path = info.FullName,
                    size = info.Length,
                    modified_at = info.LastWriteTimeUtc,
                })
                .ToArray();
            return Task.FromResult(files);
        }

        // 获取元素 innerText（未找到抛 InvalidOperationException）
        public async Task<string> GetTextAsync(string selector)
        {
            EnsureStarted();
            var element = await WaitForSelectorSafe(selector);
            var text = await element.EvaluateFunctionAsync<string>("el => el.innerText");
            return text ?? string.Empty;
        }

        public async Task<string> ClassifySensitiveActionAsync(string selector, string action, string key)
        {
            if (string.IsNullOrWhiteSpace(selector) ||
                action is not (BrowserProtocol.Actions.Click or BrowserProtocol.Actions.Press))
            {
                return "none";
            }

            var element = await WaitForSelectorSafe(selector);
            return await element.EvaluateFunctionAsync<string>(@"(element, action, key) => {
                const allowed = new Set(['submit_personal_data', 'purchase', 'change_permissions', 'delete_data']);
                const declaredHost = element.closest('[data-browser-risk]');
                const declared = (declaredHost && declaredHost.getAttribute('data-browser-risk') || '').trim();
                if (allowed.has(declared)) return declared;

                const form = element.form || element.closest('form');
                if (!form) return 'none';
                const type = (element.getAttribute('type') || '').toLowerCase();
                const role = (element.getAttribute('role') || '').toLowerCase();
                const submits = (element.tagName === 'BUTTON' && (type === '' || type === 'submit'))
                    || (element.tagName === 'INPUT' && (type === 'submit' || type === 'image'))
                    || role === 'button'
                    || (action === 'press' && key === 'Enter');
                if (!submits) return 'none';

                const personalTypes = new Set(['email', 'tel', 'password']);
                const personalAutocomplete = new Set([
                    'name', 'honorific-prefix', 'given-name', 'additional-name', 'family-name',
                    'email', 'tel', 'street-address', 'address-line1', 'address-line2',
                    'postal-code', 'country', 'cc-name', 'cc-number', 'cc-exp', 'cc-csc'
                ]);
                for (const control of form.elements || []) {
                    const controlType = (control.getAttribute && control.getAttribute('type') || '').toLowerCase();
                    const autocomplete = (control.getAttribute && control.getAttribute('autocomplete') || '')
                        .trim().toLowerCase().split(/\s+/).pop();
                    if (personalTypes.has(controlType) || personalAutocomplete.has(autocomplete)) {
                        return 'submit_personal_data';
                    }
                }
                return 'none';
            }", action, key);
        }

        // 获取页面所有可交互元素的 DOM 信息（100% 准确的 CSS 选择器，不依赖视觉猜测）
        // ★ 关键改进：遍历 page.Frames，采集跨域 iframe 内元素，标记 frame_url/frame_name；
        //   跨域/未就绪 iframe 兜底：提取 iframe.src 在新页面中采集
        public async Task<string> GetElementsAsync()
        {
            EnsureStarted();
            var allElements = new List<Dictionary<string, object>>();
            var pageTextParts = new List<string>();
            var diagLog = new List<string>();  // 诊断日志

            // 0. 从主文档提取所有 <iframe> 的 src/id（用于诊断 + 兜底）
            var iframesInDom = new List<(string src, string id)>();
            try
            {
                var iframeJson = await _page!.EvaluateExpressionAsync<string>(@"
                    JSON.stringify(Array.from(document.querySelectorAll('iframe, frame')).map(function(f) {
                        return { src: f.src || '', id: f.id || '' };
                    }))
                ");
                if (!string.IsNullOrWhiteSpace(iframeJson) && iframeJson != "null" && iframeJson != "[]")
                {
                    var parsed = JsonSerializer.Deserialize<List<Dictionary<string, string>>>(iframeJson);
                    if (parsed != null)
                    {
                        foreach (var p in parsed)
                        {
                            p.TryGetValue("src", out var src);
                            p.TryGetValue("id", out var id);
                            if (!string.IsNullOrWhiteSpace(src) && src != "about:blank")
                                iframesInDom.Add((src ?? "", id ?? ""));
                        }
                    }
                }
            }
            catch (Exception) { }

            // 1. 主 frame
            var mainResult = await _page!.EvaluateFunctionAsync<string>(_collectElementsJs);
            ParseElementsResult(mainResult, allElements, pageTextParts, isFrame: false, frameUrl: string.Empty, frameName: string.Empty);

            // 2. 检测是否有子 frame（基于 page.Frames，而非主文档 querySelectorAll）
            //    原因：iframe 可能在 shadow DOM 或弹窗内，主文档 querySelectorAll 找不到
            //    但 PuppeteerSharp 的 page.Frames 能通过浏览器内部 API 获取所有 frame
            bool hasChildFrames = _page.Frames.Any(f => f != _page.MainFrame);

            // Wait until the browser frame set is stable before collecting it.
            int lastFrameCount = _page.Frames.Length;
            var frameStableSince = DateTime.UtcNow;
            var frameStableDeadline = DateTime.UtcNow.AddMilliseconds(_config.FrameStableMaxWaitMs);
            while (DateTime.UtcNow < frameStableDeadline)
            {
                await Task.Delay(_config.DomStablePollMs);
                int nowFrameCount;
                try { nowFrameCount = _page.Frames.Length; }
                catch (Exception) { continue; }
                if (nowFrameCount == lastFrameCount)
                {
                    if ((DateTime.UtcNow - frameStableSince).TotalMilliseconds >= _config.FrameStableMs) break;
                }
                else
                {
                    diagLog.Add($"FrameStabilize: FrameCount {lastFrameCount} -> {nowFrameCount}, waiting for render");
                    lastFrameCount = nowFrameCount;
                    frameStableSince = DateTime.UtcNow;
                    await Task.Delay(_config.DomStablePollMs);
                }
            }
            hasChildFrames = _page.Frames.Any(f => f != _page.MainFrame);

            // 3. 所有子 frame — 重试收集
            int frameRetryCount = 0;
            int maxFrameRetries = _config.FrameRetryCount;
            bool collectedFromAnyFrame = false;

            // ★ 记录哪些 frame URL 已经成功采集到非空元素（诊断用）
            var collectedFrameUrls = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            int prevFrameCount = _page.Frames.Length;

            do
            {
                frameRetryCount++;
                int framesBefore = allElements.Count(e => GetBool(e, "in_frame"));
                int curFrameCount = _page.Frames.Length;

                    // If a frame appeared, allow one configured stabilization interval.
                    if (curFrameCount > prevFrameCount)
                    {
                    diagLog.Add($"Retry#{frameRetryCount}: FrameCount increased {prevFrameCount} -> {curFrameCount}");
                    await Task.Delay(_config.FrameStableMs);
                }
                prevFrameCount = curFrameCount;

                foreach (var frame in _page.Frames)
                {
                    if (frame == _page.MainFrame) continue;
                    var frameUrl = frame.Url ?? string.Empty;
                    try
                    {
                        // Wait for a visible semantic target without assuming a framework.
                        try
                        {
                            // ★ 等待真正可见的交互元素出现（而非仅 querySelectorAll 命中 display:none 元素）
                            await frame.WaitForFunctionAsync(
                                VisibleInteractiveCheckJs,
                                new WaitForFunctionOptions { Timeout = _config.FrameStableMaxWaitMs, PollingInterval = _config.DomStablePollMs }
                            );
                        }
                        catch (Exception)
                        {
                            // 超时不阻断，继续尝试采集（可能 frame 内确实无交互元素）
                        }

                        int beforeCount = allElements.Count;
                        var frameResult = await frame.EvaluateFunctionAsync<string>(_collectElementsJs);
                        if (!string.IsNullOrWhiteSpace(frameResult) && frameResult != "null")
                        {
#pragma warning disable CS0618
                            var frameName = frame.Name ?? string.Empty;
#pragma warning restore CS0618
                            ParseElementsResult(frameResult, allElements, pageTextParts, isFrame: true, frameUrl: frameUrl, frameName: frameName);
                            // ★ 只有真正采集到元素才标记 collectedFromAnyFrame
                            //   修复原 bug：frame 返回空 elements 数组时也标记 true，导致重试被跳过
                            if (allElements.Count > beforeCount)
                            {
                                collectedFromAnyFrame = true;
                            }
                            if (!string.IsNullOrWhiteSpace(frameUrl))
                                collectedFrameUrls.Add(frameUrl);
                        }
                    }
                    catch (Exception ex)
                    {
                        var errMsg = $"Frame[{frameUrl}] attempt#{frameRetryCount}: {ex.GetType().Name}: {ex.Message}";
                        System.Diagnostics.Debug.WriteLine(errMsg);
                        diagLog.Add(errMsg);
                    }
                }

                int framesAfter = allElements.Count(e => GetBool(e, "in_frame"));
                // ★ 重试终止条件：
                //   1. framesAfter > framesBefore：本帧采集到 in_frame 元素，停止
                //   2. !hasChildFrames：没有子 frame，停止
                if (framesAfter > framesBefore) break;
                if (!hasChildFrames) break;

                if (frameRetryCount < maxFrameRetries)
                {
                    await Task.Delay(_config.FrameStableMs);
                    try { await WaitForNetworkIdleSafeAsync(); await WaitForDomStableMutationAsync(); } catch (Exception) { }
                }
            }
            while (frameRetryCount < maxFrameRetries);

            // Re-collect once when a frame appeared after the retry loop.
            try
            {
                int finalFrameCount = _page.Frames.Length;
                if (finalFrameCount > prevFrameCount)
                {
                    diagLog.Add($"PostLoop: FrameCount increased {prevFrameCount} -> {finalFrameCount}, re-collecting");
                    await Task.Delay(_config.FrameStableMs);
                    foreach (var frame in _page.Frames)
                    {
                        if (frame == _page.MainFrame) continue;
                        var frameUrl = frame.Url ?? string.Empty;
                        try
                        {
                            try
                            {
                                await frame.WaitForFunctionAsync(
                                    VisibleInteractiveCheckJs,
                                    new WaitForFunctionOptions { Timeout = _config.FrameStableMaxWaitMs, PollingInterval = _config.DomStablePollMs }
                                );
                            }
                            catch (Exception) { }
                            int beforeCount = allElements.Count;
                            var frameResult = await frame.EvaluateFunctionAsync<string>(_collectElementsJs);
                            if (!string.IsNullOrWhiteSpace(frameResult) && frameResult != "null")
                            {
#pragma warning disable CS0618
                                var frameName = frame.Name ?? string.Empty;
#pragma warning restore CS0618
                                ParseElementsResult(frameResult, allElements, pageTextParts, isFrame: true, frameUrl: frameUrl, frameName: frameName);
                                if (allElements.Count > beforeCount && !string.IsNullOrWhiteSpace(frameUrl))
                                    collectedFrameUrls.Add(frameUrl);
                            }
                        }
                        catch (Exception ex)
                        {
                            diagLog.Add($"PostLoop Frame[{frameUrl}]: {ex.Message}");
                        }
                    }
                }
            }
            catch (Exception) { }

            // ★ 4. 跨域 iframe 兜底已移除（原 L729-786）
            //   原因：兜底在新 page 采集后立即释放（using var newPage），css_selector 属于新 page 上下文，
            //         ClickAsync→WaitForSelectorSafe 只搜 _page.MainFrame + _page.Frames，无法搜已释放的新 page，
            //         导致兜底采集的元素 100% 无法 click，反而误导 AI 反复点击失败。
            //         且每个跨域 iframe 兜底耗时 20s（15s GoTo + 5s networkidle），严重拖慢速度。
            //   正确做法：跨域 iframe 内容由 AI 调用 vls_analyze_browser 获取视觉描述（仅用文字理解，不用其 css_selector）。
            if (iframesInDom.Count > 0)
            {
                var uncapturedIframes = iframesInDom
                    .Where(ifr => !collectedFrameUrls.Contains(ifr.src) && !string.IsNullOrWhiteSpace(ifr.src))
                    .ToList();
                if (uncapturedIframes.Count > 0)
                {
                    diagLog.Add($"CrossOriginIframe: {uncapturedIframes.Count} 个跨域 iframe 已跳过采集（兜底已移除，元素无法 click）");
                }
            }

            // ★ 5. 增强诊断：采集页面级指标（Body HTML 长度、DOM 元素总数、交互元素数、弹窗数、ShadowRoot 数）
            //    任何网站出问题基本一眼定位是“元素被截断 / 弹窗未渲染 / frame 未创建 / shadow 未穿透”
            int bodyHtmlLength = 0, totalDomCount = 0, interactiveCount = 0, modalCount = 0, shadowRootCount = 0;
            try
            {
                var metricsJson = await _page!.EvaluateExpressionAsync<string>(@"
                    JSON.stringify((function(){
                        var body = document.body;
                        var bodyLen = body ? body.innerHTML.length : 0;
                        var totalDom = document.querySelectorAll('*').length;
                        var roles = new Set(['button','link','checkbox','radio','switch','tab','menuitem','option','combobox','textbox','slider','spinbutton','treeitem']);
                        var tags = new Set(['input','button','textarea','select','a','summary','option']);
                        var interactive = Array.from(document.querySelectorAll('*')).filter(function(el){
                            var tag=(el.tagName||'').toLowerCase();
                            var role=(el.getAttribute('role')||'').toLowerCase();
                            var tabindex=el.getAttribute('tabindex');
                            return tags.has(tag)||roles.has(role)||el.isContentEditable||
                                (tabindex!==null&&Number(tabindex)>=0)||typeof el.onclick==='function'||
                                el.hasAttribute('onclick')||getComputedStyle(el).cursor==='pointer';
                        }).length;
                        var modals = Array.from(document.querySelectorAll('dialog,[role=""dialog""],[aria-modal=""true""]')).filter(function(el){
                            return el.getAttribute('aria-modal')==='true'||el.getAttribute('role')==='dialog'||el.open;
                        }).length;
                        var shadow = 0;
                        document.querySelectorAll('*').forEach(function(el){ if(el.shadowRoot) shadow++; });
                        return {bodyLen:bodyLen,totalDom:totalDom,interactive:interactive,modals:modals,shadow:shadow};
                    })())
                ");
                if (!string.IsNullOrWhiteSpace(metricsJson) && metricsJson != "null")
                {
                    var metrics = JsonSerializer.Deserialize<Dictionary<string, object>>(metricsJson);
                    if (metrics != null)
                    {
                        bodyHtmlLength = GetInt(metrics, "bodyLen");
                        totalDomCount = GetInt(metrics, "totalDom");
                        interactiveCount = GetInt(metrics, "interactive");
                        modalCount = GetInt(metrics, "modals");
                        shadowRootCount = GetInt(metrics, "shadow");
                    }
                }
            }
            catch (Exception) { }

            // ★ 6. 诊断日志写入文件（每次追加最新状态）
            try
            {
                var diagPath = ResolveConfiguredPath(_config.DiagnosticsFile);
                // 确保路径存在
                var diagDir = Path.GetDirectoryName(diagPath);
                if (!string.IsNullOrWhiteSpace(diagDir) && !Directory.Exists(diagDir))
                    Directory.CreateDirectory(diagDir);

                var timestamp = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss.fff");
                var diagLines = new List<string>
                {
                    $"=== [{timestamp}] GetElementsAsync diag ===",
                    $"PageURL: {_page.Url ?? ""}",
                    $"FrameCount (excl main): {_page.Frames.Count(f => f != _page.MainFrame)}",
                    $"FrameURLs: {string.Join(" | ", _page.Frames.Where(f => f != _page.MainFrame).Select(f => f.Url ?? ""))}",
                    $"BodyHtmlLength: {bodyHtmlLength}",
                    $"TotalDomCount: {totalDomCount}",
                    $"InteractiveElementCount (page): {interactiveCount}",
                    $"ModalCount (page): {modalCount}",
                    $"ShadowRootCount: {shadowRootCount}",
                    $"IframesInDOM: {iframesInDom.Count}",
                    $"IframesInDOM details: {string.Join("; ", iframesInDom.Select(i => $"{i.id}={i.src}"))}",
                    $"CollectedFrameURLs: {string.Join("; ", collectedFrameUrls)}",
                    $"CollectedFromAnyFrame: {collectedFromAnyFrame}",
                    $"FrameRetryCount: {frameRetryCount}",
                    $"TotalElements before sorting: {allElements.Count}",
                    $"InModal: {allElements.Count(e => GetBool(e, "in_modal"))}",
                    $"InFrame: {allElements.Count(e => GetBool(e, "in_frame"))}",
                    $"PerDocumentLimit: {_config.PerDocumentElementLimit}",
                    $"MaxReturnedElements: {_config.MaxReturnedElements}",
                };
                diagLines.AddRange(diagLog.Select(l => "  " + l));
                diagLines.Add($"=== end ===");

                File.AppendAllLines(diagPath, diagLines, System.Text.Encoding.UTF8);
            }
            catch (Exception) { }

            // 6. 全局排序：弹窗内元素 > iframe 内元素 > 普通元素
            var sortedElements = allElements
                .OrderByDescending(el => GetBool(el, "in_modal"))
                .ThenByDescending(el => GetBool(el, "in_frame"))
                .Take(_config.MaxReturnedElements)
                .ToList();

            _pageVersion++;
            _elementRegistry.Clear();
            for (var index = 0; index < sortedElements.Count; index++)
            {
                var element = sortedElements[index];
                var elementId = $"{_pageVersion}:{index + 1}";
                var selector = GetString(element, "css_selector");
                var frameUrl = GetString(element, "frame_url");
                var frameName = GetString(element, "frame_name");
                var runtimeId = GetString(element, "runtime_id");
                element["element_id"] = elementId;
                element["page_version"] = _pageVersion;
                _elementRegistry[elementId] = (_pageVersion, selector, frameUrl, frameName, runtimeId);
            }

            var combinedPageText = string.Join(" ", pageTextParts).Replace("  ", " ").Trim();
            if (combinedPageText.Length > _config.PageTextLimit) combinedPageText = combinedPageText.Substring(0, _config.PageTextLimit);

            return JsonSerializer.Serialize(new { page_version = _pageVersion, elements = sortedElements, page_text = combinedPageText });
        }

        public string ResolveElementTarget(string? elementId, string? selector)
        {
            if (!string.IsNullOrWhiteSpace(elementId))
            {
                if (!_elementRegistry.TryGetValue(elementId, out var target) || target.Version != _pageVersion)
                {
                    throw new InvalidOperationException("stale_element: 元素快照已过期，请重新 inspect");
                }
                if (string.IsNullOrWhiteSpace(target.Selector))
                {
                    throw new InvalidOperationException("element_not_found: 元素没有可执行定位信息");
                }
                if (!string.IsNullOrWhiteSpace(target.RuntimeId))
                {
                    return $"[data-moonya-runtime-id=\"{target.RuntimeId.Replace("\"", "\\\"")}\"]";
                }
                return target.Selector;
            }
            if (!string.IsNullOrWhiteSpace(selector)) return selector;
            throw new ArgumentException("缺少 element_id 或 selector 参数");
        }

        // 解析单 frame 的采集结果并合并到全局列表
        private void ParseElementsResult(string json, List<Dictionary<string, object>> allElements, List<string> pageTextParts, bool isFrame, string frameUrl, string frameName)
        {
            if (string.IsNullOrWhiteSpace(json)) return;
            try
            {
                var data = JsonSerializer.Deserialize<Dictionary<string, object>>(json);
                if (data == null) return;

                if (data.TryGetValue("page_text", out var pageTextObj) && pageTextObj is JsonElement pageTextJson && pageTextJson.ValueKind == JsonValueKind.String)
                {
                    var framePageText = pageTextJson.GetString() ?? string.Empty;
                    if (!string.IsNullOrWhiteSpace(framePageText))
                    {
                        // 主文档直接追加；iframe 文档加标记，便于 AI 识别弹窗/frame 上下文
                        if (isFrame)
                        {
                            pageTextParts.Add($"[FRAME {frameUrl}] {framePageText}");
                        }
                        else
                        {
                            pageTextParts.Add(framePageText);
                        }
                    }
                }

                if (data.TryGetValue("elements", out var elementsObj) && elementsObj is JsonElement elementsJson && elementsJson.ValueKind == JsonValueKind.Array)
                {
                    foreach (var el in elementsJson.EnumerateArray())
                    {
                        var elDict = el.Deserialize<Dictionary<string, object>>();
                        if (elDict == null) continue;
                        elDict["in_frame"] = isFrame;
                        elDict["frame_url"] = frameUrl;
                        elDict["frame_name"] = frameName;
                        // 只要是通过 frame API 采集的，统一标记为 in_iframe=true
                        if (isFrame) elDict["in_iframe"] = true;
                        allElements.Add(elDict);
                    }
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"ParseElementsResult failed: {ex.Message}");
            }
        }

        // 在浏览器执行任意 JS 表达式并返回 JSON 序列化结果
        public async Task<string> EvaluateAsync(string jsCode)
        {
            EnsureStarted();
            var result = await _page!.EvaluateExpressionAsync<object>(jsCode);
            if (result == null) return "null";
            // 直接 Serialize object 可能丢失原始结构，这里包装为 JsonElement 再序列化以保留运行时类型
            if (result is JsonElement je)
            {
                return je.ValueKind == JsonValueKind.Null ? "null" : je.GetRawText();
            }
            return JsonSerializer.Serialize(result);
        }

        // 单纯延迟，不做任何浏览器操作
        public async Task WaitAsync(int ms)
        {
            await Task.Delay(ms);
        }

        // 关闭浏览器并释放资源
        public async Task StopAsync()
        {
            if (_browser != null)
            {
                if (!_browser.IsClosed)
                {
                    using var cts = new CancellationTokenSource(TimeSpan.FromMilliseconds(_config.ShutdownTimeoutMs));
                    try
                    {
                        var closeTask = _browser.CloseAsync();
                        await Task.WhenAny(closeTask, Task.Delay(-1, cts.Token));
                    }
                    catch (Exception) { }
                }
                try { _browser.Dispose(); } catch (Exception) { }
                _browser = null;
                _page = null;
                _sessionId = string.Empty;
                _pageVersion = 0;
                _elementRegistry.Clear();

                await Task.Delay(_config.CleanupDelayMs);
                KillLaunchedBrowserProcess();
            }
        }

        private void KillLaunchedBrowserProcess()
        {
            if (_launchedBrowserProcessId <= 0) return;
            try
            {
                using var process = Process.GetProcessById(_launchedBrowserProcessId);
                if (!process.HasExited)
                {
                    process.Kill(entireProcessTree: true);
                    process.WaitForExit(_config.ShutdownTimeoutMs);
                }
            }
            catch { }
            finally { _launchedBrowserProcessId = 0; }
        }

        // 返回当前运行状态对象
        public async Task<object> GetStatusAsync()
        {
            bool running = _browser != null && !_browser.IsClosed;
            string url = string.Empty;
            string title = string.Empty;

            if (running && _page != null)
            {
                try
                {
                    url = _page.Url ?? string.Empty;
                    title = await _page.GetTitleAsync();
                }
                catch (Exception) { }
            }

            return new
            {
                running,
                session_id = _sessionId,
                page_version = _pageVersion,
                tab_id = running && _page != null ? _page.Target.TargetId : string.Empty,
                url,
                title,
            };
        }

        public async Task<string> GetPageFactsFingerprintAsync()
        {
            if (!IsRunning) return string.Empty;
            var facts = await _page!.EvaluateExpressionAsync<string>($@"
                JSON.stringify({{
                    url: location.href,
                    title: document.title,
                    text: (document.body ? document.body.innerText : '').slice(0, {_config.PageTextLimit}),
                    dom: (document.documentElement ? document.documentElement.innerHTML : '').slice(0, {_config.PageTextLimit}),
                    active: document.activeElement ? {{
                        tag: document.activeElement.tagName,
                        value: document.activeElement.value || '',
                        checked: document.activeElement.checked === true
                    }} : null
                }})");
            var input = (facts ?? string.Empty) + "|frames=" + _page.Frames.Length;
            return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(input))).ToLowerInvariant();
        }

        public void Dispose()
        {
            if (_disposed) return;
            _disposed = true;
            try
            {
                // 同步等待，但带超时避免死锁；若超时则强制清理进程
                var stopTask = StopAsync();
                if (!stopTask.Wait(TimeSpan.FromMilliseconds(_config.ShutdownTimeoutMs + _config.CleanupDelayMs)))
                {
                    KillLaunchedBrowserProcess();
                }
            }
            catch (Exception) { }
        }

        // ── 内部辅助 ───────────────────────────────────────

        private void EnsureStarted()
        {
            if (_browser == null || _browser.IsClosed || _page == null)
            {
                throw new InvalidOperationException("浏览器未启动或已关闭，请先调用 StartAsync");
            }
        }

        private static void ValidateConfig(BrowserAutomationConfig config)
        {
            var missing = new List<string>();
            if (string.IsNullOrWhiteSpace(config.LoopbackHost)) missing.Add("loopback_host");
            if (config.Port <= 0) missing.Add("port");
            if (config.DefaultTimeoutMs <= 0) missing.Add("default_timeout_ms");
            if (config.ElementTimeoutMs <= 0) missing.Add("element_timeout_ms");
            if (config.ViewportWidth <= 0) missing.Add("viewport_width");
            if (config.ViewportHeight <= 0) missing.Add("viewport_height");
            if (config.ClickInitialDelayMs < 0) missing.Add("click_initial_delay_ms");
            if (config.NetworkIdleTimeoutMs <= 0) missing.Add("network_idle_timeout_ms");
            if (config.NetworkIdleTimeMs <= 0) missing.Add("network_idle_time_ms");
            if (config.DomStableMaxWaitMs <= 0) missing.Add("dom_stable_max_wait_ms");
            if (config.DomStableThresholdMs <= 0) missing.Add("dom_stable_threshold_ms");
            if (config.DomStablePollMs <= 0) missing.Add("dom_stable_poll_ms");
            if (config.MutationStableMs <= 0) missing.Add("mutation_stable_ms");
            if (config.MutationTimeoutMs <= 0) missing.Add("mutation_timeout_ms");
            if (config.FrameStableMs <= 0) missing.Add("frame_stable_ms");
            if (config.FrameStableMaxWaitMs <= 0) missing.Add("frame_stable_max_wait_ms");
            if (config.FrameRetryCount <= 0) missing.Add("frame_retry_count");
            if (config.PerDocumentElementLimit <= 0) missing.Add("per_document_element_limit");
            if (config.MaxReturnedElements <= 0) missing.Add("max_returned_elements");
            if (config.PageTextLimit <= 0) missing.Add("page_text_limit");
            if (config.DefaultWaitMs <= 0) missing.Add("default_wait_ms");
            if (config.DefaultScrollAmount <= 0) missing.Add("default_scroll_amount");
            if (config.RelayTimeoutMs <= 0) missing.Add("relay_timeout_ms");
            if (config.ShutdownTimeoutMs <= 0) missing.Add("shutdown_timeout_ms");
            if (config.CleanupDelayMs < 0) missing.Add("cleanup_delay_ms");
            if (missing.Count > 0)
            {
                throw new InvalidOperationException("browser_automation 配置缺失或无效: " + string.Join(", ", missing));
            }
        }

        private string ResolveBrowserExecutablePath()
        {
            var candidates = new List<string>();
            if (!string.IsNullOrWhiteSpace(_config.ChromiumExecutablePath)) candidates.Add(_config.ChromiumExecutablePath);
            candidates.AddRange(_config.ChromiumCandidatePaths.Where(path => !string.IsNullOrWhiteSpace(path)));
            foreach (var candidate in candidates)
            {
                var resolved = ResolveConfiguredPath(candidate);
                if (File.Exists(resolved)) return resolved;
            }
            return string.Empty;
        }

        private static string ResolveConfiguredPath(string configuredPath)
        {
            if (string.IsNullOrWhiteSpace(configuredPath)) return string.Empty;
            var expanded = Environment.ExpandEnvironmentVariables(configuredPath.Trim());
            return Path.GetFullPath(Path.IsPathRooted(expanded)
                ? expanded
                : Path.Combine(AppDomain.CurrentDomain.BaseDirectory, expanded));
        }

        private async Task WaitForPageSettledAsync()
        {
            await WaitForNetworkIdleSafeAsync();
            await WaitForDomStableMutationAsync();
            await WaitForDomStableAsync();
        }

        // 从 Dictionary 中安全读取 bool 值（兼容 JsonElement true / C# bool）
        private static bool GetBool(Dictionary<string, object> dict, string key)
        {
            if (dict == null || !dict.TryGetValue(key, out var obj)) return false;
            if (obj is bool b) return b;
            if (obj is JsonElement je)
            {
                return je.ValueKind == JsonValueKind.True;
            }
            return false;
        }

        // ★ 从 Dictionary 中安全读取 int 值（兼容 JsonElement number / C# int/long）
        private static int GetInt(Dictionary<string, object> dict, string key)
        {
            if (dict == null || !dict.TryGetValue(key, out var obj)) return 0;
            if (obj is int i) return i;
            if (obj is long l) return (int)l;
            if (obj is double d) return (int)d;
            if (obj is JsonElement je)
            {
                if (je.ValueKind == JsonValueKind.Number)
                {
                    return je.TryGetInt32(out var iv) ? iv : (int)je.GetDouble();
                }
            }
            return 0;
        }

        private static string GetString(Dictionary<string, object> dict, string key)
        {
            if (!dict.TryGetValue(key, out var value) || value == null) return string.Empty;
            if (value is string text) return text;
            if (value is JsonElement json && json.ValueKind == JsonValueKind.String) return json.GetString() ?? string.Empty;
            return value.ToString() ?? string.Empty;
        }

        // Network-idle is advisory because streaming and polling pages may never become idle.
        private async Task WaitForNetworkIdleSafeAsync()
        {
            try
            {
                await _page!.WaitForNetworkIdleAsync(new WaitForNetworkIdleOptions
                {
                    Timeout = _config.NetworkIdleTimeoutMs,
                    IdleTime = _config.NetworkIdleTimeMs,
                });
            }
            catch (Exception)
            {
                // 超时或页面导航中，忽略，继续后续 DOM 稳定检测
            }
        }

        // ★ 等待 DOM 稳定：轮询 document.querySelectorAll('*').length，
        //   元素数量在 DomStableThresholdMs 内无变化视为渲染完成。
        //   适配 SPA 路由切换（pushState 不触发 navigation）、传统页面导航、弹窗 AJAX 渲染三种场景。
        //   导航过程中 DOM 可能短暂不可访问（EvaluateExpressionAsync 抛异常），捕获后继续轮询。
        private async Task WaitForDomStableAsync()
        {
            var deadline = DateTime.UtcNow.AddMilliseconds(_config.DomStableMaxWaitMs);
            int lastCount = -1;
            var lastChange = DateTime.UtcNow;

            while (DateTime.UtcNow < deadline)
            {
                int currentCount;
                try
                {
                    currentCount = await _page!.EvaluateExpressionAsync<int>("document.querySelectorAll('*').length");
                }
                catch (Exception)
                {
                    // 页面导航中 DOM 短暂不可访问，重置基准继续等待
                    await Task.Delay(_config.DomStablePollMs);
                    lastChange = DateTime.UtcNow;
                    continue;
                }

                if (currentCount == lastCount)
                {
                    if ((DateTime.UtcNow - lastChange).TotalMilliseconds >= _config.DomStableThresholdMs)
                    {
                        return; // 元素数量稳定，视为渲染完成
                    }
                }
                else
                {
                    lastCount = currentCount;
                    lastChange = DateTime.UtcNow;
                }

                await Task.Delay(_config.DomStablePollMs);
            }
            // 超过最大等待时长仍未稳定，直接返回（避免无限阻塞）
        }

        // ★ 基于 MutationObserver 的 DOM 稳定等待：监听 body 子树变化，300ms 内无 mutation 视为稳定
        //   比固定轮询 querySelectorAll('*').length 更精准：能捕获属性/文本变化（元素数量可能不变但内容在变）
        //   带兜底超时（5s），防止长轮询页面永久阻塞；立即触发一次，若 DOM 已稳定 300ms 后自动结束
        private async Task WaitForDomStableMutationAsync()
        {
            try
            {
                await _page!.EvaluateFunctionAsync(@"(stableMs, timeoutMs) => new Promise(resolve => {
                    let settled = false;
                    let observer;
                    const finish = () => {
                        if (settled) return;
                        settled = true;
                        try { if (observer) observer.disconnect(); } catch(e){}
                        clearTimeout(window.__baMutationTimer);
                        resolve();
                    };
                    try {
                        observer = new MutationObserver(() => {
                            clearTimeout(window.__baMutationTimer);
                            window.__baMutationTimer = setTimeout(finish, stableMs);
                        });
                        observer.observe(document.body, { childList: true, subtree: true });
                    } catch(e) {
                        // body 不存在或不可观察，立即返回
                        finish();
                        return;
                    }
                    // 兜底超时：最多等待 5 秒
                    setTimeout(finish, timeoutMs);
                    // 立即触发一次：若 DOM 已稳定，300ms 后自动结束
                    clearTimeout(window.__baMutationTimer);
                    window.__baMutationTimer = setTimeout(finish, stableMs);
                })", _config.MutationStableMs, _config.MutationTimeoutMs);
            }
            catch (Exception)
            {
                // 页面导航中或 body 不可访问时忽略，回退到轮询方式
            }
        }

        // Detect standards-based modal state and wait for its contents without vendor selectors.
        private async Task WaitForModalContentAsync()
        {
            try
            {
                var hasModal = await _page!.EvaluateExpressionAsync<bool>(@"
                    !!document.querySelector('dialog[open], [role=""dialog""], [aria-modal=""true""]')
                ");
                if (hasModal)
                {
                    // ★ 弹窗容器已出现—主等待：让内部表单/iframe 完成渲染和动画
                    //   MutationObserver 精准等待 mutation 停止（5s 兜底）+ 轮询 DOM 稳定兜底
                    await WaitForDomStableMutationAsync();
                    await WaitForDomStableAsync();

                    // ★ 二次稳定检查：弹窗内 input/select/textarea < 2 时再等一次 MutationObserver
                    //   （原 sleep + DomStable 改为仅 MutationObserver，节省 ~3s）
                    try
                    {
                        var modalInputCount = await _page!.EvaluateExpressionAsync<int>(@"
                            (function() {
                                var container = document.querySelector('dialog[open], [role=""dialog""], [aria-modal=""true""]');
                                if (!container) return 0;
                                return container.querySelectorAll('input, select, textarea, button').length;
                            })()
                        ");
                        if (modalInputCount < 2)
                        {
                            await WaitForDomStableMutationAsync();
                        }
                    }
                    catch (Exception)
                    {
                        // 二次检查失败不阻断
                    }

                    // Wait for interactive content in any frame owned by the modal navigation.
                    try
                    {
                        foreach (var frame in _page.Frames)
                        {
                            if (frame == _page.MainFrame) continue;
                            try
                            {
                                await frame.WaitForFunctionAsync(
                                    VisibleInteractiveCheckJs,
                                    new WaitForFunctionOptions { Timeout = _config.FrameStableMaxWaitMs, PollingInterval = _config.DomStablePollMs }
                                );
                            }
                            catch (Exception)
                            {
                                // 超时不阻断：frame 内可能确实无交互元素
                            }
                        }
                    }
                    catch (Exception)
                    {
                        // frame 遍历失败不阻断
                    }
                }
            }
            catch (Exception)
            {
                // 页面导航中或 DOM 不可访问时忽略，不阻断主流程
            }
        }

        // Standard CSS compatibility path. element_id targets resolve to a runtime-id selector.
        private async Task<IElementHandle> WaitForSelectorSafe(string selector, int? timeoutMs = null)
        {
            var effectiveTimeout = Math.Max(1, timeoutMs ?? _config.ElementTimeoutMs);
            var deadline = DateTime.UtcNow.AddMilliseconds(effectiveTimeout);
            do
            {
                try
                {
                    var remaining = Math.Max(1, (int)(deadline - DateTime.UtcNow).TotalMilliseconds);
                    var perFrameTimeout = Math.Min(remaining, Math.Max(50, _config.DomStablePollMs));
                    var mainElement = await TryWaitForSelectorInFrameAsync(_page!.MainFrame, selector, perFrameTimeout);
                    if (mainElement != null) return mainElement;

                    foreach (var frame in _page.Frames)
                    {
                        if (ReferenceEquals(frame, _page.MainFrame)) continue;
                        var frameElement = await TryWaitForSelectorInFrameAsync(frame, selector, perFrameTimeout);
                        if (frameElement != null) return frameElement;
                    }
                }
                catch (Exception ex) when (ex is TimeoutException || ex.GetType().Name.Contains("WaitTask"))
                {
                    // Retry until the shared deadline so one slow frame cannot consume the whole wait.
                }
                if (DateTime.UtcNow < deadline) await Task.Delay(Math.Min(_config.DomStablePollMs, 100));
            }
            while (DateTime.UtcNow < deadline);

            throw new InvalidOperationException($"element_not_found: 元素 {selector} 未找到");
        }

        // 在单个 frame 中尝试查找元素；返回 null 表示未找到（不抛异常，便于跨 frame 重试）
        private async Task<IElementHandle?> TryWaitForSelectorInFrameAsync(IFrame frame, string selector, int timeoutMs)
        {
            try
            {
                if (selector.StartsWith("[data-moonya-runtime-id=\"", StringComparison.Ordinal))
                {
                    var firstQuote = selector.IndexOf('\"');
                    var lastQuote = selector.LastIndexOf('\"');
                    var runtimeId = firstQuote >= 0 && lastQuote > firstQuote
                        ? selector.Substring(firstQuote + 1, lastQuote - firstQuote - 1)
                        : string.Empty;
                    if (runtimeId.Length > 0)
                    {
                        var handle = await frame.EvaluateFunctionHandleAsync(@"runtimeId => {
                            const find = root => {
                                if (!root || !root.querySelectorAll) return null;
                                const direct = root.querySelector('[data-moonya-runtime-id=""' + CSS.escape(runtimeId) + '""]');
                                if (direct) return direct;
                                for (const node of root.querySelectorAll('*')) {
                                    if (node.shadowRoot) {
                                        const nested = find(node.shadowRoot);
                                        if (nested) return nested;
                                    }
                                }
                                return null;
                            };
                            return find(document);
                        }", runtimeId);
                        if (handle is IElementHandle elementHandle) return elementHandle;
                        await handle.DisposeAsync();
                        return null;
                    }
                }

                // 标准 CSS 选择器：走 PuppeteerSharp 原生 WaitForSelectorAsync
                return await frame.WaitForSelectorAsync(selector, new WaitForSelectorOptions
                {
                    Timeout = timeoutMs,
                    Visible = true,
                });
            }
            catch
            {
                // 当前 frame 未找到或执行失败，返回 null 让上层尝试其他 frame
                return null;
            }
        }
    }
}
