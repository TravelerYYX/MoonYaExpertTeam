<?php
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../Database.php';
    require_once __DIR__ . '/../Auth.php';
    require_once __DIR__ . '/../Logger.php';

    $config = require __DIR__ . '/../config.php';

    $db = new Database($config);
    $conn = $db->getConnection();

    $logger = new Logger($config, $conn);
    $auth = new Auth($conn, $config, $logger);

    $headers = getallheaders();
    $token = null;
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
    } elseif (isset($headers['authorization'])) {
        $token = str_replace('Bearer ', '', $headers['authorization']);
    }

    $currentAdmin = $auth->authenticate($token);
    if (!$currentAdmin) {
        sendError(401, '未授权访问，请先登录');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError(405, '仅支持 POST 请求');
    }

    // 参数接收
    $compress = isset($_POST['compress']) ? intval($_POST['compress']) : 1;
    $quality  = isset($_POST['quality'])  ? intval($_POST['quality'])  : 75;
    $scale    = isset($_POST['scale'])    ? intval($_POST['scale'])    : 80;

    // 参数校验
    if (!in_array($compress, [0, 1], true)) {
        sendError(400, 'compress 参数仅支持 0 或 1');
    }
    if ($quality < 1 || $quality > 100) {
        sendError(400, 'quality 参数范围为 1-100');
    }
    if ($scale < 10 || $scale > 100) {
        sendError(400, 'scale 参数范围为 10-100');
    }

    // 文件校验
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errMsg = '文件上传失败';
        if (isset($_FILES['file'])) {
            switch ($_FILES['file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errMsg = '文件大小超出服务器限制';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errMsg = '未选择文件';
                    break;
            }
        }
        sendError(400, $errMsg);
    }

    $file = $_FILES['file'];

    // MIME 类型校验
    $hasWebpSupport = function_exists('imagecreatefromwebp') && function_exists('imagewebp');
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowedMimes, true)) {
        sendError(400, '仅允许上传图片文件（JPEG, PNG, GIF, WEBP, BMP）');
    }
    // WEBP 文件但服务器不支持 WEBP GD → 强制不压缩，直接保存原图
    $forceNoCompress = false;
    if ($mimeType === 'image/webp' && !$hasWebpSupport) {
        $forceNoCompress = true;
    }

    // 文件大小校验（10MB）
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        sendError(400, '文件大小不能超过 10MB');
    }

    // 文件名防路径穿越
    $originalName = basename($file['name']);
    $originalName = str_replace(['..', '/', '\\'], '', $originalName);
    if ($originalName === '') {
        $originalName = 'upload';
    }

    // 获取原始后缀
    $originalExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($originalExt === '') {
        // 根据 MIME 类型推断后缀
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/bmp'  => 'bmp',
        ];
        $originalExt = isset($mimeToExt[$mimeType]) ? $mimeToExt[$mimeType] : 'jpg';
    }

    // 生成文件名
    $timestamp = date('YmdHis');
    $randomHex = bin2hex(random_bytes(3)); // 6位十六进制
    // WEBP文件无GD支持时直接保存原图；压缩时：有WEBP支持则转WEBP，否则转JPEG；不压缩时保留原格式
    $actualCompress = $compress && !$forceNoCompress;
    if ($actualCompress) {
        $ext = $hasWebpSupport ? 'webp' : 'jpg';
    } else {
        $ext = $originalExt;
    }
    $filename = "upload_{$timestamp}_{$randomHex}.{$ext}";

    // 上传目录
    $uploadDir = realpath(__DIR__ . '/../../uploads/new-images');
    if ($uploadDir === false) {
        sendError(500, '上传目录不存在');
    }
    $savePath = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    $sizeOriginal = $file['size'];

    if ($actualCompress) {
        // 压缩逻辑：GD 库缩放 + 转 WEBP/JPEG
        $srcImage = null;
        switch ($mimeType) {
            case 'image/jpeg':
                $srcImage = imagecreatefromjpeg($file['tmp_name']);
                break;
            case 'image/png':
                $srcImage = imagecreatefrompng($file['tmp_name']);
                break;
            case 'image/gif':
                $srcImage = imagecreatefromgif($file['tmp_name']);
                break;
            case 'image/webp':
                if ($hasWebpSupport) {
                    $srcImage = imagecreatefromwebp($file['tmp_name']);
                }
                break;
            case 'image/bmp':
                $srcImage = imagecreatefrombmp($file['tmp_name']);
                break;
        }

        if (!$srcImage) {
            sendError(500, '无法读取图片文件');
        }

        $origWidth  = imagesx($srcImage);
        $origHeight = imagesy($srcImage);
        $scaleRatio = $scale / 100;
        $newWidth   = max(1, intval($origWidth * $scaleRatio));
        $newHeight  = max(1, intval($origHeight * $scaleRatio));

        $dstImage = imagecreatetruecolor($newWidth, $newHeight);

        // 保持透明通道（PNG/GIF 来源时需要）
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
        $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
        imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $transparent);

        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        if ($hasWebpSupport) {
            $saveResult = imagewebp($dstImage, $savePath, $quality);
        } else {
            // 回退为 JPEG 压缩
            $saveResult = imagejpeg($dstImage, $savePath, $quality);
        }

        imagedestroy($srcImage);
        imagedestroy($dstImage);

        if (!$saveResult) {
            sendError(500, '图片压缩保存失败');
        }

        $sizeCompressed = filesize($savePath);
    } else {
        // 不压缩：直接保存原图
        if (!move_uploaded_file($file['tmp_name'], $savePath)) {
            sendError(500, '文件保存失败');
        }
        $sizeCompressed = $sizeOriginal;
    }

    // 返回结果
    $url = "/uploads/new-images/{$filename}";
    ob_end_clean();
    echo json_encode([
        'success'         => true,
        'url'             => $url,
        'filename'        => $filename,
        'size_original'   => $sizeOriginal,
        'size_compressed' => $sizeCompressed,
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    sendError(500, '服务器错误: ' . $e->getMessage());
}

function sendError($code, $message) {
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
