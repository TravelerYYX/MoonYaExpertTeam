<?php
declare(strict_types=1);

function contractAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$history = (string)file_get_contents($root . '/script/MoonYa-index/modules/script-1c-save.php');
$runtime = (string)file_get_contents($root . '/script/MoonYa-index/modules/script-1e-rest.php');
$office = (string)file_get_contents($root . '/script/MoonYa-index/modules/script-5-office.php');
$officeStyle = (string)file_get_contents($root . '/script/MoonYa-index/styles/css-17-office.php');
$officeLayout = (string)file_get_contents($root . '/script/MoonYa-index/layouts/office-panel.php');
$teamRepository = (string)file_get_contents($root . '/Services/TeamRepository.php');
$worker = (string)file_get_contents($root . '/script/MoonYa-index/workers/conversation-runtime-worker.js');
$stateService = (string)file_get_contents($root . '/Services/ConversationTaskState.php');
$conversationApi = (string)file_get_contents($root . '/conversation_api.php');
$mainApi = (string)file_get_contents($root . '/api.php');
$walkProcessor = (string)file_get_contents(dirname($root) . '/tools/office_cutout/process_walk_sheet.py');
$terminalFinishPosition = strrpos($mainApi, '$conversationTaskState->finish(');
$terminalDonePosition = strrpos($mainApi, "json_encode(['type' => 'done'])");

$statusStart = strpos($history, 'const taskState = chat.taskState || {}');
$statusEnd = strpos($history, '// 3个小点菜单按钮', $statusStart ?: 0);
contractAssert($statusStart !== false && $statusEnd !== false, 'History task status block is missing');
$statusBlock = substr($history, $statusStart, $statusEnd - $statusStart);
contractAssert(
    str_contains($statusBlock, 'createWorkflowSpinnerIcon(18, 3.5)')
        && str_contains($statusBlock, "createWorkflowCheckIcon(18, '#2787f5')")
        && !str_contains($statusBlock, '<path')
        && !str_contains($statusBlock, '<circle'),
    'History task status must only reuse existing spinner/check factories'
);

contractAssert(
    !str_contains($officeLayout, 'officeMessageInput')
        && !str_contains($officeLayout, 'officeSendBtn'),
    'Office still contains a duplicate composer'
);
contractAssert(
    str_contains($officeLayout, "htmlspecialchars(\$oa['name'], ENT_QUOTES, 'UTF-8')")
        && !str_contains($officeLayout, "\$oa['name'] . ' · ' . \$oa['title']")
        && str_contains($officeLayout, "\$oa['key'] === 'moonya' ? ' ws-active' : ''")
        && str_contains($officeLayout, 'class="office-walker-name"'),
    'Office Agent names must show name-only above seated and walking characters'
);
contractAssert(
    substr_count($teamRepository, 'INNER JOIN conversation_task_state s') >= 2
        && substr_count($teamRepository, 's.active_run_id = r.id') >= 2
        && substr_count($teamRepository, 's.heartbeat_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)') >= 2
        && str_contains($office, "if (key === 'moonya') active = true;"),
    'Office activity must ignore orphaned runs while keeping MoonYa screen always on'
);
contractAssert(
    str_contains($officeStyle, 'background: #f5f5f4;')
        && str_contains($officeStyle, 'inset: 10px 28px 44px;')
        && str_contains($officeStyle, 'body.office-active .input-container-wrapper')
        && str_contains($officeStyle, 'background: transparent;')
        && str_contains($office, 'var MAX_GAP_X = 285;')
        && !str_contains($office, 'SKEW_X'),
    'Office must retain the flat white-gray workstation layout'
);

$expectedOrder = [
    ['moonya', 0, 0], ['image', 0, 1], ['search', 0, 2],
    ['file', 1, 0], ['voice', 1, 1], ['app', 1, 2],
    ['browser', 2, 0], ['code', 2, 1], ['computer', 2, 2],
];
foreach ($expectedOrder as [$key, $row, $column]) {
    contractAssert(
        preg_match(
            "/'key' => '{$key}'.*'row' => {$row}, 'col' => {$column}/",
            $officeLayout
        ) === 1,
        "Office position drifted for {$key}"
    );
}

