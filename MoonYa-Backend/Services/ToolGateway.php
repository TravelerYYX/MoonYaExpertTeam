<?php
declare(strict_types=1);

require_once __DIR__ . '/RiskPolicy.php';
require_once __DIR__ . '/ResourceLockManager.php';
require_once __DIR__ . '/ProjectTeamProtocol.php';
require_once __DIR__ . '/BrowserAutomationGateway.php';

final class ToolGateway
{
    private TeamRepository $repository;
    private TeamEventEmitter $events;
    private RiskPolicy $riskPolicy;
    private array $config;
    private $relay;
    private int $userId;
    private ?int $conversationId;
    private string $approvalMode;
    private ?string $projectPath;
    private ResourceLockManager $resourceLocks;
    /** @var array<string,array<string,mixed>> Run-local shell operation ledger. */
    private array $shellOperations = [];
    /** @var array<string,array<string,bool>> Invalid preflight fingerprints by operation/phase. */
    private array $shellPreflightFailures = [];
    /** @var array<string,array<string,mixed>> Background commands started by this run. */
    private array $backgroundCommands = [];
    /** @var array<string,array<string,mixed>> Server-authoritative project write scopes by task id. */
    private array $taskScopes = [];

    public function __construct(
        TeamRepository $repository,
        TeamEventEmitter $events,
        array $config,
        callable $relay,
        int $userId,
        ?int $conversationId,
        string $approvalMode,
        ?string $projectPath
    ) {
        $this->repository = $repository;
        $this->events = $events;
        $this->riskPolicy = new RiskPolicy();
        $this->config = $config;
        $this->relay = $relay;
        $this->userId = $userId;
        $this->conversationId = $conversationId;
        $this->approvalMode = $approvalMode;
        $this->projectPath = $projectPath;
        $this->resourceLocks = new ResourceLockManager();
    }

    public function __destruct()
    {
        try {
            $this->cleanupUnverifiedBackgroundCommands();
        } catch (Throwable $e) {
            // Destructors must never replace the authoritative run result.
        }
    }

    public function projectPath(): ?string
    {
        return $this->projectPath;
    }

    public function registerTaskScope(string $taskId, array $scope): void
    {
        $scope['project_root'] = (string)($scope['project_root'] ?? $this->projectPath ?? '');
        $scope['owned_paths'] = array_values(array_unique(array_map('strval', $scope['owned_paths'] ?? [])));
        $this->taskScopes[$taskId] = $scope;
    }

