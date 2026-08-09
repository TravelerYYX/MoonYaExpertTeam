<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Services/ConversationTaskState.php';
require_once dirname(__DIR__) . '/Services/ExecutionJobRepository.php';
require_once dirname(__DIR__) . '/Services/TeamRepository.php';

function e2eFail(string $message): never
{
    throw new RuntimeException($message);
}

function e2eUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function e2eEvents(string $sse): array
{
    $events = [];
    foreach (preg_split('/\r?\n\r?\n/', $sse) ?: [] as $frame) {
        foreach (preg_split('/\r?\n/', $frame) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $decoded = json_decode(ltrim(substr($line, 5)), true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
    }
    return $events;
}

function e2eRequest(string $url, string $token, array $payload, ?callable $onChunk = null): array
{
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 420,
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $onChunk): int {
            $body .= $chunk;
            if ($onChunk !== null && $onChunk($body, $chunk) === false) {
                return 0; // Intentional subscriber disconnect; worker must survive.
            }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['ok' => $ok, 'status' => $status, 'error' => $error, 'body' => $body];
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
$user = $pdo->query(<<<'SQL'
SELECT id, api_token
FROM users
WHERE api_token IS NOT NULL AND api_token<>''
  AND status NOT IN ('banned','deleted')
  AND (token_created_at IS NULL OR token_created_at >= DATE_SUB(NOW(), INTERVAL 15 DAY))
ORDER BY id ASC LIMIT 1
SQL)->fetch(PDO::FETCH_ASSOC);
if (!is_array($user)) {
    fwrite(STDOUT, "[SKIP] execution guard HTTP E2E: no active API token fixture.\n");
    exit(0);
}

$userId = (int)$user['id'];
$token = (string)$user['api_token'];
$clientMessageId = e2eUuid();
$title = 'execution-guard-e2e-' . substr($clientMessageId, 0, 8);
$insertConversation = $pdo->prepare(
    'INSERT INTO conversations (user_id, title, pinned, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())'
);
$insertConversation->execute([$userId, $title]);
$conversationId = (int)$pdo->lastInsertId();
$jobs = new ExecutionJobRepository($pdo);
$taskState = new ConversationTaskState($pdo);
$teamRepository = new TeamRepository($pdo);
$terminal = false;

try {
    $payload = [
        'message' => 'Use the search.web_research capability and delegate this task to Search Agent. Search the public web for current information about OpenAI products from at least two public sources, including one official OpenAI page. This is read-only: do not log in, use browser automation, use local computer tools, or perform any write action. Return the source URLs.',
        'model' => 'deepseek',
        'deepThinking' => false,
        'reasoningEffort' => 'medium',
        'agent_mode' => 'agent',
        'computer_user_mode' => false,
        'conversation_id' => $conversationId,
        'client_message_id' => $clientMessageId,
    ];
    $url = rtrim((string)(getenv('MOONYA_E2E_BASE_URL') ?: 'http://localhost'), '/') . '/api.php';

    $first = e2eRequest($url, $token, $payload, static function (string $body): bool {
        return !str_contains($body, '"event":"run.started"');
    });
    $firstEvents = e2eEvents((string)$first['body']);
    $runId = '';
    $lastTeamSeq = 0;
    foreach ($firstEvents as $event) {
        if (($event['type'] ?? '') === 'team_event') {
            $runId = (string)($event['run_id'] ?? $runId);
            $lastTeamSeq = max($lastTeamSeq, (int)($event['seq'] ?? 0));
        }
    }
    if ($runId === '') {
        // The worker may have started just after the subscriber was severed.
        $deadline = microtime(true) + 20.0;
        do {
            usleep(200000);
            $job = $jobs->findForOwner($userId, $conversationId, $clientMessageId);
            $runId = trim((string)($job['run_id'] ?? ''));
        } while ($runId === '' && microtime(true) < $deadline);
    }
    if ($runId === '') {
        e2eFail(
            'detached worker did not create a team run after subscriber disconnect; first stream: '
            . mb_substr(trim((string)$first['body']), 0, 1000)
        );
    }

    $payload['resume'] = [
        'run_id' => $runId,
        'client_message_id' => $clientMessageId,
        'after_seq' => $lastTeamSeq,
        'attempt' => 1,
        'error' => 'intentional_e2e_disconnect',
    ];
    $resume = e2eRequest($url, $token, $payload);
    if ((int)$resume['status'] !== 200) {
        e2eFail('resume HTTP status ' . $resume['status'] . ': ' . $resume['error']);
    }
    $all = array_merge($firstEvents, e2eEvents((string)$resume['body']));
    $uniqueTeam = [];
    foreach ($all as $event) {
        if (($event['type'] ?? '') !== 'team_event') {
            continue;
        }
        $uniqueTeam[(string)$event['run_id'] . ':' . (int)$event['seq']] = $event;
    }

    $delegated = false;
    $searchStarted = false;
    $searchCompleted = false;
    $searchFailed = false;
    $terminalEvents = [];
    foreach ($uniqueTeam as $event) {
        $name = (string)($event['event'] ?? '');
        $agentKey = (string)($event['agent']['key'] ?? '');
        if ($name === 'agent.summary' && ($event['payload']['phase'] ?? '') === 'delegation') {
            $delegated = true;
        }
        if ($name === 'agent.started' && in_array($agentKey, ['search', 'search_agent'], true)) {
            $searchStarted = true;
        }
        if ($name === 'agent.completed' && in_array($agentKey, ['search', 'search_agent'], true)) {
            $searchCompleted = true;
        }
        if ($name === 'agent.failed' && in_array($agentKey, ['search', 'search_agent'], true)) {
            $searchFailed = true;
        }
        if (in_array($name, ['run.completed', 'run.failed', 'run.cancelled'], true)) {
            $terminalEvents[] = $event;
        }
    }
    $timeline = [];
    foreach ($uniqueTeam as $event) {
        $name = (string)($event['event'] ?? '');
        if (!in_array($name, [
            'run.started', 'delegation.accepted', 'agent.started', 'agent.completed',
            'agent.failed', 'run.completed', 'run.failed', 'run.cancelled',
        ], true)) {
            continue;
        }
        $error = $event['payload']['error'] ?? '';
        if (is_array($error)) {
            $error = json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $timeline[] = [
            'seq' => (int)($event['seq'] ?? 0),
            'event' => $name,
            'agent' => (string)($event['agent']['key'] ?? ''),
            'status' => (string)($event['payload']['status'] ?? ''),
            'error' => (string)$error,
        ];
    }
    $timelineJson = json_encode($timeline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $job = $jobs->findForOwner($userId, $conversationId, $clientMessageId);
    $terminal = is_array($job) && ExecutionJobRepository::isTerminal((string)$job['status']);
    if (!$delegated || !$searchStarted) {
        e2eFail('MoonYa did not produce a real Search Agent handoff; timeline=' . $timelineJson);
    }
    if (!$searchCompleted && !$searchFailed) {
        e2eFail('Search Agent did not reach a terminal state; timeline=' . $timelineJson);
    }
    if (count($terminalEvents) !== 1) {
        e2eFail('run did not produce exactly one terminal event; timeline=' . $timelineJson);
    }
    $terminalEventName = (string)$terminalEvents[0]['event'];
    $expectedRunStatus = match ($terminalEventName) {
        'run.failed' => 'failed',
        'run.cancelled' => 'cancelled',
        default => 'completed',
    };
    $runStatus = (string)($terminalEvents[0]['payload']['status'] ?? $expectedRunStatus);
    if (!in_array($runStatus, ['completed', 'partial', 'failed', 'cancelled'], true)
        || ($terminalEventName === 'run.failed' && $runStatus !== 'failed')
        || ($terminalEventName === 'run.cancelled' && $runStatus !== 'cancelled')
        || ($terminalEventName === 'run.completed' && !in_array($runStatus, ['completed', 'partial'], true))
    ) {
        e2eFail("{$terminalEventName} carried an invalid status: {$runStatus}");
    }
    if (!$terminal || (string)$job['status'] !== $runStatus) {
        e2eFail(
            'execution job did not converge to the authoritative run status; run='
            . $runStatus . ', job=' . (string)($job['status'] ?? 'missing')
        );
    }
    if (!str_contains((string)$resume['body'], 'network.reconnected')) {
        e2eFail('resume stream did not acknowledge reconnection');
    }

    $searchOutcome = $searchCompleted ? 'completed' : 'failed externally but settled';
    fwrite(STDOUT, "[PASS] real Work handoff survived SSE disconnect, Search Agent {$searchOutcome}, {$terminalEventName}={$runStatus}, and one terminal event was emitted.\n");
} finally {
    $job = $jobs->findForOwner($userId, $conversationId, $clientMessageId);
    if (is_array($job) && !ExecutionJobRepository::isTerminal((string)$job['status'])) {
        $jobs->cancelForOwnerClient($userId, $conversationId, $clientMessageId);
        $runId = trim((string)($job['run_id'] ?? ''));
        if ($runId !== '') {
            $teamRepository->cancelRun($userId, $runId);
        }
        $taskState->finish($userId, $conversationId, $clientMessageId, 'cancelled');
        usleep(500000);
    }
    // Delete only the uniquely named fixture created by this test.
    $delete = $pdo->prepare('DELETE FROM conversations WHERE id=? AND user_id=? AND title=?');
    $delete->execute([$conversationId, $userId, $title]);
}
