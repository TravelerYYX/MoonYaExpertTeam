<?php
header('Content-Type: application/json');
require_once __DIR__ . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

function logDebug($message) {
    $logFile = __DIR__ . '/admin/logs/deepseek_upload_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

$config = require_once 'config.php';
$uploadConfig = $config['deepseek_upload'];
$MAX_FILE_SIZE = $uploadConfig['max_file_size'];
$ALLOWED_TYPES = $uploadConfig['allowed_types'];
$ALLOWED_EXTENSIONS = $uploadConfig['allowed_extensions'];

logDebug("=== 开始处理DeepSeek上传请求 ===");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    logDebug("收到文件: name=$fileName, type=$fileType, size=$fileSize");

    if (!file_exists($filePath)) {
        logDebug("错误: 临时文件不存在");
        echo json_encode(array('success' => false, 'error' => '临时文件不存在'));
        exit;
    }

    if ($fileSize > $MAX_FILE_SIZE) {
        $maxMB = round($MAX_FILE_SIZE / (1024 * 1024));
        $fileMB = round($fileSize / (1024 * 1024), 2);
        logDebug("错误: 文件大小超限 file_size=$fileMB MB, max=$maxMB MB");
        echo json_encode(array('success' => false, 'error' => "文件大小超过限制（最大{$maxMB}MB，当前{$fileMB}MB）"));
        exit;
    }

    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $typeAllowed = in_array($fileType, $ALLOWED_TYPES);
    $extAllowed = in_array($fileExt, $ALLOWED_EXTENSIONS);

    if (!$typeAllowed && !$extAllowed) {
        logDebug("错误: 不支持的文件类型 type=$fileType, ext=$fileExt");
        echo json_encode(array('success' => false, 'error' => '不支持的文件类型，仅支持图片（JPG、PNG等）和PDF文件'));
        exit;
    }

    $isPDF = ($fileType === 'application/pdf' || $fileExt === 'pdf');
    logDebug("文件类型判断: isPDF=" . ($isPDF ? 'true' : 'false'));

    $fileId = 'ds_' . uniqid() . '_' . time();

    if ($isPDF) {
        $pdfText = extractPdfText($filePath);
        if ($pdfText !== false && mb_strlen(trim($pdfText)) > 0) {
            logDebug("PDF文本提取成功，长度: " . mb_strlen($pdfText) . " 字符");
            echo json_encode(array(
                'success' => true,
                'file_id' => $fileId,
                'ocr_text' => $pdfText,
                'filename' => $fileName,
                'bytes' => $fileSize,
                'is_pdf' => true,
                'is_image' => false,
                'text_length' => mb_strlen($pdfText)
            ));
        } else {
            logDebug("PDF文本提取失败或为空");
            echo json_encode(array(
                'success' => false,
                'error' => 'PDF文本提取失败，请确认PDF包含可提取的文本内容'
            ));
        }
    } else {
        $imageData = file_get_contents($filePath);
        if ($imageData === false) {
            logDebug("错误: 无法读取图片文件");
            echo json_encode(array('success' => false, 'error' => '无法读取图片文件'));
            exit;
        }

        $base64Data = base64_encode($imageData);
        $mimeType = !empty($fileType) ? $fileType : 'image/jpeg';
        $dataUri = "data:{$mimeType};base64,{$base64Data}";

        logDebug("图片转base64成功，大小: " . strlen($base64Data) . " 字符");

        echo json_encode(array(
            'success' => true,
            'file_id' => $fileId,
            'base64_data' => $dataUri,
            'filename' => $fileName,
            'bytes' => $fileSize,
            'is_pdf' => false,
            'is_image' => true,
            'mime_type' => $mimeType
        ));
    }
} else {
    logDebug("错误: 请使用POST方法请求");
    echo json_encode(array('success' => false, 'error' => '请使用POST方法请求'));
}
logDebug("=== 请求处理结束 ===\n");

function extractPdfText($filePath) {
    $output = array();
    $returnVar = 0;

    exec("pdftotext -layout " . escapeshellarg($filePath) . " - 2>&1", $output, $returnVar);

    if ($returnVar === 0 && !empty($output)) {
        $text = implode("\n", $output);
        if (mb_strlen(trim($text)) > 10) {
            return $text;
        }
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        return false;
    }

    if (preg_match_all('/\(([^)]*)\)/', $content, $matches)) {
        $text = implode(' ', $matches[1]);
        $text = preg_replace('/\\\\n/', "\n", $text);
        $text = preg_replace('/\\\\r/', "\r", $text);
        $text = preg_replace('/\\\\t/', "\t", $text);
        if (mb_strlen(trim($text)) > 10) {
            return $text;
        }
    }

    if (preg_match_all('/<([0-9A-Fa-f]+)>/i', $content, $hexMatches)) {
        $text = '';
        foreach ($hexMatches[1] as $hex) {
            $decoded = @hex2bin($hex);
            if ($decoded !== false) {
                $text .= $decoded;
            }
        }
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-16BE');
        if (mb_strlen(trim($text)) > 10) {
            return $text;
        }
    }

    return false;
}
?>
