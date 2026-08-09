
        /* 代码块样式 - 一字不差复刻 样式.html 的 plaintext-card UI（类名/规则原样保留） */
        /* 组件容器 */
        .plaintext-card {
            width: 100%;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background-color: #f9fafb;
            overflow: hidden;
            margin-bottom: 30px;
            box-sizing: border-box;
        }

        /* 顶部标题栏 */
        .plain-header {
            display: flex;
            align-items: center;
            padding: 4px 12px;
            background-color: #f3f4f6;
            cursor: pointer;
            user-select: none;
        }

        /* 标题文字 */
        .plain-title {
            font-size: 14px;
            color: #374151;
            font-weight: 500;
            margin-right: 8px;
        }

        /* 折叠箭头：展开默认朝下 */
        .arrow-icon {
            width: 18px;
            height: 18px;
            color: #6b7280;
            transition: transform 0.3s ease;
            transform: rotate(0deg);
        }
        /* 收起状态：箭头朝右 */
        .plaintext-card.collapsed .arrow-icon {
            transform: rotate(-90deg);
        }

        /* 右侧操作按钮组 */
        .header-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
        }
        .header-actions .action-icon {
            width: 18px !important;
            height: 18px !important;
            border-radius: 0 !important;
            display: inline-block !important;
            color: #6b7280 !important;
            cursor: pointer;
        }

        /* 内容区域：展开收起动画 */
        .plain-content {
            padding: 16px 14px;
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 14px;
            color: #1f2937;
            line-height: 1.8;
            background-color: #ffffff;
            overflow: auto;
            max-height: 300px;
            opacity: 1;
            transition: max-height 0.3s ease, padding 0.3s ease, opacity 0.2s ease;
        }

        /* 滚动条 - iOS27 液态玻璃立体质感 */
        .plain-content {
            scrollbar-width: thin;
            scrollbar-color: rgba(150, 170, 200, 0.55) transparent;
        }
        .plain-content::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .plain-content::-webkit-scrollbar-track {
            background: transparent;
            border-radius: 8px;
            margin: 6px 0;
        }
        .plain-content::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg,
                rgba(190, 205, 225, 0.65) 0%,
                rgba(155, 175, 205, 0.45) 50%,
                rgba(190, 205, 225, 0.65) 100%);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow:
                inset 0 1px 1px rgba(255, 255, 255, 0.9),
                inset 0 -1px 1px rgba(80, 100, 130, 0.15),
                0 2px 6px rgba(0, 0, 0, 0.08),
                0 1px 2px rgba(0, 0, 0, 0.04);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            transition: background 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                        box-shadow 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                        transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .plain-content::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg,
                rgba(170, 190, 220, 0.85) 0%,
                rgba(135, 160, 195, 0.7) 50%,
                rgba(170, 190, 220, 0.85) 100%);
            box-shadow:
                inset 0 1px 2px rgba(255, 255, 255, 0.95),
                inset 0 -1px 2px rgba(80, 100, 130, 0.2),
                0 4px 10px rgba(0, 0, 0, 0.12),
                0 2px 4px rgba(0, 0, 0, 0.06);
        }
        .plain-content::-webkit-scrollbar-thumb:active {
            background: linear-gradient(135deg,
                rgba(145, 170, 200, 0.95) 0%,
                rgba(115, 145, 180, 0.85) 50%,
                rgba(145, 170, 200, 0.95) 100%);
            transform: scaleX(1.15);
        }
        /* 隐藏上下箭头与角落 */
        .plain-content::-webkit-scrollbar-button,
        .plain-content::-webkit-scrollbar-corner {
            display: none;
            width: 0;
            height: 0;
        }
        .plaintext-card.collapsed .plain-content {
            max-height: 0;
            padding-top: 0;
            padding-bottom: 0;
            opacity: 0;
        }

        /* 展开态头部分隔线 */
        .plaintext-card:not(.collapsed) .plain-header {
            border-bottom: 1px solid #e5e7eb;
        }
        .plaintext-card.collapsed .plain-header {
            border-bottom: 1px solid transparent;
            transition: border-color 0.3s ease;
        }

        /* 仅用于让 hljs 高亮在 .plain-content 内透明呈现（不修改样式.html的任何规则） */
        .plain-content pre {
            margin: 0;
            padding: 0;
            background: transparent !important;
            overflow-x: auto;
        }
        .plain-content pre code {
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.8;
            white-space: pre-wrap;
            word-wrap: break-word;
            background: transparent !important;
            padding: 0 !important;
        }
        .plain-content pre code .hljs {
            background: transparent !important;
            padding: 0 !important;
        }



        .main-header {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            z-index: 10;
            overflow: hidden;
            max-height: 35vh;
        }
