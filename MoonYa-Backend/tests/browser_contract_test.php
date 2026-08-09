<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Services/BrowserAutomationGateway.php';

function failBrowserContract(string $message): never
{
    fwrite(STDERR, "browser contract test failed: {$message}\n");
    exit(1);
}

function sqlTupleLine(string $sql, string $prefix): string
{
    $offset = strpos($sql, $prefix);
    if ($offset === false) failBrowserContract("missing SQL row {$prefix}");
    $end = strpos($sql, "\n", $offset);
    return substr($sql, $offset, $end === false ? null : $end - $offset);
}

/** @return list<string|null> */
function parseSqlTuple(string $line): array
{
    $line = trim($line);
    if (!str_starts_with($line, '(')) failBrowserContract('invalid SQL tuple');
    $values = [];
    $value = '';
    $quoted = false;
    $escaped = false;
    $wasQuoted = false;
    for ($index = 1, $length = strlen($line); $index < $length; $index++) {
        $char = $line[$index];
        if ($escaped) {
            $value .= match ($char) {
                'n' => "\n", 'r' => "\r", 't' => "\t",
                default => $char,
            };
            $escaped = false;
            continue;
        }
        if ($quoted && $char === '\\') {
            $escaped = true;
            continue;
        }
        if ($char === "'") {
            $quoted = !$quoted;
            $wasQuoted = true;
            continue;
        }
        if (!$quoted && ($char === ',' || $char === ')')) {
            $trimmed = trim($value);
            $values[] = !$wasQuoted && strtoupper($trimmed) === 'NULL' ? null : $trimmed;
            $value = '';
            $wasQuoted = false;
            if ($char === ')') break;
            continue;
        }
        $value .= $char;
    }
    return $values;
}

$sqlPath = dirname(__DIR__) . '/sql/数据库.sql';
$sql = file_get_contents($sqlPath);
if (!is_string($sql)) failBrowserContract('unable to read SQL baseline');

$operatorRows = [
    parseSqlTuple(sqlTupleLine($sql, "(15, 'browser_automation',")),
    parseSqlTuple(sqlTupleLine($sql, "(20, 'agent_browser',")),
    parseSqlTuple(sqlTupleLine($sql, "(5, 'browser_automation', '浏览器自动化',")),
];
$hashes = array_map(static fn(array $row): string => hash('sha256', (string)($row[3] ?? '')), $operatorRows);
if (count(array_unique($hashes)) !== 1 || ($operatorRows[0][3] ?? '') === '') {
    failBrowserContract('the three browser operator prompts are not byte-identical');
}

$vlsRow = parseSqlTuple(sqlTupleLine($sql, "(16, 'browser_vls_agent',"));
$vlsPrompt = (string)($vlsRow[3] ?? '');
foreach (['.btlink', '.bt-form', '.bt-modal', '.bt-popup', 'Bootstrap', 'Element UI', 'LayUI', 'Ant Design'] as $forbidden) {
    if (str_contains($vlsPrompt, $forbidden)) {
        failBrowserContract("VLS prompt contains site or framework specialization: {$forbidden}");
    }
}

$toolRow = parseSqlTuple(sqlTupleLine($sql, "(45, 'browser_automation_control',"));
$inputSchema = json_decode((string)($toolRow[4] ?? ''), true);
$outputSchema = json_decode((string)($toolRow[5] ?? ''), true);
$transport = json_decode((string)($toolRow[7] ?? ''), true);
if (!is_array($inputSchema) || !is_array($outputSchema) || !is_array($transport)) {
    failBrowserContract('browser tool schemas are not valid JSON');
}
$sqlActions = $inputSchema['properties']['action']['enum'] ?? [];
if ($sqlActions !== BrowserAutomationGateway::ACTIONS) {
    failBrowserContract('SQL action enum differs from the PHP browser protocol');
}

