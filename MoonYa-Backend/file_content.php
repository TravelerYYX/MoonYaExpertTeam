<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 记录调试日志
function logDebug($message) {
    $logFile = __DIR__ . '/admin/logs/file_content_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

logDebug("=== 开始处理请求 ===");
logDebug("请求 URL: " . $_SERVER['REQUEST_URI']);
logDebug("GET 参数: " . print_r($_GET, true));

session_start();

// 检查登录状态
if (!isset($_SESSION['user_id'])) {
    logDebug("错误: 用户未登录");
    logDebug("Session 数据: " . print_r($_SESSION, true));
    http_response_code(401);
    echo json_encode(['error' => '请先登录']);
    exit;
}

logDebug("用户已登录，ID: " . $_SESSION['user_id']);

// 获取 file_id
$fileId = isset($_GET['file_id']) ? $_GET['file_id'] : '';
logDebug("file_id 原始值: " . $fileId);

if (empty($fileId)) {
    logDebug("错误: file_id 为空");
    http_response_code(400);
    echo json_encode(['error' => '缺少 file_id 参数']);
    exit;
}

// 如果是 ms:// 格式，去掉前缀
if (strpos($fileId, 'ms://') === 0) {
    $fileId = substr($fileId, 5);
    logDebug("移除 ms:// 前缀后: " . $fileId);
}

// 加载配置
logDebug("正在加载配置...");
$config = require_once __DIR__ . '/config.php';
$UPLOAD_API_URL = $config['upload_api_url'];
$API_KEY = $config['api_key'];

logDebug("upload_api_url: " . $UPLOAD_API_URL);
logDebug("api_key 前10位: " . substr($API_KEY, 0, 10));

// 构建请求 URL
$requestUrl = $UPLOAD_API_URL . '/' . $fileId . '/content';
logDebug("Kimi API 请求 URL: " . $requestUrl);

// 请求 Kimi API 获取文件内容
$ch = curl_init($requestUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $API_KEY
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

logDebug("Kimi API 响应状态码: " . $httpCode);
if ($curlError) {
    logDebug("CURL 错误: " . $curlError);
}

if ($httpCode === 200) {
    logDebug("请求成功，响应大小: " . strlen($response) . " 字节");
    
    // 尝试识别文件类型
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($response);
    logDebug("检测到的 MIME 类型: " . $mimeType);
    
    // 如果识别失败，默认使用 image/jpeg
    if (!$mimeType || strpos($mimeType, 'image/') !== 0) {
        $mimeType = 'image/jpeg';
        logDebug("使用默认 MIME 类型: image/jpeg");
    }
    
    header('Content-Type: ' . $mimeType);
    header('Cache-Control: public, max-age=86400');
    echo $response;
} else {
    logDebug("请求失败，响应内容: " . substr($response, 0, 500));
    http_response_code($httpCode);
    echo json_encode(['error' => '获取文件内容失败', 'http_code' => $httpCode, 'response' => substr($response, 0, 200)]);
}

logDebug("=== 请求处理结束 ===\n");
?>
