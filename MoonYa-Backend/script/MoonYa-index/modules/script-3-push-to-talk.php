<?php
/**
 * Push-to-Talk 语音交互模块
 *
 * 按住 Ctrl+空格键说话 → 松开填入输入框 → 自动发送
 *
 * 识别统一走后端 /api/asr.php（阿里云 Fun-ASR），桌面端不再使用本地 SAPI。
 *
 * 依赖：
 *   - window.sendMessage()         [script-1e-rest.php]
 *   - window.speakText()           [script-1c-save.php]
 *   - window.stopVoiceBroadcast()  [script-1c-save.php]
 *   - showToast()                  [script-1b-features.php]
 *   - isCefSharp                   [script-1d-dom.php]
 *   - messageInput                 [全局变量]
 *   - /api/asr.php                 [后端 ASR 接口]
 */
?>
<script>
console.log('[PTT] 脚本已加载');
(function() {
    'use strict';

    // ════════════════════════════════════════════════════════════
    //  常量与状态
    // ════════════════════════════════════════════════════════════

    const PTT_STATE = {
        IDLE: 'idle',
        RECORDING: 'recording',
        CONFIRMING: 'confirming'
    };

    const PTT_MODE = {
        HOLD: 'hold',
        TOGGLE: 'toggle'
    };

    // 防误触阈值（毫秒）
    const MIN_RECORD_MS = 300;
    // 录音超时（毫秒）
    const MAX_RECORD_MS = 15000;

    // localStorage 键名
    const LS_KEY_MODE = 'moonya_ptt_mode';
    const LS_KEY_ENABLED = 'moonya_ptt_enabled';

    function __dbgLog(hypothesisId, location, msg, data) {
        // Optional development instrumentation is disabled in production.
    }
    window.__dbgLog = __dbgLog;

    // ════════════════════════════════════════════════════════════
    //  内部状态
    // ════════════════════════════════════════════════════════════

    let state = PTT_STATE.IDLE;
    let mode = PTT_MODE.HOLD;
    let enabled = false;
    let asrAvailable = false;

    let mediaRecorder = null;
    let audioChunks = [];
    let audioStream = null;
    let recordStartTime = 0;
    let recordTimer = null;
    let recordTimeout = null;

    // ★ 防重入标志：startRecording 异步流程未完成时，重复触发一律忽略。
    //   配合 C# hook 吞掉 Space 自动重复 + cancelCsharpPtt 修复"长按闪屏"。
    let startRecordingInFlight = false;

    // ★ ASR 错误冷却期：ASR 启动失败后 3 秒内，__onPttTrigger / startRecording / onKeyDown
    //   全部直接拒绝，避免 OS 键盘自动重复导致"屏幕一直闪"。
    let pttCooldownUntil = 0;
    const PTT_COOLDOWN_MS = 3000;

    let isIMEActive = false;
    // 输入框焦点状态：聚焦时 Ctrl+Space 交给输入法切换，不触发 PTT
    let _inputFocused = false;
    let audioCtx = null;

    // Web Speech API 降级
    let fallbackRecognition = null;
    let fallbackText = '';

    // 当前一次录音的 Promise 控制器（用于在 stop 时取回识别文本）
    let captureResolve = null;
    let captureReject = null;

    // C# 长按触发后，识别完成自动发送
    let autoSendOnResult = false;
    // ★ 强化松开即发送：pendingSend 替代 autoSendOnResult，与 mode 解耦
    let pendingSend = false;

    // ★ 会话 ID 机制：每次按下 Ctrl+Space 递增，防止旧周期 ASR 回调干扰新周期
    let pttSessionId = 0;
    // ★ 松开时的文本快照（asrFinalText + 输入框值），用于 6 秒兜底发送
    let releaseSnapshot = null;


    // ════════════════════════════════════════════════════════════
    //  DOM 引用（延迟获取）
    // ════════════════════════════════════════════════════════════

    function getMessageInput() {
        return document.querySelector('.message-input');
    }
    function getTimerEl() {
        return document.getElementById('pttTimer');
    }
    function getPttInputGlow() {
        return document.getElementById('ptt-input-glow');
    }


    // ════════════════════════════════════════════════════════════
    //  状态管理
    // ════════════════════════════════════════════════════════════

    function getState() {
        return state;
    }

    function setState(newState) {
        if (state === newState) return;
        const oldState = state;
        state = newState;
        updateUI();
        console.log('[PTT] 状态切换:', oldState, '→', newState);

        // C# 长按模式下，识别完成自动发送消息
        if (newState === PTT_STATE.CONFIRMING && autoSendOnResult && mode === PTT_MODE.HOLD) {
            autoSendOnResult = false;
            console.log('[PTT] 识别完成，自动发送消息');
            setTimeout(() => {
                const input = getMessageInput();
                if (input && input.value.trim()) {
                    onConfirmSend();
                } else {
                    console.warn('[PTT] 自动发送失败：输入框为空');
                }
            }, 50);
        }
    }

    function switchMode(newMode) {
        if (newMode !== PTT_MODE.HOLD && newMode !== PTT_MODE.TOGGLE) return;
        mode = newMode;
        try { localStorage.setItem(LS_KEY_MODE, newMode); } catch(e) {}
        console.log('[PTT] 模式切换为:', newMode);
    }

    // ════════════════════════════════════════════════════════════
    //  UI 更新
    // ════════════════════════════════════════════════════════════

    function updateUI() {
        // 输入框占位符随状态变化
        const input = getMessageInput();
        const glow = getPttInputGlow();
        if (input) {
            if (state === PTT_STATE.RECORDING) {
                input.setAttribute('data-ptt-placeholder', input.placeholder);
                input.placeholder = '';
                input.classList.add('ptt-recording');
            } else if (state === PTT_STATE.CONFIRMING) {
                input.placeholder = '识别完成，按 Enter 发送 / Esc 取消';
                input.classList.remove('ptt-recording');
            } else {
                input.classList.remove('ptt-recording');
                const saved = input.getAttribute('data-ptt-placeholder');
                if (saved) {
                    input.placeholder = saved;
                    input.removeAttribute('data-ptt-placeholder');
                } else {
                    input.placeholder = '输入问题或Ctrl+Space 开启语音输入';
                }
            }
        }
        if (glow) {
            if (state === PTT_STATE.RECORDING) {
                glow.classList.add('visible');
            } else {
                glow.classList.remove('visible');
            }
        }
    }

    function setEnabled(on) {
        enabled = on;
        try { localStorage.setItem(LS_KEY_ENABLED, on ? '1' : '0'); } catch(e) {}
        if (enabled) {
            document.body.classList.add('ptt-enabled');
        } else {
            document.body.classList.remove('ptt-enabled');
            // 如果正在录音，强制停止
            if (state === PTT_STATE.RECORDING) {
                forceStopRecording();
            }
        }
    }

    // ════════════════════════════════════════════════════════════
    //  AudioContext 提示音
    // ════════════════════════════════════════════════════════════

    function ensureAudioCtx() {
        if (audioCtx) return audioCtx;
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return null;
            audioCtx = new AC();
        } catch(e) {
            audioCtx = null;
        }
        return audioCtx;
    }

    function playStartTone() {
        const ctx = ensureAudioCtx();
        if (!ctx) return;
        // 异步 resume 后再播放，确保 CefSharp 首次播放不丢失
        const play = () => {
            try {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.setValueAtTime(880, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(1200, ctx.currentTime + 0.1);
                gain.gain.setValueAtTime(0.3, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.4);
            } catch(e) {
                console.warn('[PTT] 播放开始提示音失败:', e);
            }
        };
        try {
            if (ctx.state === 'suspended') {
                ctx.resume().then(play).catch(() => play());
            } else {
                play();
            }
        } catch(e) {
            console.warn('[PTT] playStartTone 异常:', e);
            try { play(); } catch(_e) {}
        }
    }

    function playEndTone() {
        const ctx = ensureAudioCtx();
        if (!ctx) return;
        try {
            if (ctx.state === 'suspended') ctx.resume();
            // 音符1: 660Hz
            const osc1 = ctx.createOscillator();
            const gain1 = ctx.createGain();
            osc1.connect(gain1);
            gain1.connect(ctx.destination);
            osc1.frequency.setValueAtTime(660, ctx.currentTime);
            gain1.gain.setValueAtTime(0.3, ctx.currentTime);
            gain1.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
            osc1.start(ctx.currentTime);
            osc1.stop(ctx.currentTime + 0.3);
            // 音符2: 880Hz（延迟 150ms）
            const osc2 = ctx.createOscillator();
            const gain2 = ctx.createGain();
            osc2.connect(gain2);
            gain2.connect(ctx.destination);
            osc2.frequency.setValueAtTime(880, ctx.currentTime + 0.15);
            gain2.gain.setValueAtTime(0.3, ctx.currentTime + 0.15);
            gain2.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
            osc2.start(ctx.currentTime + 0.15);
            osc2.stop(ctx.currentTime + 0.45);
        } catch(e) {
            console.warn('[PTT] 播放结束提示音失败:', e);
        }
    }

    function playCancelTone() {
        const ctx = ensureAudioCtx();
        if (!ctx) return;
        try {
            if (ctx.state === 'suspended') ctx.resume();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.setValueAtTime(200, ctx.currentTime);
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.15);
        } catch(e) {
            console.warn('[PTT] 播放取消提示音失败:', e);
        }
    }

    // ════════════════════════════════════════════════════════════
    //  TTS 打断与恢复
    // ════════════════════════════════════════════════════════════

    function interruptTTS() {
        try {
            if (window.speechSynthesis && window.speechSynthesis.speaking) {
                window.speechSynthesis.pause();
            }
        } catch(e) {}
        try {
            if (window.currentAudio && typeof window.currentAudio.pause === 'function') {
                window.currentAudio.pause();
            }
        } catch(e) {}
    }

    function resumeTTS() {
        try {
            if (window.speechSynthesis && window.speechSynthesis.paused) {
                window.speechSynthesis.resume();
            }
        } catch(e) {}
        // currentAudio 不主动恢复，避免与新消息播报冲突
    }

    // ════════════════════════════════════════════════════════════
    //  音频格式转换：浏览器录制结果 → 16kHz 单声道 WAV
    //  阿里云 Fun-ASR 录音文件识别 HTTP API 推荐 wav/mp3 等格式，
    //  MediaRecorder 默认的 webm/opus 不在官方支持列表，需要转码。
    // ════════════════════════════════════════════════════════════

    function writeString(view, offset, string) {
        for (let i = 0; i < string.length; i++) {
            view.setUint8(offset + i, string.charCodeAt(i));
        }
    }

    function encodeWAV(audioBuffer) {
        const numChannels = audioBuffer.numberOfChannels;
        const sampleRate = audioBuffer.sampleRate;
        const format = 1; // PCM
        const bitDepth = 16;
        const bytesPerSample = bitDepth / 8;
        const blockAlign = numChannels * bytesPerSample;
        const byteRate = sampleRate * blockAlign;
        const dataLength = audioBuffer.length * blockAlign;
        const buffer = new ArrayBuffer(44 + dataLength);
        const view = new DataView(buffer);

        writeString(view, 0, 'RIFF');
        view.setUint32(4, 36 + dataLength, true);
        writeString(view, 8, 'WAVE');
        writeString(view, 12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, format, true);
        view.setUint16(22, numChannels, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, byteRate, true);
        view.setUint16(32, blockAlign, true);
        view.setUint16(34, bitDepth, true);
        writeString(view, 36, 'data');
        view.setUint32(40, dataLength, true);

        const offset = 44;
        const channels = [];
        for (let i = 0; i < numChannels; i++) {
            channels.push(audioBuffer.getChannelData(i));
        }
        for (let i = 0; i < audioBuffer.length; i++) {
            for (let c = 0; c < numChannels; c++) {
                let sample = Math.max(-1, Math.min(1, channels[c][i]));
                sample = sample < 0 ? sample * 0x8000 : sample * 0x7FFF;
                view.setInt16(offset + (i * blockAlign) + (c * bytesPerSample), sample, true);
            }
        }
        return new Blob([view], { type: 'audio/wav' });
    }

    async function blobToWav(blob, targetSampleRate) {
        targetSampleRate = targetSampleRate || 16000;
        const arrayBuffer = await blob.arrayBuffer();
        const ctx = ensureAudioCtx();
        if (!ctx) throw new Error('AudioContext 不可用');

        const audioBuffer = await new Promise((resolve, reject) => {
            ctx.decodeAudioData(arrayBuffer, resolve, reject);
        });

        // 已经是目标格式，直接编码
        if (audioBuffer.numberOfChannels === 1 && audioBuffer.sampleRate === targetSampleRate) {
            return encodeWAV(audioBuffer);
        }

        // 重采样为单声道 16kHz
        const offlineCtx = new (window.OfflineAudioContext || window.webkitOfflineAudioContext)(
            1,
            Math.ceil(audioBuffer.duration * targetSampleRate),
            targetSampleRate
        );
        const source = offlineCtx.createBufferSource();
        source.buffer = audioBuffer;
        source.connect(offlineCtx.destination);
        source.start();
        const resampled = await offlineCtx.startRendering();
        return encodeWAV(resampled);
    }

    // ════════════════════════════════════════════════════════════
    //  流式实时识别（CefSharp 环境，通过 C# WebSocket 连接阿里云）
    //  按住 Ctrl+空格 → 边说边识别 → 松开自动发送
    // ════════════════════════════════════════════════════════════

    let streamingCtx = null;
    let streamingStream = null;
    let streamingProcessor = null;
    let streamingSource = null;
    let streamingActive = false;
    let asrReady = false;
    let asrFinalText = '';
    let asrWaitingFinish = false;
    // ★ ASR Bridge 绑定状态：CefSharp.BindObjectAsync 完成前为 false
    let asrBridgeReady = false;

    // 音量检测（用于驱动屏幕边缘光效）
    let pttVolumeAnalyser = null;
    let pttVolumeSource = null;
    let pttVolumeContext = null;
    let pttVolumeRafId = null;
    let lastReportedVolume = -1;

    function reportVolume(level) {
        if (typeof window.moonYaPttGlow !== 'undefined' && window.moonYaPttGlow !== null && window.moonYaPttGlow.updateVolume) {
            // 微小死区，避免无声音时频繁抖动
            if (Math.abs(level - lastReportedVolume) > 0.02) {
                lastReportedVolume = level;
                try { window.moonYaPttGlow.updateVolume(level); } catch(e) {}
            }
        }
        // 同步驱动输入区流光指示器
        const glow = getPttInputGlow();
        if (glow) {
            const scale = 0.92 + level * 0.52;    // [0.92, 1.44]
            const opacity = 0.56 + level * 0.40;  // [0.56, 0.96]
            glow.style.setProperty('--ptt-orb-scale', scale.toFixed(3));
            glow.style.setProperty('--ptt-orb-opacity', opacity.toFixed(3));
        }
    }

    function calculateRmsLevel(input) {
        let sum = 0;
        for (let i = 0; i < input.length; i++) {
            sum += input[i] * input[i];
        }
        const rms = Math.sqrt(sum / input.length);
        // 对数映射到 [0, 1]，-50dB 为底，-10dB 为顶
        const db = 20 * Math.log10(Math.max(rms, 1e-10));
        return Math.min(1, Math.max(0, (db + 50) / 40));
    }

    function cleanupVolumeAnalyser() {
        if (pttVolumeRafId) {
            cancelAnimationFrame(pttVolumeRafId);
            pttVolumeRafId = null;
        }
        try {
            if (pttVolumeSource) pttVolumeSource.disconnect();
        } catch(e) {}
        try {
            if (pttVolumeAnalyser) pttVolumeAnalyser.disconnect();
        } catch(e) {}
        try {
            if (pttVolumeContext && pttVolumeContext.state !== 'closed') pttVolumeContext.close();
        } catch(e) {}
        pttVolumeSource = null;
        pttVolumeAnalyser = null;
        pttVolumeContext = null;
        lastReportedVolume = -1;

        // 重置输入区流光指示器为默认状态
        const glow = getPttInputGlow();
        if (glow) {
            glow.style.setProperty('--ptt-orb-scale', '1');
            glow.style.setProperty('--ptt-orb-opacity', '0.85');
            if (state !== PTT_STATE.RECORDING) {
                glow.classList.remove('visible');
            }
        }
    }

    function readAnalyserVolume() {
        if (!pttVolumeAnalyser || state !== PTT_STATE.RECORDING) return;
        const data = new Uint8Array(pttVolumeAnalyser.frequencyBinCount);
        pttVolumeAnalyser.getByteFrequencyData(data);
        let sum = 0;
        for (let i = 0; i < data.length; i++) sum += data[i];
        const avg = sum / data.length / 255;
        reportVolume(avg);
        pttVolumeRafId = requestAnimationFrame(readAnalyserVolume);
    }

    // ★ 等待 ASR Bridge 绑定完成（首次按键时可能还没绑定完）
    function waitForAsrBridge(timeoutMs) {
        timeoutMs = timeoutMs || 3000;
        return new Promise(function(resolve) {
            if (asrBridgeReady) { resolve(true); return; }
            const start = Date.now();
            const timer = setInterval(function() {
                if (asrBridgeReady) {
                    clearInterval(timer);
                    resolve(true);
                } else if (Date.now() - start > timeoutMs) {
                    clearInterval(timer);
                    console.warn('[PTT] ASR Bridge 绑定超时');
                    resolve(false);
                }
            }, 50);
        });
    }

    // Float32 → Int16 PCM → base64
    function floatToPcmBase64(float32Array) {
        const pcm = new Int16Array(float32Array.length);
        for (let i = 0; i < float32Array.length; i++) {
            let s = Math.max(-1, Math.min(1, float32Array[i]));
            pcm[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
        }
        const bytes = new Uint8Array(pcm.buffer);
        let binary = '';
        for (let i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
    }

    // 降采样到 16kHz
    function downsampleTo16k(float32Array, fromRate) {
        if (fromRate === 16000) return float32Array;
        const ratio = fromRate / 16000;
        const newLength = Math.round(float32Array.length / ratio);
        const result = new Float32Array(newLength);
        for (let i = 0; i < newLength; i++) {
            const srcIdx = Math.floor(i * ratio);
            result[i] = float32Array[srcIdx];
        }
        return result;
    }

    // ★ 强制释放 C# 端 PTT 状态（停止轮询、隐藏光效）。
    //   修复：长按 Ctrl+空格 + ASR 启动失败时，OS 键盘自动重复的 keydown 会反复触发
    //   startRecording()，造成"屏幕一直闪"。本函数在前端主动放弃 PTT 时通知 C# 复位。
    function cancelCsharpPtt() {
        try {
            if (window.moonYaPttGlow && typeof window.moonYaPttGlow.cancelPtt === 'function') {
                window.moonYaPttGlow.cancelPtt();
            }
        } catch(e) { /* ignore */ }
    }

    // ★ 让 C# 端 hook 进入冷却期（3 秒内吞掉 Space keydown）。
    //   配合 cancelCsharpPtt() 一起使用，是修复"ASR 失败 + 长按 Ctrl+空格闪屏"的关键。
    //   前后端双重冷却：前端在 onKeyDown / __onPttTrigger 中也会做同样判断。
    function enterCsharpCooldown() {
        try {
            if (window.moonYaPttGlow && typeof window.moonYaPttGlow.enterCooldown === 'function') {
                window.moonYaPttGlow.enterCooldown();
            }
        } catch(e) { /* ignore */ }
    }

    // ★ ASR 启动成功时调用，让 C# 端 hook 清除冷却期恢复正常响应。
    function clearCsharpCooldown() {
        try {
            if (window.moonYaPttGlow && typeof window.moonYaPttGlow.clearCooldown === 'function') {
                window.moonYaPttGlow.clearCooldown();
            }
        } catch(e) { /* ignore */ }
    }

    // ★ 统一的"进入 PTT 冷却期"辅助：前后端双重冷却。
    //   用于 ASR 启动失败 / Bridge 不可用 / 流程异常等所有需要阻断 PTT 触发的场景。
    function enterPttCooldownAndCleanup() {
        pttCooldownUntil = Date.now() + PTT_COOLDOWN_MS;
        startRecordingInFlight = false;
        cancelCsharpPtt();
        enterCsharpCooldown();
    }

    async function startStreamingRecording() {
        // ★ 捕获当前会话 ID，用于异步完成时竞态保护
        const session = pttSessionId;
        asrReady = false;
        asrFinalText = '';
        asrWaitingFinish = false;

        // 1. 启动 C# WebSocket ASR
        console.log('[PTT] 启动 C# ASR WebSocket...');
        // #region debug-point B:start-streaming
        __dbgLog('B', 'script-3-push-to-talk.php:startStreamingRecording', 'Starting streaming ASR', { session: session, state: state });
        // #endregion
        const err = await window.moonYaAsr.start();
        // #region debug-point B:asr-start-result
        __dbgLog('B', 'script-3-push-to-talk.php:startStreamingRecording', 'ASR start returned', { session: session, err: err, state: state });
        // #endregion
        if (err) {
            throw new Error('ASR 启动失败: ' + err);
        }

        // 2. 获取麦克风
        streamingStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                channelCount: 1,
                echoCancellation: true,
                noiseSuppression: true
            }
        });

        // 3. 创建 AudioContext（尝试 16kHz）
        try {
            streamingCtx = new AudioContext({ sampleRate: 16000 });
        } catch(e) {
            streamingCtx = new AudioContext();
        }
        const actualRate = streamingCtx.sampleRate;
        console.log('[PTT] AudioContext 采样率:', actualRate);

        streamingSource = streamingCtx.createMediaStreamSource(streamingStream);
        streamingProcessor = streamingCtx.createScriptProcessor(4096, 1, 1);

        streamingProcessor.onaudioprocess = function(e) {
            if (!streamingActive || !asrReady) return;
            const input = e.inputBuffer.getChannelData(0);
            const ds = downsampleTo16k(input, actualRate);
            const base64 = floatToPcmBase64(ds);
            try { window.moonYaAsr.sendAudio(base64); } catch(ex) {}
            // 同时向 C# 光效层上报音量
            reportVolume(calculateRmsLevel(input));
        };

        streamingSource.connect(streamingProcessor);
        streamingProcessor.connect(streamingCtx.destination);

        // 4. 等待 ASR ready（最多 5 秒）
        for (let i = 0; i < 50 && !asrReady; i++) {
            await new Promise(r => setTimeout(r, 100));
        }
        if (!asrReady) {
            throw new Error('ASR 连接超时');
        }

        // 5. 开始录音
        // ★ 会话 ID 竞态保护：若期间用户已松开按键（pttSessionId 递增），不启动录音
        if (session !== pttSessionId) {
            console.warn('[PTT] 会话已变更（松开/重按），放弃启动录音');
            // #region debug-point B:session-changed
            __dbgLog('B', 'script-3-push-to-talk.php:startStreamingRecording', 'Session changed, aborting start', { session: session, pttSessionId: pttSessionId });
            // #endregion
            cleanupStreaming();
            // ★ 早返回：START 阶段未真正启动，需清除防重入标志
            startRecordingInFlight = false;
            return;
        }
        streamingActive = true;
        console.log('[PTT] 流式录音已启动, session=' + session);
        // #region debug-point B:streaming-active
        __dbgLog('B', 'script-3-push-to-talk.php:startStreamingRecording', 'Streaming recording active', { session: session, streamingActive: streamingActive, state: state });
        // #endregion
        // ★ START 阶段完成，清除防重入标志（后续由 state 守卫拦截重复触发）
        startRecordingInFlight = false;
    }

    async function stopStreamingRecording() {
        streamingActive = false;

        // 断开音频处理
        try {
            if (streamingProcessor) streamingProcessor.disconnect();
            if (streamingSource) streamingSource.disconnect();
        } catch(e) {}

        // 清理音量检测
        cleanupVolumeAnalyser();

        // 停止麦克风
        try {
            if (streamingStream) streamingStream.getTracks().forEach(t => t.stop());
        } catch(e) {}
        streamingStream = null;

        // 关闭 AudioContext
        try {
            if (streamingCtx) streamingCtx.close();
        } catch(e) {}
        streamingCtx = null;
        streamingProcessor = null;
        streamingSource = null;

        // 发送 finish-task，等待最终结果（C# 回调 __onAsrFinished）
        asrWaitingFinish = true;

        // ★ 兜底超时：5 秒内 C# 没回调，强制用输入框当前文本触发 __onAsrFinished
        const fallbackTimer = setTimeout(() => {
            if (asrWaitingFinish) {
                console.warn('[PTT] ASR 5 秒未返回，使用输入框当前文本触发发送');
                const input = getMessageInput();
                const fallbackText = input ? input.value : (asrFinalText || '');
                window.__onAsrFinished(fallbackText);
            }
        }, 5000);

        try {
            await window.moonYaAsr.stop();
            clearTimeout(fallbackTimer);
        } catch(ex) {
            console.error('[PTT] ASR stop 失败:', ex);
            clearTimeout(fallbackTimer);
            if (asrWaitingFinish) {
                asrWaitingFinish = false;
                cleanupStreaming();
                setState(PTT_STATE.IDLE);
                resumeTTS();
            }
        }
    }

    function cleanupStreaming() {
        streamingActive = false;
        try { if (streamingStream) streamingStream.getTracks().forEach(t => t.stop()); } catch(e) {}
        streamingStream = null;
        try { if (streamingCtx) streamingCtx.close(); } catch(e) {}
        streamingCtx = null;
        streamingProcessor = null;
        streamingSource = null;
        cleanupVolumeAnalyser();
    }

    // ★ 流式识别可用性检测：CefSharp 环境且 moonYaAsr 已绑定
    function isStreamingAvailable() {
        return typeof window.moonYaAsr !== 'undefined' &&
               window.moonYaAsr !== null &&
               typeof window.moonYaAsr.start === 'function';
    }

    // ═══ ASR 回调（由 C# ExecuteScriptAsync 调用） ═══

    window.__onAsrReady = function() {
        console.log('[PTT] ASR 会话已建立');
        asrReady = true;
    };

    window.__onAsrResult = function(text, isFinal) {
        console.log('[PTT] ASR 结果:', text, 'isFinal:', isFinal);
        const input = getMessageInput();
        if (input && text) {
            input.value = text;
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 160) + 'px';
        }
        if (isFinal) {
            asrFinalText = text;
        }
    };

    window.__onAsrError = function(message) {
        console.error('[PTT] ASR 错误:', message);
        if (typeof showToast === 'function') {
            showToast('语音识别失败: ' + message);
        }
        asrWaitingFinish = false;
        cleanupStreaming();
        if (state === PTT_STATE.RECORDING) {
            stopTimer();
            if (recordTimeout) { clearTimeout(recordTimeout); recordTimeout = null; }
            setState(PTT_STATE.IDLE);
        }
        // ★ ASR 出现运行时错误，释放防重入标志 + C# 端 PTT 状态 + 进入冷却期
        startRecordingInFlight = false;
        pttCooldownUntil = Date.now() + PTT_COOLDOWN_MS;
        cancelCsharpPtt();
        enterCsharpCooldown();
        resumeTTS();
    };

    // ★ 公共发送函数：供 __onAsrFinished 和 6 秒兜底共用
    // 合并多来源文本，取首个非空者发送
    // ★ async：window.sendMessage 是 async 函数，首次发送时需 await createNewChat()
    //   创建对话。若不 await 就清空输入框，sendMessage 恢复后会读到空消息而放弃发送。
    async function sendPendingMessage(text) {
        if (!pendingSend) {
            console.log('[PTT] sendPendingMessage: pendingSend 已清除，跳过');
            return;
        }
        pendingSend = false;

        // 合并多来源文本：参数 text > asrFinalText > releaseSnapshot.inputValue > 当前输入框值
        let finalText = '';
        if (text && text.trim()) {
            finalText = text.trim();
        } else if (asrFinalText && asrFinalText.trim()) {
            finalText = asrFinalText.trim();
        } else if (releaseSnapshot && releaseSnapshot.inputValue && releaseSnapshot.inputValue.trim()) {
            finalText = releaseSnapshot.inputValue.trim();
        } else {
            const input = getMessageInput();
            const inputVal = input ? (input.value || '').trim() : '';
            if (inputVal) finalText = inputVal;
        }

        if (!finalText) {
            console.log('[PTT] sendPendingMessage: 文本为空，静默忽略');
            cleanupStreaming();
            resumeTTS();
            return;
        }

        console.log('[PTT] sendPendingMessage 发送:', finalText);
        playEndTone();
        resumeTTS();

        if (typeof window.sendMessage === 'function') {
            const input = getMessageInput();
            if (input) {
                input.value = finalText;
                input.style.height = 'auto';
            }
            // ★ 必须 await：sendMessage 是 async，首次发送时会 await createNewChat()
            //   创建对话。若不等待就在下方清空输入框，sendMessage 恢复后读到空消息，
            //   导致第一次 Ctrl+空格识别到的文字发不出去，第二次（对话已存在）才正常。
            try { await window.sendMessage(); } catch(e) { console.error('[PTT] sendMessage 异常:', e); }
            const inputAfter = getMessageInput();
            if (inputAfter) {
                inputAfter.value = '';
                inputAfter.style.height = 'auto';
            }
        }

        cleanupStreaming();
        releaseSnapshot = null;
    }

    window.__onAsrFinished = function(finalText) {
        console.log('[PTT] ASR 完成，最终文本:', finalText, 'pendingSend=' + pendingSend);
        // #region debug-point D:asr-finished
        __dbgLog('D', 'script-3-push-to-talk.php:__onAsrFinished', 'ASR finished callback', { finalText: finalText, pendingSend: pendingSend, state: state, pttSessionId: pttSessionId });
        // #endregion
        asrWaitingFinish = false;

        stopTimer();
        if (recordTimeout) { clearTimeout(recordTimeout); recordTimeout = null; }

        // ★ 清除 6 秒兜底计时器（C# 正常回调到达，无需兜底）
        if (window._pttReleaseTimer) {
            clearTimeout(window._pttReleaseTimer);
            window._pttReleaseTimer = null;
        }

        // ★ 释放流程（pendingSend=true）：委托 sendPendingMessage 合并文本并发送
        if (pendingSend) {
            // #region debug-point D:asr-finished-send-pending
            __dbgLog('D', 'script-3-push-to-talk.php:__onAsrFinished', 'Sending pending message', { finalText: finalText, pttSessionId: pttSessionId });
            // #endregion
            sendPendingMessage(finalText);
            return;
        }

        // ── 非释放流程（toggle 模式、手动点击等） ──

        // ★ 旧周期检测：如果正在录音中，说明新周期已开始，忽略旧回调
        if (state === PTT_STATE.RECORDING) {
            console.log('[PTT] 忽略旧周期的 ASR 回调（新周期已开始）');
            // #region debug-point D:asr-finished-old-cycle
            __dbgLog('D', 'script-3-push-to-talk.php:__onAsrFinished', 'Ignoring old cycle callback', { state: state, pttSessionId: pttSessionId });
            // #endregion
            return;
        }

        // 合并文本：C# 传入 > 前端累计 > 输入框当前值
        let text = ((finalText != null ? finalText : '') || asrFinalText || '').trim();
        if (!text) {
            const input = getMessageInput();
            text = input ? (input.value || '').trim() : '';
        }

        if (!text || text.length < 1) {
            console.log('[PTT] 识别结果为空');
            if (typeof showToast === 'function') showToast('未识别到语音，请重试');
            setState(PTT_STATE.IDLE);
            resumeTTS();
            return;
        }

        const input = getMessageInput();
        if (input) {
            input.value = text;
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 160) + 'px';
        }

        playEndTone();

        // 进入确认状态
        setState(PTT_STATE.CONFIRMING);
        setTimeout(() => {
            if (state === PTT_STATE.CONFIRMING) {
                if (input) {
                    input.focus();
                    try { input.setSelectionRange(input.value.length, input.value.length); } catch(e) {}
                }
            }
        }, 100);
    };

    // ════════════════════════════════════════════════════════════
    //  录音核心：MediaRecorder + 后端 ASR（非 CefSharp 环境的降级方案）
    // ════════════════════════════════════════════════════════════

    function startSpeechCapture() {
        return new Promise(async (resolve, reject) => {
            // 保存控制器：录音停止后通过 captureResolve 返回识别文本
            captureResolve = function(text) {
                captureResolve = null;
                captureReject = null;
                resolve(text || '');
            };
            captureReject = function(err) {
                captureResolve = null;
                captureReject = null;
                reject(err);
            };

            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        sampleRate: 16000,
                        channelCount: 1,
                        echoCancellation: true,
                        noiseSuppression: true
                    }
                });
                audioStream = stream;
                audioChunks = [];

                // 创建独立音量检测节点（不影响 MediaRecorder）
                cleanupVolumeAnalyser();
                try {
                    pttVolumeContext = new AudioContext();
                    pttVolumeSource = pttVolumeContext.createMediaStreamSource(stream);
                    pttVolumeAnalyser = pttVolumeContext.createAnalyser();
                    pttVolumeAnalyser.fftSize = 256;
                    pttVolumeSource.connect(pttVolumeAnalyser);
                    readAnalyserVolume();
                } catch(e) {
                    console.warn('[PTT] 音量检测初始化失败:', e);
                }

                // 选择支持的 mimeType
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

                mediaRecorder = new MediaRecorder(stream, { mimeType: mimeType });
                const format = mimeType.split(';')[0].split('/')[1] || 'webm';

                mediaRecorder.ondataavailable = function(event) {
                    if (event.data && event.data.size > 0) {
                        audioChunks.push(event.data);
                    }
                };

                mediaRecorder.onstop = async function() {
                    // 释放麦克风
                    try {
                        stream.getTracks().forEach(t => t.stop());
                    } catch(e) {}
                    audioStream = null;

                    // 清理音量检测
                    cleanupVolumeAnalyser();

                    // 已经被 stopSpeechCapture 处理过，直接忽略
                    if (!captureResolve) return;

                    const audioBlob = new Blob(audioChunks, { type: mimeType });
                    if (audioBlob.size === 0) {
                        captureResolve('');
                        return;
                    }

                    // 全局使用阿里云 ASR API，后端不可用时直接返回空（不再降级到本地识别）
                    if (!asrAvailable) {
                        console.warn('[PTT] 后端 ASR API 不可用，跳过识别');
                        if (typeof showToast === 'function') {
                            showToast('语音识别服务未配置');
                        }
                        captureResolve('');
                        return;
                    }

                    try {
                        // 直接上传浏览器录制的 webm/ogg 格式（阿里云 Fun-ASR 官方支持 webm/opus）
                        // 前端转码 WAV 容易出错且不必要，故优先使用原始格式
                        const uploadBlob = audioBlob;
                        const uploadFormat = format; // 'webm' 或 'ogg' 等

                        const formData = new FormData();
                        formData.append('audio', uploadBlob, 'recording.' + uploadFormat);
                        formData.append('format', uploadFormat);
                        formData.append('sample_rate', '16000');

                        console.log('[PTT] 上传音频:', uploadFormat, '大小:', uploadBlob.size, 'bytes');

                        const response = await fetch('/api/asr.php', {
                            method: 'POST',
                            body: formData
                        });

                        if (!response.ok) {
                            throw new Error('ASR API 返回 ' + response.status);
                        }

                        const result = await response.json();
                        console.log('[PTT] ASR 结果:', result);

                        if (result.code === 0) {
                            captureResolve(result.text || '');
                        } else {
                            console.warn('[PTT] ASR 后端失败:', result.message);
                            if (typeof showToast === 'function') {
                                showToast('语音识别失败: ' + (result.message || '未知错误'));
                            }
                            captureResolve('');
                        }
                    } catch(err) {
                        console.error('[PTT] 上传音频失败:', err);
                        if (typeof showToast === 'function') {
                            showToast('语音识别上传失败: ' + (err.message || '未知错误'));
                        }
                        captureResolve('');
                    }
                };

                mediaRecorder.onerror = function(event) {
                    try { stream.getTracks().forEach(t => t.stop()); } catch(e) {}
                    audioStream = null;
                    if (captureReject) {
                        captureReject(new Error('MediaRecorder 错误: ' + (event.error || 'unknown')));
                    }
                };

                mediaRecorder.start();
                // 录音已经开始，但 Promise 保持 pending，直到 onstop 完成后再 resolve
            } catch(err) {
                audioStream = null;
                if (captureReject) {
                    if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                        captureReject(new Error('麦克风权限被拒绝'));
                    } else {
                        captureReject(err);
                    }
                }
            }
        });
    }

    function stopSpeechCapture() {
        return new Promise((resolve) => {
            if (!captureResolve) {
                resolve('');
                return;
            }

            // 拦截真正的 resolve，把识别文本同步给本次 stop 调用方
            const originalResolve = captureResolve;
            captureResolve = function(text) {
                captureResolve = null;
                captureReject = null;
                originalResolve(text);
                resolve(text || '');
            };
            captureReject = function() {
                captureResolve = null;
                captureReject = null;
                originalResolve('');
                resolve('');
            };

            // 录音从未真正启动，直接结束
            if (!mediaRecorder) {
                if (audioStream) {
                    try { audioStream.getTracks().forEach(t => t.stop()); } catch(e) {}
                    audioStream = null;
                }
                captureResolve('');
                return;
            }

            // 停止录音
            try {
                if (mediaRecorder.state !== 'inactive') {
                    mediaRecorder.stop();
                } else {
                    // 已经停止：onstop 可能已触发，给 500ms 等待它完成
                    setTimeout(() => {
                        if (captureResolve) {
                            console.warn('[PTT] mediaRecorder 已停止但未返回结果，强制结束');
                            captureResolve('');
                        }
                    }, 500);
                }
            } catch(e) {
                console.warn('[PTT] mediaRecorder.stop() 失败:', e);
                captureResolve('');
            }

            // 兜底：30 秒内无论 onstop 是否触发都返回（与后端轮询最大时间对齐）
            setTimeout(() => {
                if (captureReject) captureReject(new Error('识别等待超时'));
            }, 30000);
        });
    }

    function forceStopRecording() {
        try {
            if (recordTimer) { clearInterval(recordTimer); recordTimer = null; }
            if (recordTimeout) { clearTimeout(recordTimeout); recordTimeout = null; }
            // 流式模式清理
            if (isStreamingAvailable()) {
                cleanupStreaming();
            }
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
            if (audioStream) {
                audioStream.getTracks().forEach(t => t.stop());
            }
            // 清理音量检测
            cleanupVolumeAnalyser();
        } catch(e) {}
        setState(PTT_STATE.IDLE);
    }

    // ════════════════════════════════════════════════════════════
    //  Web Speech API 降级
    // ════════════════════════════════════════════════════════════

    function initFallbackRecognition() {
        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) return false;
        try {
            fallbackRecognition = new SR();
            fallbackRecognition.lang = 'zh-CN';
            fallbackRecognition.continuous = false;
            fallbackRecognition.interimResults = false;

            fallbackRecognition.onresult = function(event) {
                if (event.results && event.results[0]) {
                    fallbackText = event.results[0][0].transcript || '';
                }
            };
            fallbackRecognition.onerror = function() {
                fallbackText = '';
            };
            fallbackRecognition.onend = function() {
                // 结果已在 onresult 中保存
            };
            return true;
        } catch(e) {
            fallbackRecognition = null;
            return false;
        }
    }

    // ════════════════════════════════════════════════════════════
    //  键盘事件处理
    // ════════════════════════════════════════════════════════════

    function isSpaceKey(e) {
        return e.code === 'Space' || e.keyCode === 32 || e.key === ' ';
    }

    function onKeyDown(e) {
        // 防重入（window.onkeydown + document.addEventListener 可能重复）
        if (e._pttHandled) return;
        e._pttHandled = true;

        try {
            // ---- DEBUG: 打印所有 keydown 事件 ----
            console.debug('[PTT:DEBUG] keydown', e.key, e.code, e.keyCode, 'ctrl:', e.ctrlKey, 'shift:', e.shiftKey, 'alt:', e.altKey, 'meta:', e.metaKey);

            if (!enabled) return;

            // ★ 冷却期检查：ASR 错误后 3 秒内，所有 PTT 触发直接吞掉，避免长按闪屏
            if (isSpaceKey(e) && e.ctrlKey && Date.now() < pttCooldownUntil) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                console.debug('[PTT] 冷却期内，忽略 Ctrl+Space');
                return;
            }

            // Escape：仅在 confirming 状态下取消
            if (e.key === 'Escape' || e.keyCode === 27) {
                if (state === PTT_STATE.CONFIRMING) {
                    e.preventDefault();
                    onCancel();
                }
                return;
            }

            // Enter：仅在 confirming 状态下发送
            if (e.key === 'Enter' && !e.shiftKey) {
                if (state === PTT_STATE.CONFIRMING) {
                    const input = getMessageInput();
                    if (input && input.value.trim()) {
                        e.preventDefault();
                        onConfirmSend();
                    }
                }
                return;
            }

            if (!isSpaceKey(e)) return;

            // 需要 Ctrl 组合键（避免与正常空格输入冲突）
            if (!e.ctrlKey) return;

            // 输入框聚焦时，Ctrl+Space 交给系统输入法切换，不触发 PTT
            if (_inputFocused) {
                console.log('[PTT] 输入框聚焦中，Ctrl+Space 交给输入法切换');
                return;
            }

            // 互斥检查：实时语音对话模式开启时禁用 PTT
            if (window.VoiceChat && typeof window.VoiceChat.isActive === 'function' && window.VoiceChat.isActive()) {
                e.preventDefault();
                console.log('[PTT] 语音对话模式已开启，PTT 被禁用');
                if (typeof showToast === 'function') {
                    showToast('语音对话模式已开启，请先关闭');
                }
                return;
            }

            // 仅 idle 状态可开始录音
            if (state === PTT_STATE.IDLE) {
                e.preventDefault();
                startRecording();
            } else if (state === PTT_STATE.RECORDING && mode === PTT_MODE.TOGGLE) {
                // toggle 模式：再按一次空格停止
                e.preventDefault();
                stopRecording();
            }
        } catch(ex) {
            console.warn('[PTT] onKeyDown error:', ex);
        }
    }

    function onKeyUp(e) {
        try {
            // ---- DEBUG: 打印所有 keyup 事件 ----
            console.debug('[PTT:DEBUG] keyup', e.key, e.code, e.keyCode, 'ctrl:', e.ctrlKey);

            // 防重入
            if (e._pttHandled) return;
            e._pttHandled = true;

            if (!enabled) return;
            if (!isSpaceKey(e)) return;
            if (!e.ctrlKey) return;

            // 仅 hold 模式下 keyup 停止录音
            if (mode === PTT_MODE.HOLD && state === PTT_STATE.RECORDING) {
                e.preventDefault();
                stopRecording();
            }
        } catch(ex) {
            console.warn('[PTT] onKeyUp error:', ex);
        }
    }

    // ════════════════════════════════════════════════════════════
    //  录音生命周期
    // ════════════════════════════════════════════════════════════

    async function startRecording() {
        // ★ 防重入：若上一次 startRecording 流程还没走完（通常发生在 ASR 启动失败
        //   且 OS 键盘自动重复持续触发时），直接忽略本次调用，避免反复启动。
        if (startRecordingInFlight) {
            console.warn('[PTT] startRecording 已在执行中，忽略重复触发');
            return;
        }
        startRecordingInFlight = true;

        // ★ 冷却期检查：ASR 错误后 3 秒内，startRecording 直接拒绝 + 通知 C# 端同步冷却
        if (Date.now() < pttCooldownUntil) {
            console.warn('[PTT] startRecording: 冷却期内，忽略');
            startRecordingInFlight = false;
            enterCsharpCooldown();
            return;
        }

        // ★ 清理任何遗留的释放标志和安全兜底
        if (window._pttReleaseTimer) {
            clearTimeout(window._pttReleaseTimer);
            window._pttReleaseTimer = null;
        }

        recordStartTime = Date.now();

        // 重置 ASR 状态标志，确保旧会话不干扰新录音
        asrReady = false;
        asrFinalText = '';
        asrWaitingFinish = false;
        // 安全清理残留的 pendingSend（正常情况下应已清空）
        if (pendingSend) {
            console.warn('[PTT] startRecording 检测到残留 pendingSend，清理');
            pendingSend = false;
            autoSendOnResult = false;
        }

        // 播放开始提示音
        playStartTone();

        // 打断 TTS
        interruptTTS();

        // 切换状态（必须在获取麦克风之前，否则动画会瞬间消失）
        setState(PTT_STATE.RECORDING);
        // #region debug-point B:start-recording-state
        __dbgLog('B', 'script-3-push-to-talk.php:startRecording', 'State set to RECORDING', { state: state, pttSessionId: pttSessionId, isStreaming: typeof CefSharp !== 'undefined' });
        // #endregion

        // 启动计时器
        startTimer();

        // 设置超时
        recordTimeout = setTimeout(() => {
            if (state === PTT_STATE.RECORDING) {
                console.log('[PTT] 录音超时（15 秒）');
                stopRecording();
            }
        }, MAX_RECORD_MS);

        // ═══ 流式模式（CefSharp 环境）：通过 C# WebSocket 实时识别 ═══
        // ★ 先等待 ASR Bridge 绑定完成（首次按键时可能还没绑定完）
        if (typeof CefSharp !== 'undefined' && CefSharp.BindObjectAsync) {
            // ★ 修复：延迟 100ms 再绑定，确保 C# 的 Dispatcher 队列中
            //    OnLoadingStateChanged → Register Bridge 已完成。
            //    init() 中已移除提前绑定，改为在此处按需首次绑定。
            var doBind = function() {
                if (asrBridgeReady) return;
                console.log('[PTT] 按需绑定 ASR Bridge...');
                Promise.all([
                    CefSharp.BindObjectAsync('moonYaAsr'),
                    CefSharp.BindObjectAsync('moonYaPttGlow')
                ]).then(function() {
                    asrBridgeReady = true;
                    console.log('[PTT] ASR Bridge 绑定成功');
                }).catch(function(e) {
                    console.warn('[PTT] ASR Bridge 绑定失败:', e);
                });
            };
            if (!asrBridgeReady) {
                setTimeout(doBind, 100);
            }
            waitForAsrBridge(5000).then(function(ready) {
                // #region debug-point B:asr-bridge-ready
                __dbgLog('B', 'script-3-push-to-talk.php:startRecording', 'ASR bridge ready check', { ready: ready, isStreamingAvailable: isStreamingAvailable(), state: state, pttSessionId: pttSessionId });
                // #endregion
                if (state !== PTT_STATE.RECORDING) return; // 期间状态可能已变
                if (ready && isStreamingAvailable()) {
                    startStreamingRecording().catch(err => {
                        stopTimer();
                        if (recordTimeout) { clearTimeout(recordTimeout); recordTimeout = null; }
                        console.error('[PTT] 流式录音启动失败:', err);
                        cleanupStreaming();
                        const msg = err.message || '';
                        if (msg.indexOf('麦克风权限') !== -1 || msg.indexOf('NotAllowed') !== -1) {
                            showToast('麦克风权限被拒绝，请在系统设置中允许');
                        } else {
                            showToast('语音识别启动失败: ' + msg);
                        }
                        if (state === PTT_STATE.RECORDING) {
                            setState(PTT_STATE.IDLE);
                        }
                        // ★ 通知 C# 端释放 PTT 状态 + 进入冷却期（避免长按时屏幕一直闪 + 防止光效卡住不消失）
                        enterPttCooldownAndCleanup();
                        resumeTTS();
                    });
                } else {
                    console.warn('[PTT] ASR Bridge 不可用，无法启动流式录音');
                    stopTimer();
                    if (recordTimeout) { clearTimeout(recordTimeout); recordTimeout = null; }
                    showToast('语音识别服务未就绪，请稍后重试');
                    if (state === PTT_STATE.RECORDING) {
                        setState(PTT_STATE.IDLE);
                    }
                    // ★ 通知 C# 端释放 PTT 状态 + 进入冷却期
                    enterPttCooldownAndCleanup();
                    resumeTTS();
                }
            }).catch(function(err) {
                console.error('[PTT] startRecording 异步流程异常:', err);
                stopTimer();
                if (recordTimeout) { clearTimeout(recordTimeout); recordTimeout = null; }
                cleanupStreaming();
                if (state === PTT_STATE.RECORDING) {
                    setState(PTT_STATE.IDLE);
                }
                // ★ 通知 C# 端释放 PTT 状态 + 进入冷却期
                enterPttCooldownAndCleanup();
                resumeTTS();
            });
            return;
        }

        // ═══ 旧模式（非 CefSharp 环境）：MediaRecorder + HTTP ASR（阿里云 Fun-ASR）═══
        startSpeechCapture().then(function() {
            // 录音已正常开始，清除防重入标志
            startRecordingInFlight = false;
        }).catch(err => {
            stopTimer();
            if (recordTimeout) { clearTimeout(recordTimeout); recordTimeout = null; }
            console.error('[PTT] 启动录音失败:', err);
            const msg = err.message || '';
            if (msg.indexOf('麦克风权限') !== -1) {
                showToast('麦克风权限被拒绝，请在浏览器设置中允许');
            } else {
                showToast('语音识别启动失败: ' + msg);
            }
            if (state === PTT_STATE.RECORDING) {
                setState(PTT_STATE.IDLE);
            }
            startRecordingInFlight = false;
            resumeTTS();
        });
    }

    async function stopRecording() {
        if (state !== PTT_STATE.RECORDING) return;

        const duration = Date.now() - recordStartTime;
        stopTimer();
        if (recordTimeout) { clearTimeout(recordTimeout); recordTimeout = null; }

        // 误触防护：录音时长 < 300ms 静默忽略
        if (duration < MIN_RECORD_MS) {
            console.log('[PTT] 误触忽略（' + duration + 'ms < ' + MIN_RECORD_MS + 'ms）');
            if (isStreamingAvailable()) {
                cleanupStreaming();
                try { window.moonYaAsr.stop(); } catch(e) {}
                setState(PTT_STATE.IDLE);
            } else {
                await stopSpeechCapture().catch(() => {});
                forceStopRecording();
            }
            return;
        }

        // ═══ 流式模式：停止录音，等待 C# 回传最终结果 ═══
        if (isStreamingAvailable()) {
            try {
                // 直接改占位符提示'识别中'，但不改变状态（保持 RECORDING），
                // 让 __onAsrFinished 回调来做最终的 setState(IDLE) 和 updateUI()
                const input = getMessageInput();
                if (input) input.placeholder = '识别中...';
                await stopStreamingRecording();
                // 最终结果在 __onAsrFinished 回调中处理；pendingSend 仍为 true，识别完成后自动发送
            } catch(err) {
                console.error('[PTT] 停止流式录音失败:', err);
                cleanupStreaming();
                setState(PTT_STATE.IDLE);
                resumeTTS();
            }
            return;
        }

        // ═══ 旧模式（MediaRecorder + HTTP ASR）═══
        try {
            // 直接改占位符提示'识别中'，不改状态，让 handleRecognitionResult 处理最终状态
            const input = getMessageInput();
            if (input) input.placeholder = '识别中...';
            const text = await stopSpeechCapture();
            handleRecognitionResult(text);
        } catch(err) {
            console.error('[PTT] 停止录音失败:', err);
            setState(PTT_STATE.IDLE);
            resumeTTS();
        }
    }

    function handleRecognitionResult(text) {
        text = (text || '').trim();

        // 检测有效语音：少于 2 个字视为无效
        if (!text || text.length < 2) {
            if (text === '') {
                // 静默忽略空结果（可能是误触或噪音）
                console.log('[PTT] 识别结果为空');
            } else {
                showToast('未识别到有效语音，请重试');
            }
            setState(PTT_STATE.IDLE);
            resumeTTS();
            return;
        }

        // 填入输入框（不发送！）
        const input = getMessageInput();
        if (!input) {
            setState(PTT_STATE.IDLE);
            return;
        }
        input.value = text;
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 160) + 'px';

        // 播放结束提示音
        playEndTone();

        // 进入确认状态
        setState(PTT_STATE.CONFIRMING);

        // 聚焦输入框以便用户直接按 Enter
        setTimeout(() => {
            if (state === PTT_STATE.CONFIRMING) {
                input.focus();
                // 将光标移到末尾
                try {
                    input.setSelectionRange(input.value.length, input.value.length);
                } catch(e) {}
            }
        }, 100);
    }

    function onConfirmSend() {
        const input = getMessageInput();
        if (!input || !input.value.trim()) return;

        // 恢复 TTS 播放
        resumeTTS();

        // 调用现有发送逻辑
        if (typeof window.sendMessage === 'function') {
            window.sendMessage();
        } else {
            console.error('[PTT] window.sendMessage 不存在');
        }

        setState(PTT_STATE.IDLE);
    }

    function onCancel() {
        const input = getMessageInput();
        if (input) input.value = '';

        playCancelTone();
        resumeTTS();
        setState(PTT_STATE.IDLE);
        showToast('已取消发送');
    }

    // ════════════════════════════════════════════════════════════
    //  计时器
    // ════════════════════════════════════════════════════════════

    function startTimer() {
        const timerEl = getTimerEl();
        if (!timerEl) return;
        recordTimer = setInterval(() => {
            const elapsed = Math.floor((Date.now() - recordStartTime) / 1000);
            const mm = String(Math.floor(elapsed / 60)).padStart(2, '0');
            const ss = String(elapsed % 60).padStart(2, '0');
            timerEl.textContent = mm + ':' + ss;
        }, 200);
    }

    function stopTimer() {
        if (recordTimer) { clearInterval(recordTimer); recordTimer = null; }
    }

    // ════════════════════════════════════════════════════════════
    //  ASR API 健康检查
    // ════════════════════════════════════════════════════════════

    async function checkAsrAvailable() {
        try {
            const response = await fetch('/api/asr.php', {
                method: 'HEAD',
                cache: 'no-cache'
            });
            // 405 表示方法不允许（说明端点存在），503 表示未配置
            if (response.status === 405 || response.status === 200 || response.status === 400) {
                return true;
            }
            if (response.status === 503) {
                console.log('[PTT] ASR 服务未配置（503）');
                return false;
            }
            return true; // 其他状态码默认认为端点存在
        } catch(err) {
            console.warn('[PTT] ASR 健康检查失败:', err);
            return false;
        }
    }

    // ════════════════════════════════════════════════════════════
    //  环境检查
    // ════════════════════════════════════════════════════════════

    function isSecureContext() {
        // HTTPS 或 localhost 视为安全上下文
        if (window.isSecureContext) return true;
        const host = window.location.hostname;
        return host === 'localhost' || host === '127.0.0.1' || host === '::1';
    }

    function supportsGetUserMedia() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    // ════════════════════════════════════════════════════════════
    //  初始化
    // ════════════════════════════════════════════════════════════

    async function init() {
        // 读取用户偏好
        try {
            const savedMode = localStorage.getItem(LS_KEY_MODE);
            if (savedMode === PTT_MODE.TOGGLE) {
                mode = PTT_MODE.TOGGLE;
            } else {
                mode = PTT_MODE.HOLD;
            }
        } catch(e) {}

        // 从 localStorage 读取启用偏好
        let shouldEnable = true;
        try {
            const savedEnabled = localStorage.getItem(LS_KEY_ENABLED);
            shouldEnable = savedEnabled !== '0';
        } catch(e) {}

        // ★ 立即绑定键盘事件（不等待异步检查完成）
        document.addEventListener('keydown', onKeyDown, true);
        document.addEventListener('keyup', onKeyUp, true);
        // DOM0 方式：在 CefSharp 中比 addEventListener 更可靠
        window.onkeydown = onKeyDown;
        window.onkeyup = onKeyUp;
        document.addEventListener('compositionstart', () => { isIMEActive = true; });
        document.addEventListener('compositionend', () => { isIMEActive = false; });

        // 也直接绑定到输入框
        bindInputKeyEvents();

        // ★ 不再在 init 中提前调用 BindObjectAsync（ASR Bridge）。
        //   原因：云端加载时 C# 的 OnLoadingStateChanged 可能尚未注册 Bridge，
        //   过早调用 BindObjectAsync 会挂起或失败，且可能阻塞后续绑定。
        //   改为在 startRecording 中按需绑定（那时 Bridge 肯定已注册完毕）。
        if (typeof CefSharp !== 'undefined' && CefSharp.BindObjectAsync) {
            console.log('[PTT] CefSharp 环境，ASR Bridge 将在首次 PTT 按键时按需绑定');

            // 绑定输入框焦点同步 Bridge，使 C# 端键盘 hook 知道何时应让 Ctrl+Space 给输入法
            function bindInputFocusBridge(attempt) {
                attempt = attempt || 1;
                CefSharp.BindObjectAsync('moonyaInputFocus').then(function() {
                    console.log('[IME] moonyaInputFocus bridge 绑定成功（尝试 ' + attempt + '）');
                    setupInputFocusTracking();
                }).catch(function(e) {
                    console.warn('[IME] 绑定 moonyaInputFocus 失败（尝试 ' + attempt + '）:', e);
                    if (attempt < 3) {
                        setTimeout(function() { bindInputFocusBridge(attempt + 1); }, 500);
                    }
                });
            }
            bindInputFocusBridge(1);
        }

        // ★ 立即启用功能（让用户能马上使用，后续检查失败再禁用）
        if (shouldEnable) {
            setEnabled(true);
        }

        // ── 环境检查 ──
        if (!isSecureContext() || !supportsGetUserMedia()) {
            console.log('[PTT] 环境不支持（非 HTTPS 或无 getUserMedia），Ctrl+空格语音功能禁用');
            setEnabled(false);
            if (typeof showToast === 'function') {
                showToast('语音功能需要 HTTPS 环境或 localhost 访问');
            }
            return;
        }

        // ── 静默预检麦克风权限（不弹窗） ──
        if (navigator.permissions && navigator.permissions.query) {
            try {
                const micPerm = await navigator.permissions.query({ name: 'microphone' });
                console.log('[PTT] 麦克风权限状态:', micPerm.state);
                if (micPerm.state === 'denied') {
                    console.log('[PTT] 麦克风权限已被拒绝，Ctrl+空格语音功能禁用');
                    setEnabled(false);
                    if (typeof showToast === 'function') {
                        showToast('麦克风权限被拒绝，请在浏览器设置中允许后重试');
                    }
                    return;
                }
                micPerm.onchange = () => {
                    console.log('[PTT] 麦克风权限变更:', micPerm.state);
                };
            } catch(e) {
                console.log('[PTT] 权限 API 不可用，跳过预检:', e.message);
            }
        }

        // ── ASR 识别方案检测 ──
        // ★ 全局只使用阿里云 ASR API（流式 WebSocket 或 HTTP 文件识别），禁用本地 Web Speech API
        asrAvailable = await checkAsrAvailable();
        console.log('[PTT] 后端 ASR API 可用:', asrAvailable);

        // ★ 不再初始化 Web Speech API 降级（确保只走阿里云 API）

        if (!asrAvailable && typeof CefSharp === 'undefined') {
            // 非 CefSharp 环境且后端 ASR 不可用：禁用
            console.log('[PTT] 无可用语音识别方案，禁用 Ctrl+空格语音');
            setEnabled(false);
            if (typeof showToast === 'function') {
                showToast('语音识别服务不可用');
            }
            return;
        }

        console.log('[PTT] 初始化完成，模式:', mode, '启用:', enabled, 'ASR 可用:', asrAvailable);
    }

    function bindInputKeyEvents() {
        const input = getMessageInput();
        if (!input) {
            // 输入框可能还没渲染，延迟重试一次
            setTimeout(function() {
                const input2 = getMessageInput();
                if (input2) {
                    input2.addEventListener('keydown', onKeyDown, false);
                    input2.addEventListener('keyup', onKeyUp, false);
                }
            }, 500);
            return;
        }
        input.addEventListener('keydown', onKeyDown, false);
        input.addEventListener('keyup', onKeyUp, false);
    }

    // ════════════════════════════════════════════════════════════
    //  输入框焦点同步到 C#（用于 Ctrl+Space 输入法切换与 PTT 的冲突处理）
    // ════════════════════════════════════════════════════════════

    function reportInputFocus(focused) {
        _inputFocused = focused;
        console.log('[IME] reportInputFocus:', focused);
        try {
            if (window.moonyaInputFocus && typeof window.moonyaInputFocus.setInputFocused === 'function') {
                window.moonyaInputFocus.setInputFocused(focused);
            } else {
                console.warn('[IME] moonyaInputFocus bridge 不可用，无法同步焦点状态');
            }
        } catch(e) {
            console.warn('[IME] setInputFocused 调用失败:', e);
        }
    }

    function setupInputFocusTracking() {
        // 事件委托，覆盖动态添加的输入框
        document.addEventListener('focusin', function(e) {
            const target = e.target;
            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) {
                reportInputFocus(true);
            }
        });
        document.addEventListener('focusout', function(e) {
            const target = e.target;
            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) {
                reportInputFocus(false);
            }
        });

        // 窗口失去焦点时重置状态，避免切回后 C# 仍认为输入框聚焦
        window.addEventListener('blur', function() {
            reportInputFocus(false);
        });

        // 初始状态
        const active = document.activeElement;
        if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.isContentEditable)) {
            reportInputFocus(true);
        }

        console.log('[IME] 输入框焦点跟踪已初始化');
    }

    // ════════════════════════════════════════════════════════════
    //  销毁
    // ════════════════════════════════════════════════════════════

    function destroy() {
        document.removeEventListener('keydown', onKeyDown, true);
        document.removeEventListener('keyup', onKeyUp, true);

        // 清理输入框级别的监听器
        const input = getMessageInput();
        if (input) {
            input.removeEventListener('keydown', onKeyDown, false);
            input.removeEventListener('keyup', onKeyUp, false);
        }

        stopTimer();
        if (recordTimeout) { clearTimeout(recordTimeout); recordTimeout = null; }
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            try { mediaRecorder.stop(); } catch(e) {}
        }
        if (audioStream) {
            try { audioStream.getTracks().forEach(t => t.stop()); } catch(e) {}
        }
        if (audioCtx) {
            try { audioCtx.close(); } catch(e) {}
            audioCtx = null;
        }
        state = PTT_STATE.IDLE;
    }

    // ════════════════════════════════════════════════════════════
    //  公开 API
    // ════════════════════════════════════════════════════════════

    window.PushToTalk = {
        init: init,
        destroy: destroy,
        getState: getState,
        setState: setState,
        switchMode: switchMode,
        playStartTone: playStartTone,
        playEndTone: playEndTone,
        playCancelTone: playCancelTone,
        startRecording: startRecording,
        stopRecording: stopRecording
    };

    // ════════════════════════════════════════════════════════════
    //  C# 端（CefSharp）回调入口
    //  C# 代码调用 browser.ExecuteScriptAsync("window.__onPttTrigger()")
    //  自动处理 hold/toggle 模式
    // ════════════════════════════════════════════════════════════

    window.__onPttTrigger = function() {
        console.log('[PTT] C# 触发 Ctrl+Space, state=' + state + ', enabled=' + enabled);
        // #region debug-point C:trigger-state
        __dbgLog('C', 'script-3-push-to-talk.php:__onPttTrigger', 'C# trigger invoked', { state: state, enabled: enabled, pttSessionId: pttSessionId, pendingSend: pendingSend, asrWaitingFinish: asrWaitingFinish });
        // #endregion

        // ★ 冷却期检查：ASR 错误后 3 秒内，所有 Ctrl+Space 触发直接吞掉
        if (Date.now() < pttCooldownUntil) {
            console.warn('[PTT] __onPttTrigger: 冷却期内，忽略触发');
            return;
        }

        // 互斥检查：实时语音对话模式开启时禁用 PTT
        if (window.VoiceChat && typeof window.VoiceChat.isActive === 'function' && window.VoiceChat.isActive()) {
            console.log('[PTT] 语音对话模式已开启，PTT 被禁用');
            if (typeof showToast === 'function') {
                showToast('语音对话模式已开启，请先关闭');
            }
            return;
        }

        // 录音中重复触发，忽略
        if (state === PTT_STATE.RECORDING) {
            console.warn('[PTT] 重复触发，忽略');
            // #region debug-point C:trigger-ignored
            __dbgLog('C', 'script-3-push-to-talk.php:__onPttTrigger', 'Trigger ignored: already RECORDING', { state: state, pttSessionId: pttSessionId });
            // #endregion
            return;
        }
        // 确认状态下，取消确认并开始新录音
        if (state === PTT_STATE.CONFIRMING) {
            setState(PTT_STATE.IDLE);
        }
        // ★ 递增会话 ID（新录音周期开始，旧周期的 ASR 回调将被忽略）
        pttSessionId++;
        // ★ 清除上一周期可能残留的兜底计时器
        if (window._pttReleaseTimer) {
            clearTimeout(window._pttReleaseTimer);
            window._pttReleaseTimer = null;
        }
        if (enabled) {
            // #region debug-point C:start-recording
            __dbgLog('C', 'script-3-push-to-talk.php:__onPttTrigger', 'Calling startRecording', { pttSessionId: pttSessionId });
            // #endregion
            startRecording();
        } else {
            console.warn('[PTT] PTT 未启用');
            // #region debug-point C:trigger-disabled
            __dbgLog('C', 'script-3-push-to-talk.php:__onPttTrigger', 'Trigger ignored: PTT disabled', { enabled: enabled });
            // #endregion
        }
    };

    window.__onPttRelease = function() {
        console.log('[PTT] C# 触发 Ctrl+Space 松开, state=' + state);
        // #region debug-point A:release-invoked
        __dbgLog('A', 'script-3-push-to-talk.php:__onPttRelease', 'C# release invoked', { state: state, pendingSend: pendingSend, pttSessionId: pttSessionId, asrWaitingFinish: asrWaitingFinish });
        // #endregion

        try {
            // 清除旧的兜底计时器（如果有）
            if (window._pttReleaseTimer) {
                clearTimeout(window._pttReleaseTimer);
                window._pttReleaseTimer = null;
            }

            if (state !== PTT_STATE.RECORDING) {
                console.log('[PTT] 松开时不在 RECORDING 状态，忽略 (state=' + state + ')');
                // #region debug-point B:release-state-not-recording
                __dbgLog('B', 'script-3-push-to-talk.php:__onPttRelease', 'Release ignored: state not RECORDING', { state: state, pendingSend: pendingSend });
                // #endregion
                return;
            }

            // ★ 捕获松开时的文本快照（用于 6 秒兜底发送）
            const inputAtRelease = getMessageInput();
            releaseSnapshot = {
                asrFinalText: asrFinalText || '',
                inputValue: inputAtRelease ? (inputAtRelease.value || '') : ''
            };
            console.log('[PTT] 松开快照:', JSON.stringify(releaseSnapshot));

            // ★ 标记：松开后需要自动发送
            pendingSend = true;
            // #region debug-point D:release-pendingSend-set
            __dbgLog('D', 'script-3-push-to-talk.php:__onPttRelease', 'pendingSend set to true', { pttSessionId: pttSessionId, releaseSnapshot: releaseSnapshot });
            // #endregion

            // ★ 先停止麦克风和音频流，再切回 IDLE，避免 ASR 与状态机竞态
            stopTimer();
            if (recordTimeout) { clearTimeout(recordTimeout); recordTimeout = null; }

            if (streamingActive) {
                streamingActive = false;
                try { if (streamingProcessor) streamingProcessor.disconnect(); } catch(e) {}
                try { if (streamingSource) streamingSource.disconnect(); } catch(e) {}
                cleanupVolumeAnalyser();
                try { if (streamingStream) streamingStream.getTracks().forEach(t => t.stop()); } catch(e) {}
                streamingStream = null;
                try { if (streamingCtx) streamingCtx.close(); } catch(e) {}
                streamingCtx = null;
                streamingProcessor = null;
                streamingSource = null;
            }

            // 麦克风流已停止，现在安全恢复 UI 状态
            setState(PTT_STATE.IDLE);

            // 调用 C# ASR 停止（fire-and-forget，__onAsrFinished 回调中处理发送）
            asrWaitingFinish = true;
            // #region debug-point D:release-stop-asr
            __dbgLog('D', 'script-3-push-to-talk.php:__onPttRelease', 'Calling moonYaAsr.stop', { pttSessionId: pttSessionId, hasMoonYaAsr: typeof window.moonYaAsr !== 'undefined', hasStop: !!(window.moonYaAsr && window.moonYaAsr.stop) });
            // #endregion
            if (typeof window.moonYaAsr !== 'undefined' && window.moonYaAsr.stop) {
                window.moonYaAsr.stop().catch(function(ex) {
                    console.error('[PTT] ASR stop 异常:', ex);
                    // #region debug-point D:release-stop-error
                    __dbgLog('D', 'script-3-push-to-talk.php:__onPttRelease', 'moonYaAsr.stop rejected', { error: String(ex && ex.message || ex) });
                    // #endregion
                });
            }

            // ★★★ 6 秒兜底：比 C# Stop() 的 5 秒超时长 1 秒 ★★★
            // 若 C# 回调丢失（CallJs 异常被吞等），兜底直接发送快照文本
            // 注意：兜底调用 sendPendingMessage，由其内部清除 pendingSend，不会提前清除标志
            window._pttReleaseTimer = setTimeout(function() {
                window._pttReleaseTimer = null;
                // #region debug-point D:release-fallback-check
                __dbgLog('D', 'script-3-push-to-talk.php:__onPttRelease', '6s fallback timer fired', { pendingSend: pendingSend, pttSessionId: pttSessionId });
                // #endregion
                if (pendingSend) {
                    console.warn('[PTT] 6 秒兜底触发：C# 未回调 __onAsrFinished，使用快照文本发送');
                    // 合并快照文本：asrFinalText 优先，其次输入框快照值
                    const fallbackText = (releaseSnapshot && releaseSnapshot.asrFinalText) ||
                                         (releaseSnapshot && releaseSnapshot.inputValue) || '';
                    sendPendingMessage(fallbackText);
                }
            }, 6000);
        } catch (err) {
            console.error('[PTT] __onPttRelease 异常:', err);
            // #region debug-point D:release-exception
            __dbgLog('D', 'script-3-push-to-talk.php:__onPttRelease', 'Exception in __onPttRelease', { error: String(err && err.message || err), stack: err && err.stack });
            // #endregion
        }
    };

    // ★ 确认回调已注册到全局
    console.log('[PTT] __onPttTrigger / __onPttRelease 已注册到 window');

    // ════════════════════════════════════════════════════════════
    //  自动初始化（DOM 加载完成后）
    // ════════════════════════════════════════════════════════════

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            // 延迟初始化，确保其他模块（sendMessage 等）已加载
            setTimeout(init, 200);
        });
    } else {
        setTimeout(init, 200);
    }

})();
</script>
