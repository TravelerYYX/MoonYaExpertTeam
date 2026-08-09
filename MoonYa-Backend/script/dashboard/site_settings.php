            <div id="siteSettingsSection" class="content-section">
                <div class="card">
                    <div class="card-header">
                        <h2>搜索设置</h2>
                    </div>
                    <div style="padding: 24px;">
                        <p class="text-secondary" style="margin-bottom: 16px;">
                            控制 <strong>Chat 模式</strong>下 Kimi 模型的联网搜索行为。系统会根据 query 类型自动选择最佳搜索方式。修改后立即生效，无需重启服务。
                        </p>
                        <div id="siteSettingsContainer">
                            <div style="text-align:center;color:#888;padding:24px;">加载中...</div>
                        </div>
                    </div>
                </div>
            </div>

<script>
        // ★ v4.6 (2026-06-20 修订): 三个选项，默认 auto
        //   auto → 系统自动选后端（URL 直接 Python，其他优先 Kimi，失败 fallback）
        //   moonshot → 强制走 Moonshot 原生 builtin_function
        //   function_calling → 强制走 Function Calling + Python 搜索服务
        const KNOWN_FALLBACK_OPTIONS = {
            'chat_search_backend': {
                'auto': '🤖 自动（推荐）— 系统智能选择：URL 直接 Python，其他优先 Kimi，失败 fallback Python',
                'moonshot': 'Moonshot 原生 web_search（builtin_function，Kimi 自己执行）',
                'function_calling': 'Function Calling + Python 搜索服务（自己执行）'
            }
        };

        async function loadSiteSettings() {
            try {
                const response = await fetch('api/site_settings.php', {
                    headers: { 'Authorization': `Bearer ${token}` }
                });
                const data = await response.json();
                if (!data.success) {
                    showAlert('error', data.error || '加载设置失败');
                    return;
                }
                renderSiteSettings(data.data.settings || []);
            } catch (e) {
                showAlert('error', '加载设置失败: ' + e.message);
            }
        }

        function renderSiteSettings(settings) {
            const container = document.getElementById('siteSettingsContainer');
            container.innerHTML = '';

            if (settings.length === 0) {
                container.innerHTML = '<div style="text-align:center;color:#888;padding:24px;">暂无配置项</div>';
                return;
            }

            settings.forEach(setting => {
                const card = document.createElement('div');
                card.className = 'card';
                card.style.marginBottom = '20px';

                // 兜底：DB 中 options 为空但有 KNOWN_FALLBACK 时使用硬编码
                let options = setting.options || {};
                const optionEntries = Object.entries(options);
                const type = setting.setting_type || 'text';

                // ★ v4.6 修订: 通用 radio 渲染（chat_search_backend 跟其他 select 一样用 KNOWN_FALLBACK）
                if (type === 'select' && optionEntries.length === 0 && KNOWN_FALLBACK_OPTIONS[setting.setting_key]) {
                    options = KNOWN_FALLBACK_OPTIONS[setting.setting_key];
                }
                const finalOptionEntries = Object.entries(options);

                let inputHtml = '';
                if (type === 'select' && finalOptionEntries.length > 0) {
                    // 单选 radio 组
                    const radios = finalOptionEntries.map(([val, label]) => {
                        const checked = (String(val) === String(setting.setting_value)) ? 'checked' : '';
                        const id = `setting_${setting.setting_key}_${val}`;
                        // auto 选项特殊高亮
                        const isAuto = val === 'auto';
                        const highlightStyle = isAuto
                            ? 'background:#f0f7ff;border:2px solid #4a90e2;'
                            : (String(val) === String(setting.setting_value) ? 'background:#f0f7ff;' : 'background:#fff;');
                        return `
                            <label for="${id}" style="display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border:1px solid #e3e6ed;border-radius:6px;cursor:pointer;${highlightStyle}transition:all .15s;">
                                <input type="radio" id="${id}" name="setting_${setting.setting_key}" value="${escapeHtml(val)}" ${checked} style="margin-top:3px;">
                                <span style="flex:1;">
                                    <span style="display:block;font-weight:${isAuto ? '600' : '500'};">${escapeHtml(label)}</span>
                                    <span style="display:block;font-size:12px;color:#888;margin-top:2px;">value: <code>${escapeHtml(val)}</code></span>
                                </span>
                            </label>
                        `;
                    }).join('');
                    inputHtml = `<div style="display:flex;flex-direction:column;gap:8px;">${radios}</div>`;
                } else if (type === 'boolean') {
                    inputHtml = `
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" id="setting_input_${escapeHtml(setting.setting_key)}" ${setting.setting_value === '1' || setting.setting_value === 'true' ? 'checked' : ''}>
                            <span>启用</span>
                        </label>
                    `;
                } else {
                    inputHtml = `<input type="text" id="setting_input_${escapeHtml(setting.setting_key)}" class="form-input" style="width:100%;" value="${escapeHtml(setting.setting_value || '')}">`;
                }

                const displayLabel = setting.setting_label || setting.setting_key;
                card.innerHTML = `
                    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                        <h3 style="margin:0;">${escapeHtml(displayLabel)}</h3>
                        <code style="font-size:12px;color:#888;background:#f3f5fa;padding:2px 8px;border-radius:4px;">${escapeHtml(setting.setting_key)}</code>
                    </div>
                    <div style="padding:20px;">
                        <div class="form-group">
                            ${inputHtml}
                        </div>
                        <div class="modal-footer" style="margin-top:16px;justify-content:flex-end;">
                            <button class="btn-primary" onclick="saveSiteSetting('${escapeHtml(setting.setting_key)}', '${type}')">保存设置</button>
                        </div>
                    </div>
                `;
                container.appendChild(card);
            });
        }

        async function saveSiteSetting(key, type) {
            let value = '';
            if (type === 'select') {
                const checked = document.querySelector(`input[name="setting_${key}"]:checked`);
                if (!checked) {
                    showAlert('error', '请选择一个选项');
                    return;
                }
                value = checked.value;
            } else if (type === 'boolean') {
                const cb = document.getElementById(`setting_input_${key}`);
                value = cb && cb.checked ? '1' : '0';
            } else {
                value = document.getElementById(`setting_input_${key}`).value;
            }

            try {
                const response = await fetch('api/site_settings.php?action=update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({
                        setting_key: key,
                        setting_value: value
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showAlert('success', '设置保存成功');
                    loadSiteSettings();
                } else {
                    showAlert('error', data.error || '保存失败');
                }
            } catch (e) {
                showAlert('error', '保存失败: ' + e.message);
            }
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
</script>
