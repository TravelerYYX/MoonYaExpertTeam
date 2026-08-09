<section id="agentTeamSection" class="content-section">
    <div class="at-head">
        <div>
            <span class="at-kicker">MULTI-AGENT V1</span>
            <h2>Agent 团队</h2>
            <p>Agent、Function Calling 工具、委派关系、运行参数与 MCP 服务均由数据库驱动。</p>
        </div>
        <button class="btn-primary" type="button" onclick="loadAgentTeam()">刷新配置</button>
    </div>

    <div class="at-tabs" role="tablist" aria-label="Agent 团队配置">
        <button class="active" type="button" data-at-tab="agents" onclick="switchAgentTeamTab('agents')">Agents</button>
        <button type="button" data-at-tab="capabilities" onclick="switchAgentTeamTab('capabilities')">路由能力</button>
        <button type="button" data-at-tab="tools" onclick="switchAgentTeamTab('tools')">工具与授权</button>
        <button type="button" data-at-tab="mcp" onclick="switchAgentTeamTab('mcp')">MCP 服务</button>
        <button type="button" data-at-tab="runtime" onclick="switchAgentTeamTab('runtime')">运行参数</button>
    </div>

    <div id="agentTeamLoading" class="at-empty">正在加载团队配置…</div>
    <div id="agentTeamAgents" class="at-pane active"></div>
    <div id="agentTeamCapabilities" class="at-pane"></div>
    <div id="agentTeamTools" class="at-pane"></div>
    <div id="agentTeamMcp" class="at-pane"></div>
    <div id="agentTeamRuntime" class="at-pane"></div>
</section>

