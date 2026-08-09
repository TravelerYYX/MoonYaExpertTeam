
        // 页面加载时检查用户信息
        document.addEventListener('DOMContentLoaded', function() {
            // 应用对话区域显示模式（必须在创建布局前应用，避免闪烁）
            try {
                var mode = localStorage.getItem('chatDisplayMode');
                if (mode === 'fullscreen') {
                    document.body.classList.add('chat-fullscreen');
                }
            } catch (e) {}

            var communityAuthSource = null;
            var communityAuthReturn = null;

            function safeCommunityReturn(value) {
                if (typeof value !== 'string') return null;
                try {
                    var parsed = new URL(value, window.location.origin);
                    if (parsed.origin !== window.location.origin || !parsed.pathname.startsWith('/community/')) return null;
                    return parsed.pathname + parsed.search + parsed.hash;
                } catch (e) {
                    return null;
                }
            }

            function completeCommunityLogin() {
                if (communityAuthSource && !communityAuthSource.closed) {
                    communityAuthSource.postMessage({ type: 'moonya:auth-completed' }, window.location.origin);
                    communityAuthSource.focus();
                    communityAuthSource = null;
                    communityAuthReturn = null;
                    return;
                }
                if (communityAuthReturn) {
                    var returnPath = communityAuthReturn;
                    communityAuthReturn = null;
                    window.location.href = returnPath;
                }
            }

            window.addEventListener('message', function(event) {
                if (event.origin !== window.location.origin || !event.data || event.data.type !== 'moonya:auth-required') return;
                var safePath = safeCommunityReturn(event.data.return_url);
                if (!safePath) return;
                communityAuthSource = event.source;
                communityAuthReturn = safePath;
                openAuthModal('login');
            });

            try {
                var authParams = new URLSearchParams(window.location.search);
                if (authParams.get('auth') === '1') {
                    communityAuthReturn = safeCommunityReturn(authParams.get('return'));
                    if (communityAuthReturn) openAuthModal('login');
                }
            } catch (e) {}

            // 获取发送按钮元素
            sendBtn = document.getElementById('sendBtn');
            sendBtnImg = document.getElementById('sendBtnImg');

            // 初始化发送状态
            window.isSendingMessage = false;

            // 确保输入框初始高度为40px
            messageInput.style.height = '40px';

            // ═══ 修复：自动聚焦输入框，确保 CefSharp 中键盘输入正常工作 ═══
            // 延迟聚焦以确保浏览器渲染完成
            if (messageInput) {
                setTimeout(function() {
                    messageInput.focus();
                }, 500);
            }

            // 当用户点击主内容区域时，自动聚焦输入框
            var mainContentEl = document.querySelector('.main-content');
            if (mainContentEl && messageInput) {
                mainContentEl.addEventListener('click', function(e) {
                    // 如果点击的不是输入框本身，且目标不是按钮或下拉菜单，则聚焦输入框
                    var tag = e.target.tagName;
                    if (tag !== 'TEXTAREA' && tag !== 'BUTTON' && tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'A') {
                        messageInput.focus();
                    }
                });
            }

            // ═══ 环境检测：启动器（桌面端）隐藏"下载电脑版"按钮，网页端显示 ═══
            try {
                var downloadBtn = document.getElementById('downloadAppBtn');
                if (downloadBtn) {
                    // 桌面启动器使用自定义 UserAgent "MoonYaDesktop"
                    if (navigator.userAgent.indexOf('MoonYaDesktop') !== -1) {
                        var link = downloadBtn.closest('a');
                        if (link) {
                            link.style.display = 'none';
                        } else {
                            downloadBtn.style.display = 'none';
                        }
                    }
                }
            } catch (e) {}

            // 侧边栏折叠功能
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const floatingControls = document.getElementById('floatingControls');
            const expandSidebarBtn = document.getElementById('expandSidebarBtn');
            const newChatFloatingBtn = document.getElementById('newChatFloatingBtn');
            const layoutContainer = document.querySelector('.container');
            const detailPanel = document.getElementById('detailPanel');
            const detailPanelResizer = document.getElementById('detailPanelResizer');
            const sidebarBreakpoint = window.matchMedia('(max-width: 660px)');
            const TEAM_PANEL_WIDTH_STORAGE_KEY = 'moonya.team.panel.width.v1';
            const splitLayoutConfig = Object.freeze({
                minChatWidth: 520,
                minPanelWidth: 350,
                dividerWidth: 1,
                sidebarRestoreMargin: 48
            });
            const expandedSidebarWidth = Math.max(
                240,
                Math.round(sidebar ? sidebar.getBoundingClientRect().width : 0)
            );
            const sidebarCollapseReasons = {
                manual: false,
                viewport: sidebarBreakpoint.matches,
                space: false
            };
            let preferredPanelWidth = null;
            let resizeFrame = 0;
            let activeResizePointerId = null;
            let keepSidebarExpanded = false;

            function isDetailPanelOpen() {
                return !!detailPanel && detailPanel.classList.contains('open');
            }

            function defaultDetailPanelWidth() {
                const containerWidth = layoutContainer ? layoutContainer.clientWidth : window.innerWidth;
                return Math.round(Math.min(430, Math.max(
                    splitLayoutConfig.minPanelWidth,
                    containerWidth * 0.29
                )));
            }

            function loadPreferredPanelWidth() {
                let storedWidth = NaN;
                try {
                    const storedValue = localStorage.getItem(TEAM_PANEL_WIDTH_STORAGE_KEY);
                    if (storedValue !== null && storedValue !== '') {
                        storedWidth = Number(storedValue);
                    }
                } catch (storageError) {}
                preferredPanelWidth = Number.isFinite(storedWidth)
                    ? Math.max(splitLayoutConfig.minPanelWidth, storedWidth)
                    : defaultDetailPanelWidth();
            }

            function persistPreferredPanelWidth() {
                try {
                    localStorage.setItem(
                        TEAM_PANEL_WIDTH_STORAGE_KEY,
                        String(Math.round(preferredPanelWidth))
                    );
                } catch (storageError) {}
            }

            function applySidebarState() {
                if (!sidebar || !floatingControls) return;
                const collapsed = sidebarCollapseReasons.manual
                    || sidebarCollapseReasons.viewport
                    || sidebarCollapseReasons.space;
                sidebar.classList.toggle('collapsed', collapsed);
                floatingControls.classList.toggle('show', collapsed);
            }

            function expandedSidebarFits(panelWidth, restoreMargin) {
                if (!layoutContainer) return true;
                const panelSpace = isDetailPanelOpen()
                    ? panelWidth + splitLayoutConfig.dividerWidth
                    : 0;
                return layoutContainer.clientWidth >= (
                    expandedSidebarWidth
                    + splitLayoutConfig.minChatWidth
                    + panelSpace
                    + (restoreMargin || 0)
                );
            }

            function canExpandSidebar() {
                const panelWidth = preferredPanelWidth || defaultDetailPanelWidth();
                return expandedSidebarFits(panelWidth, 0);
            }

            function maximumPanelWidthWithExpandedSidebar() {
                if (!layoutContainer) return splitLayoutConfig.minPanelWidth;
                return Math.max(
                    splitLayoutConfig.minPanelWidth,
                    Math.floor(
                        layoutContainer.clientWidth
                        - splitLayoutConfig.dividerWidth
                        - splitLayoutConfig.minChatWidth
                        - expandedSidebarWidth
                    )
                );
            }

            function expandSidebarByUser() {
                keepSidebarExpanded = true;
                sidebarCollapseReasons.manual = false;
                sidebarCollapseReasons.viewport = false;
                sidebarCollapseReasons.space = false;

                if (isDetailPanelOpen()) {
                    const panelWidth = Math.min(
                        preferredPanelWidth || defaultDetailPanelWidth(),
                        maximumPanelWidthWithExpandedSidebar()
                    );
                    preferredPanelWidth = Math.max(
                        splitLayoutConfig.minPanelWidth,
                        panelWidth
                    );
                    persistPreferredPanelWidth();
                }

                applySidebarState();
                reconcileSplitLayout();
            }

            function reconcileSplitLayout() {
                if (!layoutContainer || !detailPanel || !detailPanelResizer) return;
                if (!Number.isFinite(preferredPanelWidth)) loadPreferredPanelWidth();

                const panelOpen = isDetailPanelOpen();
                const requestedWidth = Math.max(
                    splitLayoutConfig.minPanelWidth,
                    preferredPanelWidth
                );

                if (panelOpen) {
                    if (keepSidebarExpanded) {
                        sidebarCollapseReasons.space = false;
                    } else {
                        const restoreMargin = sidebarCollapseReasons.space
                            ? splitLayoutConfig.sidebarRestoreMargin
                            : 0;
                        sidebarCollapseReasons.space = !expandedSidebarFits(
                            requestedWidth,
                            restoreMargin
                        );
                    }
                } else {
                    sidebarCollapseReasons.space = false;
                    keepSidebarExpanded = false;
                }
                applySidebarState();

                if (!panelOpen) return;

                const sidebarWidth = sidebar.classList.contains('collapsed')
                    ? 0
                    : expandedSidebarWidth;
                const availablePanelWidth = layoutContainer.clientWidth
                    - splitLayoutConfig.dividerWidth
                    - splitLayoutConfig.minChatWidth
                    - sidebarWidth;
                const maximumPanelWidth = Math.max(
                    splitLayoutConfig.minPanelWidth,
                    Math.floor(availablePanelWidth)
                );
                const appliedWidth = Math.min(requestedWidth, maximumPanelWidth);

                detailPanel.style.setProperty('--team-panel-width', appliedWidth + 'px');
                detailPanelResizer.setAttribute('aria-valuemax', String(maximumPanelWidth));
                detailPanelResizer.setAttribute('aria-valuenow', String(Math.round(appliedWidth)));
                return appliedWidth;
            }

            function setPreferredPanelWidth(width, persist) {
                const requestedWidth = Math.max(
                    splitLayoutConfig.minPanelWidth,
                    Number(width) || splitLayoutConfig.minPanelWidth
                );
                if (
                    keepSidebarExpanded
                    && requestedWidth > maximumPanelWidthWithExpandedSidebar()
                ) {
                    keepSidebarExpanded = false;
                }
                preferredPanelWidth = requestedWidth;
                const appliedWidth = reconcileSplitLayout();
                if (Number.isFinite(appliedWidth)) {
                    preferredPanelWidth = appliedWidth;
                }
                if (persist !== false) persistPreferredPanelWidth();
            }

            // 切换侧边栏显示/隐藏
            function toggleSidebar() {
                if (!sidebar) return;
                if (sidebar.classList.contains('collapsed')) {
                    expandSidebarByUser();
                    return;
                } else {
                    keepSidebarExpanded = false;
                    sidebarCollapseReasons.manual = true;
                    sidebarCollapseReasons.space = false;
                }
                applySidebarState();
                reconcileSplitLayout();
            }

            function collapseSidebar() {
                keepSidebarExpanded = false;
                sidebarCollapseReasons.viewport = true;
                applySidebarState();
            }

            function expandSidebar() {
                sidebarCollapseReasons.viewport = false;
                reconcileSplitLayout();
                applySidebarState();
            }

            function handleSidebarResize(e) {
                if (e.matches) {
                    collapseSidebar();
                } else {
                    expandSidebar();
                }
            }
            sidebarBreakpoint.addEventListener('change', handleSidebarResize);
            applySidebarState();

            // 侧边栏折叠按钮点击事件
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }

            // 展开侧边栏按钮点击事件
            if (expandSidebarBtn) {
                expandSidebarBtn.addEventListener('click', toggleSidebar);
            }

            loadPreferredPanelWidth();
            reconcileSplitLayout();

            if (detailPanelResizer) {
                detailPanelResizer.addEventListener('pointerdown', function(event) {
                    if (event.button !== 0 || !isDetailPanelOpen()) return;
                    event.preventDefault();
                    activeResizePointerId = event.pointerId;
                    detailPanelResizer.setPointerCapture(event.pointerId);
                    document.body.classList.add('is-resizing-detail-panel');
                });

                detailPanelResizer.addEventListener('pointermove', function(event) {
                    if (event.pointerId !== activeResizePointerId || !layoutContainer) return;
                    const containerRect = layoutContainer.getBoundingClientRect();
                    setPreferredPanelWidth(containerRect.right - event.clientX, false);
                });

                const finishPanelResize = function(event) {
                    if (activeResizePointerId === null) return;
                    if (event && event.pointerId !== activeResizePointerId) return;
                    activeResizePointerId = null;
                    document.body.classList.remove('is-resizing-detail-panel');
                    persistPreferredPanelWidth();
                    reconcileSplitLayout();
                };
                detailPanelResizer.addEventListener('pointerup', finishPanelResize);
                detailPanelResizer.addEventListener('pointercancel', finishPanelResize);
                detailPanelResizer.addEventListener('lostpointercapture', finishPanelResize);

                detailPanelResizer.addEventListener('keydown', function(event) {
                    if (!isDetailPanelOpen()) return;
                    const step = event.shiftKey ? 50 : 16;
                    let nextWidth = preferredPanelWidth;
                    if (event.key === 'ArrowLeft') nextWidth += step;
                    else if (event.key === 'ArrowRight') nextWidth -= step;
                    else if (event.key === 'Home') nextWidth = splitLayoutConfig.minPanelWidth;
                    else if (event.key === 'End') {
                        nextWidth = layoutContainer.clientWidth
                            - splitLayoutConfig.dividerWidth
                            - splitLayoutConfig.minChatWidth;
                    } else {
                        return;
                    }
                    event.preventDefault();
                    setPreferredPanelWidth(nextWidth, true);
                });
            }

            window.addEventListener('resize', function() {
                if (resizeFrame) cancelAnimationFrame(resizeFrame);
                resizeFrame = requestAnimationFrame(function() {
                    resizeFrame = 0;
                    reconcileSplitLayout();
                });
            });

            window.moonyaSplitLayout = {
                sync: reconcileSplitLayout,
                canExpandSidebar: canExpandSidebar,
                setPanelWidth: setPreferredPanelWidth
            };

            // 浮动新建对话按钮点击事件
            if (newChatFloatingBtn) {
                newChatFloatingBtn.addEventListener('click', async function() {
                    // 检查当前是否已经是新对话（没有消息）
                    const history = getChatHistory();
                    const currentChat = history.find(chat => chat.id === currentChatId);
                    if (currentChat && currentChat.messages.length === 0) {
                        // 当前已经是新对话，不再创建
                        return;
                    }

                    await createNewChat();
                    // 清空消息容器
                    messagesContainer.innerHTML = '';
                    // 清空输入框
                    messageInput.value = '';
                    // 显示主标题
                    document.querySelector('.main-title').style.display = 'block';
                    // 显示热点按钮
                    const hotTopicsContainer = document.querySelector('.hot-topics-container');
                    if (hotTopicsContainer) hotTopicsContainer.style.display = 'flex';
                    // 重置深度思考标签和专家模型标签
                    deepThinkingLabel.classList.remove('active');
                    expertLabel.classList.remove('expert-active');
                    isExpertMode = false;
                    // 重置专精模式
                    if (specialistLabel) {
                        specialistLabel.classList.remove('specialist-active');
                    }
                    isSpecialistMode = false;
                    specialistRouteInfo = null;
                    if (typeof updateDeepSeekSelectorsVisibility === 'function') {
                        updateDeepSeekSelectorsVisibility();
                    }
                    // 重置所有模式
                    isProgrammingMode = false;
                    isTranslationMode = false;
                    isWritingMode = false;
                    isResearchMode = false;
                    isClassicalMode = false;
                    isImageGenMode = false;
                    isVideoGenMode = false;
                    videoGenRefImages = [];
                    // 移除所有模式按钮的激活状态
                    const programmingBtn = document.getElementById('programmingBtn');
                    if (programmingBtn) programmingBtn.classList.remove('active');
                    const translationBtn = document.getElementById('translationBtn');
                    if (translationBtn) translationBtn.classList.remove('active');
                    const writingBtn = document.getElementById('writingBtn');
                    if (writingBtn) writingBtn.classList.remove('active');
                    const researchBtn = document.getElementById('researchBtn');
                    if (researchBtn) researchBtn.classList.remove('active');
                    const classicalBtn = document.getElementById('classicalBtn');
                    if (classicalBtn) classicalBtn.classList.remove('active');
                    const musicBtn = document.getElementById('musicBtn');
                    if (musicBtn) musicBtn.classList.remove('active');
                    const imageGenBtn3 = document.getElementById('imageGenBtn');
                    if (imageGenBtn3) imageGenBtn3.classList.remove('active');
                });
            }

            // 侧边栏新建对话按钮点击事件
            const sidebarNewChatBtn = document.getElementById('sidebarNewChatBtn');
            if (sidebarNewChatBtn) {
                sidebarNewChatBtn.addEventListener('click', async function() {
                    const history = getChatHistory();
                    const currentChat = history.find(chat => chat.id === currentChatId);
                    if (currentChat && currentChat.messages.length === 0) {
                        return;
                    }
                    await createNewChat();
                    messagesContainer.innerHTML = '';
                    messageInput.value = '';
                    document.querySelector('.main-title').style.display = 'block';
                    const hotTopicsContainer = document.querySelector('.hot-topics-container');
                    if (hotTopicsContainer) hotTopicsContainer.style.display = 'flex';
                    deepThinkingLabel.classList.remove('active');
                    expertLabel.classList.remove('expert-active');
                    isExpertMode = false;
                    if (specialistLabel) {
                        specialistLabel.classList.remove('specialist-active');
                    }
                    isSpecialistMode = false;
                    specialistRouteInfo = null;
                    if (typeof updateDeepSeekSelectorsVisibility === 'function') {
                        updateDeepSeekSelectorsVisibility();
                    }
                    isProgrammingMode = false;
                    isTranslationMode = false;
                    isWritingMode = false;
                    isResearchMode = false;
                    isClassicalMode = false;
                    isImageGenMode = false;
                    isVideoGenMode = false;
                    videoGenRefImages = [];
                    const programmingBtn = document.getElementById('programmingBtn');
                    if (programmingBtn) programmingBtn.classList.remove('active');
                    const translationBtn = document.getElementById('translationBtn');
                    if (translationBtn) translationBtn.classList.remove('active');
                    const writingBtn = document.getElementById('writingBtn');
                    if (writingBtn) writingBtn.classList.remove('active');
                    const researchBtn = document.getElementById('researchBtn');
                    if (researchBtn) researchBtn.classList.remove('active');
                    const classicalBtn = document.getElementById('classicalBtn');
                    if (classicalBtn) classicalBtn.classList.remove('active');
                    const musicBtn = document.getElementById('musicBtn');
                    if (musicBtn) musicBtn.classList.remove('active');
                    const imageGenBtn3 = document.getElementById('imageGenBtn');
                    if (imageGenBtn3) imageGenBtn3.classList.remove('active');
                });
            }

            // 模型选择下拉菜单事件
            const modelSelect = document.getElementById('modelSelect');
            modelSelect.addEventListener('change', function() {
                const selectElement = this;
                selectElement.classList.add('loading');
                showToast('正在切换模型...');
                
                setTimeout(() => {
                    currentModel = selectElement.value;
                    selectElement.classList.remove('loading');
                    
                    showToast(`已切换为: ${selectElement.options[selectElement.selectedIndex].text}`);
                }, 500);
            });
            
            // 语音输入按钮
            const voiceBtn = document.getElementById('voiceBtn');
            const voiceBtnImg = document.getElementById('voiceBtnImg');

            // ─── 统一使用阿里云 Fun-ASR（MediaRecorder + /api/asr.php）───
            let voiceRecording = false;
            let voiceRecorder = null;
            let voiceChunks = [];
            let voiceStream = null;

            async function uploadVoiceAudio(audioBlob, format) {
                const formData = new FormData();
                formData.append('audio', audioBlob, 'voice.' + format);
                formData.append('format', format);
                formData.append('sample_rate', '16000');

                const response = await fetch('/api/asr.php', {
                    method: 'POST',
                    body: formData
                });
                if (!response.ok) {
                    throw new Error('ASR API 返回 ' + response.status);
                }
                const result = await response.json();
                if (result.code === 0) {
                    return result.text || '';
                }
                throw new Error(result.message || '识别失败');
            }

            voiceBtn.addEventListener('click', async function() {
                if (voiceRecording) {
                    // ── 第二次点击：停止录音并识别 ──
                    voiceRecording = false;
                    voiceBtnImg.src = '/image/mkf.png';
                    showToast('语音识别已停止');

                    if (voiceRecorder && voiceRecorder.state !== 'inactive') {
                        voiceRecorder.stop();
                    }
                    return;
                }

                // ── 第一次点击：开始录音 ──
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({
                        audio: {
                            sampleRate: 16000,
                            channelCount: 1,
                            echoCancellation: true,
                            noiseSuppression: true
                        }
                    });

                    voiceStream = stream;
                    voiceChunks = [];
                    voiceRecording = true;
                    voiceBtnImg.src = '/image/gif/yy.gif';
                    showToast('正在聆听...');

                    let mimeType = 'audio/webm';
                    const candidates = [
                        'audio/webm;codecs=opus',
                        'audio/webm',
                        'audio/ogg;codecs=opus',
                        'audio/mp4'
                    ];
                    for (const c of candidates) {
                        if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(c)) {
                            mimeType = c;
                            break;
                        }
                    }
                    const format = mimeType.split(';')[0].split('/')[1] || 'webm';

                    voiceRecorder = new MediaRecorder(stream, { mimeType: mimeType });

                    voiceRecorder.ondataavailable = function(event) {
                        if (event.data && event.data.size > 0) {
                            voiceChunks.push(event.data);
                        }
                    };

                    voiceRecorder.onstop = async function() {
                        try { stream.getTracks().forEach(t => t.stop()); } catch(e) {}
                        voiceStream = null;

                        const audioBlob = new Blob(voiceChunks, { type: mimeType });
                        if (audioBlob.size === 0) {
                            showToast('未检测到语音，请重试');
                            return;
                        }

                        try {
                            const text = await uploadVoiceAudio(audioBlob, format);
                            if (text) {
                                messageInput.value = text;
                                messageInput.style.height = 'auto';
                                messageInput.style.height = Math.min(messageInput.scrollHeight, 160) + 'px';
                                showToast('语音识别完成');
                            } else {
                                showToast('未识别到语音，请重试');
                            }
                        } catch (err) {
                            console.error('语音识别上传失败:', err);
                            showToast('语音识别失败: ' + (err.message || '未知错误'));
                        }
                    };

                    voiceRecorder.onerror = function() {
                        try { stream.getTracks().forEach(t => t.stop()); } catch(e) {}
                        voiceStream = null;
                        voiceRecording = false;
                        voiceBtnImg.src = '/image/mkf.png';
                        showToast('录音过程出错，请重试');
                    };

                    voiceRecorder.start();
                } catch (error) {
                    voiceRecording = false;
                    voiceBtnImg.src = '/image/mkf.png';
                    console.error('语音输入启动失败:', error);
                    if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
                        showToast('麦克风权限被拒绝，请在浏览器设置中允许');
                    } else {
                        showToast('语音输入启动失败，请检查麦克风权限');
                    }
                }
            });

            // ==================== 语音播报功能 ====================

            // 语音播报开关相关DOM元素
            const voiceToggleContainer = document.getElementById('voiceToggleContainer');
            const voiceToggleIcon = document.getElementById('voiceToggleIcon');

            // 初始化语音播报开关状态
            function initVoiceBroadcastToggle() {
                // 从localStorage读取设置，默认为开启
                const savedSetting = localStorage.getItem('voiceBroadcastEnabled');
                isVoiceBroadcastEnabled = savedSetting !== 'false';
                updateVoiceToggleUI();
            }

            // 更新语音播报开关UI
            function updateVoiceToggleUI() {
                if (!voiceToggleContainer || !voiceToggleIcon) return;
                if (isVoiceBroadcastEnabled) {
                    voiceToggleContainer.classList.remove('disabled');
                    voiceToggleIcon.src = '/image/voice-on.svg';
                    voiceToggleContainer.setAttribute('data-tooltip', '点击关闭语音播报');
                } else {
                    voiceToggleContainer.classList.add('disabled');
                    voiceToggleIcon.src = '/image/voice-off.svg';
                    voiceToggleContainer.setAttribute('data-tooltip', '点击开启语音播报');
                }
            }

            // 切换语音播报开关
            if (voiceToggleContainer) {
                voiceToggleContainer.addEventListener('click', function() {
                    // 检查浏览器是否支持语音合成
                    if (!window.speechSynthesis) {
                        showToast('您的浏览器不支持语音播报功能');
                        return;
                    }
                    
                    // 检查是否需要用户交互（Chrome自动播放策略）
                    if (window.speechSynthesis.speaking || window.speechSynthesis.pending) {
                        window.speechSynthesis.cancel();
                    }
                    
                    isVoiceBroadcastEnabled = !isVoiceBroadcastEnabled;
                    localStorage.setItem('voiceBroadcastEnabled', isVoiceBroadcastEnabled);
                    updateVoiceToggleUI();

                    if (!isVoiceBroadcastEnabled) {
                        // 如果关闭语音播报，停止当前播放
                        window.stopVoiceBroadcast();
                        showToast('语音播报已关闭');
                    } else {
                        showToast('语音播报已开启');
                    }
                });
            }

            // 初始化语音播报开关
            initVoiceBroadcastToggle();
            // ==================== 语音播报功能结束 ====================

            // 每日热点按钮点击事件（动态绑定）
            function loadHotTopics() {
                fetch('hot_topics_api.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.data.topics && data.data.topics.length > 0) {
                            const container = document.getElementById('hotTopicsContainer');
                            container.innerHTML = '';
                            data.data.topics.forEach((topic, index) => {
                                const btn = document.createElement('button');
                                btn.className = 'hot-topic-btn';
                                btn.dataset.topic = topic.topic;
                                btn.textContent = topic.topic;
                                btn.style.animationDelay = (index * 0.05 + 0.05) + 's';
                                btn.addEventListener('click', function() {
                                    const topicText = this.dataset.topic;
                                    messageInput.value = topicText;
                                    const sendButton = document.getElementById('sendBtn');
                                    if (sendButton) {
                                        let imgElement = sendButton.querySelector('img');
                                        sendButton.setAttribute('data-state', 'stop');
                                        sendButton.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="#ffffff" xmlns="http://www.w3.org/2000/svg" class="block size-18"><g clip-path="url(#clip0_299_3088)"><path d="M12 0.5C18.3513 0.5 23.5 5.64873 23.5 12C23.5 18.3513 18.3513 23.5 12 23.5C5.64873 23.5 0.5 18.3513 0.5 12C0.5 5.64873 5.64873 0.5 12 0.5ZM12 2.5C6.75329 2.5 2.5 6.75329 2.5 12C2.5 17.2467 6.75329 21.5 12 21.5C17.2467 21.5 21.5 17.2467 21.5 12C21.5 6.75329 17.2467 2.5 12 2.5ZM12.5 7.5C14.3856 7.5 15.3283 7.50015 15.9141 8.08594C16.4998 8.67172 16.5 9.61438 16.5 11.5V12.5C16.5 14.3856 16.4998 15.3283 15.9141 15.9141C15.3283 16.4998 14.3856 16.5 12.5 16.5H11.5C9.61438 16.5 8.67172 16.4998 8.08594 15.9141C7.50015 15.3283 7.5 14.3856 7.5 12.5V11.5C7.5 9.61438 7.50015 8.67172 8.08594 8.08594C8.67172 7.50015 9.61438 7.5 11.5 7.5H12.5Z" fill="#ffffff"></path></g><defs><clipPath id="clip0_299_3088"><rect width="24" height="24" fill="#ffffff"></rect></clipPath></defs></svg>';
                                        // 启动卡顿检测器
                                        if (typeof startStallDetector === 'function') {
                                            startStallDetector();
                                        }
                                    }
                                    document.querySelector('.main-title').style.display = 'none';
                                    const hotTopicsContainer = document.querySelector('.hot-topics-container');
                                    if (hotTopicsContainer) hotTopicsContainer.style.display = 'none';
                                    sendMessage();
                                });
                                container.appendChild(btn);
                            });
                        }
                    })
                    .catch(error => {
                        
                    });
            }
            loadHotTopics();
            
            // 翻译按钮点击事件
            const translationBtn = document.getElementById('translationBtn');
            if (translationBtn) {
                translationBtn.addEventListener('click', function() {
                    isTranslationMode = !isTranslationMode;
                    this.classList.toggle('active');
                    
                    if (isTranslationMode) {
                        // 确保使用 Kimi 模型
                        currentModel = 'kimi';
                        // 更新模型选择下拉框
                        const modelSelect = document.getElementById('modelSelect');
                        const modelSelectValue = document.getElementById('modelSelectValue');
                        if (modelSelect) {
                            modelSelect.value = 'kimi';
                        }
                        if (modelSelectValue) {
                            modelSelectValue.textContent = 'Kimi';
                        }
                        // 关闭深度思考、专家模型和专精模式
                        deepThinkingLabel.classList.remove('active');
                        expertLabel.classList.remove('expert-active');
                        isExpertMode = false;
                        if (specialistLabel) {
                            specialistLabel.classList.remove('specialist-active');
                        }
                        isSpecialistMode = false;
                        specialistRouteInfo = null;
                        if (typeof updateDeepSeekSelectorsVisibility === 'function') {
                            updateDeepSeekSelectorsVisibility();
                        }
                        // 清空已上传的图片
                        uploadedImages = [];
                        document.getElementById('uploadContainer').innerHTML = '';
                        // 如果编程模式开启，关闭它
                        if (isProgrammingMode) {
                            isProgrammingMode = false;
                            const programmingBtn = document.getElementById('programmingBtn');
                            if (programmingBtn) {
                                programmingBtn.classList.remove('active');
                            }
                        }
                        // 如果写作模式开启，关闭它
                        if (isWritingMode) {
                            isWritingMode = false;
                            const writingBtn = document.getElementById('writingBtn');
                            if (writingBtn) {
                                writingBtn.classList.remove('active');
                            }
                        }
                        // 如果深入研究模式开启，关闭它
                        if (isResearchMode) {
                            isResearchMode = false;
                            const researchBtn = document.getElementById('researchBtn');
                            if (researchBtn) {
                                researchBtn.classList.remove('active');
                            }
                        }
                        // 如果文言文翻译模式开启，关闭它
                        if (isClassicalMode) {
                            isClassicalMode = false;
                            const classicalBtn = document.getElementById('classicalBtn');
                            if (classicalBtn) {
                                classicalBtn.classList.remove('active');
                            }
                        }
                        showToast('已进入翻译模式');
                    } else {
                        showToast('已退出翻译模式');
                    }

                });
            }
            
            // 编程按钮点击事件
            const programmingBtn = document.getElementById('programmingBtn');
            if (programmingBtn) {
                programmingBtn.addEventListener('click', function() {
                    isProgrammingMode = !isProgrammingMode;
                    this.classList.toggle('active');
                    
                    if (isProgrammingMode) {
                        // 关闭深度思考、专家模型和专精模式
                        deepThinkingLabel.classList.remove('active');
                        expertLabel.classList.remove('expert-active');
                        isExpertMode = false;
                        if (specialistLabel) {
                            specialistLabel.classList.remove('specialist-active');
                        }
                        isSpecialistMode = false;
                        specialistRouteInfo = null;
                        if (typeof updateDeepSeekSelectorsVisibility === 'function') {
                            updateDeepSeekSelectorsVisibility();
                        }
                        // 清空已上传的图片
                        uploadedImages = [];
                        document.getElementById('uploadContainer').innerHTML = '';
                        // 如果翻译模式开启，关闭它
                        if (isTranslationMode) {
                            isTranslationMode = false;
                            const translationBtn = document.getElementById('translationBtn');
                            if (translationBtn) {
                                translationBtn.classList.remove('active');
                            }
                        }
                        // 如果写作模式开启，关闭它
                        if (isWritingMode) {
                            isWritingMode = false;
                            const writingBtn = document.getElementById('writingBtn');
                            if (writingBtn) {
                                writingBtn.classList.remove('active');
                            }
                        }
                        // 如果深入研究模式开启，关闭它
                        if (isResearchMode) {
                            isResearchMode = false;
                            const researchBtn = document.getElementById('researchBtn');
                            if (researchBtn) {
                                researchBtn.classList.remove('active');
                            }
                        }
                        // 如果文言文翻译模式开启，关闭它
                        if (isClassicalMode) {
                            isClassicalMode = false;
                            const classicalBtn = document.getElementById('classicalBtn');
                            if (classicalBtn) {
                                classicalBtn.classList.remove('active');
                            }
                        }
                        showToast('已进入编程模式');
                    } else {
                        showToast('已退出编程模式');
                    }
                    if (typeof updateMoreFeaturesActiveState === 'function') {
                        updateMoreFeaturesActiveState();
                    }
                });
            }
            
            // 写作按钮点击事件
            const writingBtn = document.getElementById('writingBtn');
            if (writingBtn) {
                writingBtn.addEventListener('click', function() {
                    isWritingMode = !isWritingMode;
                    this.classList.toggle('active');
                    
                    if (isWritingMode) {
                        // 确保使用 Kimi 模型
                        currentModel = 'kimi';
                        // 更新模型选择下拉框
                        const modelSelect = document.getElementById('modelSelect');
                        const modelSelectValue = document.getElementById('modelSelectValue');
                        if (modelSelect) {
                            modelSelect.value = 'kimi';
                        }
                        if (modelSelectValue) {
                            modelSelectValue.textContent = 'Kimi';
                        }
                        // 关闭深度思考、专家模型和专精模式
                        deepThinkingLabel.classList.remove('active');
                        expertLabel.classList.remove('expert-active');
                        isExpertMode = false;
                        if (specialistLabel) {
                            specialistLabel.classList.remove('specialist-active');
                        }
                        isSpecialistMode = false;
                        specialistRouteInfo = null;
                        if (typeof updateDeepSeekSelectorsVisibility === 'function') {
                            updateDeepSeekSelectorsVisibility();
                        }
                        // 清空已上传的图片
                        uploadedImages = [];
                        document.getElementById('uploadContainer').innerHTML = '';
                        // 如果编程模式开启，关闭它
                        if (isProgrammingMode) {
                            isProgrammingMode = false;
                            const programmingBtn = document.getElementById('programmingBtn');
                            if (programmingBtn) {
                                programmingBtn.classList.remove('active');
                            }
                        }
                        // 如果翻译模式开启，关闭它
                        if (isTranslationMode) {
                            isTranslationMode = false;
                            const translationBtn = document.getElementById('translationBtn');
                            if (translationBtn) {
                                translationBtn.classList.remove('active');
                            }
                        }
                        // 如果深入研究模式开启，关闭它
                        if (isResearchMode) {
                            isResearchMode = false;
                            const researchBtn = document.getElementById('researchBtn');
                            if (researchBtn) {
                                researchBtn.classList.remove('active');
                            }
                        }
                        // 如果文言文翻译模式开启，关闭它
                        if (isClassicalMode) {
                            isClassicalMode = false;
                            const classicalBtn = document.getElementById('classicalBtn');
                            if (classicalBtn) {
                                classicalBtn.classList.remove('active');
                            }
                        }
                        showToast('已进入写作模式');
                    } else {
                        showToast('已退出写作模式');
                    }
                    if (typeof updateMoreFeaturesActiveState === 'function') {
                        updateMoreFeaturesActiveState();
                    }
                });
            }
            
            // 深入研究按钮点击事件
            const researchBtn = document.getElementById('researchBtn');
            if (researchBtn) {
                researchBtn.addEventListener('click', function() {
                    isResearchMode = !isResearchMode;
                    this.classList.toggle('active');
                    
                    if (isResearchMode) {
                        // 确保使用 DeepSeek 模型
                        currentModel = 'deepseek';
                        // 更新模型选择下拉框
                        const modelSelect = document.getElementById('modelSelect');
                        const modelSelectValue = document.getElementById('modelSelectValue');
                        if (modelSelect) {
                            modelSelect.value = 'deepseek';
                        }
                        if (modelSelectValue) {
                            modelSelectValue.textContent = 'DeepSeek';
                        }
                        // 关闭深度思考、专家模型和专精模式
                        deepThinkingLabel.classList.remove('active');
                        expertLabel.classList.remove('expert-active');
                        isExpertMode = false;
                        if (specialistLabel) {
                            specialistLabel.classList.remove('specialist-active');
                        }
                        isSpecialistMode = false;
                        specialistRouteInfo = null;
                        if (typeof updateDeepSeekSelectorsVisibility === 'function') {
                            updateDeepSeekSelectorsVisibility();
                        }
                        // 清空已上传的图片
                        uploadedImages = [];
                        document.getElementById('uploadContainer').innerHTML = '';
                        // 如果编程模式开启，关闭它
                        if (isProgrammingMode) {
                            isProgrammingMode = false;
                            const programmingBtn = document.getElementById('programmingBtn');
                            if (programmingBtn) {
                                programmingBtn.classList.remove('active');
                            }
                        }
                        // 如果翻译模式开启，关闭它
                        if (isTranslationMode) {
                            isTranslationMode = false;
                            const translationBtn = document.getElementById('translationBtn');
                            if (translationBtn) {
                                translationBtn.classList.remove('active');
                            }
                        }
                        // 如果写作模式开启，关闭它
                        if (isWritingMode) {
                            isWritingMode = false;
                            const writingBtn = document.getElementById('writingBtn');
                            if (writingBtn) {
                                writingBtn.classList.remove('active');
                            }
                        }
                        // 如果文言文翻译模式开启，关闭它
                        if (isClassicalMode) {
                            isClassicalMode = false;
                            const classicalBtn = document.getElementById('classicalBtn');
                            if (classicalBtn) {
                                classicalBtn.classList.remove('active');
                            }
                        }
                        showToast('已进入深入研究模式');
                    } else {
                        showToast('已退出深入研究模式');
                    }
                    if (typeof updateMoreFeaturesActiveState === 'function') {
                        updateMoreFeaturesActiveState();
                    }
                });
            }
            
            // 文言文翻译按钮点击事件
            const classicalBtn = document.getElementById('classicalBtn');
            if (classicalBtn) {
                classicalBtn.addEventListener('click', function() {
                    isClassicalMode = !isClassicalMode;
                    this.classList.toggle('active');
                    
                    if (isClassicalMode) {
                        // 使用运行时配置的当前 Kimi 模型。
                        currentModel = 'kimi';
                        // 更新模型选择下拉框
                        const modelSelect = document.getElementById('modelSelect');
                        const modelSelectValue = document.getElementById('modelSelectValue');
                        if (modelSelect) {
                            modelSelect.value = 'kimi';
                        }
                        if (modelSelectValue) {
                            modelSelectValue.textContent = 'Kimi';
                        }
                        // 关闭深度思考、专家模型和专精模式
                        deepThinkingLabel.classList.remove('active');
                        expertLabel.classList.remove('expert-active');
                        isExpertMode = false;
                        if (specialistLabel) {
                            specialistLabel.classList.remove('specialist-active');
                        }
                        isSpecialistMode = false;
                        specialistRouteInfo = null;
                        if (typeof updateDeepSeekSelectorsVisibility === 'function') {
                            updateDeepSeekSelectorsVisibility();
                        }
                        // 清空已上传的图片
                        uploadedImages = [];
                        document.getElementById('uploadContainer').innerHTML = '';
                        // 如果编程模式开启，关闭它
                        if (isProgrammingMode) {
                            isProgrammingMode = false;
                            const programmingBtn = document.getElementById('programmingBtn');
                            if (programmingBtn) {
                                programmingBtn.classList.remove('active');
                            }
                        }
                        // 如果翻译模式开启，关闭它
                        if (isTranslationMode) {
                            isTranslationMode = false;
                            const translationBtn = document.getElementById('translationBtn');
                            if (translationBtn) {
                                translationBtn.classList.remove('active');
                            }
                        }
                        // 如果写作模式开启，关闭它
                        if (isWritingMode) {
                            isWritingMode = false;
                            const writingBtn = document.getElementById('writingBtn');
                            if (writingBtn) {
                                writingBtn.classList.remove('active');
                            }
                        }
                        // 如果深入研究模式开启，关闭它
                        if (isResearchMode) {
                            isResearchMode = false;
                            const researchBtn = document.getElementById('researchBtn');
                            if (researchBtn) {
                                researchBtn.classList.remove('active');
                            }
                        }
                        showToast('已进入文言文翻译模式');
                    } else {
                        showToast('已退出文言文翻译模式');
                    }
                    if (typeof updateMoreFeaturesActiveState === 'function') {
                        updateMoreFeaturesActiveState();
                    }
                });
            }
            
            // 音乐按钮点击事件
            const musicBtn = document.getElementById('musicBtn');
            if (musicBtn) {
                musicBtn.addEventListener('click', async function() {
                    // 检查登录状态
                    if (!isLoggedIn) {
                        showToast('请先登录');
                        openAuthModal();
                        return;
                    }
                    
                    // 防止重复发送
                    if (window.isSendingMessage) {
                        return;
                    }
                    
                    // 添加激活状态
                    this.classList.add('active');
                    isMusicMode = true;
                    if (typeof updateMoreFeaturesActiveState === 'function') {
                        updateMoreFeaturesActiveState();
                    }
                    
                    // 直接处理音乐请求
                    await handleMusicRequest('随便来点音乐吧～');
                });
            }
            
            const videoBtn = document.getElementById('videoBtn');
            if (videoBtn) {
                videoBtn.addEventListener('click', function() {
                    if (!window.WEB_RUNTIME_CONFIG?.videoPortalUrl) throw new Error('Missing required configuration: video_portal.url');
                    window.open(window.WEB_RUNTIME_CONFIG.videoPortalUrl, '_blank');
                });
            }
            
            const horoscopeBtn = document.getElementById('horoscopeBtn');
            if (horoscopeBtn) {
                horoscopeBtn.addEventListener('click', async function() {
                    if (!isLoggedIn) {
                        showToast('请先登录');
                        openAuthModal();
                        return;
                    }
                    
                    if (window.isSendingMessage) {
                        return;
                    }
                    
                    this.classList.add('active');
                    isHoroscopeMode = true;
                    if (typeof updateMoreFeaturesActiveState === 'function') {
                        updateMoreFeaturesActiveState();
                    }
                    
                    await handleHoroscopeRequest();
                });
            }
            
            const weatherBtn = document.getElementById('weatherBtn');
            if (weatherBtn) {
                weatherBtn.addEventListener('click', async function() {
                    if (!isLoggedIn) {
                        showToast('请先登录');
                        openAuthModal();
                        return;
                    }
                    
                    if (window.isSendingMessage) {
                        return;
                    }
                    
                    this.classList.add('active');
                    isWeatherMode = true;
                    if (typeof updateMoreFeaturesActiveState === 'function') {
                        updateMoreFeaturesActiveState();
                    }
                    
                    await handleWeatherRequest();
                });
            }
            
            const imageGenBtn = document.getElementById('imageGenBtn');
            if (imageGenBtn) {
                imageGenBtn.addEventListener('click', function() {
                    isImageGenMode = !isImageGenMode;
                    const fileCard = document.getElementById('fileCard');
                    if (isImageGenMode) {
                        this.classList.add('active');
                        isVideoGenMode = false;
                        videoGenRefImages = [];
                        const moreVideoGen = document.getElementById('moreVideoGen'); if (moreVideoGen) moreVideoGen.classList.remove('active');
                        if (fileCard) fileCard.style.display = 'none';
                        isWritingMode = false; isTranslationMode = false; isProgrammingMode = false;
                        isResearchMode = false; isClassicalMode = false;
                        const writingBtn = document.getElementById('writingBtn'); if (writingBtn) writingBtn.classList.remove('active');
                        const translationBtn = document.getElementById('translationBtn'); if (translationBtn) translationBtn.classList.remove('active');
                        const programmingBtn = document.getElementById('programmingBtn'); if (programmingBtn) programmingBtn.classList.remove('active');
                        const researchBtn = document.getElementById('researchBtn'); if (researchBtn) researchBtn.classList.remove('active');
                        const classicalBtn = document.getElementById('classicalBtn'); if (classicalBtn) classicalBtn.classList.remove('active');
                        if (deepThinkingLabel) deepThinkingLabel.classList.remove('active');
                        if (expertLabel) expertLabel.classList.remove('expert-active');
                        isExpertMode = false;
                        if (specialistLabel) specialistLabel.classList.remove('specialist-active');
                        isSpecialistMode = false;
                        showToast('已开启图片生成，选择比例后输入描述即可');
                    } else {
                        this.classList.remove('active');
                        if (fileCard) fileCard.style.display = '';
                        showToast('已关闭图片生成');
                    }
                    if (typeof updateMoreFeaturesActiveState === 'function') {
                        updateMoreFeaturesActiveState();
                    }
                    updateDeepSeekSelectorsVisibility();
                });
            }
            
            const imageGenClose = document.getElementById('imageGenClose');
            if (imageGenClose) {
                imageGenClose.addEventListener('click', function(e) {
                    e.stopPropagation();
                    isImageGenMode = false;
                    if (imageGenBtn) imageGenBtn.classList.remove('active');
                    const fCard = document.getElementById('fileCard');
                    if (fCard) fCard.style.display = '';
                    if (typeof updateMoreFeaturesActiveState === 'function') {
                        updateMoreFeaturesActiveState();
                    }
                    updateDeepSeekSelectorsVisibility();
                    showToast('已关闭图片生成');
                });
            }

            const videoGenClose = document.getElementById('videoGenClose');
            if (videoGenClose) {
                videoGenClose.addEventListener('click', function(e) {
                    e.stopPropagation();
                    isVideoGenMode = false;
                    videoGenRefImages = [];
                    const moreVideoGen = document.getElementById('moreVideoGen');
                    if (moreVideoGen) moreVideoGen.classList.remove('active');
                    if (typeof updateMoreFeaturesActiveState === 'function') {
                        updateMoreFeaturesActiveState();
                    }
                    updateDeepSeekSelectorsVisibility();
                    showToast('已关闭视频生成');
                });
            }
            
            const aspectRatioBtn = document.getElementById('aspectRatioBtn');
            const aspectRatioDropdown = document.getElementById('aspectRatioDropdown');
            if (aspectRatioBtn) {
                aspectRatioBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (aspectRatioDropdown) aspectRatioDropdown.classList.toggle('show');
                    aspectRatioBtn.classList.toggle('open');
                });
            }
            const aspectRatioOptions = document.querySelectorAll('.aspect-ratio-option');
            aspectRatioOptions.forEach(option => {
                option.addEventListener('click', function(e) {
                    e.stopPropagation();
                    aspectRatio = this.dataset.ratio;
                    document.getElementById('aspectRatioBtnText').textContent = aspectRatio;
                    if (aspectRatioDropdown) aspectRatioDropdown.classList.remove('show');
                    if (aspectRatioBtn) aspectRatioBtn.classList.remove('open');
                    aspectRatioOptions.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    showToast(`图片比例: ${aspectRatio}`);
                });
            });

            // 视频生成参数选择器 - 质量
            const videoQualityBtn = document.getElementById('videoQualityBtn');
            const videoQualityDropdown = document.getElementById('videoQualityDropdown');
            if (videoQualityBtn) {
                videoQualityBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (videoQualityDropdown) videoQualityDropdown.classList.toggle('show');
                    videoQualityBtn.classList.toggle('open');
                });
            }
            const videoQualityOptions = document.querySelectorAll('.video-quality-option');
            videoQualityOptions.forEach(option => {
                option.addEventListener('click', function(e) {
                    e.stopPropagation();
                    videoGenQuality = this.dataset.quality;
                    document.getElementById('videoQualityBtnText').textContent = this.querySelector('span').textContent;
                    if (videoQualityDropdown) videoQualityDropdown.classList.remove('show');
                    if (videoQualityBtn) videoQualityBtn.classList.remove('open');
                    videoQualityOptions.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    showToast(`视频质量: ${this.querySelector('span').textContent}`);
                });
            });

            // 视频生成参数选择器 - 尺寸
            const videoSizeBtn = document.getElementById('videoSizeBtn');
            const videoSizeDropdown = document.getElementById('videoSizeDropdown');
            if (videoSizeBtn) {
                videoSizeBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (videoSizeDropdown) videoSizeDropdown.classList.toggle('show');
                    videoSizeBtn.classList.toggle('open');
                });
            }
            const videoSizeOptions = document.querySelectorAll('.video-size-option');
            videoSizeOptions.forEach(option => {
                option.addEventListener('click', function(e) {
                    e.stopPropagation();
                    videoGenSize = this.dataset.size;
                    document.getElementById('videoSizeBtnText').textContent = videoGenSize;
                    if (videoSizeDropdown) videoSizeDropdown.classList.remove('show');
                    if (videoSizeBtn) videoSizeBtn.classList.remove('open');
                    videoSizeOptions.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    showToast(`视频尺寸: ${videoGenSize}`);
                });
            });

            // 视频生成参数选择器 - FPS
            const videoFpsBtn = document.getElementById('videoFpsBtn');
            const videoFpsDropdown = document.getElementById('videoFpsDropdown');
            if (videoFpsBtn) {
                videoFpsBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (videoFpsDropdown) videoFpsDropdown.classList.toggle('show');
                    videoFpsBtn.classList.toggle('open');
                });
            }
            const videoFpsOptions = document.querySelectorAll('.video-fps-option');
            videoFpsOptions.forEach(option => {
                option.addEventListener('click', function(e) {
                    e.stopPropagation();
                    videoGenFps = parseInt(this.dataset.fps);
                    document.getElementById('videoFpsBtnText').textContent = videoGenFps + 'fps';
                    if (videoFpsDropdown) videoFpsDropdown.classList.remove('show');
                    if (videoFpsBtn) videoFpsBtn.classList.remove('open');
                    videoFpsOptions.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    showToast(`视频帧率: ${videoGenFps}fps`);
                });
            });

            // 视频生成参数选择器 - 时长
            const videoDurationBtn = document.getElementById('videoDurationBtn');
            const videoDurationDropdown = document.getElementById('videoDurationDropdown');
            if (videoDurationBtn) {
                videoDurationBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (videoDurationDropdown) videoDurationDropdown.classList.toggle('show');
                    videoDurationBtn.classList.toggle('open');
                });
            }
            const videoDurationOptions = document.querySelectorAll('.video-duration-option');
            videoDurationOptions.forEach(option => {
                option.addEventListener('click', function(e) {
                    e.stopPropagation();
                    videoGenDuration = parseInt(this.dataset.duration);
                    document.getElementById('videoDurationBtnText').textContent = videoGenDuration + '秒';
                    if (videoDurationDropdown) videoDurationDropdown.classList.remove('show');
                    if (videoDurationBtn) videoDurationBtn.classList.remove('open');
                    videoDurationOptions.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    showToast(`视频时长: ${videoGenDuration}秒`);
                });
            });

            // AI音效切换
            const videoAudioBtn = document.getElementById('videoAudioBtn');
            if (videoAudioBtn) {
                videoAudioBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    videoGenWithAudio = !videoGenWithAudio;
                    if (videoGenWithAudio) {
                        this.classList.add('active');
                        showToast('AI音效: 已开启');
                    } else {
                        this.classList.remove('active');
                        showToast('AI音效: 已关闭');
                    }
                });
            }

            // 点击其他地方关闭视频生成下拉
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.video-quality-selector')) {
                    if (videoQualityDropdown) videoQualityDropdown.classList.remove('show');
                    if (videoQualityBtn) videoQualityBtn.classList.remove('open');
                }
                if (!e.target.closest('.video-size-selector')) {
                    if (videoSizeDropdown) videoSizeDropdown.classList.remove('show');
                    if (videoSizeBtn) videoSizeBtn.classList.remove('open');
                }
                if (!e.target.closest('.video-fps-selector')) {
                    if (videoFpsDropdown) videoFpsDropdown.classList.remove('show');
                    if (videoFpsBtn) videoFpsBtn.classList.remove('open');
                }
                if (!e.target.closest('.video-duration-selector')) {
                    if (videoDurationDropdown) videoDurationDropdown.classList.remove('show');
                    if (videoDurationBtn) videoDurationBtn.classList.remove('open');
                }
            });

            // 点击其他地方关闭比例下拉
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.aspect-ratio-selector')) {
                    if (aspectRatioDropdown) aspectRatioDropdown.classList.remove('show');
                    if (aspectRatioBtn) aspectRatioBtn.classList.remove('open');
                }
            });

            // 视频播放器
            window.openVideoPlayer = function(videoUrl) {
                const overlay = document.getElementById('videoPlayerOverlay');
                const video = document.getElementById('videoPlayer');
                video.src = videoUrl;
                overlay.classList.add('show');
            }
            const videoPlayerCloseBtn = document.getElementById('videoPlayerClose');
            if (videoPlayerCloseBtn) {
                videoPlayerCloseBtn.addEventListener('click', function() {
                    const overlay = document.getElementById('videoPlayerOverlay');
                    const video = document.getElementById('videoPlayer');
                    video.pause();
                    video.src = '';
                    overlay.classList.remove('show');
                });
            }
            const videoPlayerOverlayEl = document.getElementById('videoPlayerOverlay');
            if (videoPlayerOverlayEl) {
                videoPlayerOverlayEl.addEventListener('click', function(e) {
                    if (e.target === this) {
                        const video = document.getElementById('videoPlayer');
                        video.pause();
                        video.src = '';
                        this.classList.remove('show');
                    }
                });
            }
            
            // 直接绑定点击事件到发送按钮，不使用事件委托
            const sendBtnElement = document.getElementById('sendBtn');
            if (sendBtnElement) {
                // 移除旧的事件监听器（如果有的话），防止重复绑定
                sendBtnElement.replaceWith(sendBtnElement.cloneNode(true));
                const newSendBtnElement = document.getElementById('sendBtn');
                
                // 添加点击时间戳，防止重复触发
                let lastClickTime = 0;
                
                newSendBtnElement.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // 防止300ms内的重复点击
                    const now = Date.now();
                    if (now - lastClickTime < 300) {
                        
                        return;
                    }
                    lastClickTime = now;
                    
                    
                    
                    // 点击停止与回车停止共用同一处理函数。
                    if (currentAbortController || window.isSendingMessage) {
                        if (typeof window.stopCurrentMoonYaResponse === 'function') {
                            window.stopCurrentMoonYaResponse();
                        }
                        return;
                    }
                    
                    // 防止重复点击
                    if (window.isSendingMessage) {
                        
                        return;
                    }
                    
                    // 检查输入框是否为空
                    const message = messageInput.value.trim();
                    const hasImages = uploadedImages.length > 0;
                    if (!message && !hasImages) {
                        
                        return;
                    }
                    
                    // 先切换按钮为停止图标，再发送消息
                    
                    const btn = document.getElementById('sendBtn');
                    if (btn) {
                        btn.setAttribute('data-state', 'stop');
                        btn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="#ffffff" xmlns="http://www.w3.org/2000/svg" class="block size-18"><g clip-path="url(#clip0_299_3088)"><path d="M12 0.5C18.3513 0.5 23.5 5.64873 23.5 12C23.5 18.3513 18.3513 23.5 12 23.5C5.64873 23.5 0.5 18.3513 0.5 12C0.5 5.64873 5.64873 0.5 12 0.5ZM12 2.5C6.75329 2.5 2.5 6.75329 2.5 12C2.5 17.2467 6.75329 21.5 12 21.5C17.2467 21.5 21.5 17.2467 21.5 12C21.5 6.75329 17.2467 2.5 12 2.5ZM12.5 7.5C14.3856 7.5 15.3283 7.50015 15.9141 8.08594C16.4998 8.67172 16.5 9.61438 16.5 11.5V12.5C16.5 14.3856 16.4998 15.3283 15.9141 15.9141C15.3283 16.4998 14.3856 16.5 12.5 16.5H11.5C9.61438 16.5 8.67172 16.4998 8.08594 15.9141C7.50015 15.3283 7.5 14.3856 7.5 12.5V11.5C7.5 9.61438 7.50015 8.67172 8.08594 8.08594C8.67172 7.50015 9.61438 7.5 11.5 7.5H12.5Z" fill="#ffffff"></path></g><defs><clipPath id="clip0_299_3088"><rect width="24" height="24" fill="#ffffff"></rect></clipPath></defs></svg>';
                        
                    } else {
                        
                    }
                    
                    // 发送消息
                    
                    sendMessage();
                });
            }
            
            (async () => {
            await checkUserSession();
            // 初始化对话历史
            if (isLoggedIn) {
                    let history = getChatHistory();
                    // localStorage 为空/换域名/数据丢失时，尝试从服务器恢复对话列表
                    if (history.length === 0) {
                        await syncChatHistoryFromServer();
                        history = getChatHistory();
                    }
                    // 一次性清理历史列表中堆积的空对话（本地创建但未发送消息的），仅保留最新 1 个
                    // 有 dbConversationId 但 messages:[] 的是从数据库同步的对话，数据库里可能有消息，不能删除
                    const emptyChats = history.filter(chat => chat.messages.length === 0 && !chat.dbConversationId);
                    if (emptyChats.length > 1) {
                        const keepId = emptyChats[0].id;
                        history = history.filter(chat => chat.messages.length > 0 || chat.dbConversationId || chat.id === keepId);
                        saveChatHistory(history);
                    }
                    // 启动时不再自动加载历史对话内容，直接显示新对话首页。
                    // currentChatId 保持为空，发送第一条消息时会复用空对话或创建新的（见 sendMessage）。
                    // 左侧「最近对话」列表仍保留所有历史，用户可点击查看。
                    renderChatList();
                    // 清空对话区域残留 DOM（防止会话恢复等导致历史消息残留），确保显示新对话首页
                    if (messagesContainer) messagesContainer.innerHTML = '';
                    const mainTitle = document.querySelector('.main-title');
                    if (mainTitle) mainTitle.style.display = 'block';
                    const hotTopicsContainer = document.querySelector('.hot-topics-container');
                    if (hotTopicsContainer) hotTopicsContainer.style.display = 'flex';
                }
            })();
            

        });
        
