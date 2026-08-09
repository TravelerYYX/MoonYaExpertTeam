<?php
// CuEventEmitter:封装 CU (Computer User) 模式的 SSE 事件发送,负载与 api.php 内联 echo 完全一致

if (!class_exists('CuEventEmitter')) {

class CuEventEmitter
{
    /**
     * 内部助手:统一发送 SSE data 事件 (json_encode + JSON_UNESCAPED_UNICODE + \n\n + flush)
     *
     * @param array $payload 要编码为 JSON 的负载数组
     * @return void
     */
    public function send(array $payload): void
    {
        echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    /**
     * cu_thinking — AI 推理文本
     * 注意:api.php 与前端 script-1e-rest.php 均使用 'content' 字段 (非 'text'),
     * 此处保持一致以确保前端零改动。
     *
     * @param string $text AI 思考/计划文本
     * @return void
     */
    public function thinking(string $text): void
    {
        $this->send([
            'type'    => 'cu_thinking',
            'content' => $text,
        ]);
    }

    /**
     * 模型增量文本常包含内部推理或工具协议，不能直接展示给用户。
     * 实时体验由 cu_status、cu_step 和 cu_action 提供；这些事件在首包后立即发送。
     */
    public function thinkingDelta(string $streamId, string $text): void
    {
        // Intentionally do not expose chain-of-thought or partial tool arguments.
    }

    /**
     * 不新增时间线节点的即时状态。用于模型请求刚开始时立即更新 CU 卡片标题。
     */
    public function status(string $text): void
    {
        $this->send([
            'type'    => 'cu_status',
            'content' => $text,
        ]);
    }

    /**
     * cu_screenshot — 屏幕截图 (base64)
     * timestamp 沿用 api.php 的 date('Y-m-d H:i:s') 字符串格式 (前端依赖 indexOf(' ') 切分时间标签)。
     *
     * @param string   $b64       base64 编码的 PNG 图像数据
     * @param int      $index     截图序号 (从 1 开始)
     * @param int|null $timestamp Unix 时间戳,为 null 时取当前时间;最终格式化为 'Y-m-d H:i:s'
     * @return void
     */
    public function screenshot(string $b64, int $index, ?int $timestamp = null): void
    {
        $ts = $timestamp ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s');
        $this->send([
            'type'      => 'cu_screenshot',
            'image'     => $b64,
            'index'     => $index,
            'timestamp' => $ts,
        ]);
    }

    /**
     * cu_action — 鼠标/键盘/UIA 动作
     * 同时包含 'action' 字段 (api.php/前端兼容) 与 'action_type'/'target'/'method' (新 UIA 模型字段),
     * 既保证前端 data.action 读取正常,又为未来 AIAssistant.php 提供 UIA 元信息。
     *
     * @param string $actionType  动作类型:click|move|scroll|type|key|click_element|set_text|get_text
     * @param string $target      人类可读的目标描述 (如 '按钮「提交」' 或 '(120, 340)')
     * @param string $method      UIA Pattern 名称:InvokePattern|TogglePattern|SendInput|ValuePattern|'' (非 UIA 为空)
     * @return void
     */
    public function action(string $actionType, string $target, string $method = ''): void
    {
        $this->send([
            'type'        => 'cu_action',
            'action_type' => $actionType,
            'action'      => $actionType,
            'target'      => $target,
            'method'      => $method,
            'timestamp'   => time(),
        ]);
    }

    /**
     * cu_step — 通用步骤 (screenshot/mouse/keyboard/complete/observe/find/warning/error)
     * step_index/step_type/text/status 与 api.php 完全一致;timestamp 为扩展字段 (前端忽略)。
     *
     * @param int    $stepIndex 步骤序号
     * @param string $stepType  步骤类型:screenshot|mouse|keyboard|complete|observe|find|warning|error
     * @param string $text      步骤中文描述
     * @param string $status    状态:running|done|warning|error
     * @return void
     */
    public function step(int $stepIndex, string $stepType, string $text, string $status = 'done'): void
    {
        $this->send([
            'type'       => 'cu_step',
            'step_index' => $stepIndex,
            'step_type'  => $stepType,
            'text'       => $text,
            'status'     => $status,
            'timestamp'  => time(),
        ]);
    }

    /**
     * cu_complete — 任务完成
     * summary/status 与 api.php 一致;step_count/timestamp 为扩展字段 (前端忽略)。
     *
     * @param string $summary   完成摘要文本
     * @param string $status    完成状态:success|limited|error
     * @param int    $stepCount 总步骤数
     * @return void
     */
    public function complete(string $summary, string $status, int $stepCount): void
    {
        $this->send([
            'type'       => 'cu_complete',
            'summary'    => $summary,
            'status'     => $status,
            'step_count' => $stepCount,
            'timestamp'  => time(),
        ]);
    }

    /**
     * cu_waiting_user — 桌面登录已到达安全边界，等待用户扫码、输入凭据或完成验证。
     */
    public function waitingUser(string $app, string $reason, string $prompt, int $remainingSeconds): void
    {
        $this->send([
            'type'              => 'cu_waiting_user',
            'app'               => $app,
            'reason'            => $reason,
            'prompt'            => $prompt,
            'remaining_seconds' => max(0, $remainingSeconds),
            'timestamp'         => time(),
        ]);
    }

    /**
     * launcher_request — 通过浏览器中继转发给 launcher 的请求 (供 callLauncherViaRelay 调试/展示)
     *
     * @param string       $requestId  请求唯一标识
     * @param string       $url        目标 URL (如 /cu-op)
     * @param string|array $body       请求体 (通常为 JSON 字符串或数组)
     * @return void
     */
    public function launcherRequest(string $requestId, string $url, $body): void
    {
        $this->send([
            'type'       => 'launcher_request',
            'request_id' => $requestId,
            'url'        => $url,
            'method'     => 'POST',
            'body'       => $body,
        ]);
    }

    /**
     * ba_screenshot — Browser Automation 截图 (base64)
     * index 由调用方在会话内单调递增维护,本方法仅接收并透传。
     *
     * @param string $imageBase64 base64 编码的图像数据
     * @param int    $index       截图序号 (会话内单调递增,由调用方维护)
     * @return void
     */
    public function baScreenshot(string $imageBase64, int $index): void
    {
        $this->send([
            'type'  => 'ba_screenshot',
            'image' => $imageBase64,
            'index' => $index,
        ]);
    }

    /**
     * ba_status — Browser Automation 状态/动作事件
     *
     * @param string $status 状态:starting|navigating|clicking|completed|error
     * @param string $action 动作:navigate|click|fill|screenshot
     * @return void
     */
    public function baStatus(string $status, string $action): void
    {
        $this->send([
            'type'   => 'ba_status',
            'status' => $status,
            'action' => $action,
        ]);
    }

    /**
     * vls_analysis — 视觉布局分析 (Visual Layout Structure) 结果
     * elements 为数组,每项含 type/css_selector/position/state 等键,结构由调用方决定。
     *
     * @param array  $elements 元素数组 (每项含 type/css_selector/position/state 等键)
     * @param string $summary  分析摘要文本
     * @return void
     */
    public function vlsAnalysis(array $elements, string $summary): void
    {
        $this->send([
            'type'     => 'vls_analysis',
            'elements' => $elements,
            'summary'  => $summary,
        ]);
    }

    /**
     * heartbeat — SSE keep-alive 注释 (非 data: 事件,仅维持连接)
     *
     * @return void
     */
    public function heartbeat(): void
    {
        echo ": heartbeat\n\n";
        flush();
    }

    /**
     * cu_plan — Plan 阶段步骤计划
     * 发送给前端展示规划步骤列表，让用户看到 AI 拆解的任务计划。
     *
     * @param array  $steps   步骤数组，每项含 id/title/task_type/expected_outcome
     * @param string $rawPlan 原始 JSON（调试用，可为空）
     * @return void
     */
    public function plan(array $steps, string $rawPlan = ''): void
    {
        $this->send([
            'type'      => 'cu_plan',
            'steps'     => $steps,
            'raw'       => $rawPlan,
            'timestamp' => time(),
        ]);
    }

    /**
     * cu_step_progress — 步骤执行进度
     * 每个步骤开始/执行中/验证中/完成/失败/重试时发送，驱动前端进度条。
     *
     * @param int    $stepIndex  当前步骤序号（从 1 开始）
     * @param int    $totalSteps 总步骤数
     * @param string $stepTitle  步骤标题
     * @param string $taskType   任务类型：drag|click|type|key|observe|scroll
     * @param string $status     状态：started|acting|verifying|completed|failed|retrying
     * @return void
     */
    public function stepProgress(int $stepIndex, int $totalSteps, string $stepTitle, string $taskType, string $status): void
    {
        $this->send([
            'type'        => 'cu_step_progress',
            'step_index'  => $stepIndex,
            'total_steps' => $totalSteps,
            'step_title'  => $stepTitle,
            'task_type'   => $taskType,
            'status'      => $status,
            'timestamp'   => time(),
        ]);
    }

    /**
     * cu_verify — Verify 阶段独立裁判结果
     * 独立视觉模型判断步骤是否真正完成，发送给前端展示验证结论。
     *
     * @param int    $stepIndex 步骤序号
     * @param bool   $completed 是否完成
     * @param string $reason    判断依据
     * @param string $missing   未完成时缺失的内容（完成时为空）
     * @param int    $round     当前验证轮次（0=首次，1+=补全轮次）
     * @return void
     */
    public function verify(int $stepIndex, bool $completed, string $reason, string $missing = '', int $round = 0): void
    {
        $this->send([
            'type'        => 'cu_verify',
            'step_index'  => $stepIndex,
            'completed'   => $completed,
            'reason'      => $reason,
            'missing'     => $missing,
            'verify_round'=> $round,
            'timestamp'   => time(),
        ]);
    }

    /**
     * done — 终止 SSE 流的通用结束事件
     *
     * @return void
     */
    public function done(): void
    {
        $this->send(['type' => 'done']);
    }
}

}
