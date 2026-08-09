<?php
/**
 * VLS 真实大图测试 - 用 1.6MB 的 JPG 测试 Moonshot API 是否支持大请求体
 */

require_once __DIR__ . '/env_loader.php';
$config = require __DIR__ . '/config.php';

echo "=== VLS 真实大图诊断测试 ===\n\n";

$imagePath = __DIR__ . '/test_large.jpg';
if (!file_exists($imagePath)) {
    die("测试图片不存在: $imagePath\n");
}

$rawSize = filesize($imagePath);
$imageBase64 = base64_encode(file_get_contents($imagePath));
$base64Size = strlen($imageBase64);

echo "原始 JPG 大小: " . round($rawSize / 1024, 1) . " KB\n";
echo "Base64 编码后: " . round($base64Size / 1024, 1) . " KB\n";
echo "Moonshot API URL: {$config['api_url']}\n\n";

$prompt = '请简要描述这张图片的内容。';

function testVisionModel(string $label, string $apiUrl, string $apiKey, string $model, string $imageBase64, string $prompt, float $temp): void {
    echo "--- 测试: {$label} (temperature={$temp}) ---\n";

    $requestData = [
        'model'      => $model,
        'stream'     => false,
        'messages'   => [
            [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imageBase64]],
                ],
            ],
        ],
        'max_tokens' => 512,
        'temperature' => $temp,
    ];

    $body = json_encode($requestData, JSON_UNESCAPED_UNICODE);
    echo "  请求体大小: " . round(strlen($body) / 1024, 1) . " KB\n";

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT         => 120,
        CURLOPT_CONNECTTIMEOUT  => 15,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $err) {
        echo "  结果: curl 错误 - {$err}\n\n";
        return;
    }
    echo "  HTTP 状态码: {$code}\n";
    if ($code >= 400) {
        echo "  错误响应: " . substr((string)$resp, 0, 800) . "\n\n";
        return;
    }
    $json = json_decode($resp, true);
    if (isset($json['error'])) {
        echo "  结果: API 返回错误 - " . ($json['error']['message'] ?? json_encode($json['error'])) . "\n\n";
        return;
    }
    $content = $json['choices'][0]['message']['content'] ?? '';
    if (is_array($content)) {
        $text = '';
        foreach ($content as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        $content = $text;
    }
    echo "  结果: 成功 ✓\n";
    echo "  模型回复: " . mb_substr((string)$content, 0, 200) . "\n\n";
}

// 测试 1: Moonshot + moonshot-v1-8k-vision-preview + 1.6MB 大图 + temp=0.2
testVisionModel(
    'Moonshot + moonshot-v1-8k-vision-preview + 1.6MB 大图 + temp=0.2',
    $config['api_url'],
    $config['api_key'],
    'moonshot-v1-8k-vision-preview',
    $imageBase64,
    $prompt,
    0.2
);

// 测试 2: Moonshot + moonshot-v1-8k-vision-preview + 1.6MB 大图 + temp=0.3
testVisionModel(
    'Moonshot + moonshot-v1-8k-vision-preview + 1.6MB 大图 + temp=0.3',
    $config['api_url'],
    $config['api_key'],
    'moonshot-v1-8k-vision-preview',
    $imageBase64,
    $prompt,
    0.3
);

// 测试 3: Moonshot + kimi-k2.5 + 1.6MB 大图 + temp=1
testVisionModel(
    'Moonshot + kimi-k2.5 + 1.6MB 大图 + temp=1',
    $config['api_url'],
    $config['api_key'],
    'kimi-k2.5',
    $imageBase64,
    $prompt,
    1.0
);

echo "=== 诊断完成 ===\n";
echo "\n结论：\n";
echo "- 如果大图测试报 'unknown variant image_url'，确认是 Moonshot 对大请求体的限制\n";
echo "- 如果大图测试成功，说明问题在别处（可能 PHP 代码构造请求有差异）\n";
