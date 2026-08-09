        /* Toast提示样式 */
        .toast {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            z-index: 10000;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        
        .toast.show {
            opacity: 1;
        }
        
        /* 发送验证码加载动画 */
        .send-code-loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #1890ff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 6px;
            vertical-align: middle;
        }
        
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .dropdown-arrow {
            font-size: 12px;
            color: #999;
        }
        
        .sidebar-footer {
            padding: 10px 12px;
            border-top: 1px solid #e8e8e8;
            margin-top: auto;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .user-avatar {
            position: relative;
        }
        
        .user-avatar img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .user-details {
            flex: 1;
        }
        
        .user-name {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 4px;
        }
        
        .user-qq {
            display: none;
        }
        
        .qq-login-btn {
            width: 100%;
            padding: 8px 0;
            border: 1px solid #1890ff;
            border-radius: 6px;
            background-color: white;
            color: #1890ff;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .qq-login-btn:hover {
            background-color: #e6f7ff;
        }
        
