<?php
declare(strict_types=1);

require_once __DIR__ . '/TeamMediaStore.php';

final class TeamEventEmitter
{
    private TeamRepository $repository;
    private string $runId;
    private int $seq = 0;
    private array $agents = [];
    private array $turns = [];
    private array $pendingDeltas = [];
    private int $pendingDeltaOrder = 0;
    private float $deltaFlushInterval;
    private int $deltaFlushBytes;
    private TeamMediaStore $mediaStore;

    public function __construct(TeamRepository $repository, string $runId)
    {
        $this->repository = $repository;
        $this->runId = $runId;
        // A recovered executor continues the same TeamEventV1 stream. Starting
        // again at sequence 1 would violate the unique (run_id, seq) contract.
        $this->seq = $repository->lastEventSequence($runId);
        $this->mediaStore = new TeamMediaStore($repository);
        $this->deltaFlushInterval = max(
            0.02,
            ((int)$repository->runtimeConfig('stream_delta_flush_ms', 50)) / 1000
        );
        $this->deltaFlushBytes = max(1024, (int)$repository->runtimeConfig('stream_delta_flush_bytes', 4096));
        foreach ($repository->listAgents(false) as $agent) {
            $this->agents[$agent['agent_key']] = [
                'key' => $agent['agent_key'],
                'name' => $agent['display_name'],
                'avatar_url' => $agent['avatar_url'],
                'role_summary' => $agent['role_summary'],
            ];
        }
    }

    public function emit(
        string $event,
        array $payload = [],
        ?string $agentKey = null,
        ?string $parentAgentKey = null,
        ?string $taskId = null,
        ?string $toolCallId = null
    ): array {
        $this->flushDeltas(true);
        $this->seq++;
        // Extract image bytes before either the SSE envelope or persisted event
        // is encoded. TeamEventV1 only carries authenticated media references.
        $payload = $this->mediaStore->extractFromPayload(
            $this->runId,
            $taskId,
            $toolCallId,
            $this->seq,
            $event,
            $payload
        );
        $envelope = [
            'type' => 'team_event',
            'version' => 1,
            'run_id' => $this->runId,
            'seq' => $this->seq,
            'event' => $event,
            'timestamp' => gmdate('c'),
            'agent' => $agentKey !== null ? ($this->agents[$agentKey] ?? ['key' => $agentKey, 'name' => $agentKey]) : null,
            'parent_agent_key' => $parentAgentKey,
            'task_id' => $taskId,
            'tool_call_id' => $toolCallId,
            'payload' => TeamRepository::redact(TeamRepository::withoutEmbeddedImageData($payload)),
        ];
        $this->repository->persistEvent(
            $this->runId,
            $this->seq,
            $event,
            $agentKey,
            $parentAgentKey,
            $taskId,
            $toolCallId,
            $payload
        );
        echo 'data: ' . json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        if (function_exists('streamFlush')) {
            streamFlush();
        } else {
            flush();
        }
        return $envelope;
    }

    public function emitTransient(
        string $event,
        array $payload = [],
        ?string $agentKey = null,
        ?string $parentAgentKey = null,
        ?string $taskId = null,
        ?string $toolCallId = null
    ): array {
        $this->seq++;
        $envelope = $this->envelope(
            $event,
            $payload,
            $agentKey,
            $parentAgentKey,
            $taskId,
            $toolCallId
        );
        $this->writeEnvelope($envelope);
        return $envelope;
    }

    public function startTurn(
        string $turnId,
        string $agentKey,
        ?string $parentAgentKey = null,
        ?string $taskId = null,
        array $metadata = []
    ): void {
        if (isset($this->turns[$turnId])) {
            return;
        }
        $this->turns[$turnId] = [
            'agent_key' => $agentKey,
            'parent_agent_key' => $parentAgentKey,
            'task_id' => $taskId,
            'reasoning_content' => '',
            'content' => '',
            'received_reasoning_chars' => 0,
            'received_content_chars' => 0,
            'metadata' => $metadata,
        ];
        $this->emit(
            'agent.turn.started',
            array_merge(
                ['turn_id' => $turnId],
                array_intersect_key($metadata, array_flip([
                    'phase', 'round', 'model', 'project_group_id', 'actor_id',
                    'role_key', 'role_label', 'workstream', 'owned_paths',
                    'depends_on', 'project_phase',
                ])),
                ['metadata' => $metadata]
            ),
            $agentKey,
            $parentAgentKey,
            $taskId
        );
    }

