<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/Services/TeamAuth.php';
require_once __DIR__ . '/Services/WebAttachmentService.php';
$config = require __DIR__ . '/config.php';

try {
    $pdo = TeamAuth::connect($config);
    $service = new WebAttachmentService($pdo, $config);
    $count = $service->cleanupExpired(200);
    fwrite(STDOUT, "Cleaned {$count} expired MoonYa web attachments.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
