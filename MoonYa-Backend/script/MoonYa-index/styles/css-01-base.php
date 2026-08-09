        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* 不可复制的元素 */
        .menu-item,
        .action-icon,
        .feature-btn,
        .qq-login-btn,
        .file-card,
        .input-label,
        .voice-chat-card,
        .sidebar-title,
        .dropdown-arrow,
        .footer-link,
        .user-name,
        .user-qq,
        .unselectable {
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }
        
        /* 可复制的元素 */
        .message-content {
            user-select: text;
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 14px;
            color: #333;
            background-color: #f5f7fa;
        }

        /* ===== 自定义右键刷新菜单 ===== */
        .my-context-menu {
            position: fixed;
            z-index: 999999;
            display: none;
            align-items: center;
            width: 130px;
            height: 42px;
            padding: 0 14px 0 12px;
            box-sizing: border-box;
            background: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            cursor: pointer;
            user-select: none;
            -webkit-user-select: none;
            overflow: hidden;
            transition: background 0.15s ease, box-shadow 0.15s ease;
            animation: ctxMenuIn 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .my-context-menu.show {
            display: flex;
        }

        .my-context-menu:hover {
            background: #f7f9fc;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.16);
        }

        .my-context-menu:active {
            background: #f0f2f6;
        }

        /* 左侧刷新图标 */
        .my-context-menu .ctx-icon {
            flex: 0 0 auto;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .my-context-menu .ctx-icon svg {
            width: 18px;
            height: 18px;
            fill: #1f1f1f;
        }

        /* 右侧文字 */
        .my-context-menu .ctx-text {
            flex: 1 1 auto;
            margin-left: 10px;
            display: flex;
            align-items: center;
        }

        .my-context-menu .ctx-text .ctx-title {
            font-size: 14px;
            font-weight: 400;
            color: #1f1f1f;
            line-height: 1;
        }

        @keyframes ctxMenuIn {
            from {
                opacity: 0;
                transform: scale(0.96) translateY(2px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