    public function execute(
        string $agentKey,
        string $taskId,
        string $toolCallId,
        string $toolKey,
        array $arguments,
        ?string $capabilityKey = null
    ): array {
        if ($this->repository->isRunCancelled($this->events->runId())) {
            return $this->errorResult('团队运行已由用户停止', 'run_cancelled');
        }
        $canonical = $this->canonicalToolName($toolKey);
        $tool = $this->repository->getToolForAgent($agentKey, $canonical, $this->userId);
        if ($tool === null) {
            return $this->errorResult("Agent {$agentKey} 无权调用工具 {$canonical}", 'forbidden_tool');
        }

        // Enforce managed-runtime policy before shell preflight so a forbidden
        // package install cannot consume the sole syntax-correction allowance.
        if (in_array($canonical, ['shell_executor', 'python_executor'], true)
            && ($capabilityKey === 'file.office'
                || ($capabilityKey === null
                    && $this->repository->agentOwnsRoutingCapability($agentKey, 'file.office')))
            && $this->isManagedRuntimeInstallAttempt($canonical, $arguments)) {
            $result = $this->errorResult(
                'File Agent 不得临时安装 Python 包；请通过 MoonYa 安装/升级流程维护固定运行环境。',
                'managed_runtime_install_forbidden'
            );
            $this->emitToolCompleted($agentKey, $taskId, $toolCallId, $canonical, $result);
            return $result;
        }

        $shellContext = null;
        $authoritativeVerificationContext = null;
        if ($canonical === 'shell_executor') {
            $prepared = $this->prepareShellExecution($taskId, $arguments);
            if (!empty($prepared['result'])) {
                $result = $prepared['result'];
                $this->emitToolCompleted($agentKey, $taskId, $toolCallId, $canonical, $result);
                return $result;
            }
            $shellContext = $prepared['context'];
            if ($this->projectPath !== null
                && $this->projectPath !== ''
                && trim((string)($arguments['cwd'] ?? '')) === ''
            ) {
                $arguments['cwd'] = $this->projectPath;
            }
        }
        if ($canonical === 'recycle_bin_status') {
            $prepared = $this->prepareRecycleBinStatus($taskId, $arguments);
            if (!empty($prepared['result'])) {
                $result = $prepared['result'];
                $this->emitToolCompleted($agentKey, $taskId, $toolCallId, $canonical, $result);
                return $result;
            }
            $authoritativeVerificationContext = $prepared['context'];
        }

        if (is_array($shellContext)) {
            $effectiveEffect = (string)$shellContext['effect'];
            $effectiveRisk = (string)$shellContext['risk_level'];
        } elseif (is_array($authoritativeVerificationContext)) {
            // The launcher implementation is a fixed SHQueryRecycleBin call;
            // model-provided phase metadata cannot turn it into a write.
            $effectiveEffect = 'read';
            $effectiveRisk = 'low';
        } else {
            [$effectiveEffect, $effectiveRisk] = $this->riskPolicy->effectivePolicy($tool, $arguments);
            if ($canonical === 'edit_file' && strtolower((string)($arguments['command'] ?? '')) === 'view') {
                $effectiveEffect = 'read';
                $effectiveRisk = 'low';
            }
        }
        $scopeError = $this->validateTaskScope(
            $taskId,
            $canonical,
            $arguments,
            $effectiveEffect,
            $shellContext
        );
        if ($scopeError !== null) {
            $this->emitToolCompleted($agentKey, $taskId, $toolCallId, $canonical, $scopeError);
            return $scopeError;
        }
        $this->events->emit(
            'tool.started',
            array_merge([
                'tool_key' => $canonical,
                'display_name' => $tool['display_name'],
                'arguments' => $arguments,
                'effect' => $effectiveEffect,
                'risk_level' => $effectiveRisk,
                'phase' => $arguments['phase'] ?? null,
                'operation_id' => $arguments['operation_id'] ?? null,
                'intent' => $arguments['intent'] ?? null,
                'shell' => $shellContext['shell'] ?? ($arguments['shell'] ?? null),
                'command_fingerprint' => $shellContext['command_fingerprint'] ?? null,
            ], $this->taskEventContext($taskId, (string)($arguments['phase'] ?? 'execution'))),
            $agentKey,
            'moonya',
            $taskId,
            $toolCallId
        );

        [$needsApproval, $reason] = is_array($shellContext)
            ? $this->riskPolicy->requiresApprovalForPolicy(
                $effectiveEffect,
                $effectiveRisk,
                $this->approvalMode,
                'native',
                true
            )
            : $this->riskPolicy->requiresApproval($tool, $arguments, $this->approvalMode);
        if (is_array($shellContext) && $effectiveEffect === 'unknown') {
            $needsApproval = true;
            $reason = 'Shell 语义无法证明为只读或已知变更，按未知高风险处理';
        }
        if ($needsApproval) {
            $approvalArguments = $arguments;
            if (is_array($shellContext)) {
                $approvalArguments['command_preview'] = $shellContext['command_preview'];
                $approvalArguments['risk_reason'] = $shellContext['risk_reason'];
                $reason = $this->shellApprovalReason($arguments, $shellContext);
            }
            $approval = $this->repository->createApproval(
                $this->events->runId(),
                $this->userId,
                $this->conversationId,
                $agentKey,
                $toolCallId,
                $canonical,
                $approvalArguments,
                $reason
            );
            $this->events->emit(
                'approval.required',
                $approval,
                $agentKey,
                'moonya',
                $taskId,
                $toolCallId
            );
            $status = $this->waitForApproval($approval['id']);
            $this->events->emit(
                'approval.decided',
                ['approval_id' => $approval['id'], 'status' => $status],
                $agentKey,
                'moonya',
                $taskId,
                $toolCallId
            );
            if ($status !== 'allowed') {
                $result = $this->errorResult(
                    $status === 'expired' ? '用户确认已超时' : '用户拒绝了本次工具调用',
                    'approval_' . $status
                );
                $this->emitToolCompleted($agentKey, $taskId, $toolCallId, $canonical, $result);
                return $result;
            }
        }

        if (is_array($shellContext)) {
            // The launcher must not display a second confirmation after the gateway
            // has applied its trusted preflight policy and, when required, received approval.
            $arguments['approval_granted'] = true;
        } elseif ($canonical === 'python_executor') {
            $arguments['approval_granted'] = true;
            $arguments['managed_work'] = true;
        }

        $lockHandles = [];
        $persistentCommandId = is_array($shellContext)
            && (($arguments['phase'] ?? '') === 'verify')
            ? $this->persistentCommandForOperation((string)$shellContext['operation_key'])
            : null;
        $previousToolContext = is_array($GLOBALS['teamActiveToolContext'] ?? null)
            ? $GLOBALS['teamActiveToolContext']
            : null;
        try {
            $lockKeys = $this->resourceLocks->keysFor(
                $tool,
                $canonical,
                $arguments,
                $effectiveEffect,
                $this->taskScopes[$taskId] ?? null
            );
            if ($lockKeys !== [] && $persistentCommandId === null) {
                $lockStartedAt = microtime(true);
                $lockHandles = $this->resourceLocks->acquire(
                    $lockKeys,
                    $this->resourceLockTimeoutSeconds($canonical),
                    fn(): bool => $this->repository->isRunCancelled($this->events->runId()),
                    function (string $resource) use ($agentKey, $taskId, $toolCallId, $canonical): void {
                        $this->events->emitTransient('agent.waiting', [
                            'state' => 'waiting_resource',
                            'label' => '等待资源锁',
                            'tool_key' => $canonical,
                            'resource' => $resource,
                        ], $agentKey, 'moonya', $taskId, $toolCallId);
                        $this->events->heartbeat();
                    }
                );
                // Emit "locked" only after acquisition. Previously this event
                // was emitted before acquire(), so the UI reported a lock as
                // held even while the call was only waiting for another worker.
                $this->events->emit(
                    'tool.locked',
                    [
                        'tool_key' => $canonical,
                        'resources' => $lockKeys,
                        'wait_ms' => (int)round((microtime(true) - $lockStartedAt) * 1000),
                    ],
                    $agentKey,
                    'moonya',
                    $taskId,
                    $toolCallId
                );
            }
            $GLOBALS['teamActiveToolContext'] = [
                'agent_key' => $agentKey,
                'task_id' => $taskId,
                'tool_call_id' => $toolCallId,
                'tool_key' => $canonical,
            ];
            $result = $this->dispatch($tool, $canonical, $arguments);
            if ($canonical === 'shell_executor'
                && ($arguments['blocking'] ?? true) === false
                && !empty($result['ok'])
            ) {
                $result = $this->manageBackgroundShellResult(
                    $agentKey,
                    $taskId,
                    $toolCallId,
                    $arguments,
                    $shellContext ?? [],
                    $result,
                    $lockHandles,
                    $lockKeys
                );
                $lockHandles = [];
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $code = $message === 'run_cancelled'
                ? 'run_cancelled'
                : (str_starts_with($message, '等待资源锁超时：')
                    ? 'resource_lock_timeout'
                    : 'transport_error');
            $result = $this->errorResult(
                $code === 'run_cancelled' ? '团队运行已由用户停止' : $message,
                $code
            );
        } finally {
            if ($previousToolContext !== null) {
                $GLOBALS['teamActiveToolContext'] = $previousToolContext;
            } else {
                unset($GLOBALS['teamActiveToolContext']);
            }
            $this->resourceLocks->release($lockHandles);
        }

        if (is_array($shellContext)) {
            $result = $this->recordShellResult($taskId, $arguments, $shellContext, $result);
            if (($arguments['phase'] ?? '') === 'verify'
                && (($result['metadata']['operation_receipt']['state'] ?? '') === 'verified_completed')
                && $persistentCommandId !== null
            ) {
                $this->releaseBackgroundLocks($persistentCommandId, true);
            }
        } elseif (is_array($authoritativeVerificationContext)) {
            $result = $this->recordRecycleBinStatusResult(
                $arguments,
                $authoritativeVerificationContext,
                $result
            );
        }

        $artifacts = $this->extractArtifacts($result);
        $persisted = [];
        foreach ($artifacts as $artifact) {
            if (($artifact['uri'] ?? '') === '') {
                continue;
            }
            try {
                $record = $this->repository->createArtifact(
                    $this->events->runId(),
                    $taskId,
                    $agentKey,
                    $artifact
                );
                $persisted[] = $record;
                $this->events->emit(
                    'artifact.created',
                    $record,
                    $agentKey,
                    'moonya',
                    $taskId,
                    $toolCallId
                );
            } catch (Throwable $e) {
                // An artifact persistence failure must not erase a valid tool result.
            }
        }
        if ($persisted !== []) {
            $result['artifacts'] = $persisted;
        }

        $this->emitToolCompleted($agentKey, $taskId, $toolCallId, $canonical, $result);
        return $result;
    }

    /**
     * Validates metadata for the fixed, read-only Windows Recycle Bin query.
     * The actual assertion is evaluated server-side after the launcher result.
     *
     * @return array{context?:array<string,mixed>,result?:array<string,mixed>}
     */
    private function prepareRecycleBinStatus(string $taskId, array $arguments): array
    {
        foreach (['phase', 'operation_id', 'intent'] as $required) {
            if (!array_key_exists($required, $arguments)
                || trim((string)$arguments[$required]) === ''
            ) {
                return ['result' => $this->errorResult(
                    "Work 模式 recycle_bin_status 缺少必填执行元数据：{$required}",
                    'verification_metadata_required'
                )];
            }
        }

        $phase = strtolower(trim((string)$arguments['phase']));
        if (!in_array($phase, ['inspect', 'verify'], true)) {
            return ['result' => $this->errorResult(
                'recycle_bin_status 的 phase 只能是 inspect 或 verify。',
                'verification_phase_invalid'
            )];
        }
        if ($phase === 'verify'
            && (!array_key_exists('expected_empty', $arguments)
                || !is_bool($arguments['expected_empty']))
        ) {
            return ['result' => $this->errorResult(
                'recycle_bin_status 的 verify 阶段必须提供布尔型 expected_empty 断言。',
                'verification_assertion_required'
            )];
        }

        $operationKey = $taskId . '::' . (string)$arguments['operation_id'];
        $operation = $this->shellOperations[$operationKey] ?? [
            'state' => 'not_started',
            'used_action_fingerprints' => [],
            'allow_recovery' => false,
        ];
        $fingerprintPayload = [
            'tool' => 'recycle_bin_status',
            'phase' => $phase,
            'root_path' => (string)($arguments['root_path'] ?? ''),
            'expected_empty' => $arguments['expected_empty'] ?? null,
        ];
        $fingerprint = hash(
            'sha256',
            (string)json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if ($phase === 'verify') {
            if (($operation['state'] ?? '') === 'verified_completed') {
                return ['result' => $this->shellSkipResult(
                    $arguments,
                    $fingerprint,
                    'verified_completed',
                    '操作已经通过 Windows 原生状态 API 验证完成，无需重复验证。'
                )];
            }
            if (!in_array((string)($operation['state'] ?? ''), [
                'action_succeeded_unverified',
                'action_running',
                'verification_failed',
                'verification_proved_not_achieved',
            ], true)) {
                return ['result' => $this->errorResult(
                    '尚无成功或正在运行的 act 回执，不能开始回收站状态验证。',
                    'verification_before_action'
                )];
            }
            if ((string)($operation['authoritative_verifier'] ?? '') !== 'recycle_bin_status') {
                return ['result' => $this->errorResult(
                    '该操作没有绑定 Windows 回收站状态验证器，不能用回收站状态替代其原始验收条件。',
                    'authoritative_verifier_mismatch'
                )];
            }
        }

        return ['context' => [
            'operation_key' => $operationKey,
            'fingerprint' => $fingerprint,
        ]];
    }

    /**
     * Evaluates the authoritative Windows status result and closes (or reopens)
     * the same operation ledger used by shell_executor.
     */
    private function recordRecycleBinStatusResult(
        array $arguments,
        array $context,
        array $result
    ): array {
        $phase = (string)$arguments['phase'];
        $key = (string)$context['operation_key'];
        $fingerprint = (string)$context['fingerprint'];
        $operation = $this->shellOperations[$key] ?? [
            'state' => 'not_started',
            'used_action_fingerprints' => [],
            'allow_recovery' => false,
        ];
        $structured = is_array($result['structured_content'] ?? null)
            ? $result['structured_content']
            : [];

        if (!empty($result['ok'])) {
            $actualEmpty = $structured['isEmpty'] ?? $structured['is_empty'] ?? null;
            if (!is_bool($actualEmpty)) {
                $result = $this->errorResult(
                    'Windows 回收站状态工具未返回布尔型 is_empty，已按契约失败关闭。',
                    'verification_invalid_contract'
                );
            } elseif ($phase === 'verify') {
                $expectedEmpty = (bool)$arguments['expected_empty'];
                if ($actualEmpty === $expectedEmpty) {
                    $operation['state'] = 'verified_completed';
                    $operation['allow_recovery'] = false;
                    $structured['stage'] = 'verify';
                    $structured['status'] = 'success';
                    $structured['assertionPassed'] = true;
                    $result['structured_content'] = $structured;
                } else {
                    $operation['state'] = 'verification_proved_not_achieved';
                    $operation['allow_recovery'] = true;
                    $itemCount = $structured['itemCount'] ?? $structured['item_count'] ?? null;
                    $message = sprintf(
                        'Windows 回收站逻辑状态未满足断言：期望 empty=%s，实际 empty=%s%s。',
                        $expectedEmpty ? 'true' : 'false',
                        $actualEmpty ? 'true' : 'false',
                        is_int($itemCount) ? "，已删除项目数={$itemCount}" : ''
                    );
                    $structured['stage'] = 'verify';
                    $structured['status'] = 'error';
                    $structured['errorCode'] = 'verification_assertion_failed';
                    $structured['assertionPassed'] = false;
                    $result['ok'] = false;
                    $result['content'] = $message;
                    $result['structured_content'] = $structured;
                    $result['error'] = [
                        'code' => 'verification_assertion_failed',
                        'message' => $message,
                    ];
                }
                $this->shellOperations[$key] = $operation;
            }
        }

        $receiptState = $phase === 'inspect'
            ? (!empty($result['ok']) ? 'inspected' : 'inspection_failed')
            : (string)($operation['state'] ?? 'action_succeeded_unverified');
        $result['metadata'] = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $result['metadata']['operation_receipt'] = [
            'operation_id' => (string)$arguments['operation_id'],
            'phase' => $phase,
            'state' => $receiptState,
            'command_fingerprint' => $fingerprint,
            'intent' => (string)$arguments['intent'],
            'verification_source' => (string)($structured['source'] ?? 'SHQueryRecycleBin'),
        ];
        return $result;
    }

    /**
     * Mandatory, server-controlled shell preflight and run-local idempotency gate.
     * This runs before approval creation and never executes the target command.
     *
     * @return array{context?:array<string,mixed>,result?:array<string,mixed>}
     */
    private function prepareShellExecution(string $taskId, array $arguments): array
    {
        foreach (['command', 'shell', 'phase', 'operation_id', 'intent', 'success_criteria'] as $required) {
            if (!array_key_exists($required, $arguments)
                || ($required !== 'success_criteria' && trim((string)$arguments[$required]) === '')
            ) {
                return ['result' => $this->errorResult(
                    "Work 模式 shell_executor 缺少必填执行元数据：{$required}",
                    'shell_metadata_required'
                )];
            }
        }

        $phase = strtolower(trim((string)$arguments['phase']));
        if (!in_array($phase, ['inspect', 'act', 'verify'], true)) {
            return ['result' => $this->errorResult('phase 必须是 inspect、act 或 verify。', 'shell_phase_invalid')];
        }
        $requestedShell = strtolower(trim((string)$arguments['shell']));
        if (!in_array($requestedShell, ['powershell', 'cmd', 'auto'], true)) {
            return ['result' => $this->errorResult('shell 必须是 powershell、cmd 或 auto。', 'shell_type_invalid')];
        }
        if (!is_array($arguments['success_criteria'])) {
            return ['result' => $this->errorResult('success_criteria 必须是确定性断言对象。', 'shell_success_criteria_invalid')];
        }
        if ($phase === 'verify' && !$this->hasOutputAssertion($arguments['success_criteria'])) {
            return ['result' => $this->errorResult(
                'verify 至少需要一个 stdout/stderr 输出断言，不能只依赖退出码。',
                'shell_verify_assertion_required'
            )];
        }
        $blocking = ($arguments['blocking'] ?? true) !== false;
        $completionMode = strtolower(trim((string)($arguments['completion_mode'] ?? '')));
        if (!$blocking && !in_array($completionMode, ['finite', 'persistent'], true)) {
            return ['result' => $this->errorResult(
                'blocking=false 时必须显式指定 completion_mode=finite 或 persistent。',
                'completion_mode_required'
            )];
        }
        if (!$blocking && $completionMode === 'persistent' && $phase !== 'act') {
            return ['result' => $this->errorResult(
                'completion_mode=persistent 只允许用于 act 阶段。',
                'persistent_phase_forbidden'
            )];
        }

        $preflight = $this->callShellPreflight($arguments);
        $structured = is_array($preflight['structured_content'] ?? null)
            ? $preflight['structured_content']
            : [];
        $version = (string)($structured['commandPreflightVersion']
            ?? $structured['command_preflight_version']
            ?? '');
        if ($version !== 'shell-preflight-v1') {
            return ['result' => $this->errorResult(
                'MoonYa 启动器未报告 command_preflight_version=shell-preflight-v1；已按安全策略停止执行。',
                'shell_preflight_unavailable'
            )];
        }

        if (empty($preflight['ok'])) {
            $errorCode = (string)($preflight['error']['code'] ?? 'shell_preflight_failed');
            if ($errorCode === 'shell_preflight_failed') {
                $fingerprint = (string)($structured['commandFingerprint']
                    ?? $structured['command_fingerprint']
                    ?? hash('sha256', (string)$arguments['command']));
                $failureKey = $taskId . '::' . (string)$arguments['operation_id'] . '::' . $phase;
                $seen = $this->shellPreflightFailures[$failureKey] ?? [];
                if (isset($seen[$fingerprint])) {
                    $preflight['error']['code'] = 'shell_preflight_repeated';
                    $preflight['content'] = '相同的错误命令已被预检拦截；请根据已有行列诊断修正，不能重复提交。';
                    $preflight['error']['message'] = $preflight['content'];
                    return ['result' => $preflight];
                }
                $seen[$fingerprint] = true;
                $this->shellPreflightFailures[$failureKey] = $seen;
            }
            return ['result' => $preflight];
        }

        $effect = (string)($structured['effect'] ?? 'unknown');
        $risk = (string)($structured['riskLevel'] ?? $structured['risk_level'] ?? 'high');
        $shell = (string)($structured['shell'] ?? $requestedShell);
        $fingerprint = (string)($structured['commandFingerprint'] ?? $structured['command_fingerprint'] ?? '');
        $authoritativeVerifier = (string)($structured['authoritativeVerifier']
            ?? $structured['authoritative_verifier']
            ?? '');
        if ($fingerprint === '' || !in_array($effect, ['read', 'write', 'destructive', 'unknown'], true)) {
            return ['result' => $this->errorResult('启动器预检结果缺少可信指纹或风险分类。', 'shell_preflight_invalid_contract')];
        }

        $operationKey = $taskId . '::' . (string)$arguments['operation_id'];
        $operation = $this->shellOperations[$operationKey] ?? [
            'state' => 'not_started',
            'used_action_fingerprints' => [],
            'allow_recovery' => false,
        ];

        if ($phase === 'act') {
            if ($operation['state'] === 'verified_completed') {
                return ['result' => $this->shellSkipResult($arguments, $fingerprint, 'verified_completed', '操作已经验证完成，后续变更已被永久拦截。')];
            }
            if ($operation['state'] === 'action_succeeded_unverified' && empty($operation['allow_recovery'])) {
                return ['result' => $this->shellSkipResult($arguments, $fingerprint, 'action_succeeded_unverified', '变更已经成功；现在只能修正并执行 verify，不能重复变更。')];
            }
            if ($operation['state'] === 'action_running') {
                return ['result' => $this->shellSkipResult($arguments, $fingerprint, 'action_running', '后台变更仍在运行；不能重复执行 act，请提交独立 readiness verify。')];
            }
            if (isset($operation['used_action_fingerprints'][$fingerprint])) {
                return ['result' => $this->errorResult('该变更命令指纹已经成功执行过，禁止重复。', 'duplicate_action_fingerprint')];
            }
        } elseif ($phase === 'verify') {
            if ($operation['state'] === 'verified_completed') {
                return ['result' => $this->shellSkipResult($arguments, $fingerprint, 'verified_completed', '操作已经验证完成，无需重复 verify。')];
            }
            if (!in_array($operation['state'], [
                'action_succeeded_unverified',
                'action_running',
                'verification_failed',
                'verification_proved_not_achieved',
            ], true)) {
                return ['result' => $this->errorResult('尚无成功或正在运行的 act 回执，不能开始 verify。', 'shell_verify_before_action')];
            }
            if ((string)($operation['authoritative_verifier'] ?? '') !== '') {
                return ['result' => $this->errorResult(
                    '该操作必须使用系统绑定的权威状态工具完成 verify，不能用 Shell 输出替代。',
                    'authoritative_verifier_required'
                )];
            }
        }

        return ['context' => [
            'operation_key' => $operationKey,
            'effect' => $effect,
            'risk_level' => $risk,
            'shell' => $shell,
            'command_fingerprint' => $fingerprint,
            'command_preview' => (string)($structured['commandPreview'] ?? $structured['command_preview'] ?? ''),
            'risk_reason' => $effect === 'read' ? '已由启动器证明为只读' : "{$effect} / {$risk}",
            'authoritative_verifier' => $authoritativeVerifier,
        ]];
    }

    private function validateTaskScope(
        string $taskId,
        string $toolKey,
        array &$arguments,
        string $effectiveEffect,
        ?array $shellContext
    ): ?array {
        $scope = $this->taskScopes[$taskId] ?? null;
        if (!is_array($scope) || $effectiveEffect === 'read') {
            return null;
        }
        $root = (string)($scope['project_root'] ?? '');
        $owned = is_array($scope['owned_paths'] ?? null) ? $scope['owned_paths'] : [];
        $phase = strtolower(trim((string)($arguments['phase'] ?? '')));
        $paths = [];
        if ($toolKey === 'shell_executor') {
            if ($phase !== 'act') {
                return $this->errorResult(
                    '代码项目组中的 Shell inspect/verify 必须由预检证明为只读。',
                    'task_scope_non_read_verification'
                );
            }
            if (!isset($arguments['affected_paths']) || !is_array($arguments['affected_paths']) || $arguments['affected_paths'] === []) {
                return $this->errorResult(
                    '代码项目组中的 shell_executor act 必须声明 affected_paths。',
                    'affected_paths_required'
                );
            }
            $paths = $arguments['affected_paths'];
        } else {
            $pathKeys = match ($toolKey) {
                'copy_file', 'move_file' => ['destination', 'destination_path', 'target_path'],
                'download_file' => ['path', 'destination', 'destination_path'],
                default => ['path', 'file', 'file_path', 'folder', 'directory', 'destination', 'destination_path', 'target_path'],
            };
            foreach ($pathKeys as $pathKey) {
                if (isset($arguments[$pathKey]) && is_string($arguments[$pathKey]) && trim($arguments[$pathKey]) !== '') {
                    $paths[] = $arguments[$pathKey];
                }
            }
        }
        if ($paths === []) {
            return $this->errorResult(
                "项目成员工具 {$toolKey} 的写入目标无法确定，已按文件所有权策略拒绝。",
                'task_scope_opaque_write'
            );
        }
        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                return $this->errorResult('affected_paths 包含无效路径。', 'task_scope_violation');
            }
            try {
                $candidate = ProjectTeamProtocol::normalizeProjectPath($path, $root);
            } catch (Throwable $e) {
                return $this->errorResult($e->getMessage(), 'task_scope_violation');
            }
            if (!ProjectTeamProtocol::pathWithinScopes($candidate, $owned, $root)) {
                return $this->errorResult(
                    "写入路径不属于当前项目角色：{$candidate}",
                    'task_scope_violation'
                );
            }
            $normalized[] = $candidate;
        }
        if ($toolKey === 'shell_executor') {
            $arguments['affected_paths'] = array_values(array_unique($normalized));
        }
        return null;
    }

