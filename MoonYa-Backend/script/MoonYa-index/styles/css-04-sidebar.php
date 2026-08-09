        /* 左侧侧边栏 */
        .sidebar {
            width: 240px;
            background-color: #f5f7fa;
            border-right: 1px solid #e8e8e8;
            display: flex;
            flex-direction: column;
            padding: 10px 0 0 0;
            transition: transform 0.3s ease, width 0.3s ease;
            height: 100vh;
            box-sizing: border-box;
            overflow: hidden;
            flex-shrink: 0;
        }

        .sidebar.collapsed {
            transform: translateX(-100%);
            width: 0;
            padding: 0;
            border-right: none;
        }
        
        .sidebar-header {
            padding: 0 12px 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }

        .sidebar-toggle {
            margin-left: auto;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .sidebar-toggle:hover {
            transform: scale(1.05);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
        }

        .sidebar-toggle img {
            width: 20px;
            height: 20px;
            object-fit: contain;
        }

        /* 收起侧边栏按钮提示框 */
        #sidebarToggle {
            position: relative;
        }

        #sidebarToggle::after {
            content: '收起侧边栏';
            position: absolute;
            right: 40px;
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

        #sidebarToggle::before {
            content: '';
            position: absolute;
            right: 34px;
            top: 50%;
            transform: translateY(-50%) scale(0.8);
            border: 6px solid transparent;
            border-left-color: white;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            z-index: 101;
        }

        #sidebarToggle:hover::after,
        #sidebarToggle:hover::before {
            opacity: 1;
            visibility: visible;
            transform: translateY(-50%) scale(1);
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .sidebar-title {
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }
        
        .sidebar-menu {
            flex: 1;
            min-height: 0;
            overflow: hidden;
            padding: 6px 12px;
            font-size: 13px;
            display: flex;
            flex-direction: column;
        }

        .menu-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #374151;
        }

        .menu-icon svg {
            width: 20px;
            height: 20px;
        }

        .menu-item {
            padding: 7px 12px;
            cursor: pointer;
            border-radius: 8px;
            margin: 1px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid transparent;
            transition: all 0.3s;
            min-height: 32px;
        }
        
        .menu-item.active {
            background-color: #e6f7ff;
            color: #1890ff;
            font-weight: 500;
            border-color: #0057ff;
        }
        
        .menu-item:hover {
            background-color: #f1f3f4;
        }
        


        /* 发送按钮样式 - 蓝色背景 */
        #sendBtn {
            background-color: #0057ff;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #sendBtn:hover {
            background-color: #40a9ff;
        }

        /* 发送按钮图片样式 - 只应用于发送图片 */
        #sendBtn img[src*="send.png"] {
            filter: brightness(0) invert(1);
            margin: 0 !important;
            width: 20px;
            height: 20px;
        }
        
        /* 停止按钮 SVG 样式 */
        #sendBtn[data-state="stop"] {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #sendBtn[data-state="stop"] svg {
            width: 20px;
            height: 20px;
            display: block;
        }

        /* 最近对话按钮样式 - 改为与更多按钮一致：无边框、透明背景、悬停淡灰色 */
        #recentChatBtn {
            padding: 7px 12px;
            margin: 1px 0;
            border: none;
            border-radius: 8px;
            background-color: transparent;
            color: #333;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 32px;
        }

        #recentChatBtn:hover {
            background-color: #f1f3f4;
        }

        #downloadAppBtn {
            padding: 7px 12px;
            margin: 1px 0;
            border: none;
            border-radius: 8px;
            background-color: transparent;
            color: #333;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 32px;
        }

        #downloadAppBtn:hover {
            background-color: #f1f3f4;
        }

        .menu-item-main {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .menu-arrow {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            transition: transform 0.3s ease;
            color: #9ca3af;
        }

        .menu-arrow.open {
            transform: rotate(90deg);
        }
        
        .recent-chat-list {
            margin: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .recent-chat-list.open {
            flex: 1 1 auto;
            min-height: 0;
            max-height: 360px;
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        
        .recent-chat-list.open::-webkit-scrollbar {
            display: none;
        }
        
        .recent-chat-item {
            padding: 5px 10px;
            cursor: pointer;
            border-radius: 8px;
            color: #333;
            transition: all 0.3s;
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 26px;
        }

        .recent-chat-item:hover {
            background-color: #f1f3f4;
        }

        .recent-chat-item.active {
            background-color: #f1f3f4;
            color: #111213ff;
            font-weight: 500;
        }

        .recent-chat-item-title-wrap {
            display: flex;
            align-items: center;
            flex: 1;
            min-width: 0;
        }

        .recent-chat-item-icon-circle {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: #f1f3f4;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 8px;
            color: #666;
        }

        .recent-chat-item-title {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 13px;
        }
        
        .recent-chat-item-menu-btn {
            opacity: 0;
            transition: opacity 0.2s;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            color: #999;
            letter-spacing: 2px;
        }
        
        .recent-chat-item:hover .recent-chat-item-menu-btn,
        .recent-chat-item.active .recent-chat-item-menu-btn {
            opacity: 1;
        }
        
        .recent-chat-item-menu-btn:hover {
            background-color: #f1f3f4;
            color: #333;
        }

        .recent-chat-item-task-status {
            width: 28px;
            height: 28px;
            flex: 0 0 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border: 0;
            border-radius: 8px;
            color: #2787f5;
            background: transparent;
            cursor: pointer;
        }

        .recent-chat-item-task-status:hover {
            background: rgba(39, 135, 245, 0.1);
        }

        .recent-chat-item-task-status.running svg {
            animation: statusSpin 1s linear infinite;
        }
        
        .recent-chat-item-menu {
            position: absolute;
            right: 0;
            top: 100%;
            background-color: white;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            min-width: 120px;
            display: none;
        }
        
        .recent-chat-item-menu.bottom-up {
            top: auto;
            bottom: 100%;
        }
        
        .recent-chat-item-menu.open {
            display: block;
        }
        
        .recent-chat-item-menu-item {
            padding: 8px 16px;
            cursor: pointer;
            font-size: 13px;
            color: #333;
            transition: background-color 0.2s;
        }
        
        .recent-chat-item-menu-item:hover {
            background-color: #f1f3f4;
        }
        
        .recent-chat-item-menu-item:first-child {
            border-radius: 8px 8px 0 0;
        }
        
        .recent-chat-item-menu-item:last-child {
            border-radius: 0 0 8px 8px;
        }
        
        .recent-chat-item-menu-item.delete {
            color: #ff4d4f;
        }
        
        /* 批量删除模式样式 */
        .recent-chat-item.batch-mode {
            padding-left: 8px;
        }

        .recent-chat-item.batch-mode .recent-chat-item-icon-circle {
            display: none;
        }
        
        .recent-chat-item-checkbox {
            width: 18px;
            height: 18px;
            margin-right: 8px;
            cursor: pointer;
            flex-shrink: 0;
        }
        
        .select-all-container {
            display: flex;
            align-items: center;
            padding: 5px 12px 5px 10px;
            margin: 0;
            border-radius: 8px;
            background-color: #f1f3f4;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .select-all-container:hover {
            background-color: #f1f3f4;
        }

        .select-all-checkbox {
            width: 18px;
            height: 18px;
            margin-right: 8px;
            cursor: pointer;
        }

        .select-all-label {
            font-size: 13px;
            color: #666;
        }

        .batch-delete-actions {
            display: flex;
            gap: 8px;
            padding: 8px 12px;
            margin: 4px 0 0 0;
        }
        
        .batch-delete-btn {
            flex: 1;
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .batch-delete-btn.confirm {
            background-color: #ff4d4f;
            color: white;
        }
        
        .batch-delete-btn.confirm:hover {
            background-color: #ff7875;
        }
        
        .batch-delete-btn.cancel {
            background-color: #e8e8e8;
            color: #333;
        }
        
        .batch-delete-btn.cancel:hover {
            background-color: #d9d9d9;
        }
        
