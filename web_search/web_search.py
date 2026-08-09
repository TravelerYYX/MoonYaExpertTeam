import json
import urllib.request
import urllib.parse
import re
import html as html_mod
import os
from . import register

_SEARCH_CONFIG = {}

_SEARCH_ENVIRONMENT_KEYS = {
    "primary_endpoint": "MOONYA_SEARCH_PRIMARY_ENDPOINT",
    "fallback_one_url_template": "MOONYA_SEARCH_FALLBACK_ONE_URL_TEMPLATE",
    "fallback_two_url_template": "MOONYA_SEARCH_FALLBACK_TWO_URL_TEMPLATE",
    "user_agent": "MOONYA_SEARCH_USER_AGENT",
    "primary_timeout_seconds": "MOONYA_SEARCH_PRIMARY_TIMEOUT_SECONDS",
    "fallback_timeout_seconds": "MOONYA_SEARCH_FALLBACK_TIMEOUT_SECONDS",
    "fetch_timeout_seconds": "MOONYA_SEARCH_FETCH_TIMEOUT_SECONDS",
    "result_limit": "MOONYA_SEARCH_RESULT_LIMIT",
    "fetch_content_limit": "MOONYA_SEARCH_FETCH_CONTENT_LIMIT",
}


def resolve_search_config(component_config: dict) -> dict:
    """Apply environment overrides to the Python component configuration."""
    if not isinstance(component_config, dict):
        raise RuntimeError("missing required Python service configuration section: search")
    resolved = {}
    for key, environment_key in _SEARCH_ENVIRONMENT_KEYS.items():
        environment_value = os.environ.get(environment_key)
        resolved[key] = environment_value if environment_value not in (None, "") else component_config.get(key)
    return resolved


def configure_search(config: dict) -> None:
    """Install validated runtime search configuration for this process."""
    required_strings = ("primary_endpoint", "fallback_one_url_template", "fallback_two_url_template", "user_agent")
    missing = [key for key in required_strings if not str(config.get(key) or "").strip()]
    required_positive = ("primary_timeout_seconds", "fallback_timeout_seconds", "fetch_timeout_seconds", "result_limit", "fetch_content_limit")
    missing.extend(key for key in required_positive if int(config.get(key) or 0) < 1)
    if missing:
        raise RuntimeError("missing or invalid required search configuration: " + ", ".join(sorted(set(missing))))
    for key in ("primary_endpoint", "fallback_one_url_template", "fallback_two_url_template"):
        parsed = urllib.parse.urlparse(str(config[key]))
        if parsed.scheme not in ("http", "https") or not parsed.netloc:
            raise RuntimeError(f"search configuration {key} must be an absolute HTTP URL")
    global _SEARCH_CONFIG
    _SEARCH_CONFIG = dict(config)


def _search_config(key: str):
    if key not in _SEARCH_CONFIG:
        raise RuntimeError(f"search runtime configuration is not initialized: {key}")
    return _SEARCH_CONFIG[key]


@register(
    "web_search",
    "搜索互联网信息。用于查找最新新闻、事实或任何实时信息。",
    {"query": {"type": "string", "description": "搜索关键词"}}
)
def web_search(query: str) -> str:
    """Web search using the provider chain declared by service_config.json."""
    first_error = None
    # 首选 DuckDuckGo
    try:
        data = urllib.parse.urlencode({"q": query}).encode()
        req = urllib.request.Request(
            str(_search_config("primary_endpoint")),
            data=data,
            headers={
                "User-Agent": str(_search_config("user_agent"))
            }
        )
        with urllib.request.urlopen(req, timeout=int(_search_config("primary_timeout_seconds"))) as resp:
            html_content = resp.read().decode("utf-8", errors="ignore")

        results = []
        # DuckDuckGo HTML 版搜索结果结构：
        #   <div class="result">
        #     <h2 class="result__title">
        #       <a class="result__a" href="...">title</a>
        #     </h2>
        #     <a class="result__snippet">snippet</a>
        #   </div>
        for block in re.finditer(
            r'<div[^>]*class="result[^"]*"[^>]*>(.*?)</div>\s*(?:</div>)?',
            html_content, re.DOTALL
        ):
            block_html = block.group(1)

            # 提取标题 + 链接
            link_match = re.search(
                r'<a[^>]*class="result__a"[^>]*href="(https?://[^"]*)"[^>]*>(.*?)</a>',
                block_html, re.DOTALL
            )
            if not link_match:
                continue
            link_url = link_match.group(1)
            link_text = re.sub(r'<[^>]+>', '', link_match.group(2)).strip()

            # 提取摘要
            snippet_match = re.search(
                r'<a[^>]*class="result__snippet"[^>]*>(.*?)</a>',
                block_html, re.DOTALL
            )
            snippet = ""
            if snippet_match:
                snippet = re.sub(r'<[^>]+>', '', snippet_match.group(1)).strip()
                snippet = html_mod.unescape(snippet)

            results.append({
                "title": html_mod.unescape(link_text),
                "url": urllib.parse.unquote(link_url),
                "snippet": snippet
            })

        return json.dumps({"query": query, "results": results[:int(_search_config("result_limit"))]}, ensure_ascii=False)

    except Exception as e:
        first_error = e

    # Fallback 1: Baidu（国内最稳定）
    try:
        return _baidu_search(query)
    except Exception:
        pass

    # Fallback 2: Bing China
    try:
        return _bing_search_fallback(query)
    except Exception:
        pass

    return json.dumps({"error": f"Search failed: {str(first_error)}"})


