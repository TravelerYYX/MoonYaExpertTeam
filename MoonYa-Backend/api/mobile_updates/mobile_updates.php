<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }
}

try {
    require_once __DIR__ . '/../../admin/config.php';
    require_once __DIR__ . '/../../admin/Database.php';
    require_once __DIR__ . '/../../admin/Auth.php';
    require_once __DIR__ . '/../../admin/Logger.php';

    $config = require __DIR__ . '/../../admin/config.php';

    $db = new Database($config);
    $conn = $db->getConnection();
    $db->initializeTables();

    $logger = new Logger($config, $conn);
    $auth = new Auth($conn, $config, $logger);

    $headers = getallheaders();
    $token = null;
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
    } elseif (isset($headers['authorization'])) {
        $token = str_replace('Bearer ', '', $headers['authorization']);
    }

    $currentAdmin = $auth->authenticate($token);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    $publicActions = ['latest'];
    $requiresAuth = !in_array($action, $publicActions);

    if ($requiresAuth && !$currentAdmin) {
        sendError(401, '未授权访问，请先登录');
    }

    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                getMobileUpdates($conn);
            } elseif ($action === 'latest') {
                getLatestMobileUpdate($conn);
            } elseif ($action === 'get') {
                getMobileUpdateById($conn);
            } else {
                getMobileUpdates($conn);
            }
            break;
        case 'POST':
            if ($action === 'create') {
                createMobileUpdate($conn, $currentAdmin, $logger);
            } elseif ($action === 'update') {
                updateMobileUpdate($conn, $currentAdmin, $logger);
            } elseif ($action === 'delete') {
                deleteMobileUpdate($conn, $currentAdmin, $logger);
            } elseif ($action === 'toggle') {
                toggleMobileUpdate($conn, $currentAdmin, $logger);
            } else {
                sendError(400, '无效的操作');
            }
            break;
        default:
            sendError(405, '不支持的请求方法');
    }
} catch (Exception $e) {
    sendError(500, '服务器错误: ' . $e->getMessage());
}

function getMobileUpdates($conn) {
    $stmt = $conn->query("SELECT * FROM mobile_updates ORDER BY created_at DESC");
    $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    sendSuccess(['updates' => $updates]);
}

function getLatestMobileUpdate($conn) {
    $stmt = $conn->query("SELECT * FROM mobile_updates WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1");
    $update = $stmt->fetch(PDO::FETCH_ASSOC);
    sendSuccess(['update' => $update]);
}

function getMobileUpdateById($conn) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id) {
        sendError(400, 'ID不能为空');
    }

    $stmt = $conn->prepare("SELECT * FROM mobile_updates WHERE id = ?");
    $stmt->execute([$id]);
    $update = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$update) {
        sendError(404, '移动端更新不存在');
    }

    sendSuccess(['update' => $update]);
}

function createMobileUpdate($conn, $admin, $logger) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['version']) || empty($data['title']) || empty($data['content'])) {
        sendError(400, '版本号、标题和内容不能为空');
    }

    if (!preg_match('/^\d+(\.\d+)*$/', $data['version'])) {
        sendError(400, '版本号格式不正确，应为如 1.2.3 的格式');
    }

    try {
        $stmt = $conn->prepare("INSERT INTO mobile_updates (version, title, content, download_url, is_force, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['version'],
            $data['title'],
            $data['content'],
            $data['download_url'] ?? '',
            $data['is_force'] ?? 0,
            $data['is_active'] ?? 1
        ]);
        $newId = $conn->lastInsertId();

        $logger->logAdminAction($admin['id'], 'create_mobile_update', null,
            json_encode(['id' => $newId, 'version' => $data['version']]));

        sendSuccess(['message' => '移动端更新创建成功', 'id' => $newId]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            sendError(400, '版本号已存在');
        } else {
            throw $e;
        }
    }
}

function updateMobileUpdate($conn, $admin, $logger) {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? 0;

    if (!$id) {
        sendError(400, 'ID不能为空');
    }

    if (empty($data['version']) || empty($data['title']) || empty($data['content'])) {
        sendError(400, '版本号、标题和内容不能为空');
    }

    $stmt = $conn->prepare("UPDATE mobile_updates SET version = ?, title = ?, content = ?, download_url = ?, is_force = ?, is_active = ? WHERE id = ?");
    $stmt->execute([
        $data['version'],
        $data['title'],
        $data['content'],
        $data['download_url'] ?? '',
        $data['is_force'] ?? 0,
        $data['is_active'] ?? 1,
        $id
    ]);

    $logger->logAdminAction($admin['id'], 'update_mobile_update', $id,
        json_encode(['version' => $data['version']]));

    sendSuccess(['message' => '移动端更新修改成功']);
}

function deleteMobileUpdate($conn, $admin, $logger) {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? 0;

    if (!$id) {
        sendError(400, 'ID不能为空');
    }

    $stmt = $conn->prepare("DELETE FROM mobile_updates WHERE id = ?");
    $stmt->execute([$id]);

    $logger->logAdminAction($admin['id'], 'delete_mobile_update', $id, null);

    sendSuccess(['message' => '移动端更新删除成功']);
}

function toggleMobileUpdate($conn, $admin, $logger) {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? 0;

    if (!$id) {
        sendError(400, 'ID不能为空');
    }

    $isActive = isset($data['is_active']) ? $data['is_active'] : 1;

    $stmt = $conn->prepare("UPDATE mobile_updates SET is_active = ? WHERE id = ?");
    $stmt->execute([$isActive, $id]);

    $logger->logAdminAction($admin['id'], 'toggle_mobile_update', $id,
        json_encode(['is_active' => $isActive]));

    sendSuccess(['message' => '移动端更新状态更新成功']);
}

function sendSuccess($data) {
    echo json_encode([
        'success' => true,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
