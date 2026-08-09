<?php
/**
 * API调试测试 - 显示所有错误
 */

// 显示所有错误
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');

echo "=== API调试测试 ===\n\n";

// 步骤1: 加载配置
echo "1. 加载config.php...\n";
try {
    $config = require_once __DIR__ . '/config.php';
    echo "   ✓ 配置加载成功\n";
} catch (Exception $e) {
    echo "   ✗ 失败: " . $e->getMessage() . "\n";
    exit;
} catch (Error $e) {
    echo "   ✗ 错误: " . $e->getMessage() . "\n";
    exit;
}

// 步骤2: 连接数据库
echo "2. 连接数据库...\n";
try {
    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    echo "   ✓ 数据库连接成功\n";
} catch (PDOException $e) {
    echo "   ✗ 失败: " . $e->getMessage() . "\n";
    exit;
}

// 步骤3: 检查session
echo "3. 检查session...\n";
session_start();
if (isset($_SESSION['user_id'])) {
    echo "   ✓ Session中有user_id: " . $_SESSION['user_id'] . "\n";
} else {
    echo "   ⚠ Session中没有user_id\n";
}

// 步骤4: 检查token
echo "4. 检查Authorization头...\n";
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (isset($headers['Authorization'])) {
    echo "   ✓ 找到Authorization头: " . substr($headers['Authorization'], 0, 30) . "...\n";
} else {
    echo "   ⚠ 未找到Authorization头\n";
}

// 步骤5: 测试调用api.php
echo "\n5. 测试调用api.php...\n";

// 准备测试数据
$testData = json_encode([
    'message' => '你好',
    'model' => 'kimi',
    'deepThinking' => false
]);

// 获取当前URL
$protocol = isset($_SERVER['HTTPS']) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiUrl = "$protocol://$host/api.php";

echo "   API URL: $apiUrl\n";

// 使用cURL测试
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $testData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "   HTTP状态码: $httpCode\n";
if ($error) {
    echo "   cURL错误: $error\n";
}
echo "   响应内容:\n";
echo "   " . str_replace("\n", "\n   ", $response) . "\n";

// 解析响应
echo "\n6. 解析响应...\n";
$lines = explode("\n", $response);
foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, 'data: ') === 0) {
        $jsonStr = substr($line, 6);
        $data = json_decode($jsonStr, true);
        if ($data) {
            echo "   类型: " . ($data['type'] ?? 'unknown') . "\n";
            echo "   内容: " . ($data['content'] ?? '无') . "\n";
        }
    }
}

echo "\n=== 测试完成 ===\n";
