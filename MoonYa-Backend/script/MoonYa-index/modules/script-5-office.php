<?php
/**
 * script-5-office.php
 * 办公室 2.5D 视图：布局、视图切换、Agent 运行状态（屏幕蓝/黑）、MoonYa 派发动画。
 *
 * 数据来源（全部只读，不触碰任何调度逻辑）：
 *   - 主窗口：监听 script-1e 转发的 'moonya:team-event' 事件总线（SSE 镜像）
 *   - 独立窗口：3s 轮询 GET /api/team.php?action=office_status
 *   - Image Agent：SSE attachment_agent_status（图像生成活动）
 *   - Voice Agent：TTS 生命周期钩子 + PTT 录音态（class 观察）
 */
?>
<script>
(function () {
    'use strict';

    var POPOUT = document.body && document.body.dataset.officePopout === '1';
    var authenticated = document.body && document.body.dataset.authenticated === '1';
    var statusPollTimer = null;

    var officeView = document.getElementById('officeView');
    var stage = document.getElementById('officeStage');
    var walker = document.getElementById('officeWalker');
    var bubble = document.getElementById('officeBubble');
    var popoutBtn = document.getElementById('officePopoutBtn');
    var agentCard = document.getElementById('officeAgentCard');
    var agentCardAvatar = document.getElementById('officeAgentCardAvatar');
    var agentCardName = document.getElementById('officeAgentCardName');
    var agentCardTitle = document.getElementById('officeAgentCardTitle');
    var agentCardStatus = document.getElementById('officeAgentCardStatus');
    var agentCardSummary = document.getElementById('officeAgentCardSummary');
    var agentCardSkills = document.getElementById('officeAgentCardSkills');
    var activeProfileTrigger = null;
    if (!officeView || !stage) return;

    // ---------- 工位布局（参考图的正视 3 列 × 3 行） ----------
    var MAX_GAP_X = 285;
    var MAX_GAP_Y = 205;
    var ROW_SCALE = [0.96, 0.98, 1];

    var stations = {};    // agent_key -> { el, row, col, x, y }
    Array.prototype.forEach.call(stage.querySelectorAll('.workstation'), function (el) {
        stations[el.dataset.agent] = {
            el: el,
            row: parseInt(el.dataset.row, 10),
            col: parseInt(el.dataset.col, 10),
            x: 0,
            y: 0
        };
    });

    function layoutOffice() {
        if (!stage.offsetWidth) return; // 隐藏时不布局
        var w = stage.offsetWidth;
        var h = stage.offsetHeight;
        var gapX = Math.min(MAX_GAP_X, Math.max(205, (w - 310) / 3));
        var gapY = Math.min(MAX_GAP_Y, Math.max(160, (h - 250) / 3));
        var baseX = w / 2;
        var baseY = Math.max(215, Math.min(245, h * 0.30));
        Object.keys(stations).forEach(function (key) {
            var st = stations[key];
            st.x = baseX + (st.col - 1) * gapX;
            st.y = baseY + st.row * gapY;
            var scale = ROW_SCALE[st.row] || 1;
            st.el.style.left = st.x + 'px';
            st.el.style.top = st.y + 'px';
            st.el.style.transform = 'translate(-50%, -100%) scale(' + scale + ')';
            st.el.style.zIndex = String(10 + st.row * 10);
        });
    }

    // ---------- 屏幕蓝/黑状态 ----------
    var activeAgents = {};        // agent_key -> Set(run/task reference)
    var voiceSources = {};        // voice 虚拟源: tts / ptt
    var imageRefs = new Set();

    function applyScreen(key, on) {
        var st = stations[key];
        if (!st) return;
        st.el.classList.toggle('ws-active', !!on);
        if (activeProfileTrigger && activeProfileTrigger.closest('.workstation') === st.el) {
            refreshProfileStatus(st.el);
        }
    }

    function referencesFor(key) {
        if (!(activeAgents[key] instanceof Set)) activeAgents[key] = new Set();
        return activeAgents[key];
    }

    function refreshAgentScreen(key) {
        var active = referencesFor(key).size > 0;
        if (key === 'moonya') active = true;
        if (key === 'image') active = active || imageRefs.size > 0;
        if (key === 'voice') {
            active = active || Object.keys(voiceSources).some(function (source) { return voiceSources[source]; });
        }
        applyScreen(key, active);
    }

    function setAgentActive(key, on, reference) {
        if (key === 'voice') { /* voice 由源计数驱动 */ return; }
        if (key === 'image') {
            var imageRef = String(reference || 'legacy:image');
            if (on) imageRefs.add(imageRef); else imageRefs.delete(imageRef);
            refreshAgentScreen('image');
            return;
        }
        var refs = referencesFor(key);
        var ref = String(reference || ('legacy:' + key));
        if (on) refs.add(ref); else refs.delete(ref);
        refreshAgentScreen(key);
    }

    function refreshVoice() {
        refreshAgentScreen('voice');
    }

    function clearRunScreens(runId) {
        var prefix = String(runId || '') + ':';
        Object.keys(activeAgents).forEach(function (key) {
            var refs = referencesFor(key);
            Array.from(refs).forEach(function (ref) {
                if (ref.indexOf(prefix) === 0) refs.delete(ref);
            });
            refreshAgentScreen(key);
        });
    }

    function runIdFromAgentReference(reference, key) {
        var ref = String(reference || '');
        var lifecycleSuffix = ':' + key + ':lifecycle';
        if (ref.endsWith(lifecycleSuffix)) {
            return ref.slice(0, -lifecycleSuffix.length);
        }
        var turnMarker = ':' + key + ':turn:';
        var turnIndex = ref.indexOf(turnMarker);
        return turnIndex > 0 ? ref.slice(0, turnIndex) : '';
    }

    // ---------- MoonYa 行走 + 气泡派发动画 ----------
    var animQueue = Promise.resolve();
    var animatedEvents = {};      // run_id + agent + task 幂等；一次真实 Agent 调用只派发一次
    var pendingTaskText = '';
    var moonHomeHidden = false;

    function moonStation() { return stations['moonya']; }

    function setMoonHomeVisible(visible) {
        var st = moonStation();
        if (!st) return;
        var img = st.el.querySelector('.ws-character');
        var trigger = st.el.querySelector('.ws-character-trigger');
        var emptyChair = st.el.querySelector('.ws-empty-chair');
        var name = st.el.querySelector('.ws-name');
        if (img) img.style.visibility = visible ? 'visible' : 'hidden';
        if (trigger) trigger.style.visibility = visible ? 'visible' : 'hidden';
        if (name) name.style.visibility = visible ? 'visible' : 'hidden';
        if (emptyChair) emptyChair.classList.toggle('show', !visible);
        if (!visible && activeProfileTrigger === trigger) hideAgentCard();
        moonHomeHidden = !visible;
    }

    // ---------- 人物资料卡 ----------
    function refreshProfileStatus(workstation) {
        if (!agentCardStatus || !workstation) return;
        var active = workstation.classList.contains('ws-active');
        agentCardStatus.classList.toggle('is-active', active);
        var text = agentCardStatus.querySelector('.office-agent-status-text');
        if (text) text.textContent = active ? '工作中' : '空闲中';
    }

    function positionAgentCard(trigger) {
        if (!agentCard || !trigger || agentCard.hidden) return;
        var stageRect = stage.getBoundingClientRect();
        var triggerRect = trigger.getBoundingClientRect();
        var cardRect = agentCard.getBoundingClientRect();
        var edge = 12;
        var gap = 18;
        var left = triggerRect.right - stageRect.left + gap;
        if (left + cardRect.width > stageRect.width - edge) {
            left = triggerRect.left - stageRect.left - cardRect.width - gap;
        }
        left = Math.max(edge, Math.min(left, stageRect.width - cardRect.width - edge));

        var top = triggerRect.top - stageRect.top - 18;
        top = Math.max(edge, Math.min(top, stageRect.height - cardRect.height - edge));
        agentCard.style.left = Math.round(left) + 'px';
        agentCard.style.top = Math.round(top) + 'px';
    }

    function hideAgentCard(options) {
        if (!agentCard || agentCard.hidden) return;
        var trigger = activeProfileTrigger;
        agentCard.classList.remove('show');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
        activeProfileTrigger = null;
        window.setTimeout(function () {
            if (!activeProfileTrigger) agentCard.hidden = true;
        }, 170);
        if (options && options.restoreFocus && trigger && trigger.offsetParent !== null) {
            trigger.focus();
        }
    }

    function showAgentCard(trigger) {
        if (!agentCard || !trigger) return;
        var workstation = trigger.closest('.workstation');
        if (!workstation) return;
        if (activeProfileTrigger === trigger && !agentCard.hidden) {
            hideAgentCard();
            return;
        }
        if (activeProfileTrigger) activeProfileTrigger.setAttribute('aria-expanded', 'false');
        activeProfileTrigger = trigger;
        trigger.setAttribute('aria-expanded', 'true');
        agentCard.dataset.agent = workstation.dataset.agent || '';

        agentCardName.textContent = workstation.dataset.profileName || workstation.dataset.agent || '';
        agentCardTitle.textContent = workstation.dataset.profileTitle || '';
        agentCardSummary.textContent = workstation.dataset.profileSummary || '';
        agentCardSkills.textContent = '';
        String(workstation.dataset.profileSkills || '').split('|').filter(Boolean).forEach(function (skill) {
            var tag = document.createElement('span');
            tag.className = 'office-agent-skill';
            tag.textContent = skill;
            agentCardSkills.appendChild(tag);
        });

        var avatar = workstation.dataset.profileAvatar || workstation.dataset.profileAvatarFallback || '';
        var fallback = workstation.dataset.profileAvatarFallback || '';
        agentCardAvatar.alt = (workstation.dataset.profileName || '人物') + '头像';
        agentCardAvatar.onerror = function () {
            if (fallback && agentCardAvatar.src.indexOf(fallback) === -1) agentCardAvatar.src = fallback;
        };
        agentCardAvatar.src = avatar;
        refreshProfileStatus(workstation);

        agentCard.hidden = false;
        agentCard.classList.remove('show');
        positionAgentCard(trigger);
        void agentCard.offsetWidth;
        agentCard.classList.add('show');
    }

    function walkerHeight() {
        var img = walker.querySelector('img');
        return (img && img.getBoundingClientRect().height) || 200;
    }

    function OfficeActor(key) {
        this.key = key;
        this.station = stations[key] || null;
        this.frame = 0;
        this.frameTimer = null;
    }

    OfficeActor.prototype.startWalkFrames = function (image) {
        var actor = this;
        clearInterval(this.frameTimer);
        this.frameTimer = setInterval(function () {
            actor.frame = (actor.frame % 4) + 1;
            image.src = '/assets/office/walk/' + actor.key + '-' + actor.frame + '.png';
        }, 150);
    };

    OfficeActor.prototype.stopWalkFrames = function (image) {
        clearInterval(this.frameTimer);
        this.frameTimer = null;
        if (image) {
            image.src = this.key === 'moonya' && image.closest('.office-walker')
                ? '/assets/office/moonya.png'
                : '/assets/office/seated-back/' + this.key + '.png';
        }
    };

    // All nine actors expose the same path API. Current automatic dispatch uses
    // MoonYa only; the remaining actors are ready for later office behaviors.
    OfficeActor.prototype.walk = function (path) {
        var actor = this;
        if (!Array.isArray(path) || path.length === 0) return Promise.resolve();
        var image = actor.key === 'moonya'
            ? walker.querySelector('img')
            : (actor.station && actor.station.el.querySelector('.ws-character'));
        if (!image) return Promise.resolve();
        actor.startWalkFrames(image);
        var sequence = Promise.resolve();
        path.forEach(function (point) {
            sequence = sequence.then(function () {
                if (actor.key === 'moonya') {
                    var currentX = parseFloat(walker.style.left || point.x);
                    walker.classList.toggle('facing-left', point.x < currentX);
                    walker.classList.toggle('facing-right', point.x >= currentX);
                    return moveWalkerTo(point.x, point.y, point.duration);
                }
                return new Promise(function (resolve) {
                    actor.station.el.classList.add('actor-walking');
                    actor.station.el.style.setProperty('--actor-walk-x', (point.x || 0) + 'px');
                    actor.station.el.style.setProperty('--actor-walk-y', (point.y || 0) + 'px');
                    setTimeout(resolve, Number(point.duration || 450));
                });
            });
        });
        return sequence.finally(function () {
            actor.stopWalkFrames(image);
            if (actor.station) actor.station.el.classList.remove('actor-walking');
        });
    };

    var officeActors = {};
    Object.keys(stations).forEach(function (key) { officeActors[key] = new OfficeActor(key); });
    window.OfficeActor = OfficeActor;
    window.MoonYaOfficeActors = officeActors;

    function moveWalkerTo(x, y, duration) {
        return new Promise(function (resolve) {
            duration = Math.max(180, Number(duration || 520));
            walker.style.transitionDuration = duration + 'ms';
            walker.style.left = x + 'px';
            walker.style.top = y + 'px';
            setTimeout(resolve, duration + 30);
        });
    }

    function showBubble(text, x, y) {
        bubble.innerHTML = '<span class="bubble-from">MoonYa 派发任务</span>' +
            String(text || '').replace(/[<>&"]/g, function (c) {
                return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c];
            });
        bubble.style.left = x + 'px';
        bubble.style.top = (y - walkerHeight() - 10) + 'px';
        bubble.classList.add('show');
    }

    function hideBubble() {
        bubble.classList.remove('show');
    }

    function playDispatch(agentKey, text) {
        var target = stations[agentKey];
        var home = moonStation();
        if (!target || !home) return Promise.resolve();

        // 目标点：目标工位左侧空位。路径先下到行间过道，再沿最左侧纵向过道
        // 换行，最后从目标桌左侧进入，禁止横穿任何工位和显示器。
        var tx = target.x - 138;
        var ty = target.y + 12;
        var leftMostX = Math.min.apply(null, Object.keys(stations).map(function (key) {
            return stations[key].x;
        }));
        var outerLaneX = leftMostX - 152;
        var homeExitX = home.x - 138;
        var homeExitY = home.y + 12;
        var homeLaneY = home.y + 72;
        var targetLaneY = target.y + 72;

        setMoonHomeVisible(false);
        walker.classList.add('walking');
        walker.classList.remove('idle');
        // 坐席图在桌前消失后，行走图从桌侧出口出现，避免第一步穿过自己的电脑。
        walker.style.left = homeExitX + 'px';
        walker.style.top = homeExitY + 'px';

        // 强制 reflow 让起点生效后再过渡
        void walker.offsetWidth;

        var routeOut = [
            { x: homeExitX, y: homeLaneY, duration: 300 },
            { x: outerLaneX, y: homeLaneY, duration: 520 },
            { x: outerLaneX, y: targetLaneY, duration: target.row === home.row ? 180 : 560 },
            { x: tx, y: targetLaneY, duration: 620 },
            { x: tx, y: ty, duration: 280 }
        ];
        return officeActors.moonya.walk(routeOut)
            .then(function () {
                walker.classList.add('idle'); // 到位后停止上下浮动
                showBubble(text, tx, ty);
                // 派单只是视觉反馈，不等待 Agent 执行完成；短暂停留后立即回工位。
                return new Promise(function (resolve) { setTimeout(resolve, 1050); });
            })
            .then(function () {
                hideBubble();
                walker.classList.remove('idle');
                return officeActors.moonya.walk([
                    { x: tx, y: targetLaneY, duration: 280 },
                    { x: outerLaneX, y: targetLaneY, duration: 620 },
                    { x: outerLaneX, y: homeLaneY, duration: target.row === home.row ? 180 : 560 },
                    { x: homeExitX, y: homeLaneY, duration: 520 },
                    { x: homeExitX, y: homeExitY, duration: 300 }
                ]);
            })
            .then(function () {
                walker.classList.remove('walking');
                setMoonHomeVisible(true);
            })
            .catch(function () {
                walker.classList.remove('walking');
                setMoonHomeVisible(true);
            });
    }

    function queueDispatch(agentKey, text) {
        animQueue = animQueue.then(function () {
            return playDispatch(agentKey, text);
        });
    }

    function officeVisible() {
        return POPOUT || document.body.classList.contains('office-active');
    }

    // ---------- 团队事件总线（主窗口） ----------
    function agentKeyOf(d) {
        return (d && d.agent && d.agent.key) || (d && d.agent_key) || '';
    }

    function emitOfficeActivity(agentKey, status, source) {
        if (!authenticated) return;
        var detail = {
            type: 'office_activity',
            agent_key: agentKey,
            status: status,
            source: source,
            activity_id: source + ':' + agentKey
        };
        onTeamEvent({ detail: detail });
        var runtime = window.MoonYaConversationRuntime && window.MoonYaConversationRuntime.current
            ? window.MoonYaConversationRuntime.current()
            : null;
        if (runtime && runtime.dbConversationId && window.MoonYaSharedRuntime) {
            window.MoonYaSharedRuntime.officeEvent({
                conversationId: runtime.dbConversationId,
                clientMessageId: null,
                runId: runtime.activeRunId || null,
                event: detail
            }).catch(function() {});
        }
    }

    function onTeamEvent(e) {
        if (!authenticated) return;
        var d = e.detail || {};

        if (d.type === 'office_activity') {
            var activityRef = String(d.activity_id || (d.source + ':' + d.agent_key));
            if (d.agent_key === 'voice') {
                voiceSources[activityRef] = d.status === 'started';
                refreshVoice();
            } else {
                setAgentActive(d.agent_key, d.status === 'started', activityRef);
            }
            return;
        }

        // Image Agent：图像生成附件任务活动
        if (d.type === 'attachment_agent_status') {
            var attachmentRef = String(d.client_message_id || d.attachment_id || 'attachment');
            if (d.status === 'started') setAgentActive('image', true, attachmentRef);
            if (d.status === 'completed' || d.status === 'failed') setAgentActive('image', false, attachmentRef);
            return;
        }

        if (d.type !== 'team_event') return;
        var key = agentKeyOf(d);

        if ((d.event === 'agent.turn.started' || d.event === 'agent.started') && key) {
            var runId = d.run_id || 'unknown';
            var payload = d.payload || {};
            var refId = d.event === 'agent.started'
                ? runId + ':' + key + ':lifecycle'
                : runId + ':' + key + ':turn:' + String(payload.turn_id || d.task_id || d.seq || 'current');
            referencesFor(key).add(refId);
            refreshAgentScreen(key);
            var dispatchId = runId + ':' + key + ':' + String(d.task_id || payload.task_id || payload.instruction || 'agent');
            // agent.turn.started 是同一 Agent 内部模型轮次，不是新的派单；只在生命周期开始时走一次。
            if (d.event === 'agent.started' && key !== 'moonya' && !animatedEvents[dispatchId] && officeVisible()) {
                animatedEvents[dispatchId] = true;
                var text = pendingTaskText ||
                    (d.payload && (d.payload.task || d.payload.title || d.payload.summary)) ||
                    '新任务已送达，请开始处理';
                pendingTaskText = '';
                var chatTitle = '';
                try {
                    var eventConversationId = d.conversation_id || d.conversationId || null;
                    var activeChat = getChatHistory().find(function (chat) {
                        return eventConversationId
                            ? Number(chat.dbConversationId) === Number(eventConversationId)
                            : chat.id === currentChatId;
                    });
                    chatTitle = activeChat && activeChat.title ? activeChat.title : '';
                } catch (_) {}
                text = (chatTitle ? chatTitle + ' · ' : '') + text;
                if (String(text).length > 56) text = String(text).slice(0, 56) + '…';
                queueDispatch(key, text);
            }
        }
        if ((d.event === 'agent.turn.completed' || d.event === 'agent.completed' || d.event === 'agent.failed') && key) {
            var completionRun = String(d.run_id || 'unknown');
            var refs = referencesFor(key);
            if (d.event === 'agent.turn.completed') {
                var completedTurn = d.payload && d.payload.turn_id;
                if (completedTurn) {
                    refs.delete(completionRun + ':' + key + ':turn:' + String(completedTurn));
                } else {
                    Array.from(refs).forEach(function (ref) {
                        if (ref.indexOf(completionRun + ':' + key + ':turn:') === 0) refs.delete(ref);
                    });
                }
            } else {
                refs.delete(completionRun + ':' + key + ':lifecycle');
                Array.from(refs).forEach(function (ref) {
                    if (ref.indexOf(completionRun + ':' + key + ':turn:') === 0) refs.delete(ref);
                });
            }
            refreshAgentScreen(key);
        }
        if (d.event === 'run.completed' || d.event === 'run.failed' || d.event === 'run.cancelled') {
            clearRunScreens(d.run_id || 'unknown');
        }
    }

    // ---------- 只读状态恢复 / 独立窗口轮询 ----------
    function fetchOfficeStatus() {
        if (!authenticated) return Promise.resolve();
        fetch('/api/team.php?action=office_status', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success || !res.data) return;
                var list = res.data.active_agents || [];
                var activeRunAgents = new Set();
                (res.data.active_runs || []).forEach(function (run) {
                    var runId = String(run.run_id || '');
                    if (!runId) return;
                    (run.active_agents || []).forEach(function (agentKey) {
                        activeRunAgents.add(runId + ':' + String(agentKey));
                    });
                });
                Object.keys(stations).forEach(function (k) {
                    var refs = referencesFor(k);
                    // 轮询结果是每个运行内 Agent 在岗状态的权威兜底。run_id 既可能是
                    // 数字也可能是 UUID；不能再按格式猜测，否则 Code/Computer 的旧引用
                    // 会在同一运行的其他 Agent 继续工作时残留，造成空工位永久亮屏。
                    Array.from(refs).forEach(function (ref) {
                        if (ref === 'server:active' || ref.indexOf('legacy:') === 0) return;
                        var runRef = runIdFromAgentReference(ref, k);
                        if (runRef && !activeRunAgents.has(runRef + ':' + k)) {
                            refs.delete(ref);
                        }
                    });
                    if (list.indexOf(k) !== -1) refs.add('server:active');
                    else refs.delete('server:active');
                    refreshAgentScreen(k);
                });
            })
            .catch(function () { /* 静默 */ });
    }

    // ---------- Voice Agent 前端信号（仅主窗口） ----------
    function hookVoiceSignals() {
        // 1) TTS 生命周期钩子：script-4 会覆写 window.__onTtsStart/__onTtsEnd，
        //    这里定时检测引用变化并链式包装（保存原引用再调用，互不影响）
        var wrappedStart = null;
        var wrappedEnd = null;
        function wrap() {
            if (window.__onTtsStart !== wrappedStart) {
                var origStart = window.__onTtsStart;
                window.__onTtsStart = function () {
                    emitOfficeActivity('voice', 'started', 'tts');
                    if (typeof origStart === 'function') return origStart.apply(this, arguments);
                };
                wrappedStart = window.__onTtsStart;
            }
            if (window.__onTtsEnd !== wrappedEnd) {
                var origEnd = window.__onTtsEnd;
                window.__onTtsEnd = function () {
                    emitOfficeActivity('voice', 'completed', 'tts');
                    if (typeof origEnd === 'function') return origEnd.apply(this, arguments);
                };
                wrappedEnd = window.__onTtsEnd;
            }
        }
        wrap();
        setInterval(wrap, 600);

        // 2) PTT 录音：主输入框会被切换 ptt-recording class
        var mainInput = document.querySelector('.input-container-wrapper .message-input');
        if (mainInput && typeof MutationObserver !== 'undefined') {
            new MutationObserver(function () {
                emitOfficeActivity(
                    'voice',
                    mainInput.classList.contains('ptt-recording') ? 'started' : 'completed',
                    'ptt'
                );
            }).observe(mainInput, { attributes: true, attributeFilter: ['class'] });
        }
    }

    // ---------- 视图切换 ----------
    function openOffice() {
        document.body.classList.add('office-active');
        layoutOffice();
        if (authenticated) fetchOfficeStatus(); // 登录后恢复真实状态；访客只显示静态场景
    }

    function closeOffice() {
        hideAgentCard();
        document.body.classList.remove('office-active');
    }

    function bindEvents() {
        Array.prototype.forEach.call(stage.querySelectorAll('.ws-character-trigger'), function (trigger) {
            trigger.addEventListener('click', function () {
                showAgentCard(trigger);
            });
        });
        if (agentCard) {
            var closeButton = agentCard.querySelector('.office-agent-card-close');
            if (closeButton) {
                closeButton.addEventListener('click', function () {
                    hideAgentCard({ restoreFocus: true });
                });
            }
        }
        document.addEventListener('pointerdown', function (event) {
            if (!agentCard || agentCard.hidden) return;
            if (agentCard.contains(event.target) || event.target.closest('.ws-character-trigger')) return;
            hideAgentCard();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && agentCard && !agentCard.hidden) {
                event.preventDefault();
                hideAgentCard({ restoreFocus: true });
            }
        });

        if (popoutBtn) {
            popoutBtn.addEventListener('click', function () {
                window.open('/office/index.php', '_blank');
            });
        }

        // 点击其他导航（最近对话/新建会话等）时自动切回对话视图
        ['conversationBtn', 'recentChatBtn', 'sidebarNewChatBtn', 'newChatTopBtn'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('click', closeOffice);
        });

        window.addEventListener('resize', function () {
            if (officeVisible()) {
                layoutOffice();
                if (activeProfileTrigger) positionAgentCard(activeProfileTrigger);
            }
        });
    }

    // ---------- 初始化 ----------
    window.addEventListener('moonya:team-event', onTeamEvent);
    window.addEventListener('moonya:office-event', function (event) {
        var shared = event.detail || {};
        if (shared.event) {
            onTeamEvent({
                detail: Object.assign({}, shared.event, {
                    conversation_id: shared.conversationId || shared.event.conversation_id || null
                })
            });
        }
    });
    hookVoiceSignals();
    if (officeVisible()) layoutOffice();
    if (authenticated) {
        fetchOfficeStatus();
        statusPollTimer = setInterval(fetchOfficeStatus, 3000);
    }
    bindEvents();

    function setAuthenticated(value) {
        authenticated = !!value;
        if (document.body) document.body.dataset.authenticated = authenticated ? '1' : '0';
        if (authenticated) {
            fetchOfficeStatus();
            if (!statusPollTimer) statusPollTimer = setInterval(fetchOfficeStatus, 3000);
            return;
        }
        if (statusPollTimer) clearInterval(statusPollTimer);
        statusPollTimer = null;
        Object.keys(stations).forEach(function (key) {
            activeAgents[key] = new Set();
            refreshAgentScreen(key);
        });
    }

    // 对外暴露（sidebar 的 officeBtn 点击调用）
    window.MoonYaOffice = {
        open: openOffice,
        close: closeOffice,
        setAuthenticated: setAuthenticated,
        setAgentActive: setAgentActive,
        relayout: layoutOffice
    };
    window.closeOfficeMode = closeOffice;
})();
</script>
