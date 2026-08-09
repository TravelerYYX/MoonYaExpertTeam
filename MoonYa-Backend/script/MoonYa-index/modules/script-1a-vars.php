    <script>






        // 对话历史管理
        let currentChatId = null;
        let currentDbConversationId = null; // 数据库中的对话ID（用于多轮对话）
        let isBatchDeleteMode = false;
        let isLoggedIn = false;
        let currentUser = null;
        let CHAT_HISTORY_KEY = 'chat_history';

        const FEATURE_CONFIG = <?php echo json_encode([
            'horoscope' => [
                'enabled' => $config['horoscope']['enabled'] ?? true,
                'apiUrl' => $config['horoscope']['api_url'] ?? '',
                'apiToken' => $config['horoscope']['api_token'] ?? '',
            ],
            'weather' => [
                'enabled' => $config['weather']['enabled'] ?? true,
                'apiUrl' => $config['weather']['api_url'] ?? '',
                'apiToken' => $config['weather']['api_token'] ?? '',
                'ipLocationApis' => $config['weather']['ip_location_apis'] ?? [],
                'defaultTimezone' => $config['weather']['default_timezone'] ?? 'UTC+8',
            ],
        ]); ?>;
        const BG_WEBP_DATA = '<?php
            $bgPath = __DIR__ . '/image/bg.webp';
            if (file_exists($bgPath)) {
                echo base64_encode(file_get_contents($bgPath));
            }
        ?>';
        
        // Agent 模型配置：用户可见入口仅提供 DeepSeek v4-flash / v4-pro。
        const DEEPSEEK_MODEL_STORAGE_KEY = 'moonya.deepseek.model.v1';
        const DEEPSEEK_EFFORT_STORAGE_KEY = 'moonya.deepseek.reasoning.v1';
        const MODEL_UI_CONFIG = <?php echo json_encode($config['ui_model_groups'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        function configuredModelGroup(name) {
            const group = MODEL_UI_CONFIG[name];
            if (!group || !group.default || !Array.isArray(group.models) || group.models.length === 0) {
                throw new Error('Missing required configuration: ui_model_groups.' + name);
            }
            return group;
        }
        function configuredModelMeta(groupName, modelId) {
            return configuredModelGroup(groupName).models.find(function(model) { return model.id === modelId; }) || null;
        }
        function configuredModelLabel(groupName, modelId) {
            const model = configuredModelMeta(groupName, modelId);
            return model ? model.label : modelId;
        }
        const DEEPSEEK_MODEL_GROUP = configuredModelGroup('deepseek');
        const DEEPSEEK_MODEL_ALLOWLIST = DEEPSEEK_MODEL_GROUP.models.map(function(model) { return model.id; });
        const DEEPSEEK_EFFORT_LEVELS = ['none', 'low', 'medium', 'high', 'xhigh'];
        let persistedDeepseekModel = DEEPSEEK_MODEL_GROUP.default;
        let persistedReasoningEffort = 'high';
        try {
            const storedModel = localStorage.getItem(DEEPSEEK_MODEL_STORAGE_KEY);
            const storedEffort = localStorage.getItem(DEEPSEEK_EFFORT_STORAGE_KEY);
            if (DEEPSEEK_MODEL_ALLOWLIST.includes(storedModel)) persistedDeepseekModel = storedModel;
            if (DEEPSEEK_EFFORT_LEVELS.includes(storedEffort)) persistedReasoningEffort = storedEffort;
        } catch (storageError) {}
        let currentModel = 'deepseek';
        let deepseekModelVersion = persistedDeepseekModel;
        let minmaxModelVersion = configuredModelGroup('minmax').default;
        let kimiModelVersion = configuredModelGroup('kimi').default;
        let glmModelVersion = configuredModelGroup('glm').default;
        let glmThinkingEnabled = false;
        let reasoningEffort = persistedReasoningEffort;
        let isProgrammingMode = false; // 编程模式标志
        let isTranslationMode = false; // 翻译模式标志
        let isWritingMode = false; // 写作模式标志
        let isResearchMode = false; // 深入研究模式标志
        let isClassicalMode = false; // 文言文翻译模式标志
        let isMusicMode = false; // 音乐模式标志
        let isHoroscopeMode = false; // 星座运势模式标志
        let isWeatherMode = false; // 天气模式标志
        let isImageGenMode = false; // 图片生成模式标志
        let aspectRatio = '1:1'; // 图片生成比例
        let isVideoGenMode = false;
        let videoGenQuality = 'speed';
        let videoGenSize = '1280x720';
        let videoGenFps = 30;
        let videoGenDuration = 5;
        let videoGenWithAudio = true;
        let videoGenRefImages = [];
        let isAgentMode = true;
        let isComputerUserMode = false; // Computer User 模式标志
        function updateActiveFeatureBadges() {
            const container = document.getElementById('activeFeatureBadges');
            const voiceBadge = document.getElementById('voiceFeatureBadge');
            const cuBadge = document.getElementById('cuFeatureBadge');
            const voiceActive = voiceBadge ? !voiceBadge.hidden : false;
            const cuActive = cuBadge ? !cuBadge.hidden : false;
            if (container) container.classList.toggle('has-active', voiceActive || cuActive);
        }
        window.setMoonYaFeatureBadge = function(feature, active) {
            const badge = document.getElementById(feature === 'voice' ? 'voiceFeatureBadge' : 'cuFeatureBadge');
            if (!badge) return;
            badge.hidden = !active;
            updateActiveFeatureBadges();
        };
        updateActiveFeatureBadges();
        // 当前输出消息的 Agent 名称（用于在 AI 消息气泡上方显示来源）
        // work 模式默认为 "MoonYa Agent"；后端 agent_switch 事件会切换为 "Code-Agent" / "MoonYa-T-Agent"
        let currentAgentName = null;
        // Work 为默认模式，初始化 body 类名以应用对应 CSS
        if (document.body) document.body.classList.add('work-mode');
        
        // 模型选择下拉菜单
        const modelSelectValue = document.getElementById('modelSelectValue');
        const modelSelectDropdown = document.querySelector('.model-select-dropdown');
        const modelOptions = document.querySelectorAll('.model-option');
        
        // 切换模型选择下拉菜单
        modelSelectValue.addEventListener('click', function(e) {
            e.stopPropagation();
            modelSelectDropdown.style.display = modelSelectDropdown.style.display === 'block' ? 'none' : 'block';
            // 切换箭头方向
            const arrow = document.querySelector('.model-select-arrow');
            if (modelSelectDropdown.style.display === 'block') {
                arrow.style.transform = 'translateY(-50%) rotate(180deg)';
            } else {
                arrow.style.transform = 'translateY(-50%)';
            }
        });
        
        // 选择模型
        modelOptions.forEach(option => {
            option.addEventListener('click', function(e) {
                e.stopPropagation();
                const value = this.dataset.value;
                const name = this.querySelector('.model-option-name').textContent;
                
                // 显示切换模型的提示
                showToast('正在切换模型...');
                
                setTimeout(() => {
                    currentModel = value;
                    document.getElementById('modelSelect').value = value;
                    modelSelectValue.textContent = name;
                    modelSelectDropdown.style.display = 'none';
                    // 重置箭头方向
                    const arrow = document.querySelector('.model-select-arrow');
                    arrow.style.transform = 'translateY(-50%)';
                    // 更新选中状态
                    modelOptions.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    
                    if (typeof updateSpecialistLabelsVisibility === 'function') {
                        updateSpecialistLabelsVisibility();
                    }
                    
                    if (typeof updateDeepSeekSelectorsVisibility === 'function') {
                        updateDeepSeekSelectorsVisibility();
                    }
                    
                    showToast(`已切换为: ${name}`);
                }, 500);
            });
        });
        
        // 模式切换 Tab
        const modeTabs = document.querySelectorAll('.mode-toggle-tab');
        const modeSelect = document.getElementById('modeSelect');
        modeTabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.stopPropagation();
                const value = this.dataset.value;
                modeTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                if (modeSelect) modeSelect.value = value;
                if (value === 'work') {
                    isAgentMode = true;
                    document.body.classList.add('work-mode');
                    showToast('已切换到Work模式');
                } else {
                    isAgentMode = false;
                    currentAgentName = null;
                    document.body.classList.remove('work-mode');
                    showToast('已切换到Chat模式');
                }
                if (typeof updateModelSelectVisibility === 'function') {
                    updateModelSelectVisibility();
                }
                if (typeof updateWorkProjectBarVisibility === 'function') {
                    updateWorkProjectBarVisibility();
                }
                if (typeof updateOfficeBtnVisibility === 'function') {
                    updateOfficeBtnVisibility();
                }
            });
        });

        // DeepSeek子模型选择器
        const deepseekModelSelector = document.getElementById('deepseekModelSelector');
        const deepseekModelBtn = document.getElementById('deepseekModelBtn');
        const deepseekModelBtnText = document.getElementById('deepseekModelBtnText');
        const deepseekModelDropdown = document.getElementById('deepseekModelDropdown');
        const deepseekModelOptions = document.querySelectorAll('.deepseek-model-option');
        
        if (deepseekModelBtn) {
            deepseekModelBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (deepseekModelDropdown) deepseekModelDropdown.classList.toggle('show');
                deepseekModelBtn.classList.toggle('open');
            });
        }
        
        deepseekModelOptions.forEach(option => {
            option.addEventListener('click', function(e) {
                e.stopPropagation();
                const model = this.dataset.model;
                deepseekModelVersion = model;
                const displayName = configuredModelLabel('deepseek', model);
                if (deepseekModelBtnText) deepseekModelBtnText.textContent = displayName;
                if (deepseekModelDropdown) deepseekModelDropdown.classList.remove('show');
                if (deepseekModelBtn) deepseekModelBtn.classList.remove('open');
                deepseekModelOptions.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');
                showToast(`已切换为: ${model}`);
            });
        });
        
        // 推理档位选择器
        const reasoningEffortSelector = document.getElementById('reasoningEffortSelector');
        const reasoningEffortBtn = document.getElementById('reasoningEffortBtn');
        const reasoningEffortBtnText = document.getElementById('reasoningEffortBtnText');
        const reasoningEffortDropdown = document.getElementById('reasoningEffortDropdown');
        const reasoningEffortOptions = document.querySelectorAll('.reasoning-effort-option');
        
        if (reasoningEffortBtn) {
            reasoningEffortBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (reasoningEffortDropdown) reasoningEffortDropdown.classList.toggle('show');
                reasoningEffortBtn.classList.toggle('open');
            });
        }
        
        reasoningEffortOptions.forEach(option => {
            option.addEventListener('click', function(e) {
                e.stopPropagation();
                const effort = this.dataset.effort;
                reasoningEffort = effort;
                const effortNames = { high: '高推理', max: '极致推理' };
                if (reasoningEffortBtnText) reasoningEffortBtnText.textContent = effortNames[effort] || '高推理';
                if (reasoningEffortDropdown) reasoningEffortDropdown.classList.remove('show');
                if (reasoningEffortBtn) reasoningEffortBtn.classList.remove('open');
                reasoningEffortOptions.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');
                showToast(`推理档位: ${effortNames[effort]}`);
            });
        });

        // 参考桌面端的紧凑模型/推理浮层。滑条只生成 DeepSeek 参数；
        // Kimi/MiniMax 即使由内部 Agent 配置使用，也不会读取这里的 reasoningEffort。
        (function initAgentSettings() {
            const selector = document.getElementById('agentSettingsSelector');
            const status = document.getElementById('agentSettingsStatus');
            const popover = document.getElementById('agentSettingsPopover');
            const advancedToggle = document.getElementById('agentAdvancedToggle');
            const advancedPanel = document.getElementById('agentAdvancedPanel');
            const modelMenuToggle = document.getElementById('agentModelMenuToggle');
            const modelMenu = document.getElementById('agentModelMenu');
            const slider = document.getElementById('reasoningEffortSlider');
            const uploadButton = document.getElementById('fileCard');
            if (!selector || !status || !popover || !slider) return;
            if (uploadButton) {
                uploadButton.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        uploadButton.click();
                    }
                });
            }

            const effortLabels = {
                none: '不思考',
                low: '低',
                medium: '中',
                high: '高',
                xhigh: '极高'
            };
            const modelLabels = Object.fromEntries(DEEPSEEK_MODEL_GROUP.models.map(function(model) {
                return [model.id, model.label];
            }));

            function persistSettings() {
                try {
                    localStorage.setItem(DEEPSEEK_MODEL_STORAGE_KEY, deepseekModelVersion);
                    localStorage.setItem(DEEPSEEK_EFFORT_STORAGE_KEY, reasoningEffort);
                } catch (storageError) {}
            }

            function syncLegacyControls() {
                deepseekModelOptions.forEach(function(option) {
                    option.classList.toggle('selected', option.dataset.model === deepseekModelVersion);
                });
                if (deepseekModelBtnText) {
                    deepseekModelBtnText.textContent = configuredModelLabel('deepseek', deepseekModelVersion);
                }
                reasoningEffortOptions.forEach(function(option) {
                    const legacyEffort = option.dataset.effort === 'max' ? 'xhigh' : option.dataset.effort;
                    option.classList.toggle('selected', legacyEffort === reasoningEffort);
                });
                if (reasoningEffortBtnText) {
                    reasoningEffortBtnText.textContent = effortLabels[reasoningEffort] + '推理';
                }
                const legacyThinkingLabel = document.getElementById('deepThinkingLabel');
                if (legacyThinkingLabel) {
                    legacyThinkingLabel.classList.toggle('active', reasoningEffort !== 'none');
                }
            }

            function renderSettings() {
                currentModel = 'deepseek';
                if (!DEEPSEEK_MODEL_ALLOWLIST.includes(deepseekModelVersion)) {
                    deepseekModelVersion = DEEPSEEK_MODEL_GROUP.default;
                }
                if (!DEEPSEEK_EFFORT_LEVELS.includes(reasoningEffort)) {
                    reasoningEffort = 'high';
                }
                const effortLabel = effortLabels[reasoningEffort];
                const modelLabel = modelLabels[deepseekModelVersion];
                slider.value = String(DEEPSEEK_EFFORT_LEVELS.indexOf(reasoningEffort));
                slider.setAttribute('aria-label', '推理强度：' + effortLabel);
                slider.style.setProperty('--effort-progress', (Number(slider.value) / 4 * 100) + '%');
                ['agentModelStatusText', 'agentSettingsCaptionModel', 'agentAdvancedModelValue'].forEach(function(id) {
                    const node = document.getElementById(id);
                    if (node) node.textContent = modelLabel;
                });
                ['agentEffortStatusText', 'agentSettingsCaptionEffort', 'agentAdvancedEffortValue'].forEach(function(id) {
                    const node = document.getElementById(id);
                    if (node) node.textContent = effortLabel;
                });
                modelMenu.querySelectorAll('[data-agent-model]').forEach(function(button) {
                    const selected = button.dataset.agentModel === deepseekModelVersion;
                    button.classList.toggle('selected', selected);
                    button.setAttribute('aria-checked', selected ? 'true' : 'false');
                });
                syncLegacyControls();
                persistSettings();
            }

            function closeSettings() {
                selector.classList.remove('open');
                status.setAttribute('aria-expanded', 'false');
                if (modelMenu) modelMenu.hidden = true;
                if (modelMenuToggle) modelMenuToggle.setAttribute('aria-expanded', 'false');
            }

            status.addEventListener('click', function(event) {
                event.stopPropagation();
                const open = selector.classList.toggle('open');
                status.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            slider.addEventListener('input', function() {
                reasoningEffort = DEEPSEEK_EFFORT_LEVELS[Number(slider.value)] || 'high';
                renderSettings();
            });
            if (advancedToggle && advancedPanel) {
                advancedToggle.addEventListener('click', function(event) {
                    event.stopPropagation();
                    const willOpen = advancedPanel.hidden;
                    advancedPanel.hidden = !willOpen;
                    advancedToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                    if (!willOpen && modelMenu) modelMenu.hidden = true;
                });
            }
            if (modelMenuToggle && modelMenu) {
                modelMenuToggle.addEventListener('click', function(event) {
                    event.stopPropagation();
                    modelMenu.hidden = !modelMenu.hidden;
                    modelMenuToggle.setAttribute('aria-expanded', modelMenu.hidden ? 'false' : 'true');
                });
                modelMenu.querySelectorAll('[data-agent-model]').forEach(function(button) {
                    button.addEventListener('click', function(event) {
                        event.stopPropagation();
                        deepseekModelVersion = button.dataset.agentModel;
                        modelMenu.hidden = true;
                        modelMenuToggle.setAttribute('aria-expanded', 'false');
                        renderSettings();
                    });
                });
            }
            document.addEventListener('click', function(event) {
                if (!selector.contains(event.target)) closeSettings();
            });
            selector.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeSettings();
                    status.focus();
                }
            });
            renderSettings();
        })();
        
        // MiniMax子模型选择器
        const minmaxModelSelector = document.getElementById('minmaxModelSelector');
        const minmaxModelBtn = document.getElementById('minmaxModelBtn');
        const minmaxModelBtnText = document.getElementById('minmaxModelBtnText');
        const minmaxModelDropdown = document.getElementById('minmaxModelDropdown');
        const minmaxModelOptions = document.querySelectorAll('.minmax-model-option');
        
        if (minmaxModelBtn) {
            minmaxModelBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (minmaxModelDropdown) minmaxModelDropdown.classList.toggle('show');
                minmaxModelBtn.classList.toggle('open');
            });
        }
        
        minmaxModelOptions.forEach(option => {
            option.addEventListener('click', function(e) {
                e.stopPropagation();
                const model = this.dataset.model;
                minmaxModelVersion = model;
                const displayName = this.querySelector('span').textContent;
                if (minmaxModelBtnText) minmaxModelBtnText.textContent = displayName;
                if (minmaxModelDropdown) minmaxModelDropdown.classList.remove('show');
                if (minmaxModelBtn) minmaxModelBtn.classList.remove('open');
                minmaxModelOptions.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');
                showToast(`已切换为: ${displayName}`);
            });
        });
        
        // GLM子模型选择器
        const glmModelSelector = document.getElementById('glmModelSelector');
        const glmModelBtn = document.getElementById('glmModelBtn');
        const glmModelBtnText = document.getElementById('glmModelBtnText');
        const glmModelDropdown = document.getElementById('glmModelDropdown');
        const glmModelOptions = document.querySelectorAll('.glm-model-option');
        
        if (glmModelBtn) {
            glmModelBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (glmModelDropdown) glmModelDropdown.classList.toggle('show');
                glmModelBtn.classList.toggle('open');
            });
        }
        
        glmModelOptions.forEach(option => {
            option.addEventListener('click', function(e) {
                e.stopPropagation();
                const model = this.dataset.model;
                glmModelVersion = model;
                const displayName = this.querySelector('span').textContent;
                if (glmModelBtnText) glmModelBtnText.textContent = displayName;
                if (glmModelDropdown) glmModelDropdown.classList.remove('show');
                if (glmModelBtn) glmModelBtn.classList.remove('open');
                glmModelOptions.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');
                if (configuredModelMeta('glm', model)?.supports_thinking === true) {
                    glmThinkingEnabled = false;
                    if (deepThinkingLabel) deepThinkingLabel.classList.remove('active');
                    updateDeepSeekSelectorsVisibility();
                    showToast(`已切换为: ${displayName}`);
                } else {
                    showToast(`已切换为: ${displayName}`);
                }
            });
        });
        
        const kimiModelSelector = document.getElementById('kimiModelSelector');
        const kimiModelBtn = document.getElementById('kimiModelBtn');
        const kimiModelBtnText = document.getElementById('kimiModelBtnText');
        const kimiModelDropdown = document.getElementById('kimiModelDropdown');
        const kimiModelOptions = document.querySelectorAll('.kimi-model-option');
        
        if (kimiModelBtn) {
            kimiModelBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (kimiModelDropdown) kimiModelDropdown.classList.toggle('show');
                kimiModelBtn.classList.toggle('open');
            });
        }
        
        kimiModelOptions.forEach(option => {
            option.addEventListener('click', function(e) {
                e.stopPropagation();
                const model = this.dataset.model;
                kimiModelVersion = model;
                const displayName = this.querySelector('span').textContent;
                if (kimiModelBtnText) kimiModelBtnText.textContent = displayName;
                if (kimiModelDropdown) kimiModelDropdown.classList.remove('show');
                if (kimiModelBtn) kimiModelBtn.classList.remove('open');
                kimiModelOptions.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');
                showToast(`已切换为: ${displayName}`);
                updateDeepSeekSelectorsVisibility();
            });
        });
        
        function updateDeepSeekSelectorsVisibility() {
            const isDeepSeek = currentModel === 'deepseek';
            const isMinMax = currentModel === 'minmax';
            const isGlm = currentModel === 'glm';
            const isKimi = currentModel === 'kimi';
            const dtLabel = document.getElementById('deepThinkingLabel');
            const isDeepThinking = dtLabel ? dtLabel.classList.contains('active') : false;
            if (deepseekModelSelector) {
                if (isDeepSeek) {
                    deepseekModelSelector.classList.add('visible');
                } else {
                    deepseekModelSelector.classList.remove('visible');
                }
            }
            if (reasoningEffortSelector) {
                if (isDeepSeek && isDeepThinking) {
                    reasoningEffortSelector.classList.add('visible');
                } else {
                    reasoningEffortSelector.classList.remove('visible');
                }
            }
            if (minmaxModelSelector) {
                if (isMinMax) {
                    minmaxModelSelector.classList.add('visible');
                } else {
                    minmaxModelSelector.classList.remove('visible');
                }
            }
            if (glmModelSelector) {
                if (isGlm) {
                    glmModelSelector.classList.add('visible');
                } else {
                    glmModelSelector.classList.remove('visible');
                }
            }
            if (kimiModelSelector) {
                if (isKimi) {
                    kimiModelSelector.classList.add('visible');
                } else {
                    kimiModelSelector.classList.remove('visible');
                }
            }
            const kimiSupportsThinking = isKimi && configuredModelMeta('kimi', kimiModelVersion)?.supports_thinking === true;
            const showThinking = !isKimi || kimiSupportsThinking;
            const minmaxForceThink = isMinMax && configuredModelMeta('minmax', minmaxModelVersion)?.force_thinking === true;
            if (dtLabel) {
                dtLabel.style.display = showThinking ? '' : 'none';
                if (minmaxForceThink) {
                    dtLabel.classList.add('active');
                    dtLabel.classList.add('think-locked');
                    dtLabel.title = '强制深度思考';
                } else {
                    dtLabel.classList.remove('think-locked');
                    dtLabel.title = '';
                }
            }
            const expertLabel = document.getElementById('expertLabel');
            const specialistLabel = document.getElementById('specialistLabel');
            if (expertLabel) {
                expertLabel.style.display = (isMinMax || isGlm || isKimi) ? 'none' : '';
            }
            if (specialistLabel) {
                specialistLabel.style.display = (isMinMax || isGlm || isKimi) ? 'none' : '';
            }
            const imageGenTag = document.getElementById('imageGenTag');
            const aspectRatioSel = document.getElementById('aspectRatioSelector');
            if (isImageGenMode) {
                if (imageGenTag) imageGenTag.style.display = 'inline-flex';
                if (aspectRatioSel) aspectRatioSel.style.display = 'inline-flex';
                if (deepseekModelSelector) deepseekModelSelector.classList.remove('visible');
                if (minmaxModelSelector) minmaxModelSelector.classList.remove('visible');
                if (glmModelSelector) glmModelSelector.classList.remove('visible');
                if (kimiModelSelector) kimiModelSelector.classList.remove('visible');
                if (reasoningEffortSelector) reasoningEffortSelector.classList.remove('visible');
                if (dtLabel) dtLabel.style.display = 'none';
                if (expertLabel) expertLabel.style.display = 'none';
                if (specialistLabel) specialistLabel.style.display = 'none';
            } else {
                if (imageGenTag) imageGenTag.style.display = 'none';
                if (aspectRatioSel) aspectRatioSel.style.display = 'none';
            }
            const videoGenTag = document.getElementById('videoGenTag');
            const videoQualitySel = document.getElementById('videoQualitySelector');
            const videoSizeSel = document.getElementById('videoSizeSelector');
            const videoFpsSel = document.getElementById('videoFpsSelector');
            const videoDurationSel = document.getElementById('videoDurationSelector');
            const videoAudioTgl = document.getElementById('videoAudioToggle');
            if (isVideoGenMode) {
                if (videoGenTag) videoGenTag.style.display = 'inline-flex';
                if (videoQualitySel) videoQualitySel.style.display = 'inline-flex';
                if (videoSizeSel) videoSizeSel.style.display = 'inline-flex';
                if (videoFpsSel) videoFpsSel.style.display = 'inline-flex';
                if (videoDurationSel) videoDurationSel.style.display = 'inline-flex';
                if (videoAudioTgl) videoAudioTgl.style.display = 'inline-flex';
                if (deepseekModelSelector) deepseekModelSelector.classList.remove('visible');
                if (minmaxModelSelector) minmaxModelSelector.classList.remove('visible');
                if (glmModelSelector) glmModelSelector.classList.remove('visible');
                if (kimiModelSelector) kimiModelSelector.classList.remove('visible');
                if (reasoningEffortSelector) reasoningEffortSelector.classList.remove('visible');
                if (dtLabel) dtLabel.style.display = 'none';
                if (expertLabel) expertLabel.style.display = 'none';
                if (specialistLabel) specialistLabel.style.display = 'none';
            } else {
                if (videoGenTag) videoGenTag.style.display = 'none';
                if (videoQualitySel) videoQualitySel.style.display = 'none';
                if (videoSizeSel) videoSizeSel.style.display = 'none';
                if (videoFpsSel) videoFpsSel.style.display = 'none';
                if (videoDurationSel) videoDurationSel.style.display = 'none';
                if (videoAudioTgl) videoAudioTgl.style.display = 'none';
            }
            const fileCard = document.getElementById('fileCard');
            if (isImageGenMode || isVideoGenMode) {
                if (fileCard) fileCard.style.display = 'none';
            } else {
                if (fileCard) fileCard.style.display = '';
            }
        }
        updateDeepSeekSelectorsVisibility();

        // Work模式下隐藏侧边栏模型选择
        function updateModelSelectVisibility() {
            const c = document.getElementById('modelSelectContainer');
            if (c) c.classList.toggle('sidebar-model-hidden', isAgentMode);
        }
        updateModelSelectVisibility();

        // Work 模式下显示/隐藏“进入项目工作区”
        function updateWorkProjectBarVisibility() {
            const bar = document.getElementById('workProjectBar');
            if (!bar) return;
            bar.style.display = isAgentMode ? 'block' : 'none';
            if (!isAgentMode) {
                const dd = document.getElementById('workProjectDropdown');
                const btn = document.getElementById('workProjectBtn');
                if (dd) dd.classList.remove('show');
                if (btn) btn.classList.remove('open');
            }
        }
        updateWorkProjectBarVisibility();

        // Chat 模式下隐藏“办公室”入口，并关闭可能已打开的办公室视图
        function updateOfficeBtnVisibility() {
            const btn = document.getElementById('officeBtn');
            if (btn) btn.style.display = isAgentMode ? '' : 'none';
            if (!isAgentMode && document.body.classList.contains('office-active') && window.MoonYaOffice) {
                window.MoonYaOffice.close();
            }
        }
        updateOfficeBtnVisibility();

        // Computer User 模式按钮
        const computerUserBtn = document.getElementById('computerUserBtn');
        if (computerUserBtn) {
            computerUserBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (!isMoonYaLauncher()) {
                    // 网页端：弹提示，不激活 CU 模式
                    if (typeof openModal === 'function') {
                        openModal('workProjectWebWarnModal');
                    } else {
                        showToast('需要桌面启动器');
                    }
                    return;
                }
                // 启动器端：切换 CU 模式
                isComputerUserMode = !isComputerUserMode;
                computerUserBtn.classList.toggle('active', isComputerUserMode);
                computerUserBtn.setAttribute('aria-checked', isComputerUserMode ? 'true' : 'false');
                window.setMoonYaFeatureBadge('cu', isComputerUserMode);
            });
        }

        // Work 模式：进入项目工作区下拉菜单
        const workProjectBtn = document.getElementById('workProjectBtn');
        const workProjectDropdown = document.getElementById('workProjectDropdown');
        
        // ═══ 更多功能按钮：下拉菜单 ═══
        const moreFeaturesBtn = document.getElementById('moreFeaturesBtn');
        const moreFeaturesDropdown = document.getElementById('moreFeaturesDropdown');
        const moreFeaturesOptions = document.querySelectorAll('.more-features-option');
        
        // 功能名 → 对应的模式变量/按钮ID映射
        const FEATURE_MAP = {
            writing:     { flag: 'isWritingMode',     btnId: 'writingBtn' },
            translation: { flag: 'isTranslationMode',  btnId: 'translationBtn' },
            programming: { flag: 'isProgrammingMode',  btnId: 'programmingBtn' },
            research:    { flag: 'isResearchMode',     btnId: 'researchBtn' },
            classical:   { flag: 'isClassicalMode',    btnId: 'classicalBtn' },
            music:       { flag: 'isMusicMode',        btnId: 'musicBtn' },
            video:       { flag: 'isVideoGenMode',     btnId: 'videoBtn' },
            horoscope:   { flag: 'isHoroscopeMode',    btnId: 'horoscopeBtn' },
            weather:     { flag: 'isWeatherMode',      btnId: 'weatherBtn' },
            image_gen:   { flag: 'isImageGenMode',     btnId: 'imageGenBtn' },
        };
        
        function updateMoreFeaturesActiveState() {
            const hasActiveFeature = isProgrammingMode || isTranslationMode || isWritingMode || 
                                     isResearchMode || isClassicalMode || isMusicMode ||
                                     isHoroscopeMode || isWeatherMode || isImageGenMode || isVideoGenMode;
            if (moreFeaturesBtn) {
                moreFeaturesBtn.classList.toggle('active', hasActiveFeature);
            }
            // 同步下拉菜单选项的激活状态
            moreFeaturesOptions.forEach(function(opt) {
                var feature = opt.getAttribute('data-feature');
                var map = FEATURE_MAP[feature];
                var isActive = map && window[map.flag];
                opt.classList.toggle('active', !!isActive);
            });
        }
        
        // 打开/关闭下拉菜单
        if (moreFeaturesBtn && moreFeaturesDropdown) {
            moreFeaturesBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                moreFeaturesDropdown.classList.toggle('show');
                moreFeaturesBtn.classList.toggle('open');
            });
        }
        
        // 下拉菜单选项点击
        moreFeaturesOptions.forEach(function(opt) {
            opt.addEventListener('click', function(e) {
                e.stopPropagation();
                var feature = this.getAttribute('data-feature');
                var map = FEATURE_MAP[feature];
                if (map) {
                    // 模拟点击对应的功能按钮
                    var btn = document.getElementById(map.btnId);
                    if (btn) btn.click();
                }
                // 关闭下拉菜单
                if (moreFeaturesDropdown) moreFeaturesDropdown.classList.remove('show');
                if (moreFeaturesBtn) moreFeaturesBtn.classList.remove('open');
            });
        });
        
        // 点击页面其他地方关闭更多功能下拉菜单
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.more-features-selector')) {
                if (moreFeaturesDropdown) moreFeaturesDropdown.classList.remove('show');
                if (moreFeaturesBtn) moreFeaturesBtn.classList.remove('open');
            }
            if (!e.target.closest('.custom-model-select')) {
                modelSelectDropdown.style.display = 'none';
                const arrow = document.querySelector('.model-select-arrow');
                arrow.style.transform = 'translateY(-50%)';
            }
            if (!e.target.closest('.deepseek-model-selector')) {
                const dd = document.getElementById('deepseekModelDropdown');
                const btn = document.getElementById('deepseekModelBtn');
                if (dd) dd.classList.remove('show');
                if (btn) btn.classList.remove('open');
            }
            if (!e.target.closest('.minmax-model-selector')) {
                const dd = document.getElementById('minmaxModelDropdown');
                const btn = document.getElementById('minmaxModelBtn');
                if (dd) dd.classList.remove('show');
                if (btn) btn.classList.remove('open');
            }
            if (!e.target.closest('.glm-model-selector')) {
                const dd = document.getElementById('glmModelDropdown');
                const btn = document.getElementById('glmModelBtn');
                if (dd) dd.classList.remove('show');
                if (btn) btn.classList.remove('open');
            }
            if (!e.target.closest('.kimi-model-selector')) {
                const dd = document.getElementById('kimiModelDropdown');
                const btn = document.getElementById('kimiModelBtn');
                if (dd) dd.classList.remove('show');
                if (btn) btn.classList.remove('open');
            }
            if (!e.target.closest('.reasoning-effort-selector')) {
                const dd = document.getElementById('reasoningEffortDropdown');
                const btn = document.getElementById('reasoningEffortBtn');
                if (dd) dd.classList.remove('show');
                if (btn) btn.classList.remove('open');
            }
            if (!e.target.closest('.work-project-selector')) {
                const dd = document.getElementById('workProjectDropdown');
                const btn = document.getElementById('workProjectBtn');
                if (dd) dd.classList.remove('show');
                if (btn) btn.classList.remove('open');
            }
        });
        
        // 图片上传管理
<?php require __DIR__ . '/script-1f-work-project.php'; ?>
