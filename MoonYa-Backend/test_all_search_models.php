<?php
/**
 * 测试所有支持搜索的 kimi 模型
 * 对每个模型发送搜索请求，看哪个能真正执行搜索
 */

$apiKey = 'sk-WE4wI9Dxe2YXloAEXW28H13drqUJC1zIPjHlpIFVG8iThpWq';
$apiUrl = 'https://api.moonshot.cn/v1/chat/completions';
$query = '搜索yueyaxuan.cn';

$models = [
    'kimi-k2.6',
    'kimi-k2.5',
    'moonshot-v1-32k',
    'moonshot-v1-128k',
    'moonshot-v1-8k',
    'moonshot-v1-32k-vision-preview',
];

$systemPrompt = "你是一个AI助手，直接迅速回答用户的问题\n\n【联网搜索能力 - 必须使用】\n你拥有联网搜索工具 \$web_search（由 Moonshot 提供）。\n当用户的问题涉及实时信息、新闻、事实查询、网站内容、当前事件、人物/实体信息等任何需要联网的内容时，你**必须**调用 \$web_search 工具来获取信息。\n**直接调用工具，不要先回答\"我无法访问互联网\"**。\n调用工具后，基于工具返回的搜索结果用简洁自然的中文给出最终答案。";

foreach ($models as $model) {
    echo "\n========================================\n";
    echo "测试模型: $model\n";
    echo "========================================\n";

    $requestData = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $query]
        ],
        'stream' => true,
        'temperature' => 0.6,
        'max_tokens' => 32768,
        'tools' => [
            ['type' => 'builtin_function', 'function' => ['name' => '$web_search']]
        ],
        'tool_choice' => 'auto',
        'thinking' => ['type' => 'disabled']
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $contentAccumulated = '';
    $hasError = false;
    $apiErrorMessage = '';
    $finishReason = null;

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$contentAccumulated, &$hasError, &$apiErrorMessage, &$finishReason) {
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            if (strpos($line, 'data: ') === 0) {
                $jsonStr = substr($line, 6);
                if ($jsonStr === '[DONE]') continue;
                $json = json_decode($jsonStr, true);
                if (!$json) continue;
                if (isset($json['error'])) {
                    $hasError = true;
                    $apiErrorMessage = $json['error']['message'] ?? '未知错误';
                    continue;
                }
                if (isset($json['choices'][0]['finish_reason'])) {
                    $finishReason = $json['choices'][0]['finish_reason'];
                }
                if (isset($json['choices'][0]['delta']['content'])) {
                    $contentAccumulated .= $json['choices'][0]['delta']['content'];
                }
            }
        }
        return strlen($data);
    });

    $startTime = microtime(true);
    curl_exec($ch);
    $elapsed = round((microtime(true) - $startTime) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP: $httpCode | 耗时: {$elapsed}ms | Finish: " . ($finishReason ?? 'NULL') . "\n";
    if ($hasError) {
        echo "❌ 错误: $apiErrorMessage\n";
    }
    echo "Content 长度: " . mb_strlen($contentAccumulated) . "\n";

    // 检测伪装字符串
    $hasFaker = mb_strpos($contentAccumulated, '$web_search(') !== false;
    $hasRealInfo = mb_strpos($contentAccumulated, '月雅轩') !== false;
    $hasFailMarker = mb_strpos($contentAccumulated, '我无法访问') !== false || mb_strpos($contentAccumulated, '知识截止') !== false;

    if ($hasFaker) echo "❌ 输出伪装 \$web_search 字符串\n";
    if ($hasRealInfo) echo "✓ 包含真实搜索结果(月雅轩)\n";
    if ($hasFailMarker) echo "❌ 包含失败标记\n";

    if (mb_strlen($contentAccumulated) < 200 && !$hasFaker) {
        echo "Content: " . $contentAccumulated . "\n";
    } else {
        echo "Content 前 300 字: " . mb_substr($contentAccumulated, 0, 300) . "\n";
    }
    sleep(1);
}

echo "\n========================================\n";
echo "测试完成\n";
