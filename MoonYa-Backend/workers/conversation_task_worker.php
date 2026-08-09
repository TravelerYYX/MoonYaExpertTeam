<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker is CLI-only.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/Services/ExecutionJobRepository.php';
require_once dirname(__DIR__) . '/Services/ExecutionGuardOutput.php';

$options = getopt('', ['job:']);
$jobId = trim((string)($options['job'] ?? ''));
if (!preg_match('/^[a-f0-9-]{36}$/i', $jobId)) {
    fwrite(STDERR, "Missing or invalid --job.\n");
    exit(2);
}

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'],
    $config['db_pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$repository = new ExecutionJobRepository($pdo);
$workerId = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
$job = $repository->claim($jobId, $workerId);
if (!is_array($job)) {
    exit(0); // Another independently launched worker won the atomic claim.
}

$request = json_decode((string)$job['request_json'], true);
if (!is_array($request)) {
    $repository->finish($jobId, 'failed', '队列请求 JSON 无效');
    exit(1);
}
unset($request['resume']);

$sink = new ExecutionGuardOutput($repository, $jobId, $workerId);
$GLOBALS['moonyaExecutionGuard'] = [
    'job_id' => $jobId,
    'worker_id' => $workerId,
    'user_id' => (int)$job['user_id'],
    'request_body' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'repository' => $repository,
    'config' => $config,
];

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api.php?execution_guard=1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

ob_start([$sink, 'consume'], 1);
register_shutdown_function(static function () use ($sink): void {
    try {
        $sink->finalize();
    } catch (Throwable $e) {
        error_log('[execution-guard-finalize] ' . $e->getMessage());
    }
});

if (getenv('MOONYA_EXECUTION_GUARD_SELF_TEST') === '1') {
    echo 'data: ' . json_encode([
        'type' => 'team_event',
        'version' => 1,
        'run_id' => 'execution-guard-self-test',
        'seq' => 1,
        'event' => 'run.completed',
        'payload' => ['status' => 'completed'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    echo "data: {\"type\":\"done\"}\n\n";
    ob_flush();
    exit(0);
}

require dirname(__DIR__) . '/api.php';
