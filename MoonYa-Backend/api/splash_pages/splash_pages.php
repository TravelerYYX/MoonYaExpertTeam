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

    $publicActions = ['active'];
    $requiresAuth = !in_array($action, $publicActions);

    if ($requiresAuth && !$currentAdmin) {
        sendError(401, '未授权访问，请先登录');
    }

    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                getSplashPages($conn);
            } elseif ($action === 'active') {
                getActiveSplashPages($conn);
            } elseif ($action === 'get') {
                getSplashPageById($conn);
            } else {
                getSplashPages($conn);
            }
            break;
        case 'POST':
            if ($action === 'add') {
                addSplashPage($conn, $currentAdmin, $logger);
            } elseif ($action === 'update') {
                updateSplashPage($conn, $currentAdmin, $logger);
            } elseif ($action === 'delete') {
                deleteSplashPage($conn, $currentAdmin, $logger);
            } elseif ($action === 'toggle') {
                toggleSplashPage($conn, $currentAdmin, $logger);
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

function getSplashPages($conn) {
    $sql = "SELECT id, image_url, jump_url, sort_order, is_active, created_at, updated_at 
            FROM splash_pages 
            ORDER BY sort_order ASC, id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $pages = $stmt->fetchAll();

    sendSuccess([
        'splash_pages' => $pages
    ]);
}

function getActiveSplashPages($conn) {
    $sql = "SELECT id, image_url, jump_url, sort_order, is_active, created_at, updated_at 
            FROM splash_pages 
            WHERE is_active = 1 
            ORDER BY RAND() 
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $page = $stmt->fetch();

    sendSuccess([
        'splash_page' => $page ?: null
    ]);
}

function getSplashPageById($conn) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id) {
        sendError(400, '启动页ID不能为空');
    }

    $stmt = $conn->prepare("SELECT id, image_url, jump_url, sort_order, is_active, created_at, updated_at FROM splash_pages WHERE id = ?");
    $stmt->execute([$id]);
    $page = $stmt->fetch();

    if (!$page) {
        sendError(404, '启动页不存在');
    }

    sendSuccess([
        'splash_page' => $page
    ]);
}

function addSplashPage($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['image_url'])) {
        sendError(400, '启动页图片链接不能为空');
    }

    $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM splash_pages");
    $stmt->execute();
    $nextOrder = $stmt->fetch()['next_order'];

    $isActive = isset($input['is_active']) ? $input['is_active'] : 1;

    $stmt = $conn->prepare("INSERT INTO splash_pages (image_url, jump_url, sort_order, is_active) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $input['image_url'],
        $input['jump_url'] ?? '',
        $input['sort_order'] ?? $nextOrder,
        $isActive
    ]);

    $newId = $conn->lastInsertId();

    $logger->logAdminAction($admin['id'], 'add_splash_page', null,
        json_encode(['id' => $newId, 'image_url' => $input['image_url']]));

    sendSuccess(['message' => '启动页添加成功', 'id' => $newId]);
}

function updateSplashPage($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['id'])) {
        sendError(400, '启动页ID不能为空');
    }

    if (empty($input['image_url'])) {
        sendError(400, '启动页图片链接不能为空');
    }

    $stmt = $conn->prepare("UPDATE splash_pages SET image_url = ?, jump_url = ?, sort_order = ?, is_active = ? WHERE id = ?");
    $stmt->execute([
        $input['image_url'],
        $input['jump_url'] ?? '',
        $input['sort_order'] ?? 0,
        isset($input['is_active']) ? $input['is_active'] : 1,
        $input['id']
    ]);

    $logger->logAdminAction($admin['id'], 'update_splash_page', $input['id'],
        json_encode(['image_url' => $input['image_url']]));

    sendSuccess(['message' => '启动页更新成功']);
}

function deleteSplashPage($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['id'])) {
        sendError(400, '启动页ID不能为空');
    }

    $stmt = $conn->prepare("DELETE FROM splash_pages WHERE id = ?");
    $stmt->execute([$input['id']]);

    $logger->logAdminAction($admin['id'], 'delete_splash_page', $input['id'], null);

    sendSuccess(['message' => '启动页删除成功']);
}

function toggleSplashPage($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['id'])) {
        sendError(400, '启动页ID不能为空');
    }

    $isActive = isset($input['is_active']) ? $input['is_active'] : 1;

    $stmt = $conn->prepare("UPDATE splash_pages SET is_active = ? WHERE id = ?");
    $stmt->execute([$isActive, $input['id']]);

    $logger->logAdminAction($admin['id'], 'toggle_splash_page', $input['id'],
        json_encode(['is_active' => $isActive]));

    sendSuccess(['message' => '启动页状态更新成功']);
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
