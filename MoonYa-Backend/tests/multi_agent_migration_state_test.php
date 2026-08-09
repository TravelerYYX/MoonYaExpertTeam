<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
    $config['db_user'],
    $config['db_pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$state = $pdo->query(
    "SELECT
       (SELECT COUNT(*) FROM agents WHERE enabled=1) AS agents,
       (SELECT COUNT(*) FROM agent_delegations WHERE enabled=1) AS delegations,
       (SELECT COUNT(*) FROM tool_registry WHERE enabled=1) AS tools,
       (SELECT COUNT(*) FROM agent_tool_grants WHERE enabled=1) AS grants,
       (SELECT COUNT(*) FROM agent_routing_capabilities WHERE enabled=1) AS capabilities,
       (SELECT config_value FROM agent_runtime_config WHERE config_key='max_parallel_agents') AS max_parallel,
       (SELECT config_value FROM agent_runtime_config WHERE config_key='max_project_members') AS max_project_members,
       (SELECT config_value FROM agent_runtime_config WHERE config_key='max_root_delegations') AS max_root_delegations,
       (SELECT config_value FROM agent_runtime_config WHERE config_key='max_planning_corrections') AS max_planning_corrections,
       (SELECT config_value FROM agent_runtime_config WHERE config_key='approval_timeout_seconds') AS approval_timeout_seconds,
       (SELECT config_value FROM agent_runtime_config WHERE config_key='loop_guard_repeat_count') AS loop_guard_repeat_count,
       (SELECT config_value FROM agent_runtime_config WHERE config_key='loop_guard_max_period') AS loop_guard_max_period,
       (SELECT config_value FROM agent_runtime_config WHERE config_key='loop_guard_recovery_attempts') AS loop_guard_recovery_attempts"
)->fetch();

require_once dirname(__DIR__) . '/Services/TeamRepository.php';
$repository = new TeamRepository($pdo);
$state['approval_mode'] = $repository->approvalMode(1, null);
$functionCallingCompatibility = $repository->runtimeConfig(
    'function_calling_compatibility',
    []
);
$deepSeekProfile = is_array($functionCallingCompatibility)
    ? array_values(array_filter(
        $functionCallingCompatibility,
        static fn($profile): bool => is_array($profile)
            && strtolower((string)($profile['model_contains'] ?? '')) === 'deepseek'
    ))
    : [];
if (($deepSeekProfile[0]['thinking'] ?? '') !== 'preserve'
    || ($deepSeekProfile[0]['supports_tool_choice'] ?? true) !== false) {
    throw new RuntimeException(
        'DeepSeek Function Calling compatibility profile was not seeded'
    );
}
$state['deepseek_fc_thinking'] = 'preserve';
$state['deepseek_tool_choice'] = 'unsupported';
$expected = [
    'max_parallel' => '6',
    'max_project_members' => '6',
    'max_root_delegations' => '2',
    'max_planning_corrections' => '1',
    'approval_timeout_seconds' => '0',
    'loop_guard_repeat_count' => '3',
    'loop_guard_max_period' => '4',
    'loop_guard_recovery_attempts' => '1',
    'approval_mode' => 'high_risk',
];
foreach ($expected as $key => $value) {
    if ((string)$state[$key] !== (string)$value) {
        throw new RuntimeException("Unexpected {$key}: " . json_encode($state[$key]));
    }
}
if ((int)$state['delegations'] < 6 || (int)$state['capabilities'] < 10) {
    throw new RuntimeException(
        'Required delegation or routing capability baseline is incomplete: ' .
        json_encode($state, JSON_UNESCAPED_UNICODE)
    );
}
$requiredAgentKeys = ['moonya', 'app', 'computer', 'browser', 'file', 'search', 'code'];
$installedAgentKeys = array_map(
    'strval',
    $pdo->query('SELECT agent_key FROM agents WHERE enabled=1')->fetchAll(PDO::FETCH_COLUMN)
);
$missingAgentKeys = array_values(array_diff($requiredAgentKeys, $installedAgentKeys));
if ($missingAgentKeys !== []) {
    throw new RuntimeException(
        'Required agents are missing: ' . json_encode($missingAgentKeys, JSON_UNESCAPED_UNICODE)
    );
}
if ((int)$state['tools'] < 10 || (int)$state['grants'] < 20) {
    throw new RuntimeException('Tool registry or grant matrix was not seeded');
}
$approvalExpiryColumn = $pdo->query(
    "SHOW COLUMNS FROM tool_approvals WHERE Field='expires_at'"
)->fetch();
if (!is_array($approvalExpiryColumn)
    || strtoupper((string)($approvalExpiryColumn['Null'] ?? 'NO')) !== 'YES') {
    throw new RuntimeException('tool_approvals.expires_at is not nullable');
}
$messageIndexes = [];
foreach ($pdo->query('SHOW INDEX FROM messages')->fetchAll() as $index) {
    $messageIndexes[(string)$index['Key_name']][] = (string)$index['Column_name'];
}
if (($messageIndexes['uq_messages_client'] ?? []) !== [
        'conversation_id', 'user_id', 'client_message_id',
    ]
    || ($messageIndexes['uq_messages_run_role'] ?? []) !== [
        'conversation_id', 'user_id', 'source_run_id', 'role',
    ]) {
    throw new RuntimeException(
        'Message idempotency indexes are incomplete: ' .
        json_encode($messageIndexes, JSON_UNESCAPED_UNICODE)
    );
}
$configuredOverrides = (int)$pdo->query(
    "SELECT COUNT(*) FROM agents
     WHERE enabled=1
       AND agent_key IN ('moonya','app','computer','browser','file','search','code')
       AND model_override IS NOT NULL AND TRIM(model_override)<>''"
)->fetchColumn();
if ($configuredOverrides !== 0) {
    throw new RuntimeException('Current Agent rows no longer inherit the coordinator model');
}
$state['model_overrides'] = 'all_empty';