$browserProtocolPath = dirname(__DIR__, 2) . '/MoonYa-Win/MoonYa-Solution/MoonYa/Services/BrowserProtocol.cs';
$browserProtocolSource = file_get_contents($browserProtocolPath);
if (!is_string($browserProtocolSource)) {
    failBrowserContract('unable to read the Windows browser protocol');
}
if (!preg_match('/public static class Actions\s*\{(?<body>.*?)public static readonly/s', $browserProtocolSource, $actionBlock)) {
    failBrowserContract('unable to locate the Windows public action constants');
}
preg_match_all('/public const string \w+\s*=\s*"([^"]+)";/', $actionBlock['body'], $actionMatches);
$windowsActions = $actionMatches[1] ?? [];
if ($windowsActions !== BrowserAutomationGateway::ACTIONS) {
    failBrowserContract('Windows and PHP public browser actions differ');
}

$browserServerPath = dirname(__DIR__, 2) . '/MoonYa-Win/MoonYa-Solution/MoonYa/Services/BrowserApiServer.cs';
$browserServerSource = file_get_contents($browserServerPath);
if (!is_string($browserServerSource)) {
    failBrowserContract('unable to read the Windows browser API server');
}
if (preg_match('/RoutePrefix\s*\+\s*"\/(?:evaluate|eval|javascript)"/i', $browserServerSource)) {
    failBrowserContract('arbitrary JavaScript evaluation is exposed as a browser route');
}
if (($transport['endpoint'] ?? '') !== BrowserAutomationGateway::EXECUTE_ROUTE) {
    failBrowserContract('SQL tool transport is not the unified execute route');
}
foreach (['success', 'error_code', 'browser', 'page_version', 'page_text', 'dom_elements',
    'focused_element', 'page_changed', 'change_evidence'] as $field) {
    if (!array_key_exists($field, $outputSchema['properties'] ?? [])) {
        failBrowserContract("browser result schema is missing {$field}");
    }
}

foreach (['browser_site_permissions', 'browser_action_confirmations'] as $table) {
    if (!str_contains($sql, "CREATE TABLE `{$table}`") ||
        !str_contains($sql, "fk_{$table}_user")) {
        failBrowserContract("SQL baseline is missing table/index definitions for {$table}");
    }
}

$observedRelay = [];
$gateway = new BrowserAutomationGateway(
    static function (string $route, string $body, int $timeout) use (&$observedRelay): string {
        $observedRelay = [
            'route' => $route,
            'body' => json_decode($body, true),
            'timeout' => $timeout,
        ];
        return json_encode(['success' => true], JSON_THROW_ON_ERROR);
    },
    0,
    'user:test'
);
$gatewayResult = $gateway->execute(['action' => 'status']);
if (($gatewayResult['success'] ?? false) !== true
    || ($observedRelay['route'] ?? '') !== BrowserAutomationGateway::EXECUTE_ROUTE
    || ($observedRelay['timeout'] ?? 0) !== BrowserAutomationGateway::DEFAULT_RELAY_TIMEOUT_SECONDS
    || ($observedRelay['body']['user_context'] ?? '') !== 'user:test'
) {
    failBrowserContract('zero browser relay timeout was not replaced by the finite default');
}

$toolGatewaySource = file_get_contents(dirname(__DIR__) . '/Services/ToolGateway.php');
if (!is_string($toolGatewaySource)
    || !str_contains($toolGatewaySource, 'BrowserAutomationGateway::DEFAULT_RELAY_TIMEOUT_SECONDS')
    || !str_contains($toolGatewaySource, 'BrowserAutomationGateway::RESOURCE_LOCK_TIMEOUT_SECONDS')
    || !str_contains($toolGatewaySource, "'resource_lock_timeout'")
) {
    failBrowserContract('team browser dispatch is missing finite relay/lock timeout enforcement');
}

foreach (['162.251.93.209', 'aaa.yueyaxuan.cn', '20091201', '宝塔', '.btlink', '.bt-form', '.bt-modal', '.bt-popup'] as $forbidden) {
    if (str_contains($sql, $forbidden)) failBrowserContract("SQL baseline contains forbidden runtime target: {$forbidden}");
}

echo "browser contract test OK\n";
