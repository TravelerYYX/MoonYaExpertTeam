<?php
$runtimeConfig = require dirname(__DIR__) . '/config.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户管理后台</title>
        <link rel="stylesheet" href="assets/admin-ui.css">

</head>

<body class="admin-body">
    <div id="alert" class="alert"></div>

    <div class="main-wrapper">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:10px;color:var(--primary)"><circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0 1 10 10"/><path d="M12 12 4.5 6.5"/></svg>
                MoonYa Admin
            </div>
            <nav class="sidebar-nav">
                <a class="nav-item" data-section="home" href="javascript:void(0)" onclick="switchSection('home')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v18H3V3zm16 16V5H5v14h14z" /></svg></span>
                    <span>首页</span>
                </a>
                <a class="nav-item" data-section="users" href="javascript:void(0)" onclick="switchSection('users')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 7a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm10 8v2M22 11v2" /></svg></span>
                    <span>用户</span>
                </a>
                <a class="nav-item" data-section="splashPages" href="javascript:void(0)" onclick="switchSection('splashPages')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v18H3V3zm16 16V5H5v14h14zM7 7h10v2H7zm0 4h10v2H7z" /></svg></span>
                    <span>启动页</span>
                </a>
                <a class="nav-item" data-section="systemPrompts" href="javascript:void(0)" onclick="switchSection('systemPrompts')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v4H4zM4 10h10v4H4zM4 16h16v4H4z" /></svg></span>
                    <span>系统提示词</span>
                </a>
                <a class="nav-item" data-section="toolSettings" href="javascript:void(0)" onclick="switchSection('toolSettings')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.3-3.3a1 1 0 0 0 0-1.4l-1.6-1.6a1 1 0 0 0-1.4 0L14.7 6.3zM21 12h-2M3 12h2M12 3v2M12 21v2M7.05 7.05l1.41 1.41M16.95 16.95l1.41 1.41M7.05 16.95l1.41-1.41M16.95 7.05l1.41-1.41" /></svg></span>
                    <span>工具</span>
                </a>
                <a class="nav-item" data-section="agentTeam" href="javascript:void(0)" onclick="switchSection('agentTeam')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="3"/><circle cx="5" cy="18" r="3"/><circle cx="19" cy="18" r="3"/><path d="M12 8v4M7.6 15.6 10 13M16.4 15.6 14 13"/></svg></span>
                    <span>Agent 团队</span>
                </a>
                <a class="nav-item" data-section="updates" href="javascript:void(0)" onclick="switchSection('updates')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M5 5l2.8 2.8M17 5l-2.8 2.8M12 13a6 6 0 1 0 0 12 6 6 0 0 0 0-12z" /></svg></span>
                    <span>版本</span>
                </a>
                <a class="nav-item" data-section="mobileUpdates" href="javascript:void(0)" onclick="switchSection('mobileUpdates')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm5 18a1 1 0 1 0 0-2 1 1 0 0 0 0 2z" /></svg></span>
                    <span>移动端</span>
                </a>
                <a class="nav-item" data-section="hotTopics" href="javascript:void(0)" onclick="switchSection('hotTopics')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-4-3-4-3 0 2 1 4.5 1 4.5M12 14.5a2.5 2.5 0 0 0 2.5-2.5c0-1.38-.5-2-1-3-1.072-2.143-4-3-4-3 0 2 1 4.5 1 4.5M12 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z" /></svg></span>
                    <span>热点</span>
                </a>
                <a class="nav-item" data-section="webpages" href="javascript:void(0)" onclick="switchSection('webpages')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" /></svg></span>
                    <span>网页</span>
                </a>
                <a class="nav-item" data-section="community" href="javascript:void(0)" onclick="switchSection('community')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg></span>
                    <span>社区</span>
                </a>
                <a class="nav-item" data-section="email" href="javascript:void(0)" onclick="switchSection('email')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 7l-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7M2 7v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7" /></svg></span>
                    <span>邮件</span>
                </a>
                <a class="nav-item" data-section="siteSettings" href="javascript:void(0)" onclick="switchSection('siteSettings')">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
                    <span>搜索设置</span>
                </a>
                <a class="nav-item" href="api_domain.php">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span>
                    <span>API 域名配置</span>
                </a>
            </nav>
        </aside>

        <div class="content-area" style="flex:1;display:flex;flex-direction:column;min-width:0;">
            <header class="topbar">
                <div style="display:flex;align-items:center;gap:12px;">
                    <button class="btn-ghost sidebar-toggle" style="display:none;" onclick="document.querySelector('.sidebar').classList.toggle('open')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </button>
                    <h1 class="topbar-title" id="topbarTitle">Dashboard</h1>
                </div>
                <div class="topbar-actions">
                    <span class="text-secondary" id="adminInfo">Admin</span>
                    <button class="btn-ghost" onclick="logout()">退出</button>
                </div>
            </header>

            <main class="main-content">
        <?php
        $dashboard_dir = __DIR__ . '/../script/dashboard';
        include $dashboard_dir . '/home.php';
        include $dashboard_dir . '/splash_pages.php';
        include $dashboard_dir . '/users.php';
        include $dashboard_dir . '/personality.php';
        include $dashboard_dir . '/tool_settings.php';
        include $dashboard_dir . '/agent_team.php';
        include $dashboard_dir . '/updates.php';
        include $dashboard_dir . '/mobile_updates.php';
        include $dashboard_dir . '/hot_topics.php';
        include $dashboard_dir . '/webpages.php';
        include $dashboard_dir . '/community.php';
        include $dashboard_dir . '/email.php';
        include $dashboard_dir . '/site_settings.php';
        ?>
            </main>
        </div>
    </div>
