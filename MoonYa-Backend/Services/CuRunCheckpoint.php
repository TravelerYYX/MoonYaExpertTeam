<?php
declare(strict_types=1);

/**
 * Durable CU execution checkpoint and single-operation idempotency record.
 * Image bytes are deliberately excluded; callers persist only media references.
 */
final class CuRunCheckpoint
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cu_run_checkpoints (
  run_id CHAR(36) NOT NULL,
  user_id INT NOT NULL,
  conversation_id INT NOT NULL,
  client_message_id CHAR(36) NOT NULL,
  status ENUM('running','recovering','completed','failed','cancelled') NOT NULL DEFAULT 'running',
  current_layer VARCHAR(32) NOT NULL DEFAULT 'router',
  iteration_no INT UNSIGNED NOT NULL DEFAULT 0,
  last_event_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
  model_messages_json LONGTEXT NULL,
  state_json LONGTEXT NULL,
  pending_operation_id VARCHAR(64) NULL,
  pending_operation_json LONGTEXT NULL,
  pending_operation_status ENUM('none','pending','succeeded','failed','unknown') NOT NULL DEFAULT 'none',
  pending_result_json LONGTEXT NULL,
  lease_owner CHAR(36) NULL,
  lease_expires_at DATETIME NULL,
  last_error VARCHAR(2000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (run_id),
  UNIQUE KEY uq_cu_checkpoint_message (user_id, conversation_id, client_message_id),
  KEY idx_cu_checkpoint_lease (status, lease_expires_at),
  CONSTRAINT fk_cu_checkpoint_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cu_checkpoint_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function begin(
        string $runId,
        int $userId,
        int $conversationId,
        string $clientMessageId,
        string $leaseOwner
    ): bool {
        $this->pdo->prepare(
            'INSERT INTO cu_run_checkpoints
             (run_id, user_id, conversation_id, client_message_id, lease_owner, lease_expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 180 SECOND))
             ON DUPLICATE KEY UPDATE run_id=run_id'
        )->execute([$runId, $userId, $conversationId, $clientMessageId, $leaseOwner]);
        $stmt = $this->pdo->prepare(
            "UPDATE cu_run_checkpoints
             SET lease_owner=?, lease_expires_at=DATE_ADD(NOW(), INTERVAL 180 SECOND), status='running'
             WHERE run_id=? AND (lease_owner=? OR lease_expires_at IS NULL OR lease_expires_at<NOW())"
        );
        $stmt->execute([$leaseOwner, $runId, $leaseOwner]);
        $ownerStmt = $this->pdo->prepare(
            'SELECT lease_owner FROM cu_run_checkpoints WHERE run_id=? LIMIT 1'
        );
        $ownerStmt->execute([$runId]);
        return hash_equals($leaseOwner, (string)$ownerStmt->fetchColumn());
    }

    public function heartbeat(string $runId, string $leaseOwner): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cu_run_checkpoints SET lease_expires_at=DATE_ADD(NOW(), INTERVAL 180 SECOND) '
            . 'WHERE run_id=? AND lease_owner=?'
        );
        $stmt->execute([$runId, $leaseOwner]);
        return $stmt->rowCount() > 0;
    }

    public function snapshot(string $runId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT run_id, user_id, conversation_id, client_message_id, status, current_layer,
                    iteration_no, model_messages_json, state_json, pending_operation_id,
                    pending_operation_json, pending_operation_status, pending_result_json,
                    lease_owner, lease_expires_at, last_error
             FROM cu_run_checkpoints WHERE run_id=? LIMIT 1'
        );
        $stmt->execute([$runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach (['model_messages_json' => 'messages', 'state_json' => 'state',
                  'pending_operation_json' => 'pending_operation', 'pending_result_json' => 'pending_result'] as $column => $key) {
            $decoded = json_decode((string)($row[$column] ?? ''), true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    public function takeoverReady(
        string $runId,
        int $userId,
        int $conversationId,
        string $clientMessageId
    ): bool {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM cu_run_checkpoints
             WHERE run_id=? AND user_id=? AND conversation_id=? AND client_message_id=?
               AND status IN ('running','recovering')
               AND (lease_expires_at IS NULL OR lease_expires_at<NOW())
             LIMIT 1"
        );
        $stmt->execute([$runId, $userId, $conversationId, $clientMessageId]);
        return $stmt->fetchColumn() !== false;
    }

    /** A process may have died after dispatching an input but before recording its result. */
    public function markPendingUnknownForRecovery(string $runId, string $leaseOwner): bool
    {
        $result = json_encode([
            'ok' => false,
            'layer' => 'computer',
            'method' => 'checkpoint_recovery',
            'attempts' => 0,
            'verification' => ['status' => 'unknown'],
            'failure_code' => 'operation_result_unknown',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare(
            "UPDATE cu_run_checkpoints
             SET pending_operation_status='unknown', pending_result_json=?
             WHERE run_id=? AND lease_owner=? AND pending_operation_status='pending'"
        );
        $stmt->execute([$result, $runId, $leaseOwner]);
        return $stmt->rowCount() > 0;
    }

    public function record(
        string $runId,
        string $leaseOwner,
        int $iteration,
        string $layer,
        array $messages,
        array $state
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE cu_run_checkpoints SET iteration_no=?, current_layer=?, model_messages_json=?, state_json=?, '
            . 'lease_expires_at=DATE_ADD(NOW(), INTERVAL 180 SECOND) WHERE run_id=? AND lease_owner=?'
        );
        $stmt->execute([
            max(0, $iteration),
            mb_substr($layer, 0, 32),
            json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $runId,
            $leaseOwner,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function cachedOperationResult(string $runId, string $operationId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pending_operation_status, pending_result_json
             FROM cu_run_checkpoints WHERE run_id=? AND pending_operation_id=? LIMIT 1"
        );
        $stmt->execute([$runId, $operationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !in_array((string)$row['pending_operation_status'], ['succeeded', 'failed', 'unknown'], true)) {
            return null;
        }
        $decoded = json_decode((string)($row['pending_result_json'] ?? ''), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function beginOperation(string $runId, string $leaseOwner, string $operationId, array $operation): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE cu_run_checkpoints
             SET pending_operation_id=?, pending_operation_json=?, pending_operation_status='pending',
                 pending_result_json=NULL, lease_expires_at=DATE_ADD(NOW(), INTERVAL 180 SECOND)
             WHERE run_id=? AND lease_owner=?
               AND (pending_operation_status<>'pending' OR pending_operation_id=?)"
        );
        $stmt->execute([
            $operationId,
            json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $runId,
            $leaseOwner,
            $operationId,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function finishOperation(
        string $runId,
        string $leaseOwner,
        string $operationId,
        string $status,
        array $result
    ): void {
        $status = in_array($status, ['succeeded', 'failed', 'unknown'], true) ? $status : 'unknown';
        $this->pdo->prepare(
            'UPDATE cu_run_checkpoints SET pending_operation_status=?, pending_result_json=?, '
            . 'lease_expires_at=DATE_ADD(NOW(), INTERVAL 180 SECOND) '
            . 'WHERE run_id=? AND lease_owner=? AND pending_operation_id=?'
        )->execute([
            $status,
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $runId,
            $leaseOwner,
            $operationId,
        ]);
    }

    public function complete(string $runId, string $leaseOwner, string $status, ?string $error = null): void
    {
        $status = in_array($status, ['completed', 'failed', 'cancelled'], true) ? $status : 'failed';
        $this->pdo->prepare(
            'UPDATE cu_run_checkpoints SET status=?, last_error=?, lease_owner=NULL, lease_expires_at=NULL '
            . 'WHERE run_id=? AND lease_owner=?'
        )->execute([$status, $error === null ? null : mb_substr($error, 0, 2000), $runId, $leaseOwner]);
    }
}
