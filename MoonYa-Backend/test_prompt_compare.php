<?php
/**
 * 对比：宽松 vs 严格 prompt 的差异
 * 测试哪个能真正触发 $web_search
 */

$apiKey = 'sk-WE4wI9Dxe2YXloAEXW28H13drqUJC1zIPjHlpIFVG8iThpWq';
$apiUrl = 'https://api.moonshot.cn/v1/chat/completions';
$model = 'kimi-k2.6';
$query = '搜索yueyaxuan.cn';

$prompts = [
    '宽松 prompt (test_all_models_streaming.php 用)' => "你是一个AI助手，直接迅速回答用户的问题\n\n【联网搜索能力】\n你现在拥有联网搜索工具（Moonshot 原生 \$web_search）。\n当用户询问需要实时信息的问题时，必须主动调用搜索工具，而不是回答不知道或无法访问互联网。\n调用工具后，请基于工具返回的搜索结果用简洁自然的中文给出最终答案。",
    '严格 prompt (api.php 当前用)' => "你是一个AI助手，直接迅速回答用户的问题\n\n【联网搜索能力 - 必须使用】\n你拥有联网搜索工具 \$web_search（由 Moonshot 提供）。\n当用户的问题涉及实时信息、新闻、事实查询、网站内容、当前事件、人物/实体信息等任何需要联网的内容时，你**必须**调用 \$web_search 工具来获取信息。\n**直接调用工具，不要先回答\"我无法访问互联网\"**。\n调用工具后，基于工具返回的搜索结果用简洁自然的中文给出最终答案。",
    '极简 prompt' => "你是 MoonYa。直接回答用户问题。",
    '中文极简 prompt' => "你是一个有用的 AI 助手。请回答用户的问题。",
];

foreach ($prompts as $name => $prompt) {
    echo "\n=== $name ===\n";
    $requestData = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $prompt],
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
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $accumulated = '';
    $writeBuffer = '';
    $chunkCount = 0;
    $hasError = false;
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$accumulated, &$writeBuffer, &$chunkCount, &$hasError) {
        $writeBuffer .= $data;
        while (($pos = strpos($writeBuffer, "\n")) !== false) {
            $line = substr($writeBuffer, 0, $pos);
            $writeBuffer = substr($writeBuffer, $pos + 1);
            $line = trim($line);
            if (empty($line)) continue;
            if (strpos($line, 'data: ') !== 0) continue;
            $jsonStr = substr($line, 6);
            if ($jsonStr === '[DONE]') continue;
            $json = json_decode($jsonStr, true);
            if (!$json) continue;
            $chunkCount++;
            if (isset($json['error'])) {
                $hasError = true;
            }
            if (isset($json['choices'][0]['delta']['content'])) {
                $accumulated .= $json['choices'][0]['delta']['content'];
            }
        }
        return strlen($data);
    });

    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $hasFaker = mb_strpos($accumulated, '$web_search(') !== false;
    $hasRealInfo = mb_strpos($accumulated, '月雅轩') !== false;
    echo "HTTP: $code, Chunks: $chunkCount, Length: " . mb_strlen($accumulated) . "\n";
    if ($hasError) echo "❌ 有错误\n";
    if ($hasFaker) echo "❌ 输出伪装 \$web_search 字符串\n";
    if ($hasRealInfo) echo "✓ 包含真实信息(月雅轩)\n";
    if (!$hasFaker && mb_strlen($accumulated) > 100) echo "→ PASS\n";
    else echo "→ FAIL\n";

    echo "Content 前 200 字: " . mb_substr($accumulated, 0, 200) . "\n";
    sleep(2);
}
