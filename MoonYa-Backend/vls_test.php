<?php
/**
 * VLS 视觉模型测试脚本 - 诊断 Moonshot API 是否真的支持 image_url
 *
 * 用法：php vls_test.php
 * 输出：测试 1（Moonshot + kimi-k2.5）、测试 2（Moonshot + moonshot-v1-8k-vision-preview）、
 *      测试 3（GLM + GLM-4.6V-Flash）、测试 4（DeepSeek 不支持视觉，对照）
 */

require_once __DIR__ . '/env_loader.php';
$config = require __DIR__ . '/config.php';

echo "=== VLS 视觉模型诊断测试 ===\n\n";

// 生成一个 1x1 红色 PNG 的 base64（最小测试图片，排除请求体大小问题）
// 一个真实的 1x1 红色 PNG
$minimalPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

$prompt = '请描述这张图片的内容。';

function testVisionModel(string $label, string $apiUrl, string $apiKey, string $model, string $imageBase64, string $prompt): void {
    echo "--- 测试: {$label} ---\n";
    echo "  API URL: {$apiUrl}\n";
    echo "  Model:   {$model}\n";
    echo "  API Key: " . substr($apiKey, 0, 8) . "..." . substr($apiKey, -4) . "\n";

    if ($apiKey === '') {
        echo "  结果: 跳过（API Key 为空）\n\n";
        return;
    }

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
        'temperature' => 0.3,
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
        CURLOPT_TIMEOUT         => 60,
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
    if (!is_array($json)) {
        echo "  结果: 响应非 JSON - " . substr((string)$resp, 0, 200) . "\n\n";
        return;
    }
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

// 测试 1: Moonshot + kimi-k2.5（用户说这是多模态模型）
testVisionModel(
    'Moonshot + kimi-k2.5',
    $config['api_url'],
    $config['api_key'],
    'kimi-k2.5',
    $minimalPngBase64,
    $prompt
);

// 测试 2: Moonshot + moonshot-v1-8k-vision-preview（传统视觉模型）
testVisionModel(
    'Moonshot + moonshot-v1-8k-vision-preview',
    $config['api_url'],
    $config['api_key'],
    'moonshot-v1-8k-vision-preview',
    $minimalPngBase64,
    $prompt
);

// 测试 3: GLM + GLM-4.6V-Flash（智谱视觉模型）
testVisionModel(
    'GLM + GLM-4.6V-Flash',
    $config['glm_api_url'],
    $config['glm_api_key'],
    'GLM-4.6V-Flash',
    $minimalPngBase64,
    $prompt
);

// 测试 4: GLM + glm-4v-flash（小写变体，对照）
testVisionModel(
    'GLM + glm-4v-flash',
    $config['glm_api_url'],
    $config['glm_api_key'],
    'glm-4v-flash',
    $minimalPngBase64,
    $prompt
);

echo "=== 诊断完成 ===\n";
echo "\n说明：\n";
echo "- 如果 Moonshot 测试报 'unknown variant image_url'，说明 Moonshot API 实际不支持 image_url（文档与实现不符）\n";
echo "- 如果 GLM 测试成功，建议把 vls_model 改为 GLM-4.6V-Flash\n";
echo "- 测试用 1x1 像素最小图片，排除请求体大小问题\n";