$legacyNames = (int)$pdo->query(
    "SELECT COUNT(*) FROM tool_registry WHERE tool_key IN ('execute_command','execute_python')"
)->fetchColumn();
$canonicalNames = (int)$pdo->query(
    "SELECT COUNT(*) FROM tool_registry WHERE tool_key IN ('shell_executor','python_executor')"
)->fetchColumn();
if ($legacyNames !== 0 || $canonicalNames !== 2) {
    throw new RuntimeException('Canonical executor names were not seeded correctly');
}

$routeClassColumn = $pdo->query(
    "SHOW COLUMNS FROM tool_registry WHERE Field='route_class'"
)->fetch();
if (!is_array($routeClassColumn)) {
    throw new RuntimeException('tool_registry.route_class migration is missing');
}
$desktopBusinessTools = (int)$pdo->query(
    "SELECT COUNT(*) FROM tool_registry
     WHERE enabled=1 AND route_class='computer'
       AND tool_key IN ('computer_observe','computer_interact','computer_complete')"
)->fetchColumn();
if ($desktopBusinessTools !== 3) {
    throw new RuntimeException('Semantic desktop business tools were not seeded');
}
$mediaTable = $pdo->query("SHOW TABLES LIKE 'team_event_media'")->fetchColumn();
if ($mediaTable === false) {
    throw new RuntimeException('team_event_media migration is missing');
}
$mediaColumns = array_map(
    'strval',
    $pdo->query('SHOW COLUMNS FROM team_event_media')->fetchAll(PDO::FETCH_COLUMN)
);
foreach (['run_id', 'task_id', 'tool_call_id', 'event_seq', 'relative_path'] as $requiredColumn) {
    if (!in_array($requiredColumn, $mediaColumns, true)) {
        throw new RuntimeException("team_event_media.{$requiredColumn} is missing");
    }
}

$moonyaPrompt = (string)$pdo->query(
    "SELECT sp.prompt
     FROM agents a
     JOIN system_prompts sp ON sp.name=a.prompt_name
     WHERE a.agent_key='moonya' AND a.enabled=1
     LIMIT 1"
)->fetchColumn();
if (!str_contains($moonyaPrompt, '第一项动作必须在两个协调工具中二选一')
    || !str_contains($moonyaPrompt, 'respond_without_delegation')
    || !str_contains($moonyaPrompt, 'finalize_work')
    || !str_contains($moonyaPrompt, '员工批次成功不会自动进入最终汇总')
    || !str_contains($moonyaPrompt, 'HTML、CSS、JavaScript')
    || !str_contains($moonyaPrompt, '多模块、多文件')
    || !str_contains($moonyaPrompt, 'code.project_delivery')
    || !str_contains($moonyaPrompt, 'project.acceptance.completed')
    || !str_contains($moonyaPrompt, 'capability_key')
    || !str_contains($moonyaPrompt, '一律不得自行补全')
    || !str_contains($moonyaPrompt, '不得替员工编写 shell 命令')) {
    throw new RuntimeException('MoonYa delegation, no-assumption, or instruction boundary policy was not seeded');
}
$filePrompt = (string)$pdo->query(
    "SELECT sp.prompt
     FROM agents a
     JOIN system_prompts sp ON sp.name=a.prompt_name
     WHERE a.agent_key='file' AND a.enabled=1
     LIMIT 1"
)->fetchColumn();
if (!str_contains($filePrompt, '不得在任务中临时 pip install')
    || !str_contains($filePrompt, 'artifact_path')
    || !str_contains($filePrompt, 'verification')
    || !str_contains($filePrompt, '重新打开验证格式')) {
    throw new RuntimeException('File Agent managed Office runtime contract was not seeded');
}

