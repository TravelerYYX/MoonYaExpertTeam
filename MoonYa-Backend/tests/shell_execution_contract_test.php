<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Services/TeamRepository.php';
require_once dirname(__DIR__) . '/Services/TeamEventEmitter.php';
require_once dirname(__DIR__) . '/Services/ToolGateway.php';

function shellAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function shellArgs(
    string $command,
    string $phase,
    string $operationId,
    bool $blocking = true,
    ?string $completionMode = null
): array
{
    $result = [
        'command' => $command,
        'shell' => 'powershell',
        'phase' => $phase,
        'operation_id' => $operationId,
        'intent' => "{$phase} {$operationId}",
        'success_criteria' => $phase === 'verify'
            ? ['expected_exit_code' => 0, 'stdout_contains' => ['TARGET_OK']]
            : ['expected_exit_code' => 0],
        'blocking' => $blocking,
    ];
    if ($completionMode !== null) {
        $result['completion_mode'] = $completionMode;
    }
    return $result;
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE system_prompts (id INTEGER PRIMARY KEY, name TEXT, display_name TEXT, prompt TEXT, enabled INTEGER)');
$pdo->exec('CREATE TABLE agents (
    id INTEGER PRIMARY KEY, agent_key TEXT UNIQUE, display_name TEXT, role_summary TEXT,
    avatar_url TEXT, prompt_name TEXT, model_override TEXT, is_coordinator INTEGER,
    enabled INTEGER, sort_order INTEGER
)');
$pdo->exec('CREATE TABLE agent_runtime_config (config_key TEXT, config_value TEXT)');
$pdo->exec('CREATE TABLE tool_registry (
    id INTEGER PRIMARY KEY, tool_key TEXT, display_name TEXT, description TEXT,
    input_schema TEXT, output_schema TEXT, source TEXT, transport TEXT,
    transport_config TEXT, effect TEXT, risk_level TEXT, reviewed INTEGER, enabled INTEGER
)');
$pdo->exec('CREATE TABLE agent_tool_grants (agent_id INTEGER, tool_id INTEGER, enabled INTEGER)');
$pdo->exec('CREATE TABLE agent_routing_capabilities (
    id INTEGER PRIMARY KEY AUTOINCREMENT, capability_key TEXT, agent_id INTEGER,
    display_name TEXT, description TEXT, examples_json TEXT, exclusions_json TEXT,
    required_tools_json TEXT, enabled INTEGER, sort_order INTEGER
)');
$pdo->exec('CREATE TABLE team_runs (
    id TEXT PRIMARY KEY, user_id INTEGER, conversation_id INTEGER, status TEXT,
    completed_at TEXT, planning_rejections INTEGER DEFAULT 0
)');
$pdo->exec('CREATE TABLE team_run_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT, seq INTEGER, event_name TEXT,
    agent_key TEXT, parent_agent_key TEXT, task_id TEXT, tool_call_id TEXT, payload TEXT
)');
// Approvals are auto-allowed in this contract harness so execution does not block.
$pdo->exec('CREATE TABLE tool_approvals (
    id TEXT PRIMARY KEY, run_id TEXT, user_id INTEGER, conversation_id INTEGER,
    agent_key TEXT, tool_call_id TEXT, tool_key TEXT, arguments_hash TEXT,
    reason TEXT, status TEXT DEFAULT "allowed", expires_at TEXT, decided_at TEXT,
    UNIQUE(run_id, tool_call_id)
)');
$pdo->exec("INSERT INTO system_prompts VALUES (1, 'shell-test', 'Shell test', 'Use tools.', 1)");
$pdo->exec("INSERT INTO agents VALUES (1, 'computer', 'Computer Agent', 'system', '', 'shell-test', '', 0, 1, 1)");
$pdo->exec("INSERT INTO tool_registry VALUES (
    1, 'shell_executor', 'Shell executor', 'Managed shell',
    '{\"type\":\"object\",\"properties\":{}}', '{\"type\":\"object\"}',
    'native', 'launcher_file', '{\"action\":\"execute_command\"}', 'write', 'high', 1, 1
)");
$pdo->exec("INSERT INTO tool_registry VALUES (
    2, 'recycle_bin_status', 'Recycle Bin status', 'Authoritative Windows status',
    '{\"type\":\"object\",\"properties\":{}}', '{\"type\":\"object\"}',
    'native', 'launcher_file', '{\"action\":\"recycle_bin_status\"}', 'read', 'low', 1, 1
)");
$pdo->exec('INSERT INTO agent_tool_grants VALUES (1, 1, 1)');
$pdo->exec('INSERT INTO agent_tool_grants VALUES (1, 2, 1)');
$pdo->exec("INSERT INTO agent_runtime_config VALUES ('max_shell_preflight_corrections', '1')");
$pdo->exec("INSERT INTO team_runs VALUES ('shell-run', 1, 1, 'running', NULL, 0)");

$actionExecutions = 0;
$verifyExecutions = 0;
$inspectExecutions = 0;
$backgroundCommands = [];
$stoppedBackgroundCommands = 0;
$recycleLogicalEmpty = false;
$recycleStatusQueries = 0;
$relay = static function (string $url, string $body) use (
    &$actionExecutions,
    &$verifyExecutions,
    &$inspectExecutions,
    &$backgroundCommands,
    &$stoppedBackgroundCommands,
    &$recycleLogicalEmpty,
    &$recycleStatusQueries
): array {
    $payload = json_decode($body, true) ?: [];
    $command = (string)($payload['command'] ?? '');
    $phase = (string)($payload['phase'] ?? '');
    if (($payload['action'] ?? '') === 'recycle_bin_status') {
        $recycleStatusQueries++;
        return [
            'success' => true,
            'status' => 'success',
            'stage' => 'inspect',
            'source' => 'SHQueryRecycleBin',
            'isEmpty' => $recycleLogicalEmpty,
            'itemCount' => $recycleLogicalEmpty ? 0 : 2,
            'sizeBytes' => $recycleLogicalEmpty ? 0 : 247,
            // Deliberate physical scaffolding fixture: the gateway must never
            // use this unrelated implementation detail as the logical assertion.
            'physicalScaffoldingEntries' => 3,
            'message' => 'authoritative recycle status',
        ];
    }
    if (($payload['action'] ?? '') === 'get_command_status') {
        $commandId = (string)($payload['command_id'] ?? '');
        return $backgroundCommands[$commandId] ?? ['success' => false, 'message' => 'missing'];
    }
    if (($payload['action'] ?? '') === 'stop_command') {
        $commandId = (string)($payload['command_id'] ?? '');
        $stoppedBackgroundCommands++;
        if (isset($backgroundCommands[$commandId])) {
            $backgroundCommands[$commandId]['status'] = 'killed';
            $backgroundCommands[$commandId]['command_ok'] = false;
        }
        return ['success' => true, 'command_id' => $commandId, 'status' => 'killed', 'command_ok' => false];
    }
    if (($payload['action'] ?? '') === 'validate_command') {
        $version = str_contains($command, 'NO_VERSION') ? '' : 'shell-preflight-v1';
        if (str_contains($command, 'SYNTAX_BAD')) {
            return [
                'valid' => false,
                'status' => 'error',
                'stage' => 'preflight',
                'shell' => 'powershell',
                'effect' => 'unknown',
                'riskLevel' => 'high',
                'errorCode' => 'shell_preflight_failed',
                'error' => 'MissingEndParenthesisInExpression at line 2 column 4',
                'diagnostics' => [[
                    'errorId' => 'MissingEndParenthesisInExpression',
                    'startLineNumber' => 2,
                    'startColumnNumber' => 4,
                    'suggestion' => '补齐右括号',
                ]],
                'commandFingerprint' => hash('sha256', $command),
                'commandPreview' => $command,
                'commandPreflightVersion' => $version,
            ];
        }
        $unknown = str_contains($command, 'UNKNOWN_DYNAMIC');
        $readOnly = !$unknown && ($phase !== 'act' || str_starts_with($command, 'Get-'));
        return [
            'valid' => true,
            'status' => 'success',
            'stage' => 'preflight',
            'shell' => 'powershell',
            'effect' => $unknown ? 'unknown' : ($readOnly ? 'read' : 'destructive'),
            'riskLevel' => $readOnly ? 'low' : 'high',
            'commandFingerprint' => hash('sha256', $command),
            'commandPreview' => $command,
            'commandPreflightVersion' => $version,
            'authoritativeVerifier' => str_contains($command, 'Clear-LogicalRecycle')
                ? 'recycle_bin_status'
                : '',
        ];
    }

    if ($phase === 'act') {
        $actionExecutions++;
        if (in_array($command, ['Clear-LogicalRecycle', 'Clear-LogicalRecycle-Recovery'], true)) {
            $recycleLogicalEmpty = true;
        }
    } elseif ($phase === 'verify') {
        $verifyExecutions++;
    } else {
        $inspectExecutions++;
    }
    if (($payload['blocking'] ?? true) === false) {
        $commandId = 'bg-' . (count($backgroundCommands) + 1);
        $completionMode = (string)($payload['completion_mode'] ?? 'finite');
        $runningState = $phase === 'inspect' ? 'inspection_running' : ($phase === 'verify' ? 'verification_running' : 'action_running');
        $doneState = $phase === 'inspect' ? 'inspected' : ($phase === 'verify' ? 'verified_completed' : 'action_succeeded_unverified');
        $backgroundCommands[$commandId] = [
            'success' => true,
            'command_id' => $commandId,
            'status' => $completionMode === 'finite' ? 'done' : 'running',
            'command_ok' => $completionMode === 'finite' ? true : null,
            'stdout' => $phase === 'verify' ? 'TARGET_OK' : 'background done',
            'stderr' => '',
            'output_revision' => 1,
            'completion_mode' => $completionMode,
            'operation_receipt' => [
                'operation_id' => (string)($payload['operation_id'] ?? ''),
                'phase' => $phase,
                'state' => $completionMode === 'finite' ? $doneState : $runningState,
                'command_fingerprint' => hash('sha256', $command),
            ],
        ];
        return [
            'success' => true,
            'command_id' => $commandId,
            'status' => 'running',
            'command_ok' => null,
            'stdout' => '',
            'stderr' => '',
            'output_revision' => 0,
            'completion_mode' => $completionMode,
            'operation_receipt' => [
                'operation_id' => (string)($payload['operation_id'] ?? ''),
                'phase' => $phase,
                'state' => $runningState,
                'command_fingerprint' => hash('sha256', $command),
            ],
        ];
    }
    if (str_contains($command, 'VERIFY_FALSE')) {
        return [
            'status' => 'error',
            'stage' => 'verify',
            'errorCode' => 'verification_assertion_failed',
            'error' => 'TARGET_OK missing',
            'exitCode' => 0,
            'commandFingerprint' => hash('sha256', $command),
        ];
    }
    if (str_contains($command, 'VERIFY_RUNTIME')) {
        return [
            'status' => 'error',
            'stage' => 'verify',
            'errorCode' => 'verification_runtime_failed',
            'error' => 'verification process exited 1',
            'exitCode' => 1,
            'commandFingerprint' => hash('sha256', $command),
        ];
    }
    return [
        'status' => 'success',
        'stage' => $phase === 'verify' ? 'verify' : 'execute',
        'output' => $phase === 'verify' ? 'TARGET_OK' : 'done',
        'exitCode' => 0,
        'commandFingerprint' => hash('sha256', $command),
    ];
};

$repository = new TeamRepository($pdo);
$events = new TeamEventEmitter($repository, 'shell-run');
$gateway = new ToolGateway($repository, $events, [], $relay, 1, 1, 'high_risk', sys_get_temp_dir());

ob_start();
try {
    $inspect = $gateway->execute('computer', 'task-a', 'inspect-a', 'shell_executor', shellArgs('Get-ChildItem', 'inspect', 'op-a'));
    shellAssert(($inspect['ok'] ?? false) === true, 'Read-only inspect failed');
    shellAssert((int)$pdo->query('SELECT COUNT(*) FROM tool_approvals')->fetchColumn() === 0, 'Read-only inspect created approval');
    $verifyBeforeAct = $gateway->execute('computer', 'task-a', 'verify-before-act', 'shell_executor', shellArgs('Get-TargetState', 'verify', 'op-a'));
    shellAssert(($verifyBeforeAct['error']['code'] ?? '') === 'shell_verify_before_action', 'inspect incorrectly authorized verify before act');

    $bad = $gateway->execute('computer', 'task-a', 'bad-a', 'shell_executor', shellArgs('SYNTAX_BAD(', 'act', 'op-a'));
    shellAssert(($bad['error']['code'] ?? '') === 'shell_preflight_failed', 'Syntax error did not return precise preflight failure');
    shellAssert($actionExecutions === 0, 'Syntax error launched target process');
    shellAssert((int)$pdo->query('SELECT COUNT(*) FROM tool_approvals')->fetchColumn() === 0, 'Syntax error created approval');

    $badAgain = $gateway->execute('computer', 'task-a', 'bad-a-again', 'shell_executor', shellArgs('SYNTAX_BAD(', 'act', 'op-a'));
    shellAssert(($badAgain['error']['code'] ?? '') === 'shell_preflight_repeated', 'Repeated invalid fingerprint was not blocked');

    $badCorrection1 = $gateway->execute('computer', 'task-correction', 'bad-correction-1', 'shell_executor', shellArgs('SYNTAX_BAD one', 'act', 'op-correction'));
    $badCorrection2 = $gateway->execute('computer', 'task-correction', 'bad-correction-2', 'shell_executor', shellArgs('SYNTAX_BAD two', 'act', 'op-correction'));
    shellAssert(
        ($badCorrection1['error']['code'] ?? '') === 'shell_preflight_failed'
            && ($badCorrection2['error']['code'] ?? '') === 'shell_preflight_failed',
        'Different preflight corrections were incorrectly capped'
    );

    $missingMode = $gateway->execute(
        'computer',
        'task-background-contract',
        'background-missing-mode',
        'shell_executor',
        shellArgs('Get-ChildItem', 'inspect', 'op-background-contract', false)
    );
    shellAssert(($missingMode['error']['code'] ?? '') === 'completion_mode_required', 'Background completion mode was not required');

    $finiteInspect = $gateway->execute(
        'computer',
        'task-background-contract',
        'background-finite-inspect',
        'shell_executor',
        shellArgs('Get-ChildItem', 'inspect', 'op-background-inspect', false, 'finite')
    );
    shellAssert(
        ($finiteInspect['ok'] ?? false)
            && ($finiteInspect['structured_content']['status'] ?? '') === 'done'
            && ($finiteInspect['structured_content']['command_ok'] ?? false) === true
            && ($finiteInspect['metadata']['operation_receipt']['state'] ?? '') === 'inspected',
        'Managed finite inspect did not return one final result'
    );

    $act = $gateway->execute('computer', 'task-a', 'act-a', 'shell_executor', shellArgs('Remove-Item fake', 'act', 'op-a'));
    shellAssert(($act['ok'] ?? false) === true && $actionExecutions === 1, 'First mutation did not execute exactly once');
    shellAssert((int)$pdo->query('SELECT COUNT(*) FROM tool_approvals')->fetchColumn() === 1, 'Mutation did not create exactly one approval');

    $duplicate = $gateway->execute('computer', 'task-a', 'act-a-duplicate', 'shell_executor', shellArgs('Remove-Item fake', 'act', 'op-a'));
    shellAssert(($duplicate['structured_content']['status'] ?? '') === 'skipped' && $actionExecutions === 1, 'Successful mutation was repeated');
    shellAssert((int)$pdo->query('SELECT COUNT(*) FROM tool_approvals')->fetchColumn() === 1, 'Skipped duplicate created another approval');

    $verifySyntax = $gateway->execute('computer', 'task-a', 'verify-bad-a', 'shell_executor', shellArgs('SYNTAX_BAD verify', 'verify', 'op-a'));
    shellAssert(($verifySyntax['ok'] ?? true) === false && $actionExecutions === 1, 'Verification syntax failure reopened mutation');

    $verify = $gateway->execute('computer', 'task-a', 'verify-a', 'shell_executor', shellArgs('Get-TargetState', 'verify', 'op-a'));
    shellAssert(($verify['ok'] ?? false) === true && $verifyExecutions === 1, 'Corrected read-only verification failed');
    shellAssert((int)$pdo->query('SELECT COUNT(*) FROM tool_approvals')->fetchColumn() === 1, 'Read-only verification created approval');

    $afterComplete = $gateway->execute('computer', 'task-a', 'act-after-complete', 'shell_executor', shellArgs('Remove-Item another', 'act', 'op-a'));
    shellAssert(($afterComplete['structured_content']['status'] ?? '') === 'skipped' && $actionExecutions === 1, 'Verified operation accepted later mutation');

    $actB = $gateway->execute('computer', 'task-b', 'act-b', 'shell_executor', shellArgs('Remove-Item b', 'act', 'op-b'));
    $verifyFalseArgs = shellArgs('Get-VERIFY_FALSE', 'verify', 'op-b');
    $verifyFalse = $gateway->execute('computer', 'task-b', 'verify-false-b', 'shell_executor', $verifyFalseArgs);
    shellAssert(($verifyFalse['error']['code'] ?? '') === 'verification_assertion_failed', 'False output assertion was not recorded');
    $recovery = $gateway->execute('computer', 'task-b', 'recovery-b', 'shell_executor', shellArgs('Remove-Item b-recovery', 'act', 'op-b'));
    shellAssert(($actB['ok'] ?? false) && ($recovery['ok'] ?? false) && $actionExecutions === 3, 'Different recovery action was not allowed after deterministic false verification');

    $runtimeAct = $gateway->execute('computer', 'task-runtime', 'runtime-act', 'shell_executor', shellArgs('Remove-Item runtime', 'act', 'op-runtime'));
    $runtimeVerify = $gateway->execute('computer', 'task-runtime', 'runtime-verify', 'shell_executor', shellArgs('Get-VERIFY_RUNTIME', 'verify', 'op-runtime'));
    $runtimeRecovery = $gateway->execute('computer', 'task-runtime', 'runtime-recovery', 'shell_executor', shellArgs('Remove-Item runtime-recovery', 'act', 'op-runtime'));
    shellAssert(
        ($runtimeAct['ok'] ?? false)
            && ($runtimeVerify['error']['code'] ?? '') === 'verification_runtime_failed'
            && ($runtimeRecovery['structured_content']['status'] ?? '') === 'skipped'
            && $actionExecutions === 4,
        'Verification runtime failure incorrectly reopened mutation'
    );
    $mismatchedNativeVerifier = $gateway->execute('computer', 'task-runtime', 'runtime-wrong-verifier', 'recycle_bin_status', [
        'phase' => 'verify',
        'operation_id' => 'op-runtime',
        'intent' => 'must not verify an unrelated operation',
        'expected_empty' => true,
    ]);
    shellAssert(
        ($mismatchedNativeVerifier['error']['code'] ?? '') === 'authoritative_verifier_mismatch',
        'Recycle Bin status was allowed to verify an unrelated operation'
    );

    $nativeVerifyBeforeAct = $gateway->execute('computer', 'task-native', 'native-verify-before-act', 'recycle_bin_status', [
        'phase' => 'verify',
        'operation_id' => 'native-recycle',
        'intent' => 'verify logical Recycle Bin state',
        'expected_empty' => true,
    ]);
    shellAssert(
        ($nativeVerifyBeforeAct['error']['code'] ?? '') === 'verification_before_action',
        'Authoritative status verify incorrectly ran before act'
    );

    $approvalCountBeforeNativeInspect = (int)$pdo->query('SELECT COUNT(*) FROM tool_approvals')->fetchColumn();
    $nativeInspect = $gateway->execute('computer', 'task-native', 'native-inspect', 'recycle_bin_status', [
        'phase' => 'inspect',
        'operation_id' => 'native-recycle',
        'intent' => 'inspect logical Recycle Bin state',
    ]);
    shellAssert(
        ($nativeInspect['ok'] ?? false)
            && ($nativeInspect['structured_content']['isEmpty'] ?? null) === false
            && $recycleStatusQueries === 1
            && (int)$pdo->query('SELECT COUNT(*) FROM tool_approvals')->fetchColumn() === $approvalCountBeforeNativeInspect,
        'Authoritative Recycle Bin inspect was not read-only or did not preserve logical state'
    );

    $nativeAct = $gateway->execute(
        'computer',
        'task-native',
        'native-act',
        'shell_executor',
        shellArgs('Clear-LogicalRecycle', 'act', 'native-recycle')
    );
    $shellVerifyForNativeOperation = $gateway->execute(
        'computer',
        'task-native',
        'native-shell-verify-forbidden',
        'shell_executor',
        shellArgs('Get-TargetState', 'verify', 'native-recycle')
    );
    shellAssert(
        ($shellVerifyForNativeOperation['error']['code'] ?? '') === 'authoritative_verifier_required',
        'Shell output was allowed to replace the authoritative Recycle Bin verifier'
    );
    $nativeVerify = $gateway->execute('computer', 'task-native', 'native-verify', 'recycle_bin_status', [
        'phase' => 'verify',
        'operation_id' => 'native-recycle',
        'intent' => 'verify logical Recycle Bin state',
        'expected_empty' => true,
    ]);
    $actionsAfterNativeVerify = $actionExecutions;
    $nativeDuplicateAct = $gateway->execute(
        'computer',
        'task-native',
        'native-act-duplicate',
        'shell_executor',
        shellArgs('Remove-Item ShouldNeverRun', 'act', 'native-recycle')
    );
    shellAssert(
        ($nativeAct['ok'] ?? false)
            && ($nativeVerify['ok'] ?? false)
            && ($nativeVerify['metadata']['operation_receipt']['state'] ?? '') === 'verified_completed'
            && ($nativeVerify['structured_content']['itemCount'] ?? -1) === 0
            && ($nativeVerify['structured_content']['physicalScaffoldingEntries'] ?? -1) === 3
            && ($nativeDuplicateAct['structured_content']['status'] ?? '') === 'skipped'
            && $actionExecutions === $actionsAfterNativeVerify,
        'Native Recycle Bin verification did not close the operation idempotently'
    );

    $recycleLogicalEmpty = false;
    $nativeFalseAct = $gateway->execute(
        'computer',
        'task-native-false',
        'native-false-act',
        'shell_executor',
        shellArgs('Clear-LogicalRecycle-NoEffect', 'act', 'native-recycle-false')
    );
    $nativeFalseVerify = $gateway->execute('computer', 'task-native-false', 'native-false-verify', 'recycle_bin_status', [
        'phase' => 'verify',
        'operation_id' => 'native-recycle-false',
        'intent' => 'prove logical Recycle Bin state',
        'expected_empty' => true,
    ]);
    $actionsBeforeNativeRecovery = $actionExecutions;
    $nativeRecovery = $gateway->execute(
        'computer',
        'task-native-false',
        'native-false-recovery',
        'shell_executor',
        shellArgs('Clear-LogicalRecycle-Recovery', 'act', 'native-recycle-false')
    );
    shellAssert(
        ($nativeFalseAct['ok'] ?? false)
            && ($nativeFalseVerify['error']['code'] ?? '') === 'verification_assertion_failed'
            && ($nativeFalseVerify['metadata']['operation_receipt']['state'] ?? '') === 'verification_proved_not_achieved'
            && ($nativeRecovery['ok'] ?? false)
            && $actionExecutions === $actionsBeforeNativeRecovery + 1,
        'Authoritative false verification did not allow exactly one different recovery action'
    );

    $noVersion = $gateway->execute('computer', 'task-c', 'no-version', 'shell_executor', shellArgs('NO_VERSION', 'inspect', 'op-c'));
    shellAssert(($noVersion['error']['code'] ?? '') === 'shell_preflight_unavailable', 'Missing preflight version did not fail closed');

    $approvalCountBeforeUnknown = (int)$pdo->query('SELECT COUNT(*) FROM tool_approvals')->fetchColumn();
    $fullAccessGateway = new ToolGateway($repository, $events, [], $relay, 1, 1, 'full_access', null);
    $unknown = $fullAccessGateway->execute('computer', 'task-unknown', 'unknown-act', 'shell_executor', shellArgs('UNKNOWN_DYNAMIC', 'act', 'op-unknown'));
    shellAssert(
        ($unknown['ok'] ?? false)
            && (int)$pdo->query('SELECT COUNT(*) FROM tool_approvals')->fetchColumn() === $approvalCountBeforeUnknown + 1,
        'Unknown Shell semantics bypassed approval in full_access mode'
    );

    $persistent = $gateway->execute(
        'computer',
        'task-persistent',
        'persistent-act',
        'shell_executor',
        shellArgs('Start-PersistentService', 'act', 'op-persistent', false, 'persistent')
    );
    shellAssert(
        ($persistent['ok'] ?? false)
            && ($persistent['structured_content']['status'] ?? '') === 'running'
            && ($persistent['metadata']['operation_receipt']['state'] ?? '') === 'action_running',
        'Persistent act did not remain running'
    );
    $actionsBeforePersistentDuplicate = $actionExecutions;
    $persistentDuplicate = $gateway->execute(
        'computer',
        'task-persistent',
        'persistent-act-duplicate',
        'shell_executor',
        shellArgs('Start-PersistentService-Different-Wording', 'act', 'op-persistent', false, 'persistent')
    );
    shellAssert(
        ($persistentDuplicate['structured_content']['status'] ?? '') === 'skipped'
            && ($persistentDuplicate['metadata']['operation_receipt']['state'] ?? '') === 'action_running'
            && $actionExecutions === $actionsBeforePersistentDuplicate,
        'A running asynchronous act was executed twice'
    );
    $gatewayReflection = new ReflectionClass($gateway);
    $backgroundProperty = $gatewayReflection->getProperty('backgroundCommands');
    $backgroundProperty->setAccessible(true);
    $persistentCommands = $backgroundProperty->getValue($gateway);
    $persistentRecord = reset($persistentCommands);
    shellAssert(
        is_array($persistentRecord) && count($persistentRecord['lock_handles'] ?? []) > 0,
        'Persistent act did not retain its resource lock'
    );
    $readiness = $gateway->execute(
        'computer',
        'task-persistent',
        'persistent-readiness',
        'shell_executor',
        shellArgs('Get-PersistentReady', 'verify', 'op-persistent')
    );
    shellAssert(
        ($readiness['ok'] ?? false)
            && ($readiness['metadata']['operation_receipt']['state'] ?? '') === 'verified_completed',
        'Persistent readiness verify did not close the operation'
    );
    $persistentCommands = $backgroundProperty->getValue($gateway);
    $persistentRecord = reset($persistentCommands);
    shellAssert(
        is_array($persistentRecord)
            && ($persistentRecord['verified'] ?? false) === true
            && ($persistentRecord['lock_handles'] ?? []) === [],
        'Persistent readiness did not release its resource lock'
    );

    $cancelledPersistent = $gateway->execute(
        'computer',
        'task-cancel-background',
        'persistent-cancel-act',
        'shell_executor',
        shellArgs('Start-AnotherPersistentService', 'act', 'op-persistent-cancel', false, 'persistent')
    );
    shellAssert(($cancelledPersistent['ok'] ?? false), 'Persistent cancellation fixture did not start');
    $gateway->cancelRunBackgroundCommands();
    shellAssert($stoppedBackgroundCommands >= 2, 'Run cancellation did not stop all persistent commands started by the run');

    $approvalPayloads = $pdo->query("SELECT payload FROM team_run_events WHERE event_name='approval.required'")->fetchAll(PDO::FETCH_COLUMN);
    shellAssert($approvalPayloads !== [], 'No approval events were persisted');
    $firstApproval = json_decode((string)$approvalPayloads[0], true) ?: [];
    shellAssert(
        ($firstApproval['arguments']['phase'] ?? '') === 'act'
            && ($firstApproval['arguments']['intent'] ?? '') !== ''
            && ($firstApproval['arguments']['shell'] ?? '') === 'powershell'
            && ($firstApproval['arguments']['command_preview'] ?? '') !== '',
        'Approval card payload is missing phase, intent, shell, or command preview'
    );
    $nullExpiryApproval = $pdo->query(
        'SELECT id FROM tool_approvals WHERE expires_at IS NULL LIMIT 1'
    )->fetchColumn();
    shellAssert(is_string($nullExpiryApproval) && $nullExpiryApproval !== '', 'approval_timeout_seconds=0 did not persist NULL expiry');
    $pdo->prepare('UPDATE tool_approvals SET status="pending" WHERE id=?')->execute([$nullExpiryApproval]);
    shellAssert(
        $repository->decideApproval(1, $nullExpiryApproval, 'allow_once') === 'allowed',
        'An infinite pending approval could not be decided'
    );
} finally {
    ob_end_clean();
}

echo "shell execution contract: PASS\n";
