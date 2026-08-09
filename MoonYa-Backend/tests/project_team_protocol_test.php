<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Services/ProjectTeamProtocol.php';
require_once dirname(__DIR__) . '/Services/ResourceLockManager.php';

function projectAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function projectRejects(callable $callback, string $needle): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        projectAssert(str_contains($error->getMessage(), $needle), $error->getMessage());
        return;
    }
    throw new RuntimeException("Expected project protocol rejection: {$needle}");
}

$root = 'D:\\workspace\\demo';
$contract = ProjectTeamProtocol::validateContract([
    'group_id' => 'project-1',
    'architecture' => 'app.js 定义路由，store.js 定义公共状态接口。',
    'lead_owned_paths' => ['js/app.js', 'js/store.js'],
    'acceptance_criteria' => ['浏览器控制台无错误', '三个页面均可切换'],
    'work_packages' => [
        [
            'id' => 'work',
            'title' => '工作区',
            'instruction' => '实现工作区页面并自测。',
            'owned_paths' => ['js/pages/work.js'],
            'read_dependencies' => ['js/app.js', 'js/store.js'],
            'depends_on' => [],
        ],
        [
            'id' => 'study',
            'title' => '考公区',
            'instruction' => '实现考公区页面并自测。',
            'owned_paths' => ['js/pages/study.js'],
            'read_dependencies' => ['js/app.js'],
            'depends_on' => [],
        ],
        [
            'id' => 'notes',
            'title' => '随笔区',
            'instruction' => '实现随笔区页面并自测。',
            'owned_paths' => ['js/pages/notes.js'],
            'read_dependencies' => ['js/app.js'],
            'depends_on' => ['work'],
        ],
    ],
], 'project-1', $root, 6);

projectAssert(count($contract['work_packages']) === 3, 'Valid contract lost work packages');
projectAssert(
    str_ends_with($contract['work_packages'][0]['owned_paths'][0], '\\js\\pages\\work.js'),
    'Owned path was not normalized against the project root'
);
projectAssert(
    ProjectTeamProtocol::pathWithinScopes(
        'js/pages/work.js',
        $contract['work_packages'][0]['owned_paths'],
        $root
    ),
    'Owned path scope rejected its own file'
);
projectAssert(
    !ProjectTeamProtocol::pathWithinScopes(
        'js/pages/study.js',
        $contract['work_packages'][0]['owned_paths'],
        $root
    ),
    'Owned path scope accepted a peer file'
);

projectRejects(static function () use ($root): void {
    ProjectTeamProtocol::validateContract([
        'group_id' => 'project-1',
        'architecture' => 'overlap',
        'lead_owned_paths' => [],
        'acceptance_criteria' => ['test'],
        'work_packages' => [
            ['id' => 'a', 'title' => 'A', 'instruction' => 'A', 'owned_paths' => ['js/pages'], 'depends_on' => []],
            ['id' => 'b', 'title' => 'B', 'instruction' => 'B', 'owned_paths' => ['js/pages/work.js'], 'depends_on' => []],
        ],
    ], 'project-1', $root, 6);
}, '并行写入范围重叠');

projectRejects(static function () use ($root): void {
    ProjectTeamProtocol::validateContract([
        'group_id' => 'project-1',
        'architecture' => 'cycle',
        'lead_owned_paths' => [],
        'acceptance_criteria' => ['test'],
        'work_packages' => [
            ['id' => 'a', 'title' => 'A', 'instruction' => 'A', 'owned_paths' => ['a.js'], 'depends_on' => ['b']],
            ['id' => 'b', 'title' => 'B', 'instruction' => 'B', 'owned_paths' => ['b.js'], 'depends_on' => ['a']],
        ],
    ], 'project-1', $root, 6);
}, '依赖形成循环');

projectRejects(static function () use ($root): void {
    ProjectTeamProtocol::normalizeProjectPath('..\\outside.js', $root);
}, '越过项目根目录');

$acceptance = ProjectTeamProtocol::validateAcceptance([
    'group_id' => 'project-1',
    'outcome' => 'completed',
    'evidence_task_ids' => ['member.work', 'member.study'],
    'checks' => ['npm test exit 0', 'browser console clean'],
    'unresolved' => [],
], 'project-1', [
    'member.work' => ['status' => 'success'],
    'member.study' => ['status' => 'success'],
]);
projectAssert($acceptance['outcome'] === 'completed', 'Valid acceptance was rejected');

projectRejects(static function (): void {
    ProjectTeamProtocol::validateAcceptance([
        'group_id' => 'project-1',
        'outcome' => 'completed',
        'evidence_task_ids' => ['member.work'],
        'checks' => ['npm test exit 0'],
        'unresolved' => [],
    ], 'project-1', [
        'member.work' => ['status' => 'success'],
        'member.study' => ['status' => 'success'],
    ]);
}, '引用全部成功成员');

$locks = new ResourceLockManager(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moonya-project-lock-test');
$tool = ['transport' => 'launcher_file'];
projectAssert($locks->keysFor($tool, 'read_file', ['path' => 'a.js'], 'read') === [], 'Read-only calls must not take an exclusive lock');
$leftKeys = $locks->keysFor($tool, 'shell_executor', [
    'cwd' => $root,
    'affected_paths' => [$root . '\\js\\pages\\work.js'],
], 'write', ['project_root' => $root]);
$rightKeys = $locks->keysFor($tool, 'shell_executor', [
    'cwd' => $root,
    'affected_paths' => [$root . '\\js\\pages\\study.js'],
], 'write', ['project_root' => $root]);
projectAssert(array_intersect($leftKeys, $rightKeys) === [], 'Different files in the same cwd were still locked as one project');

$contendedKey = 'browser:timeout-contract';
$contendedPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moonya-project-lock-test'
    . DIRECTORY_SEPARATOR . hash('sha256', $contendedKey) . '.lock';
$contendedHandle = fopen($contendedPath, 'c+');
projectAssert(is_resource($contendedHandle), 'Unable to create the lock contention fixture');
projectAssert(flock($contendedHandle, LOCK_EX | LOCK_NB), 'Unable to hold the lock contention fixture');
$lockTimedOut = false;
$lockStartedAt = microtime(true);
try {
    $locks->acquire([$contendedKey], 1);
} catch (RuntimeException $e) {
    $lockTimedOut = str_starts_with($e->getMessage(), '等待资源锁超时：');
} finally {
    flock($contendedHandle, LOCK_UN);
    fclose($contendedHandle);
}
projectAssert($lockTimedOut, 'Contended resource lock did not fail at its finite deadline');
projectAssert(microtime(true) - $lockStartedAt < 2.5, 'Resource lock timeout exceeded its bounded deadline');

echo "project team protocol: PASS\n";
