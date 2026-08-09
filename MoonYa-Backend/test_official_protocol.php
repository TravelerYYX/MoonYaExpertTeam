<?php
/**
 * 严格按 Kimi 官方协议测试 $web_search
 * 官方协议 = 两阶段：
 *   Phase 1 (stream=true): 拿 tool_calls
 *   Phase 2 (stream=true):  把 tool_calls 原封不动作为 role=tool 提交，拿最终答案
 *
 * 参考: https://platform.moonshot.cn/docs/guide/use-web-search
 */

$config = require __DIR__ . '/config.php';
$apiKey = $config['api_key'];
$apiUrl = $config['kimi_web_search']['endpoint'];
$primaryModel = $argv[2] ?? $config['kimi_web_search']['primary_model'];

echo "===== Kimi 官方协议测试 =====\n";
echo "API URL: $apiUrl\n";
echo "Model: $primaryModel\n\n";

$query = $argv[1] ?? '请搜索yueyaxuan.cn这个网站，告诉我它是什么';
echo "Query: $query\n\n";

$systemPrompt = "你是 Kimi，由 Moonshot AI 提供的人工智能助手。\n\n# 联网搜索能力\n你拥有联网搜索工具 \$web_search。当用户的问题涉及以下情况时，请主动调用 \$web_search 工具：\n- 需要查询实时信息、新闻、天气、股票等\n- 需要查询某个网站、域名、公司的信息\n- 需要查询最新的事实、人物、事件\n- 用户明确说\"搜索\"、\"查一下\"、\"告诉我\"等\n\n当用户只是闲聊、问候、咨询通用知识时，**不要**调用搜索工具，直接回答即可。";

$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
    ['role' => 'user', 'content' => $query],
];

// ========== Phase 1: stream=true 拿 tool_calls ==========
echo "===== Phase 1: stream=true 拿 tool_calls =====\n";

$phase1Request = [
    'model' => $primaryModel,
    'messages' => $messages,
    'max_tokens' => 32768,
    'stream' => false,  // 关键：stream=false 才会真正触发 $web_search
    'thinking' => ['type' => 'disabled'],
    'tools' => [
        ['type' => 'builtin_function', 'function' => ['name' => '$web_search']],
    ],
    'tool_choice' => 'required',  // v4.1: 强制模型必须调用工具
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($phase1Request));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$start = microtime(true);
$response = curl_exec($ch);
$elapsed = round((microtime(true) - $start) * 1000);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP: $httpCode, 耗时: {$elapsed}ms\n";
if ($curlError) echo "Curl Error: $curlError\n";

$resp = json_decode($response, true);
if (!$resp) {
    echo "JSON 解析失败: $response\n";
    exit(1);
}
if (isset($resp['error'])) {
    echo "API Error: " . json_encode($resp['error'], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$choice = $resp['choices'][0] ?? null;
if (!$choice) {
    echo "No choice in response\n";
    exit(1);
}

$phase1Content = $choice['message']['content'] ?? '';
$phase1FinishReason = $choice['finish_reason'] ?? 'unknown';
$phase1ToolCalls = [];

if (isset($choice['message']['tool_calls'])) {
    foreach ($choice['message']['tool_calls'] as $idx => $tc) {
        $phase1ToolCalls[$idx] = [
            'id' => $tc['id'] ?? '',
            'type' => $tc['type'] ?? 'function',
            'function' => [
                'name' => $tc['function']['name'] ?? '',
                'arguments' => $tc['function']['arguments'] ?? '',
            ],
        ];
    }
}

echo "Finish Reason: $phase1FinishReason\n";
echo "Content 长度: " . mb_strlen($phase1Content) . "\n";
echo "Content 预览: " . substr($phase1Content, 0, 200) . "\n";
echo "Tool Calls 数: " . count($phase1ToolCalls) . "\n";
foreach ($phase1ToolCalls as $i => $tc) {
    echo "  [$i] id={$tc['id']}, name={$tc['function']['name']}, args={$tc['function']['arguments']}\n";
}
echo "\n";

if (empty($phase1ToolCalls) || $phase1FinishReason !== 'tool_calls') {
    echo "❌ Phase 1 没有返回 tool_calls\n";
    echo "Finish Reason: $phase1FinishReason\n";
    echo "完整 content: $phase1Content\n";
    exit(1);
}

echo "✓ Phase 1 成功拿到 tool_calls\n\n";

// ========== 构造第二轮请求 ==========
// 1. 追加 assistant 消息（包含 tool_calls）
$assistantMsg = [
    'role' => 'assistant',
    'content' => $phase1Content,
    'tool_calls' => array_values($phase1ToolCalls),
];
$messages[] = $assistantMsg;

// 2. 遍历 tool_calls 追加 tool 消息（关键：原封不动返回 arguments）
foreach ($phase1ToolCalls as $toolCall) {
    $toolName = $toolCall['function']['name'];
    $toolArgs = $toolCall['function']['arguments'];  // 字符串形式
    $messages[] = [
        'role' => 'tool',
        'tool_call_id' => $toolCall['id'],
        'name' => $toolName,
        'content' => $toolArgs,
    ];
    echo "  Tool: $toolName\n";
    echo "  Args: $toolArgs\n";
}

echo "\n===== Phase 2: stream=true 拿最终答案 =====\n";

$phase2Request = [
    'model' => $primaryModel,
    'messages' => $messages,
    'max_tokens' => 32768,
    'temperature' => 0.6,
    'stream' => true,
    'thinking' => ['type' => 'disabled'],
    'tools' => [
        ['type' => 'builtin_function', 'function' => ['name' => '$web_search']],
    ],
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($phase2Request));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$phase2Content = '';
$phase2FinishReason = null;
$phase2HasError = false;
$phase2ErrorMessage = '';

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$phase2Content, &$phase2FinishReason, &$phase2HasError, &$phase2ErrorMessage) {
    $lines = explode("\n", $data);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if (strpos($line, 'data: ') !== 0) continue;
        $jsonStr = substr($line, 6);
        if ($jsonStr === '[DONE]') continue;
        $j = json_decode($jsonStr, true);
        if (!$j) continue;
        if (isset($j['error'])) {
            $phase2HasError = true;
            $phase2ErrorMessage = $j['error']['message'] ?? 'unknown';
            echo "ERROR: $phase2ErrorMessage\n";
        }
        if (isset($j['choices'][0]['delta']['content'])) {
            $phase2Content .= $j['choices'][0]['delta']['content'];
        }
        if (isset($j['choices'][0]['finish_reason']) && $j['choices'][0]['finish_reason']) {
            $phase2FinishReason = $j['choices'][0]['finish_reason'];
        }
    }
    return strlen($data);
});

$start = microtime(true);
curl_exec($ch);
$elapsed = round((microtime(true) - $start) * 1000);
$httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP: $httpCode2, 耗时: {$elapsed}ms\n";
echo "Finish Reason: " . ($phase2FinishReason ?? 'NULL') . "\n";
echo "Has Error: " . ($phase2HasError ? "YES" : 'NO') . "\n";
echo "Content 长度: " . mb_strlen($phase2Content) . "\n";
echo "\n===== Content =====\n";
echo $phase2Content . "\n\n";

echo "===== 验证 =====\n";
$markers = ['月雅轩', 'yueyaxuan'];
foreach ($markers as $m) {
    $has = mb_strpos($phase2Content, $m) !== false;
    echo $has ? "✓ 包含 \"$m\"（真实搜索结果）\n" : "✗ 不包含 \"$m\"\n";
}
