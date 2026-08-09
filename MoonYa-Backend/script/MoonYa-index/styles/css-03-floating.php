        /* 浮动按钮（侧边栏收起时显示） */
        .floating-controls {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
            display: none;
        }

        .floating-controls.show {
            display: block;
        }

        .floating-btn-group {
            display: flex;
            align-items: center;
            gap: 8px;
            background: white;
            border-radius: 50px;
            padding: 6px 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        /* 小按钮 - 展开侧边栏 */
        .floating-btn-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .floating-btn-small:hover {
            background: #e8e8e8;
            transform: scale(1.05);
        }

        .floating-btn-small img {
            width: 16px;
            height: 16px;
            object-fit: contain;
        }

        /* 展开侧边栏按钮提示框 */
        #expandSidebarBtn {
            position: relative;
        }

        #expandSidebarBtn::after {
            content: '展开侧边栏';
            position: absolute;
            left: 40px;
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

        #expandSidebarBtn::before {
            content: '';
            position: absolute;
            left: 34px;
            top: 50%;
            transform: translateY(-50%) scale(0.8);
            border: 6px solid transparent;
            border-right-color: white;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            z-index: 101;
        }

        #expandSidebarBtn:hover::after,
        #expandSidebarBtn:hover::before {
            opacity: 1;
            visibility: visible;
            transform: translateY(-50%) scale(1);
        }

        /* 大按钮 - 新建对话 */
        .floating-btn-large {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .floating-btn-large:hover {
            background: #e8e8e8;
            transform: scale(1.05);
        }

        .floating-btn-large img {
            width: 24px;
            height: 24px;
            object-fit: contain;
            cursor: default;
            pointer-events: none;
        }

        /* 浮动新建对话按钮提示框 */
        #newChatFloatingBtn {
            position: relative;
        }

        #newChatFloatingBtn::after {
            content: '新建对话';
            position: absolute;
            left: 50px;
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

        #newChatFloatingBtn::before {
            content: '';
            position: absolute;
            left: 44px;
            top: 50%;
            transform: translateY(-50%) scale(0.8);
            border: 6px solid transparent;
            border-right-color: white;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            z-index: 101;
        }

        #newChatFloatingBtn:hover::after,
        #newChatFloatingBtn:hover::before {
            opacity: 1;
            visibility: visible;
            transform: translateY(-50%) scale(1);
        }

