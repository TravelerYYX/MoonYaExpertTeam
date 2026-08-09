        // 保存当前对话
        let _saveQueue = Promise.resolve();

        function __reportDebugSave(hypothesisId, location, msg, data) {
            // Optional development instrumentation is disabled in production.
        }

        function saveCurrentChat(runtimeOverride = null) {
            // 所有保存严格串行。调用方 await 的是自己的真实保存任务，而不是“已进入队列”。
            const saveTask = _saveQueue.then(
                () => _doSaveCurrentChat(runtimeOverride),
                () => _doSaveCurrentChat(runtimeOverride)
            );
            _saveQueue = saveTask.catch(error => {
                console.error('保存当前对话失败:', error);
            });
            return saveTask;
        }

        async function _doSaveCurrentChat(runtimeOverride = null) {
            if (!runtimeOverride && !currentChatId) {
                const newChat = await createNewChat();
                currentChatId = newChat.id;
            }
            // 捕获当前对话快照：异步存库期间 currentChatId/currentDbConversationId 可能因切换/新建对话被改写，
            // 用快照保证消息存入正确的对话，避免串库。
            const saveChatId = runtimeOverride ? runtimeOverride.chatId : currentChatId;
            const saveDbId = runtimeOverride ? (runtimeOverride.dbConversationId || null) : currentDbConversationId;
            const saveMessagesContainer = runtimeOverride && runtimeOverride.container
                ? runtimeOverride.container
                : messagesContainer;

            const history = getChatHistory();
            const chatIndex = history.findIndex(chat => chat.id === saveChatId);
            
            if (chatIndex !== -1) {
                // 获取所有消息（含状态条，按 DOM 顺序保留过程信息）
                const messages = [];
                let pendingStatuses = [];

                Array.from(saveMessagesContainer.children).forEach((el) => {
                    // 状态条直接挂在 messagesContainer 上（不在 .message 内），需单独收集
                    if (el.classList.contains('agent-status-bar')) {
                        const statusMatch = el.className.match(/status-(\w+)/);
                        const statusVal = statusMatch ? statusMatch[1] : '';
                        // ★ 跳过 thinking 瞬时过渡态——它是"AI 即将开始下一轮"的过渡提示，
                        //   不应作为持久阶段保存到历史记录（与实时流 clearTransientThinkingBars 配合）
                        if (statusVal === 'thinking') return;
                        const labelEl = el.querySelector('span:not(.status-icon)');
                        const detailEl = el.querySelector('.status-detail');
                        pendingStatuses.push({
                            status: statusVal,
                            label: labelEl ? labelEl.textContent : '',
                            detail: detailEl ? detailEl.textContent : ''
                        });
                        return;
                    }
                    // 其他过程元素（工作流时间线/搜索结果/文件内容）也直接挂在 container 上，收集 HTML 以便历史还原
                    if (el.classList.contains('workflow-timeline') ||
                        el.classList.contains('search-result-wrapper') ||
                        el.classList.contains('file-content-streaming')) {
                        pendingStatuses.push({ html: el.outerHTML });
                        return;
                    }
                    if (!el.classList.contains('message')) return;
                    const isUser = el.classList.contains('user');
                    const contentEl = el.querySelector('.message-content');
                    const timeEl = el.querySelector('.message-time');
                    const thinkingEl = el.querySelector('.thinking-text');
                    
                    if (contentEl) {
                        // 创建一个临时副本，避免修改原 DOM
                        const tempContentEl = contentEl.cloneNode(true);
                        
                        // 移除专精分析内容（避免重复保存）
                        const specialistDiv = tempContentEl.querySelector('.specialist-analysis');
                        if (specialistDiv) {
                            specialistDiv.remove();
                        }
                        
                        // 检查是否是音乐卡片内容
                        const isMusicCard = tempContentEl.innerHTML.includes('music-card-container') || tempContentEl.innerHTML.includes('music-card');
                        // 检查是否是星座运势卡片内容
                        const isHoroscopeCard = tempContentEl.innerHTML.includes('horoscope-card-container');
                        const isWeatherCard = tempContentEl.innerHTML.includes('weather-card-container');
                        const isSpecialCard = isMusicCard || isHoroscopeCard || isWeatherCard;
                        
                        // 获取图片 - 保存 base64 数据以便历史对话中显示
                        const images = [];
                        const imgElements = tempContentEl.querySelectorAll('img');
                        imgElements.forEach(img => {
                            // 保存图片的 src（应该是 base64 格式）
                            const imgSrc = img.src;
                            if (imgSrc && imgSrc.startsWith('data:')) {
                                images.push(imgSrc);
                            } else if (imgSrc) {
                                // 如果不是 base64，也保存下来
                                images.push(imgSrc);
                            }
                            // 从临时副本中移除图片元素（特殊卡片除外）
                            if (!isSpecialCard) {
                                img.remove();
                            }
                        });
                        
                        // 处理代码块 - 将代码块HTML转回markdown格式，以便历史对话加载时能正确重建
                        const codeWrappers = tempContentEl.querySelectorAll('.plaintext-card, .code-wrapper');
                        codeWrappers.forEach(wrapper => {
                            const copyBtns = wrapper.querySelectorAll('.copy-btn, .code-toggle');
                            copyBtns.forEach(btn => btn.remove());
                        });
                        
                        // 现在获取内容（已经移除了图片、代码块和专精分析）
                        // 使用 innerHTML 保留 HTML 标签，以便在历史对话中正确渲染
                        const rawTextContent = tempContentEl.innerHTML || '';
                        const textContent = typeof cleanAccidentalDomString === 'function'
                            ? cleanAccidentalDomString(rawTextContent)
                            : rawTextContent;
                        
                        // 实时消息在首次保存时获得稳定 ID；从数据库恢复的消息保留服务端 ID。
                        // 相同文字不再作为去重依据，因此用户可以连续发送完全相同的内容。
                        if (!el.dataset.clientMessageId && !el.dataset.serverMessageId) {
                            el.dataset.clientMessageId = (window.crypto && typeof window.crypto.randomUUID === 'function')
                                ? window.crypto.randomUUID()
                                : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                                    const r = Math.random() * 16 | 0;
                                    return (c === 'x' ? r : ((r & 0x3) | 0x8)).toString(16);
                                });
                        }

                        const messageData = {
                            type: isUser ? 'user' : 'ai',
                            content: textContent.trim(),
                            images: images,
                            time: timeEl ? timeEl.textContent : '',
                            agent: el.dataset.agentName || '',
                            clientMessageId: el.dataset.clientMessageId || '',
                            serverMessageId: el.dataset.serverMessageId || ''
                        };
                        
                        // 如果有思考过程，也保存
                        if (thinkingEl) {
                            let thinkingContent = thinkingEl.innerHTML || '';
                            thinkingContent = thinkingContent.replace(/<br\s*\/?>/gi, '\n');
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = thinkingContent;
                            thinkingContent = tempDiv.textContent || tempDiv.innerText || '';
                            // ★ 修复：过滤掉仅含空白字符（含 <br> / &nbsp; / 换行 / Unicode 零宽空格）的 thinking 内容，
                            //   避免历史保存了"空白 thinking"，加载时创建只有标题的空折叠框。
                            //   优先使用 isThinkingEmpty（定义在 script-1e-rest.php），回退到 trim()。
                            var _thinkingHasContent = typeof isThinkingEmpty === 'function'
                                ? !isThinkingEmpty(thinkingContent)
                                : (thinkingContent.trim() && thinkingContent.replace(/[\s\u200B-\u200D\uFEFF]/g, '').length > 0);
                            if (_thinkingHasContent) {
                                messageData.thinking = thinkingContent;
                            }
                        }
                        
                        // 如果有专精模式分析，也保存
                        let specialistAnalysisHtml = el.dataset.specialistAnalysis;
                        
                        // 如果 dataset 中没有，尝试从 DOM 中查找
                        if (!specialistAnalysisHtml) {
                            const specialistDiv = contentEl.querySelector('.specialist-analysis');
                            
                            if (specialistDiv) {
                                specialistAnalysisHtml = specialistDiv.outerHTML;
                                
                            }
                        }
                        if (specialistAnalysisHtml) {
                            messageData.specialistAnalysis = specialistAnalysisHtml;
                            
                        } else {
                            
                        }
                        
                        // 附加累积的状态条到这条消息（过程信息随消息一起保存/加载）
                        if (pendingStatuses.length > 0) {
                            messageData.statuses = pendingStatuses;
                            pendingStatuses = [];
                        }
                        messages.push(messageData);
                    }
                });
                // 末尾仍有未附加的状态条，作为最后一条消息的 afterStatuses（渲染在消息之后，保持原始顺序）
                if (pendingStatuses.length > 0 && messages.length > 0) {
                    const lastMsg = messages[messages.length - 1];
                    lastMsg.afterStatuses = pendingStatuses;
                }
                
                history[chatIndex].messages = messages;
                
                // 更新对话标题（使用第一条消息）
                let newTitleForDb = null;
                if (messages.length > 0 && history[chatIndex].title === '新对话') {
                    const firstMessage = messages.find(m => m.type === 'user');
                    if (firstMessage) {
                        // 提取纯文本内容作为标题
                        const tempElement = document.createElement('div');
                        tempElement.innerHTML = firstMessage.content;
                        const plainTextRaw = tempElement.textContent || tempElement.innerText || '';
                        const plainText = typeof cleanAccidentalDomString === 'function'
                            ? cleanAccidentalDomString(plainTextRaw)
                            : plainTextRaw;
                        history[chatIndex].title = plainText.substring(0, 20) + (plainText.length > 20 ? '...' : '');
                        newTitleForDb = history[chatIndex].title;
                    }
                }

                saveChatHistory(history);
                renderChatList();

                // 如果已登录，保存消息到数据库（saveMessagesToDatabase 内部会在缺少 dbConversationId 时延迟创建）
                if (isLoggedIn) {
                    await saveMessagesToDatabase(messages, saveChatId, saveDbId, saveMessagesContainer);
                    // 标题同步到数据库：saveMessagesToDatabase 可能刚延迟创建数据库对话并写入 dbConversationId，
                    // 需从最新 history 取 saveChatId 对应的 dbConversationId，避免用过期快照导致标题丢失。
                    if (newTitleForDb) {
                        const latestHistory = getChatHistory();
                        const latestChat = latestHistory.find(c => c.id === saveChatId);
                        const latestDbId = latestChat ? latestChat.dbConversationId : null;
                        if (latestDbId) {
                            updateConversationTitleInDatabase(latestDbId, newTitleForDb);
                        }
                    }
                }
            }
        }
        
        // 保存消息到数据库
        function getAuthHeaders() {
            const headers = { 'Content-Type': 'application/json' };
            const apiToken = localStorage.getItem('api_token');
            if (apiToken) {
                headers['Authorization'] = 'Bearer ' + apiToken;
            }
            return headers;
        }

        function getAuthToken() {
            return localStorage.getItem('api_token') || '';
        }

        function addTokenToUrl(url) {
            const token = getAuthToken();
            if (!token) return url;
            const separator = url.includes('?') ? '&' : '?';
            return url + separator + 'token=' + encodeURIComponent(token);
        }

        // 已删除对话的 dbConversationId 持久化列表（存在 localStorage）
        // 根治"删除后发送消息，被删对话又从服务器拉回来"的问题：
        // 即使 deleteConversationFromDatabase 的网络请求失败，syncChatHistoryFromServer
        // 也会跳过这些已删除的对话，不会把它们加回 localStorage。
        const DELETED_DB_IDS_KEY = 'deleted_db_conversation_ids';
        function getDeletedDbConversationIds() {
            try {
                return JSON.parse(localStorage.getItem(DELETED_DB_IDS_KEY) || '[]');
            } catch (e) {
                return [];
            }
        }
        function addDeletedDbConversationId(dbId) {
            if (!dbId) return;
            try {
                const ids = getDeletedDbConversationIds();
                if (!ids.includes(dbId)) {
                    ids.push(dbId);
                    // 限制列表大小，只保留最近 200 个，避免无限增长
                    const trimmed = ids.slice(-200);
                    localStorage.setItem(DELETED_DB_IDS_KEY, JSON.stringify(trimmed));
                }
            } catch (e) {}
        }

        // 删除数据库中的对话（同步本地删除操作到后端，避免同步时被删对话又回来）
        // 同时把删除 promise 登记到 _pendingConversationDeletes，供 syncChatHistoryFromServer 等待，
        // 根治竞态：删除请求还在飞行中就触发同步，导致"正在被删除"的对话又被拉回来。
        let _pendingConversationDeletes = [];
        async function deleteConversationFromDatabase(dbId) {
            if (!isLoggedIn || !dbId) return;
            const p = (async () => {
                try {
                    const resp = await fetch(addTokenToUrl(`conversation_api.php?action=delete&conversation_id=${dbId}`), {
                        method: 'DELETE',
                        headers: getAuthHeaders()
                    });
                    if (!resp.ok) {
                        console.error('删除数据库对话失败，HTTP状态:', resp.status);
                    }
                } catch (error) {
                    console.error('删除数据库对话失败:', error);
                }
            })();
            _pendingConversationDeletes.push(p);
            return p;
        }

        // 等待所有 pending 的对话删除请求完成（在同步服务器数据前调用，避免竞态）
        async function awaitPendingConversationDeletes() {
            if (_pendingConversationDeletes.length === 0) return;
            const pending = _pendingConversationDeletes;
            _pendingConversationDeletes = [];
            await Promise.all(pending);
        }

        // 更新数据库中的对话标题（同步本地标题到后端，避免同步回来时标题被重置为"新对话"）
        async function updateConversationTitleInDatabase(dbId, title) {
            if (!isLoggedIn || !dbId || !title) return;
            try {
                await fetch(addTokenToUrl(`conversation_api.php?action=update&conversation_id=${dbId}`), {
                    method: 'PUT',
                    headers: getAuthHeaders(),
                    body: JSON.stringify({ title: title })
                });
            } catch (error) {
                console.error('更新数据库对话标题失败:', error);
            }
        }

        async function saveMessagesToDatabase(messages, chatId, dbId, targetMessagesContainer = null) {
            if (!isLoggedIn) return;
            // 延迟创建数据库对话：只有真正需要保存消息时才创建，避免空对话在数据库堆积
            if (!dbId) {
                try {
                    const createResp = await fetch(addTokenToUrl('conversation_api.php?action=create'), {
                        method: 'POST',
                        headers: getAuthHeaders(),
                        body: JSON.stringify({ title: '新对话' })
                    });
                    const createResult = await createResp.json();
                    if (createResult.success) {
                        dbId = createResult.data.conversation_id;
                        if (currentChatId === chatId) {
                            currentDbConversationId = dbId;
                        }
                        const history = getChatHistory();
                        const chat = history.find(c => c.id === chatId);
                        if (chat) {
                            chat.dbConversationId = dbId;
                            saveChatHistory(history);
                        }
                    } else {
                        return;
                    }
                } catch (error) {
                    console.error('延迟创建数据库对话失败:', error);
                    return;
                }
            }
            
            try {
                const url = addTokenToUrl(`conversation_api.php?action=get&conversation_id=${dbId}`);
                const response = await fetch(url, {
                    headers: getAuthHeaders()
                });
                if (!response.ok) {
                    console.error('获取已保存消息失败，HTTP状态:', response.status);
                    const errorText = await response.text().catch(() => '');
                    console.error('错误响应内容:', errorText.substring(0, 200));
                }
                const result = await response.json().catch(() => ({ success: false }));
                if (!response.ok || !result.success || !result.data || !Array.isArray(result.data.messages)) {
                    // 无法确认服务端现状时不盲目重放本地快照，下一次保存会继续重试。
                    return;
                }
                const savedMessages = result.data.messages;
                const savedClientIds = new Set(
                    savedMessages.map(saved => saved.client_message_id || '').filter(Boolean)
                );

                const newMessages = messages.filter(msg => {
                    if (msg.serverMessageId) return false;
                    if (msg.clientMessageId && savedClientIds.has(msg.clientMessageId)) return false;
                    return true;
                });
                
                for (const msg of newMessages) {
                    const saveUrl = addTokenToUrl('conversation_api.php?action=save_message');
                    const saveResp = await fetch(saveUrl, {
                        method: 'POST',
                        headers: getAuthHeaders(),
                        body: JSON.stringify({
                            conversation_id: dbId,
                            role: msg.type,
                            content: msg.content,
                            images: msg.images || [],
                            thinking: msg.thinking || '',
                            specialist_analysis: msg.specialistAnalysis || '',
                            agent: msg.agent || '',
                            statuses: msg.statuses || [],
                            client_message_id: msg.clientMessageId || null
                        })
                    });
                    if (!saveResp.ok) {
                        console.error('保存消息失败，HTTP状态:', saveResp.status, '角色:', msg.type);
                        continue;
                    }
                    const saveResult = await saveResp.json().catch(() => ({ success: false }));
                    if (saveResult.success && saveResult.data && saveResult.data.message_id) {
                        const persistedContainer = targetMessagesContainer || messagesContainer;
                        const messageNodes = persistedContainer.querySelectorAll('.message.user, .message.ai');
                        messageNodes.forEach(node => {
                            if (msg.clientMessageId && node.dataset.clientMessageId === msg.clientMessageId) {
                                node.dataset.serverMessageId = String(saveResult.data.message_id);
                            }
                        });
                    }
                }
                
            } catch (error) {
                console.error('保存消息到数据库失败:', error);
            }
        }
        
        // 从服务器同步对话列表到本地（用于 localStorage 为空/换域名/数据丢失时恢复）
        async function syncChatHistoryFromServer() {
            if (!isLoggedIn) return false;
            // 等待所有 pending 的删除请求完成，根治竞态：删除请求还在飞行中就同步，
            // 导致"正在被删除"的对话又被拉回来（即用户看到的"删除后发送消息，删除的对话又出现"）
            await awaitPendingConversationDeletes();
            try {
                const response = await fetch(addTokenToUrl('conversation_api.php?action=list'), {
                    headers: getAuthHeaders()
                });
                const result = await response.json().catch(() => ({ success: false }));
                if (!result.success || !Array.isArray(result.data.conversations) || result.data.conversations.length === 0) {
                    return false;
                }
                // 同一数据库会话可能因旧版 string/number 严格比较失配而产生两个本地条目。
                // 先按规范化后的数据库 ID 合并，优先保留当前会话、实时本地会话和消息更完整者。
                const rawExisting = getChatHistory();
                const existing = [];
                const existingByDbId = new Map();
                rawExisting.forEach(function(chat) {
                    if (!chat.dbConversationId) {
                        existing.push(chat);
                        return;
                    }
                    const dbKey = String(chat.dbConversationId);
                    const prior = existingByDbId.get(dbKey);
                    if (!prior) {
                        existingByDbId.set(dbKey, chat);
                        existing.push(chat);
                        return;
                    }
                    const chatScore = (String(chat.id) === String(currentChatId) ? 1000 : 0)
                        + (!String(chat.id).startsWith('db_') ? 100 : 0)
                        + ((chat.messages && chat.messages.length) || 0);
                    const priorScore = (String(prior.id) === String(currentChatId) ? 1000 : 0)
                        + (!String(prior.id).startsWith('db_') ? 100 : 0)
                        + ((prior.messages && prior.messages.length) || 0);
                    if (chatScore > priorScore) {
                        if ((!chat.messages || chat.messages.length === 0) && prior.messages) chat.messages = prior.messages;
                        const index = existing.indexOf(prior);
                        if (index !== -1) existing[index] = chat;
                        existingByDbId.set(dbKey, chat);
                    } else if ((!prior.messages || prior.messages.length === 0) && chat.messages) {
                        prior.messages = chat.messages;
                    }
                });
                const existingDbIds = new Set(existing.map(c => c.dbConversationId).filter(Boolean).map(String));
                const deletedDbIds = new Set(getDeletedDbConversationIds().map(String));
                const serverChats = [];
                for (const conv of result.data.conversations) {
                    const taskState = {
                        phase: conv.phase || 'idle',
                        activeTaskId: conv.active_task_id || null,
                        activeRunId: conv.active_run_id || null,
                        lastTerminalStatus: conv.last_terminal_status || null,
                        unreadTerminal: !!conv.unread_terminal,
                        version: Number(conv.task_state_version || 0)
                    };
                    const convDbKey = String(conv.id);
                    const cleanServerTitle = typeof cleanAccidentalDomString === 'function'
                        ? cleanAccidentalDomString(conv.title || '')
                        : (conv.title || '');
                    if (cleanServerTitle !== (conv.title || '') && cleanServerTitle) {
                        updateConversationTitleInDatabase(conv.id, cleanServerTitle);
                    }
                    if (existingDbIds.has(convDbKey)) {
                        const localChat = existing.find(c => Number(c.dbConversationId) === Number(conv.id));
                        if (localChat) {
                            localChat.taskState = taskState;
                            localChat.title = cleanServerTitle || localChat.title;
                            localChat.pinned = !!conv.pinned;
                            if (taskState.unreadTerminal
                                && String(localChat.id) === String(currentChatId)
                                && !document.body.classList.contains('office-active')) {
                                localChat.taskState.unreadTerminal = false;
                                fetch(addTokenToUrl('conversation_api.php?action=mark_viewed'), {
                                    method: 'POST',
                                    headers: getAuthHeaders(),
                                    body: JSON.stringify({ conversation_id: conv.id })
                                }).catch(function() {});
                                if (window.MoonYaSharedRuntime) {
                                    window.MoonYaSharedRuntime.markViewed(conv.id).catch(function() {});
                                }
                            }
                        }
                        continue;
                    }
                    // 跳过用户已删除的对话：即使数据库删除请求失败，也不会把被删对话拉回来
                    if (deletedDbIds.has(convDbKey)) continue;
                    // 跳过数据库中无消息的空对话，避免列表堆积空的「新对话」
                    if ((conv.message_count || 0) === 0) continue;
                    serverChats.push({
                        id: 'db_' + conv.id + '_' + Date.now(),
                        title: cleanServerTitle || '新对话',
                        messages: [],
                        createdAt: conv.created_at || new Date().toISOString(),
                        dbConversationId: conv.id,
                        pinned: conv.pinned ? true : false,
                        taskState: taskState
                    });
                }
                saveChatHistory(serverChats.concat(existing));
                renderChatList();
                return serverChats.length > 0;
            } catch (error) {
                console.error('从服务器同步对话列表失败:', error);
                return false;
            }
        }
        
        // 加载对话
        async function loadChat(chatId) {
            if (typeof window.closeOfficeMode === 'function') {
                window.closeOfficeMode();
            }
            
            const history = getChatHistory();

            const chat = history.find(c => c.id === chatId);
            
            
            if (chat) {
                currentChatId = chatId;
                const liveRuntime = typeof activateConversationRuntime === 'function'
                    ? activateConversationRuntime(chatId)
                    : null;
                // 恢复数据库对话ID
                currentDbConversationId = chat.dbConversationId || null;

                // A hydrated/live conversation owns this DOM. Rebuilding it from a
                // stale history snapshot would interrupt streaming nodes and cursors.
                if (liveRuntime && liveRuntime.container.childElementCount > 0) {
                    if (currentDbConversationId && !document.body.classList.contains('office-active')) {
                        if (window.MoonYaSharedRuntime) {
                            window.MoonYaSharedRuntime.markViewed(currentDbConversationId).catch(function() {});
                        }
                        fetch(addTokenToUrl('conversation_api.php?action=mark_viewed'), {
                            method: 'POST',
                            headers: getAuthHeaders(),
                            body: JSON.stringify({ conversation_id: currentDbConversationId })
                        }).then(function() {
                            const latest = getChatHistory();
                            const viewed = latest.find(c => c.id === chatId);
                            if (viewed && viewed.taskState) viewed.taskState.unreadTerminal = false;
                            saveChatHistory(latest);
                            renderChatList();
                        }).catch(function() {});
                    }
                    renderChatList();
                    return;
                }
                
                messagesContainer.innerHTML = '';
                
                // 如果有数据库ID，优先从数据库加载消息（特别是图片数据）
                let messagesToRender = chat.messages;
                if (isLoggedIn && currentDbConversationId) {
                    try {
                        const conversationUrl = addTokenToUrl(`conversation_api.php?action=get&conversation_id=${currentDbConversationId}`);
                        const response = await fetch(conversationUrl, {
                            headers: getAuthHeaders()
                        });
                        const result = await response.json();
                        if (result.success && result.data.messages) {
                            // 将数据库消息格式转换为本地格式
                            messagesToRender = result.data.messages.map(dbMsg => {

                                return {
                                    type: dbMsg.role,
                                    content: dbMsg.content,
                                    images: dbMsg.images || [],
                                    thinking: dbMsg.thinking || '',
                                    specialistAnalysis: dbMsg.specialist_analysis || '',
                                    agent: dbMsg.agent || '',
                                    statuses: dbMsg.statuses || [],
                                    afterStatuses: dbMsg.afterStatuses || [],
                                    clientMessageId: dbMsg.client_message_id || '',
                                    serverMessageId: dbMsg.id || '',
                                    time: new Date(dbMsg.created_at).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' })
                                };
                            });

                            // 数据库可能没有 statuses/agent 字段（旧库未迁移），从本地 chat.messages 借用过程信息
                            // 按 role+正文纯文本 做 key 匹配，把本地的状态条/过程元素/Agent标签补到数据库消息上
                            if (chat.messages && chat.messages.length > 0) {
                                const localMetaMap = {};
                                chat.messages.forEach(m => {
                                    const key = m.type + '|' + (m.content || '').replace(/<[^>]*>/g, '').trim();
                                    if ((m.statuses && m.statuses.length > 0) || (m.afterStatuses && m.afterStatuses.length > 0) || m.agent) {
                                        localMetaMap[key] = { statuses: m.statuses || [], afterStatuses: m.afterStatuses || [], agent: m.agent || '' };
                                    }
                                });
                                messagesToRender.forEach(msg => {
                                    const key = msg.type + '|' + (msg.content || '').replace(/<[^>]*>/g, '').trim();
                                    const meta = localMetaMap[key];
                                    if (meta) {
                                        if (!msg.statuses || msg.statuses.length === 0) msg.statuses = meta.statuses;
                                        if (!msg.afterStatuses || msg.afterStatuses.length === 0) msg.afterStatuses = meta.afterStatuses;
                                        if (!msg.agent) msg.agent = meta.agent;
                                    }
                                });
                            }
                            
                            
                        }
                    } catch (error) {
                        console.error('从数据库加载消息失败:', error);
                    }
                }
                
                messagesToRender.forEach(msg => {
                    if (typeof cleanAccidentalDomString === 'function') {
                        msg.content = cleanAccidentalDomString(msg.content || '');
                    }
                    // 先渲染这条消息前累积的状态条（过程信息，直接挂到 container，与实时渲染一致）
                    if (msg.statuses && msg.statuses.length > 0) {
                        msg.statuses.forEach(st => {
                            // 富 HTML 过程元素（工作流时间线/搜索结果/文件内容），直接还原
                            if (st.html) {
                                const tempWrapper = document.createElement('div');
                                tempWrapper.innerHTML = st.html;
                                while (tempWrapper.firstChild) {
                                    messagesContainer.appendChild(tempWrapper.firstChild);
                                }
                                return;
                            }
                            // ★ 跳过 thinking 瞬时过渡态——清理旧库历史数据中可能残留的 thinking 条
                            if (st.status === 'thinking') return;
                            const statusBar = document.createElement('div');
                            statusBar.className = 'agent-status-bar status-' + (st.status || 'executing');
                            const stIcon = document.createElement('span');
                            stIcon.className = 'status-icon';
                            if (st.status === 'executing' || st.status === 'thinking') {
                                stIcon.innerHTML = createWorkflowSpinnerIcon(16, 3.5);
                                stIcon.style.animation = 'statusSpin 1s linear infinite';
                            } else {
                                const warnSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
                                const stIcons = {
                                    success: createWorkflowCheckIcon(14, '#888888'),
                                    complete: createWorkflowCheckIcon(14, '#888888'),
                                    failure: createWorkflowCrossIcon(14, '#888888'),
                                    done: createWorkflowCheckIcon(14, '#888888'),
                                    stopped: warnSvg,
                                    error: createWorkflowCrossIcon(14, '#888888')
                                };
                                stIcon.innerHTML = stIcons[st.status] || '';
                            }
                            const stLabel = document.createElement('span');
                            stLabel.textContent = st.label || '';
                            statusBar.appendChild(stIcon);
                            statusBar.appendChild(stLabel);
                            if (st.detail) {
                                const stDetail = document.createElement('span');
                                stDetail.className = 'status-detail';
                                stDetail.textContent = st.detail;
                                statusBar.appendChild(stDetail);
                            }
                            messagesContainer.appendChild(statusBar);
                        });
                    }
                    const messageDiv = document.createElement('div');
                    messageDiv.className = `message ${msg.type}`;
                    if (msg.clientMessageId) {
                        messageDiv.dataset.clientMessageId = msg.clientMessageId;
                    }
                    if (msg.serverMessageId) {
                        messageDiv.dataset.serverMessageId = String(msg.serverMessageId);
                    }

                    // 恢复 Agent 来源标签（仅 AI 消息且历史记录中保存了 agent 名称）
                    if (msg.type === 'ai' && msg.agent) {
                        messageDiv.dataset.agentName = msg.agent;
                        const senderLabel = document.createElement('div');
                        senderLabel.className = 'message-sender';
                        senderLabel.textContent = msg.agent;
                        messageDiv.appendChild(senderLabel);
                    }

                    // 如果有思考过程，先添加思考过程（可折叠）
                    if (msg.thinking) {
                        const thinkingWrapper = document.createElement('div');
                        thinkingWrapper.className = 'thinking-wrapper';
                        
                        const thinkingHeader = document.createElement('div');
                        thinkingHeader.className = 'thinking-header';
                        thinkingHeader.onclick = function(e) {
                            e.stopPropagation();
                            const wrapper = this.parentElement;
                            const toggle = wrapper.querySelector('.thinking-toggle');
                            const text = wrapper.querySelector('.thinking-text');
                            const completed = wrapper.querySelector('.thinking-completed');
                            toggle.classList.toggle('expanded');
                            text.classList.toggle('expanded');
                            if (completed) {
                                completed.classList.toggle('collapsed');
                            }
                        };
                        
                        const thinkingLabel = document.createElement('span');
                        thinkingLabel.className = 'thinking-label';
                        thinkingLabel.textContent = '思考内容';
                        
                        const thinkingToggle = document.createElement('span');
                        thinkingToggle.className = 'thinking-toggle';
                        thinkingToggle.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle;"><path d="M21.5627 8.86518C21.8751 8.55276 21.8751 8.04674 21.5627 7.73432C21.2502 7.4219 20.7442 7.4219 20.4318 7.73432L12.3117 15.8544C12.1555 16.0106 11.9015 16.0106 11.7453 15.8544L3.62517 7.73432C3.31275 7.4219 2.80673 7.4219 2.49431 7.73432C2.18189 8.04673 2.18189 8.55275 2.49431 8.86517L10.6144 16.9853C11.3955 17.7663 12.6615 17.7663 13.4425 16.9853L21.5627 8.86518Z" fill="#999"></path></svg>';
                        
                        const thinkingDiv = document.createElement('div');
                        thinkingDiv.className = 'thinking-text';
                        let thinkingText = msg.thinking || '';
                        if (thinkingText.includes('<') && !thinkingText.includes('&lt;')) {
                            thinkingText = thinkingText.replace(/<br\s*\/?>/gi, '\n');
                            const tempEl = document.createElement('div');
                            tempEl.innerHTML = thinkingText;
                            thinkingText = tempEl.textContent || tempEl.innerText || '';
                        }
                        thinkingDiv.innerHTML = renderThinkingContent(thinkingText);
                        
                        // 添加已完成标记
                        const thinkingCompleted = document.createElement('div');
                        thinkingCompleted.className = 'thinking-completed collapsed';
                        thinkingCompleted.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" fill="none" viewBox="0 0 24 24" style="margin-right: 6px; vertical-align: middle;"><path fill="currentColor" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18m0 2C5.925 23 1 18.075 1 12S5.925 1 12 1s11 4.925 11 11-4.925 11-11 11m-1.16-8.72 4.952-4.952a.996.996 0 0 1 1.409.005 1 1 0 0 1 .007 1.41c-1.888 1.905-3.752 3.842-5.685 5.7a.98.98 0 0 1-1.364-.001c-1.01-.98-1.993-1.992-2.983-2.993a1.003 1.003 0 0 1 .005-1.414 1 1 0 0 1 1.412-.002z"></path></svg><span style="color: #999; font-size: 12px;">已完成</span>';
                        
                        thinkingHeader.appendChild(thinkingLabel);
                        thinkingHeader.appendChild(thinkingToggle);
                        thinkingWrapper.appendChild(thinkingHeader);
                        thinkingWrapper.appendChild(thinkingDiv);
                        thinkingWrapper.appendChild(thinkingCompleted);
                        messageDiv.appendChild(thinkingWrapper);
                        
                        const links = thinkingWrapper.querySelectorAll('a');
                        links.forEach(link => {
                            link.onclick = function(e) {
                                e.stopPropagation();
                            };
                        });
                    }
                    
                    const contentDiv = document.createElement('div');
                    contentDiv.className = 'message-content';
                    
                    // 如果有专精模式分析，先添加分析内容
                    
                    // 检查 specialistAnalysis 是否与 content 重复（避免重复显示）
                    let hasDuplicateContent = false;
                    if (msg.specialistAnalysis && msg.specialistAnalysis.length > 0 && msg.content) {
                        // 提取 specialistAnalysis 中的文本内容（去除HTML标签）
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = msg.specialistAnalysis;
                        const specialistText = tempDiv.textContent || tempDiv.innerText || '';
                        // 提取 content 中的文本内容（去除HTML标签和代码块）
                        const contentText = msg.content.replace(/```[\s\S]*?```/g, '').replace(/<[^>]*>/g, '');
                        // 如果 specialistAnalysis 的文本内容在 content 中已经存在，认为是重复
                        if (specialistText.length > 50 && contentText.includes(specialistText.substring(0, 50))) {
                            hasDuplicateContent = true;
                            
                        }
                    }
                    
                    if (msg.specialistAnalysis && msg.specialistAnalysis.length > 0 && !hasDuplicateContent) {
                        
                        // 使用 insertAdjacentHTML 直接插入HTML
                        contentDiv.insertAdjacentHTML('afterbegin', msg.specialistAnalysis);
                        // 获取刚插入的元素
                        const specialistDiv = contentDiv.querySelector('.specialist-analysis');
                        
                        if (specialistDiv) {
                            // 恢复折叠功能
                            const headerDiv = specialistDiv.children[0];
                            const contentContainer = specialistDiv.querySelector('.specialist-content');
                            const toggleImg = headerDiv ? headerDiv.querySelector('img') : null;
                            if (headerDiv && contentContainer && toggleImg) {
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
                            }
                        }
                        // 保存到 dataset 以便再次保存历史记录时使用
                        messageDiv.dataset.specialistAnalysis = msg.specialistAnalysis;
                    }
                    
                    // 检测内容是否包含代码块标记（```） - 如果有，应该使用 renderContentWithCodeBlocks
                    const hasCodeBlockMarkers = msg.content && /```/.test(msg.content);
                    
                    // 检查是否是音乐卡片内容
                    const isMusicCard = msg.content && (msg.content.includes('music-card-container') || msg.content.includes('music-card'));
                    // 检查是否是星座运势卡片内容
                    const isHoroscopeCard = msg.content && msg.content.includes('horoscope-card-container');
                    const isWeatherCard = msg.content && msg.content.includes('weather-card-container');
                    const isSpecialCard = isMusicCard || isHoroscopeCard || isWeatherCard;
                    
                    // 检测内容是否包含代码块HTML格式（新类名 plaintext-card 或旧类名 code-wrapper）
                    const hasCodeBlockHtml = msg.content && (msg.content.includes('plaintext-card') || msg.content.includes('code-wrapper'));
                    
                    // 优先使用 msg.images 数组显示图片（从数据库加载时）- 特殊卡片除外
                    if (!isSpecialCard && msg.images && msg.images.length > 0) {
                        const imagesContainer = document.createElement('div');
                        imagesContainer.style.cssText = 'display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px;';
                        imagesContainer.setAttribute('data-images-container', 'true');
                        
                        msg.images.forEach((imgUrl) => {
                            if (imgUrl && imgUrl.startsWith('videothumb://')) {
                                const thumbSrc = imgUrl.replace(/^videothumb:\/\//, '');
                                const wrapper = document.createElement('div');
                                wrapper.style.position = 'relative';
                                wrapper.style.display = 'inline-block';
                                wrapper.style.borderRadius = '8px';
                                wrapper.style.overflow = 'hidden';
                                const img = document.createElement('img');
                                img.src = thumbSrc;
                                img.style.cssText = 'max-width:200px; max-height:200px; border-radius:8px; object-fit:cover; display:block;';
                                const overlay = document.createElement('div');
                                overlay.style.cssText = 'position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:40px; height:40px; border-radius:50%; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center;';
                                overlay.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="white"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>';
                                wrapper.appendChild(img);
                                wrapper.appendChild(overlay);
                                imagesContainer.appendChild(wrapper);
                            } else if (imgUrl && imgUrl.startsWith('video://')) {
                                const displayName = imgUrl.replace(/^video:\/\//, '');
                                const fileDiv = document.createElement('div');
                                fileDiv.style.cssText = 'display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; font-size:12px; background:#e6f7ff; border:1px solid #91d5ff; color:#1890ff;';
                                fileDiv.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1890ff" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg><span>' + displayName + '</span>';
                                imagesContainer.appendChild(fileDiv);
                            } else if (imgUrl && (imgUrl.startsWith('pdf://') || imgUrl.startsWith('doc://') || imgUrl.startsWith('txt://'))) {
                                const displayName = imgUrl.replace(/^(pdf|doc|txt):\/\//, '');
                                let iconColor = '#ff4d4f', bgColor = '#fff2f0', borderColor = '#ffccc7';
                                if (imgUrl.startsWith('doc://')) { iconColor = '#1677ff'; bgColor = '#e6f4ff'; borderColor = '#91caff'; }
                                else if (imgUrl.startsWith('txt://')) { iconColor = '#52c41a'; bgColor = '#f6ffed'; borderColor = '#b7eb8f'; }
                                const fileDiv = document.createElement('div');
                                fileDiv.style.cssText = `display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; font-size:12px; background:${bgColor}; border:1px solid ${borderColor}; color:${iconColor};`;
                                fileDiv.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="' + iconColor + '" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg><span>' + displayName + '</span>';
                                imagesContainer.appendChild(fileDiv);
                            } else {
                                const img = document.createElement('img');
                                img.src = imgUrl;
                                img.style.cssText = 'max-width:200px; max-height:200px; border-radius:8px; object-fit:cover; display:block;';
                                imagesContainer.appendChild(img);
                            }
                        });
                        
                        contentDiv.appendChild(imagesContainer);
                    }
                    
                    // 检查内容是否包含代码块HTML
                    if (isMusicCard || isHoroscopeCard || isWeatherCard) {
                        const htmlDiv = document.createElement('div');
                        htmlDiv.innerHTML = msg.content;
                        contentDiv.appendChild(htmlDiv);
                    } else if (hasCodeBlockMarkers) {
                        if (msg.content) {
                            const cleanContent = msg.content.replace(/data:image\/[a-zA-Z0-9]+;base64,[A-Za-z0-9+/=]+/g, '');
                            if (cleanContent.trim()) {
                                renderContentWithCodeBlocks(contentDiv, cleanContent);
                            }
                        }
                    } else if (hasCodeBlockHtml) {
                        // 内容包含代码块 HTML（plaintext-card 或旧 code-wrapper），先用 innerHTML 渲染，再重建代码块
                        const htmlDiv = document.createElement('div');
                        htmlDiv.innerHTML = msg.content;

                        // 找到所有代码块（新旧类名都兼容），用 createCodeBlock 重建
                        const oldCodeWrappers = htmlDiv.querySelectorAll('.plaintext-card, .code-wrapper');
                        let blockIdx = 0;
                        oldCodeWrappers.forEach(oldWrapper => {
                            const filenameSpan = oldWrapper.querySelector('.plain-title, .code-filename');
                            const lang = filenameSpan ? filenameSpan.textContent.trim() : '代码';
                            const codeContentEl = oldWrapper.querySelector('.plain-content pre code') || oldWrapper.querySelector('.plain-content pre') || oldWrapper.querySelector('.code-content pre code') || oldWrapper.querySelector('.code-content pre');
                            const code = codeContentEl ? codeContentEl.textContent : '';
                            const newWrapper = createCodeBlock(code, lang, 'hist-' + blockIdx);
                            oldWrapper.parentNode.replaceChild(newWrapper, oldWrapper);
                            blockIdx++;
                        });

                        contentDiv.appendChild(htmlDiv);
                    } else if (msg.content && msg.content.includes('<img')) {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = msg.content;
                        
                        const oldImages = tempDiv.querySelectorAll('img');
                        const imagesToShow = [];
                        oldImages.forEach(img => {
                            imagesToShow.push(img.src);
                            img.remove();
                        });
                        
                        if (imagesToShow.length > 0 && (!msg.images || msg.images.length === 0)) {
                            const imagesContainer = document.createElement('div');
                            imagesContainer.style.display = 'flex';
                            imagesContainer.style.gap = '8px';
                            imagesContainer.style.flexWrap = 'wrap';
                            imagesContainer.style.marginBottom = '8px';
                            imagesToShow.forEach(imgUrl => {
                                const img = document.createElement('img');
                                img.src = imgUrl;
                                img.style.maxWidth = '200px';
                                img.style.maxHeight = '200px';
                                img.style.borderRadius = '8px';
                                img.style.objectFit = 'cover';
                                imagesContainer.appendChild(img);
                            });
                            contentDiv.appendChild(imagesContainer);
                        }
                        
                        const remainingText = tempDiv.innerText || tempDiv.textContent || '';
                        if (remainingText.trim()) {
                            let cleanText = remainingText.trim();
                            cleanText = cleanText.replace(/data:image\/[a-zA-Z0-9]+;base64,[A-Za-z0-9+/=]+/g, '');
                            cleanText = cleanText.replace(/[A-Za-z0-9+/=]{100,}/g, '');
                            cleanText = cleanText.trim();
                            if (cleanText) {
                                renderContentWithCodeBlocks(contentDiv, cleanText);
                            }
                        }
                    } else {
                        if (msg.content) {
                            let cleanContent = msg.content;
                            cleanContent = cleanContent.replace(/data:image\/[a-zA-Z0-9]+;base64,[A-Za-z0-9+/=]+/g, '');
                            cleanContent = cleanContent.replace(/[A-Za-z0-9+/=]{100,}/g, '');
                            cleanContent = cleanContent.trim();
                            
                            if (cleanContent) {
                                const existingImagesContainer = contentDiv.querySelector('[data-images-container="true"]');
                                const hasHtml = /<[a-zA-Z][^>]*>/.test(cleanContent);
                                if (hasHtml) {
                                    const htmlDiv = document.createElement('div');
                                    htmlDiv.innerHTML = cleanContent;
                                    contentDiv.appendChild(htmlDiv);
                                } else {
                                    renderContentWithCodeBlocks(contentDiv, cleanContent);
                                }
                                if (existingImagesContainer) {
                                    contentDiv.insertBefore(existingImagesContainer, contentDiv.firstChild);
                                }
                            }
                        }
                    }
                    
                    messageDiv.appendChild(contentDiv);
                    messagesContainer.appendChild(messageDiv);

                    // 渲染这条消息之后的尾部状态条（如"Agent 执行完成"，保持原始顺序）
                    if (msg.afterStatuses && msg.afterStatuses.length > 0) {
                        msg.afterStatuses.forEach(st => {
                            if (st.html) {
                                const tempWrapper = document.createElement('div');
                                tempWrapper.innerHTML = st.html;
                                while (tempWrapper.firstChild) {
                                    messagesContainer.appendChild(tempWrapper.firstChild);
                                }
                                return;
                            }
                            // ★ 跳过 thinking 瞬时过渡态——清理旧库历史数据中可能残留的 thinking 条
                            if (st.status === 'thinking') return;
                            const afterBar = document.createElement('div');
                            afterBar.className = 'agent-status-bar status-' + (st.status || 'executing');
                            const afterIcon = document.createElement('span');
                            afterIcon.className = 'status-icon';
                            if (st.status === 'executing' || st.status === 'thinking') {
                                afterIcon.innerHTML = createWorkflowSpinnerIcon(16, 3.5);
                                afterIcon.style.animation = 'statusSpin 1s linear infinite';
                            } else {
                                const warnSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
                                const afterIcons = {
                                    success: createWorkflowCheckIcon(14, '#888888'),
                                    complete: createWorkflowCheckIcon(14, '#888888'),
                                    failure: createWorkflowCrossIcon(14, '#888888'),
                                    done: createWorkflowCheckIcon(14, '#888888'),
                                    stopped: warnSvg,
                                    error: createWorkflowCrossIcon(14, '#888888')
                                };
                                afterIcon.innerHTML = afterIcons[st.status] || '';
                            }
                            const afterLabel = document.createElement('span');
                            afterLabel.textContent = st.label || '';
                            afterBar.appendChild(afterIcon);
                            afterBar.appendChild(afterLabel);
                            if (st.detail) {
                                const afterDetail = document.createElement('span');
                                afterDetail.className = 'status-detail';
                                afterDetail.textContent = st.detail;
                                afterBar.appendChild(afterDetail);
                            }
                            messagesContainer.appendChild(afterBar);
                        });
                    }

                });
                


                // 重新高亮代码块
                messagesContainer.querySelectorAll('.plain-content pre code, .code-content pre code').forEach(codeEl => {
                    if (!codeEl.classList.contains('hljs')) {
                        const langMatch = codeEl.className.match(/language-(\w+)/);
                        const lang = langMatch ? langMatch[1] : 'plaintext';
                        try {
                            codeEl.innerHTML = hljs.highlight(codeEl.textContent, { language: lang }).value;
                            codeEl.classList.add('hljs');
                        } catch(e) {}
                    }
                });
                
                const hotTopicsContainer = document.querySelector('.hot-topics-container');
                if (messagesToRender.length > 0) {
                    document.querySelector('.main-title').style.display = 'none';
                    if (hotTopicsContainer) hotTopicsContainer.style.display = 'none';
                } else {
                    document.querySelector('.main-title').style.display = 'block';
                    if (hotTopicsContainer) hotTopicsContainer.style.display = 'flex';
                }
                
                renderChatList();
                // ★ 修复：历史渲染完成后兜底清理空思考框
                //   历史中可能残留 textContent 为空（仅 <br> / 空白字符）的 thinking-wrapper，
                //   这些 wrapper 视觉上只有"思考内容"标题，用户看到一片空折叠框。
                //   removeEmptyThinkingWrappers 定义在 script-1e-rest.php，加载顺序晚于本模块，
                //   但 loadChat 实际调用时（用户切换对话）所有模块已就绪，所以这里运行时调用是安全的。
                if (typeof removeEmptyThinkingWrappers === 'function') {
                    removeEmptyThinkingWrappers(messagesContainer);
                }
                if (currentDbConversationId && !document.body.classList.contains('office-active')) {
                    if (window.MoonYaSharedRuntime) {
                        window.MoonYaSharedRuntime.markViewed(currentDbConversationId).catch(function() {});
                    }
                    fetch(addTokenToUrl('conversation_api.php?action=mark_viewed'), {
                        method: 'POST',
                        headers: getAuthHeaders(),
                        body: JSON.stringify({ conversation_id: currentDbConversationId })
                    }).then(function() {
                        const history = getChatHistory();
                        const viewed = history.find(c => c.id === chatId);
                        if (viewed && viewed.taskState) viewed.taskState.unreadTerminal = false;
                        saveChatHistory(history);
                        renderChatList();
                    }).catch(function() {});
                }
            }
        }
        
        // 置顶/取消置顶对话
        function pinChat(chatId) {
            const history = getChatHistory();
            const chat = history.find(c => c.id === chatId);
            
            if (chat) {
                chat.pinned = !chat.pinned;
                saveChatHistory(history);
                renderChatList();
            }
        }
        
        // 重命名对话
        function renameChat(chatId) {
            const history = getChatHistory();
            const chat = history.find(c => c.id === chatId);

            if (chat) {
                const newTitle = prompt('请输入新的对话名称:', chat.title);
                if (newTitle && newTitle.trim()) {
                    chat.title = newTitle.trim();
                    saveChatHistory(history);
                    renderChatList();
                    // 同步标题到数据库，避免同步回来时标题被重置为"新对话"
                    if (chat.dbConversationId) {
                        updateConversationTitleInDatabase(chat.dbConversationId, chat.title);
                    }
                }
            }
        }
        
        // 删除对话
        function deleteChat(chatId) {
            let history = getChatHistory();
            // 先取出待删对话的数据库ID，本地删除后同步删除数据库，避免同步时被删对话又回来
            const chatToDelete = history.find(chat => chat.id === chatId);
            const dbIdToDelete = chatToDelete ? chatToDelete.dbConversationId : null;
            history = history.filter(chat => chat.id !== chatId);
            saveChatHistory(history);
            // 同步删除数据库中的对话（根治"删除后发送消息又出现"的问题）
            if (dbIdToDelete) {
                addDeletedDbConversationId(dbIdToDelete);
                deleteConversationFromDatabase(dbIdToDelete);
            }

            // 如果删除的是当前对话，清空当前内容
            if (currentChatId === chatId) {
                currentChatId = null;
                messagesContainer.innerHTML = '';
                document.querySelector('.main-title').style.display = 'block';
                const hotTopicsContainer = document.querySelector('.hot-topics-container');
                if (hotTopicsContainer) hotTopicsContainer.style.display = 'flex';
            }
            
            renderChatList();
        }
        
        // 渲染对话列表
        function renderChatList() {
            const history = getChatHistory();
            recentChatList.innerHTML = '';
            
            // 如果是批量删除模式，显示操作按钮和全选
            if (isBatchDeleteMode) {
                // 操作按钮容器（放在最上面）
                const actionsContainer = document.createElement('div');
                actionsContainer.className = 'batch-delete-actions';
                
                const confirmBtn = document.createElement('button');
                confirmBtn.className = 'batch-delete-btn confirm';
                confirmBtn.textContent = '删除选中';
                confirmBtn.addEventListener('click', function() {
                    const selectedCheckboxes = recentChatList.querySelectorAll('.recent-chat-item-checkbox:checked');
                    if (selectedCheckboxes.length === 0) {
                        alert('请先选择要删除的对话');
                        return;
                    }
                    
                    let history = getChatHistory();
                    const selectedIds = Array.from(selectedCheckboxes).map(cb => cb.dataset.chatId);

                    // 检查是否删除了当前对话
                    const deletedCurrentChat = selectedIds.includes(currentChatId);

                    // 先收集待删对话的数据库ID，本地删除后同步删除数据库，避免同步时被删对话又回来
                    const dbIdsToDelete = history
                        .filter(chat => selectedIds.includes(chat.id) && chat.dbConversationId)
                        .map(chat => chat.dbConversationId);

                    history = history.filter(chat => !selectedIds.includes(chat.id));
                    saveChatHistory(history);

                    // 同步删除数据库中的对话（根治"删除后发送消息又出现"的问题）
                    dbIdsToDelete.forEach(dbId => {
                        addDeletedDbConversationId(dbId);
                        deleteConversationFromDatabase(dbId);
                    });

                    if (deletedCurrentChat) {
                        currentChatId = null;
                        messagesContainer.innerHTML = '';
                        document.querySelector('.main-title').style.display = 'block';
                        const hotTopicsContainer = document.querySelector('.hot-topics-container');
                        if (hotTopicsContainer) hotTopicsContainer.style.display = 'flex';
                    }
                    
                    isBatchDeleteMode = false;
                    renderChatList();
                });
                
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'batch-delete-btn cancel';
                cancelBtn.textContent = '取消';
                cancelBtn.addEventListener('click', function() {
                    isBatchDeleteMode = false;
                    renderChatList();
                });
                
                actionsContainer.appendChild(confirmBtn);
                actionsContainer.appendChild(cancelBtn);
                recentChatList.appendChild(actionsContainer);
                
                // 全选容器
                const selectAllContainer = document.createElement('div');
                selectAllContainer.className = 'select-all-container';
                
                const selectAllCheckbox = document.createElement('input');
                selectAllCheckbox.type = 'checkbox';
                selectAllCheckbox.className = 'select-all-checkbox';
                selectAllCheckbox.id = 'selectAllChats';
                selectAllCheckbox.addEventListener('change', function() {
                    const allCheckboxes = recentChatList.querySelectorAll('.recent-chat-item-checkbox');
                    allCheckboxes.forEach(cb => {
                        cb.checked = selectAllCheckbox.checked;
                    });
                });
                
                const selectAllLabel = document.createElement('span');
                selectAllLabel.className = 'select-all-label';
                selectAllLabel.textContent = '全选';
                selectAllLabel.addEventListener('click', function() {
                    selectAllCheckbox.checked = !selectAllCheckbox.checked;
                    selectAllCheckbox.dispatchEvent(new Event('change'));
                });
                
                selectAllContainer.appendChild(selectAllCheckbox);
                selectAllContainer.appendChild(selectAllLabel);
                recentChatList.appendChild(selectAllContainer);
            }
            
            // 排序：置顶的在最前面
            const sortedHistory = [...history].sort((a, b) => {
                if (a.pinned && !b.pinned) return -1;
                if (!a.pinned && b.pinned) return 1;
                return 0;
            });
            
            sortedHistory.forEach(chat => {
                const item = document.createElement('div');
                item.className = 'recent-chat-item' + (chat.id === currentChatId ? ' active' : '') + (isBatchDeleteMode ? ' batch-mode' : '');
                item.dataset.chatId = chat.id;
                
                // 如果是批量删除模式，添加复选框
                if (isBatchDeleteMode) {
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.className = 'recent-chat-item-checkbox';
                    checkbox.dataset.chatId = chat.id;
                    checkbox.addEventListener('click', function(e) {
                        e.stopPropagation();
                    });
                    item.appendChild(checkbox);
                }
                
                // 图标 + 标题容器
                const titleWrap = document.createElement('div');
                titleWrap.className = 'recent-chat-item-title-wrap';
                titleWrap.style.display = 'flex';
                titleWrap.style.alignItems = 'center';
                titleWrap.style.flex = '1';
                titleWrap.style.minWidth = '0';
                titleWrap.style.cursor = 'pointer';

                // 左侧圆形图标背景
                const iconCircle = document.createElement('div');
                iconCircle.className = 'recent-chat-item-icon-circle';
                iconCircle.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M13.2373 1.90039C15.9038 1.90039 17.2374 1.9002 18.2959 2.31641C19.8464 2.92621 21.0737 4.15361 21.6836 5.7041C22.0999 6.76261 22.1006 8.09619 22.1006 10.7627C22.1006 13.4293 22.0999 14.7628 21.6836 15.8213C21.0737 17.3719 19.8465 18.5991 18.2959 19.209C17.2374 19.6253 15.9039 19.626 13.2373 19.626H12.8115L7.64648 22.5186C6.56273 23.125 5.25306 22.2266 5.42871 20.9971L5.68457 19.2002C4.14336 18.5877 2.92363 17.3653 2.31641 15.8213C1.90016 14.7628 1.90039 13.4292 1.90039 10.7627C1.90039 8.09619 1.90011 6.76261 2.31641 5.7041C2.92629 4.15358 4.15355 2.92622 5.7041 2.31641C6.76262 1.90014 8.09611 1.90039 10.7627 1.90039H13.2373ZM10.7627 3.90039C9.40322 3.90039 8.47878 3.90148 7.75977 3.94824C7.05823 3.9939 6.69232 4.0772 6.43652 4.17773C5.40291 4.58424 4.58436 5.40275 4.17773 6.43652C4.0772 6.69227 3.9939 7.05817 3.94824 7.75977C3.90148 8.47879 3.90039 9.40325 3.90039 10.7627C3.90039 12.1221 3.90147 13.0466 3.94824 13.7656C3.9939 14.4671 4.07721 14.8331 4.17773 15.0889L4.25781 15.2793C4.68411 16.2179 5.45885 16.9586 6.42285 17.3418L7.88672 17.9229L7.66504 19.4824L7.55078 20.2783L11.834 17.8809L12.29 17.626H13.2373C14.5964 17.626 15.5205 17.6251 16.2393 17.5781C16.9408 17.5323 17.3072 17.4484 17.5635 17.3477C18.5972 16.9411 19.4157 16.1226 19.8223 15.0889C19.923 14.8326 20.0069 14.4662 20.0527 13.7646C20.0997 13.0459 20.1006 12.1218 20.1006 10.7627C20.1006 9.40364 20.0997 8.47952 20.0527 7.76074C20.0069 7.0591 19.923 6.69277 19.8223 6.43652C19.4156 5.40275 18.5971 4.58423 17.5635 4.17773C17.3077 4.07722 16.9417 3.9939 16.2402 3.94824C15.5212 3.90147 14.5967 3.90039 13.2373 3.90039H10.7627ZM7.40625 9.46875C8.17945 9.46875 8.80664 10.0959 8.80664 10.8691C8.80643 11.6422 8.17932 12.2686 7.40625 12.2686C6.63341 12.2683 6.00705 11.642 6.00684 10.8691C6.00684 10.0961 6.63328 9.46901 7.40625 9.46875ZM12.001 9.46875C12.774 9.46901 13.4004 10.0961 13.4004 10.8691C13.4002 11.642 12.7738 12.2683 12.001 12.2686C11.2279 12.2686 10.6008 11.6422 10.6006 10.8691C10.6006 10.0959 11.2278 9.46875 12.001 9.46875ZM16.5947 9.46875C17.3677 9.46901 17.9941 10.0961 17.9941 10.8691C17.9939 11.642 17.3676 12.2683 16.5947 12.2686C15.8217 12.2686 15.1945 11.6422 15.1943 10.8691C15.1943 10.0959 15.8215 9.46875 16.5947 9.46875Z" fill="currentColor"></path></svg>';

                // 标题部分
                const titleDiv = document.createElement('div');
                titleDiv.className = 'recent-chat-item-title';
                titleDiv.textContent = (chat.pinned ? '📌 ' : '') + chat.title;

                titleWrap.appendChild(iconCircle);
                titleWrap.appendChild(titleDiv);
                titleWrap.addEventListener('click', function(e) {
                    e.stopPropagation();
                    loadChat(chat.id);
                });

                item.appendChild(titleWrap);
                
                // 非批量删除模式时才显示菜单
                if (!isBatchDeleteMode) {
                    const taskState = chat.taskState || {};
                    const taskRunning = taskState.phase === 'running' || taskState.phase === 'waiting_approval';
                    if (taskRunning || taskState.unreadTerminal) {
                        const statusBtn = document.createElement('button');
                        statusBtn.type = 'button';
                        statusBtn.className = 'recent-chat-item-task-status ' + (taskRunning ? 'running' : 'complete');
                        statusBtn.setAttribute('aria-label', taskRunning ? '任务执行中' : '任务已结束，点击查看');
                        statusBtn.innerHTML = taskRunning
                            ? createWorkflowSpinnerIcon(18, 3.5)
                            : createWorkflowCheckIcon(18, '#2787f5');
                        statusBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            loadChat(chat.id);
                        });
                        item.appendChild(statusBtn);
                        recentChatList.appendChild(item);
                        return;
                    }
                    // 3个小点菜单按钮
                    const menuBtn = document.createElement('div');
                    menuBtn.className = 'recent-chat-item-menu-btn';
                    menuBtn.textContent = '···';
                    menuBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        // 关闭其他所有菜单
                        document.querySelectorAll('.recent-chat-item-menu.open').forEach(menu => {
                            menu.classList.remove('open');
                            menu.classList.remove('bottom-up');
                        });
                        // 打开当前菜单
                        const menu = item.querySelector('.recent-chat-item-menu');
                        
                        // 检查是否需要向上/向下弹出
                        const rect = item.getBoundingClientRect();
                        const recentChatListRect = recentChatList.getBoundingClientRect();
                        
                        // 如果菜单项在列表的上半部分，则向下弹出（默认）
                        // 如果菜单项在列表的下半部分，则向上弹出
                        if (rect.top > recentChatListRect.top + recentChatListRect.height / 2) {
                            menu.classList.add('bottom-up');
                        }
                        
                        menu.classList.toggle('open');
                    });
                    
                    // 弹出菜单
                    const menu = document.createElement('div');
                    menu.className = 'recent-chat-item-menu';
                    
                    // 置顶/取消置顶选项
                    const pinItem = document.createElement('div');
                    pinItem.className = 'recent-chat-item-menu-item';
                    pinItem.textContent = chat.pinned ? '取消置顶' : '置顶';
                    pinItem.addEventListener('click', function(e) {
                        e.stopPropagation();
                        pinChat(chat.id);
                        menu.classList.remove('open');
                    });
                    
                    // 重命名选项
                    const renameItem = document.createElement('div');
                    renameItem.className = 'recent-chat-item-menu-item';
                    renameItem.textContent = '重命名';
                    renameItem.addEventListener('click', function(e) {
                        e.stopPropagation();
                        renameChat(chat.id);
                        menu.classList.remove('open');
                    });
                    
                    // 删除选项
                    const deleteItem = document.createElement('div');
                    deleteItem.className = 'recent-chat-item-menu-item delete';
                    deleteItem.textContent = '删除';
                    deleteItem.addEventListener('click', function(e) {
                        e.stopPropagation();
                        let history = getChatHistory();
                        // 先取出待删对话的数据库ID，本地删除后同步删除数据库，避免同步时被删对话又回来
                        const dbIdToDelete = chat.dbConversationId || null;
                        history = history.filter(c => c.id !== chat.id);
                        saveChatHistory(history);
                        // 同步删除数据库中的对话（根治"删除后发送消息又出现"的问题）
                        if (dbIdToDelete) {
                            addDeletedDbConversationId(dbIdToDelete);
                            deleteConversationFromDatabase(dbIdToDelete);
                        }

                        if (currentChatId === chat.id) {
                            currentChatId = null;
                            messagesContainer.innerHTML = '';
                            document.querySelector('.main-title').style.display = 'block';
                            const hotTopicsContainer = document.querySelector('.hot-topics-container');
                            if (hotTopicsContainer) hotTopicsContainer.style.display = 'flex';
                        }

                        menu.classList.remove('open');
                        renderChatList();
                    });
                    
                    // 批量删除选项
                    const batchDeleteItem = document.createElement('div');
                    batchDeleteItem.className = 'recent-chat-item-menu-item';
                    batchDeleteItem.textContent = '批量删除';
                    batchDeleteItem.addEventListener('click', function(e) {
                        e.stopPropagation();
                        isBatchDeleteMode = true;
                        menu.classList.remove('open');
                        renderChatList();
                    });
                    
                    menu.appendChild(pinItem);
                    menu.appendChild(renameItem);
                    menu.appendChild(deleteItem);
                    menu.appendChild(batchDeleteItem);
                    
                    item.appendChild(menuBtn);
                    item.appendChild(menu);
                }
                
                // 点击对话项加载对话（非批量删除模式时）
                recentChatList.appendChild(item);
            });
            
            // 点击页面其他地方关闭菜单
            document.addEventListener('click', function() {
                document.querySelectorAll('.recent-chat-item-menu.open').forEach(menu => {
                    menu.classList.remove('open');
                });
            });
            

        }
        
        // 获取DOM元素
        const authOverlay = document.getElementById('authOverlay');
        const authModalBox = document.getElementById('authModalBox');
        const authCloseBtn = document.getElementById('authCloseBtn');
        const qqLoginBtn = document.getElementById('qqLoginBtn');
        const userAvatar = document.getElementById('userAvatar');
        const avatarPlaceholder = document.getElementById('avatarPlaceholder');
        const userName = document.getElementById('userName');
        const userQQ = document.getElementById('userQQ');
        const userInfoDiv = document.getElementById('userInfoDiv');
        const newChatTopBtn = document.getElementById('newChatTopBtn');
        const aiCreateBtn = document.getElementById('aiCreateBtn');
        const recentChatBtn = document.getElementById('recentChatBtn');
        const recentChatList = document.getElementById('recentChatList');
        const recentChatArrow = document.getElementById('recentChatArrow');
        
        // 登录相关DOM
        const loginAccount = document.getElementById('loginAccount');
        const loginPassword = document.getElementById('loginPassword');
        const loginBtn = document.getElementById('loginBtn');
        const loginQQ = document.getElementById('loginQQ');
        const loginCode = document.getElementById('loginCode');
        const loginByCodeBtn = document.getElementById('loginByCodeBtn');
        const loginSendCodeBtn = document.getElementById('loginSendCodeBtn');
        let loginCodeTimer = null;
        let loginCountdown = 69;
        
        // 注册相关DOM
        const registerQQ = document.getElementById('registerQQ');
        const registerCode = document.getElementById('registerCode');
        const registerBtn = document.getElementById('registerBtn');
        const sendCodeBtn = document.getElementById('sendCodeBtn');
        let codeTimer = null;
        let countdown = 69;
        
        // 弹窗光标跟随柔光
        if (authModalBox) {
            authModalBox.addEventListener('mousemove', (e) => {
                const rect = authModalBox.getBoundingClientRect();
                authModalBox.style.setProperty('--mouse-x', `${e.clientX - rect.left}px`);
                authModalBox.style.setProperty('--mouse-y', `${e.clientY - rect.top}px`);
            });
        }
        
        // Tab切换（3D滑入旋转动画）
        const authTabs = document.querySelectorAll('.auth-tab-btn');
        authTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                if (tab.classList.contains('active')) return;
                if (document.querySelector('.auth-form.enter-from-left, .auth-form.enter-from-right, .auth-form.leave-to-left, .auth-form.leave-to-right')) return;
                
                const target = tab.dataset.tab;
                const currentForm = document.querySelector('.auth-form.active');
                const nextForm = document.getElementById(`${target}-form`);
                
                const currentTab = document.querySelector('.auth-tab-btn.active');
                const currentIndex = Array.from(authTabs).indexOf(currentTab);
                const targetIndex = Array.from(authTabs).indexOf(tab);
                const direction = targetIndex > currentIndex ? 'right' : 'left';
                
                authTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                if (currentForm && currentForm !== nextForm) {
                    currentForm.classList.remove('active');
                    currentForm.classList.add(direction === 'right' ? 'leave-to-right' : 'leave-to-left');
                    currentForm.addEventListener('animationend', function onLeave() {
                        currentForm.classList.remove('leave-to-left', 'leave-to-right');
                        currentForm.removeEventListener('animationend', onLeave);
                    });
                }
                
                if (nextForm) {
                    nextForm.classList.add('active');
                    nextForm.classList.add(direction === 'right' ? 'enter-from-left' : 'enter-from-right');
                    nextForm.addEventListener('animationend', function onEnter() {
                        nextForm.classList.remove('enter-from-right', 'enter-from-left');
                        nextForm.removeEventListener('animationend', onEnter);
                    });
                }
            });
        });
        
        // 打开/关闭弹窗
        function openAuthModal(tabName) {
            authOverlay.classList.add('show');
            if (tabName) {
                const targetTab = document.querySelector(`.auth-tab-btn[data-tab="${tabName}"]`);
                if (targetTab && !targetTab.classList.contains('active')) {
                    targetTab.click();
                }
            }
        }
        function closeAuthModal() {
            authOverlay.classList.remove('show');
        }
        
        authCloseBtn.addEventListener('click', closeAuthModal);
        
        // 用户信息点击跳转
        userInfoDiv.addEventListener('click', function() {
            window.location.href = 'user/user_xinxi.php';
        });

        // 顶部新建对话按钮点击事件
        newChatTopBtn.addEventListener('click', async function() {
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
            const imageGenBtn2 = document.getElementById('imageGenBtn');
            if (imageGenBtn2) imageGenBtn2.classList.remove('active');
        });

        // 最近对话按钮点击事件
        recentChatBtn.addEventListener('click', function() {
            recentChatList.classList.toggle('open');
            // 箭头旋转效果：默认向右，展开时旋转90度向下
            recentChatArrow.style.transform = recentChatList.classList.contains('open') ? 'rotate(90deg)' : 'rotate(0deg)';
            recentChatArrow.style.transition = 'transform 0.3s';
        });

        // 聊天相关DOM元素
        const mainContent = document.querySelector('.main-content');
        let messagesContainer = document.querySelector('.messages-container');
        const messageInput = document.querySelector('.message-input');
        let sendBtn = null;
        let sendBtnImg = null;
        const deepThinkingLabel = document.getElementById('deepThinkingLabel');
        const expertLabel = document.getElementById('expertLabel');
        const inputContainer = document.querySelector('.input-container');
        let isExpertMode = false;
        let isSpecialistMode = false;
        let specialistRouteInfo = null;
        
        // 语音识别相关
        let recognition = null;
        let isRecording = false;

        // 语音播报相关全局变量
        let isVoiceBroadcastEnabled = true; // 默认开启语音播报
        let currentSpeechUtterance = null; // 当前播放的语音

        // 停止语音播报（全局函数）
        window.stopVoiceBroadcast = function() {
            // 若当前有 TTS 在播报，派发 stopped 回调供 VoiceChat 模块恢复麦克风
            var __ttsActive = (window.speechSynthesis && window.speechSynthesis.speaking) ||
                              (typeof currentAudio !== 'undefined' && currentAudio && !currentAudio.paused);
            if (__ttsActive && typeof window.__onTtsEnd === 'function') {
                try { window.__onTtsEnd('stopped'); } catch(e) { console.warn('[TTS] __onTtsEnd(stopped) 回调异常:', e); }
            }
            // 停止浏览器TTS
            if (window.speechSynthesis) {
                window.speechSynthesis.cancel();
            }
            currentSpeechUtterance = null;
            
            // 停止阿里云音频
            if (currentAudio) {
                currentAudio.pause();
                currentAudio.currentTime = 0;
                currentAudio = null;
                window.currentAudio = null;
            }
        };

        // 当前播放的音频对象
        let currentAudio = null;

        // 语音播报函数（全局函数）
        window.speakText = function(text) {
            

            // 实时语音对话模式下，无论语音播报开关是否关闭，都强制语音回答。
            var voiceChatActive = typeof window.VoiceChat !== 'undefined' && typeof window.VoiceChat.isActive === 'function' && window.VoiceChat.isActive();

            // #region debug-point H4:tts-entry
            __reportDebugSave('H4', 'script-1c-save.php:speakText', 'speakText called', { textPreview: (text || '').substring(0, 80), isVoiceBroadcastEnabled: isVoiceBroadcastEnabled, voiceChatActive: voiceChatActive });
            // #endregion

            // 普通模式下语音播报被禁用直接返回；实时语音对话模式下强制播报。
            if (!isVoiceBroadcastEnabled && !voiceChatActive) {
                
                return;
            }

            // 停止之前的播报
            window.stopVoiceBroadcast();

            // 清理文本，移除Markdown标记和特殊字符
            const cleanText = text
                .replace(/```[\s\S]*?```/g, '代码块') // 移除代码块
                .replace(/`([^`]+)`/g, '$1') // 移除行内代码
                .replace(/\*\*([^*]+)\*\*/g, '$1') // 移除加粗
                .replace(/\*([^*]+)\*/g, '$1') // 移除斜体
                .replace(/#+\s/g, '') // 移除标题标记
                .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1') // 移除链接，保留文本
                .replace(/!\[([^\]]*)\]\([^)]+\)/g, '图片') // 替换图片
                .replace(/\n+/g, ' ') // 将换行替换为空格
                .replace(/\s+/g, ' ') // 合并多个空格
                .trim();

            if (!cleanText) {
                
                return;
            }

            // Work 模式下仅播报第一个短句兜底：若其他调用路径传入长文本，
            // 同样截取到第一个句号/问号/感叹号/换行，最大不超过 60 字。
            let textToSpeak = cleanText;
            if (document.body && document.body.classList.contains('work-mode')) {
                const sentenceEndChars = /[。！？.!?\n]/;
                const match = cleanText.match(sentenceEndChars);
                let endIndex = (match && typeof match.index === 'number') ? match.index : -1;
                const maxShortLen = 60;
                if (endIndex === -1 && cleanText.length > maxShortLen) {
                    endIndex = maxShortLen - 1;
                }
                if (endIndex !== -1) {
                    textToSpeak = cleanText.substring(0, endIndex + 1).trim();
                }
            }

            if (!textToSpeak) {
                
                return;
            }

            

            // 获取配置
            const voiceConfig = window.VOICE_CONFIG || {};
            
            // 判断是否使用MinMax TTS
            if (voiceConfig.minimax && voiceConfig.minimax.enabled && !voiceConfig.use_browser_tts) {
                // 使用MinMax语音合成
                speakWithMinMax(textToSpeak, voiceConfig.minimax);
            } else {
                // 使用浏览器原生TTS
                speakWithBrowserTTS(textToSpeak, voiceConfig);
            }
        };

        // 使用MinMax语音合成
        function speakWithMinMax(text, minimaxConfig) {
            

            // 限制文本长度
            const maxLength = 500;
            const truncatedText = text.length > maxLength ? text.substring(0, maxLength) + '...' : text;

            // 调用后端TTS API
            
            fetch('/api/tts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ text: truncatedText })
            })
            .then(response => {
                
                return response.json();
            })
            .then(data => {
                
                if (data.success && data.audio) {
                    // 播放音频
                    const audioFormat = data.format || 'mp3';
                    const audioData = 'data:audio/' + audioFormat + ';base64,' + data.audio;
                    currentAudio = new Audio(audioData);
                    window.currentAudio = currentAudio;
                    currentAudio.volume = minimaxConfig.volume || 1.0;

                    currentAudio.onplay = function() {
                        
                        triggerIslandPulse();
                        // 派发 TTS 开始回调，供 VoiceChat 模块实施回声抑制
                        if (typeof window.__onTtsStart === 'function') {
                            try { window.__onTtsStart(); } catch(e) { console.warn('[TTS] __onTtsStart 回调异常:', e); }
                        }
                    };
                    currentAudio.onended = function() {
                        // 派发 TTS 结束回调，供 VoiceChat 模块恢复麦克风监听
                        if (typeof window.__onTtsEnd === 'function') {
                            try { window.__onTtsEnd('complete'); } catch(e) { console.warn('[TTS] __onTtsEnd 回调异常:', e); }
                        }
                    };
                    currentAudio.onerror = function(e) {
                        console.error('MinMax语音播报错误:', e);
                        // 派发 TTS 错误回调，供 VoiceChat 模块恢复麦克风监听
                        if (typeof window.__onTtsEnd === 'function') {
                            try { window.__onTtsEnd('error'); } catch(e) { console.warn('[TTS] __onTtsEnd 回调异常:', e); }
                        }
                    };

                    currentAudio.play().catch(e => {
                        console.error('播放音频失败:', e);
                        // 如果播放失败，回退到浏览器TTS
                        speakWithBrowserTTS(text, window.VOICE_CONFIG || {});
                    });
                } else {
                    console.error('MinMax语音合成失败:', data.error);
                    // 失败时回退到浏览器TTS
                    speakWithBrowserTTS(text, window.VOICE_CONFIG || {});
                }
            })
            .catch(error => {
                console.error('请求MinMax TTS失败:', error);
                // 失败时回退到浏览器TTS
                speakWithBrowserTTS(text, window.VOICE_CONFIG || {});
            });
        }

        // 使用浏览器原生TTS
        function speakWithBrowserTTS(text, voiceConfig) {
            
            
            // 检查浏览器是否支持语音合成
            if (!window.speechSynthesis) {
                console.warn('浏览器不支持语音合成功能');
                return;
            }

            // 创建语音合成实例
            currentSpeechUtterance = new SpeechSynthesisUtterance(text);
            currentSpeechUtterance.lang = voiceConfig.lang || 'zh-CN';
            currentSpeechUtterance.rate = voiceConfig.rate || 1.0;
            currentSpeechUtterance.pitch = voiceConfig.pitch || 1.0;
            currentSpeechUtterance.volume = voiceConfig.volume || 1.0;

            // 添加事件监听
            currentSpeechUtterance.onstart = function() {
                
                triggerIslandPulse();
                // 派发 TTS 开始回调，供 VoiceChat 模块实施回声抑制
                if (typeof window.__onTtsStart === 'function') {
                    try { window.__onTtsStart(); } catch(e) { console.warn('[TTS] __onTtsStart 回调异常:', e); }
                }
            };
            currentSpeechUtterance.onend = function() {
                // 派发 TTS 结束回调，供 VoiceChat 模块恢复麦克风监听
                if (typeof window.__onTtsEnd === 'function') {
                    try { window.__onTtsEnd('complete'); } catch(e) { console.warn('[TTS] __onTtsEnd 回调异常:', e); }
                }
            };
            currentSpeechUtterance.onerror = function(event) {
                console.error('浏览器语音播报错误:', event.error);
                // 派发 TTS 错误回调，供 VoiceChat 模块恢复麦克风监听
                if (typeof window.__onTtsEnd === 'function') {
                    try { window.__onTtsEnd('error'); } catch(e) { console.warn('[TTS] __onTtsEnd 回调异常:', e); }
                }
            };

            // 尝试使用配置的音色
            let voices = window.speechSynthesis.getVoices();
            
            // 选择语音的函数
            function selectVoice(voicesList) {
                if (voiceConfig.voice_name) {
                    const namedVoice = voicesList.find(voice => 
                        voice.name.includes(voiceConfig.voice_name)
                    );
                    if (namedVoice) {
                        
                        return namedVoice;
                    }
                }
                const chineseVoice = voicesList.find(voice => voice.lang && voice.lang.includes('zh'));
                if (chineseVoice) {
                    
                    return chineseVoice;
                }
                
                return null;
            }
            
            if (!voices || voices.length === 0) {
                
                window.speechSynthesis.onvoiceschanged = function() {
                    voices = window.speechSynthesis.getVoices();
                    const selectedVoice = selectVoice(voices);
                    if (selectedVoice) {
                        currentSpeechUtterance.voice = selectedVoice;
                    }
                    window.speechSynthesis.speak(currentSpeechUtterance);
                };
            } else {
                const selectedVoice = selectVoice(voices);
                if (selectedVoice) {
                    currentSpeechUtterance.voice = selectedVoice;
                }
                window.speechSynthesis.speak(currentSpeechUtterance);
            }
        }
