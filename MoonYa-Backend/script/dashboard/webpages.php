            <div id="webpagesSection" class="content-section">
                <div class="card">
                    <div class="card-header">
                        <h2>网页管理</h2>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="webpageSearchInput" class="search-input" placeholder="搜索标题...">
                            <button class="btn-secondary" onclick="loadWebpages()">刷新</button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th style="width: 120px;">用户</th>
                                    <th>网页标题</th>
                                    <th style="width: 160px;">创建时间</th>
                                    <th style="width: 200px;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="webpagesTableBody">
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-card">
                        <div class="pagination" id="webpagesPagination"></div>
                    </div>
                </div>
            </div>

<script>
        let webpagePage = 1;
        const webpagePageSize = 10;
        
        async function loadWebpages(page) {
            if (page) webpagePage = page;
            const search = document.getElementById('webpageSearchInput') ? document.getElementById('webpageSearchInput').value.trim() : '';
            
            try {
                let url = `api/webpages.php?page=${webpagePage}&page_size=${webpagePageSize}`;
                if (search) url += `&search=${encodeURIComponent(search)}`;
                
                const response = await fetch(url, {
                    headers: { 'Authorization': `Bearer ${token}` }
                });
                const data = await response.json();
                
                if (data.success) {
                    renderWebpages(data.data.webpages);
                    const totalPages = Math.ceil(data.data.total / webpagePageSize);
                    renderPagination('webpagesPagination', totalPages > 1 ? { pages: totalPages, current_page: webpagePage } : { pages: 1 }, (p) => loadWebpages(p));
                } else {
                    showAlert('error', data.error || '加载网页失败');
                }
            } catch (e) {
                showAlert('error', '加载网页失败');
            }
        }
        
        function renderWebpages(webpages) {
            const tbody = document.getElementById('webpagesTableBody');
            if (!webpages || webpages.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="empty-state">暂无网页数据</td></tr>';
                return;
            }
            
            tbody.innerHTML = webpages.map(wp => `
                <tr>
                    <td>${wp.id}</td>
                    <td>${escapeHtml(wp.username || '未知用户')}</td>
                    <td>${escapeHtml(wp.title || '未命名网页')}</td>
                    <td>${escapeHtml(wp.created_at || '')}</td>
                    <td>
                        <button class="btn-primary btn-sm" style="margin-right:4px;" 
                            onclick="previewWebpage('${wp.preview_token}')">
                            预览
                        </button>
                        <button class="btn-secondary btn-sm" style="margin-right:4px;" 
                            onclick="viewWebpageCode(${wp.id})">
                            查看代码
                        </button>
                        <button class="btn-danger btn-sm" 
                            onclick="deleteWebpage(${wp.id})">
                            删除
                        </button>
                    </td>
                </tr>
            `).join('');
        }
        
        function previewWebpage(previewToken) {
            window.open('../webpage_preview.php?token=' + previewToken, '_blank');
        }
        
        async function viewWebpageCode(id) {
            try {
                const response = await fetch(`api/webpages.php?action=get&id=${id}`, {
                    headers: { 'Authorization': `Bearer ${token}` }
                });
                const data = await response.json();
                
                if (data.success) {
                    showModal(`
                        <div class="modal-header">
                            <h3>${escapeHtml(data.data.webpage.title || '未命名网页')}</h3>
                        </div>
                        <div class="modal-body" style="padding: 20px; max-height: 70vh; overflow: auto;">
                            <pre style="background: var(--border-light); padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 13px; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word;">${escapeHtml(data.data.webpage.html_code)}</pre>
                        </div>
                        <div class="modal-footer">
                            <button class="btn-secondary" onclick="hideModal()">关闭</button>
                            <button class="btn-primary" onclick="copyWebpageCode(${id})">复制代码</button>
                        </div>
                    `);
                } else {
                    showAlert('error', data.error || '获取网页失败');
                }
            } catch (e) {
                showAlert('error', '获取网页失败');
            }
        }
        
        async function copyWebpageCode(id) {
            try {
                const response = await fetch(`api/webpages.php?action=get&id=${id}`, {
                    headers: { 'Authorization': `Bearer ${token}` }
                });
                const data = await response.json();
                
                if (data.success) {
                    navigator.clipboard.writeText(data.data.webpage.html_code).then(() => {
                        showAlert('success', '代码已复制到剪贴板');
                    }).catch(() => {
                        showAlert('error', '复制失败');
                    });
                }
            } catch (e) {
                showAlert('error', '复制失败');
            }
        }
        
        async function deleteWebpage(id) {
            if (!confirm('确定要删除此网页吗？')) return;
            
            try {
                const response = await fetch('api/webpages.php?action=delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ id })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', '网页删除成功');
                    loadWebpages();
                } else {
                    showAlert('error', data.error || '删除失败');
                }
            } catch (e) {
                showAlert('error', '删除失败: ' + e.message);
            }
        }
        document.getElementById('webpageSearchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                webpagePage = 1;
                loadWebpages();
            }
        });
</script>
