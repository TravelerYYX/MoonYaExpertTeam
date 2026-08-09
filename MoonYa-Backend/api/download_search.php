<?php
/**
 * 下载链接搜索 API
 * 通过 AI 搜索模型查找软件/文件的最佳下载链接
 * POST /api/download_search.php
 * Body: { "query": "腾讯视频 PC版 官方下载" }
 */

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '仅支持 POST 方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$query = trim($input['query'] ?? '');

if (empty($query)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '缺少查询参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 补充搜索提示词以获取最佳下载链接
$searchPrompt = $query . ' 官方下载链接 ';

// 构建 AI 搜索请求
$searchModel = trim((string)($config['search_models']['search'] ?? ''));
if ($searchModel === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error_code' => 'missing_config', 'error' => 'Missing required configuration: search_models.search']);
    exit;
}
$apiUrl = $config['api_url'];
$apiKey = $config['api_key'];

$messages = [
    ['role' => 'system', 'content' => '你是一个下载链接搜索助手。用户想要下载某个软件或文件，请帮用户找到官方的直接下载链接。请以简洁格式返回：1. 最佳下载链接 2. 建议的文件名。注意：只需要返回一个最佳的直接下载URL，不要返回网页地址，不要有多余解释。'],
    ['role' => 'user', 'content' => $searchPrompt]
];

$requestData = [
    'model' => $searchModel,
    'messages' => $messages,
    'temperature' => 0.3,
    'max_tokens' => 500,
    'stream' => false,
    'tools' => [
        [
            'type' => 'builtin_function',
            'function' => ['name' => '$web_search']
        ]
    ]
];

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($requestData),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => '搜索服务暂时不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
if (!$data) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => '搜索响应解析失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 提取 AI 回复文本
$content = $data['choices'][0]['message']['content'] ?? '';

// 从回复中提取所有 URL
preg_match_all('#https?://[^\s\'"<>\)\]\}]+#i', $content, $urlMatches);
$allUrls = $urlMatches[0] ?? [];

// 过滤出可能的下载链接
$downloadKeywords = ['.exe', '.zip', '.msi', '.dmg', '.pkg', '.deb', '.rpm', '.apk', '.appx', 'download', 'setup', 'install', 'dl.', 'get.'];
$downloadUrls = [];
foreach ($allUrls as $u) {
    $u = rtrim($u, ',;.!?');
    foreach ($downloadKeywords as $kw) {
        if (stripos($u, $kw) !== false) {
            $downloadUrls[] = $u;
            break;
        }
    }
}

// 如果没有匹配到下载关键词的链接，返回所有找到的 URL
if (empty($downloadUrls)) {
    $downloadUrls = $allUrls;
}

// 提取建议的文件名
$suggestedFilename = '';
if (preg_match('/[^\/\\\\]+\.(exe|zip|msi|dmg|apk)/i', $content, $nameMatch)) {
    $suggestedFilename = $nameMatch[0];
}

$bestUrl = $downloadUrls[0] ?? ($allUrls[0] ?? '');

echo json_encode([
    'success' => !empty($bestUrl),
    'urls' => array_slice($downloadUrls, 0, 5),
    'best_url' => $bestUrl,
    'suggested_filename' => $suggestedFilename,
    'search_query' => $query,
], JSON_UNESCAPED_UNICODE);
