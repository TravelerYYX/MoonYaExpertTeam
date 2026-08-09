<?php
// 探查 Python 搜索服务的不同 action
$base = 'http://127.0.0.1:58901';
$actions = [
    'fetch_yueyaxuan' => ['action' => 'web_fetch', 'url' => 'https://yueyaxuan.cn'],
    'fetch_baidu' => ['action' => 'web_fetch', 'url' => 'https://www.baidu.com'],
];

foreach ($actions as $name => $body) {
    $ch = curl_init($base . '/search');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    echo "[$name] HTTP: $code, err: " . ($err ?: '无') . "\n";
    if ($resp) echo "  resp: " . substr($resp, 0, 300) . "\n";
    echo "\n";
}
