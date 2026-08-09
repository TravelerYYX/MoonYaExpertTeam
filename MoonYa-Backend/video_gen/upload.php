<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function logUploadDebug($message) {
    $logDir = __DIR__ . '/../admin/logs/';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . 'video_upload_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

try {
    $config = require_once __DIR__ . '/../config.php';
} catch (\Throwable $e) {
    logUploadDebug("配置加载失败: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务配置错误']);
    exit;
}

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        logUploadDebug("无法创建上传目录: $uploadDir");
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '无法创建上传目录']);
        exit;
    }
}

$maxFileSize = 5 * 1024 * 1024;
$allowedExtensions = ['png', 'jpeg', 'jpg'];
$allowedMimeTypes = ['image/png', 'image/jpeg', 'image/jpg'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '请使用POST方法请求']);
    exit;
}

$imageBlob = null;
$imageExt = null;
$source = null;

if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['image'];
    $tmpPath = $file['tmp_name'];
    $fileSize = $file['size'];
    $mimeType = $file['type'];
    $origName = $file['name'];
    $source = 'multipart';

    logUploadDebug("收到文件上传: name=$origName, type=$mimeType, size=$fileSize");

    if ($fileSize > $maxFileSize) {
        logUploadDebug("文件过大: $fileSize > $maxFileSize");
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '文件大小超过5MB限制']);
        exit;
    }

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        logUploadDebug("不支持的MIME类型: $mimeType");
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '仅支持 PNG、JPEG、JPG 格式的图片']);
        exit;
    }

    $imageBlob = file_get_contents($tmpPath);
    if ($imageBlob === false) {
        logUploadDebug("读取临时文件失败: $tmpPath");
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '读取上传文件失败']);
        exit;
    }

    $imageExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($imageExt, $allowedExtensions, true)) {
        $imageExt = 'jpg';
    }
} elseif (isset($_POST['image_data']) && !empty($_POST['image_data'])) {
    $source = 'base64';
    $base64Str = $_POST['image_data'];

    if (preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $base64Str, $matches)) {
        $imageExt = $matches[1];
        if ($imageExt === 'jpeg') {
            $imageExt = 'jpg';
        }
        $base64Str = preg_replace('/^data:image\/\w+;base64,/', '', $base64Str);
    } else {
        $imageExt = 'jpg';
    }

    $imageBlob = base64_decode($base64Str, true);
    if ($imageBlob === false) {
        logUploadDebug("Base64解码失败");
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '无效的Base64图片数据']);
        exit;
    }

    logUploadDebug("收到Base64图片: ext=$imageExt, decoded_size=" . strlen($imageBlob));
} else {
    logUploadDebug("未提供图片数据");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '请提供图片文件(image)或Base64图片数据(image_data)']);
    exit;
}

if (strlen($imageBlob) > $maxFileSize) {
    logUploadDebug("Base64解码后文件过大: " . strlen($imageBlob) . " > $maxFileSize");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '图片大小超过5MB限制']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->buffer($imageBlob);
if (!in_array($detectedMime, $allowedMimeTypes, true)) {
    logUploadDebug("文件内容验证失败: detected=$detectedMime");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '图片内容验证失败，仅支持 PNG、JPEG、JPG 格式']);
    exit;
}

$uniqueName = uniqid('vid_', true) . '.' . $imageExt;
$savePath = $uploadDir . $uniqueName;

$written = file_put_contents($savePath, $imageBlob);
if ($written === false) {
    logUploadDebug("保存文件失败: $savePath");
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '保存文件失败']);
    exit;
}

$publicUrl = '/video_gen/uploads/' . $uniqueName;

logUploadDebug("上传成功: $savePath, url=$publicUrl, source=$source");

echo json_encode([
    'success' => true,
    'url' => $publicUrl,
    'filename' => $uniqueName
]);
