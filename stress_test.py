"""
MoonYa 压力测试脚本 - 模拟真实用户使用场景
支持 SSE 流式响应、多用户并发、阶梯加压

使用方法：
    pip install aiohttp
    python stress_test.py --scenario chat --users 10 --duration 60
    python stress_test.py --scenario work_simple --users 5 --duration 120
    python stress_test.py --scenario work_complex --users 3 --duration 180 --rampup 30
"""

import asyncio
import aiohttp
import json
import time
import argparse
import random
from collections import defaultdict
from dataclasses import dataclass, field
from typing import List


# ──────────────────────────────────────────────────────────
# 测试场景定义
# ──────────────────────────────────────────────────────────

SCENARIOS = {
    # 场景1：普通对话（低负载）
    "chat": {
        "name": "普通对话",
        "messages": [
            "你好，请介绍一下你自己",
            "今天天气怎么样？",
            "帮我写一首关于春天的诗",
            "解释一下量子计算的基本原理",
            "推荐几本好书",
        ],
        "payload_template": {
            "message": "",  # 动态填充
            "model": "kimi",
            "kimiModelVersion": "moonshot-v1-32k",
            "agent_mode": "normal",
            "deepThinking": False,
        },
        "timeout": 60,
    },

    # 场景2：Work 模式简单任务（中负载，1-2 轮工具调用）
    "work_simple": {
        "name": "Work模式-简单任务",
        "messages": [
            "打开记事本",
            "查询今天北京天气",
            "现在几点了",
            "列出桌面文件",
            "创建一个名为test的文件夹在桌面",
        ],
        "payload_template": {
            "message": "",
            "model": "kimi",
            "kimiModelVersion": "moonshot-v1-32k",
            "agent_mode": "agent",
            "deepThinking": False,
        },
        "timeout": 120,
    },

    # 场景3：Work 模式复杂任务（高负载，多轮工具调用+规划）
    "work_complex": {
        "name": "Work模式-复杂任务",
        "messages": [
            "制作一份关于AI助手的竞品分析报告，保存到桌面",
            "查询今天的新闻并整理成文档",
            "搜索Python学习资料并整理成Markdown文件保存到桌面",
            "分析当前系统状态并给出优化建议",
            "整理桌面所有文件按类型分类到不同文件夹",
        ],
        "payload_template": {
            "message": "",
            "model": "kimi",
            "kimiModelVersion": "moonshot-v1-32k",
            "agent_mode": "agent",
            "deepThinking": False,
        },
        "timeout": 300,
    },

    # 场景4：深度思考（高负载，长耗时）
    "deep_thinking": {
        "name": "深度思考",
        "messages": [
            "请深入分析人工智能对就业市场的影响",
            "推导一下贝叶斯定理的应用场景",
            "对比分析微服务架构与单体架构的优劣",
        ],
        "payload_template": {
            "message": "",
            "model": "deepseek",
            "deepseekModelVersion": "deepseek-v4-pro",
            "agent_mode": "normal",
            "deepThinking": True,
            "reasoningEffort": "max",
        },
        "timeout": 180,
    },

    # 场景5：混合场景（模拟真实用户分布）
    "mixed": {
        "name": "混合场景",
        "sub_scenarios": ["chat", "work_simple", "work_complex", "deep_thinking"],
        "weights": [50, 25, 15, 10],  # 各场景占比%
    },
}


# ──────────────────────────────────────────────────────────
# 测试结果收集
# ──────────────────────────────────────────────────────────

@dataclass
class RequestResult:
    """单次请求结果"""
    user_id: int
    scenario: str
    message: str
    success: bool
    status_code: int
    response_time: float          # 总响应时间（秒）
    first_byte_time: float        # 首字节时间（秒）
    sse_events: int               # 收到的 SSE 事件数
    content_length: int           # 响应内容总长度
    error: str = ""


