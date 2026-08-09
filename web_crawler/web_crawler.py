import asyncio, os, re, json, sys, base64, zlib, time
from pathlib import Path
from urllib.parse import urljoin, urlparse, urldefrag
from hashlib import md5
from datetime import datetime
from typing import Callable, Awaitable

import aiohttp
from crawl4ai import AsyncWebCrawler, BrowserConfig, CrawlerRunConfig

EXT_MAP = {
    "text/css": ".css", "text/javascript": ".js",
    "application/javascript": ".js", "application/x-javascript": ".js",
    "image/png": ".png", "image/jpeg": ".jpg", "image/webp": ".webp",
    "image/svg+xml": ".svg", "image/gif": ".gif",
    "image/x-icon": ".ico", "image/vnd.microsoft.icon": ".ico",
    "font/woff": ".woff", "font/woff2": ".woff2", "font/ttf": ".ttf", "font/otf": ".otf",
    "video/mp4": ".mp4", "video/webm": ".webm",
    "audio/mpeg": ".mp3", "audio/ogg": ".ogg", "audio/wav": ".wav",
}
_IMG = (".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".ico")
_FONT = (".woff", ".woff2", ".ttf", ".otf", ".eot")
_SRC_RE = re.compile(r'src\s*=\s*"([^"]+)"', re.IGNORECASE)
_SRC1_RE = re.compile(r"src\s*=\s*'([^']+)'", re.IGNORECASE)
_HREF_RE = re.compile(r'href\s*=\s*"([^"]+)"', re.IGNORECASE)
_HREF1_RE = re.compile(r"href\s*=\s*'([^']+)'", re.IGNORECASE)
_URL_DQ = re.compile(r'url\s*\(\s*"([^")]+)"\s*\)', re.IGNORECASE)
_URL_SQ = re.compile(r"url\s*\(\s*'([^')]+)'\s*\)", re.IGNORECASE)
_URL_NQ = re.compile(r'url\s*\(\s*([^)"\']+?)\s*\)', re.IGNORECASE)
_SRCSET_RE = re.compile(r'srcset\s*=\s*"([^"]+)"', re.IGNORECASE)
_SRCSET1_RE = re.compile(r"srcset\s*=\s*'([^']+)'", re.IGNORECASE)
_POSTER_RE = re.compile(r'poster\s*=\s*"([^"]+)"', re.IGNORECASE)
_POSTER1_RE = re.compile(r"poster\s*=\s*'([^']+)'", re.IGNORECASE)
# JS 内字符串形式的路径
_JS_PATH_RE = re.compile(r'["\']([^"\']+\.(?:js|css|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf|otf|mp4|webm|mp3|wav|ogg)[^"\']*?)["\']', re.IGNORECASE)

def classify(url: str, ct: str = "") -> str:
    ul = url.lower(); c = ct.split(";")[0].strip().lower()
    if "javascript" in c or "ecmascript" in c or ul.endswith(".js"): return "js"
    if "css" in c or ul.endswith(".css"): return "css"
    if "image" in c or "icon" in c or ul.endswith(_IMG): return "img"
    if "font" in c or ul.endswith(_FONT): return "font"
    if "video" in c or ul.endswith((".mp4",".webm")): return "media"
    if "audio" in c or "mpeg" in c or ul.endswith((".mp3",".wav",".ogg")): return "media"
    if ul.endswith(".js"): return "js"
    if ul.endswith(".css"): return "css"
    if ul.endswith(_IMG): return "img"
    if ul.endswith(_FONT): return "font"
    return "other"

def guess_ext(ct: str, url: str) -> str:
    c = ct.split(";")[0].strip().lower(); e = EXT_MAP.get(c)
    return e or os.path.splitext(urlparse(url).path)[1] or ".bin"

def _try_beautify(raw: bytes, ct: str) -> bytes:
    c = ct.split(";")[0].strip().lower(); is_js = "javascript" in c or "ecmascript" in c
    try:
        import jsbeautifier; t = raw.decode("utf-8","replace")
        o = jsbeautifier.default_options(); o.indent_size = 2
        o.unescape_strings = True; o.jslint_happy = is_js
        return jsbeautifier.beautify(t, o).encode("utf-8")
    except: return raw

def _try_decode(raw: bytes) -> bytes:
    t = raw.decode("utf-8","replace").strip()
    if re.match(r'^[A-Za-z0-9+/=]+$',t) and len(t)>20 and len(t)%4==0:
        try: d=base64.b64decode(t,validate=True)
        except: pass
        else:
            if b"function" in d or b"var " in d: return d
    if re.match(r'^[0-9A-Fa-f]+$',t) and len(t)%2==0 and len(t)>20:
        try: d=bytes.fromhex(t)
        except: pass
        else:
            if b"function" in d or b"var " in d: return d
    if len(raw)>=2 and raw[0]==0x78:
        try: return zlib.decompress(raw)
        except: pass
    return raw

def _extract_raw_urls(text: str) -> list[tuple[str, str]]:
    """从文本中提取所有资源引用，返回 [(原始字符串, 匹配内容), ...]"""
    pairs: list[tuple[str, str]] = []
    for m in _SRC_RE.finditer(text): pairs.append((m.group(0), m.group(1)))
    for m in _SRC1_RE.finditer(text): pairs.append((m.group(0), m.group(1)))
    for m in _HREF_RE.finditer(text): pairs.append((m.group(0), m.group(1)))
    for m in _HREF1_RE.finditer(text): pairs.append((m.group(0), m.group(1)))
    for m in _URL_DQ.finditer(text): pairs.append((m.group(0), m.group(1)))
    for m in _URL_SQ.finditer(text): pairs.append((m.group(0), m.group(1)))
    for m in _URL_NQ.finditer(text): pairs.append((m.group(0), m.group(1)))
    for m in _SRCSET_RE.finditer(text):
        for p in m.group(1).split(","):
            u = p.strip().split(" ")[0].strip()
            if u: pairs.append((u, u))
    for m in _SRCSET1_RE.finditer(text):
        for p in m.group(1).split(","):
            u = p.strip().split(" ")[0].strip()
            if u: pairs.append((u, u))
    for m in _POSTER_RE.finditer(text): pairs.append((m.group(0), m.group(1)))
    for m in _POSTER1_RE.finditer(text): pairs.append((m.group(0), m.group(1)))
    for m in _JS_PATH_RE.finditer(text):
        val = m.group(1)
        if classify(val, "") != "other":
            pairs.append((m.group(0), val))
    return pairs


class MoonYaPachong:
    def __init__(self, url: str, out_dir: str = "saved_pages",
                 progress_callback: Callable[[str, str, int, int, float], Awaitable[None]] | None = None):
        self.url = url
        self.base_parsed = urlparse(url)
        self.out = Path(out_dir)
        self._local: dict[str, str] = {}   # 绝对URL → 本地路径
        self._replace: dict[str, str] = {} # HTML中的原始形式 → 本地路径
        self._bodies: dict[str, bytes] = {}
        self._content_types: dict[str, str] = {}
        self._failed: list[str] = []       # 下载失败的 URL
        self._progress = progress_callback
        self._start_time: float = 0
        self._pd: Path | None = None       # 在 run() 中初始化一次，避免时间戳竞态

    def _url_forms(self, raw_text: str, base_url: str) -> str | None:
        """将HTML中的原始URL文本解析为绝对URL。跳过 data:/#/blob: 等。"""
        u = raw_text.strip()
        if not u or u.startswith("data:") or u.startswith("#") or u.startswith("blob:") or \
           u.startswith("chrome-extension:") or u.startswith("javascript:"):
            return None
        try:
            full, _ = urldefrag(urljoin(base_url, u))
            if urlparse(full).netloc:
                return full
        except:
            pass
        return None

    # ===== 收集网络捕获URL =====

    def _collect_net_urls(self, net_reqs: list[dict] | None) -> set[str]:
        urls: set[str] = set()
        if not net_reqs: return urls
        for e in net_reqs:
            u = e.get("url","")
            if not u: continue
            u, _ = urldefrag(u)
            if e.get("event_type") == "response" and e.get("status") == 200:
                body = e.get("body", {})
                text = body.get("text") if isinstance(body, dict) else None
                if text:
                    self._bodies[u] = text.encode("utf-8","replace") if isinstance(text,str) else text
                hdrs = e.get("headers", {})
                ct = hdrs.get("content-type","") or hdrs.get("Content-Type","")
                if ct: self._content_types[u] = ct
                urls.add(u)
            elif e.get("event_type") in ("request_failed","response_capture_error"):
                urls.add(u)
        return urls

    # ===== 下载单个资源 =====

    async def _download(self, session: aiohttp.ClientSession, full_url: str,
                         idx: int = 0, total_count: int = 0) -> str | None:
        """下载并保存，返回本地路径。idx/total_count 用于进度回调。"""
        if full_url in self._local:
            return self._local[full_url]

        raw = self._bodies.get(full_url)
        content_type = self._content_types.get(full_url, "")

        if raw is None:
            try:
                async with session.get(full_url, timeout=aiohttp.ClientTimeout(total=20)) as r:
                    if r.status != 200:
                        self._failed.append(full_url)
                        return None
                    content_type = r.headers.get("Content-Type", "")
                    raw = await r.read()
            except Exception:
                self._failed.append(full_url)
                return None

        rtype = classify(full_url, content_type)
        if rtype == "other" and "text/html" in content_type:
            return None

        ext = guess_ext(content_type, full_url)
        sub = self._pd / rtype; sub.mkdir(parents=True, exist_ok=True)
        name = md5(full_url.encode()).hexdigest()[:10] + ext
        local = f"{rtype}/{name}"
        fp = self._pd / local

        if rtype in ("js","css"):
            d = _try_decode(raw); b = _try_beautify(d, content_type)
            fp.write_bytes(b)
            if len(b) != len(raw) and len(raw) > 100:
                (self._pd / f"{local}.raw").write_bytes(raw)
        else:
            fp.write_bytes(raw)

        self._local[full_url] = local

        # 进度回调
        if self._progress and idx > 0:
            elapsed = time.time() - self._start_time
            short = urlparse(full_url).path.rsplit("/", 1)[-1] or full_url[:60]
            await self._progress("下载资源", short, idx, total_count, elapsed)

        return local

    # ===== 重写文本：多形式替换 =====

    def _replace_all(self, text: str) -> str:
        """用 _replace 映射替换文本中所有URL形式，按原始文本长度降序避免误伤。"""
        for orig, local in sorted(self._replace.items(), key=lambda x: -len(x[0])):
            text = text.replace(orig, local)
        return text

    # ===== 主流程 =====

    async def run(self):
        self._start_time = time.time()
        # 计算输出目录（仅一次，避免时间戳竞态）
        p = urlparse(self.url)
        h = md5((p.netloc + p.path.rstrip("/")).encode()).hexdigest()[:12]
        ts = datetime.now().strftime("%Y%m%d_%H%M%S")
        self._pd = self.out / f"{h}_{ts}"
        self._pd.mkdir(parents=True, exist_ok=True)
        bc = BrowserConfig(headless=True, verbose=False)
        rc = CrawlerRunConfig(capture_mhtml=True, capture_network_requests=True,
                              delay_before_return_html=5.0,
                              wait_for="js:()=>document.readyState==='complete'")
        print(f"🌐 爬取: {self.url}")

        # 阶段1: 加载页面
        elapsed = time.time() - self._start_time
        if self._progress:
            await self._progress("加载页面", "正在加载页面…", 0, 0, elapsed)

        async with AsyncWebCrawler(config=bc) as c:
            res = await c.arun(url=self.url, config=rc)

        if not res.success:
            print(f"❌ 失败: {res.error_message}"); return

        html = res.html

        # ---- 步骤1: 从HTML提取所有(raw_text, url_value)对 ----
        raw_pairs: list[tuple[str, str]] = _extract_raw_urls(html)
        if res.cleaned_html:
            raw_pairs += _extract_raw_urls(res.cleaned_html)

        # ---- 步骤2: 建立 绝对URL 集合 ----
        abs_urls: set[str] = set()
        net_abs = self._collect_net_urls(res.network_requests)
        abs_urls |= net_abs

        for raw, val in raw_pairs:
            abs_u = self._url_forms(val, self.url)
            if abs_u:
                abs_urls.add(abs_u)

        abs_list = list(abs_urls)
        net_bodies = len(self._bodies)
        total_count = len(abs_list)
        print(f"\n📦 发现 {total_count} 个资源 ({net_bodies} 个已有响应体)")

        # 进度: 发现资源
        elapsed = time.time() - self._start_time
        if self._progress:
            await self._progress("发现资源", f"发现 {total_count} 个资源", total_count, total_count, elapsed)

        # ---- 步骤3: 统一下载 ----
        if abs_list:
            conn = aiohttp.TCPConnector(limit=20, limit_per_host=10)
            async with aiohttp.ClientSession(connector=conn) as s:
                tasks = [self._download(s, u, i + 1, total_count) for i, u in enumerate(abs_list)]
                await asyncio.gather(*tasks)

        # 计算下载完成后的计数
        cnt = {"js":0,"css":0,"img":0,"font":0,"media":0,"other":0}
        for v in self._local.values():
            for k in cnt:
                if v.startswith(k+"/"): cnt[k] += 1; break
        downloaded = sum(cnt.values())
        elapsed = time.time() - self._start_time
        if self._progress:
            await self._progress("下载完成", f"已下载 {downloaded} 个资源", downloaded, total_count, elapsed)

        # ---- 步骤4: CSS文件二次解析 url() ----
        css_files = [(k, v) for k, v in self._local.items() if classify(k, "") in ("css",)]
        css_extra = 0
        if css_files:
            elapsed = time.time() - self._start_time
            if self._progress:
                await self._progress("CSS解析", f"解析 {len(css_files)} 个CSS文件中的资源", 0, 0, elapsed)
            conn = aiohttp.TCPConnector(limit=20, limit_per_host=10)
            async with aiohttp.ClientSession(connector=conn) as s:
                for css_url, local_rel in css_files:
                    fp = self._pd / local_rel
                    if not fp.exists(): continue
                    css_text = fp.read_text("utf-8","replace")
                    css_pairs = _extract_raw_urls(css_text)
                    css_to_dl: set[str] = set()
                    for raw, val in css_pairs:
                        abs_u = self._url_forms(val, css_url)
                        if abs_u and abs_u not in self._local:
                            css_to_dl.add(abs_u)
                    if css_to_dl:
                        tasks = [self._download(s, u) for u in css_to_dl]
                        rrs = await asyncio.gather(*tasks)
                        css_extra += sum(1 for r in rrs if r)

        # ---- 步骤5: 构建 _replace 映射（核心！多形式） ----
        for abs_url, local_path in self._local.items():
            self._replace[abs_url] = local_path
            parsed = urlparse(abs_url)
            proto_rel = f"//{parsed.netloc}{parsed.path}"
            self._replace[proto_rel] = local_path
            if parsed.path:
                self._replace[parsed.path] = local_path
                if parsed.query:
                    self._replace[f"{parsed.path}?{parsed.query}"] = local_path

        for raw_text, val in raw_pairs:
            abs_u = self._url_forms(val, self.url)
            if abs_u and abs_u in self._local:
                self._replace[val] = self._local[abs_u]
                if raw_text != val and val in raw_text:
                    self._replace[raw_text] = raw_text.replace(val, self._local[abs_u])

        # 进度: 重写链接
        elapsed = time.time() - self._start_time
        if self._progress:
            await self._progress("重写链接", "正在重写HTML/CSS中的链接…", 0, 0, elapsed)

        # ---- 步骤6: 重写HTML ----
        (self._pd / "index_original.html").write_text(html, encoding="utf-8")
        rewritten = self._replace_all(html)
        (self._pd / "index.html").write_text(rewritten, encoding="utf-8")

        # ---- 步骤7: 重写CSS ----
        for css_url, local_rel in css_files:
            fp = self._pd / local_rel
            if not fp.exists(): continue
            orig = fp.read_text("utf-8","replace")
            rw = self._replace_all(orig)
            if rw != orig: fp.write_text(rw, encoding="utf-8")

        # ---- 统计 ----
        cnt = {"js":0,"css":0,"img":0,"font":0,"media":0,"other":0}
        for v in self._local.values():
            for k in cnt:
                if v.startswith(k+"/"): cnt[k] += 1; break
        total = sum(cnt.values())

        # 保存元数据
        (self._pd / "output.md").write_text(str(res.markdown), encoding="utf-8")
        if res.mhtml: (self._pd / "page.mhtml").write_text(res.mhtml, encoding="utf-8")
        for fn, data in [("media.json",res.media),("links.json",res.links),
                          ("metadata.json",{"url":res.url,"status":res.status_code,
                           "redirect":res.redirected_url})]:
            (self._pd / fn).write_text(json.dumps(data,ensure_ascii=False,indent=2,default=str),encoding="utf-8")
        if res.network_requests:
            (self._pd / "network_requests.json").write_text(
                json.dumps(res.network_requests[:500],ensure_ascii=False,indent=2,default=str),encoding="utf-8")

        manifest = {"page_url":self.url,"urls_mapped":len(self._local),
            "replace_entries":len(self._replace),
            "by_type":cnt,"total":total,"css_extra":css_extra,
            "failed_count":len(self._failed),"failed_urls":self._failed[:50],
            "elapsed_seconds": round(time.time() - self._start_time, 1)}
        (self._pd / "manifest.json").write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding="utf-8")

        print(f"\n{'='*50}")
        print(f"✅ 完成 → {self._pd.absolute()}")
        print(f"{'='*50}")
        print(f"  index.html            — 本地化（可直接浏览器打开）")
        print(f"  index_original.html   — 原始备份")
        print(f"  js/      {cnt['js']}   css/    {cnt['css']}")
        print(f"  img/     {cnt['img']}   font/   {cnt['font']}")
        print(f"  media/   {cnt['media']}   other/  {cnt['other']}")
        print(f"  📂 {total} 个资源  |  🔗 {len(self._replace)} 处URL已重写")
        if self._failed:
            print(f"  ⚠️  {len(self._failed)} 个资源下载失败")
        print(f"{'='*50}")


async def main():
    if len(sys.argv) > 1: url = sys.argv[1]
    else: url = input("网址: ").strip()
    if not url: print("❌ 未提供网址"); return
    if not url.startswith("http"): url = "https://" + url
    await MoonYaPachong(url).run()

if __name__ == "__main__":
    asyncio.run(main())
