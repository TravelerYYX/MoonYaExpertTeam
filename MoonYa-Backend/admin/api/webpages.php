<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../Database.php';
    require_once __DIR__ . '/../Auth.php';
    require_once __DIR__ . '/../Logger.php';

    $config = require __DIR__ . '/../config.php';

    $db = new Database($config);
    $conn = $db->getConnection();

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

    if (!$currentAdmin) {
        sendError(401, '未授权访问，请先登录');
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    switch ($method) {
        case 'GET':
            if ($action === 'get' && isset($_GET['id'])) {
                getWebpage($conn, intval($_GET['id']));
            } else {
                getWebpages($conn);
            }
            break;
        case 'POST':
            if ($action === 'delete') {
                deleteWebpage($conn, $currentAdmin, $logger);
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

function getWebpages($conn) {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['page_size']) ? max(1, min(100, intval($_GET['page_size']))) : 10;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    $offset = ($page - 1) * $pageSize;
    
    $whereClause = '';
    $params = [];
    
    if (!empty($search)) {
        $whereClause = " WHERE w.title LIKE ?";
        $params[] = '%' . $search . '%';
    }
    
    $countSql = "SELECT COUNT(*) as total FROM webpages w" . $whereClause;
    $stmt = $conn->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    $sql = "SELECT w.id, w.user_id, w.title, w.preview_token, w.created_at, u.username 
            FROM webpages w 
            LEFT JOIN users u ON w.user_id = u.id" 
            . $whereClause . 
            " ORDER BY w.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $pageSize;
    $params[] = $offset;
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $webpages = $stmt->fetchAll();
    
    sendSuccess([
        'webpages' => $webpages,
        'total' => intval($total),
        'page' => $page,
        'page_size' => $pageSize
    ]);
}

function getWebpage($conn, $id) {
    $stmt = $conn->prepare("SELECT w.*, u.username FROM webpages w LEFT JOIN users u ON w.user_id = u.id WHERE w.id = ?");
    $stmt->execute([$id]);
    $webpage = $stmt->fetch();
    
    if (!$webpage) {
        sendError(404, '网页不存在');
    }
    
    sendSuccess([
        'webpage' => $webpage
    ]);
}

function deleteWebpage($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['id'])) {
        sendError(400, '网页ID不能为空');
    }
    
    $stmt = $conn->prepare("DELETE FROM webpages WHERE id = ?");
    $stmt->execute([$input['id']]);
    
    $logger->logAdminAction($admin['id'], 'delete_webpage', $input['id'], null);
    
    sendSuccess(['message' => '网页删除成功']);
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
