<?php
declare(strict_types=1);

/**
 * Detects deterministic cycles from completed tool calls and their results.
 *
 * The guard deliberately has no time or iteration limit. In-flight work and
 * external waits are ignored; only completed, stable observations enter the
 * window. A cycle is allowed one recovery before the same cycle is terminal.
 */
final class AgentLoopGuard
{
    private int $repeatCount;
    private int $maxPeriod;
    private int $recoveryAttempts;
    /** @var array<string,list<string>> */
    private array $history = [];
    /** @var array<string,array<string,int>> */
    private array $recoveries = [];

    private const VOLATILE_KEYS = [
        'id', 'call_id', 'tool_call_id', 'request_id', 'response_id',
        'created_at', 'updated_at', 'started_at', 'finished_at', 'expires_at',
        'timestamp', 'time', 'duration', 'duration_ms', 'elapsed', 'elapsed_ms',
        'heartbeat_at', 'output_revision', 'sequence', 'seq',
    ];

    private const WAITING_STATES = [
        'model_thinking', 'tool_running', 'running', 'pending',
        'waiting_external', 'waiting_approval', 'waiting_resource',
        'inspection_running', 'action_running', 'verification_running',
    ];

    public function __construct(int $repeatCount = 3, int $maxPeriod = 4, int $recoveryAttempts = 1)
    {
        $this->repeatCount = max(2, $repeatCount);
        $this->maxPeriod = max(1, $maxPeriod);
        $this->recoveryAttempts = max(0, $recoveryAttempts);
    }

    /**
     * @param list<array<string,mixed>> $calls
     * @param list<array<string,mixed>> $results
     * @return array{action:string,period?:int,cycle?:string,evidence?:array<string,mixed>}
     */
    public function observe(string $scope, array $calls, array $results): array
    {
        $scope = $scope !== '' ? $scope : 'default';
        $observed = false;
        foreach ($calls as $index => $call) {
            $result = $results[$index] ?? null;
            if (!is_array($result) || $this->isWaitingResult($result)) {
                continue;
            }
            $observed = true;
            $this->history[$scope][] = $this->stepFingerprint($call, $result);
        }

        if (!$observed) {
            return ['action' => 'ignored'];
        }

        // No historical count is a limit. This trim only bounds memory beyond
        // the largest suffix that can participate in the configured detector.
        $windowSize = $this->maxPeriod * $this->repeatCount;
        if (count($this->history[$scope]) > $windowSize) {
            $this->history[$scope] = array_slice($this->history[$scope], -$windowSize);
        }

        $match = $this->detectSuffixCycle($this->history[$scope]);
        if ($match === null) {
            return ['action' => 'continue'];
        }

        $cycleKey = hash('sha256', implode("\n", $match['steps']));
        $attempts = (int)($this->recoveries[$scope][$cycleKey] ?? 0);
        $evidence = [
            'period' => $match['period'],
            'repeat_count' => $this->repeatCount,
            'cycle_fingerprint' => $cycleKey,
            'step_fingerprints' => $match['steps'],
        ];

        // Reset the observation window after a decision so the repeated cycle
        // must happen in full again before it can become terminal.
        $this->history[$scope] = [];
        if ($attempts < $this->recoveryAttempts) {
            $this->recoveries[$scope][$cycleKey] = $attempts + 1;
            return [
                'action' => 'recover',
                'period' => $match['period'],
                'cycle' => $cycleKey,
                'evidence' => $evidence,
            ];
        }

        return [
            'action' => 'stop',
            'period' => $match['period'],
            'cycle' => $cycleKey,
            'evidence' => $evidence,
        ];
    }

    /** @return array{period:int,steps:list<string>}|null */
    private function detectSuffixCycle(array $history): ?array
    {
        $count = count($history);
        for ($period = 1; $period <= $this->maxPeriod; $period++) {
            $required = $period * $this->repeatCount;
            if ($count < $required) {
                continue;
            }
            $suffix = array_slice($history, -$required);
            $pattern = array_slice($suffix, 0, $period);
            $matches = true;
            for ($repeat = 1; $repeat < $this->repeatCount; $repeat++) {
                if (array_slice($suffix, $repeat * $period, $period) !== $pattern) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return ['period' => $period, 'steps' => $pattern];
            }
        }
        return null;
    }

