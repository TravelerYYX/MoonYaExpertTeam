<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$config = require_once __DIR__ . '/config.php';
$yxVideoConfig = $config['yx_video'] ?? [];

$url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($url)) {
    echo json_encode(['code' => 400, 'msg' => '缺少 url 参数']);
    exit;
}

$allowedHosts = $yxVideoConfig['api_whitelist'] ?? [];
$apiTimeout = $yxVideoConfig['api_timeout'] ?? 10;
$apiConnectTimeout = $yxVideoConfig['api_connect_timeout'] ?? 5;

$host = parse_url($url, PHP_URL_HOST);
$allowed = false;
foreach ($allowedHosts as $h) {
    if ($host && stripos($host, $h) !== false) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
    echo json_encode(['code' => 403, 'msg' => '非法请求域名']);
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => $apiTimeout,
    CURLOPT_CONNECTTIMEOUT => $apiConnectTimeout,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => ['Accept: application/json']
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['code' => 500, 'msg' => '网络错误: ' . $curlErr]);
    exit;
}

if ($httpCode !== 200 || empty($response)) {
    echo json_encode(['code' => $httpCode, 'msg' => '上游接口异常']);
    exit;
}

echo $response;
