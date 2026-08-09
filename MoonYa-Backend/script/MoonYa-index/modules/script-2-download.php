
// ==================== 文件下载功能 ====================
/**
 * 浏览器端文件下载（支持 File System Access API 和 Blob 双通道）
 * @param {string} url - 下载 URL
 * @param {string} filename - 保存文件名（可选）
 * @param {Function} onProgress - 进度回调 (loaded, total, percentage)
 * @returns {Promise<Object>} 下载结果
 */
async function downloadFileBrowser(url, filename, onProgress) {
    const result = {
        success: false,
        code: 0,
        file: null,
        error: null
    };

    try {
        // 尝试获取文件信息
        const headResponse = await fetch(url, { method: 'HEAD' });
        const total = parseInt(headResponse.headers.get('Content-Length') || '0');
        const mimeType = headResponse.headers.get('Content-Type') || 'application/octet-stream';
        
        if (!filename) {
            const disposition = headResponse.headers.get('Content-Disposition') || '';
            const match = disposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
            filename = match ? match[1].replace(/['"]/g, '') : url.split('/').pop().split('?')[0] || 'download.bin';
        }

        // 开始下载
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error('HTTP ' + response.status + ': ' + response.statusText);
        }

        // 尝试使用 File System Access API（Chromium 内核浏览器如 CefSharp 支持）
        if (window.showSaveFilePicker) {
            try {
                const ext = filename.split('.').pop().toLowerCase();
                const handle = await window.showSaveFilePicker({
                    suggestedName: filename,
                    types: [{
                        description: '下载文件',
                        accept: { [mimeType]: ['.' + ext] }
                    }]
                });
                const writable = await handle.createWritable();

                // 使用 ReadableStream 读取并写入
                const reader = response.body.getReader();
                const contentLength = total;
                let loaded = 0;

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    await writable.write(value);
                    loaded += value.length;

                    if (onProgress) {
                        const percentage = contentLength > 0 ? (loaded / contentLength * 100) : 0;
                        onProgress(loaded, contentLength, percentage);
                    }
                }

                await writable.close();
                
                result.success = true;
                result.code = 200;
                result.file = {
                    name: filename,
                    size: loaded,
                    type: mimeType
                };

                document.dispatchEvent(new CustomEvent('download:complete', { detail: result }));
                return result;
            } catch (fsError) {
                if (fsError.name === 'AbortError') {
                    // 用户取消了保存对话框，回退到 Blob 方式
                    
                } else {
                    throw fsError;
                }
            }
        }

        // 回退：传统 Blob + <a> 标签下载方式
        const contentLength = total;
        let blob;

        // 如果响应支持 ReadableStream，监听进度
        if (response.body && response.body.getReader) {
            const reader = response.body.getReader();
            const chunks = [];
            let loaded = 0;

            while (true) {
                const { done, value } = await reader.read();
                if (done) {
                    blob = new Blob(chunks, { type: mimeType });
                    break;
                }
                chunks.push(value);
                loaded += value.length;
                
                if (onProgress && contentLength > 0) {
                    const percentage = (loaded / contentLength * 100);
                    onProgress(loaded, contentLength, percentage);
                }
            }
        } else {
            // 不支持流式读取，直接获取全部
            const buffer = await response.arrayBuffer();
            blob = new Blob([buffer], { type: mimeType });
            loaded = buffer.byteLength;
        }

        // 创建下载链接并触发
        const downloadUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = downloadUrl;
        a.download = filename;
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        
        // 清理
        setTimeout(() => {
            document.body.removeChild(a);
            URL.revokeObjectURL(downloadUrl);
        }, 100);

        if (onProgress) {
            const totalSize = contentLength || blob.size;
            onProgress(totalSize, totalSize, 100);
        }

        result.success = true;
        result.code = 200;
        result.file = {
            name: filename,
            size: blob.size,
            type: mimeType
        };

        document.dispatchEvent(new CustomEvent('download:complete', { detail: result }));
        return result;

    } catch (error) {
        result.success = false;
        result.code = 500;
        result.error = error.message;

        document.dispatchEvent(new CustomEvent('download:error', {
            detail: { ...result, error: error.message }
        }));
        return result;
    }
}

/**
 * 使用 CefSharp Native Bridge 下载（平台 SDK 方式）
 * @param {string} url - 下载 URL
 * @param {string} savePath - 保存路径
 * @returns {Promise<Object>} 下载结果
 */
async function downloadFileSdk(url, savePath) {
    if (window.moonYaFileOps && window.moonYaFileOps.downloadFile) {
        try {
            const result = await window.moonYaFileOps.downloadFile(url, savePath);
            document.dispatchEvent(new CustomEvent('download:complete', { detail: result }));
            return result;
        } catch (error) {
            document.dispatchEvent(new CustomEvent('download:error', {
                detail: { success: false, error: error.message }
            }));
            return { success: false, error: error.message };
        }
    } else {
        return { success: false, error: 'Native bridge not available' };
    }
}

/**
 * 统一下载入口：根据环境和参数自动选择最佳下载方式
 * @param {Object} options - { url, filename, path, method, onProgress }
 * @returns {Promise<Object>}
 */
async function downloadFile(options) {
    const { url, filename, path, method = 'direct', onProgress } = options;
    
    if (method === 'sdk' || method === 'direct') {
        // 优先尝试平台 SDK（CefSharp Bridge）
        if (window.moonYaFileOps && window.moonYaFileOps.downloadFile) {
            const desktopPath = path || '桌面';
            return await downloadFileSdk(url, desktopPath + '/' + (filename || ''));
        }
    }
    
    // 回退到浏览器下载
    return await downloadFileBrowser(url, filename, onProgress);
}

// 封装下载执行逻辑，供 download_file tool handler 调用
function executeDownload(url, path, filename, method) {
    downloadFile({
        url: url,
        filename: filename,
        path: path,
        method: method,
        onProgress: function(loaded, total, percentage) {
            const progressEl = document.getElementById('download-progress');
            if (progressEl) {
                progressEl.textContent = '下载进度: ' + Math.round(percentage) + '%';
            }
        }
    }).then(function(result) {
        if (result.success && result.file) {
            const sizeStr = result.file.size > 1024 * 1024
                ? (result.file.size / 1024 / 1024).toFixed(2) + ' MB'
                : (result.file.size / 1024).toFixed(1) + ' KB';
            addChatMessage('assistant', '文件下载完成：' + result.file.name + ' (' + sizeStr + ')');

            if (showToast) {
                showToast('下载完成：' + result.file.name);
            }
        } else {
            addChatMessage('assistant', '下载失败：' + (result.error || '未知错误'));

            if (showToast) {
                showToast('下载失败：' + (result.error || '未知错误'));
            }
        }
    }).catch(function(error) {
        addChatMessage('assistant', '下载失败：' + (error.message || '未知错误'));
        if (showToast) {
            showToast('下载失败：' + (error.message || '未知错误'));
        }
    });
}
    </script>
