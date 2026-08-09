<?php
/**
 * ASR 诊断脚本
 * 直接测试阿里云百炼录音文件识别 API，不依赖任何前端逻辑。
 * 访问方式：浏览器直接打开 /api/asr_test.php
 */
header('Content-Type: text/html; charset=utf-8');
echo '<h1>ASR 诊断工具</h1>';

// 加载配置
$config = require_once __DIR__ . '/../config.php';
$asrConfig = $config['aliyun_asr'] ?? [];
$apiKey = $asrConfig['api_key'] ?? '';
$region = $asrConfig['region'] ?? 'cn-beijing';

echo '<h2>配置信息</h2>';
echo '<p>API Key: <code>' . substr($apiKey, 0, 10) . '...' . substr($apiKey, -5) . '</code></p>';
echo '<p>Key 前缀: <code>' . substr($apiKey, 0, 6) . '</code></p>';
echo '<p>地域: <code>' . htmlspecialchars($region) . '</code></p>';

if (empty($apiKey)) {
    echo '<p style="color:red">API Key 为空！请在 .env 中配置 ALIYUN_ASR_API_KEY</p>';
    exit;
}

// 测试的模型列表
$testModels = [
    'fun-asr',
    'fun-asr-mtl',
    'paraformer-v2',
    'paraformer-v1',
    'qwen3-asr-flash-filetrans',
];

echo '<h2>模型权限测试</h2>';
echo '<p>依次测试各模型是否有访问权限...</p>';

foreach ($testModels as $model) {
    echo '<hr><h3>测试模型: <code>' . htmlspecialchars($model) . '</code></h3>';

    // 测试 getPolicy
    echo '<p><strong>步骤 1: 获取上传策略 (getPolicy)</strong></p>';
    $url = 'https://dashscope.aliyuncs.com/api/v1/uploads?action=getPolicy&model=' . urlencode($model);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo '<p style="color:red">❌ 请求失败: ' . htmlspecialchars($error) . '</p>';
        continue;
    }
    if ($httpCode >= 400) {
        echo '<p style="color:red">❌ HTTP ' . $httpCode . ': ' . htmlspecialchars(substr($response, 0, 300)) . '</p>';
        continue;
    }
    $data = json_decode($response, true);
    if (!empty($data['data']['upload_host'])) {
        echo '<p style="color:green">✅ 上传策略获取成功</p>';
    } else {
        echo '<p style="color:orange">⚠️ 响应异常: ' . htmlspecialchars(substr($response, 0, 300)) . '</p>';
        continue;
    }

    // 测试创建转写任务（使用示例音频 URL，而非上传本地文件）
    echo '<p><strong>步骤 2: 创建转写任务</strong></p>';

    // 使用百炼官方示例音频（公网可访问）
    $sampleAudioUrl = 'https://dashscope.oss-cn-beijing.aliyuncs.com/samples/audio/paraformer/hello_world_female2.wav';

    $taskPayload = [
        'model' => $model,
        'input' => [
            'file_urls' => [$sampleAudioUrl],
        ],
        'parameters' => [
            'language_hints' => ['zh', 'en'],
        ],
    ];

    $ch = curl_init('https://dashscope.aliyuncs.com/api/v1/services/audio/asr/transcription');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($taskPayload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'X-DashScope-Async: enable',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo '<p style="color:red">❌ 请求失败: ' . htmlspecialchars($error) . '</p>';
        continue;
    }

    $result = json_decode($response, true);
    $taskId = $result['output']['task_id'] ?? '';

    if ($httpCode >= 400) {
        $msg = $result['message'] ?? $result['code'] ?? '未知错误';
        echo '<p style="color:red">❌ HTTP ' . $httpCode . ': ' . htmlspecialchars(json_encode($result, JSON_UNESCAPED_UNICODE)) . '</p>';
        continue;
    }

    if (!empty($taskId)) {
        echo '<p style="color:green">✅ 转写任务创建成功！TaskID: ' . htmlspecialchars($taskId) . '</p>';
        echo '<p style="color:green;font-weight:bold">🎉 模型 <code>' . htmlspecialchars($model) . '</code> 可用！</p>';
        break; // 找到可用模型，不再测试
    } else {
        echo '<p style="color:orange">⚠️ 响应异常: ' . htmlspecialchars(substr($response, 0, 300)) . '</p>';
    }
}

echo '<hr><h2>建议</h2>';
echo '<ol>';
echo '<li>如果所有模型都返回 ModelAccessDenied，说明 Key 确实没有录音文件识别权限。</li>';
echo '<li>请重新创建 API Key，在弹窗中 <strong>权限必须选"全部"</strong>（不要选自定义）。</li>';
echo '<li>如果已经是"全部"，试试在 <a href="https://bailian.console.aliyun.com/cn-beijing?tab=model#/api-key" target="_blank">API Key 管理</a> 删除旧 Key 重新创建。</li>';
echo '<li>新 Key 更新到 .env 的 ALIYUN_ASR_API_KEY 后，刷新本页重新测试。</li>';
echo '</ol>';
echo '<p><a href="?" onclick="location.reload()">重新测试</a></p>';
