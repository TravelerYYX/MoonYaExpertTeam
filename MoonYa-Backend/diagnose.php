<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== Moonya 诊断报告 ===\n\n";

echo "1. PHP 版本: " . PHP_VERSION . "\n";
echo "2. 服务器时间: " . date('Y-m-d H:i:s') . "\n";
echo "3. 当前文件: " . __FILE__ . "\n\n";

echo "=== 4. Authorization 头检测 ===\n";
echo "HTTP_AUTHORIZATION: " . ($_SERVER['HTTP_AUTHORIZATION'] ?? '未设置') . "\n";
echo "REDIRECT_HTTP_AUTHORIZATION: " . ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '未设置') . "\n";
echo "GET token: " . ($_GET['token'] ?? '未设置') . "\n";
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    $authFound = false;
    foreach ($headers as $k => $v) {
        if (stripos($k, 'auth') !== false) {
            echo "getallheaders[$k]: " . substr($v, 0, 80) . "\n";
            $authFound = true;
        }
    }
    if (!$authFound) echo "getallheaders: 未找到Authorization相关头\n";
} else {
    echo "getallheaders: 函数不可用\n";
}

echo "\n=== 5. SESSION 检测 ===\n";
session_start();
echo "session_id: " . session_id() . "\n";
echo "session user_id: " . ($_SESSION['user_id'] ?? '未设置') . "\n";

echo "\n=== 6. config.php 加载测试 ===\n";
try {
    $config = require __DIR__ . '/config.php';
    if (is_array($config)) {
        echo "config.php 加载成功 (类型: array)\n";
        echo "db_host: " . $config['db_host'] . "\n";
        echo "db_name: " . $config['db_name'] . "\n";
        echo "db_user: " . $config['db_user'] . "\n";
        echo "db_pass: " . (strlen($config['db_pass']) > 0 ? '***已设置(' . strlen($config['db_pass']) . '字符)' : '空') . "\n";
    } else {
        echo "config.php 返回类型错误: " . gettype($config) . " (期望array)\n";
    }
} catch (Exception $e) {
    echo "config.php 加载失败: " . $e->getMessage() . "\n";
}

echo "\n=== 7. 数据库连接测试 ===\n";
if (isset($config) && is_array($config)) {
    try {
        $pdo = new PDO(
            "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "数据库连接成功\n";
        
        echo "\n=== 8. conversations 表测试 ===\n";
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM conversations");
        $row = $stmt->fetch();
        echo "conversations 总数: " . $row['cnt'] . "\n";
        
        echo "\n=== 9. messages 表测试 ===\n";
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM messages");
        $row = $stmt->fetch();
        echo "messages 总数: " . $row['cnt'] . "\n";
        
        echo "\n=== 10. Token 验证测试 ===\n";
        $testToken = $_GET['test_token'] ?? '';
        if ($testToken) {
            $stmt = $pdo->prepare("SELECT id, username, api_token, token_created_at FROM users WHERE api_token = ?");
            $stmt->execute([$testToken]);
            $user = $stmt->fetch();
            if ($user) {
                echo "Token验证成功! 用户ID: " . $user['id'] . ", 用户名: " . $user['username'] . "\n";
            } else {
                echo "Token验证失败: 数据库中找不到该token\n";
            }
        } else {
            echo "跳过 (请在URL中添加 &test_token=YOUR_TOKEN 来测试)\n";
        }
        
    } catch (PDOException $e) {
        echo "数据库连接失败: " . $e->getMessage() . "\n";
    }
} else {
    echo "跳过 (config.php未正确加载)\n";
}

echo "\n=== 11. conversation_api.php 文件检查 ===\n";
$apiFile = __DIR__ . '/conversation_api.php';
if (file_exists($apiFile)) {
    echo "文件存在\n";
    echo "文件大小: " . filesize($apiFile) . " 字节\n";
    echo "修改时间: " . date('Y-m-d H:i:s', filemtime($apiFile)) . "\n";
    $content = file_get_contents($apiFile);
    echo "包含 REDIRECT_HTTP_AUTHORIZATION: " . (strpos($content, 'REDIRECT_HTTP_AUTHORIZATION') !== false ? '是' : '否') . "\n";
    echo "包含 _GET token: " . (strpos($content, "_GET['token']") !== false ? '是' : '否') . "\n";
    echo "使用 require (非require_once): " . (preg_match('/require\s+__DIR__/', $content) ? '是' : '否') . "\n";
} else {
    echo "文件不存在!\n";
}

echo "\n=== 诊断完成 ===\n";
?>
