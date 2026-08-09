            <div id="splashPagesSection" class="content-section">
                <div class="card">
                    <div class="card-header">
                        <h2>启动页管理</h2>
                        <button class="btn-primary" onclick="showAddSplashPageModal()">添加启动页</button>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">排序</th>
                                    <th style="width: 120px;">启动页图片</th>
                                    <th>图片链接</th>
                                    <th>跳转链接</th>
                                    <th style="width: 80px;">状态</th>
                                    <th style="width: 160px;">创建时间</th>
                                    <th style="width: 240px;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="splashPagesTableBody">
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-card">
                        <div class="pagination" id="splashPagination"></div>
                    </div>
                    <div class="card" style="margin-top:20px;">
                        <div class="card-body">
                            <div class="api-debug-panel">
                                <div class="api-debug-header" onclick="toggleApiDebugPanel('splashApiBody')">
                                    <h3 class="api-debug-title">🔌 接口调试</h3>
                                    <span class="api-debug-toggle" id="splashApiToggle">展开 ▼</span>
                                </div>
                                <div class="api-debug-body" id="splashApiBody">
                                    <div class="api-debug-row">
                                        <span class="badge badge-success api-method">GET</span>
                                        <span class="api-path" id="splashApiActiveUrl"></span>
                                        <span class="api-desc">获取启用启动页(免认证)</span>
                                        <button class="btn-secondary btn-sm" onclick="debugApi('splash', 'active')">调试</button>
                                    </div>
                                    <div class="api-debug-row">
                                        <span class="badge badge-success api-method">GET</span>
                                        <span class="api-path" id="splashApiListUrl"></span>
                                        <span class="api-desc">获取全部启动页</span>
                                        <button class="btn-secondary btn-sm" onclick="debugApi('splash', 'list')">调试</button>
                                    </div>
                                    <div class="api-debug-row">
                                        <span class="badge badge-info api-method">POST</span>
                                        <span class="api-path" id="splashApiAddUrl"></span>
                                        <span class="api-desc">添加启动页</span>
                                    </div>
                                    <div class="api-debug-row">
                                        <span class="badge badge-info api-method">POST</span>
                                        <span class="api-path" id="splashApiUpdateUrl"></span>
                                        <span class="api-desc">更新启动页</span>
                                    </div>
                                    <div class="api-debug-row">
                                        <span class="badge badge-info api-method">POST</span>
                                        <span class="api-path" id="splashApiDeleteUrl"></span>
                                        <span class="api-desc">删除启动页</span>
                                    </div>
                                    <div class="api-debug-row">
                                        <span class="badge badge-info api-method">POST</span>
                                        <span class="api-path" id="splashApiToggleUrl"></span>
                                        <span class="api-desc">切换启用/禁用</span>
                                    </div>
                                    <div class="api-debug-result" id="splashApiResult">
                                        <div class="api-debug-result-header">
                                            <span id="splashApiResultTitle">响应结果</span>
                                            <span class="api-debug-result-close" onclick="document.getElementById('splashApiResult').classList.remove('show')">✕</span>
                                        </div>
                                        <div class="api-debug-result-body">
                                            <pre id="splashApiResultBody"></pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

