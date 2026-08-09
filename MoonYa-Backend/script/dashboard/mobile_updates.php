            <div id="mobileUpdatesSection" class="content-section">
                <div class="card">
                    <div class="card-header">
                        <h2>移动端更新管理</h2>
                        <button class="search-btn" onclick="showMobileUpdateModal()">+ 添加移动端版本</button>
                    </div>
                    <div style="padding: 24px;">
                        <div id="mobileUpdatesContainer"></div>
                    </div>
                    <div class="pagination-card">
                        <div class="pagination" id="mobileUpdatesPagination"></div>
                    </div>
                    <div class="card" style="margin-top:20px;">
                        <div class="card-body">
                            <div class="api-debug-panel">
                                <div class="api-debug-header" onclick="toggleApiDebugPanel('mobileApiBody')">
                                    <h3 class="api-debug-title">🔌 接口调试</h3>
                                    <span class="api-debug-toggle" id="mobileApiToggle">展开 ▼</span>
                                </div>
                                <div class="api-debug-body" id="mobileApiBody">
                                    <div class="api-debug-row">
                                        <span class="badge badge-success api-method">GET</span>
                                        <span class="api-path" id="mobileApiLatestUrl"></span>
                                        <span class="api-desc">获取最新版本(免认证)</span>
                                        <button class="btn-secondary btn-sm" onclick="debugApi('mobile', 'latest')">调试</button>
                                    </div>
                                    <div class="api-debug-row">
                                        <span class="badge badge-success api-method">GET</span>
                                        <span class="api-path" id="mobileApiListUrl"></span>
                                        <span class="api-desc">获取全部更新列表</span>
                                        <button class="btn-secondary btn-sm" onclick="debugApi('mobile', 'list')">调试</button>
                                    </div>
                                    <div class="api-debug-row">
                                        <span class="badge badge-info api-method">POST</span>
                                        <span class="api-path" id="mobileApiCreateUrl"></span>
                                        <span class="api-desc">创建更新记录</span>
                                    </div>
                                    <div class="api-debug-row">
                                        <span class="badge badge-info api-method">POST</span>
                                        <span class="api-path" id="mobileApiUpdateUrl"></span>
                                        <span class="api-desc">修改更新记录</span>
                                    </div>
                                    <div class="api-debug-row">
                                        <span class="badge badge-info api-method">POST</span>
                                        <span class="api-path" id="mobileApiDeleteUrl"></span>
                                        <span class="api-desc">删除更新记录</span>
                                    </div>
                                    <div class="api-debug-row">
                                        <span class="badge badge-info api-method">POST</span>
                                        <span class="api-path" id="mobileApiToggleUrl"></span>
                                        <span class="api-desc">切换启用/禁用</span>
                                    </div>
                                    <div class="api-debug-result" id="mobileApiResult">
                                        <div class="api-debug-result-header">
                                            <span id="mobileApiResultTitle">响应结果</span>
                                            <span class="api-debug-result-close" onclick="document.getElementById('mobileApiResult').classList.remove('show')">✕</span>
                                        </div>
                                        <div class="api-debug-result-body">
                                            <pre id="mobileApiResultBody"></pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