@dataclass
class TestStats:
    """测试统计"""
    results: List[RequestResult] = field(default_factory=list)
    start_time: float = 0
    end_time: float = 0

    def add(self, r: RequestResult):
        self.results.append(r)

    def summary(self):
        if not self.results:
            return "无结果"

        total = len(self.results)
        success = sum(1 for r in self.results if r.success)
        failed = total - success
        durations = [r.response_time for r in self.results if r.success]
        ttfts = [r.first_byte_time for r in self.results if r.success]
        events = [r.sse_events for r in self.results if r.success]

        durations_sorted = sorted(durations) if durations else [0]
        ttfts_sorted = sorted(ttfts) if ttfts else [0]

        def percentile(data, p):
            if not data:
                return 0
            k = (len(data) - 1) * p / 100
            f = int(k)
            c = min(f + 1, len(data) - 1)
            return data[f] + (data[c] - data[f]) * (k - f)

        total_time = self.end_time - self.start_time
        qps = total / total_time if total_time > 0 else 0

        # 按场景分组统计
        by_scenario = defaultdict(list)
        for r in self.results:
            by_scenario[r.scenario].append(r)

        scenario_stats = []
        for sc, rs in by_scenario.items():
            sc_success = sum(1 for r in rs if r.success)
            sc_durations = [r.response_time for r in rs if r.success]
            avg_dur = sum(sc_durations) / len(sc_durations) if sc_durations else 0
            scenario_stats.append({
                "scenario": sc,
                "total": len(rs),
                "success": sc_success,
                "success_rate": f"{sc_success/len(rs)*100:.1f}%",
                "avg_time": f"{avg_dur:.2f}s",
            })

        return {
            "total_requests": total,
            "success": success,
            "failed": failed,
            "success_rate": f"{success/total*100:.2f}%",
            "total_duration": f"{total_time:.1f}s",
            "qps": f"{qps:.2f}",
            "response_time": {
                "avg": f"{sum(durations)/len(durations):.2f}s" if durations else "N/A",
                "p50": f"{percentile(durations_sorted, 50):.2f}s",
                "p95": f"{percentile(durations_sorted, 95):.2f}s",
                "p99": f"{percentile(durations_sorted, 99):.2f}s",
                "max": f"{max(durations):.2f}s" if durations else "N/A",
            },
            "first_byte_time": {
                "avg": f"{sum(ttfts)/len(ttfts):.2f}s" if ttfts else "N/A",
                "p50": f"{percentile(ttfts_sorted, 50):.2f}s",
                "p95": f"{percentile(ttfts_sorted, 95):.2f}s",
            },
            "sse_events_avg": f"{sum(events)/len(events):.1f}" if events else "0",
            "by_scenario": scenario_stats,
        }


# ──────────────────────────────────────────────────────────
# SSE 流式响应读取
# ──────────────────────────────────────────────────────────

async def send_request_and_collect(
    session: aiohttp.ClientSession,
    url: str,
    payload: dict,
    user_id: int,
    scenario_name: str,
    message: str,
    timeout: int,
) -> RequestResult:
    """发送请求并收集 SSE 流式响应结果"""
    start = time.time()
    first_byte_time = 0
    sse_events = 0
    content_length = 0
    status_code = 0
    error_msg = ""

    try:
        async with session.post(
            url,
            json=payload,
            timeout=aiohttp.ClientTimeout(total=timeout),
        ) as resp:
            status_code = resp.status
            if status_code != 200:
                error_msg = f"HTTP {status_code}"
                return RequestResult(
                    user_id=user_id, scenario=scenario_name, message=message,
                    success=False, status_code=status_code,
                    response_time=time.time() - start,
                    first_byte_time=0, sse_events=0, content_length=0,
                    error=error_msg,
                )

            # 读取 SSE 流
            async for raw_line in resp.content:
                if not raw_line:
                    continue
                line = raw_line.decode("utf-8", errors="ignore").strip()
                if not line:
                    continue

                if first_byte_time == 0:
                    first_byte_time = time.time() - start

                content_length += len(line)

                # 解析 SSE 事件
                if line.startswith("data: "):
                    data_str = line[6:]
                    sse_events += 1
                    try:
                        data = json.loads(data_str)
                        # 检测错误事件
                        if data.get("type") == "error":
                            error_msg = data.get("content", "未知错误")[:100]
                    except json.JSONDecodeError:
                        pass

            success = error_msg == ""
            return RequestResult(
                user_id=user_id, scenario=scenario_name, message=message,
                success=success, status_code=status_code,
                response_time=time.time() - start,
                first_byte_time=first_byte_time,
                sse_events=sse_events,
                content_length=content_length,
                error=error_msg,
            )

    except asyncio.TimeoutError:
        error_msg = f"请求超时（{timeout}s）"
    except aiohttp.ClientError as e:
        error_msg = f"连接错误: {str(e)[:100]}"
    except Exception as e:
        error_msg = f"异常: {str(e)[:100]}"

    return RequestResult(
        user_id=user_id, scenario=scenario_name, message=message,
        success=False, status_code=status_code,
        response_time=time.time() - start,
        first_byte_time=first_byte_time,
        sse_events=sse_events,
        content_length=content_length,
        error=error_msg,
    )


