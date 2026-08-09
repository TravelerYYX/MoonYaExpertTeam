<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/Services/CuEventEmitter.php';
require_once __DIR__ . '/Services/AIAssistant.php';

$class = new ReflectionClass(AIAssistant::class);
$assistant = $class->newInstanceWithoutConstructor();
$failures = [];
$passes = 0;

function invokePrivate(ReflectionClass $class, object $object, string $method, array $args = [])
{
    return $class->getMethod($method)->invokeArgs($object, $args);
}

function expectTrue(bool $condition, string $name, string $detail = ''): void
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo "[PASS] {$name}\n";
        return;
    }
    $failures[] = $name . ($detail !== '' ? ": {$detail}" : '');
    echo "[FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$normalized = invokePrivate($class, $assistant, 'normalizeUserInstruction', [
    '<div class="ProseMirror"><p>&nbsp;登陆QQ&nbsp;</p></div>',
]);
expectTrue($normalized === '登陆QQ', 'HTML 指令归一化', "actual={$normalized}");

$loginTarget = invokePrivate($class, $assistant, 'extractLoginAppTarget', [$normalized]);
expectTrue($loginTarget === 'QQ', '“登陆QQ”分类为 login_app', 'actual=' . var_export($loginTarget, true));

$loginTarget2 = invokePrivate($class, $assistant, 'extractLoginAppTarget', ['请帮我登录 QQ 一下']);
expectTrue($loginTarget2 === 'QQ', '“登录 + 应用名”兼容空白和礼貌用语');

$compound = invokePrivate($class, $assistant, 'extractLoginAppTarget', ['登录QQ然后发送消息']);
expectTrue($compound === null, '复合任务不误走登录快速通道');

$simpleOpen = invokePrivate($class, $assistant, 'extractSimpleOpenAppTarget', ['打开QQ']);
expectTrue($simpleOpen === 'QQ', '普通打开与登录任务区分');

$actionTools = $class->getConstant('CU_ACTION_TOOLS');
$launcherTools = $class->getConstant('CU_LAUNCHER_TOOLS');
foreach (['todo_write', 'execute_command', 'edit_file', 'create_file'] as $forbidden) {
    expectTrue(
        !in_array($forbidden, $actionTools, true) && !in_array($forbidden, $launcherTools, true),
        "CU 工具集排除 {$forbidden}"
    );
}

$loginTools = invokePrivate($class, $assistant, 'buildLoginVisionTools');
$loginToolNames = array_map(
    static fn(array $tool): string => (string)($tool['function']['name'] ?? ''),
    $loginTools
);
sort($loginToolNames);
expectTrue(
    $loginToolNames === ['login_safe_click', 'report_login_state'],
    '登录视觉层仅暴露分类与安全点击工具'
);

$validTranscript = [
    ['role' => 'system', 'content' => 's'],
    ['role' => 'user', 'content' => 'u'],
    [
        'role' => 'assistant',
        'content' => null,
        'tool_calls' => [
            ['id' => 'call_a', 'function' => ['name' => 'x', 'arguments' => '{}']],
            ['id' => 'call_b', 'function' => ['name' => 'y', 'arguments' => '{}']],
        ],
    ],
    ['role' => 'tool', 'tool_call_id' => 'call_a', 'content' => 'a'],
    ['role' => 'tool', 'tool_call_id' => 'call_b', 'content' => 'b'],
    ['role' => 'user', 'content' => 'continue'],
];
$validResult = invokePrivate($class, $assistant, 'validateToolTranscript', [$validTranscript]);
expectTrue(!empty($validResult['valid']), '多工具调用严格配对通过');

$rejectedComplete = $validTranscript;
array_splice($rejectedComplete, 4, 1, [['role' => 'user', 'content' => 'bad order']]);
$invalidResult = invokePrivate($class, $assistant, 'validateToolTranscript', [$rejectedComplete]);
expectTrue(empty($invalidResult['valid']), 'task_complete 拒绝时的错误消息顺序被拦截');

$imageMessages = [
    ['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => 'old'],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,OLD']],
    ]],
    ['role' => 'system', 'content' => 'late-system'],
    ['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => 'new'],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,NEW']],
    ]],
];
$moonshot = invokePrivate($class, $assistant, 'adaptMessagesForModel', [$imageMessages, 'moonshot-v1-8k-vision-preview']);
$moonshotJson = json_encode($moonshot);
expectTrue(str_contains($moonshotJson, 'NEW') && !str_contains($moonshotJson, 'OLD'), 'Moonshot 仅保留最新截图');
expectTrue(($moonshot[0]['role'] ?? '') === 'system', 'system 消息归并到上下文首部');

$minimax = invokePrivate($class, $assistant, 'adaptMessagesForModel', [$imageMessages, 'MiniMax-M3']);
$minimaxJson = json_encode($minimax);
expectTrue(!str_contains($minimaxJson, 'base64,OLD') && !str_contains($minimaxJson, 'base64,NEW'), 'MiniMax 上下文剔除全部图片');

ob_start();
$emitter = new CuEventEmitter();
$emitter->waitingUser('QQ', 'qr_code', '请扫码', 90);
$sse = (string)ob_get_clean();
expectTrue(
    str_contains($sse, '"type":"cu_waiting_user"')
        && str_contains($sse, '"remaining_seconds":90'),
    'cu_waiting_user SSE 结构'
);

expectTrue($class->getConstant('CU_MAX_ITERATIONS') === 20, '通用 CU 硬上限为 20 轮');
expectTrue($class->getConstant('CU_TOTAL_TIMEOUT_SECONDS') === 120, '通用 CU 硬上限为 120 秒');
expectTrue($class->getConstant('LOGIN_MAX_SCREENSHOTS') === 3, '登录流程最多 3 张截图');
expectTrue($class->getConstant('LOGIN_MAX_VISION_DECISIONS') === 3, '登录流程最多 3 次视觉决策');

echo "\nResult: {$passes} passed, " . count($failures) . " failed\n";
if (!empty($failures)) {
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}