<div id="modal" class="modal"><div class="modal-content" id="modalContent"></div></div>

<script>
        let token = localStorage.getItem('adminToken');
        let adminInfo = JSON.parse(localStorage.getItem('adminInfo') || '{}');
        let currentPage = 1;
        let currentFilters = {};
        let currentSection = 'home';
        
        if (!token) {
            window.location.href = 'login.php';
        }
        
        document.getElementById('adminInfo').textContent = `欢迎，${adminInfo.username}`;
        
        function switchSection(section) {
            currentSection = section;
            
            // 更新菜单样式
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            document.querySelector(`.nav-item[data-section="${section}"]`).classList.add('active');
            
            // 显示对应内容
            document.querySelectorAll('.content-section').forEach(sec => {
                sec.classList.remove('active');
            });
            document.getElementById(section + 'Section').classList.add('active');
            const titles = {home:'Dashboard',users:'用户管理',splashPages:'启动页管理',systemPrompts:'系统提示词模板',toolSettings:'工具设置',agentTeam:'Agent 团队',updates:'版本更新管理',mobileUpdates:'移动端更新管理',hotTopics:'热点管理',webpages:'网页管理',community:'社区管理',email:'邮件发送',siteSettings:'搜索设置'};
            const topbarTitle = document.getElementById('topbarTitle');
            if(topbarTitle) topbarTitle.textContent = titles[section] || 'Dashboard';
            
            // 加载数据
            if (section === 'home') {
                loadStats();
            } else if (section === 'splashPages') {
                loadSplashPages();
            } else if (section === 'users') {
                loadUsers();
            } else if (section === 'systemPrompts') {
                loadSystemPrompts();
            } else if (section === 'toolSettings') {
                loadToolSettings();
            } else if (section === 'agentTeam') {
                loadAgentTeam();
            } else if (section === 'updates') {
                loadUpdates();
            } else if (section === 'mobileUpdates') {
                loadMobileUpdates();
            } else if (section === 'hotTopics') {
                loadHotTopics();
            } else if (section === 'webpages') {
                loadWebpages();
            } else if (section === 'community') {
                loadCommunityStats();
                loadCommunityPosts();
                loadCommunityReports();
                loadNotificationList();
            } else if (section === 'email') {
                loadEmailUsers();
            } else if (section === 'siteSettings') {
                loadSiteSettings();
            }
        }
        function showAlert(type, message) {
            const alertDiv = document.getElementById('alert');
            alertDiv.className = 'alert alert-' + type + ' show';
            alertDiv.textContent = message;
            setTimeout(() => {
                alertDiv.className = 'alert';
            }, 3000);
        }
        function logout() {
            localStorage.removeItem('adminToken');
            localStorage.removeItem('adminInfo');
            window.location.href = 'login.php';
        }
        async function apiRequest(endpoint, options = {}) {
            const url = `api/users.php${endpoint}`;
            
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                const method = options.method || 'GET';
                
                xhr.open(method, url, true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('Authorization', `Bearer ${token}`);
                
                xhr.onload = function() {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        resolve(data);
                    } catch (e) {
                        reject(new Error('解析响应失败'));
                    }
                };
                
                xhr.onerror = function() {
                    reject(new Error('网络请求失败'));
                };
                
                if (options.body) {
                    xhr.send(options.body);
                } else {
                    xhr.send();
                }
            });
        }
        function getApiBaseUrl() {
            const protocol = window.location.protocol;
            const host = window.location.host;
            const adminDir = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
            return protocol + '//' + host + adminDir;
        }

        function initApiDebugUrls() {
            const base = getApiBaseUrl();
            const splashExternalBase = base.replace(/\/admin$/, '') + '/api/splash_pages/splash_pages.php';
            const mobileExternalBase = base.replace(/\/admin$/, '') + '/api/mobile_updates/mobile_updates.php';

            document.getElementById('splashApiActiveUrl').textContent = splashExternalBase + '?action=active';
            document.getElementById('splashApiListUrl').textContent = splashExternalBase + '?action=list';
            document.getElementById('splashApiAddUrl').textContent = splashExternalBase + '?action=add';
            document.getElementById('splashApiUpdateUrl').textContent = splashExternalBase + '?action=update';
            document.getElementById('splashApiDeleteUrl').textContent = splashExternalBase + '?action=delete';
            document.getElementById('splashApiToggleUrl').textContent = splashExternalBase + '?action=toggle';

            document.getElementById('mobileApiLatestUrl').textContent = mobileExternalBase + '?action=latest';
            document.getElementById('mobileApiListUrl').textContent = mobileExternalBase + '?action=list';
            document.getElementById('mobileApiCreateUrl').textContent = mobileExternalBase + '?action=create';
            document.getElementById('mobileApiUpdateUrl').textContent = mobileExternalBase + '?action=update';
            document.getElementById('mobileApiDeleteUrl').textContent = mobileExternalBase + '?action=delete';
            document.getElementById('mobileApiToggleUrl').textContent = mobileExternalBase + '?action=toggle';
        }
        function toggleApiDebugPanel(bodyId) {
            const body = document.getElementById(bodyId);
            const toggleId = bodyId.replace('Body', 'Toggle');
            const toggle = document.getElementById(toggleId);
            if (body.classList.contains('open')) {
                body.classList.remove('open');
                toggle.textContent = '展开 ▼';
            } else {
                body.classList.add('open');
                toggle.textContent = '收起 ▲';
            }
        }
        async function debugApi(module, action) {
            const base = getApiBaseUrl();
            let url;
            let options = { method: 'GET' };

            if (module === 'splash') {
                const splashExternalBase = base.replace(/\/admin$/, '') + '/api/splash_pages/splash_pages.php';
                url = splashExternalBase + '?action=' + action;
            } else if (module === 'mobile') {
                const mobileExternalBase = base.replace(/\/admin$/, '') + '/api/mobile_updates/mobile_updates.php';
                url = mobileExternalBase + '?action=' + action;
            }

            const publicActions = module === 'splash' ? ['active'] : ['latest'];
            if (!publicActions.includes(action)) {
                options.headers = {
                    'Authorization': 'Bearer ' + token
                };
            }

            const resultId = module + 'ApiResult';
            const resultTitleId = module + 'ApiResultTitle';
            const resultBodyId = module + 'ApiResultBody';
            const resultEl = document.getElementById(resultId);
            const resultTitle = document.getElementById(resultTitleId);
            const resultBody = document.getElementById(resultBodyId);

            resultTitle.innerHTML = '<span style="color:var(--primary);">请求中...</span> ' + url;
            resultBody.textContent = '';
            resultEl.classList.add('show');

            try {
                const startTime = performance.now();
                const response = await fetch(url, options);
                const endTime = performance.now();
                const duration = Math.round(endTime - startTime);
                const text = await response.text();

                let formatted;
                try {
                    formatted = JSON.stringify(JSON.parse(text), null, 2);
                } catch (e) {
                    formatted = text;
                }

                const statusBadgeClass = response.ok ? 'badge-success' : 'badge-danger';
                resultTitle.innerHTML = '<span class="badge ' + statusBadgeClass + '">' + response.status + ' ' + response.statusText + '</span> ' + duration + 'ms — ' + url;
                resultBody.textContent = formatted;
            } catch (e) {
                resultTitle.innerHTML = '<span class="badge badge-danger">请求失败</span> ' + url;
                resultBody.textContent = 'Error: ' + e.message;
            }
        }

        // Generic modal helpers used by multiple modules
        function showModal(content, options = {}) {
            document.getElementById('modalContent').innerHTML = content;
            const modal = document.getElementById('modal');
            modal.classList.add('show');
            modal.dataset.dismissByMask = options.dismissByMask === false ? 'false' : 'true';
            modal.classList.toggle('modal-wide', options.wide === true);
        }

        function hideModal() {
            const modal = document.getElementById('modal');
            modal.classList.remove('show');
            modal.classList.remove('modal-wide');
        }

        // Pagination helper used by multiple modules
        function renderPagination(containerId, pagination, loadFn) {
            const div = document.getElementById(containerId);
            if (!div) return;
            if (!pagination || pagination.pages <= 1) {
                div.innerHTML = '';
                div.style.display = 'none';
                return;
            }
            div.style.display = 'flex';
            div.innerHTML = '';

            const current = pagination.current_page || 1;
            const pages = pagination.pages;
            const loadCallback = typeof loadFn === 'function' ? loadFn : (page) => {
                if (typeof window[loadFn] === 'function') window[loadFn](page);
            };

            function createPageBtn(label, page, disabled, isActive) {
                const btn = document.createElement('button');
                btn.className = 'page-btn' + (isActive ? ' active' : '');
                btn.textContent = label;
                btn.disabled = !!disabled;
                if (!disabled) {
                    btn.addEventListener('click', () => loadCallback(page));
                }
                return btn;
            }

            div.appendChild(createPageBtn('上一页', current - 1, current <= 1));

            for (let i = 1; i <= pages; i++) {
                if (i === 1 || i === pages || (i >= current - 2 && i <= current + 2)) {
                    div.appendChild(createPageBtn(String(i), i, false, i === current));
                } else if (i === current - 3 || i === current + 3) {
                    const span = document.createElement('span');
                    span.className = 'page-ellipsis';
                    span.textContent = '...';
                    div.appendChild(span);
                }
            }

            div.appendChild(createPageBtn('下一页', current + 1, current >= pages));
        }

        // HTML escape helper used by multiple modules
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.getElementById('modal').addEventListener('click', function(e) {
            if (e.target === this && this.dataset.dismissByMask !== 'false') {
                hideModal();
            }
        });

        // 拦截 ESC 键：对于禁止遮罩关闭的弹窗，ESC 也不关闭
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('modal');
                if (modal.classList.contains('show') && modal.dataset.dismissByMask === 'false') {
                    e.preventDefault();
                    e.stopPropagation();
                }
            }
        });

        // Initialize
        // 初始化加载首页数据
        // 支持 ?section=xxx 深链（来自 api_domain.php 等独立子页面的侧边栏跳转），
        // 仅允许白名单内的 section，其余一律回退到 home，保证原有行为不被破坏。
        (function() {
            const validSections = ['home','users','splashPages','systemPrompts','toolSettings','updates','mobileUpdates','hotTopics','webpages','community','email','siteSettings'];
            const requested = new URLSearchParams(window.location.search).get('section');
            switchSection(validSections.indexOf(requested) !== -1 ? requested : 'home');
        })();
        initApiDebugUrls();
    </script>
</body>
</html>
