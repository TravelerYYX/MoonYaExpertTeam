            <div id="systemPromptsSection" class="content-section">
                <div class="card">
                    <div class="card-header">
                        <h2>系统提示词模板</h2>
                        <button class="btn-primary" onclick="openSystemPromptModal()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            新增
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>名称</th>
                                    <th>展示名</th>
                                    <th>适用模型</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="systemPromptsTableBody">
                                <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:32px;">加载中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

<!-- 系统提示词编辑模态框（16:9 横向） -->
<div id="systemPromptModal" class="modal modal-169">
    <div class="modal-content modal-169-content">
        <div class="modal-header">
            <h3 id="systemPromptModalTitle">新增系统提示词模板</h3>
            <span class="api-debug-result-close" id="systemPromptModalClose" title="关闭">✕</span>
        </div>
        <div class="modal-body modal-169-body">
            <input type="hidden" id="systemPromptId">
            <div class="modal-169-grid">
                <div class="modal-169-left">
                    <div class="form-group">
                        <label>名称 <span class="text-danger">*</span></label>
                        <input type="text" id="systemPromptName" class="form-input" placeholder="唯一标识，如 normal / programming / agent / custom_xxx">
                        <small style="color: var(--text-muted);">英文/数字/下划线；编辑时不可修改</small>
                    </div>
                    <div class="form-group">
                        <label>展示名 <span class="text-danger">*</span></label>
                        <input type="text" id="systemPromptDisplayName" class="form-input" placeholder="后台列表中显示的名称">
                    </div>
                    <div class="form-group">
                        <label>适用模型 <span class="text-danger">*</span></label>
                        <div id="modelCheckboxes" class="model-checkboxes"></div>
                        <small style="color: var(--text-muted);">至少选择一个；选择"全部模型"将匹配任意模型</small>
                    </div>
                    <div class="form-group">
                        <label class="switch-row">
                            <label class="toggle-switch" title="点击切换启用状态">
                                <input type="checkbox" id="systemPromptEnabled" checked>
                                <span class="toggle-slider"></span>
                            </label>
                            <span>启用此提示词模板</span>
                        </label>
                    </div>
                </div>
                <div class="modal-169-right">
                    <div class="form-group" style="height:100%;display:flex;flex-direction:column;">
                        <label>提示词内容 <span class="text-danger">*</span></label>
                        <textarea id="systemPromptContent" class="form-textarea" rows="12" placeholder="请输入完整的系统提示词内容" style="flex:1;min-height:240px;"></textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" id="systemPromptModalCancel">取消</button>
            <button class="btn-primary" id="systemPromptSaveBtn" onclick="saveSystemPrompt()">保存</button>
        </div>
    </div>
</div>

<style>
/* 系统提示词管理专用样式 */
.model-chip {
    display: inline-flex;
    align-items: center;
    padding: 2px 10px;
    border-radius: var(--radius-full);
    font-size: 12px;
    font-weight: 500;
    line-height: 1.6;
    white-space: nowrap;
    background: var(--primary-light);
    color: var(--primary);
    border: 1px solid var(--primary-lighter);
    margin: 2px;
}
.model-chip-more {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: var(--radius-full);
    font-size: 12px;
    font-weight: 500;
    background: var(--border-light);
    color: var(--text-secondary);
    border: 1px solid var(--border);
    margin: 2px;
}
.model-checkboxes {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    max-height: 160px;
    overflow-y: auto;
    background: #fff;
}
.model-check-item {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: #fff;
    font-size: 13px;
    color: var(--text-primary);
    cursor: pointer;
    transition: var(--transition);
}
.model-check-item:hover {
    border-color: var(--primary-lighter);
    background: var(--primary-light);
}
.model-check-item input {
    margin: 0;
    cursor: pointer;
}
.model-check-item.all-models {
    background: var(--success-light);
    border-color: rgba(16, 185, 129, 0.3);
    color: var(--success-hover);
    font-weight: 600;
}
.toggle-switch {
    position: relative;
    display: inline-block;
    width: 40px;
    height: 22px;
    vertical-align: middle;
}
.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.toggle-slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background-color: var(--border);
    transition: 0.2s;
    border-radius: 22px;
}
.toggle-slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.2s;
    border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.toggle-switch input:checked + .toggle-slider {
    background-color: var(--success);
}
.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(18px);
}

