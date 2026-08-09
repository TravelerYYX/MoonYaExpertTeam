<?php
declare(strict_types=1);

require_once __DIR__ . '/ExecutionJobRepository.php';

final class ExecutionGuardOutput
{
    private ExecutionJobRepository $repository;
    private string $jobId;
    private string $workerId;
    private string $buffer = '';
    private bool $sawDone = false;
    private bool $sawError = false;
    private bool $sawTerminalEvent = false;
    private string $terminalStatus = 'completed';
    private float $lastHeartbeatAt = 0.0;

    public function __construct(
        ExecutionJobRepository $repository,
        string $jobId,
        string $workerId
    ) {
        $this->repository = $repository;
        $this->jobId = $jobId;
        $this->workerId = $workerId;
    }

    public function consume(string $chunk): string
    {
        $this->buffer .= str_replace("\r\n", "\n", $chunk);
        while (($offset = strpos($this->buffer, "\n\n")) !== false) {
            $frame = substr($this->buffer, 0, $offset);
            $this->buffer = substr($this->buffer, $offset + 2);
            $this->persist($frame);
        }
        $this->heartbeat();
        return '';
    }

    public function finalize(): void
    {
        if (trim($this->buffer) !== '') {
            $this->persist($this->buffer);
            $this->buffer = '';
        }

        $fatal = error_get_last();
        $isFatal = is_array($fatal) && in_array(
            (int)($fatal['type'] ?? 0),
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
            true
        );
        if ($isFatal && !$this->sawError) {
            $this->repository->appendFrame($this->jobId, 'data: ' . json_encode([
                'type' => 'error',
                'content' => '执行进程发生致命错误。',
                'error_code' => 'execution_guard_fatal',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->sawError = true;
        }
        if (!$this->sawDone) {
            $this->repository->appendFrame($this->jobId, 'data: {"type":"done"}');
        }

        $status = $isFatal
            ? 'failed'
            : ($this->sawTerminalEvent
                ? $this->terminalStatus
                : ($this->sawError ? 'failed' : $this->terminalStatus));
        $message = $isFatal ? (string)($fatal['message'] ?? 'fatal error') : null;
        $this->repository->finish($this->jobId, $status, $message);
    }

    private function persist(string $frame): void
    {
        if (trim($frame) === '') {
            return;
        }
        foreach (explode("\n", $frame) as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = json_decode(ltrim(substr($line, 5)), true);
            if (!is_array($payload)) {
                continue;
            }
            $type = (string)($payload['type'] ?? '');
            if ($type === 'team_event' && !empty($payload['run_id'])) {
                $this->repository->attachRun($this->jobId, (string)$payload['run_id']);
            }
            if ($type === 'done') {
                $this->sawDone = true;
            } elseif ($type === 'error') {
                $this->sawError = true;
            } elseif ($type === 'team_event' && in_array(
                $eventName = (string)($payload['event'] ?? ''),
                ['run.completed', 'run.failed', 'run.cancelled'],
                true
            )) {
                $defaultStatus = match ($eventName) {
                    'run.failed' => 'failed',
                    'run.cancelled' => 'cancelled',
                    default => 'completed',
                };
                $status = (string)($payload['payload']['status'] ?? $defaultStatus);
                if (in_array($status, ['completed', 'partial', 'failed', 'cancelled'], true)) {
                    $this->terminalStatus = $status;
                }
                $this->sawTerminalEvent = true;
            }
        }
        $this->repository->appendFrame($this->jobId, $frame);
    }

    private function heartbeat(): void
    {
        if (microtime(true) - $this->lastHeartbeatAt < 5.0) {
            return;
        }
        $this->repository->heartbeat($this->jobId, $this->workerId);
        $this->lastHeartbeatAt = microtime(true);
    }
}