    public function emitDelta(
        string $kind,
        string $content,
        string $turnId,
        string $agentKey,
        ?string $parentAgentKey = null,
        ?string $taskId = null
    ): void {
        if ($content === '') {
            return;
        }
        if (!isset($this->turns[$turnId])) {
            $this->startTurn($turnId, $agentKey, $parentAgentKey, $taskId);
        }
        $field = $kind === 'reasoning' ? 'reasoning_content' : 'content';
        $this->turns[$turnId][$field] .= $content;
        $counter = $kind === 'reasoning' ? 'received_reasoning_chars' : 'received_content_chars';
        $this->turns[$turnId][$counter] += mb_strlen($content);
        $key = $turnId . ':' . $kind;
        if (!isset($this->pendingDeltas[$key])) {
            $this->pendingDeltas[$key] = [
                'turn_id' => $turnId,
                'kind' => $kind,
                'content' => '',
                'bytes' => 0,
                'queued_at' => microtime(true),
                'order' => ++$this->pendingDeltaOrder,
                'agent_key' => $agentKey,
                'parent_agent_key' => $parentAgentKey,
                'task_id' => $taskId,
            ];
        }
        $this->pendingDeltas[$key]['content'] .= $content;
        $this->pendingDeltas[$key]['bytes'] += strlen($content);
        // ★ 修复：final_synthesis 阶段（最终汇总回复）强制立即刷新，
        //   避免 curl WRITEFUNCTION 一次回调处理多个 SSE 事件时所有 delta 在同一
        //   microtime 入队导致 50ms 时间窗口判定始终不满足，内容被全部缓冲到
        //   completeTurn 才一次性输出（左边对话区域非流式问题根因）。
        //   coordination 等中间轮次仍保留 50ms/4KB 合并刷新以减少 RIGHT 面板 I/O 抖动。
        $forceFlush = ($GLOBALS['teamRootTurnPhase'] ?? '') === 'final_synthesis';
        $this->flushDeltas($forceFlush);
    }

    public function flushDeltas(bool $force = false): void
    {
        if ($this->pendingDeltas === []) {
            return;
        }
        uasort(
            $this->pendingDeltas,
            static fn(array $left, array $right): int => $left['order'] <=> $right['order']
        );
        $now = microtime(true);
        foreach (array_keys($this->pendingDeltas) as $key) {
            $pending = $this->pendingDeltas[$key];
            if (!$force
                && $pending['bytes'] < $this->deltaFlushBytes
                && $now - $pending['queued_at'] < $this->deltaFlushInterval
            ) {
                continue;
            }
            unset($this->pendingDeltas[$key]);
            $turn = $this->turns[$pending['turn_id']] ?? [];
            $counter = $pending['kind'] === 'reasoning'
                ? 'received_reasoning_chars'
                : 'received_content_chars';
            $this->emitTransient(
                $pending['kind'] === 'reasoning' ? 'agent.reasoning.delta' : 'agent.content.delta',
                array_merge(
                    [
                        'turn_id' => $pending['turn_id'],
                        'delta' => $pending['content'],
                        'received_chars' => (int)($turn[$counter] ?? mb_strlen($pending['content'])),
                    ],
                    array_intersect_key(
                        (array)($turn['metadata'] ?? []),
                        array_flip([
                            'phase', 'round', 'project_group_id', 'actor_id',
                            'role_key', 'role_label', 'workstream', 'owned_paths',
                            'depends_on', 'project_phase',
                        ])
                    )
                ),
                $pending['agent_key'],
                $pending['parent_agent_key'],
                $pending['task_id']
            );
        }
    }

