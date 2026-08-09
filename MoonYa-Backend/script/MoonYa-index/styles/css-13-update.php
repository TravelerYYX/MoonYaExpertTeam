        /* 版本更新弹窗样式 */
        .update-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            z-index: 10000;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .update-modal-overlay.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        
        .update-modal-container {
            background: #ffffff;
            border-radius: 16px;
            width: 100%;
            max-width: 480px;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            transform: scale(0.95) translateY(10px);
            transition: all 0.3s ease-out;
        }
        
        .update-modal-overlay.show .update-modal-container {
            transform: scale(1) translateY(0);
        }
        
        .update-modal-media {
            position: relative;
            width: 100%;
            background: #000;
            aspect-ratio: 16 / 9;
            overflow: hidden;
        }
        
        .update-modal-media video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            cursor: pointer;
        }
        
        .update-modal-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        
        .update-video-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.3);
            cursor: pointer;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
        }
        
        .update-video-overlay.show {
            opacity: 1;
            pointer-events: auto;
        }
        
        .update-play-btn {
            width: 56px;
            height: 56px;
            background: rgba(255,255,255,0.95);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            transition: transform 0.2s;
        }
        
        .update-play-btn:hover {
            transform: scale(1.1);
        }
        
        .update-play-btn::after {
            content: '';
            width: 0;
            height: 0;
            border-left: 18px solid #1a1a1a;
            border-top: 11px solid transparent;
            border-bottom: 11px solid transparent;
            margin-left: 4px;
        }
        
        .update-mute-toggle {
            position: absolute;
            bottom: 12px;
            right: 12px;
            width: 32px;
            height: 32px;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(8px);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: none;
            z-index: 10;
            transition: background 0.2s;
            color: white;
        }
        
        .update-mute-toggle:hover {
            background: rgba(0,0,0,0.6);
        }
        
        .update-mute-toggle svg {
            width: 16px;
            height: 16px;
        }
        
        .update-modal-content {
            padding: 20px 24px 24px;
            text-align: left;
        }
        
        .update-modal-title {
            font-size: 16px;
            font-weight: 600;
            color: #111111;
            margin-bottom: 6px;
            line-height: 1.4;
        }
        
        .update-modal-desc {
            font-size: 14px;
            color: #666666;
            line-height: 1.6;
            margin-bottom: 20px;
            max-height: 150px;
            overflow-y: auto;
        }
        
        .update-modal-desc::-webkit-scrollbar {
            width: 4px;
        }
        
        .update-modal-desc::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .update-modal-desc::-webkit-scrollbar-thumb {
            background: #ddd;
            border-radius: 2px;
        }
        
        .update-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .update-btn-confirm {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            outline: none;
            transition: all 0.2s;
            background: #f2f2f2;
            color: #333333;
        }
        
        .update-btn-confirm:hover {
            background: #e5e5e5;
        }
        
        .update-btn-confirm.btn-countdown {
            background: #e8e8e8;
            color: #999;
            cursor: not-allowed;
        }
        
        .update-btn-confirm.btn-countdown:hover {
            background: #e8e8e8;
        }
        
        @media (max-width: 480px) {
            .update-modal-content {
                padding: 18px 20px 20px;
            }
            .update-modal-title {
                font-size: 15px;
            }
            .update-modal-desc {
                font-size: 13px;
            }
            .update-btn-confirm {
                font-size: 13px;
                padding: 8px 14px;
            }
        }

