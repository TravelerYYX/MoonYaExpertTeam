<?php
/**
 * 直接测试API响应
 * 模拟前端发送请求到api.php
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== 直接测试API响应 ===\n\n";

// 测试URL
$apiUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api.php';
echo "API URL: $apiUrl\n\n";

// 准备测试数据
$testData = [
    'message' => '你好',
    'model' => 'kimi',
    'deepThinking' => false,
    'conversation_id' => null
];

// 初始化cURL
$ch = curl_init($apiUrl);

// 设置cURL选项
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: text/event-stream'
]);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// 执行请求
echo "发送请求（不带token）...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP状态码: $httpCode\n";
if ($error) {
    echo "cURL错误: $error\n";
}
echo "响应内容:\n";
echo $response;
echo "\n\n";

// 解析SSE响应
echo "解析响应:\n";
$lines = explode("\n", $response);
foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, 'data: ') === 0) {
        $jsonData = substr($line, 6);
        $data = json_decode($jsonData, true);
        if ($data) {
            echo "  类型: " . ($data['type'] ?? 'unknown') . "\n";
            echo "  内容: " . ($data['content'] ?? '无内容') . "\n";
        }
    }
}

echo "\n=== 测试完成 ===\n";
