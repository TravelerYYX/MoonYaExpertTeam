<?php
declare(strict_types=1);

function uiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$workspace = dirname($root);
$layout = (string)file_get_contents($root . '/script/MoonYa-index/layouts/main-content.php');
$containerLayout = (string)file_get_contents($root . '/script/MoonYa-index/layouts/container.php');
$sidebar = (string)file_get_contents($root . '/script/MoonYa-index/layouts/sidebar.php');
$variables = (string)file_get_contents($root . '/script/MoonYa-index/modules/script-1a-vars.php');
$historyEvents = (string)file_get_contents($root . '/script/MoonYa-index/modules/script-1c-save.php');
$domEvents = (string)file_get_contents($root . '/script/MoonYa-index/modules/script-1d-dom.php');
$events = (string)file_get_contents($root . '/script/MoonYa-index/modules/script-1e-rest.php');
$styles = (string)file_get_contents($root . '/script/MoonYa-index/styles/css-16-agent-experience.php');
$baseStyles = (string)file_get_contents($root . '/script/MoonYa-index/styles/css-06-main.php');
$animationStyles = (string)file_get_contents($root . '/script/MoonYa-index/styles/css-09-animations.php');
$protocol = (string)file_get_contents($root . '/Services/TeamWorkProtocol.php');
$repository = (string)file_get_contents($root . '/Services/TeamRepository.php');
$cuEmitter = (string)file_get_contents($root . '/Services/TeamCuEventEmitter.php');
$teamApi = (string)file_get_contents($root . '/api/team.php');
$mainApi = (string)file_get_contents($root . '/api.php');
$runtimeConfig = require $root . '/config.php';
$desktopWindow = (string)file_get_contents(
    $workspace . '/MoonYa-Win/MoonYa-Solution/MoonYa/MainWindow.xaml'
);
$document = (string)file_get_contents(
    $workspace . '/MD/Visual_Office_Agent_Scheduling/new_agent.md'
);

uiAssert(
    str_contains($layout, 'class="file-card file-upload-plus"')
        && str_contains($layout, '<path d="M12 5v14M5 12h14"></path>'),
    'File upload is not a code-generated SVG plus button'
);
uiAssert(
    str_contains($layout, 'id="reasoningEffortSlider"')
        && str_contains($layout, 'min="0" max="4" step="1" value="3"'),
    'Five-level reasoning slider is missing or has an invalid default'
);
uiAssert(
    !str_contains($layout, '>›<')
        && !str_contains($layout, '>⌄<')
        && !str_contains($events, "textContent = '›'")
        && str_contains($layout, 'class="ui-chevron-icon"')
        && str_contains($events, 'function createWorkflowChevronIcon'),
    'A text chevron remains or the shared SVG chevron is missing'
);
uiAssert(
    str_contains($events, "fileInput.insertAdjacentElement('afterend', approvalSelector)")
        && str_contains($baseStyles, '#approvalModeButtonText,')
        && str_contains($baseStyles, '.approval-mode-menu {')
        && str_contains($baseStyles, 'transform-origin: bottom left'),
    'Permission selector is not placed directly after upload or is aligned incorrectly'
);
uiAssert(
    str_contains($styles, '#agentModelStatusText')
        && str_contains($styles, '#agentEffortStatusText')
        && str_contains($styles, '.agent-settings-status')
        && str_contains($styles, 'background: transparent;')
        && str_contains($styles, '.agent-settings-advanced-label'),
    'Compact model status or advanced slider styling is incomplete'
);
$agentModelIds = array_column(
    (array)($runtimeConfig['ui_model_groups']['deepseek']['models'] ?? []),
    'id'
);
uiAssert(
    $agentModelIds === ['deepseek-v4-flash', 'deepseek-v4-pro']
        && str_contains($layout, "foreach (\$modelUiGroups['deepseek']['models'] as \$model)")
        && str_contains($layout, 'data-agent-model="<?php echo htmlspecialchars($model[\'id\']'),
    'Visible Agent model menu is not restricted to DeepSeek v4-flash/v4-pro'
);
uiAssert(
    !str_contains($sidebar, 'data-value="kimi"')
        && !str_contains($sidebar, 'data-value="minmax"')
        && !str_contains($sidebar, 'data-value="glm"'),
    'Sidebar still exposes a non-DeepSeek user-selectable model'
);
foreach (['none', 'low', 'medium', 'high', 'xhigh'] as $level) {
    uiAssert(str_contains($variables, "'{$level}'"), "UI effort level {$level} is missing");
}
uiAssert(
    str_contains($variables, 'moonya.deepseek.model.v1')
        && str_contains($variables, 'moonya.deepseek.reasoning.v1'),
    'Global model/effort persistence keys are missing'
);

