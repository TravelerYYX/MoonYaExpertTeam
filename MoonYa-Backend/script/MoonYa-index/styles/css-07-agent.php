        /* ── Agent Status Bar ── */
        .agent-status-bar {
            position: relative;
            display: grid;
            grid-template-columns: 16px 1fr;
            gap: 6px;
            margin-left: 22px;
            padding: 2px 0;
            font-size: 12px;
            white-space: normal;
            word-break: break-word;
            max-width: 100%;
            line-height: 1.5;
            text-align: left;
            animation: statusSlideIn 0.2s ease-out;
        }
        @keyframes statusSlideIn {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes statusSpin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }
        /* 图标与文本使用网格对齐，与 .message.ai 内容左边缘（22px）对齐 */
        .agent-status-bar .status-icon {
            position: static;
            grid-column: 1;
            display: flex;
            width: 16px;
            height: 16px;
            align-items: center;
            justify-content: center;
            color: #888888;
        }
        .agent-status-bar .status-icon svg {
            display: block;
        }

        /* 标签和详情文本支持换行（长选择器/URL 不溢出） */
        .agent-status-bar > span:not(.status-icon) {
            grid-column: 2;
            word-break: break-all;
        }
        /* 详情文本独占一行，与主标签严格左对齐 */
        .agent-status-bar .status-detail {
            grid-column: 2;
            display: block;
            font-size: 11px;
            color: #888888;
            margin-top: 2px;
            margin-left: 0;
            text-align: left;
        }
        /* executing */
        .agent-status-bar.status-executing,
        .agent-status-bar.status-success,
        .agent-status-bar.status-complete,
        .agent-status-bar.status-failure,
        .agent-status-bar.status-thinking,
        .agent-status-bar.status-done,
        .agent-status-bar.status-stopped,
        .agent-status-bar.status-error {
            color: #666666;
        }

        /* ── Workflow Timeline ── */
        .workflow-timeline {
            position: relative;
            margin: 8px 0;
            padding: 10px 12px 10px 22px;
            background: #fafafa;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            animation: statusSlideIn 0.25s ease-out;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            text-align: left;
        }
        .workflow-timeline-header {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #333333;
        }
        .workflow-timeline-header .workflow-header-text {
            color: #333333;
        }
        .workflow-steps {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .workflow-step {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #333333;
            cursor: default;
        }
        .workflow-node-icon {
            flex-shrink: 0;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888888;
        }
        .workflow-node-icon svg {
            display: block;
        }
        .workflow-step-content {
            flex: 1;
            min-width: 0;
        }
        .workflow-step-title {
            font-size: 13px;
            color: #333333;
            word-break: break-word;
        }
        .workflow-step.status-pending,
        .workflow-step.status-running,
        .workflow-step.status-success,
        .workflow-step.status-failed,
        .workflow-step.has-error {
            color: #333333;
        }
        .workflow-step.status-failed .workflow-step-title {
            color: #555555;
        }

        /* ── 工作流统计区（与步骤节点对齐） ── */
        .workflow-stats {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #eeeeee;
            font-size: 13px;
            color: #666666;
        }
        .workflow-stats-icon {
            flex-shrink: 0;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .workflow-stats-icon svg {
            display: block;
        }

        /* ── CU 截图卡片（作为时间线节点的一部分） ── */
        /* 兼容旧独立容器用法（已弃用） */
        .cu-screenshots {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 8px 0;
        }
        /* 时间线内的截图节点：icon + content 纵向布局 */
        .cu-step-node.cu-step-with-screenshot {
            align-items: flex-start;
        }
        .cu-screenshot-content {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 0;
        }
        .cu-screenshot-content .cu-step-title {
            font-size: 12px;
            color: #888888;
            font-weight: 500;
        }
        .cu-screenshot-card {
            display: block;
            width: 200px;
            max-width: 100%;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.10);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .cu-screenshot-card:hover {
            transform: scale(1.03);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.45);
        }
        .cu-screenshot-thumb {
            width: 100%;
            display: block;
            max-height: 140px;
            object-fit: cover;
        }
        .cu-screenshot-label {
            font-size: 11px;
            opacity: 0.7;
            padding: 4px;
            color: #ccc;
        }

        /* ── CU 步骤时间线 ── */
        .cu-timeline {
            position: relative;
            margin-top: 8px;
            padding-left: 4px;
            border-left: 2px solid #e0e0e0;
        }
        .cu-step-node {
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 0;
            color: #666666;
        }
        .cu-step-icon {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            line-height: 1;
            background: #f0f0f0;
            color: #888888;
            border: 1px solid #d0d0d0;
        }
        .cu-step-text {
            flex: 1;
            font-size: 12px;
            word-break: break-word;
        }
        /* 统一中性灰调 */
        .cu-step-screenshot .cu-step-icon,
        .cu-step-mouse .cu-step-icon,
        .cu-step-keyboard .cu-step-icon,
        .cu-step-thinking .cu-step-icon,
        .cu-step-complete .cu-step-icon {
            background: #f0f0f0;
            color: #666666;
            border-color: #c0c0c0;
        }
        .cu-step-thinking .cu-thinking-text {
            color: #888888;
            font-style: italic;
            white-space: pre-wrap;
        }
        /* 运行中脉冲 */
        .cu-step-running .cu-step-icon {
            animation: cu-pulse 1.4s ease-in-out infinite;
        }
        @keyframes cu-pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(150, 150, 150, 0.35); }
            50%      { box-shadow: 0 0 0 5px rgba(150, 150, 150, 0); }
        }
        .cu-step-done {
            color: #888888;
        }

        /* ── CU Plan-Act-Verify 计划卡片 ── */
        .cu-plan-card {
            margin: 8px 0 4px 0;
            padding: 10px 12px;
            background: #f7f7f7;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
        }
        .cu-plan-header {
            font-size: 13px;
            font-weight: 600;
            color: #555555;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .cu-plan-icon {
            font-size: 14px;
            color: #888888;
        }
        .cu-plan-steps {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .cu-plan-step {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            background: #ffffff;
            border-left: 3px solid #d0d0d0;
            transition: all 0.3s ease;
        }
        .cu-plan-step.cu-step-active {
            border-left-color: #666666;
            background: #f0f0f0;
        }
        .cu-plan-step.cu-step-completed {
            border-left-color: #888888;
            opacity: 0.85;
        }
        .cu-plan-step.cu-step-failed {
            border-left-color: #555555;
            background: #f0f0f0;
        }
        .cu-plan-step.cu-step-retrying {
            border-left-color: #999999;
            background: #f5f5f5;
        }
        .cu-plan-step-num {
            flex-shrink: 0;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #e8e8e8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: #888888;
        }
        .cu-plan-step.cu-step-completed .cu-plan-step-num {
            background: #e0e0e0;
            color: #666666;
        }
        .cu-plan-step.cu-step-failed .cu-plan-step-num {
            background: #e0e0e0;
            color: #555555;
        }
        .cu-plan-step-title {
            flex: 1;
            color: #444444;
        }
        .cu-plan-step-type {
            flex-shrink: 0;
            font-size: 10px;
            padding: 1px 6px;
            border-radius: 3px;
            background: #e8e8e8;
            color: #777777;
        }
        .cu-task-type-drag,
        .cu-task-type-click,
        .cu-task-type-type,
        .cu-task-type-key,
        .cu-task-type-observe,
        .cu-task-type-scroll {
            background: #e8e8e8;
            color: #777777;
        }

        /* 验证结果节点 */
        .cu-verify-result .cu-step-icon,
        .cu-verify-result:not(.cu-step-done) .cu-step-icon {
            background: #f0f0f0;
            color: #666666;
            border-color: #c0c0c0;
        }

        /* ── CU 执行记录：纯文本层级，避免彩色状态、卡片阴影与装饰动画 ── */
        .cu-codex-card {
            margin: 12px 0 8px;
            overflow: visible;
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }
        .cu-codex-header {
            width: 100%;
            min-height: 28px;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 3px 0;
            color: #4a4a4a;
            text-align: left;
            background: transparent;
            border: 0;
            cursor: pointer;
            font: inherit;
        }
        .cu-codex-header:hover { background: transparent; color: #111111; }
        .cu-codex-title { font-size: 13px; font-weight: 400; }
        .cu-codex-state {
            color: #7a7a7a;
            font-size: 12px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        .cu-codex-state::before { content: '·'; margin-right: 6px; }
        .cu-codex-chevron {
            flex: 0 0 auto;
            margin-left: 2px;
            color: #8a8a8a;
            font-size: 14px;
            line-height: 1;
            transition: none;
        }
        .cu-codex-marker {
            flex: 0 0 auto;
            color: #6b6b6b;
            font-size: 16px;
            line-height: 1;
        }
        .cu-codex-card.is-error .cu-codex-title { color: #4a4a4a; }
        .cu-codex-body { padding: 2px 0 2px 22px; }
        .cu-codex-card.is-collapsed .cu-codex-body { display: none; }
        .cu-codex-card.is-collapsed .cu-codex-chevron { transform: rotate(-90deg); }
        .cu-codex-card .cu-timeline {
            margin-top: 0;
            padding-left: 0;
            border-left: 0;
        }
        .cu-codex-card .cu-step-node {
            gap: 6px;
            padding: 4px 0;
            color: #4a4a4a;
            border-top: 0;
        }
        .cu-codex-card .cu-step-icon {
            width: 14px;
            height: 14px;
            color: #777777;
            background: transparent;
            border: 0;
            border-radius: 0;
        }
        .cu-codex-card .cu-step-text { font-size: 13px; }
        .cu-codex-card .cu-step-thinking .cu-thinking-text {
            color: #555555;
            font-style: normal;
        }
        .cu-codex-card .cu-step-running .cu-step-icon { animation: none; }
        .cu-codex-card .cu-plan-card {
            margin: 4px 0 2px;
            padding: 2px 0;
            background: transparent;
            border: 0;
            border-radius: 0;
        }
        .cu-codex-card .cu-plan-header { font-weight: 400; }
        .cu-codex-card .cu-plan-step {
            padding: 3px 0;
            background: transparent;
            border: 0;
            border-radius: 0;
        }
        .cu-codex-card .cu-plan-step-num {
            width: auto;
            height: auto;
            color: #777777;
            background: transparent;
            border-radius: 0;
        }
        .cu-codex-card .cu-plan-step-type { display: none; }
        .cu-codex-card .cu-screenshot-card {
            background: transparent;
            border-color: #dddddd;
        }
        .message.ai .message-content.cu-final-answer {
            display: block;
            margin-top: 8px;
            color: #252525;
            line-height: 1.6;
            white-space: pre-wrap;
        }

        /* ── CU 截图灯箱 ── */
        .cu-lightbox {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .cu-lightbox.show,
        .cu-lightbox[aria-hidden="false"] {
            display: flex;
        }
        .cu-lightbox-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.9);
        }
        .cu-lightbox-img {
            position: relative;
            max-width: 90vw;
            max-height: 85vh;
            object-fit: contain;
            z-index: 1;
        }
        .cu-lightbox-close,
        .cu-lightbox-prev,
        .cu-lightbox-next {
            position: fixed;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #ffffff;
            font-size: 32px;
            line-height: 1;
            cursor: pointer;
            z-index: 2;
            padding: 8px 12px;
            opacity: 0.8;
            transition: opacity 0.2s ease;
        }
        .cu-lightbox-close {
            top: 16px;
            right: 16px;
            transform: none;
            font-size: 28px;
        }
        .cu-lightbox-prev {
            left: 16px;
        }
        .cu-lightbox-next {
            right: 16px;
        }
        .cu-lightbox-close:hover,
        .cu-lightbox-prev:hover,
        .cu-lightbox-next:hover {
            opacity: 1;
        }
        .cu-lightbox-counter {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translateX(-50%);
            color: #ffffff;
            font-size: 13px;
            z-index: 2;
            opacity: 0.85;
        }

        /* ── v4.11: 文件内容流式显示区域 ── */
        .file-content-streaming {
            margin: 8px 0;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            overflow: hidden;
            background: #f7f7f7;
            animation: statusSlideIn 0.2s ease-out;
        }
        .file-content-header {
            padding: 6px 12px 6px 22px;
            font-size: 12px;
            color: #777777;
            background: #f0f0f0;
            border-bottom: 1px solid #e8e8e8;
        }
        .file-content-code {
            margin: 0;
            padding: 10px 12px 10px 22px;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.5;
            color: #444444;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 400px;
            overflow-y: auto;
        }

        /* ── Operations Log (运行日志) ── */
        .operations-collapsible {
            margin: 4px 0 8px;
            width: 100%;
            max-width: 100%;
            background: transparent;
            border: none;
            border-radius: 0;
            box-shadow: none;
            overflow: visible;
            font-size: 14px;
            color: #6a6a6a;
            box-sizing: border-box;
            animation: statusSlideIn 0.2s ease-out;
        }
        .operations-header {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 2px 0;
            cursor: pointer;
            user-select: none;
            transition: color 0.15s ease;
            color: #888;
        }
        .operations-header:hover {
            color: #2a2a2a;
        }
        .operations-arrow {
            display: inline-flex;
            width: 12px;
            height: 12px;
            align-items: center;
            justify-content: center;
            color: currentColor;
            transform: rotate(0deg);
            transition: transform 0.2s ease;
        }
        .operations-collapsible.expanded .operations-arrow {
            transform: rotate(90deg);
        }
        .operations-label {
            font-size: 14px;
            color: inherit;
        }
        .operations-list {
            display: none;
            padding: 6px 0 4px 0;
        }
        .operations-collapsible.expanded .operations-list {
            display: block;
        }
        .operation-log-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
            min-height: 22px;
            font-size: 13px;
            line-height: 1.5;
            color: #666;
        }
        .operation-log-item .log-icon {
            display: inline-flex;
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            color: currentColor;
        }
        .operation-log-item .log-icon svg {
            display: block;
            width: 100%;
            height: 100%;
        }
        .operation-log-item .log-text {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        /* 状态色 */
        .operation-log-item.status-executing,
        .operation-log-item.status-thinking {
            color: #666;
        }
        .operation-log-item.status-executing .log-icon,
        .operation-log-item.status-thinking .log-icon {
            animation: statusSpin 1s linear infinite;
        }
        .operation-log-item.status-success,
        .operation-log-item.status-complete,
        .operation-log-item.status-done {
            color: #888;
        }
        .operation-log-item.status-failure,
        .operation-log-item.status-error {
            color: #e53935;
        }
