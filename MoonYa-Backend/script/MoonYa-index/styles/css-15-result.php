        /* ========== 执行结果卡片（日间风格，默认收起） ========== */
        /* 执行消息包装器：去掉父级 padding 限制，实现横向最大化 */
        .message.ai.execution-message {
            padding-left: 0 !important;
            padding-right: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        .message.ai.execution-message .message-content {
            padding: 0 !important;
            max-width: 100% !important;
            width: 100% !important;
            align-self: stretch !important;
        }
        /* 代码执行框：与外层"使用了N工具"头、AI 正文共用同一左边缘
           （不再额外加 14px 横向 margin，否则会与左侧操作记录/正文错位） */
        .message.ai.execution-message .execution-block {
            width: auto !important;
            max-width: 100% !important;
            margin-left: 0;
            margin-right: 0;
        }

        .execution-block {
            margin: 8px 0;
            border: 1px solid #dcdcdc;
            border-radius: 8px;
            overflow: hidden;
            background: #ffffff;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            text-align: left;
            width: 100%;
            box-sizing: border-box;
        }

        /* 头部栏 */
        .execution-header {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
            padding: 10px 34px 10px 14px;
            cursor: pointer;
            color: #333333;
            font-size: 14px;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            transition: background 0.15s;
            background: #ffffff;
            border-bottom: 1px solid #dcdcdc;
            box-sizing: border-box;
        }

        .execution-header:hover {
            background-color: #f0f0f0;
        }

        /* 图标容器：双图标叠放切换 */
        .toggle-icon {
            flex-shrink: 0;
            margin-right: 10px;
            color: #666666;
            display: flex;
            align-items: center;
            position: relative;
            width: 1em;
            height: 1em;
        }
        .toggle-icon svg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            transition: opacity 0.15s ease;
        }
        /* 默认显示终端图标 */
        .toggle-icon .default-icon {
            opacity: 1;
        }
        /* 折叠箭头默认隐藏，悬停显示 */
        .toggle-icon .hover-icon {
            opacity: 0;
            transform: rotate(180deg);
            transition: transform 0.2s ease, opacity 0.15s ease;
        }
        .execution-block.collapsed .hover-icon {
            transform: rotate(90deg);
        }
        .execution-header:hover .default-icon {
            opacity: 0;
        }
        .execution-header:hover .hover-icon {
            opacity: 1;
        }

        /* 状态文字 */
        .exec-status {
            flex-shrink: 0;
            font-weight: 500;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            color: #333333;
        }

        .exec-status.running { color: #888888; }
        .exec-status.success { color: #333333; }
        .exec-status.error { color: #333333; }
        .exec-status.rejected { color: #888888; }

        .exec-spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #999999;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            flex-shrink: 0;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* 收起态单行命令 */
        .command-inline {
            display: none;
            margin-left: 12px;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 13px;
            color: #0451a5;
        }
        .execution-block.collapsed .command-inline {
            display: block;
        }

        /* 更多按钮 */
        .more-btn {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #999999;
            font-size: 18px;
            line-height: 1;
            padding: 0 4px;
            flex-shrink: 0;
        }

        /* 展开内容区 */
        .execution-body {
            padding: 2px 14px 14px;
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.7;
            overflow: hidden;
        }
        .execution-block.collapsed .execution-body {
            display: none;
        }

        /* 命令行样式 - 日间语法高亮 */
        .execution-block .code-block-wrapper {
            position: relative;
            background: transparent;
            margin-bottom: 8px;
        }

        .execution-block .code-block-wrapper pre {
            margin: 0;
            padding: 0;
            background: transparent;
            color: #0451a5;
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.7;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            border: none;
        }

        .execution-block .copy-code-btn {
            position: absolute;
            top: 0;
            right: 0;
            background: transparent;
            border: none;
            color: #999999;
            padding: 2px 4px;
            border-radius: 4px;
            cursor: pointer;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .execution-block .copy-code-btn:hover {
            color: #333333;
            background: #f0f0f0;
        }

        /* 输出区域 */
        .execution-output {
            padding: 0;
            background: transparent;
            border-top: none;
        }

        .execution-output .output-content {
            background: transparent;
            max-height: 320px;
            overflow-y: auto;
        }

        .execution-output .output-content pre {
            margin: 0;
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-all;
            color: #333333;
        }

        .execution-output .output-content.output-error pre {
            color: #a31515;
        }

        .execution-output .no-output {
            color: #999999;
            font-size: 12px;
            font-style: italic;
        }

        .execution-output .output-content.output-error .no-output {
            color: #a31515;
        }

        /* 错误区域 */
        .execution-error {
            border-top: none;
            padding: 0;
            margin-top: 8px;
            background: transparent;
        }

        .execution-error .error-header {
            font-size: 12px;
            font-weight: 600;
            color: #555555;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .execution-error pre {
            margin: 0;
            padding: 8px;
            background: #f0f0f0;
            border-radius: 4px;
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.45;
            color: #555555;
            white-space: pre-wrap;
            word-break: break-all;
        }

        /* 重新执行按钮 */
        .re-execute-btn {
            margin: 8px 0 0 0;
            padding: 4px 10px;
            background: #f0f0f0;
            color: #555555;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .re-execute-btn:hover {
            background: #e8e8e8;
            color: #333333;
        }
