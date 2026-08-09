#!/usr/bin/env python3
"""Small concurrent OpenAI-compatible server used by multi_agent_v1_smoke_test.php."""

import json
import re
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/health":
            self.send_response(200)
            self.end_headers()
            self.wfile.write(b"ok")
            return
        self.send_error(404)

    def do_POST(self):
        length = int(self.headers.get("content-length", "0"))
        request_bytes = self.rfile.read(length) or b"{}"
        body = json.loads(request_bytes)
        messages = body.get("messages") or []
        user_text = "\n".join(
            str(item.get("content") or "")
            for item in messages
            if item.get("role") == "user"
        )
        last_user_text = next(
            (
                str(item.get("content") or "")
                for item in reversed(messages)
                if item.get("role") == "user"
            ),
            "",
        )
        early_tool_result_count = sum(
            1 for item in messages if item.get("role") == "tool"
        )
        if "SILENT_CANCEL" in user_text:
            # Deliberately send no headers or body while the Work coordinator
            # proves that cancellation, rather than a total timeout, closes it.
            time.sleep(5)
        elif "COOPERATIVE_SLOW" in user_text and early_tool_result_count > 0:
            time.sleep(1.4)
        elif "COOPERATIVE_FAST" in user_text and early_tool_result_count > 0:
            time.sleep(0.05)
        else:
            time.sleep(0.2)
        deepseek_thinking_with_unsupported_tool_choice = (
            "deepseek" in str(body.get("model") or "").lower()
            and bool(body.get("tools"))
            and (body.get("thinking") or {}).get("type") == "enabled"
            and "tool_choice" in body
        )
        invalid_empty_schema_object = any(
            isinstance((tool.get("function") or {}).get("parameters"), dict)
            and isinstance(
                ((tool.get("function") or {}).get("parameters") or {}).get(
                    "properties"
                ),
                list,
            )
            for tool in (body.get("tools") or [])
        )
        invalid_deepseek_tool_history = any(
            "deepseek" in str(body.get("model") or "").lower()
            and item.get("role") == "assistant"
            and bool(item.get("tool_calls"))
            and (
                not str(item.get("content") or "").strip()
                or not str(item.get("reasoning_content") or "").strip()
            )
            for item in messages
        )
        if invalid_empty_schema_object:
            payload = {
                "error": {
                    "message": "Invalid schema: properties must be an object"
                }
            }
            raw = json.dumps(payload).encode("utf-8")
            self.send_response(400)
        elif invalid_deepseek_tool_history:
            payload = {
                "error": {
                    "message": "assistant tool history must preserve reasoning and non-empty content"
                }
            }
            raw = json.dumps(payload).encode("utf-8")
            self.send_response(400)
        elif deepseek_thinking_with_unsupported_tool_choice:
            payload = {
                "error": {
                    "message": "Thinking mode does not support this tool_choice"
                }
            }
            raw = json.dumps(payload).encode("utf-8")
            self.send_response(400)
        elif b"FORCE_FAIL" in request_bytes or "FORCE_FAIL" in user_text:
            payload = {"error": {"message": "intentional fake model failure"}}
            raw = json.dumps(payload).encode("utf-8")
            self.send_response(500)
        else:
            tool_result_count = sum(1 for item in messages if item.get("role") == "tool")
            has_tool_result = tool_result_count > 0
            tools = body.get("tools") or []
            tool_names = [
                str((tool.get("function") or {}).get("name") or "")
                for tool in tools
            ]
            last_user_index = max(
                (index for index, item in enumerate(messages) if item.get("role") == "user"),
                default=-1,
            )
            recent_tool_result_count = sum(
                1
                for index, item in enumerate(messages)
                if index > last_user_index and item.get("role") == "tool"
            )
            last_tool_name = next(
                (
                    str(item.get("name") or "")
                    for item in reversed(messages)
                    if item.get("role") == "tool"
                ),
                "",
            )
            project_contract = "submit_project_contract" in tool_names
            project_acceptance = "submit_project_acceptance" in tool_names
            is_root_coordinator = "delegate_to_agents" in tool_names
            root_delegation_results = sum(
                1
                for item in messages
                if item.get("role") == "tool"
                and item.get("name") == "delegate_to_agents"
            )
            read_then_build_root = (
                is_root_coordinator and "READ_THEN_BUILD" in user_text
            )
            long_progress = "LONG_15" in user_text and tool_result_count < 15
            dead_loop = "DEAD_LOOP" in user_text
            project_tool_needed = (
                project_contract and last_tool_name != "submit_project_contract"
            ) or (
                project_acceptance
                and last_tool_name not in {
                    "submit_project_acceptance",
                    "request_project_rework",
                }
            )
            if tools and (
                not has_tool_result
                or long_progress
                or dead_loop
                or read_then_build_root
                or project_tool_needed
            ) and "return text without a tool" not in user_text:
                tool_name = tools[0]["function"]["name"]
                if project_contract:
                    if recent_tool_result_count == 0:
                        tool_name = next(
                            name
                            for name in tool_names
                            if name != "submit_project_contract"
                        )
                        arguments = {"code": "inspect-and-create-project-foundation"}
                    else:
                        tool_name = "submit_project_contract"
                        group_match = re.search(r"project-[a-f0-9]{24}", user_text)
                        arguments = {
                            "group_id": group_match.group(0) if group_match else "missing-group",
                            "architecture": "app.js and store.js define the shared public contract.",
                            "lead_owned_paths": ["js/app.js", "js/store.js"],
                            "acceptance_criteria": ["all members succeeded", "integration check passed"],
                            "work_packages": [
                                {
                                    "id": "work",
                                    "title": "工作区",
                                    "instruction": "COOPERATIVE_FAST implement and verify the work page.",
                                    "owned_paths": ["js/pages/work.js"],
                                    "read_dependencies": ["js/app.js", "js/store.js"],
                                    "depends_on": [],
                                },
                                {
                                    "id": "study",
                                    "title": "考公区",
                                    "instruction": "COOPERATIVE_SLOW implement and verify the study page.",
                                    "owned_paths": ["js/pages/study.js"],
                                    "read_dependencies": ["js/app.js", "js/store.js"],
                                    "depends_on": [],
                                },
                                {
                                    "id": "notes",
                                    "title": "随笔区",
                                    "instruction": "COOPERATIVE_FAST implement and verify the notes page.",
                                    "owned_paths": ["js/pages/notes.js"],
                                    "read_dependencies": ["js/app.js", "js/store.js"],
                                    "depends_on": ["work"],
                                },
                            ],
                        }
                elif project_acceptance:
                    if recent_tool_result_count == 0:
                        tool_name = next(
                            name
                            for name in tool_names
                            if name not in {
                                "submit_project_acceptance",
                                "request_project_rework",
                            }
                        )
                        arguments = {"code": "independent-integration-check"}
                    else:
                        tool_name = "submit_project_acceptance"
                        group_match = re.search(r"project-[a-f0-9]{24}", user_text)
                        evidence = sorted(set(re.findall(
                            r"[A-Za-z0-9._-]+\.member\.[A-Za-z0-9._-]+",
                            user_text,
                        )))
                        arguments = {
                            "group_id": group_match.group(0) if group_match else "missing-group",
                            "outcome": "completed",
                            "evidence_task_ids": evidence,
                            "checks": ["fake integration check passed"],
                            "unresolved": [],
                        }
                elif read_then_build_root:
                    if root_delegation_results == 0:
                        tool_name = "delegate_to_agents"
                        arguments = {
                            "tasks": [{
                                "id": "prompt-read",
                                "capability_key": "file.management",
                                "instruction": "READ_THEN_BUILD：读取工作台设计提示词并返回事实输入。",
                                "context": "读取是中间步骤，不得宣称实现完成。",
                                "depends_on": [],
                                "selection_reason": "普通文本读取属于 file.management。",
                            }]
                        }
                    elif root_delegation_results == 1:
                        tool_name = "delegate_to_agents"
                        arguments = {
                            "tasks": [{
                                "id": "workspace-build",
                                "capability_key": "code.engineering",
                                "instruction": "READ_THEN_BUILD：实现并验证 HTML、CSS、JavaScript 工作台。",
                                "context": "使用上一轮提示词读取结果作为事实输入。",
                                "depends_on": [],
                                "selection_reason": "项目实现与验证属于 code.engineering。",
                            }]
                        }
                    else:
                        tool_name = "finalize_work"
                        arguments = {
                            "outcome": "completed",
                            "evidence_task_ids": [
                                "prompt-read",
                                "workspace-build",
                            ],
                            "unresolved": [],
                        }
                elif tool_name == "delegate_to_agents":
                    if last_user_text.strip() == "你好":
                        direct_tool = next(
                            (
                                item
                                for item in tools
                                if (item.get("function") or {}).get("name")
                                == "respond_without_delegation"
                            ),
                            None,
                        )
                        if direct_tool is not None:
                            tool_name = "respond_without_delegation"
                            arguments = {
                                "response": "你好，请问今天想让我帮你做什么？",
                                "reason": "chat",
                            }
                        else:
                            arguments = {}
                    else:
                        arguments = None
                    capability_enum = (
                        tools[0]["function"]["parameters"]["properties"]["tasks"]
                        ["items"]["properties"]["capability_key"].get("enum") or []
                    )
                    computer_capability = next(
                        (key for key in capability_enum if key == "computer.system"),
                        capability_enum[0],
                    )
                    if arguments is None:
                        arguments = {
                            "tasks": [{
                                "id": "clear-recycle-bin",
                                "capability_key": computer_capability,
                                "instruction": "观察当前系统环境并执行用户要求的清空回收站操作，返回可验证结果。",
                                "context": "用户原始请求：清空回收站。未指定任何网站、网盘或账号，不得自行补全。",
                                "depends_on": [],
                                "selection_reason": "这是本机系统管理操作，属于 computer.system。",
                            }],
                        }
                else:
                    if dead_loop:
                        code = "same-dead-loop"
                    elif "TOOL_WAIT_SLOW" in user_text:
                        code = "cooperative-tool-wait"
                    else:
                        code = f"fake-{tool_result_count}"
                    arguments = {"code": code}
                payload = {
                    "id": "fake-tool-response",
                    "choices": [{
                        "finish_reason": "tool_calls",
                        "message": {
                            "role": "assistant",
                            "content": None,
                            "tool_calls": [{
                                "id": "fake-tool-call",
                                "type": "function",
                                "function": {
                                    "name": tool_name,
                                    "arguments": json.dumps(arguments, ensure_ascii=False),
                                },
                            }],
                        },
                    }],
                }
            else:
                summary = "fake-agent-ok:" + user_text[:120]
                if "dependency_results" in user_text or '"task-a"' in user_text:
                    summary = "fake-dependent-agent-ok"
                payload = {
                    "id": "fake-response",
                    "choices": [{
                        "finish_reason": "stop",
                        "message": {"role": "assistant", "content": summary},
                    }],
                }
            if body.get("stream"):
                self.send_response(200)
                self.send_header("content-type", "text/event-stream")
                self.send_header("cache-control", "no-cache")
                self.send_header("connection", "close")
                self.end_headers()
                reasoning = {
                    "choices": [{
                        "finish_reason": None,
                        "delta": {"reasoning_content": "先检查环境与可用工具。"},
                    }]
                }
                self.wfile.write(
                    ("data: " + json.dumps(reasoning, ensure_ascii=False) + "\n\n")
                    .encode("utf-8")
                )
                message = payload["choices"][0]["message"]
                tool_calls = message.get("tool_calls") or []
                if tool_calls:
                    call = tool_calls[0]
                    first = {
                        "choices": [{
                            "finish_reason": None,
                            "delta": {
                                "tool_calls": [{
                                    "index": 0,
                                    "id": call["id"],
                                    "type": "function",
                                    "function": {
                                        "name": call["function"]["name"],
                                        "arguments": "",
                                    },
                                }]
                            },
                        }]
                    }
                    second = {
                        "choices": [{
                            "finish_reason": "tool_calls",
                            "delta": {
                                "tool_calls": [{
                                    "index": 0,
                                    "function": {
                                        "arguments": call["function"]["arguments"],
                                    },
                                }]
                            },
                        }]
                    }
                    for event in (first, second):
                        self.wfile.write(
                            ("data: " + json.dumps(event, ensure_ascii=False) + "\n\n")
                            .encode("utf-8")
                        )
                else:
                    text = str(message.get("content") or "")
                    midpoint = max(1, len(text) // 2)
                    for index, part in enumerate((text[:midpoint], text[midpoint:])):
                        if not part:
                            continue
                        event = {
                            "choices": [{
                                "finish_reason": "stop" if index == 1 else None,
                                "delta": {"content": part},
                            }]
                        }
                        self.wfile.write(
                            ("data: " + json.dumps(event, ensure_ascii=False) + "\n\n")
                            .encode("utf-8")
                        )
                self.wfile.write(b"data: [DONE]\n\n")
                self.wfile.flush()
                self.close_connection = True
                return
            raw = json.dumps(payload).encode("utf-8")
            self.send_response(200)
        self.send_header("content-type", "application/json")
        self.send_header("content-length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def log_message(self, *_args):
        return


if __name__ == "__main__":
    port = int(sys.argv[1])
    ThreadingHTTPServer(("127.0.0.1", port), Handler).serve_forever()