<style>
#agentTeamSection { --at-purple:#7466ef; --at-line:#e8eaf1; }
.at-head { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; margin-bottom:20px; }
.at-head h2 { margin:2px 0 4px; }
.at-head p { margin:0; color:#7a8190; font-size:13px; }
.at-kicker { color:var(--at-purple); font-size:10px; font-weight:800; letter-spacing:.14em; }
.at-tabs { display:flex; gap:5px; width:max-content; max-width:100%; margin-bottom:18px; padding:5px; border:1px solid var(--at-line); border-radius:14px; background:#f4f5f9; overflow:auto; }
.at-tabs button { padding:8px 15px; border:0; border-radius:10px; background:transparent; color:#707789; cursor:pointer; white-space:nowrap; }
.at-tabs button.active { background:white; color:#423b7a; box-shadow:0 4px 14px rgba(55,50,105,.12); font-weight:700; }
.at-pane { display:none; }
.at-pane.active { display:block; }
.at-empty { padding:45px; color:#8b91a0; text-align:center; }
.at-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:14px; }
.at-card { padding:16px; border:1px solid var(--at-line); border-radius:16px; background:#fff; box-shadow:0 8px 28px rgba(35,44,73,.05); }
.at-card-head { display:flex; align-items:center; gap:11px; margin-bottom:13px; }
.at-avatar { width:42px; height:42px; object-fit:cover; border:1px solid #eceef4; border-radius:13px; background:#f0f2f7; }
.at-card-title { min-width:0; flex:1; }
.at-card-title strong,.at-card-title small { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.at-card-title small { margin-top:2px; color:#9399a7; font:11px ui-monospace,monospace; }
.at-status { padding:4px 8px; border-radius:99px; background:#edf8f2; color:#3b8c62; font-size:10px; }
.at-status.off { background:#f3f4f7; color:#8c93a2; }
.at-form { display:grid; grid-template-columns:1fr 1fr; gap:9px; }
.at-form .wide { grid-column:1/-1; }
.at-field label { display:block; margin-bottom:4px; color:#72798a; font-size:11px; }
.at-field input,.at-field select,.at-field textarea { width:100%; box-sizing:border-box; padding:8px 9px; border:1px solid #dfe2eb; border-radius:9px; background:#fbfcfe; color:#343a49; font:12px inherit; }
.at-field textarea { min-height:64px; resize:vertical; }
.at-card-actions { display:flex; justify-content:flex-end; gap:7px; margin-top:12px; }
.at-card-actions button,.at-mini-btn { padding:7px 10px; border:1px solid #dde0ea; border-radius:9px; background:#fff; color:#5f6677; cursor:pointer; font-size:11px; }
.at-card-actions .primary,.at-mini-btn.primary { border-color:transparent; background:linear-gradient(135deg,#8172f4,#698ceb); color:#fff; }
.at-checks { display:flex; flex-wrap:wrap; gap:6px; max-height:165px; overflow:auto; padding:7px; border:1px solid #e7e9f0; border-radius:10px; background:#fafbfe; }
.at-checks label { display:flex; align-items:center; gap:5px; padding:5px 7px; border-radius:8px; background:white; color:#62697a; font-size:10px; }
.at-section-label { margin:13px 0 6px; color:#555d70; font-size:11px; font-weight:700; }
.at-table-wrap { overflow:auto; border:1px solid var(--at-line); border-radius:14px; background:#fff; }
.at-table { width:100%; border-collapse:collapse; font-size:11px; }
.at-table th,.at-table td { padding:10px; border-bottom:1px solid #eef0f4; text-align:left; vertical-align:top; }
.at-table th { position:sticky; top:0; background:#f7f8fb; color:#747b8b; }
.at-tool-detail summary { color:#4f586a; cursor:pointer; font-weight:700; }
.at-tool-editor { min-width:520px; padding-top:10px; }
.at-badge { display:inline-block; margin:1px; padding:3px 6px; border-radius:99px; background:#f0effd; color:#6e62c8; font-size:9px; }
.at-badge.high,.at-badge.critical { background:#fff0f1; color:#c44f61; }
.at-mcp-hero { display:grid; grid-template-columns:minmax(300px,420px) 1fr; gap:14px; align-items:start; }
.at-mcp-list { display:grid; gap:10px; }
.at-mcp-server { padding:13px; border:1px solid var(--at-line); border-radius:13px; background:#fff; }
.at-mcp-server-head { display:flex; align-items:center; gap:8px; }
.at-mcp-server-head strong { flex:1; }
.at-runtime { max-width:680px; }
.at-runtime-row { display:grid; grid-template-columns:1fr 180px; gap:20px; align-items:center; padding:13px 0; border-bottom:1px solid #eceef3; }
.at-runtime-row small { display:block; margin-top:3px; color:#9197a5; }
.at-runtime-row input { width:100%; box-sizing:border-box; padding:8px; border:1px solid #dfe2eb; border-radius:9px; }
@media(max-width:900px){.at-mcp-hero{grid-template-columns:1fr}.at-form{grid-template-columns:1fr}.at-form .wide{grid-column:auto}}
</style>

<script>
let agentTeamSnapshot = null;

async function agentTeamRequest(action, body) {
    const response = await fetch('api/agent_team.php' + (action ? '?action=' + encodeURIComponent(action) : ''), {
        method: action ? 'POST' : 'GET',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + token
        },
        body: action ? JSON.stringify(body || {}) : undefined
    });
    const result = await response.json();
    if (!result.success) throw new Error(result.error || '请求失败');
    return result.data;
}

function switchAgentTeamTab(name) {
    document.querySelectorAll('[data-at-tab]').forEach(button => button.classList.toggle('active', button.dataset.atTab === name));
    document.querySelectorAll('#agentTeamSection .at-pane').forEach(pane => pane.classList.remove('active'));
    const pane = document.getElementById('agentTeam' + name.charAt(0).toUpperCase() + name.slice(1));
    if (pane) pane.classList.add('active');
}

async function loadAgentTeam() {
    const loading = document.getElementById('agentTeamLoading');
    if (loading) loading.style.display = 'block';
    try {
        agentTeamSnapshot = await agentTeamRequest('', null);
        renderAgentTeamAgents();
        renderAgentTeamCapabilities();
        renderAgentTeamTools();
        renderAgentTeamMcp();
        renderAgentTeamRuntime();
        if (loading) loading.style.display = 'none';
    } catch (error) {
        if (loading) loading.textContent = error.message;
        showAlert('error', error.message);
    }
}

function atElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
}

function atInput(id, label, value, type, wide) {
    const field = atElement('div', 'at-field' + (wide ? ' wide' : ''));
    const caption = atElement('label', '', label);
    caption.htmlFor = id;
    const input = document.createElement(type === 'textarea' ? 'textarea' : 'input');
    input.id = id;
    if (type && type !== 'textarea') input.type = type;
    if (input.type === 'checkbox') input.checked = !!Number(value);
    else input.value = value == null ? '' : value;
    field.append(caption, input);
    return field;
}

function promptSelect(id, selected) {
    const field = atElement('div', 'at-field');
    const label = atElement('label', '', '提示词引用');
    label.htmlFor = id;
    const select = document.createElement('select');
    select.id = id;
    (agentTeamSnapshot.prompts || []).forEach(prompt => {
        const option = new Option(prompt.display_name + ' · ' + prompt.name, prompt.name, false, prompt.name === selected);
        select.add(option);
    });
    field.append(label, select);
    return field;
}

function renderAgentTeamAgents() {
    const root = document.getElementById('agentTeamAgents');
    root.innerHTML = '';
    const grid = atElement('div', 'at-grid');
    const activeDelegations = new Set((agentTeamSnapshot.delegations || []).filter(row => Number(row.enabled)).map(row => row.parent_agent_key + '|' + row.child_agent_key));
    const activeGrants = new Set((agentTeamSnapshot.grants || []).filter(row => Number(row.enabled)).map(row => row.agent_key + '|' + row.tool_id));
    const createCard = atElement('article', 'at-card at-create-card');
    createCard.appendChild(atElement('h3', '', '新增 Agent'));
    createCard.appendChild(atElement('p', 'at-muted', 'Agent 保存后即可配置委派关系与 Function Calling 工具授权。'));
    const createForm = atElement('div', 'at-form');
    const createPrefix = 'at-agent-new-';
    createForm.append(
        atInput(createPrefix + 'name', '显示名称', ''),
        atInput(createPrefix + 'key', 'Agent key', ''),
        atInput(createPrefix + 'role', '角色摘要', '', 'textarea', true),
        atInput(createPrefix + 'avatar', '头像 URL', '', 'text', true),
        promptSelect(createPrefix + 'prompt', ''),
        atInput(createPrefix + 'model', '模型覆盖（可空）', ''),
        atInput(createPrefix + 'sort', '排序', 100, 'number'),
        atInput(createPrefix + 'enabled', '启用', true, 'checkbox')
    );
    const createActions = atElement('div', 'at-card-actions');
    const createButton = atElement('button', 'primary', '创建 Agent');
    createButton.type = 'button';
    createButton.addEventListener('click', async () => {
        try {
            const agentKey = document.getElementById(createPrefix + 'key').value.trim();
            const displayName = document.getElementById(createPrefix + 'name').value.trim();
            if (!agentKey || !displayName) throw new Error('显示名称和 Agent key 不能为空');
            agentTeamSnapshot = await agentTeamRequest('save_agent', {
                agent_key: agentKey,
                display_name: displayName,
                role_summary: document.getElementById(createPrefix + 'role').value,
                avatar_url: document.getElementById(createPrefix + 'avatar').value.trim(),
                prompt_name: document.getElementById(createPrefix + 'prompt').value,
                model_override: document.getElementById(createPrefix + 'model').value.trim(),
                sort_order: Number(document.getElementById(createPrefix + 'sort').value || 100),
                enabled: document.getElementById(createPrefix + 'enabled').checked,
                is_coordinator: false
            });
            renderAgentTeamAgents();
            renderAgentTeamTools();
            showAlert('success', 'Agent 已创建');
        } catch (error) { showAlert('error', error.message); }
    });
    createActions.appendChild(createButton);
    createCard.append(createForm, createActions);
    grid.appendChild(createCard);
    (agentTeamSnapshot.agents || []).forEach(agent => {
        const prefix = 'at-agent-' + agent.id + '-';
        const card = atElement('article', 'at-card');
        const head = atElement('div', 'at-card-head');
        const avatar = atElement('img', 'at-avatar');
        avatar.src = agent.avatar_url || '';
        avatar.alt = '';
        const title = atElement('div', 'at-card-title');
        title.append(atElement('strong', '', agent.display_name), atElement('small', '', agent.agent_key));
        const status = atElement('span', 'at-status' + (Number(agent.enabled) ? '' : ' off'), Number(agent.enabled) ? '启用' : '停用');
        head.append(avatar, title, status);
        const form = atElement('div', 'at-form');
        form.append(
            atInput(prefix + 'name', '显示名称', agent.display_name),
            atInput(prefix + 'key', 'Agent key', agent.agent_key),
            atInput(prefix + 'role', '角色摘要', agent.role_summary, 'textarea', true),
            atInput(prefix + 'avatar', '头像 URL', agent.avatar_url, 'text', true),
            promptSelect(prefix + 'prompt', agent.prompt_name),
            atInput(prefix + 'model', '模型覆盖（可空）', agent.model_override),
            atInput(prefix + 'sort', '排序', agent.sort_order, 'number'),
            atInput(prefix + 'enabled', '启用', agent.enabled, 'checkbox')
        );
        card.append(head, form);

        const delegationTitle = atElement('div', 'at-section-label', '可委派 Agent');
        const delegationChecks = atElement('div', 'at-checks');
        (agentTeamSnapshot.agents || []).filter(child => child.agent_key !== agent.agent_key).forEach(child => {
            const label = document.createElement('label');
            const check = document.createElement('input');
            check.type = 'checkbox';
            check.dataset.delegationChild = child.agent_key;
            check.checked = activeDelegations.has(agent.agent_key + '|' + child.agent_key);
            label.append(check, document.createTextNode(child.display_name));
            delegationChecks.appendChild(label);
        });
        const grantTitle = atElement('div', 'at-section-label', 'Function Calling 工具授权');
        const grantChecks = atElement('div', 'at-checks');
        (agentTeamSnapshot.tools || []).forEach(tool => {
            const label = document.createElement('label');
            const check = document.createElement('input');
            check.type = 'checkbox';
            check.dataset.grantTool = tool.id;
            check.checked = activeGrants.has(agent.agent_key + '|' + tool.id);
            label.append(check, document.createTextNode(tool.tool_key));
            grantChecks.appendChild(label);
        });
        const actions = atElement('div', 'at-card-actions');
        const save = atElement('button', 'primary', '保存 Agent 与授权');
        save.type = 'button';
        save.addEventListener('click', async () => {
            try {
                agentTeamSnapshot = await agentTeamRequest('save_agent', {
                    agent_key: document.getElementById(prefix + 'key').value.trim(),
                    display_name: document.getElementById(prefix + 'name').value.trim(),
                    role_summary: document.getElementById(prefix + 'role').value,
                    avatar_url: document.getElementById(prefix + 'avatar').value.trim(),
                    prompt_name: document.getElementById(prefix + 'prompt').value,
                    model_override: document.getElementById(prefix + 'model').value.trim(),
                    sort_order: Number(document.getElementById(prefix + 'sort').value || 0),
                    enabled: document.getElementById(prefix + 'enabled').checked,
                    is_coordinator: Number(agent.is_coordinator) === 1
                });
                const agentKey = document.getElementById(prefix + 'key').value.trim();
                agentTeamSnapshot = await agentTeamRequest('save_delegations', {
                    parent_agent_key: agentKey,
                    child_agent_keys: Array.from(delegationChecks.querySelectorAll(':checked')).map(item => item.dataset.delegationChild)
                });
                agentTeamSnapshot = await agentTeamRequest('save_grants', {
                    agent_key: agentKey,
                    tool_ids: Array.from(grantChecks.querySelectorAll(':checked')).map(item => Number(item.dataset.grantTool))
                });
                renderAgentTeamAgents();
                renderAgentTeamTools();
                showAlert('success', 'Agent 配置已保存');
            } catch (error) { showAlert('error', error.message); }
        });
        actions.append(save);
        card.append(delegationTitle, delegationChecks, grantTitle, grantChecks, actions);
        grid.appendChild(card);
    });
    root.appendChild(grid);
}

function renderAgentTeamCapabilities() {
    const root = document.getElementById('agentTeamCapabilities');
    root.innerHTML = '';
    const grid = atElement('div', 'at-grid');
    (agentTeamSnapshot.capabilities || []).forEach(capability => {
        const card = atElement('article', 'at-card');
        const head = atElement('div', 'at-card-head');
        const title = atElement('div', 'at-card-title');
        title.append(
            atElement('strong', '', capability.display_name || capability.capability_key),
            atElement('small', '', capability.capability_key + ' → ' + capability.agent_key)
        );
        head.append(
            title,
            atElement(
                'span',
                'at-status' + (capability.ready && Number(capability.enabled) ? '' : ' off'),
                !Number(capability.enabled) ? '停用' : (capability.ready ? '可用' : '授权不完整')
            )
        );
        card.appendChild(head);
        const prefix = 'at-cap-' + capability.id + '-';
        const form = atElement('div', 'at-form');
        form.append(
            atInput(prefix + 'key', 'Capability key', capability.capability_key),
            atInput(prefix + 'name', '显示名称', capability.display_name),
            atInput(prefix + 'desc', '用户可理解的能力说明', capability.description, 'textarea', true)
        );
        const agentField = atElement('div', 'at-field');
        const agentLabel = atElement('label', '', '唯一负责 Agent');
        const agentSelect = document.createElement('select');
        agentSelect.id = prefix + 'agent';
        (agentTeamSnapshot.agents || []).filter(agent => !Number(agent.is_coordinator)).forEach(agent => {
            agentSelect.add(new Option(
                agent.display_name + ' · ' + agent.agent_key,
                agent.agent_key,
                false,
                agent.agent_key === capability.agent_key
            ));
        });
        agentField.append(agentLabel, agentSelect);
        form.append(
            agentField,
            atInput(prefix + 'sort', '排序', capability.sort_order, 'number'),
            atInput(prefix + 'examples', '适用示例（JSON 数组）', JSON.stringify(capability.examples || [], null, 2), 'textarea', true),
            atInput(prefix + 'exclusions', '不适用示例（JSON 数组）', JSON.stringify(capability.exclusions || [], null, 2), 'textarea', true),
            atInput(prefix + 'tools', '必需工具（JSON 数组）', JSON.stringify(capability.required_tools || [], null, 2), 'textarea', true),
            atInput(prefix + 'enabled', '启用', capability.enabled, 'checkbox')
        );
        card.appendChild(form);
        if ((capability.missing_tools || []).length) {
            card.appendChild(atElement(
                'p',
                'at-muted',
                '缺少授权：' + capability.missing_tools.join(', ')
            ));
        }
        if (!capability.delegated_by_moonya) {
            card.appendChild(atElement(
                'p',
                'at-muted',
                '缺少委派授权：MoonYa 当前不能把该能力交给 ' + capability.agent_key
            ));
        }
        const actions = atElement('div', 'at-card-actions');
        const save = atElement('button', 'primary', '保存能力');
        save.type = 'button';
        save.addEventListener('click', async () => {
            try {
                agentTeamSnapshot = await agentTeamRequest('save_capability', {
                    capability_key: document.getElementById(prefix + 'key').value.trim(),
                    display_name: document.getElementById(prefix + 'name').value.trim(),
                    description: document.getElementById(prefix + 'desc').value.trim(),
                    agent_key: document.getElementById(prefix + 'agent').value,
                    examples: JSON.parse(document.getElementById(prefix + 'examples').value || '[]'),
                    exclusions: JSON.parse(document.getElementById(prefix + 'exclusions').value || '[]'),
                    required_tools: JSON.parse(document.getElementById(prefix + 'tools').value || '[]'),
                    enabled: document.getElementById(prefix + 'enabled').checked,
                    sort_order: Number(document.getElementById(prefix + 'sort').value || 0)
                });
                renderAgentTeamCapabilities();
                renderAgentTeamAgents();
                showAlert('success', '路由能力已保存');
            } catch (error) { showAlert('error', error.message); }
        });
        actions.appendChild(save);
        card.appendChild(actions);
        grid.appendChild(card);
    });
    root.appendChild(grid);
}

function renderAgentTeamTools() {
    const root = document.getElementById('agentTeamTools');
    root.innerHTML = '';
    const wrap = atElement('div', 'at-table-wrap');
    const table = atElement('table', 'at-table');
    table.innerHTML = '<thead><tr><th>工具</th><th>来源/传输</th><th>副作用</th><th>风险</th><th>状态</th><th>编辑</th></tr></thead>';
    const body = document.createElement('tbody');
    (agentTeamSnapshot.tools || []).forEach(tool => {
        const row = document.createElement('tr');
        const name = document.createElement('td');
        name.append(atElement('strong', '', tool.display_name || tool.tool_key), document.createElement('br'), atElement('code', '', tool.tool_key));
        const source = atElement('td', '', (tool.source || 'native') + ' / ' + tool.transport);
        const effect = atElement('td', '', tool.effect);
        const risk = atElement('td');
        risk.append(atElement('span', 'at-badge ' + tool.risk_level, tool.risk_level));
        const enabled = atElement('td', '', Number(tool.enabled) ? (Number(tool.reviewed) ? '已审核启用' : '启用·未审核') : '停用');
        const editorCell = document.createElement('td');
        const detail = atElement('details', 'at-tool-detail');
        detail.appendChild(atElement('summary', '', '编辑'));
        const editor = atElement('div', 'at-tool-editor at-form');
        const prefix = 'at-tool-' + tool.id + '-';
        const isMcpTool = tool.source === 'mcp';
        if (isMcpTool) {
            editor.appendChild(atElement('p', 'at-muted', 'MCP 工具名称、说明和 Schema 由服务端发现同步，只能审核本地风险与启用状态。'));
        } else {
            editor.append(
                atInput(prefix + 'name', '显示名称', tool.display_name),
                atInput(prefix + 'desc', '工具描述', tool.description, 'textarea', true),
                atInput(prefix + 'schema', 'Input JSON Schema', tool.input_schema, 'textarea', true),
                atInput(prefix + 'out', 'Output JSON Schema', tool.output_schema, 'textarea', true),
                atInput(prefix + 'transport', '传输配置 JSON', tool.transport_config, 'textarea', true)
            );
        }
        editor.append(
            atInput(prefix + 'effect', '副作用 read/write/destructive', tool.effect),
            atInput(prefix + 'risk', '风险 low/medium/high/critical', tool.risk_level),
            atInput(prefix + 'enabled', '启用', tool.enabled, 'checkbox'),
            atInput(prefix + 'reviewed', '已审核', tool.reviewed, 'checkbox')
        );
        const save = atElement('button', 'at-mini-btn primary', '保存工具');
        save.type = 'button';
        save.addEventListener('click', async () => {
            try {
                agentTeamSnapshot = await agentTeamRequest('save_tool', {
                    id: Number(tool.id),
                    display_name: isMcpTool ? tool.display_name : document.getElementById(prefix + 'name').value,
                    description: isMcpTool ? tool.description : document.getElementById(prefix + 'desc').value,
                    input_schema: isMcpTool ? tool.input_schema : document.getElementById(prefix + 'schema').value,
                    output_schema: isMcpTool ? tool.output_schema : document.getElementById(prefix + 'out').value,
                    transport_config: isMcpTool ? tool.transport_config : document.getElementById(prefix + 'transport').value,
                    effect: document.getElementById(prefix + 'effect').value,
                    risk_level: document.getElementById(prefix + 'risk').value,
                    enabled: document.getElementById(prefix + 'enabled').checked,
                    reviewed: document.getElementById(prefix + 'reviewed').checked
                });
                renderAgentTeamTools();
                showAlert('success', '工具配置已保存');
            } catch (error) { showAlert('error', error.message); }
        });
        editor.appendChild(save);
        detail.appendChild(editor);
        editorCell.appendChild(detail);
        row.append(name, source, effect, risk, enabled, editorCell);
        body.appendChild(row);
    });
    table.appendChild(body);
    wrap.appendChild(table);
    root.appendChild(wrap);
}

function jsonObject(value, fallback) {
    if (value && typeof value === 'object') return value;
    try { return JSON.parse(value || ''); } catch (_) { return fallback; }
}

function renderAgentTeamMcp() {
    const root = document.getElementById('agentTeamMcp');
    root.innerHTML = '';
    const hero = atElement('div', 'at-mcp-hero');
    const formCard = atElement('div', 'at-card');
    formCard.appendChild(atElement('h3', '', '新增或更新 MCP 服务'));
    const form = atElement('div', 'at-form');
    form.append(
        atInput('at-mcp-key', 'Server key', ''),
        atInput('at-mcp-name', '显示名称', ''),
        atInput('at-mcp-transport', '传输 stdio / streamable_http', 'streamable_http'),
        atInput('at-mcp-auth', '认证 none / oauth / headers', 'none'),
        atInput('at-mcp-endpoint', 'HTTPS Endpoint', '', 'text', true),
        atInput('at-mcp-command', 'stdio 命令路径', '', 'text', true),
        atInput('at-mcp-args', '参数 JSON 数组', '[]', 'textarea', true),
        atInput('at-mcp-env', '环境变量 JSON（秘密使用 vault://）', '{}', 'textarea', true),
        atInput('at-mcp-oauth', 'OAuth 配置 JSON', '{}', 'textarea', true),
        atInput('at-mcp-enabled', '启用', 1, 'checkbox')
    );
    const formActions = atElement('div', 'at-card-actions');
    const save = atElement('button', 'primary', '保存 MCP 服务');
    save.type = 'button';
    save.addEventListener('click', async () => {
        try {
            agentTeamSnapshot = await agentTeamRequest('save_mcp_server', {
                server_key: document.getElementById('at-mcp-key').value.trim(),
                display_name: document.getElementById('at-mcp-name').value.trim(),
                transport: document.getElementById('at-mcp-transport').value.trim(),
                auth_mode: document.getElementById('at-mcp-auth').value.trim(),
                endpoint: document.getElementById('at-mcp-endpoint').value.trim(),
                command_path: document.getElementById('at-mcp-command').value.trim(),
                arguments_json: document.getElementById('at-mcp-args').value,
                environment_json: document.getElementById('at-mcp-env').value,
                oauth_config_json: document.getElementById('at-mcp-oauth').value,
                enabled: document.getElementById('at-mcp-enabled').checked
            });
            renderAgentTeamMcp();
            showAlert('success', 'MCP 服务已保存');
        } catch (error) { showAlert('error', error.message); }
    });
    formActions.append(save);
    formCard.append(form, formActions);

    const right = atElement('div');
    const serverList = atElement('div', 'at-mcp-list');
    (agentTeamSnapshot.mcpServers || []).forEach(server => {
        const card = atElement('article', 'at-mcp-server');
        const head = atElement('div', 'at-mcp-server-head');
        head.append(atElement('strong', '', server.display_name), atElement('span', 'at-badge', server.transport), atElement('span', 'at-badge', server.last_status || 'unknown'));
        card.append(head, atElement('div', 'at-section-label', server.endpoint || server.command_path || '未配置地址'));
        const actions = atElement('div', 'at-card-actions');
        const edit = atElement('button', '', '编辑');
        edit.type = 'button';
        edit.addEventListener('click', () => {
            document.getElementById('at-mcp-key').value = server.server_key;
            document.getElementById('at-mcp-name').value = server.display_name;
            document.getElementById('at-mcp-transport').value = server.transport;
            document.getElementById('at-mcp-auth').value = server.auth_mode;
            document.getElementById('at-mcp-endpoint').value = server.endpoint || '';
            document.getElementById('at-mcp-command').value = server.command_path || '';
            document.getElementById('at-mcp-args').value = server.arguments_json || '[]';
            document.getElementById('at-mcp-env').value = server.environment_json || '{}';
            document.getElementById('at-mcp-oauth').value = server.oauth_config_json || '{}';
            document.getElementById('at-mcp-enabled').checked = !!Number(server.enabled);
            formCard.scrollIntoView({ behavior:'smooth', block:'start' });
        });
        const test = atElement('button', 'primary', '测试并发现工具');
        test.type = 'button';
        test.addEventListener('click', () => testAndSyncMcpServer(server, test));
        const remove = atElement('button', '', '删除');
        remove.type = 'button';
        remove.addEventListener('click', async () => {
            if (!confirm('删除 MCP 服务“' + server.display_name + '”？')) return;
            try {
                agentTeamSnapshot = await agentTeamRequest('delete_mcp_server', { id:Number(server.id) });
                renderAgentTeamMcp();
            } catch (error) { showAlert('error', error.message); }
        });
        actions.append(edit, test, remove);
        card.appendChild(actions);
        serverList.appendChild(card);
    });
    right.append(serverList, atElement('h3', '', '已发现工具（默认停用、高风险、未分配）'));
    const toolsWrap = atElement('div', 'at-table-wrap');
    const toolsTable = atElement('table', 'at-table');
    toolsTable.innerHTML = '<thead><tr><th>服务</th><th>原始工具名</th><th>Function 名</th><th>风险/副作用</th><th>审核</th></tr></thead>';
    const toolBody = document.createElement('tbody');
    (agentTeamSnapshot.mcpTools || []).forEach(tool => {
        const row = document.createElement('tr');
        row.append(atElement('td','',tool.server_name), atElement('td','',tool.original_name), atElement('td','',tool.function_name));
        const policy = document.createElement('td');
        const risk = document.createElement('select');
        ['low','medium','high','critical'].forEach(value => risk.add(new Option(value,value,false,value===tool.risk_level)));
        const effect = document.createElement('select');
        ['read','write','destructive'].forEach(value => effect.add(new Option(value,value,false,value===tool.effect)));
        policy.append(risk, document.createTextNode(' '), effect);
        const review = document.createElement('td');
        const enabled = document.createElement('input');
        enabled.type = 'checkbox';
        enabled.checked = !!Number(tool.enabled);
        const button = atElement('button','at-mini-btn primary',Number(tool.reviewed)?'更新审核':'审核并保存');
        button.type = 'button';
        button.addEventListener('click', async () => {
            try {
                agentTeamSnapshot = await agentTeamRequest('review_mcp_tool', {
                    id:Number(tool.id), risk_level:risk.value, effect:effect.value, enabled:enabled.checked
                });
                renderAgentTeamMcp();
                showAlert('success','MCP 工具审核已保存');
            } catch (error) { showAlert('error',error.message); }
        });
        review.append(enabled, document.createTextNode(' 启用 '), button);
        row.append(policy, review);
        toolBody.appendChild(row);
    });
    toolsTable.appendChild(toolBody);
    toolsWrap.appendChild(toolsTable);
    right.appendChild(toolsWrap);
    hero.append(formCard, right);
    root.appendChild(hero);
}

async function testAndSyncMcpServer(server, button) {
    if (typeof CefSharp === 'undefined' || !CefSharp.BindObjectAsync) {
        showAlert('error', 'MCP 本机测试需要在 MoonYa 桌面端打开管理页');
        return;
    }
    const original = button.textContent;
    button.disabled = true;
    button.textContent = '正在连接…';
    try {
        await CefSharp.BindObjectAsync('moonYaFileOps');
        const config = Object.assign({}, server, {
            arguments_json: jsonObject(server.arguments_json, []),
            environment_json: jsonObject(server.environment_json, {}),
            oauth_config_json: jsonObject(server.oauth_config_json, {})
        });
        await window.moonYaFileOps.mcpOp(JSON.stringify({ action:'configure', servers:[config] }));
        const raw = await window.moonYaFileOps.mcpOp(JSON.stringify({
            action:'list_tools',
            user_id:900000000 + Number(adminInfo.id || 1),
            server_key:server.server_key,
            timeout_seconds:120
        }));
        const discovered = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (!discovered.ok) throw new Error((discovered.error && discovered.error.message) || discovered.content || '连接失败');
        agentTeamSnapshot = await agentTeamRequest('sync_mcp_catalog', {
            server_key:server.server_key,
            tools:discovered.tools || []
        });
        renderAgentTeamMcp();
        showAlert('success','已发现并同步 ' + (discovered.tools || []).length + ' 个工具');
    } catch (error) {
        showAlert('error',error.message);
    } finally {
        button.disabled = false;
        button.textContent = original;
    }
}

function renderAgentTeamRuntime() {
    const root = document.getElementById('agentTeamRuntime');
    root.innerHTML = '';
    const card = atElement('div', 'at-card at-runtime');
    const labels = {
        multi_agent_v1:'启用新 Work / CU 团队路径',
        mcp_gateway:'启用 MCP 混合工具网关',
        max_parallel_agents:'最大并行 Agent 数',
        max_root_delegations:'已废弃：根委派批次数',
        max_planning_corrections:'已废弃：规划纠错次数',
        max_agent_iterations:'已废弃：单 Agent 工具轮次',
        max_shell_preflight_corrections:'已废弃：Shell 预检纠错次数',
        approval_timeout_seconds:'工具确认等待（0=无限）',
        loop_guard_repeat_count:'循环完整重复次数',
        loop_guard_max_period:'循环最大周期步数',
        loop_guard_recovery_attempts:'同一循环纠偏次数',
        event_payload_max_bytes:'事件内联载荷上限（字节）'
    };
    Object.keys(labels).forEach(key => {
        const config = (agentTeamSnapshot.runtime || {})[key] || { value:'' };
        const row = atElement('div','at-runtime-row');
        const text = atElement('div');
        text.append(atElement('strong','',labels[key]),atElement('small','',config.description || key));
        const input = document.createElement('input');
        input.id = 'at-runtime-' + key;
        input.type = (key === 'multi_agent_v1' || key === 'mcp_gateway') ? 'checkbox' : 'number';
        if (input.type === 'checkbox') input.checked = !!config.value;
        else input.value = config.value;
        row.append(text,input);
        card.appendChild(row);
    });
    const actions = atElement('div','at-card-actions');
    const save = atElement('button','primary','保存运行参数');
    save.type = 'button';
    save.addEventListener('click', async () => {
        const values = {};
        Object.keys(labels).forEach(key => {
            const input = document.getElementById('at-runtime-' + key);
            values[key] = input.type === 'checkbox' ? input.checked : Number(input.value);
        });
        try {
            agentTeamSnapshot = await agentTeamRequest('save_runtime',{values});
            renderAgentTeamRuntime();
            showAlert('success','运行参数已保存');
        } catch (error) { showAlert('error',error.message); }
    });
    actions.appendChild(save);
    card.appendChild(actions);
    root.appendChild(card);
}
</script>
