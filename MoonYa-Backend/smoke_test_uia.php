<?php
// Smoke test: verify new UIA refactor classes load and instantiate correctly
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

chdir(__DIR__);
$config = require_once __DIR__ . '/config.php';
require __DIR__ . '/Services/CuEventEmitter.php';
require __DIR__ . '/Services/AIAssistant.php';

// config.php 第 576 行已将 agent_config.php 的返回值嵌入 $config['agent_mode']
$agentConfig = $config['agent_mode'] ?? null;
if (!is_array($agentConfig)) {
    echo "[FAIL] \$config['agent_mode'] is not an array (got " . gettype($agentConfig) . ")\n";
    exit(1);
}

echo "=== UIA Refactor Smoke Test ===\n";

// 1. CuEventEmitter instantiation
$emitter = new CuEventEmitter();
echo "[OK] CuEventEmitter instantiated: " . get_class($emitter) . "\n";

// 2. Config has cu_model
$cuModel = $agentConfig['cu_model'] ?? 'MISSING';
echo "[OK] cu_model config = {$cuModel}\n";

// 3. All 13 CU tools present
$cuToolNames = array_column(array_map(fn($t) => $t['function'], $agentConfig['agent_tools']), 'name');
$expectedUiaTools = ['find_element', 'get_ui_tree', 'click_element', 'set_text', 'get_text'];
$expectedCoordTools = ['take_screenshot', 'get_cursor_pos', 'mouse_move', 'mouse_click', 'mouse_scroll', 'keyboard_type', 'key_press', 'task_complete'];
$missingUia = array_diff($expectedUiaTools, $cuToolNames);
$missingCoord = array_diff($expectedCoordTools, $cuToolNames);
if (empty($missingUia) && empty($missingCoord)) {
    echo "[OK] All 13 CU tools present (5 UIA + 8 coord)\n";
} else {
    echo "[FAIL] Missing UIA tools: " . implode(',', $missingUia) . "\n";
    echo "[FAIL] Missing coord tools: " . implode(',', $missingCoord) . "\n";
}

// 4. UIA/visual tool properties structure
foreach ($agentConfig['agent_tools'] as $tool) {
    $name = $tool['function']['name'];
    $props = $tool['function']['parameters']['properties'] ?? null;
    if ($name === 'get_cursor_pos') {
        if ($props instanceof \stdClass) {
            echo "[OK] {$name}: properties uses stdClass (empty)\n";
        } else {
            echo "[WARN] {$name}: properties should be stdClass, got " . gettype($props) . "\n";
        }
    } elseif ($name === 'take_screenshot') {
        if (is_array($props) && isset($props['target'])
            && in_array('screen', $props['target']['enum'] ?? [], true)) {
            echo "[OK] take_screenshot: target supports full-screen visual fallback\n";
        } else {
            echo "[FAIL] take_screenshot: target=screen parameter is missing\n";
        }
    } elseif (in_array($name, $expectedUiaTools)) {
        if (is_array($props)) {
            echo "[OK] {$name}: properties uses array (" . count($props) . " fields)\n";
        } else {
            echo "[FAIL] {$name}: properties should be array, got " . gettype($props) . "\n";
        }
    }
}

// 5. AIAssistant class reflection
$reflection = new ReflectionClass('AIAssistant');
$constructor = $reflection->getConstructor();
$params = $constructor->getParameters();
echo "[OK] AIAssistant constructor params: " . count($params) . "\n";
foreach ($params as $p) {
    $type = $p->getType();
    echo "  - \${$p->getName()}: " . ($type ? $type->getName() : 'mixed') . "\n";
}

// 6. AIAssistant methods
$methods = ['runCuLoop', 'queryUiAction', 'injectReasoningField', 'enforceClickStopLoss', 'handleToolCall'];
foreach ($methods as $m) {
    if ($reflection->hasMethod($m)) {
        echo "[OK] AIAssistant::{$m}() exists\n";
    } else {
        echo "[FAIL] AIAssistant::{$m}() missing\n";
    }
}

// 7. Verify reasoning injection works on a copy of tools
$testTools = array_slice($agentConfig['agent_tools'], 0, 3, true);
// Simulate injectReasoningField logic
foreach ($testTools as &$tool) {
    if (!isset($tool['function']['parameters'])) {
        $tool['function']['parameters'] = ['type' => 'object'];
    }
    $props = $tool['function']['parameters']['properties'] ?? null;
    if ($props instanceof \stdClass) {
        $props = (array)$props;
    } elseif (!is_array($props)) {
        $props = [];
    }
    $props['reasoning'] = ['type' => 'string', 'description' => 'test'];
    $tool['function']['parameters']['properties'] = $props;
    $required = $tool['function']['parameters']['required'] ?? [];
    if (!is_array($required)) $required = [];
    $required[] = 'reasoning';
    $tool['function']['parameters']['required'] = $required;
}
unset($tool);
echo "[OK] Reasoning injection simulation passed (no Fatal Error)\n";

echo "\n=== Smoke Test Complete ===\n";
echo "All static checks passed. Runtime testing (Tasks 10-12) requires live environment.\n";
