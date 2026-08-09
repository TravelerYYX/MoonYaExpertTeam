<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/Services/CuPolicyCatalog.php';

$dsn = 'mysql:host=' . (env('DB_HOST') ?: 'localhost')
    . ';dbname=' . (env('DB_NAME') ?: 'ai_system')
    . ';charset=utf8mb4';
$pdo = new PDO($dsn, env('DB_USER') ?: '', env('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// DDL must run outside the seed transaction because MySQL commits ALTER TABLE.
$reliabilityColumns = [
    'network_retry_max' => 'INT NOT NULL DEFAULT 5',
    'network_retry_base_delay_ms' => 'INT NOT NULL DEFAULT 1000',
    'network_retry_max_delay_ms' => 'INT NOT NULL DEFAULT 16000',
    'cu_total_timeout_seconds' => 'INT NOT NULL DEFAULT 0',
];
$columnCheck = $pdo->prepare('SHOW COLUMNS FROM cu_runtime_config LIKE ?');
foreach ($reliabilityColumns as $column => $definition) {
    $columnCheck->execute([$column]);
    if ($columnCheck->fetch(PDO::FETCH_ASSOC) === false) {
        $pdo->exec("ALTER TABLE cu_runtime_config ADD COLUMN `{$column}` {$definition}");
    }
}

$pdo->beginTransaction();
try {
    $row = $pdo->query(
        'SELECT user_login_intent_keywords FROM cu_runtime_config WHERE id=1 FOR UPDATE'
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('cu_runtime_config id=1 is missing');
    }

    $loginPatterns = json_decode((string)($row['user_login_intent_keywords'] ?? ''), true);
    if (!is_array($loginPatterns)) {
        $loginPatterns = [];
    }
    $loginPatterns = array_values(array_unique(array_merge(['登录', '登陆'], $loginPatterns)));

    $toolDescriptions = [];
    foreach (($config['agent_mode']['agent_tools'] ?? []) as $tool) {
        $name = (string)($tool['function']['name'] ?? '');
        $description = (string)($tool['function']['description'] ?? '');
        if ($name !== '' && $description !== '') {
            $toolDescriptions[$name] = $description;
        }
    }
    if (!isset($toolDescriptions['focus_window'])) {
        throw new RuntimeException('focus_window tool schema is missing');
    }

    $update = $pdo->prepare(
        'UPDATE cu_runtime_config
         SET cu_max_iterations=1000,
             cu_api_timeout=90,
             vls_max_iterations=3,
             vls_failure_threshold=3,
             user_login_intent_keywords=?,
             tool_descriptions=?,
             scenario_hints=?,
             keyboard_fallback_hints=?,
             network_retry_max=5,
             network_retry_base_delay_ms=1000,
             network_retry_max_delay_ms=16000,
             cu_total_timeout_seconds=0
         WHERE id=1'
    );
    $update->execute([
        json_encode($loginPatterns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($toolDescriptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode(CuPolicyCatalog::scenarioHints(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode(CuPolicyCatalog::keyboardFallbackHints(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $promptUpdate = $pdo->prepare(
        'UPDATE system_prompts SET prompt=?, updated_at=CURRENT_TIMESTAMP WHERE name=?'
    );
    foreach (CuPolicyCatalog::prompts() as $name => $prompt) {
        $promptUpdate->execute([$prompt, $name]);
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'protocol_version' => 'cu-reliability-v2',
        'tool_descriptions' => count($toolDescriptions),
        'login_patterns' => count($loginPatterns),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
