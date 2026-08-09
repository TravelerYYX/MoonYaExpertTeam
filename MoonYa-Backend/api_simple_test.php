<?php
// 极简测试 - 检查API是否能正常响应

error_reporting(E_ALL);
ini_set('display_errors', 1);

// 记录到文件而不是直接输出
$logFile = __DIR__ . '/admin/logs/api_test_' . date('Y-m-d') . '.log';
file_put_contents($logFile, "\n=== " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

// 检查是否能读取请求头
$headers = function_exists('getallheaders') ? getallheaders() : [];
file_put_contents($logFile, "Headers: " . json_encode($headers, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

// 检查Authorization - 多种方式
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '未设置(HTTP_AUTHORIZATION)';
file_put_contents($logFile, "HTTP_AUTHORIZATION: " . $auth . "\n", FILE_APPEND);

$auth2 = $_SERVER['Authorization'] ?? '未设置(Authorization)';
file_put_contents($logFile, "Authorization: " . $auth2 . "\n", FILE_APPEND);

$auth3 = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '未设置(REDIRECT_HTTP_AUTHORIZATION)';
file_put_contents($logFile, "REDIRECT_HTTP_AUTHORIZATION: " . $auth3 . "\n", FILE_APPEND);

// 检查所有SERVER变量中包含auth的
$authVars = [];
foreach ($_SERVER as $key => $value) {
    if (stripos($key, 'auth') !== false) {
        $authVars[$key] = substr($value, 0, 100);
    }
}
file_put_contents($logFile, "所有Auth相关变量: " . json_encode($authVars, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

// 返回简单的JSON响应
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => '测试完成，请查看日志: admin/logs/api_test_' . date('Y-m-d') . '.log'
]);
