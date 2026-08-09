#!/usr/bin/env python3
import json
import sys
import time


def send(payload):
    sys.stdout.write(json.dumps(payload, separators=(",", ":")) + "\n")
    sys.stdout.flush()


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


for line in sys.stdin:
    try:
        request = json.loads(line)
        method = request.get("method")
        request_id = request.get("id")
        if method == "initialize":
            protocol = (request.get("params") or {}).get("protocolVersion", "2025-11-25")
            send({
                "jsonrpc": "2.0",
                "id": request_id,
                "result": {
                    "protocolVersion": protocol,
                    "capabilities": {"tools": {"listChanged": True}},
                    "serverInfo": {"name": "fake-stdio", "version": "1.0"},
                },
            })
        elif method == "notifications/initialized":
            send({"jsonrpc": "2.0", "method": "notifications/tools/list_changed"})
        elif method == "ping":
            send({"jsonrpc": "2.0", "id": request_id, "result": {}})
        elif method == "tools/list":
            cursor = (request.get("params") or {}).get("cursor")
            send({"jsonrpc": "2.0", "id": request_id, "result": tools_page(cursor)})
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
            send({"jsonrpc": "2.0", "id": request_id, "result": result})
        elif request_id is not None:
            send({
                "jsonrpc": "2.0",
                "id": request_id,
                "error": {"code": -32601, "message": "method not found"},
            })
    except Exception as error:
        send({
            "jsonrpc": "2.0",
            "id": None,
            "error": {"code": -32603, "message": str(error)},
        })
