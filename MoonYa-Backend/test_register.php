<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "测试用户注册API...\n";
echo "====================\n\n";

$baseUrl = 'http://localhost/ai2/user_auth.php';

// 1. 测试发送验证码
echo "1. 测试发送验证码...\n";
$data = ['email' => 'test@qq.com'];
$ch = curl_init($baseUrl . '?action=send_code');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
curl_close($ch);
echo "响应: " . $response . "\n\n";

$responseData = json_decode($response, true);
$code = $responseData['data']['code'] ?? '';

if ($code) {
    // 2. 测试注册
    echo "2. 测试用户注册...\n";
    $registerData = [
        'account' => 'testuser' . time(),
        'password' => 'Test@123456',
        'name' => '测试用户',
        'email' => 'test@qq.com',
        'code' => $code
    ];
    
    $ch = curl_init($baseUrl . '?action=register');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($registerData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);
    echo "响应: " . $response . "\n\n";
}

echo "测试完成！\n";
?>