    private function stepFingerprint(array $call, array $result): string
    {
        $name = strtolower(trim((string)($call['function']['name'] ?? $call['name'] ?? 'unknown')));
        $arguments = $call['function']['arguments'] ?? $call['arguments'] ?? [];
        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : ['raw' => trim($arguments)];
        }
        if (!is_array($arguments)) {
            $arguments = ['value' => $arguments];
        }

        $payload = $name === 'delegate_to_agents'
            ? $this->delegationFingerprint($arguments, $result)
            : [
                'tool' => $name,
                'arguments' => $this->stableValue($arguments),
                'result' => $this->resultFingerprint($result),
            ];
        return hash('sha256', $this->stableJson($payload));
    }

    private function delegationFingerprint(array $arguments, array $result): array
    {
        $tasks = is_array($arguments['tasks'] ?? null) ? $arguments['tasks'] : [];
        $capabilityById = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $taskId = (string)($task['id'] ?? '');
            $capabilityById[$taskId] = strtolower(trim((string)($task['capability_key'] ?? 'unknown')));
        }

        $normalizedTasks = [];
        $rawResults = is_array($result['structured_content'] ?? null)
            ? $result['structured_content']
            : [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $taskId = (string)($task['id'] ?? '');
            $dependencies = [];
            foreach ((array)($task['depends_on'] ?? []) as $dependency) {
                $dependencies[] = $capabilityById[(string)$dependency] ?? 'unknown';
            }
            sort($dependencies, SORT_STRING);
            $normalizedTasks[] = [
                'capability' => $capabilityById[$taskId] ?? 'unknown',
                'depends_on' => $dependencies,
                'result' => $this->resultFingerprint(
                    is_array($rawResults[$taskId] ?? null) ? $rawResults[$taskId] : []
                ),
            ];
        }
        usort($normalizedTasks, static fn(array $a, array $b): int => strcmp(
            json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));

        return [
            'tool' => 'delegate_to_agents',
            'dispatch_status' => (string)($result['metadata']['dispatch_status'] ?? ''),
            'tasks' => $normalizedTasks,
            'error_code' => (string)($result['error']['code'] ?? ''),
            'artifacts' => $this->artifactFingerprint((array)($result['artifacts'] ?? [])),
        ];
    }

    private function resultFingerprint(array $result): array
    {
        $structured = $result['structured_content'] ?? null;
        return [
            'ok' => ($result['ok'] ?? (($result['status'] ?? '') === 'success')) === true,
            'status' => (string)($result['status'] ?? $result['metadata']['status'] ?? ''),
            'error_code' => (string)($result['error']['code'] ?? $result['code'] ?? ''),
            'structured' => $this->stableValue($structured),
            'receipt' => $this->stableValue($result['metadata']['operation_receipt'] ?? null),
            'artifacts' => $this->artifactFingerprint((array)($result['artifacts'] ?? [])),
        ];
    }

    private function artifactFingerprint(array $artifacts): array
    {
        $stable = [];
        foreach ($artifacts as $artifact) {
            if (is_array($artifact)) {
                $stable[] = $this->stableValue($artifact);
            } elseif (is_scalar($artifact)) {
                $stable[] = (string)$artifact;
            }
        }
        usort($stable, fn($a, $b): int => strcmp($this->stableJson($a), $this->stableJson($b)));
        return $stable;
    }

    private function isWaitingResult(array $result): bool
    {
        $candidates = [
            $result['status'] ?? null,
            $result['state'] ?? null,
            $result['structured_content']['status'] ?? null,
            $result['structured_content']['state'] ?? null,
            $result['metadata']['status'] ?? null,
            $result['metadata']['operation_receipt']['state'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array(strtolower(trim($candidate)), self::WAITING_STATES, true)) {
                return true;
            }
        }
        return false;
    }

    private function stableValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), self::VOLATILE_KEYS, true)) {
            return null;
        }
        if (!is_array($value)) {
            return is_string($value) ? trim($value) : $value;
        }
        $isList = array_is_list($value);
        $stable = [];
        foreach ($value as $childKey => $childValue) {
            if (!$isList && in_array(strtolower((string)$childKey), self::VOLATILE_KEYS, true)) {
                continue;
            }
            // Human-facing prose is deliberately not evidence of progress.
            if (!$isList && in_array(strtolower((string)$childKey), [
                'content', 'message', 'summary', 'instruction', 'context', 'selection_reason',
            ], true)) {
                continue;
            }
            $stable[$childKey] = $this->stableValue($childValue, (string)$childKey);
        }
        if (!$isList) {
            ksort($stable, SORT_STRING);
        }
        return $stable;
    }

    private function stableJson(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
