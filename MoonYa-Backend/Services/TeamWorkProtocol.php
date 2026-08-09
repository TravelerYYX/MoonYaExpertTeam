<?php
declare(strict_types=1);

/**
 * Server-side contract for a Work-mode team run.
 *
 * MoonYa selects a database-defined capability, then TeamCoordinator resolves
 * that capability to its unique Agent and validates the required tool grants.
 * This class enforces the Work boundary: MoonYa may either delegate executable
 * work, respond directly for chat/clarification, or explicitly finalize from
 * validated employee evidence.
 */
final class TeamWorkProtocol
{
    public const DELEGATION_FUNCTION = 'delegate_to_agents';
    public const DIRECT_RESPONSE_FUNCTION = 'respond_without_delegation';
    public const FINALIZE_FUNCTION = 'finalize_work';

    /**
     * Work is a team-only protocol. If its runtime is unavailable, callers
     * must fail closed instead of exposing the legacy root execution tools.
     * CU remains an independent compatibility path.
     */
    public static function requiresTeamRuntime(
        string $agentMode,
        bool $computerUserMode
    ): bool {
        return $agentMode === 'agent' && !$computerUserMode;
    }

    /** @param list<string> $allowedModels */
    public static function normalizeConfiguredModel(string $model, array $allowedModels, string $defaultModel): string
    {
        $model = trim($model);
        if ($defaultModel === '' || !in_array($defaultModel, $allowedModels, true)) {
            throw new InvalidArgumentException('Configured default model is missing from its allowlist');
        }
        return in_array($model, $allowedModels, true) ? $model : $defaultModel;
    }

    public static function modelCapabilities(array $config, string $model): array
    {
        $capabilities = $config['model_capabilities'][$model] ?? null;
        if (!is_array($capabilities) || trim((string)($capabilities['provider'] ?? '')) === '') {
            throw new InvalidArgumentException("Model is missing from model_capabilities: {$model}");
        }
        return $capabilities;
    }

    public static function endpointForConfiguredModel(array $config, string $model): array
    {
        $capabilities = self::modelCapabilities($config, $model);
        $provider = (string)$capabilities['provider'];
        $route = $config['model_provider_routes'][$provider] ?? null;
        if (!is_array($route)) {
            throw new InvalidArgumentException("Missing model provider route: {$provider}");
        }
        $urlKey = trim((string)($route['url_key'] ?? ''));
        $apiKeyKey = trim((string)($route['api_key_key'] ?? ''));
        $url = trim((string)($config[$urlKey] ?? ''));
        $apiKey = trim((string)($config[$apiKeyKey] ?? ''));
        if ($urlKey === '' || $apiKeyKey === '' || $url === '' || $apiKey === '') {
            throw new RuntimeException("Missing endpoint configuration for model provider: {$provider}");
        }
        return [$url, $apiKey];
    }

    /**
     * Normalize MoonYa's five UI levels without pretending that DeepSeek has
     * five distinct wire-level efforts.
     */
    public static function normalizeReasoningLevel(string $level): string
    {
        $level = strtolower(trim($level));
        if ($level === 'max') {
            return 'xhigh';
        }
        return in_array($level, ['none', 'low', 'medium', 'high', 'xhigh'], true)
            ? $level
            : 'high';
    }

    /** Apply the configured reasoning-control contract without inspecting model names. */
    public static function applyReasoningPolicy(array $request, string $level, array $capabilities): array
    {
        if (($capabilities['reasoning_control'] ?? '') !== 'binary_strength') {
            unset($request['reasoning_effort']);
            return $request;
        }

        $level = self::normalizeReasoningLevel($level);
        if ($level === 'none') {
            $request['thinking'] = ['type' => 'disabled'];
            unset($request['reasoning_effort']);
            return $request;
        }

        $request['thinking'] = ['type' => 'enabled'];
        $request['reasoning_effort'] = $level === 'xhigh' ? 'max' : 'high';
        if (($capabilities['strip_sampling_when_thinking'] ?? false) === true) {
            unset(
                $request['temperature'],
                $request['top_p'],
                $request['presence_penalty'],
                $request['frequency_penalty'],
                $request['stop']
            );
        }
        return $request;
    }

    public static function prepareInitialRequest(
        array $request,
        array $coordinatorTools,
        array $functionCallingCompatibility = [],
        array $modelCapabilities = []
    ): array
    {
        if (isset($coordinatorTools['function'])) {
            $coordinatorTools = [$coordinatorTools];
        }
        $functionNames = array_values(array_filter(array_map(
            static fn(array $tool): string => (string)($tool['function']['name'] ?? ''),
            $coordinatorTools
        )));
        if (!in_array(self::DELEGATION_FUNCTION, $functionNames, true)
            || !in_array(self::DIRECT_RESPONSE_FUNCTION, $functionNames, true)) {
            throw new InvalidArgumentException('团队委派工具协议无效');
        }

        // MoonYa may either delegate executable work or answer/clarify without
        // employees. Operational tools remain exclusively on employees.
        // finalize_work is unavailable on the first turn: a Work run cannot
        // declare an executable request complete before any employee result
        // exists. It is unlocked only after an executed delegation.
        $request['tools'] = array_values(array_filter(
            $coordinatorTools,
            static fn(array $tool): bool =>
                (string)($tool['function']['name'] ?? '') !== self::FINALIZE_FUNCTION
        ));
        $request['tool_choice'] = 'required';

        $request = self::applyFunctionCallingCompatibility(
            $request,
            $functionCallingCompatibility
        );
        if (($modelCapabilities['omit_tool_choice_when_thinking'] ?? false) === true
            && (($request['thinking']['type'] ?? '') === 'enabled')) {
            unset($request['tool_choice']);
        }
        return $request;
    }

