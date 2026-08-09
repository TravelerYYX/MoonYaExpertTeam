        /* Agent 输入栏：直接上传 + DeepSeek 模型/五档推理浮层 */
        .input-bottom-left > .input-label-container {
            display: none !important;
        }

        .add-menu-selector {
            position: relative;
            flex: 0 0 auto;
        }

        #fileCard.file-upload-plus {
            display: grid;
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            place-items: center;
            padding: 0;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #5f636b;
            box-shadow: none;
            cursor: pointer;
        }

        #fileCard.file-upload-plus:hover,
        #fileCard.file-upload-plus:focus-visible {
            background: #f1f2f4;
            color: #25272b;
            outline: none;
        }

        #fileCard.file-upload-plus svg {
            width: 19px;
            height: 19px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.75;
            stroke-linecap: round;
        }

        .file-upload-plus-label {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }

        .add-menu-popover {
            position: absolute;
            bottom: calc(100% + 9px);
            left: 0;
            z-index: 9600;
            display: none;
            width: 276px;
            padding: 8px;
            border: 1px solid rgba(230, 232, 236, .94);
            border-radius: 13px;
            background: rgba(255, 255, 255, .98);
            box-shadow: 0 12px 36px rgba(35, 37, 43, .15);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .add-menu-selector.open .add-menu-popover {
            display: block;
            animation: team-menu-in .15s ease-out both;
        }

        .add-menu-heading {
            padding: 3px 8px 6px;
            color: #94979e;
            font-size: 12px;
            font-weight: 500;
            line-height: 18px;
        }

        .add-menu-divider {
            height: 1px;
            margin: 7px 4px 8px;
            background: #eceef1;
        }

        .add-menu-option {
            display: flex;
            width: 100%;
            min-height: 46px;
            align-items: center;
            gap: 10px;
            padding: 7px 8px;
            border: 0;
            border-radius: 9px;
            background: transparent;
            color: #292c32;
            font: inherit;
            text-align: left;
            cursor: pointer;
            transition: background-color .15s ease, color .15s ease;
        }

        .add-menu-option:hover,
        .add-menu-option:focus-visible {
            background: #f1f2f4;
            outline: none;
        }

        .add-menu-option-icon {
            width: 19px;
            height: 19px;
            flex: 0 0 19px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .add-menu-option-copy {
            display: flex;
            min-width: 0;
            flex: 1;
            flex-direction: column;
            gap: 1px;
        }

        .add-menu-option-title {
            color: inherit;
            font-size: 13px;
            font-weight: 500;
            line-height: 18px;
        }

        .add-menu-option-desc {
            overflow: hidden;
            color: #92959c;
            font-size: 11px;
            font-weight: 400;
            line-height: 15px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .web-upload-choice[hidden] {
            display: none;
        }

        .web-upload-choice {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin: 2px 4px 7px 37px;
        }

        .web-upload-choice-button {
            display: flex;
            min-width: 0;
            height: 34px;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 0 8px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            color: #555a63;
            font: inherit;
            font-size: 12px;
            cursor: pointer;
        }

        .web-upload-choice-button:hover,
        .web-upload-choice-button:focus-visible {
            border-color: #cfd3da;
            background: #f5f6f8;
            color: #25272b;
            outline: none;
        }

        .web-upload-choice-button svg {
            width: 15px;
            height: 15px;
            flex: 0 0 15px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.7;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .add-menu-switch-option.active {
            color: #1677ff;
            background: #f0f7ff;
        }

        .add-menu-switch {
            position: relative;
            width: 30px;
            height: 18px;
            flex: 0 0 30px;
            border-radius: 999px;
            background: #c8cbd1;
            transition: background-color .18s ease;
        }

        .add-menu-switch span {
            position: absolute;
            top: 3px;
            left: 3px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .18);
            transition: transform .18s ease;
        }

        .add-menu-switch-option.active .add-menu-switch {
            background: #1677ff;
        }

        .add-menu-switch-option.active .add-menu-switch span {
            transform: translateX(12px);
        }

        .active-feature-badges {
            display: none;
            min-width: 0;
            align-items: center;
            gap: 8px;
            color: #777b84;
            white-space: nowrap;
        }

        .active-feature-badges.has-active {
            display: flex;
        }

        .active-feature-badges::before {
            width: 1px;
            height: 15px;
            margin: 0 1px;
            background: #e2e4e8;
            content: "";
        }

        .active-feature-badge {
            display: inline-flex;
            min-width: 0;
            align-items: center;
            gap: 4px;
            color: #777b84;
            font-size: 12px;
            line-height: 18px;
        }

        .active-feature-badge[hidden] {
            display: none;
        }

        .active-feature-badge svg {
            width: 16px;
            height: 16px;
            flex: 0 0 16px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.65;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .web-upload-skipped-summary {
            max-width: 260px;
            overflow: hidden;
            padding: 6px 9px;
            border: 1px solid #f2d4a7;
            border-radius: 8px;
            background: #fff8ec;
            color: #8a5a18;
            font-size: 11px;
            line-height: 16px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .agent-settings-selector {
            position: relative;
            flex: 0 0 auto;
        }

        .agent-settings-status {
            display: flex;
            min-width: 0;
            height: 32px;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 0 3px;
            border: 0;
            border-radius: 7px;
            background: transparent;
            color: #282a2e;
            font: inherit;
            cursor: pointer;
        }

        .agent-settings-status:hover,
        .agent-settings-selector.open .agent-settings-status {
            background: transparent;
            color: #111317;
        }

        #agentModelStatusText {
            color: #26292e;
            font-size: 12px;
            font-weight: 400;
            letter-spacing: -.01em;
        }

        #agentEffortStatusText {
            color: #777b82;
            font-size: 12px;
            font-weight: 400;
        }

        .agent-settings-status svg {
            width: 12px;
            height: 12px;
            margin-left: 0;
            fill: none;
            stroke: #858990;
            stroke-width: 1.7;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: transform .18s ease;
        }

        .agent-settings-selector.open .agent-settings-status svg {
            transform: rotate(180deg);
        }

        .agent-settings-popover {
            position: absolute;
            right: 0;
            bottom: calc(100% + 9px);
            z-index: 9600;
            display: none;
            width: 226px;
            padding: 8px 9px 9px;
            border: 0;
            border-radius: 13px;
            background: rgba(255, 255, 255, .98);
            box-shadow: 0 12px 36px rgba(35, 37, 43, .15);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .agent-settings-selector.open .agent-settings-popover {
            display: block;
            animation: team-menu-in .15s ease-out both;
        }

        .agent-settings-advanced-toggle,
        .agent-settings-row,
        .agent-model-menu button {
            width: 100%;
            border: 0;
            background: transparent;
            color: #36383d;
            font: inherit;
            cursor: pointer;
        }

        .agent-settings-advanced-toggle {
            display: flex;
            height: 28px;
            align-items: center;
            justify-content: space-between;
            padding: 0 2px 3px;
            color: #7e8188;
            font-size: 13px;
            font-weight: 400;
        }

        .agent-settings-advanced-label,
        .agent-settings-row-value {
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .ui-chevron-icon {
            width: 12px;
            height: 12px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: transform .18s ease;
        }

        .agent-settings-advanced-toggle[aria-expanded="true"] .agent-settings-advanced-label .ui-chevron-icon {
            transform: rotate(90deg);
        }

        .agent-effort-control {
            display: block;
            margin-top: 2px;
            padding: 4px 1px 1px;
            border-top: 1px solid #eff0f1;
        }

        .agent-effort-icon {
            width: 16px;
            height: 16px;
            margin-left: auto;
            fill: none;
            stroke: #797d85;
            stroke-width: 1.65;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .agent-effort-slider-wrap {
            position: relative;
            height: 36px;
            display: flex;
            align-items: center;
        }

        .agent-effort-slider {
            position: relative;
            z-index: 2;
            width: 100%;
            height: 24px;
            margin: 0;
            appearance: none;
            -webkit-appearance: none;
            border-radius: 99px;
            outline: none;
            background: linear-gradient(90deg, #3d9dff 0 var(--effort-progress, 75%), #d9dade var(--effort-progress, 75%) 100%);
            cursor: pointer;
        }

        .agent-effort-slider::-webkit-slider-thumb {
            width: 26px;
            height: 26px;
            appearance: none;
            -webkit-appearance: none;
            border: 1px solid rgba(0,0,0,.06);
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,.14);
        }

        .agent-effort-slider::-moz-range-thumb {
            width: 26px;
            height: 26px;
            border: 1px solid rgba(0,0,0,.06);
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,.14);
        }

        .agent-effort-dots {
            position: absolute;
            z-index: 3;
            left: 9px;
            right: 9px;
            top: 50%;
            display: flex;
            justify-content: space-between;
            pointer-events: none;
            transform: translateY(-50%);
        }

        .agent-effort-dots i {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: rgba(255,255,255,.42);
        }

        .agent-settings-caption {
            display: none;
            justify-content: center;
            gap: 5px;
            margin: 4px 0 1px;
            color: #303237;
            font-size: 13px;
        }

        .agent-settings-advanced {
            max-height: 100px;
            margin-top: 6px;
            padding-top: 5px;
            overflow: hidden;
            border-top: 1px solid #e5e6e8;
            opacity: 1;
            transform: translateY(0);
            visibility: visible;
            transition:
                max-height .24s cubic-bezier(.22, 1, .36, 1),
                margin-top .24s cubic-bezier(.22, 1, .36, 1),
                padding-top .24s cubic-bezier(.22, 1, .36, 1),
                border-color .16s ease,
                opacity .18s ease .04s,
                transform .24s cubic-bezier(.22, 1, .36, 1),
                visibility 0s linear;
        }

        .agent-settings-advanced[hidden] {
            display: block !important;
            max-height: 0;
            margin-top: 0;
            padding-top: 0;
            border-top-color: transparent;
            opacity: 0;
            transform: translateY(-6px);
            visibility: hidden;
            pointer-events: none;
            transition-delay: 0s, 0s, 0s, 0s, 0s, 0s, .24s;
        }

        .agent-model-menu[hidden] {
            display: none !important;
        }

        .agent-settings-row {
            display: flex;
            min-height: 32px;
            align-items: center;
            justify-content: space-between;
            padding: 0 7px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 400;
            text-align: left;
        }

        button.agent-settings-row:hover {
            background: #eff0f2;
        }

        .agent-settings-row > span:last-child {
            color: #92959b;
        }

        .agent-settings-row-value .ui-chevron-icon {
            width: 11px;
            height: 11px;
        }

        .agent-settings-row b {
            font-weight: 400;
        }

        .agent-model-menu {
            position: absolute;
            right: calc(100% + 7px);
            bottom: 0;
            width: 170px;
            padding: 6px;
            border: 1px solid #e7e7e8;
            border-radius: 14px;
            background: rgba(250,250,251,.98);
            box-shadow: 0 15px 42px rgba(35,37,43,.16);
        }

        .agent-model-menu button {
            display: flex;
            height: 34px;
            align-items: center;
            justify-content: space-between;
            padding: 0 9px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 400;
            text-align: left;
        }

        .agent-model-menu button:hover,
        .agent-model-menu button.selected {
            background: #eff0f2;
        }

        .agent-model-check {
            visibility: hidden;
        }

        .agent-model-menu button.selected .agent-model-check {
            visibility: visible;
        }

        @media (prefers-reduced-motion: reduce) {
            .agent-settings-advanced,
            .agent-settings-advanced-toggle .ui-chevron-icon {
                transition: none;
            }
        }

        @media (max-width: 680px) {
            .input-bottom-bar {
                gap: 4px;
                padding-right: 7px;
                padding-left: 7px;
            }
            .input-bottom-left {
                flex: 0 0 auto;
                gap: 4px;
            }
            .input-bottom-right {
                min-width: 0;
                gap: 4px;
            }
            .voice-chat-card,
            #voiceBtn {
                display: none !important;
            }
            .approval-mode-selector {
                margin-right: 0;
            }
            .approval-mode-button {
                width: 32px;
                min-width: 32px;
                padding: 0;
                justify-content: center;
            }
            .approval-mode-caret {
                display: none;
            }
            .active-feature-badges {
                max-width: min(48vw, 280px);
                overflow: hidden;
            }
            .active-feature-badge {
                max-width: 142px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .agent-settings-status {
                min-width: 0;
                padding: 0 2px;
            }
            .agent-settings-popover {
                position: fixed;
                right: 12px;
                bottom: 82px;
                width: min(226px, calc(100vw - 24px));
            }
            .agent-model-menu {
                right: 0;
                bottom: calc(100% + 7px);
                width: 100%;
            }
        }

        /* 主对话中的 Agent 角色消息。主对话不绘制时间线，时间线仅保留在工作日志。 */
        .team-agent-message {
            position: relative;
            width: calc(100% - 40px);
            margin: 8px 20px 22px;
            padding-left: 54px;
            box-sizing: border-box;
            color: #25272b;
        }

        .team-main-head {
            position: relative;
            display: flex;
            width: 100%;
            min-height: 38px;
            align-items: center;
            gap: 8px;
            padding: 0;
            border: 0;
            background: transparent;
            color: inherit;
            font: inherit;
            text-align: left;
            cursor: default;
        }

        .team-agent-message.is-complete .team-main-head,
        .team-agent-message.is-complete .team-main-compact {
            cursor: pointer;
        }

        .team-main-avatar {
            position: absolute;
            left: -54px;
            top: 0;
            z-index: 1;
            display: grid;
            width: 38px;
            height: 38px;
            place-items: center;
            object-fit: cover;
            border: 1px solid #ececef;
            border-radius: 50%;
            background: #fff;
            color: #555b66;
            font-size: 12px;
            font-weight: 700;
        }

        .team-main-agent {
            font-size: 13px;
            font-weight: 650;
        }

        .team-main-status {
            color: #a1a3a8;
            font-size: 10px;
        }

        .team-agent-message.is-live .team-main-status::before {
            content: "";
            display: inline-block;
            width: 5px;
            height: 5px;
            margin-right: 5px;
            border-radius: 50%;
            background: #45a5ff;
            box-shadow: 0 0 0 3px rgba(69,165,255,.12);
            animation: team-live-pulse 1.5s ease-in-out infinite;
            vertical-align: 1px;
        }

        .team-main-caret {
            margin-left: auto;
            color: #afb1b6;
            font-size: 12px;
        }

        .team-main-bubble {
            margin-top: 2px;
            padding: 14px 16px;
            border: 1px solid #eeeeef;
            border-radius: 17px;
            background: #fff;
            box-shadow: 0 5px 17px rgba(30,32,38,.035);
        }

        .team-presentation-message .team-main-head {
            width: fit-content;
        }

        .team-presentation-message.is-complete .team-main-head {
            cursor: default;
        }

        .team-main-loading-bubble {
            display: inline-flex;
            width: 54px;
            height: 46px;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin-top: 2px;
            border: 1px solid #eeeeef;
            border-radius: 17px;
            background: #fff;
            box-shadow: 0 5px 17px rgba(30,32,38,.035);
            box-sizing: border-box;
        }

        .team-main-loading-bubble span {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: #999ca2;
            animation: team-main-loading-dot 1.15s ease-in-out infinite;
        }

        .team-main-loading-bubble span:nth-child(2) {
            animation-delay: .14s;
        }

        .team-main-loading-bubble span:nth-child(3) {
            animation-delay: .28s;
        }

        .team-presentation-bubble {
            width: fit-content;
            max-width: 100%;
            color: #202226;
            font-size: 14px;
            line-height: 1.72;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
            box-sizing: border-box;
            user-select: text;
        }

        .team-presentation-bubble.team-markdown {
            white-space: normal;
        }

        .team-presentation-message.is-interrupted .team-main-status::before {
            display: none;
        }

        @keyframes team-main-loading-dot {
            0%, 60%, 100% {
                opacity: .42;
                transform: translateY(0);
            }
            30% {
                opacity: 1;
                transform: translateY(-3px);
            }
        }

        .team-main-reasoning {
            margin-bottom: 13px;
            padding: 10px 12px;
            border-left: 2px solid #d9dcdf;
            border-radius: 0 10px 10px 0;
            background: #f7f7f8;
        }

        .team-main-section-title {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 7px;
            color: #777b82;
            font-size: 10px;
            font-weight: 650;
        }

        .team-main-section-title i {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #8c929a;
        }

        .team-agent-message.is-live .team-main-section-title i {
            background: #45a5ff;
            animation: team-live-pulse 1.5s ease-in-out infinite;
        }

        .team-main-reasoning-text,
        .team-main-content {
            color: #373a40;
            font-size: 13px;
            line-height: 1.75;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
            user-select: text;
        }

        .team-main-content {
            color: #202226;
            font-size: 14px;
        }

        /* Agent formatted output: neutral project palette, semantic colors only. */
        .team-markdown {
            color: #292c31;
            line-height: 1.72;
            overflow-wrap: anywhere;
            white-space: normal;
            word-break: normal;
        }

        .team-markdown > :first-child {
            margin-top: 0;
        }

        .team-markdown > :last-child {
            margin-bottom: 0;
        }

        .team-markdown h1,
        .team-markdown h2,
        .team-markdown h3,
        .team-markdown h4 {
            margin: 18px 0 8px;
            color: #202327;
            font-weight: 680;
            line-height: 1.4;
            letter-spacing: -.01em;
        }

        .team-markdown h1 { font-size: 19px; }
        .team-markdown h2 { font-size: 17px; }
        .team-markdown h3 { font-size: 15px; }
        .team-markdown h4 { font-size: 14px; }

        .team-markdown .team-result-heading {
            padding: 0 0 8px 10px;
            border-bottom: 1px solid #e3e5e7;
            border-left: 3px solid #656970;
        }

        .team-markdown p {
            margin: 7px 0;
        }

        .team-markdown strong {
            color: #1f2226;
            font-weight: 680;
        }

        .team-markdown em {
            color: #555960;
        }

        .team-markdown ul,
        .team-markdown ol {
            margin: 8px 0;
            padding-left: 22px;
        }

        .team-markdown li {
            margin: 3px 0;
            padding-left: 2px;
        }

        .team-markdown li::marker {
            color: #777b81;
        }

        .team-markdown blockquote {
            margin: 10px 0;
            padding: 9px 12px;
            border-left: 3px solid #cfd2d5;
            border-radius: 0 9px 9px 0;
            background: #f6f7f8;
            color: #555960;
        }

        .team-markdown blockquote p {
            margin: 3px 0;
        }

        .team-markdown hr {
            margin: 16px 0;
            border: 0;
            border-top: 1px solid #e2e4e6;
        }

        .team-markdown a {
            color: #34383e;
            font-weight: 560;
            text-decoration: underline;
            text-decoration-color: #a9adb2;
            text-decoration-thickness: 1px;
            text-underline-offset: 3px;
        }

        .team-markdown a:hover {
            color: #17191c;
            text-decoration-color: #555960;
        }

        .team-inline-code {
            padding: 2px 5px;
            border: 1px solid #e1e3e5;
            border-radius: 5px;
            background: #f5f6f7;
            color: #363a40;
            font: 500 .9em/1.5 ui-monospace, SFMono-Regular, Consolas, monospace;
        }

        .team-inline-status-icon {
            display: inline-flex;
            width: 16px;
            height: 16px;
            flex: 0 0 16px;
            align-items: center;
            justify-content: center;
            margin-right: 5px;
            color: #686c72;
            vertical-align: -3px;
        }

        .team-inline-status-icon svg,
        .team-code-block svg,
        .team-artifact-icon svg {
            display: block;
            width: 100%;
            height: 100%;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .team-artifact-icon svg {
            width: 18px;
            height: 18px;
        }

        .team-inline-status-icon.success { color: #2f7a49; }
        .team-inline-status-icon.warning { color: #8a641e; }
        .team-inline-status-icon.error { color: #a5484f; }
        .team-inline-status-icon.running { color: #666a70; }

        .team-table-wrap {
            max-width: 100%;
            margin: 11px 0 14px;
            overflow-x: auto;
            border: 1px solid #e1e3e5;
            border-radius: 11px;
            background: #fff;
        }

        .team-markdown table {
            width: 100%;
            min-width: 360px;
            border: 0;
            border-collapse: collapse;
            border-spacing: 0;
            color: #31343a;
            font-size: .95em;
        }

        .team-markdown th,
        .team-markdown td {
            padding: 9px 11px;
            border: 0;
            border-bottom: 1px solid #e7e9eb;
            text-align: left;
            vertical-align: top;
        }

        .team-markdown th {
            background: #f5f6f7;
            color: #5d6168;
            font-size: .88em;
            font-weight: 650;
            letter-spacing: .01em;
        }

        .team-markdown tbody tr:last-child td {
            border-bottom: 0;
        }

        .team-markdown tbody tr:hover td {
            background: #fafafa;
        }

        .team-status-cell {
            font-weight: 560;
        }

        .team-cell-value {
            min-width: 0;
        }

        .team-status-cell .team-cell-value {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
        }

        .team-status-cell.success {
            background: #eef7f1;
            color: #2f7045;
        }

        .team-status-cell.warning {
            background: #fff7e8;
            color: #815d1d;
        }

        .team-status-cell.error {
            background: #fff1f2;
            color: #9c4249;
        }

        .team-status-cell.running {
            background: #f2f3f4;
            color: #5f6369;
        }

        .team-code-block {
            margin: 11px 0 14px;
            overflow: hidden;
            border: 1px solid #dfe2e4;
            border-radius: 11px;
            background: #f7f8f8;
        }

        .team-code-header {
            display: flex;
            min-height: 36px;
            align-items: center;
            gap: 6px;
            padding: 0 7px 0 4px;
            border-bottom: 1px solid #e1e3e5;
            background: #f2f3f4;
        }

        .team-code-toggle,
        .team-code-copy {
            border: 0;
            background: transparent;
            color: #62666c;
            font: inherit;
            cursor: pointer;
        }

        .team-code-toggle {
            display: flex;
            min-width: 0;
            min-height: 34px;
            flex: 1;
            align-items: center;
            gap: 6px;
            padding: 0 5px;
            text-align: left;
        }

        .team-code-chevron {
            display: inline-flex;
            width: 14px;
            height: 14px;
            flex: 0 0 14px;
            transition: transform .16s ease;
        }

        .team-code-block:not(.is-collapsed) .team-code-chevron {
            transform: rotate(90deg);
        }

        .team-code-title {
            overflow: hidden;
            color: #3e4248;
            font-size: 11px;
            font-weight: 620;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .team-code-lines {
            margin-left: auto;
            color: #8b8f95;
            font-size: 9px;
            font-weight: 400;
            white-space: nowrap;
        }

        .team-code-copy {
            display: grid;
            width: 28px;
            height: 28px;
            flex: 0 0 28px;
            place-items: center;
            border-radius: 7px;
        }

        .team-code-copy:hover {
            background: #e7e9eb;
            color: #30343a;
        }

        .team-code-copy.copied {
            color: #2f7a49;
        }

        .team-code-copy svg {
            width: 15px;
            height: 15px;
        }

        .team-code-block pre {
            max-height: 340px;
            margin: 0;
            overflow: auto;
            padding: 13px 14px;
            background: #fbfbfb;
            color: #30343a;
            tab-size: 4;
        }

        .team-code-block code {
            display: block;
            padding: 0;
            background: transparent;
            color: inherit;
            font: 11.5px/1.72 ui-monospace, SFMono-Regular, Consolas, monospace;
            white-space: pre;
        }

        .team-code-block.is-collapsed .team-code-header {
            border-bottom-color: transparent;
        }

        .team-code-block.is-collapsed pre {
            display: none;
        }

        .team-code-block .hljs {
            background: transparent;
            color: #30343a;
        }

        .team-code-block .hljs-comment,
        .team-code-block .hljs-quote {
            color: #85898f;
            font-style: italic;
        }

        .team-code-block .hljs-keyword,
        .team-code-block .hljs-selector-tag,
        .team-code-block .hljs-built_in,
        .team-code-block .hljs-type {
            color: #292c31;
            font-weight: 680;
        }

        .team-code-block .hljs-string,
        .team-code-block .hljs-attr,
        .team-code-block .hljs-symbol,
        .team-code-block .hljs-bullet {
            color: #555b57;
        }

        .team-code-block .hljs-number,
        .team-code-block .hljs-literal,
        .team-code-block .hljs-variable,
        .team-code-block .hljs-template-variable {
            color: #555960;
        }

        .team-code-block .hljs-title,
        .team-code-block .hljs-section,
        .team-code-block .hljs-function {
            color: #3e4248;
            font-weight: 620;
        }

        .team-log-content.team-markdown {
            color: #41454c;
            font-size: 11px;
            white-space: normal;
        }

        .team-log-content.team-markdown h1 { font-size: 14px; }
        .team-log-content.team-markdown h2 { font-size: 13px; }
        .team-log-content.team-markdown h3,
        .team-log-content.team-markdown h4 { font-size: 12px; }

        .team-log-content.team-markdown h1,
        .team-log-content.team-markdown h2,
        .team-log-content.team-markdown h3,
        .team-log-content.team-markdown h4 {
            margin-top: 12px;
        }

        .team-log-content .team-table-wrap {
            overflow: visible;
            border: 0;
            background: transparent;
        }

        .team-log-content .team-table-wrap table,
        .team-log-content .team-table-wrap tbody,
        .team-log-content .team-table-wrap tr,
        .team-log-content .team-table-wrap td {
            display: block;
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        .team-log-content .team-table-wrap thead {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }

        .team-log-content .team-table-wrap tr {
            margin-bottom: 7px;
            overflow: hidden;
            border: 1px solid #e3e5e7;
            border-radius: 9px;
            background: #fff;
        }

        .team-log-content .team-table-wrap td {
            display: grid;
            grid-template-columns: minmax(66px, .42fr) minmax(0, 1fr);
            gap: 8px;
            padding: 7px 8px;
            border-bottom: 1px solid #eceeef;
            text-align: left !important;
        }

        .team-log-content .team-table-wrap td::before {
            content: attr(data-label);
            color: #85898f;
            font-size: 9px;
            font-weight: 620;
        }

        .team-log-content .team-table-wrap td:last-child {
            border-bottom: 0;
        }

        .team-log-content .team-code-block code {
            font-size: 10px;
        }

        .team-main-approvals:empty {
            display: none;
        }

        .team-main-compact {
            display: none;
            max-width: 100%;
            margin-top: 2px;
            padding: 11px 15px;
            overflow: hidden;
            border: 1px solid #eeeeef;
            border-radius: 17px;
            background: #fff;
            color: #303238;
            font-size: 13px;
            line-height: 1.55;
            text-overflow: ellipsis;
            white-space: nowrap;
            box-shadow: 0 5px 17px rgba(30,32,38,.035);
        }

        .team-agent-message.is-collapsed .team-main-bubble {
            display: none;
        }

        .team-agent-message.is-collapsed .team-main-compact {
            display: block;
        }

        .team-agent-message.has-pending-approval .team-main-bubble {
            display: block;
        }

        .team-agent-message.has-pending-approval .team-main-compact {
            display: none;
        }

        .team-main-approval-card {
            margin: 12px 0 0;
        }

        .team-log-approval-card {
            margin: 7px 0 0;
            padding: 11px;
            border-radius: 13px;
        }

        .team-log-approval-card p {
            white-space: pre-wrap;
        }

        /* 右侧工作日志始终保存完整时间线；卡片只更新，不因状态变化重复插入。 */
        .team-panel .team-work-log {
            padding: 5px 5px 20px 3px;
        }

        .team-panel .team-log-event {
            position: relative;
            margin: 0;
            padding: 8px 7px 18px 47px;
            overflow: visible;
            border: 0;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
            color: #30343b;
        }

        .team-panel .team-log-event::before {
            left: 22px;
            top: 38px;
            bottom: -1px;
            background: #dde0e4;
        }

        .team-panel .team-log-event:last-child::before {
            bottom: 18px;
        }

        .team-panel .team-log-event .team-event-avatar {
            left: 5px;
            top: 7px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            box-shadow: none;
        }

        .team-panel .team-log-event .team-event-head {
            min-height: 28px;
            margin-bottom: 5px;
            cursor: default;
        }

        .team-panel .team-log-event .team-event-agent {
            color: #282b30;
            font-size: 12px;
        }

        .team-panel .team-log-event .team-event-kind {
            padding: 0;
            background: transparent;
            color: #a0a3a9;
            font-size: 9px;
        }

        .team-panel .team-log-event .team-event-time {
            font-size: 8px;
        }

        .team-panel .team-log-event .team-event-summary,
        .team-log-reasoning,
        .team-log-content {
            color: #41454c;
            font-size: 11px;
            line-height: 1.7;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
            user-select: text;
        }

        .team-log-reasoning {
            padding: 8px 9px;
            border-left: 2px solid #d9dcdf;
            background: #f6f7f8;
            color: #62666d;
        }

        .team-log-section + .team-log-section {
            margin-top: 9px;
        }

        .team-log-section-label {
            margin-bottom: 4px;
            color: #8a8e95;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: .04em;
        }

        .team-log-reasoning.team-markdown,
        .team-log-content.team-markdown {
            white-space: normal;
        }

        .team-log-event.running .team-event-kind {
            color: #3e93dc;
        }

        .team-log-event.error .team-event-kind {
            color: #bd5660;
        }

        .team-follow-host {
            position: relative;
        }

        .team-new-content-button {
            position: sticky;
            z-index: 24;
            bottom: 12px;
            left: 50%;
            width: max-content;
            max-width: calc(100% - 24px);
            margin: 8px auto 0;
            padding: 7px 12px;
            transform: translateX(-50%);
            border: 1px solid rgba(55, 118, 184, .2);
            border-radius: 999px;
            background: rgba(248, 252, 255, .96);
            box-shadow: 0 7px 22px rgba(35, 69, 103, .13);
            color: #3978b5;
            font-size: 10px;
            cursor: pointer;
        }

        .team-new-content-button[hidden] {
            display: none !important;
        }

        .team-panel .team-work-log {
            container-type: inline-size;
        }

        .team-project-board {
            margin: 5px 3px 15px;
            overflow: hidden;
            border: 1px solid #e0e5eb;
            border-radius: 12px;
            background: #f8fafc;
            box-shadow: 0 8px 24px rgba(35, 46, 59, .055);
        }

        .team-project-board-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 11px 13px;
            border: 0;
            border-bottom: 1px solid #e8ebef;
            background: #fff;
            color: #30353c;
            cursor: pointer;
        }

        .team-project-board-head strong {
            font-size: 12px;
        }

        .team-project-board-status {
            color: #5285b6;
            font-size: 9px;
        }

        .team-project-board.collapsed .team-project-tabs,
        .team-project-board.collapsed .team-project-lanes {
            display: none;
        }

        .team-project-lanes {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: minmax(300px, 1fr);
            gap: 9px;
            padding: 9px;
            overflow-x: auto;
            overscroll-behavior-inline: contain;
            scrollbar-width: thin;
        }

        .team-project-lane {
            min-width: 0;
            overflow: hidden;
            border: 1px solid #e2e6eb;
            border-radius: 10px;
            background: #fff;
        }

        .team-project-lane.lead {
            border-color: #cdddec;
            background: #fbfdff;
        }

        .team-project-lane-head {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 4px 8px;
            padding: 10px;
            border-bottom: 1px solid #eceff2;
        }

        .team-project-lane-head strong {
            font-size: 11px;
        }

        .team-project-lane-status {
            color: #8a9097;
            font-size: 9px;
        }

        .team-project-lane-status.running { color: #428bd0; }
        .team-project-lane-status.completed { color: #358260; }
        .team-project-lane-status.failed { color: #bc5962; }

        .team-project-workstream,
        .team-project-ownership {
            grid-column: 1 / -1;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .team-project-workstream {
            color: #454b52;
            font-size: 10px;
            font-weight: 620;
        }

        .team-project-ownership {
            color: #858b92;
            font-size: 8px;
            line-height: 1.55;
        }

        .team-project-lane-log {
            position: relative;
            max-height: min(54vh, 560px);
            min-height: 90px;
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-width: thin;
        }

        .team-project-lane-log .team-log-event {
            padding-left: 43px;
        }

        .team-project-tabs {
            display: none;
        }

        .team-streaming-text {
            white-space: pre-wrap !important;
            overflow-anchor: none;
        }

        .team-stream-placeholder {
            color: #969ba1;
            font-style: italic;
        }

        @container (max-width: 620px) {
            .team-project-tabs {
                display: flex;
                gap: 5px;
                padding: 8px 8px 0;
                overflow-x: auto;
            }

            .team-project-tab {
                flex: 0 0 auto;
                max-width: 220px;
                padding: 6px 9px;
                overflow: hidden;
                border: 1px solid #e0e5ea;
                border-radius: 999px;
                background: #fff;
                color: #6c737b;
                font-size: 9px;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .team-project-tab.selected {
                border-color: #aecbe4;
                background: #eef6fc;
                color: #3976ad;
            }

            .team-project-tab[data-status="failed"]::before { content: '失败 · '; color: #bc5962; }
            .team-project-tab[data-status="completed"]::before { content: '完成 · '; color: #358260; }

            .team-project-lanes {
                display: block;
                padding-top: 8px;
                overflow: visible;
            }

            .team-project-lane {
                display: none;
            }

            .team-project-lane.selected {
                display: block;
            }
        }

        @media (max-width: 680px) {
            .team-agent-message {
                width: calc(100% - 20px);
                margin-right: 10px;
                margin-left: 10px;
                padding-left: 45px;
            }
            .team-main-avatar {
                left: -45px;
                width: 34px;
                height: 34px;
            }
            .team-main-bubble {
                padding: 12px;
            }

            .team-main-content .team-table-wrap,
            .team-presentation-bubble .team-table-wrap {
                overflow: visible;
                border: 0;
                background: transparent;
            }

            .team-main-content .team-table-wrap table,
            .team-main-content .team-table-wrap tbody,
            .team-main-content .team-table-wrap tr,
            .team-main-content .team-table-wrap td,
            .team-presentation-bubble .team-table-wrap table,
            .team-presentation-bubble .team-table-wrap tbody,
            .team-presentation-bubble .team-table-wrap tr,
            .team-presentation-bubble .team-table-wrap td {
                display: block;
                width: 100%;
                min-width: 0;
                box-sizing: border-box;
            }

            .team-main-content .team-table-wrap thead,
            .team-presentation-bubble .team-table-wrap thead {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                clip: rect(0 0 0 0);
                white-space: nowrap;
            }

            .team-main-content .team-table-wrap tr,
            .team-presentation-bubble .team-table-wrap tr {
                margin-bottom: 8px;
                overflow: hidden;
                border: 1px solid #e3e5e7;
                border-radius: 10px;
                background: #fff;
            }

            .team-main-content .team-table-wrap td,
            .team-presentation-bubble .team-table-wrap td {
                display: grid;
                grid-template-columns: minmax(76px, .4fr) minmax(0, 1fr);
                gap: 9px;
                padding: 8px 9px;
                border-bottom: 1px solid #eceeef;
                text-align: left !important;
            }

            .team-main-content .team-table-wrap td::before,
            .team-presentation-bubble .team-table-wrap td::before {
                content: attr(data-label);
                color: #85898f;
                font-size: 10px;
                font-weight: 620;
            }

            .team-main-content .team-table-wrap td:last-child,
            .team-presentation-bubble .team-table-wrap td:last-child {
                border-bottom: 0;
            }
        }
