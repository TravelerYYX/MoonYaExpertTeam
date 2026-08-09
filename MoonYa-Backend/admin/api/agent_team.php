<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Logger.php';

function agentTeamSuccess($data = null): void
{
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function agentTeamError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $config = require __DIR__ . '/../config.php';
    $db = new Database($config);
    $pdo = $db->getConnection();
    $logger = new Logger($config, $pdo);
    $auth = new Auth($pdo, $config, $logger);
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = preg_replace('/^Bearer\s+/i', '', (string)$header);
    $admin = $auth->authenticate($token);
    if (!$admin) {
        agentTeamError(401, '未授权访问');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        agentTeamSuccess(agentTeamSnapshot($pdo));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        agentTeamError(405, '不支持的请求方法');
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        agentTeamError(400, '请求体必须是 JSON 对象');
    }
    $action = (string)($_GET['action'] ?? '');

    if ($action === 'save_agent') {
        $agentKey = trim((string)($input['agent_key'] ?? ''));
        $displayName = trim((string)($input['display_name'] ?? ''));
        $promptName = trim((string)($input['prompt_name'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $agentKey) || $displayName === '' || $promptName === '') {
            agentTeamError(400, 'Agent 标识、名称或提示词引用无效');
        }
        $isCoordinator = !empty($input['is_coordinator']) ? 1 : 0;
        if (empty($input['enabled'])) {
            $capabilityStmt = $pdo->prepare(
                'SELECT c.capability_key
                 FROM agent_routing_capabilities c
                 JOIN agents a ON a.id=c.agent_id
                 WHERE a.agent_key=? AND c.enabled=1'
            );
            $capabilityStmt->execute([$agentKey]);
            $ownedCapabilities = $capabilityStmt->fetchAll(PDO::FETCH_COLUMN);
            if ($ownedCapabilities !== []) {
                agentTeamError(409, '请先停用该 Agent 负责的能力：' . implode(', ', $ownedCapabilities));
            }
        }
        if ($isCoordinator) {
            $pdo->exec('UPDATE agents SET is_coordinator=0');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO agents
             (agent_key, display_name, role_summary, avatar_url, prompt_name, is_coordinator, enabled, model_override, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               display_name=VALUES(display_name),
               role_summary=VALUES(role_summary),
               avatar_url=VALUES(avatar_url),
               prompt_name=VALUES(prompt_name),
               is_coordinator=VALUES(is_coordinator),
               enabled=VALUES(enabled),
               model_override=VALUES(model_override),
               sort_order=VALUES(sort_order)'
        );
        $stmt->execute([
            $agentKey,
            $displayName,
            trim((string)($input['role_summary'] ?? '')),
            trim((string)($input['avatar_url'] ?? '')),
            $promptName,
            $isCoordinator,
            !empty($input['enabled']) ? 1 : 0,
            trim((string)($input['model_override'] ?? '')) ?: null,
            (int)($input['sort_order'] ?? 0),
        ]);
        $logger->logAdminAction($admin['id'], 'save_agent', null, json_encode(['agent_key' => $agentKey], JSON_UNESCAPED_UNICODE));
        agentTeamSuccess(agentTeamSnapshot($pdo));
    }

    if ($action === 'save_delegations') {
        $parentKey = trim((string)($input['parent_agent_key'] ?? 'moonya'));
        $childKeys = is_array($input['child_agent_keys'] ?? null) ? array_unique($input['child_agent_keys']) : [];
        $where = 'agent_key=?';
        if ($childKeys !== []) {
            $where .= ' OR agent_key IN (' . implode(',', array_fill(0, count($childKeys), '?')) . ')';
        }
        $stmt = $pdo->prepare('SELECT id, agent_key FROM agents WHERE ' . $where);
        $params = array_merge([$parentKey], array_values($childKeys));
        $stmt->execute($params);
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[$row['agent_key']] = (int)$row['id'];
        }
        if (!isset($ids[$parentKey])) {
            agentTeamError(404, '父 Agent 不存在');
        }
        if ($parentKey === 'moonya') {
            $requiredAgentKeys = array_map('strval', $pdo->query(
                'SELECT DISTINCT a.agent_key
                 FROM agent_routing_capabilities c
                 JOIN agents a ON a.id=c.agent_id
                 WHERE c.enabled=1 AND a.enabled=1'
            )->fetchAll(PDO::FETCH_COLUMN));
            $missingDelegations = array_values(array_diff($requiredAgentKeys, $childKeys));
            if ($missingDelegations !== []) {
                agentTeamError(
                    409,
                    '以下 Agent 仍负责已启用能力，不能移除委派授权：' . implode(', ', $missingDelegations)
                );
            }
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE agent_delegations SET enabled=0 WHERE parent_agent_id=?')->execute([$ids[$parentKey]]);
        $upsert = $pdo->prepare(
            'INSERT INTO agent_delegations (parent_agent_id, child_agent_id, enabled)
             VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE enabled=1'
        );
        foreach ($childKeys as $childKey) {
            if ($childKey !== $parentKey && isset($ids[$childKey])) {
                $upsert->execute([$ids[$parentKey], $ids[$childKey]]);
            }
        }
        $pdo->commit();
        $logger->logAdminAction($admin['id'], 'save_agent_delegations', null, json_encode($input, JSON_UNESCAPED_UNICODE));
        agentTeamSuccess(agentTeamSnapshot($pdo));
    }

    if ($action === 'save_tool') {
        $toolId = (int)($input['id'] ?? 0);
        if ($toolId <= 0) {
            agentTeamError(400, '工具 id 无效');
        }
        $effect = (string)($input['effect'] ?? 'read');
        $risk = (string)($input['risk_level'] ?? 'medium');
        if (!in_array($effect, ['read', 'write', 'destructive'], true) ||
            !in_array($risk, ['low', 'medium', 'high', 'critical'], true)) {
            agentTeamError(400, '工具风险或副作用类型无效');
        }
        $sourceStmt = $pdo->prepare('SELECT source FROM tool_registry WHERE id=? LIMIT 1');
        $sourceStmt->execute([$toolId]);
        $toolSource = $sourceStmt->fetchColumn();
        if ($toolSource === false) {
            agentTeamError(404, 'Tool not found');
        }
        if (empty($input['enabled'])) {
            $requiredBy = capabilitiesRequiringTool($pdo, $toolId);
            if ($requiredBy !== []) {
                agentTeamError(409, '该工具仍被已启用能力使用：' . implode(', ', $requiredBy));
            }
        }
        if ((string)$toolSource === 'mcp') {
            // MCP names and schemas are synchronized from the server and remain read-only.
            $stmt = $pdo->prepare(
                'UPDATE tool_registry SET effect=?, risk_level=?, enabled=?, reviewed=? WHERE id=?'
            );
            $stmt->execute([
                $effect,
                $risk,
                !empty($input['enabled']) ? 1 : 0,
                !empty($input['reviewed']) ? 1 : 0,
                $toolId,
            ]);
            $logger->logAdminAction($admin['id'], 'review_mcp_tool', null, json_encode(['tool_id' => $toolId], JSON_UNESCAPED_UNICODE));
            agentTeamSuccess(agentTeamSnapshot($pdo));
        }
        $inputSchema = normalizeJsonField($input['input_schema'] ?? null, true);
        $outputSchema = normalizeJsonField($input['output_schema'] ?? null, false);
        $transportConfig = normalizeJsonField($input['transport_config'] ?? null, false);
        $stmt = $pdo->prepare(
            'UPDATE tool_registry SET
               display_name=?, description=?, input_schema=?, output_schema=?,
               transport_config=?, effect=?, risk_level=?, enabled=?, reviewed=?
             WHERE id=?'
        );
        $stmt->execute([
            trim((string)($input['display_name'] ?? '')),
            (string)($input['description'] ?? ''),
            $inputSchema,
            $outputSchema,
            $transportConfig,
            $effect,
            $risk,
            !empty($input['enabled']) ? 1 : 0,
            !empty($input['reviewed']) ? 1 : 0,
            $toolId,
        ]);
        $logger->logAdminAction($admin['id'], 'save_agent_tool', null, json_encode(['tool_id' => $toolId], JSON_UNESCAPED_UNICODE));
        agentTeamSuccess(agentTeamSnapshot($pdo));
    }

    if ($action === 'save_grants') {
        $agentKey = trim((string)($input['agent_key'] ?? ''));
        $toolIds = array_values(array_unique(array_map('intval', is_array($input['tool_ids'] ?? null) ? $input['tool_ids'] : [])));
        $stmt = $pdo->prepare('SELECT id FROM agents WHERE agent_key=?');
        $stmt->execute([$agentKey]);
        $agentId = (int)($stmt->fetchColumn() ?: 0);
        if ($agentId <= 0) {
            agentTeamError(404, 'Agent 不存在');
        }
        validateAgentCapabilityGrants($pdo, $agentId, $toolIds);
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE agent_tool_grants SET enabled=0 WHERE agent_id=?')->execute([$agentId]);
        $upsert = $pdo->prepare(
            'INSERT INTO agent_tool_grants (agent_id, tool_id, enabled)
             VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE enabled=1'
        );
        foreach ($toolIds as $toolId) {
            if ($toolId > 0) {
                $upsert->execute([$agentId, $toolId]);
            }
        }
        $pdo->commit();
        $logger->logAdminAction($admin['id'], 'save_agent_tool_grants', null, json_encode(['agent_key' => $agentKey, 'count' => count($toolIds)], JSON_UNESCAPED_UNICODE));
        agentTeamSuccess(agentTeamSnapshot($pdo));
    }

    if ($action === 'save_capability') {
        $capabilityKey = trim((string)($input['capability_key'] ?? ''));
        $agentKey = trim((string)($input['agent_key'] ?? ''));
        $capabilityDisplayName = trim((string)($input['display_name'] ?? ''));
        $capabilityDescription = trim((string)($input['description'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $capabilityKey)) {
            agentTeamError(400, '能力标识无效');
        }
        if ($capabilityDisplayName === '' || $capabilityDescription === '') {
            agentTeamError(400, '能力显示名称和用户可理解的说明不能为空');
        }
        $stmt = $pdo->prepare('SELECT id FROM agents WHERE agent_key=? AND enabled=1 LIMIT 1');
        $stmt->execute([$agentKey]);
        $agentId = (int)($stmt->fetchColumn() ?: 0);
        if ($agentId <= 0) {
            agentTeamError(404, '负责 Agent 不存在或未启用');
        }
        $examples = normalizeJsonArray($input['examples'] ?? []);
        $exclusions = normalizeJsonArray($input['exclusions'] ?? []);
        $requiredTools = normalizeJsonArray($input['required_tools'] ?? []);
        if (!empty($input['enabled']) && $requiredTools === []) {
            agentTeamError(400, '启用的路由能力必须配置至少一个必需工具');
        }
        $toolIds = [];
        $toolIdsByKey = [];
        if ($requiredTools !== []) {
            $toolStmt = $pdo->prepare(
                'SELECT id, tool_key FROM tool_registry
                 WHERE tool_key IN (' . implode(',', array_fill(0, count($requiredTools), '?')) . ')
                   AND enabled=1'
            );
            $toolStmt->execute($requiredTools);
            $found = [];
            foreach ($toolStmt->fetchAll() as $tool) {
                $found[(string)$tool['tool_key']] = true;
                $toolIds[] = (int)$tool['id'];
                $toolIdsByKey[(string)$tool['tool_key']] = (int)$tool['id'];
            }
            $missing = array_values(array_filter(
                $requiredTools,
                static fn(string $tool): bool => !isset($found[$tool])
            ));
            if ($missing !== []) {
                agentTeamError(409, '必需工具不存在或未启用：' . implode(', ', $missing));
            }
        }
        if (!empty($input['enabled'])) {
            $delegationStmt = $pdo->prepare(
                'SELECT 1
                 FROM agent_delegations d
                 JOIN agents parent ON parent.id=d.parent_agent_id
                 WHERE parent.agent_key="moonya" AND parent.enabled=1
                   AND d.child_agent_id=? AND d.enabled=1
                 LIMIT 1'
            );
            $delegationStmt->execute([$agentId]);
            if (!$delegationStmt->fetchColumn()) {
                agentTeamError(409, '负责 Agent 尚未获得 MoonYa 的委派授权');
            }
            $grantedIds = [];
            if ($toolIds !== []) {
                $grantStmt = $pdo->prepare(
                    'SELECT tool_id FROM agent_tool_grants
                     WHERE agent_id=? AND enabled=1
                       AND tool_id IN (' . implode(',', array_fill(0, count($toolIds), '?')) . ')'
                );
                $grantStmt->execute(array_merge([$agentId], $toolIds));
                $grantedIds = array_map('intval', $grantStmt->fetchAll(PDO::FETCH_COLUMN));
            }
            $missingGrantIds = array_values(array_diff($toolIds, $grantedIds));
            if ($missingGrantIds !== []) {
                $missingTools = [];
                foreach ($requiredTools as $requiredTool) {
                    if (isset($toolIdsByKey[$requiredTool])
                        && in_array($toolIdsByKey[$requiredTool], $missingGrantIds, true)) {
                        $missingTools[] = $requiredTool;
                    }
                }
                agentTeamError(409, '负责 Agent 缺少必需工具授权：' . implode(', ', $missingTools));
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO agent_routing_capabilities
             (capability_key, agent_id, display_name, description, examples_json,
              exclusions_json, required_tools_json, enabled, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               agent_id=VALUES(agent_id), display_name=VALUES(display_name),
               description=VALUES(description), examples_json=VALUES(examples_json),
               exclusions_json=VALUES(exclusions_json),
               required_tools_json=VALUES(required_tools_json),
               enabled=VALUES(enabled), sort_order=VALUES(sort_order)'
        );
        $stmt->execute([
            $capabilityKey,
            $agentId,
            $capabilityDisplayName,
            $capabilityDescription,
            json_encode($examples, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($exclusions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($requiredTools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            !empty($input['enabled']) ? 1 : 0,
            (int)($input['sort_order'] ?? 0),
        ]);
        $logger->logAdminAction($admin['id'], 'save_routing_capability', null, json_encode([
            'capability_key' => $capabilityKey,
            'agent_key' => $agentKey,
        ], JSON_UNESCAPED_UNICODE));
        agentTeamSuccess(agentTeamSnapshot($pdo));
    }

    if ($action === 'save_runtime') {
        $allowed = [
            'multi_agent_v1', 'mcp_gateway', 'max_parallel_agents',
            'max_root_delegations', 'max_planning_corrections',
            'max_agent_iterations', 'approval_timeout_seconds', 'event_payload_max_bytes',
            'max_shell_preflight_corrections', 'loop_guard_repeat_count',
            'loop_guard_max_period', 'loop_guard_recovery_attempts',
        ];
        $values = is_array($input['values'] ?? null) ? $input['values'] : [];
        $stmt = $pdo->prepare(
            'INSERT INTO agent_runtime_config (config_key, config_value, description)
             VALUES (?, ?, "") ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)'
        );
        foreach ($values as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
            }
        }
        $logger->logAdminAction($admin['id'], 'save_agent_runtime', null, json_encode(['keys' => array_keys($values)], JSON_UNESCAPED_UNICODE));
        agentTeamSuccess(agentTeamSnapshot($pdo));
    }

    if ($action === 'save_mcp_server') {
        $serverKey = trim((string)($input['server_key'] ?? ''));
        $displayName = trim((string)($input['display_name'] ?? ''));
        $transport = (string)($input['transport'] ?? '');
        $authMode = (string)($input['auth_mode'] ?? 'none');
        if (!preg_match('/^[a-z][a-z0-9_-]{1,99}$/', $serverKey) ||
            $displayName === '' ||
            !in_array($transport, ['stdio', 'streamable_http'], true) ||
            !in_array($authMode, ['none', 'oauth', 'headers'], true)) {
            agentTeamError(400, 'MCP 服务器配置无效');
        }
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($transport === 'streamable_http' && !preg_match('#^https://#i', $endpoint) &&
            !preg_match('#^http://(?:127\.0\.0\.1|localhost)(?::\d+)?(?:/|$)#i', $endpoint)) {
            agentTeamError(400, '远程 MCP 必须使用 HTTPS；仅 localhost 允许 HTTP');
        }
        assertMcpSecretsAreVaultReferences($input['environment_json'] ?? []);
        assertMcpSecretsAreVaultReferences($input['oauth_config_json'] ?? []);
        $stmt = $pdo->prepare(
            'INSERT INTO mcp_servers
             (server_key, display_name, transport, endpoint, command_path, arguments_json,
              environment_json, auth_mode, oauth_config_json, enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               display_name=VALUES(display_name),
               transport=VALUES(transport),
               endpoint=VALUES(endpoint),
               command_path=VALUES(command_path),
               arguments_json=VALUES(arguments_json),
               environment_json=VALUES(environment_json),
               auth_mode=VALUES(auth_mode),
               oauth_config_json=VALUES(oauth_config_json),
               enabled=VALUES(enabled)'
        );
        $stmt->execute([
            $serverKey,
            $displayName,
            $transport,
            $endpoint ?: null,
            trim((string)($input['command_path'] ?? '')) ?: null,
            normalizeJsonField($input['arguments_json'] ?? [], false) ?: '[]',
            normalizeJsonField($input['environment_json'] ?? [], false) ?: '{}',
            $authMode,
            normalizeJsonField($input['oauth_config_json'] ?? [], false) ?: '{}',
            !empty($input['enabled']) ? 1 : 0,
        ]);
        $logger->logAdminAction($admin['id'], 'save_mcp_server', null, json_encode(['server_key' => $serverKey], JSON_UNESCAPED_UNICODE));
        agentTeamSuccess(agentTeamSnapshot($pdo));
    }

    if ($action === 'delete_mcp_server') {
        $serverId = (int)($input['id'] ?? 0);
        $pdo->prepare('DELETE FROM mcp_servers WHERE id=?')->execute([$serverId]);
        $logger->logAdminAction($admin['id'], 'delete_mcp_server', null, json_encode(['id' => $serverId], JSON_UNESCAPED_UNICODE));
        agentTeamSuccess(agentTeamSnapshot($pdo));
    }

    if ($action === 'review_mcp_tool') {
        $catalogId = (int)($input['id'] ?? 0);
        $risk = (string)($input['risk_level'] ?? 'high');
        $effect = (string)($input['effect'] ?? 'write');
        $enabled = !empty($input['enabled']) ? 1 : 0;
        if (!in_array($risk, ['low', 'medium', 'high', 'critical'], true) ||
            !in_array($effect, ['read', 'write', 'destructive'], true)) {
            agentTeamError(400, 'MCP 工具风险配置无效');
        }
        $stmt = $pdo->prepare('SELECT function_name FROM mcp_tool_catalog WHERE id=?');
        $stmt->execute([$catalogId]);
        $functionName = (string)($stmt->fetchColumn() ?: '');
        if ($functionName === '') {
            agentTeamError(404, 'MCP 工具不存在');
        }
        $pdo->beginTransaction();
        $pdo->prepare(
            'UPDATE mcp_tool_catalog SET enabled=?, reviewed=1, risk_level=?, effect=? WHERE id=?'
        )->execute([$enabled, $risk, $effect, $catalogId]);
        $pdo->prepare(
            'UPDATE tool_registry SET enabled=?, reviewed=1, risk_level=?, effect=? WHERE tool_key=?'
        )->execute([$enabled, $risk, $effect, $functionName]);
        $pdo->commit();
        $logger->logAdminAction($admin['id'], 'review_mcp_tool', null, json_encode(['id' => $catalogId, 'enabled' => $enabled], JSON_UNESCAPED_UNICODE));
        agentTeamSuccess(agentTeamSnapshot($pdo));
    }

    if ($action === 'sync_mcp_catalog') {
        $serverKey = trim((string)($input['server_key'] ?? ''));
        $tools = is_array($input['tools'] ?? null) ? $input['tools'] : [];
        $stmt = $pdo->prepare('SELECT id FROM mcp_servers WHERE server_key=? LIMIT 1');
        $stmt->execute([$serverKey]);
        $serverId = (int)($stmt->fetchColumn() ?: 0);
        if ($serverId <= 0) {
            agentTeamError(404, 'MCP 服务不存在');
        }
        $count = adminSyncMcpCatalog($pdo, $serverId, $serverKey, $tools);
        $hash = hash('sha256', json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $pdo->prepare(
            'UPDATE mcp_servers
             SET last_status="connected", last_error=NULL, catalog_hash=?, last_seen_at=CURRENT_TIMESTAMP
             WHERE id=?'
        )->execute([$hash, $serverId]);
        $logger->logAdminAction(
            $admin['id'],
            'sync_mcp_catalog',
            null,
            json_encode(['server_key' => $serverKey, 'count' => $count], JSON_UNESCAPED_UNICODE)
        );
        agentTeamSuccess(agentTeamSnapshot($pdo));
    }

    agentTeamError(404, '未知操作');
} catch (InvalidArgumentException $e) {
    agentTeamError(400, $e->getMessage());
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[agent-team-admin] ' . $e->getMessage());
    agentTeamError(500, '保存失败：' . $e->getMessage());
}

function normalizeJsonField($value, bool $required): ?string
{
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $text = trim((string)$value);
    if ($text === '') {
        if ($required) {
            throw new InvalidArgumentException('JSON Schema 不能为空');
        }
        return null;
    }
    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('JSON 字段格式无效');
    }
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function assertMcpSecretsAreVaultReferences($value, ?string $key = null): void
{
    if (is_string($value) && $key === null) {
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return;
        }
        $value = $decoded;
    }
    if (is_array($value)) {
        foreach ($value as $childKey => $childValue) {
            assertMcpSecretsAreVaultReferences($childValue, is_string($childKey) ? $childKey : $key);
        }
        return;
    }
    if ($key !== null &&
        preg_match('/token|authorization|api[_-]?key|password|passwd|secret|credential|cookie/i', $key) &&
        is_string($value) &&
        $value !== '' &&
        !str_starts_with($value, 'vault://')) {
        throw new InvalidArgumentException("敏感字段 {$key} 只能保存 vault:// 引用");
    }
}

function normalizeJsonArray($value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            agentTeamError(400, '列表字段必须是有效的 JSON 数组');
        }
        $value = $decoded;
    }
    if (!is_array($value)) {
        agentTeamError(400, '列表字段必须是数组');
    }
    return array_values(array_unique(array_filter(array_map(
        static fn($item): string => trim((string)$item),
        $value
    ), static fn(string $item): bool => $item !== '')));
}

function validateAgentCapabilityGrants(
    PDO $pdo,
    int $agentId,
    array $toolIds,
    ?string $onlyCapability = null
): void {
    $sql = 'SELECT capability_key, required_tools_json
            FROM agent_routing_capabilities
            WHERE agent_id=? AND enabled=1';
    $params = [$agentId];
    if ($onlyCapability !== null) {
        $sql .= ' AND capability_key=?';
        $params[] = $onlyCapability;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $toolKeyById = [];
    if ($toolIds !== []) {
        $toolStmt = $pdo->prepare(
            'SELECT id, tool_key FROM tool_registry
             WHERE id IN (' . implode(',', array_fill(0, count($toolIds), '?')) . ')
               AND enabled=1'
        );
        $toolStmt->execute($toolIds);
        foreach ($toolStmt->fetchAll() as $tool) {
            $toolKeyById[(int)$tool['id']] = (string)$tool['tool_key'];
        }
    }
    $granted = array_fill_keys(array_values($toolKeyById), true);
    foreach ($stmt->fetchAll() as $capability) {
        $required = json_decode((string)$capability['required_tools_json'], true);
        $required = is_array($required) ? $required : [];
        $missing = array_values(array_filter(
            $required,
            static fn(string $tool): bool => !isset($granted[$tool])
        ));
        if ($missing !== []) {
            agentTeamError(
                409,
                '能力 ' . $capability['capability_key'] .
                ' 缺少必需工具授权：' . implode(', ', $missing)
            );
        }
    }
}

function capabilitiesRequiringTool(PDO $pdo, int $toolId): array
{
    $stmt = $pdo->prepare('SELECT tool_key FROM tool_registry WHERE id=? LIMIT 1');
    $stmt->execute([$toolId]);
    $toolKey = (string)($stmt->fetchColumn() ?: '');
    if ($toolKey === '') {
        return [];
    }
    $matches = [];
    foreach ($pdo->query(
        'SELECT capability_key, required_tools_json
         FROM agent_routing_capabilities WHERE enabled=1'
    )->fetchAll() as $capability) {
        $required = json_decode((string)$capability['required_tools_json'], true);
        if (is_array($required) && in_array($toolKey, $required, true)) {
            $matches[] = (string)$capability['capability_key'];
        }
    }
    return $matches;
}

function agentTeamSnapshot(PDO $pdo): array
{
    $agents = $pdo->query('SELECT * FROM agents ORDER BY sort_order, id')->fetchAll();
    $tools = $pdo->query('SELECT * FROM tool_registry ORDER BY source, tool_key')->fetchAll();
    $delegations = $pdo->query(
        'SELECT d.id, parent.agent_key AS parent_agent_key, child.agent_key AS child_agent_key, d.enabled
         FROM agent_delegations d
         JOIN agents parent ON parent.id=d.parent_agent_id
         JOIN agents child ON child.id=d.child_agent_id
         ORDER BY parent.sort_order, child.sort_order'
    )->fetchAll();
    $grants = $pdo->query(
        'SELECT a.agent_key, t.id AS tool_id, t.tool_key, g.enabled
         FROM agent_tool_grants g
         JOIN agents a ON a.id=g.agent_id
         JOIN tool_registry t ON t.id=g.tool_id
         ORDER BY a.sort_order, t.tool_key'
    )->fetchAll();
    $capabilities = $pdo->query(
        'SELECT c.*, a.agent_key, a.display_name AS agent_display_name,
                EXISTS(
                    SELECT 1
                    FROM agent_delegations d2
                    JOIN agents parent ON parent.id=d2.parent_agent_id
                    WHERE parent.agent_key="moonya" AND parent.enabled=1
                      AND d2.child_agent_id=c.agent_id AND d2.enabled=1
                ) AS delegated_by_moonya
         FROM agent_routing_capabilities c
         JOIN agents a ON a.id=c.agent_id
         ORDER BY c.sort_order, c.id'
    )->fetchAll();
    $activeGrantKeys = [];
    foreach ($grants as $grant) {
        if ((int)$grant['enabled'] === 1) {
            $activeGrantKeys[(string)$grant['agent_key']][(string)$grant['tool_key']] = true;
        }
    }
    foreach ($capabilities as &$capability) {
        foreach (['examples', 'exclusions', 'required_tools'] as $field) {
            $decoded = json_decode((string)$capability[$field . '_json'], true);
            $capability[$field] = is_array($decoded) ? $decoded : [];
        }
        $agentGrants = $activeGrantKeys[(string)$capability['agent_key']] ?? [];
        $capability['missing_tools'] = array_values(array_filter(
            $capability['required_tools'],
            static fn(string $tool): bool => !isset($agentGrants[$tool])
        ));
        $capability['delegated_by_moonya'] = (bool)$capability['delegated_by_moonya'];
        $capability['authorization_differences'] = $capability['missing_tools'];
        if (!$capability['delegated_by_moonya']) {
            $capability['authorization_differences'][] = 'delegation:moonya';
        }
        $capability['ready'] = $capability['missing_tools'] === []
            && $capability['delegated_by_moonya']
            && (int)$capability['enabled'] === 1;
    }
    unset($capability);
    $runtime = [];
    foreach ($pdo->query('SELECT config_key, config_value, description FROM agent_runtime_config')->fetchAll() as $row) {
        $decoded = json_decode((string)$row['config_value'], true);
        $runtime[$row['config_key']] = [
            'value' => json_last_error() === JSON_ERROR_NONE ? $decoded : $row['config_value'],
            'description' => $row['description'],
        ];
    }
    $mcpServers = $pdo->query('SELECT * FROM mcp_servers ORDER BY display_name')->fetchAll();
    foreach ($mcpServers as &$server) {
        // Configuration is shown to admins, but secret values are references only.
        $env = json_decode((string)($server['environment_json'] ?? ''), true);
        if (is_array($env)) {
            foreach ($env as $key => &$value) {
                if (preg_match('/token|key|secret|password/i', (string)$key) && is_string($value) && !str_starts_with($value, 'vault://')) {
                    $value = '[REDACTED: use vault:// reference]';
                }
            }
            unset($value);
            $server['environment_json'] = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    unset($server);
    $mcpTools = $pdo->query(
        'SELECT c.*, s.server_key, s.display_name AS server_name
         FROM mcp_tool_catalog c
         JOIN mcp_servers s ON s.id=c.mcp_server_id
         ORDER BY s.display_name, c.original_name'
    )->fetchAll();
    $prompts = $pdo->query(
        'SELECT name, display_name FROM system_prompts WHERE enabled=1 ORDER BY sort_order, id'
    )->fetchAll();
    return compact(
        'agents',
        'tools',
        'delegations',
        'grants',
        'capabilities',
        'runtime',
        'mcpServers',
        'mcpTools',
        'prompts'
    );
}

function adminSyncMcpCatalog(PDO $pdo, int $serverId, string $serverKey, array $tools): int
{
    $catalog = $pdo->prepare(
        'INSERT INTO mcp_tool_catalog
         (mcp_server_id, original_name, function_name, title, description, input_schema, output_schema, annotations_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           function_name=VALUES(function_name), title=VALUES(title), description=VALUES(description),
           input_schema=VALUES(input_schema), output_schema=VALUES(output_schema),
           annotations_json=VALUES(annotations_json), last_seen_at=CURRENT_TIMESTAMP'
    );
    $registry = $pdo->prepare(
        'INSERT INTO tool_registry
         (tool_key, display_name, description, input_schema, output_schema, transport, transport_config,
          effect, risk_level, source, route_class, enabled, reviewed)
         VALUES (?, ?, ?, ?, ?, "mcp", ?, "write", "high", "mcp", "specialized_api", 0, 0)
         ON DUPLICATE KEY UPDATE
           display_name=VALUES(display_name), description=VALUES(description),
           input_schema=VALUES(input_schema), output_schema=VALUES(output_schema),
           transport_config=VALUES(transport_config), route_class="specialized_api"'
    );
    $count = 0;
    foreach ($tools as $tool) {
        if (!is_array($tool) || trim((string)($tool['name'] ?? '')) === '') {
            continue;
        }
        $original = trim((string)$tool['name']);
        $functionName = adminUniqueMcpFunctionName($pdo, $serverId, $serverKey, $original);
        $title = trim((string)($tool['title'] ?? $original));
        $description = trim((string)($tool['description'] ?? $title));
        $inputSchema = is_array($tool['inputSchema'] ?? null)
            ? $tool['inputSchema']
            : ['type' => 'object', 'properties' => new stdClass()];
        $outputSchema = is_array($tool['outputSchema'] ?? null) ? $tool['outputSchema'] : null;
        $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
        $encodedInput = json_encode($inputSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encodedOutput = $outputSchema === null
            ? null
            : json_encode($outputSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $catalog->execute([
            $serverId, $original, $functionName, $title, $description,
            $encodedInput, $encodedOutput,
            json_encode($annotations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $registry->execute([
            $functionName, $title, $description, $encodedInput, $encodedOutput,
            json_encode(['server_key' => $serverKey, 'original_name' => $original], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $count++;
    }
    return $count;
}

function adminMcpFunctionName(string $serverKey, string $toolName): string
{
    $base = strtolower($serverKey . '__' . $toolName);
    $base = trim((string)preg_replace('/[^a-z0-9_-]+/', '_', $base), '_-');
    if ($base === '') {
        $base = 'mcp_tool';
    }
    if (strlen($base) > 58) {
        $base = substr($base, 0, 49) . '_' . substr(hash('sha256', $serverKey . "\0" . $toolName), 0, 8);
    }
    return 'mcp__' . $base;
}

function adminUniqueMcpFunctionName(PDO $pdo, int $serverId, string $serverKey, string $toolName): string
{
    $candidate = adminMcpFunctionName($serverKey, $toolName);
    $stmt = $pdo->prepare(
        'SELECT mcp_server_id, original_name FROM mcp_tool_catalog WHERE function_name=? LIMIT 1'
    );
    $stmt->execute([$candidate]);
    $existing = $stmt->fetch();
    if (!$existing ||
        ((int)$existing['mcp_server_id'] === $serverId && (string)$existing['original_name'] === $toolName)) {
        return $candidate;
    }
    $suffix = '_' . substr(hash('sha256', $serverKey . "\0" . $toolName), 0, 8);
    return substr($candidate, 0, 160 - strlen($suffix)) . $suffix;
}
