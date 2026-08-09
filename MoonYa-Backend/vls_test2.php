<?php
/**
 * VLS 大图片测试 - 验证 Moonshot API 是否对请求体大小有限制
 */

require_once __DIR__ . '/env_loader.php';
$config = require __DIR__ . '/config.php';

echo "=== VLS 大图片诊断测试 ===\n\n";

// 用 PHP GD 生成一个 1280x800 的 PNG（模拟浏览器视口截图）
$tmpFile = sys_get_temp_dir() . '/vls_test_' . uniqid() . '.png';
$img = imagecreatetruecolor(1280, 800);
$bg = imagecolorallocate($img, 240, 240, 240);
imagefill($img, 0, 0, $bg);
// 画一些内容（模拟网页）
$black = imagecolorallocate($img, 0, 0, 0);
$blue = imagecolorallocate($img, 50, 100, 200);
imagefilledrectangle($img, 100, 100, 500, 140, $blue); // 搜索框
imagefilledrectangle($img, 520, 100, 600, 140, $black); // 搜索按钮
imagestring($img, 5, 110, 110, 'Search...', $black);
imagepng($img, $tmpFile);
imagedestroy($img);

$imageBase64 = base64_encode(file_get_contents($tmpFile));
unlink($tmpFile);

echo "生成的 1280x800 PNG 截图大小: " . strlen($imageBase64) . " 字节 (base64)\n";
echo "Moonshot API URL: {$config['api_url']}\n";
echo "Moonshot API Key: " . substr($config['api_key'], 0, 8) . "...\n";
echo "GLM API URL: {$config['glm_api_url']}\n";
echo "GLM API Key: " . substr($config['glm_api_key'], 0, 8) . "...\n\n";

$prompt = '请描述这张图片的内容。';

function testVisionModel(string $label, string $apiUrl, string $apiKey, string $model, string $imageBase64, string $prompt, float $temp = 0.3): void {
    echo "--- 测试: {$label} (temperature={$temp}) ---\n";

    $requestData = [
        'model'      => $model,
        'stream'     => false,
        'messages'   => [
            [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $imageBase64]],
                ],
            ],
        ],
        'max_tokens' => 512,
        'temperature' => $temp,
    ];

    $body = json_encode($requestData, JSON_UNESCAPED_UNICODE);
    echo "  请求体大小: " . strlen($body) . " 字节\n";

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
        echo "  错误响应: " . substr((string)$resp, 0, 500) . "\n\n";
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

// 测试 1: Moonshot + moonshot-v1-8k-vision-preview + 大截图 + temperature=0.3
testVisionModel(
    'Moonshot + moonshot-v1-8k-vision-preview + 大截图',
    $config['api_url'],
    $config['api_key'],
    'moonshot-v1-8k-vision-preview',
    $imageBase64,
    $prompt,
    0.3
);

// 测试 2: Moonshot + moonshot-v1-8k-vision-preview + 大截图 + temperature=0.2（实际代码用的值）
testVisionModel(
    'Moonshot + moonshot-v1-8k-vision-preview + 大截图 + temp=0.2',
    $config['api_url'],
    $config['api_key'],
    'moonshot-v1-8k-vision-preview',
    $imageBase64,
    $prompt,
    0.2
);

// 测试 3: GLM + GLM-4.6V-Flash + 大截图
testVisionModel(
    'GLM + GLM-4.6V-Flash + 大截图',
    $config['glm_api_url'],
    $config['glm_api_key'],
    'GLM-4.6V-Flash',
    $imageBase64,
    $prompt,
    0.3
);

// 测试 4: Moonshot + kimi-k2.5 + 大截图 + temperature=1（kimi-k2.5 要求 temp=1）
testVisionModel(
    'Moonshot + kimi-k2.5 + 大截图 + temp=1',
    $config['api_url'],
    $config['api_key'],
    'kimi-k2.5',
    $imageBase64,
    $prompt,
    1.0
);

echo "=== 诊断完成 ===\n";
