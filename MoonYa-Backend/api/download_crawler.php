<?php
/**
 * MoonYa Crawler Download — 直接从 crawler_output_dir 读取 zip 文件
 * 不依赖 Python 爬虫服务在线，避免服务重启导致 404
 */

require_once __DIR__ . '/../config.php';

$user_id = $_GET['user_id'] ?? '';
$file    = $_GET['file']    ?? '';

if (empty($user_id) || empty($file)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '缺少 user_id 或 file 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 安全过滤：防止路径穿越
$user_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $user_id);
$file    = basename($file);

if (empty($user_id) || empty($file)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '参数无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 从配置读取爬虫输出目录
$outputDir = $config['crawler_output_dir'] ?? (dirname(__DIR__, 2) . '/crawler_output');
$zipPath   = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . $user_id . DIRECTORY_SEPARATOR . $file;

if (!file_exists($zipPath) || !is_file($zipPath)) {
    // 如果本地文件不存在，尝试从 Python 爬虫服务代理
    $crawlerApiUrl = rtrim(trim((string)($config['crawler_api_url'] ?? '')), '/');
    if ($crawlerApiUrl === '') {
        throw new RuntimeException('缺少必填配置字段 crawler_api_url');
    }
    $proxyUrl = $crawlerApiUrl . '/download/' . urlencode($user_id) . '/' . urlencode($file);

    $ch = curl_init($proxyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $errmsg = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => '文件未找到，且爬虫服务未运行（' . $errmsg . '）'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($httpCode === 200 && $data) {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $data;
        exit;
    }

    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    $msg = '文件未找到';
    if ($httpCode > 0 && $httpCode !== 200) {
        $msg .= "（爬虫服务返回 HTTP {$httpCode}）";
    }
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// 直接输出 zip 文件
$fileSize = filesize($zipPath);

// 清除可能从 config 引入时输出的空白字符，确保 HTTP 响应体纯净
if (ob_get_level()) ob_clean();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-cache, no-store, must-revalidate');

// 大文件流式输出，避免内存问题
if ($fileSize > 10 * 1024 * 1024) {
    $fp = fopen($zipPath, 'rb');
    while (!feof($fp)) {
        echo fread($fp, 8192);
        if (ob_get_level()) ob_flush();
        flush();
    }
    fclose($fp);
} else {
    readfile($zipPath);
}
