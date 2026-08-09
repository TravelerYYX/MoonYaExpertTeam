<?php
declare(strict_types=1);

final class RiskPolicy
{
    public function effectivePolicy(array $tool, array $arguments): array
    {
        $effect = (string)($tool['effect'] ?? 'write');
        $risk = (string)($tool['risk_level'] ?? 'high');
        if (($tool['tool_key'] ?? '') !== 'browser_automation_control') {
            return [$effect, $risk];
        }

        $action = strtolower(trim((string)($arguments['action'] ?? '')));
        if (in_array($action, ['screenshot', 'inspect', 'read', 'get_content'], true)) {
            return ['read', 'low'];
        }
        if (in_array($action, [
            'upload', 'submit_sensitive', 'purchase', 'payment',
            'send_credentials', 'confirm_payment',
        ], true)) {
            return ['write', 'high'];
        }
        return ['write', 'medium'];
    }

    public function requiresApproval(array $tool, array $arguments, string $mode): array
    {
        [$effect, $risk] = $this->effectivePolicy($tool, $arguments);
        $reviewed = (bool)($tool['reviewed'] ?? false);
        $source = (string)($tool['source'] ?? 'native');

        return $this->requiresApprovalForPolicy($effect, $risk, $mode, $source, $reviewed);
    }

    /**
     * Apply approval mode to a server-trusted policy classification.
     * Shell classifications reach this method only after launcher preflight.
     */
    public function requiresApprovalForPolicy(
        string $effect,
        string $risk,
        string $mode,
        string $source = 'native',
        bool $reviewed = true
    ): array {

        // Server-side immutable safety boundary: unreviewed external tools always
        // require a human regardless of the client-selected convenience mode.
        if ($source === 'mcp' && !$reviewed) {
            return [true, '该 MCP 工具尚未经过管理员审核'];
        }
        if ($mode === 'confirm_writes' && $effect !== 'read') {
            return [true, '当前会话设置为“变更前始终确认”'];
        }
        if ($mode === 'high_risk' && in_array($risk, ['high', 'critical'], true)) {
            return [true, "工具风险级别为 {$risk}"];
        }
        if ($mode === 'full_access') {
            return [false, ''];
        }
        return [false, ''];
    }
}
