                <div id="homeSection" class="content-section active">
            <h1 class="page-title">Dashboard</h1>
            <div class="welcome-banner">
                <div class="welcome-banner-content">
                    <h1 id="welcomeTitle">欢迎回来，Admin</h1>
                    <p id="welcomeDate">祝您管理愉快。</p>
                </div>
                <div class="welcome-banner-decor">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4"/><path d="m5 5 2.8 2.8"/><path d="m17 5-2.8 2.8"/><circle cx="12" cy="13" r="6"/></svg>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                    <div>
                        <div class="stat-value" id="totalUsers">0</div>
                        <div class="stat-label">总用户数</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon success"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
                    <div>
                        <div class="stat-value" id="activeUsers">0</div>
                        <div class="stat-label">活跃用户数</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon danger"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
                    <div>
                        <div class="stat-value" id="bannedUsers">0</div>
                        <div class="stat-label">封禁用户数</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon warning"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></div>
                    <div>
                        <div class="stat-value" id="totalWebpages">0</div>
                        <div class="stat-label">网页总数</div>
                    </div>
                </div>
            </div>
            <div class="card" style="margin-bottom:24px;">
                <div class="card-header"><h2>快捷操作</h2></div>
                <div class="card-body">
                    <div class="quick-actions">
                        <div class="quick-action-item" onclick="switchSection('users')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <span>用户管理</span>
                        </div>
                        <div class="quick-action-item" onclick="switchSection('community')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <span>社区管理</span>
                        </div>
                        <div class="quick-action-item" onclick="switchSection('email')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            <span>邮件发送</span>
                        </div>
                        <div class="quick-action-item" onclick="switchSection('splashPages')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                            <span>启动页</span>
                        </div>
                        <div class="quick-action-item" onclick="switchSection('webpages')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            <span>网页管理</span>
                        </div>
                        <div class="quick-action-item" onclick="switchSection('hotTopics')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-4-3-4-3 0 2 1 4.5 1 4.5"/><path d="M12 14.5a2.5 2.5 0 0 0 2.5-2.5c0-1.38-.5-2-1-3-1.072-2.143-4-3-4-3 0 2 1 4.5 1 4.5"/><circle cx="12" cy="19" r="2"/></svg>
                            <span>热点管理</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2>最近动态</h2></div>
                <div class="card-body">
                    <div class="empty-state" id="homeRecentActivity">加载中...</div>
                </div>
            </div>
        </div>

<script>
        async function loadStats() {
            const data = await apiRequest('?limit=1000');
            if (data.success) {
                const users = data.data.users;
                document.getElementById('totalUsers').textContent = data.data.pagination.total;
                document.getElementById('activeUsers').textContent = users.filter(u => u.status === 'active').length;
                document.getElementById('bannedUsers').textContent = users.filter(u => u.status === 'banned').length;
            }

            try {
                const webpageResponse = await fetch('api/webpages.php?page=1&page_size=1', {
                    headers: { 'Authorization': `Bearer ${token}` }
                });
                const webpageData = await webpageResponse.json();

                if (webpageData.success) {
                    document.getElementById('totalWebpages').textContent = webpageData.data.total;
                }
            } catch (e) {
                console.error('加载网页统计失败:', e);
            }
        }
</script>
