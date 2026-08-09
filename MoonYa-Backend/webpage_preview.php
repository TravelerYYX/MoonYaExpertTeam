<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

$config = require_once __DIR__ . '/config.php';

$pdo = new PDO(
    "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
    $config['db_user'],
    $config['db_pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]
);

$token = isset($_GET['token']) ? $_GET['token'] : '';

if (empty($token)) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>错误</title></head><body><h1>缺少预览令牌</h1></body></html>';
    exit;
}

$stmt = $pdo->prepare("SELECT html_code FROM webpages WHERE preview_token = ?");
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>未找到</title></head><body><h1>网页不存在或已被删除</h1></body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo $row['html_code'];
