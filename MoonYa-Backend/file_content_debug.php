<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

session_start();

echo '<h1>file_content.php 调试信息</h1>';

// 检查登录状态
echo '<h2>1. 登录状态检查</h2>';
if (!isset($_SESSION['user_id'])) {
    echo '<p style="color:red">未登录！请先登录系统</p>';
    echo '<p>Session 数据: ' . print_r($_SESSION, true) . '</p>';
    exit;
}
echo '<p style="color:green">已登录，用户ID: ' . $_SESSION['user_id'] . '</p>';

// 获取 file_id
$fileId = isset($_GET['file_id']) ? $_GET['file_id'] : '';
echo '<h2>2. 参数检查</h2>';
echo '<p>file_id 参数: ' . htmlspecialchars($fileId) . '</p>';

if (empty($fileId)) {
    echo '<p style="color:red">错误: 缺少 file_id 参数</p>';
    exit;
}

// 如果是 ms:// 格式，去掉前缀
if (strpos($fileId, 'ms://') === 0) {
    $originalId = $fileId;
    $fileId = substr($fileId, 5);
    echo '<p>检测到 ms:// 前缀，已移除</p>';
    echo '<p>原始值: ' . htmlspecialchars($originalId) . '</p>';
    echo '<p>处理后: ' . htmlspecialchars($fileId) . '</p>';
}

// 加载配置
echo '<h2>3. 配置检查</h2>';
try {
    $config = require_once __DIR__ . '/config.php';
    echo '<p style="color:green">配置加载成功</p>';
    echo '<p>upload_api_url: ' . htmlspecialchars($config['upload_api_url']) . '</p>';
    echo '<p>api_key: ' . substr($config['api_key'], 0, 10) . '...' . substr($config['api_key'], -5) . '</p>';
} catch (Exception $e) {
    echo '<p style="color:red">配置加载失败: ' . $e->getMessage() . '</p>';
    exit;
}

$UPLOAD_API_URL = $config['upload_api_url'];
$API_KEY = $config['api_key'];

// 构建请求 URL
$requestUrl = $UPLOAD_API_URL . '/' . $fileId . '/content';
echo '<h2>4. API 请求</h2>';
echo '<p>请求 URL: ' . htmlspecialchars($requestUrl) . '</p>';

// 请求 Kimi API 获取文件内容
$ch = curl_init($requestUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $API_KEY
]);

echo '<p>正在发送请求...</p>';
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo '<h2>5. 响应结果</h2>';
echo '<p>HTTP 状态码: ' . $httpCode . '</p>';

if ($curlError) {
    echo '<p style="color:red">CURL 错误: ' . htmlspecialchars($curlError) . '</p>';
}

if ($httpCode === 200) {
    echo '<p style="color:green">请求成功！</p>';
    echo '<p>响应大小: ' . strlen($response) . ' 字节</p>';
    
    // 尝试识别文件类型
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($response);
    echo '<p>检测到的 MIME 类型: ' . htmlspecialchars($mimeType) . '</p>';
    
    // 显示图片
    echo '<h2>6. 图片预览</h2>';
    $base64 = base64_encode($response);
    echo '<img src="data:' . $mimeType . ';base64,' . $base64 . '" style="max-width: 400px; border: 2px solid green;">';
} else {
    echo '<p style="color:red">请求失败！</p>';
    echo '<p>响应内容:</p>';
    echo '<pre>' . htmlspecialchars($response) . '</pre>';
}
?>
