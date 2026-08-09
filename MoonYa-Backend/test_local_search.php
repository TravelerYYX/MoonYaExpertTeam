<?php
// 测试本地 Python 搜索服务
$url = 'http://127.0.0.1:58901/search';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => 'yueyaxuan.cn 是什么网站', 'count' => 5]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP: $httpCode\n";
if ($err) echo "Curl Error: $err\n";
echo "Response: " . substr($resp ?? '', 0, 1000) . "\n";

// 解析 JSON
$j = json_decode($resp, true);
if ($j) {
    echo "\n===== JSON Parse =====\n";
    if (isset($j['results'])) {
        echo "Results 数: " . count($j['results']) . "\n";
        foreach (array_slice($j['results'], 0, 3) as $i => $r) {
            echo "  [$i] " . ($r['title'] ?? '?') . "\n";
            echo "      URL: " . ($r['url'] ?? '?') . "\n";
            echo "      Snippet: " . substr($r['snippet'] ?? $r['content'] ?? '', 0, 200) . "\n";
        }
    } else {
        print_r($j);
    }
}
