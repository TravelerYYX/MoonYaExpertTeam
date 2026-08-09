<?php
/**
 * 检查api.php错误消息版本
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== 检查api.php错误消息 ===\n\n";

$apiFile = __DIR__ . '/api.php';
if (!file_exists($apiFile)) {
    echo "✗ api.php不存在\n";
    exit;
}

$content = file_get_contents($apiFile);

echo "文件大小: " . filesize($apiFile) . " 字节\n";
echo "最后修改: " . date('Y-m-d H:i:s', filemtime($apiFile)) . "\n\n";

// 检查错误消息版本
$oldMessage = '数据库连接失败，请检查配置';
$newMessage = '$e->getMessage()';

if (strpos($content, $oldMessage) !== false) {
    echo "⚠ 警告: api.php仍包含旧版错误消息 '$oldMessage'\n";
    echo "  这意味着服务器上的api.php不是最新版本\n";
} elseif (strpos($content, $newMessage) !== false) {
    echo "✓ api.php已更新，包含详细错误信息\n";
} else {
    echo "? 无法确定错误消息版本\n";
}

// 查找数据库错误处理代码的位置
echo "\n数据库错误处理代码:\n";
$lines = explode("\n", $content);
foreach ($lines as $lineNum => $line) {
    if (strpos($line, '数据库连接失败') !== false) {
        echo "  第" . ($lineNum + 1) . "行: " . trim($line) . "\n";
    }
}

echo "\n=== 检查完成 ===\n";
echo "\n如果显示旧版错误消息，请重新上传api.php\n";
