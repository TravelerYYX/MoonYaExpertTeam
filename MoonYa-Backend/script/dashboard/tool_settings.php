            <div id="toolSettingsSection" class="content-section">
                <div class="card">
                    <div class="card-header">
                        <h2>工具设置</h2>
                    </div>
                    <div style="padding: 24px;">
                        <div id="toolSettingsContainer"></div></div></div></div>

<script>
        async function loadToolSettings() {
            try {
                const response = await fetch('api/tool_settings.php', {
                    headers: {
                        'Authorization': `Bearer ${token}`
                    }
                });
                const data = await response.json();
                
                if (data.success) {
                    const container = document.getElementById('toolSettingsContainer');
                    container.innerHTML = '';
                    
                    data.data.tool_settings.forEach(tool => {
                        const toolCard = document.createElement('div');
                        toolCard.className = 'card';
                        toolCard.style.marginBottom = '20px';
                        
                        toolCard.innerHTML = `
                            <div class="card-header">
                                <h3>${tool.tool_display_name}</h3>
                            </div>
                            <div style="padding: 20px;">
                                <div class="form-group">
                                    <label>系统提示词</label>
                                    <textarea id="toolPrompt_${tool.tool_name}" class="form-textarea" style="width: 100%; min-height: 150px; resize: vertical;">${tool.system_prompt}</textarea>
                                </div>
                                <div class="modal-footer" style="margin-top: 16px; justify-content: flex-end;">
                                    <button class="btn-primary" onclick="saveToolSetting('${tool.tool_name}')">保存设置</button>
                                </div>
                            </div>
                        `;
                        
                        container.appendChild(toolCard);
                    });
                }
            } catch (e) {
                showAlert('error', '加载工具设置失败');
            }
        }
        
        async function saveToolSetting(toolName) {
            const systemPrompt = document.getElementById(`toolPrompt_${toolName}`).value;
            
            if (!systemPrompt) {
                showAlert('error', '系统提示词不能为空');
                return;
            }
            
            try {
                const response = await fetch('api/tool_settings.php?action=update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({ tool_name: toolName, system_prompt: systemPrompt })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', '设置保存成功');
                } else {
                    showAlert('error', data.error || '保存失败');
                }
            } catch (e) {
                console.error('保存失败:', e);
                showAlert('error', '保存失败: ' + e.message);
            }
        }
</script>
