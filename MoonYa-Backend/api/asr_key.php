<?php
/**
 * ASR 配置接口 - 供 C# 启动器获取 API Key 和模型配置
 * 仅返回实时识别所需的配置，不暴露其他敏感信息
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/Services/CorsPolicy.php';
applyCorsPolicy();

$config = require_once __DIR__ . '/../config.php';
$asrConfig = $config['aliyun_asr'] ?? [];

$required = ['api_key', 'model', 'fallback_models', 'realtime_models', 'websocket_url'];
foreach ($required as $field) {
    $value = $asrConfig[$field] ?? null;
    if ($value === null || $value === '' || (is_array($value) && $value === [])) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error_code' => 'missing_config',
            'error' => "Missing required configuration: aliyun_asr.{$field}",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$fallbackModels = $asrConfig['fallback_models'];
$fallbackModels = array_values(array_unique(array_map('strval', $fallbackModels)));

$realtimeModels = array_values(array_unique(array_map('strval', $asrConfig['realtime_models'])));
$allModels = array_merge($fallbackModels, $realtimeModels);
$allModels = array_values(array_unique($allModels));

echo json_encode([
    'success'         => true,
    'api_key'         => $asrConfig['api_key'],
    'model'           => $asrConfig['model'],
    'fallback_models' => $allModels,
    'websocket_url'   => $asrConfig['websocket_url'],
    'region'          => $asrConfig['region'] ?? '',
], JSON_UNESCAPED_UNICODE);
