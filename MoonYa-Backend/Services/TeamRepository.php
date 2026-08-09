<?php
declare(strict_types=1);

final class TeamRepository
{
    private PDO $pdo;
    private ?array $runtimeConfig = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function isInstalled(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM agents LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function runtimeConfig(string $key, $default = null)
    {
        if ($this->runtimeConfig === null) {
            $this->runtimeConfig = [];
            try {
                foreach ($this->pdo->query('SELECT config_key, config_value FROM agent_runtime_config')->fetchAll() as $row) {
                    $decoded = json_decode((string)$row['config_value'], true);
                    $this->runtimeConfig[(string)$row['config_key']] =
                        json_last_error() === JSON_ERROR_NONE ? $decoded : $row['config_value'];
                }
            } catch (Throwable $e) {
                // Migration may not have run yet; callers use explicit defaults.
            }
        }
        return array_key_exists($key, $this->runtimeConfig) ? $this->runtimeConfig[$key] : $default;
    }

    public function listAgents(bool $enabledOnly = true): array
    {
        $where = $enabledOnly ? 'WHERE a.enabled=1' : '';
        $sql = "SELECT a.*, sp.display_name AS prompt_display_name
                FROM agents a
                LEFT JOIN system_prompts sp ON sp.name=a.prompt_name
                {$where}
                ORDER BY a.sort_order, a.id";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function getAgent(string $agentKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agents WHERE agent_key=? AND enabled=1 LIMIT 1');
        $stmt->execute([$agentKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getAgentPrompt(string $agentKey): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT sp.prompt
             FROM agents a
             JOIN system_prompts sp ON sp.name=a.prompt_name AND sp.enabled=1
             WHERE a.agent_key=? AND a.enabled=1
             LIMIT 1'
        );
        $stmt->execute([$agentKey]);
        return (string)($stmt->fetchColumn() ?: '');
    }

    public function getDelegatedAgents(string $parentKey = 'moonya'): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT child.*
             FROM agent_delegations d
             JOIN agents parent ON parent.id=d.parent_agent_id
             JOIN agents child ON child.id=d.child_agent_id
             WHERE parent.agent_key=? AND parent.enabled=1 AND child.enabled=1 AND d.enabled=1
             ORDER BY child.sort_order, child.id'
        );
        $stmt->execute([$parentKey]);
        return $stmt->fetchAll();
    }

    public function listRoutingCapabilities(bool $enabledOnly = true): array
    {
        $where = $enabledOnly ? 'WHERE c.enabled=1 AND a.enabled=1' : '';
        $rows = $this->pdo->query(
            "SELECT c.*, a.agent_key, a.display_name AS agent_display_name,
                    EXISTS(
                        SELECT 1
                        FROM agent_delegations d
                        JOIN agents parent ON parent.id=d.parent_agent_id
                        WHERE parent.agent_key='moonya'
                          AND parent.enabled=1
                          AND d.child_agent_id=c.agent_id
                          AND d.enabled=1
                    ) AS delegated_by_moonya
             FROM agent_routing_capabilities c
             JOIN agents a ON a.id=c.agent_id
             {$where}
             ORDER BY c.sort_order, c.id"
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['examples'] = self::decodeJsonObject((string)$row['examples_json']);
            $row['exclusions'] = self::decodeJsonObject((string)$row['exclusions_json']);
            $row['required_tools'] = self::decodeJsonObject((string)$row['required_tools_json']);
            $granted = array_fill_keys(array_map(
                static fn(array $tool): string => (string)$tool['tool_key'],
                $this->getToolsForAgent((string)$row['agent_key'])
            ), true);
            $row['missing_tools'] = array_values(array_filter(
                $row['required_tools'],
                static fn(string $tool): bool => !isset($granted[$tool])
            ));
            $row['delegated_by_moonya'] = (bool)$row['delegated_by_moonya'];
            $row['authorization_differences'] = $row['missing_tools'];
            if (!$row['delegated_by_moonya']) {
                $row['authorization_differences'][] = 'delegation:moonya';
            }
            $row['ready'] = $row['missing_tools'] === [] && $row['delegated_by_moonya'];
        }
        unset($row);
        return $rows;
    }

    public function getRoutingCapability(string $capabilityKey): ?array
    {
        foreach ($this->listRoutingCapabilities(true) as $capability) {
            if ((string)$capability['capability_key'] === $capabilityKey) {
                return $capability;
            }
        }
        return null;
    }

