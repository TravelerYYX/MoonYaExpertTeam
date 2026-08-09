#!/usr/bin/env python3
import json
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


def tools_page(cursor):
    if cursor is None:
        return {
            "tools": [{
                "name": "echo",
                "description": "Echo a value",
                "inputSchema": {
                    "type": "object",
                    "properties": {"value": {"type": "string"}},
                    "required": ["value"],
                },
            }],
            "nextCursor": "page-2",
        }
    return {
        "tools": [
            {
                "name": "artifact",
                "description": "Return a resource link",
                "inputSchema": {"type": "object"},
            },
            {
                "name": "slow",
                "description": "Wait for cancellation",
                "inputSchema": {"type": "object"},
            },
        ]
    }


class Handler(BaseHTTPRequestHandler):
    def read_body(self):
        if self.headers.get("transfer-encoding", "").lower() != "chunked":
            length = int(self.headers.get("content-length", "0"))
            return self.rfile.read(length)
        chunks = []
        while True:
            size_line = self.rfile.readline().strip()
            size = int(size_line.split(b";", 1)[0], 16)
            if size == 0:
                while self.rfile.readline().strip():
                    pass
                break
            chunks.append(self.rfile.read(size))
            self.rfile.read(2)
        return b"".join(chunks)

    def do_GET(self):
        if self.path == "/health":
            self.send_response(200)
            self.end_headers()
            return
        self.send_error(405)

    def do_DELETE(self):
        self.send_response(200)
        self.end_headers()

    def do_POST(self):
        request = json.loads(self.read_body() or b"{}")
        method = request.get("method")
        request_id = request.get("id")
        if request_id is None:
            self.send_response(202)
            self.send_header("Mcp-Session-Id", "smoke-session")
            self.end_headers()
            return
        if method == "initialize":
            protocol = (request.get("params") or {}).get("protocolVersion", "2025-11-25")
            result = {
                "protocolVersion": protocol,
                "capabilities": {"tools": {"listChanged": False}},
                "serverInfo": {"name": "fake-http", "version": "1.0"},
            }
        elif method == "ping":
            result = {}
        elif method == "tools/list":
            result = tools_page((request.get("params") or {}).get("cursor"))
        elif method == "tools/call":
            params = request.get("params") or {}
            name = params.get("name")
            arguments = params.get("arguments") or {}
            if name == "slow":
                time.sleep(3)
                result = {"content": [{"type": "text", "text": "late"}]}
            elif name == "artifact":
                result = {
                    "content": [{
                        "type": "resource_link",
                        "uri": "https://example.invalid/report.json",
                        "name": "report.json",
                        "mimeType": "application/json",
                    }],
                    "structuredContent": {"kind": "report"},
                }
            else:
                value = str(arguments.get("value", ""))
                result = {
                    "content": [{"type": "text", "text": "echo:" + value}],
                    "structuredContent": {"value": value},
                }
        else:
            payload = {
                "jsonrpc": "2.0",
                "id": request_id,
                "error": {"code": -32601, "message": "method not found"},
            }
            self.respond(payload, initialize=False)
            return
        self.respond({"jsonrpc": "2.0", "id": request_id, "result": result}, method == "initialize")

    def respond(self, payload, initialize=False):
        json_line = json.dumps(payload, separators=(",", ":"))
        raw = ("event: message\ndata: " + json_line + "\n\n").encode("utf-8")
        try:
            self.send_response(200)
            self.send_header("content-type", "text/event-stream")
            self.send_header("content-length", str(len(raw)))
            if initialize:
                self.send_header("Mcp-Session-Id", "smoke-session")
            self.end_headers()
            self.wfile.write(raw)
        except OSError:
            pass

    def log_message(self, *_args):
        return


if __name__ == "__main__":
    ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1])), Handler).serve_forever()
