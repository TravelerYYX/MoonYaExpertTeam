<?php
declare(strict_types=1);

require_once __DIR__ . '/CuEventEmitter.php';
require_once __DIR__ . '/TeamEventEmitter.php';

/**
 * Adapts the existing CU runtime to TeamEventV1. Reasoning and final text use
 * the same streaming turn protocol as coordinator and employee Agents.
 */
final class TeamCuEventEmitter extends CuEventEmitter
{
    private TeamEventEmitter $team;
    private string $turnId = 'direct-cu-turn';
    private string $lastSummary = '';
    private string $lastStatus = 'limited';
    private ?string $lastActionCallId = null;
    private string $lastActionName = 'computer_action';

    public function __construct(TeamEventEmitter $team)
    {
        $this->team = $team;
        $this->team->startTurn(
            $this->turnId,
            'computer',
            'moonya',
            'direct-cu',
            ['mode' => 'computer_user']
        );
    }

    public function send(array $payload): void
    {
        $type = (string)($payload['type'] ?? 'cu_status');
        if ($type === 'cu_thinking') {
            $this->team->emitDelta(
                'reasoning',
                (string)($payload['content'] ?? ''),
                $this->turnId,
                'computer',
                'moonya',
                'direct-cu'
            );
            return;
        }
        if ($type === 'cu_action') {
            $this->completeLastAction(true);
            $this->lastActionCallId = 'cu-' . ($payload['timestamp'] ?? microtime(true));
            $this->lastActionName = (string)($payload['action_type'] ?? $payload['action'] ?? 'computer_action');
            $this->team->emit(
                'tool.started',
                [
                    'tool_key' => $this->lastActionName,
                    'display_name' => '桌面操作',
                    'target' => $payload['target'] ?? '',
                    'method' => $payload['method'] ?? '',
                ],
                'computer',
                'moonya',
                'direct-cu',
                $this->lastActionCallId
            );
            return;
        }
        if ($type === 'cu_screenshot') {
            // TeamEventEmitter extracts these bytes to WorkLogMedia before the
            // TeamEventV1 envelope is persisted or streamed.
            $this->team->emit(
                'tool.completed',
                [
                    'tool_key' => 'take_screenshot',
                    'ok' => true,
                    'content' => '已获取屏幕快照',
                    'index' => $payload['index'] ?? null,
                    'screenshot' => $payload['screenshot'] ?? $payload['image'] ?? $payload['data'] ?? null,
                    'mime_type' => (string)($payload['mime_type'] ?? 'image/png'),
                    'source' => 'cu_screenshot',
                ],
                'computer',
                'moonya',
                'direct-cu',
                'cu-screenshot-' . ($payload['index'] ?? time())
            );
            return;
        }
        if ($type === 'cu_step' || $type === 'cu_step_progress' || $type === 'cu_verify' || $type === 'cu_plan') {
            $this->completeLastAction(($payload['status'] ?? '') !== 'failed');
            $this->team->emit(
                'agent.summary',
                [
                    'title' => $type === 'cu_plan' ? 'Computer Agent 计划' : 'Computer Agent 步骤',
                    'summary' => (string)($payload['text'] ?? $payload['step_title'] ?? $payload['reason'] ?? ''),
                    'status' => $payload['status'] ?? null,
                    'step_index' => $payload['step_index'] ?? null,
                    'steps' => $payload['steps'] ?? null,
                ],
                'computer',
                'moonya',
                'direct-cu'
            );
            return;
        }
        if ($type === 'cu_waiting_user') {
            $this->team->emit(
                'approval.required',
                [
                    'reason' => (string)($payload['reason'] ?? '需要用户完成敏感交互'),
                    'prompt' => (string)($payload['prompt'] ?? ''),
                    'app' => (string)($payload['app'] ?? ''),
                    'external_user_action' => true,
                ],
                'computer',
                'moonya',
                'direct-cu'
            );
            return;
        }
        if ($type === 'cu_complete') {
            $this->completeLastAction(in_array(
                (string)($payload['status'] ?? 'limited'),
                ['success', 'completed'],
                true
            ));
            $this->lastSummary = (string)($payload['summary'] ?? 'Computer User 任务已结束');
            $this->lastStatus = (string)($payload['status'] ?? 'limited');
            $this->team->emitDelta(
                'content',
                $this->lastSummary,
                $this->turnId,
                'computer',
                'moonya',
                'direct-cu'
            );
            $this->team->completeTurn(
                $this->turnId,
                $this->normalizedCompletionStatus(),
                ['step_count' => $payload['step_count'] ?? null]
            );
            $event = in_array($this->lastStatus, ['success', 'completed'], true)
                ? 'agent.completed'
                : 'agent.failed';
            $this->team->emit(
                $event,
                [
                    'status' => $this->lastStatus,
                    'summary' => $this->lastSummary,
                    'step_count' => $payload['step_count'] ?? null,
                ],
                'computer',
                'moonya',
                'direct-cu'
            );
            return;
        }
        if ($type === 'cu_status') {
            $this->team->emit(
                'agent.summary',
                [
                    'title' => 'Computer Agent',
                    'summary' => (string)($payload['content'] ?? ''),
                ],
                'computer',
                'moonya',
                'direct-cu'
            );
            return;
        }
        if ($type === 'cu_route') {
            $this->team->emit(
                'capability.route.selected',
                [
                    'route_class' => (string)($payload['route_class'] ?? ''),
                    'tool_key' => (string)($payload['tool_key'] ?? ''),
                    'fallback_reason' => (string)($payload['fallback_reason'] ?? ''),
                    'ok' => $payload['ok'] ?? null,
                ],
                'computer',
                'moonya',
                'direct-cu'
            );
            return;
        }
        // Browser-specific CU events are represented as structured tool events.
        $this->team->emit(
            'tool.completed',
            ['tool_key' => $type, 'ok' => true, 'content' => 'Computer Agent 已更新工具状态'],
            'computer',
            'moonya',
            'direct-cu'
        );
    }

    public function done(): void
    {
        // api.php emits one final MoonYa response and done event after CU returns.
    }

    public function summary(): string
    {
        return $this->lastSummary !== '' ? $this->lastSummary : 'Computer User 任务已结束。';
    }

    public function completionStatus(): string
    {
        return $this->normalizedCompletionStatus();
    }

    private function normalizedCompletionStatus(): string
    {
        if (in_array($this->lastStatus, ['success', 'completed'], true)) {
            return 'completed';
        }
        if ($this->lastStatus === 'cancelled') {
            return 'cancelled';
        }
        if (in_array($this->lastStatus, ['error', 'failed'], true)) {
            return 'failed';
        }
        return 'partial';
    }

    private function completeLastAction(bool $ok): void
    {
        if ($this->lastActionCallId === null) {
            return;
        }
        $this->team->emit(
            'tool.completed',
            [
                'tool_key' => $this->lastActionName,
                'ok' => $ok,
                'content' => $ok ? '桌面操作已完成' : '桌面操作未完成',
            ],
            'computer',
            'moonya',
            'direct-cu',
            $this->lastActionCallId
        );
        $this->lastActionCallId = null;
    }
}
