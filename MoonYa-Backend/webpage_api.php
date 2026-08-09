<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('session.gc_maxlifetime', 1296000);
ini_set('session.cookie_lifetime', 1296000);
session_start();

try {
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
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    
    if ($method === 'POST') {
        if ($action === 'save') {
            saveWebpage($pdo);
        } else {
            sendError(400, '无效的操作');
        }
    } else {
        sendError(405, '不支持的请求方法');
    }
} catch (Exception $e) {
    sendError(500, '服务器错误: ' . $e->getMessage());
}

function saveWebpage($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['html_code'])) {
        sendError(400, 'HTML代码不能为空');
    }
    
    $htmlCode = $input['html_code'];
    $title = isset($input['title']) && !empty($input['title']) ? $input['title'] : extractTitle($htmlCode);
    $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    $previewToken = bin2hex(random_bytes(32));
    
    $stmt = $pdo->prepare("INSERT INTO webpages (user_id, title, html_code, preview_token) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $title, $htmlCode, $previewToken]);
    
    $webpageId = $pdo->lastInsertId();
    
    sendSuccess([
        'message' => '网页保存成功',
        'id' => $webpageId,
        'preview_token' => $previewToken,
        'title' => $title
    ]);
}

function extractTitle($html) {
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
        $title = trim($matches[1]);
        if (!empty($title)) {
            return mb_substr($title, 0, 500);
        }
    }
    return '未命名网页';
}

function sendSuccess($data) {
    echo json_encode([
        'success' => true,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