    private function callShellPreflight(array $arguments): array
    {
        $payload = [
            'action' => 'validate_command',
            'command' => (string)($arguments['command'] ?? ''),
            'shell' => (string)($arguments['shell'] ?? 'auto'),
            'phase' => (string)($arguments['phase'] ?? ''),
        ];
        $raw = ($this->relay)(
            '/file-op',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0
        );
        return $this->normalizeRawResult($raw);
    }

    /**
     * Converts an asynchronous launcher start into the managed Work protocol.
     * finite jobs are polled internally and produce one final ToolResult;
     * persistent jobs return running and keep their resource locks until verify.
     */
    private function manageBackgroundShellResult(
        string $agentKey,
        string $taskId,
        string $toolCallId,
        array $arguments,
        array $context,
        array $startResult,
        array $lockHandles,
        array $lockKeys
    ): array {
        $structured = is_array($startResult['structured_content'] ?? null)
            ? $startResult['structured_content']
            : [];
        $commandId = (string)($structured['command_id'] ?? $structured['commandId'] ?? '');
        if ($commandId === '') {
            $this->resourceLocks->release($lockHandles);
            return $this->errorResult('后台 Shell 启动器未返回 command_id。', 'background_command_id_missing');
        }

        $completionMode = (string)($arguments['completion_mode'] ?? '');
        $this->backgroundCommands[$commandId] = [
            'command_id' => $commandId,
            'operation_key' => (string)($context['operation_key'] ?? ''),
            'completion_mode' => $completionMode,
            'agent_key' => $agentKey,
            'task_id' => $taskId,
            'tool_call_id' => $toolCallId,
            'lock_handles' => $lockHandles,
            'lock_keys' => $lockKeys,
            'verified' => false,
        ];

        if ($completionMode === 'persistent') {
            $receipt = $structured['operation_receipt'] ?? $structured['operationReceipt'] ?? [
                'operation_id' => (string)($arguments['operation_id'] ?? ''),
                'phase' => 'act',
                'state' => 'action_running',
                'command_fingerprint' => (string)($context['command_fingerprint'] ?? ''),
                'intent' => (string)($arguments['intent'] ?? ''),
            ];
            $startResult['metadata'] = is_array($startResult['metadata'] ?? null)
                ? $startResult['metadata']
                : [];
            $startResult['metadata']['operation_receipt'] = $receipt;
            $startResult['content'] = '持久后台进程已启动；必须执行独立 readiness verify 后才能宣称完成。';
            return $startResult;
        }

        $lastProgress = 0;
        $lastRevision = -1;
        while (true) {
            if ($this->repository->isRunCancelled($this->events->runId()) || connection_aborted()) {
                $this->stopBackgroundCommand($commandId);
                $this->releaseBackgroundLocks($commandId);
                return $this->errorResult('团队运行已由用户停止', 'run_cancelled');
            }
            $statusResult = $this->queryBackgroundCommand($commandId);
            if (empty($statusResult['ok'])) {
                $this->stopBackgroundCommand($commandId);
                $this->releaseBackgroundLocks($commandId);
                return $statusResult;
            }
            $status = (string)($statusResult['structured_content']['status'] ?? '');
            $revision = (int)($statusResult['structured_content']['output_revision']
                ?? $statusResult['structured_content']['outputRevision']
                ?? 0);
            if ($revision !== $lastRevision || time() - $lastProgress >= 5) {
                $this->events->emitTransient('tool.progress', array_merge([
                    'state' => 'tool_running',
                    'label' => '后台运行',
                    'tool_key' => 'shell_executor',
                    'command_id' => $commandId,
                    'output_revision' => $revision,
                    'stdout' => $statusResult['structured_content']['stdout'] ?? '',
                    'stderr' => $statusResult['structured_content']['stderr'] ?? '',
                ], $this->taskEventContext($taskId, 'tool_running')), $agentKey, 'moonya', $taskId, $toolCallId);
                $this->events->heartbeat();
                $lastProgress = time();
                $lastRevision = $revision;
            }
            if ($status !== 'running') {
                $this->releaseBackgroundLocks($commandId);
                return $statusResult;
            }
            usleep(100000);
        }
    }

