"""MoonYa 爬虫 HTTP API 服务器 — SSE 流式推送爬取进度"""
import asyncio
import json
import os
import subprocess
import sys
import time
import traceback
import zipfile
from hashlib import md5
from pathlib import Path
from urllib.parse import urlparse

from aiohttp import web

# 确保能 import 同目录的 web_crawler 模块
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))


def _ensure_playwright_browsers() -> bool:
    """确保 Playwright 浏览器已安装；若缺失则自动安装 chromium"""
    try:
        result = subprocess.run(
            [sys.executable, "-m", "playwright", "install", "chromium"],
            capture_output=True, text=True,
            timeout=300,
            cwd=os.path.dirname(os.path.abspath(__file__)),
        )
        if result.returncode == 0:
            print("[crawler] Playwright chromium 就绪")
            return True
        else:
            print(f"[crawler] Playwright install 失败: {result.stderr.strip()[-200:]}")
            return False
    except Exception as e:
        print(f"[crawler] Playwright 安装异常: {e}")
        return False


# 启动时自动安装浏览器
_ensure_playwright_browsers()

from web_crawler import MoonYaPachong


def _url_hash(url: str) -> str:
    p = urlparse(url)
    return md5((p.netloc + p.path.rstrip("/")).encode()).hexdigest()[:12]


def _zip_dir(src_dir: Path, zip_path: Path) -> None:
    """将 src_dir 下所有文件打包为 zip"""
    zip_path.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
        for root, _dirs, files in os.walk(src_dir):
            for fn in files:
                fp = Path(root) / fn
                zf.write(fp, fp.relative_to(src_dir))


async def _sse_send(resp: web.StreamResponse, event: str, data: dict) -> None:
    """发送一条 SSE 事件"""
    payload = json.dumps(data, ensure_ascii=False)
    await resp.write(f"event: {event}\ndata: {payload}\n\n".encode("utf-8"))


class CrawlerServer:
    def __init__(self, host: str, port: int, data_dir: str = ""):
        self.host = host
        self.port = port
        self.data_dir = data_dir
        self.app = web.Application()
        self.app.router.add_post("/crawl", self.handle_crawl)
        self.app.router.add_get("/download/{user_id}/{filename}", self.handle_download)
        self.app.router.add_get("/health", self.handle_health)

    # ------------------------------------------------------------------ health

    async def handle_health(self, request: web.Request) -> web.Response:
        return web.json_response({"status": "ok"})

    # ------------------------------------------------------------------ crawl

    async def handle_crawl(self, request: web.Request) -> web.StreamResponse:
        resp = web.StreamResponse(
            status=200,
            reason="OK",
            headers={
                "Content-Type": "text/event-stream",
                "Cache-Control": "no-cache",
                "Connection": "keep-alive",
            },
        )
        await resp.prepare(request)

        try:
            try:
                body = await request.json()
            except Exception:
                await _sse_send(resp, "error", {"message": "请求体不是有效 JSON"})
                return resp

            url = str(body.get("url") or "").strip()
            user_id = str(body.get("user_id") or "").strip()
            base_dir = str(body.get("base_dir") or self.data_dir or "").strip()
            # 缓存 base_dir 以便 handle_download 可用
            if base_dir:
                self.data_dir = base_dir

            if not url:
                await _sse_send(resp, "error", {"message": "缺少 url 参数"})
                return resp
            if not user_id:
                await _sse_send(resp, "error", {"message": "缺少 user_id 参数"})
                return resp
            if not base_dir:
                await _sse_send(resp, "error", {"message": "缺少 base_dir 参数"})
                return resp
            if not url.startswith("http"):
                url = "https://" + url

            domain = urlparse(url).netloc
            h = _url_hash(url)

            try:
                # 保存到用户专属目录：{base_dir}/{user_id}/{domain_hash}/
                user_root = Path(base_dir) / user_id
                user_root.mkdir(parents=True, exist_ok=True)

                # 创建进度回调：将爬虫内部进度转为 SSE 事件
                async def on_progress(stage: str, message: str,
                                      current: int = 0, total: int = 0,
                                      elapsed: float = 0) -> None:
                    await _sse_send(resp, "progress", {
                        "stage": stage,
                        "message": message,
                        "current": current,
                        "total": total,
                        "elapsed": elapsed,
                    })

                start_time = time.time()
                crawler = MoonYaPachong(url, out_dir=str(user_root),
                                        progress_callback=on_progress)
                await crawler.run()

                # 检查是否成功（manifest.json 存在才算成功）
                out_dir = crawler._pd
                manifest_path = out_dir / "manifest.json"
                if not manifest_path.exists():
                    await _sse_send(resp, "error", {
                        "message": "爬取失败：未能生成 manifest.json，目标页面可能无法访问",
                    })
                    return resp

                # 阶段：打包
                await _sse_send(resp, "progress", {
                    "stage": "打包",
                    "message": "正在压缩为 zip…",
                    "current": 0,
                    "total": 0,
                    "elapsed": round(time.time() - start_time, 1),
                })

                zip_filename = f"{domain}_{out_dir.name}.zip"
                user_dir = Path(base_dir) / user_id
                zip_path = user_dir / zip_filename
                _zip_dir(out_dir, zip_path)

                # 读取 manifest 获取统计信息
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                total = manifest.get("total", 0)
                by_type = manifest.get("by_type", {})
                failed_count = manifest.get("failed_count", 0)
                failed_urls = manifest.get("failed_urls", [])

                elapsed = time.time() - start_time
                await _sse_send(resp, "complete", {
                    "zip_url": f"/download/{user_id}/{zip_filename}",
                    "total": total,
                    "by_type": by_type,
                    "local_dir": str(out_dir.absolute()),
                    "failed_count": failed_count,
                    "failed_urls": failed_urls,
                    "elapsed": round(elapsed, 2),
                })

            except Exception as e:
                traceback.print_exc()
                try:
                    await _sse_send(resp, "error", {"message": str(e)})
                except Exception:
                    pass

        finally:
            await resp.write_eof()

        return resp

    # ------------------------------------------------------------------ download

    async def handle_download(self, request: web.Request) -> web.Response:
        user_id = request.match_info["user_id"]
        filename = request.match_info["filename"]
        base_dir = request.query.get("base_dir", self.data_dir).strip()

        if not base_dir:
            raise web.HTTPBadRequest(reason="缺少 base_dir 参数")

        file_path = Path(base_dir) / user_id / filename

        if not file_path.exists() or not file_path.is_file():
            raise web.HTTPNotFound(reason="文件未找到")

        return web.FileResponse(
            path=str(file_path),
            headers={
                "Content-Type": "application/zip",
                "Content-Disposition": f'attachment; filename="{filename}"',
            },
        )

    # ------------------------------------------------------------------ run

    def run(self) -> None:
        print(f"MoonYa 爬虫服务启动: http://{self.host}:{self.port}")
        web.run_app(self.app, host=self.host, port=self.port, print=None)


# ====================================================================== main


def main() -> None:
    host = sys.argv[1] if len(sys.argv) > 1 else os.environ.get("MOONYA_PYTHON_HOST", "")
    port_raw = sys.argv[2] if len(sys.argv) > 2 else os.environ.get("MOONYA_PYTHON_SERVICE_PORT", "")
    if not host or not port_raw:
        raise RuntimeError("host and port are required for the standalone crawler service")
    port = int(port_raw)
    data_dir = sys.argv[3] if len(sys.argv) > 3 else ""
    server = CrawlerServer(host, port, data_dir)
    server.run()


if __name__ == "__main__":
    main()