<script>
        // ==================== 移动端更新管理相关函数 ====================
        
        let currentEditingMobileUpdateId = null;
        
        async function loadMobileUpdates(page = 1) {
            try {
                const response = await fetch(`api/mobile_updates.php?action=list&page=${page}`, {
                    headers: {
                        'Authorization': `Bearer ${token}`
                    }
                });
                if (!response.ok) {
                    const text = await response.text();
                    let errMsg = '加载移动端更新列表失败';
                    try { const errData = JSON.parse(text); errMsg = errData.error || errMsg; } catch(ex) { errMsg = text.substring(0, 200) || errMsg; }
                    showAlert('error', errMsg);
                    return;
                }
                const data = await response.json();
                
                if (data.success) {
                    const container = document.getElementById('mobileUpdatesContainer');
                    container.innerHTML = '';
                    const updates = data.data.updates || [];

                    if (updates.length === 0) {
                        container.innerHTML = '<div class="empty-state">暂无移动端更新记录</div>';
                        renderPagination('mobileUpdatesPagination', { pages: 1 }, (p) => loadMobileUpdates(p));
                        return;
                    }

                    const table = document.createElement('table');
                    table.className = 'table';
                    table.innerHTML = `
                        <thead>
                            <tr>
                                <th>版本号</th>
                                <th>标题</th>
                                <th>下载链接</th>
                                <th>强制更新</th>
                                <th>状态</th>
                                <th>创建时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${updates.map(update => `
                                <tr data-mu-id="${update.id}" data-mu-version="${encodeURIComponent(update.version)}" data-mu-title="${encodeURIComponent(update.title)}" data-mu-content="${encodeURIComponent(update.content)}" data-mu-download-url="${encodeURIComponent(update.download_url || '')}" data-mu-is-force="${update.is_force}" data-mu-is-active="${update.is_active}">
                                    <td><strong>${escapeHtml(update.version)}</strong></td>
                                    <td>${escapeHtml(update.title)}</td>
                                    <td>${update.download_url ? '<a href="' + escapeHtml(update.download_url) + '" target="_blank" style="color: var(--primary); text-decoration: none; font-size: 12px;">下载链接</a>' : '<span style="color: var(--text-muted);">无</span>'}</td>
                                    <td>${update.is_force == 1 ? '<span class="badge badge-danger">是</span>' : '否'}</td>
                                    <td>${update.is_active == 1 ? '<span class="badge badge-success">启用</span>' : '<span class="badge badge-default">禁用</span>'}</td>
                                    <td>${new Date(update.created_at).toLocaleString()}</td>
                                    <td>
                                        <button class="btn-secondary btn-sm" onclick="editMobileUpdateFromRow(this)">编辑</button>
                                        <button class="btn-secondary btn-sm" onclick="toggleMobileUpdateStatus(${update.id}, ${update.is_active == 1 ? 0 : 1})">${update.is_active == 1 ? '禁用' : '启用'}</button>
                                        <button class="btn-danger btn-sm" onclick="deleteMobileUpdate(${update.id})">删除</button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    `;
                    container.appendChild(table);
                    renderPagination('mobileUpdatesPagination', data.data.pagination ? { ...data.data.pagination, current_page: page } : { pages: 1 }, (p) => loadMobileUpdates(p));
                }
            } catch (e) {
                showAlert('error', '加载移动端更新列表失败: ' + e.message);
            }
        }
        
        function showMobileUpdateModal(id = null, version = '', title = '', content = '', downloadUrl = '', isForce = 0, isActive = 1) {
            currentEditingMobileUpdateId = id;
            const isEdit = id !== null;
            
            showModal(`
                <div class="modal-header">
                    <h3>${isEdit ? '编辑移动端更新' : '添加移动端更新'}</h3>
                </div>
                <div class="modal-body">
                <div class="form-group">
                    <label>版本号 <span class="text-danger">*</span></label>
                    <input class="form-input" type="text" id="mobileUpdateVersion" value="${version}" placeholder="如: 1.2.3" ${isEdit ? 'readonly' : ''}>
                    ${isEdit ? '<small style="color: var(--text-muted);">版本号不可修改</small>' : ''}
                </div>
                <div class="form-group">
                    <label>更新标题 <span class="text-danger">*</span></label>
                    <input class="form-input" type="text" id="mobileUpdateTitle" value="${title}" placeholder="请输入更新标题">
                </div>
                <div class="form-group form-group-full">
                    <label>更新内容 <span class="text-danger">*</span></label>
                    <textarea class="form-textarea" id="mobileUpdateContent" rows="6" placeholder="请输入更新内容，支持HTML标签">${content}</textarea>
                    <small style="color: var(--text-muted);">支持HTML标签，如 &lt;br&gt; 换行、&lt;b&gt; 加粗等</small>
                </div>
                <div class="form-group form-group-full">
                    <label>下载链接 <span class="text-danger">*</span></label>
                    <input class="form-input" type="text" id="mobileUpdateDownloadUrl" value="${downloadUrl}" placeholder="请输入APK/IPA下载链接">
                </div>
                <div class="form-group form-group-inline">
                    <label class="checkbox-label">
                        <input type="checkbox" id="mobileUpdateIsForce" ${isForce == 1 ? 'checked' : ''}>
                        强制更新（用户必须更新后才能使用）
                    </label>
                </div>
                <div class="form-group form-group-inline">
                    <label class="checkbox-label">
                        <input type="checkbox" id="mobileUpdateIsActive" ${isActive == 1 ? 'checked' : ''}>
                        启用此版本更新
                    </label>
                </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="hideModal()">取消</button>
                    <button class="btn-primary" onclick="saveMobileUpdate()">${isEdit ? '保存' : '创建'}</button>
                </div>
            `, { dismissByMask: false, wide: true });
        }
        
        function editMobileUpdateFromRow(button) {
            const row = button.closest('tr');
            const id = parseInt(row.dataset.muId);
            const version = decodeURIComponent(row.dataset.muVersion);
            const title = decodeURIComponent(row.dataset.muTitle);
            const content = decodeURIComponent(row.dataset.muContent);
            const downloadUrl = decodeURIComponent(row.dataset.muDownloadUrl || '');
            const isForce = parseInt(row.dataset.muIsForce);
            const isActive = parseInt(row.dataset.muIsActive);
            showMobileUpdateModal(id, version, title, content, downloadUrl, isForce, isActive);
        }
        
        async function saveMobileUpdate() {
            const version = document.getElementById('mobileUpdateVersion').value.trim();
            const title = document.getElementById('mobileUpdateTitle').value.trim();
            const content = document.getElementById('mobileUpdateContent').value.trim();
            const downloadUrl = document.getElementById('mobileUpdateDownloadUrl').value.trim();
            const isForce = document.getElementById('mobileUpdateIsForce').checked ? 1 : 0;
            const isActive = document.getElementById('mobileUpdateIsActive').checked ? 1 : 0;
            
            if (!version || !title || !content) {
                showAlert('error', '版本号、标题和内容不能为空');
                return;
            }
            
            if (!/^\d+(\.\d+)*$/.test(version)) {
                showAlert('error', '版本号格式不正确，应为如 1.2.3 的格式');
                return;
            }
            
            try {
                const url = currentEditingMobileUpdateId
                    ? `api/mobile_updates.php?action=update`
                    : 'api/mobile_updates.php?action=create';
                
                const body = currentEditingMobileUpdateId
                    ? { id: currentEditingMobileUpdateId, version, title, content, download_url: downloadUrl, is_force: isForce, is_active: isActive }
                    : { version, title, content, download_url: downloadUrl, is_force: isForce, is_active: isActive };
                
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
                    showAlert('success', currentEditingMobileUpdateId ? '移动端更新保存成功' : '移动端更新创建成功');
                    hideModal();
                    loadMobileUpdates();
                } else {
                    showAlert('error', data.error || data.message || '操作失败');
                }
            } catch (e) {
                console.error('保存失败:', e);
                showAlert('error', '保存失败: ' + e.message);
            }
        }
        
        async function deleteMobileUpdate(id) {
            if (!confirm('确定要删除这个移动端更新吗？')) {
                return;
            }
            
            try {
                const response = await fetch('api/mobile_updates.php?action=delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ id })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', '移动端更新删除成功');
                    loadMobileUpdates();
                } else {
                    showAlert('error', data.error || data.message || '删除失败');
                }
            } catch (e) {
                console.error('删除失败:', e);
                showAlert('error', '删除失败: ' + e.message);
            }
        }
        
        async function toggleMobileUpdateStatus(id, isActive) {
            try {
                const response = await fetch('api/mobile_updates.php?action=toggle', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ id, is_active: isActive })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', '状态更新成功');
                    loadMobileUpdates();
                } else {
                    showAlert('error', data.error || '更新失败');
                }
            } catch (e) {
                showAlert('error', '更新失败: ' + e.message);
            }
        }
</script>
