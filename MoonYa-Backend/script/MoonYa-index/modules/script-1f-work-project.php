
        // ════════════════════════════════════════════════════════════════════
        // Work 模式：项目文件夹管理模块（script-1f-work-project.php）
        // 取代 script-1a-vars.php 中旧的 #workProjectBtn / .work-project-option 绑定
        // 后端契约：/api/work_projects.php  |  启动器：{WP_LAUNCHER_API}/file-op
        // ════════════════════════════════════════════════════════════════════

        // ── 1. 全局状态与常量 ──
        const WP_LAUNCHER_API = (window.MOONYA_CONFIG && window.MOONYA_CONFIG.launcher_api_url) || '';
        const WP_API_BASE = 'api/work_projects.php';
        const WP_LS_KEY_PATH = 'moonya_work_project_path';
        const WP_LS_KEY_NAME = 'moonya_work_project_name';
        let WORK_PROJECT_TEXTS = {};
        let wpCurrentProjects = []; // 缓存当前列表
        let wpSearchDebounce = null;

        // ── 2. 工具函数 ──

        // 桌面启动器环境检测（与 script-1d-dom.php 一致）
        function isMoonYaLauncher() {
            return navigator.userAgent.indexOf('MoonYaDesktop') !== -1;
        }

        // 文案取值：缺失返回 key 本身；支持 {name}/{path}/{keyword} 占位符替换
        function t(key, params) {
            var raw = WORK_PROJECT_TEXTS[key];
            if (raw === undefined || raw === null || raw === '') return key;
            if (params && typeof raw === 'string') {
                return raw.replace(/\{(\w+)\}/g, function(_, name) {
                    return (params[name] !== undefined && params[name] !== null) ? params[name] : ('{' + name + '}');
                });
            }
            return raw;
        }

        // 遍历 [data-i18n] 设 textContent，[data-i18n-ph] 设 placeholder
        function applyI18n() {
            document.querySelectorAll('[data-i18n]').forEach(function(el) {
                var key = el.getAttribute('data-i18n');
                var val = WORK_PROJECT_TEXTS[key];
                if (val !== undefined && val !== null && val !== '') {
                    el.textContent = val;
                }
            });
            document.querySelectorAll('[data-i18n-ph]').forEach(function(el) {
                var key = el.getAttribute('data-i18n-ph');
                var val = WORK_PROJECT_TEXTS[key];
                if (val !== undefined && val !== null && val !== '') {
                    el.placeholder = val;
                }
            });
        }

        // 调用 C# 启动器 /file-op：pick_folder 等 UI 阻塞型 action 用 60 秒超时，其它 5 秒
        function callLauncher(action, body) {
            var timeoutMs = (action === 'pick_folder') ? 60000 : 5000;
            var fetchPromise = fetch(WP_LAUNCHER_API + '/file-op', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ action: action }, body || {}))
            }).then(function(r) { return r.json(); });
            var timeoutPromise = new Promise(function(_, reject) {
                setTimeout(function() { reject(new Error('launcher timeout')); }, timeoutMs);
            });
            return Promise.race([fetchPromise, timeoutPromise]).catch(function() {
                return { success: false, message: t('error_launcher_unreachable') };
            });
        }

        // 路径验证：启动器环境浏览器直连本地 C# API（远程后端无法 curl 用户本机），
        // 普通浏览器回退到 PHP 后端转发
        async function validatePath(path) {
            if (isMoonYaLauncher()) {
                return await callLauncher('validate_path', { path: path });
            }
            return await callPhpApi('validate_path', 'POST', { path: path });
        }

        // 弹出文件夹选择对话框：优先走 C# JS 桥接（绕过 HTTP CORS/PNA 限制），回退到 HTTP API
        async function pickFolderDialog() {
            if (window.moonYaFileOps && window.moonYaFileOps.pickFolder) {
                try {
                    return await window.moonYaFileOps.pickFolder();
                } catch (e) {
                    console.warn('Bridge pickFolder failed, falling back to HTTP', e);
                }
            }
            return await callLauncher('pick_folder', {});
        }

        // 调用 PHP API：GET/POST，POST 时 body 为 JSON
        function callPhpApi(action, method, body) {
            var opts = { method: method, headers: {} };
            if (method === 'POST' && body) {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            return fetch(WP_API_BASE + '?action=' + action, opts)
                .then(function(r) { return r.json(); })
                .catch(function() { return { success: false, message: 'network error' }; });
        }

        // Toast 适配：沿用全局 showToast(message, duration)，error 用更长时长；缺失则 console 兜底
        function wpShowToast(msg, type) {
            if (typeof window.showToast === 'function') {
                window.showToast(msg, type === 'error' ? 4000 : 2000);
            } else {
                console.log('[WorkProject]', type === 'error' ? 'ERROR:' : '', msg);
            }
        }

        function openModal(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.classList.add('show');
            el.setAttribute('aria-hidden', 'false');
        }

        function closeModal(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.classList.remove('show');
            el.setAttribute('aria-hidden', 'true');
        }

        // 设置 #workProjectBtn 内的 <span> 文本（按钮内含两个 svg，span 居中）
        function setButtonLabel(name) {
            var btn = document.getElementById('workProjectBtn');
            if (!btn) return;
            var span = btn.querySelector('span');
            if (span) span.textContent = name;
        }

        function syncWorkProjectConversation() {
            window.MoonYaActiveProject = {
                path: localStorage.getItem(WP_LS_KEY_PATH) || '',
                name: localStorage.getItem(WP_LS_KEY_NAME) || ''
            };
            if (typeof window.syncActiveConversationComposer === 'function') {
                window.syncActiveConversationComposer();
            }
        }

        // 转义：& < > " （文本与双引号属性均安全）
        function wpEscape(s) {
            s = (s === undefined || s === null) ? '' : String(s);
            return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // ── 3. 文案加载 ──
        async function loadWorkProjectTexts() {
            try {
                var r = await callPhpApi('get_texts', 'GET');
                if (r.success && r.data && r.data.texts) {
                    WORK_PROJECT_TEXTS = r.data.texts;
                    window.WORK_PROJECT_TEXTS = WORK_PROJECT_TEXTS;
                    applyI18n();
                    // 文案就绪后刷新按钮默认文案（若无已选项目）
                    if (!localStorage.getItem(WP_LS_KEY_NAME)) {
                        setButtonLabel(WORK_PROJECT_TEXTS.btn_enter_project_default || '进入项目工作');
                    }
                }
            } catch (e) {
                console.warn('load work project texts failed', e);
            }
        }

        // ── 4. 按钮点击事件重写（取代 script-1a-vars.php 第 577-583 行）──
        // 用 cloneNode 彻底移除旧绑定后重新绑定，确保真正“取代”
        function rebindWorkProjectButton() {
            var oldBtn = document.getElementById('workProjectBtn');
            if (!oldBtn) return;
            var btn = oldBtn.cloneNode(true);
            oldBtn.parentNode.replaceChild(btn, oldBtn);
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (!isMoonYaLauncher()) {
                    // 网页端：弹提示，阻止默认与冒泡
                    e.preventDefault();
                    openModal('workProjectWebWarnModal');
                    return;
                }
                // 启动器端：切换下拉菜单（与旧逻辑一致）
                var dd = document.getElementById('workProjectDropdown');
                if (dd) dd.classList.toggle('show');
                btn.classList.toggle('open');
            });
        }

        // ── 5. 三个 .work-project-option 点击事件（取代 script-1a-vars.php 第 585-591 行）──
        function rebindWorkProjectOptions() {
            var opts = document.querySelectorAll('.work-project-option');
            opts.forEach(function(oldOpt) {
                var opt = oldOpt.cloneNode(true);
                oldOpt.parentNode.replaceChild(opt, oldOpt);
                opt.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var dd = document.getElementById('workProjectDropdown');
                    var btn = document.getElementById('workProjectBtn');
                    if (dd) dd.classList.remove('show');
                    if (btn) btn.classList.remove('open');
                    handleOptionAction(opt.getAttribute('data-action'));
                });
            });
        }

        function handleOptionAction(action) {
            if (action === 'new_project') {
                openCreateModal();
            } else if (action === 'existing_folder') {
                pickExistingFolder();
            } else if (action === 'no_folder') {
                try { localStorage.removeItem(WP_LS_KEY_PATH); } catch (e) {}
                try { localStorage.removeItem(WP_LS_KEY_NAME); } catch (e) {}
                setButtonLabel(WORK_PROJECT_TEXTS.btn_enter_project_default || '进入项目工作');
                syncWorkProjectConversation();
                wpShowToast(t('toast_no_folder'));
            }
        }

        // ── 5.1 使用现有文件夹：直接弹资源管理器选文件夹，选完用文件夹名作为项目名保存 ──
        async function pickExistingFolder() {
            var btn = document.getElementById('workProjectBtn');
            var origLabel = null;
            var span = btn ? btn.querySelector('span') : null;
            if (span) {
                origLabel = span.textContent;
                span.textContent = t('loading_validating');
            }
            var r = await pickFolderDialog();
            if (span && origLabel !== null) span.textContent = origLabel;
            if (!r || !r.success) {
                if (r && r.cancelled) return; // 用户取消，静默
                wpShowToast((r && r.message) || t('error_launcher_unreachable'), 'error');
                return;
            }
            var path = r.path || '';
            if (!path) return;
            // 取最后一段作为项目名（兼容 / 和 \）
            var name = path.replace(/[\/\\]+$/, '').split(/[\/\\]/).pop() || '项目';
            // 幂等保存（已存在则切换）
            var create = await callPhpApi('create', 'POST', { name: name, path: path });
            if (create.success) {
                try { localStorage.setItem(WP_LS_KEY_PATH, path); } catch (e) {}
                try { localStorage.setItem(WP_LS_KEY_NAME, name); } catch (e) {}
                setButtonLabel(name);
                syncWorkProjectConversation();
                wpShowToast(t('toast_project_switched', { name: name }));
            } else if (create.message && create.message.indexOf('已存在') !== -1) {
                // 路径已绑定过，直接切换
                try { localStorage.setItem(WP_LS_KEY_PATH, path); } catch (e) {}
                try { localStorage.setItem(WP_LS_KEY_NAME, name); } catch (e) {}
                setButtonLabel(name);
                syncWorkProjectConversation();
                wpShowToast(t('toast_project_switched', { name: name }));
            } else {
                wpShowToast(create.message || t('error_name_exists'), 'error');
            }
        }

        // ── 6. 列表弹窗渲染 ──
        function renderFolderList(projects, keyword) {
            var list = document.getElementById('wpFolderList');
            var empty = document.getElementById('wpListEmpty');
            if (!list || !empty) return;
            var kw = (keyword || '').trim().toLowerCase();
            var filtered = kw ? projects.filter(function(p) {
                return (p.name && String(p.name).toLowerCase().indexOf(kw) !== -1);
            }) : projects.slice();

            if (!filtered.length) {
                list.style.display = 'none';
                empty.style.display = '';
                var emptyText = empty.querySelector('.wp-empty-text');
                var emptyHint = empty.querySelector('.wp-empty-hint');
                var emptyCreateBtn = document.getElementById('wpEmptyCreateBtn');
                if (!projects.length) {
                    // 真正无文件夹
                    if (emptyText) emptyText.textContent = t('empty_no_folders');
                    if (emptyHint) emptyHint.textContent = t('empty_no_folders_hint');
                    if (emptyCreateBtn) emptyCreateBtn.style.display = '';
                } else {
                    // 搜索无匹配
                    if (emptyText) emptyText.textContent = t('empty_search_no_match', { keyword: keyword || '' });
                    if (emptyHint) emptyHint.textContent = '';
                    if (emptyCreateBtn) emptyCreateBtn.style.display = 'none';
                }
                return;
            }
            empty.style.display = 'none';
            list.style.display = '';
            list.innerHTML = filtered.map(function(p) {
                return '<div class="wp-folder-item" data-id="' + wpEscape(p.id) + '" data-name="' + wpEscape(p.name) + '" data-path="' + wpEscape(p.path) + '">' +
                    '<div class="wp-folder-info">' +
                        '<div class="wp-folder-name">' + wpEscape(p.name) + '</div>' +
                        '<div class="wp-folder-path">' + wpEscape(p.path) + '</div>' +
                    '</div>' +
                    '<div class="wp-folder-actions">' +
                        '<button class="wp-icon-btn select" data-action="select">' + wpEscape(t('btn_select')) + '</button>' +
                        '<button class="wp-icon-btn delete" data-action="delete">' + wpEscape(t('btn_delete')) + '</button>' +
                    '</div>' +
                '</div>';
            }).join('');
        }

        async function openListModal() {
            openModal('workProjectListModal');
            var searchInput = document.getElementById('wpListSearchInput');
            if (searchInput) searchInput.value = '';
            var list = document.getElementById('wpFolderList');
            var empty = document.getElementById('wpListEmpty');
            if (empty) empty.style.display = 'none';
            if (list) {
                list.style.display = '';
                list.innerHTML = '<div style="text-align:center;padding:24px;"><span class="wp-spinner dark"></span></div>';
            }
            var r = await callPhpApi('list', 'GET');
            if (r.success && r.data && r.data.projects) {
                wpCurrentProjects = r.data.projects;
            } else {
                wpCurrentProjects = [];
            }
            renderFolderList(wpCurrentProjects, searchInput ? searchInput.value : '');
        }

        // ── 7. 选择文件夹流程 ──
        async function selectFolder(id) {
            var p = wpCurrentProjects.find(function(x) { return String(x.id) === String(id); });
            if (!p) return;
            var v = await validatePath(p.path);
            if (!v.success) {
                wpShowToast(t('error_path_not_writable', { path: p.path }), 'error');
                return;
            }
            try { localStorage.setItem(WP_LS_KEY_PATH, p.path); } catch (e) {}
            try { localStorage.setItem(WP_LS_KEY_NAME, p.name); } catch (e) {}
            setButtonLabel(p.name);
            syncWorkProjectConversation();
            closeModal('workProjectListModal');
            wpShowToast(t('toast_project_switched', { name: p.name }));
            // 后台更新 last_used_at
            callPhpApi('save_current', 'POST', { id: p.id });
        }

        // ── 8. 删除文件夹流程 ──
        async function deleteFolder(id, name) {
            var r = await callPhpApi('delete', 'POST', { id: id });
            if (r.success) {
                wpCurrentProjects = wpCurrentProjects.filter(function(x) { return String(x.id) !== String(id); });
                var searchInput = document.getElementById('wpListSearchInput');
                renderFolderList(wpCurrentProjects, searchInput ? searchInput.value : '');
                wpShowToast(t('toast_project_deleted', { name: name }));
            } else {
                wpShowToast(r.message || 'error', 'error');
            }
        }

        // ── 9. 新建项目弹窗交互 ──
        function openCreateModal() {
            var nameInput = document.getElementById('wpCreateNameInput');
            var pathInput = document.getElementById('wpCreatePathInput');
            if (nameInput) nameInput.value = '';
            if (pathInput) pathInput.value = '';
            openModal('workProjectCreateModal');
            if (nameInput) { setTimeout(function() { nameInput.focus(); }, 50); }
        }

        function bindCreateModalEvents() {
            // 选择文件夹（可选辅助：原生选择器回填到路径输入框）
            var pickBtn = document.getElementById('wpCreatePickBtn');
            if (pickBtn) {
                pickBtn.addEventListener('click', async function() {
                    var r = await pickFolderDialog();
                    if (r && r.success) {
                        var pathInput = document.getElementById('wpCreatePathInput');
                        if (pathInput) {
                            pathInput.value = r.path || '';
                            pathInput.focus();
                        }
                    } else if (r && r.cancelled) {
                        // 用户取消，静默
                    } else {
                        wpShowToast((r && r.message) || t('error_launcher_unreachable'), 'error');
                    }
                });
            }

            // 保存新建
            var confirmBtn = document.getElementById('wpCreateConfirmBtn');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', async function() {
                    var nameInput = document.getElementById('wpCreateNameInput');
                    var pathInput = document.getElementById('wpCreatePathInput');
                    var name = nameInput ? nameInput.value.trim() : '';
                    var path = pathInput ? pathInput.value.trim() : '';
                    if (!name || !path) {
                        wpShowToast(t('error_name_or_path_empty'), 'error');
                        return;
                    }
                    var origText = confirmBtn.textContent;
                    confirmBtn.disabled = true;
                    confirmBtn.innerHTML = '<span class="wp-spinner"></span> ' + wpEscape(t('loading_creating'));
                    try {
                        var v = await validatePath(path);
                        if (!v.success) {
                            wpShowToast(t('error_path_not_writable', { path: path }), 'error');
                            return;
                        }
                        var r = await callPhpApi('create', 'POST', { name: name, path: path });
                        if (r.success) {
                            try { localStorage.setItem(WP_LS_KEY_PATH, path); } catch (e) {}
                            try { localStorage.setItem(WP_LS_KEY_NAME, name); } catch (e) {}
                            setButtonLabel(name);
                            syncWorkProjectConversation();
                            closeModal('workProjectCreateModal');
                            wpShowToast(t('toast_project_created', { name: name }));
                        } else {
                            wpShowToast(r.message || t('error_name_exists'), 'error');
                        }
                    } finally {
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = origText;
                    }
                });
            }

            // 取消 / 关闭 / 网页提示确认
            var cancelBtn = document.getElementById('wpCreateCancelBtn');
            if (cancelBtn) cancelBtn.addEventListener('click', function() { closeModal('workProjectCreateModal'); });
            var closeBtn = document.getElementById('wpCreateCloseBtn');
            if (closeBtn) closeBtn.addEventListener('click', function() { closeModal('workProjectCreateModal'); });
            var warnOkBtn = document.getElementById('wpWarnOkBtn');
            if (warnOkBtn) warnOkBtn.addEventListener('click', function() { closeModal('workProjectWebWarnModal'); });
        }

        // 列表项事件委托 + 搜索防抖 + 空状态跳转新建
        function bindListEvents() {
            var list = document.getElementById('wpFolderList');
            if (list) {
                list.addEventListener('click', function(e) {
                    var btnEl = e.target.closest('.wp-icon-btn');
                    if (!btnEl) return;
                    var item = btnEl.closest('.wp-folder-item');
                    if (!item) return;
                    var id = item.getAttribute('data-id');
                    var name = item.getAttribute('data-name') || '';
                    var act = btnEl.getAttribute('data-action');
                    if (act === 'select') {
                        selectFolder(id);
                    } else if (act === 'delete') {
                        deleteFolder(id, name);
                    }
                });
            }
            var searchInput = document.getElementById('wpListSearchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var val = searchInput.value;
                    clearTimeout(wpSearchDebounce);
                    wpSearchDebounce = setTimeout(function() {
                        renderFolderList(wpCurrentProjects, val);
                    }, 200);
                });
            }
            var emptyCreateBtn = document.getElementById('wpEmptyCreateBtn');
            if (emptyCreateBtn) {
                emptyCreateBtn.addEventListener('click', function() {
                    closeModal('workProjectListModal');
                    openCreateModal();
                });
            }
            var listCloseBtn = document.getElementById('wpListCloseBtn');
            if (listCloseBtn) listCloseBtn.addEventListener('click', function() { closeModal('workProjectListModal'); });
        }

        // ── 10. ESC 与遮罩点击关闭 ──
        function createModalHasInput() {
            var modal = document.getElementById('workProjectCreateModal');
            if (!modal || !modal.classList.contains('show')) return false;
            var nameInput = document.getElementById('wpCreateNameInput');
            var pathInput = document.getElementById('wpCreatePathInput');
            var name = nameInput ? nameInput.value.trim() : '';
            var path = pathInput ? pathInput.value.trim() : '';
            return !!name || !!path;
        }

        function bindKeyboardAndOverlay() {
            // ESC：关闭所有打开的 .wp-modal-overlay.show；新建弹窗有输入时需二次确认
            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Escape' && e.keyCode !== 27) return;
                var opens = document.querySelectorAll('.wp-modal-overlay.show');
                if (!opens.length) return;
                var createModal = document.getElementById('workProjectCreateModal');
                if (createModal && createModal.classList.contains('show') && createModalHasInput()) {
                    if (!confirm(t('confirm_discard_input'))) return;
                }
                opens.forEach(function(el) {
                    el.classList.remove('show');
                    el.setAttribute('aria-hidden', 'true');
                });
            });
            // 遮罩点击（target === currentTarget）关闭该弹窗，逻辑同上
            document.querySelectorAll('.wp-modal-overlay').forEach(function(overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target !== overlay) return;
                    if (overlay.id === 'workProjectCreateModal' && createModalHasInput()) {
                        if (!confirm(t('confirm_discard_input'))) return;
                    }
                    overlay.classList.remove('show');
                    overlay.setAttribute('aria-hidden', 'true');
                });
            });
        }

        // ── 11. 初始化 ──
        function restoreButtonLabel() {
            var saved = null;
            try { saved = localStorage.getItem(WP_LS_KEY_NAME); } catch (e) {}
            if (saved) {
                setButtonLabel(saved);
            } else {
                setButtonLabel(WORK_PROJECT_TEXTS.btn_enter_project_default || '进入项目工作');
            }
        }

        function wpInit() {
            // 把弹窗移到 body 直接子元素，避免被 .input-container-wrapper 的 transform
            // 破坏 position:fixed（CSS 规范：祖先有 transform 时 fixed 退化为 absolute）
            ['workProjectWebWarnModal', 'workProjectCreateModal', 'workProjectListModal'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el && el.parentElement && el.parentElement.tagName !== 'BODY') {
                    document.body.appendChild(el);
                }
            });
            rebindWorkProjectButton();
            rebindWorkProjectOptions();
            bindCreateModalEvents();
            bindListEvents();
            bindKeyboardAndOverlay();
            restoreButtonLabel();
            if (isMoonYaLauncher()) {
                loadWorkProjectTexts();
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', wpInit);
        } else {
            wpInit();
        }
