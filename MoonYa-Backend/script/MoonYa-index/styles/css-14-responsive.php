        /* 响应式布局 - 中等屏幕 */
        @media screen and (max-width: 660px) {
            .main-content {
                padding: 20px 12px;
            }
            .input-container-wrapper {
                padding: 6px 10px;
            }
            .input-bottom-left {
                flex-wrap: wrap;
            }
            .input-label-container {
                flex-wrap: wrap;
            }
        }

        /* 响应式布局 - 小屏幕 */
        @media screen and (max-width: 480px) {
            .hot-topics-container {
                display: none !important;
            }
            .main-content {
                padding: 10px 4px;
            }
            .input-container-wrapper {
                padding: 6px 4px;
            }
            .input-container {
                width: 100%;
                max-width: none;
            }
            .input-bottom-bar {
                flex-wrap: wrap;
                gap: 6px;
            }
            .input-bottom-left {
                flex-wrap: wrap;
                gap: 6px;
            }
            .input-label-container {
                flex-wrap: wrap;
                gap: 4px;
            }
        }

        .input-label.hidden-model {
            display: none !important;
        }

        .deepseek-model-selector,
        .minmax-model-selector,
        .glm-model-selector,
        .kimi-model-selector {
            position: relative;
            display: none;
        }

        .deepseek-model-selector.visible,
        .minmax-model-selector.visible,
        .glm-model-selector.visible,
        .kimi-model-selector.visible {
            display: inline-flex;
        }

        .deepseek-model-btn,
        .minmax-model-btn,
        .glm-model-btn,
        .kimi-model-btn {
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

        .deepseek-model-btn:hover,
        .minmax-model-btn:hover,
        .glm-model-btn:hover,
        .kimi-model-btn:hover {
            border-color: #1890ff;
            color: #1890ff;
            background: linear-gradient(135deg, #f0f7ff 0%, #e6f2ff 100%);
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.12);
        }

        .deepseek-model-btn.active-model,
        .minmax-model-btn.active-model,
        .glm-model-btn.active-model,
        .kimi-model-btn.active-model {
            color: #1890ff;
            border-color: #1890ff;
            background: linear-gradient(135deg, #e6f7ff 0%, #d6eeff 100%);
            box-shadow: 0 1px 3px rgba(24, 144, 255, 0.15);
        }

        .deepseek-model-btn .arrow-icon,
        .minmax-model-btn .arrow-icon,
        .glm-model-btn .arrow-icon,
        .kimi-model-btn .arrow-icon {
            width: 12px;
            height: 12px;
            transition: transform 0.3s;
        }

        .deepseek-model-btn.open .arrow-icon,
        .minmax-model-btn.open .arrow-icon,
        .glm-model-btn.open .arrow-icon,
        .kimi-model-btn.open .arrow-icon {
            transform: rotate(180deg);
        }

        .deepseek-model-dropdown,
        .minmax-model-dropdown,
        .glm-model-dropdown,
        .kimi-model-dropdown {
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
            overflow: hidden;
            animation: dropdownFadeUp 0.2s ease;
        }

        @keyframes dropdownFadeUp {
            from {
                opacity: 0;
                transform: translateY(4px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .deepseek-model-dropdown.show,
        .minmax-model-dropdown.show,
        .glm-model-dropdown.show,
        .kimi-model-dropdown.show {
            display: block;
        }

        .deepseek-model-option,
        .minmax-model-option,
        .glm-model-option,
        .kimi-model-option {
            padding: 10px 14px;
            cursor: pointer;
            transition: all 0.15s ease;
            font-size: 12px;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f5f5f5;
        }

        .deepseek-model-option:last-child,
        .minmax-model-option:last-child,
        .glm-model-option:last-child,
        .kimi-model-option:last-child {
            border-bottom: none;
        }

        .deepseek-model-option:hover,
        .minmax-model-option:hover,
        .glm-model-option:hover,
        .kimi-model-option:hover {
            background-color: #f8f9fa;
        }

        .deepseek-model-option.selected,
        .minmax-model-option.selected,
        .glm-model-option.selected,
        .kimi-model-option.selected {
            color: #1890ff;
            background-color: #f0f7ff;
            font-weight: 500;
        }

        .deepseek-model-option .check-icon,
        .minmax-model-option .check-icon,
        .glm-model-option .check-icon,
        .kimi-model-option .check-icon {
            display: none;
        }

        .deepseek-model-option.selected .check-icon,
        .minmax-model-option.selected .check-icon,
        .glm-model-option.selected .check-icon,
        .kimi-model-option.selected .check-icon {
            display: inline;
        }

        .reasoning-effort-selector {
            position: relative;
            display: none;
        }

        .reasoning-effort-selector.visible {
            display: inline-flex;
        }

        .reasoning-effort-btn {
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

        .reasoning-effort-btn:hover {
            border-color: #1890ff;
            color: #1890ff;
            background: linear-gradient(135deg, #f0f7ff 0%, #e6f2ff 100%);
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.12);
        }

        .reasoning-effort-btn.active-effort {
            color: #1890ff;
            border-color: #1890ff;
            background: linear-gradient(135deg, #e6f7ff 0%, #d6eeff 100%);
            box-shadow: 0 1px 3px rgba(24, 144, 255, 0.15);
        }

        .reasoning-effort-btn .arrow-icon {
            width: 12px;
            height: 12px;
            transition: transform 0.3s;
        }

        .reasoning-effort-btn.open .arrow-icon {
            transform: rotate(180deg);
        }

        .reasoning-effort-dropdown {
            position: absolute;
            bottom: 100%;
            left: 0;
            background-color: white;
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 10px;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.12), 0 0 1px rgba(0, 0, 0, 0.05);
            margin-bottom: 6px;
            z-index: 1000;
            min-width: 150px;
            display: none;
            overflow: hidden;
            animation: dropdownFadeUp 0.2s ease;
        }

        .reasoning-effort-dropdown.show {
            display: block;
        }

        .reasoning-effort-option {
            padding: 10px 14px;
            cursor: pointer;
            transition: all 0.15s ease;
            font-size: 12px;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f5f5f5;
        }

        .reasoning-effort-option:last-child {
            border-bottom: none;
        }

        .reasoning-effort-option:hover {
            background-color: #f8f9fa;
        }

        .reasoning-effort-option.selected {
            color: #1890ff;
            background-color: #f0f7ff;
            font-weight: 500;
        }

        .reasoning-effort-option .check-icon {
            display: none;
            color: #1890ff;
            font-size: 13px;
            font-weight: bold;
        }

        .reasoning-effort-option.selected .check-icon {
            display: inline;
        }

        .image-gen-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 12px;
            background-color: rgba(24, 144, 255, 0.15);
            color: #1890ff;
            font-size: 12px;
            cursor: default;
            z-index: 10;
        }

        .image-gen-close {
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
            opacity: 0.8;
            margin-left: 2px;
        }

        .image-gen-close:hover {
            opacity: 1;
        }

        .video-gen-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 12px;
            background-color: rgba(114, 46, 209, 0.15);
            color: #1890ff;
            font-size: 12px;
            cursor: default;
            z-index: 10;
        }
        .video-gen-close {
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
            opacity: 0.6;
        }
        .video-gen-close:hover { opacity: 1; }

        .video-quality-selector, .video-size-selector, .video-fps-selector, .video-duration-selector {
            position: relative;
            display: inline-flex;
        }
        .video-quality-btn, .video-size-btn, .video-fps-btn, .video-duration-btn {
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
        .video-quality-btn:hover, .video-size-btn:hover, .video-fps-btn:hover, .video-duration-btn:hover {
            border-color: #1890ff;
            color: #1890ff;
            background: linear-gradient(135deg, #e6f7ff 0%, #bae7ff 100%);
        }
        .video-quality-btn.open, .video-size-btn.open, .video-fps-btn.open, .video-duration-btn.open {
            color: #1890ff;
            border-color: #1890ff;
            background: linear-gradient(135deg, #e6f7ff 0%, #bae7ff 100%);
        }
        .video-quality-dropdown, .video-size-dropdown, .video-fps-dropdown, .video-duration-dropdown {
            position: absolute;
            bottom: 100%;
            right: 0;
            background-color: white;
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 12px;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.12);
            margin-bottom: 6px;
            z-index: 1000;
            min-width: 140px;
            display: none;
            overflow: hidden;
            animation: dropdownFadeUp 0.2s ease;
            padding: 6px 0;
        }
        .video-quality-dropdown.show, .video-size-dropdown.show, .video-fps-dropdown.show, .video-duration-dropdown.show {
            display: block;
        }
        .video-quality-option, .video-size-option, .video-fps-option, .video-duration-option {
            padding: 8px 14px;
            cursor: pointer;
            font-size: 12px;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: background-color 0.15s;
        }
        .video-quality-option:hover, .video-size-option:hover, .video-fps-option:hover, .video-duration-option:hover {
            background-color: #f5f5f5;
        }
        .video-quality-option .check-icon, .video-size-option .check-icon, .video-fps-option .check-icon, .video-duration-option .check-icon {
            display: none;
        }
        .video-quality-option.selected .check-icon, .video-size-option.selected .check-icon, .video-fps-option.selected .check-icon, .video-duration-option.selected .check-icon {
            display: block;
        }

        .video-audio-toggle { display: inline-flex; }
        .video-audio-btn {
            font-size: 11px;
            color: #555;
            background: linear-gradient(135deg, #fafbfc 0%, #f0f2f5 100%);
            border: 1px solid #e1e4e8;
            padding: 5px 10px;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.25s ease;
            white-space: nowrap;
            user-select: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }
        .video-audio-btn:hover { border-color: #1890ff; color: #1890ff; }
        .video-audio-btn.active {
            color: #1890ff;
            border-color: #1890ff;
            background: linear-gradient(135deg, #e6f7ff 0%, #bae7ff 100%);
        }

        .video-player-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s ease;
        }
        .video-player-overlay.show { display: flex; }
        .video-player-container {
            position: relative;
            max-width: 90vw;
            max-height: 90vh;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
        }
        .video-player-container video {
            display: block;
            max-width: 90vw;
            max-height: 90vh;
        }
        .video-player-close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(0,0,0,0.5);
            color: #fff;
            border: none;
            font-size: 18px;
            cursor: pointer;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        .video-player-close:hover { background: rgba(0,0,0,0.8); }

        .aspect-ratio-selector {
            position: relative;
            display: inline-flex;
            z-index: 10;
        }

        .aspect-ratio-btn {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border: 1px solid #e1e4e8;
            border-radius: 12px;
            background: #fff;
            font-size: 12px;
            color: #333;
            cursor: pointer;
        }

        .aspect-ratio-btn .arrow-icon {
            width: 10px;
            height: 10px;
            transition: transform 0.3s;
        }

        .aspect-ratio-btn.open .arrow-icon {
            transform: rotate(180deg);
        }

        .aspect-ratio-dropdown {
            display: none;
            position: absolute;
            bottom: 100%;
            left: 0;
            margin-bottom: 4px;
            background: #fff;
            border: 1px solid #e1e4e8;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            min-width: 120px;
            z-index: 100;
            overflow: hidden;
        }

        .aspect-ratio-dropdown.show {
            display: block;
        }

        .aspect-ratio-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 14px;
            font-size: 12px;
            color: #333;
            cursor: pointer;
            border-bottom: 1px solid #f5f5f5;
        }

        .aspect-ratio-option:last-child {
            border-bottom: none;
        }

        .aspect-ratio-option:hover {
            background-color: #f8f9fa;
        }

        .aspect-ratio-option .check-icon {
            display: none;
        }

        .aspect-ratio-option.selected {
            color: #1890ff;
        }

        .aspect-ratio-option.selected .check-icon {
            display: block;
        }

        .dynamic-island {
            position: fixed;
            top: 16px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 10000;
            background: #000;
            border-radius: 50px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            display: none;
            user-select: none;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .dynamic-island.visible {
            display: block;
        }

        .dynamic-island.compact {
            width: 120px;
            height: 40px;
            border-radius: 20px;
        }

        @keyframes island-pulse-expand {
            0% { width: 120px; }
            40% { width: 220px; }
            100% { width: 120px; }
        }

        .dynamic-island.pulse-expand {
            animation: island-pulse-expand 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
            transition: none !important;
        }

        .dynamic-island.expanded {
            width: 360px;
            height: 200px;
            border-radius: 40px;
        }

        .island-compact-content {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            opacity: 1;
            transition: opacity 0.2s ease;
        }

        .dynamic-island.expanded .island-compact-content {
            opacity: 0;
            pointer-events: none;
        }

        .island-expanded-content {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            opacity: 0;
            transform: scale(0.9);
            transition: all 0.8s ease 0.3s;
            padding: 20px;
        }

        .dynamic-island.expanded .island-expanded-content {
            opacity: 1;
            transform: scale(1);
        }

        .island-album-art-small {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            object-fit: cover;
            animation: island-pulse 2s infinite;
        }

        @keyframes island-pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .island-waveform {
            display: flex;
            align-items: center;
            gap: 2px;
            height: 20px;
        }

        .island-wave-bar {
            width: 3px;
            background: #3b82f6;
            border-radius: 2px;
            animation: island-wave 1s ease-in-out infinite;
        }

        .island-wave-bar:nth-child(1) { height: 8px; animation-delay: 0s; }
        .island-wave-bar:nth-child(2) { height: 16px; animation-delay: 0.12s; }
        .island-wave-bar:nth-child(3) { height: 12px; animation-delay: 0.24s; }
        .island-wave-bar:nth-child(4) { height: 18px; animation-delay: 0.36s; }
        .island-wave-bar:nth-child(5) { height: 10px; animation-delay: 0.48s; }

        @keyframes island-wave {
            0%, 100% { transform: scaleY(0.4); }
            50% { transform: scaleY(1); }
        }

        .dynamic-island.paused .island-wave-bar {
            animation-play-state: paused;
        }

        .dynamic-island.paused .island-album-art-small {
            animation-play-state: paused;
        }

        .island-player-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            margin-top: 8px;
        }

        .island-album-art-large {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            object-fit: cover;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            flex-shrink: 0;
        }

        .island-song-info {
            color: white;
            flex: 1;
            min-width: 0;
        }

        .island-song-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
            letter-spacing: 0.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #fff;
        }

        .island-artist-name {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.6);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .island-progress-container {
            margin-top: auto;
            margin-bottom: 16px;
        }

        .island-progress-time {
            display: flex;
            justify-content: space-between;
            color: rgba(255, 255, 255, 0.5);
            font-size: 11px;
            margin-bottom: 6px;
        }

        .island-progress-bar {
            width: 100%;
            height: 4px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 2px;
            overflow: hidden;
            cursor: pointer;
            position: relative;
        }

        .island-progress-bar:hover {
            height: 6px;
        }

        .island-progress-fill {
            height: 100%;
            width: 0%;
            background: white;
            border-radius: 2px;
            transition: width 0.1s linear;
        }

        .island-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 10px;
        }

        .island-control-btn {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            transition: transform 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .island-control-btn:hover {
            transform: scale(1.1);
        }

        .island-control-btn svg {
            fill: currentColor;
        }

        .island-control-btn.island-btn-play {
            width: 44px;
            height: 44px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 50%;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .island-logo-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }

        .island-pip-btn {
            background: none;
            border: none;
            color: rgba(255,255,255,0.5);
            cursor: pointer;
            transition: color 0.2s, transform 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .island-pip-btn:hover {
            color: rgba(255,255,255,0.8);
            transform: scale(1.1);
        }

        .island-pip-btn svg {
            fill: currentColor;
        }

        .island-popped-status {
            position: fixed;
            top: 16px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            display: none;
            align-items: center;
            gap: 8px;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: 20px;
            padding: 8px 16px;
            color: rgba(255,255,255,0.7);
            font-size: 12px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
            cursor: pointer;
            user-select: none;
        }

        .island-popped-status.visible {
            display: flex;
        }

        .island-popped-status:hover {
            background: rgba(0, 0, 0, 0.95);
        }

        .island-popped-close {
            background: rgba(255,255,255,0.12);
            border: none;
            color: rgba(255,255,255,0.8);
            padding: 4px 10px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 11px;
            transition: background 0.2s;
        }

        .island-popped-close:hover {
            background: rgba(255,255,255,0.2);
        }

        .island-no-pip-tip {
            position: fixed;
            top: 16px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 10001;
            display: none;
            align-items: center;
            gap: 8px;
            background: rgba(180, 60, 60, 0.9);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 20px;
            padding: 8px 16px;
            color: #fff;
            font-size: 12px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
        }

        .island-no-pip-tip.visible {
            display: flex;
        }

        .dynamic-island.search-mode {
            width: calc(100% - 32px);
            max-width: 400px;
            height: 52px;
            border-radius: 26px;
            background: #FFFFFF;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            cursor: default;
        }

        .dynamic-island.search-mode .island-compact-content,
        .dynamic-island.search-mode .island-expanded-content {
            opacity: 0;
            pointer-events: none;
        }

        .island-search-content {
            position: absolute;
            inset: 0;
            display: none;
            align-items: center;
            padding: 0 16px;
            gap: 10px;
        }

        .dynamic-island.search-mode .island-search-content {
            display: flex;
        }

        .island-search-icon {
            width: 20px;
            height: 20px;
            fill: none;
            stroke: #999;
            stroke-width: 2;
            flex-shrink: 0;
            cursor: pointer;
            transition: stroke 0.2s;
        }

        .island-search-icon:hover {
            stroke: #3b82f6;
        }

        .island-search-input {
            flex: 1;
            height: 100%;
            border: none;
            outline: none;
            font-size: 15px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: transparent;
            color: #333;
        }

        .island-search-input::placeholder {
            color: #999;
        }

        .island-search-cancel {
            font-size: 14px;
            color: #3b82f6;
            cursor: pointer;
            white-space: nowrap;
            padding: 4px 0;
            flex-shrink: 0;
        }

        .island-search-cancel:hover {
            color: #2563eb;
        }

        /* ── Work 模式：进入项目工作区 ── */
        .work-project-bar {
            display: none;
            position: relative;
            z-index: 0;
            height: 39px;
            margin: 0 14px;
            padding: 0 8px;
            align-items: center;
            flex-shrink: 0;
            background-color: #f6f6f6;
            border-radius: 20px 20px 0 0;
            box-shadow: none;
        }

        /*
         * 连接层只负责延伸项目栏背景，由输入框覆盖；项目栏本体就是完整的
         * 39px 可见区域，因此内部内容可以直接通过 Flex 真正居中。
         */
        .work-project-bar::after {
            content: "";
            position: absolute;
            top: 100%;
            right: 0;
            left: 0;
            z-index: 0;
            height: 12px;
            background-color: #f6f6f6;
            pointer-events: none;
        }

        body.work-mode .input-container-wrapper {
            padding-bottom: 6px;
        }

        body.work-mode .input-container {
            position: relative;
            z-index: 2;
        }

        /* 没有待上传文件时不保留原有的 10px 空白。 */
        body.work-mode .upload-container:empty {
            display: none;
            margin-bottom: 0;
            padding: 0;
        }

        body.work-mode .input-wrapper {
            margin-bottom: 0;
            border-color: #e1e1e2;
            border-radius: 20px;
            background-color: #fff;
            box-shadow:
                0 8px 22px rgba(31, 38, 52, 0.08),
                0 2px 6px rgba(31, 38, 52, 0.05),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }

        body.work-mode .work-project-bar {
            display: flex;
        }

        body.work-mode .work-project-bar .work-project-btn,
        body.work-mode .work-project-bar .work-project-icon {
            color: #28292b;
        }

        body.work-mode .work-project-bar .work-project-selector {
            position: relative;
            z-index: 1;
            height: 100%;
            align-items: center;
        }

        body.work-mode .work-project-bar .work-project-arrow {
            color: #8b8d90;
        }

        .work-project-selector {
            position: relative;
            display: inline-flex;
        }

        .work-project-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            background: transparent;
            border: none;
            border-radius: 8px;
            color: #555;
            font-size: 13px;
            line-height: 1.4;
            cursor: pointer;
            transition: background-color 0.2s ease;
            outline: none;
        }

        .work-project-btn:hover {
            background-color: rgba(0, 0, 0, 0.04);
        }

        .work-project-btn.open {
            color: #333;
        }

        .work-project-btn svg {
            display: block;
        }

        .work-project-icon {
            width: 16px;
            height: 16px;
            color: #666;
            flex-shrink: 0;
        }

        .work-project-arrow {
            width: 14px;
            height: 14px;
            color: #999;
            flex-shrink: 0;
            transition: transform 0.25s ease;
        }

        .work-project-btn.open .work-project-arrow {
            transform: rotate(90deg);
        }

        .work-project-dropdown {
            position: absolute;
            bottom: 100%;
            left: 0;
            margin-bottom: 6px;
            background-color: #fff;
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 12px;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.12), 0 0 1px rgba(0, 0, 0, 0.05);
            min-width: 200px;
            z-index: 1000;
            display: none;
            overflow: hidden;
            animation: dropdownFadeUp 0.2s ease;
        }

        .work-project-dropdown.show {
            display: block;
        }

        .work-project-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            font-size: 13px;
            color: #333;
            cursor: pointer;
            transition: background-color 0.15s ease;
            white-space: nowrap;
        }

        .work-project-option:hover {
            background-color: #f8f9fa;
        }

        .work-project-option svg {
            width: 16px;
            height: 16px;
            color: #555;
            flex-shrink: 0;
        }

        .work-project-current {
            font-weight: 500;
        }

        .work-project-divider {
            height: 1px;
            background-color: #f0f0f0;
            margin: 4px 0;
        }

        /* ── Work 模式：项目文件夹弹窗 ── */
        .wp-modal-overlay {
            position: fixed;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            transition: opacity 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .wp-modal-overlay.show {
            display: flex;
            opacity: 1;
        }

        .wp-modal {
            background-color: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25), 0 0 1px rgba(0, 0, 0, 0.1);
            width: 480px;
            max-width: 90vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transform: translateY(12px) scale(0.96);
            transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .wp-modal-overlay.show .wp-modal {
            transform: translateY(0) scale(1);
        }
        .wp-modal-sm {
            width: 380px;
        }

        .wp-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px 14px;
            border-bottom: 1px solid #f0f0f0;
            flex-shrink: 0;
            border-radius: 16px 16px 0 0;
        }
        .wp-modal-title {
            margin: 0;
            font-size: 17px;
            font-weight: 600;
            color: #1a1a1a;
        }
        .wp-modal-close {
            background: transparent;
            border: none;
            font-size: 24px;
            line-height: 1;
            color: #999;
            cursor: pointer;
            padding: 0 4px;
            border-radius: 6px;
            transition: background-color 0.15s cubic-bezier(0.4, 0, 0.2, 1), color 0.15s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .wp-modal-close:hover {
            background-color: #f5f5f5;
            color: #333;
        }

        .wp-modal-body {
            padding: 18px 22px;
            overflow-y: auto;
            flex: 1;
            min-height: 0;
        }
        .wp-modal-text {
            margin: 0;
            color: #555;
            font-size: 14px;
            line-height: 1.6;
        }
        .wp-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 22px 18px;
            border-top: 1px solid #f0f0f0;
            flex-shrink: 0;
            border-radius: 0 0 16px 16px;
            background-color: #fff;
        }

        .wp-field {
            margin-bottom: 16px;
        }
        .wp-field:last-child {
            margin-bottom: 0;
        }
        .wp-label {
            display: block;
            font-size: 13px;
            color: #666;
            margin-bottom: 6px;
            font-weight: 500;
        }
        .wp-input {
            width: 100%;
            box-sizing: border-box;
            padding: 9px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            color: #1a1a1a;
            background-color: #fff;
            transition: border-color 0.15s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
        }
        .wp-input:focus {
            border-color: #4a4a4a;
            box-shadow: 0 0 0 3px rgba(74, 74, 74, 0.08);
        }
        .wp-search-input {
            margin-bottom: 12px;
        }

        .wp-path-row {
            display: flex;
            gap: 8px;
            align-items: stretch;
        }
        .wp-path-input {
            flex: 1;
            min-width: 0;
            font-family: "Cascadia Mono", "Consolas", "Courier New", monospace;
            font-size: 13px;
        }
        .wp-pick-btn {
            flex-shrink: 0;
            padding: 8px 16px;
            font-size: 13px;
            line-height: 1;
            font-weight: 500;
        }
        .wp-path-display {
            flex: 1;
            padding: 9px 12px;
            border: 1px dashed #d0d0d0;
            border-radius: 8px;
            font-size: 13px;
            color: #888;
            background-color: #fafafa;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: flex;
            align-items: center;
        }
        .wp-path-display.has-value {
            color: #1a1a1a;
            border-style: solid;
            background-color: #f5f7fa;
        }

        .wp-btn-primary,
        .wp-btn-secondary {
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.15s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.15s cubic-bezier(0.4, 0, 0.2, 1), color 0.15s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid transparent;
            outline: none;
            white-space: nowrap;
        }
        .wp-btn-primary {
            background-color: #1a1a1a;
            color: #fff;
        }
        .wp-btn-primary:hover {
            background-color: #333;
        }
        .wp-btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .wp-btn-secondary {
            background-color: #fff;
            color: #555;
            border-color: #e0e0e0;
        }
        .wp-btn-secondary:hover {
            background-color: #f8f8f8;
            border-color: #c0c0c0;
        }

        .wp-folder-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 320px;
            overflow-y: auto;
        }
        .wp-folder-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid #eee;
            border-radius: 10px;
            background-color: #fff;
            transition: border-color 0.15s cubic-bezier(0.4, 0, 0.2, 1), background-color 0.15s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .wp-folder-item:hover {
            border-color: #d0d0d0;
            background-color: #fafafa;
        }
        .wp-folder-info {
            flex: 1;
            min-width: 0;
        }
        .wp-folder-name {
            font-size: 14px;
            color: #1a1a1a;
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .wp-folder-path {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .wp-folder-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }
        .wp-icon-btn {
            background: transparent;
            border: none;
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.15s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .wp-icon-btn.select {
            color: #1a1a1a;
        }
        .wp-icon-btn.select:hover {
            background-color: #1a1a1a;
            color: #fff;
        }
        .wp-icon-btn.delete {
            color: #c05050;
        }
        .wp-icon-btn.delete:hover {
            background-color: #fbeaea;
        }

        .wp-empty {
            text-align: center;
            padding: 32px 20px;
            color: #999;
        }
        .wp-empty-icon {
            font-size: 40px;
            margin-bottom: 12px;
            opacity: 0.6;
        }
        .wp-empty-text {
            font-size: 15px;
            color: #666;
            margin-bottom: 4px;
        }
        .wp-empty-hint {
            font-size: 13px;
            color: #aaa;
            margin-bottom: 16px;
        }

        .wp-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: wp-spin 0.6s linear infinite;
            vertical-align: middle;
        }
        .wp-spinner.dark {
            border-color: rgba(0, 0, 0, 0.15);
            border-top-color: #666;
        }
        @keyframes wp-spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 660px) {
            .wp-modal {
                width: 90vw;
                max-height: 90vh;
            }
            .wp-modal-sm {
                width: 90vw;
            }
            .wp-path-row {
                flex-direction: column;
            }
            .wp-path-display {
                width: 100%;
            }
            .wp-modal-footer {
                flex-direction: column-reverse;
            }
            .wp-btn-primary,
            .wp-btn-secondary {
                width: 100%;
            }
            .wp-folder-item {
                flex-wrap: wrap;
            }
        }
