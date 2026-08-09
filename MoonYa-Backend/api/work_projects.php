<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
    require_once __DIR__ . '/../admin/config.php';
    require_once __DIR__ . '/../admin/Database.php';
    require_once __DIR__ . '/../admin/Auth.php';
    require_once __DIR__ . '/../admin/Logger.php';

    $config = require __DIR__ . '/../admin/config.php';

    $db = new Database($config);
    $conn = $db->getConnection();

    ensureTables($conn);

    $logger = new Logger($config, $conn);
    $auth = new Auth($conn, $config, $logger);

    $headers = getallheaders();
    $token = null;
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
    } elseif (isset($headers['authorization'])) {
        $token = str_replace('Bearer ', '', $headers['authorization']);
    }

    // 鉴权降级为可选：无 token 或解析失败时 user_id=0（匿名）
    $userId = 0;
    $currentAdmin = $auth->authenticate($token);
    if ($currentAdmin && isset($currentAdmin['id'])) {
        $userId = (int)$currentAdmin['id'];
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                listProjects($conn, $userId);
            } elseif ($action === 'get_texts') {
                getTexts($conn);
            } else {
                sendError(400, '无效的操作');
            }
            break;
        case 'POST':
            if ($action === 'create') {
                createProject($conn, $userId);
            } elseif ($action === 'delete') {
                deleteProject($conn, $userId);
            } elseif ($action === 'save_current') {
                saveCurrent($conn, $userId);
            } elseif ($action === 'validate_path') {
                validatePath($config);
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
 * 表不存在时创建（与 sql/数据库.sql 中的建表语句一致）。
 */
function ensureTables($conn) {
    $conn->exec("CREATE TABLE IF NOT EXISTS `work_projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL DEFAULT 0 COMMENT '用户ID，0表示匿名',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '项目显示名',
  `path` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '项目文件夹绝对路径',
  `last_used_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_path` (`user_id`, `path`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Work 模式用户绑定的项目文件夹'");

    $conn->exec("CREATE TABLE IF NOT EXISTS `work_project_texts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `text_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文案键',
  `text_value` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文案值，支持 {name} {path} {keyword} 占位符',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_text_key` (`text_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Work 模式 UI 文案表'");

    // 幂等插入默认文案：已存在的键不覆盖（保留用户自定义），缺失的键自动补齐
    // 与 sql/数据库.sql 中的默认数据保持一致
    $conn->exec("INSERT IGNORE INTO `work_project_texts` (`text_key`, `text_value`) VALUES
('btn_enter_project_default', '进入项目工作'),
('web_mode_warning_title', '需要桌面启动器'),
('web_mode_warning_body', '该功能需要配合 MoonYa 桌面启动器使用，网页端无法访问本地文件系统。请下载并启动桌面启动器后重试。'),
('btn_acknowledge', '我知道了'),
('modal_create_title', '创建项目'),
('modal_list_title', '选择现有文件夹'),
('placeholder_project_name', '这里写项目名称'),
('placeholder_project_path', '直接输入或粘贴项目的绝对路径'),
('placeholder_search', '搜索文件夹名称...'),
('btn_select_folder', '选择文件夹'),
('btn_confirm', '确认'),
('btn_save', '保存'),
('btn_cancel', '取消'),
('btn_delete', '删除'),
('btn_select', '选择'),
('btn_pick_folder_short', '…'),
('btn_browse', '浏览'),
('btn_create_new', '新建项目'),
('toast_project_switched', '已切换到项目: {name}'),
('toast_project_created', '项目 {name} 创建成功'),
('toast_project_deleted', '项目 {name} 已删除'),
('error_path_not_writable', '路径 {path} 不可写或不存在'),
('error_launcher_unreachable', '无法连接桌面启动器，请确认启动器已运行'),
('error_name_or_path_empty', '请填写项目名称并选择路径'),
('error_name_exists', '项目名已存在，请更换'),
('empty_no_folders', '暂无文件夹'),
('empty_no_folders_hint', '点击下方按钮新建第一个项目'),
('empty_search_no_match', '未找到匹配 \"{keyword}\" 的文件夹'),
('loading_fetching', '正在加载文件夹列表...'),
('loading_creating', '正在创建项目...'),
('loading_validating', '正在验证路径...'),
('label_existing_folders', '已绑定的项目文件夹'),
('label_project_name', '项目名称'),
('label_project_path', '项目路径'),
('label_no_folder', '不使用文件夹'),
('toast_no_folder', '已清除项目选择'),
('label_path_selected', '已选择: {name}'),
('confirm_discard_input', '有未保存的内容，确认放弃？'),
('btn_close', '关闭')");

    // Computer User 模式相关 UI 文案（幂等：已存在的键不覆盖）
    $conn->exec("INSERT IGNORE INTO `work_project_texts` (`text_key`, `text_value`) VALUES
('btn_computer_user', 'Computer User'),
('cu_step_screenshot', '截图观察屏幕'),
('cu_step_click', '鼠标点击'),
('cu_step_move', '鼠标移动'),
('cu_step_scroll', '鼠标滚动'),
('cu_step_type', '键盘输入'),
('cu_step_key', '按键'),
('cu_step_complete', '任务完成'),
('cu_complete_success', '任务已完成'),
('cu_complete_limited', '已达最大轮次，自动停止'),
('cu_lightbox_prev', '上一张'),
('cu_lightbox_next', '下一张'),
('cu_lightbox_close', '关闭'),
('web_mode_warning_cu', 'Computer User 模式需要桌面启动器支持'),
('cu_step_observe', '观察 UI 树'),
('cu_step_find', '定位元素'),
('cu_action_click_element', '点击元素'),
('cu_action_set_text', '输入文本'),
('cu_action_get_text', '读取文本')");

    // Computer User 模式系统提示词已移至数据库 SQL 文件（sql/数据库.sql），
    // 不再在代码中硬编码。管理员可在后台 → 系统提示词 → computer_user 中编辑。
    // 首次安装数据库时通过 sql/数据库.sql 中的 INSERT 创建默认记录。
}

function listProjects($conn, $userId) {
    $stmt = $conn->prepare("SELECT id, name, path, last_used_at, created_at
                            FROM work_projects
                            WHERE user_id = ?
                            ORDER BY last_used_at DESC, id DESC");
    $stmt->execute([$userId]);
    $projects = $stmt->fetchAll();

    sendSuccess(['projects' => $projects]);
}

function getTexts($conn) {
    $stmt = $conn->query("SELECT text_key, text_value FROM work_project_texts");
    $rows = $stmt->fetchAll();

    $texts = [];
    foreach ($rows as $row) {
        $texts[$row['text_key']] = $row['text_value'];
    }

    sendSuccess(['texts' => $texts]);
}

function createProject($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);

    $name = isset($input['name']) ? trim($input['name']) : '';
    $path = isset($input['path']) ? trim($input['path']) : '';

    if ($name === '' || $path === '') {
        sendError(400, '请填写项目名称并选择路径');
    }

    try {
        $stmt = $conn->prepare("INSERT INTO work_projects (user_id, name, path) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $name, $path]);
        $newId = $conn->lastInsertId();
    } catch (PDOException $e) {
        // 23000 = 唯一约束冲突（user_id, path 重复）
        if ($e->getCode() == '23000') {
            sendError(400, '项目已存在');
        }
        throw $e;
    }

    sendSuccess([
        'id' => (int)$newId,
        'name' => $name,
        'path' => $path
    ]);
}

function deleteProject($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : 0;

    if ($id <= 0) {
        sendError(400, '无效的 id');
    }

    $stmt = $conn->prepare("DELETE FROM work_projects WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);

    sendSuccess([]);
}

function saveCurrent($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : 0;

    if ($id <= 0) {
        sendError(400, '无效的 id');
    }

    $stmt = $conn->prepare("UPDATE work_projects SET last_used_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);

    sendSuccess([]);
}

function validatePath($config) {
    $input = json_decode(file_get_contents('php://input'), true);
    $path = isset($input['path']) ? $input['path'] : '';

    $launcherApiUrl = $config['launcher_api_url'] ?? '';
    $apiBody = json_encode(['action' => 'validate_path', 'path' => $path]);

    $ch = curl_init($launcherApiUrl . '/file-op');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $apiBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error !== '') {
        // 兜底文案：前端会优先使用 DB 中的文案，此处为最后兜底
        sendError(502, '无法连接桌面启动器，请确认启动器已运行');
    }

    // 原样返回启动器 JSON
    echo $response;
    exit;
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
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
