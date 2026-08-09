            <div id="emailSection" class="content-section">
                <h1 class="page-title">📧 邮件发送</h1>
                <div class="card">
                    <div class="card-body">
                        <div class="form-group">
                            <label>收件人</label>
                            <select id="emailRecipientType" class="form-select" onchange="toggleEmailRecipientSelect()">
                                <option value="all">全部用户</option>
                                <option value="selected">指定用户</option>
                            </select>
                        </div>

                        <div class="form-group" id="emailUserSelectWrap" style="display:none;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                <label>选择用户</label>
                                <span class="text-muted">已选 <span id="emailSelectedCount">0</span> 人</span>
                            </div>
                            <input type="text" id="emailUserSearch" class="search-input" placeholder="搜索用户名或邮箱..." oninput="filterEmailUsers()">
                            <div id="emailUserList" class="form-select" style="padding:0;max-height:240px;overflow-y:auto;margin-top:8px;"></div>
                        </div>

                        <div class="form-group">
                            <label>邮件主题</label>
                            <input type="text" id="emailSubject" class="form-input" placeholder="输入邮件主题">
                        </div>

                        <div class="form-group">
                            <label>邮件格式</label>
                            <div style="display:flex;gap:12px;">
                                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:14px;">
                                    <input type="radio" name="emailFormat" value="text" checked> 纯文本
                                </label>
                                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:14px;">
                                    <input type="radio" name="emailFormat" value="html"> HTML
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>邮件内容</label>
                            <textarea id="emailContent" class="form-textarea" placeholder="输入邮件内容..." rows="10"></textarea>
                        </div>

                        <div class="form-group" style="display:flex;gap:12px;justify-content:flex-end;margin-bottom:0;">
                            <button class="btn-secondary" onclick="resetEmailForm()">重置</button>
                            <button id="emailSendBtn" class="btn-primary" onclick="submitEmail()">发送邮件</button>
                        </div>
                    </div>
                    <div class="pagination-card">
                        <div class="pagination" id="emailsPagination"></div>
                    </div>
                </div>
            </div>

    <div id="emailResultModal" class="modal">
    <div class="modal-content" style="text-align:center;">
        <div class="modal-body">
            <div id="emailResultIcon" style="font-size:48px;margin-bottom:16px;color:var(--primary);">✅</div>
            <h3 id="emailResultTitle">发送完成</h3>
            <div id="emailResultBody" style="margin-bottom:24px;color:var(--text-secondary);line-height:1.8;"></div>
            <button class="btn-primary" onclick="closeEmailResultModal()">确定</button>
        </div>
    </div>
</div>