foreach (array_column($expectedOrder, 0) as $key) {
    contractAssert(
        is_file($root . "/assets/office/{$key}.png"),
        "Office seated asset is missing for {$key}"
    );
    $frameSize = null;
    for ($frame = 1; $frame <= 4; $frame++) {
        $path = $root . "/assets/office/walk/{$key}-{$frame}.png";
        contractAssert(is_file($path), "Office walk frame is missing: {$key}-{$frame}");
        $imageSize = getimagesize($path);
        contractAssert(is_array($imageSize), "Office walk frame is not a readable image: {$key}-{$frame}");
        $currentSize = [$imageSize[0], $imageSize[1]];
        $frameSize ??= $currentSize;
        contractAssert($currentSize === $frameSize, "Office walk frame size drifted for {$key}");
        $header = (string)file_get_contents($path, false, null, 0, 26);
        contractAssert(
            strlen($header) >= 26 && in_array(ord($header[25]), [4, 6], true),
            "Office walk frame must preserve a PNG alpha channel: {$key}-{$frame}"
        );
    }
}
contractAssert(
    str_contains($walkProcessor, 'baseline = max(bottoms)')
        && str_contains($walkProcessor, 'right = (index + 1) * frame_width'),
    'Office walk processing must preserve equal frame sizes and a shared heel baseline'
);

foreach (['activate', 'patchComposer', 'start', 'stop', 'markViewed', 'snapshot', 'streamEvent', 'taskState', 'officeEvent'] as $message) {
    contractAssert(str_contains($worker, $message), "SharedWorker protocol is missing {$message}");
}
contractAssert(
    str_contains($runtime, 'conversationRuntimeContexts')
        && str_contains($runtime, 'sendRuntime.abortController')
        && str_contains($runtime, "kind: 'conversation_dom_snapshot'")
        && str_contains($runtime, 'projectPath:')
        && str_contains($runtime, 'applySharedComposerState')
        && str_contains($runtime, "runtime.container.querySelectorAll('.loading-indicator')")
        && !str_contains(
            substr($history, strpos($history, 'async function loadChat'), strpos($history, 'function pinChat') - strpos($history, 'async function loadChat')),
            'currentAbortController.abort()'
        ),
    'Conversation switching can still abort or share a single runtime'
);
contractAssert(
    str_contains($stateService, 'conversation_task_already_running')
        && str_contains($stateService, "phase='idle'")
        && str_contains($stateService, 'unread_terminal=1')
        && !str_contains($stateService, 'OR active_task_id=?')
        && str_contains($conversationApi, "\$action === 'stop_task'")
        && $terminalFinishPosition !== false
        && $terminalDonePosition !== false
        && $terminalFinishPosition < $terminalDonePosition,
    'Server conversation task slot/unread terminal projection is incomplete'
);
contractAssert(
    str_contains($worker, 'originClientId')
        && str_contains($worker, 'unreadTerminal = true')
        && str_contains($worker, "['starting', 'running', 'waiting_approval', 'recovering', 'stopping'].includes(state.phase)"),
    'SharedWorker stream echo suppression or terminal idempotency is incomplete'
);
contractAssert(
    str_contains($office, 'new Set()')
        && str_contains($office, 'clearRunScreens')
        && str_contains($office, 'runIdFromAgentReference')
        && str_contains($office, 'activeRunAgents')
        && !str_contains($office, '/^[0-9a-f-]{30,}$/i')
        && str_contains($office, 'OfficeActor.prototype.walk')
        && str_contains($office, "st.el.querySelector('.ws-name')")
        && str_contains($office, "name.style.visibility = visible ? 'visible' : 'hidden'")
        && str_contains($office, 'animatedEvents'),
    'Office reference counts, walking API or per-event dispatch queue is missing'
);

echo "office concurrency contract: PASS\n";
