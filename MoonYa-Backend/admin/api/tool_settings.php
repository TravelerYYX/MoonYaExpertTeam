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
            getToolSettings($conn);
            break;
        case 'POST':
            if ($action === 'update') {
                updateToolSetting($conn, $currentAdmin, $logger);
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

function getToolSettings($conn) {
    $sql = "SELECT id, tool_name, tool_display_name, system_prompt, created_at, updated_at 
            FROM tool_settings 
            ORDER BY id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $settings = $stmt->fetchAll();

    sendSuccess([
        'tool_settings' => $settings
    ]);
}

function updateToolSetting($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['tool_name']) || empty($input['system_prompt'])) {
        sendError(400, '工具名称和系统提示词不能为空');
    }

    // 检查工具是否存在
    $stmt = $conn->prepare("SELECT id FROM tool_settings WHERE tool_name = ?");
    $stmt->execute([$input['tool_name']]);
    $tool = $stmt->fetch();

    if ($tool) {
        // 更新现有工具设置
        $stmt = $conn->prepare("UPDATE tool_settings SET system_prompt = ? WHERE id = ?");
        $stmt->execute([
            $input['system_prompt'],
            $tool['id']
        ]);
        
        $logger->logAdminAction($admin['id'], 'update_tool_setting', null, 
            json_encode(['tool_name' => $input['tool_name']]));
        
        sendSuccess(['message' => '设置保存成功']);
    } else {
        sendError(404, '工具不存在');
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
?>
