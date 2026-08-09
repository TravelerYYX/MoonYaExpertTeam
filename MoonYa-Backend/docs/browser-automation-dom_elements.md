# 浏览器自动化 — dom_elements 元素采集机制

## 整体流程

```
[C# BrowserAutomationService]
    │
    ├── GetElementsAsync()                     ← API 入口
    │   ├── 提取主文档所有 <iframe> src/id      ← 诊断 + 跨域兜底用
    │   ├── 主 frame 采集 (CollectElementsJs)   ← 在浏览器中执行 JS
    │   ├── 子 frame 遍历 (page.Frames)        ← 最大重试 5 次
    │   │   ├── WaitForFunctionAsync(等待元素)  ← 等 iframe 动态内容渲染
    │   │   └── frame.EvaluateFunctionAsync()  ← 采集子 frame 内元素
    │   ├── 跨域 iframe 兜底                   ← 新开 page 加载采集
    │   └── 排序: in_modal > in_frame > 普通    ← 弹窗 > iframe > 其他
    │
    └── ParseElementsResult()                  ← 解析 JSON，标记 frame 属性
```

---

## 一、CollectElementsJs — 浏览器端执行的核心 JS 脚本

**文件**: [BrowserAutomationService.cs:53-289](file:///d:/Project/Project/MoonYa/MoonYa-Win/MoonYa-Solution/MoonYa/Services/BrowserAutomationService.cs#L53)

```javascript
() => {
    const elements = [];

    // =========================================================
    // 1. 选择器定义
    // =========================================================

    // 交互元素选择器（覆盖 Element UI / Layui / Ant Design 等框架）
    const selectors = [
        'input', 'button', 'a', 'select', 'textarea',
        '[role="button"]', '[role="link"]', '[role="checkbox"]', '[role="tab"]',
        '[contenteditable="true"]', '[onclick]', 'label',
        '.el-input__inner', '.el-textarea__inner', '.el-button',
        '.layui-btn', '.layui-form-select',
        '.ant-btn', '.ant-input',
        '[class*="btn"]', '[class*="input"]'
    ].join(', ');

    // 弹窗检测选择器
    const modalSelectors = [
        '[role="dialog"]',
        '.modal', '.modal-dialog', '.modal-content',
        '.el-dialog', '.el-dialog__wrapper', '.v-modal', '.el-message-box', '.el-drawer',
        '.ant-modal', '.ant-modal-root', '.ant-modal-wrap', '.ant-modal-content', '.ant-drawer',
        '.layui-layer', '.layui-layer-page', '.layui-layer-content', '.layui-layer-main',
        '.ivu-modal', '.ivu-modal-wrap',
        '.bt-form', '.bt-modal', '.bt-popup',
        '.el-popup-parent--hidden',
        '[class*="modal"]', '[class*="dialog"]', '[class*="popup"]', '[class*="layer"]', '[class*="drawer"]'
    ].join(', ');

    // =========================================================
    // 2. 辅助函数
    // =========================================================

    // 判断元素是否可见
    function isVisible(el) {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) return false;
        const style = (el.ownerDocument && el.ownerDocument.defaultView)
            ? el.ownerDocument.defaultView.getComputedStyle(el)
            : window.getComputedStyle(el);
        return !(style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0');
    }

    // 判断元素是否在弹窗/浮层内
    function isInModal(el, doc) {
        if (el.closest(modalSelectors)) return true;
        let node = el;
        while (node && node !== doc.body) {
            try {
                const nodeStyle = (node.ownerDocument && node.ownerDocument.defaultView)
                    ? node.ownerDocument.defaultView.getComputedStyle(node)
                    : window.getComputedStyle(node);
                if (nodeStyle && (nodeStyle.position === 'fixed' || nodeStyle.position === 'absolute')) {
                    const z = parseInt(nodeStyle.zIndex || '0', 10);
                    if (!isNaN(z) && z > 50) return true;
                }
            } catch (e) { }
            node = node.parentElement;
        }
        return false;
    }

    // 过滤框架生成的动态 ID（如 el-id-123-456）
    function isStableId(id) {
        if (!id) return false;
        if (/^el-id-\d+-\d+$/.test(id)) return false;
        if (/^el-\d+$/.test(id)) return false;
        if (/^app-\d+$/.test(id)) return false;
        if (/^\d+$/.test(id)) return false;
        if (id.length < 2) return false;
        return true;
    }

    // =========================================================
    // 3. CSS 选择器生成（优先级）
    // =========================================================
    //   1. input[name='xxx']      → name 属性（表单元素优先）
    //   2. #id                    → 稳定的 id
    //   3. input[name='xxx']      → name 属性（通用）
    //   4. [data-testid='xxx']    → 测试 ID
    //   5. input[placeholder='']  → placeholder
    //   6. input.class1.class2    → class 组合（过滤 is-/el-icon 等状态类）
    //   7. tag:nth-of-type(n)     → 父元素+标签路径
    //   8. tag                    → 兜底

    function buildSelector(el) {
        const tagLower = el.tagName.toLowerCase();

        // ① 表单元素优先 name 属性
        if ((tagLower === 'input' || tagLower === 'textarea' || tagLower === 'select') && el.name) {
            return tagLower + '[name="' + CSS.escape(el.name) + '"]';
        }
        // ② 稳定的 id
        if (isStableId(el.id)) {
            return '#' + CSS.escape(el.id);
        }
        // ③ name 属性（通用）
        if (el.name) {
            return tagLower + '[name="' + CSS.escape(el.name) + '"]';
        }
        // ④ data-testid
        if (el.getAttribute('data-testid')) {
            return '[data-testid="' + CSS.escape(el.getAttribute('data-testid')) + '"]';
        }
        // ⑤ placeholder（仅 input/textarea）
        if (el.placeholder && (tagLower === 'input' || tagLower === 'textarea')) {
            return tagLower + '[placeholder="' + CSS.escape(el.placeholder) + '"]';
        }
        // ⑥ class 组合
        if (el.className && typeof el.className === 'string' && el.className.trim()) {
            const classes = el.className.trim().split(/\s+/);
            const meaningful = classes.filter(c =>
                c.length >= 2 && !/^is-/.test(c) && !/^el-icon/.test(c) && !/^fa-/.test(c) && !/^icon-/.test(c)
            ).slice(0, 2);
            if (meaningful.length > 0) {
                return tagLower + meaningful.map(c => '.' + CSS.escape(c)).join('');
            }
        }
        // ⑦ 父元素+标签路径
        const parent = el.parentNode;
        if (parent) {
            const siblings = Array.from(parent.children).filter(s => s.tagName === el.tagName);
            if (siblings.length > 1) {
                return tagLower + ':nth-of-type(' + (siblings.indexOf(el) + 1) + ')';
            }
        }
        // ⑧ 兜底
        return tagLower;
    }

    // =========================================================
    // 4. 从指定 document 采集元素
    // =========================================================

    function collectFromDoc(doc) {
        const nodes = doc.querySelectorAll(selectors);
        // ★ 分两遍采集：先弹窗内元素，后弹窗外元素
        //   确保弹窗内元素不被 PerDocumentElementLimit(500) 截断
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

        function processEl(el) {
            if (elements.length >= 500) return;  // PerDocumentElementLimit
            const rect = el.getBoundingClientRect();
            const tagLower = el.tagName.toLowerCase();
            let cssSelector = buildSelector(el);

            // ★ 对 <a> 标签，用 pathname 关键词增强选择器
            if (tagLower === 'a' && el.href && el.href !== '#' && !el.href.startsWith('javascript:')) {
                try {
                    var hrefPath = new URL(el.href).pathname;
                    var seg = hrefPath.split('/').filter(function(s) { return s.length >= 2; })[0];
                    if (seg) cssSelector += '[href*="' + seg + '"]';
                } catch (e) { }
            }

            // ★ 提取元素文本
            let text = '';
            if (el.innerText && el.innerText.trim()) {
                text = el.innerText.trim();
            } else if (el.textContent && el.textContent.trim()) {
                text = el.textContent.trim();
            } else {
                text = (el.value || el.placeholder || el.getAttribute('aria-label') || el.title || '').trim();
            }
            // 输入框尝试找关联 label
            if (!text && (tagLower === 'input' || tagLower === 'textarea' || tagLower === 'select')) {
                const labelledBy = el.getAttribute('aria-labelledby');
                if (labelledBy) {
                    const labelEl = doc.getElementById(labelledBy);
                    if (labelEl) text = (labelEl.innerText || labelEl.textContent || '').trim();
                }
                if (!text && el.id) {
                    const labels = doc.querySelectorAll('label[for="' + CSS.escape(el.id) + '"]');
                    if (labels.length > 0) text = (labels[0].innerText || labels[0].textContent || '').trim();
                }
                if (!text) {
                    const prevLabel = el.parentElement && el.parentElement.tagName.toLowerCase() === 'label' ? el.parentElement : null;
                    if (prevLabel) text = (prevLabel.innerText || prevLabel.textContent || '').trim();
                }
            }

            elements.push({
                tag:         tagLower,
                type:        el.type || el.getAttribute('role') || '',
                css_selector: cssSelector,
                text:        text.slice(0, 120),
                name:        el.name || '',
                id:          el.id || '',
                placeholder: el.placeholder || '',
                href:        el.href || '',
                value:       (el.value || '').toString().slice(0, 80),
                position:    { x: Math.round(rect.x), y: Math.round(rect.y), w: Math.round(rect.width), h: Math.round(rect.height) },
                disabled:    el.disabled || false,
                visible:     true,
                in_modal:    isInModal(el, doc),
                in_iframe:   false            // 后续由 C# 端标记
            });
        }

        modalEls.forEach(processEl);   // 优先
        otherEls.forEach(processEl);
    }

    // =========================================================
    // 5. 采集执行
    // =========================================================

    collectFromDoc(document);          // 主文档
    walkShadow(document);              // 穿透 shadow DOM
    collectIframeContentDocument();    // 同源 iframe 兜底

    // 提取全页面可见文本
    const pageText = extractVisibleText();

    return JSON.stringify({ elements: elements, page_text: pageText });
}
```

---

## 二、GetElementsAsync — C# 端入口

**文件**: [BrowserAutomationService.cs:447-660](file:///d:/Project/Project/MoonYa/MoonYa-Win/MoonYa-Solution/MoonYa/Services/BrowserAutomationService.cs#L447)

```csharp
/// <summary>
/// 获取页面所有可交互元素的 DOM 信息（100% 准确的 CSS 选择器，不依赖视觉猜测）
/// 遍历 page.Frames，采集跨域 iframe 内元素，标记 frame_url/frame_name
/// </summary>
public async Task<string> GetElementsAsync()
{
    EnsureStarted();
    var allElements   = new List<Dictionary<string, object>>();
    var pageTextParts = new List<string>();
    var diagLog       = new List<string>();

    // ── 步骤 0：从主文档提取所有 <iframe> 的 src/id（用于诊断 + 兜底） ──
    var iframesInDom = new List<(string src, string id)>();
    try
    {
        var iframeJson = await _page!.EvaluateExpressionAsync<string>(@"
            JSON.stringify(Array.from(document.querySelectorAll('iframe, frame')).map(function(f) {
                return { src: f.src || '', id: f.id || '' };
            }))
        ");
        // 解析并排除 about:blank
    }
    catch (Exception) { }

    // ── 步骤 1：主 frame 采集 ──
    var mainResult = await _page!.EvaluateFunctionAsync<string>(CollectElementsJs);
    ParseElementsResult(mainResult, allElements, pageTextParts,
        isFrame: false, frameUrl: string.Empty, frameName: string.Empty);

    // ── 步骤 2：检测是否有子 frame ──
    bool hasChildFrames = _page.Frames.Any(f => f != _page.MainFrame);

    // ── 步骤 3：遍历 page.Frames，重试采集（最多 5 次） ──
    int frameRetryCount = 0;
    const int maxFrameRetries = 5;
    bool collectedFromAnyFrame = false;
    var collectedFrameUrls = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

    do
    {
        frameRetryCount++;
        int framesBefore = allElements.Count(e => GetBool(e, "in_frame"));

        foreach (var frame in _page.Frames)
        {
            if (frame == _page.MainFrame) continue;
            var frameUrl = frame.Url ?? string.Empty;
            try
            {
                // ★ 关键：先等待 frame 内有交互元素就绪，最多 5 秒
                //   宝塔弹窗 iframe 是 about:blank 动态创建，内容异步渲染
                try
                {
                    await frame.WaitForFunctionAsync(
                        "() => document.querySelectorAll('input, button, select, textarea, a').length > 0",
                        new WaitForFunctionOptions { Timeout = 5000, PollingInterval = 200 }
                    );
                }
                catch (Exception) { }  // 超时不阻断

                int beforeCount = allElements.Count;
                var frameResult = await frame.EvaluateFunctionAsync<string>(CollectElementsJs);
                if (!string.IsNullOrWhiteSpace(frameResult) && frameResult != "null")
                {
                    ParseElementsResult(frameResult, allElements, pageTextParts,
                        isFrame: true, frameUrl, frameName);
                    // ★ 只有真正采集到新元素才标记
                    if (allElements.Count > beforeCount) collectedFromAnyFrame = true;
                    if (!string.IsNullOrWhiteSpace(frameUrl)) collectedFrameUrls.Add(frameUrl);
                }
            }
            catch (Exception ex)
            {
                diagLog.Add($"Frame[{frameUrl}] attempt#{frameRetryCount}: {ex.Message}");
            }
        }

        int framesAfter = allElements.Count(e => GetBool(e, "in_frame"));
        if (framesAfter > framesBefore) break;  // 采集到元素 → 成功，退出
        if (!hasChildFrames) break;              // 没有子 frame → 退出

        if (frameRetryCount < maxFrameRetries)
        {
            await Task.Delay(1500);
            try { await WaitForNetworkIdleSafeAsync(); await WaitForDomStableAsync(); } catch (Exception) { }
        }
    }
    while (frameRetryCount < maxFrameRetries);

    // ── 步骤 4：跨域/未就绪 iframe 兜底 ──
    // 找到前序未采集的 iframe，在新 page 中打开采集
    if (iframesInDom.Count > 0)
    {
        var uncaptured = iframesInDom
            .Where(ifr => !collectedFrameUrls.Contains(ifr.src) && !string.IsNullOrWhiteSpace(ifr.src))
            .ToList();
        foreach (var (src, id) in uncaptured)
        {
            using var newPage = await _browser.NewPageAsync();
            await newPage.GoToAsync(src, new NavigationOptions { WaitUntil = ..., Timeout = 15000 });
            var frameContent = await newPage.EvaluateFunctionAsync<string>(CollectElementsJs);
            ParseElementsResult(frameContent, allElements, pageTextParts,
                isFrame: true, frameUrl: src, frameName: id);
        }
    }

    // ── 步骤 5：写入诊断日志 ──
    // （详见 debug-ba-frame-diag.log）

    // ── 步骤 6：全局排序 ──
    var sortedElements = allElements
        .OrderByDescending(el => GetBool(el, "in_modal"))   // 弹窗内元素优先
        .ThenByDescending(el => GetBool(el, "in_frame"))   // iframe 内元素次之
        .Take(MaxReturnedElements)                         // 上限 120 个
        .ToList();

    // 最终输出
    return JsonSerializer.Serialize(new
    {
        elements  = sortedElements,
        page_text = combinedPageText
    }, new JsonSerializerOptions { PropertyNamingPolicy = JsonNamingPolicy.CamelCase });
}
```

---

## 三、ParseElementsResult — 解析与标记

**文件**: [BrowserAutomationService.cs:645-689](file:///d:/Project/Project/MoonYa/MoonYa-Win/MoonYa-Solution/MoonYa/Services/BrowserAutomationService.cs#L645)

```csharp
private void ParseElementsResult(
    string json,
    List<Dictionary<string, object>> allElements,
    List<string> pageTextParts,
    bool isFrame,
    string frameUrl,
    string frameName)
{
    if (string.IsNullOrWhiteSpace(json)) return;

    var data = JsonSerializer.Deserialize<Dictionary<string, object>>(json);
    if (data == null) return;

    // 提取 page_text
    if (data.TryGetValue("page_text", out var ptObj) && ptObj is JsonElement ptJson && ptJson.ValueKind == JsonValueKind.String)
    {
        var text = ptJson.GetString() ?? string.Empty;
        if (!string.IsNullOrWhiteSpace(text))
        {
            if (isFrame)
                pageTextParts.Add($"[FRAME {frameUrl}] {text}");  // iframe 文本标记来源
            else
                pageTextParts.Add(text);
        }
    }

    // 提取 elements，标记 frame 属性
    if (data.TryGetValue("elements", out var elObj) && elObj is JsonElement elJson && elJson.ValueKind == JsonValueKind.Array)
    {
        foreach (var el in elJson.EnumerateArray())
        {
            var elDict = el.Deserialize<Dictionary<string, object>>();
            if (elDict == null) continue;

            // ★ 标记来源 frame（JS 脚本中无法获取这些信息，由 C# 端注入）
            elDict["in_frame"]   = isFrame;        // bool: 是否来自 iframe
            elDict["frame_url"]  = frameUrl;        // string: iframe 的 URL
            elDict["frame_name"] = frameName;       // string: iframe 的 name

            // ★ 兼容旧字段
            if (isFrame) elDict["in_iframe"] = true;

            allElements.Add(elDict);
        }
    }
}
```

---

## 四、输出数据结构

C# 端最终输出给 api.php，再返回给 AI 的完整格式：

```json
{
  "elements": [
    {
      "tag":          "input",
      "type":         "text",
      "css_selector": "input[name='domain']",
      "text":         "域名",
      "name":         "domain",
      "id":           "",
      "placeholder":  "请输入域名",
      "href":         "",
      "value":        "",
      "position":     { "x": 540, "y": 280, "w": 300, "h": 36 },
      "disabled":     false,
      "visible":      true,
      "in_modal":     true,
      "in_iframe":    false,
      "in_frame":     false,
      "frame_url":    "",
      "frame_name":   ""
    },
    {
      "tag":          "input",
      "type":         "text",
      "css_selector": "input[name='domain']",
      "text":         "域名",
      "name":         "domain",
      "id":           "",
      "placeholder":  "请输入域名",
      "position":     { "x": 0, "y": 0, "w": 300, "h": 36 },
      "visible":      true,
      "in_modal":     true,
      "in_iframe":    true,
      "in_frame":     true,                    // ← 标记来自 iframe
      "frame_url":    "https://example.com/iframe-content",
      "frame_name":   "mainIframe"
    }
  ],
  "page_text": "[FRAME https://example.com/iframe-content] 域名 创建站点 确定 取消 ..."
}
```

---

## 五、关键字段说明

| 字段 | 类型 | 说明 |
|------|------|------|
| `tag` | string | HTML 标签名（input/button/a/select/textarea/...） |
| `css_selector` | string | 100% 准确的 CSS 选择器（AI 直接使用来 click/fill） |
| `text` | string | 元素可见文本（按钮文字/输入框关联 label） |
| `name` | string | 表单元素的 name 属性 |
| `placeholder` | string | 输入框的 placeholder |
| `position` | object | `{x, y, w, h}` 元素在视口中的位置和大小 |
| `visible` | bool | 是否可见（过滤了 display:none/visibility:hidden/opacity:0） |
| `disabled` | bool | 是否禁用 |
| `in_modal` | bool | **是否在弹窗/浮层内**（AI 优先操作） |
| `in_frame` | bool | **是否在 iframe 内**（由 C# 端注入） |
| `in_iframe` | bool | 兼容旧字段，同 in_frame |
| `frame_url` | string | iframe 的 URL（空字符串表示在主文档） |
| `frame_name` | string | iframe 的 name 属性 |

---

## 六、采集优先级规则

1. **弹窗内元素优先**（`in_modal=true` 排在前面）
2. **iframe 内元素次之**（`in_frame=true` 排在中间）
3. **普通元素最后**（其余排在后面）
4. **上限 120 个**（`MaxReturnedElements=120`）

## 七、关键修复记录

### 2026-07-05: iframe 动态内容采集修复
- **问题**：宝塔面板"添加站点"弹窗使用 `about:blank` 动态 iframe，原代码在 frame 返回空 elements 时错误标记 `collectedFromAnyFrame=true` 并终止重试
- **修复**：
  - 采集前先 `WaitForFunctionAsync` 等待 frame 内有交互元素
  - `collectedFromAnyFrame` 只在真正采集到元素时设为 true
  - 移除 `if (!hasDomIframes) break;`（主文档可能找不到弹窗内 iframe）
  - 重试次数从 3 → 5

### 2026-07-05: 弹窗等待增强
- 在 `WaitForModalContentAsync` 中增加检测弹窗后等待子 frame 元素就绪
