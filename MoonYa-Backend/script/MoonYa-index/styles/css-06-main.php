        /* 右侧主内容区 */
        .main-content {
            flex: 1;
            background-color: white;
            display: flex;
            flex-direction: column;
            padding: 40px 20px;
            position: relative;
            overflow: hidden;
            min-width: 0;
        }

        /* 顶部工具栏（详情开关 + 语音播报） */
        .top-toolbar {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 100;
            display: flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(0, 0, 0, 0.05);
            border-radius: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 2px 4px;
            transition: all 0.3s ease;
        }

        .top-toolbar:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .top-toolbar-item {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 5px;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #666;
            position: relative;
        }

        .top-toolbar-item:hover {
            background: #f5f7fa;
            color: #1890ff;
        }

        .top-toolbar-item.active {
            background: #e6f7ff;
            color: #1890ff;
        }

        .top-toolbar-divider {
            width: 1px;
            height: 18px;
            background: #e8e8e8;
            margin: 0 2px;
            flex-shrink: 0;
        }

        .detail-toggle-icon {
            width: 18px;
            height: 18px;
            display: block;
            pointer-events: none;
        }

        .voice-toggle-icon {
            width: 18px;
            height: 18px;
            display: block;
            transition: all 0.3s ease;
            pointer-events: none;
        }

        /* 工具栏提示框 */
        .top-toolbar-item::after {
            content: attr(data-tooltip);
            position: absolute;
            right: calc(100% + 10px);
            top: 50%;
            transform: translateY(-50%) scale(0.8);
            background: white;
            color: #333;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            z-index: 101;
        }

        .top-toolbar-item::before {
            content: '';
            position: absolute;
            right: calc(100% + 4px);
            top: 50%;
            transform: translateY(-50%) scale(0.8);
            border: 6px solid transparent;
            border-left-color: white;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            z-index: 101;
        }

        .top-toolbar-item:hover::after,
        .top-toolbar-item:hover::before {
            opacity: 1;
            visibility: visible;
            transform: translateY(-50%) scale(1);
        }

        /* 操作详情面板（右侧，与左侧栏一致：240px / #f5f7fa） */
        .detail-panel {
            flex-shrink: 0;
            background-color: #f5f7fa;
            display: flex;
            flex-direction: column;
            height: 100vh;
            box-sizing: border-box;
            overflow: hidden;
            transform: translateX(100%);
            width: 0;
            padding: 0;
            border-left: none;
            transition: transform 0.3s ease, width 0.3s ease, padding 0.3s ease, border 0.3s ease;
        }

        .detail-panel.open {
            transform: translateX(0);
            width: 240px;
            padding: 10px 0 0 0;
            border-left: 1px solid #e8e8e8;
        }

        /* 主对话与工作日志之间的可拖拽分割边 */
        .detail-panel-resizer {
            position: relative;
            z-index: 120;
            width: 0;
            height: 100vh;
            flex: 0 0 0;
            align-self: stretch;
            overflow: visible;
            opacity: 0;
            pointer-events: none;
            cursor: col-resize;
            touch-action: none;
            outline: none;
            transition: width 0.3s ease, flex-basis 0.3s ease, opacity 0.2s ease;
        }

        body.detail-panel-open .detail-panel-resizer {
            width: 1px;
            flex-basis: 1px;
            opacity: 1;
            pointer-events: auto;
        }

        .detail-panel-resizer::before {
            content: '';
            position: absolute;
            inset: 0 -7px;
        }

        .detail-panel-resizer::after {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 1px;
            background: #e8e8e8;
        }

        body.is-resizing-detail-panel,
        body.is-resizing-detail-panel * {
            cursor: col-resize !important;
            user-select: none !important;
        }

        body.is-resizing-detail-panel .sidebar,
        body.is-resizing-detail-panel .detail-panel,
        body.is-resizing-detail-panel .detail-panel-resizer {
            transition: none !important;
        }

        .detail-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 12px 10px;
            border-bottom: 1px solid #e8e8e8;
            margin-bottom: 6px;
        }

        .detail-panel-title {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }

        .detail-panel-clear {
            font-size: 12px;
            color: #999;
            cursor: pointer;
            padding: 2px 8px;
            border-radius: 6px;
            transition: all 0.2s ease;
            user-select: none;
        }

        .detail-panel-clear:hover {
            background: #f1f3f4;
            color: #333;
        }

        .detail-panel-content {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 6px 10px 12px;
            scrollbar-width: thin;
            -ms-overflow-style: none;
        }

        .detail-panel-content::-webkit-scrollbar {
            display: none;
        }

        .detail-panel-empty {
            color: #999;
            font-size: 13px;
            text-align: center;
            padding: 24px 0;
        }

        /* 单条操作详情卡片 */
        .detail-entry {
            background: #ffffff;
            border: 1px solid #ececec;
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 8px;
            font-size: 12px;
            color: #333;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
            word-wrap: break-word;
            word-break: break-all;
        }

        .detail-entry-header {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
            font-weight: 600;
            color: #1a1a1a;
        }

        .detail-entry-header .detail-entry-icon {
            font-size: 14px;
        }

        .detail-entry-header .detail-entry-name {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .detail-entry-header .detail-entry-time {
            font-size: 11px;
            color: #999;
            font-weight: 400;
            flex-shrink: 0;
        }

        .detail-entry-row {
            display: flex;
            margin: 2px 0;
            line-height: 1.5;
        }

        .detail-entry-key {
            color: #666;
            min-width: 48px;
            flex-shrink: 0;
        }

        .detail-entry-val {
            color: #333;
            flex: 1;
            min-width: 0;
            word-break: break-all;
        }

        .detail-entry-val.success {
            color: #52c41a;
            font-weight: 600;
        }

        .detail-entry-val.failure {
            color: #ff4d4f;
            font-weight: 600;
        }

        .detail-entry-val.mono {
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 11px;
        }

        /* 详情面板中的截图缩略图（浏览器自动化） */
        .detail-entry-screenshot {
            margin-top: 6px;
            cursor: pointer;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #ececec;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .detail-entry-screenshot:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .detail-entry-screenshot img {
            display: block;
            width: 100%;
            height: auto;
            max-height: 180px;
            object-fit: cover;
        }

        /* 详情截图灯箱（全屏查看） */
        .detail-lightbox {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .detail-lightbox.show { display: flex; }
        .detail-lightbox-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.9);
        }
        .detail-lightbox-img {
            position: relative;
            max-width: 90vw;
            max-height: 85vh;
            object-fit: contain;
            z-index: 1;
        }
        .detail-lightbox-close {
            position: fixed;
            top: 16px;
            right: 16px;
            background: transparent;
            border: none;
            color: #ffffff;
            font-size: 28px;
            line-height: 1;
            cursor: pointer;
            z-index: 2;
            padding: 8px 12px;
            opacity: 0.8;
            transition: opacity 0.2s ease;
        }
        .detail-lightbox-close:hover { opacity: 1; }
        .messages-container {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 0;
            padding: 0 0 56px;
            max-height: calc(100vh - 200px);
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE and Edge */
            width: 100%;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
            box-sizing: border-box;
        }
        
        /* 隐藏滚动条 */
        .messages-container::-webkit-scrollbar {
            display: none; /* Chrome, Safari and Opera */
        }

        .network-recovery-notice {
            align-self: center;
            margin: 8px auto;
            padding: 7px 12px;
            border: 1px solid rgba(99, 102, 241, 0.28);
            border-radius: 999px;
            color: #4f46e5;
            background: rgba(238, 242, 255, 0.94);
            font-size: 12px;
            line-height: 1.4;
        }
        
        .input-container-wrapper {
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100%;
            max-width: 800px;
            z-index: 10;
            padding: 6px 20px;
            background-color: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-sizing: border-box;
        }

        /* 全屏显示模式：对话区域占满整个主区 */
        body.chat-fullscreen .messages-container {
            max-width: none;
            margin-left: 0;
            margin-right: 0;
        }

        body.chat-fullscreen .input-container-wrapper {
            left: 0;
            right: 0;
            transform: none;
            max-width: none;
        }

        body.chat-fullscreen .message.user {
            margin-left: 50%;
        }

        body.chat-fullscreen .message.user .message-content {
            max-width: 100%;
        }
        
        .message {
            margin-bottom: 8px;
            display: flex;
            flex-direction: column;
        }
        
        .message.user {
            align-items: flex-end;
        }

        .message.ai {
            align-items: flex-start;
            margin-right: 0;
            width: 100%;
            padding-left: 20px;
            padding-right: 20px;
            box-sizing: border-box;
        }

        .message-sender {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
            color: #8c8c8c;
            padding: 0;
            margin-bottom: 2px;
            line-height: 1.4;
            user-select: text;
        }

