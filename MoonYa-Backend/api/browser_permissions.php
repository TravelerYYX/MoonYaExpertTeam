<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_start();

function browserPermissionResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function browserPermissionOrigin(string $url): array
{
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        throw new InvalidArgumentException('url 必须是绝对 HTTP 地址');
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : 0;
    if ($port < 0 || $port > 65535) throw new InvalidArgumentException('url 端口无效');
    $origin = $scheme . '://' . $host . ($port > 0 ? ':' . $port : '');
    return [$scheme, $host, $port, $origin];
}

if (empty($_SESSION['user_id'])) {
    browserPermissionResponse(401, ['success' => false, 'error' => '用户未登录']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_ORIGIN'])) {
    $originHost = strtolower((string)parse_url((string)$_SERVER['HTTP_ORIGIN'], PHP_URL_HOST));
    $requestHost = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
    if ($originHost === '' || !hash_equals($requestHost, $originHost)) {
        browserPermissionResponse(403, ['success' => false, 'error' => '跨站请求已拒绝']);
    }
}

$config = require dirname(__DIR__) . '/config.php';
foreach (['db_host', 'db_name', 'db_user'] as $requiredField) {
    if (!isset($config[$requiredField]) || trim((string)$config[$requiredField]) === '') {
        browserPermissionResponse(503, ['success' => false, 'error' => '缺少必填数据库配置字段 ' . $requiredField]);
    }
}

try {
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        (string)$config['db_user'],
        (string)($config['db_pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $userId = (int)$_SESSION['user_id'];
    $body = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? json_decode((string)file_get_contents('php://input'), true)
        : $_GET;
    if (!is_array($body)) throw new InvalidArgumentException('请求体不是有效 JSON');
    $operation = trim((string)($body['operation'] ?? ''));

    if ($operation === 'list') {
        $statement = $pdo->prepare(
            'SELECT scheme, host, port, decision, created_at, updated_at
             FROM browser_site_permissions WHERE user_id=? ORDER BY host, scheme, port'
        );
        $statement->execute([$userId]);
        browserPermissionResponse(200, ['success' => true, 'permissions' => $statement->fetchAll()]);
    }

    if ($operation === 'check') {
        [$scheme, $host, $port] = browserPermissionOrigin((string)($body['url'] ?? ''));
        $statement = $pdo->prepare(
            'SELECT decision FROM browser_site_permissions
             WHERE user_id=? AND scheme=? AND host=? AND port=? LIMIT 1'
        );
        $statement->execute([$userId, $scheme, $host, $port]);
        browserPermissionResponse(200, ['success' => true, 'decision' => $statement->fetchColumn() ?: null]);
    }

    if ($operation === 'set') {
        [$scheme, $host, $port] = browserPermissionOrigin((string)($body['url'] ?? ''));
        $decision = (string)($body['decision'] ?? '');
        if (!in_array($decision, ['allow_always', 'block'], true)) {
            throw new InvalidArgumentException('decision 仅支持 allow_always 或 block');
        }
        $statement = $pdo->prepare(
            'INSERT INTO browser_site_permissions (user_id,scheme,host,port,decision)
             VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE decision=VALUES(decision), updated_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([$userId, $scheme, $host, $port, $decision]);
        browserPermissionResponse(200, ['success' => true]);
    }

    if ($operation === 'revoke') {
        $scheme = strtolower(trim((string)($body['scheme'] ?? '')));
        $host = strtolower(trim((string)($body['host'] ?? '')));
        $port = (int)($body['port'] ?? 0);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $port < 0 || $port > 65535) {
            throw new InvalidArgumentException('站点权限标识无效');
        }
        $statement = $pdo->prepare(
            'DELETE FROM browser_site_permissions WHERE user_id=? AND scheme=? AND host=? AND port=?'
        );
        $statement->execute([$userId, $scheme, $host, $port]);
        browserPermissionResponse(200, ['success' => true, 'revoked' => $statement->rowCount() > 0]);
    }

    if ($operation === 'record_confirmation') {
        [, , , $origin] = browserPermissionOrigin((string)($body['url'] ?? ''));
        $sessionId = trim((string)($body['session_id'] ?? ''));
        $action = trim((string)($body['action'] ?? ''));
        $token = trim((string)($body['approval_token'] ?? ''));
        $pageVersion = (int)($body['page_version'] ?? -1);
        $riskCategory = trim((string)($body['risk_category'] ?? ''));
        $status = (string)($body['status'] ?? '');
        $allowedRisks = ['site_access', 'tls_exception', 'submit_personal_data', 'purchase', 'change_permissions', 'delete_data'];
        if ($action === '' || $token === '' || $pageVersion < 0
            || !in_array($riskCategory, $allowedRisks, true)
            || !in_array($status, ['approved', 'denied'], true)) {
            throw new InvalidArgumentException('确认记录上下文不完整');
        }
        $ttl = max(30, (int)($config['browser_security']['confirmation_ttl_seconds'] ?? 0));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
        $statement = $pdo->prepare(
            'INSERT INTO browser_action_confirmations
             (user_id,session_id,site_origin,page_version,action,risk_category,token_hash,status,expires_at,decided_at)
             VALUES (?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE status=VALUES(status), decided_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([
            $userId, $sessionId, $origin, $pageVersion, $action, $riskCategory,
            hash('sha256', $token), $status, $expiresAt,
        ]);
        browserPermissionResponse(200, ['success' => true]);
    }

    throw new InvalidArgumentException('未知 operation');
} catch (InvalidArgumentException $error) {
    browserPermissionResponse(400, ['success' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('[browser_permissions] ' . $error->getMessage());
    browserPermissionResponse(500, ['success' => false, 'error' => '浏览器权限服务暂不可用']);
}
