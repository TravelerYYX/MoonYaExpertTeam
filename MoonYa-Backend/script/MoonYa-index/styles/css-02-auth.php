        /* 登录注册弹窗 - Liquid Glass */
        .auth-overlay {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.35);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        .auth-overlay.show { display: flex; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        
        .auth-modal {
            position: relative;
            display: flex;
            width: 960px;
            height: 540px;
            border-radius: 24px;
            overflow: hidden;
            opacity: 0;
            animation: materialize 1s cubic-bezier(0.22, 1, 0.36, 1) 0.1s forwards;
        }
        @keyframes materialize {
            from { opacity: 0; filter: blur(12px); transform: scale(0.97); }
            to { opacity: 1; filter: blur(0); transform: scale(1); }
        }
        .auth-modal.liquid-glass {
            background: rgba(255,255,255,0.72);
            backdrop-filter: blur(40px) saturate(1.6);
            -webkit-backdrop-filter: blur(40px) saturate(1.6);
            border: 1px solid rgba(255,255,255,0.6);
            box-shadow: 0 24px 80px rgba(0,0,0,0.08), 0 4px 12px rgba(0,0,0,0.03), inset 0 1px 1px rgba(255,255,255,0.8);
        }
        .auth-modal.liquid-glass::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 2px;
            background: linear-gradient(180deg, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.2) 40%, rgba(255,255,255,0) 100%);
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            pointer-events: none;
            opacity: 0.7;
            mix-blend-mode: overlay;
        }
        .auth-modal.liquid-glass::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: radial-gradient(ellipse 80% 50% at 50% 0%, rgba(255,255,255,0.6) 0%, transparent 60%);
            pointer-events: none;
            mix-blend-mode: overlay;
        }
        .modal-glow {
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            pointer-events: none;
            transform: translate(-50%, -50%);
            left: var(--mouse-x, 50%);
            top: var(--mouse-y, 50%);
            opacity: 0;
            transition: opacity 0.5s ease;
            z-index: 2;
            mix-blend-mode: overlay;
        }
        .auth-modal:hover .modal-glow { opacity: 0.6; }
        
        .auth-modal-left {
            position: relative;
            width: 66.666%;
            height: 100%;
            overflow: hidden;
            flex-shrink: 0;
        }
        .auth-modal-left .image-wrapper {
            position: absolute;
            inset: 0;
        }
        .auth-modal-left .image-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: brightness(0.95) saturate(1.05);
            transition: transform 8s ease;
        }
        .auth-modal:hover .image-wrapper img { transform: scale(1.03); }
        .auth-modal-left .image-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 50%, rgba(0,0,0,0.15) 100%);
            backdrop-filter: blur(0.5px);
        }
        .auth-modal-left .left-content {
            position: absolute;
            bottom: 48px;
            left: 48px;
            z-index: 3;
            color: #FFF;
            text-shadow: 0 2px 16px rgba(0,0,0,0.2);
        }
        .auth-modal-left .left-content h2 {
            font-size: 36px;
            font-weight: 600;
            letter-spacing: -0.03em;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #FFF 0%, rgba(255,255,255,0.85) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .auth-modal-left .left-content p {
            font-size: 14px;
            font-weight: 400;
            color: rgba(255,255,255,0.8);
            letter-spacing: 0.05em;
        }
        
        .auth-modal-right {
            position: relative;
            z-index: 3;
            width: 33.334%;
            height: 100%;
            display: flex;
            flex-direction: column;
            padding: 36px 32px 32px;
            background: rgba(255,255,255,0.72);
            backdrop-filter: blur(40px) saturate(1.4);
            -webkit-backdrop-filter: blur(40px) saturate(1.4);
            border-left: 1px solid rgba(255,255,255,0.5);
            box-shadow: inset 8px 0 24px rgba(255,255,255,0.4);
        }
        .auth-tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 28px;
            flex-shrink: 0;
        }
        .auth-tab-btn {
            flex: 1;
            padding: 8px 0;
            border: none;
            background: transparent;
            color: #999;
            font-family: inherit;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border-radius: 10px;
            transition: all 0.4s cubic-bezier(0.22, 1, 0.36, 1);
            letter-spacing: 0.01em;
            white-space: nowrap;
        }
        .auth-tab-btn:hover { color: #666; background: rgba(255,255,255,0.55); }
        .auth-tab-btn.active {
            color: #333;
            background: rgba(255,255,255,0.85);
            border: 1px solid rgba(0,0,0,0.06);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .auth-form-container {
            position: relative;
            flex: 1;
            width: 100%;
            perspective: 900px;
        }
        .auth-form {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            visibility: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 14px;
            transform-style: preserve-3d;
            backface-visibility: hidden;
        }
        .auth-form.active, .auth-form.entering {
            opacity: 1;
            visibility: visible;
            position: relative;
            z-index: 2;
        }
        .auth-form.enter-from-left { animation: authEnterFromLeft 0.95s cubic-bezier(0.22, 1, 0.36, 1) forwards; }
        .auth-form.enter-from-right { animation: authEnterFromRight 0.95s cubic-bezier(0.22, 1, 0.36, 1) forwards; }
        .auth-form.leave-to-left { animation: authLeaveToLeft 0.85s cubic-bezier(0.22, 1, 0.36, 1) forwards; z-index: 1; }
        .auth-form.leave-to-right { animation: authLeaveToRight 0.85s cubic-bezier(0.22, 1, 0.36, 1) forwards; z-index: 1; }
        @keyframes authEnterFromLeft {
            0% { opacity: 0; transform: translateX(-120px) translateZ(-80px) rotateY(-32deg) rotateZ(-4deg); }
            100% { opacity: 1; transform: translateX(0) translateZ(0) rotateY(0) rotateZ(0); }
        }
        @keyframes authEnterFromRight {
            0% { opacity: 0; transform: translateX(120px) translateZ(-80px) rotateY(32deg) rotateZ(4deg); }
            100% { opacity: 1; transform: translateX(0) translateZ(0) rotateY(0) rotateZ(0); }
        }
        @keyframes authLeaveToLeft {
            0% { opacity: 1; transform: translateX(0) translateZ(0) rotateY(0) rotateZ(0); }
            100% { opacity: 0; transform: translateX(-120px) translateZ(-80px) rotateY(-32deg) rotateZ(-4deg); }
        }
        @keyframes authLeaveToRight {
            0% { opacity: 1; transform: translateX(0) translateZ(0) rotateY(0) rotateZ(0); }
            100% { opacity: 0; transform: translateX(120px) translateZ(-80px) rotateY(32deg) rotateZ(4deg); }
        }
        .auth-form .input-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .auth-form .input-group label {
            font-size: 12px;
            font-weight: 500;
            color: #777;
            padding-left: 2px;
            letter-spacing: 0.03em;
        }
        .auth-form .input-group input {
            width: 100%;
            padding: 10px 12px;
            background: rgba(255,255,255,0.7);
            border: 1px solid rgba(0,0,0,0.05);
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
            color: #444;
            outline: none;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        .auth-form .input-group input::placeholder { color: #BBB; }
        .auth-form .input-group input:focus {
            background: rgba(255,255,255,0.95);
            border-color: rgba(0,0,0,0.12);
            box-shadow: 0 0 0 3px rgba(0,0,0,0.04);
        }
        .auth-form .input-row {
            display: flex;
            gap: 8px;
        }
        .auth-form .input-row input { flex: 1; }
        .auth-btn-primary {
            width: 100%;
            padding: 11px;
            margin-top: 4px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #4A4A4A 0%, #2C2C2C 100%);
            color: #FFF;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            letter-spacing: 0.03em;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .auth-btn-primary::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
            transition: left 0.6s ease;
        }
        .auth-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 22px rgba(0,0,0,0.25); background: linear-gradient(135deg, #555 0%, #333 100%); }
        .auth-btn-primary:hover::after { left: 100%; }
        .auth-btn-secondary {
            padding: 8px 12px;
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 8px;
            background: rgba(255,255,255,0.6);
            color: #666;
            font-family: inherit;
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.3s ease;
        }
        .auth-btn-secondary:hover { background: rgba(255,255,255,0.9); border-color: rgba(0,0,0,0.12); color: #444; }
        .auth-btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
        .auth-form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            padding: 0 2px;
            margin-top: -2px;
        }
        .auth-link { color: #888; text-decoration: none; font-weight: 500; transition: color 0.3s; font-size: 11px; }
        .auth-link:hover { color: #555; text-decoration: underline; }
        .auth-close-btn {
            position: absolute;
            top: 12px;
            right: 16px;
            z-index: 10;
            width: 28px;
            height: 28px;
            border: none;
            background: rgba(255,255,255,0.5);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            color: #999;
            font-size: 14px;
        }
        .auth-close-btn:hover { background: rgba(255,255,255,0.8); color: #555; }
        
        .auth-btn-loading {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.2);
            border-top-color: rgba(255,255,255,0.8);
            border-radius: 50%;
            animation: authSpin 0.7s linear infinite;
            vertical-align: middle;
        }
        .auth-btn-secondary .auth-btn-loading {
            border-color: rgba(0,0,0,0.1);
            border-top-color: #666;
        }
        @keyframes authSpin {
            to { transform: rotate(360deg); }
        }
        
        .auth-qq-input-wrapper {
            position: relative;
        }
        .auth-qq-input-wrapper input {
            padding-right: 80px !important;
        }
        .auth-qq-suffix {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 13px;
            color: #999;
            pointer-events: none;
            user-select: none;
        }
        
        @media (max-width: 1024px) {
            .auth-modal { width: 90vw; height: auto; min-height: 520px; max-height: 90vh; }
            .auth-modal-left { width: 60%; }
            .auth-modal-right { width: 40%; padding: 28px 24px; }
        }
        @media (max-width: 768px) {
            .auth-modal { width: 100%; height: auto; flex-direction: column; }
            .auth-modal-left { width: 100%; height: 200px; }
            .auth-modal-right { width: 100%; padding: 24px; border-left: none; border-top: 1px solid rgba(255,255,255,0.4); }
            .auth-modal-left .left-content { bottom: 24px; left: 24px; }
        }
        
        .container {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        
