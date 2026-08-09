<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

echo '<h1>Kimi API 文件访问测试</h1>';

session_start();

if (!isset($_SESSION['user_id'])) {
    echo '<p style="color:red">请先登录</p>';
    exit;
}

$config = require_once __DIR__ . '/config.php';
$API_KEY = $config['api_key'];

// 测试用的 file_id（从日志中获取）
$testFileId = isset($_GET['file_id']) ? $_GET['file_id'] : 'f99skf8kj79i11gm1xhi';

echo '<h2>测试文件 ID: ' . htmlspecialchars($testFileId) . '</h2>';

// 测试 1: 尝试获取文件信息
echo '<h3>测试 1: 获取文件信息</h3>';
$ch = curl_init('https://api.moonshot.cn/v1/files/' . $testFileId);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $API_KEY
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo '<p>HTTP 状态码: ' . $httpCode . '</p>';
echo '<pre>' . htmlspecialchars($response) . '</pre>';

// 测试 2: 尝试获取文件内容
echo '<h3>测试 2: 获取文件内容</h3>';
$ch = curl_init('https://api.moonshot.cn/v1/files/' . $testFileId . '/content');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $API_KEY
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo '<p>HTTP 状态码: ' . $httpCode . '</p>';
if ($httpCode === 200) {
    echo '<p style="color:green">成功获取内容！大小: ' . strlen($response) . ' 字节</p>';
    // 尝试显示图片
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($response);
    echo '<p>MIME 类型: ' . htmlspecialchars($mimeType) . '</p>';
    $base64 = base64_encode($response);
    echo '<img src="data:' . $mimeType . ';base64,' . $base64 . '" style="max-width: 400px; border: 2px solid green;">';
} else {
    echo '<p style="color:red">获取失败</p>';
    echo '<pre>' . htmlspecialchars($response) . '</pre>';
}

echo '<hr>';
echo '<p>你可以通过 URL 参数指定其他 file_id 进行测试:</p>';
echo '<p>例如: test_kimi_file.php?file_id=你的file_id</p>';
?>
