            <div id="communitySection" class="content-section">
                <div class="stats-grid" style="margin-bottom: 20px;">
                    <div class="stat-card community-stat">
                        <h3>总帖子数</h3>
                        <div class="number" id="communityTotalPosts">0</div>
                    </div>
                    <div class="stat-card community-stat">
                        <h3>今日新帖</h3>
                        <div class="number active" id="communityTodayPosts">0</div>
                    </div>
                    <div class="stat-card community-stat">
                        <h3>总评论数</h3>
                        <div class="number" id="communityTotalComments">0</div>
                    </div>
                    <div class="stat-card community-stat">
                        <h3>待处理举报</h3>
                        <div class="number pending" id="communityPendingReports">0</div></div></div>
                <div class="card">
                    <div class="card-header">
                        <h2>帖子管理</h2>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="communitySearchInput" class="search-input" placeholder="搜索标题/内容/用户...">
                            <button class="btn-secondary" onclick="loadCommunityPosts()">搜索</button>
                            <button class="btn-secondary" onclick="loadCommunityPosts('')">刷新</button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th style="width: 120px;">用户</th>
                                    <th>标题</th>
                                    <th>内容预览</th>
                                    <th style="width: 80px;">点赞</th>
                                    <th style="width: 80px;">评论</th>
                                    <th style="width: 160px;">发布时间</th>
                                    <th style="width: 100px;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="communityTableBody">
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-card">
                        <div class="pagination" id="communityPagination"></div>
                    </div>
                </div>
                <div class="card" style="margin-top:20px;">
                    <div class="card-header">
                        <h2>举报管理</h2>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <select id="reportStatusFilter" class="form-select" onchange="loadCommunityReports()">
                                <option value="">全部</option>
                                <option value="pending">待处理</option>
                                <option value="resolved">已处理</option>
                                <option value="dismissed">已驳回</option>
                            </select>
                            <button class="btn-secondary" onclick="loadCommunityReports()">刷新</button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th style="width: 100px;">举报者</th>
                                    <th style="width: 80px;">类型</th>
                                    <th>举报原因</th>
                                    <th>目标内容</th>
                                    <th style="width: 80px;">状态</th>
                                    <th style="width: 160px;">举报时间</th>
                                    <th style="width: 160px;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="reportsTableBody">
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-card">
                        <div class="pagination" id="reportsPagination"></div>
                    </div>
                </div>

                <div class="card" style="margin-top:20px;">
                    <div class="card-header">
                        <h2>消息推送</h2>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <button class="btn-secondary" onclick="openSendNotificationModal()">发送消息</button>
                            <button class="btn-secondary" onclick="loadNotificationList()">刷新</button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th style="width: 120px;">标题</th>
                                    <th>内容</th>
                                    <th style="width: 100px;">发送对象</th>
                                    <th style="width: 80px;">接收人数</th>
                                    <th style="width: 80px;">已读人数</th>
                                    <th style="width: 150px;">发送时间</th>
                                    <th style="width: 160px;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="notificationTableBody">
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-card">
                        <div class="pagination" id="notificationPagination"></div>
                    </div>
                </div>
            </div>

    <div id="notificationModal" class="modal">
    <div class="modal-content" onclick="event.stopPropagation()">
        <div class="modal-header"><h3 id="notifModalTitle">发送系统消息</h3></div>
        <div class="modal-body">
            <input type="hidden" id="notifEditId" value="">
            <div class="form-group">
                <label>标题（可选）</label>
                <input id="notifTitle" type="text" class="form-input" placeholder="输入消息标题">
            </div>
            <div class="form-group">
                <label>正文 *</label>
                <textarea id="notifContent" rows="4" class="form-textarea" placeholder="输入消息正文"></textarea>
            </div>
            <div class="form-group">
                <label>图片（可选）</label>
                <input id="notifImage" type="text" class="form-input" placeholder="输入图片URL">
            </div>
            <div class="form-group" id="notifTargetWrap">
                <label>发送对象</label>
                <select id="notifTarget" class="form-select"><option value="0">全部用户</option></select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeSendNotificationModal()">取消</button>
            <button id="notifSubmitBtn" class="btn-primary" onclick="submitNotification()">发送</button>
        </div>
    </div>
