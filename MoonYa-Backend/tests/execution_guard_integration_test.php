<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Services/ExecutionJobRepository.php';
require_once dirname(__DIR__) . '/Services/ExecutionGuard.php';
require_once dirname(__DIR__) . '/Services/ExecutionGuardOutput.php';
require_once dirname(__DIR__) . '/Services/LocalToolRequestRepository.php';

if (!function_exists('streamFlush')) {
    function streamFlush(): void {}
}

function guardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'],
    $config['db_pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$owner = $pdo->query(
    'SELECT c.user_id, c.id AS conversation_id FROM conversations c ORDER BY c.id ASC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($owner)) {
    fwrite(STDOUT, "[SKIP] execution guard integration: no conversation fixture.\n");
    exit(0);
}

$jobs = new ExecutionJobRepository($pdo);
$relays = new LocalToolRequestRepository($pdo);
guardAssert($jobs->isInstalled(), 'execution guard schema is missing');
guardAssert($relays->isInstalled(), 'local tool request schema is missing');

$userId = (int)$owner['user_id'];
$conversationId = (int)$owner['conversation_id'];
$clientId = sprintf(
    '%s-%s-%s-%s-%s',
    bin2hex(random_bytes(4)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(6))
);
$job = $jobs->enqueue(
    $userId,
    $conversationId,
    $clientId,
    'work',
    json_encode(['agent_mode' => 'agent', 'client_message_id' => $clientId], JSON_THROW_ON_ERROR)
);
$jobId = (string)$job['id'];

try {
    $claimed = $jobs->claim($jobId, 'integration-worker');
    guardAssert(is_array($claimed), 'atomic worker claim failed');
    guardAssert($jobs->claim($jobId, 'second-worker') === null, 'a second worker claimed the same job');

    $sink = new ExecutionGuardOutput($jobs, $jobId, 'integration-worker');
    // A recoverable tool/transport error may be streamed before the coordinator
    // emits its authoritative terminal run event.
    $sink->consume("data: {\"type\":\"error\",\"content\":\"recoverable test error\"}\n\n");
    $sink->consume("data: {\"type\":\"team_event\",\"run_id\":\"guard-run\",\"seq\":1,\"event\":\"run.completed\",\"payload\":{\"status\":\"completed\"}}\n\n");

    $requestId = bin2hex(random_bytes(16));
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $relays->create(
        $requestId,
        $token,
        '/file-op',
        '{"operation_id":"guard-operation","action":"list_files"}',
        30,
        $jobId,
        'guard-run',
        $userId
    );
    $sink->consume('data: ' . json_encode([
        'type' => 'launcher_request',
        'request_id' => $requestId,
        'relay_token' => $token,
        'url' => '/file-op',
        'body' => '{"operation_id":"guard-operation","action":"list_files"}',
    ], JSON_UNESCAPED_SLASHES) . "\n\n");
    guardAssert(!$relays->complete($requestId, 'wrong-token', ['success' => true]), 'invalid relay token was accepted');
    guardAssert($relays->complete($requestId, $token, ['success' => true, 'value' => 1]), 'relay result was not accepted');
    guardAssert($relays->complete($requestId, $token, ['success' => true, 'value' => 1]), 'duplicate relay receipt was not idempotent');

    $sink->consume("data: {\"type\":\"done\"}\n\n");
    $sink->finalize();
    $finished = $jobs->find($jobId);
    guardAssert(($finished['status'] ?? '') === 'completed', 'job did not reach completed');
    guardAssert(($finished['run_id'] ?? '') === 'guard-run', 'run_id was not attached from TeamEventV1');

    ob_start();
    ExecutionGuard::stream($jobs, $finished, 1, true);
    $resumeOutput = (string)ob_get_clean();
    guardAssert(str_contains($resumeOutput, 'network.reconnected'), 'resume acknowledgement missing');
    guardAssert(str_contains($resumeOutput, 'launcher_request'), 'pending/durable launcher request was not replayed');
    guardAssert(!str_contains($resumeOutput, '"event":"run.completed"'), 'already consumed TeamEventV1 was replayed');
    guardAssert(str_contains($resumeOutput, '"type":"done"'), 'terminal done was not replayed');

    // Prove the Windows/POSIX detached launcher can claim and finish a separate job.
    $detachedClientId = sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );
    $detached = $jobs->enqueue(
        $userId,
        $conversationId,
        $detachedClientId,
        'work',
        json_encode(['agent_mode' => 'agent', 'client_message_id' => $detachedClientId], JSON_THROW_ON_ERROR)
    );
    putenv('MOONYA_EXECUTION_GUARD_SELF_TEST=1');
    ExecutionGuard::launch(dirname(__DIR__), (string)$detached['id']);
    $deadline = microtime(true) + 10.0;
    do {
        usleep(100000);
        $detachedState = $jobs->find((string)$detached['id']);
    } while (microtime(true) < $deadline
        && !ExecutionJobRepository::isTerminal((string)($detachedState['status'] ?? '')));
    putenv('MOONYA_EXECUTION_GUARD_SELF_TEST');
    guardAssert(($detachedState['status'] ?? '') === 'completed', 'detached worker did not finish independently');
    $pdo->prepare('DELETE FROM execution_jobs WHERE id=?')->execute([(string)$detached['id']]);

    fwrite(STDOUT, "[PASS] execution guard queue, detached worker, SSE replay, relay token and idempotent receipt.\n");
} finally {
    putenv('MOONYA_EXECUTION_GUARD_SELF_TEST');
    $pdo->prepare('DELETE FROM local_tool_requests WHERE execution_job_id=?')->execute([$jobId]);
    $pdo->prepare('DELETE FROM execution_jobs WHERE id=?')->execute([$jobId]);
}
