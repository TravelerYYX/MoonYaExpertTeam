<?php
/**
 * 测试服务器API连接
 * 上传到服务器后访问此文件来测试API是否正常工作
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== API连接测试 ===\n\n";

// 测试1: 检查api.php是否存在
echo "1. 检查api.php文件:\n";
$apiFile = __DIR__ . '/api.php';
if (file_exists($apiFile)) {
    echo "   ✓ api.php 存在\n";
    echo "   文件大小: " . filesize($apiFile) . " 字节\n";
    echo "   最后修改: " . date('Y-m-d H:i:s', filemtime($apiFile)) . "\n";
    
    // 检查是否包含关键代码
    $content = file_get_contents($apiFile);
    $hasTokenCode = strpos($content, 'api_token') !== false;
    $hasAuthCode = strpos($content, 'Authorization') !== false;
    echo "   包含token验证代码: " . ($hasTokenCode ? "✓ 是" : "✗ 否") . "\n";
    echo "   包含Authorization处理: " . ($hasAuthCode ? "✓ 是" : "✗ 否") . "\n";
} else {
    echo "   ✗ api.php 不存在!\n";
}
echo "\n";

// 测试2: 检查config.php
echo "2. 检查config.php:\n";
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    echo "   ✓ config.php 存在\n";
    $config = include $configFile;
    echo "   数据库配置: " . $config['db_host'] . " / " . $config['db_name'] . "\n";
    echo "   API Key: " . (isset($config['api_access_key']) ? "已设置" : "未设置") . "\n";
} else {
    echo "   ✗ config.php 不存在!\n";
}
echo "\n";

// 测试3: 数据库连接测试
echo "3. 数据库连接测试:\n";
if (isset($config)) {
    try {
        $pdo = new PDO(
            "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        echo "   ✓ 数据库连接成功\n";
        
        // 检查users表
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        if ($stmt->fetch()) {
            echo "   ✓ users表存在\n";
            
            // 检查字段
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'api_token'");
            echo "   api_token字段: " . ($stmt->fetch() ? "✓ 存在" : "✗ 不存在") . "\n";
            
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'token_created_at'");
            echo "   token_created_at字段: " . ($stmt->fetch() ? "✓ 存在" : "✗ 不存在") . "\n";
        } else {
            echo "   ✗ users表不存在\n";
        }
    } catch (Exception $e) {
        echo "   ✗ 数据库连接失败: " . $e->getMessage() . "\n";
    }
}
echo "\n";

// 测试4: 模拟API请求测试
echo "4. 模拟API请求测试:\n";
echo "   测试URL: " . (isset($_SERVER['HTTPS']) ? 'https' : 'http') . "://" . $_SERVER['HTTP_HOST'] . "/api.php\n";
echo "\n";

// 测试5: 检查日志目录
echo "5. 日志目录检查:\n";
$logDir = __DIR__ . '/admin/logs';
if (is_dir($logDir)) {
    echo "   ✓ 日志目录存在\n";
    echo "   可写权限: " . (is_writable($logDir) ? "✓ 是" : "✗ 否") . "\n";
} else {
    echo "   ✗ 日志目录不存在\n";
}
echo "\n";

echo "=== 测试完成 ===\n";
echo "\n建议操作:\n";
echo "1. 如果api.php不包含token验证代码，请重新上传最新的api.php文件\n";
echo "2. 如果数据库字段不存在，请运行update_token_fields.php更新数据库\n";
echo "3. 如果日志目录不可写，请设置权限: chmod 755 admin/logs\n";