.message-sender::before {
            content: '';
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #999999;
            margin-left: 0;
        }

        .message-content {
            max-width: 100%;
            padding: 12px 16px;
            border-radius: 16px;
            line-height: 1.4;
            word-wrap: break-word;
            white-space: pre-wrap;
        }

        .message.user .message-content {
            background-color: #e6f7ff;
            color: #333;
            border-bottom-right-radius: 4px;
            max-width: 75%;
        }
        
        .message.ai .message-content {
            background-color: transparent;
            color: #333;
            border-radius: 0;
            padding: 8px 0;
            width: 100%;
            box-sizing: border-box;
        }
        
        .message.ai .message-content h1,
        .message.ai .message-content h2,
        .message.ai .message-content h3,
        .message.ai .message-content h4 {
            color: #1a1a1a;
            font-weight: 700;
            margin-top: 16px;
            margin-bottom: 8px;
            line-height: 1.4;
        }
        .message.ai .message-content h1 { font-size: 20px; }
        .message.ai .message-content h2 { font-size: 18px; }
        .message.ai .message-content h3 { font-size: 16px; }
        .message.ai .message-content h4 { font-size: 15px; }
        .message.ai .message-content h1:first-child,
        .message.ai .message-content h2:first-child,
        .message.ai .message-content h3:first-child,
        .message.ai .message-content h4:first-child {
            margin-top: 0;
        }
        
        .message.ai .message-content strong,
        .message.ai .message-content b {
            font-weight: 700;
            font-size: 15px;
            color: #1a1a1a;
        }
        
        .message.ai .message-content ul,
        .message.ai .message-content ol {
            padding-left: 20px;
            margin: 8px 0;
        }
        .message.ai .message-content ul { list-style-type: disc; }
        .message.ai .message-content ol { list-style-type: decimal; }
        .message.ai .message-content li {
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 4px;
        }
        .message.ai .message-content li::marker {
            color: #666;
        }
        
        .message.ai .message-content p {
            margin: 6px 0;
            line-height: 1.6;
        }
        .message.ai .message-content p:first-child {
            margin-top: 0;
        }
        .message.ai .message-content p:last-child {
            margin-bottom: 0;
        }
        
        .message.ai .message-content hr {
            border: none;
            border-top: 1px solid #e0e0e0;
            margin: 12px 0;
        }
        
        .message.ai .message-content blockquote {
            border-left: 3px solid #ddd;
            padding-left: 12px;
            margin: 8px 0;
            color: #666;
        }
        
        .message.ai .message-content table {
            border-collapse: separate;
            border-spacing: 0;
            margin: 8px 0;
            font-size: 14px;
            width: 100%;
            max-width: 100%;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        
        .message.ai .message-content th,
        .message.ai .message-content td {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
        }
        
        .message.ai .message-content thead th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 13px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .message.ai .message-content tbody tr:hover {
            background: #f8f9fa;
        }
        
        .message.ai .message-content tbody tr:last-child td {
            border-bottom: none;
        }
        
        .message.ai .message-content:has(.music-card-container) {
            width: 340px;
        }
        
        .message.ai .message-content:has(.horoscope-card-container) {
            width: 360px;
        }
        .message.ai .message-content:has(.weather-card-container) {
            width: auto;
        }
        
        .loading-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #999;
            font-size: 14px;
        }
        
        .loading-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #999;
            animation: pulse 1.5s infinite ease-in-out;
        }
        
        .loading-dot:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .loading-dot:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(0.8);
                opacity: 0.6;
            }
            50% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        /* AI思考过程文字样式 - 淡灰色小字体 */
        .thinking-wrapper {
            margin-top: 8px;
            background-color: transparent;
            border: none;
            border-radius: 0;
            padding: 0 0 0 22px;
        }
        
        .thinking-header {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            margin-bottom: 8px;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }
        
        .thinking-toggle {
            color: #999;
            font-size: 12px;
            transition: transform 0.2s;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            transform: rotate(-90deg);
        }
        
        .thinking-toggle.expanded {
            transform: rotate(0deg);
        }
        
        .thinking-label {
            color: #666;
            font-size: 12px;
            font-weight: 500;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            position: relative;
            overflow: hidden;
        }
        
        .thinking-text {
            color: #999;
            font-size: 12px;
            line-height: 1.5;
            overflow: hidden;
            transition: max-height 0.3s ease, opacity 0.3s ease;
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: anywhere;
            word-break: break-word;
            max-height: 0;
            opacity: 0;
            padding: 0;
        }
        
        .thinking-text.expanded {
            max-height: 400px;
            overflow-y: auto;
            opacity: 1;
            padding: 8px 0;
        }
        
        .thinking-text a {
            color: #999 !important;
            text-decoration: underline;
            font-size: 12px;
        }
        
        .message-content a {
            color: #666666;
            text-decoration: underline;
        }
        
        .thinking-text.collapsed {
            max-height: 0;
            opacity: 0;
            margin-bottom: 0;
        }
        
        .thinking-completed {
            display: flex;
            align-items: center;
            margin-top: 8px;
            padding-top: 8px;
        }
        
        .thinking-completed.collapsed {
            display: none;
        }

        /* 搜索结果折叠菜单（复用 thinking-wrapper 模式，默认收起） */
        .search-result-wrapper {
            margin-top: 8px;
            background-color: transparent;
            border: none;
            border-radius: 0;
            padding: 0 0 0 22px;
        }

        .search-result-header {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            margin-bottom: 4px;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }

        /* ==================== Push-to-Talk 语音状态栏 ==================== */
        .ptt-status-bar {
            display: none;
            align-items: center;
            min-height: 32px;
            padding: 4px 16px;
            border-top: 1px solid #f0f0f0;
            background-color: #fafafa;
            border-radius: 0;
            transition: background-color 0.25s ease;
        }

        .ptt-status-bar[data-ptt-state="idle"] { background-color: #fafafa; }
        .ptt-status-bar[data-ptt-state="recording"] { background-color: #fff1f0; }
        .ptt-status-bar[data-ptt-state="confirming"] { background-color: #f0fff4; }

        /* 仅在功能启用时显示 */
        .ptt-enabled .ptt-status-bar { display: flex; }

        .ptt-status-content {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            font-size: 13px;
            line-height: 1.4;
        }

        .ptt-state {
            display: none;
            align-items: center;
            gap: 10px;
            width: 100%;
        }

        .ptt-status-bar[data-ptt-state="idle"] .ptt-state-idle { display: flex; }
        .ptt-status-bar[data-ptt-state="recording"] .ptt-state-recording { display: flex; }
        .ptt-status-bar[data-ptt-state="confirming"] .ptt-state-confirming { display: flex; }

        .ptt-icon {
            font-size: 14px;
            line-height: 1;
        }
        .ptt-icon-idle { color: #999; }
        .ptt-icon-confirming { color: #52c41a; font-weight: bold; }

        .ptt-text { color: #555; }
        .ptt-hint {
            color: #999;
            font-size: 12px;
            margin-left: auto;
        }

        .ptt-timer {
            font-variant-numeric: tabular-nums;
            color: #ff4444;
            font-weight: 600;
        }

        /* ── 脉冲圆环（recording 状态） ── */
        .voice-ring {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #ff4444;
            animation: voice-pulse 1.5s ease-in-out infinite;
            flex-shrink: 0;
        }
        @keyframes voice-pulse {
            0%, 100% { transform: scale(1); opacity: 1; box-shadow: 0 0 0 0 rgba(255, 68, 68, 0.5); }
            50%      { transform: scale(1.3); opacity: 0.7; box-shadow: 0 0 0 6px rgba(255, 68, 68, 0); }
        }

        /* ── 波形条（recording 状态） ── */
        .voice-wave {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            height: 20px;
            flex-shrink: 0;
        }
        .voice-wave span {
            display: inline-block;
            width: 3px;
            height: 16px;
            background-color: #ff4444;
            border-radius: 2px;
            animation: voice-wave 0.8s ease-in-out infinite;
        }
        .voice-wave span:nth-child(1) { animation-delay: 0.0s; height: 12px; }
        .voice-wave span:nth-child(2) { animation-delay: 0.1s; height: 20px; }
        .voice-wave span:nth-child(3) { animation-delay: 0.2s; height: 10px; }
        .voice-wave span:nth-child(4) { animation-delay: 0.3s; height: 24px; }
        .voice-wave span:nth-child(5) { animation-delay: 0.4s; height: 16px; }
        @keyframes voice-wave {
            0%, 100% { transform: scaleY(0.4); }
            50%      { transform: scaleY(1); }
        }

        /* ── 工具栏 PTT 开关 / 模式切换按钮 ── */
        .ptt-toggle-btn,
        .ptt-mode-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            transition: background-color 0.2s ease, transform 0.15s ease;
            user-select: none;
        }
        .ptt-toggle-btn:hover,
        .ptt-mode-btn:hover {
            background-color: #f0f0f0;
        }
        .ptt-toggle-btn.active {
            background-color: #e6f7ff;
            color: #1890ff;
        }
        .ptt-toggle-btn.active .ptt-toggle-icon {
            color: #1890ff;
        }
        .ptt-mode-btn.active {
            background-color: #fff7e6;
        }
        .ptt-toggle-icon,
        .ptt-mode-icon {
            line-height: 1;
            color: #666;
        }

        /* ── Push-to-Talk / Voice Chat 输入区灵动光球 ── */
        #ptt-input-glow {
            --ptt-orb-scale: 1;
            --ptt-orb-opacity: 0.85;
            --ptt-orb-color-1: #A5F3FC;
            --ptt-orb-color-2: #93C5FD;
            --ptt-orb-color-3: #C4B5FD;
            --ptt-orb-color-4: #D8B4FE;
            --ptt-orb-color-5: #F9A8D4;
            position: absolute;
            top: 18px;
            left: 50%;
            transform: translate(-50%, -50%);
            display: none;
            align-items: center;
            justify-content: center;
            width: 440px;
            height: 110px;
            pointer-events: none;
            z-index: 0;
        }

        #ptt-input-glow.visible,
        body.voice-chat-mode #ptt-input-glow {
            display: flex;
        }

        /* 光球外层柔光 */
        .ptt-orb-glow {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 84px;
            height: 84px;
            border-radius: 50%;
            transform: translate(-50%, -50%) scale(var(--ptt-orb-scale));
            background: radial-gradient(circle at 40% 40%,
                rgba(255, 255, 255, 0.95) 0%,
                var(--ptt-orb-color-1) 18%,
                var(--ptt-orb-color-2) 38%,
                var(--ptt-orb-color-3) 58%,
                var(--ptt-orb-color-4) 78%,
                var(--ptt-orb-color-5) 100%);
            opacity: calc(var(--ptt-orb-opacity) * 0.55);
            filter: blur(18px);
            transition: transform 0.08s ease-out, opacity 0.08s ease-out;
            animation: orb-breathe 2.4s ease-in-out infinite;
        }

        /* 呼吸光环 */
        .ptt-orb-ring {
            position: absolute;
            top: 50%;
            left: 50%;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
        }

        .ptt-orb-ring.ring-1 {
            width: 110px;
            height: 110px;
            border: 1.5px solid rgba(165, 243, 252, 0.35);
            box-shadow:
                0 0 14px rgba(165, 243, 252, 0.25),
                inset 0 0 14px rgba(147, 197, 253, 0.18);
            animation: orb-ring-ripple 2.4s ease-in-out infinite;
        }

        .ptt-orb-ring.ring-2 {
            width: 150px;
            height: 150px;
            border: 1px solid rgba(196, 181, 253, 0.28);
            box-shadow:
                0 0 18px rgba(196, 181, 253, 0.20),
                inset 0 0 18px rgba(249, 168, 212, 0.12);
            animation: orb-ring-ripple 2.4s ease-in-out infinite 0.8s;
        }

        /* 光球核心 */
        .ptt-orb-core {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 64px;
            height: 64px;
            border-radius: 50%;
            transform: translate(-50%, -50%) scale(var(--ptt-orb-scale));
            background:
                radial-gradient(circle at 35% 30%,
                    rgba(255, 255, 255, 0.92) 0%,
                    rgba(255, 255, 255, 0) 28%),
                radial-gradient(circle at 50% 50%,
                    var(--ptt-orb-color-1) 0%,
                    var(--ptt-orb-color-2) 28%,
                    var(--ptt-orb-color-3) 54%,
                    var(--ptt-orb-color-4) 76%,
                    var(--ptt-orb-color-5) 100%);
            opacity: var(--ptt-orb-opacity);
            box-shadow:
                0 0 22px rgba(165, 243, 252, 0.72),
                0 0 44px rgba(147, 197, 253, 0.58),
                0 0 68px rgba(196, 181, 253, 0.46),
                0 0 96px rgba(249, 168, 212, 0.34),
                inset 0 0 20px rgba(255, 255, 255, 0.35);
            transition: transform 0.08s ease-out, opacity 0.08s ease-out;
            animation: orb-breathe 2.4s ease-in-out infinite;
        }

        /* 内部声纹 */
        .ptt-orb-waves {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            width: 42px;
            height: 42px;
            pointer-events: none;
            z-index: 1;
        }

        .ptt-orb-waves span {
            width: 4px;
            height: 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.82);
            box-shadow: 0 0 6px rgba(255, 255, 255, 0.55);
            transform-origin: center;
            animation: orb-wave-dance 1.1s ease-in-out infinite;
        }

        .ptt-orb-waves span:nth-child(1) { animation-delay: 0s; height: 10px; }
        .ptt-orb-waves span:nth-child(2) { animation-delay: 0.12s; height: 14px; }
        .ptt-orb-waves span:nth-child(3) { animation-delay: 0.24s; height: 18px; }
        .ptt-orb-waves span:nth-child(4) { animation-delay: 0.36s; height: 14px; }
        .ptt-orb-waves span:nth-child(5) { animation-delay: 0.48s; height: 10px; }

        /* 录音时隐藏输入框占位符，避免与流光指示器重叠 */
        .message-input.ptt-recording::placeholder {
            color: transparent;
            opacity: 0;
        }

        @keyframes orb-breathe {
            0%, 100% {
                transform: translate(-50%, -50%) scale(calc(var(--ptt-orb-scale) * 0.92));
                opacity: calc(var(--ptt-orb-opacity) * 0.82);
            }
            50% {
                transform: translate(-50%, -50%) scale(calc(var(--ptt-orb-scale) * 1.06));
                opacity: var(--ptt-orb-opacity);
            }
        }

        @keyframes orb-ring-ripple {
            0% {
                transform: translate(-50%, -50%) scale(0.86);
                opacity: 0.55;
            }
            50% {
                transform: translate(-50%, -50%) scale(1.05);
                opacity: 0.28;
            }
            100% {
                transform: translate(-50%, -50%) scale(0.86);
                opacity: 0.55;
            }
        }

        @keyframes orb-wave-dance {
            0%, 100% { transform: scaleY(0.7); opacity: 0.7; }
            50% { transform: scaleY(1.35); opacity: 1; }
        }

        /* ────────────────────────────────────────────────
           实时语音对话开关（Voice Chat Toggle）
           ── UI 与文件卡片 .file-card 保持一致
           ── 位于麦克风按钮左侧，开启后进入实时人机语音交互模式
           ──────────────────────────────────────────────── */

        .voice-chat-card {
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            margin-right: 10px;       /* 与右侧 #voiceBtn 保持 10px 间距 */
            font-size: 12px;
            color: #999;
            background-color: white;
            border: 1px solid #e8e8e8;
            padding: 4px 10px;
            border-radius: 12px;
            gap: 6px;
            transition: all 0.3s;
            outline: none;
            position: relative;
            z-index: 10;
        }

        .voice-chat-card:hover {
            background-color: #f5f7fa;
        }

        .voice-chat-card.active {
            color: #1890ff;
            border-color: #1890ff;
            background-color: rgba(24, 144, 255, 0.1);
        }

        .voice-chat-card-info {
            display: flex;
            align-items: center;
        }

        .voice-chat-card-name {
            font-size: 12px;
        }

        .voice-chat-card.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }

        .voice-chat-card:focus-visible {
            outline: 2px solid #93C5FD;
            outline-offset: 2px;
        }

        /* ────────────────────────────────────────────────
           实时语音对话模式期间 #ptt-input-glow 节奏动画
           ── 进入语音对话模式时 body 加 .voice-chat-mode 类
           ── 通过 .vc-listening / .vc-ai-speaking / .vc-capturing 切换光效状态
           ──────────────────────────────────────────────── */

        /* 语音对话模式：#ptt-input-glow 默认显示，不受 PTT 按键状态影响 */
        body.voice-chat-mode #ptt-input-glow {
            display: flex;
        }

        /* 监听态：慢呼吸，提示用户可以说话 */
        #ptt-input-glow.vc-listening .ptt-orb-core,
        #ptt-input-glow.vc-listening .ptt-orb-glow {
            animation: orb-breathe-listening 3s ease-in-out infinite;
        }
        #ptt-input-glow.vc-listening .ptt-orb-ring.ring-1 {
            animation: orb-ring-ripple-listening 3s ease-in-out infinite;
        }
        #ptt-input-glow.vc-listening .ptt-orb-ring.ring-2 {
            animation: orb-ring-ripple-listening 3s ease-in-out infinite 0.9s;
        }

        /* 捕获态：声纹活跃起伏，核心轻微高频脉冲 */
        #ptt-input-glow.vc-capturing .ptt-orb-core,
        #ptt-input-glow.vc-capturing .ptt-orb-glow {
            animation: orb-pulse-capturing 0.85s ease-in-out infinite;
        }
        #ptt-input-glow.vc-capturing .ptt-orb-ring.ring-1 {
            animation: orb-ring-ripple-capturing 0.85s ease-in-out infinite;
        }
        #ptt-input-glow.vc-capturing .ptt-orb-ring.ring-2 {
            animation: orb-ring-ripple-capturing 0.85s ease-in-out infinite 0.28s;
        }
        #ptt-input-glow.vc-capturing .ptt-orb-waves span {
            animation-duration: 0.55s;
            background: rgba(255, 255, 255, 0.95);
        }

        /* AI 说话态：快速脉冲，颜色偏紫粉 */
        #ptt-input-glow.vc-ai-speaking .ptt-orb-core,
        #ptt-input-glow.vc-ai-speaking .ptt-orb-glow {
            animation: orb-pulse-ai 0.8s ease-in-out infinite;
        }
        #ptt-input-glow.vc-ai-speaking .ptt-orb-ring.ring-1 {
            animation: orb-ring-ripple-ai 0.8s ease-in-out infinite;
        }
        #ptt-input-glow.vc-ai-speaking .ptt-orb-ring.ring-2 {
            animation: orb-ring-ripple-ai 0.8s ease-in-out infinite 0.27s;
        }
        #ptt-input-glow.vc-ai-speaking .ptt-orb-waves span {
            animation-duration: 0.45s;
            background: rgba(255, 255, 255, 0.95);
        }

        @keyframes orb-breathe-listening {
            0%, 100% {
                transform: translate(-50%, -50%) scale(calc(var(--ptt-orb-scale) * 0.9));
                opacity: calc(var(--ptt-orb-opacity) * 0.72);
            }
            50% {
                transform: translate(-50%, -50%) scale(calc(var(--ptt-orb-scale) * 1.04));
                opacity: calc(var(--ptt-orb-opacity) * 0.92);
            }
        }

        @keyframes orb-pulse-capturing {
            0%, 100% {
                transform: translate(-50%, -50%) scale(var(--ptt-orb-scale));
                opacity: var(--ptt-orb-opacity);
            }
            50% {
                transform: translate(-50%, -50%) scale(calc(var(--ptt-orb-scale) * 1.1));
                opacity: calc(var(--ptt-orb-opacity) * 1.05);
            }
        }

        @keyframes orb-pulse-ai {
            0%, 100% {
                transform: translate(-50%, -50%) scale(calc(var(--ptt-orb-scale) * 0.96));
                opacity: calc(var(--ptt-orb-opacity) * 0.85);
                filter: hue-rotate(0deg);
            }
            50% {
                transform: translate(-50%, -50%) scale(calc(var(--ptt-orb-scale) * 1.14));
                opacity: calc(var(--ptt-orb-opacity) * 1.08);
                filter: hue-rotate(12deg);
            }
        }

        @keyframes orb-ring-ripple-listening {
            0% { transform: translate(-50%, -50%) scale(0.84); opacity: 0.5; }
            50% { transform: translate(-50%, -50%) scale(1.02); opacity: 0.24; }
            100% { transform: translate(-50%, -50%) scale(0.84); opacity: 0.5; }
        }

        @keyframes orb-ring-ripple-capturing {
            0% { transform: translate(-50%, -50%) scale(0.88); opacity: 0.6; }
            50% { transform: translate(-50%, -50%) scale(1.08); opacity: 0.32; }
            100% { transform: translate(-50%, -50%) scale(0.88); opacity: 0.6; }
        }

        @keyframes orb-ring-ripple-ai {
            0% { transform: translate(-50%, -50%) scale(0.9); opacity: 0.65; }
            50% { transform: translate(-50%, -50%) scale(1.12); opacity: 0.38; }
            100% { transform: translate(-50%, -50%) scale(0.9); opacity: 0.65; }
        }

        .search-result-toggle {
            color: #999;
            font-size: 12px;
            transition: transform 0.2s;
            transform: rotate(-90deg);
            display: flex;
            align-items: center;
        }

        .search-result-toggle.expanded {
            transform: rotate(0deg);
        }

        .search-result-label {
            color: #666;
            font-size: 12px;
            font-weight: 500;
        }

        .search-result-text {
            color: #999;
            font-size: 12px;
            line-height: 1.6;
            overflow: hidden;
            transition: max-height 0.3s ease, opacity 0.3s ease;
            max-height: 0;
            opacity: 0;
            padding: 0;
        }

        .search-result-text.expanded {
            max-height: 5000px;
            opacity: 1;
            padding: 8px 0;
        }

        .search-result-item {
            margin-bottom: 6px;
        }

        .search-result-item a {
            color: #1890ff;
            text-decoration: underline;
            font-size: 12px;
        }

        .search-result-snippet {
            color: #aaa;
            font-size: 11px;
            display: block;
            margin-top: 2px;
        }

        /* ========== Multi-agent v1 / liquid glass work surface ========== */
        .team-panel {
            --team-ink: #18223a;
            --team-muted: #778198;
            --team-accent: #7466ff;
            --team-accent-2: #56b9ff;
            position: relative;
            background: linear-gradient(180deg, #f8faff 0%, #f2f5fa 100%);
            box-shadow: none;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            color: var(--team-ink);
            isolation: isolate;
        }

        .team-panel::before {
            content: none;
        }

        .detail-panel.open.team-panel {
            width: var(--team-panel-width, clamp(350px, 29vw, 430px));
            min-width: 350px;
            padding: 12px;
            border-left: none;
        }

        .team-panel .detail-panel-header {
            min-height: 52px;
            margin: 0 0 10px;
            padding: 8px 10px 10px;
            border: 1px solid rgba(255,255,255,.68);
            border-radius: 17px;
            background: linear-gradient(135deg, rgba(255,255,255,.65), rgba(255,255,255,.24));
            box-shadow:
                inset 0 1px rgba(255,255,255,.92),
                inset 0 -1px rgba(113,128,178,.08),
                0 13px 30px rgba(57,72,116,.10);
        }

        .detail-panel-kicker {
            display: block;
            color: #8779f6;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .14em;
            line-height: 1.1;
        }

        .team-panel .detail-panel-title {
            display: block;
            margin-top: 2px;
            color: var(--team-ink);
            font-size: 16px;
            letter-spacing: -.02em;
        }

        .detail-panel-actions,
        .team-live-indicator {
            display: flex;
            align-items: center;
        }

        .detail-panel-actions { gap: 5px; }

        .team-live-indicator {
            gap: 5px;
            margin-right: 2px;
            padding: 4px 8px;
            border: 1px solid rgba(255,255,255,.72);
            border-radius: 999px;
            background: rgba(255,255,255,.42);
            color: var(--team-muted);
            font-size: 10px;
            box-shadow: inset 0 1px rgba(255,255,255,.8);
        }

        .team-live-indicator i {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #9aa4b8;
            box-shadow: 0 0 0 3px rgba(154,164,184,.12);
        }

        .team-live-indicator.running i {
            background: #56c989;
            box-shadow: 0 0 0 3px rgba(86,201,137,.15), 0 0 12px rgba(86,201,137,.8);
            animation: team-live-pulse 1.8s ease-in-out infinite;
        }

        .team-panel .detail-panel-clear,
        .detail-panel-close {
            min-width: 29px;
            height: 29px;
            padding: 0 8px;
            border: 1px solid rgba(255,255,255,.7);
            border-radius: 10px;
            background: rgba(255,255,255,.38);
            color: #738096;
            font: inherit;
            font-size: 11px;
            cursor: pointer;
            box-shadow: inset 0 1px rgba(255,255,255,.85);
        }

        .detail-panel-close { padding: 0; font-size: 19px; line-height: 1; }
        .team-panel .detail-panel-clear:hover,
        .detail-panel-close:hover { color: #3f4961; background: rgba(255,255,255,.78); }

        .team-panel-tabs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4px;
            padding: 4px;
            margin-bottom: 10px;
            border: 1px solid rgba(255,255,255,.65);
            border-radius: 15px;
            background: rgba(218,225,242,.36);
            box-shadow: inset 0 1px 4px rgba(77,91,132,.08), 0 8px 22px rgba(55,71,115,.06);
        }

        .team-panel-tab {
            position: relative;
            min-height: 34px;
            border: 0;
            border-radius: 11px;
            background: transparent;
            color: #7d879d;
            font: inherit;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .team-panel-tab.selected {
            color: #3f3b75;
            background:
                linear-gradient(160deg, rgba(255,255,255,.92), rgba(249,248,255,.54)),
                radial-gradient(circle at 40% 0, rgba(132,111,255,.2), transparent 65%);
            box-shadow:
                inset 0 1px rgba(255,255,255,1),
                inset 0 -1px rgba(98,78,215,.10),
                0 7px 15px rgba(65,60,119,.13);
            transform: translateY(-1px);
        }

        .team-tab-count {
            display: inline-grid;
            min-width: 16px;
            height: 16px;
            margin-left: 2px;
            place-items: center;
            border-radius: 999px;
            background: rgba(116,102,255,.12);
            font-size: 9px;
        }

        .team-panel-panes,
        .team-panel-pane {
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        .team-panel-panes { display: flex; }
        .team-panel-pane { display: none; width: 100%; }
        .team-panel-pane.selected { display: flex; flex-direction: column; }

        .team-panel .detail-panel-content,
        .team-artifact-list,
        .team-preview {
            height: 100%;
            overflow-y: auto;
            padding: 2px 3px 12px;
            scrollbar-width: thin;
            scrollbar-color: rgba(117,126,157,.24) transparent;
        }

        .team-panel .detail-panel-empty {
            display: flex;
            min-height: 180px;
            padding: 30px 18px;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 7px;
            color: #929aad;
            font-size: 11px;
            line-height: 1.55;
            text-align: center;
        }

        .detail-panel-empty strong { color: #5e687d; font-size: 13px; }
        .team-empty-orb {
            width: 46px;
            height: 46px;
            margin-bottom: 7px;
            border: 1px solid rgba(255,255,255,.8);
            border-radius: 50%;
            background:
                radial-gradient(circle at 32% 26%, #fff 0 5%, transparent 20%),
                radial-gradient(circle at 62% 68%, rgba(76,199,255,.64), transparent 42%),
                linear-gradient(145deg, rgba(154,124,255,.76), rgba(107,160,255,.42));
            box-shadow: inset 0 0 14px rgba(255,255,255,.64), 0 12px 27px rgba(89,90,186,.22);
            transform: rotate(-8deg);
        }

        .team-event {
            position: relative;
            margin: 0 0 8px;
            padding: 10px 10px 10px 44px;
            border: 1px solid rgba(255,255,255,.66);
            border-radius: 15px;
            background: linear-gradient(142deg, rgba(255,255,255,.72), rgba(255,255,255,.34));
            box-shadow: inset 0 1px rgba(255,255,255,.82), 0 9px 22px rgba(63,76,117,.08);
            color: #465067;
            font-size: 11px;
            overflow: hidden;
        }

        .team-event::before {
            content: "";
            position: absolute;
            left: 23px;
            top: 0;
            bottom: -10px;
            width: 1px;
            background: linear-gradient(rgba(120,108,244,.42), rgba(120,108,244,.05));
        }

        .team-event:last-child::before { bottom: 50%; }
        .team-event.depth-1 { margin-left: 12px; }
        .team-event.depth-2 { margin-left: 25px; }

        .team-event-avatar {
            position: absolute;
            left: 9px;
            top: 10px;
            z-index: 1;
            width: 28px;
            height: 28px;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,.88);
            border-radius: 10px;
            background: #e6eaf3;
            box-shadow: 0 4px 10px rgba(53,67,108,.15);
        }

        .team-event-head {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 3px;
            cursor: pointer;
            list-style: none;
        }
        .team-event-head::-webkit-details-marker { display: none; }
        .team-event:not([open]) .team-event-head { margin-bottom: 0; }
        .team-event-avatar-fallback {
            display: grid;
            place-items: center;
            color: #6f66be;
            font-size: 11px;
            font-weight: 800;
        }

        .team-event-agent { color: #374159; font-weight: 700; }
        .team-event-kind {
            padding: 2px 6px;
            border-radius: 99px;
            background: rgba(112,99,240,.10);
            color: #786bd6;
            font-size: 9px;
        }
        .team-event-time { margin-left: auto; color: #a2a9b8; font-size: 9px; }
        .team-event-summary { line-height: 1.5; word-break: break-word; }
        .team-event-media {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 7px;
            margin-top: 9px;
        }
        .team-event-media-tile {
            position: relative;
            min-width: 0;
            min-height: 88px;
            padding: 0;
            overflow: hidden;
            border: 1px solid rgba(105, 96, 194, .16);
            border-radius: 10px;
            background: rgba(246, 247, 252, .82);
            cursor: zoom-in;
        }
        .team-event-media-tile img {
            display: block;
            width: 100%;
            height: 112px;
            object-fit: cover;
        }
        .team-event-media-tile:hover:not(.error) {
            border-color: rgba(105, 96, 194, .38);
            box-shadow: 0 7px 16px rgba(56, 65, 112, .14);
        }
        .team-event-media-tile.error {
            display: grid;
            place-items: center;
            cursor: default;
        }
        .team-event-media-placeholder {
            padding: 12px;
            color: #b34d62;
            font-size: 10px;
            line-height: 1.45;
        }
        .team-event-media-source {
            position: absolute;
            right: 5px;
            bottom: 5px;
            max-width: calc(100% - 10px);
            padding: 2px 5px;
            overflow: hidden;
            border-radius: 5px;
            background: rgba(30, 34, 50, .72);
            color: #fff;
            font-size: 8px;
            text-overflow: ellipsis;
            white-space: nowrap;
            pointer-events: none;
        }
        .team-event-task-list {
            display: grid;
            gap: 7px;
            margin: 9px 0 0;
            padding: 0;
            list-style: none;
        }
        .team-event-task-list li {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 4px 7px;
            align-items: start;
            padding: 8px 9px;
            border: 1px solid rgba(255,255,255,.7);
            border-radius: 11px;
            background: linear-gradient(135deg, rgba(255,255,255,.66), rgba(236,238,255,.32));
            box-shadow: inset 0 1px rgba(255,255,255,.82), 0 5px 13px rgba(71,78,135,.06);
        }
        .team-event-task-owner {
            padding: 2px 6px;
            border-radius: 999px;
            color: #675bc8;
            background: rgba(108,92,231,.11);
            font-size: 9px;
            font-weight: 750;
            white-space: nowrap;
        }
        .team-event-task-instruction { min-width: 0; line-height: 1.45; word-break: break-word; }
        .team-event-task-list small {
            grid-column: 2;
            color: #969daf;
            font-size: 9px;
        }
        .team-event.error { border-color: rgba(231,107,127,.32); }
        .team-event.error .team-event-kind { color: #c85a6f; background: rgba(231,107,127,.1); }
        .team-event.running { box-shadow: inset 0 1px rgba(255,255,255,.82), 0 10px 25px rgba(100,83,219,.13); }

        .team-approval-card {
            margin: 8px 0;
            padding: 13px;
            border: 1px solid rgba(255,181,86,.34);
            border-radius: 16px;
            background: linear-gradient(145deg, rgba(255,252,242,.84), rgba(255,242,221,.48));
            box-shadow: inset 0 1px rgba(255,255,255,.9), 0 12px 28px rgba(131,91,43,.12);
        }

        .team-approval-card strong { display: block; margin-bottom: 5px; color: #725333; font-size: 12px; }
        .team-approval-card p { margin: 0 0 10px; color: #8d7359; font-size: 11px; line-height: 1.5; }
        .team-approval-card.decided {
            padding: 9px 12px;
            border-color: rgba(128,137,163,.2);
            background: rgba(255,255,255,.48);
            box-shadow: inset 0 1px rgba(255,255,255,.78);
        }
        .team-approval-card.decided strong {
            margin: 0;
            overflow: hidden;
            color: #777f91;
            font-size: 10px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .team-approval-actions { display: flex; gap: 8px; }
        .team-approval-actions button {
            flex: 1;
            min-height: 31px;
            border: 1px solid rgba(119,100,229,.18);
            border-radius: 10px;
            background: rgba(255,255,255,.56);
            color: #6b617c;
            font: inherit;
            font-size: 11px;
            cursor: pointer;
        }
        .team-approval-actions .allow {
            color: white;
            border-color: transparent;
            background: linear-gradient(135deg, #8577f6, #668cef);
            box-shadow: 0 7px 15px rgba(97,81,210,.22), inset 0 1px rgba(255,255,255,.35);
        }
        .team-approval-actions button:disabled {
            cursor: wait;
            opacity: .62;
        }

        .team-artifact-group { margin-bottom: 14px; }
        .team-artifact-group-title {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 0 4px 7px;
            color: #697289;
            font-size: 11px;
            font-weight: 700;
        }
        .team-artifact-group-title img { width: 22px; height: 22px; border-radius: 8px; object-fit: cover; }
        .team-artifact-card {
            display: grid;
            grid-template-columns: 38px 1fr auto;
            gap: 10px;
            align-items: center;
            width: 100%;
            margin-bottom: 7px;
            padding: 9px;
            border: 1px solid rgba(255,255,255,.72);
            border-radius: 14px;
            background: linear-gradient(145deg, rgba(255,255,255,.74), rgba(255,255,255,.36));
            color: inherit;
            text-align: left;
            cursor: pointer;
            box-shadow: inset 0 1px rgba(255,255,255,.88), 0 7px 18px rgba(65,78,116,.07);
        }
        .team-artifact-card:hover { transform: translateY(-1px); box-shadow: inset 0 1px white, 0 11px 23px rgba(65,78,116,.12); }
        .team-artifact-icon {
            display: grid;
            width: 38px;
            height: 38px;
            place-items: center;
            border-radius: 12px;
            background: linear-gradient(145deg, rgba(130,111,250,.17), rgba(87,189,255,.12));
            color: #7368d0;
            font-size: 17px;
        }
        .team-artifact-name { display: block; max-width: 210px; overflow: hidden; color: #3e485e; font-size: 11px; font-weight: 700; text-overflow: ellipsis; white-space: nowrap; }
        .team-artifact-meta { display: block; margin-top: 2px; color: #929aad; font-size: 9px; }
        .team-artifact-open { color: #8b93a4; font-size: 17px; }

        .team-preview-card {
            min-height: 100%;
            padding: 12px;
            border: 1px solid rgba(255,255,255,.72);
            border-radius: 17px;
            background: rgba(255,255,255,.52);
            box-shadow: inset 0 1px white, 0 12px 30px rgba(62,75,116,.09);
        }
        .team-preview-header { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
        .team-preview-title { min-width: 0; flex: 1; color: #3f4960; font-size: 12px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .team-preview-open-native {
            padding: 5px 9px;
            border: 1px solid rgba(112,99,222,.18);
            border-radius: 9px;
            background: rgba(255,255,255,.62);
            color: #6e64b8;
            font: inherit;
            font-size: 10px;
            cursor: pointer;
        }
        .team-preview-media { display: block; max-width: 100%; margin: auto; border-radius: 12px; }
        .team-preview-frame { width: 100%; min-height: 58vh; border: 0; border-radius: 12px; background: white; }
        .team-preview-code {
            max-height: calc(100vh - 230px);
            margin: 0;
            overflow: auto;
            padding: 12px;
            border-radius: 12px;
            background: #172033;
            color: #dce6f7;
            font: 10.5px/1.6 ui-monospace, SFMono-Regular, Consolas, monospace;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .approval-mode-selector { position: relative; flex: 0 0 32px; margin-right: 0; }
        .approval-mode-button {
            display: flex;
            width: 32px;
            min-width: 32px;
            height: 32px;
            align-items: center;
            justify-content: center;
            gap: 0;
            padding: 0;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #62697c;
            font: inherit;
            font-size: 11px;
            cursor: pointer;
            box-shadow: none;
        }
        .approval-mode-button:hover,
        .approval-mode-selector.open .approval-mode-button { background: #f1f2f4; }
        .approval-mode-button svg { width: 16px; height: 16px; fill: none; stroke: #766bd0; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        #approvalModeButtonText,
        .approval-mode-caret { display: none; }
        .approval-mode-menu {
            position: absolute;
            left: 0;
            right: auto;
            bottom: calc(100% + 10px);
            z-index: 9500;
            display: none;
            width: 285px;
            padding: 7px;
            border: 1px solid rgba(255,255,255,.78);
            border-radius: 18px;
            background:
                radial-gradient(circle at 15% 0, rgba(136,116,255,.18), transparent 42%),
                rgba(245,247,253,.87);
            box-shadow: inset 0 1px white, 0 22px 55px rgba(40,50,84,.22);
            backdrop-filter: blur(25px) saturate(160%);
            -webkit-backdrop-filter: blur(25px) saturate(160%);
            transform-origin: bottom left;
        }
        .approval-mode-selector.open .approval-mode-menu { display: block; animation: team-menu-in .16s ease-out both; }
        .approval-mode-menu button {
            display: block;
            width: 100%;
            padding: 10px 11px;
            border: 0;
            border-radius: 12px;
            background: transparent;
            color: #4f586d;
            text-align: left;
            cursor: pointer;
        }
        .approval-mode-menu button:hover,
        .approval-mode-menu button.selected {
            background: linear-gradient(145deg, rgba(255,255,255,.86), rgba(248,247,255,.55));
            box-shadow: inset 0 1px white, 0 7px 16px rgba(68,62,125,.09);
        }
        .approval-mode-menu button.selected::after { content: "✓"; float: right; margin-top: -28px; color: #7568da; font-weight: 800; }
        .approval-menu-title { display: block; font-size: 12px; font-weight: 700; }
        .approval-menu-desc { display: block; margin-top: 3px; color: #8b93a4; font-size: 10px; line-height: 1.35; }
        .mcp-connection-block { margin-top: 5px; padding: 9px 5px 3px; border-top: 1px solid rgba(116,124,158,.13); }
        .mcp-connection-heading { margin: 0 5px 7px; color: #6d7486; font-size: 10px; font-weight: 800; letter-spacing: .04em; }
        .mcp-connection-list { display: grid; gap: 5px; }
        .mcp-connection-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: center; padding: 7px 8px; border-radius: 10px; background: rgba(255,255,255,.44); }
        .mcp-connection-name { min-width: 0; overflow: hidden; color: #555e72; font-size: 10px; font-weight: 700; text-overflow: ellipsis; white-space: nowrap; }
        .mcp-connection-state { display: block; margin-top: 2px; color: #969dac; font-size: 9px; font-weight: 400; }
        .mcp-connection-row button { padding: 5px 7px; border: 1px solid rgba(112,99,222,.17); border-radius: 8px; background: rgba(255,255,255,.7); color: #6d64b7; font: inherit; font-size: 9px; cursor: pointer; }
        .mcp-connection-empty { padding: 5px; color: #999fac; font-size: 9px; }

        @keyframes team-menu-in { from { opacity: 0; transform: translateY(7px) scale(.97); } to { opacity: 1; transform: none; } }
        @keyframes team-live-pulse { 50% { opacity: .58; transform: scale(.82); } }

        @media (max-width: 680px) {
            .approval-mode-button span:not(.approval-mode-caret) { display: none; }
            .approval-mode-menu { position: fixed; left: 12px; right: auto; bottom: 82px; width: calc(100vw - 24px); }
        }

        @supports not ((backdrop-filter: blur(4px)) or (-webkit-backdrop-filter: blur(4px))) {
            .team-panel { background: #f3f6fc; }
            .approval-mode-menu { background: #f7f8fc; }
            .approval-mode-button { background: transparent; }
        }

        @media (prefers-reduced-motion: reduce) {
            .team-panel *,
            .approval-mode-selector * {
                scroll-behavior: auto !important;
                animation: none !important;
                transition-duration: .01ms !important;
            }
        }

        .browser-security-overlay {
            position: fixed;
            inset: 0;
            z-index: 100000;
            display: grid;
            place-items: center;
            padding: 24px;
            background: rgba(31, 35, 54, .28);
            backdrop-filter: blur(18px) saturate(135%);
            -webkit-backdrop-filter: blur(18px) saturate(135%);
        }
        .browser-security-overlay[hidden] { display: none; }
        .browser-security-modal {
            width: min(520px, calc(100vw - 32px));
            max-height: min(720px, calc(100vh - 48px));
            overflow: auto;
            padding: 26px;
            border: 1px solid rgba(255,255,255,.72);
            border-radius: 24px;
            background: linear-gradient(145deg, rgba(255,255,255,.9), rgba(241,243,255,.7));
            box-shadow: inset 0 1px rgba(255,255,255,.95), 0 28px 80px rgba(35,39,70,.24);
            color: #30364a;
        }
        .browser-security-kicker { color: #766bd1; font-size: 10px; font-weight: 800; letter-spacing: .16em; }
        .browser-security-header h2 { margin: 8px 0 0; font-size: 22px; }
        #browserSecurityDescription { margin: 16px 0; color: #626a7f; line-height: 1.65; }
        .browser-security-facts { display: grid; grid-template-columns: max-content 1fr; gap: 8px 14px; margin: 0; padding: 14px; border-radius: 16px; background: rgba(255,255,255,.56); }
        .browser-security-facts dt { color: #8a90a1; font-size: 12px; }
        .browser-security-facts dd { min-width: 0; margin: 0; overflow-wrap: anywhere; font-size: 12px; font-weight: 650; }
        .browser-security-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 9px; margin-top: 20px; }
        .browser-security-actions button,
        .browser-security-manage,
        .browser-security-permission-row button {
            border: 1px solid rgba(112,99,222,.18);
            border-radius: 11px;
            padding: 9px 13px;
            background: rgba(255,255,255,.66);
            color: #535b70;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .browser-security-actions button[data-decision="allow_once"],
        .browser-security-actions button[data-decision="allow_always"] { background: #7166d6; color: #fff; }
        .browser-security-manage { margin-top: 14px; padding-left: 0; border: 0; background: transparent; color: #7166d6; }
        .browser-security-permissions { margin-top: 18px; padding-top: 16px; border-top: 1px solid rgba(100,108,145,.14); }
        .browser-security-permissions h3 { margin: 0 0 10px; font-size: 13px; }
        .browser-security-permission-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 9px 0; }
        .browser-security-permission-row span { min-width: 0; overflow-wrap: anywhere; font-size: 12px; }
        
