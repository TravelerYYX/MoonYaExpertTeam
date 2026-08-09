<?php
/**
 * 检查index.php版本
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== 检查index.php版本 ===\n\n";

$indexFile = __DIR__ . '/index.php';
if (!file_exists($indexFile)) {
    echo "✗ index.php不存在\n";
    exit;
}

$content = file_get_contents($indexFile);

echo "文件大小: " . filesize($indexFile) . " 字节\n";
echo "最后修改: " . date('Y-m-d H:i:s', filemtime($indexFile)) . "\n\n";

// 检查关键修复点
$checks = [
    "localStorage.setItem('api_token'" => '保存token到localStorage',
    "headers['Authorization'] = 'Bearer '" => '发送Authorization头',
    'const apiToken = localStorage.getItem' => '获取apiToken',
];

echo "关键修复点检查:\n";
foreach ($checks as $keyword => $desc) {
    $found = strpos($content, $keyword) !== false;
    echo "   $desc: " . ($found ? "✓ 已包含" : "✗ 未包含") . "\n";
}

echo "\n=== 检查完成 ===\n";
