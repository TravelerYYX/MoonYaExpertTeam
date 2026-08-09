<?php
/**
 * 服务器诊断脚本
 * 上传到服务器后访问此文件来诊断问题
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== AI系统服务器诊断 ===\n\n";

// 1. 检查PHP版本
echo "1. PHP版本: " . PHP_VERSION . "\n\n";

// 2. 检查关键文件是否存在
$files = [
    'api.php',
    'config.php',
    'user_auth.php',
    'Vague_answer.php'
];

echo "2. 关键文件检查:\n";
foreach ($files as $file) {
    $exists = file_exists(__DIR__ . '/' . $file);
    echo "   $file: " . ($exists ? "✓ 存在" : "✗ 不存在") . "\n";
}
echo "\n";

// 3. 检查api.php是否包含关键代码
echo "3. api.php 关键代码检查:\n";
if (file_exists(__DIR__ . '/api.php')) {
    $apiContent = file_get_contents(__DIR__ . '/api.php');
    
    $checks = [
        'api_token' => '数据库token字段',
        'Authorization' => 'Authorization头处理',
        'getallheaders' => '获取请求头',
        'api_access_key' => 'API Key验证',
        'deepThinkingWithSearch' => '专家模式函数',
        'logDebug' => '调试日志函数'
    ];
    
    foreach ($checks as $keyword => $desc) {
        $found = strpos($apiContent, $keyword) !== false;
        echo "   $desc ($keyword): " . ($found ? "✓ 存在" : "✗ 不存在") . "\n";
    }
} else {
    echo "   api.php 不存在!\n";
}
echo "\n";

// 4. 检查config.php
echo "4. config.php 检查:\n";
if (file_exists(__DIR__ . '/config.php')) {
    $config = include __DIR__ . '/config.php';
    echo "   配置加载: ✓ 成功\n";
    echo "   数据库主机: " . ($config['db_host'] ?? '未设置') . "\n";
    echo "   数据库名: " . ($config['db_name'] ?? '未设置') . "\n";
    echo "   API Key: " . (isset($config['api_access_key']) ? '已设置 (' . substr($config['api_access_key'], 0, 10) . '...)' : '未设置') . "\n";
} else {
    echo "   config.php 不存在!\n";
}
echo "\n";

// 5. 检查数据库连接
echo "5. 数据库连接检查:\n";
try {
    if (isset($config)) {
        $pdo = new PDO(
            "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        echo "   数据库连接: ✓ 成功\n";
        
        // 检查users表结构
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'api_token'");
        $hasTokenField = $stmt->fetch();
        echo "   api_token字段: " . ($hasTokenField ? "✓ 存在" : "✗ 不存在") . "\n";
        
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'token_created_at'");
        $hasTokenTimeField = $stmt->fetch();
        echo "   token_created_at字段: " . ($hasTokenTimeField ? "✓ 存在" : "✗ 不存在") . "\n";
        
        // 检查是否有用户设置了token
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE api_token IS NOT NULL");
        $tokenCount = $stmt->fetch()['count'];
        echo "   设置了token的用户数: $tokenCount\n";
    } else {
        echo "   无法加载配置，跳过数据库检查\n";
    }
} catch (Exception $e) {
    echo "   数据库连接: ✗ 失败 - " . $e->getMessage() . "\n";
}
echo "\n";

// 6. 检查日志目录
echo "6. 日志目录检查:\n";
$logDir = __DIR__ . '/admin/logs';
if (is_dir($logDir)) {
    echo "   日志目录: ✓ 存在\n";
    echo "   可写权限: " . (is_writable($logDir) ? "✓ 可写" : "✗ 不可写") . "\n";
} else {
    echo "   日志目录: ✗ 不存在 ($logDir)\n";
}
echo "\n";

// 7. 检查请求头获取方式
echo "7. 请求头获取检查:\n";
echo "   getallheaders()函数: " . (function_exists('getallheaders') ? "✓ 可用" : "✗ 不可用") . "\n";
echo "   \$_SERVER[HTTP_AUTHORIZATION]: " . (isset($_SERVER['HTTP_AUTHORIZATION']) ? "✓ 当前请求有值" : "○ 当前请求无值") . "\n";
echo "\n";

// 8. 测试当前请求的Authorization头
echo "8. 当前请求头测试:\n";
$allHeaders = function_exists('getallheaders') ? getallheaders() : [];
echo "   所有请求头: " . json_encode($allHeaders, JSON_UNESCAPED_UNICODE) . "\n";

// 9. 检查是否有常见的服务器配置问题
echo "\n9. 常见配置问题检查:\n";
echo "   mod_rewrite: " . (in_array('mod_rewrite', apache_get_modules()) ? "✓ 已启用" : "○ 未知/未启用") . "\n";
echo "   output_buffering: " . ini_get('output_buffering') . "\n";

echo "\n=== 诊断完成 ===\n";
echo "\n如果以上检查中有任何 ✗ 标记，请修复对应的问题。\n";
echo "最常见的问题是：\n";
echo "1. 服务器上的api.php没有更新到最新版本\n";
echo "2. 数据库表结构没有更新（缺少api_token字段）\n";
echo "3. 日志目录没有写入权限\n";
