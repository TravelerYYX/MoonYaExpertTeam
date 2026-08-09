<?php
// API 域名配置 — 独立后台子页面
// 复用 admin 后台布局（sidebar + topbar + main-content），通过 fetch 调用 api/api_domain.php
// 鉴权方式与 dashboard.php / login.php 一致：localStorage.adminToken + Authorization: Bearer
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API 域名配置 - MoonYa Admin</title>
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
                <a class="nav-item" href="dashboard.php?section=home">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v18H3V3zm16 16V5H5v14h14z" /></svg></span>
                    <span>首页</span>
                </a>
                <a class="nav-item" href="dashboard.php?section=users">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 7a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm10 8v2M22 11v2" /></svg></span>
                    <span>用户</span>
                </a>
                <a class="nav-item" href="dashboard.php?section=splashPages">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v18H3V3zm16 16V5H5v14h14zM7 7h10v2H7zm0 4h10v2H7z" /></svg></span>
                    <span>启动页</span>
                </a>
                <a class="nav-item" href="dashboard.php?section=systemPrompts">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v4H4zM4 10h10v4H4zM4 16h16v4H4z" /></svg></span>
                    <span>系统提示词</span>
                </a>
                <a class="nav-item" href="dashboard.php?section=toolSettings">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.3-3.3a1 1 0 0 0 0-1.4l-1.6-1.6a1 1 0 0 0-1.4 0L14.7 6.3zM21 12h-2M3 12h2M12 3v2M12 21v2M7.05 7.05l1.41 1.41M16.95 16.95l1.41 1.41M7.05 16.95l1.41-1.41M16.95 7.05l1.41-1.41" /></svg></span>
                    <span>工具</span>
                </a>
                <a class="nav-item" href="dashboard.php?section=updates">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M5 5l2.8 2.8M17 5l-2.8 2.8M12 13a6 6 0 1 0 0 12 6 6 0 0 0 0-12z" /></svg></span>
                    <span>版本</span>
                </a>
                <a class="nav-item" href="dashboard.php?section=mobileUpdates">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm5 18a1 1 0 1 0 0-2 1 1 0 0 0 0 2z" /></svg></span>
                    <span>移动端</span>
                </a>
                <a class="nav-item" href="dashboard.php?section=hotTopics">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-4-3-4-3 0 2 1 4.5 1 4.5M12 14.5a2.5 2.5 0 0 0 2.5-2.5c0-1.38-.5-2-1-3-1.072-2.143-4-3-4-3 0 2 1 4.5 1 4.5M12 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z" /></svg></span>
                    <span>热点</span>
                </a>
                <a class="nav-item" href="dashboard.php?section=webpages">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" /></svg></span>
                    <span>网页</span>
                </a>
                <a class="nav-item" href="dashboard.php?section=community">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg></span>
                    <span>社区</span>
                </a>
                <a class="nav-item" href="dashboard.php?section=email">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 7l-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7M2 7v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7" /></svg></span>
                    <span>邮件</span>
                </a>
                <a class="nav-item" href="dashboard.php?section=siteSettings">
                    <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
                    <span>搜索设置</span>
                </a>
                <a class="nav-item active" href="api_domain.php">
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
                    <h1 class="topbar-title">API 域名配置</h1>
                </div>
                <div class="topbar-actions">
                    <span class="text-secondary" id="adminInfo">Admin</span>
                    <button class="btn-ghost" onclick="logout()">退出</button>
                </div>
            </header>

            <main class="main-content">
                <div class="card" style="max-width:720px;">
                    <div class="card-header">
                        <h2>API 域名配置</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary" style="margin-bottom:20px;">
                            配置主 API 与 Python 服务的访问域名。修改后立即生效，无需重启服务。系统会自动为非空域名补全尾部 <code>/</code>，两域名互相独立。
                        </p>

                        <div class="form-group">
                            <label for="mainApiDomain">主 API 域名</label>
                            <input type="text" id="mainApiDomain" class="form-input" placeholder="https://your-domain.com" style="max-width:520px;" autocomplete="off">
                            <p class="text-secondary" style="font-size:13px;margin-top:6px;">所有 API 请求将使用此域名。系统自动补全尾部 / 后缀，无需手动输入。</p>
                        </div>

                        <div class="form-group">
                            <label for="pythonServiceDomain">Python 服务域名</label>
                            <input type="text" id="pythonServiceDomain" class="form-input" placeholder="请输入 Python 服务地址" style="max-width:520px;" autocomplete="off">
                            <p class="text-secondary" style="font-size:13px;margin-top:6px;">Python 爬虫/搜索服务独立域名，与主 API 域名互不影响。</p>
                        </div>

                        <div style="margin-top:8px;">
                            <button id="saveBtn" class="btn-primary" onclick="saveConfig()">保存配置</button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

<script>
        // 鉴权：与 dashboard.php / login.php 一致，从 localStorage 读取 adminToken
        let token = localStorage.getItem('adminToken');
        let adminInfo = JSON.parse(localStorage.getItem('adminInfo') || '{}');

        if (!token) {
            window.location.href = 'login.php';
        }

        document.getElementById('adminInfo').textContent = `欢迎，${adminInfo.username || 'Admin'}`;

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

        // URL 预校验：空值允许；非空值必须以 http:// 或 https:// 开头（与后端 api_domain.php 规则一致）
        function isValidDomain(value) {
            if (value === '' || value === null || value === undefined) return true;
            return value.indexOf('http://') === 0 || value.indexOf('https://') === 0;
        }

        // 页面加载时：GET 拉取当前配置填充输入框
        async function loadConfig() {
            try {
                const response = await fetch('api/api_domain.php', {
                    headers: { 'Authorization': `Bearer ${token}` }
                });
                const data = await response.json();
                if (data.success) {
                    document.getElementById('mainApiDomain').value = (data.data && data.data.main_api_domain) || '';
                    document.getElementById('pythonServiceDomain').value = (data.data && data.data.python_service_domain) || '';
                } else {
                    showAlert('error', data.error || '加载配置失败');
                }
            } catch (e) {
                showAlert('error', '加载配置失败: ' + e.message);
            }
        }

        // 保存配置：前端预校验 -> POST update
        async function saveConfig() {
            const mainInput = document.getElementById('mainApiDomain');
            const pythonInput = document.getElementById('pythonServiceDomain');
            const main = mainInput.value.trim();
            const python = pythonInput.value.trim();

            // 前端预校验：非空值必须以 http:// 或 https:// 开头
            if (!isValidDomain(main) || !isValidDomain(python)) {
                alert('域名必须以 http:// 或 https:// 开头');
                return;
            }

            const btn = document.getElementById('saveBtn');
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = '保存中...';

            try {
                const response = await fetch('api/api_domain.php?action=update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({
                        main_api_domain: main,
                        python_service_domain: python
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showAlert('success', data.message || '已保存，实时生效');
                    // 重新拉取以回显后端自动补全尾部 / 之后的归一化值
                    loadConfig();
                } else {
                    showAlert('error', data.error || '保存失败');
                }
            } catch (e) {
                showAlert('error', '保存失败: ' + e.message);
            } finally {
                btn.disabled = false;
                btn.textContent = originalText;
            }
        }

        // 初始化：拉取当前配置
        loadConfig();
</script>
</body>
</html>
