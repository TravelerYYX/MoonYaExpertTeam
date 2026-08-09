<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$backendRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$coordinator = $backendRoot . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'AIAssistant.php';
$emitter = $backendRoot . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'CuEventEmitter.php';

http_response_code(200);
echo json_encode([
    'success' => true,
    'protocol_version' => 'cu-reliability-v2',
    'backend_root' => $backendRoot,
    'build_id' => getenv('MOONYA_BUILD_ID') ?: 'local-source',
    'coordinator_sha256' => is_file($coordinator) ? hash_file('sha256', $coordinator) : null,
    'emitter_sha256' => is_file($emitter) ? hash_file('sha256', $emitter) : null,
    'coordinator_modified_utc' => is_file($coordinator)
        ? gmdate(DATE_ATOM, (int)filemtime($coordinator))
        : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
