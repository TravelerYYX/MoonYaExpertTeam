<?php
session_start();
require_once __DIR__ . '/../config.php';
$config = require __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    $stmt = $pdo->prepare("SELECT id, username, email, real_name, gender, avatar, bio, likes_count, password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: ../index.php');
        exit;
    }
    
    $hasPassword = !empty($user['password']);

    $defaultAvatar = '/image/mr.png';
    $userAvatar = !empty($user['avatar']) ? '../' . $user['avatar'] : $defaultAvatar;
} catch (Exception $e) {
    die('数据库错误: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>用户中心 - MoonYa</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif;
            background-color: #f4f4f4;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .mobile-container {
            max-width: 430px;
            margin: 0 auto;
            background: #f4f4f4;
            min-height: 100vh;
            position: relative;
        }

        .top-nav {
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            position: relative;
        }

        .top-nav .back-btn {
            width: 24px;
            height: 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
        }

        .top-nav .nav-title {
            font-size: 16px;
            font-weight: 500;
            color: #333;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
        }

        .top-nav .nav-placeholder {
            width: 24px;
        }

        .header-section {
            padding: 24px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 12px;
        }

        .avatar-wrapper {
            position: relative;
            width: 80px;
            height: 80px;
        }

        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #f0f0f0;
        }

        .camera-icon {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 28px;
            height: 28px;
            background: white;
            border-radius: 50%;
            border: 2px solid #e8e8e8;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .camera-icon img {
            width: 14px;
            height: 14px;
        }

        .user-name {
            font-size: 20px;
            font-weight: 600;
            color: #1a1a1a;
            margin-top: 16px;
        }

        .user-id {
            font-size: 13px;
            color: #999;
            margin-top: 4px;
        }

        .manage-btn {
            margin-top: 16px;
            padding: 8px 20px;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            font-size: 14px;
            color: #333;
            cursor: pointer;
            transition: all 0.2s;
        }

        .manage-btn:active {
            background: #f5f5f5;
        }

        .content-area {
            padding: 12px 16px 24px;
        }

        .card {
            background: #fcfcfc;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 14px 16px;
            cursor: pointer;
            transition: background-color 0.2s;
            border-bottom: 1px solid #f0f0f0;
            text-decoration: none;
            color: inherit;
        }

        .menu-item:last-child {
            border-bottom: none;
        }

        .menu-item:active {
            background-color: #f9f9f9;
        }

        .menu-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .menu-icon svg {
            width: 18px;
            height: 18px;
        }

        .menu-icon.green {
            background: #e8f5e9;
            color: #4caf50;
        }

        .menu-icon.green svg {
            fill: #4caf50;
        }

        .menu-icon.blue {
            background: #e3f2fd;
            color: #2196f3;
        }

        .menu-icon.blue svg {
            fill: #2196f3;
        }

        .menu-icon.purple {
            background: #f3e5f5;
            color: #9c27b0;
        }

        .menu-icon.purple svg {
            fill: #9c27b0;
        }

        .menu-icon.orange {
            background: #fff3e0;
            color: #ff9800;
        }

        .menu-icon.orange svg {
            fill: #ff9800;
        }

        .menu-icon.red {
            background: #ffebee;
            color: #f44336;
        }

        .menu-icon.red svg {
            fill: #f44336;
        }

        .menu-icon.teal {
            background: #e0f2f1;
            color: #009688;
        }

        .menu-icon.teal svg {
            fill: #009688;
        }

        .menu-icon.indigo {
            background: #e8eaf6;
            color: #3f51b5;
        }

        .menu-icon.indigo svg {
            fill: #3f51b5;
        }

        .menu-icon.pink {
            background: #fce4ec;
            color: #e91e63;
        }

        .menu-icon.pink svg {
            fill: #e91e63;
        }

        .menu-icon.cyan {
            background: #e0f7fa;
            color: #00bcd4;
        }

        .menu-icon.cyan svg {
            fill: #00bcd4;
        }

        .menu-icon.amber {
            background: #fff8e1;
            color: #ffc107;
        }

        .menu-icon.amber svg {
            fill: #ffc107;
        }

        .menu-text {
            flex: 1;
            font-size: 15px;
            color: #1a1a1a;
        }

        .menu-extra {
            font-size: 14px;
            color: #999;
            margin-right: 4px;
        }

        .menu-arrow {
            font-size: 18px;
            color: #ccc;
            line-height: 1;
        }

        .toggle-switch {
            width: 44px;
            height: 24px;
            background: #ccc;
            border-radius: 12px;
            position: relative;
            cursor: pointer;
            transition: background 0.3s;
            flex-shrink: 0;
        }

        .toggle-switch.active {
            background: #4caf50;
        }

        .toggle-switch::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            background: #fff;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: transform 0.3s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }

        .toggle-switch.active::after {
            transform: translateX(20px);
        }

        .sub-text {
            font-size: 12px;
            color: #999;
            padding: 0 16px 12px 60px;
            line-height: 1.5;
        }

        .sub-text a {
            color: #2196f3;
            text-decoration: none;
        }

        .alert {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            animation: slideDown 0.3s ease;
            display: none;
            white-space: nowrap;
        }

        .alert.show {
            display: block;
        }

        .alert.success {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            color: #52c41a;
        }

        .alert.error {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: #ff4d4f;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }

        .share-panel-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            z-index: 9998; opacity: 0; visibility: hidden;
            transition: all 0.3s ease;
        }
        .share-panel-overlay.show { opacity: 1; visibility: visible; }

        .share-panel {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: #fff; border-radius: 20px 20px 0 0;
            z-index: 9999; padding: 24px 20px 34px;
            transform: translateY(100%);
            visibility: hidden;
            pointer-events: none;
            transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1), visibility 0.4s;
            box-shadow: 0 -4px 24px rgba(0,0,0,0.1);
        }
        .share-panel.show { transform: translateY(0); visibility: visible; pointer-events: auto; }

        .share-panel-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px;
        }
        .share-panel-header h3 { font-size: 17px; font-weight: 600; color: #333; }
        .share-panel-close {
            width: 28px; height: 28px; border: none; background: #f0f0f0;
            border-radius: 50%; cursor: pointer; font-size: 14px; color: #999;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s;
        }
        .share-panel-close:hover { background: #e0e0e0; color: #666; }

        .share-panel-grid {
            display: flex; gap: 16px; justify-content: flex-start;
            overflow-x: auto; padding-bottom: 8px;
        }
        .share-panel-item {
            display: flex; flex-direction: column; align-items: center;
            gap: 8px; cursor: pointer; min-width: 68px; border: none;
            background: none; padding: 0; font-family: inherit;
        }
        .share-panel-icon {
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            transition: transform 0.2s;
        }
        .share-panel-icon:hover { transform: scale(1.08); }
        .share-panel-icon svg { width: 26px; height: 26px; }
        .share-panel-icon.qq { background: linear-gradient(135deg, #12B7F5, #0D8ECF); }
        .share-panel-icon.qq svg { fill: #fff; }
        .share-panel-icon.wechat { background: linear-gradient(135deg, #07C160, #06AD56); }
        .share-panel-icon.wechat svg { fill: #fff; }
        .share-panel-icon.weibo { background: linear-gradient(135deg, #E6162D, #C41021); }
        .share-panel-icon.weibo svg { fill: #fff; }
        .share-panel-icon.link { background: linear-gradient(135deg, #667eea, #764ba2); }
        .share-panel-icon.link svg { fill: #fff; }
        .share-panel-label { font-size: 12px; color: #666; white-space: nowrap; }

        .share-copy-toast {
            position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.8);
            background: rgba(0,0,0,0.75); color: #fff; padding: 12px 24px;
            border-radius: 10px; font-size: 14px; z-index: 10001;
            opacity: 0; visibility: hidden; transition: all 0.3s ease;
            pointer-events: none;
        }
        .share-copy-toast.show {
            opacity: 1;
            visibility: visible;
            transform: translate(-50%, -50%) scale(1);
        }

        /* 对话区域显示模式面板 */
        .display-mode-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px;
            border-radius: 12px;
            background: #f7f8fa;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }

        .display-mode-item:active {
            background: #eef0f3;
        }

        .display-mode-item.active {
            border-color: #4caf50;
            background: #f0f9f1;
        }

        .display-mode-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .display-mode-icon svg {
            width: 22px;
            height: 22px;
        }

        .display-mode-icon.centered {
            background: #e3f2fd;
            color: #2196f3;
        }

        .display-mode-icon.fullscreen {
            background: #fff3e0;
            color: #ff9800;
        }

        .display-mode-info {
            flex: 1;
            min-width: 0;
        }

        .display-mode-label {
            font-size: 15px;
            font-weight: 500;
            color: #1a1a1a;
            margin-bottom: 2px;
        }

        .display-mode-desc {
            font-size: 12px;
            color: #888;
            line-height: 1.4;
        }

        .display-mode-check {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #4caf50;
            color: #fff;
            font-size: 14px;
            display: none;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .display-mode-item.active .display-mode-check {
            display: flex;
        }
    </style>
</head>

<body>
    <div id="alert" class="alert"></div>

    <div class="mobile-container">
        <div class="top-nav">
            <div class="back-btn" onclick="location.href='../index.php'">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M17.0633 19.4393C17.6491 20.0251 17.6491 20.9746 17.0633 21.5604C16.4775 22.1462 15.528 22.1462 14.9422 21.5604L7.79081 14.4091C6.7364 13.3547 6.73639 11.6451 7.79081 10.5907L14.9422 3.43934C15.528 2.85355 16.4775 2.85355 17.0633 3.43934C17.6491 4.02513 17.6491 4.97465 17.0633 5.56043L10.1238 12.4999L17.0633 19.4393Z" fill="currentColor"></path></svg>
            </div>
            <span class="nav-title">MoonYa账号管理</span>
            <div class="nav-placeholder"></div>
        </div>

        <div class="header-section">
            <div class="avatar-wrapper">
                <img src="<?php echo htmlspecialchars($userAvatar); ?>" alt="用户头像" class="user-avatar">
                <div class="camera-icon" onclick="location.href='edit_profile.php'">
                    <img src="/image/tp.png" alt="更换头像">
                </div>
            </div>
            <div class="user-name"><?php echo htmlspecialchars($user['real_name'] ?? $user['username']); ?></div>
            <div class="user-id"><?php echo htmlspecialchars($user['username']); ?></div>
            <button class="manage-btn" onclick="location.href='edit_profile.php'">账号管理</button>
        </div>

        <div class="content-area">
            <div class="card">
                <a href="edit_profile.php" class="menu-item">
                    <div class="menu-icon blue">
                        <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                    </div>
                    <span class="menu-text">编辑个人资料</span>
                    <span class="menu-arrow">›</span>
                </a>
                <a href="account_settings.php" class="menu-item">
                    <div class="menu-icon orange">
                        <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                    </div>
                    <span class="menu-text"><?php echo $hasPassword ? '修改密码' : '设置密码'; ?></span>
                    <span class="menu-extra"><?php echo $hasPassword ? '已设置' : '未设置'; ?></span>
                    <span class="menu-arrow">›</span>
                </a>
                <div class="menu-item">
                    <div class="menu-icon indigo">
                        <svg viewBox="0 0 24 24"><path d="M12.87 15.07l-2.54-2.51.03-.03c1.74-1.94 2.98-4.17 3.71-6.53H17V4h-7V2H8v2H1v1.99h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z"/></svg>
                    </div>
                    <span class="menu-text">语言设置</span>
                    <span class="menu-extra">中文（简体）</span>
                    <span class="menu-arrow">›</span>
                </div>
                <div class="menu-item">
                    <div class="menu-icon purple">
                        <svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                    </div>
                    <span class="menu-text">声音选择</span>
                    <span class="menu-extra" style="display:flex;align-items:center;gap:6px;">
                        <img src="<?php echo htmlspecialchars($userAvatar); ?>" style="width:20px;height:20px;border-radius:50%;object-fit:cover;">
                        芙宁娜
                    </span>
                    <span class="menu-arrow">›</span>
                </div>
                <div class="menu-item" id="chatDisplayModeBtn" style="cursor:pointer;">
                    <div class="menu-icon teal">
                        <svg viewBox="0 0 24 24"><path d="M3 5h18v2H3V5zm0 6h18v2H3v-2zm0 6h18v2H3v-2z" fill="currentColor"/></svg>
                    </div>
                    <span class="menu-text">对话区域显示</span>
                    <span class="menu-extra" id="chatDisplayModeValue">区域显示</span>
                    <span class="menu-arrow">›</span>
                </div>
                <div class="menu-item" id="furinaPetToggleBtn" style="cursor:pointer;">
                    <div class="menu-icon cyan">
                        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z" fill="currentColor"/><circle cx="12" cy="12" r="3" fill="currentColor" opacity="0.5"/></svg>
                    </div>
                    <span class="menu-text">芙宁娜桌宠</span>
                    <div class="toggle-switch" id="furinaPetToggle" aria-label="芙宁娜桌宠开关"></div>
                </div>
            </div>

            <div class="card">
                <div class="menu-item" onclick="openSharePanel()" style="cursor:pointer;">
                    <div class="menu-icon red">
                        <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                    </div>
                    <span class="menu-text">分享给好友</span>
                    <span class="menu-arrow">›</span>
                </div>
            </div>

            <div class="card">
                <div class="menu-item" onclick="logout()">
                    <div class="menu-icon amber">
                        <svg viewBox="0 0 24 24"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                    </div>
                    <span class="menu-text">退出登录</span>
                    <span class="menu-arrow">›</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showAlert(type, message) {
            const alertDiv = document.getElementById('alert');
            alertDiv.className = 'alert alert-' + type + ' show';
            alertDiv.textContent = message;
            setTimeout(() => {
                alertDiv.className = 'alert';
            }, 3000);
        }

        function toggleCookie(el) {
            const toggle = el.querySelector('.toggle-switch');
            toggle.classList.toggle('active');
        }

        function logout() {
            if (confirm('确定要退出登录吗？')) {
                fetch('../user_auth.php?action=logout', {
                        method: 'GET',
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            localStorage.removeItem('api_token');
                            localStorage.removeItem('api_token_expires');
                            window.location.href = '../index.php';
                        } else {
                            showAlert('error', data.error || '退出失败');
                        }
                    })
                    .catch(error => {
                        showAlert('error', '退出失败，请稍后重试');
                    });
            }
        }
    </script>

    <div class="share-panel-overlay" id="sharePanelOverlay"></div>
    <div class="share-panel" id="sharePanel">
        <div class="share-panel-header">
            <h3>分享给好友</h3>
            <button class="share-panel-close" onclick="closeSharePanel()">✕</button>
        </div>
        <div class="share-panel-grid">
            <div class="share-panel-item" onclick="shareToQQ()">
                <div class="share-panel-icon qq">
                    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 13.24c-.18.53-.5.98-.92 1.34.24.12.78.42.78 1.08 0 .9-.96 1.34-2.5 1.34-.72 0-1.32-.12-1.78-.32h-.44c-.46.2-1.06.32-1.78.32-1.54 0-2.5-.44-2.5-1.34 0-.66.54-.96.78-1.08-.42-.36-.74-.81-.92-1.34-.16-.48-.04-.82.18-1.06.22-.24.56-.32.84-.2.28.12.48.42.42.74-.04.2.02.42.14.58.08-.28.22-.56.42-.82C9.68 12.86 10.72 12 12 12s2.32.86 2.64 2.48c.2.26.34.54.42.82.12-.16.18-.38.14-.58-.06-.32.14-.62.42-.74.28-.12.62-.04.84.2.22.24.34.58.18 1.06z"/></svg>
                </div>
                <span class="share-panel-label">QQ</span>
            </div>
            <div class="share-panel-item" onclick="shareToWeChat()">
                <div class="share-panel-icon wechat">
                    <svg viewBox="0 0 24 24"><path d="M8.691 2.188C3.891 2.188 0 5.476 0 9.53c0 2.212 1.17 4.203 3.002 5.55a.59.59 0 0 1 .213.665l-.39 1.48c-.019.07-.048.141-.048.213 0 .163.13.295.29.295a.326.326 0 0 0 .167-.054l1.903-1.114a.864.864 0 0 1 .717-.098 10.16 10.16 0 0 0 2.837.403c.276 0 .543-.027.811-.05-.857-2.578.157-4.972 1.932-6.446 1.703-1.415 3.882-1.98 5.853-1.838-.576-3.583-4.196-6.348-8.596-6.348zM5.785 5.991c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 0 1-1.162 1.178A1.17 1.17 0 0 1 4.623 7.17c0-.651.52-1.18 1.162-1.18zm5.813 0c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 0 1-1.162 1.178 1.17 1.17 0 0 1-1.162-1.178c0-.651.52-1.18 1.162-1.18zm5.34 2.867c-1.797-.052-3.746.512-5.28 1.786-1.72 1.428-2.687 3.72-1.78 6.22.942 2.453 3.666 4.229 6.884 4.229.826 0 1.622-.12 2.361-.336a.722.722 0 0 1 .598.082l1.584.926a.272.272 0 0 0 .14.047c.134 0 .24-.111.24-.247 0-.06-.023-.12-.038-.177l-.327-1.233a.582.582 0 0 1-.023-.156.49.49 0 0 1 .201-.398C23.024 18.48 24 16.82 24 14.98c0-3.21-2.931-5.837-7.062-6.122zM14.033 13.33c.535 0 .969.44.969.982a.976.976 0 0 1-.969.983.976.976 0 0 1-.969-.983c0-.542.434-.982.97-.982zm4.844 0c.535 0 .969.44.969.982a.976.976 0 0 1-.969.983.976.976 0 0 1-.969-.983c0-.542.434-.982.97-.982z"/></svg>
                </div>
                <span class="share-panel-label">微信</span>
            </div>
            <div class="share-panel-item" onclick="shareToWeibo()">
                <div class="share-panel-icon weibo">
                    <svg viewBox="0 0 24 24"><path d="M10.098 20.323c-3.977.391-7.414-1.406-7.672-4.02-.259-2.609 2.759-5.047 6.74-5.441 3.979-.394 7.413 1.404 7.671 4.018.259 2.6-2.759 5.049-6.739 5.443zm-1.119-6.724c-2.385.324-3.497 2.092-3.027 3.729.464 1.621 2.352 2.683 4.666 2.398 2.391-.293 3.678-1.922 3.258-3.648-.416-1.706-2.516-2.803-4.897-2.479zM20.196 9.4c-.096-.258-.385-.389-.645-.293l-.313.115c-.26.098-.393.387-.297.645.385 1.031.354 2.133-.109 3.084-.465.951-1.299 1.656-2.328 2.012-.26.09-.396.377-.307.637l.111.313c.09.26.377.396.637.307 1.348-.465 2.434-1.393 3.049-2.65.617-1.256.664-2.682.201-4.17zm-2.475 1.107c.146.387.131.803-.043 1.162-.174.359-.484.633-.869.779-.256.094-.389.377-.295.633l.111.305c.094.256.377.389.633.295.604-.221 1.084-.654 1.352-1.221.268-.566.293-1.209.066-1.809-.096-.258-.383-.393-.641-.297l-.307.113c-.258.096-.391.383-.295.641l.287-.301z"/></svg>
                </div>
                <span class="share-panel-label">微博</span>
            </div>
            <div class="share-panel-item" onclick="copyShareLink()">
                <div class="share-panel-icon link">
                    <svg viewBox="0 0 24 24"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>
                </div>
                <span class="share-panel-label">复制链接</span>
            </div>
        </div>
    </div>
    <div class="share-copy-toast" id="shareCopyToast">已复制到剪贴板，快去粘贴分享吧！</div>

    <div class="share-panel-overlay" id="displayModePanelOverlay"></div>
    <div class="share-panel" id="displayModePanel">
        <div class="share-panel-header">
            <h3>对话区域显示</h3>
            <button class="share-panel-close" onclick="closeDisplayModePanel()">✕</button>
        </div>
        <div class="share-panel-grid" style="flex-direction:column;gap:12px;">
            <div class="display-mode-item" data-mode="centered" onclick="selectDisplayMode('centered')">
                <div class="display-mode-icon centered">
                    <svg viewBox="0 0 24 24"><path d="M3 5h18v14H3V5zm2 2v10h14V7H5z" fill="currentColor"/></svg>
                </div>
                <div class="display-mode-info">
                    <div class="display-mode-label">区域显示</div>
                    <div class="display-mode-desc">对话内容居中显示，最大宽度 800px，阅读更舒适</div>
                </div>
                <div class="display-mode-check" id="checkCentered">✓</div>
            </div>
            <div class="display-mode-item" data-mode="fullscreen" onclick="selectDisplayMode('fullscreen')">
                <div class="display-mode-icon fullscreen">
                    <svg viewBox="0 0 24 24"><path d="M3 3h18v18H3V3zm2 2v14h14V5H5z" fill="currentColor"/></svg>
                </div>
                <div class="display-mode-info">
                    <div class="display-mode-label">全屏显示</div>
                    <div class="display-mode-desc">对话内容占满整个主区域，展示更多信息</div>
                </div>
                <div class="display-mode-check" id="checkFullscreen">✓</div>
            </div>
        </div>
    </div>

    <script>
    var shareText = '我发现了一个宝藏宝贝快来和我一起体验吧！<?php echo rtrim(get_api_domain_config('main_api_domain', ''), '/'); ?>/';
    var shareUrl = '<?php echo rtrim(get_api_domain_config('main_api_domain', ''), '/'); ?>/';

    function openSharePanel() {
        document.getElementById('sharePanelOverlay').classList.add('show');
        document.getElementById('sharePanel').classList.add('show');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(shareText).then(function() {
                showCopyToast('已复制到剪贴板，快去粘贴分享吧！');
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = shareText;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showCopyToast('已复制到剪贴板，快去粘贴分享吧！');
        }
    }

    function closeSharePanel() {
        document.getElementById('sharePanelOverlay').classList.remove('show');
        document.getElementById('sharePanel').classList.remove('show');
    }

    document.getElementById('sharePanelOverlay').addEventListener('click', closeSharePanel);

    function showCopyToast(msg) {
        var toast = document.getElementById('shareCopyToast');
        toast.textContent = msg || '已复制到剪贴板';
        toast.classList.add('show');
        setTimeout(function() { toast.classList.remove('show'); }, 2000);
    }

    function copyShareLink() {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(shareText).then(function() {
                showCopyToast('已复制到剪贴板，快去粘贴分享吧！');
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = shareText;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showCopyToast('已复制到剪贴板，快去粘贴分享吧！');
        }
    }

    function shareToQQ() {
        shareUsingPlatform();
    }

    function shareToWeChat() {
        copyShareLink();
        showCopyToast('链接已复制，快去粘贴分享吧！');
    }

    function shareToWeibo() {
        shareUsingPlatform();
    }

    function shareUsingPlatform() {
        if (navigator.share) {
            navigator.share({ title: shareText, text: shareText, url: shareUrl }).catch(function(error) {
                if (error && error.name !== 'AbortError') copyShareLink();
            });
        } else {
            copyShareLink();
        }
    }

    // 对话区域显示模式
    var CHAT_DISPLAY_MODE_KEY = 'chatDisplayMode';
    function getChatDisplayMode() {
        var m = localStorage.getItem(CHAT_DISPLAY_MODE_KEY);
        return (m === 'fullscreen' || m === 'centered') ? m : 'centered';
    }
    function setChatDisplayMode(mode) {
        localStorage.setItem(CHAT_DISPLAY_MODE_KEY, mode);
    }
    function updateDisplayModeUI() {
        var mode = getChatDisplayMode();
        var valueEl = document.getElementById('chatDisplayModeValue');
        var items = document.querySelectorAll('.display-mode-item');
        items.forEach(function(item) {
            if (item.getAttribute('data-mode') === mode) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });
        if (valueEl) {
            valueEl.textContent = mode === 'fullscreen' ? '全屏显示' : '区域显示';
        }
    }
    function openDisplayModePanel() {
        updateDisplayModeUI();
        document.getElementById('displayModePanelOverlay').classList.add('show');
        document.getElementById('displayModePanel').classList.add('show');
    }
    function closeDisplayModePanel() {
        document.getElementById('displayModePanelOverlay').classList.remove('show');
        document.getElementById('displayModePanel').classList.remove('show');
    }
    function selectDisplayMode(mode) {
        setChatDisplayMode(mode);
        updateDisplayModeUI();
        showAlert('success', '已切换为' + (mode === 'fullscreen' ? '全屏显示' : '区域显示') + '，返回对话页生效');
        setTimeout(closeDisplayModePanel, 600);
    }
    document.getElementById('displayModePanelOverlay').addEventListener('click', closeDisplayModePanel);
    document.getElementById('chatDisplayModeBtn').addEventListener('click', openDisplayModePanel);
    updateDisplayModeUI();

    // ── 芙宁娜桌宠开关 ──
    //   通过 CefSharp JS 桥 petController 调用 WPF 桌面桌宠（PetWindow）的显隐 API。
    //   petController 由 MainWindow.xaml.cs 的 OnLoadingStateChanged 注册。
    //   非桌面应用环境下 CefSharp.BindObjectAsync 会失败，回退到 localStorage 仅记录意图。
    var PET_ENABLED_KEY = 'furinaPetEnabled';
    var petBridgeReady = false;

    function getPetEnabledLocal() {
        var v = localStorage.getItem(PET_ENABLED_KEY);
        return v === null ? true : v === '1'; // 默认开启桌宠（自启动）
    }
    function setPetEnabledLocal(on) {
        localStorage.setItem(PET_ENABLED_KEY, on ? '1' : '0');
        var ts = document.getElementById('furinaPetToggle');
        if (ts) ts.classList.toggle('active', on);
    }

    // 异步初始化：尝试绑定 C# 桥并读取当前实际状态
    (function initPetBridge() {
        if (typeof CefSharp === 'undefined' || !CefSharp.BindObjectAsync) {
            // 非 CefSharp 环境：仅用本地存储
            setPetEnabledLocal(getPetEnabledLocal());
            return;
        }
        CefSharp.BindObjectAsync('petController').then(function() {
            if (!window.petController || typeof window.petController.isEnabled !== 'function') {
                setPetEnabledLocal(getPetEnabledLocal());
                return;
            }
            petBridgeReady = true;
            // 优先以 C# 端实际状态为准，避免本地存储与桌面状态不同步
            Promise.resolve(window.petController.isEnabled())
                .then(function(realEnabled) {
                    setPetEnabledLocal(!!realEnabled);
                })
                .catch(function() {
                    setPetEnabledLocal(getPetEnabledLocal());
                });
        }).catch(function() {
            setPetEnabledLocal(getPetEnabledLocal());
        });
    })();

    // 设置桌宠开关：同步本地存储 + 调用 C# 桥接控制桌面桌宠显隐
    function setPetEnabled(on) {
        setPetEnabledLocal(on);
        if (petBridgeReady && window.petController && typeof window.petController.setEnabled === 'function') {
            try {
                window.petController.setEnabled(on);
            } catch (e) {
                console.warn('[Pet] petController.setEnabled 调用失败:', e);
            }
        }
    }

    (function initPetToggle() {
        var ts = document.getElementById('furinaPetToggle');
        var btn = document.getElementById('furinaPetToggleBtn');
        if (!ts || !btn) return;
        // 初始 UI 状态：先用本地值占位，桥接初始化后会自动同步
        ts.classList.toggle('active', getPetEnabledLocal());
        btn.addEventListener('click', function(e) {
            if (e.target === ts) return;
            var now = !ts.classList.contains('active');
            setPetEnabled(now);
            showAlert('success', now ? '芙宁娜桌宠已开启，可在桌面上看到她' : '芙宁娜桌宠已关闭');
        });
        ts.addEventListener('click', function(e) {
            e.stopPropagation();
            var now = !ts.classList.contains('active');
            setPetEnabled(now);
            showAlert('success', now ? '芙宁娜桌宠已开启，可在桌面上看到她' : '芙宁娜桌宠已关闭');
        });
    })();
    </script>
</body>

</html>