    public function agentOwnsRoutingCapability(string $agentKey, string $capabilityKey): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM agent_routing_capabilities c
             JOIN agents a ON a.id=c.agent_id
             WHERE c.capability_key=? AND c.enabled=1
               AND a.agent_key=? AND a.enabled=1
             LIMIT 1'
        );
        $stmt->execute([$capabilityKey, $agentKey]);
        return $stmt->fetchColumn() !== false;
    }

    public function getToolsForAgent(string $agentKey, ?int $userId = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*
             FROM agent_tool_grants g
             JOIN agents a ON a.id=g.agent_id
             JOIN tool_registry t ON t.id=g.tool_id
             WHERE a.agent_key=? AND a.enabled=1 AND g.enabled=1 AND t.enabled=1
             ORDER BY t.id'
        );
        $stmt->execute([$agentKey]);
        $tools = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!$this->isToolAvailableForUser($row, $userId)) {
                continue;
            }
            $row['input_schema_array'] = self::decodeJsonObject((string)$row['input_schema']);
            $row['output_schema_array'] = self::decodeJsonObject((string)($row['output_schema'] ?? ''));
            $row['transport_config_array'] = self::decodeJsonObject((string)($row['transport_config'] ?? ''));
            $tools[] = $row;
        }
        return $tools;
    }

    public function getToolForAgent(string $agentKey, string $toolKey, ?int $userId = null): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*
             FROM agent_tool_grants g
             JOIN agents a ON a.id=g.agent_id
             JOIN tool_registry t ON t.id=g.tool_id
             WHERE a.agent_key=? AND t.tool_key=? AND a.enabled=1 AND g.enabled=1 AND t.enabled=1
             LIMIT 1'
        );
        $stmt->execute([$agentKey, $toolKey]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (!$this->isToolAvailableForUser($row, $userId)) {
            return null;
        }
        $row['input_schema_array'] = self::decodeJsonObject((string)$row['input_schema']);
        $row['transport_config_array'] = self::decodeJsonObject((string)($row['transport_config'] ?? ''));
        return $row;
    }

    public function functionToolsForAgent(string $agentKey, ?int $userId = null): array
    {
        $result = [];
        foreach ($this->getToolsForAgent($agentKey, $userId) as $tool) {
            // Decode the original JSON as objects for the Function Calling wire
            // payload. Associative decoding turns an empty JSON object (`{}`)
            // into PHP `[]`, which json_encode sends back as an array and causes
            // providers to reject valid schemas such as `"properties": {}`.
            // ToolGateway continues to use input_schema_array for local checks.
            $wireSchema = json_decode((string)($tool['input_schema'] ?? ''));
            if (!is_object($wireSchema)) {
                $wireSchema = (object)[
                    'type' => 'object',
                    'properties' => (object)[],
                ];
            }
            $result[] = [
                'type' => 'function',
                'route_class' => (string)($tool['route_class'] ?? ''),
                'source' => (string)($tool['source'] ?? ''),
                'transport' => (string)($tool['transport'] ?? ''),
                'function' => [
                    'name' => $tool['tool_key'],
                    'description' => $tool['description'],
                    'parameters' => $wireSchema,
                ],
            ];
        }
        return $result;
    }

    private function isToolAvailableForUser(array $tool, ?int $userId): bool
    {
        if ((string)($tool['source'] ?? '') !== 'mcp') {
            return true;
        }
        if ($userId === null || $userId <= 0 || (string)($tool['transport'] ?? '') !== 'mcp') {
            return false;
        }
        $transport = self::decodeJsonObject((string)($tool['transport_config'] ?? ''));
        $serverKey = trim((string)($transport['server_key'] ?? ''));
        if ($serverKey === '') {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT c.scopes_json
                 FROM mcp_servers s
                 INNER JOIN user_mcp_connections c ON c.mcp_server_id=s.id
                 WHERE s.server_key=? AND s.enabled=1 AND s.last_status='connected'
                   AND c.user_id=? AND c.status='connected'
                   AND c.vault_key<>''
                   AND (c.expires_at IS NULL OR c.expires_at>CURRENT_TIMESTAMP)
                 LIMIT 1"
            );
            $stmt->execute([$serverKey, $userId]);
            $scopesJson = $stmt->fetchColumn();
            if ($scopesJson === false) {
                return false;
            }
            $scopes = json_decode((string)$scopesJson, true);
            return is_array($scopes);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function createRun(
        int $userId,
        ?int $conversationId,
        string $mode,
        string $requestSummary,
        ?string $clientMessageId = null,
        ?int $historyFirstMessageId = null,
        ?int $historyLastMessageId = null
    ): string {
        $runId = self::uuid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO team_runs
             (id, conversation_id, user_id, mode, root_agent_key, status, request_summary,
              client_message_id, history_first_message_id, history_last_message_id)
             VALUES (?, ?, ?, ?, "moonya", "running", ?, ?, ?, ?)'
        );
        $stmt->execute([
            $runId,
            $conversationId,
            $userId,
            $mode,
            self::truncate($requestSummary, 4000),
            $clientMessageId,
            $historyFirstMessageId,
            $historyLastMessageId,
        ]);
        return $runId;
    }

    public function resumeRunForOwner(string $runId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE team_runs SET status='running', completed_at=NULL WHERE id=? AND user_id=? AND status='running'"
        );
        $stmt->execute([$runId, $userId]);
        $verify = $this->pdo->prepare('SELECT status FROM team_runs WHERE id=? AND user_id=? LIMIT 1');
        $verify->execute([$runId, $userId]);
        return $verify->fetchColumn() === 'running';
    }

    public function lastEventSequence(string $runId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(seq), 0) FROM team_run_events WHERE run_id=?');
        $stmt->execute([$runId]);
        return max(0, (int)$stmt->fetchColumn());
    }

    public function incrementPlanningRejection(string $runId): void
    {
        $this->pdo->prepare(
            'UPDATE team_runs SET planning_rejections=planning_rejections+1 WHERE id=?'
        )->execute([$runId]);
    }

    public function markDirectResponse(string $runId, string $reason): void
    {
        $valid = ['chat', 'clarification', 'unsupported'];
        $reason = in_array($reason, $valid, true) ? $reason : 'unsupported';
        $this->pdo->prepare(
            'UPDATE team_runs SET direct_response_reason=? WHERE id=?'
        )->execute([$reason, $runId]);
    }

    public function persistFinalAssistantMessage(
        string $runId,
        int $userId,
        ?int $conversationId,
        string $content
    ): ?int {
        if ($conversationId === null || trim($content) === '') {
            return null;
        }
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO messages
                 (conversation_id, user_id, source_run_id, role, content, thinking, specialist_analysis, agent)
                 VALUES (?, ?, ?, "ai", ?, "", "", "MoonYa")
                 ON DUPLICATE KEY UPDATE
                   id=LAST_INSERT_ID(id), content=VALUES(content), agent=VALUES(agent)'
            );
            $stmt->execute([$conversationId, $userId, $runId, $content]);
            $messageId = (int)$this->pdo->lastInsertId();
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM messages
                 WHERE conversation_id=? AND user_id=? AND source_run_id=? AND role="ai" LIMIT 1'
            );
            $stmt->execute([$conversationId, $userId, $runId]);
            $messageId = (int)($stmt->fetchColumn() ?: 0);
            if ($messageId === 0) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO messages
                     (conversation_id, user_id, source_run_id, role, content)
                     VALUES (?, ?, ?, "ai", ?)'
                );
                $insert->execute([$conversationId, $userId, $runId, $content]);
                $messageId = (int)$this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare('UPDATE messages SET content=? WHERE id=?')
                    ->execute([$content, $messageId]);
            }
        }
        $this->pdo->prepare(
            'UPDATE team_runs SET final_message_id=? WHERE id=?'
        )->execute([$messageId, $runId]);
        $this->pdo->prepare(
            'UPDATE conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?'
        )->execute([$conversationId, $userId]);
        return $messageId;
    }

    public function finishRun(string $runId, string $status, ?string $summary = null): void
    {
        $valid = ['completed', 'partial', 'failed', 'cancelled'];
        $status = in_array($status, $valid, true) ? $status : 'failed';
        $stmt = $this->pdo->prepare(
            'UPDATE team_runs
             SET status=?, final_summary=?, completed_at=CURRENT_TIMESTAMP
             WHERE id=? AND status IN ("running","waiting_approval")'
        );
        $stmt->execute([$status, $summary === null ? null : self::truncate($summary, 65535), $runId]);
    }

    public function createProjectGroup(
        string $id,
        string $runId,
        string $rootTaskId,
        string $leadActorId,
        string $objective
    ): void {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $sql = 'INSERT INTO team_project_groups
                    (id, run_id, root_task_id, lead_actor_id, phase, status, objective)
                    VALUES (?, ?, ?, ?, "contract", "running", ?)
                    ON DUPLICATE KEY UPDATE
                      lead_actor_id=VALUES(lead_actor_id), objective=VALUES(objective),
                      phase=IF(status="running", VALUES(phase), phase)';
        } else {
            $sql = 'INSERT INTO team_project_groups
                    (id, run_id, root_task_id, lead_actor_id, phase, status, objective)
                    VALUES (?, ?, ?, ?, "contract", "running", ?)
                    ON CONFLICT(id) DO UPDATE SET
                      lead_actor_id=excluded.lead_actor_id, objective=excluded.objective';
        }
        $this->pdo->prepare($sql)->execute([$id, $runId, $rootTaskId, $leadActorId, self::truncate($objective, 65535)]);
    }

    public function saveProjectContract(string $groupId, array $contract): void
    {
        $this->pdo->prepare(
            'UPDATE team_project_groups
             SET phase="implementation", contract_json=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status="running"'
        )->execute([
            json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $groupId,
        ]);
    }

    public function saveProjectAcceptance(string $groupId, array $acceptance, string $status): void
    {
        $allowed = ['completed', 'partial', 'blocked', 'failed', 'cancelled'];
        $status = in_array($status, $allowed, true) ? $status : 'failed';
        $this->pdo->prepare(
            'UPDATE team_project_groups
             SET phase=?, status=?, acceptance_json=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=?'
        )->execute([
            $status,
            $status,
            json_encode($acceptance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $groupId,
        ]);
    }

    public function setProjectPhase(string $groupId, string $phase): void
    {
        $allowed = ['contract', 'implementation', 'acceptance', 'completed', 'partial', 'blocked', 'failed', 'cancelled'];
        if (!in_array($phase, $allowed, true)) {
            throw new InvalidArgumentException('Invalid project phase');
        }
        $this->pdo->prepare(
            'UPDATE team_project_groups SET phase=?, updated_at=CURRENT_TIMESTAMP WHERE id=?'
        )->execute([$phase, $groupId]);
    }

    public function upsertProjectActor(string $groupId, array $actor): void
    {
        $values = [
            (string)$actor['id'],
            $groupId,
            (string)$actor['task_id'],
            (string)$actor['role_key'],
            (string)$actor['role_label'],
            self::truncate((string)$actor['workstream'], 240),
            json_encode(array_values($actor['owned_paths'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode(array_values($actor['read_dependencies'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode(array_values($actor['depends_on'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string)($actor['status'] ?? 'queued'),
        ];
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $sql = 'INSERT INTO team_project_actors
                    (id, project_group_id, task_id, role_key, role_label, workstream,
                     owned_paths_json, read_dependencies_json, depends_on_json, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                      role_label=VALUES(role_label), workstream=VALUES(workstream),
                      owned_paths_json=VALUES(owned_paths_json),
                      read_dependencies_json=VALUES(read_dependencies_json),
                      depends_on_json=VALUES(depends_on_json), status=VALUES(status)';
        } else {
            $sql = 'INSERT INTO team_project_actors
                    (id, project_group_id, task_id, role_key, role_label, workstream,
                     owned_paths_json, read_dependencies_json, depends_on_json, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(id) DO UPDATE SET
                      role_label=excluded.role_label, workstream=excluded.workstream,
                      owned_paths_json=excluded.owned_paths_json,
                      read_dependencies_json=excluded.read_dependencies_json,
                      depends_on_json=excluded.depends_on_json, status=excluded.status';
        }
        $this->pdo->prepare($sql)->execute($values);
    }

    public function setProjectActorStatus(string $actorId, string $status): void
    {
        $allowed = ['queued', 'running', 'waiting', 'completed', 'partial', 'failed', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            $status = 'failed';
        }
        $this->pdo->prepare(
            'UPDATE team_project_actors SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?'
        )->execute([$status, $actorId]);
    }

    public function projectGroup(string $groupId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM team_project_groups WHERE id=? LIMIT 1');
        $stmt->execute([$groupId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            return null;
        }
        foreach (['contract_json' => 'contract', 'acceptance_json' => 'acceptance'] as $column => $target) {
            $group[$target] = self::decodeJsonObject((string)($group[$column] ?? ''));
        }
        $actors = $this->pdo->prepare(
            'SELECT * FROM team_project_actors WHERE project_group_id=? ORDER BY role_key DESC, created_at, id'
        );
        $actors->execute([$groupId]);
        $group['actors'] = array_map(static function (array $actor): array {
            $actor['owned_paths'] = self::decodeJsonObject((string)$actor['owned_paths_json']);
            $actor['read_dependencies'] = self::decodeJsonObject((string)$actor['read_dependencies_json']);
            $actor['depends_on'] = self::decodeJsonObject((string)$actor['depends_on_json']);
            return $actor;
        }, $actors->fetchAll(PDO::FETCH_ASSOC));
        return $group;
    }

    public function cancelRun(int $userId, string $runId): bool
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE team_runs
                 SET status="cancelled", final_summary=?, completed_at=CURRENT_TIMESTAMP
                 WHERE id=? AND user_id=? AND status IN ("running","waiting_approval")'
            );
            $stmt->execute(['说停就停~等待新的工作安排。', $runId, $userId]);
            $cancelled = $stmt->rowCount() > 0;
            if ($cancelled) {
                $this->pdo->prepare(
                    'UPDATE tool_approvals
                     SET status="denied", decided_at=CURRENT_TIMESTAMP
                     WHERE run_id=? AND user_id=? AND status="pending"'
                )->execute([$runId, $userId]);
            } else {
                $statusStmt = $this->pdo->prepare(
                    'SELECT status FROM team_runs WHERE id=? AND user_id=? LIMIT 1'
                );
                $statusStmt->execute([$runId, $userId]);
                $cancelled = $statusStmt->fetchColumn() === 'cancelled';
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $cancelled;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function isRunCancelled(string $runId): bool
    {
        $stmt = $this->pdo->prepare('SELECT status FROM team_runs WHERE id=? LIMIT 1');
        $stmt->execute([$runId]);
        return $stmt->fetchColumn() === 'cancelled';
    }

    public function persistEvent(
        string $runId,
        int $seq,
        string $event,
        ?string $agentKey,
        ?string $parentAgentKey,
        ?string $taskId,
        ?string $toolCallId,
        array $payload
    ): void {
        $maxBytes = (int)$this->runtimeConfig('event_payload_max_bytes', 8388608);
        $safePayload = self::redact(self::withoutEmbeddedImageData($payload));
        $encoded = json_encode($safePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = '{"error":"payload_encode_failed"}';
        }
        if (strlen($encoded) > $maxBytes) {
            $encoded = json_encode([
                'truncated' => true,
                'preview' => self::truncate($encoded, max(512, $maxBytes - 128)),
                'original_bytes' => strlen($encoded),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO team_run_events
             (run_id, seq, event_name, agent_key, parent_agent_key, task_id, tool_call_id, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$runId, $seq, $event, $agentKey, $parentAgentKey, $taskId, $toolCallId, $encoded]);
    }

    public function persistEventMedia(array $media): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO team_event_media
             (id, run_id, task_id, tool_call_id, event_seq, kind, mime_type, width, height,
              relative_path, thumbnail_relative_path, source, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $media['id'],
            $media['run_id'],
            $media['task_id'] ?? null,
            $media['tool_call_id'] ?? null,
            (int)$media['event_seq'],
            $media['kind'] ?? 'image',
            $media['mime_type'],
            $media['width'] ?? null,
            $media['height'] ?? null,
            $media['relative_path'] ?? null,
            $media['thumbnail_relative_path'] ?? null,
            $media['source'] ?? 'tool',
            $media['error_message'] ?? null,
        ]);
    }

    public function eventMediaForUser(int $userId, string $mediaId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*
             FROM team_event_media m
             INNER JOIN team_runs r ON r.id=m.run_id
             WHERE m.id=? AND r.user_id=? LIMIT 1'
        );
        $stmt->execute([$mediaId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function runIdsForConversation(int $userId, int $conversationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM team_runs WHERE user_id=? AND conversation_id=?'
        );
        $stmt->execute([$userId, $conversationId]);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function createArtifact(
        string $runId,
        ?string $taskId,
        string $agentKey,
        array $artifact
    ): array {
        $id = self::uuid();
        $uri = (string)($artifact['uri'] ?? $artifact['path'] ?? $artifact['url'] ?? '');
        $displayName = (string)($artifact['display_name'] ?? $artifact['name'] ?? basename(str_replace('\\', '/', $uri)));
        if ($displayName === '') {
            $displayName = '产出物';
        }
        $row = [
            'id' => $id,
            'run_id' => $runId,
            'task_id' => $taskId,
            'agent_key' => $agentKey,
            'kind' => (string)($artifact['kind'] ?? self::inferArtifactKind($uri)),
            'display_name' => self::truncate($displayName, 500),
            'mime_type' => $artifact['mime_type'] ?? null,
            'uri' => $uri,
            'size_bytes' => isset($artifact['size_bytes'])
                ? (int)$artifact['size_bytes']
                : (isset($artifact['size']) ? (int)$artifact['size'] : null),
            'sha256' => $artifact['sha256'] ?? null,
            'metadata_json' => json_encode(self::redact($artifact['metadata'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO team_artifacts
             (id, run_id, task_id, agent_key, kind, display_name, mime_type, uri, size_bytes, sha256, metadata_json)
             VALUES (:id, :run_id, :task_id, :agent_key, :kind, :display_name, :mime_type, :uri, :size_bytes, :sha256, :metadata_json)'
        );
        $stmt->execute($row);
        return $row;
    }

    public function approvalMode(int $userId, ?int $conversationId): string
    {
        if ($conversationId === null || $conversationId <= 0) {
            return 'high_risk';
        }
        $stmt = $this->pdo->prepare(
            'SELECT approval_mode FROM conversation_agent_settings
             WHERE conversation_id=? AND user_id=? LIMIT 1'
        );
        $stmt->execute([$conversationId, $userId]);
        return (string)($stmt->fetchColumn() ?: 'high_risk');
    }

    public function setApprovalMode(int $userId, int $conversationId, string $mode): string
    {
        $valid = ['full_access', 'high_risk', 'confirm_writes'];
        if (!in_array($mode, $valid, true)) {
            throw new InvalidArgumentException('无效的权限模式');
        }
        $verify = $this->pdo->prepare('SELECT id FROM conversations WHERE id=? AND user_id=?');
        $verify->execute([$conversationId, $userId]);
        if (!$verify->fetchColumn()) {
            throw new RuntimeException('会话不存在');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO conversation_agent_settings (conversation_id, user_id, approval_mode)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE approval_mode=VALUES(approval_mode)'
        );
        $stmt->execute([$conversationId, $userId, $mode]);
        return $mode;
    }

    public function createApproval(
        string $runId,
        int $userId,
        ?int $conversationId,
        string $agentKey,
        string $toolCallId,
        string $toolKey,
        array $arguments,
        string $reason
    ): array {
        $id = self::uuid();
        $timeout = max(0, (int)$this->runtimeConfig('approval_timeout_seconds', 0));
        $hash = hash('sha256', json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $expiresExpression = $timeout === 0
            ? 'NULL'
            : ($driver === 'sqlite'
                ? "datetime(CURRENT_TIMESTAMP, '+{$timeout} seconds')"
                : "DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$timeout} SECOND)");
        $insertSql = 'INSERT INTO tool_approvals
             (id, run_id, user_id, conversation_id, agent_key, tool_call_id, tool_key, arguments_hash, reason, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . $expiresExpression . ')';
        if ($driver === 'sqlite') {
            $insertSql = str_replace('INSERT INTO', 'INSERT OR IGNORE INTO', $insertSql);
        } else {
            $insertSql .= ' ON DUPLICATE KEY UPDATE id=id';
        }
        $stmt = $this->pdo->prepare($insertSql);
        $stmt->execute([
            $id, $runId, $userId, $conversationId, $agentKey, $toolCallId,
            $toolKey, $hash, self::truncate($reason, 1000),
        ]);
        $lookup = $this->pdo->prepare(
            'SELECT id, status, expires_at, reason, tool_key
             FROM tool_approvals
             WHERE run_id=? AND tool_call_id=?
             LIMIT 1'
        );
        $lookup->execute([$runId, $toolCallId]);
        $approval = $lookup->fetch();
        if (!$approval) {
            throw new RuntimeException('确认记录创建失败');
        }
        if ((string)$approval['status'] === 'pending') {
            $this->pdo->prepare("UPDATE team_runs SET status='waiting_approval' WHERE id=?")
                ->execute([$runId]);
        }
        return [
            'id' => (string)$approval['id'],
            'status' => (string)$approval['status'],
            'expires_at' => $approval['expires_at'] !== null ? (string)$approval['expires_at'] : null,
            'reason' => (string)$approval['reason'],
            'tool_key' => (string)$approval['tool_key'],
            'arguments' => self::redact($arguments),
        ];
    }

    public function getApprovalStatus(string $approvalId, int $userId): string
    {
        // Keep expiry creation and comparison on the database clock. PHP and
        // MySQL may use different time zones on the same host.
        $this->pdo->prepare(
            "UPDATE tool_approvals
             SET status='expired', decided_at=CURRENT_TIMESTAMP
             WHERE id=? AND user_id=? AND status='pending'
               AND expires_at IS NOT NULL AND expires_at<=CURRENT_TIMESTAMP"
        )->execute([$approvalId, $userId]);
        $stmt = $this->pdo->prepare('SELECT status FROM tool_approvals WHERE id=? AND user_id=?');
        $stmt->execute([$approvalId, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return 'denied';
        }
        return (string)$row['status'];
    }

    public function decideApproval(int $userId, string $approvalId, string $decision): string
    {
        $status = $decision === 'allow_once' ? 'allowed' : 'denied';
        $lookup = $this->pdo->prepare(
            'SELECT status FROM tool_approvals WHERE id=? AND user_id=? LIMIT 1'
        );
        $lookup->execute([$approvalId, $userId]);
        $current = $lookup->fetch();
        if (!$current) {
            throw new RuntimeException('确认不存在或无权访问');
        }
        if ((string)$current['status'] !== 'pending') {
            return (string)$current['status'];
        }
        $stmt = $this->pdo->prepare(
            'UPDATE tool_approvals
             SET status=?, decided_at=CURRENT_TIMESTAMP
             WHERE id=? AND user_id=? AND status="pending"
               AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)'
        );
        $stmt->execute([$status, $approvalId, $userId]);
        if ($stmt->rowCount() !== 1) {
            // The row either expired on the database clock or another click/tab
            // decided it. Resolve both paths from the authoritative row.
            $this->pdo->prepare(
                "UPDATE tool_approvals
                 SET status='expired', decided_at=CURRENT_TIMESTAMP
                 WHERE id=? AND user_id=? AND status='pending'
                   AND expires_at IS NOT NULL AND expires_at<=CURRENT_TIMESTAMP"
            )->execute([$approvalId, $userId]);
            $lookup->execute([$approvalId, $userId]);
            $resolved = $lookup->fetch();
            if (!$resolved) {
                throw new RuntimeException('确认不存在或无权访问');
            }
            return (string)$resolved['status'];
        }
        $this->pdo->prepare(
            "UPDATE team_runs
             SET status='running'
             WHERE id=(SELECT run_id FROM tool_approvals WHERE id=?)
               AND status='waiting_approval'"
        )->execute([$approvalId]);
        return $status;
    }

    public function bootstrap(int $userId, ?int $conversationId): array
    {
        $agents = $this->listAgents();
        foreach ($agents as &$agent) {
            unset($agent['model_override']);
        }
        unset($agent);
        $connections = [];
        try {
            $stmt = $this->pdo->prepare(
                'SELECT s.server_key, s.display_name, s.transport, s.auth_mode, s.last_status,
                        COALESCE(c.status, "disconnected") AS connection_status,
                        c.scopes_json, c.expires_at
                 FROM mcp_servers s
                 LEFT JOIN user_mcp_connections c ON c.mcp_server_id=s.id AND c.user_id=?
                 WHERE s.enabled=1
                 ORDER BY s.display_name'
            );
            $stmt->execute([$userId]);
            $connections = $stmt->fetchAll();
        } catch (Throwable $e) {
            // Optional until migration completes.
        }
        return [
            'protocol' => 'team-v1',
            'user_id' => $userId,
            'agents' => $agents,
            'approval_mode' => $this->approvalMode($userId, $conversationId),
            'runtime' => [
                'multi_agent_v1' => (bool)$this->runtimeConfig('multi_agent_v1', false),
                'mcp_gateway' => (bool)$this->runtimeConfig('mcp_gateway', false),
                'max_parallel_agents' => (int)$this->runtimeConfig('max_parallel_agents', 3),
            ],
            'mcp_connections' => $connections,
        ];
    }

    public function updateMcpConnection(
        int $userId,
        string $serverKey,
        string $status,
        string $vaultKey = '',
        array $scopes = [],
        ?string $expiresAt = null,
        ?string $error = null
    ): void {
        if ($serverKey === '') {
            return;
        }
        $valid = ['disconnected', 'authorizing', 'connected', 'expired', 'error'];
        if (!in_array($status, $valid, true)) {
            $status = 'error';
        }
        $stmt = $this->pdo->prepare('SELECT id FROM mcp_servers WHERE server_key=? LIMIT 1');
        $stmt->execute([$serverKey]);
        $serverId = (int)($stmt->fetchColumn() ?: 0);
        if ($serverId <= 0) {
            return;
        }
        if ($vaultKey === '') {
            $vaultKey = "moonya:{$userId}:{$serverKey}";
        }
        $this->pdo->prepare(
            'INSERT INTO user_mcp_connections
             (user_id, mcp_server_id, vault_key, status, scopes_json, expires_at, last_error)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               vault_key=VALUES(vault_key), status=VALUES(status), scopes_json=VALUES(scopes_json),
               expires_at=VALUES(expires_at), last_error=VALUES(last_error)'
        )->execute([
            $userId,
            $serverId,
            $vaultKey,
            $status,
            json_encode($scopes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $expiresAt,
            $error === null ? null : self::truncate($error, 4000),
        ]);
        $this->pdo->prepare(
            'UPDATE mcp_servers SET last_status=?, last_error=?, last_seen_at=CURRENT_TIMESTAMP WHERE id=?'
        )->execute([$status === 'connected' ? 'connected' : 'error', $error, $serverId]);
    }

    public function runsForConversation(int $userId, int $conversationId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM team_runs
             WHERE user_id=? AND conversation_id=?
             ORDER BY started_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$userId, $conversationId]);
        $runs = $stmt->fetchAll();
        $eventStmt = $this->pdo->prepare(
            'SELECT seq, event_name, agent_key, parent_agent_key, task_id, tool_call_id, payload, created_at
             FROM team_run_events WHERE run_id=? ORDER BY seq'
        );
        $artifactStmt = $this->pdo->prepare('SELECT * FROM team_artifacts WHERE run_id=? ORDER BY created_at');
        foreach ($runs as &$run) {
            $eventStmt->execute([$run['id']]);
            $run['events'] = array_map(static function (array $event): array {
                $event['payload'] = self::decodeJsonObject((string)$event['payload']);
                return $event;
            }, $eventStmt->fetchAll());
            $artifactStmt->execute([$run['id']]);
            $run['artifacts'] = $artifactStmt->fetchAll();
        }
        unset($run);
        return $runs;
    }

    /** Persisted TeamEventV1 replay for an authenticated transport re-attach. */
    public function eventsAfterForRun(int $userId, string $runId, int $afterSeq, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT e.seq, e.event_name, e.agent_key, e.parent_agent_key,
                    e.task_id, e.tool_call_id, e.payload, e.created_at
             FROM team_run_events e
             INNER JOIN team_runs r ON r.id=e.run_id AND r.user_id=?
             WHERE e.run_id=? AND e.seq>?
             ORDER BY e.seq
             LIMIT {$limit}"
        );
        $stmt->execute([$userId, $runId, max(0, $afterSeq)]);
        return array_map(static function (array $event): array {
            $event['seq'] = (int)$event['seq'];
            $event['payload'] = self::decodeJsonObject((string)$event['payload']);
            return $event;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function runStateForOwner(int $userId, string $runId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, conversation_id, status, final_summary, final_message_id, completed_at '
            . 'FROM team_runs WHERE id=? AND user_id=? LIMIT 1'
        );
        $stmt->execute([$runId, $userId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($run) ? $run : null;
    }

    /**
     * 办公室界面专用（纯只读）：返回当前用户处于 running/waiting_approval 的运行中，
     * 已开始 turn 但尚未 completed/failed 的 agent_key 集合。
     * 仅查询 team_runs / team_run_events，不触碰任何调度逻辑。
     */
    public function officeActiveAgents(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT active.agent_key
             FROM (
                 SELECT e.run_id, e.agent_key
                 FROM team_run_events e
                 INNER JOIN team_runs r ON r.id = e.run_id
                 INNER JOIN conversation_task_state s
                    ON s.user_id = r.user_id
                   AND s.conversation_id = r.conversation_id
                   AND s.active_run_id = r.id
                   AND s.phase IN ('running','waiting_approval')
                   AND s.heartbeat_at IS NOT NULL
                   AND s.heartbeat_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                 WHERE r.user_id = ?
                   AND r.status IN ('running','waiting_approval')
                   AND e.agent_key IS NOT NULL AND e.agent_key <> ''
                 GROUP BY e.run_id, e.agent_key
                 HAVING
                     MAX(CASE WHEN e.event_name='agent.started' THEN e.seq ELSE 0 END)
                         > MAX(CASE WHEN e.event_name IN ('agent.completed','agent.failed') THEN e.seq ELSE 0 END)
                     OR MAX(CASE WHEN e.event_name='agent.turn.started' THEN e.seq ELSE 0 END)
                         > MAX(CASE WHEN e.event_name='agent.turn.completed' THEN e.seq ELSE 0 END)
             ) active
             GROUP BY active.agent_key"
        );
        $stmt->execute([$userId]);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** Read-only office projection grouped by run, preserving conversation identity. */
    public function officeActiveRuns(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id AS run_id, r.conversation_id, r.status, r.request_summary,
                    r.started_at, c.title AS conversation_title,
                    COALESCE(MAX(e.seq), 0) AS last_event_seq
             FROM team_runs r
             INNER JOIN conversation_task_state s
                ON s.user_id = r.user_id
               AND s.conversation_id = r.conversation_id
               AND s.active_run_id = r.id
               AND s.phase IN ('running','waiting_approval')
               AND s.heartbeat_at IS NOT NULL
               AND s.heartbeat_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
             LEFT JOIN conversations c ON c.id=r.conversation_id AND c.user_id=r.user_id
             LEFT JOIN team_run_events e ON e.run_id=r.id
             WHERE r.user_id=? AND r.status IN ('running','waiting_approval')
             GROUP BY r.id, r.conversation_id, r.status, r.request_summary, r.started_at, c.title
             ORDER BY r.started_at ASC"
        );
        $stmt->execute([$userId]);
        $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $events = $this->pdo->prepare(
            "SELECT seq, event_name, agent_key, payload
             FROM team_run_events WHERE run_id=? AND agent_key IS NOT NULL AND agent_key<>''
             ORDER BY seq"
        );
        foreach ($runs as &$run) {
            $events->execute([(string)$run['run_id']]);
            $activity = [];
            foreach ($events->fetchAll(PDO::FETCH_ASSOC) as $event) {
                $agent = (string)$event['agent_key'];
                $name = (string)$event['event_name'];
                $activity[$agent] ??= ['lifecycle' => false, 'turn' => false];
                if ($name === 'agent.started') $activity[$agent]['lifecycle'] = true;
                if (in_array($name, ['agent.completed', 'agent.failed'], true)) $activity[$agent]['lifecycle'] = false;
                if ($name === 'agent.turn.started') $activity[$agent]['turn'] = true;
                if ($name === 'agent.turn.completed') $activity[$agent]['turn'] = false;
            }
            $run['active_agents'] = array_values(array_keys(array_filter(
                $activity,
                static fn(array $state): bool => $state['lifecycle'] || $state['turn']
            )));
            $run['last_event_seq'] = (int)$run['last_event_seq'];
        }
        unset($run);
        return $runs;
    }

    public static function redact($value, ?string $key = null)
    {
        $sensitive = '/token|authorization|api[_-]?key|password|passwd|secret|credential|cookie|client_secret/i';
        if ($key !== null && preg_match($sensitive, $key)) {
            if (is_string($value) && str_starts_with($value, 'vault://')) {
                return $value;
            }
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::redact($v, is_string($k) ? $k : null);
            }
            return $out;
        }
        if (is_string($value)) {
            $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [REDACTED]', $value);
            $value = preg_replace('/([?&](?:token|api_key|access_token)=)[^&\s]+/i', '$1[REDACTED]', $value);
            $value = preg_replace(
                '/\b(password|passwd|token|access_token|refresh_token|api[_-]?key|client_secret|authorization)\b(\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,;]+)/i',
                '$1$2[REDACTED]',
                (string)$value
            );
            $value = preg_replace(
                '/(--(?:password|passwd|token|api-key|client-secret)\s+)(?:"[^"]*"|\'[^\']*\'|[^\s]+)/i',
                '$1[REDACTED]',
                (string)$value
            );
            $value = preg_replace('#(https?://[^:/\s]+:)[^@\s/]+@#i', '$1[REDACTED]@', (string)$value);
            $streamFields = ['reasoning_content', 'content', 'delta'];
            $imageFields = [
                'image', 'image_base64', 'base64', 'screenshot', 'screenshot_base64',
                'evidence_image', 'evidence_image_base64', 'data', 'data_url',
            ];
            $limit = (in_array((string)$key, $streamFields, true)
                || in_array(strtolower((string)$key), $imageFields, true))
                ? 83886080
                : 65535;
            return self::truncate((string)$value, $limit);
        }
        return $value;
    }

    /**
     * Defense in depth for TeamEventV1. The normal path extracts images through
     * TeamMediaStore; direct repository callers still cannot persist inline
     * image bytes accidentally.
     *
     * @return mixed
     */
    public static function withoutEmbeddedImageData($value, ?string $key = null)
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $childKey => $child) {
                $clean[$childKey] = self::withoutEmbeddedImageData($child, (string)$childKey);
            }
            return $clean;
        }
        if (!is_string($value)) {
            return $value;
        }
        if (preg_match('#^\s*data:image/[a-z0-9.+-]+;base64,#i', $value)) {
            return '[MEDIA_EXTRACTED]';
        }
        if ($key !== null && preg_match('/^(?:image|image_base64|base64|screenshot|screenshot_base64|evidence_image|evidence_image_base64|data)$/i', $key)) {
            $compact = preg_replace('/\s+/', '', $value) ?? '';
            if (strlen($compact) >= 16) {
                $prefix = base64_decode(substr($compact, 0, min(strlen($compact), 64)), true);
                if (is_string($prefix)
                    && (str_starts_with($prefix, "\x89PNG\r\n\x1a\n")
                        || str_starts_with($prefix, "\xff\xd8\xff")
                        || str_starts_with($prefix, 'GIF87a')
                        || str_starts_with($prefix, 'GIF89a')
                        || (strlen($prefix) >= 12 && substr($prefix, 0, 4) === 'RIFF' && substr($prefix, 8, 4) === 'WEBP')
                        || str_starts_with($prefix, 'BM'))
                ) {
                    return '[MEDIA_EXTRACTED]';
                }
            }
        }
        return $value;
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function inferArtifactKind(string $uri): string
    {
        $path = strtolower(parse_url($uri, PHP_URL_PATH) ?: $uri);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match (true) {
            in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], true) => 'image',
            in_array($ext, ['mp4', 'webm', 'mov', 'mkv'], true) => 'video',
            in_array($ext, ['mp3', 'wav', 'ogg', 'flac', 'm4a'], true) => 'audio',
            $ext === 'pdf' => 'pdf',
            in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true) => 'office',
            preg_match('/^https?:\/\//i', $uri) === 1 => 'link',
            default => 'file',
        };
    }

    private static function decodeJsonObject(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }
        return substr($value, 0, max(0, $length - 16)) . '…[truncated]';
    }
}
