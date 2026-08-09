<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=utf-8');

echo "=== conversation_api.php 最终诊断 ===\n\n";

echo "1. 检查 conversation_api.php 是否为最新版本...\n";
$apiFile = __DIR__ . '/conversation_api.php';
$content = file_get_contents($apiFile);
echo "文件大小: " . filesize($apiFile) . " 字节\n";
echo "使用 !empty (非isset): " . (strpos($content, '!empty($_SERVER[\'HTTP_AUTHORIZATION\'])') !== false ? '是 ✅' : '否 ❌ 旧版本！') . "\n";
echo "包含 _GET token: " . (strpos($content, "_GET['token']") !== false ? '是 ✅' : '否 ❌') . "\n";
echo "使用 require (非require_once): " . (preg_match('/require\s+__DIR__/', $content) ? '是 ✅' : '否 ❌') . "\n";
echo "sendSuccess 在文件开头: " . (strpos($content, 'function sendSuccess') < 200 ? '是 ✅' : '否 ❌') . "\n";

echo "\n2. 检查 index.php 是否为最新版本...\n";
$indexFile = __DIR__ . '/index.php';
$indexContent = file_get_contents($indexFile);
echo "包含 getAuthHeaders: " . (strpos($indexContent, 'getAuthHeaders') !== false ? '是 ✅' : '否 ❌ 旧版本！') . "\n";
echo "包含 addTokenToUrl: " . (strpos($indexContent, 'addTokenToUrl') !== false ? '是 ✅' : '否 ❌ 旧版本！') . "\n";
echo "包含 getAuthToken: " . (strpos($indexContent, 'getAuthToken') !== false ? '是 ✅' : '否 ❌ 旧版本！') . "\n";

echo "\n3. Token 认证测试（使用 !empty 逻辑）...\n";
$config = require __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tokenUserId = null;
$authHeader = null;
echo "HTTP_AUTHORIZATION 值: '" . ($_SERVER['HTTP_AUTHORIZATION'] ?? '未设置') . "'\n";
echo "HTTP_AUTHORIZATION empty: " . (empty($_SERVER['HTTP_AUTHORIZATION']) ? '是(空)' : '否(有值)') . "\n";

if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    echo "→ 使用 HTTP_AUTHORIZATION\n";
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    echo "→ 使用 REDIRECT_HTTP_AUTHORIZATION\n";
} elseif (isset($_GET['token']) && $_GET['token'] !== '') {
    $authHeader = 'Bearer ' . $_GET['token'];
    echo "→ 使用 URL token 参数\n";
} else {
    echo "→ 无任何认证信息\n";
}

if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
    echo "提取到 Token: " . substr($token, 0, 20) . "...\n";
    $stmt = $pdo->prepare("SELECT id, username, api_token, token_created_at FROM users WHERE api_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if ($user) {
        $tokenUserId = $user['id'];
        echo "Token 验证成功! userId={$user['id']}, username={$user['username']} ✅\n";
    } else {
        echo "Token 在数据库中不存在 ❌\n";
    }
} else {
    echo "无法提取 Bearer Token ❌\n";
}

echo "\n=== 诊断完成 ===\n";
?>
