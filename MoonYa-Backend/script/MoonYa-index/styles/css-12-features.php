        /* 模型选择样式 */
        .model-select-container {
            padding: 6px 0;
            margin: 0 0 8px 0;
        }

        .model-select-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 6px;
            padding: 0 12px;
        }

        .custom-model-select {
            padding: 0 12px;
        }
        
        .model-select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            font-size: 13px;
            background-color: white;
            color: #333;
            cursor: pointer;
            outline: none;
            transition: all 0.3s;
        }
        
        .model-select:hover {
            border-color: #1890ff;
        }
        
        .model-select:focus {
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.1);
        }
        
        .model-select.loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        /* 自定义模型选择样式 */
        .custom-model-select {
            position: relative;
            width: 100%;
        }
        
        .model-select-value {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            font-size: 13px;
            background-color: white;
            color: #333;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .model-select-value:hover {
            border-color: #1890ff;
        }
        
        .model-select-arrow {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            transition: transform 0.3s;
            cursor: pointer;
        }
        
        .model-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            bottom: auto;
            background-color: white;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            margin-top: 4px;
            z-index: 1000;
            max-height: calc(100vh - 200px);
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            display: none;
        }
        
        .model-select-dropdown::-webkit-scrollbar {
            display: none;
        }
        
        .model-option {
            padding: 10px 12px;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .model-option:last-child {
            border-bottom: none;
        }
        
        .model-option:hover {
            background-color: #f5f7fa;
        }
        
        .model-option.selected {
            background-color: #e6f7ff;
            color: #1890ff;
        }
        
        .model-option-name {
            font-size: 13px;
            font-weight: 500;
            color: #333;
        }
        
        .model-option-desc {
            font-size: 11px;
            color: #999;
            margin-top: 2px;
        }
        
        /* 模式切换 Tab */
        .mode-toggle-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            height: 40px;
            margin: 0 0 8px 0;
            padding: 3px;
            background: #eceeef;
            border-radius: 10px;
            box-sizing: border-box;
        }
        .mode-toggle-tab {
            flex: 1;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #6b7280;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
        }
        .mode-toggle-tab.active {
            background: #ffffff;
            color: #111827;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        .mode-toggle-tab:not(.active):hover {
            color: #374151;
        }
        .mode-tab-icon {
            width: 14px;
            height: 14px;
        }

        /* 侧边栏新建会话按钮 */
        .new-session-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            height: 40px;
            padding: 0 12px;
            margin: 0 0 8px 0;
            background: #eceeef;
            border: 1px solid #e4e6e8;
            border-radius: 10px;
            cursor: pointer;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        .new-session-btn:hover {
            background: #eceeef;
        }
        .new-session-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .new-session-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 2px rgba(0,0,0,0.06);
            color: #374151;
        }
        .new-session-icon svg {
            width: 20px;
            height: 20px;
        }
        .new-session-text {
            font-size: 14px;
            font-weight: 500;
            color: #374151;
        }
        .new-session-shortcut {
            font-size: 12px;
            font-weight: 500;
            color: #9ca3af;
            background: #ffffff;
            padding: 3px 8px;
            border-radius: 6px;
            border: 1px solid #e4e6e8;
        }
        
        /* 隐藏的文件输入 */
        #fileInput,
        #folderInput {
            display: none;
        }

        .sidebar-model-hidden { display: none !important; }
