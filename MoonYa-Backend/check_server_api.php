<?php
/**
 * 服务器API诊断脚本
 * 上传到服务器后访问此页面，检查api.php是否正确配置
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<h1>服务器API诊断</h1>";
echo "<hr>";

// 1. 检查api.php是否存在
echo "<h2>1. 文件检查</h2>";
$apiFile = __DIR__ . '/api.php';
if (file_exists($apiFile)) {
    echo "<p>✅ api.php 存在</p>";
    echo "<p>文件大小: " . filesize($apiFile) . " 字节</p>";
    echo "<p>最后修改: " . date('Y-m-d H:i:s', filemtime($apiFile)) . "</p>";
    
    // 检查是否包含token验证代码
    $content = file_get_contents($apiFile);
    if (strpos($content, 'Authorization') !== false) {
        echo "<p>✅ api.php 包含Authorization处理代码</p>";
    } else {
        echo "<p>❌ api.php 缺少Authorization处理代码</p>";
    }
    
    if (strpos($content, 'api_token') !== false) {
        echo "<p>✅ api.php 包含api_token验证代码</p>";
    } else {
        echo "<p>❌ api.php 缺少api_token验证代码</p>";
    }
} else {
    echo "<p>❌ api.php 不存在</p>";
}

// 2. 检查config.php
echo "<hr><h2>2. 配置检查</h2>";
try {
    $config = require_once __DIR__ . '/config.php';
    echo "<p>✅ config.php 加载成功</p>";
    echo "<p>数据库主机: " . $config['db_host'] . "</p>";
    echo "<p>数据库名称: " . $config['db_name'] . "</p>";
    echo "<p>API访问密钥: " . substr($config['api_access_key'] ?? '未设置', 0, 20) . "...</p>";
} catch (Exception $e) {
    echo "<p>❌ config.php 错误: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 3. 检查数据库连接
echo "";
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
    echo "<p>✅ 数据库连接成功</p>";
    
    // 检查users表结构
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'api_token'");
    if ($stmt->fetch()) {
        echo "<p>✅ users表包含api_token字段</p>";
    } else {
        echo "<p>❌ users表缺少api_token字段</p>";
    }
    
    // 检查是否有用户设置了token
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE api_token IS NOT NULL");
    $count = $stmt->fetch()['count'];
    echo "<p>设置了api_token的用户数: $count</p>";
    
} catch (Exception $e) {
    echo "<p>❌ 数据库错误: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 4. 测试请求头接收
echo "<hr><h2>4. 请求头测试</h2>";
$headers = function_exists('getallheaders') ? getallheaders() : [];
echo "<p>getallheaders() 可用: " . (function_exists('getallheaders') ? '是' : '否') . "</p>";
echo "<p>当前请求头:</p>";
echo "<pre>";
print_r($headers);
echo "</pre>";

// 5. 检查日志目录
echo "<hr><h2>5. 日志检查</h2>";
$logDir = __DIR__ . '/admin/logs';
if (is_dir($logDir)) {
    echo "<p>✅ 日志目录存在</p>";
    $logs = glob($logDir . '/*.log');
    echo "<p>日志文件数: " . count($logs) . "</p>";
    foreach (array_slice($logs, -5) as $log) {
        echo "<p>- " . basename($log) . " (" . date('Y-m-d H:i:s', filemtime($log)) . ")</p>";
    }
} else {
    echo "<p>❌ 日志目录不存在</p>";
}

echo "<hr>";
echo "<h2>建议</h2>";
echo "<p>如果以上检查都通过但API仍然报错，请:</p>";
echo "<ol>";
echo "<li>确保api.php已正确上传到服务器</li>";
echo "<li>检查服务器PHP错误日志</li>";
echo "<li>在api.php开头添加调试代码，记录每次请求</li>";
echo "</ol>";
?>
