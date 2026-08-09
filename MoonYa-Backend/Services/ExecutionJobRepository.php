<?php
declare(strict_types=1);

/** Persistent queue and exact SSE journal for detached Work/CU execution. */
final class ExecutionJobRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function isInstalled(): bool
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables"
                . " WHERE table_schema = DATABASE()"
                . " AND table_name IN ('execution_jobs','execution_job_events')"
            );
            return (int)$stmt->fetchColumn() === 2;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function enqueue(
        int $userId,
        int $conversationId,
        string $clientMessageId,
        string $mode,
        string $requestJson
    ): array {
        $jobId = self::uuid();
        $requestHash = hash('sha256', $requestJson);
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO execution_jobs
    (id, user_id, conversation_id, client_message_id, mode, request_json, request_hash, status)
VALUES (?, ?, ?, ?, ?, ?, ?, 'queued')
ON DUPLICATE KEY UPDATE id = id
SQL);
        $stmt->execute([
            $jobId,
            $userId,
            $conversationId,
            $clientMessageId,
            $mode,
            $requestJson,
            $requestHash,
        ]);

        $job = $this->findForOwner($userId, $conversationId, $clientMessageId);
        if (!is_array($job)) {
            throw new RuntimeException('execution_job_enqueue_failed');
        }
        return $job;
    }

    public function findForOwner(int $userId, int $conversationId, string $clientMessageId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM execution_jobs WHERE user_id = ? AND conversation_id = ? AND client_message_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $conversationId, $clientMessageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function find(string $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM execution_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function claim(string $jobId, string $workerId): ?array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE execution_jobs
SET status = 'running', worker_id = ?, claimed_at = CURRENT_TIMESTAMP,
    heartbeat_at = CURRENT_TIMESTAMP, attempt_count = attempt_count + 1,
    error_message = NULL
WHERE id = ? AND status = 'queued'
SQL);
        $stmt->execute([$workerId, $jobId]);
        return $stmt->rowCount() === 1 ? $this->find($jobId) : null;
    }

    public function heartbeat(string $jobId, string $workerId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE execution_jobs SET heartbeat_at = CURRENT_TIMESTAMP"
            . " WHERE id = ? AND worker_id = ? AND status = 'running'"
        );
        $stmt->execute([$jobId, $workerId]);
    }

    public function attachRun(string $jobId, string $runId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE execution_jobs SET run_id = ? WHERE id = ? AND status IN ('queued','running')"
        );
        $stmt->execute([$runId, $jobId]);
    }

    public function cancelForOwnerClient(int $userId, int $conversationId, string $clientMessageId): ?array
    {
        $job = $this->findForOwner($userId, $conversationId, $clientMessageId);
        if (!is_array($job)) {
            return null;
        }
        $this->finish((string)$job['id'], 'cancelled', '用户停止任务');
        $this->pdo->prepare(
            "UPDATE local_tool_requests SET status='cancelled', completed_at=CURRENT_TIMESTAMP"
            . " WHERE execution_job_id=? AND status='pending'"
        )->execute([(string)$job['id']]);
        return $this->find((string)$job['id']);
    }

    public function cancelForRun(int $userId, string $runId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE execution_jobs SET status='cancelled', error_message='用户停止任务',"
            . " heartbeat_at=CURRENT_TIMESTAMP, finished_at=CURRENT_TIMESTAMP"
            . " WHERE user_id=? AND run_id=? AND status IN ('queued','running')"
        );
        $stmt->execute([$userId, $runId]);
        $this->pdo->prepare(
            "UPDATE local_tool_requests SET status='cancelled', completed_at=CURRENT_TIMESTAMP"
            . " WHERE run_id=? AND user_id=? AND status='pending'"
        )->execute([$runId, $userId]);
    }

    public function appendFrame(string $jobId, string $frame): int
    {
        $frame = trim(str_replace("\r\n", "\n", $frame));
        if ($frame === '') {
            return 0;
        }

        [$eventType, $teamSeq, $requestId] = self::frameMetadata($frame);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT last_event_seq FROM execution_jobs WHERE id = ? FOR UPDATE');
            $lock->execute([$jobId]);
            $last = $lock->fetchColumn();
            if ($last === false) {
                throw new RuntimeException('execution_job_not_found');
            }
            $seq = (int)$last + 1;
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO execution_job_events (job_id, seq, event_type, team_seq, request_id, frame)
VALUES (?, ?, ?, ?, ?, ?)
SQL);
            $insert->execute([$jobId, $seq, $eventType, $teamSeq, $requestId, $frame . "\n\n"]);
            $update = $this->pdo->prepare(
                'UPDATE execution_jobs SET last_event_seq = ?, heartbeat_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $update->execute([$seq, $jobId]);
            $this->pdo->commit();
            return $seq;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function eventsAfter(string $jobId, int $afterSeq, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT seq, event_type, team_seq, request_id, frame
FROM execution_job_events
WHERE job_id = ? AND seq > ?
ORDER BY seq ASC
LIMIT ?
SQL);
        $stmt->bindValue(1, $jobId);
        $stmt->bindValue(2, max(0, $afterSeq), PDO::PARAM_INT);
        $stmt->bindValue(3, max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function finish(string $jobId, string $status, ?string $errorMessage = null): void
    {
        if (!in_array($status, ['completed', 'partial', 'failed', 'cancelled'], true)) {
            throw new InvalidArgumentException('invalid_execution_job_terminal_status');
        }
        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE execution_jobs
SET status = ?, error_message = ?, heartbeat_at = CURRENT_TIMESTAMP,
    finished_at = CURRENT_TIMESTAMP
WHERE id = ? AND status IN ('queued','running')
SQL);
        $stmt->execute([$status, $errorMessage, $jobId]);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, ['completed', 'partial', 'failed', 'cancelled'], true);
    }

    private static function frameMetadata(string $frame): array
    {
        $eventType = str_starts_with($frame, ':') ? 'heartbeat' : 'unknown';
        $teamSeq = null;
        $requestId = null;
        foreach (explode("\n", $frame) as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = json_decode(ltrim(substr($line, 5)), true);
            if (!is_array($payload)) {
                break;
            }
            $eventType = (string)($payload['type'] ?? 'message');
            if ($eventType === 'team_event' && isset($payload['seq'])) {
                $teamSeq = (int)$payload['seq'];
            }
            if ($eventType === 'launcher_request') {
                $requestId = trim((string)($payload['request_id'] ?? '')) ?: null;
            }
            break;
        }
        return [$eventType, $teamSeq, $requestId];
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
