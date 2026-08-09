<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('max_execution_time', '0');
set_time_limit(0);

$config = require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Services/DownloadService.php';

$service = new DownloadService($config);

// Load logger if available
$logConfig = ['log_path' => $config['download']['log_path'] ?? (__DIR__ . '/../admin/logs/')];
if (file_exists(__DIR__ . '/../admin/DownloadLogger.php')) {
    require_once __DIR__ . '/../admin/DownloadLogger.php';
    try {
        $pdo = new PDO(
            "mysql:host=" . ($config['db_host'] ?? 'localhost') . ";dbname=" . ($config['db_name'] ?? 'ai_system') . ";charset=utf8mb4",
            $config['db_user'] ?? 'root',
            $config['db_pass'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $logger = new DownloadLogger($logConfig, $pdo);
        $service->setLogger($logger);
    } catch (Exception $e) {}
}

// Generate request ID
$requestId = uniqid('dl_', true);
$startTime = microtime(true);

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Single file download: GET /api/download.php?file=filename.pdf
        $filename = $_GET['file'] ?? '';
        if (empty($filename)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(DownloadService::errorResponse(400, '缺少必要参数', 'file 参数为必填项'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $safeName = $service->normalizePath($filename);
        
        // Check extension
        if (!$service->isExtensionAllowed($safeName)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(DownloadService::errorResponse(403, '文件类型不允许下载'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Build file path from storage
        $storagePath = $config['download']['storage_path'] ?? (__DIR__ . '/../downloads/');
        $fullPath = realpath($storagePath . $safeName);
        
        // Security: ensure path stays within storage
        $realStorage = realpath($storagePath);
        if (!$fullPath || strpos($fullPath, $realStorage) !== 0) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(DownloadService::errorResponse(403, '访问被拒绝', '路径遍历攻击检测'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Determine MIME type
        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
        
        // Stream the file
        $service->streamFile($fullPath, $safeName, $mimeType);
        
    } elseif ($method === 'POST') {
        // Batch download: POST /api/download.php with JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        $url = $input['url'] ?? '';
        $filename = $input['filename'] ?? '';
        $userRole = $input['user_role'] ?? 'guest';
        
        if (empty($url)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(DownloadService::errorResponse(400, '缺少必要参数', 'url 参数为必填项'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Extract filename
        $filename = $service->extractFilename($url, $filename);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Check permission
        if (!$service->checkPermission($ext, $userRole)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(DownloadService::errorResponse(403, '权限不足', "用户角色 {$userRole} 不允许下载此类文件"), JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Download to local
        $result = $service->downloadToLocal($url, $filename);
        
        if (!$result['success']) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($result['code'] ?? 500);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Return success with streaming
        $service->streamFile($result['local_path'], $filename, $result['file']['type']);
        
    } else {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(405);
        echo json_encode(DownloadService::errorResponse(405, '方法不允许', '仅支持 GET 和 POST 方法'), JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(DownloadService::errorResponse(500, '服务器内部错误', $e->getMessage()), JSON_UNESCAPED_UNICODE);
}
