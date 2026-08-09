        /* 音乐卡片样式 */
        .music-card-container {
            display: flex;
            flex-direction: column;
            background: #f5f5f5;
            border-radius: 12px;
            margin: 10px 0;
            width: 100%;
            max-width: 600px;
            overflow: hidden;
            user-select: none;
        }
        
        /* 顶部汽水音乐LOGO区域 */
        .music-header {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            background: #f5f5f5;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .music-header-logo {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            margin-right: 8px;
            object-fit: cover;
        }
        
        .music-header-text {
            font-size: 14px;
            font-weight: 500;
            color: #999;
        }
        
        /* 搜索音乐按钮 */
        .music-search-btn {
            width: calc(100% - 32px);
            margin: 0 16px 16px 16px;
            padding: 12px 0;
            background: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #333;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .music-search-btn:hover {
            background: #f0f0f0;
        }
        
        .music-search-btn svg {
            width: 16px;
            height: 16px;
            fill: none;
            stroke: #666;
            stroke-width: 2;
        }
        
        .music-card {
            display: flex;
            align-items: center;
            padding: 16px;
            background: transparent;
            transition: background 0.2s;
        }
        
        .music-card:first-child {
            padding-top: 20px;
        }
        
        .music-card:last-child {
            padding-bottom: 20px;
        }
        
        .music-card:hover {
            background: #e8e8e8;
        }
        
        .music-card:not(:last-child) {
            border-bottom: 1px solid #f0f0f0;
        }
        
        .music-logo {
            width: 56px;
            height: 56px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
            margin-right: 12px;
        }
        
        .music-info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .music-name {
            font-size: 15px;
            font-weight: 500;
            color: #333;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.4;
        }
        
        .music-artist {
            font-size: 13px;
            color: #999;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.4;
        }
        
        /* 播放按钮容器 - 包含进度条 */
        .music-play-wrapper {
            position: relative;
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            margin-left: 8px;
        }
        
        /* 圆形进度条 */
        .music-progress-ring {
            position: absolute;
            top: 0;
            left: 0;
            width: 44px;
            height: 44px;
            transform: rotate(-90deg);
        }
        
        .music-progress-ring-circle {
            fill: none;
            stroke: #e8e8e8;
            stroke-width: 2;
        }
        
        .music-progress-ring-progress {
            fill: none;
            stroke: #333;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-dasharray: 126;
            stroke-dashoffset: 126;
            transition: stroke-dashoffset 0.1s linear;
        }
        
        /* 播放/暂停按钮 */
        .music-play-btn {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #333;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .music-play-btn:hover {
            background: #000;
            transform: translate(-50%, -50%) scale(1.05);
        }
        
        /* 播放图标 */
        .music-play-btn .play-icon {
            width: 0;
            height: 0;
            border-left: 10px solid white;
            border-top: 6px solid transparent;
            border-bottom: 6px solid transparent;
            margin-left: 2px;
        }
        
        /* 暂停图标 */
        .music-play-btn .pause-icon {
            display: none;
            width: 10px;
            height: 12px;
            position: relative;
        }
        
        .music-play-btn .pause-icon::before,
        .music-play-btn .pause-icon::after {
            content: '';
            position: absolute;
            width: 3px;
            height: 100%;
            background: white;
            border-radius: 1px;
        }
        
        .music-play-btn .pause-icon::before {
            left: 0;
        }
        
        .music-play-btn .pause-icon::after {
            right: 0;
        }
        
        /* 播放状态 */
        .music-play-btn.playing .play-icon {
            display: none;
        }
        
        .music-play-btn.playing .pause-icon {
            display: block;
        }
        
        .music-section-title {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .horoscope-card-container {
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 16px;
            margin: 10px 0;
            width: 100%;
            max-width: 600px;
            overflow: hidden;
            user-select: none;
            border: 1px solid #e8e8e8;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }

        .horoscope-header {
            display: flex;
            align-items: center;
            padding: 14px 18px;
            background: #fff;
            border-bottom: 1px solid #f0f0f0;
        }

        .horoscope-header-logo {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            margin-right: 8px;
            object-fit: cover;
        }

        .horoscope-header-text {
            font-size: 13px;
            font-weight: 500;
            color: #999;
        }

        .horoscope-zodiac-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            padding: 14px 18px;
        }

        .horoscope-zodiac-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 4px;
            border-radius: 12px;
            background: #f7f7f7;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }

        .horoscope-zodiac-item:hover {
            background: #eef2ff;
            border-color: #c7d2fe;
        }

        .horoscope-zodiac-item.selected {
            border-color: #4f46e5;
            background: #eef2ff;
        }

        .horoscope-zodiac-emoji {
            font-size: 1.5rem;
            margin-bottom: 4px;
        }

        .horoscope-zodiac-name {
            font-size: 11px;
            color: #666;
            letter-spacing: 0.5px;
        }

        .horoscope-zodiac-item.selected .horoscope-zodiac-name {
            color: #4f46e5;
            font-weight: 600;
        }

        .horoscope-time-selector {
            display: flex;
            gap: 8px;
            padding: 0 18px 14px 18px;
        }

        .horoscope-time-btn {
            padding: 5px 14px;
            border-radius: 20px;
            background: #f7f7f7;
            border: none;
            color: #888;
            cursor: pointer;
            font-size: 12px;
            transition: 0.2s;
        }

        .horoscope-time-btn:hover {
            background: #eef2ff;
            color: #4f46e5;
        }

        .horoscope-time-btn.active {
            background: #4f46e5;
            color: #fff;
            font-weight: 500;
        }

        .horoscope-fetch-btn {
            width: calc(100% - 36px);
            margin: 0 18px 14px 18px;
            padding: 11px 0;
            background: #111;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            color: #fff;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .horoscope-fetch-btn:hover {
            background: #333;
            transform: translateY(-1px);
        }

        .horoscope-fetch-btn:disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        .horoscope-result {
            padding: 0 18px 18px 18px;
        }

        .horoscope-result-card {
            background: #fff;
            border-radius: 14px;
            padding: 0;
            animation: horoscopeFadeIn 0.4s ease;
        }

        @keyframes horoscopeFadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .horoscope-result-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .horoscope-result-title {
            font-size: 16px;
            font-weight: 600;
            color: #111;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .horoscope-score-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            background: #111;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
        }

        .horoscope-score-badge span {
            font-size: 11px;
            font-weight: 400;
            opacity: 0.8;
        }

        .horoscope-summary {
            font-size: 13px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 16px;
            padding: 12px 14px;
            background: #f7f7f7;
            border-radius: 10px;
        }

        .horoscope-fortune-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 14px;
        }

        .horoscope-fortune-item {
            padding: 12px;
            border-radius: 10px;
            background: #f7f7f7;
            border-left: 3px solid #4f46e5;
        }

        .horoscope-fortune-item.love { border-left-color: #ec4899; }
        .horoscope-fortune-item.work { border-left-color: #06b6d4; }
        .horoscope-fortune-item.wealth { border-left-color: #f59e0b; }
        .horoscope-fortune-item.health { border-left-color: #10b981; }

        .horoscope-fortune-label {
            font-size: 11px;
            color: #999;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .horoscope-fortune-value {
            font-size: 12px;
            color: #333;
            line-height: 1.5;
        }

        .horoscope-detail-section {
            padding-top: 12px;
            border-top: 1px solid #f0f0f0;
        }

        .horoscope-detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
        }

        .horoscope-detail-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 12px;
        }

        .horoscope-detail-label { color: #999; }
        .horoscope-detail-value { color: #333; font-weight: 500; }

        .horoscope-pairing {
            margin-bottom: 12px;
            font-size: 12px;
        }

        .horoscope-pairing-label {
            color: #999;
        }

        .horoscope-pairing-value {
            color: #4f46e5;
            font-weight: 600;
        }

        .horoscope-loading {
            padding: 24px;
            text-align: center;
            color: #999;
            font-size: 13px;
        }

        .horoscope-loading-spinner {
            width: 24px;
            height: 24px;
            border: 3px solid #f0f0f0;
            border-top-color: #111;
            border-radius: 50%;
            animation: horoscopeSpin 0.8s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes horoscopeSpin {
            to { transform: rotate(360deg); }
        }

        .weather-card-container {
            width: fit-content;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 10px auto;
            animation: horoscopeFadeIn 0.4s ease;
            white-space: normal;
        }
        .weather-card-container::before {
            width: 100px; height: 100px;
            content: ""; position: absolute;
            background-color: rgb(144,161,255);
            z-index: 0; border-radius: 50%;
            left: 100px; top: 50px;
            transition: all 1s;
            pointer-events: none;
        }
        .weather-card-container:hover::before { transform: translate(-50px, 50px); }
        .weather-card {
            position: relative;
            width: 260px;
            min-height: 300px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 20px 15px;
            border-radius: 10px;
            backdrop-filter: blur(30px);
            -webkit-backdrop-filter: blur(30px);
            background-color: rgba(65, 65, 65, 0.308);
            border: 1px solid rgba(255, 255, 255, 0.089);
            color: white;
            text-align: center;
            overflow: visible;
            z-index: 1;
            user-select: none;
            white-space: normal;
        }
        .weather-card * {
            white-space: normal;
        }
        .weather-card .wave-loader {
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg,
                transparent 0%,
                rgba(255,255,255,0.3) 45%,
                rgba(255,255,255,0.5) 50%,
                rgba(255,255,255,0.3) 55%,
                transparent 100%);
            z-index: 10;
            animation: weatherWaveMove 1.5s ease-in-out infinite;
            pointer-events: none;
            display: none;
        }
        .weather-card.loading .wave-loader { display: block; }
        @keyframes weatherWaveMove {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        .weather-city-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            margin-bottom: 4px;
        }
        .weather-city-input {
            font-weight: 700;
            font-size: 0.9em;
            letter-spacing: 1.2px;
            color: white;
            background: transparent;
            border: none;
            border-bottom: 1px dashed rgba(255,255,255,0.5);
            text-align: center;
            outline: none;
            width: 120px;
            padding: 2px 4px;
            font-family: inherit;
        }
        .weather-city-input:focus { border-bottom-color: #fff; }
        .weather-city-input::placeholder { color: rgba(255,255,255,0.4); }
        .weather-search-btn {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            font-size: 0.85rem;
            padding: 4px 10px;
            border-radius: 15px;
            cursor: pointer;
            transition: all 0.28s;
            white-space: nowrap;
            backdrop-filter: blur(5px);
        }
        .weather-search-btn:hover {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.5);
        }
        .weather-search-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .weather-text {
            font-weight: 500;
            font-size: 0.7em;
            letter-spacing: 1.2px;
            color: rgb(197,197,197);
            margin-top: 6px;
        }
        .weather-icon { font-size: 48px; line-height: 1; margin: 8px 0; }
        .weather-temp { font-size: 1.8em; font-weight: 700; color: white; margin: 6px 0; }
        .weather-error-card {
            width: 260px;
            min-height: 160px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            border-radius: 10px;
            backdrop-filter: blur(30px);
            background-color: rgba(65,65,65,0.308);
            border: 1px solid rgba(255,255,255,0.089);
            color: white;
            text-align: center;
            gap: 10px;
            margin: 0 auto;
            z-index: 1;
            position: relative;
        }
        .weather-error-card .weather-city-input {
            background: rgba(255,255,255,0.1);
            border-bottom: 1px dashed rgba(255,255,255,0.3);
            color: white;
            text-align: center;
        }
        .weather-error-card .weather-city-input::placeholder { color: #ccc; }
        .weather-error-card .weather-search-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
        }
        
        .main-footer {
            position: absolute;
            bottom: 20px;
            font-size: 12px;
            color: #999;
        }

