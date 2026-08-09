<?php
/**
 * MinMax语音合成 API 接口
 * 接收文本，返回语音合成的音频数据
 */

// 设置响应头
header('Content-Type: application/json');
require_once dirname(__DIR__) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// 加载配置
$config = require_once __DIR__ . '/../config.php';

// 获取请求数据
$input = json_decode(file_get_contents('php://input'), true);
$text = $input['text'] ?? '';

if (empty($text)) {
    http_response_code(400);
    echo json_encode(['error' => '文本不能为空']);
    exit;
}

// 获取MinMax TTS配置
$voiceConfig = $config['voice_config']['minimax'] ?? [];
foreach (['api_key', 'api_url', 'voice_id', 'model', 'connect_timeout_seconds', 'timeout_seconds'] as $requiredField) {
    if (empty($voiceConfig[$requiredField])) {
        http_response_code(503);
        echo json_encode(['error' => 'MinMax TTS 缺少必填配置字段 voice_config.minimax.' . $requiredField]);
        exit;
    }
}
if (!filter_var($voiceConfig['api_url'], FILTER_VALIDATE_URL)) {
    http_response_code(503);
    echo json_encode(['error' => 'MinMax TTS 配置字段 voice_config.minimax.api_url 无效']);
    exit;
}

$apiKey = $voiceConfig['api_key'];
$voiceId = $voiceConfig['voice_id'];
$model = (string)$voiceConfig['model'];

$apiUrl = (string)$voiceConfig['api_url'];
$groupId = $voiceConfig['group_id'] ?? '';
if (!empty($groupId)) {
    $apiUrl .= (str_contains($apiUrl, '?') ? '&' : '?') . http_build_query(['GroupId' => $groupId]);
}

// 构建请求参数 - 根据 MinMax T2A API 文档
$postData = [
    'model' => $model,
    'text' => $text,
    'voice_setting' => [
        'voice_id' => $voiceId,
    ],
    'audio_setting' => [
        'audio_format' => 'mp3',
        'sample_rate' => 32000,
    ],
];

error_log('TTS Request: ' . json_encode($postData));

// 发送请求到MinMax
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$voiceConfig['connect_timeout_seconds']);
curl_setopt($ch, CURLOPT_TIMEOUT, (int)$voiceConfig['timeout_seconds']);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    error_log('CURL Error: ' . $error);
    http_response_code(500);
    echo json_encode(['error' => '请求失败: ' . $error]);
    exit;
}

error_log('TTS Response HTTP Code: ' . $httpCode);
error_log('TTS Response: ' . substr($response, 0, 1000));

if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode([
        'error' => '语音合成失败',
        'http_code' => $httpCode,
        'details' => $response
    ]);
    exit;
}

// 解析MinMax响应
$responseData = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log('JSON解析错误: ' . json_last_error_msg());
    http_response_code(500);
    echo json_encode([
        'error' => '响应解析失败',
        'details' => substr($response, 0, 200)
    ]);
    exit;
}

// 检查响应状态
if (!empty($responseData['base_resp']) && $responseData['base_resp']['status_code'] !== 0) {
    error_log('MinMax API 错误: ' . json_encode($responseData['base_resp']));
    http_response_code(500);
    echo json_encode([
        'error' => 'MinMax API 错误: ' . ($responseData['base_resp']['status_msg'] ?? '未知错误'),
        'response' => $responseData
    ]);
    exit;
}

// 提取音频数据 - MinMax T2A API 返回的音频在 data->audio 字段（hex格式）
$audioHex = null;
if (!empty($responseData['data']['audio'])) {
    $audioHex = $responseData['data']['audio'];
}

if (empty($audioHex)) {
    error_log('未找到音频数据，完整响应: ' . json_encode($responseData));
    http_response_code(500);
    echo json_encode([
        'error' => '未找到音频数据',
        'response' => $responseData,
        'debug' => [
            'keys' => array_keys($responseData),
            'full_response' => $responseData
        ]
    ]);
    exit;
}

// 将 hex 转换为二进制，再编码为 base64
$audioBinary = hex2bin($audioHex);
$audioBase64 = base64_encode($audioBinary);

// 返回音频数据
echo json_encode([
    'success' => true,
    'audio' => $audioBase64,
    'format' => 'mp3'
]);