    /**
     * After an employee batch has actually executed, MoonYa must make another
     * explicit coordination decision: delegate more work, ask/respond, or
     * submit a validated completion declaration. A clean batch is never an
     * implicit signal to synthesize the final answer.
     */
    public static function prepareContinuationRequest(
        array $request,
        array $coordinatorTools,
        array $functionCallingCompatibility = []
    ): array {
        $request['tools'] = $coordinatorTools;
        $request['tool_choice'] = 'required';
        return self::applyFunctionCallingCompatibility(
            $request,
            $functionCallingCompatibility
        );
    }

    public static function prepareFinalSynthesisRequest(array $request): array
    {
        unset($request['tools'], $request['tool_choice']);
        return $request;
    }

    /**
     * Apply provider/model wire-protocol compatibility loaded from runtime data.
     *
     * A missing `thinking` field is not equivalent to `thinking=disabled` for
     * every OpenAI-compatible provider. Keeping this policy in the database
     * allows new model families to be added without changing the scheduler.
     */
    public static function applyFunctionCallingCompatibility(
        array $request,
        array $profiles
    ): array {
        $model = trim((string)($request['model'] ?? ''));
        if ($model === '' || $profiles === []) {
            return $request;
        }

        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $modelContains = trim((string)($profile['model_contains'] ?? ''));
            if ($modelContains === '' || stripos($model, $modelContains) === false) {
                continue;
            }

            $thinkingMode = strtolower(trim((string)($profile['thinking'] ?? 'preserve')));
            if ($thinkingMode === 'disabled' || $thinkingMode === 'enabled') {
                $request['thinking'] = ['type' => $thinkingMode];
            } elseif ($thinkingMode === 'omit') {
                unset($request['thinking'], $request['reasoning_effort']);
            }
            if (($profile['supports_tool_choice'] ?? true) === false) {
                unset($request['tool_choice']);
            }
            break;
        }

        return $request;
    }

    public static function hasEmployeeEvidence(array $delegationResult): bool
    {
        $results = $delegationResult['structured_content'] ?? null;
        $dispatchStatus = (string)($delegationResult['metadata']['dispatch_status'] ?? '');
        return ($delegationResult['ok'] ?? false) === true
            && $dispatchStatus === 'executed'
            && is_array($results)
            && $results !== [];
    }

    public static function delegationOutcome(array $delegationResult): array
    {
        $dispatchStatus = (string)($delegationResult['metadata']['dispatch_status'] ?? '');
        if ($dispatchStatus === '') {
            $dispatchStatus = ($delegationResult['ok'] ?? false) ? 'executed' : 'rejected';
        }
        $results = $delegationResult['structured_content'] ?? null;
        if ($dispatchStatus !== 'executed' || !is_array($results)) {
            return [
                'dispatch_status' => $dispatchStatus,
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'retryable' => $dispatchStatus === 'rejected',
            ];
        }

        $success = 0;
        $failed = 0;
        foreach ($results as $result) {
            if (!is_array($result)) {
                $failed++;
                continue;
            }
            if (($result['status'] ?? '') === 'success') {
                $success++;
            } else {
                $failed++;
            }
        }

        return [
            'dispatch_status' => 'executed',
            'total' => count($results),
            'success' => $success,
            'failed' => $failed,
            'retryable' => $failed > 0,
        ];
    }

    public static function isDirectResponse(array $toolResult): bool
    {
        return ($toolResult['ok'] ?? false) === true
            && ($toolResult['metadata']['dispatch_status'] ?? '') === 'direct_response'
            && trim((string)($toolResult['structured_content']['response'] ?? '')) !== '';
    }

    public static function isFinalization(array $toolResult): bool
    {
        return ($toolResult['ok'] ?? false) === true
            && ($toolResult['metadata']['dispatch_status'] ?? '') === 'finalized'
            && in_array(
                (string)($toolResult['structured_content']['outcome'] ?? ''),
                ['completed', 'partial', 'blocked'],
                true
            );
    }

    public static function containsToolProtocolMarkup(string $content): bool
    {
        if ($content === '') {
            return false;
        }
        return preg_match(
            '/(?:<[|｜]{1,2}DSML[|｜]{1,2}|<\/?(?:tool_calls?|invoke|function_calls?)\b|"tool_calls"\s*:)/iu',
            $content
        ) === 1;
    }

    public static function terminalRunStatus(
        bool $hasEmployeeEvidence,
        int $successfulTasks,
        int $failedTasks,
        bool $loopExitedWithError,
        bool $hasDirectResponse = false,
        ?string $finalizationOutcome = null
    ): string {
        if ($hasDirectResponse && !$loopExitedWithError) {
            return 'completed';
        }
        if ($loopExitedWithError) {
            return 'failed';
        }
        if ($finalizationOutcome !== null) {
            return match ($finalizationOutcome) {
                'completed' => 'completed',
                'partial' => 'partial',
                'blocked' => 'failed',
                default => 'failed',
            };
        }
        if (!$hasEmployeeEvidence || $successfulTasks === 0) {
            return 'failed';
        }
        // Employee evidence alone is intermediate. Work can only complete
        // after an accepted finalize_work declaration.
        return 'failed';
    }

    public static function protocolFailureMessage(): string
    {
        return '本次 Work 运行既没有取得员工 Agent 的结构化执行结果，也没有产生有效的直接回复。MoonYa 已拒绝把未执行的文字当作成果。';
    }
}
