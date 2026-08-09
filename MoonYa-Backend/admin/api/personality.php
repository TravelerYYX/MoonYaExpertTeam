<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
            getPersonality($conn);
            break;
        case 'POST':
            if ($action === 'update') {
                updatePersonality($conn, $currentAdmin, $logger);
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

function getPersonality($conn) {
    $sql = "SELECT id, name, description, use_custom, created_at, updated_at 
            FROM personality 
            ORDER BY id ASC 
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $personality = $stmt->fetch();

    // 如果没有数据，创建默认数据
    if (!$personality) {
        $stmt = $conn->prepare("INSERT INTO personality (name, description, use_custom) VALUES (?, ?, ?)");
        $stmt->execute([
            'AI助手',
            'AI助手，直接迅速回答用户问题',
            1
        ]);
        
        $newId = $conn->lastInsertId();
        $personality = [
            'id' => $newId,
            'name' => 'AI助手',
            'description' => 'AI助手，直接迅速回答用户问题',
            'use_custom' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    sendSuccess([
        'personalities' => [$personality]
    ]);
}

function updatePersonality($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['name'])) {
        sendError(400, 'AI名称不能为空');
    }

    // 获取第一条记录
    $stmt = $conn->prepare("SELECT id FROM personality ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $personality = $stmt->fetch();

    if ($personality) {
        // 更新现有记录
        $stmt = $conn->prepare("UPDATE personality SET name = ?, description = ?, use_custom = ? WHERE id = ?");
        $stmt->execute([
            $input['name'],
            $input['description'] ?? null,
            isset($input['use_custom']) ? $input['use_custom'] : 1,
            $personality['id']
        ]);
        
        $logger->logAdminAction($admin['id'], 'update_personality', null, 
            json_encode(['name' => $input['name'], 'use_custom' => isset($input['use_custom']) ? $input['use_custom'] : 1]));
        
        sendSuccess(['message' => '设置保存成功']);
    } else {
        // 创建新记录
        $stmt = $conn->prepare("INSERT INTO personality (name, description, use_custom) VALUES (?, ?, ?)");
        $stmt->execute([
            $input['name'],
            $input['description'] ?? null,
            isset($input['use_custom']) ? $input['use_custom'] : 1
        ]);
        
        $logger->logAdminAction($admin['id'], 'create_personality', null, 
            json_encode(['name' => $input['name'], 'use_custom' => isset($input['use_custom']) ? $input['use_custom'] : 1]));
        
        sendSuccess(['message' => '设置保存成功']);
    }
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