async function checkUserSession() {
    try {
        const response = await fetch('user_auth.php?action=check_session', {
            method: 'GET',
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error('网络响应错误');
        }
        
        const data = await response.json();
        
        if (data.success && data.data.logged_in) {
            isLoggedIn = true;
            if (window.MoonYaOffice) window.MoonYaOffice.setAuthenticated(true);
            currentUser = data.data.user;
            // 设置用户专属的localStorage key
            CHAT_HISTORY_KEY = 'chat_history_' + currentUser.id;
            
            userName.textContent = currentUser.real_name || currentUser.username;
            userQQ.textContent = `账号: ${currentUser.username}`;
            qqLoginBtn.style.display = 'none';
            
            if (currentUser.avatar) {
                userAvatar.src = currentUser.avatar;
            }
        } else {
            isLoggedIn = false;
            if (window.MoonYaOffice) window.MoonYaOffice.setAuthenticated(false);
            currentUser = null;
            // 未登录，不再自动弹出登录弹窗，用户可点击登录按钮手动打开
        }
    } catch (error) {
        console.error('检查会话失败:', error);
        isLoggedIn = false;
        if (window.MoonYaOffice) window.MoonYaOffice.setAuthenticated(false);
        currentUser = null;
        // 检查失败也不再自动弹出登录弹窗
    }
}
        
        async function loadUserAvatar() {
            try {
                const response = await fetch('user_profile.php?action=get_info', {
                    method: 'GET',
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                
                if (data.success && data.data.avatar) {
                    userAvatar.src = data.data.avatar;
                }
            } catch (error) {
                
            }
        }
        
        // 打开登录弹窗
        qqLoginBtn.addEventListener('click', function() {
            openAuthModal();
        });
        
        // 社区按钮点击事件
        const communityBtn = document.getElementById('communityBtn');
        if (communityBtn) {
            communityBtn.addEventListener('click', function() {
                // ★ 用 window.open 触发 C# 端 LifeSpanHandler 拦截：
                //   - 内部 URL（community/、MoonYa-main/ 等）→ 在 C# 端的新窗口中打开
                //   - 外部 URL（https://...）→ 用系统浏览器打开
                // 修复历史：
                //   1) 原实现 window.open 会被 OnBeforePopup 无差别地用 Process.Start 调起系统浏览器
                //   2) 上一轮改为 window.location.href 但用户反馈"要新窗口打开"
                //   3) 这次保留 window.open + 改进 C# 端，让内部 URL 走新窗口
                window.open('community/index.php', '_blank');
            });
        }

        // “对话”就是现有主对话视图；切换视图不会中止隐藏容器中的运行任务。
        const conversationBtn = document.getElementById('conversationBtn');
        if (conversationBtn) {
            conversationBtn.addEventListener('click', function() {
                if (window.MoonYaOffice) window.MoonYaOffice.close();
            });
        }

        // 办公室按钮点击事件（主界面内切换，不刷新页面、不打断进行中的任务）
        const officeBtn = document.getElementById('officeBtn');
        if (officeBtn) {
            officeBtn.addEventListener('click', function() {
                if (window.MoonYaOffice) {
                    window.MoonYaOffice.open();
                }
            });
        }

        // 发送验证码（注册）
        sendCodeBtn.addEventListener('click', function() {
            const qq = registerQQ.value.trim();
            if (!qq || !/^[0-9]{5,11}$/.test(qq)) {
                showToast('请输入有效的QQ号');
                return;
            }
            
            const originalText = sendCodeBtn.textContent;
            sendCodeBtn.disabled = true;
            sendCodeBtn.innerHTML = '<span class="auth-btn-loading"></span>';
            
            fetch('user_auth.php?action=send_code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: qq + '@qq.com', purpose: 'register' })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('验证码已发送');
                    sendCodeBtn.disabled = true;
                    countdown = 69;
                    sendCodeBtn.textContent = `${countdown}s`;
                    codeTimer = setInterval(() => {
                        countdown--;
                        if (countdown <= 0) {
                            clearInterval(codeTimer);
                            sendCodeBtn.disabled = false;
                            sendCodeBtn.textContent = '获取验证码';
                        } else {
                            sendCodeBtn.textContent = `${countdown}s`;
                        }
                    }, 1000);
                } else {
                    showToast(data.error || '发送失败');
                    sendCodeBtn.disabled = false;
                    sendCodeBtn.textContent = originalText;
                }
            })
            .catch(error => {
                showToast('发送验证码失败');
                sendCodeBtn.disabled = false;
                sendCodeBtn.textContent = originalText;
            });
        });
        
        // 发送验证码（登录）
        loginSendCodeBtn.addEventListener('click', function() {
            const qq = loginQQ.value.trim();
            if (!qq || !/^[0-9]{5,11}$/.test(qq)) {
                showToast('请输入有效的QQ号');
                return;
            }
            
            const originalText = loginSendCodeBtn.textContent;
            loginSendCodeBtn.disabled = true;
            loginSendCodeBtn.innerHTML = '<span class="auth-btn-loading"></span>';
            
            fetch('user_auth.php?action=send_code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: qq + '@qq.com', purpose: 'login' })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('验证码已发送');
                    loginSendCodeBtn.disabled = true;
                    loginCountdown = 69;
                    loginSendCodeBtn.textContent = `${loginCountdown}s`;
                    loginCodeTimer = setInterval(() => {
                        loginCountdown--;
                        if (loginCountdown <= 0) {
                            clearInterval(loginCodeTimer);
                            loginSendCodeBtn.disabled = false;
                            loginSendCodeBtn.textContent = '获取验证码';
                        } else {
                            loginSendCodeBtn.textContent = `${loginCountdown}s`;
                        }
                    }, 1000);
                } else {
                    showToast(data.error || '发送失败');
                    loginSendCodeBtn.disabled = false;
                    loginSendCodeBtn.textContent = originalText;
                }
            })
            .catch(error => {
                showToast('发送验证码失败');
                loginSendCodeBtn.disabled = false;
                loginSendCodeBtn.textContent = originalText;
            });
        });
        
        // 登录按钮点击事件（账号密码）
        loginBtn.addEventListener('click', async function() {
            if (!loginAccount.value.trim() || !loginPassword.value.trim()) {
                showToast('请输入账号和密码');
                return;
            }
            
            try {
                const response = await fetch('user_auth.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ account: loginAccount.value.trim(), password: loginPassword.value })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    isLoggedIn = true;
                    if (window.MoonYaOffice) window.MoonYaOffice.setAuthenticated(true);
                    currentUser = data.data.user;
                    
                    if (data.data.token) {
                        localStorage.setItem('api_token', data.data.token);
                        localStorage.setItem('api_token_expires', Date.now() + data.data.expires_in * 1000);
                    }
                    
                    CHAT_HISTORY_KEY = 'chat_history_' + currentUser.id;
                    
                    if (currentUser.avatar) userAvatar.src = currentUser.avatar;
                    userAvatar.style.display = 'block';
                    avatarPlaceholder.style.display = 'none';
                    userName.textContent = currentUser.real_name || currentUser.username;
                    userQQ.textContent = `账号: ${currentUser.username}`;
                    qqLoginBtn.style.display = 'none';
                    closeAuthModal();
                    
                    loginAccount.value = '';
                    loginPassword.value = '';
                    showToast('登录成功');
                    completeCommunityLogin();
                    
                    let history = getChatHistory();
                    if (history.length === 0) {
                        await syncChatHistoryFromServer();
                        history = getChatHistory();
                    }
                    if (history.length > 0) {
                        renderChatList();
                        await loadChat(history[0].id);
                    }
                } else {
                    showToast(data.error || '登录失败');
                }
            } catch (error) {
                showToast('登录失败，请稍后重试');
            }
        });
        
        // 邮箱验证码登录按钮点击事件
        loginByCodeBtn.addEventListener('click', async function() {
            const qq = loginQQ.value.trim();
            if (!qq || !/^[0-9]{5,11}$/.test(qq) || !loginCode.value.trim()) {
                showToast('请输入QQ号和验证码');
                return;
            }
            
            try {
                const response = await fetch('user_auth.php?action=login_by_code', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email: qq + '@qq.com', code: loginCode.value.trim() })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    isLoggedIn = true;
                    if (window.MoonYaOffice) window.MoonYaOffice.setAuthenticated(true);
                    currentUser = data.data.user;
                    
                    if (data.data.token) {
                        localStorage.setItem('api_token', data.data.token);
                        localStorage.setItem('api_token_expires', Date.now() + data.data.expires_in * 1000);
                    }
                    
                    CHAT_HISTORY_KEY = 'chat_history_' + currentUser.id;
                    
                    if (currentUser.avatar) userAvatar.src = currentUser.avatar;
                    userAvatar.style.display = 'block';
                    avatarPlaceholder.style.display = 'none';
                    userName.textContent = currentUser.real_name || currentUser.username;
                    userQQ.textContent = `账号: ${currentUser.username}`;
                    qqLoginBtn.style.display = 'none';
                    closeAuthModal();
                    
                    loginQQ.value = '';
                    loginCode.value = '';
                    showToast('登录成功');
                    completeCommunityLogin();
                    
                    if (loginCodeTimer) {
                        clearInterval(loginCodeTimer);
                        loginSendCodeBtn.disabled = false;
                        loginSendCodeBtn.textContent = '获取验证码';
                    }
                    
                    let history = getChatHistory();
                    if (history.length === 0) {
                        await syncChatHistoryFromServer();
                        history = getChatHistory();
                    }
                    if (history.length > 0) {
                        renderChatList();
                        await loadChat(history[0].id);
                    }
                } else {
                    showToast(data.error || '登录失败');
                }
            } catch (error) {
                showToast('登录失败，请稍后重试');
            }
        });
        
        // 注册按钮点击事件
        registerBtn.addEventListener('click', async function() {
            const qq = registerQQ.value.trim();
            if (!qq || !/^[0-9]{5,11}$/.test(qq) || !registerCode.value.trim()) {
                showToast('请输入QQ号和验证码');
                return;
            }
            
            try {
                const response = await fetch('user_auth.php?action=register', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email: qq + '@qq.com', code: registerCode.value.trim() })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    isLoggedIn = true;
                    if (window.MoonYaOffice) window.MoonYaOffice.setAuthenticated(true);
                    const user = data.data.user;
                    currentUser = user;
                    
                    if (data.data.token) {
                        localStorage.setItem('api_token', data.data.token);
                        localStorage.setItem('api_token_expires', Date.now() + data.data.expires_in * 1000);
                    }
                    
                    CHAT_HISTORY_KEY = 'chat_history_' + user.id;
                    
                    if (user.avatar) userAvatar.src = user.avatar;
                    userAvatar.style.display = 'block';
                    avatarPlaceholder.style.display = 'none';
                    userName.textContent = user.real_name || user.username;
                    userQQ.textContent = `账号: ${user.username}`;
                    qqLoginBtn.style.display = 'none';
                    closeAuthModal();
                    
                    registerQQ.value = '';
                    registerCode.value = '';
                    
                    if (codeTimer) {
                        clearInterval(codeTimer);
                        sendCodeBtn.disabled = false;
                        sendCodeBtn.textContent = '获取验证码';
                    }
                    
                    showToast('注册成功');
                    completeCommunityLogin();
                    
                    let history = getChatHistory();
                    if (history.length === 0) {
                        await syncChatHistoryFromServer();
                        history = getChatHistory();
                    }
                    if (history.length > 0) {
                        renderChatList();
                        await loadChat(history[0].id);
                    }
                } else {
                    showToast(data.error || '注册失败');
                }
            } catch (error) {
                showToast('注册失败，请稍后重试');
            }
        });
        
        // 深度思考标签点击事件
        deepThinkingLabel.addEventListener('click', function() {
            if (isProgrammingMode) {
                showToast('编程模式下禁止使用深度思考');
                return;
            }
            if (isTranslationMode) {
                showToast('翻译模式下禁止使用深度思考');
                return;
            }
            if (isWritingMode) {
                showToast('写作模式下禁止使用深度思考');
                return;
            }
            if (isResearchMode) {
                showToast('深入研究模式下禁止使用深度思考');
                return;
            }
            if (isClassicalMode) {
                showToast('文言文翻译模式下禁止使用深度思考');
                return;
            }
            if (currentModel === 'minmax' && minmaxModelVersion.indexOf('M2.7') === -1) {
                showToast('该 MiniMax 模型强制深度思考');
                return;
            }
            // 如果专家模型已开启，关闭它
            if (isExpertMode) {
                expertLabel.classList.remove('expert-active');
                isExpertMode = false;
            }
            // 如果专精模式已开启，关闭它
            if (isSpecialistMode) {
                specialistLabel.classList.remove('specialist-active');
                isSpecialistMode = false;
                specialistRouteInfo = null;
            }
            deepThinkingLabel.classList.toggle('active');
            if (configuredModelMeta('glm', glmModelVersion)?.supports_thinking === true) {
                glmThinkingEnabled = deepThinkingLabel.classList.contains('active');
            }
            updateDeepSeekSelectorsVisibility();
        });

        // 专家模型标签点击事件
        expertLabel.addEventListener('click', function() {
            if (isProgrammingMode) {
                showToast('编程模式下禁止使用专家模型');
                return;
            }
            if (isTranslationMode) {
                showToast('翻译模式下禁止使用专家模型');
                return;
            }
            if (isWritingMode) {
                showToast('写作模式下禁止使用专家模型');
                return;
            }
            if (isResearchMode) {
                showToast('深入研究模式下禁止使用专家模型');
                return;
            }
            if (isClassicalMode) {
                showToast('文言文翻译模式下禁止使用专家模型');
                return;
            }
            // 如果深度思考已开启，关闭它
            if (deepThinkingLabel.classList.contains('active')) {
                deepThinkingLabel.classList.remove('active');
                if (typeof updateDeepSeekSelectorsVisibility === 'function') {
                    updateDeepSeekSelectorsVisibility();
                }
            }
            // 如果专精模式已开启，关闭它
            if (isSpecialistMode) {
                specialistLabel.classList.remove('specialist-active');
                isSpecialistMode = false;
            }
            expertLabel.classList.toggle('expert-active');
            isExpertMode = expertLabel.classList.contains('expert-active');
        });

        // 专精模式标签点击事件
        const specialistLabel = document.getElementById('specialistLabel');

        function updateSpecialistLabelsVisibility() {
            const hideExpert = currentModel === 'deepseek' || currentModel === 'kimi';
            expertLabel.classList.toggle('hidden-model', hideExpert);
            if (specialistLabel) {
                specialistLabel.classList.toggle('hidden-model', hideExpert);
            }
        }
        updateSpecialistLabelsVisibility();

        specialistLabel.addEventListener('click', function() {
            if (isProgrammingMode) {
                showToast('编程模式下禁止使用专精模式');
                return;
            }
            if (isTranslationMode) {
                showToast('翻译模式下禁止使用专精模式');
                return;
            }
            if (isWritingMode) {
                showToast('写作模式下禁止使用专精模式');
                return;
            }
            if (isResearchMode) {
                showToast('深入研究模式下禁止使用专精模式');
                return;
            }
            if (isClassicalMode) {
                showToast('文言文翻译模式下禁止使用专精模式');
                return;
            }

            // 切换专精模式状态
            isSpecialistMode = !isSpecialistMode;
            specialistLabel.classList.toggle('specialist-active', isSpecialistMode);

            if (isSpecialistMode) {
                // 关闭其他模式
                deepThinkingLabel.classList.remove('active');
                expertLabel.classList.remove('expert-active');
                isExpertMode = false;
                if (typeof updateDeepSeekSelectorsVisibility === 'function') {
                    updateDeepSeekSelectorsVisibility();
                }
                // 不再在这里分析，改为发送消息时分析
            } else {
                specialistRouteInfo = null;
            }
        });

        // 分析用户输入并路由到合适的模型
        async function analyzeAndRoute(message) {
            try {
                const response = await fetch('model_router.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ message: message })
                });

                const result = await response.json();

                if (result.success && result.data) {
                    specialistRouteInfo = result.data;
                    return specialistRouteInfo;
                }
            } catch (error) {
                console.error('路由分析失败:', error);
            }
            return null;
        }

        // 注：移除了输入框实时分析功能，改为发送消息时显示路由分析
        
        // 切换发送按钮图标
        function toggleSendIcon(isSending) {
            // 每次都重新获取元素，确保能找到
            const img = document.getElementById('sendBtnImg');
            if (img) {
                img.src = isSending ? '/image/stop.png' : '/image/send.png';
            }
        }
        
        function renderThinkingContent(text) {
            if (!text) return '';
            let escaped = text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
            escaped = escaped.replace(/\[(.*?)\]\((https?:\/\/[^)]+)\)/g, function(match, linkText, url) {
                return `<a href="${url}" target="_blank" style="color: #999; text-decoration: underline;" rel="noopener noreferrer">${linkText}</a>`;
            });
            escaped = escaped.replace(/(https?:\/\/[\w\-._~:/?#[\]@!$&'()*+,;=.]+)/g, function(url) {
                return `<a href="${url}" target="_blank" style="color: #999; text-decoration: underline;" rel="noopener noreferrer">${url}</a>`;
            });
            escaped = escaped.replace(/\n/g, '<br>');
            return escaped;
        }

        // 将文本中的链接转换为可点击的HTML链接
        function convertLinksToHtml(text, isThinking = false) {
            // 根据是否在思考过程中决定链接颜色
            const linkStyle = isThinking ? 'color: #999; text-decoration: underline;' : 'color: #1890ff; text-decoration: underline;';
            
            // 匹配 Markdown 格式的链接 [文本](链接)，处理各种情况
            let result = text.replace(/\[(.*?)\]\((https?:\/\/[^)]+)\)/g, function(match, text, url) {
                return `<a href="${url}" target="_blank" style="${linkStyle}" rel="noopener noreferrer">${text}</a>`;
            });
            
            // 匹配纯 URL，处理各种情况
            result = result.replace(/(https?:\/\/[\w\-._~:/?#[\]@!$&'()*+,;=.]+)/g, function(url) {
                return `<a href="${url}" target="_blank" style="${linkStyle}" rel="noopener noreferrer">${url}</a>`;
            });
            
            return result;
        }
        
        // 格式化专精模式路由分析为HTML - 参考图片中的UI风格
        function formatSpecialistAnalysis(reason) {
            if (!reason) return '';
            
            // 解析路由分析文本
            const lines = reason.split('\n');
            let steps = [];
            let currentStep = null;
            
            lines.forEach(line => {
                const trimmed = line.trim();
                if (!trimmed || trimmed.includes('━━') || trimmed.includes('🔧')) return;
                
                // 检测步骤标题（如 "1. 分析请求"）
                const stepMatch = trimmed.match(/^(\d+)\.\s*(.+)/);
                if (stepMatch) {
                    if (currentStep) steps.push(currentStep);
                    currentStep = {
                        number: stepMatch[1],
                        title: stepMatch[2],
                        content: []
                    };
                } else if (currentStep) {
                    currentStep.content.push(trimmed);
                }
            });
            if (currentStep) steps.push(currentStep);
            
            return steps;
        }
        
        // 流式输出专精模式分析
        async function streamSpecialistAnalysis(container, steps) {
            // 设置容器为不可复制、紧凑布局
            container.style.cssText = 'background: #f8f9fa; padding: 8px 12px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; line-height: 1.6; color: #333; display: inline-block; max-width: 100%; user-select: none; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none;';
            
            // 创建标题栏（包含标题和折叠按钮）- 紧凑布局
            const headerDiv = document.createElement('div');
            headerDiv.style.cssText = 'display: inline-flex; align-items: center; cursor: pointer; white-space: nowrap;';
            container.appendChild(headerDiv);
            
            // 添加标题
            const titleDiv = document.createElement('div');
            titleDiv.style.cssText = 'font-size: 12px; color: #999;';
            titleDiv.textContent = '已完成思考';
            headerDiv.appendChild(titleDiv);
            
            // 添加折叠/展开图片（放在文字旁边）
            const toggleImg = document.createElement('img');
            toggleImg.src = '/image/y.png';
            toggleImg.alt = '收起';
            toggleImg.style.cssText = 'width: 12px; height: 12px; cursor: pointer; transition: transform 0.3s ease; margin-left: 6px; flex-shrink: 0;';
            headerDiv.appendChild(toggleImg);
            
            // 创建内容容器
            const contentContainer = document.createElement('div');
            contentContainer.className = 'specialist-content';
            contentContainer.style.cssText = 'margin-top: 8px; user-select: none; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none;';
            container.appendChild(contentContainer);
            
            // 折叠/展开功能
            let isExpanded = true;
            headerDiv.addEventListener('click', function() {
                isExpanded = !isExpanded;
                if (isExpanded) {
                    contentContainer.style.display = 'block';
                    toggleImg.style.transform = 'rotate(0deg)';
                    toggleImg.alt = '收起';
                } else {
                    contentContainer.style.display = 'none';
                    toggleImg.style.transform = 'rotate(-90deg)';
                    toggleImg.alt = '展开';
                }
            });
            
            // 逐字输出每个步骤
            for (let i = 0; i < steps.length; i++) {
                const step = steps[i];
                const isLast = i === steps.length - 1;
                
                // 创建步骤容器
                const stepDiv = document.createElement('div');
                stepDiv.style.cssText = 'display: flex; margin-bottom: 8px;';
                contentContainer.appendChild(stepDiv);
                
                // 左侧圆点和竖线
                const leftDiv = document.createElement('div');
                leftDiv.style.cssText = 'display: flex; flex-direction: column; align-items: center; margin-right: 8px; flex-shrink: 0;';
                stepDiv.appendChild(leftDiv);
                
                const dot = document.createElement('div');
                dot.style.cssText = 'width: 5px; height: 5px; background: #666; border-radius: 50%;';
                leftDiv.appendChild(dot);
                
                if (!isLast) {
                    const line = document.createElement('div');
                    line.style.cssText = 'width: 1px; flex: 1; background: #e0e0e0; margin: 3px 0;';
                    leftDiv.appendChild(line);
                }
                
                // 右侧内容容器
                const rightDiv = document.createElement('div');
                rightDiv.style.cssText = 'flex: 1; min-width: 0;';
                stepDiv.appendChild(rightDiv);
                
                // 步骤标题
                const stepTitle = document.createElement('div');
                stepTitle.style.cssText = 'font-weight: 500; color: #333; margin-bottom: 2px; font-size: 12px;';
                rightDiv.appendChild(stepTitle);
                
                // 流式输出标题
                const titleText = `${step.number}. ${step.title}`;
                await typeWriterElement(stepTitle, titleText, 10);
                
                // 输出内容
                for (const content of step.content) {
                    const contentDiv = document.createElement('div');
                    contentDiv.style.cssText = 'color: #666; font-size: 12px; line-height: 1.5; word-break: break-all;';
                    rightDiv.appendChild(contentDiv);
                    await typeWriterElement(contentDiv, content, 8);
                }
            }
        }
        
        // 处理缓存的AI回复内容（专精模式流式输出完成后调用）
        function processCachedContent(content, container) {
            const specialistAnalysis = container.querySelector('.specialist-analysis');
            const specialistAnalysisHtml = specialistAnalysis ? specialistAnalysis.outerHTML : '';
            const imagesContainer = container.querySelector('[data-images-container="true"]');
            const imagesContainerClone = imagesContainer ? imagesContainer.cloneNode(true) : null;
            
            renderContentWithCodeBlocks(container, content);
            
            if (specialistAnalysisHtml) {
                container.insertAdjacentHTML('afterbegin', specialistAnalysisHtml);
            }
            if (imagesContainerClone) {
                container.appendChild(imagesContainerClone);
            }
        }
        
        // 辅助函数：逐字输出到元素
        async function typeWriterElement(element, text, speed = 10) {
            return new Promise(resolve => {
                let i = 0;
                function type() {
                    if (i < text.length) {
                        element.textContent += text.charAt(i);
                        i++;
                        setTimeout(type, speed);
                    } else {
                        resolve();
                    }
                }
                type();
            });
        }
        
        // 发送按钮点击事件会在 DOMContentLoaded 中绑定
        
        // 输入框回车发送
        messageInput.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                // 如果正在发送，则中断请求（与点击发送按钮逻辑一致）
                if (currentAbortController || window.isSendingMessage) {
                    if (typeof window.stopCurrentMoonYaResponse === 'function') {
                        window.stopCurrentMoonYaResponse();
                    }
                    return;
                }

                // 检查是否有消息或图片
                const message = messageInput.value.trim();
                const hasImages = uploadedImages.length > 0;
                if (!message && !hasImages) {
                    return;
                }
                
                // 先切换按钮为停止图标
                const sendButton = document.getElementById('sendBtn');
                if (sendButton) {
                    sendButton.setAttribute('data-state', 'stop');
                    sendButton.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="#ffffff" xmlns="http://www.w3.org/2000/svg" class="block size-18"><g clip-path="url(#clip0_299_3088)"><path d="M12 0.5C18.3513 0.5 23.5 5.64873 23.5 12C23.5 18.3513 18.3513 23.5 12 23.5C5.64873 23.5 0.5 18.3513 0.5 12C0.5 5.64873 5.64873 0.5 12 0.5ZM12 2.5C6.75329 2.5 2.5 6.75329 2.5 12C2.5 17.2467 6.75329 21.5 12 21.5C17.2467 21.5 21.5 17.2467 21.5 12C21.5 6.75329 17.2467 2.5 12 2.5ZM12.5 7.5C14.3856 7.5 15.3283 7.50015 15.9141 8.08594C16.4998 8.67172 16.5 9.61438 16.5 11.5V12.5C16.5 14.3856 16.4998 15.3283 15.9141 15.9141C15.3283 16.4998 14.3856 16.5 12.5 16.5H11.5C9.61438 16.5 8.67172 16.4998 8.08594 15.9141C7.50015 15.3283 7.5 14.3856 7.5 12.5V11.5C7.5 9.61438 7.50015 8.67172 8.08594 8.08594C8.67172 7.50015 9.61438 7.5 11.5 7.5H12.5Z" fill="#ffffff"></path></g><defs><clipPath id="clip0_299_3088"><rect width="24" height="24" fill="#ffffff"></rect></clipPath></defs></svg>';
                    // 启动卡顿检测器
                    if (typeof startStallDetector === 'function') {
                        startStallDetector();
                    }
                }

                // 隐藏主标题和热点按钮
                document.querySelector('.main-title').style.display = 'none';
                const hotTopicsContainer = document.querySelector('.hot-topics-container');
                if (hotTopicsContainer) hotTopicsContainer.style.display = 'none';

                sendMessage();
            }
        });
        
        // 输入框自动调整高度和字数限制
        messageInput.addEventListener('input', function() {
            const defaultHeight = 40; // 默认高度
            const maxHeight = 120; // 最大高度
            const maxLength = 1000; // 最大字数限制
            
            // 检查字数限制
            if (this.value.length > maxLength) {
                // 超过字数限制，自动截断
                this.value = this.value.substring(0, maxLength);
                // 显示提示
                showToast('最多输入1000字符');
            }
            
            if (this.value.trim() === '') {
                // 内容为空时恢复默认高度
                this.style.height = defaultHeight + 'px';
            } else {
                // 保存当前高度
                const currentHeight = parseInt(this.style.height) || defaultHeight;
                
                // 先设置为auto以计算实际需要的高度
                this.style.height = 'auto';
                const contentHeight = this.scrollHeight;
                
                // 决定新高度：第一行保持默认高度，第二行开始才展开
                let newHeight;
                if (contentHeight > defaultHeight + 20) {
                    // 内容超过一行半高度，使用内容高度（不超过最大高度）
                    newHeight = Math.min(contentHeight, maxHeight);
                } else {
                    // 一行内容，保持默认高度
                    newHeight = defaultHeight;
                }
                
                // 只有当新高度与当前高度不同时才更新，避免频繁切换
                if (newHeight !== currentHeight) {
                    this.style.height = newHeight + 'px';
                } else {
                    // 保持当前高度不变
                    this.style.height = currentHeight + 'px';
                }
            }
        });
        
        // 全局快捷键：Ctrl/Cmd + K 新建会话
        document.addEventListener('keydown', function(event) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                const sidebarNewChatBtn = document.getElementById('sidebarNewChatBtn');
                if (sidebarNewChatBtn) {
                    sidebarNewChatBtn.click();
                }
            }
        });

        // “添加”菜单：本地文件/文件夹只把路径交给模型，不上传文件内容。
        const addMenuSelector = document.getElementById('addMenuSelector');
        const addMenuPopover = document.getElementById('addMenuPopover');
        const localPathPicker = document.getElementById('localPathPicker');
        const fileCard = document.getElementById('fileCard');
        const fileInput = document.getElementById('fileInput');
        const folderInput = document.getElementById('folderInput');
        const webUploadChoice = document.getElementById('webUploadChoice');
        const webFilePicker = document.getElementById('webFilePicker');
        const webFolderPicker = document.getElementById('webFolderPicker');

        function setAddMenuOpen(open) {
            if (!addMenuSelector || !fileCard) return;
            addMenuSelector.classList.toggle('open', open);
            fileCard.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (!open && webUploadChoice) webUploadChoice.hidden = true;
        }

        async function getLocalPathBridge() {
            for (let attempt = 0; attempt < 4; attempt++) {
                if (window.moonYaFileOps && typeof window.moonYaFileOps.pickLocalFiles === 'function') {
                    return window.moonYaFileOps;
                }
                if (typeof CefSharp !== 'undefined' && CefSharp.BindObjectAsync) {
                    try {
                        await CefSharp.BindObjectAsync('moonYaFileOps');
                    } catch (error) {
                        console.warn('[LocalPath] 绑定桌面桥接失败（第 ' + (attempt + 1) + ' 次）:', error);
                    }
                }
                if (attempt < 3) {
                    await new Promise(function(resolve) { setTimeout(resolve, 150); });
                }
            }
            return window.moonYaFileOps && typeof window.moonYaFileOps.pickLocalFiles === 'function'
                ? window.moonYaFileOps
                : null;
        }

        function renderLocalPathSelections() {
            const container = document.getElementById('uploadContainer');
            if (!container) return;

            container.querySelectorAll('.local-path-item').forEach(function(item) {
                item.remove();
            });

            localPathSelections.forEach(function(selection) {
                const item = document.createElement('div');
                item.className = 'local-path-item';
                item.title = selection.path;

                const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                icon.setAttribute('viewBox', '0 0 24 24');
                icon.setAttribute('aria-hidden', 'true');
                icon.innerHTML = selection.kind === 'folder'
                    ? '<path d="M3 6.5A2.5 2.5 0 0 1 5.5 4H10l2 2h6.5A2.5 2.5 0 0 1 21 8.5v8A2.5 2.5 0 0 1 18.5 19h-13A2.5 2.5 0 0 1 3 16.5z"></path>'
                    : '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline>';

                const name = document.createElement('span');
                name.className = 'local-path-name';
                name.textContent = selection.name || selection.path;

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'local-path-remove';
                removeButton.setAttribute('aria-label', '移除 ' + (selection.name || selection.path));
                removeButton.textContent = '×';
                removeButton.addEventListener('click', async function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    localPathSelections = localPathSelections.filter(function(item) {
                        return item.path !== selection.path;
                    });
                    renderLocalPathSelections();
                    if (typeof window.syncActiveConversationComposer === 'function') window.syncActiveConversationComposer();
                    const bridge = await getLocalPathBridge();
                    if (bridge && typeof bridge.revokeLocalPath === 'function') {
                        try {
                            await bridge.revokeLocalPath(selection.path);
                        } catch (error) {
                            console.warn('[LocalPath] 撤销本地路径授权失败:', error);
                        }
                    }
                });

                item.append(icon, name, removeButton);
                container.appendChild(item);
            });
        }

        // 通过桌面桥接选择本地文件或文件夹。kind: 'file' | 'folder'
        // 返回 true 表示桥接可用（无论用户是否取消），false 表示桥接不可用需回退 Web 上传
        async function pickLocalPathViaBridge(kind) {
            const bridge = await getLocalPathBridge();
            if (!bridge) {
                return false;
            }

            const isFolder = kind === 'folder';
            const errorLabel = isFolder ? '选择文件夹失败' : '选择文件失败';

            let result;
            try {
                result = isFolder
                    ? await bridge.pickLocalFolders()
                    : await bridge.pickLocalFiles();
            } catch (error) {
                console.error('[LocalPath] ' + errorLabel + ':', error);
                showToast(errorLabel);
                return true;
            }

            if (!result || !(result.success ?? result.Success)) {
                if (!((result && (result.cancelled ?? result.Cancelled)))) {
                    showToast((result && (result.message || result.Message)) || errorLabel);
                }
                return true;
            }

            const items = Array.isArray(result.items) ? result.items : (Array.isArray(result.Items) ? result.Items : []);
            items.forEach(function(item) {
                const path = String(item.path || item.Path || '').trim();
                if (!path || localPathSelections.some(function(selected) { return selected.path === path; })) return;
                localPathSelections.push({
                    path: path,
                    kind: String(item.kind || item.Kind || kind).toLowerCase() === 'folder' ? 'folder' : 'file',
                    name: String(item.name || item.Name || '').trim()
                });
            });
            renderLocalPathSelections();
            if (typeof window.syncActiveConversationComposer === 'function') window.syncActiveConversationComposer();
            setAddMenuOpen(false);
            return true;
        }

        if (fileCard) {
            fileCard.addEventListener('click', function() {
                setAddMenuOpen(!(addMenuSelector && addMenuSelector.classList.contains('open')));
                return;
            const isDeepSeek = currentModel === 'deepseek';
            if (isProgrammingMode && !isDeepSeek) {
                showToast('编程模式下禁止上传文件');
                return;
            }
            if (isTranslationMode) {
                showToast('翻译模式下禁止上传文件');
                return;
            }
            if (isWritingMode) {
                showToast('写作模式下禁止上传文件');
                return;
            }
            if (isResearchMode && !isDeepSeek) {
                showToast('深入研究模式下禁止上传文件');
                return;
            }
            if (isClassicalMode) {
                showToast('文言文翻译模式下禁止上传文件');
                return;
            }
            if (isVideoGenMode) {
                if (videoGenRefImages.length >= 2) {
                    showToast('最多只能上传2张参考图片');
                    return;
                }
                const videoRefInput = document.createElement('input');
                videoRefInput.type = 'file';
                videoRefInput.accept = 'image/png,image/jpeg,image/jpg';
                videoRefInput.addEventListener('change', function() {
                    const file = videoRefInput.files[0];
                    if (!file) return;
                    if (!['image/png', 'image/jpeg', 'image/jpg'].includes(file.type)) {
                        showToast('仅支持png、jpeg、jpg格式的图片');
                        return;
                    }
                    if (file.size > 5 * 1024 * 1024) {
                        showToast('图片大小不能超过5MB');
                        return;
                    }
                    const formData = new FormData();
                    formData.append('image', file);
                    fetch('video_gen/upload.php', {
                        method: 'POST',
                        body: formData
                    }).then(res => res.json()).then(data => {
                        if (data.success && data.url) {
                            videoGenRefImages.push({name: file.name, url: data.url});
                            if (videoGenRefImages.length === 1) {
                                fileCard.querySelector('span').textContent = '首帧图';
                            } else if (videoGenRefImages.length === 2) {
                                fileCard.querySelector('span').textContent = '首帧图 + 尾帧图';
                            }
                            showToast('参考图片上传成功');
                        } else {
                            showToast('图片上传失败: ' + (data.error || '未知错误'));
                        }
                    }).catch(err => {
                        showToast('图片上传失败: 网络错误');
                        console.error(err);
                    });
                });
                videoRefInput.click();
                return;
            }
            const maxFiles = 5;
            if (uploadedImages.length + uploadingCount >= maxFiles) {
                showToast('最多只能上传5个文件');
                return;
            }
            if (isDeepSeek) {
                fileInput.setAttribute('accept', 'image/*,.pdf,.doc,.docx,.txt');
            } else {
                fileInput.setAttribute('accept', 'image/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.md,.epub,.mobi,.log,.go,.h,.c,.cpp,.cxx,.cc,.cs,.java,.js,.css,.jsp,.php,.py,.py3,.asp,.yaml,.yml,.ini,.conf,.ts,.tsx,.html,.json,.bmp,.svg,.svgz,.ico,.avif,.tiff,.tif,.mp4,.mpeg,.mov,.avi,.mpg,.webm,.wmv');
            }
            fileInput.click();
        });
        }

        if (localPathPicker) {
            localPathPicker.addEventListener('click', async function(event) {
                event.preventDefault();
                event.stopPropagation();
                // 统一展示「选择文件 / 选择文件夹」二级菜单，由子按钮按需走桌面桥接或 Web 上传
                if (webUploadChoice) {
                    webUploadChoice.hidden = !webUploadChoice.hidden;
                }
            });
        }

        document.addEventListener('click', function(event) {
            if (!event.target.closest('#addMenuSelector')) {
                setAddMenuOpen(false);
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                setAddMenuOpen(false);
            }
        });
        
        // 将文件转换为base64
        function fileToBase64(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.onerror = reject;
                reader.readAsDataURL(file);
            });
        }

        // Web 附件统一配置与上传队列。限制和白名单只由后端配置提供，
        // 前端不再按当前模型复制一套 5 个/100MB 的旧规则。
        let webAttachmentBatchId = null;
        let webAttachmentConfigPromise = null;
        let pendingWebUploadBatches = 0;

        function createClientUuid() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(char) {
                const random = Math.random() * 16 | 0;
                const value = char === 'x' ? random : ((random & 0x3) | 0x8);
                return value.toString(16);
            });
        }

        function loadWebAttachmentConfig() {
            if (!webAttachmentConfigPromise) {
                webAttachmentConfigPromise = fetch('attachment_upload.php?action=config', {
                    credentials: 'same-origin',
                    cache: 'no-store'
                }).then(async function(response) {
                    const result = await response.json();
                    if (!response.ok || !result.success || !result.config) {
                        throw new Error(result.error || '附件配置加载失败');
                    }
                    return result.config;
                }).catch(function(error) {
                    webAttachmentConfigPromise = null;
                    throw error;
                });
            }
            return webAttachmentConfigPromise;
        }

        function webAttachmentAuthHeaders(baseHeaders) {
            const headers = Object.assign({}, baseHeaders || {});
            try {
                const apiToken = localStorage.getItem('api_token');
                if (apiToken) headers.Authorization = 'Bearer ' + apiToken;
            } catch (storageError) {}
            return headers;
        }

        function extensionOf(filename) {
            const name = String(filename || '');
            const dot = name.lastIndexOf('.');
            return dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
        }

        function classifyWebFile(file, config) {
            const ext = extensionOf(file.name);
            const mime = String(file.type || '').toLowerCase();
            const categories = config.categories || {};
            if (ext === 'webm') {
                if (mime.startsWith('audio/')) return 'audio';
                if (mime.startsWith('video/')) return 'video';
            }
            for (const category of ['image', 'video', 'audio', 'document']) {
                const allowed = Array.isArray(categories[category]) ? categories[category] : [];
                if (allowed.includes(ext)) return category;
            }
            return null;
        }

        function renderSkippedWebFiles(skipped) {
            if (!skipped.length) return;
            const container = document.getElementById('uploadContainer');
            if (!container) return;
            const summary = document.createElement('div');
            summary.className = 'web-upload-skipped-summary';
            summary.title = skipped.map(function(item) {
                return item.name + '：' + item.reason;
            }).join('\n');
            const firstNames = skipped.slice(0, 3).map(function(item) { return item.name; }).join('、');
            summary.textContent = '已跳过 ' + skipped.length + ' 个文件：' + firstNames + (skipped.length > 3 ? ' 等' : '');
            container.appendChild(summary);
            showToast(summary.textContent);
        }

        async function uploadWebAttachment(file, relativePath, category, config) {
            const batchId = webAttachmentBatchId || (webAttachmentBatchId = createClientUuid());
            const progressId = 'attachment-progress-' + createClientUuid();
            let previewUrl = null;
            let base64Data = null;
            let progress = 0;

            if (category === 'image') {
                previewUrl = URL.createObjectURL(file);
            }

            createProgressItem(progressId, previewUrl, file.name);
            uploadingCount++;
            const progressTimer = setInterval(function() {
                progress = Math.min(90, progress + Math.max(2, Math.random() * 9));
                updateProgress(progressId, progress);
            }, 140);

            try {
                const formData = new FormData();
                formData.append('file', file, file.name);
                formData.append('batch_id', batchId);
                formData.append('relative_path', relativePath || file.name);

                const response = await fetch('attachment_upload.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: webAttachmentAuthHeaders(),
                    body: formData
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.error || '上传失败');
                }

                updateProgress(progressId, 100);
                let videoThumbnail = null;
                if (category === 'video') {
                    videoThumbnail = await captureVideoFrame(file);
                }

                const imageData = {
                    file_id: result.attachment_id,
                    attachment_id: result.attachment_id,
                    preview_url: previewUrl,
                    base64_url: base64Data,
                    filename: file.name,
                    relative_path: relativePath || file.name,
                    category: category,
                    purpose: result.purpose || '',
                    file_ext: extensionOf(file.name),
                    expires_at: result.expires_at || null
                };

                if (category === 'image') {
                    imageData.is_image = true;
                } else if (category === 'video') {
                    imageData.is_video = true;
                    imageData.video_thumbnail = videoThumbnail;
                } else {
                    imageData.is_document = true;
                    imageData.is_audio = category === 'audio';
                    imageData.file_content = result.extracted_text || '';
                    imageData.file_text = result.extracted_text || '';
                }

                uploadedImages.push(imageData);
                createImageItem(
                    result.attachment_id,
                    videoThumbnail || previewUrl,
                    category === 'image' ? null : file.name
                );
                if (typeof window.syncActiveConversationComposer === 'function') window.syncActiveConversationComposer();
            } catch (error) {
                if (previewUrl && previewUrl.startsWith('blob:')) {
                    URL.revokeObjectURL(previewUrl);
                }
                throw error;
            } finally {
                clearInterval(progressTimer);
                removeProgressItem(progressId);
                uploadingCount = Math.max(0, uploadingCount - 1);
            }
        }

        async function handleWebFileSelection(fileList) {
            const files = Array.from(fileList || []);
            if (!files.length) return;

            let config;
            try {
                config = await loadWebAttachmentConfig();
            } catch (error) {
                showToast('无法加载附件配置：' + error.message);
                return;
            }

            const limits = config.limits || {};
            const maxFiles = Number(limits.max_files || 0);
            const maxFileSize = Number(limits.max_file_size || 0);
            const availableSlots = Math.max(0, maxFiles - uploadedImages.length - uploadingCount);
            const accepted = [];
            const skipped = [];

            files.forEach(function(file) {
                const category = classifyWebFile(file, config);
                if (!category) {
                    skipped.push({ name: file.webkitRelativePath || file.name, reason: '格式不在 Web 白名单中' });
                    return;
                }
                if (!file.size || file.size > maxFileSize) {
                    skipped.push({ name: file.webkitRelativePath || file.name, reason: file.size ? '超过单文件大小限制' : '空文件' });
                    return;
                }
                if (accepted.length >= availableSlots) {
                    skipped.push({ name: file.webkitRelativePath || file.name, reason: '超过本条消息的文件数量限制' });
                    return;
                }
                accepted.push({
                    file: file,
                    category: category,
                    relativePath: file.webkitRelativePath || file.name
                });
            });

            renderSkippedWebFiles(skipped);
            pendingWebUploadBatches++;
            try {
                for (const item of accepted) {
                    try {
                        await uploadWebAttachment(item.file, item.relativePath, item.category, config);
                    } catch (error) {
                        showToast(item.relativePath + ' 上传失败：' + error.message);
                    }
                }
            } finally {
                pendingWebUploadBatches = Math.max(0, pendingWebUploadBatches - 1);
            }
        }

        window.resetWebAttachmentBatch = function() {
            webAttachmentBatchId = null;
        };

        if (webFilePicker) {
            webFilePicker.addEventListener('click', async function(event) {
                event.preventDefault();
                event.stopPropagation();
                // 桌面桥接可用时走原生文件选择器（仅传路径，不上传内容）
                const usedDesktop = await pickLocalPathViaBridge('file');
                if (usedDesktop) return;
                if (!fileInput) return;
                // 否则走 Web 附件上传
                try {
                    const config = await loadWebAttachmentConfig();
                    const extensions = Object.values(config.categories || {}).flat();
                    fileInput.accept = Array.from(new Set(extensions)).map(function(ext) { return '.' + ext; }).join(',');
                    fileInput.click();
                    setAddMenuOpen(false);
                } catch (error) {
                    showToast('无法打开文件选择器：' + error.message);
                }
            });
        }

        if (webFolderPicker) {
            webFolderPicker.addEventListener('click', async function(event) {
                event.preventDefault();
                event.stopPropagation();
                // 桌面桥接可用时走原生文件夹选择器
                const usedDesktop = await pickLocalPathViaBridge('folder');
                if (usedDesktop) return;
                if (!folderInput) return;
                // 否则走 Web 附件上传
                try {
                    await loadWebAttachmentConfig();
                    folderInput.click();
                    setAddMenuOpen(false);
                } catch (error) {
                    showToast('无法打开文件夹选择器：' + error.message);
                }
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', async function(event) {
                event.stopImmediatePropagation();
                const selected = Array.from(event.target.files || []);
                event.target.value = '';
                await handleWebFileSelection(selected);
            }, true);
        }

        if (folderInput) {
            folderInput.addEventListener('change', async function(event) {
                event.stopImmediatePropagation();
                const selected = Array.from(event.target.files || []);
                event.target.value = '';
                await handleWebFileSelection(selected);
            }, true);
        }

        // 文件选择事件
        fileInput.addEventListener('change', async function(e) {
            const isDeepSeek = currentModel === 'deepseek';
            if (isProgrammingMode && !isDeepSeek) {
                showToast('编程模式下禁止上传文件');
                fileInput.value = '';
                return;
            }
            if (isTranslationMode) {
                showToast('翻译模式下禁止上传文件');
                fileInput.value = '';
                return;
            }
            if (isWritingMode) {
                showToast('写作模式下禁止上传文件');
                fileInput.value = '';
                return;
            }
            if (isResearchMode && !isDeepSeek) {
                showToast('深入研究模式下禁止上传文件');
                fileInput.value = '';
                return;
            }
            if (isClassicalMode) {
                showToast('文言文翻译模式下禁止上传文件');
                fileInput.value = '';
                return;
            }
            const file = e.target.files[0];
            if (!file) return;
            
            const fileName = file.name.toLowerCase();
            const isPDF = file.type === 'application/pdf' || fileName.endsWith('.pdf');
            const isImage = file.type.startsWith('image/') || /\.(jpeg|jpg|png|webp|gif|bmp|svg|svgz|ico|xbm|dib|pjp|tif|pjpeg|avif|apng|tiff|jfif)$/i.test(fileName);
            const isVideo = /\.(mp4|mpeg|mov|avi|x-flv|mpg|webm|wmv|3gpp)$/i.test(fileName);
            const isDocx = file.type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || fileName.endsWith('.docx');
            const isDoc = file.type === 'application/msword' || fileName.endsWith('.doc') || fileName.endsWith('.dot');
            const isXlsx = file.type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' || fileName.endsWith('.xlsx');
            const isXls = file.type === 'application/vnd.ms-excel' || fileName.endsWith('.xls');
            const isPptx = file.type === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' || fileName.endsWith('.pptx');
            const isPpt = file.type === 'application/vnd.ms-powerpoint' || fileName.endsWith('.ppt');
            const isTxt = file.type === 'text/plain' || fileName.endsWith('.txt');
            const isCsv = file.type === 'text/csv' || fileName.endsWith('.csv');
            const isMd = fileName.endsWith('.md');
            const isCode = /\.(go|h|c|cpp|cxx|cc|cs|java|js|css|jsp|php|py|py3|asp|yaml|yml|ini|conf|ts|tsx|html|json|log|epub|mobi)$/i.test(fileName);
            const isDocument = isPDF || isDocx || isDoc || isXlsx || isXls || isPptx || isPpt || isTxt || isCsv || isMd || isCode;
            
            if (isDeepSeek) {
                if (!isImage && !isDocument) {
                    showToast('仅支持图片、PDF、Word文档(.doc/.docx)和文本文件(.txt)');
                    fileInput.value = '';
                    return;
                }
                if (file.size > 100 * 1024 * 1024) {
                    showToast('文件大小不能超过100MB');
                    fileInput.value = '';
                    return;
                }
            } else {
                if (!isImage && !isDocument && !isVideo) {
                    showToast('不支持的文件类型');
                    fileInput.value = '';
                    return;
                }
                if (file.size > 100 * 1024 * 1024) {
                    showToast('文件大小不能超过100MB');
                    fileInput.value = '';
                    return;
                }
            }
            
            const maxFiles = isDeepSeek ? 5 : 5;
            if (uploadedImages.length + uploadingCount >= maxFiles) {
                showToast('最多只能上传5个文件');
                fileInput.value = '';
                return;
            }
            
            let previewUrl;
            if (isDocument || isVideo) {
                previewUrl = null;
            } else {
                previewUrl = URL.createObjectURL(file);
            }
            
            const base64Data = (isDocument || isVideo) ? null : await fileToBase64(file);
            
            const progressId = 'progress-' + Date.now();
            const displayName = (isDocument || isVideo) ? file.name : null;
            createProgressItem(progressId, previewUrl, displayName);
            uploadingCount++;
            
            if (isDeepSeek) {
                processFileLocally(file, previewUrl, base64Data, progressId, { isPDF, isDocx, isDoc, isTxt, isImage, isDocument });
            } else {
                uploadFileToKimi(file, previewUrl, base64Data, progressId, { isPDF, isDocx, isDoc, isXlsx, isXls, isPptx, isPpt, isTxt, isCsv, isMd, isCode, isImage, isVideo, isDocument });
            }
            
            fileInput.value = '';
        });
        
        // 前端读取PDF文本
        async function extractPdfText(file) {
            try {
                if (typeof pdfjsLib !== 'undefined' && !pdfjsLib.GlobalWorkerOptions.workerSrc) {
                    if (!window.WEB_RUNTIME_CONFIG?.pdfWorkerUrl) throw new Error('Missing required configuration: web_assets.pdf_worker_js');
                    pdfjsLib.GlobalWorkerOptions.workerSrc = window.WEB_RUNTIME_CONFIG.pdfWorkerUrl;
                }
                const arrayBuffer = await file.arrayBuffer();
                const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
                let fullText = '';
                for (let i = 1; i <= pdf.numPages; i++) {
                    const page = await pdf.getPage(i);
                    const textContent = await page.getTextContent();
                    const pageText = textContent.items.map(item => item.str).join(' ');
                    fullText += pageText + '\n';
                }
                return fullText.trim();
            } catch (error) {
                console.error('PDF文本提取失败:', error);
                return null;
            }
        }
        
        // 前端读取DOCX文本
        async function extractDocxText(file) {
            try {
                const arrayBuffer = await file.arrayBuffer();
                const result = await mammoth.extractRawText({ arrayBuffer: arrayBuffer });
                return result.value.trim();
            } catch (error) {
                console.error('DOCX文本提取失败:', error);
                return null;
            }
        }
        
        // 前端读取TXT文本
        async function extractTxtText(file) {
            try {
                return await file.text();
            } catch (error) {
                console.error('TXT文本读取失败:', error);
                return null;
            }
        }
        
        // DeepSeek模式：前端直接处理文件
        async function processFileLocally(file, previewUrl, base64Data, progressId, typeInfo) {
            const fileId = 'ds_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            const { isPDF, isDocx, isDoc, isTxt, isImage, isDocument } = typeInfo;
            
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += Math.random() * 20;
                if (progress > 90) progress = 90;
                updateProgress(progressId, progress);
            }, 80);
            
            try {
                const imageData = {
                    file_id: fileId,
                    preview_url: previewUrl,
                    base64_url: base64Data,
                    filename: file.name
                };
                
                if (isImage) {
                    const base64Raw = base64Data.split(',')[1];
                    const mimeType = base64Data.match(/data:([^;]+);/)?.[1] || file.type || 'image/jpeg';
                    try {
                        const response = await fetch('image-backend.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                imageBase64: base64Raw,
                                mimeType: mimeType,
                                filename: file.name
                            })
                        });
                        const result = await response.json();
                        if (result.success && result.reply) {
                            imageData.file_text = result.reply;
                            imageData.is_image = true;
                        } else {
                            clearInterval(progressInterval);
                            removeProgressItem(progressId);
                            uploadingCount--;
                            showToast('图片识别失败: ' + (result.error || '未知错误'));
                            return;
                        }
                    } catch (err) {
                        clearInterval(progressInterval);
                        removeProgressItem(progressId);
                        uploadingCount--;
                        showToast('图片识别请求失败: ' + err.message);
                        return;
                    }
                } else if (isPDF) {
                    const text = await extractPdfText(file);
                    if (text && text.length > 0) {
                        imageData.file_text = text;
                        imageData.is_pdf = true;
                    } else {
                        clearInterval(progressInterval);
                        removeProgressItem(progressId);
                        uploadingCount--;
                        showToast('PDF文本提取失败，文件可能是扫描件或图片PDF');
                        return;
                    }
                } else if (isDocx) {
                    const text = await extractDocxText(file);
                    if (text && text.length > 0) {
                        imageData.file_text = text;
                        imageData.is_docx = true;
                    } else {
                        clearInterval(progressInterval);
                        removeProgressItem(progressId);
                        uploadingCount--;
                        showToast('Word文档文本提取失败');
                        return;
                    }
                } else if (isDoc) {
                    clearInterval(progressInterval);
                    removeProgressItem(progressId);
                    uploadingCount--;
                    showToast('.doc格式暂不支持，请转换为.docx格式后上传');
                    return;
                } else if (isTxt) {
                    const text = await extractTxtText(file);
                    if (text && text.length > 0) {
                        imageData.file_text = text;
                        imageData.is_txt = true;
                    } else {
                        clearInterval(progressInterval);
                        removeProgressItem(progressId);
                        uploadingCount--;
                        showToast('文本文件读取失败');
                        return;
                    }
                }
                
                if (imageData.file_text && imageData.file_text.length > 500000) {
                    imageData.file_text = imageData.file_text.substring(0, 500000);
                    showToast('文件内容过长，已截取前500000字符');
                }
                
                clearInterval(progressInterval);
                updateProgress(progressId, 100);
                
                setTimeout(() => {
                    removeProgressItem(progressId);
                    uploadedImages.push(imageData);
                    const displayName = isDocument ? file.name : null;
                    createImageItem(fileId, previewUrl, displayName);
                    if (typeof window.syncActiveConversationComposer === 'function') window.syncActiveConversationComposer();
                    uploadingCount--;
                }, 300);
                
            } catch (error) {
                clearInterval(progressInterval);
                removeProgressItem(progressId);
                uploadingCount--;
                showToast('文件处理失败: ' + error.message);
            }
        }
        
        function removeProgressItem(progressId) {
            const progressItem = document.getElementById(progressId);
            if (progressItem) progressItem.remove();
        }
        
        // 创建进度显示项
        function createProgressItem(progressId, previewUrl) {
            const container = document.getElementById('uploadContainer');
            const item = document.createElement('div');
            item.className = 'upload-progress';
            item.id = progressId;
            
            // 计算圆的参数
            const radius = 13;
            const circumference = 2 * Math.PI * radius;
            
            item.innerHTML = `
                <svg class="progress-ring" viewBox="0 0 34 34">
                    <circle class="progress-ring-circle" cx="17" cy="17" r="${radius}" />
                    <circle class="progress-ring-circle-active" cx="17" cy="17" r="${radius}"
                            stroke-dasharray="${circumference}"
                            stroke-dashoffset="${circumference}"
                            id="${progressId}-circle" />
                </svg>
                <span class="progress-text" id="${progressId}-text">0%</span>
            `;
            
            container.appendChild(item);
        }
        
        // 更新进度
        function updateProgress(progressId, percent) {
            const circle = document.getElementById(progressId + '-circle');
            const text = document.getElementById(progressId + '-text');
            if (!circle || !text) return;
            
            const radius = 13;
            const circumference = 2 * Math.PI * radius;
            const offset = circumference - (percent / 100) * circumference;
            
            circle.style.strokeDashoffset = offset;
            text.textContent = Math.round(percent) + '%';
        }
        
        // 创建图片项
        function createImageItem(fileId, previewUrl, fileName) {
            const container = document.getElementById('uploadContainer');
            const item = document.createElement('div');
            item.className = 'upload-item';
            item.setAttribute('data-file-id', fileId);
            
            if (fileName) {
                const safeFileName = String(fileName).replace(/[&<>"']/g, function(char) {
                    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
                });
                const ext = fileName.split('.').pop().toLowerCase();
                let iconColor = '#ff4d4f';
                let iconBg = '#fff2f0';
                let borderColor = '#ffccc7';
                let iconSvg = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="' + iconColor + '" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';
                if (ext === 'docx' || ext === 'doc' || ext === 'dot') {
                    iconColor = '#1677ff';
                    iconBg = '#e6f4ff';
                    borderColor = '#91caff';
                } else if (ext === 'txt' || ext === 'log') {
                    iconColor = '#52c41a';
                    iconBg = '#f6ffed';
                    borderColor = '#b7eb8f';
                } else if (ext === 'xlsx' || ext === 'xls' || ext === 'csv') {
                    iconColor = '#13c2c2';
                    iconBg = '#e6fffb';
                    borderColor = '#87e8de';
                } else if (ext === 'pptx' || ext === 'ppt') {
                    iconColor = '#fa8c16';
                    iconBg = '#fff7e6';
                    borderColor = '#ffd591';
                } else if (ext === 'pdf') {
                    iconColor = '#ff4d4f';
                    iconBg = '#fff2f0';
                    borderColor = '#ffccc7';
                } else if (['mp4','mpeg','mov','avi','mpg','webm','wmv','3gpp'].includes(ext)) {
                    iconColor = '#1890ff';
                    iconBg = '#e6f7ff';
                    borderColor = '#91d5ff';
                    iconSvg = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="' + iconColor + '" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>';
                } else if (['go','h','c','cpp','cxx','cc','cs','java','js','css','jsp','php','py','py3','asp','yaml','yml','ini','conf','ts','tsx','html','json','md'].includes(ext)) {
                    iconColor = '#595959';
                    iconBg = '#fafafa';
                    borderColor = '#d9d9d9';
                }
                item.innerHTML = `
                    <div class="pdf-icon-item" style="background-color: ${iconBg}; border: 1px solid ${borderColor};">
                        ${iconSvg}
                        <span class="pdf-name" style="color: ${iconColor};">${safeFileName}</span>
                    </div>
                    <div class="delete-btn" onclick="deleteImage('${fileId}')">×</div>
                `;
            } else {
                item.innerHTML = `
                    <img src="${previewUrl}" alt="上传图片">
                    <div class="delete-btn" onclick="deleteImage('${fileId}')">×</div>
                `;
            }
            
            container.appendChild(item);
        }
        
        // 删除图片
        function deleteImage(fileId) {
            const attachment = uploadedImages.find(img => img.file_id === fileId);
            uploadedImages = uploadedImages.filter(img => img.file_id !== fileId);
            const item = document.querySelector(`.upload-item[data-file-id="${fileId}"]`);
            if (item) {
                item.remove();
            }
            if (typeof window.syncActiveConversationComposer === 'function') window.syncActiveConversationComposer();
            if (attachment && typeof attachment.preview_url === 'string'
                && attachment.preview_url.startsWith('blob:')) {
                URL.revokeObjectURL(attachment.preview_url);
            }
            if (attachment && attachment.attachment_id) {
                fetch('attachment_upload.php?action=delete', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: webAttachmentAuthHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ attachment_id: attachment.attachment_id })
                }).catch(function(error) {
                    console.warn('[Attachment] 删除暂存附件失败:', error);
                });
            }
        }
        
        function captureVideoFrame(file) {
            return new Promise((resolve) => {
                try {
                    const video = document.createElement('video');
                    video.preload = 'metadata';
                    video.muted = true;
                    video.playsInline = true;
                    const url = URL.createObjectURL(file);
                    let settled = false;
                    let timeoutId = null;
                    const finish = function(value) {
                        if (settled) return;
                        settled = true;
                        if (timeoutId) clearTimeout(timeoutId);
                        URL.revokeObjectURL(url);
                        video.removeAttribute('src');
                        video.load();
                        resolve(value);
                    };
                    video.src = url;
                    video.onloadeddata = () => {
                        video.currentTime = Math.min(0.5, video.duration * 0.1);
                    };
                    video.onseeked = () => {
                        try {
                            const canvas = document.createElement('canvas');
                            canvas.width = video.videoWidth || 320;
                            canvas.height = video.videoHeight || 240;
                            const ctx = canvas.getContext('2d');
                            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                            const thumbnailDataUrl = canvas.toDataURL('image/jpeg', 0.7);
                            finish(thumbnailDataUrl);
                        } catch (e) {
                            finish(null);
                        }
                    };
                    video.onerror = () => {
                        finish(null);
                    };
                    timeoutId = setTimeout(() => finish(null), 5000);
                } catch (e) {
                    resolve(null);
                }
            });
        }

        async function uploadFileToKimi(file, previewUrl, base64Data, progressId, typeInfo) {
            const { isPDF, isDocx, isDoc, isXlsx, isXls, isPptx, isPpt, isTxt, isCsv, isMd, isCode, isImage, isVideo, isDocument } = typeInfo;
            
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += Math.random() * 10;
                if (progress > 90) progress = 90;
                updateProgress(progressId, progress);
            }, 100);
            
            try {
                const formData = new FormData();
                formData.append('file', file);
                
                const response = await fetch('kimi_upload.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                clearInterval(progressInterval);
                
                if (result.success) {
                    updateProgress(progressId, 100);
                    
                    let videoThumbnail = null;
                    if (isVideo) {
                        videoThumbnail = await captureVideoFrame(file);
                    }
                    
                    setTimeout(() => {
                        const progressItem = document.getElementById(progressId);
                        if (progressItem) progressItem.remove();
                        
                        const imageData = {
                            file_id: result.file_id,
                            preview_url: previewUrl,
                            base64_url: base64Data,
                            filename: file.name,
                            category: result.category,
                            purpose: result.purpose,
                            file_ext: result.file_ext
                        };
                        
                        if (isVideo) {
                            imageData.is_video = true;
                            if (videoThumbnail) {
                                imageData.video_thumbnail = videoThumbnail;
                            }
                        } else if (isImage) {
                            imageData.is_image = true;
                        } else {
                            imageData.is_document = true;
                            if (result.file_content) {
                                imageData.file_content = result.file_content;
                            }
                        }
                        
                        uploadedImages.push(imageData);
                        if (isVideo && videoThumbnail) {
                            createImageItem(result.file_id, videoThumbnail, file.name);
                        } else {
                            createImageItem(result.file_id, previewUrl, file.name);
                        }
                        if (typeof window.syncActiveConversationComposer === 'function') window.syncActiveConversationComposer();
                        uploadingCount--;
                    }, 300);
                } else {
                    showToast(result.error || '上传失败');
                    const progressItem = document.getElementById(progressId);
                    if (progressItem) progressItem.remove();
                    uploadingCount--;
                }
            } catch (error) {
                clearInterval(progressInterval);
                showToast('上传失败: ' + error.message);
                const progressItem = document.getElementById(progressId);
                if (progressItem) progressItem.remove();
                uploadingCount--;
            }
        }
        
        // 上传文件到服务器
        async function uploadFile(file, previewUrl, base64Data, progressId, isPDF) {
            const isDeepSeek = currentModel === 'deepseek';
            const uploadUrl = isDeepSeek ? 'deepseek_upload.php' : 'upload.php';
            const formData = new FormData();
            formData.append('file', file);
            
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += Math.random() * 15;
                if (progress > 90) progress = 90;
                updateProgress(progressId, progress);
            }, 100);
            
            try {
                const response = await fetch(uploadUrl, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                clearInterval(progressInterval);
                
                if (result.success) {
                    updateProgress(progressId, 100);
                    
                    setTimeout(() => {
                        const progressItem = document.getElementById(progressId);
                        if (progressItem) {
                            progressItem.remove();
                        }
                        
                        const imageData = {
                            file_id: result.file_id,
                            preview_url: previewUrl,
                            base64_url: base64Data
                        };
                        
                        if (isDeepSeek) {
                            if (result.is_image && result.base64_data) {
                                imageData.base64_data = result.base64_data;
                                imageData.is_image = true;
                            }
                            if (result.ocr_text) {
                                imageData.file_text = result.ocr_text;
                                imageData.is_pdf = result.is_pdf || false;
                                imageData.filename = result.filename || file.name;
                            }
                        }
                        
                        uploadedImages.push(imageData);
                        createImageItem(result.file_id, previewUrl, (isDeepSeek && isPDF) ? file.name : null);
                        if (typeof window.syncActiveConversationComposer === 'function') window.syncActiveConversationComposer();
                        
                        uploadingCount--;
                    }, 300);
                } else {
                    showToast(result.error || '上传失败');
                    const progressItem = document.getElementById(progressId);
                    if (progressItem) {
                        progressItem.remove();
                    }
                    uploadingCount--;
                }
            } catch (error) {
                clearInterval(progressInterval);
                showToast('上传失败: ' + error.message);
                const progressItem = document.getElementById(progressId);
                if (progressItem) {
                    progressItem.remove();
                }
                uploadingCount--;
            }
        }
