<?php
/**
 * 检查api.php最新版本
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== 检查api.php版本 ===\n\n";

$apiFile = __DIR__ . '/api.php';
if (!file_exists($apiFile)) {
    echo "✗ api.php不存在\n";
    exit;
}

$content = file_get_contents($apiFile);

echo "文件大小: " . filesize($apiFile) . " 字节\n";
echo "最后修改: " . date('Y-m-d H:i:s', filemtime($apiFile)) . "\n\n";

// 检查关键修复点
$checks = [
    '@mkdir($logDir' => '日志目录自动创建',
    '用户ID为空，请重新登录' => 'userId空值检查',
    '数据库连接失败，请检查配置' => '数据库错误提示',
    '错误详情: ' => '详细错误日志',
];

echo "关键修复点检查:\n";
foreach ($checks as $keyword => $desc) {
    $found = strpos($content, $keyword) !== false;
    echo "   $desc: " . ($found ? "✓ 已包含" : "✗ 未包含") . "\n";
}

echo "\n";

// 检查是否还有旧版错误消息
$oldError = '系统错误，请稍后重试';
if (strpos($content, $oldError) !== false) {
    echo "⚠ 警告: api.php仍包含旧版错误消息 '$oldError'\n";
    // 找出位置
    $lines = explode("\n", $content);
    foreach ($lines as $lineNum => $line) {
        if (strpos($line, $oldError) !== false) {
            echo "   位置: 第" . ($lineNum + 1) . "行\n";
        }
    }
} else {
    echo "✓ api.php已更新，不包含旧版错误消息\n";
}

echo "\n=== 检查完成 ===\n";
echo "\n如果关键修复点有✗标记，请重新上传api.php文件\n";
