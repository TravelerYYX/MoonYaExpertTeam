<?php
declare(strict_types=1);

require_once __DIR__ . '/../Services/AgentLoopGuard.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function callOf(string $name, array $arguments): array
{
    return ['function' => ['name' => $name, 'arguments' => json_encode($arguments)]];
}

function resultOf(string $status, string $artifact = ''): array
{
    return [
        'ok' => $status === 'success',
        'status' => $status,
        'structured_content' => ['state' => $status],
        'artifacts' => $artifact === '' ? [] : [['path' => $artifact]],
        'error' => $status === 'success' ? null : ['code' => 'same_error'],
    ];
}

$guard = new AgentLoopGuard(3, 4, 1);
for ($i = 0; $i < 220; $i++) {
    $decision = $guard->observe('different', [callOf('read_file', ['path' => "f{$i}"])], [resultOf('success', "a{$i}")]);
    expect($decision['action'] === 'continue', 'Different calls must not be limited.');
}

$guard = new AgentLoopGuard(3, 4, 1);
foreach (['A', 'A'] as $step) {
    expect($guard->observe('aaa', [callOf($step, [])], [resultOf('error')])['action'] === 'continue', 'AAA fired too early.');
}
expect($guard->observe('aaa', [callOf('A', [])], [resultOf('error')])['action'] === 'recover', 'AAA must recover once.');
foreach (['A', 'A'] as $step) {
    $guard->observe('aaa', [callOf($step, [])], [resultOf('error')]);
}
expect($guard->observe('aaa', [callOf('A', [])], [resultOf('error')])['action'] === 'stop', 'Repeated AAA must stop.');

foreach ([['ABAB', ['A','B','A','B','A','B']], ['ABCABC', ['A','B','C','A','B','C','A','B','C']]] as [$scope, $steps]) {
    $guard = new AgentLoopGuard(3, 4, 1);
    $last = null;
    foreach ($steps as $step) {
        $last = $guard->observe($scope, [callOf($step, [])], [resultOf('error')]);
    }
    expect(($last['action'] ?? '') === 'recover', "{$scope} must be detected.");
}

$guard = new AgentLoopGuard(3, 4, 1);
foreach ([resultOf('error'), resultOf('error'), resultOf('success', 'new.txt')] as $result) {
    $decision = $guard->observe('progress', [callOf('A', [])], [$result]);
}
expect($decision['action'] === 'continue', 'New status/artifact must break a cycle.');

$guard = new AgentLoopGuard(3, 4, 1);
for ($i = 0; $i < 20; $i++) {
    $decision = $guard->observe('waiting', [callOf('shell_executor', ['command' => 'download'])], [[
        'ok' => true,
        'structured_content' => ['status' => 'running'],
        'metadata' => ['operation_receipt' => ['state' => 'action_running']],
    ]]);
    expect($decision['action'] === 'ignored', 'Waiting observations must be ignored.');
}

$guard = new AgentLoopGuard(3, 4, 1);
for ($i = 0; $i < 3; $i++) {
    $taskId = "wording-{$i}";
    $decision = $guard->observe('delegation', [callOf('delegate_to_agents', ['tasks' => [[
        'id' => $taskId,
        'capability_key' => 'code.engineering',
        'instruction' => "Different wording {$i}",
        'context' => "Different context {$i}",
        'selection_reason' => "Reason {$i}",
        'depends_on' => [],
    ]]])], [[
        'ok' => true,
        'metadata' => ['dispatch_status' => 'executed'],
        'structured_content' => [$taskId => [
            'status' => 'error',
            'error' => ['code' => 'same_error'],
            'artifacts' => [],
        ]],
        'artifacts' => [],
    ]]);
}
expect($decision['action'] === 'recover', 'Wording-only delegation changes must still be detected.');

echo "agent loop guard tests passed\n";
