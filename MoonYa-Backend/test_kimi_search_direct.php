<?php
/**
 * 直接测试 kimi 单阶段流式联网搜索
 * 模拟 api.php 中 line 1395-1446 的核心逻辑
 * 不依赖 session / 数据库
 */

// 加载 config
$config = require __DIR__ . '/config.php';
$apiKey = $config['api_key'];
$apiUrl = $config['kimi_web_search']['endpoint'];
$primaryModel = $config['kimi_web_search']['primary_model'];

echo "===== 直接测试 Kimi 单阶段流式联网搜索 =====\n";
echo "API URL: $apiUrl\n";
echo "Model: $primaryModel\n";
echo "API Key: " . substr($apiKey, 0, 10) . "...\n\n";

$query = $argv[1] ?? '搜索yueyaxuan.cn';

$requestData = [
    'model' => $primaryModel,
    'messages' => [
        [
        'role' => 'system',
        'content' => "你是一个AI助手，直接迅速回答用户的问题\n\n【联网搜索能力】\n你现在拥有联网搜索工具（Moonshot 原生 \$web_search）。\n当用户询问需要实时信息的问题时，必须主动调用搜索工具，而不是回答不知道或无法访问互联网。\n调用工具后，请基于工具返回的搜索结果用简洁自然的中文给出最终答案。"
    ],
        [
            'role' => 'user',
            'content' => $query
        ]
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

echo "===== 请求体 =====\n";
echo json_encode($requestData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

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

$fullResponse = '';
$contentAccumulated = '';
$chunkCount = 0;
$hasToolCalls = false;
$finishReason = null;
$hasError = false;
$apiErrorMessage = '';

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$fullResponse, &$contentAccumulated, &$chunkCount, &$hasToolCalls, &$finishReason, &$hasError, &$apiErrorMessage) {
    $fullResponse .= $data;
    $chunkCount++;
    $lines = explode("\n", $data);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if (strpos($line, 'data: ') === 0) {
            $jsonStr = substr($line, 6);
            if ($jsonStr === '[DONE]') {
                echo "\n===== [DONE] 流式结束 =====\n";
                continue;
            }
            $json = json_decode($jsonStr, true);
            if (!$json) continue;
            if (isset($json['error'])) {
                $hasError = true;
                $apiErrorMessage = $json['error']['message'] ?? '未知错误';
                echo "ERROR: $apiErrorMessage\n";
                continue;
            }
            if (isset($json['choices'][0]['delta']['tool_calls'])) {
                $hasToolCalls = true;
                echo "TOOL_CALL: " . json_encode($json['choices'][0]['delta']['tool_calls'], JSON_UNESCAPED_UNICODE) . "\n";
            }
            if (isset($json['choices'][0]['finish_reason'])) {
                $finishReason = $json['choices'][0]['finish_reason'];
                echo "FINISH_REASON: $finishReason\n";
            }
            if (isset($json['choices'][0]['delta']['content'])) {
                $contentAccumulated .= $json['choices'][0]['delta']['content'];
            }
        }
    }
    return strlen($data);
});

echo "===== 开始请求 Moonshot API =====\n";
$startTime = microtime(true);
curl_exec($ch);
$elapsed = round((microtime(true) - $startTime) * 1000);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "\n===== 测试结果 =====\n";
echo "HTTP 状态码: $httpCode\n";
echo "耗时: {$elapsed}ms\n";
echo "Curl 错误: " . ($curlError ?: '无') . "\n";
echo "Chunk 数量: $chunkCount\n";
echo "Has Tool Calls: " . ($hasToolCalls ? 'YES' : 'NO') . "\n";
echo "Finish Reason: " . ($finishReason ?? 'NULL') . "\n";
echo "Content 长度: " . mb_strlen($contentAccumulated) . "\n";
echo "Has Error: " . ($hasError ? 'YES' : 'NO') . "\n";
if ($hasError) echo "Error: $apiErrorMessage\n";
echo "\n===== Content 内容 =====\n";
echo $contentAccumulated . "\n";
echo "\n===== 是否包含月雅轩等真实信息 =====\n";
$hasReal = mb_strpos($contentAccumulated, '月雅轩') !== false;
echo $hasReal ? "✓ 包含真实信息\n" : "✗ 不包含真实信息\n";

// 失败标记检测
$failMarkers = ['我无法访问', '我无法搜索', '知识截止于', '$web_search('];
$foundMarker = null;
foreach ($failMarkers as $m) {
    if (mb_strpos($contentAccumulated, $m) !== false) {
        $foundMarker = $m;
        break;
    }
}
if ($foundMarker) {
    echo "✗ 检测到失败标记: $foundMarker\n";
} else {
    echo "✓ 未检测到失败标记\n";
}