<script>
        async function loadSplashPages(page = 1) {
            try {
                const response = await fetch(`api/splash_pages.php?action=list&page=${page}`, {
                    headers: {
                        'Authorization': `Bearer ${token}`
                    }
                });
                if (!response.ok) {
                    const text = await response.text();
                    let errMsg = '加载启动页列表失败';
                    try { const errData = JSON.parse(text); errMsg = errData.error || errMsg; } catch(ex) { errMsg = text.substring(0, 200) || errMsg; }
                    showAlert('error', errMsg);
                    return;
                }
                const data = await response.json();

                if (data.success) {
                    renderSplashPages(data.data.splash_pages);
                    renderPagination('splashPagination', data.data.pagination ? { ...data.data.pagination, current_page: page } : { pages: 1 }, (p) => loadSplashPages(p));
                } else {
                    showAlert('error', data.error || '加载启动页列表失败');
                }
            } catch (e) {
                showAlert('error', '加载启动页列表失败: ' + e.message);
            }
        }

        function renderSplashPages(pages) {
            const tbody = document.getElementById('splashPagesTableBody');
            if (!pages || pages.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="empty-state">暂无启动页数据</td></tr>';
                return;
            }

            tbody.innerHTML = pages.map(page => `
                <tr>
                    <td>${escapeHtml(String(page.sort_order))}</td>
                    <td>
                        <img src="${escapeHtml(page.image_url)}" alt="启动页图片" style="width: 80px; height: 60px; border-radius: 4px; object-fit: cover; cursor: pointer;" onclick="window.open('${escapeHtml(page.image_url)}', '_blank')" onerror="this.style.display='none'; this.parentElement.innerHTML='<div style=\\'width:80px;height:60px;border-radius:4px;background:var(--border-light);display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:12px;\\'>无图片</div>'">
                    </td>
                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(page.image_url)}">${escapeHtml(page.image_url)}</td>
                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(page.jump_url || '')}">${page.jump_url ? escapeHtml(page.jump_url) : '<span style="color: var(--text-muted);">无</span>'}</td>
                    <td>
                        <span class="badge ${page.is_active == 1 ? 'badge-success' : 'badge-default'}">
                            ${page.is_active == 1 ? '启用' : '禁用'}
                        </span>
                    </td>
                    <td>${escapeHtml(page.created_at || '')}</td>
                    <td>
                        <button class="btn-primary btn-sm" style="margin-right:4px;"
                            onclick="toggleSplashPage(${page.id}, ${page.is_active == 1 ? 0 : 1})">
                            ${page.is_active == 1 ? '禁用' : '启用'}
                        </button>
                        <button class="btn-secondary btn-sm" style="margin-right:4px;"
                            onclick="showEditSplashPageModal(${page.id}, '${escapeHtml(page.image_url).replace(/'/g, "\\'")}', '${escapeHtml(page.jump_url || '').replace(/'/g, "\\'")}', ${page.sort_order}, ${page.is_active})">
                            编辑
                        </button>
                        <button class="btn-danger btn-sm"
                            onclick="deleteSplashPage(${page.id})">
                            删除
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function showAddSplashPageModal() {
            showModal(`
                <div class="modal-header">
                    <h3>添加启动页</h3>
                </div>
                <div class="form-group">
                    <label>启动页图片链接 <span class="text-danger">*</span></label>
                    <input class="form-input" type="text" id="splashImageUrl" placeholder="请输入启动页图片链接">
                    <div id="splashImagePreview" style="margin-top: 8px;"></div>
                </div>
                <div class="form-group">
                    <label>点击跳转链接（可为空）</label>
                    <input class="form-input" type="text" id="splashJumpUrl" placeholder="请输入点击跳转链接">
                </div>
                <div class="form-group">
                    <label>排序（数字越小越靠前）</label>
                    <input class="form-input" type="number" id="splashSortOrder" value="0" min="0">
                </div>
                <div class="form-group" style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" id="splashIsActive" checked>
                    <label for="splashIsActive">启用</label>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="hideModal()">取消</button>
                    <button class="btn-primary" onclick="addSplashPage()">添加</button>
                </div>
            `);

            document.getElementById('splashImageUrl').addEventListener('input', function() {
                const url = this.value.trim();
                const preview = document.getElementById('splashImagePreview');
                if (url) {
                    preview.innerHTML = `<img src="${escapeHtml(url)}" style="max-width: 100%; max-height: 200px; border-radius: 4px; border: 1px solid var(--border);" onerror="this.parentElement.innerHTML='<span style=\\'color:var(--danger-hover);font-size:12px;\\'>图片加载失败</span>'">`;
                } else {
                    preview.innerHTML = '';
                }
            });
        }

        function showEditSplashPageModal(id, imageUrl, jumpUrl, sortOrder, isActive) {
            showModal(`
                <div class="modal-header">
                    <h3>编辑启动页</h3>
                </div>
                <div class="form-group">
                    <label>启动页图片链接 <span class="text-danger">*</span></label>
                    <input class="form-input" type="text" id="editSplashImageUrl" value="${imageUrl}">
                    <div id="editSplashImagePreview" style="margin-top: 8px;">
                        ${imageUrl ? `<img src="${escapeHtml(imageUrl)}" style="max-width: 100%; max-height: 200px; border-radius: 4px; border: 1px solid var(--border);" onerror="this.parentElement.innerHTML=''">` : ''}
                    </div>
                </div>
                <div class="form-group">
                    <label>点击跳转链接（可为空）</label>
                    <input class="form-input" type="text" id="editSplashJumpUrl" value="${jumpUrl}">
                </div>
                <div class="form-group">
                    <label>排序（数字越小越靠前）</label>
                    <input class="form-input" type="number" id="editSplashSortOrder" value="${sortOrder}" min="0">
                </div>
                <div class="form-group" style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" id="editSplashIsActive" ${isActive == 1 ? 'checked' : ''}>
                    <label for="editSplashIsActive">启用</label>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="hideModal()">取消</button>
                    <button class="btn-primary" onclick="updateSplashPage(${id})">保存</button>
                </div>
            `);

            document.getElementById('editSplashImageUrl').addEventListener('input', function() {
                const url = this.value.trim();
                const preview = document.getElementById('editSplashImagePreview');
                if (url) {
                    preview.innerHTML = `<img src="${escapeHtml(url)}" style="max-width: 100%; max-height: 200px; border-radius: 4px; border: 1px solid var(--border);" onerror="this.parentElement.innerHTML='<span style=\\'color:var(--danger-hover);font-size:12px;\\'>图片加载失败</span>'">`;
                } else {
                    preview.innerHTML = '';
                }
            });
        }

        async function addSplashPage() {
            const imageUrl = document.getElementById('splashImageUrl').value.trim();
            const jumpUrl = document.getElementById('splashJumpUrl').value.trim();
            const sortOrder = parseInt(document.getElementById('splashSortOrder').value) || 0;
            const isActive = document.getElementById('splashIsActive').checked ? 1 : 0;

            if (!imageUrl) {
                showAlert('error', '请输入启动页图片链接');
                return;
            }

            try {
                const response = await fetch('api/splash_pages.php?action=add', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ image_url: imageUrl, jump_url: jumpUrl, sort_order: sortOrder, is_active: isActive })
                });

                const data = await response.json();

                if (data.success) {
                    showAlert('success', '启动页添加成功');
                    hideModal();
                    loadSplashPages();
                } else {
                    showAlert('error', data.error || '添加失败');
                }
            } catch (e) {
                showAlert('error', '添加失败: ' + e.message);
            }
        }

        async function updateSplashPage(id) {
            const imageUrl = document.getElementById('editSplashImageUrl').value.trim();
            const jumpUrl = document.getElementById('editSplashJumpUrl').value.trim();
            const sortOrder = parseInt(document.getElementById('editSplashSortOrder').value) || 0;
            const isActive = document.getElementById('editSplashIsActive').checked ? 1 : 0;

            if (!imageUrl) {
                showAlert('error', '请输入启动页图片链接');
                return;
            }

            try {
                const response = await fetch('api/splash_pages.php?action=update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ id, image_url: imageUrl, jump_url: jumpUrl, sort_order: sortOrder, is_active: isActive })
                });

                const data = await response.json();

                if (data.success) {
                    showAlert('success', '启动页更新成功');
                    hideModal();
                    loadSplashPages();
                } else {
                    showAlert('error', data.error || '更新失败');
                }
            } catch (e) {
                showAlert('error', '更新失败: ' + e.message);
            }
        }

        async function deleteSplashPage(id) {
            if (!confirm('确定要删除此启动页吗？')) return;

            try {
                const response = await fetch('api/splash_pages.php?action=delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ id })
                });

                const data = await response.json();

                if (data.success) {
                    showAlert('success', '启动页删除成功');
                    loadSplashPages();
                } else {
                    showAlert('error', data.error || '删除失败');
                }
            } catch (e) {
                showAlert('error', '删除失败: ' + e.message);
            }
        }

        async function toggleSplashPage(id, isActive) {
            try {
                const response = await fetch('api/splash_pages.php?action=toggle', {
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
                    loadSplashPages();
                } else {
                    showAlert('error', data.error || '更新失败');
                }
            } catch (e) {
                showAlert('error', '更新失败: ' + e.message);
            }
        }
</script>
