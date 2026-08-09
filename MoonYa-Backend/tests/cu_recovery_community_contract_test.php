<?php
declare(strict_types=1);

function contractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$workspace = dirname($root);
$assistant = (string)file_get_contents($root . '/Services/AIAssistant.php');
$checkpoint = (string)file_get_contents($root . '/Services/CuRunCheckpoint.php');
$taskState = (string)file_get_contents($root . '/Services/ConversationTaskState.php');
$teamEmitter = (string)file_get_contents($root . '/Services/TeamEventEmitter.php');
$api = (string)file_get_contents($root . '/api.php');
$client = (string)file_get_contents($root . '/script/MoonYa-index/modules/script-1e-rest.php');
$worker = (string)file_get_contents($root . '/script/MoonYa-index/workers/conversation-runtime-worker.js');
$container = (string)file_get_contents($root . '/script/MoonYa-index/layouts/container.php');
$sidebar = (string)file_get_contents($root . '/script/MoonYa-index/layouts/sidebar.php');
$office = (string)file_get_contents($root . '/script/MoonYa-index/modules/script-5-office.php');
$communityIndex = (string)file_get_contents($root . '/community/index.php');
$communityDetail = (string)file_get_contents($root . '/community/detail.php');
$communityProfile = (string)file_get_contents($root . '/community/profile.php');
$communityApi = (string)file_get_contents($root . '/community/api/api.php');
$authBridge = (string)file_get_contents($root . '/community/auth-bridge.js');
$sql = (string)file_get_contents($root . '/sql/数据库.sql');
$desktopApi = (string)file_get_contents(
    $workspace . '/MoonYa-Win/MoonYa-Solution/MoonYa/Services/FileOperationApiServer.cs'
);

contractAssert(str_contains($assistant, 'private const CU_MAX_ITERATIONS = 1000'), 'CU fallback iteration limit is not 1000');
contractAssert(str_contains($assistant, 'private const CU_API_TIMEOUT = 90'), 'CU model timeout is not 90 seconds');
contractAssert(!str_contains($assistant, 'min($maxIterations, 20)'), 'Legacy 20-iteration clamp remains');
contractAssert(!str_contains($assistant, 'CU_TOTAL_TIMEOUT_SECONDS'), 'Legacy fixed total timeout remains');
contractAssert(str_contains($assistant, "if (\$toolName === 'task_complete')")
    && str_contains($assistant, "\$toolName = 'computer_complete'"), 'Legacy completion is not normalized before routing');

foreach (['network.reconnecting', 'network.reconnected', 'network.reconnect_failed'] as $eventName) {
    contractAssert(str_contains($client, $eventName), "Missing client network event {$eventName}");
}
contractAssert(str_contains($client, 'const networkRetryMax = 5'), 'Client reconnect count is not five');
foreach (['1000', '2000', '4000', '8000', '16000'] as $delay) {
    // The implementation is exponential; verify the base/cap plus the visible 1/2/4/8/16 contract in SQL.
    contractAssert(str_contains($sql, $delay), "Reconnect delay {$delay} is absent from persisted config/data");
}
contractAssert(str_contains($api, "is_array(\$data['resume'] ?? null)"), 'Resume request handler is missing');
contractAssert(str_contains($api, 'eventsAfterForRun'), 'Persisted event replay is missing');
contractAssert(str_contains($worker, "'recovering'"), 'Shared runtime does not preserve recovering phase');
contractAssert(str_contains($checkpoint, 'function takeoverReady')
    && str_contains($checkpoint, 'markPendingUnknownForRecovery'), 'Expired lease takeover or unknown-operation guard is missing');
contractAssert(str_contains($taskState, 'function takeover')
    && str_contains($api, '$resumeTakeoverRequested'), 'Same-task stale executor takeover is missing');
contractAssert(str_contains($assistant, 'run_recovered')
    && str_contains($assistant, 'recoveryRequiresObservation')
    && str_contains($assistant, 'wasLeaseLost'), 'Checkpoint restore or stale-executor fencing is incomplete');
contractAssert(str_contains($teamEmitter, 'lastEventSequence'), 'Recovered TeamEventV1 stream does not continue its sequence');

contractAssert(str_contains($container, 'work-mode office-active'), 'Office is not the default view');
$conversationPosition = strpos($sidebar, 'id="conversationBtn"');
$officePosition = strpos($sidebar, 'id="officeBtn"');
contractAssert($conversationPosition !== false && $officePosition !== false && $conversationPosition < $officePosition,
    'Conversation navigation is missing or not immediately above Office');
