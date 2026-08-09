<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/Services/TeamAuth.php';
require_once __DIR__ . '/Services/WebAttachmentService.php';

$config = require __DIR__ . '/config.php';
$action = (string)($_GET['action'] ?? 'upload');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'config') {
    $attachmentConfig = is_array($config['web_attachments'] ?? null) ? $config['web_attachments'] : [];
    echo json_encode([
        'success' => true,
        'config' => [
            'limits' => [
                'max_files' => (int)($attachmentConfig['max_files'] ?? 0),
                'max_file_size' => (int)($attachmentConfig['max_file_size'] ?? 0),
                'ttl_seconds' => (int)($attachmentConfig['ttl_seconds'] ?? 0),
            ],
            'categories' => $attachmentConfig['categories'] ?? [],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = TeamAuth::connect($config);
    $userId = TeamAuth::requireUser($pdo);
    $service = new WebAttachmentService($pdo, $config);

    // 请求触发的小批量清理；部署时还可定时调用 cleanup_web_attachments.php。
    try {
        $service->cleanupExpired(5);
    } catch (Throwable $cleanupError) {
        error_log('[Attachment] opportunistic cleanup failed: ' . $cleanupError->getMessage());
    }

    if ($action === 'delete') {
        $body = json_decode((string)file_get_contents('php://input'), true);
        $attachmentId = is_array($body) ? (string)($body['attachment_id'] ?? '') : '';
        $service->deleteOwned($userId, $attachmentId);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action !== 'upload') {
        throw new InvalidArgumentException('未知附件操作');
    }
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        throw new InvalidArgumentException('缺少上传文件');
    }

    $result = $service->upload(
        $userId,
        $_FILES['file'],
        trim((string)($_POST['batch_id'] ?? '')),
        trim((string)($_POST['relative_path'] ?? ''))
    );
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $message = $error->getMessage();
    if ($message === 'AUTH_REQUIRED') {
        http_response_code(401);
        $message = '请先登录后再上传附件';
    } elseif ($error instanceof PDOException
        && (str_contains($message, 'chat_attachments') || str_contains($message, "doesn't exist"))) {
        http_response_code(503);
        $message = '附件数据表尚未安装，请先运行 migrate_web_attachments_image_agent.php';
    } elseif ($error instanceof InvalidArgumentException) {
        http_response_code(400);
    } else {
        http_response_code(422);
    }
    error_log('[Attachment] request failed: ' . $error->getMessage());
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
