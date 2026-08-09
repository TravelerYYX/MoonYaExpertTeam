<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

try {
    $config = require_once __DIR__ . '/config.php';
    
    if (!isset($_SESSION['user_id'])) {
        sendError(401, '请先登录');
    }
    
    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    
    if ($method === 'GET' && $action === 'get_info') {
        handleGetInfo($pdo);
    } elseif ($method === 'GET' && $action === 'check_password_status') {
        handleCheckPasswordStatus($pdo);
    } elseif ($method === 'POST') {
        if ($action === 'update_real_name') {
            handleUpdateRealName($pdo);
        } elseif ($action === 'update_username') {
            handleUpdateUsername($pdo);
        } elseif ($action === 'update_password') {
            handleUpdatePassword($pdo);
        } elseif ($action === 'set_password') {
            handleSetPassword($pdo);
        } elseif ($action === 'upload_avatar') {
            handleUploadAvatar($pdo);
        } elseif ($action === 'update_gender') {
            handleUpdateGender($pdo);
        }
    }
    
    sendError(400, '无效的请求');
} catch (Exception $e) {
    sendError(500, '服务器错误: ' . $e->getMessage());
}

function handleGetInfo($pdo) {
    global $config;
    try {
        $stmt = $pdo->prepare("SELECT id, username, email, real_name, gender, avatar FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            sendError(404, '用户不存在');
        }

        $defaultAvatar = trim((string)($config['profile']['default_avatar_url'] ?? ''));
        if ($defaultAvatar === '') throw new RuntimeException('Missing required configuration: profile.default_avatar_url');
        $avatarUrl = !empty($user['avatar']) ? $user['avatar'] : $defaultAvatar;

        sendSuccess([
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'real_name' => $user['real_name'],
            'gender' => $user['gender'],
            'avatar' => $avatarUrl
        ]);
    } catch (PDOException $e) {
        sendError(500, '获取信息失败: ' . $e->getMessage());
    }
}

function handleUpdateRealName($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    $realName = trim($input['real_name'] ?? '');

    if (empty($realName)) {
        sendError(400, '请输入姓名');
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET real_name = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$realName, $_SESSION['user_id']]);

        sendSuccess(['message' => '姓名修改成功']);
    } catch (PDOException $e) {
        sendError(500, '修改失败: ' . $e->getMessage());
    }
}

function handleUpdateGender($pdo) {
    // 1. 校验登录态
    if (!isset($_SESSION['user_id'])) {
        sendError(401, '请先登录');
    }

    // 2. 解析 JSON 请求体
    $input = json_decode(file_get_contents('php://input'), true);
    $gender = isset($input['gender']) ? trim((string)$input['gender']) : '';

    // 3. 严格白名单校验
    $allowed = ['male', 'female', 'private'];
    if (!in_array($gender, $allowed, true)) {
        sendError(400, '性别参数不合法');
    }

    // 4. 预处理 SQL 更新
    try {
        $stmt = $pdo->prepare("UPDATE users SET gender = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$gender, $_SESSION['user_id']]);

        sendSuccess(['message' => '性别修改成功', 'gender' => $gender]);
    } catch (PDOException $e) {
        sendError(500, '修改失败: ' . $e->getMessage());
    }
}

function handleUpdateUsername($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    
    if (empty($username)) {
        sendError(400, '请输入账号');
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $_SESSION['user_id']]);
        if ($stmt->fetch()) {
            sendError(400, '该账号已被使用');
        }
        
        $stmt = $pdo->prepare("UPDATE users SET username = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$username, $_SESSION['user_id']]);
        
        $_SESSION['username'] = $username;
        
        sendSuccess(['message' => '账号修改成功']);
    } catch (PDOException $e) {
        sendError(500, '修改失败: ' . $e->getMessage());
    }
}

function handleUpdatePassword($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    $password = $input['password'] ?? '';
    
    if (empty($password)) {
        sendError(400, '请输入密码');
    }
    
    if (strlen($password) < 8) {
        sendError(400, '密码长度至少8位');
    }
    
    try {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
        
        sendSuccess(['message' => '密码修改成功']);
    } catch (PDOException $e) {
        sendError(500, '修改失败: ' . $e->getMessage());
    }
}

function handleSetPassword($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    $password = $input['password'] ?? '';
    
    if (empty($password)) {
        sendError(400, '请输入密码');
    }
    
    if (strlen($password) < 8) {
        sendError(400, '密码长度至少8位');
    }
    
    try {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if ($user && !empty($user['password'])) {
            sendError(400, '您已设置密码，如需修改请使用修改密码功能');
        }
        
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
        
        sendSuccess(['message' => '密码设置成功']);
    } catch (PDOException $e) {
        sendError(500, '设置失败: ' . $e->getMessage());
    }
}

function handleCheckPasswordStatus($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendError(404, '用户不存在');
        }
        
        sendSuccess([
            'has_password' => !empty($user['password'])
        ]);
    } catch (PDOException $e) {
        sendError(500, '查询失败: ' . $e->getMessage());
    }
}

function handleUploadAvatar($pdo) {
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        sendError(400, '请选择要上传的图片');
    }
    
    $file = $_FILES['avatar'];
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) {
        sendError(400, '只支持 JPG、PNG、GIF、WEBP 格式');
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        sendError(400, '图片大小不能超过5MB');
    }
    
    $uploadDir = __DIR__ . '/uploads/avatars/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = uniqid('avatar_', true) . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        sendError(500, '文件上传失败');
    }
    
    $avatarUrl = 'uploads/avatars/' . $fileName;
    
    try {
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $oldAvatar = $stmt->fetch()['avatar'] ?? '';
        
        $stmt = $pdo->prepare("UPDATE users SET avatar = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$avatarUrl, $_SESSION['user_id']]);
        
        if ($oldAvatar && file_exists(__DIR__ . '/' . $oldAvatar)) {
            unlink(__DIR__ . '/' . $oldAvatar);
        }
        
        sendSuccess(['message' => '头像上传成功', 'avatar_url' => '../' . $avatarUrl]);
    } catch (PDOException $e) {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        sendError(500, '上传失败: ' . $e->getMessage());
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