</div>

<script>
        // ==================== Community Management ====================

        let communityPostPage = 1;

        function loadCommunityStats() {
            fetch('api/community.php?action=stats', {
                headers: { 'Authorization': 'Bearer ' + token }
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    document.getElementById('communityTotalPosts').textContent = res.data.total_posts;
                    document.getElementById('communityTodayPosts').textContent = res.data.today_posts;
                    document.getElementById('communityTotalComments').textContent = res.data.total_comments;
                    document.getElementById('communityPendingReports').textContent = res.data.pending_reports;
                }
            })
            .catch(() => {});
        }

        function loadCommunityPosts(search) {
            if (search === undefined) {
                search = document.getElementById('communitySearchInput').value.trim();
            }
            let url = 'api/community.php?action=list&page=' + communityPostPage;
            if (search) url += '&search=' + encodeURIComponent(search);

            fetch(url, {
                headers: { 'Authorization': 'Bearer ' + token }
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                const data = res.data;
                const tbody = document.getElementById('communityTableBody');
                tbody.innerHTML = '';

                data.posts.forEach(post => {
                    const tr = document.createElement('tr');
                    const title = post.title ? escapeHtml(post.title) : '<span style="color:var(--text-muted)">无标题</span>';
                    const preview = escapeHtml(post.content_preview || '');
                    tr.innerHTML = `
                        <td>${post.id}</td>
                        <td>${escapeHtml(post.real_name || post.username)}</td>
                        <td>${title}</td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${preview}</td>
                        <td>${post.likes_count}</td>
                        <td>${post.comments_count}</td>
                        <td>${post.created_at}</td>
                        <td>
                            <button class="btn-danger btn-sm" onclick="deleteCommunityPost(${post.id})">删除</button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });

                const totalPages = Math.ceil(data.total / data.per_page);
                renderPagination('communityPagination', totalPages > 1 ? { pages: totalPages, current_page: communityPostPage } : { pages: 1 }, (p) => {
                    communityPostPage = p;
                    loadCommunityPosts();
                });
            })
            .catch(() => {});
        }

        function deleteCommunityPost(postId) {
            if (!confirm('确定要删除这篇帖子吗？此操作不可撤销。')) return;

            fetch('api/community.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                body: JSON.stringify({ id: postId })
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showAlert('success', '帖子已删除');
                    loadCommunityPosts();
                    loadCommunityStats();
                } else {
                    showAlert('error', res.error || '删除失败');
                }
            })
            .catch(() => showAlert('error', '网络错误'));
        }

        var reportsCurrentPage = 1;
        var reportsPageSize = 20;

        async function loadCommunityReports(page = 1) {
            reportsCurrentPage = page;
            const status = document.getElementById('reportStatusFilter').value;
            let url = 'api/community.php?action=reports&page=' + page + '&limit=' + reportsPageSize;
            if (status) url += '&status=' + status;

            fetch(url, {
                headers: { 'Authorization': 'Bearer ' + token }
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                const tbody = document.getElementById('reportsTableBody');
                tbody.innerHTML = '';

                const statusMap = { pending: '待处理', reviewed: '审核中', resolved: '已处理', dismissed: '已驳回' };
                const typeMap = { post: '帖子', comment: '评论', user: '用户' };

                let reports = res.data.reports || [];
                let pagination = res.data.pagination;
                if (!pagination && reports.length > reportsPageSize) {
                    const total = reports.length;
                    const pages = Math.ceil(total / reportsPageSize) || 1;
                    if (page > pages) page = pages;
                    reportsCurrentPage = page;
                    const start = (page - 1) * reportsPageSize;
                    reports = reports.slice(start, start + reportsPageSize);
                    pagination = { pages: pages, current_page: page };
                }

                reports.forEach(report => {
                    const tr = document.createElement('tr');
                    const statusText = statusMap[report.status] || report.status;
                    const typeText = typeMap[report.target_type] || report.target_type;
                    let actions = '';
                    if (report.status === 'pending') {
                        actions = `
                            <button class="btn-success btn-sm" onclick="resolveReport(${report.id},'resolved')">处理</button>
                            <button class="btn-danger btn-sm" onclick="resolveReport(${report.id},'dismissed')">驳回</button>
                            <button class="btn-secondary btn-sm" onclick="deleteReport(${report.id})">删除</button>
                        `;
                    } else {
                        actions = `<span style="color:var(--text-muted);font-size:12px;">${statusText}</span> <button class="btn-secondary btn-sm" onclick="deleteReport(${report.id})">删除</button>`;
                    }
                    tr.innerHTML = `
                        <td>${report.id}</td>
                        <td>${escapeHtml(report.reporter_real_name || report.reporter_username || '未知')}</td>
                        <td>${typeText}</td>
                        <td>${escapeHtml(report.reason)}</td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(report.target_content || report.target_title || '')}</td>
                        <td>${statusText}</td>
                        <td>${report.created_at}</td>
                        <td>${actions}</td>
                    `;
                    tbody.appendChild(tr);
                });
                renderPagination('reportsPagination', pagination || { pages: 1 }, loadCommunityReports);
            })
            .catch(() => {});
        }

        function resolveReport(reportId, action) {
            fetch('api/community.php?action=resolve_report', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                body: JSON.stringify({ id: reportId, action: action })
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showAlert('success', '操作成功');
                    loadCommunityReports(reportsCurrentPage);
                    loadCommunityStats();
                } else {
                    showAlert('error', res.error || '操作失败');
                }
            })
            .catch(() => showAlert('error', '网络错误'));
        }

        function deleteReport(reportId) {
            if (!confirm('确定要删除此举报记录吗？')) return;
            fetch('api/community.php?action=delete_report', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                body: JSON.stringify({ id: reportId })
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showAlert('success', '已删除');
                    loadCommunityReports(reportsCurrentPage);
                } else {
                    showAlert('error', res.error || '删除失败');
                }
            })
            .catch(() => showAlert('error', '网络错误'));
        }

        function openSendNotificationModal() {
            document.getElementById('notifEditId').value = '';
            document.getElementById('notifModalTitle').textContent = '发送系统消息';
            document.getElementById('notifSubmitBtn').textContent = '发送';
            document.getElementById('notifTargetWrap').style.display = '';
            document.getElementById('notifTitle').value = '';
            document.getElementById('notifContent').value = '';
            document.getElementById('notifImage').value = '';
            document.getElementById('notificationModal').classList.add('show');
            loadUserListForNotification();
        }

        function openEditNotificationModal(id, title, content, image) {
            document.getElementById('notifEditId').value = id;
            document.getElementById('notifModalTitle').textContent = '编辑消息';
            document.getElementById('notifSubmitBtn').textContent = '保存';
            document.getElementById('notifTargetWrap').style.display = 'none';
            document.getElementById('notifTitle').value = title || '';
            document.getElementById('notifContent').value = content || '';
            document.getElementById('notifImage').value = image || '';
            document.getElementById('notificationModal').classList.add('show');
        }

        function closeSendNotificationModal() {
            document.getElementById('notificationModal').classList.remove('show');
            document.getElementById('notifEditId').value = '';
            document.getElementById('notifTitle').value = '';
            document.getElementById('notifContent').value = '';
            document.getElementById('notifImage').value = '';
        }

        function loadUserListForNotification() {
            fetch('api/users.php?action=list&page=1&per_page=100', {
                headers: { 'Authorization': 'Bearer ' + token }
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                var select = document.getElementById('notifTarget');
                select.innerHTML = '<option value="0">全部用户</option>';
                (res.data.users || []).forEach(function(u) {
                    select.innerHTML += '<option value="' + u.id + '">' + escapeHtml(u.real_name || u.username) + ' (ID:' + u.id + ')</option>';
                });
            })
            .catch(function() {});
        }

        function submitNotification() {
            var editId = document.getElementById('notifEditId').value;
            var title = document.getElementById('notifTitle').value.trim();
            var content = document.getElementById('notifContent').value.trim();
            var image = document.getElementById('notifImage').value.trim();

            if (!content) {
                showAlert('error', '消息正文不能为空');
                return;
            }

            if (editId) {
                fetch('api/community.php?action=update_notification', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ id: parseInt(editId), title: title, content: content, image: image })
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showAlert('success', '消息已更新');
                        closeSendNotificationModal();
                        loadNotificationList();
                    } else {
                        showAlert('error', res.error || '更新失败');
                    }
                })
                .catch(() => showAlert('error', '网络错误'));
            } else {
                var userId = parseInt(document.getElementById('notifTarget').value);
                fetch('api/community.php?action=send_notification', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: JSON.stringify({ title: title, content: content, image: image, user_id: userId })
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showAlert('success', '消息已发送');
                        closeSendNotificationModal();
                        loadNotificationList();
                    } else {
                        showAlert('error', res.error || '发送失败');
                    }
                })
                .catch(() => showAlert('error', '网络错误'));
            }
        }

        var notifPage = 1;
        function loadNotificationList(page) {
            page = page || 1;
            notifPage = page;
            fetch('api/community.php?action=list_notifications&page=' + page, {
                headers: { 'Authorization': 'Bearer ' + token }
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                var tbody = document.getElementById('notificationTableBody');
                tbody.innerHTML = '';
                (res.data.messages || []).forEach(function(m) {
                    var tr = document.createElement('tr');
                    var targetText = m.target_user_id ? (escapeHtml(m.target_user_name || m.target_username || 'ID:' + m.target_user_id)) : '全部用户';
                    var titleText = m.title ? escapeHtml(m.title) : '<span style="color:var(--text-muted)">-</span>';
                    var contentPreview = escapeHtml(m.content).substring(0, 60) + (m.content.length > 60 ? '...' : '');
                    tr.innerHTML = '<td>' + m.id + '</td>' +
                        '<td>' + titleText + '</td>' +
                        '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(m.content) + '">' + contentPreview + '</td>' +
                        '<td>' + targetText + '</td>' +
                        '<td>' + m.recipient_count + '</td>' +
                        '<td>' + m.read_count + '</td>' +
                        '<td>' + m.created_at + '</td>' +
                        '<td>' +
                        '<button class="btn-secondary btn-sm" style="margin-right:4px;" onclick="openEditNotificationModal(' + m.id + ',' + JSON.stringify(m.title || '').replace(/"/g, '&quot;') + ',' + JSON.stringify(m.content).replace(/"/g, '&quot;') + ',' + JSON.stringify(m.image || '').replace(/"/g, '&quot;') + ')">编辑</button>' +
                        '<button class="btn-danger btn-sm" onclick="deleteNotification(' + m.id + ')">删除</button>' +
                        '</td>';
                    tbody.appendChild(tr);
                });

                var totalPages = Math.ceil(res.data.total / res.data.per_page);
                renderPagination('notificationPagination', totalPages > 1 ? { pages: totalPages, current_page: notifPage } : { pages: 1 }, loadNotificationList);
            })
            .catch(function() {});
        }

        function deleteNotification(id) {
            if (!confirm('确定要删除此消息吗？删除后所有用户将无法查看此消息。')) return;
            fetch('api/community.php?action=delete_notification', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                body: JSON.stringify({ id: id })
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showAlert('success', '已删除');
                    loadNotificationList(notifPage);
                } else {
                    showAlert('error', res.error || '删除失败');
                }
            })
            .catch(() => showAlert('error', '网络错误'));
        }

        document.getElementById('communitySearchInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                communityPostPage = 1;
                loadCommunityPosts();
            }
        });
</script>