<script>
    var emailAllUsers = [];
    var emailSelectedUserIds = [];
    var emailCurrentPage = 1;
    var emailPageSize = 20;

    async function loadEmailUsers() {
        try {
            var data = await emailApi('api/email.php?action=users', 'GET');
            if (data.success) {
                emailAllUsers = Array.isArray(data.data) ? data.data : (data.data && data.data.users) || [];
                emailCurrentPage = 1;
                renderEmailUserList();
            }
        } catch(e) {
            renderPagination('emailsPagination', { pages: 1 }, null);
        }
    }

    function renderEmailUserList(filter, page) {
        var list = document.getElementById('emailUserList');
        if (typeof filter === 'undefined') {
            filter = document.getElementById('emailUserSearch').value;
        }
        if (typeof page === 'number') {
            emailCurrentPage = page;
        }

        var filtered = emailAllUsers;
        if (filter) {
            var kw = filter.toLowerCase();
            filtered = emailAllUsers.filter(function(u) {
                return (u.username || '').toLowerCase().indexOf(kw) !== -1
                    || (u.email || '').toLowerCase().indexOf(kw) !== -1
                    || (u.real_name || '').toLowerCase().indexOf(kw) !== -1;
            });
        }

        var totalPages = Math.ceil(filtered.length / emailPageSize) || 1;
        if (emailCurrentPage > totalPages) emailCurrentPage = totalPages;
        if (emailCurrentPage < 1) emailCurrentPage = 1;

        var start = (emailCurrentPage - 1) * emailPageSize;
        var pageItems = filtered.slice(start, start + emailPageSize);

        var html = '';
        pageItems.forEach(function(u) {
            var checked = emailSelectedUserIds.indexOf(u.id) !== -1 ? 'checked' : '';
            var name = u.real_name || u.username || u.email;
            html += '<label style="display:flex;align-items:center;gap:10px;padding:10px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border-light);transition:background 0.15s;" onmouseenter="this.style.background=\'var(--primary-light)\'" onmouseleave="this.style.background=\'transparent\'">';
            html += '<input type="checkbox" value="' + u.id + '" ' + checked + ' onchange="toggleEmailUser(' + u.id + ')" style="width:16px;height:16px;cursor:pointer;accent-color:var(--primary);">';
            html += '<div style="flex:1;min-width:0;">';
            html += '<div style=\"font-weight:500;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;\">' + name + '</div>';
            html += '<div style="font-size:12px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + u.email + '</div>';
            html += '</div>';
            html += '</label>';
        });

        if (pageItems.length === 0) {
            html = '<div style="text-align:center;color:var(--text-muted);padding:20px;font-size:13px;">无匹配用户</div>';
        }

        list.innerHTML = html;
        updateEmailSelectedCount();

        renderPagination('emailsPagination', { pages: totalPages, current_page: emailCurrentPage }, function(p) {
            renderEmailUserList(filter, p);
        });
    }

    function filterEmailUsers() {
        emailCurrentPage = 1;
        renderEmailUserList();
    }

    function toggleEmailUser(userId) {
        var idx = emailSelectedUserIds.indexOf(userId);
        if (idx === -1) {
            emailSelectedUserIds.push(userId);
        } else {
            emailSelectedUserIds.splice(idx, 1);
        }
        updateEmailSelectedCount();
    }

    function updateEmailSelectedCount() {
        document.getElementById('emailSelectedCount').textContent = emailSelectedUserIds.length;
    }

    function toggleEmailRecipientSelect() {
        var type = document.getElementById('emailRecipientType').value;
        document.getElementById('emailUserSelectWrap').style.display = type === 'selected' ? 'block' : 'none';
    }

    function resetEmailForm() {
        document.getElementById('emailRecipientType').value = 'all';
        document.getElementById('emailSubject').value = '';
        document.getElementById('emailContent').value = '';
        document.getElementById('emailUserSearch').value = '';
        document.querySelector('input[name="emailFormat"][value="text"]').checked = true;
        document.getElementById('emailUserSelectWrap').style.display = 'none';
        emailSelectedUserIds = [];
        renderEmailUserList();
    }

    async function submitEmail() {
        var subject = document.getElementById('emailSubject').value.trim();
        var content = document.getElementById('emailContent').value.trim();
        var format = document.querySelector('input[name="emailFormat"]:checked').value;
        var recipientType = document.getElementById('emailRecipientType').value;

        if (!subject) { showAlert('error', '请输入邮件主题'); return; }
        if (!content) { showAlert('error', '请输入邮件内容'); return; }
        if (recipientType === 'selected' && emailSelectedUserIds.length === 0) { showAlert('error', '请选择收件人'); return; }

        var btn = document.getElementById('emailSendBtn');
        btn.disabled = true;
        btn.textContent = '发送中...';

        try {
            var body = {
                subject: subject,
                content: content,
                format: format,
                recipient_type: recipientType,
                recipient_ids: recipientType === 'selected' ? emailSelectedUserIds : []
            };

            var data = await emailApi('api/email.php?action=send', 'POST', body);

            if (data.success) {
                showEmailResultModal(data.data);
            } else {
                showAlert('error', data.error || '发送失败');
            }
        } catch(e) {
            showAlert('error', '请求失败: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.textContent = '发送邮件';
        }
    }

    function showEmailResultModal(result) {
        var modal = document.getElementById('emailResultModal');
        var icon = document.getElementById('emailResultIcon');
        var title = document.getElementById('emailResultTitle');
        var body = document.getElementById('emailResultBody');

        if (result.fail === 0) {
            icon.textContent = '✅';
            title.textContent = '发送完成';
            body.innerHTML = '全部发送成功！<br>共发送 <b>' + result.total + '</b> 封邮件';
        } else {
            icon.textContent = '⚠️';
            title.textContent = '发送完成（部分失败）';
            var html = '成功: <b style="color:var(--success-hover);">' + result.success + '</b> / 失败: <b style="color:var(--danger-hover);">' + result.fail + '</b> / 总计: ' + result.total;
            if (result.errors && result.errors.length > 0) {
                html += '<br><br><div style="text-align:left;font-size:12px;color:var(--danger-hover);max-height:120px;overflow-y:auto;">';
                result.errors.forEach(function(err) {
                    html += '<div>' + err + '</div>';
                });
                html += '</div>';
            }
            body.innerHTML = html;
        }

        modal.classList.add('show');
    }

    function closeEmailResultModal() {
        document.getElementById('emailResultModal').classList.remove('show');
    }

    function emailApi(url, method, body) {
        var opts = {
            method: method || 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        };
        if (body && method !== 'GET') {
            opts.body = JSON.stringify(body);
        }
        return fetch(url, opts).then(function(r) { return r.json(); });
    }
</script>
