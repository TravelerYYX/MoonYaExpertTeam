<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Services/CapabilityRouter.php';
require_once dirname(__DIR__) . '/Services/TeamRepository.php';
require_once dirname(__DIR__) . '/Services/TeamMediaStore.php';
require_once dirname(__DIR__) . '/Services/TeamEventEmitter.php';

function cuAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cuTool(string $name, ?string $route = null, string $source = ''): array
{
    $tool = [
        'type' => 'function',
        'function' => [
            'name' => $name,
            'description' => $name,
            'parameters' => ['type' => 'object', 'properties' => []],
        ],
    ];
    if ($route !== null) $tool['route_class'] = $route;
    if ($source !== '') $tool['source'] = $source;
    return $tool;
}

function removeFixtureDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) removeFixtureDirectory($path); else unlink($path);
    }
    rmdir($directory);
}

$ordered = CapabilityRouter::modelTools([
    cuTool('computer_interact'),
    cuTool('write_file'),
    cuTool('execute_python'),
    cuTool('mcp__mail__send', null, 'mcp'),
    cuTool('browser_automation_control'),
    cuTool('mouse_click'),
]);
$names = array_column(array_column($ordered, 'function'), 'name');
cuAssert($names === [
    'mcp__mail__send', 'write_file', 'execute_python',
    'browser_automation_control', 'computer_interact',
], 'route order or raw desktop filtering is incorrect');
cuAssert(CapabilityRouter::mayFallback(['ok' => false, 'failure_code' => 'uia_target_not_found']), 'definite no-op must allow fallback');
cuAssert(!CapabilityRouter::mayFallback(['ok' => false, 'failure_code' => 'timeout']), 'unknown timeout must not allow fallback');
cuAssert(!CapabilityRouter::mayFallback(['ok' => true]), 'successful side effect must not allow fallback');

$workLogUi = (string)file_get_contents(
    dirname(__DIR__) . '/script/MoonYa-index/modules/script-1e-rest.php'
);
$workLogCss = (string)file_get_contents(
    dirname(__DIR__) . '/script/MoonYa-index/styles/css-06-main.php'
);
$cuCoordinator = (string)file_get_contents(dirname(__DIR__) . '/Services/AIAssistant.php');
cuAssert(
    str_contains($workLogUi, 'function renderTeamEventMedia')
        && str_contains($workLogUi, "image.loading = 'lazy'")
        && str_contains($workLogUi, 'openDetailLightbox(original || thumbnail)')
        && str_contains($workLogUi, 'renderTeamEventMedia(log, payload.media)')
        && str_contains($workLogCss, '.team-event-media-tile'),
    'work-log thumbnail, history, or lightbox rendering contract is incomplete'
);
cuAssert(
    str_contains($cuCoordinator, "['openai_compatible', 'anthropic_messages']")
        && str_contains($cuCoordinator, "['supports_images']")
        && str_contains($cuCoordinator, 'for ($attempt = 1; $attempt <= 3; $attempt++)')
        && str_contains($cuCoordinator, '$stageTwoConfidence < 0.7')
        && str_contains($cuCoordinator, 'min($stageOneConfidence, $stageTwoConfidence) < 0.7')
        && str_contains($cuCoordinator, "'action' => 'computer_visual_mark'")
        && str_contains($cuCoordinator, "'action' => 'computer_visual_interact'")
        && !str_contains($cuCoordinator, "'target' => 'screen',\n                'visual_fallback' => true"),
    'two-stage, window-crop, confidence, retry, or vision-protocol contract is incomplete'
);

