        /* ==================== 办公室极简正视图 ==================== */
        .office-view {
            display: none;
            flex: 1;
            flex-direction: column;
            min-height: 0;
            position: relative;
            overflow: hidden;
            background: #f5f5f4;
        }

        body.office-active .office-view {
            display: flex;
        }

        /* 主界面内切换时隐藏对话区元素（DOM 不销毁，任务/流式渲染不断） */
        body.office-active .main-header,
        body.office-active .messages-container {
            display: none !important;
        }

        body.office-active .input-container-wrapper {
            display: block !important;
            flex: 0 0 auto;
            z-index: 110;
            background: transparent;
            box-shadow: none;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
        }

        body.office-popout-mode .sidebar,
        body.office-popout-mode .floating-controls,
        body.office-popout-mode .detail-panel,
        body.office-popout-mode .detail-panel-resizer {
            display: none !important;
        }

        body.office-popout-mode .main-content {
            width: 100vw;
            height: 100vh;
        }

        /* 独立窗口模式：office-view 直接铺满 */
        body[data-office-standalone="1"] {
            margin: 0;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif;
        }
        body[data-office-standalone="1"] .office-view {
            display: flex;
            height: 100vh;
        }

        .office-header {
            flex: 0 0 auto;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 64px;
            padding: 16px 32px 4px;
            background: #f8f8f7;
            z-index: 50;
        }

        .office-title {
            font-size: 20px;
            font-weight: 700;
            color: #111111;
            letter-spacing: 0;
            display: flex;
            align-items: center;
        }

        .office-title::before {
            display: none;
        }

        .office-popout-btn {
            position: absolute;
            right: 28px;
            top: 20px;
            border: 1px solid #ddddda;
            background: #ffffff;
            color: #3c3c3a;
            font-size: 12px;
            padding: 6px 14px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .office-popout-btn:hover {
            background: #f1f1ef;
            border-color: #c9c9c5;
            color: #111111;
            box-shadow: 0 4px 12px rgba(30, 30, 28, 0.08);
        }

        body[data-office-popout="1"] .office-popout-btn {
            display: none;
        }

        /* ---------- 舞台与地面（参考图中的白灰开放空间） ---------- */
        .office-stage {
            flex: 1;
            position: relative;
            min-height: 0;
            overflow: hidden;
            background: linear-gradient(180deg, #f8f8f7 0%, #f4f4f2 100%);
        }

        .office-floor {
            position: absolute;
            inset: 10px 28px 44px;
            background: linear-gradient(180deg, #fbfbfa 0%, #f7f7f5 100%);
            border: 1px solid #eeeeeb;
            border-radius: 24px;
            box-shadow: 0 18px 48px rgba(45, 45, 42, 0.05), inset 0 1px 0 #ffffff;
            pointer-events: none;
        }

        /* ---------- 工位 ---------- */
        .workstation {
            position: absolute;
            width: 220px;
            height: 200px;
            transform: translate(-50%, -100%);
            transform-origin: bottom center;
        }

        .ws-shadow {
            position: absolute;
            left: 50%;
            bottom: -25px;
            width: 224px;
            height: 72px;
            transform: translateX(-50%) rotate(-4deg);
            background: radial-gradient(ellipse at 42% 50%, rgba(64, 64, 60, 0.20) 0%, rgba(88, 88, 82, 0.09) 40%, transparent 72%);
            filter: blur(3px);
            z-index: 0;
        }

        /* 背面坐姿与统一白椅是一张透明复合素材；实际角色视觉宽度约 85–100px。 */
        .ws-mover {
            position: absolute;
            left: 50%;
            bottom: 6px;
            width: 124px;
            transform: translateX(-50%);
            z-index: 4;
            text-align: center;
        }

        .ws-character-trigger {
            display: block;
            width: 124px;
            margin: 0;
            padding: 0;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: inherit;
            font: inherit;
            cursor: pointer;
            outline: none;
        }

        .ws-character-trigger:focus-visible {
            outline: 3px solid rgba(39, 135, 245, 0.45);
            outline-offset: 3px;
        }

        .ws-character {
            width: 124px;
            height: auto;
            display: block;
            margin: 0 auto;
            filter: drop-shadow(0 8px 9px rgba(42, 42, 40, 0.18));
            user-select: none;
            -webkit-user-drag: none;
            cursor: pointer;
        }

        .ws-empty-chair {
            position: absolute;
            inset: 0 auto auto 0;
            width: 124px;
            height: auto;
            display: none;
            filter: drop-shadow(0 8px 9px rgba(42, 42, 40, 0.14));
            user-select: none;
            -webkit-user-drag: none;
        }

        .ws-empty-chair.show { display: block; }

        /* 白色办公桌：平直桌面、纤细桌腿和右侧抽屉柜。 */
        .ws-desk {
            position: absolute;
            left: 50%;
            bottom: 34px;
            width: 210px;
            height: 80px;
            transform: translateX(-50%);
            z-index: 2;
        }

        .ws-desk::before,
        .ws-desk::after {
            content: '';
            position: absolute;
            top: 14px;
            width: 7px;
            height: 65px;
            background: linear-gradient(90deg, #dededb, #f7f7f5 55%, #d1d1ce);
            border-radius: 0 0 3px 3px;
            box-shadow: 2px 6px 8px rgba(48, 48, 45, 0.10);
        }

        .ws-desk::before { left: 17px; }
        .ws-desk::after { right: 17px; }

        .ws-desk-top {
            position: absolute;
            left: 50%;
            bottom: 100px;
            width: 214px;
            height: 14px;
            background: linear-gradient(180deg, #ffffff, #f1f1ef);
            transform: translateX(-50%);
            border: 1px solid #e8e8e5;
            border-radius: 2px;
            box-shadow: 0 5px 10px rgba(45, 45, 42, 0.12);
            z-index: 3;
        }

        .ws-desk-front {
            position: absolute;
            right: 10px;
            top: 14px;
            width: 48px;
            height: 58px;
            background:
                linear-gradient(#dededb, #dededb) center 19px / 22px 1px no-repeat,
                linear-gradient(#dededb, #dededb) center 38px / 22px 1px no-repeat,
                linear-gradient(180deg, #fbfbfa, #ededeb);
            border: 1px solid #e1e1de;
            border-radius: 0 0 3px 3px;
            box-shadow: 3px 7px 11px rgba(45, 45, 42, 0.10);
        }

        .ws-work-hands {
            position: absolute;
            left: 50%;
            bottom: 99px;
            width: 118px;
            height: 34px;
            transform: translateX(-50%);
            z-index: 3;
            pointer-events: none;
        }

        .ws-keyboard {
            position: absolute;
            left: 18px;
            top: 12px;
            width: 62px;
            height: 17px;
            border-radius: 4px;
            transform: skewX(-18deg);
            background: repeating-linear-gradient(90deg, #d9d9d6 0 5px, #f8f8f6 5px 7px);
            box-shadow: 0 2px 4px rgba(45, 45, 42, .14);
        }

        .ws-mouse {
            position: absolute;
            right: 12px;
            top: 13px;
            width: 17px;
            height: 21px;
            border-radius: 9px 9px 7px 7px;
            background: #e8e8e5;
            box-shadow: 0 2px 4px rgba(45, 45, 42, .14);
        }

        .ws-hand {
            position: absolute;
            width: 19px;
            height: 10px;
            border-radius: 50%;
            background: #ffe0d6;
            opacity: 0;
            transition: opacity .2s ease;
            box-shadow: 0 1px 2px rgba(80, 50, 70, .18);
        }

        .ws-hand-keyboard { left: 43px; top: 10px; }
        .ws-hand-mouse { right: 11px; top: 11px; }

        .workstation.ws-active .ws-hand { opacity: 1; }
        .workstation.ws-active .ws-hand-keyboard { animation: keyboardTap .42s ease-in-out infinite alternate; }
        .workstation.ws-active .ws-hand-mouse { animation: mouseClick .7s ease-in-out infinite; }

        @keyframes keyboardTap {
            from { transform: translate(0, -2px) rotate(-5deg); }
            to { transform: translate(5px, 1px) rotate(4deg); }
        }

        @keyframes mouseClick {
            0%, 100% { transform: translate(0, -1px) scaleY(1); }
            45% { transform: translate(2px, 1px) scaleY(.86); }
            60% { transform: translate(2px, 0) scaleY(1); }
        }

        /* 显示器：参考图中的方形银白边框与黑色息屏。 */
        .ws-monitor {
            position: absolute;
            left: 50%;
            bottom: 120px;
            width: 82px;
            transform: translateX(-50%);
            z-index: 3;
        }

        .ws-monitor-frame {
            width: 82px;
            height: 50px;
            background: #eeeeec;
            border: 1px solid #d2d2cf;
            border-radius: 2px;
            padding: 3px;
            box-shadow: 0 5px 10px rgba(38, 38, 36, 0.19);
        }

        .ws-screen {
            width: 100%;
            height: 100%;
            border-radius: 1px;
            background: linear-gradient(180deg, #171717, #080808);
            transition: background 0.45s ease, box-shadow 0.45s ease;
        }

        /* 运行状态：屏幕蓝色 + 微光晕 */
        .workstation.ws-active .ws-screen {
            background: linear-gradient(180deg, #4fb3ff 0%, #1e78f0 60%, #0f5fd6 100%);
            box-shadow: 0 0 12px rgba(63, 160, 255, 0.85), inset 0 0 8px rgba(255, 255, 255, 0.25);
            animation: screenGlow 2.2s ease-in-out infinite;
        }

        @keyframes screenGlow {
            0%, 100% { box-shadow: 0 0 10px rgba(63, 160, 255, 0.7), inset 0 0 8px rgba(255, 255, 255, 0.22); }
            50% { box-shadow: 0 0 18px rgba(63, 160, 255, 1), inset 0 0 12px rgba(255, 255, 255, 0.35); }
        }

        .ws-monitor-stand {
            width: 10px;
            height: 12px;
            margin: 0 auto;
            background: linear-gradient(90deg, #e5e5e2, #bfbfbc);
        }

        .ws-monitor-base {
            width: 40px;
            height: 5px;
            margin: 0 auto;
            background: #ddddda;
            border-radius: 3px;
            box-shadow: 0 2px 3px rgba(45, 45, 42, 0.14);
        }

        /* Agent 名称固定显示在角色头顶，只展示名称，不展示职位。 */
        .ws-name {
            position: absolute;
            left: 50%;
            top: -34px;
            transform: translateX(-50%);
            white-space: nowrap;
            font-size: 14px;
            line-height: 1.2;
            font-weight: 500;
            color: #111111;
            text-shadow: 0 1px 2px #ffffff, 0 0 5px #ffffff;
            z-index: 6;
        }

        .workstation.ws-active .ws-name {
            color: #111111;
        }

        /* ---------- 人物资料卡 ---------- */
        .office-agent-card {
            position: absolute;
            z-index: 120;
            width: min(330px, calc(100% - 24px));
            box-sizing: border-box;
            padding: 25px 24px 22px;
            border: 1px solid rgba(31, 31, 29, 0.06);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.98);
            color: #252523;
            box-shadow: 0 16px 44px rgba(42, 42, 38, 0.16), 0 2px 8px rgba(42, 42, 38, 0.08);
            opacity: 0;
            transform: translateY(7px) scale(0.98);
            transform-origin: top center;
            transition: opacity 0.16s ease, transform 0.16s ease;
        }

        .office-agent-card[hidden] {
            display: none;
        }

        .office-agent-card.show {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        .office-agent-card-close {
            position: absolute;
            top: 9px;
            right: 10px;
            width: 28px;
            height: 28px;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: transparent;
            color: #a09f9b;
            font: 22px/26px -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            cursor: pointer;
        }

        .office-agent-card-close:hover,
        .office-agent-card-close:focus-visible {
            background: #f4f4f2;
            color: #4a4a47;
            outline: none;
        }

        .office-agent-card-header {
            display: flex;
            align-items: center;
            gap: 14px;
            min-height: 78px;
            padding-right: 18px;
        }

        .office-agent-avatar {
            flex: 0 0 auto;
            width: 76px;
            height: 76px;
            box-sizing: border-box;
            border: 1px solid #eeece9;
            border-radius: 50%;
            background: #fbfaf8;
            object-fit: cover;
            object-position: center 18%;
        }

        .office-agent-identity {
            min-width: 0;
        }

        .office-agent-name {
            margin: 0;
            color: #2b2a28;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        .office-agent-title {
            margin-top: 3px;
            color: #343330;
            font-size: 14px;
            line-height: 1.4;
        }

        .office-agent-status {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 3px;
            color: #aaa9a5;
            font-size: 13px;
            line-height: 1.4;
        }

        .office-agent-status-dot {
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: #c8c8c4;
            box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.02);
        }

        .office-agent-status.is-active {
            color: #2787f5;
        }

        .office-agent-status.is-active .office-agent-status-dot {
            background: #2787f5;
            box-shadow: 0 0 0 3px rgba(39, 135, 245, 0.12);
        }

        .office-agent-card-divider {
            height: 1px;
            margin: 20px 0 20px;
            background: #ecebe8;
        }

        .office-agent-section + .office-agent-section {
            margin-top: 17px;
        }

        .office-agent-section-label {
            margin-bottom: 7px;
            color: #aaa9a5;
            font-size: 13px;
            line-height: 1.4;
        }

        .office-agent-summary {
            margin: 0;
            color: #343330;
            font-size: 14px;
            line-height: 1.6;
        }

        .office-agent-skills {
            display: flex;
            flex-wrap: wrap;
            gap: 7px 8px;
        }

        .office-agent-skill {
            display: inline-flex;
            align-items: center;
            min-height: 25px;
            box-sizing: border-box;
            padding: 3px 10px;
            border-radius: 7px;
            background: #f5efec;
            color: #9a7161;
            font-size: 12px;
            line-height: 1.35;
            white-space: nowrap;
        }

        @media (max-width: 620px) {
            .office-agent-card {
                padding: 22px 20px 20px;
            }

            .office-agent-avatar {
                width: 68px;
                height: 68px;
            }
        }

        /* ---------- MoonYa 行走精灵（舞台层） ---------- */
        .office-walker {
            position: absolute;
            width: 105px;
            z-index: 90;
            transform: translate(-50%, -100%);
            transition-property: left, top;
            transition-duration: 520ms;
            transition-timing-function: cubic-bezier(0.45, 0.05, 0.55, 0.95);
            pointer-events: none;
            display: none;
        }

        .office-walker.walking {
            display: block;
        }

        .office-walker.facing-left { --walk-direction: -1; }
        .office-walker.facing-right { --walk-direction: 1; }

        /* 名称属于行走容器，因此移动和切换步行动画帧时会一直跟在头顶。 */
        .office-walker-name {
            position: absolute;
            left: 50%;
            top: -24px;
            transform: translateX(-50%);
            width: max-content;
            white-space: nowrap;
            font-size: 14px;
            line-height: 1.2;
            font-weight: 500;
            color: #1f1f1f;
            text-shadow: 0 1px 2px rgba(255, 255, 255, 0.95), 0 0 5px rgba(255, 255, 255, 0.85);
            z-index: 1;
        }

        .office-walker img {
            width: 105px;
            height: auto;
            display: block;
            filter: drop-shadow(0 8px 9px rgba(42, 42, 40, 0.20));
            animation: walkBob 0.55s ease-in-out infinite;
            transform-origin: center bottom;
        }

        .office-walker.idle img {
            animation: none;
        }

        @keyframes walkBob {
            0%, 100% { transform: translateY(0) scaleX(var(--walk-direction, 1)) rotate(-1.2deg); }
            50% { transform: translateY(-7px) scaleX(var(--walk-direction, 1)) rotate(1.2deg); }
        }

        /* ---------- 消息气泡 ---------- */
        .office-bubble {
            position: absolute;
            z-index: 95;
            max-width: 240px;
            background: #ffffff;
            color: #252523;
            font-size: 12.5px;
            line-height: 1.55;
            padding: 10px 14px;
            border-radius: 14px;
            border: 1px solid #dededb;
            box-shadow: 0 8px 24px rgba(45, 45, 42, 0.14);
            opacity: 0;
            transform: translate(-50%, -100%) scale(0.85);
            transform-origin: bottom center;
            transition: opacity 0.28s ease, transform 0.28s ease;
            pointer-events: none;
            word-break: break-word;
        }

        .office-bubble.show {
            opacity: 1;
            transform: translate(-50%, -100%) scale(1);
        }

        .office-bubble::after {
            content: '';
            position: absolute;
            left: 50%;
            bottom: -7px;
            width: 12px;
            height: 12px;
            background: #ffffff;
            border-right: 1px solid #dededb;
            border-bottom: 1px solid #dededb;
            transform: translateX(-50%) rotate(45deg);
        }

        .office-bubble .bubble-from {
            display: block;
            font-size: 11px;
            color: #2787f5;
            font-weight: 600;
            margin-bottom: 3px;
        }

        /* ---------- 底部输入框（复用对话区域样式体系） ---------- */
        .office-input-wrapper {
            flex: 0 0 auto;
            padding: 6px 24px 18px 24px;
            z-index: 60;
        }

        .office-input-wrapper .input-container {
            max-width: 860px;
            margin: 0 auto;
        }

        .office-input-wrapper .message-input {
            font-family: inherit;
        }

        .office-input-hint {
            max-width: 860px;
            margin: 6px auto 0;
            text-align: center;
            font-size: 11px;
            color: #9a93b5;
        }
