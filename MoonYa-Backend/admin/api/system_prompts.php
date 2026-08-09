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
            listSystemPrompts($conn);
            break;
        case 'POST':
            if ($action === 'create') {
                createSystemPrompt($conn, $currentAdmin, $logger);
            } elseif ($action === 'update') {
                updateSystemPrompt($conn, $currentAdmin, $logger);
            } elseif ($action === 'delete') {
                deleteSystemPrompt($conn, $currentAdmin, $logger);
            } elseif ($action === 'toggle') {
                toggleSystemPrompt($conn, $currentAdmin, $logger);
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

function listSystemPrompts($conn) {
    $sql = "SELECT id, name, display_name, prompt, applicable_models, enabled, sort_order, created_at, updated_at
            FROM system_prompts
            ORDER BY sort_order ASC, id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    sendSuccess([
        'system_prompts' => $rows
    ]);
}

function createSystemPrompt($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);

    $name = isset($input['name']) ? trim($input['name']) : '';
    $displayName = isset($input['display_name']) ? trim($input['display_name']) : '';
    $prompt = isset($input['prompt']) ? $input['prompt'] : '';
    $enabled = isset($input['enabled']) ? (int)$input['enabled'] : 1;
    $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;

    if ($name === '') {
        sendError(400, '名称不能为空');
    }
    if ($prompt === '' || $prompt === null) {
        sendError(400, '提示词内容不能为空');
    }

    $applicableModels = normalizeApplicableModels($input['applicable_models'] ?? null);
    if ($applicableModels === null) {
        sendError(400, '适用模型必须为合法 JSON 数组');
    }

    // 唯一性校验
    $stmt = $conn->prepare("SELECT id FROM system_prompts WHERE name = ?");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        sendError(400, '名称已存在，请使用其他名称');
    }

    $applicableModelsJson = json_encode($applicableModels, JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("INSERT INTO system_prompts (name, display_name, prompt, applicable_models, enabled, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $name,
        $displayName,
        $prompt,
        $applicableModelsJson,
        $enabled,
        $sortOrder
    ]);

    $newId = $conn->lastInsertId();

    $stmt = $conn->prepare("SELECT id, name, display_name, prompt, applicable_models, enabled, sort_order, created_at, updated_at FROM system_prompts WHERE id = ?");
    $stmt->execute([$newId]);
    $record = $stmt->fetch();

    $logger->logAdminAction($admin['id'], 'create_system_prompt', null,
        json_encode([
            'id' => $newId,
            'name' => $name,
            'display_name' => $displayName,
            'applicable_models' => $applicableModelsJson
        ], JSON_UNESCAPED_UNICODE));

    sendSuccess(['system_prompt' => $record]);
}

function updateSystemPrompt($conn, $admin, $logger) {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        sendError(400, '无效的 id');
    }

    $stmt = $conn->prepare("SELECT id, name, display_name, prompt, applicable_models, enabled, sort_order, created_at, updated_at FROM system_prompts WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        sendError(404, '记录不存在');
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $name = isset($input['name']) ? trim($input['name']) : '';
    $displayName = isset($input['display_name']) ? trim($input['display_name']) : '';
    $prompt = isset($input['prompt']) ? $input['prompt'] : '';
    $enabled = isset($input['enabled']) ? (int)$input['enabled'] : 1;
    $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;

    if ($name === '') {
        sendError(400, '名称不能为空');
    }
    if ($prompt === '' || $prompt === null) {
        sendError(400, '提示词内容不能为空');
    }

    $applicableModels = normalizeApplicableModels($input['applicable_models'] ?? null);
    if ($applicableModels === null) {
        sendError(400, '适用模型必须为合法 JSON 数组');
    }

    // 唯一性校验（排除自身）
    $stmt = $conn->prepare("SELECT id FROM system_prompts WHERE name = ? AND id <> ?");
    $stmt->execute([$name, $id]);
    if ($stmt->fetch()) {
        sendError(400, '名称已存在，请使用其他名称');
    }

    $applicableModelsJson = json_encode($applicableModels, JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("UPDATE system_prompts SET name = ?, display_name = ?, prompt = ?, applicable_models = ?, enabled = ?, sort_order = ? WHERE id = ?");
    $stmt->execute([
        $name,
        $displayName,
        $prompt,
        $applicableModelsJson,
        $enabled,
        $sortOrder,
        $id
    ]);

    $stmt = $conn->prepare("SELECT id, name, display_name, prompt, applicable_models, enabled, sort_order, created_at, updated_at FROM system_prompts WHERE id = ?");
    $stmt->execute([$id]);
    $record = $stmt->fetch();

    $logger->logAdminAction($admin['id'], 'update_system_prompt', null,
        json_encode([
            'id' => $id,
            'before' => [
                'name' => $existing['name'],
                'display_name' => $existing['display_name'],
                'applicable_models' => $existing['applicable_models'],
                'enabled' => (int)$existing['enabled'],
                'sort_order' => (int)$existing['sort_order']
            ],
            'after' => [
                'name' => $name,
                'display_name' => $displayName,
                'applicable_models' => $applicableModelsJson,
                'enabled' => $enabled,
                'sort_order' => $sortOrder
            ]
        ], JSON_UNESCAPED_UNICODE));

    sendSuccess(['system_prompt' => $record]);
}

function deleteSystemPrompt($conn, $admin, $logger) {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        sendError(400, '无效的 id');
    }

    $stmt = $conn->prepare("SELECT id, name FROM system_prompts WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        sendError(404, '记录不存在');
    }

    $protected = ['normal', 'programming', 'agent', 'computer_user'];
    if (in_array($existing['name'], $protected, true)) {
        sendError(400, '内置名称不可删除');
    }

    $stmt = $conn->prepare("DELETE FROM system_prompts WHERE id = ?");
    $stmt->execute([$id]);

    $logger->logAdminAction($admin['id'], 'delete_system_prompt', null,
        json_encode(['id' => $id, 'name' => $existing['name']], JSON_UNESCAPED_UNICODE));

    sendSuccess(['message' => '删除成功', 'id' => $id]);
}

function toggleSystemPrompt($conn, $admin, $logger) {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        sendError(400, '无效的 id');
    }

    $stmt = $conn->prepare("SELECT id, enabled FROM system_prompts WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        sendError(404, '记录不存在');
    }

    $newEnabled = (int)$existing['enabled'] === 1 ? 0 : 1;

    $stmt = $conn->prepare("UPDATE system_prompts SET enabled = ? WHERE id = ?");
    $stmt->execute([$newEnabled, $id]);

    $logger->logAdminAction($admin['id'], 'toggle_system_prompt', null,
        json_encode([
            'id' => $id,
            'enabled_before' => (int)$existing['enabled'],
            'enabled_after' => $newEnabled
        ], JSON_UNESCAPED_UNICODE));

    sendSuccess([
        'id' => $id,
        'enabled' => $newEnabled
    ]);
}

/**
 * 将入参中的 applicable_models 归一化为 PHP 数组。
 * 接受 JSON 字符串或已解码数组；非法输入返回 null。
 */
function normalizeApplicableModels($value) {
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
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
