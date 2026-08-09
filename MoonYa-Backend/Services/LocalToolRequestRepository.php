<?php
declare(strict_types=1);

/** Durable capability tickets for browser-to-local-launcher relay calls. */
final class LocalToolRequestRepository
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
                . " WHERE table_schema = DATABASE() AND table_name = 'local_tool_requests'"
            );
            return (int)$stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function create(
        string $requestId,
        string $token,
        string $endpoint,
        string $requestBody,
        int $timeoutSeconds,
        ?string $jobId,
        ?string $runId,
        ?int $userId
    ): void {
        $body = json_decode($requestBody, true);
        $operationId = is_array($body)
            ? trim((string)($body['operation_id'] ?? $body['operationId'] ?? ''))
            : '';
        $expiresSeconds = $timeoutSeconds > 0 ? max(30, $timeoutSeconds + 30) : 120;
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO local_tool_requests
    (id, execution_job_id, run_id, user_id, endpoint, request_body,
     operation_id, relay_token_hash, status, expires_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND))
SQL);
        $stmt->execute([
            $requestId,
            $jobId ?: null,
            $runId ?: null,
            $userId,
            $endpoint,
            $requestBody,
            $operationId !== '' ? $operationId : null,
            hash('sha256', $token),
            $expiresSeconds,
        ]);
    }

    public function find(string $requestId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM local_tool_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function complete(string $requestId, string $token, mixed $result): bool
    {
        $row = $this->find($requestId);
        if (!is_array($row)
            || $token === ''
            || !hash_equals((string)$row['relay_token_hash'], hash('sha256', $token))
        ) {
            return false;
        }
        if ((string)$row['status'] === 'completed') {
            return true;
        }
        if ((string)$row['status'] !== 'pending'
            || strtotime((string)$row['expires_at']) < time()
        ) {
            return false;
        }
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = '{"success":false,"message":"中继结果无法编码"}';
        }
        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE local_tool_requests
SET status = 'completed', result_json = ?, completed_at = CURRENT_TIMESTAMP
WHERE id = ? AND status = 'pending'
SQL);
        $stmt->execute([$encoded, $requestId]);
        return $stmt->rowCount() === 1 || (string)($this->find($requestId)['status'] ?? '') === 'completed';
    }

    public function touch(string $requestId, int $seconds = 120): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE local_tool_requests
SET expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND),
    delivery_count = delivery_count + 1,
    last_delivered_at = CURRENT_TIMESTAMP
WHERE id = ? AND status = 'pending'
SQL);
        $stmt->execute([max(30, $seconds), $requestId]);
    }

    public function finishPending(string $requestId, string $status): void
    {
        if (!in_array($status, ['failed', 'cancelled', 'expired'], true)) {
            throw new InvalidArgumentException('invalid_local_tool_terminal_status');
        }
        $stmt = $this->pdo->prepare(
            "UPDATE local_tool_requests SET status = ?, completed_at = CURRENT_TIMESTAMP"
            . " WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$status, $requestId]);
    }
}
