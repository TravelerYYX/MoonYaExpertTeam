#!/usr/bin/env python3
"""
MoonYa 后端服务统一启动入口
合并爬虫服务与搜索服务到同一域名端口，仅 API 路径不同。

API 端点:
    POST /crawl                       爬取网页（SSE 流式）
    GET  /download/{user_id}/{file}   下载爬取产物 zip
    POST /search                      搜索/抓取网页内容
    GET  /health                      健康检查

用法:
    python main.py [config.json路径]
"""

import asyncio
import json
import os
import signal
import socket
import sys
import time

from aiohttp import web

ROOT = os.path.dirname(os.path.abspath(__file__))
if ROOT not in sys.path:
    sys.path.insert(0, ROOT)


# ====================== 主后端调用重试退避 ======================


async def fetch_with_retry(url, max_retries=3, **kwargs):
    """调用主后端 API，失败时指数退避重试（1s/2s/4s）。

    作为工具函数提供给后续需要调用主后端的场景使用。
    """
    import aiohttp
    delays = [1, 2, 4]
    last_err = None
    for attempt in range(max_retries):
        try:
            async with aiohttp.ClientSession() as session:
                if isinstance(kwargs.get('method'), str):
                    async with session.request(url=url, **kwargs) as resp:
                        if resp.status < 500:
                            return resp
                        last_err = f"HTTP {resp.status}"
                else:
                    async with session.get(url, **kwargs) as resp:
                        if resp.status < 500:
                            return resp
                        last_err = f"HTTP {resp.status}"
        except Exception as e:
            last_err = str(e)
        if attempt < max_retries - 1:
            await asyncio.sleep(delays[attempt])
    raise RuntimeError(f"重试 {max_retries} 次后仍失败: {last_err}")


# ====================== 配置加载 ======================


def get_local_ip() -> str:
    """获取本机真实内网 IP（非 127.0.0.1 / 0.0.0.0）。

    优先通过建立 UDP socket 到公网地址的方式拿到出站网卡 IP（不实际发包）；
    失败则回退到 socket.gethostbyname(socket.gethostname())。
    """
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        try:
            # 连到公网 DNS，不会真正发包，仅用于让 OS 选定出站网卡
            s.connect((
                os.environ["MOONYA_NETWORK_PROBE_HOST"],
                int(os.environ["MOONYA_NETWORK_PROBE_PORT"]),
            ))
            ip = s.getsockname()[0]
            if ip and not ip.startswith("127."):
                return ip
        finally:
            s.close()
    except Exception:
        pass
    try:
        ip = socket.gethostbyname(socket.gethostname())
        if ip and not ip.startswith("127."):
            return ip
    except Exception:
        pass
    return "127.0.0.1"


def resolve_python_service_domain(raw: str, port: int) -> str:
    """解析 python_service_domain 配置值。

    下列值视为"未配置/自动"，自动用本机 IP 拼接：
        - 空字符串
        - "auto"
        - 包含 0.0.0.0 的地址（监听地址，不可作为对外服务地址）
    其他值视为用户显式配置，原样返回。
    """
    if not raw or raw.strip().lower() == "auto" or "0.0.0.0" in raw:
        return f"http://{get_local_ip()}:{port}/"
    return raw


def load_config(config_path: str | None = None) -> dict:
    """加载配置文件，返回 host/port/data_dir/python_service_domain/backend_api_url 配置。

    配置查找优先级（高到低）：
        1. 命令行参数传入的 config_path
        2. MoonYa-Python/service_config.json （Python 服务专用独立配置，与主后端隔离）
        3. launcher_config.json （位于 Python 根的上级目录或本目录）
        4. 内置默认值
    """
    path = config_path or os.path.join(ROOT, "service_config.json")
    if not os.path.isfile(path):
        raise RuntimeError(f"missing required Python service configuration: {path}")
    try:
        with open(path, "r", encoding="utf-8-sig") as file:
            data = json.load(file)
    except (json.JSONDecodeError, OSError) as error:
        raise RuntimeError(f"invalid Python service configuration {path}: {error}") from error

    host = os.environ.get("MOONYA_PYTHON_HOST") or str(data.get("host") or "").strip()
    port_raw = os.environ.get("MOONYA_PYTHON_SERVICE_PORT") or data.get("crawler_port")
    if not host:
        raise RuntimeError("missing required field host (MOONYA_PYTHON_HOST overrides it)")
    try:
        port = int(port_raw)
    except (TypeError, ValueError) as error:
        raise RuntimeError("missing or invalid required field crawler_port") from error
    if not 1 <= port <= 65535:
        raise RuntimeError("crawler_port must be within 1..65535")

    from web_search.web_search import configure_search, resolve_search_config
    search_config = resolve_search_config(data.get("search"))
    configure_search(search_config)

    return {
        "host": host,
        "port": port,
        "data_dir": os.environ.get("MOONYA_CRAWLER_DATA_DIR") or str(data.get("data_dir") or ""),
        "python_service_domain": os.environ.get("MOONYA_PYTHON_SERVICE_URL")
            or str(data.get("python_service_domain") or "").strip(),
        "backend_api_url": os.environ.get("MOONYA_BACKEND_API_URL")
            or str(data.get("backend_api_url") or "").strip(),
        "search": search_config,
    }