foreach ([
    'agent.reasoning.delta',
    'agent.content.delta',
    'agent.turn.completed',
    'agent.waiting',
    'tool.progress',
    'agent.loop.detected',
    '模型正在思考',
    '后台运行',
    '等待确认',
    '正在纠偏',
    'renderTeamTurnLog',
    'renderTeamSynthesisDelta',
    'renderTeamAgentCompletionMain',
    'renderTeamAssistantCompletedMain',
    'teamUiState.logNodes',
] as $contract) {
    uiAssert(str_contains($events, $contract), "Shared streaming UI contract missing: {$contract}");
}
foreach ([
    'project.team.started',
    'project.contract.accepted',
    'project.stage.changed',
    'project.acceptance.completed',
    'function ensureTeamProjectGroup',
    'function ensureTeamProjectActor',
    "role.textContent = context.roleLabel || (context.roleKey === 'project_lead' ? '项目负责人' : '项目成员')",
    'team-project-lanes',
    'team-project-lane-log',
    'team-project-tabs',
] as $projectUiContract) {
    uiAssert(
        str_contains($events . $styles, $projectUiContract),
        "Project work-area contract missing: {$projectUiContract}"
    );
}
uiAssert(
    str_contains($events, 'function teamFollowController')
        && str_contains($events, 'function teamScrollIfFollowing')
        && str_contains($events, 'if (event.deltaY < 0) state.following = false;')
        && str_contains($events, "button.textContent = label || '有新内容 · 回到底部';")
        && !str_contains($events, 'messagesContainer.scrollTop = messagesContainer.scrollHeight')
        && !str_contains(
            substr(
                $events,
                strpos($events, 'function renderTeamEvent'),
                strpos($events, 'function updateTeamApprovalCard') - strpos($events, 'function renderTeamEvent')
            ),
            'content.scrollTop = content.scrollHeight'
        ),
    'Team output still steals scroll position or lacks a return-to-bottom control'
);
uiAssert(
    str_contains($events, 'const _rid = data.request_id;')
        && str_contains($events, 'const _relayToken = data.relay_token')
        && !str_contains($events, 'var _rid = data.request_id;'),
    'Concurrent launcher relay callbacks still share a mutable request ticket'
);
uiAssert(
    str_contains($mainApi, 'function beginLauncherRelay')
        && str_contains($mainApi, 'function pollLauncherRelay')
        && str_contains($mainApi, 'function cancelLauncherRelay')
        && str_contains($mainApi, "\$GLOBALS['teamLauncherRelayTickets'][\$rid]")
        && str_contains($mainApi, "\$ticket['context']"),
    'Launcher relay does not expose per-request begin/poll/cancel tickets'
);
uiAssert(
    str_contains($events, 'node.appendChild(document.createTextNode(pending))')
        && str_contains($events, "if (!hasTeamVisibleContent(delta)) return;")
        && str_contains($events, 'receivedReasoningChars')
        && str_contains($events, 'renderedContentChars')
        && str_contains($styles, '.team-streaming-text'),
    'Streaming turns still re-render the full Markdown tree for each delta'
);
foreach ([
    'function normalizeTeamEmoji',
    'function renderTeamMarkdown',
    'function renderTeamPlainText',
    'function buildTeamMarkdownFragment',
    'function teamSafeLink',
    'function teamStatusSvgMarkup',
    'function createTeamCodeBlock',
    'function hasTeamToolProtocolMarkup',
    "lines > 12",
    "requestAnimationFrame",
] as $formatContract) {
    uiAssert(str_contains($events, $formatContract), "Agent formatting contract missing: {$formatContract}");
}
uiAssert(
    !str_contains($events, 'content.textContent = state.content')
        && !str_contains($events, 'answer.textContent = payload.content')
        && str_contains($events, 'renderTeamMarkdown(bubble, record.content')
        && str_contains($events, 'renderTeamMarkdown(reasoning, finalReasoning')
        && str_contains($events, 'renderTeamMarkdown(answer, finalContent')
        && str_contains($events, 'formattedOutputEvents.has(event.event)')
        && str_contains($events, 'renderTeamMarkdown(summary, teamEventSummary(event), { final: true })'),
    'Agent main/log content still bypasses the shared Markdown renderer'
);
uiAssert(
    str_contains($events, "const formattedOutputEvents = new Set(['agent.summary']);")
        && str_contains($events, "if (event.event === 'assistant.completed')")
        && str_contains($events, "return '最终答复已生成';")
        && str_contains($events, "reasoningLabel.textContent = '思考过程';")
        && str_contains($events, "answerLabel.textContent = '输出内容';")
        && str_contains($events, 'payload.content_discarded')
        && str_contains($styles, '.team-log-section-label'),
    'Lifecycle events still duplicate full output or reasoning/content sections are not explicit'
);
uiAssert(
    str_contains($events, '\\p{Extended_Pictographic}')
        && str_contains($events, '\\p{Emoji_Modifier}')
        && str_contains($events, 'TEAM_STATUS_TOKEN_START')
        && str_contains($events, 'function teamStatusIcon'),
    'Emoji removal or SVG status replacement is incomplete'
);
uiAssert(
    str_contains($events, "parsed.protocol === 'http:'")
        && str_contains($events, "parsed.protocol === 'https:'")
        && str_contains($events, "parsed.protocol === 'mailto:'")
        && !str_contains($events, "parsed.protocol === 'javascript:'"),
    'Agent Markdown link protocol allowlist is unsafe'
);
$renderEventStart = strpos($events, 'function renderTeamEvent');
$renderEventEnd = strpos($events, 'function updateTeamApprovalCard', $renderEventStart);
$renderEvent = substr($events, $renderEventStart, $renderEventEnd - $renderEventStart);
$renderToolStart = strpos($events, 'function renderTeamTool');
$renderToolEnd = strpos($events, 'function renderTeamGenericLog', $renderToolStart);
$renderTool = substr($events, $renderToolStart, $renderToolEnd - $renderToolStart);
$employeeResultStart = strpos($events, 'function renderTeamAgentCompletionMain');
$employeeResultEnd = strpos($events, 'function renderTeamSynthesisDelta', $employeeResultStart);
$employeeResult = substr($events, $employeeResultStart, $employeeResultEnd - $employeeResultStart);
uiAssert(
    !str_contains($renderEvent, 'renderTeamTurnMain(event')
        && str_contains($renderEvent, 'renderTeamTurnLog(event)')
        && str_contains($renderEvent, 'renderTeamSynthesisDelta(event)')
        && str_contains($renderEvent, "if (eventName === 'agent.waiting') return;"),
    'Work reasoning or employee content is still routed into the main conversation'
);
uiAssert(
    str_contains($events, "phase !== 'final_synthesis'")
        && str_contains($events, 'hasTeamToolProtocolMarkup(record.content)')
        && str_contains($events, "teamUiState.synthesisRuns.delete(String(event.run_id || ''))")
        && str_contains($mainApi, "'-moonya-turn-'")
        && str_contains($mainApi, "? 'final_synthesis' : 'coordination'")
        && str_contains($mainApi, "'round' => \$rootTurnRound"),
    'Final synthesis routing, protocol-marker suppression, or independent root turns are incomplete'
);
uiAssert(
    !str_contains($events, "className = 'team-main-tools'")
        && !str_contains($events, "className = 'team-tool-chip'")
        && !str_contains($styles, '.team-main-tools')
        && !str_contains($styles, '.team-tool-chip')
        && !str_contains($renderTool, 'teamTurnForActivity')
        && str_contains($renderTool, "ensureTeamLogNode(event, 'tool:'"),
    'Routine tool calls still create a main-conversation tag or are missing from the work log'
);
uiAssert(
    str_contains($employeeResult, 'payload.summary')
        && !str_contains($employeeResult, 'payload.content')
        && str_contains($employeeResult, '{ markdown: true, final: true, failed: failed }'),
    'Employee main-conversation output is not restricted to the full AgentResult.summary'
);
uiAssert(
    str_contains($events, "return sentence.length > 180 ? sentence.slice(0, 179).trimEnd() + '…' : sentence;")
        && !str_contains($employeeResult, '{ final: true, failed: failed, summary: true }'),
    'Employee completion reports are still silently shortened with an ellipsis'
);
uiAssert(
    str_contains($events, "loading.className = 'team-main-loading-bubble'")
        && str_contains($events, "'<span></span><span></span><span></span>'")
        && str_contains($styles, '.team-main-loading-bubble')
        && str_contains($styles, '@keyframes team-main-loading-dot'),
    'Independent compact three-dot Agent loading bubble is missing'
);
uiAssert(
    !str_contains($styles, '.team-agent-message::before')
        && str_contains($styles, '.team-panel .team-log-event::before'),
    'Main Agent connector remains or the work-log timeline was removed'
);
uiAssert(
    str_contains($events, 'teamMainApproval-')
        && str_contains($events, "activity.card.querySelector('.team-main-approvals')")
        && str_contains($events, 'updateTeamApprovalCard'),
    'Interactive approvals are not mounted and synchronized in the main conversation'
);
uiAssert(
    str_contains($events, 'teamLogApproval-')
        && str_contains($events, 'logSummary.replaceChildren(logCard)')
        && str_contains($events, 'cards: [card, logCard].filter(Boolean)')
        && str_contains($events, "logButtons[1].addEventListener('click'")
        && str_contains($styles, '.team-log-approval-card'),
    'Interactive approvals are not mirrored and synchronized in the work log'
);
uiAssert(
    str_contains($events, "phase === 'inspect' ? '检查'")
        && str_contains($events, "phase === 'verify' ? '验证'")
        && str_contains($events, "args.command_preview || args.command")
        && str_contains($events, "'Shell：' + shell")
        && str_contains($events, "card.classList.add('decided')")
        && str_contains($events, 'record.cards')
        && str_contains($baseStyles, '.team-approval-card.decided'),
    'Shell approval cards do not expose stage/intent/shell/preview or compact after decision'
);
uiAssert(
    str_contains($events, 'TEAM_PANEL_OPEN_STORAGE_KEY')
        && str_contains($events, '任何新事件都不能自动打开')
        && !str_contains(
            substr(
                $events,
                strpos($events, 'function renderTeamEvent'),
                strpos($events, 'function handleTeamApproval') - strpos($events, 'function renderTeamEvent')
            ),
            'setDetailPanelOpen(true)'
        ),
    'Work log panel is not strictly user-controlled and persistent'
);
uiAssert(
    str_contains($layout, 'id="detailPanelResizer"')
        && str_contains($layout, 'role="separator"')
        && str_contains($layout, 'aria-valuemin="350"')
        && str_contains($baseStyles, '.detail-panel-resizer')
        && str_contains($baseStyles, 'flex-basis: 1px;')
        && str_contains($baseStyles, 'background: #e8e8e8;')
        && str_contains($baseStyles, 'min-width: 350px;'),
    'Single-line work-log separator or its 350px safety floor is missing'
);
uiAssert(
    str_contains($domEvents, "TEAM_PANEL_WIDTH_STORAGE_KEY = 'moonya.team.panel.width.v1'")
        && str_contains($domEvents, 'minChatWidth: 520')
        && str_contains($domEvents, 'minPanelWidth: 350')
        && str_contains($domEvents, 'dividerWidth: 1')
        && str_contains($domEvents, 'sidebarCollapseReasons.space')
        && str_contains($domEvents, 'function expandSidebarByUser')
        && str_contains($domEvents, 'maximumPanelWidthWithExpandedSidebar')
        && str_contains($domEvents, 'keepSidebarExpanded')
        && str_contains($domEvents, "detailPanelResizer.addEventListener('pointermove'")
        && str_contains($events, 'window.moonyaSplitLayout.sync()'),
    'Persistent split sizing or adaptive left-sidebar expansion is incomplete'
);
uiAssert(
    str_contains($desktopWindow, 'Width="1440" Height="820"'),
    'Desktop startup window width was not increased by 60px'
);
uiAssert(
    str_contains($containerLayout, '<body class="work-mode office-active<?php echo $officePopoutMode')
        && str_contains($variables, "document.body.classList.add('work-mode')")
        && str_contains($variables, "document.body.classList.remove('work-mode')")
        && str_contains($animationStyles, 'body.work-mode .hot-topics-container')
        && str_contains($animationStyles, 'display: none !important;'),
    'Work-first mode or Chat-only hot-topic visibility is incomplete'
);
uiAssert(
    str_contains($styles, '.team-main-avatar')
        && str_contains($styles, '.team-panel .team-log-event')
        && str_contains($styles, '@media (max-width: 680px)'),
    'Agent avatar, timeline, or narrow-screen styles are missing'
);
$formatCssStart = strpos($styles, '/* Agent formatted output:');
$formatCssEnd = strpos($styles, '.team-main-approvals:empty', $formatCssStart);
$formatCss = substr($styles, $formatCssStart, $formatCssEnd - $formatCssStart);
uiAssert(
    str_contains($formatCss, '.team-markdown')
        && str_contains($formatCss, '.team-table-wrap')
        && str_contains($formatCss, '.team-code-block')
        && str_contains($formatCss, '.team-inline-status-icon')
        && str_contains($formatCss, '.team-status-cell.success'),
    'Formatted result, table, code, or SVG status styles are missing'
);
uiAssert(
    !str_contains($formatCss, 'linear-gradient')
        && !str_contains($formatCss, 'radial-gradient')
        && !str_contains($formatCss, '#45a5ff')
        && !str_contains($formatCss, '#7466ff')
        && !str_contains($formatCss, '#56b9ff'),
    'Agent formatted output contains a forbidden blue/purple gradient or accent'
);
uiAssert(
    str_contains($styles, '.team-log-content .team-table-wrap td::before')
        && str_contains($styles, '.team-presentation-bubble .team-table-wrap td::before')
        && str_contains($events, "cellValue.className = 'team-cell-value'")
        && str_contains($styles, '.team-status-cell .team-cell-value'),
    'Narrow work-log/mobile tables do not expose header-labelled key/value cards'
);
uiAssert(
    !str_contains($events, "aiThinkingDiv.classList.add('expanded')")
        && !str_contains($events, "textEl.classList.add('expanded')")
        && str_contains($historyEvents, "thinkingDiv.className = 'thinking-text'")
        && !str_contains($historyEvents, "thinkingDiv.className = 'thinking-text expanded'")
        && str_contains($events, "toggle.setAttribute('aria-expanded', 'false')"),
    'Completed or restored ordinary Chat reasoning is still forced open'
);
uiAssert(
    substr_count($domEvents, 'window.stopCurrentMoonYaResponse();') === 2
        && !str_contains($domEvents, '手动终止输出')
        && str_contains($events, 'window.stopCurrentMoonYaResponse = function()')
        && str_contains($events, "renderTeamCancellation(runId)")
        && str_contains($events, "if (run.status === 'cancelled') renderTeamCancellation(run.id)")
        && str_contains($employeeResult, "payload.error.code === 'run_cancelled'")
        && str_contains($events, "'说停就停~等待新的工作安排。'")
        && str_contains($events, "action=cancel_run"),
    'Click/Enter stopping is not unified or the role stop bubbles are incomplete'
);
uiAssert(
    str_contains($teamApi, "\$action === 'cancel_run'")
        && str_contains($teamApi, '$repository->cancelRun($userId, $runId)')
        && str_contains($repository, 'public function cancelRun(int $userId, string $runId): bool'),
    'Authenticated team-run cancellation API is missing'
);
uiAssert(
    !str_contains($cuEmitter, "'computer_agent',")
        && str_contains($cuEmitter, "'computer',")
        && str_contains($mainApi, "getAgent('computer')")
        && str_contains($mainApi, "'agent_key' => 'computer'")
        && !str_contains($mainApi, "getAgent('computer_agent')")
        && str_contains($events, "computer_agent: 'computer'")
        && str_contains($events, 'teamCanonicalAgentKey')
        && str_contains($mainApi, "'phase' => 'delegation'")
        && str_contains($mainApi, "'phase' => 'execution'")
        && str_contains($mainApi, "'phase' => 'final_synthesis'"),
    'Computer User role identity/avatar or delegation/execution/synthesis contract is incomplete'
);

