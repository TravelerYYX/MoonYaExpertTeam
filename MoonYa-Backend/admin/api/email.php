<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../Database.php';
    require_once __DIR__ . '/../Auth.php';
    require_once __DIR__ . '/../Logger.php';

    $config = require __DIR__ . '/../config.php';

    $mainConfig = require __DIR__ . '/../../config.php';
    if (is_array($mainConfig)) {
        $config = array_merge($config, $mainConfig);
    }

    $db = new Database($config);
    $conn = $db->getConnection();

    $logger = new Logger($config, $conn);
    $auth = new Auth($conn, $config, $logger);

    $headers = getallheaders();
    $token = null;
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
    } elseif (isset($headers['authorization'])) {
        $token = str_replace('Bearer ', '', $headers['authorization']);
    }

    $currentAdmin = $auth->authenticate($token);
    if (!$currentAdmin) {
        sendError(401, '未授权访问');
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    if ($method === 'GET' && $action === 'users') {
        getEmailUsers($conn);
    } elseif ($method === 'POST' && $action === 'send') {
        sendEmail($conn, $config);
    } else {
        sendError(400, '无效的操作');
    }
} catch (Throwable $e) {
    sendError(500, $e->getMessage());
}

function getEmailUsers($conn)
{
    $stmt = $conn->prepare("SELECT id, username, email, real_name FROM users WHERE status != 'deleted' AND email IS NOT NULL AND email != '' ORDER BY id ASC");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    sendSuccess($users);
}

function sendEmail($conn, $config)
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) sendError(400, '请求数据格式错误');

    $subject = trim($input['subject'] ?? '');
    $content = trim($input['content'] ?? '');
    $format = $input['format'] ?? 'text';
    $recipientType = $input['recipient_type'] ?? 'all';
    $recipientIds = $input['recipient_ids'] ?? [];

    if (empty($subject)) sendError(400, '请输入邮件主题');
    if (empty($content)) sendError(400, '请输入邮件内容');
    if (!in_array($format, ['text', 'html'])) sendError(400, '无效的邮件格式');

    $smtpHost = $config['smtp_host'] ?? '';
    $smtpPort = intval($config['smtp_port'] ?? 587);
    $smtpUser = $config['smtp_user'] ?? '';
    $smtpPass = $config['smtp_pass'] ?? '';
    $fromName = $config['email_from_name'] ?? 'MoonYa';

    if (empty($smtpHost) || empty($smtpUser)) {
        sendError(500, 'SMTP 配置不完整，请在配置文件中设置 smtp_host 和 smtp_user');
    }

    if ($recipientType === 'all') {
        $stmt = $conn->prepare("SELECT id, username, email, real_name FROM users WHERE status != 'deleted' AND email IS NOT NULL AND email != ''");
        $stmt->execute();
    } elseif ($recipientType === 'selected' && !empty($recipientIds)) {
        $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
        $stmt = $conn->prepare("SELECT id, username, email, real_name FROM users WHERE status != 'deleted' AND email IS NOT NULL AND email != '' AND id IN ({$placeholders})");
        $stmt->execute($recipientIds);
    } else {
        sendError(400, '请选择收件人');
    }

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($users)) sendError(400, '未找到有效的收件人');

    $successCount = 0;
    $failCount = 0;
    $errors = [];

    foreach ($users as $user) {
        $email = $user['email'];
        $name = $user['real_name'] ?: $user['username'];

        $result = sendSingleEmail($smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromName, $email, $name, $subject, $content, $format);

        if ($result === true) {
            $successCount++;
        } else {
            $failCount++;
            $errors[] = "{$name} <{$email}>: {$result}";
        }
    }

    sendSuccess([
        'total' => count($users),
        'success' => $successCount,
        'fail' => $failCount,
        'errors' => $errors
    ]);
}

function sendSingleEmail($host, $port, $user, $pass, $fromName, $toEmail, $toName, $subject, $body, $format)
{
    $eol = "\r\n";

    $socket = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$socket) {
        return "连接失败: {$errstr} ({$errno})";
    }

    fread($socket, 512);

    fwrite($socket, "EHLO localhost{$eol}");
    fread($socket, 512);

    fwrite($socket, "STARTTLS{$eol}");
    fread($socket, 512);

    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($socket);
        return "TLS 握手失败";
    }

    fwrite($socket, "EHLO localhost{$eol}");
    fread($socket, 512);

    fwrite($socket, "AUTH LOGIN{$eol}");
    fread($socket, 512);

    fwrite($socket, base64_encode($user) . $eol);
    fread($socket, 512);

    fwrite($socket, base64_encode($pass) . $eol);
    $authResp = fread($socket, 512);
    if (strpos($authResp, '235') !== 0) {
        fclose($socket);
        return "SMTP 认证失败";
    }

    fwrite($socket, "MAIL FROM:<{$user}>{$eol}");
    fread($socket, 512);

    fwrite($socket, "RCPT TO:<{$toEmail}>{$eol}");
    $rcptResp = fread($socket, 512);
    if (strpos($rcptResp, '250') !== 0 && strpos($rcptResp, '251') !== 0) {
        fclose($socket);
        return "收件人无效: {$toEmail}";
    }

    fwrite($socket, "DATA{$eol}");
    fread($socket, 512);

    $contentType = $format === 'html' ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8';

    $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$user}>{$eol}";
    $headers .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$toEmail}>{$eol}";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?={$eol}";
    $headers .= "MIME-Version: 1.0{$eol}";
    $headers .= "Content-Type: {$contentType}{$eol}";
    $headers .= "Content-Transfer-Encoding: base64{$eol}";
    $headers .= $eol;

    $encodedBody = chunk_split(base64_encode($body));

    fwrite($socket, $headers . $encodedBody . $eol . ".{$eol}");
    $dataResp = fread($socket, 512);

    fwrite($socket, "QUIT{$eol}");
    fclose($socket);

    if (strpos($dataResp, '250') !== 0) {
        return "发送失败: {$dataResp}";
    }

    return true;
}

function sendSuccess($data)
{
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($code, $message)
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
