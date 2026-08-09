<?php
declare(strict_types=1);

/**
 * Server-authoritative CU capability ordering.
 *
 * The order is a selection policy, not a request to execute every tier.  The
 * model may only move to a lower tier when the higher tier is unavailable or
 * has returned a result that proves no side effect was committed.
 */
final class CapabilityRouter
{
    public const SPECIALIZED_API = 'specialized_api';
    public const DETERMINISTIC_TOOL = 'deterministic_tool';
    public const SHELL = 'shell';
    public const BROWSER = 'browser';
    public const COMPUTER = 'computer';

    private const ORDER = [
        self::SPECIALIZED_API => 10,
        self::DETERMINISTIC_TOOL => 20,
        self::SHELL => 30,
        self::BROWSER => 40,
        self::COMPUTER => 50,
    ];

    /** Raw desktop primitives remain an internal launcher protocol. */
    private const INTERNAL_DESKTOP_TOOLS = [
        'capture_ui_snapshot', 'take_screenshot', 'get_cursor_pos',
        'mouse_move', 'mouse_click', 'mouse_scroll', 'mouse_drag', 'mouse_hold',
        'keyboard_type', 'key_press', 'find_element', 'get_ui_tree',
        'click_element', 'set_text', 'get_text', 'focus_window', 'task_complete',
    ];

    private const BUSINESS_DESKTOP_TOOLS = [
        'computer_observe', 'computer_interact', 'computer_complete',
    ];

    private const SPECIALIZED_NAMES = [
        'web_search', 'web_fetch', 'web_crawler', 'get_weather', 'search_music',
        'get_horoscope', 'translate_classical', 'ZTimage-Agent', 'generate_image',
        'generate_video', 'open_video_site', 'MoonYa-T-Agent',
    ];

    private const SHELL_NAMES = [
        'shell_executor', 'python_executor', 'execute_command', 'execute_python',
        'get_command_status', 'stop_command',
    ];

    private const BROWSER_NAMES = [
        'browser_automation_control', 'vls_analyze_browser',
    ];

    public static function classifyDefinition(array $tool): string
    {
        $declared = trim((string)($tool['route_class'] ?? ''));
        if (isset(self::ORDER[$declared])) {
            return $declared;
        }
        $function = is_array($tool['function'] ?? null) ? $tool['function'] : [];
        $name = (string)($function['name'] ?? $tool['tool_key'] ?? '');
        $transport = (string)($tool['transport'] ?? '');
        $source = (string)($tool['source'] ?? '');

        if ($source === 'mcp' || $transport === 'mcp' || str_starts_with($name, 'mcp__')) {
            return self::SPECIALIZED_API;
        }
        if (in_array($name, self::SPECIALIZED_NAMES, true)) {
            return self::SPECIALIZED_API;
        }
        if (in_array($name, self::SHELL_NAMES, true)) {
            return self::SHELL;
        }
        if (in_array($name, self::BROWSER_NAMES, true)) {
            return self::BROWSER;
        }
        if (in_array($name, self::INTERNAL_DESKTOP_TOOLS, true)
            || in_array($name, self::BUSINESS_DESKTOP_TOOLS, true)) {
            return self::COMPUTER;
        }
        return self::DETERMINISTIC_TOOL;
    }

    public static function classifyRegistryRow(array $tool): string
    {
        $declared = trim((string)($tool['route_class'] ?? ''));
        return isset(self::ORDER[$declared]) ? $declared : self::classifyDefinition($tool);
    }

    /**
     * Merge tool definitions, hide raw CU primitives, annotate and order them.
     * Later sets replace an earlier definition with the same function name.
     */
    public static function modelTools(array ...$sets): array
    {
        $byName = [];
        foreach ($sets as $tools) {
            foreach ($tools as $tool) {
                if (!is_array($tool) || !is_array($tool['function'] ?? null)) {
                    continue;
                }
                $name = trim((string)($tool['function']['name'] ?? ''));
                if ($name === '' || in_array($name, self::INTERNAL_DESKTOP_TOOLS, true)) {
                    continue;
                }
                $tool['_route_class'] = self::classifyDefinition($tool);
                $byName[$name] = $tool;
            }
        }
        $result = array_values($byName);
        usort($result, static function (array $left, array $right): int {
            $leftClass = (string)($left['_route_class'] ?? self::classifyDefinition($left));
            $rightClass = (string)($right['_route_class'] ?? self::classifyDefinition($right));
            $order = (self::ORDER[$leftClass] ?? 999) <=> (self::ORDER[$rightClass] ?? 999);
            if ($order !== 0) {
                return $order;
            }
            return strcmp(
                (string)($left['function']['name'] ?? ''),
                (string)($right['function']['name'] ?? '')
            );
        });
        foreach ($result as &$tool) {
            unset($tool['_route_class'], $tool['route_class'], $tool['source'], $tool['transport']);
        }
        unset($tool);
        return $result;
    }

    public static function routeOrder(): array
    {
        return array_keys(self::ORDER);
    }

    public static function isBusinessDesktopTool(string $name): bool
    {
        return in_array($name, self::BUSINESS_DESKTOP_TOOLS, true);
    }

    /**
     * A lower-tier fallback is safe only when the result proves no commit.
     */
    public static function mayFallback(array $result): bool
    {
        if (!empty($result['ok'])) {
            return false;
        }
        $code = (string)($result['error']['code']
            ?? $result['failure_code']
            ?? $result['structured_content']['failure_code']
            ?? '');
        return in_array($code, [
            'not_applicable', 'unsupported', 'unavailable', 'not_found',
            'uia_unavailable', 'uia_target_not_found', 'browser_unavailable',
            'self_drawn_ui', 'definitely_not_executed',
        ], true);
    }
}
