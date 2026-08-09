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
            getSiteSettings($conn);
            break;
        case 'POST':
            if ($action === 'update') {
                updateSiteSetting($conn, $currentAdmin, $logger);
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

function getSiteSettings($conn) {
    // 先确保 site_settings 表存在
    ensureSiteSettingsTable($conn);

    $stmt = $conn->query("SELECT id, setting_key, setting_value, setting_label, setting_type, setting_options, created_at, updated_at FROM site_settings ORDER BY id ASC");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 解析 setting_options JSON 便于前端直接渲染
    foreach ($settings as &$row) {
        $opts = $row['setting_options'] ?? null;
        $row['options'] = [];
        if (is_string($opts) && $opts !== '') {
            $decoded = json_decode($opts, true);
            if (is_array($decoded)) {
                $row['options'] = $decoded;
            }
        }
        // ★ 不做自动迁移：用户选什么就返回什么
        //   只在值为空时兜底为 'auto'（默认值）
        if ($row['setting_key'] === 'chat_search_backend') {
            if (!isset($row['setting_value']) || $row['setting_value'] === '' || $row['setting_value'] === null) {
                $row['setting_value'] = 'auto';
            }
        }
        unset($row['setting_options']);
    }
    unset($row);

    sendSuccess(['settings' => $settings]);
}

function ensureSiteSettingsTable($conn) {
    try {
        $conn->query("SELECT 1 FROM site_settings LIMIT 1");
    } catch (Exception $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS `site_settings` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '配置键',
            `setting_value` text COLLATE utf8mb4_unicode_ci COMMENT '配置值',
            `setting_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '配置显示名',
            `setting_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'text' COMMENT '类型:text/select/boolean',
            `setting_options` text COLLATE utf8mb4_unicode_ci COMMENT 'select 类型的可选项 JSON',
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通用设置表'");
    }

    // 兜底：如果 chat_search_backend 已存在但 setting_type / setting_options 为空（早期数据缺字段），补齐
    // 避免历史脏数据导致前端回退到 input
    $backendOptionsJson = json_encode([
        'auto' => '🤖 自动（推荐）— 系统智能选择',
        'moonshot' => 'Moonshot 原生 web_search（builtin_function）',
        'function_calling' => 'Function Calling + Python 搜索服务',
    ], JSON_UNESCAPED_UNICODE);

    try {
        $conn->exec("UPDATE site_settings
                     SET setting_type = 'select',
                         setting_options = " . $conn->quote($backendOptionsJson, PDO::PARAM_STR) . ",
                         setting_label = 'Chat 模式 Kimi 联网搜索后端'
                     WHERE setting_key = 'chat_search_backend'
                       AND (setting_type IS NULL OR setting_type = '' OR setting_type = 'text'
                            OR setting_options IS NULL OR setting_options = '')");
    } catch (Exception $e) {
        // ignore - 字段若不存在也不致命，前端有硬编码兜底
    }

    // ★ 修复：表为空时插入 chat_search_backend 默认记录
    //   之前只有 UPDATE（针对已存在记录补字段），表空时无记录可更新，导致前端"暂无配置项"
    try {
        $stmtInsert = $conn->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_label, setting_type, setting_options) VALUES (?, ?, ?, ?, ?)");
        $stmtInsert->execute([
            'chat_search_backend',
            'auto',
            'Chat 模式 Kimi 联网搜索后端',
            'select',
            $backendOptionsJson
        ]);
    } catch (Exception $e) {
        // ignore - INSERT IGNORE 失败不致命
    }
}

function updateSiteSetting($conn, $admin, $logger) {
    ensureSiteSettingsTable($conn);

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        sendError(400, '请求数据格式错误');
    }

    $key = trim((string)($input['setting_key'] ?? ''));
    $value = (string)($input['setting_value'] ?? '');
    $label = (string)($input['setting_label'] ?? '');
    $type = (string)($input['setting_type'] ?? 'text');
    $options = $input['setting_options'] ?? null;

    if ($key === '') {
        sendError(400, 'setting_key 不能为空');
    }

    // ★ v4.6 修订: chat_search_backend 接受三个值
    //   auto / moonshot / function_calling
    if ($key === 'chat_search_backend') {
        if (!in_array($value, ['auto', 'moonshot', 'function_calling'], true)) {
            sendError(400, '搜索后端只能是 auto / moonshot / function_calling');
        }
        // 默认值是 auto
        if ($value === '' || $value === null) {
            $value = 'auto';
        }
        // 三个选项
        $options = [
            'auto' => '🤖 自动（推荐）— 系统智能选择',
            'moonshot' => 'Moonshot 原生 web_search（builtin_function）',
            'function_calling' => 'Function Calling + Python 搜索服务',
        ];
        $type = 'select';
        $label = 'Chat 模式 Kimi 联网搜索后端';
    }

    $stmt = $conn->prepare("SELECT id FROM site_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($options !== null) {
            $optsStr = is_string($options) ? $options : json_encode($options, JSON_UNESCAPED_UNICODE);
            $stmt = $conn->prepare("UPDATE site_settings SET setting_value = ?, setting_label = ?, setting_type = ?, setting_options = ? WHERE id = ?");
            $stmt->execute([$value, $label, $type, $optsStr, $existing['id']]);
        } else {
            $stmt = $conn->prepare("UPDATE site_settings SET setting_value = ?, setting_label = ?, setting_type = ? WHERE id = ?");
            $stmt->execute([$value, $label, $type, $existing['id']]);
        }
    } else {
        $optsStr = $options !== null ? (is_string($options) ? $options : json_encode($options, JSON_UNESCAPED_UNICODE)) : null;
        $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_label, setting_type, setting_options) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$key, $value, $label, $type, $optsStr]);
    }

    $logger->logAdminAction($admin['id'], 'update_site_setting', null, json_encode([
        'setting_key' => $key,
        'setting_value' => $value
    ], JSON_UNESCAPED_UNICODE));

    sendSuccess(['message' => '设置保存成功']);
}

function sendSuccess($data) {
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
