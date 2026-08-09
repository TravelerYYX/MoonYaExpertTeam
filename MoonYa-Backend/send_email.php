<?php
header('Content-Type: application/json');
require_once __DIR__ . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // 加载配置
    $config = require __DIR__ . '/config.php';
    
    if (!$config || !is_array($config)) {
        echo json_encode(['success' => false, 'message' => '配置加载失败']);
        exit;
    }
    
    if (!function_exists('curl_init')) {
        echo json_encode(['success' => false, 'message' => '服务器缺少 cURL 扩展']);
        exit;
    }
    
    // 邮箱配置
    $smtp_host = $config['smtp_host'];
    $smtp_port = $config['smtp_port'];
    $smtp_user = $config['smtp_user'];
    $smtp_pass = $config['smtp_pass'];
    $email_from_name = $config['email_from_name'];
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '请使用POST方法']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['email']) || empty($data['email'])) {
        echo json_encode(['success' => false, 'message' => '请输入邮箱']);
        exit;
    }
    
    $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
    if (!$email) {
        echo json_encode(['success' => false, 'message' => '邮箱格式不正确']);
        exit;
    }
    
    // 生成6位验证码
    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // 存储验证码到session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['verification_code'] = $code;
    $_SESSION['verification_email'] = $email;
    $_SESSION['verification_time'] = time();
    
    // 准备邮件内容
    $subject = 'MoonYa Agent';
    $message = "您好！\n\n您的验证码是：{$code}\n\n请在5分钟内使用此验证码。\n\n此邮件由系统自动发送，请勿回复。";
    
    // 引入SMTP类并发送邮件
    require_once __DIR__ . '/SimpleSMTP.php';
    
    $smtp = new SimpleSMTP($smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_user, $email_from_name);
    $smtp->send($email, $subject, $message);
    
    echo json_encode([
        'success' => true, 
        'message' => '验证码已发送，请查看您的邮箱'
    ]);
    exit;
    
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false, 
        'message' => '发送失败：' . $e->getMessage()
    ]);
    exit;
}
?>