def _baidu_search(query: str) -> str:
    """Fallback: Baidu HTML search (most reliable in Chinese network)."""
    encoded = urllib.parse.quote(query)
    url = str(_search_config("fallback_one_url_template")).format(query=encoded)
    req = urllib.request.Request(url, headers={
        "User-Agent": str(_search_config("user_agent")),
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8",
    })
    with urllib.request.urlopen(req, timeout=int(_search_config("fallback_timeout_seconds"))) as resp:
        html_content = resp.read().decode("utf-8", errors="ignore")

    results = []
    # Baidu 搜索结果结构（2024+ 新版）：
    #   <div class="result" id="N">
    #     <div class="c-container">
    #       <h3 class="t"><a href="url">title</a></h3>
    #       <div class="c-abstract">snippet</div>
    #     </div>
    #   </div>
    #   老版也可能直接是 <div class="c-container">...</div>
    for block in re.finditer(
        r'<div[^>]*(?:class="(?:result|c-container)[^"]*"|id="\d+")[^>]*>(.*?)</div>\s*(?:</div>)?',
        html_content, re.DOTALL
    ):
        block_html = block.group(1)

        # 提取标题 + 链接 (h3 > a)
        link_match = re.search(
            r'<h3[^>]*>.*?<a[^>]*href="(https?://[^"]*)"[^>]*>(.*?)</a>',
            block_html, re.DOTALL
        )
        if not link_match:
            continue
        link_url = link_match.group(1)
        link_text = re.sub(r'<[^>]+>', '', link_match.group(2)).strip()
        if not link_text:
            continue

        # 提取摘要：尝试多种可能的容器
        snippet = ""
        snippet_match = re.search(
            r'<div[^>]*class="c-abstract"[^>]*>(.*?)</div>',
            block_html, re.DOTALL
        )
        if not snippet_match:
            snippet_match = re.search(
                r'<span[^>]*class="content-right[^"]*"[^>]*>(.*?)</span>',
                block_html, re.DOTALL
            )
        if not snippet_match:
            # 兜底：取 h3 后的第一个 div 文本
            snippet_match = re.search(
                r'</h3>\s*<div[^>]*>(.*?)</div>',
                block_html, re.DOTALL
            )
        if snippet_match:
            snippet = re.sub(r'<[^>]+>', '', snippet_match.group(1)).strip()
            snippet = html_mod.unescape(snippet)

        results.append({
            "title": html_mod.unescape(link_text),
            "url": urllib.parse.unquote(link_url),
            "snippet": snippet,
        })

    return json.dumps({"query": query, "results": results[:int(_search_config("result_limit"))]}, ensure_ascii=False)


def _bing_search_fallback(query: str) -> str:
    """Fallback: Bing China HTML search."""
    encoded = urllib.parse.quote(query)
    url = str(_search_config("fallback_two_url_template")).format(query=encoded)
    req = urllib.request.Request(url, headers={
        "User-Agent": str(_search_config("user_agent"))
    })
    with urllib.request.urlopen(req, timeout=int(_search_config("fallback_timeout_seconds"))) as resp:
        html_content = resp.read().decode("utf-8", errors="ignore")

    results = []
    for block in re.finditer(
        r'<li class="b_algo"[^>]*>(.*?)</li>',
        html_content, re.DOTALL
    ):
        block_html = block.group(1)
        h2_match = re.search(
            r'<h2[^>]*>.*?<a[^>]*href="(http[^"]*)"[^>]*>(.*?)</a>.*?</h2>',
            block_html, re.DOTALL
        )
        if h2_match:
            link_url = h2_match.group(1)
            link_text = h2_match.group(2)
        else:
            link_match = re.search(
                r'<a[^>]*href="(http[^"]*)"[^>]*>(.*?)</a>',
                block_html, re.DOTALL
            )
            if not link_match:
                continue
            link_url = link_match.group(1)
            link_text = link_match.group(2)
        snippet_match = re.search(
            r'<p class="b_lineclamp[^"]*"[^>]*>(.*?)</p>',
            block_html, re.DOTALL
        )
        snippet = ""
        if snippet_match:
            snippet = re.sub(r'<[^>]+>', '', snippet_match.group(1)).strip()
            snippet = html_mod.unescape(snippet)
        results.append({
            "title": html_mod.unescape(re.sub(r'<[^>]+>', '', link_text).strip()),
            "url": urllib.parse.unquote(link_url),
            "snippet": snippet
        })

    return json.dumps({"query": query, "results": results[:int(_search_config("result_limit"))]}, ensure_ascii=False)


@register(
    "web_fetch",
    "抓取并阅读网页内容。用于阅读文章、文档或任何网页。",
    {"url": {"type": "string", "description": "要抓取和阅读的网址"}}
)
def web_fetch(url: str) -> str:
    try:
        req = urllib.request.Request(url, headers={
            "User-Agent": str(_search_config("user_agent"))
        })
        with urllib.request.urlopen(req, timeout=int(_search_config("fetch_timeout_seconds"))) as resp:
            content = resp.read().decode("utf-8", errors="ignore")

        # Strip HTML tags for readability
        text = re.sub(r'<script[^>]*>.*?</script>', '', content, flags=re.DOTALL | re.I)
        text = re.sub(r'<style[^>]*>.*?</style>', '', text, flags=re.DOTALL | re.I)
        text = re.sub(r'<[^>]+>', ' ', text)
        text = re.sub(r'\s+', ' ', text).strip()
        text = html_mod.unescape(text)

        content_limit = int(_search_config("fetch_content_limit"))
        if len(text) > content_limit:
            text = text[:content_limit] + "\n... [truncated]"

        return json.dumps({"url": url, "content": text}, ensure_ascii=False)
    except Exception as e:
        return json.dumps({"error": f"Fetch failed: {str(e)}"})
