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
            getHotTopics($conn);
            break;
        case 'POST':
            if ($action === 'add') {
                addHotTopic($conn, $currentAdmin, $logger);
            } elseif ($action === 'update') {
                updateHotTopic($conn, $currentAdmin, $logger);
            } elseif ($action === 'delete') {
                deleteHotTopic($conn, $currentAdmin, $logger);
            } elseif ($action === 'toggle') {
                toggleHotTopic($conn, $currentAdmin, $logger);
            } elseif ($action === 'reorder') {
                reorderHotTopics($conn, $currentAdmin, $logger);
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

function getHotTopics($conn) {
    $sql = "SELECT id, topic, sort_order, is_active, created_at, updated_at 
            FROM hot_topics 
            ORDER BY sort_order ASC, id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $topics = $stmt->fetchAll();

    sendSuccess([
        'topics' => $topics
    ]);
}

function addHotTopic($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['topic'])) {
        sendError(400, '热点内容不能为空');
    }

    $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM hot_topics");
    $stmt->execute();
    $nextOrder = $stmt->fetch()['next_order'];

    $isActive = isset($input['is_active']) ? $input['is_active'] : 1;

    $stmt = $conn->prepare("INSERT INTO hot_topics (topic, sort_order, is_active) VALUES (?, ?, ?)");
    $stmt->execute([
        $input['topic'],
        $input['sort_order'] ?? $nextOrder,
        $isActive
    ]);

    $newId = $conn->lastInsertId();

    $logger->logAdminAction($admin['id'], 'add_hot_topic', null, 
        json_encode(['id' => $newId, 'topic' => $input['topic']]));

    sendSuccess(['message' => '热点添加成功', 'id' => $newId]);
}

function updateHotTopic($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['id'])) {
        sendError(400, '热点ID不能为空');
    }

    if (empty($input['topic'])) {
        sendError(400, '热点内容不能为空');
    }

    $stmt = $conn->prepare("UPDATE hot_topics SET topic = ?, sort_order = ?, is_active = ? WHERE id = ?");
    $stmt->execute([
        $input['topic'],
        $input['sort_order'] ?? 0,
        isset($input['is_active']) ? $input['is_active'] : 1,
        $input['id']
    ]);

    $logger->logAdminAction($admin['id'], 'update_hot_topic', $input['id'], 
        json_encode(['topic' => $input['topic']]));

    sendSuccess(['message' => '热点更新成功']);
}

function deleteHotTopic($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['id'])) {
        sendError(400, '热点ID不能为空');
    }

    $stmt = $conn->prepare("DELETE FROM hot_topics WHERE id = ?");
    $stmt->execute([$input['id']]);

    $logger->logAdminAction($admin['id'], 'delete_hot_topic', $input['id'], null);

    sendSuccess(['message' => '热点删除成功']);
}

function toggleHotTopic($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['id'])) {
        sendError(400, '热点ID不能为空');
    }

    $isActive = isset($input['is_active']) ? $input['is_active'] : 1;

    $stmt = $conn->prepare("UPDATE hot_topics SET is_active = ? WHERE id = ?");
    $stmt->execute([$isActive, $input['id']]);

    $logger->logAdminAction($admin['id'], 'toggle_hot_topic', $input['id'], 
        json_encode(['is_active' => $isActive]));

    sendSuccess(['message' => '热点状态更新成功']);
}

function reorderHotTopics($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['orders']) || !is_array($input['orders'])) {
        sendError(400, '排序数据无效');
    }

    foreach ($input['orders'] as $order) {
        if (isset($order['id']) && isset($order['sort_order'])) {
            $stmt = $conn->prepare("UPDATE hot_topics SET sort_order = ? WHERE id = ?");
            $stmt->execute([$order['sort_order'], $order['id']]);
        }
    }

    $logger->logAdminAction($admin['id'], 'reorder_hot_topics', null, 
        json_encode($input['orders']));

    sendSuccess(['message' => '排序更新成功']);
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
