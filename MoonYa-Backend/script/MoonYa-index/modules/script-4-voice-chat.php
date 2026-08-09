<?php
/**
 * 实时语音对话核心模块（Voice Chat）
 *
 * 功能：打开开关后系统持续监听，VAD 自动检测说话开始/结束，识别后自动发给 AI，
 *       AI 回复自动 TTS 播报（播报期间暂停麦克风避免自激），播报结束自动恢复监听。
 *
 * 与 PTT（按住 Ctrl+空格单次语音输入）互斥：本模块开启时 PTT 被禁用，
 *       通过暴露 window.VoiceChat.isActive() 供 PTT 模块检查。
 *
 * 依赖（均为全局已存在，无需本模块自行实现）：
 *   - window.sendMessage()                         [script-1e-rest.php] 发送消息给 AI
 *   - window.speakText(text)                       [script-1c-save.php]  TTS 播报
 *   - window.stopVoiceBroadcast()                  [script-1c-save.php]  停止 TTS
 *   - window.__onTtsStart / window.__onTtsEnd      [script-1c-save.php]  TTS 生命周期回调（本模块覆写）
 *   - showToast(msg)                               [script-1b-features.php] 提示
 *   - window.moonYaPttGlow.setVoiceChatMode(mode)  [CefSharp C# 端] 屏幕光效模式切换
 *   - window.moonYaPttGlow.updateVolume(level)     [CefSharp C# 端] 屏幕光效音量驱动
 *   - document.querySelector('.message-input')     [layouts/main-content.php] 消息输入框
 *   - #ptt-input-glow                              [layouts/main-content.php] 输入框流光指示器
 *   - #voiceChatToggle                             [layouts/main-content.php] 实时语音对话开关
 *   - /api/asr.php                                 [后端 ASR 接口] POST multipart，返回 {code:0, text:"..."}
 *
 * 已知限制：
 *   - 已改用 ScriptProcessorNode 持续采集麦克风原始 PCM 到内存缓冲区，VAD 仅标记语音段起止，
 *     截取时自动包含 PREROLL_MS 前置音频，彻底解决首字截断问题。
 */
