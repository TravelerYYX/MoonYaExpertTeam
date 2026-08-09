<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/Services/TeamAuth.php';
require_once dirname(__DIR__) . '/Services/TeamRepository.php';
require_once dirname(__DIR__) . '/Services/TeamMediaStore.php';
require_once dirname(__DIR__) . '/Services/ConversationTaskState.php';
require_once dirname(__DIR__) . '/Services/ExecutionJobRepository.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function teamApiSuccess($data): void
{
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function teamApiError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $config = require dirname(__DIR__) . '/config.php';
    $pdo = TeamAuth::connect($config);
    $userId = TeamAuth::requireUser($pdo);
    $repository = new TeamRepository($pdo);
    if (!$repository->isInstalled()) {
        teamApiError(503, '多 Agent 数据库迁移尚未执行');
    }

    $action = (string)($_GET['action'] ?? 'bootstrap');
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET' && $action === 'media') {
        $mediaId = trim((string)($_GET['id'] ?? ''));
        $variant = (string)($_GET['variant'] ?? 'content');
        if (!preg_match('/^[a-f0-9-]{36}$/i', $mediaId)
            || !in_array($variant, ['content', 'thumbnail'], true)
        ) {
            teamApiError(400, '媒体参数无效');
        }
        $media = $repository->eventMediaForUser($userId, $mediaId);
        if ($media === null) {
            teamApiError(404, '媒体不存在');
        }
        $mediaStore = new TeamMediaStore($repository);
        $path = $mediaStore->resolvePath($media, $variant);
        if ($path === null) {
            teamApiError(404, (string)($media['error_message'] ?? '媒体文件不可用'));
        }
        header('Content-Type: ' . (string)$media['mime_type']);
        header('Content-Length: ' . (string)filesize($path));
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        readfile($path);
        exit;
    }

    $body = [];
    if ($method === 'POST') {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            teamApiError(400, '请求体必须是 JSON 对象');
        }
    }

    if ($method === 'GET' && $action === 'bootstrap') {
        $conversationId = isset($_GET['conversation_id']) && (int)$_GET['conversation_id'] > 0
            ? (int)$_GET['conversation_id']
            : null;
        teamApiSuccess($repository->bootstrap($userId, $conversationId));
    }

    if ($method === 'GET' && $action === 'runs') {
        $conversationId = (int)($_GET['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            teamApiError(400, 'conversation_id 不能为空');
        }
        teamApiSuccess([
            'runs' => $repository->runsForConversation(
                $userId,
                $conversationId,
                (int)($_GET['limit'] ?? 20)
            ),
        ]);
    }

    // 办公室界面专用（纯只读）：当前正在工作的 agent_key 集合
    if ($method === 'GET' && $action === 'office_status') {
        $taskState = new ConversationTaskState($pdo);
        $taskState->reconcileStale($userId);
        teamApiSuccess([
            'active_agents' => $repository->officeActiveAgents($userId),
            'active_runs' => $repository->officeActiveRuns($userId),
            'active_conversations' => $taskState->activeForUser($userId),
        ]);
    }

    if ($method === 'GET' && $action === 'mcp_servers') {
        $stmt = $pdo->prepare(
            'SELECT s.id, s.server_key, s.display_name, s.transport, s.endpoint, s.command_path,
                    s.arguments_json, s.environment_json, s.auth_mode, s.oauth_config_json,
                    s.last_status, s.last_error, s.catalog_hash,
                    COALESCE(c.status, "disconnected") AS connection_status,
                    c.vault_key, c.scopes_json, c.expires_at
             FROM mcp_servers s
             LEFT JOIN user_mcp_connections c ON c.mcp_server_id=s.id AND c.user_id=?
             WHERE s.enabled=1
             ORDER BY s.display_name'
        );
        $stmt->execute([$userId]);
        $servers = $stmt->fetchAll();
        foreach ($servers as &$server) {
            foreach (['arguments_json', 'environment_json', 'oauth_config_json', 'scopes_json'] as $field) {
                $decoded = json_decode((string)($server[$field] ?? ''), true);
                $server[$field] = is_array($decoded) ? TeamRepository::redact($decoded) : [];
            }
        }
        unset($server);
        teamApiSuccess(['servers' => $servers]);
    }

    if ($method === 'POST' && $action === 'set_approval_mode') {
        $conversationId = (int)($body['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            teamApiError(400, 'conversation_id 不能为空');
        }
        teamApiSuccess([
            'approval_mode' => $repository->setApprovalMode(
                $userId,
                $conversationId,
                (string)($body['approval_mode'] ?? '')
            ),
        ]);
    }

    if ($method === 'POST' && $action === 'decide_approval') {
        $approvalId = (string)($body['approval_id'] ?? '');
        $decision = (string)($body['decision'] ?? '');
        if ($approvalId === '' || !in_array($decision, ['allow_once', 'deny'], true)) {
            teamApiError(400, '确认参数无效');
        }
        teamApiSuccess([
            'status' => $repository->decideApproval($userId, $approvalId, $decision),
        ]);
    }

    if ($method === 'POST' && $action === 'cancel_run') {
        $runId = trim((string)($body['run_id'] ?? ''));
        if ($runId === '') {
            teamApiError(400, 'run_id 不能为空');
        }
        if (!$repository->cancelRun($userId, $runId)) {
            teamApiError(404, '团队运行不存在或已结束');
        }
        $executionJobs = new ExecutionJobRepository($pdo);
        if ($executionJobs->isInstalled()) {
            $executionJobs->cancelForRun($userId, $runId);
        }
        teamApiSuccess(['run_id' => $runId, 'status' => 'cancelled']);
    }

    if ($method === 'POST' && $action === 'sync_mcp_catalog') {
        $serverKey = trim((string)($body['server_key'] ?? ''));
        $tools = $body['tools'] ?? [];
        if ($serverKey === '' || !is_array($tools)) {
            teamApiError(400, 'MCP 目录参数无效');
        }
        $stmt = $pdo->prepare('SELECT id FROM mcp_servers WHERE server_key=? AND enabled=1 LIMIT 1');
        $stmt->execute([$serverKey]);
        $serverId = (int)($stmt->fetchColumn() ?: 0);
        if ($serverId <= 0) {
            teamApiError(404, 'MCP 服务器不存在或已停用');
        }
        $synced = syncMcpCatalog($pdo, $serverId, $serverKey, $tools);
        $hash = hash('sha256', json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $pdo->prepare(
            'UPDATE mcp_servers
             SET last_status=?, last_error=?, catalog_hash=?, last_seen_at=CURRENT_TIMESTAMP
             WHERE id=?'
        )->execute([
            (string)($body['status'] ?? 'connected') === 'error' ? 'error' : 'connected',
            isset($body['error']) ? (string)$body['error'] : null,
            $hash,
            $serverId,
        ]);
        teamApiSuccess(['synced' => $synced, 'catalog_hash' => $hash]);
    }

    if ($method === 'POST' && $action === 'update_mcp_connection') {
        $serverKey = trim((string)($body['server_key'] ?? ''));
        $stmt = $pdo->prepare('SELECT id FROM mcp_servers WHERE server_key=? LIMIT 1');
        $stmt->execute([$serverKey]);
        $serverId = (int)($stmt->fetchColumn() ?: 0);
        if ($serverId <= 0) {
            teamApiError(404, 'MCP 服务器不存在');
        }
        $validStatus = ['disconnected', 'authorizing', 'connected', 'expired', 'error'];
        $status = (string)($body['status'] ?? 'disconnected');
        if (!in_array($status, $validStatus, true)) {
            teamApiError(400, '连接状态无效');
        }
        $vaultKey = trim((string)($body['vault_key'] ?? "moonya:{$userId}:{$serverKey}"));
        $pdo->prepare(
            'INSERT INTO user_mcp_connections
             (user_id, mcp_server_id, vault_key, status, scopes_json, expires_at, last_error)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               vault_key=VALUES(vault_key),
               status=VALUES(status),
               scopes_json=VALUES(scopes_json),
               expires_at=VALUES(expires_at),
               last_error=VALUES(last_error)'
        )->execute([
            $userId,
            $serverId,
            $vaultKey,
            $status,
            json_encode($body['scopes'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $body['expires_at'] ?? null,
            isset($body['error']) ? (string)$body['error'] : null,
        ]);
        teamApiSuccess(['status' => $status, 'vault_key' => $vaultKey]);
    }

    teamApiError(404, '未知操作');
} catch (InvalidArgumentException $e) {
    teamApiError(400, $e->getMessage());
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'AUTH_REQUIRED') {
        teamApiError(401, '请先登录');
    }
    teamApiError(409, $e->getMessage());
} catch (Throwable $e) {
    error_log('[team-api] ' . $e->getMessage());
    teamApiError(500, '团队服务暂不可用');
}

function syncMcpCatalog(PDO $pdo, int $serverId, string $serverKey, array $tools): int
{
    $upsertCatalog = $pdo->prepare(
        'INSERT INTO mcp_tool_catalog
         (mcp_server_id, original_name, function_name, title, description, input_schema, output_schema, annotations_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           function_name=VALUES(function_name),
           title=VALUES(title),
           description=VALUES(description),
           input_schema=VALUES(input_schema),
           output_schema=VALUES(output_schema),
           annotations_json=VALUES(annotations_json),
           last_seen_at=CURRENT_TIMESTAMP'
    );
    $upsertRegistry = $pdo->prepare(
        'INSERT INTO tool_registry
         (tool_key, display_name, description, input_schema, output_schema, transport, transport_config,
          effect, risk_level, source, route_class, enabled, reviewed)
         VALUES (?, ?, ?, ?, ?, "mcp", ?, "write", "high", "mcp", "specialized_api", 0, 0)
         ON DUPLICATE KEY UPDATE
           display_name=VALUES(display_name),
           description=VALUES(description),
           input_schema=VALUES(input_schema),
           output_schema=VALUES(output_schema),
           transport_config=VALUES(transport_config), route_class="specialized_api"'
    );
    $count = 0;
    foreach ($tools as $tool) {
        if (!is_array($tool)) {
            continue;
        }
        $original = trim((string)($tool['name'] ?? ''));
        if ($original === '') {
            continue;
        }
        $functionName = uniqueMcpFunctionName($pdo, $serverId, $serverKey, $original);
        $title = trim((string)($tool['title'] ?? $original));
        $description = trim((string)($tool['description'] ?? $title));
        $inputSchema = is_array($tool['inputSchema'] ?? null)
            ? $tool['inputSchema']
            : ['type' => 'object', 'properties' => new stdClass()];
        $outputSchema = is_array($tool['outputSchema'] ?? null) ? $tool['outputSchema'] : null;
        $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
        $upsertCatalog->execute([
            $serverId,
            $original,
            $functionName,
            $title,
            $description,
            json_encode($inputSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $outputSchema === null ? null : json_encode($outputSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($annotations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $upsertRegistry->execute([
            $functionName,
            $title,
            $description,
            json_encode($inputSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $outputSchema === null ? null : json_encode($outputSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode([
                'server_key' => $serverKey,
                'original_name' => $original,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $count++;
    }
    return $count;
}

function mcpFunctionName(string $serverKey, string $toolName): string
{
    $base = strtolower($serverKey . '__' . $toolName);
    $base = preg_replace('/[^a-z0-9_-]+/', '_', $base);
    $base = trim((string)$base, '_-');
    if ($base === '') {
        $base = 'mcp_tool';
    }
    $prefix = 'mcp__';
    $maxBase = 58;
    if (strlen($base) > $maxBase) {
        $base = substr($base, 0, $maxBase - 9) . '_' . substr(hash('sha256', $serverKey . "\0" . $toolName), 0, 8);
    }
    return $prefix . $base;
}

function uniqueMcpFunctionName(PDO $pdo, int $serverId, string $serverKey, string $toolName): string
{
    $candidate = mcpFunctionName($serverKey, $toolName);
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