contractAssert(str_contains($office, 'if (!authenticated) return;'), 'Guest Office still starts authenticated activity work');
contractAssert(str_contains($office, 'setAuthenticated'), 'Office cannot transition from guest static mode after login');

contractAssert(!preg_match("/if \(!isset\(\$_SESSION\['user_id'\]\)\) \{\s*header\('Location: \.\.\/index\.php'\)/", $communityIndex),
    'Community index still redirects guests');
contractAssert(!preg_match("/if \(!isset\(\$_SESSION\['user_id'\]\)\) \{\s*header\('Location: \.\.\/index\.php'\)/", $communityDetail),
    'Community detail still redirects guests');
contractAssert(str_contains($communityProfile, "isset(\$_GET['user_id'])"), 'Public profile user_id route is missing');
contractAssert(!str_contains($communityApi, 'Access-Control-Allow-Origin: *'), 'Wildcard community CORS remains');
contractAssert(str_contains($communityApi, 'HTTP_X_CSRF_TOKEN') && str_contains($communityApi, 'hash_equals'),
    'Community writes do not enforce session CSRF');
contractAssert(str_contains($authBridge, 'X-CSRF-Token') && str_contains($authBridge, 'moonya:auth-required'),
    'Community login/CSRF browser bridge is incomplete');

foreach (['computer_user', 'vls_agent', 'keyboard_fallback_strategy', 'agent_computer', 'agent_moonya', 'ztimage_agent', 'image_agent'] as $promptName) {
    contractAssert(str_contains($sql, "WHERE `name`='{$promptName}'"), "SQL does not override {$promptName}");
}
$promptContracts = [
    'computer_user' => ['专用 API/MCP', 'Shell 或 Python', '浏览器 DOM/CDP', 'computer_observe', 'computer_interact', 'computer_complete', '禁止绝对像素', '不可信数据'],
    'vls_agent' => ['局部截图', 'ROI', '归一化候选框', 'mark_id', 'confidence<0.7', '最多三次'],
    'keyboard_fallback_strategy' => ['computer_observe', 'computer_interact', 'key_chord', '每个动作后重新', 'computer_complete'],
    'agent_computer' => ['专用 API/MCP', 'Shell/Python', '浏览器工具', 'computer_observe', 'unknown', '不得盲目重放'],
    'agent_moonya' => ['专用 API/MCP', 'Shell/Python', '浏览器', '桌面 CU', '图片生成', '不自动安装插件'],
    'ztimage_agent' => ['MiniMax image-01', '场景→主体→关键细节', '参考图1', '不可变约束', '精确保留用户指定文字'],
    'image_agent' => ['observations', 'inferences', 'uncertainties', '不可信数据', '只输出 JSON'],
];
foreach ($promptContracts as $promptName => $requiredTokens) {
    $pattern = "~UPDATE `system_prompts` SET `prompt`='([^']*)' WHERE `name`='"
        . preg_quote($promptName, '~') . "';~s";
    contractAssert(preg_match($pattern, $sql, $match) === 1, "Cannot extract SQL prompt {$promptName}");
    $prompt = (string)$match[1];
    foreach ($requiredTokens as $token) {
        contractAssert(str_contains($prompt, $token), "SQL prompt {$promptName} is missing {$token}");
    }
    foreach (['task_complete', 'mouse_click', 'take_screenshot', 'click_element'] as $legacyTool) {
        contractAssert(!str_contains($prompt, $legacyTool), "SQL prompt {$promptName} exposes legacy tool {$legacyTool}");
    }
}
foreach (['network_retry_max', 'network_retry_base_delay_ms', 'network_retry_max_delay_ms', 'cu_total_timeout_seconds'] as $column) {
    contractAssert(str_contains($sql, "`{$column}`"), "SQL is missing CU runtime column {$column}");
}
contractAssert(str_contains($sql, 'CREATE TABLE `cu_run_checkpoints`'), 'SQL is missing CU checkpoints');
contractAssert(str_contains($desktopApi, 'CuOperationResults') && str_contains($desktopApi, 'operation_result_unknown'),
    'Windows CU endpoint lacks idempotent result caching/unknown outcome handling');

echo "CU recovery/community contract: PASS\n";
