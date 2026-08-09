<?php
/**
 * API Token测试脚本
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

// 记录请求信息
$logFile = __DIR__ . '/admin/logs/test_api_token_' . date('Y-m-d') . '.log';
file_put_contents($logFile, "\n=== " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

// 获取请求头
$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
}

file_put_contents($logFile, "Headers: " . json_encode($headers, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

// 检查Authorization
$authHeader = null;
if (isset($headers['Authorization'])) {
    $authHeader = $headers['Authorization'];
} elseif (isset($headers['authorization'])) {
    $authHeader = $headers['authorization'];
} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
}

file_put_contents($logFile, "Auth Header: " . ($authHeader ? substr($authHeader, 0, 50) : 'null') . "\n", FILE_APPEND);

// 提取token
$token = null;
if ($authHeader) {
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
    } else {
        $token = $authHeader;
    }
}

file_put_contents($logFile, "Token: " . ($token ? substr($token, 0, 30) . '...' : 'null') . "\n", FILE_APPEND);

// 如果没有token，返回错误
if (!$token) {
    echo json_encode([
        'success' => false,
        'error' => '未提供token',
        'debug' => [
            'headers' => $headers,
            'server_auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null
        ]
    ]);
    exit;
}

// 验证token
try {
    $config = require_once __DIR__ . '/config.php';
    
    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    $stmt = $pdo->prepare("SELECT id, username, email, api_token, token_created_at FROM users WHERE api_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        file_put_contents($logFile, "Token验证成功: " . $user['username'] . "\n", FILE_APPEND);
        echo json_encode([
            'success' => true,
            'message' => 'Token验证成功',
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'token_created_at' => $user['token_created_at']
            ]
        ]);
    } else {
        file_put_contents($logFile, "Token验证失败: 找不到用户\n", FILE_APPEND);
        echo json_encode([
            'success' => false,
            'error' => 'Token无效',
            'debug' => ['token_prefix' => substr($token, 0, 20)]
        ]);
    }
    
} catch (Exception $e) {
    file_put_contents($logFile, "错误: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode([
        'success' => false,
        'error' => '系统错误: ' . $e->getMessage()
    ]);
}
