<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/Services/ConversationTaskState.php';

$config = require __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'],
    $config['db_pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$userOption = getopt('', ['user::']);
$requestedUser = max(0, (int)($userOption['user'] ?? 0));
$userIds = $requestedUser > 0
    ? [$requestedUser]
    : array_map('intval', $pdo->query('SELECT DISTINCT user_id FROM conversation_task_state')->fetchAll(PDO::FETCH_COLUMN));
$state = new ConversationTaskState($pdo);
$closed = 0;
foreach ($userIds as $userId) {
    $closed += $state->reconcileStale($userId);
}
fwrite(STDOUT, "[PASS] reconciled {$closed} stale conversation task(s).\n");
