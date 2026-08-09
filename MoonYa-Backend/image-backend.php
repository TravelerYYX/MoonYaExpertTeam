<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

$tempFile = null;

try {
    $config = require_once __DIR__ . '/config.php';

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => '仅支持POST请求']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $imageBase64 = $input['imageBase64'] ?? '';
    $mimeType = $input['mimeType'] ?? 'image/jpeg';
    $filename = $input['filename'] ?? '图片';

    if (empty($imageBase64)) {
        echo json_encode(['success' => false, 'error' => '未提供图片数据']);
        exit;
    }

    $ocrConfig = $config['ocr'] ?? [];
    if (!($ocrConfig['enabled'] ?? true)) {
        echo json_encode(['success' => false, 'error' => '图片识别服务未启用']);
        exit;
    }

    $maxSize = $ocrConfig['max_image_size'] ?? 1 * 1024 * 1024;
    $imageData = base64_decode($imageBase64);
    if ($imageData === false) {
        echo json_encode(['success' => false, 'error' => 'Base64解码失败']);
        exit;
    }
    if (strlen($imageData) > $maxSize) {
        echo json_encode(['success' => false, 'error' => '图片大小超过限制（最大1MB）']);
        exit;
    }

    $supportedFormats = $ocrConfig['supported_formats'] ?? ['pdf', 'gif', 'png', 'jpg', 'tif', 'bmp', 'jpeg'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, $supportedFormats) && !in_array(str_replace('image/', '', $mimeType), $supportedFormats)) {
        echo json_encode(['success' => false, 'error' => '不支持的图片格式']);
        exit;
    }

    if (empty($ext) || !in_array($ext, $supportedFormats)) {
        $mimeToExt = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/bmp' => 'bmp', 'image/tiff' => 'tif', 'application/pdf' => 'pdf',
        ];
        $ext = $mimeToExt[$mimeType] ?? 'jpg';
    }

    foreach (['api_url', 'token', 'supported_formats', 'temp_dir', 'temp_url_path'] as $field) {
        $value = $ocrConfig[$field] ?? null;
        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            throw new RuntimeException("Missing required configuration: ocr.{$field}");
        }
    }
    $apiUrl = $ocrConfig['api_url'];
    $token = $ocrConfig['token'] ?? '';
    $target = $ocrConfig['target'] ?? 'chs';
    $type = $ocrConfig['type'] ?? 'json';
    $siteUrl = rtrim($ocrConfig['site_url'] ?? '', '/');
    $tempDir = $ocrConfig['temp_dir'];
    $tempUrlPath = $ocrConfig['temp_url_path'];

    $tempName = '_ocr_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $tempFile = $tempDir . $tempName;

    if (file_put_contents($tempFile, $imageData) === false) {
        echo json_encode(['success' => false, 'error' => '临时文件保存失败']);
        exit;
    }

    $imageUrl = $siteUrl . $tempUrlPath . $tempName;

    $queryParams = http_build_query([
        'token' => $token,
        'url' => $imageUrl,
        'target' => $target,
        'type' => $type,
    ]);

    $requestUrl = $apiUrl . '?' . $queryParams;

    $ch = curl_init($requestUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($tempFile && file_exists($tempFile)) {
        @unlink($tempFile);
    }

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'OCR请求失败: ' . $curlError]);
        exit;
    }

    if ($httpCode !== 200) {
        $errorDetail = '';
        $responseData = json_decode($response, true);
        if ($responseData && isset($responseData['message'])) {
            $errorDetail = is_array($responseData['message']) ? implode('; ', $responseData['message']) : $responseData['message'];
        }
        echo json_encode(['success' => false, 'error' => 'OCR服务返回错误: HTTP ' . $httpCode . ($errorDetail ? ' - ' . $errorDetail : '')]);
        exit;
    }

    $responseData = json_decode($response, true);

    if (isset($responseData['status']) && $responseData['status'] !== 'success') {
        $errorMsg = $responseData['message'] ?? '未知错误';
        if (is_array($errorMsg)) $errorMsg = implode('; ', $errorMsg);
        echo json_encode(['success' => false, 'error' => 'OCR识别失败: ' . $errorMsg]);
        exit;
    }

    $parsedText = '';
    if (isset($responseData['data']['ParsedResults'][0]['ParsedText'])) {
        $parsedText = $responseData['data']['ParsedResults'][0]['ParsedText'];
    } elseif (isset($responseData['ParsedResults'][0]['ParsedText'])) {
        $parsedText = $responseData['ParsedResults'][0]['ParsedText'];
    }

    if (empty($parsedText)) {
        echo json_encode(['success' => false, 'error' => '图片识别结果为空']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'reply' => $parsedText,
        'filename' => $filename
    ]);

} catch (Exception $e) {
    if ($tempFile && file_exists($tempFile)) {
        @unlink($tempFile);
    }
    echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
}
