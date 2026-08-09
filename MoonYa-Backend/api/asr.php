<?php
/**
 * 阿里云百炼 Fun-ASR 语音识别接口
 *
 * 接收前端 Push-to-Talk 上传的音频文件，调用配置的语音识别服务。
 * 模型进行语音识别，返回识别文本。
 *
 * 流程：
 *   1. 接收 multipart/form-data 上传的音频文件
 *   2. 通过 DashScope 文件上传 API 获取 file_url
 *   3. 创建转写任务（异步）
 *   4. 轮询任务状态（最长 30 秒）
 *   5. 获取转写结果并返回文本
 *
 * 配置项在 config.php 的 aliyun_asr 中定义，API-Key 从 .env 读取。
 */

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 仅接受 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['code' => -1, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 加载配置
$config = require_once __DIR__ . '/../config.php';
$asrConfig = $config['aliyun_asr'] ?? [];

// 检查 ASR 配置是否完整
if (empty($asrConfig['api_key'])) {
    http_response_code(503);
    echo json_encode(['code' => -1, 'message' => 'ASR 服务未配置：请在 .env 中设置 ALIYUN_ASR_API_KEY'], JSON_UNESCAPED_UNICODE);
    exit;
}
foreach (['model', 'upload_policy_url', 'transcription_url', 'task_url_template', 'max_wait_seconds',
          'poll_interval_microseconds', 'connect_timeout_seconds', 'request_timeout_seconds',
          'upload_timeout_seconds', 'result_timeout_seconds'] as $requiredField) {
    if (empty($asrConfig[$requiredField])) {
        http_response_code(503);
        echo json_encode(['code' => -1, 'message' => 'ASR 服务缺少必填配置字段 aliyun_asr.' . $requiredField], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
foreach (['upload_policy_url', 'transcription_url'] as $urlField) {
    if (!filter_var($asrConfig[$urlField], FILTER_VALIDATE_URL)) {
        http_response_code(503);
        echo json_encode(['code' => -1, 'message' => 'ASR 服务 URL 配置无效：aliyun_asr.' . $urlField], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!str_contains((string)$asrConfig['task_url_template'], '{task_id}')) {
    http_response_code(503);
    echo json_encode(['code' => -1, 'message' => 'ASR 服务配置 aliyun_asr.task_url_template 缺少 {task_id} 占位符'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检查音频文件
if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = '音频文件上传失败';
    if (isset($_FILES['audio'])) {
        $errorMsg .= '（错误码：' . $_FILES['audio']['error'] . '）';
    }
    echo json_encode(['code' => -1, 'message' => $errorMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

$audioFile = $_FILES['audio']['tmp_name'];
$format = $_POST['format'] ?? 'webm';
$sampleRate = intval($_POST['sample_rate'] ?? ($asrConfig['sample_rate'] ?? 16000));

// 根据格式确定文件扩展名与 MIME
$extMap = [
    'webm' => ['ext' => 'webm', 'mime' => 'audio/webm'],
    'wav'  => ['ext' => 'wav',  'mime' => 'audio/wav'],
    'pcm'  => ['ext' => 'pcm',  'mime' => 'audio/pcm'],
    'mp3'  => ['ext' => 'mp3',  'mime' => 'audio/mpeg'],
    'ogg'  => ['ext' => 'ogg',  'mime' => 'audio/ogg'],
    'm4a'  => ['ext' => 'm4a',  'mime' => 'audio/mp4'],
];
$formatInfo = $extMap[$format] ?? $extMap['webm'];
$fileExt = $formatInfo['ext'];

$apiKey = $asrConfig['api_key'];
$model = (string)$asrConfig['model'];

$startTime = microtime(true);

try {

    $models = $asrConfig['fallback_models'] ?? [$model];
    $models = array_values(array_unique($models));
    $lastError = null;
    $taskId = null;

    foreach ($models as $tryModel) {
        try {
            // ── 步骤 1：获取 DashScope 文件上传策略 ──
            $fileName = 'asr_' . uniqid() . '.' . $fileExt;
            $policyResponse = dashscopeHttpGet(
                (string)$asrConfig['upload_policy_url'],
                [
                    'action' => 'getPolicy',
                    'model' => $tryModel,
                ],
                $apiKey,
                (int)$asrConfig['connect_timeout_seconds'],
                (int)$asrConfig['request_timeout_seconds']
            );

            $policyData = $policyResponse['data'] ?? null;
            if (empty($policyData['upload_host']) || empty($policyData['upload_dir'])) {
                throw new RuntimeException('获取上传策略失败：' . json_encode($policyResponse, JSON_UNESCAPED_UNICODE));
            }

            // ── 步骤 2：上传音频文件到 DashScope 临时 OSS ──
            $fileUrl = dashscopeUploadToOss(
                $policyData,
                $audioFile,
                $fileName,
                $formatInfo['mime'],
                (int)$asrConfig['connect_timeout_seconds'],
                (int)$asrConfig['upload_timeout_seconds']
            );
            error_log('[ASR] 音频文件已上传：' . $fileUrl . '，大小：' . filesize($audioFile) . ' bytes，格式：' . $format . '，模型：' . $tryModel);

            // ── 步骤 3：创建转写任务 ──
            $taskPayload = [
                'model' => $tryModel,
                'input' => [
                    'file_urls' => [$fileUrl],
                ],
                'parameters' => [
                    'language_hints' => $asrConfig['language_hints'] ?? ['zh', 'en'],
                ],
            ];

            $taskResponse = dashscopeHttpPost(
                (string)$asrConfig['transcription_url'],
                $taskPayload,
                $apiKey,
                (int)$asrConfig['connect_timeout_seconds'],
                (int)$asrConfig['request_timeout_seconds']
            );

            $taskId = $taskResponse['output']['task_id'] ?? '';
            if (empty($taskId)) {
                throw new RuntimeException('创建转写任务失败：' . json_encode($taskResponse, JSON_UNESCAPED_UNICODE));
            }
            error_log('[ASR] 转写任务已创建：' . $taskId . '，模型：' . $tryModel);

            // 成功创建任务，跳出 fallback 循环
            break;
        } catch (Exception $e) {
            $lastError = $e;
            $msg = $e->getMessage();
            if (strpos($msg, 'ModelAccessDenied') !== false || strpos($msg, 'Model access denied') !== false || strpos($msg, 'AccessDenied') !== false) {
                error_log('[ASR] 模型 ' . $tryModel . ' 访问被拒绝，尝试下一个模型');
                continue;
            }
            throw $e;
        }
    }

    if (empty($taskId) && $lastError !== null) {
        throw $lastError;
    }

    // ── 步骤 4：轮询任务状态（最长 30 秒） ──
    $maxWaitSeconds = (int)$asrConfig['max_wait_seconds'];
    $pollIntervalMs = (int)$asrConfig['poll_interval_microseconds'];
    $deadline = time() + $maxWaitSeconds;
    $taskResult = null;

    while (time() < $deadline) {
        usleep($pollIntervalMs);
        $taskResult = dashscopeHttpGet(
            str_replace('{task_id}', rawurlencode($taskId), (string)$asrConfig['task_url_template']),
            [],
            $apiKey,
            (int)$asrConfig['connect_timeout_seconds'],
            (int)$asrConfig['request_timeout_seconds']
        );

        $status = $taskResult['output']['task_status'] ?? 'UNKNOWN';
        if ($status === 'SUCCEEDED' || $status === 'FAILED' || $status === 'CANCELED') {
            break;
        }
    }

    if ($taskResult === null) {
        throw new RuntimeException('转写任务超时（30 秒内未返回结果）');
    }

    $finalStatus = $taskResult['output']['task_status'] ?? 'UNKNOWN';
    if ($finalStatus !== 'SUCCEEDED') {
        $msg = $taskResult['output']['message'] ?? '转写任务未成功';
        throw new RuntimeException('转写任务失败：' . $msg . '（状态：' . $finalStatus . '）');
    }

    // ── 步骤 5：获取转写结果文本 ──
    $results = $taskResult['output']['results'] ?? [];
    if (empty($results)) {
        // 结果可能需要从 transcription_url 获取
        $transcriptionUrl = $taskResult['output']['transcription_url'] ?? '';
        if (!empty($transcriptionUrl)) {
            $transcriptionJson = httpGetRaw($transcriptionUrl, (int)$asrConfig['connect_timeout_seconds'], (int)$asrConfig['result_timeout_seconds']);
            $transcriptionData = json_decode($transcriptionJson, true);
            if (!empty($transcriptionData['transcripts'][0]['text'])) {
                $text = trim($transcriptionData['transcripts'][0]['text']);
                echo json_encode([
                    'code' => 0,
                    'text' => $text,
                    'duration_ms' => intval((microtime(true) - $startTime) * 1000),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        // 空结果视为识别到空文本
        echo json_encode([
            'code' => 0,
            'text' => '',
            'duration_ms' => intval((microtime(true) - $startTime) * 1000),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 从 results 数组提取文本：必须 subtask_status === 'SUCCEEDED' 才读取 transcription_url
    $text = '';
    foreach ($results as $item) {
        $subtaskStatus = $item['subtask_status'] ?? '';
        if ($subtaskStatus !== 'SUCCEEDED') {
            error_log('[ASR] 子任务未成功：' . json_encode($item, JSON_UNESCAPED_UNICODE));
            continue;
        }
        if (!empty($item['text'])) {
            $text .= $item['text'];
        } elseif (!empty($item['transcription_url'])) {
            $transcriptionJson = httpGetRaw($item['transcription_url'], (int)$asrConfig['connect_timeout_seconds'], (int)$asrConfig['result_timeout_seconds']);
            $transcriptionData = json_decode($transcriptionJson, true);
            if (!empty($transcriptionData['transcripts'][0]['text'])) {
                $text .= $transcriptionData['transcripts'][0]['text'];
            }
        }
    }
    $text = trim($text);

    error_log('[ASR] 识别完成，文本：' . $text);

    echo json_encode([
        'code' => 0,
        'text' => $text,
        'duration_ms' => intval((microtime(true) - $startTime) * 1000),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $msg = $e->getMessage();
    error_log('[ASR] 识别失败：' . $msg);

    // 对常见权限错误给出更友好的提示
    if (strpos($msg, 'ModelAccessDenied') !== false || strpos($msg, 'Model access denied') !== false) {
        $friendlyMsg = '当前 API Key 没有录音文件识别模型访问权限。';
        $friendlyMsg .= '请在服务提供方控制台开通当前配置的录音文件识别模型后重试。';
    } else {
        $friendlyMsg = '识别失败: ' . $msg;
    }

    echo json_encode([
        'code' => -1,
        'message' => $friendlyMsg,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * DashScope HTTP GET 请求（带 API-Key 鉴权）
 */
function dashscopeHttpGet(string $url, array $query, string $apiKey, int $connectTimeoutSeconds, int $timeoutSeconds): array
{
    if (!empty($query)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'X-DashScope-OssResourceResolve: enable',
        ],
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException('HTTP GET 请求失败：' . $error);
    }
    if ($httpCode >= 400) {
        throw new RuntimeException('HTTP GET 返回错误码 ' . $httpCode . '：' . substr($response, 0, 500));
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('HTTP GET 响应解析失败：' . substr($response, 0, 200));
    }
    return $data;
}

/**
 * DashScope HTTP POST 请求（JSON body + API-Key 鉴权）
 */
function dashscopeHttpPost(string $url, array $body, string $apiKey, int $connectTimeoutSeconds, int $timeoutSeconds): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'X-DashScope-Async: enable',
            'X-DashScope-OssResourceResolve: enable',
        ],
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException('HTTP POST 请求失败：' . $error);
    }
    if ($httpCode >= 400) {
        throw new RuntimeException('HTTP POST 返回错误码 ' . $httpCode . '：' . substr($response, 0, 500));
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('HTTP POST 响应解析失败：' . substr($response, 0, 200));
    }
    return $data;
}

/**
 * 使用 OSS POST 表单上传文件到 DashScope 临时存储
 *
 * @return string 返回 oss:// 开头的临时文件 URL
 */
function dashscopeUploadToOss(
    array $policyData,
    string $filePath,
    string $fileName,
    string $mimeType,
    int $connectTimeoutSeconds,
    int $timeoutSeconds
): string
{
    $uploadHost = $policyData['upload_host'] ?? '';
    $uploadDir = $policyData['upload_dir'] ?? '';
    $ossAccessKeyId = $policyData['oss_access_key_id'] ?? '';
    $signature = $policyData['signature'] ?? '';
    $policy = $policyData['policy'] ?? '';
    $xOssObjectAcl = $policyData['x_oss_object_acl'] ?? '';
    $xOssForbidOverwrite = $policyData['x_oss_forbid_overwrite'] ?? '';

    if (empty($uploadHost) || empty($uploadDir)) {
        throw new RuntimeException('上传策略缺少 upload_host 或 upload_dir');
    }
    if (!file_exists($filePath)) {
        throw new RuntimeException('待上传文件不存在：' . $filePath);
    }

    $key = rtrim($uploadDir, '/') . '/' . $fileName;

    $postFields = [
        'OSSAccessKeyId' => $ossAccessKeyId,
        'Signature' => $signature,
        'policy' => $policy,
        'x-oss-object-acl' => $xOssObjectAcl,
        'x-oss-forbid-overwrite' => $xOssForbidOverwrite,
        'key' => $key,
        'success_action_status' => '200',
        'file' => new CURLFile($filePath, $mimeType, $fileName),
    ];

    $ch = curl_init($uploadHost);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException('上传文件到 OSS 失败：' . $error);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('上传文件到 OSS 返回错误码 ' . $httpCode . '：' . substr($response, 0, 500));
    }

    return 'oss://' . $key;
}

/**
 * 原始 HTTP GET（无鉴权，用于获取转写结果 JSON）
 */
function httpGetRaw(string $url, int $connectTimeoutSeconds, int $timeoutSeconds): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException('获取转写结果失败：' . $error);
    }
    return $response ?: '';
}
