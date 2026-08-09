"""
用 function_calling 工具（普通 function，不是 builtin_function）测试
是否 tool_choice="required" 能强制模型调工具
"""
import os
import json
from openai import OpenAI

API_KEY = "sk-WE4wI9Dxe2YXloAEXW28H13drqUJC1zIPjHlpIFVG8iThpWq"
QUERY = os.environ.get('QUERY', '搜索下yueyaxuan.cn')

client = OpenAI(
    base_url="https://api.moonshot.cn/v1",
    api_key=API_KEY,
)

# 普通 function 工具（不是 builtin_function）
TOOLS = [
    {
        "type": "function",
        "function": {
            "name": "web_search",
            "description": "联网搜索互联网信息。用于查找最新新闻、事实、人物、事件、域名信息等任何实时信息。",
            "parameters": {
                "type": "object",
                "properties": {
                    "query": {
                        "type": "string",
                        "description": "搜索关键词或查询语句",
                    },
                },
                "required": ["query"],
            },
        },
    }
]

def main():
    messages = [
        {"role": "system", "content": "你是 Kimi，一个有用的 AI 助手。"},
        {"role": "user", "content": QUERY},
    ]

    print(f"===== 测试 Query: {QUERY} =====\n")

    completion = client.chat.completions.create(
        model=os.environ.get('MODEL', 'kimi-k2.6'),
        messages=messages,
        max_tokens=32768,
        extra_body={"thinking": {"type": "disabled"}},
        tools=TOOLS,
        tool_choice=os.environ.get('TOOL_CHOICE', 'required'),
    )

    choice = completion.choices[0]
    print(f"finish_reason: {choice.finish_reason}")
    content_safe = repr(choice.message.content[:200] if choice.message.content else '')
    print(f"content: {content_safe}")
    if hasattr(choice.message, 'tool_calls') and choice.message.tool_calls:
        print(f"tool_calls 数: {len(choice.message.tool_calls)}")
        for tc in choice.message.tool_calls:
            print(f"  - name={tc.function.name}, args={tc.function.arguments[:300]}")
    else:
        print("tool_calls: None")

if __name__ == '__main__':
    main()
