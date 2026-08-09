<?php
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');
header('X-Accel-Buffering: no');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function logVideoDebug($message) {
    $logDir = __DIR__ . '/../admin/logs/';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . 'video_gen_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function sendSSE($type, $data) {
    echo "data: " . json_encode(array_merge(['type' => $type], $data), JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

function sendErrorAndExit($message) {
    sendSSE('error', ['content' => "视频生成失败：{$message}"]);
    sendSSE('done', []);
    logVideoDebug("错误退出: $message");
    exit;
}

try {
    $config = require_once __DIR__ . '/../config.php';
} catch (\Throwable $e) {
    logVideoDebug("配置加载失败: " . $e->getMessage());
    sendErrorAndExit('服务配置错误');
}

$apiUrl = trim((string)($config['cogvideo_api_url'] ?? ''));
$apiKey = $config['glm_api_key'] ?? '';
$model = trim((string)($config['cogvideo_model'] ?? ''));
$asyncResultBase = trim((string)($config['cogvideo_async_result_url'] ?? ''));

foreach (['cogvideo_api_url' => $apiUrl, 'glm_api_key' => $apiKey, 'cogvideo_model' => $model, 'cogvideo_async_result_url' => $asyncResultBase] as $field => $value) {
    if (trim((string)$value) === '') sendErrorAndExit("Missing required configuration: {$field}");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorAndExit('请使用POST方法请求');
}

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);

if (!$input) {
    sendErrorAndExit('请求体JSON解析失败');
}

logVideoDebug("收到请求: " . json_encode($input, JSON_UNESCAPED_UNICODE));

$allowedQuality = ['speed', 'quality'];
$allowedSize = ['1280x720'];
$allowedFps = [30, 60];
$allowedDuration = [5, 10];

$prompt = isset($input['prompt']) ? trim($input['prompt']) : '';
if ($prompt === '') {
    sendErrorAndExit('prompt 参数不能为空');
}
if (mb_strlen($prompt) > 512) {
    sendErrorAndExit('prompt 长度不能超过512个字符');
}

$quality = isset($input['quality']) ? strtolower(trim($input['quality'])) : 'speed';
if (!in_array($quality, $allowedQuality, true)) {
    $quality = 'speed';
}

$withAudio = isset($input['with_audio']) ? (bool)$input['with_audio'] : false;

$size = isset($input['size']) ? trim($input['size']) : '1280x720';
if (!in_array($size, $allowedSize, true)) {
    $size = '1280x720';
}

$fps = isset($input['fps']) ? (int)$input['fps'] : 30;
if (!in_array($fps, $allowedFps, true)) {
    $fps = 30;
}

$duration = isset($input['duration']) ? (int)$input['duration'] : 5;
if (!in_array($duration, $allowedDuration, true)) {
    $duration = 5;
}

$imageUrl = isset($input['image_url']) ? trim($input['image_url']) : '';

$requestBody = [
    'model' => $model,
    'prompt' => $prompt,
    'quality' => $quality,
    'with_audio' => $withAudio,
    'size' => $size,
    'fps' => $fps,
    'duration' => $duration
];

if (!empty($imageUrl)) {
    $requestBody['image_url'] = $imageUrl;
}

logVideoDebug("API请求: url=$apiUrl, body=" . json_encode($requestBody, JSON_UNESCAPED_UNICODE));

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($requestBody),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ],
    CURLOPT_TIMEOUT => 300,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

logVideoDebug("API响应: http_code=$httpCode, error=$curlError, response=$response");

if ($curlError) {
    sendErrorAndExit('网络请求失败: ' . $curlError);
}

$result = json_decode($response, true);
if (!$result) {
    logVideoDebug("API响应JSON解析失败: $response");
    sendErrorAndExit('API响应解析失败');
}

if ($httpCode !== 200) {
    $errMsg = $result['error']['message'] ?? "HTTP $httpCode";
    logVideoDebug("API返回非200: $errMsg");
    sendErrorAndExit("视频生成请求失败: $errMsg");
}

$taskId = $result['id'] ?? ($result['task_id'] ?? null);
if (!$taskId) {
    logVideoDebug("未获取到task_id: " . json_encode($result, JSON_UNESCAPED_UNICODE));
    sendErrorAndExit('未获取到任务ID');
}

logVideoDebug("获取到task_id: $taskId");

$maxAttempts = 30;
$pollInterval = 3;
$videoUrl = null;
$coverUrl = null;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    sleep($pollInterval);

    $pollUrl = $asyncResultBase . '/' . $taskId;

    logVideoDebug("轮询 #$attempt: $pollUrl");

    $ch = curl_init($pollUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $pollResponse = curl_exec($ch);
    $pollHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $pollError = curl_error($ch);
    curl_close($ch);

    if ($pollError) {
        logVideoDebug("轮询 #$attempt 网络错误: $pollError");
        continue;
    }

    $pollResult = json_decode($pollResponse, true);
    if (!$pollResult) {
        logVideoDebug("轮询 #$attempt JSON解析失败: $pollResponse");
        continue;
    }

    $status = $pollResult['task_status'] ?? ($pollResult['status'] ?? '');
    logVideoDebug("轮询 #$attempt: status=$status, response=" . json_encode($pollResult, JSON_UNESCAPED_UNICODE));

    switch ($status) {
        case 'SUCCESS':
            $videoResult = $pollResult['video_result'][0] ?? [];
            $videoUrl = $videoResult['url'] ?? ($pollResult['video_url'] ?? null);
            $coverUrl = $videoResult['cover_url'] ?? ($pollResult['cover_url'] ?? ($pollResult['cover_image_url'] ?? ''));

            if (!$videoUrl) {
                sendErrorAndExit('视频生成完成但未获取到视频URL');
            }

            logVideoDebug("视频生成成功: videoUrl=$videoUrl, coverUrl=$coverUrl");

            sendSSE('content', ['content' => "\n生成完毕"]);
            sendSSE('video_gen', [
                'videoUrl' => $videoUrl,
                'coverUrl' => $coverUrl
            ]);
            sendSSE('done', []);
            logVideoDebug("=== 视频生成完成 ===");
            exit;

        case 'FAIL':
            $failMsg = $pollResult['error']['message'] ?? '视频生成失败';
            logVideoDebug("视频生成失败: $failMsg");
            sendErrorAndExit($failMsg);

        case 'PROCESSING':
        case 'PENDING':
        default:
            break;
    }
}

logVideoDebug("轮询超时: 超过{$maxAttempts}次尝试");
sendErrorAndExit("视频生成超时，请稍后重试（任务ID: {$taskId}）");
