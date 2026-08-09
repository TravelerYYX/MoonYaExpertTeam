"""
严格按 Kimi 官方 Python 代码测试 $web_search
参考: https://platform.moonshot.cn/docs/guide/use-web-search
"""
import os
import json
from openai import OpenAI

API_KEY = "sk-WE4wI9Dxe2YXloAEXW28H13drqUJC1zIPjHlpIFVG8iThpWq"
QUERY = "请搜索今天的热点新闻"

client = OpenAI(
    base_url="https://api.moonshot.cn/v1",
    api_key=API_KEY,
)

def search_impl(arguments):
    return arguments  # 原封不动返回

def chat(messages, model="kimi-k2.6"):
    completion = client.chat.completions.create(
            model=os.environ.get('MODEL', 'kimi-k2.6'),
            messages=messages,
            max_tokens=32768,
            extra_body={"thinking": {"type": "disabled"}},
            tools=[
                {
                    "type": "builtin_function",
                    "function": {
                        "name": "$web_search",
                    },
                }
            ],
            tool_choice=os.environ.get('TOOL_CHOICE', None),  # 可设为 "auto"/"required"
        )
    return completion.choices[0]

def main():
    messages = [
        {"role": "system", "content": "你是 Kimi。"},
    ]
    messages.append({
        "role": "user",
        "content": QUERY,
    })

    print(f"===== 测试 Query: {QUERY} =====\n")

    finish_reason = None
    iteration = 0
    while finish_reason is None or finish_reason == "tool_calls":
        iteration += 1
        print(f"--- 迭代 {iteration} ---")
        choice = chat(messages)
        finish_reason = choice.finish_reason
        print(f"finish_reason: {finish_reason}")
        # 检查 tool_calls
        content_safe = repr(choice.message.content[:200] if choice.message.content else '')
        print(f"content: {content_safe}")

        # 检查 tool_calls
        if hasattr(choice.message, 'tool_calls') and choice.message.tool_calls:
            print(f"tool_calls 数: {len(choice.message.tool_calls)}")
            for tc in choice.message.tool_calls:
                args_repr = repr(tc.function.arguments[:200])
                print(f"  - name={tc.function.name}, args={args_repr}")
        else:
            print("tool_calls: None")

        if finish_reason == "tool_calls":
            # 1. 追加 assistant 消息
            messages.append(choice.message)
            # 2. 执行 tool_calls
            for tool_call in choice.message.tool_calls:
                tool_call_name = tool_call.function.name
                tool_call_arguments = json.loads(tool_call.function.arguments)
                if tool_call_name == "$web_search":
                    tool_result = search_impl(tool_call_arguments)
                else:
                    tool_result = f"Error: unable to find tool by name '{tool_call_name}'"
                messages.append({
                    "role": "tool",
                    "tool_call_id": tool_call.id,
                    "name": tool_call_name,
                    "content": json.dumps(tool_result),
                })

        if iteration > 3:
            print("迭代超过 3 次，退出")
            break

    print(f"\n===== 最终回复 =====")
    final_safe = repr(choice.message.content[:2000] if choice.message.content else '')
    print(final_safe)

if __name__ == '__main__':
    main()
