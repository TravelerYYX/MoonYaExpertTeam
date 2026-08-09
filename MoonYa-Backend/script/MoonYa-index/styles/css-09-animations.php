        /* 新建对话按钮 - 顶部左侧 */
        .new-chat-top-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 100;
        }

        .new-chat-top-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background: #f5f5f5;
        }

        .new-chat-top-btn img {
            width: 20px;
            height: 20px;
            object-fit: contain;
            cursor: default;
            pointer-events: none;
        }

        /* 新建对话按钮提示框 */
        .new-chat-top-btn::after {
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

        .new-chat-top-btn::before {
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

        .new-chat-top-btn:hover::after,
        .new-chat-top-btn:hover::before {
            opacity: 1;
            visibility: visible;
            transform: translateY(-50%) scale(1);
        }

        /* 主标题和热点按钮区域的大闪光效果 - 一个长条同时划过 */
        .main-header::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -100%;
            width: 30%;
            height: 200%;
            background: linear-gradient(
                120deg,
                transparent 10%,
                rgba(255, 255, 255, 0.6) 45%,
                rgba(255, 255, 255, 0.9) 50%,
                rgba(255, 255, 255, 0.6) 55%,
                transparent 80%
            );
            animation: headerShine 3.6s ease-in-out infinite;
            animation-delay: 0.5s;
            pointer-events: none;
        }

        @keyframes headerShine {
            0% {
                left: -100%;
            }
            50% {
                left: 150%;
            }
            100% {
                left: 150%;
            }
        }
        
        .main-title {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin-bottom: 24px;
            opacity: 0;
            transform: translateY(-30px);
            animation: mainTitleIn 0.8s ease forwards;
        }

        /* 主标题入场动画 - 从上方淡入并弹性回弹 */
        @keyframes mainTitleIn {
            0% {
                opacity: 0;
                transform: translateY(-30px);
            }
            50% {
                opacity: 1;
                transform: translateY(10px);
            }
            70% {
                transform: translateY(-5px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .hot-topics-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px;
            max-width: 95vw;
            margin: 0 auto;
            padding: 0 10px;
        }

        /* Work 优先：热点仅允许在 Chat 模式的空对话首页显示 */
        body.work-mode .hot-topics-container {
            display: none !important;
        }
        
        .hot-topic-btn {
            padding: 10px 16px;
            background-color: #f5f5f5;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            color: #333;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            opacity: 0;
            transform: translateY(20px) scale(0.9);
            animation: hotTopicBtnIn 0.5s ease forwards;
        }

        /* 热点按钮入场动画 - 依次淡入上浮 */
        @keyframes hotTopicBtnIn {
            0% {
                opacity: 0;
                transform: translateY(20px) scale(0.9);
            }
            60% {
                transform: translateY(-5px) scale(1.02);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* 依次延迟动画 */
        .hot-topic-btn:nth-child(1) { animation-delay: 0.05s; }
        .hot-topic-btn:nth-child(2) { animation-delay: 0.1s; }
        .hot-topic-btn:nth-child(3) { animation-delay: 0.15s; }
        .hot-topic-btn:nth-child(4) { animation-delay: 0.2s; }
        .hot-topic-btn:nth-child(5) { animation-delay: 0.25s; }
        .hot-topic-btn:nth-child(6) { animation-delay: 0.3s; }
        .hot-topic-btn:nth-child(7) { animation-delay: 0.35s; }
        .hot-topic-btn:nth-child(8) { animation-delay: 0.4s; }
        .hot-topic-btn:nth-child(9) { animation-delay: 0.45s; }

        .hot-topic-btn:hover {
            background-color: #e8e8e8;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .input-container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            box-sizing: border-box;
        }
        
        .input-wrapper {
            position: relative;
            margin-bottom: 10px;
            border: 1px solid #e8e8e8;
            border-radius: 24px;
            background-color: white;
            box-shadow:
                0 8px 22px rgba(31, 38, 52, 0.08),
                0 2px 6px rgba(31, 38, 52, 0.05),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }

        .message-input {
            width: 100%;
            height: 40px;
            min-height: 40px;
            padding: 10px 16px 6px 16px;
            line-height: 20px;
            border: none;
            font-size: 14px;
            color: #333;
            outline: none;
            transition: all 0.3s;
            resize: none;
            overflow-y: hidden;
            position: relative;
            box-sizing: border-box;
            background-color: transparent;
        }

        .message-input::-webkit-scrollbar {
            display: none;
        }

        .message-input:focus {
            border-color: transparent;
            box-shadow: none;
        }

        .input-bottom-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background-color: white;
            border-radius: 0 0 24px 24px;
        }

        .input-bottom-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex: 1;
        }

        .input-bottom-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .input-actions {
            position: absolute;
            right: 10px;
            bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .action-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #999;
        }
        
        .action-icon:hover {
            background-color: #f0f0f0;
            color: #333;
        }
        
        .send-btn {
            background-color: #1890ff;
            color: white;
        }
        
        .send-btn:hover {
            background-color: #40a9ff;
            color: white;
        }
        
        .file-card {
            font-size: 12px;
            color: #999;
            background-color: white;
            border: 1px solid #e8e8e8;
            padding: 4px 10px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.3s;
            z-index: 10;
        }

        .file-card:hover {
            background-color: #f5f7fa;
        }

        .file-icon {
            font-size: 14px;
        }

        .file-info {
            display: flex;
            align-items: center;
        }

        .file-name {
            font-size: 12px;
        }

        .input-label {
            font-size: 12px;
            color: #999;
            background-color: white;
            border: 1px solid #e8e8e8;
            padding: 4px 10px;
            border-radius: 12px;
            transition: all 0.3s;
            z-index: 10;
            cursor: pointer;
        }
        
        .input-label.active {
            color: #1890ff;
            border-color: #1890ff;
            background-color: rgba(24, 144, 255, 0.1);
        }
        
        .input-label.expert-active {
            color: #1890ff;
            border-color: #1890ff;
            background-color: rgba(24, 144, 255, 0.1);
        }

        .input-label.specialist-active {
            color: #1890ff;
            border-color: #1890ff;
            background-color: rgba(24, 144, 255, 0.1);
        }
        
        .input-label.think-locked {
            cursor: not-allowed;
            opacity: 0.85;
        }

        .input-label-container {
            display: flex;
            gap: 8px;
            align-items: center;
            min-width: 0;
            flex-wrap: wrap;
        }
        
        .feature-buttons {
            display: none !important;
        }
        
        .feature-btn {
            padding: 6px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background-color: white;
            color: #555;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            user-select: none;
        }
        
        .feature-btn:hover {
            border-color: #1890ff;
            color: #1890ff;
            background-color: #f5f9ff;
        }
        
        .feature-btn.active {
            border-color: #0057ff;
            color: #0057ff;
            background-color: #eef4ff;
            font-weight: 500;
        }

        /* 更多功能选择器（匹配子模型选择器风格） */
        .more-features-selector {
            position: relative;
            display: inline-flex;
        }

        body.work-mode .more-features-selector {
            display: none !important;
        }

        .more-features-btn {
            font-size: 11px;
            color: #555;
            background: linear-gradient(135deg, #fafbfc 0%, #f0f2f5 100%);
            border: 1px solid #e1e4e8;
            padding: 5px 10px;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            user-select: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }
        
        .more-features-btn:hover {
            border-color: #1890ff;
            color: #1890ff;
            background: linear-gradient(135deg, #f0f7ff 0%, #e6f2ff 100%);
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.12);
        }
        
        .more-features-btn.active {
            color: #1890ff;
            border-color: #1890ff;
            background: linear-gradient(135deg, #e6f7ff 0%, #d6eeff 100%);
            box-shadow: 0 1px 3px rgba(24, 144, 255, 0.15);
        }

        .more-features-btn .arrow-icon {
            width: 12px;
            height: 12px;
            transition: transform 0.3s;
        }

        .more-features-btn.open .arrow-icon {
            transform: rotate(180deg);
        }

        .more-features-dropdown {
            position: absolute;
            bottom: 100%;
            left: 0;
            background-color: white;
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 10px;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.12), 0 0 1px rgba(0, 0, 0, 0.05);
            margin-bottom: 6px;
            z-index: 1000;
            min-width: 170px;
            display: none;
            overflow-y: auto;
            max-height: 240px;
            padding-right: 4px;
            scrollbar-width: thin;
            scrollbar-color: rgba(120, 120, 128, 0.32) transparent;
            animation: dropdownFadeUp 0.2s ease;
        }

        .more-features-dropdown.show {
            display: block;
        }

        /* iOS 27 液态玻璃风格滚动条：只保留滑条，无上下箭头 */
        .more-features-dropdown::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }

        .more-features-dropdown::-webkit-scrollbar-track {
            background: transparent;
            border-radius: 10px;
            margin: 8px 2px;
        }

        .more-features-dropdown::-webkit-scrollbar-thumb {
            background:
                linear-gradient(180deg,
                    rgba(255, 255, 255, 0.65) 0%,
                    rgba(255, 255, 255, 0.15) 40%,
                    rgba(0, 0, 0, 0.05) 100%
                ),
                rgba(130, 130, 138, 0.38);
            backdrop-filter: blur(10px) saturate(180%);
            -webkit-backdrop-filter: blur(10px) saturate(180%);
            border-radius: 10px;
            border: 0.5px solid rgba(255, 255, 255, 0.45);
            box-shadow:
                inset 0 1px 0.5px rgba(255, 255, 255, 0.85),
                inset 0 -1px 0.5px rgba(0, 0, 0, 0.12),
                0 2px 5px rgba(0, 0, 0, 0.15),
                0 0 0 0.5px rgba(0, 0, 0, 0.04);
            transition: background 0.2s ease, box-shadow 0.2s ease;
        }

        .more-features-dropdown::-webkit-scrollbar-thumb:hover {
            background:
                linear-gradient(180deg,
                    rgba(255, 255, 255, 0.75) 0%,
                    rgba(255, 255, 255, 0.25) 40%,
                    rgba(0, 0, 0, 0.06) 100%
                ),
                rgba(110, 110, 118, 0.5);
            box-shadow:
                inset 0 1px 0.5px rgba(255, 255, 255, 0.9),
                inset 0 -1px 0.5px rgba(0, 0, 0, 0.14),
                0 3px 7px rgba(0, 0, 0, 0.2),
                0 0 0 0.5px rgba(0, 0, 0, 0.05);
        }

        .more-features-dropdown::-webkit-scrollbar-button,
        .more-features-dropdown::-webkit-scrollbar-corner {
            display: none;
        }

        .more-features-option {
            padding: 10px 14px;
            cursor: pointer;
            transition: all 0.15s ease;
            font-size: 13px;
            color: #333;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .more-features-option:last-child {
            border-bottom: none;
        }

        .more-features-option:hover {
            background-color: #f8f9fa;
        }

        .more-features-option.active {
            color: #1890ff;
            background-color: #f0f7ff;
        }

        .more-features-option .check-icon {
            display: none;
            width: 16px;
            height: 16px;
        }

        .more-features-option.active .check-icon {
            display: block;
        }
        
