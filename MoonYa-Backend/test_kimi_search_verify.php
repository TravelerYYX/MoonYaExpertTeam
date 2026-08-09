<?php
/**
 * 验证 kimi 搜索结果是否包含月雅轩
 * 用字节级比较和编码转换
 */

$apiKey = 'sk-WE4wI9Dxe2YXloAEXW28H13drqUJC1zIPjHlpIFVG8iThpWq';
$apiUrl = 'https://api.moonshot.cn/v1/chat/completions';
$query = '搜索yueyaxuan.cn';

$requestData = [
    'model' => 'kimi-k2.6',
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

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json; charset=utf-8',
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$accumulated = '';
$writeBuffer = '';
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$accumulated, &$writeBuffer) {
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
        if (isset($json['choices'][0]['delta']['content'])) {
            $accumulated .= $json['choices'][0]['delta']['content'];
        }
    }
    return strlen($data);
});

curl_exec($ch);
curl_close($ch);

echo "Content 长度: " . mb_strlen($accumulated) . " 字符 / " . strlen($accumulated) . " 字节\n";
echo "Content (UTF-8):\n$accumulated\n\n";
echo "===== 字节级分析 =====\n";

// 尝试用 mb_strpos 检查月雅轩
$hasMoonYa = mb_strpos($accumulated, '月雅轩') !== false;
echo "UTF-8 '月雅轩' 匹配: " . ($hasMoonYa ? "YES" : "NO") . "\n";

// 提取所有连续中文字符
preg_match_all('/[\x{4e00}-\x{9fa5}]{2,}/u', $accumulated, $matches);
echo "所有中文字符串:\n";
foreach ($matches[0] as $m) {
    echo "  - $m\n";
}

echo "\n===== 文件保存 =====\n";
file_put_contents(__DIR__ . '/test_output_utf8.txt', $accumulated);
echo "保存到 test_output_utf8.txt\n";