    public function completeTurn(
        string $turnId,
        string $status = 'completed',
        array $metadata = []
    ): void {
        $turn = $this->turns[$turnId] ?? null;
        if (!is_array($turn)) {
            return;
        }
        $this->flushDeltas(true);
        $contentDiscarded = (bool)($metadata['discard_content'] ?? false);
        unset($metadata['discard_content']);
        if ($contentDiscarded) {
            $turn['content'] = '';
        }
        $content = trim((string)$turn['content']);
        $reasoning = trim((string)$turn['reasoning_content']);
        $summarySource = trim((string)($metadata['summary'] ?? ''));
        if ($summarySource === '') {
            $summarySource = $content !== '' ? $content : $reasoning;
        }
        $summary = preg_replace('/\s+/u', ' ', $summarySource) ?? $summarySource;
        if (mb_strlen($summary) > 240) {
            $summary = mb_substr($summary, 0, 240) . '…';
        }
        $this->emit(
            'agent.turn.completed',
            array_merge([
                'turn_id' => $turnId,
                'status' => $status,
                'reasoning_content' => $turn['reasoning_content'],
                'content' => $turn['content'],
                'content_discarded' => $contentDiscarded,
                'received_reasoning_chars' => (int)($turn['received_reasoning_chars'] ?? mb_strlen($turn['reasoning_content'])),
                'received_content_chars' => (int)($turn['received_content_chars'] ?? mb_strlen($turn['content'])),
                'summary' => $summary,
                'metadata' => array_merge($turn['metadata'], $metadata),
            ], array_intersect_key(
                array_merge($turn['metadata'], $metadata),
                array_flip([
                    'phase', 'round', 'finish_reason', 'project_group_id',
                    'actor_id', 'role_key', 'role_label', 'workstream',
                    'owned_paths', 'depends_on', 'project_phase',
                ])
            )),
            $turn['agent_key'],
            $turn['parent_agent_key'],
            $turn['task_id']
        );
        unset($this->turns[$turnId]);
    }

    /**
     * 获取指定轮次已累积的内容（在 completeTurn 之前调用）。
     */
    public function getTurnContent(string $turnId): string
    {
        return (string)($this->turns[$turnId]['content'] ?? '');
    }

    /**
     * Stream a coordinator delta from deeply nested api.php callbacks without
     * capturing the emitter in every legacy closure.
     */
    public static function activeDelta(string $kind, string $content): bool
    {
        $emitter = $GLOBALS['teamEventEmitter'] ?? null;
        if (!$emitter instanceof self || empty($GLOBALS['multiAgentTeamEnabled'])) {
            return false;
        }
        // ★ 修复：Image Agent 证据场景下，content 走 legacy 事件流式输出到左侧对话区域，
        //   reasoning 不受影响，仍走 team_event 到右侧工作日志。
        if ($kind === 'content' && !empty($GLOBALS['teamRootLegacyContent'])) {
            return false;
        }
        if ($kind === 'reasoning') {
            $GLOBALS['teamRootLastReasoning'] = (string)($GLOBALS['teamRootLastReasoning'] ?? '') . $content;
        } else {
            $GLOBALS['teamRootContentStreamed'] = true;
        }
        $turnId = trim((string)($GLOBALS['teamRootTurnId'] ?? ''));
        if ($turnId === '') {
            return false;
        }
        $emitter->emitDelta($kind, $content, $turnId, 'moonya');
        return true;
    }

    private function envelope(
        string $event,
        array $payload,
        ?string $agentKey,
        ?string $parentAgentKey,
        ?string $taskId,
        ?string $toolCallId
    ): array {
        return [
            'type' => 'team_event',
            'version' => 1,
            'run_id' => $this->runId,
            'seq' => $this->seq,
            'event' => $event,
            'timestamp' => gmdate('c'),
            'agent' => $agentKey !== null ? ($this->agents[$agentKey] ?? ['key' => $agentKey, 'name' => $agentKey]) : null,
            'parent_agent_key' => $parentAgentKey,
            'task_id' => $taskId,
            'tool_call_id' => $toolCallId,
            'payload' => TeamRepository::redact(TeamRepository::withoutEmbeddedImageData($payload)),
        ];
    }

    private function writeEnvelope(array $envelope): void
    {
        echo 'data: ' . json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        if (function_exists('streamFlush')) {
            streamFlush();
        } else {
            flush();
        }
    }

    public function heartbeat(): void
    {
        $this->flushDeltas(false);
        echo ": team-heartbeat\n\n";
        if (function_exists('streamFlush')) {
            streamFlush();
        } else {
            flush();
        }
    }

    public function runId(): string
    {
        return $this->runId;
    }
}