# ──────────────────────────────────────────────────────────
# 虚拟用户
# ──────────────────────────────────────────────────────────

async def virtual_user(
    session: aiohttp.ClientSession,
    url: str,
    user_id: int,
    scenario_key: str,
    duration: int,
    stats: TestStats,
    think_time: tuple = (1, 5),
):
    """模拟单个虚拟用户的完整使用流程"""
    scenario = SCENARIOS[scenario_key]
    messages = scenario["messages"]
    template = scenario["payload_template"].copy()
    timeout = scenario["timeout"]

    end_time = time.time() + duration
    msg_idx = 0

    while time.time() < end_time:
        # 混合场景：随机选择子场景
        if "sub_scenarios" in scenario:
            sub_key = random.choices(
                scenario["sub_scenarios"],
                weights=scenario["weights"],
                k=1,
            )[0]
            sub_scenario = SCENARIOS[sub_key]
            messages = sub_scenario["messages"]
            template = sub_scenario["payload_template"].copy()
            timeout = sub_scenario["timeout"]
            current_scenario = sub_scenario["name"]
        else:
            current_scenario = scenario["name"]

        # 选择消息（轮询或随机）
        message = messages[msg_idx % len(messages)]
        msg_idx += 1

        # 构造请求
        payload = template.copy()
        payload["message"] = message

        print(f"  [用户{user_id:3d}] {current_scenario}: {message[:30]}...")

        # 发送请求
        result = await send_request_and_collect(
            session, url, payload, user_id,
            current_scenario, message, timeout,
        )
        stats.add(result)

        status = "✓" if result.success else "✗"
        print(f"  [用户{user_id:3d}] {status} {result.response_time:.1f}s "
              f"events={result.sse_events} "
              f"{('' if result.success else result.error[:50])}")

        # 模拟用户思考时间
        think = random.uniform(*think_time)
        await asyncio.sleep(think)


# ──────────────────────────────────────────────────────────
# 阶梯加压控制
# ──────────────────────────────────────────────────────────

async def ramp_up_users(
    session: aiohttp.ClientSession,
    url: str,
    total_users: int,
    rampup: int,
    scenario: str,
    duration: int,
    stats: TestStats,
):
    """阶梯式启动虚拟用户"""
    tasks = []
    interval = rampup / total_users if total_users > 0 else 0

    for i in range(total_users):
        task = asyncio.create_task(
            virtual_user(session, url, i + 1, scenario, duration, stats)
        )
        tasks.append(task)
        if interval > 0:
            await asyncio.sleep(interval)
        print(f"  → 启动用户 {i+1}/{total_users}")

    # 等待所有用户完成
    await asyncio.gather(*tasks, return_exceptions=True)


# ──────────────────────────────────────────────────────────
# 主函数
# ──────────────────────────────────────────────────────────

