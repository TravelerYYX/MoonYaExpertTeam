<?php
header('Content-Type: application/json');
require_once __DIR__ . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// 记录调试日志
function logDebug($message) {
    $logFile = __DIR__ . '/admin/logs/upload_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// 加载配置
$config = require_once 'config.php';

$UPLOAD_API_URL = $config['upload_api_url'];
$API_KEY = $config['api_key'];

logDebug("=== 开始处理上传请求 ===");

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 检查是否有文件上传
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = '文件上传失败';
        if (isset($_FILES['file']['error'])) {
            $errorMsg .= ' (错误代码: ' . $_FILES['file']['error'] . ')';
        }
        logDebug("错误: $errorMsg");
        echo json_encode(array('success' => false, 'error' => $errorMsg));
        exit;
    }

    $file = $_FILES['file'];
    $filePath = $file['tmp_name'];
    $fileName = $file['name'];
    $fileType = $file['type'];
    $fileSize = $file['size'];

    logDebug("收到文件: name=$fileName, type=$fileType, size=$fileSize, tmp_path=$filePath");

    // 检查文件是否存在
    if (!file_exists($filePath)) {
        logDebug("错误: 临时文件不存在");
        echo json_encode(array('success' => false, 'error' => '临时文件不存在'));
        exit;
    }

    // 检查文件类型是否为图片 (兼容旧版PHP)
    if (substr($fileType, 0, 6) !== 'image/') {
        logDebug("错误: 只允许上传图片文件");
        echo json_encode(array('success' => false, 'error' => '只允许上传图片文件'));
        exit;
    }

    // 使用 CURL 上传到 Kimi API
    logDebug("开始上传到 Kimi API: $UPLOAD_API_URL");
    $ch = curl_init($UPLOAD_API_URL);
    
    $postFields = array(
        'file' => new CURLFile($filePath, $fileType, $fileName),
        'purpose' => 'image'
    );

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $API_KEY
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlInfo = curl_getinfo($ch);
    curl_close($ch);

    logDebug("API 响应: http_code=$httpCode, curl_error=$curlError, response=$response");

    if ($curlError) {
        logDebug("CURL 错误: $curlError");
        echo json_encode(array(
            'success' => false, 
            'error' => 'CURL 错误: ' . $curlError,
            'curl_info' => $curlInfo
        ));
        exit;
    }

    $result = json_decode($response, true);

    if ($httpCode === 200 && isset($result['id'])) {
        logDebug("上传成功！file_id=" . $result['id']);
        echo json_encode(array(
            'success' => true,
            'file_id' => $result['id'],
            'filename' => isset($result['filename']) ? $result['filename'] : $fileName,
            'bytes' => isset($result['bytes']) ? $result['bytes'] : 0,
            'debug' => array(
                'http_code' => $httpCode,
                'file_size' => $fileSize
            )
        ));
    } else {
        logDebug("上传失败！http_code=$httpCode");
        echo json_encode(array(
            'success' => false,
            'error' => isset($result['error']['message']) ? $result['error']['message'] : '上传失败',
            'response' => $result,
            'http_code' => $httpCode,
            'raw_response' => $response
        ));
    }
} else {
    logDebug("错误: 请使用POST方法请求");
    echo json_encode(array('success' => false, 'error' => '请使用POST方法请求'));
}
logDebug("=== 请求处理结束 ===\n");
?>