# ====================== 服务启动 ======================


async def main():
    config_path = sys.argv[1] if len(sys.argv) > 1 else None
    cfg = load_config(config_path)

    host = cfg["host"]
    port = cfg["port"]
    # 监听 0.0.0.0/:: 表示绑定所有网卡（"全部 tcp"），日志显示真实 IP 避免误解
    display_host = host if host not in ("0.0.0.0", "::", "") else get_local_ip()

    print(f"\n{'=' * 50}")
    print(f"  MoonYa 后端服务启动中…")
    print(f"  监听: {host}:{port}  (所有网卡)")
    print(f"  Service: http://{display_host}:{port}")
    print(f"  python_service_domain: {cfg.get('python_service_domain', '(未配置)')}")
    print(f"  POST /crawl       — 网页爬虫")
    print(f"  GET  /download/…  — 下载产物")
    print(f"  POST /search      — 网页搜索")
    print(f"  GET  /health      — 健康检查")
    print(f"{'=' * 50}\n")

    # ── 导入服务（CrawlerServer 导入触发 Playwright 自动安装）──
    try:
        from web_crawler.crawler_server import CrawlerServer
        from web_search.search_server import SearchServer
    except ImportError as e:
        # 根据操作系统显示对应的 pip/playwright 路径
        is_win = sys.platform.startswith("win")
        pip_bin = ".venv\\Scripts\\pip" if is_win else ".venv/bin/pip"
        pw_bin = ".venv\\Scripts\\playwright" if is_win else ".venv/bin/playwright"
        print(f"\n{'!' * 50}")
        print(f"!! 导入失败: {e}")
        print(f"!! 请确保已安装所有依赖（在项目根目录 MoonYa-Python 下执行）:")
        print(f"!!   {pip_bin} install -r requirements.txt")
        print(f"!!   {pw_bin} install chromium")
        print(f"!! 若未建虚拟环境，可用系统 pip:")
        print(f"!!   pip3 install -r requirements.txt")
        print(f"!!   python3 -m playwright install chromium")
        print(f"{'!' * 50}\n")
        return

    crawler = CrawlerServer(host=host, port=port, data_dir=cfg.get("data_dir", ""))
    search = SearchServer(host=host, port=port, search_config=cfg["search"])

    # ── 创建统一 Application，注册所有路由 ──
    app = web.Application()

    # 爬虫路由
    app.router.add_post("/crawl", crawler.handle_crawl)
    app.router.add_get("/download/{user_id}/{filename}", crawler.handle_download)

    # 搜索路由
    app.router.add_post("/search", search.handle_search)

    # 统一健康检查
    async def handle_health(_request: web.Request) -> web.Response:
        return web.json_response({"status": "ok", "services": ["crawler", "search"]})
    app.router.add_get("/health", handle_health)

    # ── 启动 ──
    try:
        runner = web.AppRunner(app)
        await runner.setup()
        await web.TCPSite(runner, host, port).start()
    except OSError as e:
        print(f"\n{'!' * 50}")
        print(f"!! 端口 {port} 启动失败: {e}")
        print(f"!! 请检查端口是否被占用，或关闭其他占用进程")
        print(f"{'!' * 50}\n")
        return

    print(f"[main] ✅ 所有服务已启动 → http://{display_host}:{port}")
    print(f"[main] 按 Ctrl+C 停止\n")

    # ── 等待终止信号 ──
    stop_event = asyncio.Event()

    def _shutdown(signum, frame):
        print("\n[main] 收到终止信号，正在停止…")
        stop_event.set()

    signal.signal(signal.SIGINT, _shutdown)
    signal.signal(signal.SIGTERM, _shutdown)

    try:
        await stop_event.wait()
    finally:
        print("[main] 正在关闭…")
        await runner.cleanup()
        print("[main] 所有服务已停止")


if __name__ == "__main__":
    asyncio.run(main())