$expectedMappings = [
    "'none'" => "'disabled'",
    "'xhigh'" => "'max'",
    "['none', 'low', 'medium', 'high', 'xhigh']" => "'high'",
];
foreach ($expectedMappings as $needle => $result) {
    uiAssert(
        str_contains($protocol, $needle) && str_contains($protocol, $result),
        "DeepSeek policy mapping is incomplete around {$needle}"
    );
}
uiAssert(
    str_contains($protocol, "unset(\$request['reasoning_effort'])")
        && str_contains($protocol, "(\$capabilities['reasoning_control'] ?? '') !== 'binary_strength'"),
    'Reasoning-capability isolation is missing'
);
uiAssert(
    str_contains($repository, 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$timeout} SECOND)')
        && str_contains($repository, 'datetime(CURRENT_TIMESTAMP, \'+{$timeout} seconds\')')
        && str_contains($repository, "? 'NULL'")
        && str_contains($repository, 'expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP')
        && !str_contains(
            substr(
                $repository,
                strpos($repository, 'public function createApproval'),
                strpos($repository, 'public function bootstrap') - strpos($repository, 'public function createApproval')
            ),
            "date('Y-m-d H:i:s'"
        ),
    'Approval expiration is not fully based on the database clock'
);

foreach ([
    'Agent 模型思考强度兼容矩阵（2026-07-30）',
    'deepseek-v4-flash',
    'deepseek-v4-pro',
    'kimi-k2.6',
    'kimi-k3',
    'MiniMax-M3',
    'MiniMax M2.x',
    'https://api-docs.deepseek.com/zh-cn/guides/thinking_mode',
    'https://platform.kimi.com/docs/guide/use-reasoning-effort',
    'https://platform.kimi.com/docs/guide/kimi-k2-6-quickstart',
    'https://platform.minimaxi.com/docs/token-plan/codex',
] as $needle) {
    uiAssert(str_contains($document, $needle), "Compatibility document is missing: {$needle}");
}
$matrixStart = strpos($document, '| Agent 模型 | 官方思考能力 | MoonYa 五档处理 |');
$matrixEnd = strpos($document, '当前数据库中的', $matrixStart);
$matrix = substr($document, $matrixStart, $matrixEnd - $matrixStart);
uiAssert(!preg_match('/^\|\s*`?GLM/im', $matrix), 'GLM was incorrectly added as an Agent matrix row');
uiAssert(
    str_contains($document, '推理强度滑条只对 DeepSeek 请求生效')
        && str_contains($document, 'model_override')
        && str_contains($document, '均未设置'),
    'DeepSeek-only or inherited-model database rule is missing from the document'
);

echo "agent UI contract: PASS\n";
