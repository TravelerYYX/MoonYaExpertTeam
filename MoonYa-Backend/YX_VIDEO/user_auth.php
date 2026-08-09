<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('session.gc_maxlifetime', 1296000);
ini_set('session.cookie_lifetime', 1296000);

session_start();

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1296000)) {
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();

try {
    $config = require_once __DIR__ . '/config.php';

    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";port=" . $config['db_port'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
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

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if ($action === 'register') {
            handleRegister($pdo, $input);
        } elseif ($action === 'login') {
            handleLogin($pdo, $input);
        } elseif ($action === 'send_code') {
            handleSendCode($pdo, $input);
        } elseif ($action === 'login_by_code') {
            handleLoginByCode($pdo, $input);
        }
    } elseif ($method === 'GET') {
        if ($action === 'check_session') {
            handleCheckSession($pdo);
        } elseif ($action === 'logout') {
            handleLogout();
        }
    }

    sendError(400, '无效的请求');
} catch (Throwable $e) {
    sendError(500, '服务器错误: ' . $e->getMessage());
}

function getQQAvatar($email) {
    global $config;
    if (preg_match('/^(\d{5,11})@qq\.com$/i', $email, $m)) {
        $template = trim((string)($config['yx_video']['account_avatar_url_template'] ?? ''));
        if ($template === '') throw new RuntimeException('Missing required configuration: yx_video.account_avatar_url_template');
        return str_replace('{account}', rawurlencode($m[1]), $template);
    }
    return null;
}

function resolveAvatar($avatar, $email) {
    if (!empty($avatar)) {
        if (strpos($avatar, 'http') === 0) {
            return $avatar;
        }
        return '/' . ltrim($avatar, '/');
    }
    $qqAvatar = getQQAvatar($email);
    if ($qqAvatar) {
        return $qqAvatar;
    }
    return null;
}

function handleRegister($pdo, $input) {
    $email = trim($input['email'] ?? '');
    $code = trim($input['code'] ?? '');

    if (empty($email) || empty($code)) {
        sendError(400, '请填写完整信息');
    }

    $savedCode = $_SESSION['verification_code'] ?? '';
    $savedEmail = $_SESSION['verification_email'] ?? '';
    $codeTime = $_SESSION['verification_time'] ?? 0;

    if (strtolower($email) !== strtolower($savedEmail)) {
        sendError(400, '邮箱不匹配');
    }

    if ($code !== $savedCode) {
        sendError(400, '验证码错误');
    }

    if (time() - $codeTime > 300) {
        sendError(400, '验证码已过期');
    }

    try {
        $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = ? AND status != 'deleted'");
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch();
        if ($existingUser) {
            sendError(400, '该邮箱已被注册');
        }
        
        $username = preg_replace('/@.*/', '', $email);
        $username = preg_replace('/[^a-zA-Z0-9]/', '', $username);
        if (empty($username)) {
            $username = 'user_' . substr(md5($email), 0, 8);
        }
        
        $originalUsername = $username;
        $counter = 1;
        while (true) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND status != 'deleted'");
            $stmt->execute([$username]);
            if (!$stmt->fetch()) break;
            $username = $originalUsername . $counter;
            $counter++;
        }

        $stmt = $pdo->prepare("INSERT INTO users (username, email, real_name, password, status, created_at, updated_at) VALUES (?, ?, ?, NULL, 'active', NOW(), NOW())");
        $stmt->execute([$username, $email, $username]);

        $userId = $pdo->lastInsertId();

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $email;
        $_SESSION['last_activity'] = time();

        setcookie(session_name(), session_id(), time() + 1296000, '/');

        unset($_SESSION['verification_code']);
        unset($_SESSION['verification_email']);
        unset($_SESSION['verification_time']);
        unset($_SESSION['yx_video_logged_out']);

        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("UPDATE users SET api_token = ?, token_created_at = NOW() WHERE id = ?");
        $stmt->execute([$token, $userId]);

        sendSuccess([
            'message' => '注册成功',
            'token' => $token,
            'expires_in' => 1296000,
            'user' => [
                'id' => $userId,
                'username' => $username,
                'email' => $email,
                'real_name' => $username,
                'avatar' => resolveAvatar(null, $email)
            ]
        ]);
    } catch (PDOException $e) {
        sendError(500, '注册失败: ' . $e->getMessage());
    }
}