$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moonya-cu-media-' . bin2hex(random_bytes(6));
mkdir($fixtureRoot, 0700, true);
$database = $fixtureRoot . DIRECTORY_SEPARATOR . 'media.sqlite';
$pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE agent_runtime_config (config_key TEXT, config_value TEXT)');
$pdo->exec('CREATE TABLE system_prompts (name TEXT, display_name TEXT)');
$pdo->exec('CREATE TABLE agents (
    id INTEGER PRIMARY KEY, agent_key TEXT, display_name TEXT, avatar_url TEXT,
    role_summary TEXT, prompt_name TEXT, sort_order INTEGER, enabled INTEGER
)');
$pdo->exec('CREATE TABLE tool_registry (
    id INTEGER PRIMARY KEY, tool_key TEXT, display_name TEXT, description TEXT,
    input_schema TEXT, output_schema TEXT, transport TEXT, transport_config TEXT,
    effect TEXT, risk_level TEXT, source TEXT, route_class TEXT, enabled INTEGER
)');
$pdo->exec('CREATE TABLE agent_tool_grants (agent_id INTEGER, tool_id INTEGER, enabled INTEGER)');
$pdo->exec('CREATE TABLE mcp_servers (
    id INTEGER PRIMARY KEY, server_key TEXT, enabled INTEGER, last_status TEXT
)');
$pdo->exec('CREATE TABLE user_mcp_connections (
    user_id INTEGER, mcp_server_id INTEGER, vault_key TEXT, status TEXT,
    scopes_json TEXT, expires_at TEXT
)');
$pdo->exec('CREATE TABLE team_runs (
    id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, conversation_id INTEGER
)');
$pdo->exec('CREATE TABLE team_run_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT, seq INTEGER,
    event_name TEXT, agent_key TEXT, parent_agent_key TEXT, task_id TEXT,
    tool_call_id TEXT, payload TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE team_event_media (
    id TEXT PRIMARY KEY, run_id TEXT, task_id TEXT, tool_call_id TEXT,
    event_seq INTEGER, kind TEXT, mime_type TEXT, width INTEGER, height INTEGER,
    relative_path TEXT, thumbnail_relative_path TEXT, source TEXT,
    error_message TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)');
$runId = '11111111-1111-4111-8111-111111111111';
$pdo->prepare('INSERT INTO team_runs (id, user_id, conversation_id) VALUES (?, 7, 9)')->execute([$runId]);
$pdo->prepare("INSERT INTO team_run_events (run_id, seq, event_name, payload) VALUES (?, 7, 'fixture.previous', '{}')")
    ->execute([$runId]);
$pdo->exec("INSERT INTO agents VALUES (1, 'computer', 'Computer', '', '', '', 1, 1)");
$pdo->exec("INSERT INTO mcp_servers VALUES (1, 'fixture', 1, 'connected')");
$pdo->exec("INSERT INTO user_mcp_connections VALUES (7, 1, 'vault://fixture', 'connected', '[]', NULL)");
$pdo->exec("INSERT INTO tool_registry VALUES (
    1, 'mcp__fixture__image', 'Fixture MCP', 'fixture', '{\"type\":\"object\",\"properties\":{}}',
    NULL, 'mcp', '{\"server_key\":\"fixture\",\"original_name\":\"image\"}',
    'read', 'low', 'mcp', 'specialized_api', 1
)");
$pdo->exec('INSERT INTO agent_tool_grants VALUES (1, 1, 1)');
$repository = new TeamRepository($pdo);
cuAssert(count($repository->functionToolsForAgent('computer', 7)) === 1, 'connected, scoped MCP tool must be routable');
cuAssert(count($repository->functionToolsForAgent('computer', 8)) === 0, 'MCP tool must not be exposed to another user');
$pdo->exec("UPDATE user_mcp_connections SET status='disconnected' WHERE user_id=7");
cuAssert(count($repository->functionToolsForAgent('computer', 7)) === 0, 'offline MCP tool must not be routable');
$pdo->exec("UPDATE user_mcp_connections SET status='connected' WHERE user_id=7");
putenv('MOONYA_WORKLOG_MEDIA_DIR=' . $fixtureRoot . DIRECTORY_SEPARATOR . 'store');

$pngA = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
$pngB = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2ZQAAAABJRU5ErkJggg==';
$emitter = new TeamEventEmitter($repository, $runId);
ob_start();
$event = $emitter->emit('tool.completed', [
    'tool_key' => 'browser_automation_control',
    'ok' => true,
    'content' => [
        ['type' => 'image', 'mimeType' => 'image/png', 'data' => $pngA],
        ['type' => 'image', 'mime_type' => 'image/png', 'data' => $pngB],
        ['type' => 'text', 'text' => 'https://invalid.example/not-downloaded.png'],
    ],
    'evidence_image' => $pngA,
    'mime_type' => 'image/png',
], 'computer', 'moonya', 'task-1', 'tool-1');
$stream = (string)ob_get_clean();
cuAssert((int)($event['seq'] ?? 0) === 8, 'recovered emitter must continue after the persisted event sequence');

$media = $event['payload']['media'] ?? [];
cuAssert(count($media) === 2, 'multi-image extraction must preserve order and deduplicate identical bytes');
cuAssert(!str_contains($stream, $pngA) && !str_contains($stream, $pngB), 'SSE event must not contain Base64 image bytes');
cuAssert(!str_contains($stream, 'data:image'), 'SSE event must not contain data URLs');
$storedPayload = (string)$pdo->query("SELECT payload FROM team_run_events WHERE event_name='tool.completed' LIMIT 1")->fetchColumn();
cuAssert(!str_contains($storedPayload, $pngA) && !str_contains($storedPayload, $pngB), 'database event must not contain Base64 image bytes');
cuAssert(str_contains($storedPayload, 'invalid.example/not-downloaded.png'), 'remote image-looking text must not be fetched or rewritten as media');
cuAssert((int)$pdo->query('SELECT COUNT(*) FROM team_event_media')->fetchColumn() === 2, 'media metadata rows were not persisted');

foreach ($media as $item) {
    cuAssert(($item['thumbnail_url'] ?? null) !== null, 'thumbnail URL missing');
    cuAssert(($item['content_url'] ?? null) !== null, 'content URL missing');
    $row = $repository->eventMediaForUser(7, (string)$item['id']);
    cuAssert($row !== null, 'owner must be able to read media metadata');
    cuAssert($repository->eventMediaForUser(8, (string)$item['id']) === null, 'other users must not read media metadata');
    $path = (new TeamMediaStore($repository))->resolvePath($row, 'content');
    cuAssert($path !== null && is_file($path), 'stored content path is unavailable');
}

$blockedRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'not-a-directory';
file_put_contents($blockedRoot, 'fixture');
$failureStore = new TeamMediaStore($repository, $blockedRoot);
$failed = $failureStore->extractFromPayload(
    $runId,
    'task-2',
    'tool-2',
    2,
    'tool.completed',
    ['screenshot' => $pngA, 'mime_type' => 'image/png']
);
cuAssert(($failed['media'][0]['kind'] ?? '') === 'image_error', 'disk failure must produce an explicit media placeholder');
cuAssert(($failed['media'][0]['content_url'] ?? null) === null, 'failed media must not expose a content URL');

$runDirectory = $fixtureRoot . DIRECTORY_SEPARATOR . 'store' . DIRECTORY_SEPARATOR . $runId;
cuAssert(is_dir($runDirectory), 'run media directory missing before cleanup');
(new TeamMediaStore($repository))->deleteRunDirectory($runId);
cuAssert(!is_dir($runDirectory), 'run media directory was not cleaned up');

putenv('MOONYA_WORKLOG_MEDIA_DIR');
unset($emitter, $repository, $failureStore, $row);
$pdo = null;
gc_collect_cycles();
removeFixtureDirectory($fixtureRoot);
echo "CU enhancement contracts: PASS\n";
