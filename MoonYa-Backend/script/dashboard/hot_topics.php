            <div id="hotTopicsSection" class="content-section">
                <div class="card">
                    <div class="card-header">
                        <h2>热点管理</h2>
                        <button class="btn-primary" onclick="showAddTopicModal()">添加热点</button>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">排序</th>
                                    <th>热点内容</th>
                                    <th style="width: 80px;">状态</th>
                                    <th style="width: 160px;">创建时间</th>
                                    <th style="width: 200px;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="hotTopicsTableBody">
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-card">
                        <div class="pagination" id="hotTopicsPagination"></div>
                    </div>
                </div>
            </div>

<script>
        // 热点管理相关函数
        async function loadHotTopics(page = 1) {
            try {
                const response = await fetch(`api/hot_topics.php?page=${page}`, {
                    headers: {
                        'Authorization': `Bearer ${token}`
                    }
                });
                const data = await response.json();

                if (data.success) {
                    renderHotTopics(data.data.topics);
                    renderPagination('hotTopicsPagination', data.data.pagination ? { ...data.data.pagination, current_page: page } : { pages: 1 }, (p) => loadHotTopics(p));
                } else {
                    showAlert('error', data.error || '加载热点失败');
                }
            } catch (e) {
                showAlert('error', '加载热点失败');
            }
        }
        
        function renderHotTopics(topics) {
            const tbody = document.getElementById('hotTopicsTableBody');
            if (!topics || topics.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="empty-state">暂无热点数据</td></tr>';
                return;
            }
            
            tbody.innerHTML = topics.map(topic => `
                <tr>
                    <td>${escapeHtml(String(topic.sort_order))}</td>
                    <td>${escapeHtml(topic.topic)}</td>
                    <td>
                        <span class="badge ${topic.is_active == 1 ? 'badge-success' : 'badge-default'}">
                            ${topic.is_active == 1 ? '启用' : '禁用'}
                        </span>
                    </td>
                    <td>${escapeHtml(topic.created_at || '')}</td>
                    <td>
                        <button class="btn-primary btn-sm" style="margin-right:4px;" 
                            onclick="toggleHotTopic(${topic.id}, ${topic.is_active == 1 ? 0 : 1})">
                            ${topic.is_active == 1 ? '禁用' : '启用'}
                        </button>
                        <button class="btn-secondary btn-sm" style="margin-right:4px;" 
                            onclick="showEditTopicModal(${topic.id}, '${escapeHtml(topic.topic).replace(/'/g, "\\'")}', ${topic.sort_order}, ${topic.is_active})">
                            编辑
                        </button>
                        <button class="btn-danger btn-sm" 
                            onclick="deleteHotTopic(${topic.id})">
                            删除
                        </button>
                    </td>
                </tr>
            `).join('');
        }
        
        function showAddTopicModal() {
            showModal(`
                <div class="modal-header">
                    <h3>添加热点</h3>
                </div>
                <div class="form-group">
                    <label>热点内容</label>
                    <textarea id="newTopicContent" class="form-textarea" placeholder="请输入热点内容"></textarea>
                </div>
                <div class="form-group">
                    <label>排序（数字越小越靠前）</label>
                    <input type="number" id="newTopicOrder" class="form-input" value="0" min="0">
                </div>
                <div class="form-group" style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" id="newTopicActive" checked>
                    <label for="newTopicActive">启用</label>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="hideModal()">取消</button>
                    <button class="btn-primary" onclick="addHotTopic()">添加</button>
                </div>
            `);
        }
        
        function showEditTopicModal(id, topic, sortOrder, isActive) {
            showModal(`
                <div class="modal-header">
                    <h3>编辑热点</h3>
                </div>
                <div class="form-group">
                    <label>热点内容</label>
                    <textarea id="editTopicContent" class="form-textarea">${topic}</textarea>
                </div>
                <div class="form-group">
                    <label>排序（数字越小越靠前）</label>
                    <input type="number" id="editTopicOrder" class="form-input" value="${sortOrder}" min="0">
                </div>
                <div class="form-group" style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" id="editTopicActive" ${isActive == 1 ? 'checked' : ''}>
                    <label for="editTopicActive">启用</label>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="hideModal()">取消</button>
                    <button class="btn-primary" onclick="updateHotTopic(${id})">保存</button>
                </div>
            `);
        }
        
        async function addHotTopic() {
            const topic = document.getElementById('newTopicContent').value.trim();
            const sortOrder = parseInt(document.getElementById('newTopicOrder').value) || 0;
            const isActive = document.getElementById('newTopicActive').checked ? 1 : 0;
            
            if (!topic) {
                showAlert('error', '请输入热点内容');
                return;
            }
            
            try {
                const response = await fetch('api/hot_topics.php?action=add', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ topic, sort_order: sortOrder, is_active: isActive })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', '热点添加成功');
                    hideModal();
                    loadHotTopics();
                } else {
                    showAlert('error', data.error || '添加失败');
                }
            } catch (e) {
                showAlert('error', '添加失败: ' + e.message);
            }
        }
        
        async function updateHotTopic(id) {
            const topic = document.getElementById('editTopicContent').value.trim();
            const sortOrder = parseInt(document.getElementById('editTopicOrder').value) || 0;
            const isActive = document.getElementById('editTopicActive').checked ? 1 : 0;
            
            if (!topic) {
                showAlert('error', '请输入热点内容');
                return;
            }
            
            try {
                const response = await fetch('api/hot_topics.php?action=update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ id, topic, sort_order: sortOrder, is_active: isActive })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', '热点更新成功');
                    hideModal();
                    loadHotTopics();
                } else {
                    showAlert('error', data.error || '更新失败');
                }
            } catch (e) {
                showAlert('error', '更新失败: ' + e.message);
            }
        }
        
        async function deleteHotTopic(id) {
            if (!confirm('确定要删除此热点吗？')) return;
            
            try {
                const response = await fetch('api/hot_topics.php?action=delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ id })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', '热点删除成功');
                    loadHotTopics();
                } else {
                    showAlert('error', data.error || '删除失败');
                }
            } catch (e) {
                showAlert('error', '删除失败: ' + e.message);
            }
        }
        
        async function toggleHotTopic(id, isActive) {
            try {
                const response = await fetch('api/hot_topics.php?action=toggle', {
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
                    loadHotTopics();
                } else {
                    showAlert('error', data.error || '更新失败');
                }
            } catch (e) {
                showAlert('error', '更新失败: ' + e.message);
            }
        }
</script>