$capabilities = $repository->listRoutingCapabilities(true);
$notReady = array_values(array_filter(
    $capabilities,
    static fn(array $capability): bool => !($capability['ready'] ?? false)
));
if (count($capabilities) < 10 || $notReady !== []) {
    throw new RuntimeException(
        'Routing capabilities are incomplete: ' .
        json_encode($notReady, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

$forbiddenWorkGrants = (int)$pdo->query(
    "SELECT COUNT(*)
     FROM agent_tool_grants g
     JOIN agents a ON a.id=g.agent_id
     JOIN tool_registry t ON t.id=g.tool_id
     WHERE g.enabled=1 AND (
       (a.agent_key IN ('browser','search') AND t.tool_key IN ('shell_executor','python_executor','get_command_status','stop_command'))
       OR (a.agent_key='app' AND t.tool_key IN ('shell_executor','python_executor','get_command_status','stop_command'))
     )"
)->fetchColumn();
if ($forbiddenWorkGrants !== 0) {
    throw new RuntimeException('Work role matrix still contains forbidden built-in grants');
}

$computerRouterGrants = (int)$pdo->query(
    "SELECT COUNT(DISTINCT t.tool_key)
     FROM agent_tool_grants g
     JOIN agents a ON a.id=g.agent_id
     JOIN tool_registry t ON t.id=g.tool_id
     WHERE g.enabled=1 AND a.agent_key='computer'
       AND t.tool_key IN (
         'open_app','shell_executor','python_executor','browser_automation_control',
         'computer_observe','computer_interact','computer_complete'
       )"
)->fetchColumn();
if ($computerRouterGrants !== 7) {
    throw new RuntimeException('Computer capability router grant chain is incomplete');
}

$expectedRiskMetadata = [
    'web_crawler' => ['write', 'medium'],
    'mouse_move' => ['write', 'medium'],
    'mouse_scroll' => ['write', 'medium'],
    'focus_window' => ['write', 'medium'],
    'open_file' => ['write', 'medium'],
    'open_video_site' => ['write', 'medium'],
    'todo_write' => ['write', 'low'],
    'task_complete' => ['write', 'low'],
    'ZTimage-Agent' => ['write', 'high'],
    'generate_video' => ['write', 'high'],
    'search_music' => ['write', 'medium'],
    'shell_executor' => ['write', 'high'],
    'python_executor' => ['write', 'high'],
    'delete_file' => ['destructive', 'critical'],
    'uninstall_app' => ['destructive', 'critical'],
    'stop_command' => ['destructive', 'critical'],
];
$riskStmt = $pdo->prepare(
    'SELECT effect, risk_level FROM tool_registry WHERE tool_key=? AND enabled=1 LIMIT 1'
);
foreach ($expectedRiskMetadata as $toolKey => [$effect, $risk]) {
    $riskStmt->execute([$toolKey]);
    $actual = $riskStmt->fetch();
    if (!$actual
        || (string)$actual['effect'] !== $effect
        || (string)$actual['risk_level'] !== $risk) {
        throw new RuntimeException(
            "Unexpected risk metadata for {$toolKey}: " .
            json_encode($actual, JSON_UNESCAPED_UNICODE)
        );
    }
}

$shellSchemaJson = (string)$pdo->query(
    "SELECT input_schema FROM tool_registry WHERE tool_key='shell_executor' LIMIT 1"
)->fetchColumn();
$shellSchema = json_decode($shellSchemaJson, true) ?: [];
$shellRequired = $shellSchema['required'] ?? [];
foreach (['command', 'shell', 'phase', 'operation_id', 'intent', 'success_criteria'] as $requiredField) {
    if (!in_array($requiredField, $shellRequired, true)) {
        throw new RuntimeException("Shell Work schema is missing required field {$requiredField}");
    }
}
$completionMode = $shellSchema['properties']['completion_mode'] ?? [];
if (($completionMode['type'] ?? '') !== 'string'
    || ($completionMode['enum'] ?? []) !== ['finite', 'persistent']) {
    throw new RuntimeException('Shell Work schema is missing the finite/persistent completion mode contract');
}
if (($shellSchema['properties']['affected_paths']['type'] ?? '') !== 'array') {
    throw new RuntimeException('Shell Work schema is missing project affected_paths');
}
$projectTables = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema=DATABASE()
       AND table_name IN ('team_project_groups','team_project_actors')"
)->fetchColumn();
if ($projectTables !== 2) {
    throw new RuntimeException('Project group persistence tables are missing');
}
$preflightCorrections = (string)$pdo->query(
    "SELECT config_value FROM agent_runtime_config WHERE config_key='max_shell_preflight_corrections'"
)->fetchColumn();
if ($preflightCorrections !== '1') {
    throw new RuntimeException('max_shell_preflight_corrections was not seeded to 1');
}

$expectedManagedGrants = [
    'app' => [
        'check_app_installed', 'close_app', 'download_file',
        'install_app', 'open_app', 'uninstall_app',
    ],
    'computer' => [
        'browser_automation_control', 'capture_ui_snapshot', 'check_app_installed',
        'click_element', 'close_app', 'computer_complete', 'computer_interact',
        'computer_observe', 'copy_file', 'create_file', 'create_folder', 'delete_file',
        'download_file', 'edit_file', 'find_element', 'focus_window', 'get_command_status',
        'get_cursor_pos', 'get_system_status', 'get_text', 'get_ui_tree', 'glob', 'grep',
        'install_app', 'key_press', 'keyboard_type', 'list_files', 'mouse_click',
        'mouse_drag', 'mouse_hold', 'mouse_move', 'mouse_scroll', 'move_file', 'open_app',
        'open_file', 'python_executor', 'read_file', 'recycle_bin_status', 'set_text',
        'shell_executor', 'stop_command', 'take_screenshot', 'uninstall_app',
        'view_directory', 'vls_analyze_browser', 'web_crawler', 'web_fetch', 'web_search',
    ],
    'browser' => [
        'browser_automation_control', 'vls_analyze_browser', 'web_crawler', 'web_fetch',
    ],
    'file' => [
        'copy_file', 'create_file', 'create_folder', 'delete_file', 'download_file',
        'edit_file', 'get_command_status', 'glob', 'grep', 'list_files', 'move_file',
        'open_file', 'python_executor', 'read_file', 'shell_executor', 'stop_command',
        'view_directory',
    ],
    'search' => ['web_fetch', 'web_search'],
    'code' => [
        'create_file', 'create_folder', 'edit_file', 'find_references', 'get_command_status',
        'get_diagnostics', 'glob', 'goto_definition', 'grep', 'list_files',
        'python_executor', 'read_file', 'shell_executor', 'stop_command', 'view_directory',
    ],
];
$managedKeys = array_values(array_unique(array_merge(...array_values($expectedManagedGrants))));
foreach ($expectedManagedGrants as $agentKey => $expectedTools) {
    sort($expectedTools);
    $grantStmt = $pdo->prepare(
        'SELECT t.tool_key
         FROM agent_tool_grants g
         JOIN agents a ON a.id=g.agent_id
         JOIN tool_registry t ON t.id=g.tool_id
         WHERE a.agent_key=? AND g.enabled=1 AND t.enabled=1 AND t.source="native"
           AND t.tool_key IN (' . implode(',', array_fill(0, count($managedKeys), '?')) . ')
         ORDER BY t.tool_key'
    );
    $grantStmt->execute(array_merge([$agentKey], $managedKeys));
    $actualTools = array_map('strval', $grantStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($actualTools !== $expectedTools) {
        throw new RuntimeException(
            "Unexpected managed grant matrix for {$agentKey}: " .
            json_encode($actualTools, JSON_UNESCAPED_UNICODE)
        );
    }
}
$workTaskCompleteGrant = (int)$pdo->query(
    "SELECT COUNT(*)
     FROM agent_tool_grants g
     JOIN agents a ON a.id=g.agent_id
     JOIN tool_registry t ON t.id=g.tool_id
     WHERE a.agent_key='computer' AND t.tool_key='task_complete' AND g.enabled=1"
)->fetchColumn();
if ($workTaskCompleteGrant !== 0) {
    throw new RuntimeException('Computer Agent still exposes the Computer User-only task_complete tool in Work mode');
}

$recycleTool = $pdo->query(
    "SELECT input_schema, transport, transport_config, effect, risk_level
     FROM tool_registry WHERE tool_key='recycle_bin_status' AND enabled=1 LIMIT 1"
)->fetch();
$recycleSchema = json_decode((string)($recycleTool['input_schema'] ?? ''), true) ?: [];
$recycleTransport = json_decode((string)($recycleTool['transport_config'] ?? ''), true) ?: [];
if (!$recycleTool
    || (string)$recycleTool['transport'] !== 'launcher_file'
    || ($recycleTransport['action'] ?? '') !== 'recycle_bin_status'
    || (string)$recycleTool['effect'] !== 'read'
    || (string)$recycleTool['risk_level'] !== 'low'
    || ($recycleSchema['properties']['phase']['enum'] ?? []) !== ['inspect', 'verify']
    || ($recycleSchema['properties']['expected_empty']['type'] ?? '') !== 'boolean'
) {
    throw new RuntimeException('Authoritative Recycle Bin status tool contract is incomplete');
}

echo 'multi_agent migration state: PASS ' . json_encode($state, JSON_UNESCAPED_UNICODE) . PHP_EOL;
