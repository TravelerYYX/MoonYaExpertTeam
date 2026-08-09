<?php
$modelUiGroups = $config['ui_model_groups'];
$modelDefaultLabel = static function (array $group): string {
    $default = (string)$group['default'];
    foreach ($group['models'] as $model) {
        if ((string)($model['id'] ?? '') === $default) return (string)($model['label'] ?? $default);
    }
    throw new RuntimeException("Configured default model is absent from its model group: {$default}");
};
?>
        <!-- 右侧主内容区 -->
        <div class="main-content">
            <!-- 新建对话按钮 - 顶部左侧 -->
            <div class="new-chat-top-btn" id="newChatTopBtn">
                <img src="/image/new_chat.svg" alt="新建对话">
            </div>

            <!-- 顶部工具栏（详情开关 + 语音播报） -->
            <div class="top-toolbar" id="topToolbar">
                <div class="top-toolbar-item detail-toggle-container" id="detailToggleContainer" data-tooltip="点击展开操作详情">
                    <img src="/image/toolbar.svg" alt="操作详情" class="detail-toggle-icon" id="detailToggleIcon">
                </div>
                <div class="top-toolbar-divider"></div>
                <div class="top-toolbar-item voice-toggle-container" id="voiceToggleContainer" data-tooltip="点击开启语音播报">
                    <img src="/image/voice-on.svg" alt="语音播报" class="voice-toggle-icon" id="voiceToggleIcon">
                </div>
            </div>

            <div class="main-header">
                <h1 class="main-title">你好，我是MoonYa</h1>
                <div class="hot-topics-container" id="hotTopicsContainer">
                </div>
            </div>
            
            <!-- 消息容器 -->
            <div class="messages-container">
            </div>

            <!-- 输入框容器 -->
            <div class="input-container-wrapper">
                <!-- Work 模式：项目栏与输入框组合为上下衔接的卡片 -->
                <div class="work-project-bar" id="workProjectBar">
                    <div class="work-project-selector" id="workProjectSelector">
                        <button class="work-project-btn" id="workProjectBtn" type="button">
                            <svg class="work-project-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                            </svg>
                            <span>进入项目工作</span>
                            <svg class="work-project-arrow" id="workProjectArrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 6 15 12 9 18"></polyline>
                            </svg>
                        </button>
                        <div class="work-project-dropdown" id="workProjectDropdown">
                            <div class="work-project-option" data-action="new_project">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19"></line>
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                                <span>新建空白项目</span>
                            </div>
                            <div class="work-project-option" data-action="existing_folder">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                                    <path d="M12 11v6"></path>
                                    <path d="M9 14h6"></path>
                                </svg>
                                <span>使用现有文件夹</span>
                            </div>
                            <div class="work-project-option" data-action="no_folder">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                                    <line x1="9" y1="13" x2="15" y2="13"></line>
                                </svg>
                                <span>不使用文件夹</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="input-container">
                    <div class="upload-container" id="uploadContainer"></div>
                    <div class="input-wrapper">
                        <textarea class="message-input" placeholder="输入问题或Ctrl+Space 开启语音输入"></textarea>
                        <div id="ptt-input-glow" aria-hidden="true">
                            <div class="ptt-orb-glow"></div>
                            <div class="ptt-orb-ring ring-1"></div>
                            <div class="ptt-orb-ring ring-2"></div>
                            <div class="ptt-orb-core"></div>
                            <div class="ptt-orb-waves" aria-hidden="true">
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                        </div>

                        <div class="input-bottom-bar">
                            <div class="input-bottom-left">
                                <div class="add-menu-selector" id="addMenuSelector">
                                    <button type="button" class="file-card file-upload-plus" id="fileCard"
                                            aria-label="添加文件或功能" aria-haspopup="menu" aria-expanded="false" title="添加">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M12 5v14M5 12h14"></path>
                                        </svg>
                                        <span class="file-upload-plus-label">添加</span>
                                    </button>
                                    <div class="add-menu-popover" id="addMenuPopover" role="menu" aria-label="添加">
                                        <div class="add-menu-heading">添加</div>
                                        <button type="button" class="add-menu-option" id="localPathPicker" role="menuitem">
                                            <svg class="add-menu-option-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M21.4 11.6 12.9 20a5.2 5.2 0 0 1-7.4-7.4l8.1-8.1a3.6 3.6 0 0 1 5.1 5.1l-8.2 8.2a2 2 0 0 1-2.8-2.8l7.6-7.6"></path>
                                            </svg>
                                            <span class="add-menu-option-copy">
                                                <span class="add-menu-option-title">文件和文件夹</span>
                                                <span class="add-menu-option-desc" id="filePickerModeDescription">桌面提供路径，Web 上传附件</span>
                                            </span>
                                        </button>
                                        <div class="web-upload-choice" id="webUploadChoice" hidden aria-label="选择上传方式">
                                            <button type="button" class="web-upload-choice-button" id="webFilePicker" role="menuitem">
                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h8l4 4v16H6zM14 2v5h5"></path></svg>
                                                <span>选择文件</span>
                                            </button>
                                            <button type="button" class="web-upload-choice-button" id="webFolderPicker" role="menuitem">
                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h7l2 2h9v11H3z"></path></svg>
                                                <span>选择文件夹</span>
                                            </button>
                                        </div>
                                        <div class="add-menu-divider" role="separator"></div>
                                        <div class="add-menu-heading">功能</div>
                                        <button type="button" class="add-menu-option add-menu-switch-option" id="voiceChatToggle"
                                                role="switch" aria-checked="false" aria-label="实时语音对话">
                                            <svg class="add-menu-option-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                <rect x="8" y="3" width="8" height="12" rx="4"></rect>
                                                <path d="M5 11a7 7 0 0 0 14 0M12 18v3M8.5 21h7"></path>
                                            </svg>
                                            <span class="add-menu-option-copy">
                                                <span class="add-menu-option-title">实时语音对话</span>
                                                <span class="add-menu-option-desc">实时语音任务</span>
                                            </span>
                                            <span class="add-menu-switch" aria-hidden="true"><span></span></span>
                                        </button>
                                        <button type="button" class="add-menu-option add-menu-switch-option" id="computerUserBtn"
                                                role="switch" aria-checked="false" aria-label="Computer Use模式">
                                            <svg class="add-menu-option-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                <rect x="3" y="4" width="18" height="13" rx="2"></rect>
                                                <path d="M8 21h8M12 17v4"></path>
                                            </svg>
                                            <span class="add-menu-option-copy">
                                                <span class="add-menu-option-title">Computer Use模式</span>
                                                <span class="add-menu-option-desc">让 AI 操作电脑完成任务</span>
                                            </span>
                                            <span class="add-menu-switch" aria-hidden="true"><span></span></span>
                                        </button>
                                    </div>
                                </div>
                                <input type="file" id="fileInput" accept="*/*" multiple>
                                <input type="file" id="folderInput" accept="*/*" webkitdirectory directory multiple>
                                <div class="input-label-container">
                                    <div class="input-label" id="deepThinkingLabel">思考</div>
                                    <div class="kimi-model-selector" id="kimiModelSelector">
                                        <div class="kimi-model-btn" id="kimiModelBtn">
                                            <span id="kimiModelBtnText"><?php echo htmlspecialchars($modelDefaultLabel($modelUiGroups['kimi']), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <svg class="arrow-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="currentColor"></path></svg>
                                        </div>
                                        <div class="kimi-model-dropdown" id="kimiModelDropdown">
                                            <?php foreach ($modelUiGroups['kimi']['models'] as $model): ?>
                                            <div class="kimi-model-option<?php echo $model['id'] === $modelUiGroups['kimi']['default'] ? ' selected' : ''; ?>" data-model="<?php echo htmlspecialchars($model['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <span><?php echo htmlspecialchars($model['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <svg class="check-icon" width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="deepseek-model-selector" id="deepseekModelSelector">
                                        <div class="deepseek-model-btn" id="deepseekModelBtn">
                                            <span id="deepseekModelBtnText"><?php echo htmlspecialchars($modelDefaultLabel($modelUiGroups['deepseek']), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <svg class="arrow-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="currentColor"></path></svg>
                                        </div>
                                        <div class="deepseek-model-dropdown" id="deepseekModelDropdown">
                                            <?php foreach ($modelUiGroups['deepseek']['models'] as $model): ?>
                                            <div class="deepseek-model-option<?php echo $model['id'] === $modelUiGroups['deepseek']['default'] ? ' selected' : ''; ?>" data-model="<?php echo htmlspecialchars($model['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <span><?php echo htmlspecialchars($model['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <svg class="check-icon" width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="minmax-model-selector" id="minmaxModelSelector">
                                        <div class="minmax-model-btn" id="minmaxModelBtn">
                                            <span id="minmaxModelBtnText"><?php echo htmlspecialchars($modelDefaultLabel($modelUiGroups['minmax']), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <svg class="arrow-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="currentColor"></path></svg>
                                        </div>
                                        <div class="minmax-model-dropdown" id="minmaxModelDropdown">
                                            <?php foreach ($modelUiGroups['minmax']['models'] as $model): ?>
                                            <div class="minmax-model-option<?php echo $model['id'] === $modelUiGroups['minmax']['default'] ? ' selected' : ''; ?>" data-model="<?php echo htmlspecialchars($model['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <span><?php echo htmlspecialchars($model['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <svg class="check-icon" width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="glm-model-selector" id="glmModelSelector">
                                        <div class="glm-model-btn" id="glmModelBtn">
                                            <span id="glmModelBtnText"><?php echo htmlspecialchars($modelDefaultLabel($modelUiGroups['glm']), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <svg class="arrow-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="currentColor"></path></svg>
                                        </div>
                                        <div class="glm-model-dropdown" id="glmModelDropdown">
                                            <?php foreach ($modelUiGroups['glm']['models'] as $model): ?>
                                            <div class="glm-model-option<?php echo $model['id'] === $modelUiGroups['glm']['default'] ? ' selected' : ''; ?>" data-model="<?php echo htmlspecialchars($model['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <span><?php echo htmlspecialchars($model['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <svg class="check-icon" width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="reasoning-effort-selector" id="reasoningEffortSelector">
                                        <div class="reasoning-effort-btn" id="reasoningEffortBtn">
                                            <span id="reasoningEffortBtnText">高推理</span>
                                            <svg class="arrow-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="currentColor"></path></svg>
                                        </div>
                                        <div class="reasoning-effort-dropdown" id="reasoningEffortDropdown">
                                            <div class="reasoning-effort-option selected" data-effort="high">
                                                <span>高推理</span>
                                                <span class="check-icon">✓</span>
                                            </div>
                                            <div class="reasoning-effort-option" data-effort="max">
                                                <span>极致推理</span>
                                                <span class="check-icon">✓</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="input-label" id="expertLabel">专家模式</div>
                                    <div class="input-label" id="specialistLabel">专精模式</div>
                                    <div class="more-features-selector" id="moreFeaturesSelector">
                                        <div class="more-features-btn" id="moreFeaturesBtn">
                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><circle cx="3" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="13" cy="8" r="1.5"/></svg>
                                            <span>更多</span>
                                            <svg class="arrow-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="currentColor"></path></svg>
                                        </div>
                                        <div class="more-features-dropdown" id="moreFeaturesDropdown">
                                            <div class="more-features-option" data-feature="writing"><span>帮我写作</span></div>
                                            <div class="more-features-option" data-feature="translation"><span>翻译</span></div>
                                            <div class="more-features-option" data-feature="programming"><span>编程</span></div>
                                            <div class="more-features-option" data-feature="research"><span>深入研究</span></div>
                                            <div class="more-features-option" data-feature="classical"><span>文言文翻译</span></div>
                                            <div class="more-features-option" data-feature="music"><span>来点音乐</span></div>
                                            <div class="more-features-option" data-feature="video"><span>雅泫视频</span></div>
                                            <div class="more-features-option" data-feature="horoscope"><span>星座运势</span></div>
                                            <div class="more-features-option" data-feature="weather"><span>今天天气</span></div>
                                            <div class="more-features-option" data-feature="image_gen"><span>图片生成</span></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="image-gen-tag" id="imageGenTag" style="display:none;">
                                    <span>图片生成</span>
                                    <span class="image-gen-close" id="imageGenClose">×</span>
                                </div>
                                <div class="aspect-ratio-selector" id="aspectRatioSelector" style="display:none;">
                                    <div class="aspect-ratio-btn" id="aspectRatioBtn">
                                        <span id="aspectRatioBtnText">1:1</span>
                                        <svg class="arrow-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="currentColor"></path></svg>
                                    </div>
                                    <div class="aspect-ratio-dropdown" id="aspectRatioDropdown">
                                        <div class="aspect-ratio-option selected" data-ratio="1:1"><span>1:1</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="aspect-ratio-option" data-ratio="9:16"><span>9:16</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="aspect-ratio-option" data-ratio="3:4"><span>3:4</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="aspect-ratio-option" data-ratio="16:9"><span>16:9</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="aspect-ratio-option" data-ratio="4:3"><span>4:3</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="aspect-ratio-option" data-ratio="2:1"><span>2:1</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="aspect-ratio-option" data-ratio="1:2"><span>1:2</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                    </div>
                                </div>
                                <div class="video-gen-tag" id="videoGenTag" style="display:none;">
                                    <span>视频生成</span>
                                    <span class="video-gen-close" id="videoGenClose">×</span>
                                </div>
                                <div class="video-quality-selector" id="videoQualitySelector" style="display:none;">
                                    <div class="video-quality-btn" id="videoQualityBtn">
                                        <span id="videoQualityBtnText">速度优先</span>
                                        <svg class="arrow-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="currentColor"></path></svg>
                                    </div>
                                    <div class="video-quality-dropdown" id="videoQualityDropdown">
                                        <div class="video-quality-option selected" data-quality="speed"><span>速度优先</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="video-quality-option" data-quality="quality"><span>质量优先</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                    </div>
                                </div>
                                <div class="video-size-selector" id="videoSizeSelector" style="display:none;">
                                    <div class="video-size-btn" id="videoSizeBtn">
                                        <span id="videoSizeBtnText">1280x720</span>
                                        <svg class="arrow-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="currentColor"></path></svg>
                                    </div>
                                    <div class="video-size-dropdown" id="videoSizeDropdown">
                                        <div class="video-size-option selected" data-size="1280x720"><span>1280x720</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="video-size-option" data-size="720x1280"><span>720x1280</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="video-size-option" data-size="1024x1024"><span>1024x1024</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="video-size-option" data-size="1920x1080"><span>1920x1080</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="video-size-option" data-size="1080x1920"><span>1080x1920</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                    </div>
                                </div>
                                <div class="video-fps-selector" id="videoFpsSelector" style="display:none;">
                                    <div class="video-fps-btn" id="videoFpsBtn">
                                        <span id="videoFpsBtnText">30fps</span>
                                        <svg class="arrow-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="currentColor"></path></svg>
                                    </div>
                                    <div class="video-fps-dropdown" id="videoFpsDropdown">
                                        <div class="video-fps-option selected" data-fps="30"><span>30fps</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="video-fps-option" data-fps="60"><span>60fps</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                    </div>
                                </div>
                                <div class="video-duration-selector" id="videoDurationSelector" style="display:none;">
                                    <div class="video-duration-btn" id="videoDurationBtn">
                                        <span id="videoDurationBtnText">5秒</span>
                                        <svg class="arrow-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="currentColor"></path></svg>
                                    </div>
                                    <div class="video-duration-dropdown" id="videoDurationDropdown">
                                        <div class="video-duration-option selected" data-duration="5"><span>5秒</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                        <div class="video-duration-option" data-duration="10"><span>10秒</span><svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M3 8 L6 11 L13 5" stroke="#444444" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                                    </div>
                                </div>
                                <div class="video-audio-toggle" id="videoAudioToggle" style="display:none;">
                                    <div class="video-audio-btn" id="videoAudioBtn">
                                        <span>AI音效</span>
                                    </div>
                                </div>
                            </div>
                            <div class="input-bottom-right">
                                <div class="agent-settings-selector" id="agentSettingsSelector">
                                    <button class="agent-settings-status" id="agentSettingsStatus" type="button"
                                            aria-haspopup="dialog" aria-expanded="false" aria-label="模型与推理设置">
                                        <span id="agentModelStatusText">v4-flash</span>
                                        <span id="agentEffortStatusText">高</span>
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"></path></svg>
                                    </button>
                                    <div class="agent-settings-popover" id="agentSettingsPopover" role="dialog"
                                         aria-label="模型与推理设置">
                                        <button class="agent-settings-advanced-toggle" id="agentAdvancedToggle" type="button"
                                                aria-expanded="false">
                                            <span class="agent-settings-advanced-label">
                                                <span>高级</span>
                                                <svg class="ui-chevron-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                    <polyline points="9 6 15 12 9 18"></polyline>
                                                </svg>
                                            </span>
                                            <svg class="agent-effort-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="m13 2-8 12h7l-1 8 8-12h-7l1-8Z"></path>
                                            </svg>
                                        </button>
                                        <div class="agent-effort-control">
                                            <div class="agent-effort-slider-wrap">
                                                <input id="reasoningEffortSlider" class="agent-effort-slider" type="range"
                                                       min="0" max="4" step="1" value="3"
                                                       aria-label="推理强度：高">
                                                <div class="agent-effort-dots" aria-hidden="true">
                                                    <i></i><i></i><i></i><i></i><i></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="agent-settings-caption">
                                            <span id="agentSettingsCaptionModel">v4-flash</span>
                                            <span id="agentSettingsCaptionEffort">高</span>
                                        </div>
                                        <div class="agent-settings-advanced" id="agentAdvancedPanel" hidden>
                                            <button class="agent-settings-row" id="agentModelMenuToggle" type="button"
                                                    aria-expanded="false">
                                                <span>模型</span>
                                                <span class="agent-settings-row-value">
                                                    <b id="agentAdvancedModelValue">v4-flash</b>
                                                    <svg class="ui-chevron-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                        <polyline points="9 6 15 12 9 18"></polyline>
                                                    </svg>
                                                </span>
                                            </button>
                                            <div class="agent-settings-row agent-settings-row-static">
                                                <span>推理强度</span><span id="agentAdvancedEffortValue">高</span>
                                            </div>
                                        </div>
                                        <div class="agent-model-menu" id="agentModelMenu" role="menu" hidden>
                                            <?php foreach ($modelUiGroups['deepseek']['models'] as $model): ?>
                                            <button type="button" role="menuitemradio" aria-checked="<?php echo $model['id'] === $modelUiGroups['deepseek']['default'] ? 'true' : 'false'; ?>"
                                                    data-agent-model="<?php echo htmlspecialchars($model['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <span><?php echo htmlspecialchars($model['label'], ENT_QUOTES, 'UTF-8'); ?></span><span class="agent-model-check">✓</span>
                                            </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="approval-mode-selector" id="approvalModeSelector">
                                    <button class="approval-mode-button" id="approvalModeButton" type="button"
                                            aria-haspopup="menu" aria-expanded="false" aria-label="工具权限：仅高风险确认">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M12 3 5 6v5c0 4.6 2.9 8.2 7 10 4.1-1.8 7-5.4 7-10V6l-7-3Z"></path>
                                            <path d="m9.5 12 1.7 1.7 3.6-4"></path>
                                        </svg>
                                        <span id="approvalModeButtonText">仅高风险确认</span>
                                        <svg class="approval-mode-caret ui-chevron-icon" viewBox="0 0 24 24" aria-hidden="true">
                                            <polyline points="9 6 15 12 9 18"></polyline>
                                        </svg>
                                    </button>
                                    <div class="approval-mode-menu" id="approvalModeMenu" role="menu" aria-label="工具权限模式">
                                        <button type="button" role="menuitemradio" aria-checked="false" data-approval-mode="full_access">
                                            <span class="approval-menu-title">完全访问</span>
                                            <span class="approval-menu-desc">已授权范围内自动执行</span>
                                        </button>
                                        <button type="button" role="menuitemradio" aria-checked="true" data-approval-mode="high_risk_only" class="selected">
                                            <span class="approval-menu-title">仅高风险确认</span>
                                            <span class="approval-menu-desc">危险、敏感或未审核写操作需要确认</span>
                                        </button>
                                        <button type="button" role="menuitemradio" aria-checked="false" data-approval-mode="always_confirm_changes">
                                            <span class="approval-menu-title">变更前始终确认</span>
                                            <span class="approval-menu-desc">读取自动执行，任何外部变更先确认</span>
                                        </button>
                                        <div class="mcp-connection-block">
                                            <div class="mcp-connection-heading">MCP 账号连接</div>
                                            <div id="mcpConnectionList" class="mcp-connection-list">
                                                <span class="mcp-connection-empty">暂无已启用的 MCP 服务</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="active-feature-badges" id="activeFeatureBadges" aria-live="polite" aria-label="已启用功能">
                                    <span class="active-feature-badge" id="voiceFeatureBadge" hidden>
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <rect x="8" y="3" width="8" height="12" rx="4"></rect>
                                            <path d="M5 11a7 7 0 0 0 14 0M12 18v3M8.5 21h7"></path>
                                        </svg>
                                        <span>实时语音对话</span>
                                    </span>
                                    <span class="active-feature-badge" id="cuFeatureBadge" hidden>
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <rect x="3" y="4" width="18" height="13" rx="2"></rect>
                                            <path d="M8 21h8M12 17v4"></path>
                                        </svg>
                                        <span>Computer Use模式</span>
                                    </span>
                                </div>
                                <div class="send-btn2" id="voiceBtn" style="margin-right: 10px; cursor: pointer;">
                                    <img id="voiceBtnImg" src="/image/mkf.png" alt="语音输入" style="width: 26px; height: 26px; margin-right: 6px; margin-bottom: 3px;">
                                </div>
                                <div class="send-btn2" id="sendBtn">
                                    <img id="sendBtnImg" src="/image/send.png" alt="发送" style="width: 20px; height: 20px; filter: brightness(0) invert(1);">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="feature-buttons">
                        <button class="feature-btn writing" id="writingBtn">帮我写作</button>
                        <button class="feature-btn translation" id="translationBtn">翻译</button>
                        <button class="feature-btn programming" id="programmingBtn">编程</button>
                        <button class="feature-btn research" id="researchBtn">深入研究</button>
                        <button class="feature-btn classical" id="classicalBtn">文言文翻译</button>
                        <button class="feature-btn music" id="musicBtn">来点音乐</button>
                        <button class="feature-btn video" id="videoBtn">雅泫视频</button>
                        <button class="feature-btn horoscope" id="horoscopeBtn">星座运势</button>
                        <button class="feature-btn weather" id="weatherBtn">今天天气</button>
                        <button class="feature-btn image_gen" id="imageGenBtn">图片生成</button>
                    </div>
                </div>

                <!-- Work 模式：网页端提示弹窗 -->
                <div class="wp-modal-overlay" id="workProjectWebWarnModal" aria-hidden="true">
                    <div class="wp-modal wp-modal-sm" role="dialog" aria-modal="true" aria-labelledby="wpWarnTitle">
                        <div class="wp-modal-header">
                            <h3 class="wp-modal-title" id="wpWarnTitle" data-i18n="web_mode_warning_title">需要桌面启动器</h3>
                        </div>
                        <div class="wp-modal-body">
                            <p class="wp-modal-text" data-i18n="web_mode_warning_body">该功能需要配合 MoonYa 桌面启动器使用...</p>
                        </div>
                        <div class="wp-modal-footer">
                            <button type="button" class="wp-btn-primary" id="wpWarnOkBtn" data-i18n="btn_acknowledge" aria-label="我知道了">我知道了</button>
                        </div>
                    </div>
                </div>

                <!-- Work 模式：新建项目弹窗 -->
                <div class="wp-modal-overlay" id="workProjectCreateModal" aria-hidden="true">
                    <div class="wp-modal" role="dialog" aria-modal="true" aria-labelledby="wpCreateTitle">
                        <div class="wp-modal-header">
                            <h3 class="wp-modal-title" id="wpCreateTitle" data-i18n="modal_create_title">创建项目</h3>
                            <button type="button" class="wp-modal-close" id="wpCreateCloseBtn" aria-label="关闭">×</button>
                        </div>
                        <div class="wp-modal-body">
                            <div class="wp-field">
                                <label class="wp-label" for="wpCreateNameInput" data-i18n="label_project_name">项目名称</label>
                                <input type="text" class="wp-input" id="wpCreateNameInput" data-i18n-ph="placeholder_project_name" placeholder="这里写项目名称" maxlength="100" autocomplete="off" />
                            </div>
                            <div class="wp-field">
                                <label class="wp-label" for="wpCreatePathInput" data-i18n="label_project_path">项目路径</label>
                                <div class="wp-path-row">
                                    <input type="text" class="wp-input wp-path-input" id="wpCreatePathInput" data-i18n-ph="placeholder_project_path" placeholder="直接输入或粘贴项目路径" autocomplete="off" spellcheck="false" />
                                    <button type="button" class="wp-btn-secondary wp-pick-btn" id="wpCreatePickBtn" data-i18n="btn_browse" aria-label="浏览选择文件夹" title="打开文件夹选择器">浏览</button>
                                </div>
                            </div>
                        </div>
                        <div class="wp-modal-footer">
                            <button type="button" class="wp-btn-secondary" id="wpCreateCancelBtn" data-i18n="btn_cancel" aria-label="取消">取消</button>
                            <button type="button" class="wp-btn-primary" id="wpCreateConfirmBtn" data-i18n="btn_save" aria-label="保存">保存</button>
                        </div>
                    </div>
                </div>

                <!-- Work 模式：现有文件夹列表弹窗 -->
                <div class="wp-modal-overlay" id="workProjectListModal" aria-hidden="true">
                    <div class="wp-modal" role="dialog" aria-modal="true" aria-labelledby="wpListTitle">
                        <div class="wp-modal-header">
                            <h3 class="wp-modal-title" id="wpListTitle" data-i18n="modal_list_title">选择现有文件夹</h3>
                            <button type="button" class="wp-modal-close" id="wpListCloseBtn" aria-label="关闭">×</button>
                        </div>
                        <div class="wp-modal-body">
                            <input type="text" class="wp-input wp-search-input" id="wpListSearchInput" data-i18n-ph="placeholder_search" placeholder="搜索文件夹名称..." />
                            <div class="wp-folder-list" id="wpFolderList">
                                <!-- 列表项 JS 动态渲染 -->
                            </div>
                            <div class="wp-empty" id="wpListEmpty" style="display:none;">
                                <div class="wp-empty-icon">📁</div>
                                <div class="wp-empty-text" data-i18n="empty_no_folders">暂无文件夹</div>
                                <div class="wp-empty-hint" data-i18n="empty_no_folders_hint">点击下方按钮新建第一个项目</div>
                                <button type="button" class="wp-btn-primary" id="wpEmptyCreateBtn" data-i18n="btn_create_new" aria-label="新建项目">新建项目</button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

<?php require __DIR__ . '/office-panel.php'; ?>

        </div>

        <div class="detail-panel-resizer" id="detailPanelResizer"
             role="separator" aria-orientation="vertical"
             aria-label="调整主对话与工作日志宽度"
             aria-controls="detailPanel" aria-valuemin="350"
             aria-hidden="true" tabindex="-1"></div>

        <!-- 原生七 Agent 团队工作面板（右侧） -->
        <aside class="detail-panel team-panel" id="detailPanel" aria-hidden="true" aria-label="团队工作面板">
            <div class="detail-panel-header">
                <div>
                    <span class="detail-panel-kicker">MOONYA TEAM</span>
                    <span class="detail-panel-title">工作面板</span>
                </div>
                <div class="detail-panel-actions">
                    <span class="team-live-indicator" id="teamLiveIndicator"><i></i><span>待命</span></span>
                    <button class="detail-panel-clear" id="detailPanelClear" type="button" title="清空当前日志">清空</button>
                    <button class="detail-panel-close" id="detailPanelClose" type="button" aria-label="关闭工作面板">×</button>
                </div>
            </div>
            <div class="team-panel-tabs" role="tablist" aria-label="工作面板视图">
                <button type="button" class="team-panel-tab selected" id="teamTabLog" role="tab"
                        aria-selected="true" aria-controls="teamPaneLog" data-team-tab="log">工作日志</button>
                <button type="button" class="team-panel-tab" id="teamTabArtifacts" role="tab"
                        aria-selected="false" aria-controls="teamPaneArtifacts" data-team-tab="artifacts">
                    产出物 <span class="team-tab-count" id="teamArtifactCount">0</span>
                </button>
                <button type="button" class="team-panel-tab" id="teamTabPreview" role="tab"
                        aria-selected="false" aria-controls="teamPanePreview" data-team-tab="preview">预览</button>
            </div>
            <div class="team-panel-panes">
                <section class="team-panel-pane selected" id="teamPaneLog" role="tabpanel" aria-labelledby="teamTabLog">
                    <div class="detail-panel-content team-work-log" id="detailPanelContent">
                        <div class="detail-panel-empty" id="detailPanelEmpty">
                            <span class="team-empty-orb" aria-hidden="true"></span>
                            <strong>团队尚未开始工作</strong>
                            <span>Work 或 Computer User 任务的执行过程会显示在这里</span>
                        </div>
                    </div>
                </section>
                <section class="team-panel-pane" id="teamPaneArtifacts" role="tabpanel"
                         aria-labelledby="teamTabArtifacts" hidden>
                    <div class="team-artifact-list" id="teamArtifactList">
                        <div class="detail-panel-empty"><strong>暂无产出物</strong><span>文件、链接和媒体会按 Agent 分组</span></div>
                    </div>
                </section>
                <section class="team-panel-pane" id="teamPanePreview" role="tabpanel"
                         aria-labelledby="teamTabPreview" hidden>
                    <div class="team-preview" id="teamPreview">
                        <div class="detail-panel-empty"><strong>选择一个产出物</strong><span>支持安全预览文本、代码、JSON、CSV、图片、PDF 与媒体</span></div>
                    </div>
                </section>
            </div>
        </aside>
    </div>

    <div class="browser-security-overlay" id="browserSecurityOverlay" hidden>
        <section class="browser-security-modal" role="dialog" aria-modal="true"
                 aria-labelledby="browserSecurityTitle" aria-describedby="browserSecurityDescription">
            <header class="browser-security-header">
                <span class="browser-security-kicker">BROWSER SECURITY</span>
                <h2 id="browserSecurityTitle">浏览器操作确认</h2>
            </header>
            <p id="browserSecurityDescription"></p>
            <dl class="browser-security-facts" id="browserSecurityFacts"></dl>
            <div class="browser-security-permissions" id="browserSecurityPermissions" hidden>
                <h3>已保存的站点权限</h3>
                <div id="browserSecurityPermissionList"></div>
            </div>
            <footer class="browser-security-actions" id="browserSecurityActions"></footer>
            <button type="button" class="browser-security-manage" id="browserSecurityManage">管理站点权限</button>
        </section>
    </div>

    <!-- 自定义右键刷新菜单 -->
    <div class="my-context-menu" id="myContextMenu" title="刷新页面">
        <div class="ctx-icon">
            <svg t="1782040107803" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="5090" width="200" height="200"><path d="M820.2752 204.8a435.2 435.2 0 1 0 104.0896 167.7312 25.6 25.6 0 1 1 48.4864-16.384 486.4 486.4 0 1 1-117.8112-188.928V25.5488a25.6 25.6 0 1 1 51.2 0v204.8a25.6 25.6 0 0 1-25.6 25.6h-204.8a25.6 25.6 0 1 1 0-51.2h144.4352z" fill="#000000" p-id="5091"></path></svg>
        </div>
        <div class="ctx-text">
            <div class="ctx-title">刷新</div>
        </div>
    </div>
