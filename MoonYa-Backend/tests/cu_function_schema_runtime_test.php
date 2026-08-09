<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Services/AIAssistant.php';

function failSchemaRuntime(string $message): never
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

$assistant = (new ReflectionClass(AIAssistant::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(AIAssistant::class, 'injectReasoningField');
$method->setAccessible(true);

$tools = [[
    'type' => 'function',
    'function' => [
        'name' => 'computer_observe',
        'description' => 'Observe the desktop.',
        'parameters' => (object)[
            'type' => 'object',
            'properties' => (object)[],
        ],
    ],
]];

$method->invokeArgs($assistant, [&$tools]);

$parameters = $tools[0]['function']['parameters'] ?? null;
if (!is_array($parameters)) {
    failSchemaRuntime('root Function Schema was not normalized to an array');
}
if (($parameters['type'] ?? null) !== 'object') {
    failSchemaRuntime('schema type was not preserved');
}
if (!isset($parameters['properties']['reasoning'])) {
    failSchemaRuntime('reasoning property was not injected');
}
if (!in_array('reasoning', $parameters['required'] ?? [], true)) {
    failSchemaRuntime('reasoning was not made required');
}

$encoded = json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (!str_contains($encoded, '"parameters":{"type":"object","properties":{"reasoning"')) {
    failSchemaRuntime('normalized schema did not serialize as a Function Calling object');
}

fwrite(STDOUT, "[PASS] CU registry stdClass Function Schema is normalized before reasoning injection.\n");