function handleLogin($pdo, $input) {
    $username = trim($input['account'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        sendError(400, '请输入账号和密码');
    }

    try {
        $stmt = $pdo->prepare("SELECT id, username, email, real_name, avatar, password, status FROM users WHERE (username = ? OR email = ?) AND status != 'deleted'");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if (!$user) {
            sendError(401, '账号或密码错误');
        }
        
        if (empty($user['password'])) {
            sendError(400, '该账号未设置密码，请使用邮箱验证码登录，或在设置中设置密码');
        }

        if (!password_verify($password, $user['password'])) {
            sendError(401, '账号或密码错误');
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['status'] = $user['status'];
        $_SESSION['last_activity'] = time();

        setcookie(session_name(), session_id(), time() + 1296000, '/');

        unset($_SESSION['yx_video_logged_out']);

        $token = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare("UPDATE users SET api_token = ?, token_created_at = NOW() WHERE id = ?");
        $stmt->execute([$token, $user['id']]);

        sendSuccess([
            'message' => '登录成功',
            'token' => $token,
            'expires_in' => 1296000,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'real_name' => $user['real_name'],
                'avatar' => resolveAvatar($user['avatar'], $user['email'])
            ]
        ]);
    } catch (PDOException $e) {
        sendError(500, '登录失败: ' . $e->getMessage());
    }
}

function handleLoginByCode($pdo, $input) {
    $email = trim($input['email'] ?? '');
    $code = trim($input['code'] ?? '');
    
    if (empty($email) || empty($code)) {
        sendError(400, '请输入邮箱和验证码');
    }
    
    $savedCode = $_SESSION['verification_code'] ?? '';
    $savedEmail = $_SESSION['verification_email'] ?? '';
    $codeTime = $_SESSION['verification_time'] ?? 0;
    
    if (strtolower($email) !== strtolower($savedEmail)) {
        sendError(400, '邮箱不匹配');
    }
    
    if ($code !== $savedCode) {
        sendError(400, '验证码错误');
    }
    
    if (time() - $codeTime > 300) {
        sendError(400, '验证码已过期');
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, username, email, real_name, avatar, status FROM users WHERE email = ? AND status != 'deleted'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendError(401, '该邮箱未注册，请先注册');
        }
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['status'] = $user['status'];
        $_SESSION['last_activity'] = time();
        
        setcookie(session_name(), session_id(), time() + 1296000, '/');
        
        unset($_SESSION['verification_code']);
        unset($_SESSION['verification_email']);
        unset($_SESSION['verification_time']);
        unset($_SESSION['yx_video_logged_out']);
        
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("UPDATE users SET api_token = ?, token_created_at = NOW() WHERE id = ?");
        $stmt->execute([$token, $user['id']]);
        
        sendSuccess([
            'message' => '登录成功',
            'token' => $token,
            'expires_in' => 1296000,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'real_name' => $user['real_name'],
                'avatar' => resolveAvatar($user['avatar'], $user['email'])
            ]
        ]);
    } catch (PDOException $e) {
        sendError(500, '登录失败: ' . $e->getMessage());
    }
}

function handleSendCode($pdo, $input) {
    $email = trim($input['email'] ?? '');
    $purpose = trim($input['purpose'] ?? 'register');

    if (empty($email)) {
        sendError(400, '请输入邮箱');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendError(400, '请输入有效的邮箱地址');
    }
    
    if ($purpose === 'register') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND status != 'deleted'");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            sendError(400, '该邮箱已被注册，请直接登录');
        }
    } elseif ($purpose === 'login') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND status != 'deleted'");
        $stmt->execute([$email]);
        if (!$stmt->fetch()) {
            sendError(400, '该邮箱未注册，请先注册');
        }
    }

    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    $_SESSION['verification_code'] = $code;
    $_SESSION['verification_email'] = $email;
    $_SESSION['verification_time'] = time();

    try {
        global $config;
        require_once __DIR__ . '/SimpleSMTP.php';
        
        if (!function_exists('curl_init')) {
            throw new Exception('服务器缺少cURL扩展，请联系管理员安装php-curl');
        }
        
        $smtp = new SimpleSMTP(
            $config['smtp_host'],
            $config['smtp_port'],
            $config['smtp_user'],
            $config['smtp_pass'],
            $config['smtp_user'],
            $config['email_from_name']
        );
        
        $subject = '雅泫视频 - 验证码';
        $message = "您好！\n\n您的验证码是：{$code}\n\n请在5分钟内使用此验证码。\n\n此邮件由系统自动发送，请勿回复。";
        
        $smtp->send($email, $subject, $message);
        
        sendSuccess([
            'message' => '验证码已发送，请查看您的邮箱'
        ]);
    } catch (Throwable $e) {
        sendError(500, '验证码发送失败: ' . $e->getMessage());
    }
}

function handleCheckSession($pdo) {
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT id, username, email, real_name, avatar FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user) {
            sendSuccess([
                'logged_in' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'real_name' => $user['real_name'],
                    'avatar' => resolveAvatar($user['avatar'], $user['email'])
                ]
            ]);
        } else {
            sendSuccess(['logged_in' => false]);
        }
    } else {
        sendSuccess(['logged_in' => false]);
    }
}

function handleLogout() {
    $_SESSION['yx_video_logged_out'] = true;
    unset($_SESSION['user_id']);
    unset($_SESSION['username']);
    unset($_SESSION['email']);
    unset($_SESSION['status']);
    sendSuccess(['message' => '已退出登录']);
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
