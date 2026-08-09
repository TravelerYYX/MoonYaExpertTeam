<?php
// 探查 Python 搜索服务的正确接口路径
$base = 'http://127.0.0.1:58901';
$paths = ['/search', '/api/search', '/web_search', '/api/web_search', '/v1/search', '/'];
$methods = ['GET', 'POST'];

foreach ($paths as $path) {
    foreach ($methods as $method) {
        $url = $base . $path;
        $ch = curl_init($url);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['action' => 'web_search', 'query' => 'yueyaxuan.cn']));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $body = substr($resp ?? '', 0, 200);
        echo "$method $url → HTTP $code, err: " . ($err ?: '无') . ", body: $body\n";
    }
    echo "\n";
}