/* 16:9 横向弹窗 */
.modal-169 {
    padding: 24px;
}
.modal-169-content {
    width: 90vw;
    max-width: 1280px;
    aspect-ratio: 16 / 9;
    max-height: 720px;
    height: auto;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.modal-169-body {
    flex: 1;
    padding: 20px 24px;
    overflow: hidden;
}
.modal-169-grid {
    display: grid;
    grid-template-columns: 35% 65%;
    gap: 16px;
    height: 100%;
}
.modal-169-left,
.modal-169-right {
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow-y: auto;
    padding-right: 4px;
}
.modal-169-right .form-group {
    height: 100%;
}
.switch-row {
    display: flex !important;
    align-items: center;
    gap: 10px;
    cursor: pointer;
}
.switch-row .toggle-switch {
    cursor: pointer;
}

/* 保存按钮 loading 态 */
.btn-primary.is-loading,
.btn-primary[disabled] {
    position: relative;
    color: transparent !important;
    pointer-events: none;
}
.btn-primary.is-loading::after {
    content: "";
    position: absolute;
    width: 14px;
    height: 14px;
    top: 50%;
    left: 50%;
    margin: -7px 0 0 -7px;
    border: 2px solid rgba(255,255,255,0.5);
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* 小屏适配 */
@media (max-width: 1024px) {
    .modal-169-content {
        aspect-ratio: auto;
        max-height: 90vh;
        height: auto;
    }
    .modal-169-grid {
        grid-template-columns: 1fr;
        height: auto;
    }
    .modal-169-left,
    .modal-169-right {
        overflow-y: visible;
    }
}
</style>

<script>
        // ==================== 系统提示词模板管理 ====================

        const MODEL_LIST = <?php
            $configuredModelIds = ['*'];
            foreach (($runtimeConfig['ui_model_groups'] ?? []) as $group) {
                foreach (($group['models'] ?? []) as $model) {
                    $id = trim((string)($model['id'] ?? ''));
                    if ($id !== '') $configuredModelIds[] = $id;
                }
            }
            echo json_encode(array_values(array_unique($configuredModelIds)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>;

        // 缓存当前列表数据，便于编辑时查找
        let systemPromptsCache = [];

        async function loadSystemPrompts() {
            const tbody = document.getElementById('systemPromptsTableBody');
            if (!tbody) return;

            try {
                const response = await fetch('api/system_prompts.php', {
                    headers: {
                        'Authorization': `Bearer ${token}`
                    }
                });
                const data = await response.json();

                if (!data.success) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--danger);padding:32px;">加载失败：' + escapeHtml(data.error || '未知错误') + '</td></tr>';
                    systemPromptsCache = [];
                    return;
                }

                const list = (data.data && data.data.system_prompts) || [];
                systemPromptsCache = list;

                if (list.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:32px;">暂无数据</td></tr>';
                    return;
                }

                tbody.innerHTML = list.map(item => renderSystemPromptRow(item)).join('');
            } catch (e) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--danger);padding:32px;">加载失败：' + escapeHtml(e.message) + '</td></tr>';
                systemPromptsCache = [];
            }
        }

        function renderSystemPromptRow(item) {
            const models = parseApplicableModels(item.applicable_models);
            const visibleModels = models.slice(0, 4);
            const overflow = models.length - visibleModels.length;
            const chipsHtml = visibleModels.map(m => `<span class="model-chip" title="${escapeHtml(modelDisplayName(m))}">${escapeHtml(modelDisplayName(m))}</span>`).join('')
                + (overflow > 0 ? `<span class="model-chip-more" title="${escapeHtml(models.slice(4).map(modelDisplayName).join(', '))}">+${overflow}</span>` : '');
            const isEnabled = Number(item.enabled) === 1;

            return `
                <tr data-sp-id="${item.id}">
                    <td><strong>${escapeHtml(item.name)}</strong></td>
                    <td>${escapeHtml(item.display_name || '')}</td>
                    <td>${chipsHtml || '<span style="color:var(--text-muted);">未配置</span>'}</td>
                    <td style="display:flex;align-items:center;gap:12px;">
                        <button class="btn-secondary btn-sm" onclick="openSystemPromptModal(${item.id})"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;vertical-align:-2px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>编辑</button>
                        <label class="toggle-switch" title="${isEnabled ? '点击禁用' : '点击启用'}">
                            <input type="checkbox" ${isEnabled ? 'checked' : ''} onchange="toggleSystemPrompt(${item.id})">
                            <span class="toggle-slider"></span>
                        </label>
                        <button class="btn-danger btn-sm" onclick="deleteSystemPrompt(${item.id})" title="删除"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
                    </td>
                </tr>
            `;
        }

        function parseApplicableModels(value) {
            if (!value) return [];
            if (Array.isArray(value)) return value;
            try {
                const parsed = JSON.parse(value);
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        }

        function modelDisplayName(name) {
            if (name === '*') return '全部模型';
            return name;
        }

        function getModelListForModal() {
            // 优先使用 window.MODEL_DESCRIPTIONS 的键列表
            if (window.MODEL_DESCRIPTIONS && typeof window.MODEL_DESCRIPTIONS === 'object') {
                const keys = Object.keys(window.MODEL_DESCRIPTIONS);
                if (keys.length > 0 && !keys.includes('*')) {
                    return ['*', ...keys];
                }
                if (keys.length > 0) {
                    return keys;
                }
            }
            return MODEL_LIST;
        }

        function renderModelCheckboxes(selectedModels) {
            const container = document.getElementById('modelCheckboxes');
            if (!container) return;
            const list = getModelListForModal();
            const selected = new Set(selectedModels || []);

            container.innerHTML = list.map(model => {
                const isChecked = selected.has(model);
                const labelText = modelDisplayName(model);
                const extraClass = model === '*' ? ' all-models' : '';
                return `<label class="model-check-item${extraClass}">
                    <input type="checkbox" value="${escapeHtml(model)}" ${isChecked ? 'checked' : ''}>
                    <span>${escapeHtml(labelText)}</span>
                </label>`;
            }).join('');
        }

        function openSystemPromptModal(id) {
            const modal = document.getElementById('systemPromptModal');
            const titleEl = document.getElementById('systemPromptModalTitle');
            const idInput = document.getElementById('systemPromptId');
            const nameInput = document.getElementById('systemPromptName');
            const displayInput = document.getElementById('systemPromptDisplayName');
            const promptInput = document.getElementById('systemPromptContent');
            const enabledInput = document.getElementById('systemPromptEnabled');
            const saveBtn = document.getElementById('systemPromptSaveBtn');

            // 重置保存按钮状态
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.classList.remove('is-loading');
                saveBtn.textContent = '保存';
            }

            if (id) {
                const item = systemPromptsCache.find(x => Number(x.id) === Number(id));
                if (!item) {
                    showAlert('error', '未找到该记录');
                    return;
                }
                titleEl.textContent = '编辑系统提示词模板';
                idInput.value = item.id;
                nameInput.value = item.name || '';
                nameInput.disabled = true;
                displayInput.value = item.display_name || '';
                promptInput.value = item.prompt || '';
                enabledInput.checked = Number(item.enabled) === 1;
                renderModelCheckboxes(parseApplicableModels(item.applicable_models));
            } else {
                titleEl.textContent = '新增系统提示词模板';
                idInput.value = '';
                nameInput.value = '';
                nameInput.disabled = false;
                displayInput.value = '';
                promptInput.value = '';
                enabledInput.checked = true;
                renderModelCheckboxes([]);
            }

            modal.classList.add('show');
        }

        function hideSystemPromptModal() {
            const modal = document.getElementById('systemPromptModal');
            if (modal) modal.classList.remove('show');
            const nameInput = document.getElementById('systemPromptName');
            if (nameInput) nameInput.disabled = false;
            const saveBtn = document.getElementById('systemPromptSaveBtn');
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.classList.remove('is-loading');
                saveBtn.textContent = '保存';
            }
        }

        async function saveSystemPrompt() {
            const saveBtn = document.getElementById('systemPromptSaveBtn');
            if (saveBtn && saveBtn.disabled) {
                return;
            }

            const id = document.getElementById('systemPromptId').value.trim();
            const name = document.getElementById('systemPromptName').value.trim();
            const displayName = document.getElementById('systemPromptDisplayName').value.trim();
            const prompt = document.getElementById('systemPromptContent').value;
            const enabled = document.getElementById('systemPromptEnabled').checked ? 1 : 0;

            const checkedBoxes = document.querySelectorAll('#modelCheckboxes input[type="checkbox"]:checked');
            const applicableModels = Array.from(checkedBoxes).map(cb => cb.value);

            if (!name) {
                showAlert('error', '请填写名称');
                return;
            }
            if (!displayName) {
                showAlert('error', '请填写展示名');
                return;
            }
            if (!prompt || !prompt.trim()) {
                showAlert('error', '请填写提示词内容');
                return;
            }
            if (applicableModels.length === 0) {
                showAlert('error', '请至少选择一个适用模型');
                return;
            }

            const body = {
                name,
                display_name: displayName,
                prompt,
                applicable_models: JSON.stringify(applicableModels),
                enabled
            };

            const url = id
                ? `api/system_prompts.php?action=update&id=${encodeURIComponent(id)}`
                : 'api/system_prompts.php?action=create';

            // 进入 loading 态
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.classList.add('is-loading');
                saveBtn.textContent = '保存中...';
            }

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify(body)
                });
                const data = await response.json();

                if (data.success) {
                    hideSystemPromptModal();
                    await loadSystemPrompts();
                    showAlert('success', '保存成功');
                } else {
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.classList.remove('is-loading');
                        saveBtn.textContent = '保存';
                    }
                    showAlert('error', data.error || '保存失败');
                }
            } catch (e) {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.classList.remove('is-loading');
                    saveBtn.textContent = '保存';
                }
                console.error('保存系统提示词失败:', e);
                showAlert('error', '保存失败: ' + e.message);
            }
        }

        async function toggleSystemPrompt(id) {
            try {
                const response = await fetch(`api/system_prompts.php?action=toggle&id=${encodeURIComponent(id)}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    }
                });
                const data = await response.json();

                if (data.success) {
                    await loadSystemPrompts();
                    showAlert('success', '状态已更新');
                } else {
                    showAlert('error', data.error || '更新失败');
                    await loadSystemPrompts();
                }
            } catch (e) {
                console.error('切换状态失败:', e);
                showAlert('error', '更新失败: ' + e.message);
                await loadSystemPrompts();
            }
        }

        async function deleteSystemPrompt(id) {
            if (!confirm('确定要删除这条提示词模板吗？此操作不可恢复')) {
                return;
            }
            try {
                const response = await fetch(`api/system_prompts.php?action=delete&id=${encodeURIComponent(id)}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    }
                });
                const data = await response.json();

                if (data.success) {
                    await loadSystemPrompts();
                    showAlert('success', '删除成功');
                } else {
                    showAlert('error', data.error || '内置名称不可删除');
                }
            } catch (e) {
                console.error('删除失败:', e);
                showAlert('error', '内置名称不可删除');
            }
        }

        // ==================== 弹窗关闭绑定 ====================
        // 仅由 × 按钮、取消按钮、保存成功三种路径关闭
        // 不再监听背景点击和 ESC 键
        document.getElementById('systemPromptModalClose').addEventListener('click', hideSystemPromptModal);
        document.getElementById('systemPromptModalCancel').addEventListener('click', hideSystemPromptModal);
</script>