?>
<script>
console.log('[VoiceChat] 脚本已加载');
(function() {
    'use strict';

    function __reportDebug(hypothesisId, location, msg, data) {
        // Optional development instrumentation is disabled in production.
    }

    // ════════════════════════════════════════════════════════════
    //  常量定义
    // ════════════════════════════════════════════════════════════

    /**
     * 语音对话状态机枚举
     * - OFF         关闭，未占用麦克风
     * - LISTENING   持续监听中，VAD 检测说话开始
     * - CAPTURING   正在录制一段语音，VAD 检测说话结束
     * - RECOGNIZING 录音段已上传，等待 ASR 结果
     * - AI_THINKING 识别文本已发给 AI，等待 AI 回复 / TTS 启动
     * - AI_SPEAKING AI 回复 TTS 播报中（麦克风静音避免自激）
     */
    const VC_STATE = {
        OFF: 'off',
        LISTENING: 'listening',
        CAPTURING: 'capturing',
        RECOGNIZING: 'recognizing',
        AI_THINKING: 'ai_thinking',
        AI_SPEAKING: 'ai_speaking'
    };

    // ── VAD 阈值与时间参数 ──
    // RMS 取值范围 [0,1]（Float32 时域数据平方根均值）。
    // 阈值针对普通桌面麦克风/笔记本麦克风校准：正常说话音量经过 AEC/NS 后 RMS 通常在 0.01~0.05 之间，
    // 原值 0.06 导致大量设备无法触发。降低阈值并缩短开始确认时间，提升灵敏度。
    const SPEECH_THRESHOLD = 0.018;     // 语音开始 RMS 阈值：约 -35 dB，适配普通麦克风
    const SILENCE_THRESHOLD = 0.008;    // 语音结束 RMS 阈值：约 -42 dB，避免尾音被过早截断
    const SPEECH_START_MS = 150;        // 语音开始确认时长：降低确认延迟以减少句首截断，连续超阈值此时长才触发
    const SILENCE_END_MS = 800;         // 语音结束确认时长：连续低于阈值此时长才触发，避免说话停顿被误判结束
    const MIN_SEGMENT_MS = 400;         // 最小有效段长：短于此丢弃，过滤咳嗽/碰撞等短促噪音
    const MAX_SEGMENT_MS = 15000;       // 最大段长：超此强制切断，防止 MediaRecorder 单段过长
    const PREROLL_MS = 300;             // 预滚动缓冲时长：ScriptProcessorNode 持续采集后，截取语音段时额外包含 300ms 前置音频，彻底避免首字被切
    const MAX_BUFFER_SECONDS = 30;      // 原始音频环形缓冲最大保留时长：超过此值丢弃最旧数据，防止内存无限增长
    const TTS_RESUME_DELAY_MS = 300;    // TTS 结束后恢复麦克风的延迟：让音频设备稳定，避免恢复瞬间采集到 TTS 尾音
    const BACKPRESSURE_FAIL_COUNT = 3;  // 连续失败次数阈值：达到后触发背压延迟，给后端恢复时间
    const BACKPRESSURE_DELAY_MS = 2000; // 背压延迟：连续失败后的冷却时长
    const VAD_FFT_SIZE = 2048;          // AnalyserNode fftSize：时域采样窗口大小，兼顾分辨率与性能
    const AI_THINKING_FALLBACK_MS = 10000; // AI 思考兜底时长：若此时长内未触发 TTS，认为 AI 纯文本回复，回退监听
    const RMS_LOG_INTERVAL_MS = 1000;   // RMS 调试日志输出间隔，用于排查麦克风采集和 VAD 灵敏度问题

    // ── localStorage 键名 ──
    const LS_KEY_ENABLED = 'moonya_voice_chat_enabled'; // 是否开启（'1'/'0'），用于刷新后自动恢复
    const LS_KEY_GUIDED = 'moonya_voice_chat_guided';   // 首次引导是否已展示（'1'）

    // ── 资源路径 ──
    const ASR_ENDPOINT = '/api/asr.php'; // 后端 ASR 接口（相对路径）

    /**
     * 状态机合法转移表
     * key = 当前状态，value = 可转移到的目标状态集合
     * 任意状态均允许转移到 OFF（用户主动关闭）
     */
    const TRANSITIONS = {
        [VC_STATE.OFF]:         [VC_STATE.LISTENING],
        [VC_STATE.LISTENING]:   [VC_STATE.CAPTURING, VC_STATE.OFF],
        [VC_STATE.CAPTURING]:   [VC_STATE.RECOGNIZING, VC_STATE.LISTENING, VC_STATE.OFF],
        [VC_STATE.RECOGNIZING]: [VC_STATE.AI_THINKING, VC_STATE.LISTENING, VC_STATE.OFF],
        [VC_STATE.AI_THINKING]: [VC_STATE.AI_SPEAKING, VC_STATE.LISTENING, VC_STATE.OFF],
        [VC_STATE.AI_SPEAKING]: [VC_STATE.LISTENING, VC_STATE.OFF]
    };

    // ════════════════════════════════════════════════════════════
    //  内部状态变量
    // ════════════════════════════════════════════════════════════

    let state = VC_STATE.OFF;          // 当前状态机状态
    let micStream = null;              // 持续麦克风流（getUserMedia 结果，全程复用）
    let audioCtx = null;               // AudioContext（VAD 分析 + 音频采集用）
    let analyser = null;               // AnalyserNode（VAD 取时域数据用）
    let micSource = null;              // MediaStreamSource（micStream → analyser/scriptProcessor 的连接节点）
    let scriptProcessor = null;        // ScriptProcessorNode：持续采集麦克风原始 PCM 到内存缓冲区
    let vadRafId = null;               // VAD requestAnimationFrame ID
    let speechStartTimer = null;       // 语音开始确认计时器（LISTENING 阶段）
    let silenceEndTimer = null;        // 语音结束确认计时器（CAPTURING 阶段）
    let captureStartTime = 0;          // 本段录音开始时间戳（用于计算段长）
    let maxSegmentTimer = null;        // 最大段长计时器（CAPTURING 阶段，超时强制结束）
    let asrAbortController = null;     // ASR 请求取消控制器
    let failCount = 0;                 // 连续失败计数（用于背压判定）
    let backpressureTimer = null;      // 背压延迟计时器
    let ttsResumeTimer = null;         // TTS 结束恢复延迟计时器
    let ttsFallbackTimer = null;       // AI 思考兜底计时器（未触发 TTS 时回退监听）
    let visibilityHandler = null;      // visibilitychange 监听器引用（用于解绑）
    let discardSegment = false;        // 当前段是否应丢弃（误触/过短）
    let lastRmsLogTime = 0;            // 上次输出 RMS 调试日志的时间戳

    // ── CefSharp 光效桥接绑定状态 ──
    // PTT 模块也会绑定 moonYaPttGlow，但语音对话模块不应依赖其他模块的副作用。
    // 此处保留绑定 Promise，确保在异步绑定完成前触发状态切换也能正确转发到 C#。
    let pttGlowBindPromise = null;     // CefSharp.BindObjectAsync 返回的 Promise
    let pttGlowBindAttempted = false;  // 是否已发起过绑定（避免重复绑定）

    // ── ScriptProcessorNode 持续采集相关 ──
    let rawAudioChunks = [];           // Float32Array 列表：ScriptProcessorNode 累积的原始 PCM 块
    let totalRawSamples = 0;           // rawAudioChunks 中当前保留的总样本数
    let currentSampleIndex = 0;        // 累计写入样本索引（单调递增，用于标记语音段起止）
    let captureStartSampleIndex = 0;   // 当前语音段起始样本索引（已包含预滚动）
    let captureEndSampleIndex = 0;     // 当前语音段结束样本索引
    let rawSampleRate = 16000;         // ScriptProcessorNode 实际采样率（初始化后设置）

    // ════════════════════════════════════════════════════════════
    //  工具函数
    // ════════════════════════════════════════════════════════════

    /**
     * 获取消息输入框元素
     * @returns {HTMLTextAreaElement|HTMLInputElement|null}
     */
    function getMessageInput() {
        return document.querySelector('.message-input');
    }

    /**
     * 获取输入框流光指示器元素
     * @returns {HTMLElement|null}
     */
    function getPttInputGlow() {
        const el = document.getElementById('ptt-input-glow');
        return el || null;
    }

    /**
     * 获取实时语音对话开关元素
     * @returns {HTMLElement|null}
     */
    function getToggleEl() {
        return document.getElementById('voiceChatToggle');
    }

    /**
     * 确保 moonYaPttGlow JS Bridge 已绑定。
     * CefSharp 中通过 Register 注册的对象不会自动注入 window，
     * 必须在前端主动调用 CefSharp.BindObjectAsync('moonYaPttGlow')。
     * 返回 Promise，resolve 表示绑定完成（不保证 C# 端一定成功）。
     * @returns {Promise<void>}
     */
    /**
     * 判断一个对象是否为 CefSharp 绑定结果状态对象（非实际桥接对象）。
     */
    function isCefSharpStatusObject(obj) {
        return obj && typeof obj === 'object' &&
               typeof obj.Count !== 'undefined' &&
               typeof obj.Success !== 'undefined' &&
               typeof obj.Message !== 'undefined';
    }

    function ensurePttGlowBridge() {
        if (pttGlowBindPromise) return pttGlowBindPromise;
        if (typeof CefSharp === 'undefined' || !CefSharp.BindObjectAsync) {
            return Promise.resolve();
        }
        pttGlowBindAttempted = true;
        pttGlowBindPromise = CefSharp.BindObjectAsync('moonYaPttGlow')
            .then(function(result) {
                // CefSharp 正常情况下会把对象挂到 window[name]，但某些版本/配置下不会自动挂载。
                // 如果 result 本身即为绑定对象，则手动回写到 window，确保后续调用可靠。
                if (!window.moonYaPttGlow && result && typeof result.setVoiceChatMode === 'function') {
                    window.moonYaPttGlow = result;
                }

                var resultValues = null;
                if (result && typeof result === 'object') {
                    resultValues = {
                        Count: typeof result.Count !== 'undefined' ? result.Count : undefined,
                        Success: typeof result.Success !== 'undefined' ? result.Success : undefined,
                        Message: typeof result.Message !== 'undefined' ? result.Message : undefined
                    };
                }

                console.log('[VoiceChat] moonYaPttGlow bridge 绑定结果', resultValues);
                // #region debug-point H2:bridge-bound
                __reportDebug('H2', 'script-4-voice-chat.php:ensurePttGlowBridge', 'moonYaPttGlow bound result', {
                    hasObject: typeof window.moonYaPttGlow !== 'undefined',
                    resultType: typeof result,
                    resultValues: resultValues,
                    resultKeys: result && typeof result === 'object' ? Object.keys(result).slice(0, 10) : null,
                    windowKeys: Object.keys(window).filter(function(k) { return k.toLowerCase().indexOf('moonya') >= 0 || k.toLowerCase().indexOf('pttglow') >= 0; })
                });
                // #endregion

                // 如果绑定返回的是状态对象（Count/Success/Message）而不是实际桥接对象，
                // 且 window.moonYaPttGlow 仍未注入，说明本次绑定未成功（通常是启动期 C# 尚未注册）。
                // 重置 Promise，允许后续调用重新发起绑定。
                if (!window.moonYaPttGlow && isCefSharpStatusObject(result)) {
                    console.warn('[VoiceChat] moonYaPttGlow 绑定返回状态对象且未注入 window，允许重试');
                    pttGlowBindPromise = null;
                }
            })
            .catch(function(e) {
                console.warn('[VoiceChat] 绑定 moonYaPttGlow 失败:', e);
                // #region debug-point H2:bridge-bind-failed
                __reportDebug('H2', 'script-4-voice-chat.php:ensurePttGlowBridge', 'moonYaPttGlow bind failed', { error: String(e && e.message || e) });
                // #endregion
                pttGlowBindPromise = null;
            });
        return pttGlowBindPromise;
    }

    /**
     * 调用 C# 端 setVoiceChatMode(mode)。
     * 如果当前 bridge 未就绪，先异步绑定再调用，避免状态切换丢失。
     * @param {string} mode off | listening | capturing | recognizing | thinking | ai_speaking
     */
    function callSetVoiceChatMode(mode) {
        function doCall() {
            if (typeof window.moonYaPttGlow !== 'undefined' && window.moonYaPttGlow !== null &&
                typeof window.moonYaPttGlow.setVoiceChatMode === 'function') {
                var returnValue = undefined;
                var threw = null;
                try {
                    returnValue = window.moonYaPttGlow.setVoiceChatMode(mode);
                } catch (e) {
                    threw = String(e && e.message || e);
                }
                console.log('[VoiceChat] 调用 setVoiceChatMode(' + mode + ') return=' + returnValue + ' threw=' + threw);
                // #region debug-point H2:js-bridge-call
                __reportDebug('H2', 'script-4-voice-chat.php:callSetVoiceChatMode', 'JS calling setVoiceChatMode', {
                    mode: mode,
                    returnType: typeof returnValue,
                    returnValue: returnValue,
                    threw: threw,
                    objectType: typeof window.moonYaPttGlow,
                    objectKeys: Object.keys(window.moonYaPttGlow).slice(0, 20)
                });
                // #endregion
                return true;
            }
            return false;
        }

        if (doCall()) return;

        if (typeof CefSharp !== 'undefined' && CefSharp.BindObjectAsync) {
            // 不重试机制：先等已有绑定完成，若仍不可用则重新发起绑定
            function attemptBindAndCall() {
                ensurePttGlowBridge().then(function() {
                    if (doCall()) return;

                    // 首次绑定后仍不可用，可能是启动期绑定失败缓存了坏 Promise。
                    // 强制重置并重新发起一次绑定。
                    console.warn('[VoiceChat] 首次绑定后 moonYaPttGlow 仍不可用，准备重试绑定');
                    pttGlowBindPromise = null;
                    ensurePttGlowBridge().then(function() {
                        if (!doCall()) {
                            console.warn('[VoiceChat] 两次绑定后 moonYaPttGlow.setVoiceChatMode 仍不可用');
                            // #region debug-point H2:bridge-missing-after-rebind
                            __reportDebug('H2', 'script-4-voice-chat.php:callSetVoiceChatMode', 'moonYaPttGlow.setVoiceChatMode still unavailable after rebind', {
                                mode: mode,
                                hasObject: typeof window.moonYaPttGlow !== 'undefined',
                                objectType: typeof window.moonYaPttGlow,
                                objectKeys: window.moonYaPttGlow && typeof window.moonYaPttGlow === 'object' ? Object.keys(window.moonYaPttGlow).slice(0, 20) : null
                            });
                            // #endregion
                        }
                    });
                });
            }
            attemptBindAndCall();
        } else {
            console.warn('[VoiceChat] moonYaPttGlow.setVoiceChatMode 不可用，屏幕光效不会切换 (mode=' + mode + ')');
            // #region debug-point H2:js-bridge-missing
            __reportDebug('H2', 'script-4-voice-chat.php:callSetVoiceChatMode', 'moonYaPttGlow.setVoiceChatMode not available', { mode: mode, hasCefSharp: typeof CefSharp !== 'undefined', hasBind: !!(typeof CefSharp !== 'undefined' && CefSharp.BindObjectAsync) });
            // #endregion
        }
    }

    /**
     * 向 C# 端上报实时音量。
     * 与 setVoiceChatMode 不同，音量上报是高频调用，不等待异步绑定，未就绪时静默跳过。
     * @param {number} level 0.0 ~ 1.0
     */
    function callUpdateVolume(level) {
        if (typeof window.moonYaPttGlow !== 'undefined' && window.moonYaPttGlow !== null &&
            typeof window.moonYaPttGlow.updateVolume === 'function') {
            try { window.moonYaPttGlow.updateVolume(level); } catch(e) {}
        }
    }

    /**
     * 清除一个计时器并返回 null
     * @param {number|null} timerId setTimeout 返回的 ID
     * @returns {null}
     */
    function clearTimer(timerId) {
        if (timerId !== null) {
            clearTimeout(timerId);
        }
        return null;
    }

    /**
     * 将字符串写入 DataView（用于 WAV 头）
     */
    function writeString(view, offset, string) {
        for (let i = 0; i < string.length; i++) {
            view.setUint8(offset + i, string.charCodeAt(i));
        }
    }

    /**
     * 将 AudioBuffer 编码为 WAV Blob（单声道/16bit PCM）
     * @param {AudioBuffer} audioBuffer 输入音频缓冲
     * @returns {Blob} WAV 格式 Blob
     */
    function encodeWAV(audioBuffer) {
        const numChannels = 1;
        const sampleRate = audioBuffer.sampleRate;
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
        view.setUint16(20, 1, true); // PCM
        view.setUint16(22, numChannels, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, byteRate, true);
        view.setUint16(32, blockAlign, true);
        view.setUint16(34, bitDepth, true);
        writeString(view, 36, 'data');
        view.setUint32(40, dataLength, true);

        const channelData = audioBuffer.getChannelData(0);
        let offset = 44;
        for (let i = 0; i < channelData.length; i++) {
            let sample = Math.max(-1, Math.min(1, channelData[i]));
            sample = sample < 0 ? sample * 0x8000 : sample * 0x7FFF;
            view.setInt16(offset, sample, true);
            offset += 2;
        }
        return new Blob([view], { type: 'audio/wav' });
    }

    /**
     * 异步重采样 AudioBuffer 到目标采样率
     * @param {AudioBuffer} audioBuffer 原始音频缓冲
     * @param {number} targetSampleRate 目标采样率
     * @returns {Promise<AudioBuffer>} 重采样后的 AudioBuffer
     */
    async function resampleAudioBuffer(audioBuffer, targetSampleRate) {
        if (audioBuffer.sampleRate === targetSampleRate) {
            return audioBuffer;
        }
        const offlineCtx = new OfflineAudioContext(
            1,
            Math.ceil(audioBuffer.duration * targetSampleRate),
            targetSampleRate
        );
        const source = offlineCtx.createBufferSource();
        source.buffer = audioBuffer;
        source.connect(offlineCtx.destination);
        source.start();
        return await offlineCtx.startRendering();
    }

    /**
     * 从 rawAudioChunks 中提取指定样本区间的音频，构造 AudioBuffer
     * @param {number} startSample 起始样本索引（含）
     * @param {number} endSample 结束样本索引（含）
     * @returns {AudioBuffer|null}
     */
    function extractAudioSegment(startSample, endSample) {
        if (!audioCtx || startSample >= endSample) return null;
        const length = endSample - startSample;
        const result = new Float32Array(length);

        let chunkStart = currentSampleIndex - totalRawSamples;
        let dstOffset = 0;
        for (let i = 0; i < rawAudioChunks.length; i++) {
            const chunk = rawAudioChunks[i];
            const chunkEnd = chunkStart + chunk.length;
            if (chunkEnd > startSample && chunkStart < endSample) {
                const srcStart = Math.max(0, startSample - chunkStart);
                const srcEnd = Math.min(chunk.length, endSample - chunkStart);
                const count = srcEnd - srcStart;
                const dstStart = Math.max(0, chunkStart - startSample);
                result.set(chunk.subarray(srcStart, srcEnd), dstStart);
                dstOffset += count;
            }
            chunkStart = chunkEnd;
        }

        const audioBuffer = audioCtx.createBuffer(1, length, rawSampleRate);
        audioBuffer.copyToChannel(result, 0);
        return audioBuffer;
    }

    /**
     * 丢弃指定样本索引之前的原始音频块，控制内存增长
     * @param {number} sampleIndex 保留的样本下界（小于此值的块将被丢弃）
     */
    function trimRawBufferBefore(sampleIndex) {
        let chunkStart = currentSampleIndex - totalRawSamples;
        while (rawAudioChunks.length > 0) {
            const chunk = rawAudioChunks[0];
            const chunkEnd = chunkStart + chunk.length;
            if (chunkEnd <= sampleIndex) {
                totalRawSamples -= chunk.length;
                rawAudioChunks.shift();
                chunkStart = chunkEnd;
            } else {
                break;
            }
        }
    }

    // ════════════════════════════════════════════════════════════
    //  状态机
    // ════════════════════════════════════════════════════════════

    /**
     * 状态机切换：校验跨态合法性，合法则切换并触发副作用
     * 非法转移：console.warn 并忽略
     * @param {string} newState 目标状态（VC_STATE 枚举值）
     */
    function setState(newState) {
        // 相同状态不处理
        if (state === newState) return;

        // 合法性校验：当前状态允许转移到 newState
        const allowed = TRANSITIONS[state];
        if (!allowed || allowed.indexOf(newState) === -1) {
            console.warn('[VoiceChat] 非法状态转移:', state, '→', newState, '（已忽略）');
            return;
        }

        const oldState = state;
        state = newState;
        console.log('[VoiceChat] 状态切换:', oldState, '→', newState);
        onStateChanged(newState);
    }

    /**
     * 状态切换副作用：屏幕光效 + 输入框指示器 + body 模式类
     * @param {string} newState 新状态
     */
    function onStateChanged(newState) {
        // ── CefSharp 屏幕光效模式切换 ──
        // mode 映射：OFF→off, LISTENING→listening, CAPTURING→capturing,
        //            RECOGNIZING→recognizing, AI_THINKING→thinking, AI_SPEAKING→ai_speaking
        const glowModeMap = {
            [VC_STATE.OFF]: 'off',
            [VC_STATE.LISTENING]: 'listening',
            [VC_STATE.CAPTURING]: 'capturing',
            [VC_STATE.RECOGNIZING]: 'recognizing',
            [VC_STATE.AI_THINKING]: 'thinking',
            [VC_STATE.AI_SPEAKING]: 'ai_speaking'
        };
        // 使用封装后的调用，自动处理 CefSharp bridge 异步绑定
        const mode = glowModeMap[newState] || 'off';
        callSetVoiceChatMode(mode);

        // ── 输入框流光指示器节奏类名切换 ──
        const glow = getPttInputGlow();
        if (glow) {
            glow.classList.remove('vc-listening', 'vc-capturing', 'vc-ai-speaking');
            if (newState === VC_STATE.LISTENING) {
                glow.classList.add('vc-listening');
            } else if (newState === VC_STATE.CAPTURING) {
                glow.classList.add('vc-capturing');
            } else if (newState === VC_STATE.AI_SPEAKING) {
                glow.classList.add('vc-ai-speaking');
            }
        }

        // ── body 模式标识 ──
        if (newState === VC_STATE.OFF) {
            document.body.classList.remove('voice-chat-mode');
        } else {
            document.body.classList.add('voice-chat-mode');
        }
    }

    /**
     * 是否处于活动状态（供 PTT 模块互斥检查）
     * @returns {boolean}
     */
    function isActive() {
        return state !== VC_STATE.OFF;
    }

    // ════════════════════════════════════════════════════════════
    //  VAD 核心
    // ════════════════════════════════════════════════════════════

    /**
     * VAD 主循环（requestAnimationFrame 驱动）
     * - LISTENING：检测语音开始（rms > SPEECH_THRESHOLD 持续 SPEECH_START_MS）
     * - CAPTURING：检测语音结束（rms < SILENCE_THRESHOLD 持续 SILENCE_END_MS）+ 音量上报
     * 非 LISTENING/CAPTURING 状态时停止轮询
     */
    function vadLoop() {
        // 状态校验：非监听/录制态停止轮询
        if (state !== VC_STATE.LISTENING && state !== VC_STATE.CAPTURING) {
            vadRafId = null;
            return;
        }
        if (!analyser) {
            console.warn('[VoiceChat] vadLoop: analyser 不存在，停止轮询');
            vadRafId = null;
            return;
        }

        // 取时域数据并计算 RMS
        const buffer = new Float32Array(analyser.fftSize);
        analyser.getFloatTimeDomainData(buffer);
        let sumSq = 0;
        for (let i = 0; i < buffer.length; i++) {
            sumSq += buffer[i] * buffer[i];
        }
        const rms = Math.sqrt(sumSq / buffer.length); // 原始 RMS，范围 [0,1]

        // ── 音量上报（LISTENING/CAPTURING 都需要，让用户能实时看到麦克风是否在工作）──
        // dB 映射到 [0,1]，与 PTT reportVolume 口径一致
        const db = 20 * Math.log10(Math.max(rms, 1e-10));
        const level = Math.min(1, Math.max(0, (db + 50) / 40));
        callUpdateVolume(level);
        const glow = getPttInputGlow();
        if (glow) {
            const scale = 0.92 + level * 0.52;    // [0.92, 1.44]
            const opacity = 0.56 + level * 0.40;  // [0.56, 0.96]
            glow.style.setProperty('--ptt-orb-scale', scale.toFixed(3));
            glow.style.setProperty('--ptt-orb-opacity', opacity.toFixed(3));
        }

        // ── LISTENING 状态：语音开始检测 ──
        if (state === VC_STATE.LISTENING) {
            if (rms > SPEECH_THRESHOLD) {
                // 超阈值：启动开始确认计时器（若未启动），清除结束计时器
                if (speechStartTimer === null) {
                    speechStartTimer = setTimeout(function() {
                        speechStartTimer = null;
                        onSpeechStart();
                    }, SPEECH_START_MS);
                }
                if (silenceEndTimer !== null) {
                    silenceEndTimer = clearTimer(silenceEndTimer);
                }
            } else {
                // 未持续够 SPEECH_START_MS，重置开始确认
                if (speechStartTimer !== null) {
                    speechStartTimer = clearTimer(speechStartTimer);
                }
            }

            // 实时音量反馈：让 LISTENING 状态也能看到麦克风响应
            const level = Math.min(rms / SPEECH_THRESHOLD, 1.0);
            callUpdateVolume(level);
            if (glow) {
                glow.style.setProperty('--ptt-glow-scale', 0.92 + level * 0.46);
                glow.style.setProperty('--ptt-glow-opacity', 0.43 + level * 0.42);
            }
        }

        // ── CAPTURING 状态：语音结束检测 ──
        if (state === VC_STATE.CAPTURING) {
            if (rms < SILENCE_THRESHOLD) {
                // 低于阈值：启动结束确认计时器（若未启动）
                if (silenceEndTimer === null) {
                    silenceEndTimer = setTimeout(function() {
                        silenceEndTimer = null;
                        onSpeechEnd(false);
                    }, SILENCE_END_MS);
                }
                if (speechStartTimer !== null) {
                    speechStartTimer = clearTimer(speechStartTimer);
                }
            } else {
                // 说话继续，重置结束确认
                if (silenceEndTimer !== null) {
                    silenceEndTimer = clearTimer(silenceEndTimer);
                }
            }
        }

        // ── RMS 调试日志：每秒输出一次，帮助排查麦克风采集和 VAD 灵敏度 ──
        const now = Date.now();
        if (now - lastRmsLogTime > RMS_LOG_INTERVAL_MS) {
            console.log('[VoiceChat] RMS=', rms.toFixed(5), 'threshold=', SPEECH_THRESHOLD, 'state=', state);
            lastRmsLogTime = now;
        }

        // 继续下一帧
        vadRafId = requestAnimationFrame(vadLoop);
    }

    /**
     * 启动 VAD 轮询（若已在运行则忽略）
     */
    function startVadLoop() {
        if (vadRafId !== null) return;
        vadRafId = requestAnimationFrame(vadLoop);
    }

    /**
     * 停止 VAD 轮询
     */
    function stopVadLoop() {
        if (vadRafId !== null) {
            cancelAnimationFrame(vadRafId);
            vadRafId = null;
        }
    }

    // ════════════════════════════════════════════════════════════
    //  录音段生命周期
    // ════════════════════════════════════════════════════════════

    /**
     * 语音开始回调（VAD 确认说话开始）
     * - 校验 LISTENING 状态
     * - 记录本段起始样本索引（已包含 PREROLL_MS 前置音频）
     * - 切换到 CAPTURING
     *
     * 说明：ScriptProcessorNode 已从麦克风打开时持续采集，因此不存在 MediaRecorder
     * 启动延迟导致的首字截断问题。起始索引会向前回溯 PREROLL_MS，确保句首完整。
     */
    function onSpeechStart() {
        if (state !== VC_STATE.LISTENING) {
            console.log('[VoiceChat] onSpeechStart: 非 LISTENING 状态，忽略 (state=' + state + ')');
            return;
        }
        if (speechStartTimer !== null) {
            speechStartTimer = clearTimer(speechStartTimer);
        }

        captureStartTime = Date.now();
        discardSegment = false;

        // 计算包含预滚动的起始样本索引（向前回溯 PREROLL_MS）
        const prerollSamples = Math.ceil((PREROLL_MS / 1000) * rawSampleRate);
        captureStartSampleIndex = Math.max(0, currentSampleIndex - prerollSamples);

        // 启动最大段长计时器（超时强制结束）
        maxSegmentTimer = setTimeout(function() {
            if (state === VC_STATE.CAPTURING) {
                console.log('[VoiceChat] 达到最大段长，强制结束');
                onSpeechEnd(true);
            }
        }, MAX_SEGMENT_MS);

        setState(VC_STATE.CAPTURING);
        console.log('[VoiceChat] 开始录制本段, startSample=' + captureStartSampleIndex + ', currentSample=' + currentSampleIndex + ', prerollMs=' + PREROLL_MS);
    }

    /**
     * 语音结束回调（VAD 确认静音 / 最大段长强制）
     * - 校验 CAPTURING 状态
     * - 记录结束样本索引
     * - 段过短则标记丢弃并回 LISTENING，否则进 RECOGNIZING
     * @param {boolean} forced 是否因最大段长强制结束
     */
    function onSpeechEnd(forced) {
        if (state !== VC_STATE.CAPTURING) {
            console.log('[VoiceChat] onSpeechEnd: 非 CAPTURING 状态，忽略 (state=' + state + ')');
            return;
        }
        // 清理确认计时器与最大段长计时器
        if (silenceEndTimer !== null) {
            silenceEndTimer = clearTimer(silenceEndTimer);
        }
        if (speechStartTimer !== null) {
            speechStartTimer = clearTimer(speechStartTimer);
        }
        if (maxSegmentTimer !== null) {
            maxSegmentTimer = clearTimer(maxSegmentTimer);
        }

        const duration = Date.now() - captureStartTime;

        // 段过短标记丢弃（强制结束不判过短）
        if (duration < MIN_SEGMENT_MS && !forced) {
            discardSegment = true;
            console.log('[VoiceChat] 段过短 (' + duration + 'ms < ' + MIN_SEGMENT_MS + 'ms)，丢弃');
        }

        captureEndSampleIndex = currentSampleIndex;

        // 状态切换：丢弃段回 LISTENING，有效段进 RECOGNIZING
        if (discardSegment) {
            setState(VC_STATE.LISTENING);
        } else {
            setState(VC_STATE.RECOGNIZING);
        }

        // 异步处理音频段（提取、重采样、编码、上传）
        processSegment();
    }

    /**
     * 处理当前语音段：从原始 PCM 缓冲区提取、重采样到 16kHz、编码 WAV、上传 ASR
     */
    async function processSegment() {
        if (discardSegment) {
            discardSegment = false;
            // 丢弃后清理已使用缓冲，避免内存无限增长
            trimRawBufferBefore(captureEndSampleIndex);
            return;
        }

        const segmentBuffer = extractAudioSegment(captureStartSampleIndex, captureEndSampleIndex);
        if (!segmentBuffer) {
            console.warn('[VoiceChat] 本段无音频数据');
            if (state === VC_STATE.RECOGNIZING) {
                setState(VC_STATE.LISTENING);
                startVadLoop();
            }
            return;
        }

        try {
            // 重采样到 16kHz 以适配 ASR
            const resampledBuffer = await resampleAudioBuffer(segmentBuffer, 16000);
            const wavBlob = encodeWAV(resampledBuffer);

            console.log('[VoiceChat] 本段音频: originalSamples=' + segmentBuffer.length +
                ', originalRate=' + segmentBuffer.sampleRate +
                ', resampledSamples=' + resampledBuffer.length +
                ', wavSize=' + wavBlob.size);

            // 上传识别
            uploadSegment(wavBlob);

            // 处理完成后清理已使用缓冲
            trimRawBufferBefore(captureEndSampleIndex);
        } catch(e) {
            console.error('[VoiceChat] 音频段处理失败:', e);
            if (typeof showToast === 'function') {
                showToast('音频处理失败');
            }
            if (state === VC_STATE.RECOGNIZING) {
                setState(VC_STATE.LISTENING);
                startVadLoop();
            }
        }
    }

    /**
     * 录音段处理完成（旧 MediaRecorder 回调，已废弃保留为空）
     */
    function handleSegmentEnd() {
        // ScriptProcessorNode 方案下，段处理由 processSegment 完成，此函数保留以避免外部引用报错
    }

    /**
     * 上传音频段到 ASR 接口识别
     * - 成功且有文本：调用 sendToAi
     * - 失败/空：failCount++ 并按背压策略恢复
     * - 网络错误：同失败处理
     * - AbortError：忽略（用户主动停止）
     * @param {Blob} blob 音频数据
     */
    function uploadSegment(blob) {
        // ScriptProcessorNode 方案固定输出 16kHz WAV
        const formatName = 'wav';

        asrAbortController = (typeof AbortController !== 'undefined') ? new AbortController() : null;

        const formData = new FormData();
        formData.append('audio', blob, 'recording.' + formatName);
        formData.append('format', formatName);
        formData.append('sample_rate', '16000');

        const fetchOpts = { method: 'POST', body: formData };
        if (asrAbortController) {
            fetchOpts.signal = asrAbortController.signal;
        }

        console.log('[VoiceChat] 上传音频:', formatName, '大小:', blob.size, 'bytes');

        fetch(ASR_ENDPOINT, fetchOpts).then(function(response) {
            if (!response.ok) {
                throw new Error('ASR API 返回 ' + response.status);
            }
            return response.json();
        }).then(function(result) {
            asrAbortController = null;
            console.log('[VoiceChat] ASR 结果:', result);

            // 用户已关闭：忽略结果
            if (state === VC_STATE.OFF) return;

            if (result && result.code === 0 && result.text && String(result.text).trim()) {
                // 成功且有文本
                failCount = 0;
                sendToAi(String(result.text).trim());
            } else {
                // 空结果或后端失败
                failCount++;
                console.warn('[VoiceChat] ASR 空结果/失败, failCount=' + failCount, result && result.message);
                if (typeof showToast === 'function') {
                    showToast('未识别到语音内容');
                }
                handleAsrFailure();
            }
        }).catch(function(err) {
            asrAbortController = null;
            // 用户主动取消（关闭开关）：忽略
            if (err && err.name === 'AbortError') {
                console.log('[VoiceChat] ASR 请求已取消');
                return;
            }
            // 用户已关闭：忽略
            if (state === VC_STATE.OFF) return;
            console.error('[VoiceChat] ASR 上传失败:', err);
            failCount++;
            if (typeof showToast === 'function') {
                showToast('语音识别失败: ' + (err.message || '未知错误'));
            }
            handleAsrFailure();
        });
    }

    /**
     * ASR 失败后的状态恢复（含背压机制）
     * 连续失败达 BACKPRESSURE_FAIL_COUNT 则延迟 BACKPRESSURE_DELAY_MS 再回监听
     */
    function handleAsrFailure() {
        // 已被关闭，不处理
        if (state === VC_STATE.OFF) return;

        if (failCount >= BACKPRESSURE_FAIL_COUNT) {
            console.log('[VoiceChat] 触发背压延迟 ' + BACKPRESSURE_DELAY_MS + 'ms');
            if (typeof showToast === 'function') {
                showToast('识别连续失败，暂停 ' + (BACKPRESSURE_DELAY_MS / 1000) + ' 秒后重试');
            }
            backpressureTimer = setTimeout(function() {
                backpressureTimer = null;
                failCount = 0;
                if (state !== VC_STATE.OFF) {
                    setState(VC_STATE.LISTENING);
                    startVadLoop();
                }
            }, BACKPRESSURE_DELAY_MS);
        } else {
            setState(VC_STATE.LISTENING);
            startVadLoop();
        }
    }

    // ════════════════════════════════════════════════════════════
    //  AI 交互
    // ════════════════════════════════════════════════════════════

    /**
     * 将识别文本发送给 AI
     * - 识别文本作为参数直传 sendMessage，不写入聊天输入框
     * - 设置 AI_THINKING_FALLBACK_MS 兜底：若未触发 TTS，回退监听
     * @param {string} text 识别文本
     */
    function sendToAi(text) {
        // 已关闭则不发送，回退监听
        if (state === VC_STATE.OFF) {
            setState(VC_STATE.LISTENING);
            startVadLoop();
            return;
        }

        setState(VC_STATE.AI_THINKING);

        // 实时语音对话：识别文本直接发送，不写入聊天输入框

        // 兜底计时器：AI 可能纯文本回复不触发 TTS，超时后回退监听
        if (ttsFallbackTimer !== null) {
            ttsFallbackTimer = clearTimer(ttsFallbackTimer);
        }
        ttsFallbackTimer = setTimeout(function() {
            ttsFallbackTimer = null;
            // 仍处于 AI_THINKING 说明未触发 TTS
            if (state === VC_STATE.AI_THINKING) {
                console.log('[VoiceChat] AI 思考兜底：未触发 TTS，回退监听');
                setState(VC_STATE.LISTENING);
                startVadLoop();
            }
        }, AI_THINKING_FALLBACK_MS);

        try {
            if (typeof window.sendMessage === 'function') {
                // 识别文本直传发送（不经过输入框）；第二参数标记语音对话来源
                // （该条 AI 回答无论「语音播报」开关与否都要朗读）；捕获 async rejection
                Promise.resolve(window.sendMessage(text, true)).catch(function(err) {
                    console.error('[VoiceChat] sendMessage 异常:', err);
                });
            } else {
                console.error('[VoiceChat] window.sendMessage 不可用');
                if (ttsFallbackTimer !== null) {
                    ttsFallbackTimer = clearTimer(ttsFallbackTimer);
                }
                if (typeof showToast === 'function') {
                    showToast('消息发送功能未就绪');
                }
                setState(VC_STATE.LISTENING);
                startVadLoop();
            }
        } catch(e) {
            console.error('[VoiceChat] sendMessage 异常:', e);
            if (ttsFallbackTimer !== null) {
                ttsFallbackTimer = clearTimer(ttsFallbackTimer);
            }
            setState(VC_STATE.LISTENING);
            startVadLoop();
        }
    }

    // ════════════════════════════════════════════════════════════
    //  TTS 回声抑制
    // ════════════════════════════════════════════════════════════

    /**
     * TTS 开始回调（由 script-1c-save.php 派发）
     * - 清除 AI 思考兜底计时器
     * - 校验 AI_THINKING 状态，切换到 AI_SPEAKING
     * - 静音麦克风避免自激
     * - 停止 VAD 轮询
     */
    function onTtsStart() {
        console.log('[VoiceChat] onTtsStart, state=' + state);
        // 清除 AI 思考兜底（TTS 已启动，无需兜底）
        if (ttsFallbackTimer !== null) {
            ttsFallbackTimer = clearTimer(ttsFallbackTimer);
        }
        // 已关闭，忽略
        if (state === VC_STATE.OFF) return;

        if (state === VC_STATE.AI_THINKING) {
            setState(VC_STATE.AI_SPEAKING);
        }

        // 静音麦克风（避免 TTS 播报被麦克风采集形成自激）
        if (micStream) {
            micStream.getTracks().forEach(function(t) {
                t.enabled = false;
            });
        }
        // 停止 VAD 轮询（播报期间不检测）
        stopVadLoop();
    }

    /**
     * TTS 结束回调（由 script-1c-save.php 派发）
     * @param {string} reason 结束原因：'complete'|'error'|'stopped'
     * - 校验 AI_SPEAKING 状态
     * - 延迟 TTS_RESUME_DELAY_MS 后恢复麦克风并重启监听
     */
    function onTtsEnd(reason) {
        console.log('[VoiceChat] onTtsEnd, reason=' + reason + ', state=' + state);
        if (state !== VC_STATE.AI_SPEAKING) {
            // 可能多次触发或已关闭，忽略
            return;
        }

        // 清理可能残留的恢复计时器
        if (ttsResumeTimer !== null) {
            ttsResumeTimer = clearTimer(ttsResumeTimer);
        }

        ttsResumeTimer = setTimeout(function() {
            ttsResumeTimer = null;
            // 已关闭则不恢复
            if (state === VC_STATE.OFF) return;

            // 恢复麦克风采集
            if (micStream) {
                micStream.getTracks().forEach(function(t) {
                    t.enabled = true;
                });
            }
            // 回到监听并重启 VAD
            setState(VC_STATE.LISTENING);
            startVadLoop();
        }, TTS_RESUME_DELAY_MS);
    }

    // ════════════════════════════════════════════════════════════
    //  启动 / 停止
    // ════════════════════════════════════════════════════════════

    /**
     * 主启动流程
     * - 申请麦克风流（单声道 + 回声消除 + 噪声抑制）
     * - 创建 AudioContext / MediaStreamSource / AnalyserNode
     * - 绑定 TTS 回调与 visibility 监听
     * - 进入 LISTENING 并启动 VAD
     * 失败时回滚资源、提示并切回关闭态
     */
    function start() {
        // 已非 OFF 状态，忽略重复启动
        if (state !== VC_STATE.OFF) {
            console.log('[VoiceChat] start: 已在运行中 (state=' + state + ')，忽略');
            return;
        }

        // 浏览器能力检查
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            console.error('[VoiceChat] 当前环境不支持 getUserMedia');
            if (typeof showToast === 'function') {
                showToast('当前环境不支持语音对话');
            }
            return;
        }

        const preferredAudioConstraints = {
            channelCount: 1,           // 单声道，降低数据量
            echoCancellation: true,     // 回声消除，减少 TTS 串扰
            noiseSuppression: true,      // 噪声抑制
            autoGainControl: true,       // 自动增益，稳定输入音量
            sampleRate: { ideal: 16000 } // 优先 16kHz，适配 ASR
        };
        const fallbackAudioConstraints = {
            channelCount: 1,
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true
        };

        navigator.mediaDevices.getUserMedia({ audio: preferredAudioConstraints })
        .catch(function(err) {
            console.warn('[VoiceChat] getUserMedia 首选约束失败，回退到基础约束:', err);
            return navigator.mediaDevices.getUserMedia({ audio: fallbackAudioConstraints });
        })
        .then(function(stream) {
            micStream = stream;

            // 创建 AudioContext
            try {
                const Ctor = window.AudioContext || window.webkitAudioContext;
                audioCtx = new Ctor();
            } catch(e) {
                console.error('[VoiceChat] AudioContext 创建失败:', e);
                try { stream.getTracks().forEach(function(t) { t.stop(); }); } catch(_) {}
                micStream = null;
                if (typeof showToast === 'function') {
                    showToast('音频上下文初始化失败');
                }
                return;
            }

            // 创建分析节点链：source → analyser
            // 同时 source → scriptProcessor → destination，实现全程原始 PCM 采集
            try {
                micSource = audioCtx.createMediaStreamSource(stream);
                analyser = audioCtx.createAnalyser();
                analyser.fftSize = VAD_FFT_SIZE;
                analyser.smoothingTimeConstant = 0.4; // 平滑系数，减少抖动
                micSource.connect(analyser);

                // ScriptProcessorNode 持续采集麦克风原始音频到内存缓冲区
                rawSampleRate = audioCtx.sampleRate || 48000;
                const scriptBufferSize = 4096;
                scriptProcessor = audioCtx.createScriptProcessor(scriptBufferSize, 1, 1);
                scriptProcessor.onaudioprocess = function(event) {
                    const inputData = event.inputBuffer.getChannelData(0);
                    const chunk = new Float32Array(inputData.length);
                    chunk.set(inputData);
                    rawAudioChunks.push(chunk);
                    totalRawSamples += chunk.length;
                    currentSampleIndex += chunk.length;

                    // 限制缓冲区大小，防止内存无限增长
                    const maxSamples = MAX_BUFFER_SECONDS * rawSampleRate;
                    while (totalRawSamples > maxSamples && rawAudioChunks.length > 0) {
                        const oldChunk = rawAudioChunks.shift();
                        totalRawSamples -= oldChunk.length;
                    }
                };
                micSource.connect(scriptProcessor);
                scriptProcessor.connect(audioCtx.destination);
            } catch(e) {
                console.error('[VoiceChat] 分析/采集节点初始化失败:', e);
                try { audioCtx.close(); } catch(_) {}
                audioCtx = null;
                micSource = null;
                analyser = null;
                scriptProcessor = null;
                try { stream.getTracks().forEach(function(t) { t.stop(); }); } catch(_) {}
                micStream = null;
                if (typeof showToast === 'function') {
                    showToast('音频分析初始化失败');
                }
                return;
            }

            // 重置原始音频缓冲区计数
            currentSampleIndex = 0;
            rawAudioChunks = [];
            totalRawSamples = 0;

            // 绑定 TTS 回调
            window.__onTtsStart = onTtsStart;
            window.__onTtsEnd = onTtsEnd;

            // 绑定 visibilitychange
            visibilityHandler = onVisibilityChange;
            document.addEventListener('visibilitychange', visibilityHandler);

            // 进入监听并启动 VAD
            setState(VC_STATE.LISTENING);
            startVadLoop();
            console.log('[VoiceChat] 启动成功, rawSampleRate=' + rawSampleRate + ', scriptProcessorBuffer=' + 4096);

            // 双重确认：主动调用一次屏幕光效 listening 模式，确保 C# 端切换成功
            callSetVoiceChatMode('listening');
        }).catch(function(err) {
            console.error('[VoiceChat] getUserMedia 失败:', err);
            micStream = null;
            const name = err && err.name ? err.name : '';
            if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError') {
                if (typeof showToast === 'function') {
                    showToast('麦克风权限被拒绝');
                }
            } else {
                if (typeof showToast === 'function') {
                    showToast('麦克风启动失败: ' + (err.message || name || '未知错误'));
                }
            }
            // 开关切回关闭态
            setToggleActive(false);
            try { localStorage.setItem(LS_KEY_ENABLED, '0'); } catch(e) {}
        });
    }

    /**
     * 停止流程：完整释放所有资源
     * - 取消所有计时器
     * - 取消 VAD 轮询
     * - 中止 ASR 请求
     * - 停止 TTS
     * - 释放麦克风流、关闭 AudioContext
     * - 解绑 TTS 回调与 visibility 监听
     * - 切回 OFF，开关 UI 切回关闭态
     */
    function stop() {
        console.log('[VoiceChat] stop() 开始清理资源, state=' + state);

        // 1. 取消所有计时器
        speechStartTimer = clearTimer(speechStartTimer);
        silenceEndTimer = clearTimer(silenceEndTimer);
        maxSegmentTimer = clearTimer(maxSegmentTimer);
        backpressureTimer = clearTimer(backpressureTimer);
        ttsResumeTimer = clearTimer(ttsResumeTimer);
        ttsFallbackTimer = clearTimer(ttsFallbackTimer);

        // 2. 取消 VAD 轮询
        stopVadLoop();

        // 3. 中止 ASR 请求
        if (asrAbortController) {
            try { asrAbortController.abort(); } catch(e) {}
            asrAbortController = null;
        }

        discardSegment = false;

        // 4. 停止 TTS 播报
        if (typeof window.stopVoiceBroadcast === 'function') {
            try { window.stopVoiceBroadcast(); } catch(e) {
                console.warn('[VoiceChat] stopVoiceBroadcast 异常:', e);
            }
        }

        // 5. 释放麦克风流
        if (micStream) {
            try {
                micStream.getTracks().forEach(function(t) {
                    t.enabled = true;
                    t.stop();
                });
            } catch(e) {
                console.warn('[VoiceChat] 释放麦克风流异常:', e);
            }
            micStream = null;
        }

        // 6. 断开并关闭音频节点
        try {
            if (scriptProcessor) {
                scriptProcessor.onaudioprocess = null;
                scriptProcessor.disconnect();
            }
        } catch(e) {}
        try { if (micSource) micSource.disconnect(); } catch(e) {}
        try { if (analyser) analyser.disconnect(); } catch(e) {}
        scriptProcessor = null;
        micSource = null;
        analyser = null;
        if (audioCtx) {
            try {
                if (audioCtx.state !== 'closed') {
                    audioCtx.close();
                }
            } catch(e) {
                console.warn('[VoiceChat] 关闭 AudioContext 异常:', e);
            }
            audioCtx = null;
        }

        // 7. 解绑 TTS 回调
        try { window.__onTtsStart = null; } catch(e) {}
        try { window.__onTtsEnd = null; } catch(e) {}

        // 8. 移除 visibilitychange 监听
        if (visibilityHandler) {
            try { document.removeEventListener('visibilitychange', visibilityHandler); } catch(e) {}
            visibilityHandler = null;
        }

        // 9. 重置失败计数与原始音频缓冲
        failCount = 0;
        rawAudioChunks = [];
        totalRawSamples = 0;
        currentSampleIndex = 0;
        captureStartSampleIndex = 0;
        captureEndSampleIndex = 0;

        // 10. 切回 OFF（触发光效/指示器复位）
        setState(VC_STATE.OFF);

        // 12. 开关 UI 切回关闭态
        setToggleActive(false);
        try { localStorage.setItem(LS_KEY_ENABLED, '0'); } catch(e) {}

        // 13. 复位输入框流光指示器 CSS 变量
        const glow = getPttInputGlow();
        if (glow) {
            glow.style.setProperty('--ptt-orb-scale', '1');
            glow.style.setProperty('--ptt-orb-opacity', '0.85');
        }

        console.log('[VoiceChat] stop() 资源清理完成');
    }

    // ════════════════════════════════════════════════════════════
    //  开关 UI
    // ════════════════════════════════════════════════════════════

    /**
     * 设置开关 UI 激活态
     * @param {boolean} active 是否激活
     */
    function setToggleActive(active) {
        if (typeof window.setMoonYaFeatureBadge === 'function') {
            window.setMoonYaFeatureBadge('voice', active);
        }
        const el = getToggleEl();
        if (!el) return;
        if (active) {
            el.classList.add('active');
            el.setAttribute('aria-checked', 'true');
        } else {
            el.classList.remove('active');
            el.setAttribute('aria-checked', 'false');
        }
    }

    /**
     * 绑定开关交互事件
     * - click：切换开关
     * - keydown：Space/Enter 切换开关
     */
    function bindToggle() {
        const el = getToggleEl();
        if (!el) {
            console.warn('[VoiceChat] 未找到 #voiceChatToggle，无法绑定开关');
            return;
        }

        el.addEventListener('click', function(e) {
            e.preventDefault();
            if (state === VC_STATE.OFF) {
                toggleOn();
            } else {
                toggleOff();
            }
        });

        el.addEventListener('keydown', function(e) {
            // Space / Enter 触发切换
            if (e.key === ' ' || e.key === 'Enter' || e.keyCode === 32 || e.keyCode === 13) {
                e.preventDefault();
                if (state === VC_STATE.OFF) {
                    toggleOn();
                } else {
                    toggleOff();
                }
            }
        });
    }

    /**
     * 打开语音对话
     * - 首次使用显示引导提示
     * - 调用 start()
     * - 成功后开关加 .active，写 localStorage
     */
    function toggleOn() {
        // 首次引导
        let guided = false;
        try {
            guided = localStorage.getItem(LS_KEY_GUIDED) === '1';
        } catch(e) {}
        if (!guided) {
            if (typeof showToast === 'function') {
                showToast('打开后直接说话即可，无需按键；AI 回复期间会暂停麦克风');
            }
            try { localStorage.setItem(LS_KEY_GUIDED, '1'); } catch(e) {}
        }

        // 立即更新 UI（即使 start 异步失败也会在 catch 中回滚）
        setToggleActive(true);
        try { localStorage.setItem(LS_KEY_ENABLED, '1'); } catch(e) {}

        start();
    }

    /**
     * 关闭语音对话
     * - 调用 stop()
     * - 开关移除 .active，写 localStorage
     */
    function toggleOff() {
        stop();
    }

    // ════════════════════════════════════════════════════════════
    //  Page Visibility 监听
    // ════════════════════════════════════════════════════════════

    /**
     * visibilitychange 处理器
     * - 页面隐藏且非 OFF 非 AI_SPEAKING：暂停 VAD 轮询（节省资源）
     * - 页面可见且处于 LISTENING：恢复 VAD 轮询
     */
    function onVisibilityChange() {
        if (document.hidden) {
            // 隐藏时：非 OFF 且非 AI_SPEAKING 暂停 VAD（AI 播报期间不干预）
            if (state !== VC_STATE.OFF && state !== VC_STATE.AI_SPEAKING) {
                stopVadLoop();
                // 清理确认计时器，避免隐藏期间误触发
                speechStartTimer = clearTimer(speechStartTimer);
                silenceEndTimer = clearTimer(silenceEndTimer);
                console.log('[VoiceChat] 页面隐藏，暂停 VAD');
            }
        } else {
            // 可见时：处于 LISTENING 恢复 VAD
            if (state === VC_STATE.LISTENING) {
                startVadLoop();
                console.log('[VoiceChat] 页面可见，恢复 VAD');
            }
        }
    }

    // ════════════════════════════════════════════════════════════
    //  初始化
    // ════════════════════════════════════════════════════════════

    /**
     * 初始化
     * - 绑定开关
     * - 读取 localStorage，若上次为开启态则延迟自动恢复
     */
    function init() {
        bindToggle();

        // 在 CefSharp 环境下主动绑定 moonYaPttGlow，不依赖 PTT 模块的副作用。
        // 绑定是异步的，ensurePttGlowBridge 会缓存 Promise，后续状态切换会自动等待绑定完成。
        if (typeof CefSharp !== 'undefined' && CefSharp.BindObjectAsync) {
            ensurePttGlowBridge();
        }

        // 读取是否需要自动恢复开启
        let shouldAutoStart = false;
        try {
            shouldAutoStart = localStorage.getItem(LS_KEY_ENABLED) === '1';
        } catch(e) {}

        if (shouldAutoStart) {
            // 延迟 500ms 等其他模块（sendMessage 等）加载完成
            setTimeout(function() {
                // 再次确认用户偏好未被改变
                let stillWanted = false;
                try {
                    stillWanted = localStorage.getItem(LS_KEY_ENABLED) === '1';
                } catch(e) {}
                if (stillWanted && state === VC_STATE.OFF) {
                    console.log('[VoiceChat] 检测到上次开启态，自动恢复');
                    toggleOn();
                }
            }, 500);
        }

        console.log('[VoiceChat] 初始化完成, autoStart=' + shouldAutoStart);
    }

    // ════════════════════════════════════════════════════════════
    //  公开 API
    // ════════════════════════════════════════════════════════════

    window.VoiceChat = {
        init: init,
        start: toggleOn,
        stop: toggleOff,
        isActive: isActive,
        getState: function() { return state; }
    };

    // ════════════════════════════════════════════════════════════
    //  自动初始化（DOM 加载完成后）
    //  延迟 300ms（比 PTT 的 200ms 稍晚），确保 PTT 先初始化并注册互斥检查
    // ════════════════════════════════════════════════════════════

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(init, 300);
        });
    } else {
        setTimeout(init, 300);
    }

})();
</script>
