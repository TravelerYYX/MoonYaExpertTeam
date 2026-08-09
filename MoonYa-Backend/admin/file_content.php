<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();

// 检查登录状态
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

// 获取 file_id
$fileId = isset($_GET['file_id']) ? $_GET['file_id'] : '';
if (empty($fileId)) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 file_id 参数']);
    exit;
}

// 如果是 ms:// 格式，去掉前缀
if (strpos($fileId, 'ms://') === 0) {
    $fileId = substr($fileId, 5);
}

// 加载配置
$config = require_once __DIR__ . '/config.php';
$UPLOAD_API_URL = $config['upload_api_url'];
$API_KEY = $config['api_key'];

// 请求 Kimi API 获取文件内容
$ch = curl_init($UPLOAD_API_URL . '/' . $fileId . '/content');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $API_KEY
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    // 尝试识别文件类型并设置正确的 Content-Type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($response);
    
    // 如果识别失败，默认使用 image/jpeg
    if (!$mimeType || strpos($mimeType, 'image/') !== 0) {
        $mimeType = 'image/jpeg';
    }
    
    header('Content-Type: ' . $mimeType);
    header('Cache-Control: public, max-age=86400');
    echo $response;
} else {
    http_response_code($httpCode);
    echo json_encode(['error' => '获取文件内容失败', 'http_code' => $httpCode]);
}
?>
