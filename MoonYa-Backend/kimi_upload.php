<?php
header('Content-Type: application/json');
require_once __DIR__ . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

function logDebug($message) {
    $logFile = __DIR__ . '/admin/logs/kimi_upload_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function kimiApiRequest($url, $apiKey, $method = 'GET', $postData = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($postData) curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'httpCode' => $httpCode, 'curlError' => $curlError];
}

$config = require_once 'config.php';
$kimiConfig = $config['kimi_upload'];
$UPLOAD_API_URL = $config['upload_api_url'];
$API_KEY = $config['api_key'];

$MAX_FILE_SIZE = $kimiConfig['max_file_size'];
$DOCUMENT_EXTENSIONS = $kimiConfig['document_extensions'];
$IMAGE_EXTENSIONS = $kimiConfig['image_extensions'];
$VIDEO_EXTENSIONS = $kimiConfig['video_extensions'];
$EXTRA_IMAGE_EXTENSIONS = $kimiConfig['extra_image_extensions'];
$IMAGE_PURPOSE = $kimiConfig['image_purpose'];
$VIDEO_PURPOSE = $kimiConfig['video_purpose'];
$MAX_VIDEO_RESOLUTION = $kimiConfig['max_video_resolution'];

$ALL_IMAGE_EXTENSIONS = array_merge($IMAGE_EXTENSIONS, $EXTRA_IMAGE_EXTENSIONS);

logDebug("=== 开始处理Kimi上传请求 ===");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = '文件上传失败';
        if (isset($_FILES['file']['error'])) {
            $errorMsg .= ' (错误代码: ' . $_FILES['file']['error'] . ')';
        }
        logDebug("错误: $errorMsg");
        echo json_encode(['success' => false, 'error' => $errorMsg]);
        exit;
    }

    $file = $_FILES['file'];
    $filePath = $file['tmp_name'];
    $fileName = $file['name'];
    $fileType = $file['type'];
    $fileSize = $file['size'];

    logDebug("收到文件: name=$fileName, type=$fileType, size=$fileSize");

    if (!file_exists($filePath)) {
        logDebug("错误: 临时文件不存在");
        echo json_encode(['success' => false, 'error' => '临时文件不存在']);
        exit;
    }

    if ($fileSize > $MAX_FILE_SIZE) {
        $maxMB = round($MAX_FILE_SIZE / (1024 * 1024));
        $fileMB = round($fileSize / (1024 * 1024), 2);
        logDebug("错误: 文件大小超限 file_size=$fileMB MB, max=$maxMB MB");
        echo json_encode(['success' => false, 'error' => "文件大小超过限制（最大{$maxMB}MB，当前{$fileMB}MB）"]);
        exit;
    }

    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $isImage = in_array($fileExt, $ALL_IMAGE_EXTENSIONS) || substr($fileType, 0, 6) === 'image/';
    $isVideo = in_array($fileExt, $VIDEO_EXTENSIONS);
    $isDocument = in_array($fileExt, $DOCUMENT_EXTENSIONS);

    if (!$isImage && !$isVideo && !$isDocument) {
        logDebug("错误: 不支持的文件类型 ext=$fileExt");
        echo json_encode(['success' => false, 'error' => '不支持的文件类型: .' . $fileExt]);
        exit;
    }

    if ($isVideo) {
        $videoInfo = getVideoResolution($filePath);
        if ($videoInfo) {
            $totalPixels = $videoInfo['width'] * $videoInfo['height'];
            if ($totalPixels > $MAX_VIDEO_RESOLUTION) {
                logDebug("错误: 视频分辨率超限 {$videoInfo['width']}x{$videoInfo['height']}");
                echo json_encode(['success' => false, 'error' => "视频分辨率超过限制（最大2048x1080，当前{$videoInfo['width']}x{$videoInfo['height']}）"]);
                exit;
            }
        }
    }

    $purpose = 'file-extract';
    $fileCategory = 'document';
    if ($isImage) {
        $purpose = $IMAGE_PURPOSE;
        $fileCategory = 'image';
    } elseif ($isVideo) {
        $purpose = $VIDEO_PURPOSE;
        $fileCategory = 'video';
    }

    logDebug("文件分类: ext=$fileExt, category=$fileCategory, purpose=$purpose");

    $ch = curl_init($UPLOAD_API_URL);
    $postFields = [
        'file' => new CURLFile($filePath, $fileType, $fileName),
        'purpose' => $purpose
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $API_KEY
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    logDebug("上传API响应: http_code=$httpCode, curl_error=$curlError");

    if ($curlError) {
        logDebug("CURL 错误: $curlError");
        echo json_encode(['success' => false, 'error' => '上传到Kimi API失败: ' . $curlError]);
        exit;
    }

    $result = json_decode($response, true);

    if ($httpCode === 200 && isset($result['id'])) {
        $fileId = $result['id'];
        logDebug("上传成功！file_id=$fileId, category=$fileCategory, purpose=$purpose");

        $responseData = [
            'success' => true,
            'file_id' => $fileId,
            'filename' => $result['filename'] ?? $fileName,
            'bytes' => $result['bytes'] ?? 0,
            'purpose' => $purpose,
            'category' => $fileCategory,
            'file_ext' => $fileExt
        ];

        if ($isDocument) {
            $contentUrl = $UPLOAD_API_URL . '/' . $fileId . '/content';
            logDebug("提取文档内容: $contentUrl");
            $contentResult = kimiApiRequest($contentUrl, $API_KEY);
            
            if ($contentResult['httpCode'] === 200 && empty($contentResult['curlError'])) {
                $fileContent = $contentResult['response'];
                $contentLength = mb_strlen($fileContent);
                logDebug("文档内容提取成功，长度: $contentLength 字符");
                $responseData['file_content'] = $fileContent;
                $responseData['content_length'] = $contentLength;
            } else {
                logDebug("文档内容提取失败: http_code={$contentResult['httpCode']}, error={$contentResult['curlError']}");
                $responseData['file_content'] = '';
                $responseData['content_extract_failed'] = true;
            }
        }

        echo json_encode($responseData);
    } else {
        $errorMsg = $result['error']['message'] ?? '上传到Kimi API失败';
        logDebug("上传失败！http_code=$httpCode, error=$errorMsg");
        echo json_encode([
            'success' => false,
            'error' => $errorMsg,
            'http_code' => $httpCode,
            'response' => $result
        ]);
    }
} else {
    logDebug("错误: 请使用POST方法请求");
    echo json_encode(['success' => false, 'error' => '请使用POST方法请求']);
}
logDebug("=== 请求处理结束 ===\n");

function getVideoResolution($filePath) {
    $ffprobe = trim(shell_exec('which ffprobe 2>/dev/null'));
    if (empty($ffprobe)) {
        $ffprobe = trim(shell_exec('where ffprobe 2>NUL'));
    }
    if (empty($ffprobe)) {
        return null;
    }
    $cmd = escapeshellcmd($ffprobe) . ' -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 ' . escapeshellarg($filePath) . ' 2>&1';
    $output = trim(shell_exec($cmd));
    if (preg_match('/^(\d+)x(\d+)$/', $output, $matches)) {
        return ['width' => intval($matches[1]), 'height' => intval($matches[2])];
    }
    return null;
}
?>
