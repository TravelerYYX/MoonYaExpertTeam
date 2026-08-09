            <div id="updatesSection" class="content-section">
                <div class="card">
                    <div class="card-header">
                        <h2>版本更新管理</h2>
                        <button class="search-btn" onclick="showUpdateModal()">+ 添加新版本</button>
                    </div>
                    <div style="padding: 24px;">
                        <div id="updatesContainer"></div>
                    </div>
                    <div class="pagination-card">
                        <div class="pagination" id="updatesPagination"></div>
                    </div>
                </div>
            </div>

<script>
        // 版本更新管理相关函数
        let currentEditingUpdateId = null;
        
        async function loadUpdates(page = 1) {
            try {
                const response = await fetch(`api/updates.php?action=list&page=${page}`, {
                    headers: {
                        'Authorization': `Bearer ${token}`
                    }
                });
                const data = await response.json();
                
                if (data.success) {
                    const container = document.getElementById('updatesContainer');
                    container.innerHTML = '';
                    const updates = Array.isArray(data.data) ? data.data : (data.data.updates || []);

                    if (updates.length === 0) {
                        container.innerHTML = '<div class="empty-state">暂无版本更新记录</div>';
                        renderPagination('updatesPagination', { pages: 1 }, (p) => loadUpdates(p));
                        return;
                    }

                    const table = document.createElement('table');
                    table.className = 'table';
                    table.innerHTML = `
                        <thead>
                            <tr>
                                <th>版本号</th>
                                <th>标题</th>
                                <th>媒体</th>
                                <th>强制显示</th>
                                <th>关闭延迟(秒)</th>
                                <th>状态</th>
                                <th>创建时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${updates.map(update => `
                                <tr data-update-id="${update.id}" data-update-version="${encodeURIComponent(update.version)}" data-update-title="${encodeURIComponent(update.title)}" data-update-content="${encodeURIComponent(update.content)}" data-update-video-url="${encodeURIComponent(update.video_url || '')}" data-update-image-url="${encodeURIComponent(update.image_url || '')}" data-update-is-force="${update.is_force}" data-update-is-active="${update.is_active}" data-update-close-delay="${update.close_delay || 0}">
                                    <td><strong>${update.version}</strong></td>
                                    <td>${update.title}</td>
                                    <td>${update.video_url ? '<span class="badge badge-info">视频</span>' : (update.image_url ? '<span class="badge badge-success">图片</span>' : '<span style="color: var(--text-muted);">默认</span>')}</td>
                                    <td>${update.is_force == 1 ? '<span class="badge badge-danger">是</span>' : '否'}</td>
                                    <td>${update.close_delay ? update.close_delay + '秒' : '<span style="color: var(--text-muted);">无限制</span>'}</td>
                                    <td>${update.is_active == 1 ? '<span class="badge badge-success">启用</span>' : '<span class="badge badge-default">禁用</span>'}</td>
                                    <td>${new Date(update.created_at).toLocaleString()}</td>
                                    <td>
                                        <button class="btn-secondary btn-sm" onclick="editUpdateFromRow(this)">编辑</button>
                                        <button class="btn-danger btn-sm" onclick="deleteUpdate(${update.id})">删除</button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    `;
                    container.appendChild(table);
                    renderPagination('updatesPagination', data.data.pagination ? { ...data.data.pagination, current_page: page } : { pages: 1 }, (p) => loadUpdates(p));
                }
            } catch (e) {
                showAlert('error', '加载版本更新列表失败');
            }
        }
        
        function showUpdateModal(id = null, version = '', title = '', content = '', videoUrl = '', imageUrl = '', isForce = 0, isActive = 1, closeDelay = 0) {
            currentEditingUpdateId = id;
            const isEdit = id !== null;
            
            showModal(`
                <div class="modal-header">
                    <h3>${isEdit ? '编辑版本更新' : '添加新版本'}</h3>
                </div>
                <div class="modal-body">
                <div class="form-group">
                    <label>版本号 <span class="text-danger">*</span></label>
                    <input class="form-input" type="text" id="updateVersion" value="${version}" placeholder="如: 1.2.3" ${isEdit ? 'readonly' : ''}>
                    ${isEdit ? '<small style="color: var(--text-muted);">版本号不可修改</small>' : ''}
                </div>
                <div class="form-group">
                    <label>更新标题 <span class="text-danger">*</span></label>
                    <input class="form-input" type="text" id="updateTitle" value="${title}" placeholder="请输入更新标题">
                </div>
                <div class="form-group">
                    <label>更新内容 <span class="text-danger">*</span></label>
                    <textarea class="form-textarea" id="updateContent" rows="6" placeholder="请输入更新内容，支持HTML标签">${content}</textarea>
                    <small style="color: var(--text-muted);">支持HTML标签，如 &lt;br&gt; 换行、&lt;b&gt; 加粗等</small>
                </div>
                <div class="form-group">
                    <label>视频链接</label>
                    <input class="form-input" type="text" id="updateVideoUrl" value="${videoUrl}" placeholder="填写视频链接（与图片链接二选一）" oninput="handleMediaUrlInput('video')">
                    <div class="media-preview" id="updateVideoPreview"></div>
                </div>
                <div class="form-group">
                    <label>图片链接</label>
                    <div class="input-with-action">
                        <input class="form-input" type="text" id="updateImageUrl" value="${imageUrl}"
                               placeholder="填写图片链接（与视频链接二选一）"
                               oninput="handleMediaUrlInput('image')">
                        <button type="button" class="btn-upload-trigger" onclick="showUploadModal()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            上传
                        </button>
                    </div>
                    <div class="media-preview" id="updateImagePreview"></div>
                    <small style="color: var(--text-muted);">视频链接与图片链接只能填写一个；都不填则使用默认图片</small>
                </div>
                <div class="form-group">
                    <label>关闭延迟（秒）</label>
                    <input class="form-input" type="number" id="updateCloseDelay" value="${closeDelay}" placeholder="填写秒数，不填则用户可直接关闭" min="0" style="width: 100%;">
                    <small style="color: var(--text-muted);">设置用户需等待多少秒后才能关闭更新弹窗，留空或填 0 则无限制</small>
                </div>
                <div class="form-group form-group-inline">
                    <label class="checkbox-label">
                        <input type="checkbox" id="updateIsForce" ${isForce == 1 ? 'checked' : ''}>
                        强制显示（用户关闭后仍会显示）
                    </label>
                </div>
                <div class="form-group form-group-inline">
                    <label class="checkbox-label">
                        <input type="checkbox" id="updateIsActive" ${isActive == 1 ? 'checked' : ''}>
                        启用此版本更新
                    </label>
                </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="hideModal()">取消</button>
                    <button class="btn-primary" onclick="saveUpdate()">${isEdit ? '保存' : '创建'}</button>
                </div>
            `, { dismissByMask: false, wide: true });
            // 渲染时回填预览
            if (videoUrl) handleMediaUrlInput('video');
            else if (imageUrl) handleMediaUrlInput('image');
        }
        
        function handleMediaUrlInput(type) {
            const videoInput = document.getElementById('updateVideoUrl');
            const imageInput = document.getElementById('updateImageUrl');
            const videoPreview = document.getElementById('updateVideoPreview');
            const imagePreview = document.getElementById('updateImagePreview');

            // 渲染当前类型预览
            function renderPreview(targetInput, previewEl, kind) {
                const url = targetInput.value.trim();
                if (!url) {
                    previewEl.innerHTML = '';
                    return;
                }
                if (kind === 'video') {
                    previewEl.innerHTML = `<video src="${escapeHtml(url)}" controls preload="metadata" style="max-width:100%; max-height:200px; border-radius:6px; background:#000;"></video>`;
                } else {
                    previewEl.innerHTML = `<img src="${escapeHtml(url)}" alt="预览" onerror="this.parentNode.innerHTML='<div class=&quot;preview-error&quot;>图片加载失败</div>'" style="max-width:100%; max-height:200px; border-radius:6px; object-fit:contain; background:#f3f4f6;">`;
                }
            }

            if (type === 'video') {
                renderPreview(videoInput, videoPreview, 'video');
                if (videoInput.value.trim()) {
                    imageInput.value = '';
                    imageInput.disabled = true;
                    imageInput.style.opacity = '0.5';
                    imagePreview.innerHTML = '';
                } else {
                    imageInput.disabled = false;
                    imageInput.style.opacity = '1';
                }
            } else {
                renderPreview(imageInput, imagePreview, 'image');
                if (imageInput.value.trim()) {
                    videoInput.value = '';
                    videoInput.disabled = true;
                    videoInput.style.opacity = '0.5';
                    videoPreview.innerHTML = '';
                } else {
                    videoInput.disabled = false;
                    videoInput.style.opacity = '1';
                }
            }
        }
        
        function editUpdate(id, version, title, content, videoUrl, imageUrl, isForce, isActive, closeDelay) {
            showUpdateModal(id, version, title, content, videoUrl, imageUrl, isForce, isActive, closeDelay);
        }

        function editUpdateFromRow(button) {
            const row = button.closest('tr');
            const id = parseInt(row.dataset.updateId);
            const version = decodeURIComponent(row.dataset.updateVersion);
            const title = decodeURIComponent(row.dataset.updateTitle);
            const content = decodeURIComponent(row.dataset.updateContent);
            const videoUrl = decodeURIComponent(row.dataset.updateVideoUrl || '');
            const imageUrl = decodeURIComponent(row.dataset.updateImageUrl || '');
            const isForce = parseInt(row.dataset.updateIsForce);
            const isActive = parseInt(row.dataset.updateIsActive);
            const closeDelay = parseInt(row.dataset.updateCloseDelay) || 0;
            editUpdate(id, version, title, content, videoUrl, imageUrl, isForce, isActive, closeDelay);
        }
        
        async function saveUpdate() {
            const version = document.getElementById('updateVersion').value.trim();
            const title = document.getElementById('updateTitle').value.trim();
            const content = document.getElementById('updateContent').value.trim();
            const videoUrl = document.getElementById('updateVideoUrl').value.trim();
            const imageUrl = document.getElementById('updateImageUrl').value.trim();
            const isForce = document.getElementById('updateIsForce').checked ? 1 : 0;
            const isActive = document.getElementById('updateIsActive').checked ? 1 : 0;
            const closeDelay = parseInt(document.getElementById('updateCloseDelay').value) || 0;
            
            if (!version || !title || !content) {
                showAlert('error', '版本号、标题和内容不能为空');
                return;
            }
            
            if (!/^\d+(\.\d+)*$/.test(version)) {
                showAlert('error', '版本号格式不正确，应为如 1.2.3 的格式');
                return;
            }
            
            if (videoUrl && imageUrl) {
                showAlert('error', '视频链接和图片链接只能填写一个');
                return;
            }
            
            try {
                const url = currentEditingUpdateId 
                    ? `api/updates.php?action=update&id=${currentEditingUpdateId}`
                    : 'api/updates.php?action=create';
                
                const body = currentEditingUpdateId
                    ? { id: currentEditingUpdateId, version, title, content, video_url: videoUrl, image_url: imageUrl, is_force: isForce, is_active: isActive, close_delay: closeDelay }
                    : { version, title, content, video_url: videoUrl, image_url: imageUrl, is_force: isForce, is_active: isActive, close_delay: closeDelay };
                
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
                    showAlert('success', currentEditingUpdateId ? '版本更新保存成功' : '版本更新创建成功');
                    hideModal();
                    loadUpdates();
                } else {
                    showAlert('error', data.message || '操作失败');
                }
            } catch (e) {
                console.error('保存失败:', e);
                showAlert('error', '保存失败: ' + e.message);
            }
        }
        
        async function deleteUpdate(id) {
            if (!confirm('确定要删除这个版本更新吗？')) {
                return;
            }
            
            try {
                const response = await fetch('api/updates.php?action=delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ id })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', '版本更新删除成功');
                    loadUpdates();
                } else {
                    showAlert('error', data.message || '删除失败');
                }
            } catch (e) {
                console.error('删除失败:', e);
                showAlert('error', '删除失败: ' + e.message);
            }
        }

        // ========== 图片上传弹窗 ==========
        let uploadSelectedFile = null;

        function showUploadModal() {
            uploadSelectedFile = null;
            const uploadModalHTML = `
                <div class="modal-header">
                    <h3>图片上传</h3>
                </div>
                <div class="modal-body">
                    <div class="upload-layout">
                        <div class="upload-preview-area">
                            <div class="upload-drop-zone" id="uploadDropZone" onclick="document.getElementById('uploadFileInput').click()">
                                <div class="upload-drop-zone-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                </div>
                                <div class="upload-drop-zone-text">点击选择图片或拖拽到此处</div>
                                <div class="upload-drop-zone-hint">支持 JPG / PNG / GIF / WEBP / BMP</div>
                                <input type="file" id="uploadFileInput" accept="image/*" style="display:none" onchange="handleUploadFileSelect(this)">
                            </div>
                            <div id="uploadPreviewContainer" style="display:none;">
                                <img id="uploadPreviewImg" class="upload-preview-img" src="" alt="预览">
                                <div class="upload-file-info">
                                    <span id="uploadFileName"></span>
                                    <span id="uploadFileSize"></span>
                                </div>
                            </div>
                            <div id="uploadError" class="upload-error" style="display:none;"></div>
                        </div>
                        <div class="upload-settings-panel">
                            <div class="upload-settings-title">压缩参数</div>
                            <div class="toggle-switch-row">
                                <span class="toggle-switch-label">压缩并转 WEBP</span>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="uploadCompress" checked onchange="updateUploadEstimate()">
                                    <span class="toggle-switch-slider"></span>
                                </label>
                            </div>
                            <div class="slider-group">
                                <div class="slider-header">
                                    <span class="slider-label">质量</span>
                                    <span class="slider-value" id="uploadQualityValue">75</span>
                                </div>
                                <input type="range" class="slider-input" id="uploadQuality" min="1" max="100" value="75" oninput="updateUploadEstimate()">
                            </div>
                            <div class="slider-group">
                                <div class="slider-header">
                                    <span class="slider-label">压缩比</span>
                                    <span class="slider-value" id="uploadScaleValue">80%</span>
                                </div>
                                <input type="range" class="slider-input" id="uploadScale" min="10" max="100" value="80" oninput="updateUploadEstimate()">
                            </div>
                            <div class="upload-estimate" id="uploadEstimate">
                                <div class="upload-estimate-row">
                                    <span>原始大小</span>
                                    <span class="upload-estimate-value" id="uploadOrigSize">-</span>
                                </div>
                                <div class="upload-estimate-row">
                                    <span>预估大小</span>
                                    <span class="upload-estimate-value" id="uploadEstSize">-</span>
                                </div>
                                <div class="upload-estimate-row">
                                    <span>预估节省</span>
                                    <span class="upload-estimate-saving" id="uploadEstSaving">-</span>
                                </div>
                            </div>
                            <div id="uploadProgressArea" style="display:none;">
                                <div class="upload-progress-bar">
                                    <div class="upload-progress-fill" id="uploadProgressFill" style="width:0%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="hideModal()">取消</button>
                    <button class="upload-btn-confirm" id="uploadConfirmBtn" onclick="doUpload()" disabled>上传</button>
                </div>
            `;
            showModal(uploadModalHTML, { dismissByMask: false, wide: true });

            // 绑定拖拽事件
            setTimeout(() => {
                const dropZone = document.getElementById('uploadDropZone');
                if (dropZone) {
                    dropZone.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        dropZone.classList.add('dragover');
                    });
                    dropZone.addEventListener('dragleave', () => {
                        dropZone.classList.remove('dragover');
                    });
                    dropZone.addEventListener('drop', (e) => {
                        e.preventDefault();
                        dropZone.classList.remove('dragover');
                        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                            processUploadFile(e.dataTransfer.files[0]);
                        }
                    });
                }
            }, 100);
        }

        function handleUploadFileSelect(input) {
            if (input.files && input.files[0]) {
                processUploadFile(input.files[0]);
            }
        }

        function processUploadFile(file) {
            if (!file.type.startsWith('image/')) {
                showUploadError('请选择图片文件');
                return;
            }
            uploadSelectedFile = file;

            const reader = new FileReader();
            reader.onload = (e) => {
                const previewContainer = document.getElementById('uploadPreviewContainer');
                const previewImg = document.getElementById('uploadPreviewImg');
                const fileName = document.getElementById('uploadFileName');
                const fileSize = document.getElementById('uploadFileSize');
                const dropZone = document.getElementById('uploadDropZone');

                previewImg.src = e.target.result;
                fileName.textContent = file.name;
                fileSize.textContent = formatFileSize(file.size);
                previewContainer.style.display = 'flex';
                dropZone.style.display = 'none';

                document.getElementById('uploadConfirmBtn').disabled = false;
                updateUploadEstimate();
            };
            reader.readAsDataURL(file);
        }

        function updateUploadEstimate() {
            const quality = parseInt(document.getElementById('uploadQuality').value);
            const scale = parseInt(document.getElementById('uploadScale').value);
            const compress = document.getElementById('uploadCompress').checked;

            document.getElementById('uploadQualityValue').textContent = quality;
            document.getElementById('uploadScaleValue').textContent = scale + '%';

            if (!uploadSelectedFile) return;

            const origSize = uploadSelectedFile.size;
            document.getElementById('uploadOrigSize').textContent = formatFileSize(origSize);

            if (compress) {
                const estSize = origSize * (scale / 100) * (quality / 100);
                document.getElementById('uploadEstSize').textContent = formatFileSize(estSize);
                const saving = ((1 - estSize / origSize) * 100).toFixed(1);
                document.getElementById('uploadEstSaving').textContent = saving + '%';
            } else {
                document.getElementById('uploadEstSize').textContent = formatFileSize(origSize);
                document.getElementById('uploadEstSaving').textContent = '0%';
            }
        }

        async function doUpload() {
            if (!uploadSelectedFile) return;

            const compress = document.getElementById('uploadCompress').checked;
            const quality = parseInt(document.getElementById('uploadQuality').value);
            const scale = parseInt(document.getElementById('uploadScale').value);
            const confirmBtn = document.getElementById('uploadConfirmBtn');
            const progressArea = document.getElementById('uploadProgressArea');
            const progressFill = document.getElementById('uploadProgressFill');
            const errorEl = document.getElementById('uploadError');

            errorEl.style.display = 'none';
            confirmBtn.disabled = true;
            confirmBtn.textContent = '上传中...';
            progressArea.style.display = 'block';
            progressFill.style.width = '0%';

            const formData = new FormData();
            formData.append('file', uploadSelectedFile);
            formData.append('compress', compress ? '1' : '0');
            formData.append('quality', quality);
            formData.append('scale', scale);

            try {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'api/upload_image.php');
                xhr.setRequestHeader('Authorization', 'Bearer ' + token);

                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable) {
                        const pct = Math.round((e.loaded / e.total) * 100);
                        progressFill.style.width = pct + '%';
                    }
                };

                const result = await new Promise((resolve, reject) => {
                    xhr.onload = () => {
                        try {
                            let text = xhr.responseText || '';
                            const firstBrace = text.indexOf('{');
                            const lastBrace = text.lastIndexOf('}');
                            if (firstBrace > 0 && lastBrace > firstBrace) {
                                text = text.substring(firstBrace, lastBrace + 1);
                            }
                            const parsed = JSON.parse(text);
                            resolve(parsed);
                        } catch (err) {
                            console.error('Upload response status:', xhr.status);
                            console.error('Upload response text (first 500):', xhr.responseText.substring(0, 500));
                            reject(new Error('服务器返回格式异常 (HTTP ' + xhr.status + ')'));
                        }
                    };
                    xhr.onerror = () => reject(new Error('网络错误'));
                    xhr.send(formData);
                });

                if (result.success && result.url) {
                    // 上传弹窗覆盖了原版本编辑弹窗，需先保存当前表单值，再重新渲染原弹窗
                    const curVersion = (document.getElementById('updateVersion') || {}).value || '';
                    const curTitle = (document.getElementById('updateTitle') || {}).value || '';
                    const curContent = (document.getElementById('updateContent') || {}).value || '';
                    const curVideoUrl = (document.getElementById('updateVideoUrl') || {}).value || '';
                    const curCloseDelay = (document.getElementById('updateCloseDelay') || {}).value || '0';
                    const curIsForce = (document.getElementById('updateIsForce') || {}).checked ? 1 : 0;
                    const curIsActive = (document.getElementById('updateIsActive') || {}).checked ? 1 : 0;
                    const newImageUrl = result.url;
                    const editingId = currentEditingUpdateId;

                    // 重新渲染版本编辑弹窗，使用新图片URL
                    showUpdateModal(editingId, curVersion, curTitle, curContent, curVideoUrl, newImageUrl, curIsForce, curIsActive, curCloseDelay);
                    showAlert('success', '图片上传成功');
                } else {
                    showUploadError(result.error || '上传失败');
                }
            } catch (e) {
                showUploadError('上传失败: ' + e.message);
            } finally {
                confirmBtn.disabled = false;
                confirmBtn.textContent = '上传';
                progressArea.style.display = 'none';
            }
        }

        function showUploadError(msg) {
            const errorEl = document.getElementById('uploadError');
            if (errorEl) {
                errorEl.textContent = msg;
                errorEl.style.display = 'block';
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
</script>
