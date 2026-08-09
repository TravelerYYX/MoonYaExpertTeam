<?php
/**
 * API错误调试脚本
 * 上传到服务器后访问此文件来查看详细的错误信息
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== API错误详细诊断 ===\n\n";

// 1. 检查PHP错误日志
echo "1. PHP错误日志:\n";
$errorLog = ini_get('error_log');
echo "   错误日志路径: " . ($errorLog ? $errorLog : "未设置") . "\n";

// 尝试找到错误日志
$possibleLogPaths = [
    __DIR__ . '/error_log',
    __DIR__ . '/../error_log',
    __DIR__ . '/admin/logs/php_error.log',
    '/var/log/apache2/error.log',
    '/var/log/httpd/error_log',
    '/var/log/nginx/error.log'
];

foreach ($possibleLogPaths as $logPath) {
    if (file_exists($logPath) && is_readable($logPath)) {
        echo "   找到日志文件: $logPath\n";
        // 显示最后10行
        $lines = file($logPath);
        $lastLines = array_slice($lines, -10);
        echo "   最后10行日志:\n";
        foreach ($lastLines as $line) {
            echo "   " . trim($line) . "\n";
        }
        break;
    }
}
echo "\n";

// 2. 检查api.php是否有语法错误
echo "2. 检查api.php语法:\n";
$apiFile = __DIR__ . '/api.php';
if (file_exists($apiFile)) {
    // 使用php -l检查语法（如果可用）
    $output = [];
    $returnVar = 0;
    exec('php -l ' . escapeshellarg($apiFile) . ' 2>&1', $output, $returnVar);
    if ($returnVar === 0) {
        echo "   ✓ 语法检查通过\n";
    } else {
        echo "   ✗ 语法错误:\n";
        foreach ($output as $line) {
            echo "   " . $line . "\n";
        }
    }
} else {
    echo "   ✗ api.php不存在\n";
}
echo "\n";

// 3. 检查config.php是否可以正常加载
echo "3. 检查config.php:\n";
try {
    $config = require_once __DIR__ . '/config.php';
    echo "   ✓ config.php加载成功\n";
} catch (Exception $e) {
    echo "   ✗ config.php加载失败: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "   ✗ config.php加载错误: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. 检查数据库连接
echo "4. 数据库连接测试:\n";
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
    } catch (PDOException $e) {
        echo "   ✗ 数据库连接失败: " . $e->getMessage() . "\n";
    }
}
echo "\n";

// 5. 检查关键文件权限
echo "5. 文件权限检查:\n";
$files = [
    'api.php',
    'config.php',
    'user_auth.php'
];
foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        $perms = fileperms($path);
        $isReadable = is_readable($path);
        echo "   $file: " . ($isReadable ? "✓ 可读" : "✗ 不可读") . " (权限: " . sprintf('%o', $perms & 0777) . ")\n";
    } else {
        echo "   $file: ✗ 不存在\n";
    }
}
echo "\n";

// 6. 检查API日志
echo "6. API日志检查:\n";
$logDir = __DIR__ . '/admin/logs';
if (is_dir($logDir)) {
    $logFiles = glob($logDir . '/api_*.log');
    if (!empty($logFiles)) {
        // 获取最新的日志文件
        usort($logFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $latestLog = $logFiles[0];
        echo "   最新日志文件: " . basename($latestLog) . "\n";
        echo "   最后修改: " . date('Y-m-d H:i:s', filemtime($latestLog)) . "\n";
        
        // 显示最后20行
        $lines = file($latestLog);
        $lastLines = array_slice($lines, -20);
        echo "   最后20行日志:\n";
        foreach ($lastLines as $line) {
            echo "   " . trim($line) . "\n";
        }
    } else {
        echo "   没有找到API日志文件\n";
    }
} else {
    echo "   日志目录不存在\n";
}
echo "\n";

// 7. 检查token_debug日志
echo "7. Token调试日志:\n";
$tokenDebugFiles = glob($logDir . '/token_debug_*.log');
if (!empty($tokenDebugFiles)) {
    usort($tokenDebugFiles, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    $latestTokenLog = $tokenDebugFiles[0];
    echo "   最新token日志: " . basename($latestTokenLog) . "\n";
    
    $lines = file($latestTokenLog);
    $lastLines = array_slice($lines, -30);
    echo "   最后30行日志:\n";
    foreach ($lastLines as $line) {
        echo "   " . trim($line) . "\n";
    }
} else {
    echo "   没有找到token调试日志\n";
}
echo "\n";

echo "=== 诊断完成 ===\n";