    private function queryBackgroundCommand(string $commandId): array
    {
        $raw = ($this->relay)(
            '/file-op',
            json_encode([
                'action' => 'get_command_status',
                'command_id' => $commandId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0
        );
        return $this->normalizeRawResult($raw);
    }

    private function stopBackgroundCommand(string $commandId): void
    {
        try {
            ($this->relay)(
                '/file-op',
                json_encode([
                    'action' => 'stop_command',
                    'command_id' => $commandId,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                0
            );
        } catch (Throwable $e) {
            // Cleanup is best-effort; locks are still released locally.
        }
    }

    private function persistentCommandForOperation(string $operationKey): ?string
    {
        foreach ($this->backgroundCommands as $commandId => $command) {
            if (($command['operation_key'] ?? '') === $operationKey
                && ($command['completion_mode'] ?? '') === 'persistent'
                && empty($command['verified'])
            ) {
                return $commandId;
            }
        }
        return null;
    }

    private function releaseBackgroundLocks(string $commandId, bool $verified = false): void
    {
        if (!isset($this->backgroundCommands[$commandId])) {
            return;
        }
        $handles = is_array($this->backgroundCommands[$commandId]['lock_handles'] ?? null)
            ? $this->backgroundCommands[$commandId]['lock_handles']
            : [];
        $this->resourceLocks->release($handles);
        $this->backgroundCommands[$commandId]['lock_handles'] = [];
        $this->backgroundCommands[$commandId]['verified'] = $verified;
        if (($this->backgroundCommands[$commandId]['completion_mode'] ?? '') === 'finite') {
            unset($this->backgroundCommands[$commandId]);
        }
    }

    /** Stop every process started by this Work run and release every held lock. */
    public function cancelRunBackgroundCommands(): void
    {
        foreach (array_keys($this->backgroundCommands) as $commandId) {
            $this->stopBackgroundCommand((string)$commandId);
            $this->releaseBackgroundLocks((string)$commandId);
            unset($this->backgroundCommands[$commandId]);
        }
        if (function_exists('cancelLauncherRelay')) {
            foreach ((array)($GLOBALS['teamLauncherRelayTickets'] ?? []) as $ticket) {
                if (is_array($ticket)) {
                    cancelLauncherRelay($ticket);
                }
            }
        }
    }

    /** Stop unfinished persistent processes owned by one employee task. */
    public function cancelTaskBackgroundCommands(string $taskId): void
    {
        foreach ($this->backgroundCommands as $commandId => $command) {
            if (($command['task_id'] ?? '') !== $taskId || !empty($command['verified'])) {
                continue;
            }
            $this->stopBackgroundCommand((string)$commandId);
            $this->releaseBackgroundLocks((string)$commandId);
            unset($this->backgroundCommands[$commandId]);
        }
        if (function_exists('cancelLauncherRelay')) {
            foreach ((array)($GLOBALS['teamLauncherRelayTickets'] ?? []) as $ticket) {
                if (is_array($ticket) && (string)($ticket['context']['task_id'] ?? '') === $taskId) {
                    cancelLauncherRelay($ticket);
                }
            }
        }
    }

    public function cleanupUnverifiedBackgroundCommands(): void
    {
        foreach ($this->backgroundCommands as $commandId => $command) {
            if (!empty($command['verified'])) {
                continue;
            }
            $this->stopBackgroundCommand((string)$commandId);
            $this->releaseBackgroundLocks((string)$commandId);
            unset($this->backgroundCommands[$commandId]);
        }
    }

    private function recordShellResult(
        string $taskId,
        array $arguments,
        array $context,
        array $result
    ): array {
        $key = (string)$context['operation_key'];
        $phase = (string)$arguments['phase'];
        $fingerprint = (string)$context['command_fingerprint'];
        $operation = $this->shellOperations[$key] ?? [
            'state' => 'not_started',
            'used_action_fingerprints' => [],
            'allow_recovery' => false,
        ];

        $nativeReceipt = $result['metadata']['operation_receipt']
            ?? $result['structured_content']['operation_receipt']
            ?? $result['structured_content']['operationReceipt']
            ?? null;
        $nativeState = is_array($nativeReceipt) ? (string)($nativeReceipt['state'] ?? '') : '';

        if ($nativeState !== '' && $phase !== 'inspect') {
            $operation['state'] = $nativeState;
            if ($phase === 'act' && in_array($nativeState, ['action_running', 'action_succeeded_unverified'], true)) {
                $operation['used_action_fingerprints'][$fingerprint] = true;
                $operation['allow_recovery'] = false;
            }
            if ($nativeState === 'verified_completed') {
                $operation['allow_recovery'] = false;
            } elseif ($nativeState === 'verification_failed'
                && (string)($result['error']['code'] ?? '') === 'verification_assertion_failed'
            ) {
                $operation['state'] = 'verification_proved_not_achieved';
                $operation['allow_recovery'] = true;
            }
        } elseif (!empty($result['ok']) && $phase === 'act') {
            $operation['state'] = 'action_succeeded_unverified';
            $operation['used_action_fingerprints'][$fingerprint] = true;
            $operation['allow_recovery'] = false;
        } elseif (!empty($result['ok']) && $phase === 'verify') {
            $operation['state'] = 'verified_completed';
            $operation['allow_recovery'] = false;
        } elseif ($phase === 'verify') {
            $errorCode = (string)($result['error']['code'] ?? '');
            if ($errorCode === 'verification_assertion_failed') {
                // The verification command itself completed and deterministically proved the target false.
                $operation['state'] = 'verification_proved_not_achieved';
                $operation['allow_recovery'] = true;
            }
            // Parser, transport, and runtime verification failures deliberately do not reopen act.
        }
        if ($phase === 'act' && (string)($context['authoritative_verifier'] ?? '') !== '') {
            $operation['authoritative_verifier'] = (string)$context['authoritative_verifier'];
        }
        $this->shellOperations[$key] = $operation;

        $receiptState = $phase === 'inspect'
            ? ($nativeState !== '' ? $nativeState : (!empty($result['ok']) ? 'inspected' : 'inspection_failed'))
            : (string)$operation['state'];
        $receipt = [
            'operation_id' => (string)$arguments['operation_id'],
            'phase' => $phase,
            'state' => $receiptState,
            'command_fingerprint' => $fingerprint,
            'intent' => (string)$arguments['intent'],
            'completion_mode' => (string)($arguments['completion_mode'] ?? ''),
            'authoritative_verifier' => (string)($operation['authoritative_verifier'] ?? ''),
            'command_id' => (string)($result['structured_content']['command_id']
                ?? $result['structured_content']['commandId']
                ?? ''),
        ];
        $result['metadata'] = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $result['metadata']['operation_receipt'] = $receipt;
        return $result;
    }

    private function shellSkipResult(array $arguments, string $fingerprint, string $state, string $message): array
    {
        return [
            'ok' => true,
            'content' => $message,
            'structured_content' => [
                'stage' => 'idempotency',
                'status' => 'skipped',
                'error_code' => 'operation_already_completed',
                'command_fingerprint' => $fingerprint,
            ],
            'artifacts' => [],
            'metadata' => [
                'operation_receipt' => [
                    'operation_id' => (string)$arguments['operation_id'],
                    'phase' => (string)$arguments['phase'],
                    'state' => $state,
                    'command_fingerprint' => $fingerprint,
                    'intent' => (string)$arguments['intent'],
                    'skipped' => true,
                ],
            ],
            'error' => null,
        ];
    }

    private function hasOutputAssertion(array $criteria): bool
    {
        foreach (['stdout_contains', 'stdout_regex', 'stderr_contains', 'stderr_not_contains'] as $key) {
            if (isset($criteria[$key]) && is_array($criteria[$key]) && $criteria[$key] !== []) {
                return true;
            }
        }
        return false;
    }

    private function shellApprovalReason(array $arguments, array $context): string
    {
        $phaseLabel = match ((string)($arguments['phase'] ?? 'act')) {
            'inspect' => '检查',
            'verify' => '验证',
            default => '执行变更',
        };
        return sprintf(
            '%s：%s；Shell=%s；风险=%s',
            $phaseLabel,
            (string)($arguments['intent'] ?? ''),
            (string)$context['shell'],
            (string)$context['risk_reason']
        );
    }

    private function dispatch(array $tool, string $toolKey, array $arguments): array
    {
        $transport = (string)$tool['transport'];
        $transportConfig = $tool['transport_config_array'] ?? [];
        return match ($transport) {
            'launcher_file' => $this->callLauncherFile($toolKey, $arguments, $transportConfig),
            'launcher_cu' => $this->callLauncherCu($toolKey, $arguments, $transportConfig),
            'execution' => $this->callExecution($toolKey, $arguments),
            'search' => $this->callSearch($toolKey, $arguments),
            'crawler' => $this->callCrawler($arguments),
            'browser' => $this->callBrowser($toolKey, $arguments),
            'mcp' => $this->callMcp($toolKey, $arguments, $transportConfig),
            'php_native' => $this->errorResult(
                "工具 {$toolKey} 由 MoonYa 主运行时执行，不在子 Agent 网关中重复实现",
                'root_native_only'
            ),
            default => $this->errorResult("未知工具传输类型：{$transport}", 'unknown_transport'),
        };
    }

    private function callLauncherFile(string $toolKey, array $arguments, array $transportConfig): array
    {
        $action = (string)($transportConfig['action'] ?? $toolKey);
        if ($toolKey === 'shell_executor') {
            $action = 'execute_command';
        }
        $payload = $arguments;
        $payload['action'] = $action;
        if ($this->projectPath !== null && $this->projectPath !== '') {
            $payload['project_path'] = $this->projectPath;
            if ($toolKey === 'shell_executor' && empty($payload['cwd'])) {
                $payload['cwd'] = $this->projectPath;
            }
        }
        $raw = ($this->relay)(
            '/file-op',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0
        );
        return $this->normalizeRawResult($raw);
    }

    private function callLauncherCu(string $toolKey, array $arguments, array $transportConfig): array
    {
        $payload = $arguments;
        $payload['action'] = (string)($transportConfig['action'] ?? $toolKey);
        $raw = ($this->relay)(
            '/cu-op',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0
        );
        return $this->normalizeRawResult($raw);
    }

    private function callExecution(string $toolKey, array $arguments): array
    {
        $payload = [
            'type' => 'python',
            'code' => (string)($arguments['code'] ?? ''),
            'params' => ['args' => (string)($arguments['args'] ?? '')],
            'session_id' => session_id(),
            'managedWork' => !empty($arguments['managed_work']),
            'approvalGranted' => !empty($arguments['approval_granted']),
        ];
        if (!empty($arguments['cwd'])) {
            $payload['cwd'] = $arguments['cwd'];
        } elseif ($this->projectPath !== null) {
            $payload['cwd'] = $this->projectPath;
        }
        $raw = ($this->relay)(
            '/execute',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0
        );
        return $this->normalizeRawResult($raw);
    }

    private function callSearch(string $toolKey, array $arguments): array
    {
        $url = $this->requiredServiceUrl('search_api_url') . '/search';
        $payload = ['action' => $toolKey] + $arguments;
        return $this->httpJson($url, $payload, 0);
    }

    private function callCrawler(array $arguments): array
    {
        $url = $this->requiredServiceUrl('crawler_api_url') . '/crawl';
        $payload = $arguments + [
            'user_id' => $this->userId,
            'base_dir' => $this->projectPath,
        ];
        return $this->httpJson($url, $payload, 0);
    }

    private function requiredServiceUrl(string $field): string
    {
        $value = rtrim(trim((string)($this->config[$field] ?? '')), '/');
        $parts = $value === '' ? false : parse_url($value);
        if ($value === '' || !is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])) {
            throw new RuntimeException("缺少或无效的必填配置字段 {$field}");
        }
        return $value;
    }

    private function callBrowser(string $toolKey, array $arguments): array
    {
        $gateway = new BrowserAutomationGateway(
            $this->relay,
            BrowserAutomationGateway::DEFAULT_RELAY_TIMEOUT_SECONDS,
            'user:' . $this->userId
        );
        if ($toolKey === 'vls_analyze_browser') {
            $arguments = ['action' => 'screenshot'];
        }
        $browserResult = $gateway->execute($arguments);
        $imageBase64 = is_string($browserResult['screenshot'] ?? null)
            ? $browserResult['screenshot']
            : '';
        unset($browserResult['screenshot']);
        $result = $this->normalizeRawResult($browserResult);
        if ($imageBase64 !== '') {
            $result['content'] = '浏览器视觉快照已作为图像消息附加；仅依据同一页面版本的可见事实分析。';
            $result['metadata']['browser_visual_message'] = [
                'page_version' => $browserResult['page_version'] ?? null,
                'data_url' => 'data:image/png;base64,' . $imageBase64,
            ];
        }
        return $result;
    }

    private function resourceLockTimeoutSeconds(string $toolKey): int
    {
        return in_array($toolKey, ['browser_automation_control', 'vls_analyze_browser'], true)
            ? BrowserAutomationGateway::RESOURCE_LOCK_TIMEOUT_SECONDS
            : 0;
    }

    private function callMcp(string $toolKey, array $arguments, array $transportConfig): array
    {
        $payload = [
            'action' => 'call_tool',
            'user_id' => $this->userId,
            'server_key' => $transportConfig['server_key'] ?? '',
            'tool_name' => $transportConfig['original_name'] ?? $toolKey,
            'arguments' => $arguments,
        ];
        $raw = ($this->relay)(
            '/mcp-op',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0
        );
        $result = $this->normalizeRawResult($raw);
        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        try {
            $this->repository->updateMcpConnection(
                $this->userId,
                (string)($transportConfig['server_key'] ?? ''),
                !empty($result['ok']) ? 'connected' : 'error',
                (string)($metadata['vault_key'] ?? ''),
                is_array($metadata['scopes'] ?? null) ? $metadata['scopes'] : [],
                isset($metadata['expires_at']) ? (string)$metadata['expires_at'] : null,
                !empty($result['ok']) ? null : (string)($result['error']['message'] ?? 'MCP 工具调用失败')
            );
        } catch (Throwable $e) {
            // The tool result remains authoritative if connection metadata persistence fails.
        }
        return $result;
    }

    private function httpJson(string $url, array $payload, int $timeout = 0): array
    {
        $ch = curl_init($url);
        $cancelled = false;
        $lastHeartbeat = 0;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function () use (&$cancelled, &$lastHeartbeat): int {
                $cooperativePump = $GLOBALS['teamCooperativePump'] ?? null;
                if (is_callable($cooperativePump)) {
                    $cooperativePump();
                }
                if ($this->repository->isRunCancelled($this->events->runId())) {
                    $cancelled = true;
                    return 1;
                }
                if (time() - $lastHeartbeat >= 5) {
                    $context = is_array($GLOBALS['teamActiveToolContext'] ?? null)
                        ? $GLOBALS['teamActiveToolContext']
                        : [];
                    $this->events->emitTransient('agent.waiting', [
                        'state' => 'waiting_external',
                        'label' => '等待外部工具',
                        'tool_key' => $context['tool_key'] ?? null,
                    ], $context['agent_key'] ?? null, 'moonya', $context['task_id'] ?? null, $context['tool_call_id'] ?? null);
                    $this->events->heartbeat();
                    $lastHeartbeat = time();
                }
                return 0;
            },
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($cancelled) {
            return $this->errorResult('团队运行已由用户停止', 'run_cancelled');
        }
        if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
            return $this->errorResult(
                $error !== '' ? $error : "HTTP {$httpCode}",
                'http_error'
            );
        }
        return $this->normalizeRawResult($raw);
    }

    private function normalizeRawResult($raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string)$raw, true);
        }
        if (!is_array($decoded)) {
            return [
                'ok' => trim((string)$raw) !== '',
                'content' => (string)$raw,
                'structured_content' => null,
                'artifacts' => [],
                'metadata' => [],
                'error' => trim((string)$raw) === '' ? ['code' => 'empty_result', 'message' => '工具未返回结果'] : null,
            ];
        }
        if (array_key_exists('ok', $decoded)) {
            $error = is_array($decoded['error'] ?? null) ? TeamRepository::redact($decoded['error']) : null;
            $content = $decoded['content'] ?? '';
            if (!is_string($content)) {
                $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return [
                'ok' => (bool)$decoded['ok'],
                'content' => (string)$content,
                'structured_content' => TeamRepository::redact($decoded['structured_content'] ?? null),
                'artifacts' => is_array($decoded['artifacts'] ?? null)
                    ? TeamRepository::redact($decoded['artifacts'])
                    : [],
                'metadata' => is_array($decoded['metadata'] ?? null)
                    ? TeamRepository::redact($decoded['metadata'])
                    : [],
                'error' => (bool)$decoded['ok'] ? null : ($error ?? [
                    'code' => 'tool_failed',
                    'message' => (string)$content,
                ]),
            ];
        }
        $ok = !array_key_exists('success', $decoded) || (bool)$decoded['success'];
        if (isset($decoded['status']) && in_array($decoded['status'], ['error', 'failed'], true)) {
            $ok = false;
        }
        $commandOk = $decoded['command_ok'] ?? $decoded['commandOk'] ?? null;
        if (($decoded['status'] ?? '') !== 'running' && $commandOk === false) {
            $ok = false;
        }
        $message = '';
        foreach (['message', 'summary', 'output', 'content', 'error'] as $messageKey) {
            if (isset($decoded[$messageKey])
                && (is_scalar($decoded[$messageKey]) || $decoded[$messageKey] === null)
                && trim((string)$decoded[$messageKey]) !== ''
            ) {
                $message = (string)$decoded[$messageKey];
                break;
            }
        }
        if (!is_string($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return [
            'ok' => $ok,
            'content' => $message !== '' ? $message : json_encode(TeamRepository::redact($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'structured_content' => TeamRepository::redact($decoded),
            'artifacts' => [],
            'metadata' => [],
            'error' => $ok ? null : [
                'code' => (string)($decoded['errorCode'] ?? $decoded['error_code']
                    ?? (($decoded['status'] ?? '') === 'killed' ? 'background_command_killed' : 'background_command_failed')),
                'message' => (string)($decoded['error'] ?? $decoded['stderr'] ?? $decoded['message'] ?? '工具执行失败'),
            ],
        ];
    }

    private function waitForApproval(string $approvalId): string
    {
        $lastHeartbeat = time();
        while (true) {
            $cooperativePump = $GLOBALS['teamCooperativePump'] ?? null;
            if (is_callable($cooperativePump)) {
                $cooperativePump();
            }
            $status = $this->repository->getApprovalStatus($approvalId, $this->userId);
            if ($status !== 'pending') {
                return $status;
            }
            if ($this->repository->isRunCancelled($this->events->runId())) {
                return 'denied';
            }
            if (connection_aborted()) {
                return 'denied';
            }
            if (time() - $lastHeartbeat >= 5) {
                $this->events->emitTransient('agent.waiting', [
                    'state' => 'waiting_approval',
                    'label' => '等待确认',
                    'approval_id' => $approvalId,
                ]);
                $this->events->heartbeat();
                $lastHeartbeat = time();
            }
            usleep(100000);
        }
    }

    private function emitToolCompleted(
        string $agentKey,
        string $taskId,
        string $toolCallId,
        string $toolKey,
        array $result
    ): void {
        $this->events->emit(
            'tool.completed',
            array_merge([
                'tool_key' => $toolKey,
                'ok' => (bool)($result['ok'] ?? false),
                'content' => $result['content'] ?? '',
                'error' => $result['error'] ?? null,
                'stage' => $result['structured_content']['stage'] ?? null,
                'status' => $result['structured_content']['status'] ?? null,
                'error_code' => $result['structured_content']['errorCode']
                    ?? $result['structured_content']['error_code']
                    ?? $result['error']['code']
                    ?? null,
                'command_fingerprint' => $result['structured_content']['commandFingerprint']
                    ?? $result['structured_content']['command_fingerprint']
                    ?? null,
                'operation_receipt' => $result['metadata']['operation_receipt'] ?? null,
                // TeamEventEmitter extracts any inline images from these fields
                // before persistence, then leaves the structured verification
                // data and authenticated media references in the work log.
                'result' => [
                    'structured_content' => $result['structured_content'] ?? null,
                    'artifacts' => $result['artifacts'] ?? [],
                    'metadata' => $result['metadata'] ?? [],
                ],
            ], $this->taskEventContext($taskId, 'tool_completed')),
            $agentKey,
            'moonya',
            $taskId,
            $toolCallId
        );
    }

    private function taskEventContext(string $taskId, string $phase): array
    {
        $scope = is_array($this->taskScopes[$taskId] ?? null)
            ? $this->taskScopes[$taskId]
            : [];
        if (($scope['project_group_id'] ?? '') === '') {
            return [];
        }
        return ProjectTeamProtocol::eventContext(
            $scope,
            (string)($scope['project_phase'] ?? $phase)
        );
    }

    private function extractArtifacts(array $result): array
    {
        $artifacts = is_array($result['artifacts'] ?? null) ? $result['artifacts'] : [];
        $seen = [];
        $walk = function ($value, ?string $key = null) use (&$walk, &$artifacts, &$seen): void {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $walk($v, is_string($k) ? $k : null);
                }
                return;
            }
            if (!is_string($value) || $value === '') {
                return;
            }
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $walk($decoded, $key);
            }
            $isUriKey = $key !== null && preg_match('/(?:path|file|url|uri|local_path|download_url)$/i', $key);
            $looksLikeFile = preg_match('/\.(?:png|jpe?g|gif|webp|pdf|docx?|xlsx?|pptx?|csv|txt|md|zip|mp4|webm|mp3|wav)$/i', parse_url($value, PHP_URL_PATH) ?: $value);
            if (!$isUriKey && !$looksLikeFile) {
                return;
            }
            if (isset($seen[$value])) {
                return;
            }
            $seen[$value] = true;
            $artifacts[] = ['uri' => $value];
        };
        $walk($result['structured_content'] ?? null);
        return $artifacts;
    }

    private function errorResult(string $message, string $code): array
    {
        return [
            'ok' => false,
            'content' => $message,
            'structured_content' => null,
            'artifacts' => [],
            'metadata' => [],
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    private function canonicalToolName(string $toolKey): string
    {
        return match ($toolKey) {
            'execute_command' => 'shell_executor',
            'execute_python' => 'python_executor',
            default => $toolKey,
        };
    }

    private function isManagedRuntimeInstallAttempt(string $toolKey, array $arguments): bool
    {
        $text = $toolKey === 'shell_executor'
            ? (string)($arguments['command'] ?? '')
            : (string)($arguments['code'] ?? '');
        if ($text === '' || !$this->containsPipInstallTokens($text)) {
            return false;
        }
        if ($toolKey === 'shell_executor') {
            return true;
        }

        // Python source may legitimately include documentation containing the
        // words "pip install". Reject only when it also invokes a process or
        // pip's programmatic entry points.
        return preg_match(
            '/\b(?:subprocess\s*\.|os\s*\.\s*(?:system|popen|spawn\w*)\b|Popen\s*\(|pip\s*\.)/i',
            $text
        ) === 1;
    }

    private function containsPipInstallTokens(string $text): bool
    {
        $tokens = preg_split('/[^a-z0-9_.-]+/i', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens)) {
            return false;
        }
        foreach ($tokens as $index => $token) {
            if (preg_match('/^pip(?:3(?:\.\d+)*)?(?:\.exe)?$/', $token) !== 1) {
                continue;
            }
            $limit = min(count($tokens), $index + 10);
            for ($next = $index + 1; $next < $limit; $next++) {
                if ($tokens[$next] === 'install') {
                    return true;
                }
            }
        }
        return false;
    }
}
