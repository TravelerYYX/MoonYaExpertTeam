<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Services/TeamRepository.php';
require_once dirname(__DIR__) . '/Services/TeamEventEmitter.php';
require_once dirname(__DIR__) . '/Services/ToolGateway.php';
require_once dirname(__DIR__) . '/Services/TeamCoordinator.php';
require_once dirname(__DIR__) . '/Services/TeamWorkProtocol.php';

function smokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("Cannot allocate test port: {$error}");
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

$port = freePort();
$fixtureModelCapabilities = [
    'provider' => 'fixture',
    'reasoning_control' => 'binary_strength',
    'strip_sampling_when_thinking' => true,
    'omit_tool_choice_when_thinking' => true,
];
$teamModelConfig = [
    'model_provider_routes' => [
        'fixture' => ['url_key' => 'fixture_api_url', 'api_key_key' => 'fixture_api_key'],
    ],
    'model_capabilities' => [
        'deepseek-v4-flash' => $fixtureModelCapabilities,
    ],
    'fixture_api_url' => "http://127.0.0.1:{$port}/v1/chat/completions",
    'fixture_api_key' => 'fixture-key',
];
$serverScript = __DIR__ . '/fake_openai_server.py';
$command = [
    PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3',
    $serverScript,
    (string)$port,
];
$pipes = [];
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, __DIR__);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start fake OpenAI server');
}
$databasePath = tempnam(sys_get_temp_dir(), 'moonya-agent-smoke-');
if ($databasePath === false) {
    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('Cannot create the smoke-test database');
}
$projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moonya-project-smoke-' . bin2hex(random_bytes(6));
if (!mkdir($projectPath, 0700, true) && !is_dir($projectPath)) {
    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('Cannot create the smoke-test project root');
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 80; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(25000);
    }
    smokeAssert($ready, 'Fake OpenAI server did not start');

    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE system_prompts (
        id INTEGER PRIMARY KEY, name TEXT, display_name TEXT, prompt TEXT, enabled INTEGER
    )');
    $pdo->exec('CREATE TABLE agents (
        id INTEGER PRIMARY KEY, agent_key TEXT UNIQUE, display_name TEXT, role_summary TEXT,
        avatar_url TEXT, prompt_name TEXT, model_override TEXT, is_coordinator INTEGER,
        enabled INTEGER, sort_order INTEGER
    )');
    $pdo->exec('CREATE TABLE agent_delegations (
        parent_agent_id INTEGER, child_agent_id INTEGER, enabled INTEGER
    )');
    $pdo->exec('CREATE TABLE agent_routing_capabilities (
        id INTEGER PRIMARY KEY AUTOINCREMENT, capability_key TEXT UNIQUE, agent_id INTEGER,
        display_name TEXT, description TEXT, examples_json TEXT, exclusions_json TEXT,
        required_tools_json TEXT, enabled INTEGER, sort_order INTEGER
    )');
    $pdo->exec('CREATE TABLE agent_runtime_config (config_key TEXT, config_value TEXT)');
    $pdo->exec('CREATE TABLE tool_registry (
        id INTEGER PRIMARY KEY, tool_key TEXT, display_name TEXT, description TEXT,
        input_schema TEXT, output_schema TEXT, source TEXT, transport TEXT,
        transport_config TEXT, effect TEXT, risk_level TEXT, reviewed INTEGER, enabled INTEGER
    )');
    $pdo->exec('CREATE TABLE agent_tool_grants (agent_id INTEGER, tool_id INTEGER, enabled INTEGER)');
    $pdo->exec('CREATE TABLE team_run_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT, seq INTEGER, event_name TEXT,
        agent_key TEXT, parent_agent_key TEXT, task_id TEXT, tool_call_id TEXT, payload TEXT
    )');
    $pdo->exec('CREATE TABLE team_runs (
        id TEXT PRIMARY KEY, user_id INTEGER, conversation_id INTEGER, status TEXT,
        final_summary TEXT, completed_at TEXT, direct_response_reason TEXT,
        planning_rejections INTEGER DEFAULT 0, final_message_id INTEGER
    )');
    $pdo->exec('CREATE TABLE team_project_groups (
        id TEXT PRIMARY KEY, run_id TEXT, root_task_id TEXT, lead_actor_id TEXT,
        phase TEXT, status TEXT, objective TEXT, contract_json TEXT,
        acceptance_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE team_project_actors (
        id TEXT PRIMARY KEY, project_group_id TEXT, task_id TEXT, role_key TEXT,
        role_label TEXT, workstream TEXT, owned_paths_json TEXT,
        read_dependencies_json TEXT, depends_on_json TEXT, status TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE tool_approvals (
        id TEXT PRIMARY KEY, run_id TEXT, user_id INTEGER, conversation_id INTEGER,
        agent_key TEXT, tool_call_id TEXT, tool_key TEXT, arguments_hash TEXT,
        reason TEXT, status TEXT DEFAULT "pending", expires_at TEXT, decided_at TEXT,
        UNIQUE(run_id, tool_call_id)
    )');
    $pdo->exec(
        "INSERT INTO team_runs (id, user_id, conversation_id, status)
         VALUES ('smoke-run', 1, 1, 'running')"
    );
    $pdo->exec("INSERT INTO system_prompts VALUES (1, 'smoke', 'Smoke', 'Return a concise result.', 1)");
    $agentKeys = ['moonya', 'app_agent', 'computer_agent', 'browser_agent', 'file_agent', 'search_agent', 'code_agent'];
    $insertAgent = $pdo->prepare(
        'INSERT INTO agents VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    foreach ($agentKeys as $index => $key) {
        $insertAgent->execute([
            $index + 1,
            $key,
            $key,
            "Role {$key}",
            "/assets/agents/{$key}.png",
            'smoke',
            '',
            $key === 'moonya' ? 1 : 0,
            $index,
        ]);
        if ($key !== 'moonya') {
            $pdo->prepare('INSERT INTO agent_delegations VALUES (1, ?, 1)')->execute([$index + 1]);
        }
    }
    $pdo->exec("INSERT INTO tool_registry VALUES (
        1, 'fake_executor', 'Fake executor', 'Test-only native executor',
        '{\"type\":\"object\",\"properties\":{\"code\":{\"type\":\"string\"}}}',
        '{\"type\":\"object\"}', 'native', 'execution', '{}', 'read', 'low', 1, 1
    )");
    $pdo->exec("INSERT INTO tool_registry VALUES (
        2, 'empty_schema_probe', 'Empty schema probe', 'Preserves empty JSON objects',
        '{\"type\":\"object\",\"properties\":{}}',
        '{\"type\":\"object\"}', 'native', 'execution', '{}', 'read', 'low', 1, 1
    )");
    $pdo->exec("INSERT INTO tool_registry VALUES (
        3, 'shell_executor', 'Shell executor', 'Test shell policy',
        '{\"type\":\"object\",\"properties\":{\"command\":{\"type\":\"string\"}}}',
        '{\"type\":\"object\"}', 'native', 'launcher_file', '{}', 'read', 'low', 1, 1
    )");
    $pdo->exec("INSERT INTO tool_registry VALUES (
        4, 'python_executor', 'Python executor', 'Test Python policy',
        '{\"type\":\"object\",\"properties\":{\"code\":{\"type\":\"string\"}}}',
        '{\"type\":\"object\"}', 'native', 'execution', '{}', 'read', 'low', 1, 1
    )");
    foreach (range(2, count($agentKeys)) as $agentId) {
        $pdo->prepare('INSERT INTO agent_tool_grants VALUES (?, 1, 1)')->execute([$agentId]);
    }
    $pdo->exec('INSERT INTO agent_tool_grants VALUES (3, 2, 1)');
    $pdo->exec('INSERT INTO agent_tool_grants VALUES (5, 3, 1)');
    $pdo->exec('INSERT INTO agent_tool_grants VALUES (5, 4, 1)');
    $pdo->exec('INSERT INTO agent_tool_grants VALUES (7, 3, 1)');
    $capabilityRows = [
        ['app.lifecycle', 2],
        ['computer.system', 3],
        ['computer.desktop_ui', 3],
        ['browser.automation', 4],
        ['browser.page_extraction', 4],
        ['file.management', 5],
        ['file.office', 5],
        ['search.web_research', 6],
        ['code.engineering', 7],
        ['code.project_delivery', 7],
    ];
    $insertCapability = $pdo->prepare(
        'INSERT INTO agent_routing_capabilities
         (capability_key, agent_id, display_name, description, examples_json,
          exclusions_json, required_tools_json, enabled, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    foreach ($capabilityRows as $index => [$capabilityKey, $agentId]) {
        $insertCapability->execute([
            $capabilityKey,
            $agentId,
            $capabilityKey,
            "Capability {$capabilityKey}",
            json_encode(["example {$capabilityKey}"]),
            json_encode(["not {$capabilityKey}"]),
            json_encode(['fake_executor']),
            $index,
        ]);
    }
    $pdo->exec("INSERT INTO agent_runtime_config VALUES ('max_parallel_agents', '3')");
    $pdo->exec("INSERT INTO agent_runtime_config VALUES ('max_project_members', '6')");
    $pdo->exec("INSERT INTO agent_runtime_config VALUES ('max_root_delegations', '2')");
    $pdo->exec("INSERT INTO agent_runtime_config VALUES ('max_agent_iterations', '4')");
    $pdo->exec("INSERT INTO agent_runtime_config VALUES ('event_payload_max_bytes', '8388608')");
    $pdo->exec("INSERT INTO agent_runtime_config VALUES (
        'function_calling_compatibility',
        '[{\"model_contains\":\"deepseek\",\"thinking\":\"preserve\",\"supports_tool_choice\":false}]'
    )");

    $repository = new TeamRepository($pdo);
    $events = new TeamEventEmitter($repository, 'smoke-run');
    $gateway = new ToolGateway(
        $repository,
        $events,
        [],
        static function (string $url, string $body): array {
            $payload = json_decode($body, true) ?: [];
            if (($payload['action'] ?? '') === 'validate_command') {
                return [
                    'valid' => true,
                    'status' => 'success',
                    'stage' => 'preflight',
                    'shell' => 'powershell',
                    'effect' => 'read',
                    'riskLevel' => 'low',
                    'commandFingerprint' => hash('sha256', (string)($payload['command'] ?? '')),
                    'commandPreview' => (string)($payload['command'] ?? ''),
                    'commandPreflightVersion' => 'shell-preflight-v1',
                ];
            }
            if (($payload['code'] ?? '') === 'cooperative-tool-wait') {
                $deadline = microtime(true) + 1.2;
                while (microtime(true) < $deadline) {
                    $pump = $GLOBALS['teamCooperativePump'] ?? null;
                    if (is_callable($pump)) {
                        $pump();
                    }
                    usleep(20_000);
                }
            }
            return [
                'ok' => true,
                'content' => 'fake tool evidence',
                'structured_content' => ['verified' => true],
                'artifacts' => [],
                'metadata' => ['test' => true],
                'error' => null,
            ];
        },
        1,
        1,
        'high_risk',
        null
    );
    $coordinator = new TeamCoordinator(
        $repository,
        $events,
        $gateway,
        "http://127.0.0.1:{$port}/v1/chat/completions",
        '',
        'deepseek-v4-flash',
        $teamModelConfig
    );

    $delegate = $coordinator->delegationTool();
    $resolvedOwners = [];
    foreach ($repository->listRoutingCapabilities(true) as $capability) {
        $resolvedOwners[$capability['capability_key']] = $capability['agent_key'];
    }
    smokeAssert($resolvedOwners === [
        'app.lifecycle' => 'app_agent',
        'computer.system' => 'computer_agent',
        'computer.desktop_ui' => 'computer_agent',
        'browser.automation' => 'browser_agent',
        'browser.page_extraction' => 'browser_agent',
        'file.management' => 'file_agent',
        'file.office' => 'file_agent',
        'search.web_research' => 'search_agent',
        'code.engineering' => 'code_agent',
        'code.project_delivery' => 'code_agent',
    ], 'Database capability ownership did not resolve to the expected Agents');
    $validateTasks = new ReflectionMethod(TeamCoordinator::class, 'validateTasks');
    $validateTasks->setAccessible(true);
    $routingCases = [
        '安装 VLC' => [
            ['id' => 'install', 'capability_key' => 'app.lifecycle', 'instruction' => '安装 VLC', 'selection_reason' => '应用生命周期'],
        ],
        '清空回收站' => [
            ['id' => 'recycle', 'capability_key' => 'computer.system', 'instruction' => '清空回收站', 'selection_reason' => '系统管理'],
        ],
        '打开记事本并输入内容' => [
            ['id' => 'open', 'capability_key' => 'app.lifecycle', 'instruction' => '打开记事本', 'selection_reason' => '启动应用'],
            ['id' => 'type', 'capability_key' => 'computer.desktop_ui', 'instruction' => '输入内容', 'selection_reason' => '桌面界面操作', 'depends_on' => ['open']],
        ],
        '操作动态网页表单' => [
            ['id' => 'form', 'capability_key' => 'browser.automation', 'instruction' => '操作动态网页表单', 'selection_reason' => '浏览器自动化'],
        ],
        '搜索 2026 年最新资料' => [
            ['id' => 'research', 'capability_key' => 'search.web_research', 'instruction' => '搜索 2026 年最新资料', 'selection_reason' => '联网调研'],
        ],
        '下载文件并整理目录' => [
            ['id' => 'files', 'capability_key' => 'file.management', 'instruction' => '下载文件并整理目录', 'selection_reason' => '文件管理'],
        ],
        '检查 LSP 报错并修改代码' => [
            ['id' => 'code', 'capability_key' => 'code.engineering', 'instruction' => '检查 LSP 报错并修改代码', 'selection_reason' => '代码工程'],
        ],
        '搜索资料并生成 docx' => [
            ['id' => 'search', 'capability_key' => 'search.web_research', 'instruction' => '搜索资料', 'selection_reason' => '联网调研'],
            ['id' => 'docx', 'capability_key' => 'file.office', 'instruction' => '根据资料生成并验证 docx', 'selection_reason' => 'Office 产物', 'depends_on' => ['search']],
        ],
    ];
    foreach ($routingCases as $label => $plan) {
        $validatedPlan = $validateTasks->invoke($coordinator, $plan);
        smokeAssert(
            count($validatedPlan) === count($plan),
            "Routing acceptance case failed: {$label}"
        );
        foreach ($plan as $plannedTask) {
            $resolvedTask = $validatedPlan[$plannedTask['id']] ?? null;
            smokeAssert(
                is_array($resolvedTask)
                    && ($resolvedTask['agent_key'] ?? '') === $resolvedOwners[$plannedTask['capability_key']]
                    && ($resolvedTask['depends_on'] ?? []) === ($plannedTask['depends_on'] ?? []),
                "Server capability resolution or dependency chain failed: {$label}"
            );
        }
    }
    $enum = $delegate['function']['parameters']['properties']['tasks']['items']['properties']['capability_key']['enum'];
    smokeAssert(
        count($enum) === 10
            && in_array('computer.system', $enum, true)
            && in_array('code.project_delivery', $enum, true)
            && !isset($delegate['function']['parameters']['properties']['tasks']['items']['properties']['agent_key']),
        'Database capability enum or server-side Agent resolution contract is invalid'
    );
    $computerToolsJson = json_encode(
        $repository->functionToolsForAgent('computer_agent'),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    smokeAssert(
        is_string($computerToolsJson)
            && str_contains(
                $computerToolsJson,
                '"name":"empty_schema_probe","description":"Preserves empty JSON objects","parameters":{"type":"object","properties":{}}'
            ),
        'Function tool JSON Schema objects were not preserved on the wire'
    );

    $prepared = TeamWorkProtocol::prepareInitialRequest(
        ['model' => 'deepseek-v4-flash', 'messages' => [], 'thinking' => ['type' => 'enabled']],
        $coordinator->coordinatorTools(),
        $repository->runtimeConfig('function_calling_compatibility', []),
        $fixtureModelCapabilities
    );
    smokeAssert(
        count($prepared['tools']) === 2
            && ($prepared['tools'][1]['function']['name'] ?? '') === 'respond_without_delegation',
        'MoonYa initial request did not expose exactly the two coordination tools'
    );
    $continuation = TeamWorkProtocol::prepareContinuationRequest(
        $prepared,
        $coordinator->coordinatorTools(),
        $repository->runtimeConfig('function_calling_compatibility', [])
    );
    smokeAssert(
        count($continuation['tools']) === 3
            && ($continuation['tools'][2]['function']['name'] ?? '') === 'finalize_work'
            && !isset($continuation['tool_choice']),
        'Employee evidence did not unlock the explicit finalize_work decision for DeepSeek'
    );
    $finalOnly = TeamWorkProtocol::prepareFinalSynthesisRequest($continuation);
    smokeAssert(
        !isset($finalOnly['tools'], $finalOnly['tool_choice']),
        'Final synthesis request still exposed coordination tools'
    );
    smokeAssert(!isset($prepared['tool_choice']), 'DeepSeek thinking request retained unsupported tool_choice');
    smokeAssert(
        ($prepared['thinking']['type'] ?? '') === 'enabled',
        'DeepSeek V4 thinking was unexpectedly disabled'
    );

    $callFakeRoot = static function (array $request) use ($port): array {
        $handle = curl_init("http://127.0.0.1:{$port}/v1/chat/completions");
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        $decoded = json_decode((string)$raw, true);
        smokeAssert($status === 200 && is_array($decoded), 'Fake root coordinator request failed');
        return $decoded;
    };
    $protocolCoordinator = new TeamCoordinator(
        $repository,
        $events,
        $gateway,
        "http://127.0.0.1:{$port}/v1/chat/completions",
        '',
        'deepseek-v4-flash',
        $teamModelConfig
    );
    $protocolMessages = [
        ['role' => 'system', 'content' => 'Use the Work coordination protocol.'],
        ['role' => 'user', 'content' => 'READ_THEN_BUILD：读取提示词后实现工作台。'],
    ];
    $protocolRequest = TeamWorkProtocol::prepareInitialRequest([
        'model' => 'deepseek-v4-flash',
        'messages' => $protocolMessages,
        'stream' => false,
        'thinking' => ['type' => 'enabled'],
    ], $protocolCoordinator->coordinatorTools(), $repository->runtimeConfig('function_calling_compatibility', []));
    $expectedRootSequence = [
        ['delegate_to_agents', 'file.management'],
        ['delegate_to_agents', 'code.engineering'],
        ['finalize_work', null],
    ];
    foreach ($expectedRootSequence as $rootStep => [$expectedTool, $expectedCapability]) {
        $protocolRequest['messages'] = $protocolMessages;
        $rootPayload = $callFakeRoot($protocolRequest);
        $rootToolCall = $rootPayload['choices'][0]['message']['tool_calls'][0] ?? [];
        $rootToolName = (string)($rootToolCall['function']['name'] ?? '');
        $rootArguments = json_decode((string)($rootToolCall['function']['arguments'] ?? '{}'), true) ?: [];
        smokeAssert($rootToolName === $expectedTool, "Unexpected root protocol step {$rootStep}: {$rootToolName}");
        if ($expectedCapability !== null) {
            smokeAssert(
                ($rootArguments['tasks'][0]['capability_key'] ?? '') === $expectedCapability,
                "Root protocol step {$rootStep} used the wrong capability"
            );
        }
        $protocolMessages[] = [
            'role' => 'assistant',
            'content' => '正在执行协调工具。',
            'reasoning_content' => '根据已完成结果选择下一项协议动作。',
            'tool_calls' => [$rootToolCall],
        ];
        ob_start();
        $rootToolResult = $rootToolName === 'delegate_to_agents'
            ? $protocolCoordinator->executeDelegation(
                $rootArguments,
                (string)($rootToolCall['id'] ?? "protocol-delegate-{$rootStep}")
            )
            : $protocolCoordinator->executeFinalization(
                $rootArguments,
                (string)($rootToolCall['id'] ?? 'protocol-finalize')
            );
        ob_end_clean();
        $protocolMessages[] = [
            'role' => 'tool',
            'tool_call_id' => (string)($rootToolCall['id'] ?? "protocol-call-{$rootStep}"),
            'name' => $rootToolName,
            'content' => json_encode($rootToolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($rootToolName === 'delegate_to_agents') {
            smokeAssert(
                TeamWorkProtocol::hasEmployeeEvidence($rootToolResult),
                "Root delegation {$rootStep} returned no employee evidence"
            );
            $protocolRequest = TeamWorkProtocol::prepareContinuationRequest(
                $protocolRequest,
                $protocolCoordinator->coordinatorTools(),
                $repository->runtimeConfig('function_calling_compatibility', [])
            );
        } else {
            smokeAssert(
                TeamWorkProtocol::isFinalization($rootToolResult),
                'Root finalize_work declaration was rejected after read and build evidence'
            );
            $protocolRequest = TeamWorkProtocol::prepareFinalSynthesisRequest($protocolRequest);
        }
    }
    $protocolRequest['messages'] = $protocolMessages;
    $finalRootPayload = $callFakeRoot($protocolRequest);
    $finalRootMessage = $finalRootPayload['choices'][0]['message'] ?? [];
    smokeAssert(
        empty($finalRootMessage['tool_calls'])
            && trim((string)($finalRootMessage['content'] ?? '')) !== ''
            && !TeamWorkProtocol::containsToolProtocolMarkup((string)$finalRootMessage['content']),
        'Final synthesis did not produce exactly one tool-free user answer'
    );

    $workflowCoordinator = new TeamCoordinator(
        $repository,
        $events,
        $gateway,
        "http://127.0.0.1:{$port}/v1/chat/completions",
        '',
        'deepseek-v4-flash',
        $teamModelConfig
    );
    ob_start();
    $promptRead = $workflowCoordinator->executeDelegation(['tasks' => [[
        'id' => 'prompt-read',
        'capability_key' => 'file.management',
        'instruction' => '读取工作台设计提示词并返回完整事实输入',
        'selection_reason' => '先读取用户指定的普通文本文件',
    ]]], 'read-prompt-call');
    $promptReadEvents = (string)ob_get_clean();
    smokeAssert(
        ($promptRead['structured_content']['prompt-read']['status'] ?? '') === 'success'
            && !str_contains($promptReadEvents, 'final_synthesis')
            && !str_contains($promptReadEvents, 'coordination.finalized'),
        'Reading the prompt incorrectly triggered final synthesis before implementation'
    );
    ob_start();
    $workspaceBuild = $workflowCoordinator->executeDelegation(['tasks' => [[
        'id' => 'workspace-build',
        'capability_key' => 'code.engineering',
        'instruction' => '依据已读取的提示词实现并验证 HTML、CSS、JavaScript 工作台',
        'selection_reason' => '项目代码创建与验证属于 code.engineering',
    ]]], 'workspace-build-call');
    ob_end_clean();
    smokeAssert(
        ($workspaceBuild['structured_content']['workspace-build']['status'] ?? '') === 'success',
        'The read-then-build workflow did not continue to code.engineering'
    );
    ob_start();
    $workflowFinalized = $workflowCoordinator->executeFinalization([
        'outcome' => 'completed',
        'evidence_task_ids' => ['prompt-read', 'workspace-build'],
        'unresolved' => [],
    ], 'workflow-finalize-call');
    $workflowFinalizeEvents = (string)ob_get_clean();
    smokeAssert(
        TeamWorkProtocol::isFinalization($workflowFinalized)
            && str_contains($workflowFinalizeEvents, '"event":"coordination.finalized"'),
        'The read-then-build workflow could not finalize after implementation evidence'
    );

    $effortCases = [
        'none' => ['disabled', null],
        'low' => ['enabled', 'high'],
        'medium' => ['enabled', 'high'],
        'high' => ['enabled', 'high'],
        'xhigh' => ['enabled', 'max'],
    ];
    foreach ($effortCases as $level => [$thinkingType, $wireEffort]) {
        $mapped = TeamWorkProtocol::applyReasoningPolicy([
            'model' => 'deepseek-v4-flash',
            'temperature' => 0.6,
            'top_p' => 0.9,
        ], $level, $fixtureModelCapabilities);
        smokeAssert(
            ($mapped['thinking']['type'] ?? '') === $thinkingType,
            "DeepSeek {$level} thinking mapping is invalid"
        );
        smokeAssert(
            ($mapped['reasoning_effort'] ?? null) === $wireEffort,
            "DeepSeek {$level} effort mapping is invalid"
        );
        if ($thinkingType === 'enabled') {
            smokeAssert(
                !isset($mapped['temperature'], $mapped['top_p']),
                "DeepSeek {$level} retained invalid thinking sampling parameters"
            );
        }
    }
    foreach (['kimi-k2.6', 'kimi-k3', 'MiniMax-M3', 'MiniMax-M2.7'] as $isolatedModel) {
        $isolated = TeamWorkProtocol::applyReasoningPolicy([
            'model' => $isolatedModel,
            'provider_native_reasoning' => true,
        ], 'xhigh', []);
        smokeAssert(
            !isset($isolated['reasoning_effort'])
                && ($isolated['provider_native_reasoning'] ?? false) === true,
            "{$isolatedModel} was incorrectly controlled by the DeepSeek slider"
        );
    }
    smokeAssert(
        TeamWorkProtocol::normalizeConfiguredModel(
            'deepseek-v4-pro',
            ['deepseek-v4-flash', 'deepseek-v4-pro'],
            'deepseek-v4-flash'
        ) === 'deepseek-v4-pro'
            && TeamWorkProtocol::normalizeConfiguredModel(
                'glm-5',
                ['deepseek-v4-flash', 'deepseek-v4-pro'],
                'deepseek-v4-flash'
            ) === 'deepseek-v4-flash',
        'DeepSeek model allowlist fallback is invalid'
    );

    $apiSource = (string)file_get_contents(dirname(__DIR__) . '/api.php');
    smokeAssert(
        str_contains($apiSource, '$requestData = TeamWorkProtocol::prepareInitialRequest('),
        'api.php does not apply the mandatory Work delegation protocol'
    );
    smokeAssert(
        str_contains($apiSource, '&& !$multiAgentTeamEnabled) {'),
        'Legacy ungrounded planner is still active during a team run'
    );
    smokeAssert(
        str_contains($apiSource, "'phase' => 'planning'")
            && str_contains($apiSource, "'phase' => 'final_synthesis'")
            && str_contains($apiSource, 'isRunCancelled($teamRunId)'),
        'MoonYa planning/final-synthesis phases or cancellation guard are missing'
    );

    $recycleRequest = TeamWorkProtocol::prepareInitialRequest([
        'model' => 'deepseek-v4-flash',
        'messages' => [
            ['role' => 'system', 'content' => 'Delegate before answering.'],
            ['role' => 'user', 'content' => '清空回收站'],
        ],
        'stream' => false,
    ], $coordinator->coordinatorTools(), $repository->runtimeConfig('function_calling_compatibility', []));
    $rootCh = curl_init("http://127.0.0.1:{$port}/v1/chat/completions");
    curl_setopt_array($rootCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($recycleRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $rootRaw = curl_exec($rootCh);
    curl_close($rootCh);
    $rootResponse = json_decode((string)$rootRaw, true);
    $rootCall = $rootResponse['choices'][0]['message']['tool_calls'][0] ?? [];
    $rootArgs = json_decode((string)($rootCall['function']['arguments'] ?? '{}'), true);
    smokeAssert(
        ($rootCall['function']['name'] ?? '') === 'delegate_to_agents',
        'Recycle-bin Work request bypassed employee delegation'
    );
    smokeAssert(
        ($rootArgs['tasks'][0]['capability_key'] ?? '') === 'computer.system',
        'Recycle-bin Work request was not routed through the system capability'
    );
    $delegatedText = (string)($rootArgs['tasks'][0]['instruction'] ?? '');
    smokeAssert(
        !str_contains($delegatedText, '网盘') && !str_contains($delegatedText, '登录'),
        'Recycle-bin delegation invented a remote service or login'
    );

    $chatRequest = TeamWorkProtocol::prepareInitialRequest([
        'model' => 'deepseek-v4-flash',
        'messages' => [
            ['role' => 'system', 'content' => 'Use the current request.'],
            ['role' => 'user', 'content' => '清空回收站'],
            ['role' => 'assistant', 'content' => '旧任务已结束。'],
            ['role' => 'user', 'content' => '你好'],
        ],
        'stream' => false,
    ], $coordinator->coordinatorTools(), $repository->runtimeConfig('function_calling_compatibility', []));
    $chatCh = curl_init("http://127.0.0.1:{$port}/v1/chat/completions");
    curl_setopt_array($chatCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($chatRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $chatRaw = curl_exec($chatCh);
    curl_close($chatCh);
    $chatResponse = json_decode((string)$chatRaw, true);
    $chatCall = $chatResponse['choices'][0]['message']['tool_calls'][0] ?? [];
    smokeAssert(
        ($chatCall['function']['name'] ?? '') === 'respond_without_delegation',
        'Current chat message replayed the historical recycle-bin task'
    );

    ob_start();
    $recycleResult = $coordinator->executeDelegation(
        $rootArgs,
        (string)($rootCall['id'] ?? 'root-recycle-call')
    );
    $recycleEvents = (string)ob_get_clean();
    smokeAssert(
        str_contains($recycleEvents, '"event":"agent.reasoning.delta"')
            && str_contains($recycleEvents, '"event":"agent.content.delta"')
            && str_contains($recycleEvents, '"event":"agent.turn.completed"'),
        'Employee reasoning/content was not streamed and persisted as a completed turn'
    );
    smokeAssert(
        str_contains($recycleEvents, '"event":"agent.summary"')
            && str_contains($recycleEvents, '"event":"delegation.accepted"')
            && str_contains($recycleEvents, '"phase":"delegation"')
            && str_contains($recycleEvents, '"announcement":"准备处理：')
            && str_contains($recycleEvents, '"agent_display_name":"computer_agent"')
            && str_contains($recycleEvents, '"phase":"execution"'),
        'Delegation announcement or employee execution phase payload is incomplete'
    );
    smokeAssert(
        TeamWorkProtocol::hasEmployeeEvidence($recycleResult),
        'MoonYa did not receive structured employee evidence for recycle-bin task'
    );
    smokeAssert(
        ($recycleResult['structured_content']['clear-recycle-bin']['status'] ?? '') === 'success',
        'Recycle-bin employee result did not return to MoonYa'
    );
    smokeAssert(
        TeamWorkProtocol::delegationOutcome($recycleResult) === [
            'dispatch_status' => 'executed',
            'total' => 1,
            'success' => 1,
            'failed' => 0,
            'retryable' => false,
        ],
        'Successful employee outcome was not summarized correctly'
    );
    smokeAssert(
        !str_contains($apiSource, 'shouldForceSynthesis')
            && str_contains($apiSource, 'prepareContinuationRequest')
            && str_contains($apiSource, 'TeamWorkProtocol::FINALIZE_FUNCTION')
            && str_contains($apiSource, 'if ($teamFinalResponseAccepted)')
            && str_contains($apiSource, "'finalization_required'")
            && str_contains($apiSource, "'empty_final_response'"),
        'A successful employee batch still forces final synthesis instead of another explicit decision'
    );

    $emptyLedgerCoordinator = new TeamCoordinator(
        $repository,
        $events,
        $gateway,
        "http://127.0.0.1:{$port}/v1/chat/completions",
        'fake-key',
        'deepseek-v4-flash',
        $teamModelConfig
    );
    $prematureFinalize = $emptyLedgerCoordinator->executeFinalization([
        'outcome' => 'completed',
        'evidence_task_ids' => ['clear-recycle-bin'],
        'unresolved' => [],
    ], 'premature-finalize');
    smokeAssert(
        ($prematureFinalize['error']['code'] ?? '') === 'employee_evidence_required',
        'finalize_work was accepted before this coordinator had employee evidence'
    );

    $unknownEvidence = $coordinator->executeFinalization([
        'outcome' => 'completed',
        'evidence_task_ids' => ['missing-task'],
        'unresolved' => [],
    ], 'unknown-finalize');
    smokeAssert(
        ($unknownEvidence['error']['code'] ?? '') === 'unknown_evidence_task',
        'finalize_work accepted an unknown task as execution evidence'
    );
    foreach ([
        [
            'outcome' => 'completed',
            'evidence_task_ids' => ['clear-recycle-bin'],
            'unresolved' => [['item' => 'still pending', 'reason' => 'not done']],
        ],
        [
            'outcome' => 'partial',
            'evidence_task_ids' => ['clear-recycle-bin'],
            'unresolved' => [],
        ],
        [
            'outcome' => 'blocked',
            'evidence_task_ids' => [],
            'unresolved' => [],
        ],
    ] as $invalidFinalization) {
        $invalidResult = $coordinator->executeFinalization(
            $invalidFinalization,
            'invalid-finalization-' . $invalidFinalization['outcome']
        );
        smokeAssert(
            ($invalidResult['error']['code'] ?? '') === 'invalid_finalization',
            'Invalid ' . $invalidFinalization['outcome'] . ' finalization shape was accepted'
        );
    }

    ob_start();
    $duplicateAcrossRun = $coordinator->executeDelegation($rootArgs, 'duplicate-run-task');
    ob_end_clean();
    smokeAssert(
        ($duplicateAcrossRun['error']['code'] ?? '') === 'invalid_delegation',
        'Task IDs were only checked inside one batch instead of across the run'
    );

    ob_start();
    $longResult = $coordinator->executeDelegation([
        'tasks' => [[
            'id' => 'long-over-old-limit',
            'capability_key' => 'code.engineering',
            'instruction' => 'LONG_15 execute fifteen distinct tool calls, then succeed',
            'selection_reason' => '验证有进展的长任务不受旧轮次上限影响',
        ]],
    ], 'root-long-over-old-limit');
    ob_end_clean();
    $longAgent = $longResult['structured_content']['long-over-old-limit'] ?? [];
    smokeAssert(
        ($longAgent['status'] ?? '') === 'success'
            && (int)($longAgent['structured_content']['tool_calls_executed'] ?? 0) === 15
            && (int)($longAgent['structured_content']['iterations'] ?? 0) > 12,
        'A progressing employee task did not continue beyond the old 12-round limit'
    );

    ob_start();
    $deadLoopResult = $coordinator->executeDelegation([
        'tasks' => [[
            'id' => 'deterministic-dead-loop',
            'capability_key' => 'code.engineering',
            'instruction' => 'DEAD_LOOP repeat the same completed call and result',
            'selection_reason' => '验证真实闭环只纠偏一次后确定性终止',
        ]],
    ], 'root-dead-loop');
    $deadLoopEvents = (string)ob_get_clean();
    $deadLoopAgent = $deadLoopResult['structured_content']['deterministic-dead-loop'] ?? [];
    smokeAssert(
        ($deadLoopAgent['error']['code'] ?? '') === 'dead_loop_detected'
            && is_array($deadLoopAgent['error']['evidence'] ?? null)
            && str_contains($deadLoopEvents, '"event":"agent.loop.detected"'),
        'A repeated completed-call loop did not return structured evidence after one recovery'
    );
    smokeAssert(
        TeamWorkProtocol::terminalRunStatus(true, 0, 1, false) === 'failed'
            && TeamWorkProtocol::terminalRunStatus(true, 1, 1, false) === 'failed'
            && TeamWorkProtocol::terminalRunStatus(true, 1, 0, false) === 'failed'
            && TeamWorkProtocol::terminalRunStatus(true, 1, 0, true, false, 'completed') === 'failed'
            && TeamWorkProtocol::terminalRunStatus(true, 1, 1, false, false, 'partial') === 'partial'
            && TeamWorkProtocol::terminalRunStatus(false, 0, 0, false, true) === 'completed',
        'Team terminal status bypassed explicit finalization or direct response evidence'
    );
    smokeAssert(
        TeamWorkProtocol::requiresTeamRuntime('agent', false)
            && !TeamWorkProtocol::requiresTeamRuntime('agent', true)
            && !TeamWorkProtocol::requiresTeamRuntime('normal', false),
        'Work runtime fail-closed boundary is incorrect'
    );

    ob_start();
    $started = microtime(true);
    $result = $coordinator->executeDelegation([
        'tasks' => [
            ['id' => 'task-a', 'capability_key' => 'app.lifecycle', 'instruction' => 'parallel success', 'selection_reason' => '应用生命周期任务'],
            ['id' => 'task-b', 'capability_key' => 'search.web_research', 'instruction' => 'FORCE_FAIL', 'selection_reason' => '联网调研任务'],
            ['id' => 'task-c', 'capability_key' => 'code.engineering', 'instruction' => 'use dependency', 'selection_reason' => '代码工程任务', 'depends_on' => ['task-a']],
        ],
    ], 'root-call');
    $elapsed = microtime(true) - $started;
    $parallelEvents = (string)ob_get_clean();

    smokeAssert($result['ok'] === true, 'Partial Agent failure should not abort the team result');
    smokeAssert($result['structured_content']['task-a']['status'] === 'success', 'Parallel success branch failed');
    smokeAssert(
        ($result['structured_content']['task-a']['structured_content']['successful_tool_calls'] ?? 0) > 0,
        'Employee returned success without successful tool evidence'
    );
    smokeAssert(
        $result['structured_content']['task-b']['status'] === 'error',
        'Failure branch was not preserved: ' . json_encode($result['structured_content'])
    );
    smokeAssert($result['structured_content']['task-c']['status'] === 'success', 'DAG dependent branch failed');
    smokeAssert($elapsed < 1.15, sprintf('Independent tasks were not parallel enough (%.3fs)', $elapsed));
    smokeAssert(
        substr_count($parallelEvents, '"event":"agent.turn.started"') >= 3
            && substr_count($parallelEvents, '"event":"agent.turn.completed"') >= 3,
        'Parallel employee turns did not all produce streaming lifecycle events'
    );

    ob_start();
    $cooperativeResult = $coordinator->executeDelegation([
        'tasks' => [
            [
                'id' => 'cooperative-slow',
                'capability_key' => 'code.engineering',
                'instruction' => 'COOPERATIVE_SLOW execute one tool call and then think for a long time',
                'selection_reason' => '验证慢成员不会形成整批屏障',
            ],
            [
                'id' => 'cooperative-fast',
                'capability_key' => 'code.engineering',
                'instruction' => 'COOPERATIVE_FAST execute one tool call and finish immediately',
                'selection_reason' => '验证快成员可独立进入下一轮并完成',
            ],
        ],
    ], 'root-cooperative');
    ob_end_clean();
    smokeAssert(
        ($cooperativeResult['structured_content']['cooperative-fast']['status'] ?? '') === 'success'
            && ($cooperativeResult['structured_content']['cooperative-slow']['status'] ?? '') === 'success',
        'Cooperative scheduler test tasks did not finish'
    );
    $fastCompleteSeq = (int)$pdo->query(
        "SELECT MAX(seq) FROM team_run_events
         WHERE task_id='cooperative-fast' AND event_name='agent.turn.completed'"
    )->fetchColumn();
    $slowLastTurnSeq = (int)$pdo->query(
        "SELECT MAX(seq) FROM team_run_events
         WHERE task_id='cooperative-slow' AND event_name='agent.turn.completed'"
    )->fetchColumn();
    smokeAssert(
        $fastCompleteSeq > 0 && $slowLastTurnSeq > 0 && $fastCompleteSeq < $slowLastTurnSeq,
        'Fast member was still held behind the slow member batch barrier'
    );

    ob_start();
    $queuedResult = $coordinator->executeDelegation([
        'tasks' => [
            ['id' => 'slot-slow', 'capability_key' => 'code.engineering', 'instruction' => 'COOPERATIVE_SLOW occupy one slot', 'selection_reason' => '慢槽位'],
            ['id' => 'slot-fast-a', 'capability_key' => 'code.engineering', 'instruction' => 'COOPERATIVE_FAST release a slot', 'selection_reason' => '快槽位 A'],
            ['id' => 'slot-fast-b', 'capability_key' => 'code.engineering', 'instruction' => 'COOPERATIVE_FAST release another slot', 'selection_reason' => '快槽位 B'],
            ['id' => 'slot-queued', 'capability_key' => 'code.engineering', 'instruction' => 'COOPERATIVE_FAST start as soon as any slot is free', 'selection_reason' => '排队槽位'],
        ],
    ], 'root-slot-queue');
    ob_end_clean();
    smokeAssert(
        count(array_filter(
            $queuedResult['structured_content'],
            static fn(array $item): bool => ($item['status'] ?? '') === 'success'
        )) === 4,
        'Queued parallel tasks did not all finish'
    );
    $queuedStartedSeq = (int)$pdo->query(
        "SELECT MIN(seq) FROM team_run_events
         WHERE task_id='slot-queued' AND event_name='agent.started'"
    )->fetchColumn();
    $slotSlowDoneSeq = (int)$pdo->query(
        "SELECT MAX(seq) FROM team_run_events
         WHERE task_id='slot-slow' AND event_name='agent.turn.completed'"
    )->fetchColumn();
    smokeAssert(
        $queuedStartedSeq > 0 && $slotSlowDoneSeq > 0 && $queuedStartedSeq < $slotSlowDoneSeq,
        'An idle Work slot did not immediately start the queued task'
    );

    ob_start();
    $toolWaitResult = $coordinator->executeDelegation([
        'tasks' => [
            [
                'id' => 'tool-wait-slow',
                'capability_key' => 'code.engineering',
                'instruction' => 'TOOL_WAIT_SLOW wait inside a long local tool call',
                'selection_reason' => '验证工具等待期间调度器仍推进其他成员',
            ],
            [
                'id' => 'tool-wait-fast',
                'capability_key' => 'code.engineering',
                'instruction' => 'COOPERATIVE_FAST finish while the peer tool is still running',
                'selection_reason' => '验证快成员不被慢工具阻塞',
            ],
        ],
    ], 'root-tool-cooperative');
    ob_end_clean();
    smokeAssert(
        ($toolWaitResult['structured_content']['tool-wait-slow']['status'] ?? '') === 'success'
            && ($toolWaitResult['structured_content']['tool-wait-fast']['status'] ?? '') === 'success',
        'Tool-wait cooperative tasks did not finish'
    );
    $fastAgentDoneSeq = (int)$pdo->query(
        "SELECT MIN(seq) FROM team_run_events
         WHERE task_id='tool-wait-fast' AND event_name='agent.completed'"
    )->fetchColumn();
    $slowToolDoneSeq = (int)$pdo->query(
        "SELECT MAX(seq) FROM team_run_events
         WHERE task_id='tool-wait-slow' AND event_name='tool.completed'"
    )->fetchColumn();
    smokeAssert(
        $fastAgentDoneSeq > 0 && $slowToolDoneSeq > 0 && $fastAgentDoneSeq < $slowToolDoneSeq,
        'A long tool call still blocked every other member from completing'
    );

    $approvalA = $repository->createApproval(
        'smoke-run', 1, 1, 'computer_agent', 'duplicate-call',
        'fake_executor', ['code' => 'safe'], 'test confirmation'
    );
    $approvalB = $repository->createApproval(
        'smoke-run', 1, 1, 'computer_agent', 'duplicate-call',
        'fake_executor', ['code' => 'safe'], 'test confirmation'
    );
    smokeAssert(
        $approvalA['id'] === $approvalB['id'],
        'Duplicate approval creation did not return the authoritative database ID'
    );
    $firstDecision = $repository->decideApproval(1, $approvalA['id'], 'allow_once');
    $secondDecision = $repository->decideApproval(1, $approvalA['id'], 'allow_once');
    smokeAssert(
        $firstDecision === 'allowed' && $secondDecision === 'allowed',
        'Repeated allow-once decision was not idempotent'
    );

    $longReasoning = str_repeat('逐步检查上下文与工具结果。', 6000);
    ob_start();
    $events->startTurn('long-history-turn', 'search_agent', 'moonya', 'long-history');
    $events->emitDelta(
        'reasoning',
        $longReasoning,
        'long-history-turn',
        'search_agent',
        'moonya',
        'long-history'
    );
    $events->emitDelta(
        'content',
        '长思考已完成。',
        'long-history-turn',
        'search_agent',
        'moonya',
        'long-history'
    );
    $events->completeTurn('long-history-turn');
    ob_end_clean();
    $historyPayload = $pdo->query(
        "SELECT payload FROM team_run_events
         WHERE event_name='agent.turn.completed' AND task_id='long-history'
         ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    $historyTurn = json_decode((string)$historyPayload, true);
    smokeAssert(
        ($historyTurn['reasoning_content'] ?? '') === $longReasoning
            && ($historyTurn['content'] ?? '') === '长思考已完成。',
        'Long reasoning turn was not preserved for history restoration'
    );

    ob_start();
    $events->startTurn(
        'smoke-run-moonya-turn-1',
        'moonya',
        null,
        null,
        ['phase' => 'planning', 'round' => 1, 'model' => 'deepseek-v4-flash']
    );
    $events->emitDelta('reasoning', '规划一', 'smoke-run-moonya-turn-1', 'moonya');
    $events->emitDelta('content', '委派一', 'smoke-run-moonya-turn-1', 'moonya');
    $events->completeTurn('smoke-run-moonya-turn-1');
    $events->startTurn(
        'smoke-run-moonya-turn-2',
        'moonya',
        null,
        null,
        ['phase' => 'coordination', 'round' => 2, 'model' => 'deepseek-v4-flash']
    );
    $events->emitDelta('reasoning', '规划二', 'smoke-run-moonya-turn-2', 'moonya');
    $events->emitDelta('content', '委派二', 'smoke-run-moonya-turn-2', 'moonya');
    $events->completeTurn('smoke-run-moonya-turn-2');
    ob_end_clean();
    $rootTurnRows = $pdo->query(
        "SELECT payload FROM team_run_events
         WHERE event_name='agent.turn.completed' AND agent_key='moonya'
         ORDER BY id DESC LIMIT 2"
    )->fetchAll(PDO::FETCH_COLUMN);
    $rootTurns = array_reverse(array_map(
        static fn(string $payload): array => json_decode($payload, true) ?: [],
        $rootTurnRows
    ));
    smokeAssert(
        ($rootTurns[0]['turn_id'] ?? '') === 'smoke-run-moonya-turn-1'
            && ($rootTurns[0]['reasoning_content'] ?? '') === '规划一'
            && ($rootTurns[0]['content'] ?? '') === '委派一'
            && ($rootTurns[0]['phase'] ?? '') === 'planning'
            && ($rootTurns[1]['turn_id'] ?? '') === 'smoke-run-moonya-turn-2'
            && ($rootTurns[1]['reasoning_content'] ?? '') === '规划二'
            && ($rootTurns[1]['content'] ?? '') === '委派二'
            && ($rootTurns[1]['phase'] ?? '') === 'coordination',
        'Root model requests were concatenated instead of persisted as independent turns'
    );
    ob_start();
    $events->startTurn(
        'smoke-run-moonya-turn-invalid-final',
        'moonya',
        null,
        null,
        ['phase' => 'final_synthesis', 'round' => 3]
    );
    $events->emitDelta(
        'content',
        '<|DSML|tool_calls>delegate_to_agents',
        'smoke-run-moonya-turn-invalid-final',
        'moonya'
    );
    $events->completeTurn(
        'smoke-run-moonya-turn-invalid-final',
        'failed',
        ['discard_content' => true]
    );
    ob_end_clean();
    $discardedPayload = json_decode((string)$pdo->query(
        "SELECT payload FROM team_run_events
         WHERE event_name='agent.turn.completed'
           AND payload LIKE '%smoke-run-moonya-turn-invalid-final%'
         ORDER BY id DESC LIMIT 1"
    )->fetchColumn(), true);
    smokeAssert(
        ($discardedPayload['content_discarded'] ?? false) === true
            && ($discardedPayload['content'] ?? null) === '',
        'Rejected final-synthesis protocol markup was persisted as assistant content'
    );

    ob_start();
    $cycle = $coordinator->executeDelegation(['tasks' => [
        ['id' => 'cycle-a', 'capability_key' => 'app.lifecycle', 'instruction' => 'a', 'selection_reason' => 'a', 'depends_on' => ['cycle-b']],
        ['id' => 'cycle-b', 'capability_key' => 'code.engineering', 'instruction' => 'b', 'selection_reason' => 'b', 'depends_on' => ['cycle-a']],
    ]], 'cycle-call');
    ob_end_clean();
    smokeAssert(
        $cycle['ok'] === false
            && $cycle['error']['code'] === 'invalid_delegation'
            && ($cycle['metadata']['dispatch_status'] ?? '') === 'rejected',
        'Cycle was not rejected during pre-execution validation'
    );

    $unauthorized = $coordinator->executeDelegation(['tasks' => [
        ['id' => 'bad', 'capability_key' => ['app.lifecycle', 'code.engineering'], 'instruction' => 'nested delegation', 'selection_reason' => 'invalid'],
    ]], 'bad-call');
    smokeAssert($unauthorized['ok'] === false && $unauthorized['error']['code'] === 'invalid_delegation', 'Nested/unauthorized delegation was accepted');

    ob_start();
    $textOnly = $coordinator->executeDelegation(['tasks' => [
        ['id' => 'text-only', 'capability_key' => 'file.office', 'instruction' => 'return text without a tool', 'selection_reason' => 'Office 产物任务'],
    ]], 'text-only-call');
    ob_end_clean();
    smokeAssert(
        ($textOnly['structured_content']['text-only']['error']['code'] ?? '') === 'tool_execution_required',
        'Employee pure text was incorrectly accepted as execution success'
    );

    ob_start();
    $officeWithoutArtifact = $coordinator->executeDelegation(['tasks' => [
        ['id' => 'office-no-artifact', 'capability_key' => 'file.office', 'instruction' => 'create an Office file but return no artifact', 'selection_reason' => 'Office 产物'],
    ]], 'office-no-artifact-call');
    ob_end_clean();
    smokeAssert(
        ($officeWithoutArtifact['structured_content']['office-no-artifact']['error']['code'] ?? '') === 'office_artifact_required',
        'Office execution without an artifact was incorrectly accepted'
    );

    $policy = new RiskPolicy();
    smokeAssert($policy->requiresApproval(['effect' => 'write', 'risk_level' => 'low', 'source' => 'native', 'reviewed' => 1], [], 'confirm_writes')[0], 'Write confirmation mode failed');
    smokeAssert($policy->requiresApproval(['effect' => 'read', 'risk_level' => 'high', 'source' => 'native', 'reviewed' => 1], [], 'high_risk')[0], 'High-risk mode failed');
    smokeAssert($policy->requiresApproval(['effect' => 'read', 'risk_level' => 'low', 'source' => 'mcp', 'reviewed' => 0], [], 'full_access')[0], 'Unreviewed MCP tool bypassed server policy');
    smokeAssert(
        $policy->effectivePolicy(['tool_key' => 'browser_automation_control'], ['action' => 'screenshot']) === ['read', 'low']
            && $policy->effectivePolicy(['tool_key' => 'browser_automation_control'], ['action' => 'navigate']) === ['write', 'medium']
            && $policy->effectivePolicy(['tool_key' => 'browser_automation_control'], ['action' => 'submit_sensitive']) === ['write', 'high'],
        'Browser action-level risk policy is invalid'
    );

    ob_start();
    $direct = $coordinator->executeDirectResponse([
        'response' => '你好，请问今天想让我帮你做什么？',
        'reason' => 'chat',
    ]);
    ob_end_clean();
    smokeAssert(
        TeamWorkProtocol::isDirectResponse($direct)
            && ($pdo->query("SELECT direct_response_reason FROM team_runs WHERE id='smoke-run'")->fetchColumn()) === 'chat',
        'MoonYa direct chat response did not complete through the coordination protocol'
    );

    $canonicalMethod = new ReflectionMethod(ToolGateway::class, 'canonicalToolName');
    $canonicalMethod->setAccessible(true);
    smokeAssert($canonicalMethod->invoke($gateway, 'execute_command') === 'shell_executor', 'Legacy shell alias failed');
    smokeAssert($canonicalMethod->invoke($gateway, 'execute_python') === 'python_executor', 'Legacy Python alias failed');
    $artifactMethod = new ReflectionMethod(ToolGateway::class, 'extractArtifacts');
    $artifactMethod->setAccessible(true);
    $jsonArtifacts = $artifactMethod->invoke($gateway, [
        'artifacts' => [],
        'structured_content' => [
            'output' => json_encode([
                'artifact_path' => 'D:\\MoonYa\\output\\verified.docx',
                'verification' => ['reopened' => true, 'paragraphs' => 3],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ],
    ]);
    smokeAssert(
        ($jsonArtifacts[0]['uri'] ?? '') === 'D:\\MoonYa\\output\\verified.docx',
        'JSON artifact_path from Python output was not recognized'
    );

    $forbiddenTool = $gateway->execute(
        'search_agent',
        'permission-test',
        'permission-call',
        'empty_schema_probe',
        []
    );
    smokeAssert(
        ($forbiddenTool['ok'] ?? true) === false
            && ($forbiddenTool['error']['code'] ?? '') === 'forbidden_tool',
        'ToolGateway did not reject a tool missing from the Agent grant matrix'
    );

    ob_start();
    $filePipShell = $gateway->execute(
        'file_agent',
        'office-policy',
        'office-shell-pip',
        'shell_executor',
        ['command' => 'python -m pip install python-pptx'],
        'file.office'
    );
    $filePipPython = $gateway->execute(
        'file_agent',
        'office-policy',
        'office-python-pip',
        'python_executor',
        ['code' => "import subprocess\nsubprocess.run(['python', '-m', 'pip', 'install', 'python-pptx'])"],
        'file.office'
    );
    $codePipShell = $gateway->execute(
        'code_agent',
        'code-policy',
        'code-shell-pip',
        'shell_executor',
        [
            'command' => 'python -m pip install project-package',
            'shell' => 'powershell',
            'phase' => 'act',
            'operation_id' => 'code-install-package',
            'intent' => 'Install project package',
            'success_criteria' => ['expected_exit_code' => 0],
        ],
        'code.engineering'
    );
    ob_end_clean();
    smokeAssert(
        ($filePipShell['error']['code'] ?? '') === 'managed_runtime_install_forbidden'
            && ($filePipPython['error']['code'] ?? '') === 'managed_runtime_install_forbidden'
            && ($codePipShell['ok'] ?? false) === true,
        'File Agent managed-runtime install guard is missing or leaked into Code Agent'
    );

    $projectGateway = new ToolGateway(
        $repository,
        $events,
        [],
        static function (string $url, string $body): array {
            return [
                'ok' => true,
                'content' => 'project inspection or verification evidence',
                'structured_content' => ['verified' => true],
                'artifacts' => [],
                'metadata' => ['test' => true],
                'error' => null,
            ];
        },
        1,
        1,
        'high_risk',
        $projectPath
    );
    $projectCoordinator = new TeamCoordinator(
        $repository,
        $events,
        $projectGateway,
        "http://127.0.0.1:{$port}/v1/chat/completions",
        '',
        'deepseek-v4-flash',
        $teamModelConfig
    );
    ob_start();
    $projectResult = $projectCoordinator->executeDelegation(['tasks' => [[
        'id' => 'workspace-project',
        'capability_key' => 'code.project_delivery',
        'instruction' => 'PROJECT_DELIVERY build a multi-page HTML/CSS/JS workspace and verify integration',
        'selection_reason' => '多模块代码项目必须使用项目组交付协议',
    ]]], 'root-project-delivery');
    $projectEvents = (string)ob_get_clean();
    $projectDelivery = $projectResult['structured_content']['workspace-project'] ?? [];
    smokeAssert(
        ($projectDelivery['status'] ?? '') === 'success'
            && ($projectDelivery['structured_content']['outcome'] ?? '') === 'completed'
            && ($projectDelivery['structured_content']['acceptance']['outcome'] ?? '') === 'completed',
        'Project lead/member/acceptance lifecycle did not complete: ' . json_encode($projectDelivery)
    );
    smokeAssert(
        str_contains($projectEvents, '"event":"project.team.started"')
            && str_contains($projectEvents, '"event":"project.contract.accepted"')
            && str_contains($projectEvents, '"event":"project.acceptance.completed"')
            && str_contains($projectEvents, '"role_label":"项目负责人"')
            && str_contains($projectEvents, '"role_label":"项目成员"'),
        'Project lifecycle events or user-facing roles are incomplete'
    );
    $projectActorCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM team_project_actors WHERE project_group_id=(
           SELECT id FROM team_project_groups WHERE root_task_id='workspace-project' LIMIT 1
         )"
    )->fetchColumn();
    smokeAssert($projectActorCount === 4, 'Project actor roster was not persisted');
    $notesStartedSeq = (int)$pdo->query(
        "SELECT MIN(seq) FROM team_run_events
         WHERE task_id='workspace-project.member.notes' AND event_name='agent.started'"
    )->fetchColumn();
    $studyFinishedSeq = (int)$pdo->query(
        "SELECT MAX(seq) FROM team_run_events
         WHERE task_id='workspace-project.member.study' AND event_name='agent.turn.completed'"
    )->fetchColumn();
    smokeAssert(
        $notesStartedSeq > 0 && $studyFinishedSeq > 0 && $notesStartedSeq < $studyFinishedSeq,
        'A newly satisfied project dependency waited for an unrelated slow member'
    );
    ob_start();
    $projectFinalize = $projectCoordinator->executeFinalization([
        'outcome' => 'completed',
        'evidence_task_ids' => ['workspace-project'],
        'unresolved' => [],
    ], 'project-finalize');
    ob_end_clean();
    smokeAssert(
        ($projectFinalize['ok'] ?? false) === true,
        'Validated project acceptance was not accepted as finalization evidence'
    );

    $cancelHelperPipes = [];
    $cancelHelper = proc_open([
        PHP_BINARY,
        '-r',
        'usleep(600000); $pdo = new PDO("sqlite:" . $argv[1]); '
            . '$pdo->exec("UPDATE team_runs SET status=\'cancelled\' WHERE id=\'smoke-run\'");',
        $databasePath,
    ], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $cancelHelperPipes, __DIR__);
    smokeAssert(is_resource($cancelHelper), 'Cannot start the cancellation helper');
    ob_start();
    $silentStarted = microtime(true);
    $silentCancelled = $coordinator->executeDelegation(['tasks' => [[
        'id' => 'silent-model-cancel',
        'capability_key' => 'code.engineering',
        'instruction' => 'SILENT_CANCEL keep the model connection silent until cancellation',
        'selection_reason' => '验证静默推理没有总超时且仍响应用户取消',
    ]]], 'silent-cancel-call');
    $silentElapsed = microtime(true) - $silentStarted;
    $silentEvents = (string)ob_get_clean();
    foreach ($cancelHelperPipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($cancelHelper);
    $silentAgent = $silentCancelled['structured_content']['silent-model-cancel'] ?? [];
    smokeAssert(
        ($silentAgent['error']['code'] ?? '') === 'run_cancelled'
            && $silentElapsed < 4.5
            && str_contains($silentEvents, 'model_thinking'),
        'A silent model request did not wait without a deadline and close promptly on cancellation'
    );
    $pdo->exec("UPDATE team_runs SET status='running', final_summary=NULL, completed_at=NULL WHERE id='smoke-run'");

    ob_start();
    $acceptedFinalize = $coordinator->executeFinalization([
        'outcome' => 'completed',
        'evidence_task_ids' => ['long-over-old-limit'],
        'unresolved' => [],
    ], 'accepted-finalize');
    $finalizationEvents = (string)ob_get_clean();
    smokeAssert(
        TeamWorkProtocol::isFinalization($acceptedFinalize)
            && str_contains($finalizationEvents, '"event":"coordination.finalized"')
            && TeamWorkProtocol::terminalRunStatus(true, 1, 0, false, false, 'completed') === 'completed'
            && TeamWorkProtocol::terminalRunStatus(true, 1, 0, false, false, null) === 'failed',
        'Explicit finalization evidence did not become the only Work completion gate'
    );
    smokeAssert(
        TeamWorkProtocol::containsToolProtocolMarkup('<|DSML|tool_calls>')
            && TeamWorkProtocol::containsToolProtocolMarkup('<｜｜DSML｜｜tool_calls>')
            && !TeamWorkProtocol::containsToolProtocolMarkup('正常的最终答复'),
        'Final response tool-protocol validation is incomplete'
    );

    $cancelledApproval = $repository->createApproval(
        'smoke-run', 1, 1, 'computer_agent', 'cancelled-approval',
        'fake_executor', ['code' => 'must not execute'], 'cancel this confirmation'
    );
    smokeAssert(
        $repository->cancelRun(1, 'smoke-run')
            && $repository->cancelRun(1, 'smoke-run')
            && $pdo->query("SELECT status FROM team_runs WHERE id='smoke-run'")->fetchColumn() === 'cancelled'
            && $repository->getApprovalStatus((string)$cancelledApproval['id'], 1) === 'denied',
        'Team run cancellation is not atomic, user-scoped, and idempotent'
    );
    $cancelledTool = $gateway->execute(
        'computer_agent',
        'cancelled-task',
        'cancelled-call',
        'fake_executor',
        ['code' => 'must not execute']
    );
    smokeAssert(
        ($cancelledTool['ok'] ?? true) === false
            && ($cancelledTool['error']['code'] ?? '') === 'run_cancelled',
        'A cancelled run still allowed a new tool call'
    );

    echo "multi_agent_v1 smoke: PASS ({$elapsed}s)\n";
} catch (Throwable $error) {
    fwrite(STDERR, "multi_agent_v1 smoke: FAIL\n" . $error . "\n");
    $exitCode = 1;
} finally {
    $pdo = null;
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($process);
    proc_close($process);
    @unlink($databasePath);
    @rmdir($projectPath);
}
exit($exitCode ?? 0);
