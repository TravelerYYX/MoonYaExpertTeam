<?php
/**
 * 检查关键文件的时间戳
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== 文件时间戳检查 ===\n\n";

$files = [
    'api.php',
    'index.php',
    'config.php',
    'user_auth.php'
];

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        $size = filesize($path);
        $mtime = filemtime($path);
        echo "$file:\n";
        echo "  大小: $size 字节\n";
        echo "  修改时间: " . date('Y-m-d H:i:s', $mtime) . "\n";
        echo "  相对时间: " . humanTiming($mtime) . "\n\n";
    } else {
        echo "$file: ✗ 不存在\n\n";
    }
}

echo "当前服务器时间: " . date('Y-m-d H:i:s') . "\n";
echo "\n=== 检查完成 ===\n";

function humanTiming($time) {
    $time = time() - $time;
    $tokens = [
        31536000 => '年',
        2592000 => '月',
        604800 => '周',
        86400 => '天',
        3600 => '小时',
        60 => '分钟',
        1 => '秒'
    ];
    foreach ($tokens as $unit => $text) {
        if ($time < $unit) continue;
        $numberOfUnits = floor($time / $unit);
        return $numberOfUnits . ' ' . $text . '前';
    }
    return '刚刚';
}