async def run_test(args):
    url = f"{args.host}:{args.port}/api.php"
    print("=" * 60)
    print(f"MoonYa 压力测试")
    print("=" * 60)
    print(f"目标:        {url}")
    print(f"场景:        {SCENARIOS[args.scenario]['name']}")
    print(f"并发用户数:  {args.users}")
    print(f"持续时间:    {args.duration}s")
    print(f"加压时间:    {args.rampup}s")
    print(f"思考时间:    {args.think_min}-{args.think_max}s")
    print("=" * 60)

    stats = TestStats()
    stats.start_time = time.time()

    # 连接池配置
    connector = aiohttp.TCPConnector(
        limit=args.users * 2,
        limit_per_host=args.users * 2,
        keepalive_timeout=30,
    )

    async with aiohttp.ClientSession(connector=connector) as session:
        # 健康检查
        try:
            async with session.get(
                f"{args.host}:{args.port}/",
                timeout=aiohttp.ClientTimeout(total=5),
            ) as resp:
                print(f"\n健康检查: HTTP {resp.status}")
        except Exception as e:
            print(f"\n⚠ 健康检查失败: {e}")
            print("  请确认 MoonYa 服务已启动")
            return

        print(f"\n开始测试...\n")
        await ramp_up_users(
            session, url, args.users, args.rampup,
            args.scenario, args.duration, stats,
        )

    stats.end_time = time.time()

    # 输出报告
    print("\n" + "=" * 60)
    print("测试报告")
    print("=" * 60)

    summary = stats.summary()
    if isinstance(summary, str):
        print(summary)
        return

    print(f"\n总请求数:      {summary['total_requests']}")
    print(f"成功/失败:     {summary['success']}/{summary['failed']}")
    print(f"成功率:        {summary['success_rate']}")
    print(f"总耗时:        {summary['total_duration']}")
    print(f"QPS:           {summary['qps']}")
    print(f"\n响应时间:")
    print(f"  平均:        {summary['response_time']['avg']}")
    print(f"  P50:         {summary['response_time']['p50']}")
    print(f"  P95:         {summary['response_time']['p95']}")
    print(f"  P99:         {summary['response_time']['p99']}")
    print(f"  最大:        {summary['response_time']['max']}")
    print(f"\n首字节时间(TTFB):")
    print(f"  平均:        {summary['first_byte_time']['avg']}")
    print(f"  P50:         {summary['first_byte_time']['p50']}")
    print(f"  P95:         {summary['first_byte_time']['p95']}")
    print(f"\n平均SSE事件数: {summary['sse_events_avg']}")

    print(f"\n按场景统计:")
    for sc in summary["by_scenario"]:
        print(f"  {sc['scenario']:20s} 总数={sc['total']:4d} "
              f"成功率={sc['success_rate']:6s} 平均={sc['avg_time']}")

    # 错误统计
    errors = defaultdict(int)
    for r in stats.results:
        if not r.success and r.error:
            # 归类错误
            if "超时" in r.error:
                errors["超时"] += 1
            elif "连接" in r.error:
                errors["连接错误"] += 1
            elif "HTTP" in r.error:
                errors["HTTP错误"] += 1
            else:
                errors["其他"] += 1

    if errors:
        print(f"\n错误分布:")
        for err_type, count in errors.items():
            print(f"  {err_type}: {count}")

    print("\n" + "=" * 60)

    # 保存详细结果到 JSON
    if args.output:
        detail = {
            "summary": summary,
            "errors": dict(errors),
            "details": [
                {
                    "user_id": r.user_id,
                    "scenario": r.scenario,
                    "message": r.message,
                    "success": r.success,
                    "response_time": r.response_time,
                    "first_byte_time": r.first_byte_time,
                    "sse_events": r.sse_events,
                    "error": r.error,
                }
                for r in stats.results
            ],
        }
        with open(args.output, "w", encoding="utf-8") as f:
            json.dump(detail, f, ensure_ascii=False, indent=2)
        print(f"详细结果已保存: {args.output}")


def main():
    parser = argparse.ArgumentParser(description="MoonYa 压力测试工具")
    parser.add_argument("--host", default="http://127.0.0.1",
                        help="MoonYa 服务地址（默认 127.0.0.1）")
    parser.add_argument("--port", type=int, default=8080,
                        help="PHP 服务端口（默认 8080）")
    parser.add_argument("--scenario", default="chat",
                        choices=list(SCENARIOS.keys()),
                        help="测试场景（默认 chat）")
    parser.add_argument("--users", type=int, default=5,
                        help="并发用户数（默认 5）")
    parser.add_argument("--duration", type=int, default=60,
                        help="持续时间秒（默认 60）")
    parser.add_argument("--rampup", type=int, default=10,
                        help="加压时间秒（默认 10）")
    parser.add_argument("--think_min", type=float, default=1,
                        help="最小思考时间秒（默认 1）")
    parser.add_argument("--think_max", type=float, default=5,
                        help="最大思考时间秒（默认 5）")
    parser.add_argument("--output", default="",
                        help="结果输出 JSON 文件路径（可选）")

    args = parser.parse_args()
    args.think_time = (args.think_min, args.think_max)

    asyncio.run(run_test(args))


if __name__ == "__main__":
    main()
