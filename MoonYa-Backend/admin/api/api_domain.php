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
            getApiDomain($conn);
            break;
        case 'POST':
            if ($action === 'update') {
                updateApiDomain($conn, $currentAdmin, $logger);
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

/**
 * 读取 API 域名配置
 * 返回 main_api_domain 与 python_service_domain 两条记录的当前值
 */
function getApiDomain($conn) {
    $defaults = [
        'main_api_domain' => '',
        'python_service_domain' => '',
    ];

    $keys = array_keys($defaults);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));

    $stmt = $conn->prepare("SELECT config_key, config_value FROM api_domain_config WHERE config_key IN ($placeholders)");
    $stmt->execute($keys);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = $defaults;
    foreach ($rows as $row) {
        if (array_key_exists($row['config_key'], $data)) {
            $data[$row['config_key']] = $row['config_value'];
        }
    }

    sendSuccess($data);
}

/**
 * 更新 API 域名配置
 * 接收 JSON: {"main_api_domain": "...", "python_service_domain": "..."}
 * - 两字段均可选，但至少提供一个
 * - 空字符串允许（清空配置）
 * - 非空值必须以 http:// 或 https:// 开头
 * - 非空值若不以 / 结尾，自动追加 /
 * - 使用 PDO 事务保证两条记录原子更新
 */
function updateApiDomain($conn, $admin, $logger) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        sendError(400, '请求数据格式错误');
    }

    $allowedKeys = ['main_api_domain', 'python_service_domain'];

    // 收集本次提交的字段（只处理白名单内且实际出现的键）
    $updates = [];
    foreach ($allowedKeys as $key) {
        if (array_key_exists($key, $input)) {
            $updates[$key] = (string)$input[$key];
        }
    }

    if (empty($updates)) {
        sendError(400, '至少需要提供一个字段（main_api_domain 或 python_service_domain）');
    }

    // URL 校验 + 自动补全尾部斜杠
    foreach ($updates as $key => $value) {
        if ($value !== '') {
            if (strpos($value, 'http://') !== 0 && strpos($value, 'https://') !== 0) {
                sendError(400, '域名必须以 http:// 或 https:// 开头');
            }
            // 自动补全尾部斜杠
            if (substr($value, -1) !== '/') {
                $value .= '/';
                $updates[$key] = $value;
            }
        }
    }

    $adminId = $admin['id'];

    try {
        $conn->beginTransaction();

        $sql = "INSERT INTO api_domain_config (config_key, config_value, updated_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_by = VALUES(updated_by)";
        $stmt = $conn->prepare($sql);

        foreach ($updates as $key => $value) {
            $stmt->execute([$key, $value, $adminId]);
        }

        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        sendError(500, '保存失败: ' . $e->getMessage());
    }

    $logger->logAdminAction($adminId, 'update_api_domain', null, json_encode([
        'updates' => $updates,
    ], JSON_UNESCAPED_UNICODE));

    sendSuccess(null, '已保存，实时生效');
}

function sendSuccess($data, $message = '') {
    $payload = ['success' => true];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    if ($message !== '') {
        $payload['message'] = $message;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
