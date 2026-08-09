<?php
declare(strict_types=1);

/**
 * Per-conversation execution slot shared by Chat, Work, attachments and voice.
 * A conversation can own one active task; different conversations remain independent.
 */
final class ConversationTaskState
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS conversation_task_state (
  conversation_id INT NOT NULL,
  user_id INT NOT NULL,
  active_task_id CHAR(36) NULL,
  active_run_id CHAR(36) NULL,
  phase ENUM('idle','running','waiting_approval','recovering','stopping') NOT NULL DEFAULT 'idle',
  task_summary VARCHAR(1000) NOT NULL DEFAULT '',
  heartbeat_at TIMESTAMP NULL,
  last_terminal_status ENUM('completed','failed','cancelled','partial') NULL,
  completed_at TIMESTAMP NULL,
  viewed_at TIMESTAMP NULL,
  unread_terminal TINYINT(1) NOT NULL DEFAULT 0,
  network_retry_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_network_error VARCHAR(2000) NULL,
  state_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (conversation_id),
  KEY idx_conversation_task_user_phase (user_id, phase, heartbeat_at),
  CONSTRAINT fk_conversation_task_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_conversation_task_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $phase = $this->pdo->query("SHOW COLUMNS FROM conversation_task_state LIKE 'phase'")->fetch(PDO::FETCH_ASSOC);
        if (is_array($phase) && !str_contains((string)($phase['Type'] ?? ''), 'recovering')) {
            $this->pdo->exec(
                "ALTER TABLE conversation_task_state MODIFY phase "
                . "ENUM('idle','running','waiting_approval','recovering','stopping') NOT NULL DEFAULT 'idle'"
            );
        }
        $this->ensureColumn('network_retry_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
        $this->ensureColumn('last_network_error', 'VARCHAR(2000) NULL');
    }

    private function ensureColumn(string $name, string $definition): void
    {
        // SHOW ... LIKE does not accept native prepared placeholders on
        // MariaDB. Use information_schema so CLI workers can keep emulation off.
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns'
            . ' WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1'
        );
        $stmt->execute(['conversation_task_state', $name]);
        if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            return;
        }
        $this->pdo->exec("ALTER TABLE conversation_task_state ADD COLUMN `{$name}` {$definition}");
    }

    public function acquire(int $userId, int $conversationId, string $taskId, string $summary = ''): array
    {
        $this->pdo->prepare(
            'INSERT IGNORE INTO conversation_task_state (conversation_id, user_id) VALUES (?, ?)'
        )->execute([$conversationId, $userId]);

        $stmt = $this->pdo->prepare(
            "UPDATE conversation_task_state
             SET active_task_id=?, active_run_id=NULL, phase='running', task_summary=?,
                 heartbeat_at=CURRENT_TIMESTAMP, unread_terminal=0,
                 last_terminal_status=NULL, completed_at=NULL, network_retry_count=0,
                 last_network_error=NULL, state_version=state_version+1
             WHERE conversation_id=? AND user_id=?
               AND (phase='idle' OR heartbeat_at IS NULL OR heartbeat_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                    )"
        );
        $stmt->execute([$taskId, mb_substr($summary, 0, 1000), $conversationId, $userId]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('conversation_task_already_running');
        }
        return $this->get($userId, $conversationId) ?? [];
    }

    /**
     * Reclaims the same logical task only after the CU checkpoint lease has
     * independently been proven expired by the caller.
     */
    public function takeover(int $userId, int $conversationId, string $taskId, string $summary = ''): array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE conversation_task_state
             SET phase='running', task_summary=?, heartbeat_at=CURRENT_TIMESTAMP,
                 last_network_error=NULL, state_version=state_version+1
             WHERE conversation_id=? AND user_id=? AND active_task_id=?
               AND phase IN ('running','recovering')"
        );
        $stmt->execute([mb_substr($summary, 0, 1000), $conversationId, $userId, $taskId]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('conversation_task_takeover_failed');
        }
        return $this->get($userId, $conversationId) ?? [];
    }

    public function attachRun(int $userId, int $conversationId, string $taskId, ?string $runId): void
    {
        $this->pdo->prepare(
            'UPDATE conversation_task_state SET active_run_id=?, heartbeat_at=CURRENT_TIMESTAMP,
             state_version=state_version+1 WHERE conversation_id=? AND user_id=? AND active_task_id=?'
        )->execute([$runId, $conversationId, $userId, $taskId]);
    }

    public function heartbeat(int $userId, int $conversationId, string $taskId, string $phase = 'running'): void
    {
        $phase = in_array($phase, ['waiting_approval', 'recovering', 'stopping'], true)
            ? $phase
            : 'running';
        $this->pdo->prepare(
            'UPDATE conversation_task_state SET phase=?, heartbeat_at=CURRENT_TIMESTAMP,
             state_version=state_version+1 WHERE conversation_id=? AND user_id=? AND active_task_id=?'
        )->execute([$phase, $conversationId, $userId, $taskId]);
    }

    public function markRecovering(
        int $userId,
        int $conversationId,
        string $taskId,
        int $retryCount,
        string $error
    ): void {
        $this->pdo->prepare(
            "UPDATE conversation_task_state
             SET phase='recovering', network_retry_count=?, last_network_error=?,
                 heartbeat_at=CURRENT_TIMESTAMP, state_version=state_version+1
             WHERE conversation_id=? AND user_id=? AND active_task_id=?"
        )->execute([
            max(0, $retryCount),
            mb_substr($error, 0, 2000),
            $conversationId,
            $userId,
            $taskId,
        ]);
    }

    public function finish(int $userId, int $conversationId, string $taskId, string $status): void
    {
        $status = in_array($status, ['completed', 'failed', 'cancelled', 'partial'], true)
            ? $status
            : 'failed';
        $this->pdo->prepare(
            "UPDATE conversation_task_state
             SET active_task_id=NULL, active_run_id=NULL, phase='idle', heartbeat_at=NULL,
                 last_terminal_status=?, completed_at=CURRENT_TIMESTAMP, unread_terminal=1,
                 network_retry_count=0, last_network_error=NULL,
                 state_version=state_version+1
             WHERE conversation_id=? AND user_id=? AND active_task_id=?"
        )->execute([$status, $conversationId, $userId, $taskId]);
    }

    public function markViewed(int $userId, int $conversationId): void
    {
        $this->pdo->prepare(
            'UPDATE conversation_task_state SET unread_terminal=0, viewed_at=CURRENT_TIMESTAMP,
             state_version=state_version+1 WHERE conversation_id=? AND user_id=?'
        )->execute([$conversationId, $userId]);
    }

    public function get(int $userId, int $conversationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT phase, active_task_id, active_run_id, task_summary, heartbeat_at,
                    last_terminal_status, completed_at, viewed_at, unread_terminal, state_version,
                    network_retry_count, last_network_error
             FROM conversation_task_state WHERE conversation_id=? AND user_id=?'
        );
        $stmt->execute([$conversationId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['unread_terminal'] = (bool)$row['unread_terminal'];
        $row['state_version'] = (int)$row['state_version'];
        $row['network_retry_count'] = (int)$row['network_retry_count'];
        return $row;
    }

    public function activeForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.conversation_id, s.active_task_id, s.active_run_id, s.phase,
                    s.task_summary, s.state_version, c.title
             FROM conversation_task_state s
             INNER JOIN conversations c ON c.id=s.conversation_id AND c.user_id=s.user_id
             WHERE s.user_id=? AND s.phase IN ('running','waiting_approval','recovering','stopping')
             ORDER BY s.updated_at ASC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Close projections whose executor disappeared. A fresh execution_jobs
     * heartbeat always wins, so a slow model/tool call is not mistaken for a
     * dead browser connection.
     */
    public function reconcileStale(int $userId, int $maxAgeSeconds = 180): int
    {
        $maxAgeSeconds = max(120, $maxAgeSeconds);
        $hasGuardTable = false;
        try {
            $table = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables"
                . " WHERE table_schema=DATABASE() AND table_name='execution_jobs'"
            );
            $hasGuardTable = (int)$table->fetchColumn() === 1;
        } catch (Throwable $e) {
            // Legacy installations are reconciled by task heartbeat alone.
        }

        $sql = "SELECT conversation_id, active_task_id, active_run_id"
            . " FROM conversation_task_state s"
            . " WHERE s.user_id=? AND s.phase IN ('running','waiting_approval','recovering','stopping')"
            . " AND (s.heartbeat_at IS NULL OR s.heartbeat_at < DATE_SUB(NOW(), INTERVAL {$maxAgeSeconds} SECOND))";
        if ($hasGuardTable) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM execution_jobs j"
                . " WHERE j.user_id=s.user_id AND j.conversation_id=s.conversation_id"
                . " AND j.client_message_id=s.active_task_id AND j.status='running'"
                . " AND j.heartbeat_at >= DATE_SUB(NOW(), INTERVAL {$maxAgeSeconds} SECOND))";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        $stale = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($stale === []) {
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($stale as $row) {
                $conversationId = (int)$row['conversation_id'];
                $taskId = (string)($row['active_task_id'] ?? '');
                $runId = trim((string)($row['active_run_id'] ?? ''));
                if ($runId !== '') {
                    $this->pdo->prepare(
                        "UPDATE team_runs SET status='failed', final_summary=COALESCE(final_summary, '执行进程心跳超时'),"
                        . " completed_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?"
                        . " AND status IN ('running','waiting_approval')"
                    )->execute([$runId, $userId]);
                    $this->pdo->prepare(
                        "UPDATE cu_run_checkpoints SET status='failed', last_error='执行进程心跳超时',"
                        . " lease_owner=NULL, lease_expires_at=NULL"
                        . " WHERE run_id=? AND user_id=? AND status IN ('running','recovering')"
                    )->execute([$runId, $userId]);
                }
                if ($hasGuardTable && $taskId !== '') {
                    $this->pdo->prepare(
                        "UPDATE execution_jobs SET status='failed', error_message='执行进程心跳超时',"
                        . " finished_at=CURRENT_TIMESTAMP WHERE user_id=? AND conversation_id=?"
                        . " AND client_message_id=? AND status IN ('queued','running')"
                    )->execute([$userId, $conversationId, $taskId]);
                }
                $this->pdo->prepare(
                    "UPDATE conversation_task_state SET active_task_id=NULL, active_run_id=NULL, phase='idle',"
                    . " heartbeat_at=NULL, last_terminal_status='failed', completed_at=CURRENT_TIMESTAMP,"
                    . " unread_terminal=1, network_retry_count=0, last_network_error=NULL,"
                    . " state_version=state_version+1 WHERE conversation_id=? AND user_id=?"
                    . " AND active_task_id=?"
                )->execute([$conversationId, $userId, $taskId]);
            }
            $this->pdo->commit();
            return count($stale);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
