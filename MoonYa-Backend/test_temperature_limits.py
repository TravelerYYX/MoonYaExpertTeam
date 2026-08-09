"""
测试 kimi-k2.5 对 temperature 的限制
"""
import os
from openai import OpenAI

API_KEY = "sk-WE4wI9Dxe2YXloAEXW28H13drqUJC1zIPjHlpIFVG8iThpWq"
client = OpenAI(base_url="https://api.moonshot.cn/v1", api_key=API_KEY)

models = ['kimi-k2.5', 'kimi-k2.6', 'moonshot-v1-128k', 'moonshot-v1-32k', 'moonshot-v1-8k']
temps = [0.0, 0.3, 0.6, 0.7, 1.0, 1.5]

for model in models:
    print(f"\n===== {model} =====")
    for t in temps:
        try:
            completion = client.chat.completions.create(
                model=model,
                messages=[{"role": "user", "content": "hi"}],
                max_tokens=10,
                temperature=t,
                extra_body={"thinking": {"type": "disabled"}},
            )
            print(f"  temperature={t}: OK")
        except Exception as e:
            err = str(e)[:150]
            print(f"  temperature={t}: ERR - {err}")
