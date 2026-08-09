<?php
session_start();
$config = require_once __DIR__ . '/../config.php';

$isLoggedIn = isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0;
if (empty($_SESSION['community_csrf'])) {
    $_SESSION['community_csrf'] = bin2hex(random_bytes(32));
}
$communityCsrf = (string)$_SESSION['community_csrf'];

$communityConfig = $config['community'] ?? [];
$colors = $communityConfig['colors'] ?? [];
$primaryColor = $colors['primary'] ?? '#6B92F2';
$secondaryColor = $colors['secondary'] ?? '#F28AB2';
$bgColor = $colors['background'] ?? '#F5F7FA';
$cardBg = $colors['card_background'] ?? '#FFFFFF';
$textPrimary = $colors['text_primary'] ?? '#333333';
$textSecondary = $colors['text_secondary'] ?? '#999999';

try {
    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $defaultAvatar = '/image/mr.png';
    $currentUser = null;
    $userAvatar = $defaultAvatar;
    if ($isLoggedIn) {
        $stmt = $pdo->prepare("SELECT id, username, real_name, avatar, bio FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $currentUser = $stmt->fetch();
        $userAvatar = !empty($currentUser['avatar']) ? '../' . $currentUser['avatar'] : $defaultAvatar;
    }
} catch (Exception $e) {
    die('数据库错误');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="moonya-authenticated" content="<?php echo $isLoggedIn ? '1' : '0'; ?>">
<meta name="moonya-community-csrf" content="<?php echo htmlspecialchars($communityCsrf, ENT_QUOTES, 'UTF-8'); ?>">
<script src="auth-bridge.js" defer></script>
<title>发现</title>
<style>
:root {
    --primary: <?php echo $primaryColor; ?>;
    --secondary: <?php echo $secondaryColor; ?>;
    --bg: <?php echo $bgColor; ?>;
    --card-bg: <?php echo $cardBg; ?>;
    --text-primary: <?php echo $textPrimary; ?>;
    --text-secondary: <?php echo $textSecondary; ?>;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    -webkit-tap-highlight-color: transparent;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif;
    background: #e8e8e8;
    color: var(--text-primary);
    font-weight: 400;
    overflow: hidden;
    height: 100vh;
    max-width: 500px;
    margin: 0 auto;
    position: relative;
}

.top-nav {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 50px;
    background: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 16px;
    z-index: 100;
    border-bottom: 1px solid #f0f0f0;
    max-width: 500px;
    margin: 0 auto;
}

.top-nav .nav-left {
    width: 40px;
    display: flex;
    align-items: center;
}

.top-nav .nav-left img {
    width: 24px;
    height: 24px;
    cursor: pointer;
    transition: opacity 0.2s ease;
}

.top-nav .nav-left img:active {
    opacity: 0.6;
}

.top-nav .nav-center {
    display: flex;
    align-items: center;
    gap: 16px;
}

.nav-tab {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-secondary);
    cursor: pointer;
    position: relative;
    padding: 4px 0;
    transition: color 0.2s;
}

.nav-tab.active {
    color: var(--text-primary);
}

.nav-tab .badge {
    position: absolute;
    top: 0;
    right: -8px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #EF4444;
    display: none;
}

.nav-tab .badge.show {
    display: block;
}

.top-nav .nav-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.top-nav .nav-right .search-btn {
    width: 24px;
    height: 24px;
    padding: 8px;
    margin-right: 4px;
    cursor: pointer;
    transition: opacity 0.2s ease;
    box-sizing: content-box;
}

.top-nav .nav-right .search-btn:active {
    opacity: 0.6;
}

.top-nav .nav-right .avatar-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    overflow: hidden;
    cursor: pointer;
    transition: transform 0.2s ease;
}

.top-nav .nav-right .avatar-btn:active {
    transform: scale(0.9);
}

.top-nav .nav-right .avatar-btn img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.scroll-container {
    position: fixed;
    top: 50px;
    left: 0;
    right: 0;
    bottom: calc(20px + env(safe-area-inset-bottom, 0px));
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    background: #FFFFFF;
    max-width: 500px;
    margin: 0 auto;
}

.message-panel {
    position: fixed;
    top: 50px;
    left: 0;
    right: 0;
    bottom: calc(20px + env(safe-area-inset-bottom, 0px));
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    background: #FFFFFF;
    max-width: 500px;
    margin: 0 auto;
    display: none;
}

.message-panel.show {
    display: block;
}

.message-tabs {
    display: flex;
    padding: 12px 16px;
    gap: 0;
    border-bottom: 1px solid #f0f0f0;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 10;
}

.message-tab {
    flex: 1;
    text-align: center;
    padding: 8px 0;
    font-size: 14px;
    color: #999;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.2s;
}

.message-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 600;
}

.notification-item {
    display: flex;
    padding: 12px 16px;
    gap: 12px;
    border-bottom: 1px solid #f5f5f5;
    cursor: pointer;
    transition: background 0.2s;
}

.notification-item:active {
    background: #f9f9f9;
}

.notification-item.unread {
    background: #f0f4ff;
}

.notification-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    flex-shrink: 0;
    object-fit: cover;
}

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-text {
    font-size: 14px;
    color: #333;
    line-height: 1.4;
}

.notification-text strong {
    font-weight: 600;
    color: #111;
}

.notification-time {
    font-size: 12px;
    color: #999;
    margin-top: 4px;
}

.notification-image {
    width: 48px;
    height: 48px;
    border-radius: 6px;
    object-fit: cover;
    flex-shrink: 0;
}

.no-notifications {
    text-align: center;
    padding: 60px 16px;
    color: #999;
    font-size: 14px;
}

.notification-type-icon {
    width: 18px;
    height: 18px;
    position: absolute;
    bottom: -2px;
    right: -2px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.notification-avatar-wrap {
    position: relative;
    flex-shrink: 0;
}

.notification-avatar-wrap svg {
    width: 16px;
    height: 16px;
}

.scroll-container::-webkit-scrollbar {
    display: none;
}

.follow-section {
    background: #FFFFFF;
    padding: 12px 0;
    margin-bottom: 8px;
}

.follow-header {
    padding: 0 16px;
    margin-bottom: 10px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
}

.follow-scroll {
    display: flex;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding: 0 16px;
    gap: 16px;
}

.follow-scroll::-webkit-scrollbar {
    display: none;
}

.follow-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 52px;
    cursor: pointer;
    transition: transform 0.2s ease;
}

.follow-item:active {
    transform: scale(0.95);
}

.follow-item .follow-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    margin-bottom: 4px;
}

.follow-item .follow-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.follow-item .follow-name {
    font-size: 12px;
    color: var(--text-secondary);
    max-width: 52px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-align: center;
}

.post-list {
    padding: 0;
}

.post-card {
    background: var(--card-bg);
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
}

.post-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}

.post-header .post-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 10px;
    flex-shrink: 0;
}

.post-header .post-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.post-header .post-user-info {
    flex: 1;
    min-width: 0;
}

.post-header .post-username {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.post-header .post-time {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 2px;
}

.post-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 6px;
    line-height: 1.4;
    word-break: break-word;
}

.post-content {
    font-size: 14px;
    font-weight: 400;
    color: var(--text-primary);
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    word-break: break-word;
    margin-bottom: 10px;
}

.post-images {
    margin-top: 8px;
    display: grid;
    gap: 6px;
}

.post-images.count-1 {
    grid-template-columns: 1fr;
}

.post-images.count-2,
.post-images.count-3 {
    grid-template-columns: 1fr 1fr;
}

.post-image-wrap {
    border-radius: 8px;
    overflow: hidden;
}

.post-image-wrap img {
    width: 100%;
    max-height: 200px;
    object-fit: cover;
    display: block;
}

.post-video-cover {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    margin-top: 8px;
    cursor: pointer;
}

.post-video-cover img {
    width: 100%;
    max-height: 240px;
    object-fit: cover;
    display: block;
}

.post-video-cover .video-play-icon {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
}

.post-video-cover .video-play-icon svg {
    width: 22px;
    height: 22px;
    fill: #fff;
    margin-left: 2px;
}

.post-video-cover .video-badge-tag {
    position: absolute;
    top: 8px;
    left: 8px;
    background: rgba(0, 0, 0, 0.6);
    color: #fff;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.post-video-cover .video-badge-tag svg {
    width: 12px;
    height: 12px;
    fill: #fff;
}

.post-actions {
    display: flex;
    align-items: center;
    padding-top: 8px;
}

.post-action {
    display: flex;
    align-items: center;
    gap: 4px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: background 0.2s ease;
    -webkit-user-select: none;
    user-select: none;
}

.post-action:active {
    background: #f5f5f5;
}

.post-action svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}

.post-action .action-count {
    font-size: 12px;
    color: var(--text-secondary);
}

.post-action.liked .action-count {
    color: #EF4444;
}

.post-action.bookmarked .action-count {
    color: #F59E0B;
}

.post-action-spacer {
    flex: 1;
}

.post-action-more {
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: background 0.2s ease;
}

.post-action-more:active {
    background: #f5f5f5;
}

.post-action-more svg {
    width: 18px;
    height: 18px;
}

.loading-indicator {
    text-align: center;
    padding: 20px;
    color: var(--text-secondary);
    font-size: 14px;
}

@keyframes shimmerWave {
    0% { background-position: 300% 0; }
    100% { background-position: -300% 0; }
}

.shimmer-card {
    background: <?php echo $cardBg; ?>;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 12px;
    overflow: hidden;
}

.shimmer-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e8e8e8;
    position: relative;
    overflow: hidden;
}

.shimmer-avatar::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(105deg, transparent 30%, rgba(255,255,255,0.6) 45%, rgba(255,255,255,0.9) 50%, rgba(255,255,255,0.6) 55%, transparent 70%);
    background-size: 300% 100%;
    animation: shimmerWave 2.5s infinite linear;
}

.shimmer-line {
    height: 14px;
    background: #e8e8e8;
    border-radius: 4px;
    margin-bottom: 8px;
    position: relative;
    overflow: hidden;
}

.shimmer-line::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(105deg, transparent 30%, rgba(255,255,255,0.6) 45%, rgba(255,255,255,0.9) 50%, rgba(255,255,255,0.6) 55%, transparent 70%);
    background-size: 300% 100%;
    animation: shimmerWave 2.5s infinite linear;
}

.shimmer-line.short { width: 60%; }
.shimmer-line.medium { width: 80%; }

.shimmer-image {
    width: 100%;
    aspect-ratio: 4/3;
    background: #e8e8e8;
    border-radius: 8px;
    margin-top: 12px;
    position: relative;
    overflow: hidden;
}

.shimmer-image::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(105deg, transparent 30%, rgba(255,255,255,0.6) 45%, rgba(255,255,255,0.9) 50%, rgba(255,255,255,0.6) 55%, transparent 70%);
    background-size: 300% 100%;
    animation: shimmerWave 2.5s infinite linear;
}

.no-more {
    text-align: center;
    padding: 20px;
    color: var(--text-secondary);
    font-size: 13px;
}

.fab-publish {
    position: fixed;
    right: max(20px, calc(50vw - 230px));
    bottom: calc(20px + env(safe-area-inset-bottom, 0px));
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: var(--primary);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(107, 146, 242, 0.4);
    z-index: 99;
    transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.25s ease;
    -webkit-user-select: none;
    user-select: none;
}

.fab-publish:active {
    transform: scale(0.9);
}

.fab-publish.hidden {
    transform: scale(0) rotate(180deg);
    opacity: 0;
    pointer-events: none;
}

.fab-publish svg {
    width: 28px;
    height: 28px;
    fill: #FFFFFF;
}

.dynamic-island {
    position: fixed;
    top: 8px;
    left: 50%;
    transform: translateX(-50%) scale(0);
    z-index: 250;
    background: #000000;
    border-radius: 28px;
    height: 36px;
    width: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    opacity: 0;
    pointer-events: none;
    max-width: 480px;
    transition: width 0.4s cubic-bezier(0.34, 1.56, 0.64, 1),
                height 0.4s cubic-bezier(0.34, 1.56, 0.64, 1),
                border-radius 0.4s cubic-bezier(0.34, 1.56, 0.64, 1),
                background 0.3s ease,
                top 0.3s ease,
                opacity 0.25s ease,
                transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    overflow: hidden;
}

.dynamic-island.show {
    opacity: 1;
    pointer-events: auto;
    transform: translateX(-50%) scale(1);
}

.dynamic-island.expanded {
    width: calc(100% - 32px);
    max-width: 400px;
    height: 52px;
    border-radius: 26px;
    background: #FFFFFF;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    top: 8px;
    cursor: default;
    opacity: 1;
    pointer-events: auto;
    transform: translateX(-50%) scale(1);
}

.dynamic-island .island-icon {
    width: 18px;
    height: 18px;
    fill: #FFFFFF;
    transition: opacity 0.2s ease;
}

.dynamic-island.expanded .island-icon {
    opacity: 0;
    position: absolute;
    pointer-events: none;
}

.dynamic-island .island-search {
    display: none;
    width: 100%;
    height: 100%;
    align-items: center;
    padding: 0 16px;
    gap: 10px;
}

.dynamic-island.expanded .island-search {
    display: flex;
}

.dynamic-island .island-search svg {
    width: 20px;
    height: 20px;
    fill: none;
    stroke: #999;
    stroke-width: 2;
    flex-shrink: 0;
}

.dynamic-island .island-search input {
    flex: 1;
    height: 100%;
    border: none;
    outline: none;
    font-size: 15px;
    font-family: inherit;
    background: transparent;
    color: #333;
}

.dynamic-island .island-search input::placeholder {
    color: #999;
}

.dynamic-island .island-cancel {
    font-size: 14px;
    color: var(--primary);
    cursor: pointer;
    white-space: nowrap;
    padding: 4px 0;
    flex-shrink: 0;
}

.more-popup {
    position: fixed;
    z-index: 150;
    background: #FFFFFF;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    overflow: hidden;
    display: none;
}

.more-popup.show {
    display: block;
}

.more-popup-item {
    padding: 12px 20px;
    font-size: 14px;
    color: var(--text-primary);
    cursor: pointer;
    transition: background 0.2s ease;
    white-space: nowrap;
}

.more-popup-item:active {
    background: #f5f5f5;
}

.action-sheet-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 300;
    align-items: flex-end;
    justify-content: center;
    max-width: 500px;
    margin: 0 auto;
}

.action-sheet-overlay.show {
    display: flex;
}

.action-sheet {
    background: #fff;
    border-radius: 16px 16px 0 0;
    width: 100%;
    max-width: 500px;
    padding: 20px 16px;
    padding-bottom: calc(20px + env(safe-area-inset-bottom));
    animation: slideUp 0.25s ease;
}

@keyframes slideUp {
    from { transform: translateY(100%); }
    to { transform: translateY(0); }
}

.action-sheet-title {
    font-size: 16px;
    font-weight: 600;
    text-align: center;
    margin-bottom: 16px;
}

.action-sheet-items {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.action-sheet-item {
    padding: 12px 16px;
    background: #f5f7fa;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    color: #333;
    cursor: pointer;
    text-align: left;
    transition: background 0.2s;
    font-family: inherit;
}

.action-sheet-item:active {
    background: #e8eaed;
}

.action-sheet-cancel {
    margin-top: 12px;
    padding: 12px;
    background: none;
    border: none;
    font-size: 14px;
    color: #999;
    cursor: pointer;
    width: 100%;
    text-align: center;
    font-family: inherit;
}

.toast {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(0, 0, 0, 0.7);
    color: #FFFFFF;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    z-index: 999;
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}

.toast.show {
    opacity: 1;
}
</style>
<link rel="stylesheet" href="css/video-player.css">
</head>
<body>

<div class="top-nav">
    <div class="nav-left">
        <img src="/image/exit2.png" alt="" onclick="handleBack()">
    </div>
    <div class="nav-center">
        <span class="nav-tab active" id="tabDiscover" onclick="switchTab('discover')">发现</span>
        <span class="nav-tab" id="tabMessage" onclick="switchTab('message')">消息<span class="badge" id="msgBadge"></span></span>
    </div>
    <div class="nav-right">
        <svg class="search-btn" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" onclick="openIslandSearch()">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <div class="avatar-btn" onclick="openMyProfile()">
            <img src="<?php echo htmlspecialchars($userAvatar); ?>" alt="">
        </div>
    </div>
</div>

<div class="scroll-container" id="scrollContainer">
    <div class="follow-section">
        <div class="follow-header">关注</div>
        <div class="follow-scroll" id="followScroll">
        </div>
    </div>
    <div class="post-list" id="postList">
    </div>
    <div class="loading-indicator" id="loadingIndicator" style="display:none;"></div>
    <div class="no-more" id="noMore" style="display:none;">没有更多了</div>
</div>

<div class="message-panel" id="messagePanel">
    <div class="message-tabs">
        <div class="message-tab active" id="msgTabAll" onclick="switchMsgTab('all')">全部消息</div>
        <div class="message-tab" id="msgTabSystem" onclick="switchMsgTab('system')">系统消息</div>
    </div>
    <div id="notificationList"></div>
    <div class="no-notifications" id="noNotifications" style="display:none;">暂无消息</div>
</div>

<button class="fab-publish" id="fabPublish" onclick="openPublish()">
    <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
</button>

<div class="dynamic-island" id="dynamicIsland" onclick="openIslandSearch()">
    <svg class="island-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
    <div class="island-search" onclick="event.stopPropagation()">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" onclick="doSearch()" style="cursor:pointer"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="searchInput" placeholder="搜索帖子..." onkeydown="if(event.key==='Enter')doSearch()">
        <span class="island-cancel" onclick="closeIslandSearch()">取消</span>
    </div>
</div>

<div class="more-popup" id="morePopup">
    <div class="more-popup-item" onclick="openReport()">举报</div>
</div>

<div class="action-sheet-overlay" id="reportSheetOverlay">
    <div class="action-sheet" onclick="event.stopPropagation()">
        <div class="action-sheet-title">举报</div>
        <div class="action-sheet-items">
            <button class="action-sheet-item" onclick="submitReport('垃圾广告')">垃圾广告</button>
            <button class="action-sheet-item" onclick="submitReport('色情低俗')">色情低俗</button>
            <button class="action-sheet-item" onclick="submitReport('虚假信息')">虚假信息</button>
            <button class="action-sheet-item" onclick="submitReport('违法违规')">违法违规</button>
            <button class="action-sheet-item" onclick="submitReport('侵权抄袭')">侵权抄袭</button>
            <button class="action-sheet-item" onclick="submitReport('其他')">其他</button>
        </div>
        <button class="action-sheet-cancel" onclick="closeActionSheet()">取消</button>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
var currentPage = 1;
var isLoading = false;
var hasMore = true;
var isSearchMode = false;
var searchKeyword = '';
var reportPostId = null;
var defaultAvatar = '/image/mr.png';
var currentTab = 'discover';
var currentMsgTab = 'all';
var notificationPage = 1;
var notificationHasMore = true;
var communityLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;

function requireCommunityLogin(returnPath) {
    if (window.MoonYaCommunityAuth) return window.MoonYaCommunityAuth.requireLogin(returnPath);
    window.location.href = '../index.php?auth=1&return=' + encodeURIComponent(returnPath || '/community/index.php');
    return false;
}

function openMyProfile() {
    if (!communityLoggedIn) return requireCommunityLogin('/community/profile.php');
    window.location.href = 'profile.php';
}

function openPublish() {
    if (!communityLoggedIn) return requireCommunityLogin('/community/publish.php');
    window.location.href = 'publish.php';
}

function switchTab(tab) {
    if (tab === 'message' && !communityLoggedIn) {
        requireCommunityLogin('/community/index.php');
        return;
    }
    currentTab = tab;
    document.getElementById('tabDiscover').classList.toggle('active', tab === 'discover');
    document.getElementById('tabMessage').classList.toggle('active', tab === 'message');
    document.getElementById('scrollContainer').style.display = tab === 'discover' ? '' : 'none';
    document.getElementById('fabPublish').style.display = tab === 'discover' ? '' : 'none';
    document.getElementById('messagePanel').classList.toggle('show', tab === 'message');
    if (tab === 'message') {
        fetch('api/api.php?module=notifications&action=unread_count')
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success && res.data.system_unread > 0) {
                switchMsgTab('system');
            } else {
                switchMsgTab('all');
            }
        })
        .catch(function() {
            switchMsgTab('all');
        });
    }
}

function switchMsgTab(tab) {
    currentMsgTab = tab;
    document.getElementById('msgTabAll').classList.toggle('active', tab === 'all');
    document.getElementById('msgTabSystem').classList.toggle('active', tab === 'system');
    notificationPage = 1;
    notificationHasMore = true;
    loadNotifications();
}

function loadNotifications() {
    var type = currentMsgTab;
    fetch('api/api.php?module=notifications&action=list&type=' + type + '&page=' + notificationPage)
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) return;
        var list = res.data.notifications;
        var html = '';
        if (list.length === 0 && notificationPage === 1) {
            document.getElementById('noNotifications').style.display = 'block';
            document.getElementById('notificationList').innerHTML = '';
            return;
        }
        document.getElementById('noNotifications').style.display = 'none';
        list.forEach(function(n) {
            var typeText = '';
            var typeIcon = '';
            var typeBg = '';
            if (n.type === 'like') { typeText = '赞了你的帖子'; typeIcon = '<svg viewBox="0 0 24 24" fill="#EF4444" stroke="none"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z"/></svg>'; typeBg = '#fee2e2'; }
            else if (n.type === 'comment') { typeText = '评论了你的帖子'; typeIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="#3B82F6" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'; typeBg = '#dbeafe'; }
            else if (n.type === 'follow') { typeText = '关注了你'; typeIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>'; typeBg = '#d1fae5'; }
            else if (n.type === 'favorite') { typeText = '收藏了你的帖子'; typeIcon = '<svg viewBox="0 0 24 24" fill="#F59E0B" stroke="none"><path d="M12 3L14.39 8.88L20.6 9.25L15.8 13.07L17.42 19.2L12 15.9L6.58 19.2L8.2 13.07L3.4 9.25L9.61 8.88L12 3Z"/></svg>'; typeBg = '#fef3c7'; }
            else if (n.type === 'system') { typeText = ''; typeIcon = ''; typeBg = ''; }

            var avatarHtml = '';
            if (n.type === 'system') {
                avatarHtml = '<div style="width:40px;height:40px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="20" height="20" viewBox="0 0 24 24" fill="#fff"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg></div>';
            } else {
                var avSrc = n.actor_avatar || defaultAvatar;
                if (avSrc && !avSrc.startsWith('http') && !avSrc.startsWith('/')) avSrc = '../' + avSrc;
                avatarHtml = '<div class="notification-avatar-wrap"><img class="notification-avatar" src="' + avSrc + '" onerror="this.src=\'' + defaultAvatar + '\'">';
                if (typeIcon) {
                    avatarHtml += '<div class="notification-type-icon" style="background:' + typeBg + '">' + typeIcon + '</div>';
                }
                avatarHtml += '</div>';
            }

            var textHtml = '';
            if (n.type === 'system') {
                textHtml = '<div class="notification-text">' + (n.content || '系统通知') + '</div>';
                if (n.image) {
                    var imgSrc = n.image;
                    if (!imgSrc.startsWith('http') && !imgSrc.startsWith('/')) imgSrc = '../' + imgSrc;
                    textHtml += '<img class="notification-image" src="' + imgSrc + '" style="margin-top:8px;">';
                }
            } else {
                textHtml = '<div class="notification-text"><strong>' + escapeHtml(n.actor_real_name || n.actor_username || '用户') + '</strong> ' + typeText + '</div>';
            }
            textHtml += '<div class="notification-time">' + formatTime(n.created_at) + '</div>';

            var clickAction = '';
            if (n.type === 'system') {
                clickAction = 'onclick="markNotificationRead(' + n.id + ')"';
            } else if (n.target_id && n.target_type === 'post') {
                clickAction = 'onclick="markNotificationRead(' + n.id + ');location.href=\'detail.php?id=' + n.target_id + '\'"';
            } else {
                clickAction = 'onclick="markNotificationRead(' + n.id + ')"';
            }

            html += '<div class="notification-item' + (n.is_read ? '' : ' unread') + '" ' + clickAction + '>';
            html += avatarHtml;
            html += '<div class="notification-content">' + textHtml + '</div>';
            html += '</div>';
        });

        if (notificationPage === 1) {
            document.getElementById('notificationList').innerHTML = html;
        } else {
            document.getElementById('notificationList').innerHTML += html;
        }
        notificationHasMore = list.length >= 20;
    })
    .catch(function() {});
}

function markNotificationRead(id) {
    fetch('api/api.php?module=notifications&action=mark_read', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id })
    }).then(function() {
        checkUnreadCount();
    }).catch(function() {});
}

function checkUnreadCount() {
    if (!communityLoggedIn) return;
    fetch('api/api.php?module=notifications&action=unread_count')
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) return;
        var badge = document.getElementById('msgBadge');
        if (res.data.all_unread > 0) {
            badge.classList.add('show');
        } else {
            badge.classList.remove('show');
        }
    })
    .catch(function() {});
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function getAvatarUrl(avatar) {
    if (!avatar) return defaultAvatar;
    return '../' + avatar;
}

function formatTime(timeStr) {
    if (!timeStr) return '';
    var now = new Date();
    var time = new Date(timeStr.replace(/-/g, '/'));
    var diff = (now - time) / 1000;
    if (diff < 60) return '刚刚';
    if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
    if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
    if (diff < 604800) return Math.floor(diff / 86400) + '天前';
    var m = time.getMonth() + 1;
    var d = time.getDate();
    return m + '月' + d + '日';
}

function showToast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}

function handleBack() {
    window.location.href = '../';
}

function loadFollows() {
    var followingPromise = communityLoggedIn
        ? fetch('api/api.php?module=follows&action=following').then(function(r) { return r.json(); }).catch(function() { return { success: false, data: { users: [] } }; })
        : Promise.resolve({ success: true, data: { users: [] } });
    var defaultPromise = fetch('api/api.php?module=follows&action=default_follows').then(function(r) { return r.json(); }).catch(function() { return { success: false, data: { users: [] } }; });

    Promise.all([followingPromise, defaultPromise]).then(function(results) {
        var users = [];
        var followingUsers = (results[0].success && results[0].data.users) ? results[0].data.users : [];
        var defaultUsers = (results[1].success && results[1].data.users) ? results[1].data.users : [];

        var seen = {};
        followingUsers.forEach(function(u) {
            if (!seen[u.id]) {
                seen[u.id] = true;
                users.push(u);
            }
        });
        defaultUsers.forEach(function(u) {
            if (!seen[u.id]) {
                seen[u.id] = true;
                users.push(u);
            }
        });

        renderFollows(users);
    });
}

function renderFollows(users) {
    var container = document.getElementById('followScroll');
    if (!users.length) {
        container.innerHTML = '<div style="color:var(--text-secondary);font-size:13px;white-space:nowrap;">暂无关注</div>';
        return;
    }
    var html = '';
    users.forEach(function(u) {
        var avatar = getAvatarUrl(u.avatar);
        var name = u.real_name || u.username || '';
        html += '<div class="follow-item" onclick="location.href=\'profile.php?user_id=' + u.id + '\'">';
        html += '<div class="follow-avatar"><img src="' + avatar + '" alt="" onerror="this.src=\'' + defaultAvatar + '\'"></div>';
        html += '<div class="follow-name">' + escapeHtml(name) + '</div>';
        html += '</div>';
    });
    container.innerHTML = html;
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function renderContent(text) {
    if (!text) return '';
    var placeholders = [];
    var processed = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(match, linkText, url) {
        var idx = placeholders.length;
        placeholders.push('<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" style="color:#4A90D9;text-decoration:none;cursor:pointer;" onclick="event.preventDefault();event.stopPropagation();window.open(\'' + url.replace(/'/g, "\\'") + '\', \'_blank\')">' + escapeHtml(linkText) + '</a>');
        return '___LINKPH_' + idx + '___';
    });
    var escaped = escapeHtml(processed);
    for (var i = 0; i < placeholders.length; i++) {
        escaped = escaped.replace('___LINKPH_' + i + '___', placeholders[i]);
    }
    return escaped;
}

function buildShimmerCards(count) {
    var html = '';
    for (var i = 0; i < count; i++) {
        html += '<div class="shimmer-card">';
        html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">';
        html += '<div class="shimmer-avatar"></div>';
        html += '<div style="flex:1;"><div class="shimmer-line short" style="margin-bottom:6px;height:12px;"></div><div class="shimmer-line" style="width:40%;height:10px;"></div></div>';
        html += '</div>';
        html += '<div class="shimmer-line medium"></div>';
        html += '<div class="shimmer-line short"></div>';
        if (i % 2 === 0) {
            html += '<div class="shimmer-image"></div>';
        }
        html += '</div>';
    }
    return html;
}

function loadPosts(page, append) {
    if (isLoading) return;
    isLoading = true;
    document.getElementById('noMore').style.display = 'none';

    var loadingEl = document.getElementById('loadingIndicator');
    if (!append) {
        document.getElementById('postList').innerHTML = '';
        loadingEl.innerHTML = buildShimmerCards(3);
        loadingEl.style.display = 'block';
    } else {
        loadingEl.innerHTML = '<div style="text-align:center;padding:12px;color:var(--text-secondary);font-size:13px;">加载中...</div>';
        loadingEl.style.display = 'block';
    }

    var url;
    if (isSearchMode && searchKeyword) {
        url = 'api/api.php?module=posts&action=search&keyword=' + encodeURIComponent(searchKeyword) + '&page=' + page;
    } else {
        url = 'api/api.php?module=posts&action=list&page=' + page;
    }

    fetch(url).then(function(r) { return r.json(); }).then(function(res) {
        if (!res.success) {
            showToast('加载失败');
            isLoading = false;
            document.getElementById('loadingIndicator').style.display = 'none';
            return;
        }
        var data = res.data;
        var posts = data.posts || [];
        var total = data.total || 0;
        var perPage = data.per_page || 20;

        if (!append) {
            document.getElementById('postList').innerHTML = '';
        }

        renderPosts(posts);

        setTimeout(renderExtVideoPosters, 100);

        if (posts.length < perPage || (page * perPage >= total)) {
            hasMore = false;
            document.getElementById('noMore').style.display = 'block';
        } else {
            hasMore = true;
        }

        currentPage = page;
        isLoading = false;
        var loadingEl = document.getElementById('loadingIndicator');
        loadingEl.style.display = 'none';
        loadingEl.innerHTML = '';
    }).catch(function() {
        showToast('网络错误');
        isLoading = false;
        var loadingEl = document.getElementById('loadingIndicator');
        loadingEl.style.display = 'none';
        loadingEl.innerHTML = '';
    });
}

function renderPosts(posts) {
    var container = document.getElementById('postList');
    posts.forEach(function(post) {
        var card = document.createElement('div');
        card.className = 'post-card';
        card.setAttribute('data-post-id', post.id);

        var avatar = getAvatarUrl(post.avatar);
        var name = post.real_name || post.username || '';
        var timeStr = formatTime(post.created_at);
        var images = [];
        try {
            if (post.images) {
                images = typeof post.images === 'string' ? JSON.parse(post.images) : post.images;
            }
        } catch(e) {}

        var html = '';
        html += '<div class="post-header">';
        html += '<div class="post-avatar" onclick="event.stopPropagation();location.href=\'profile.php?user_id=' + post.user_id + '\'"><img src="' + avatar + '" alt="" onerror="this.src=\'' + defaultAvatar + '\'"></div>';
        html += '<div class="post-user-info">';
        html += '<div class="post-username">' + escapeHtml(name) + '</div>';
        html += '<div class="post-time">' + timeStr + '</div>';
        html += '</div>';
        html += '</div>';

        if (post.title) {
            html += '<div class="post-title">' + escapeHtml(post.title) + '</div>';
        }
        html += '<div class="post-content">' + renderContent(post.content) + '</div>';

        if (images.length > 0) {
            var countClass = 'count-' + Math.min(images.length, 3);
            html += '<div class="post-images ' + countClass + '">';
            images.forEach(function(imgSrc) {
                if (imgSrc && !imgSrc.startsWith('http') && !imgSrc.startsWith('/')) {
                    imgSrc = '../' + imgSrc;
                }
                html += '<div class="post-image-wrap"><img src="' + imgSrc + '" alt="" onerror="this.parentElement.style.display=\'none\'"></div>';
            });
            html += '</div>';
        }

        if (post.video_url) {
            var coverSrc = '';
            if (post.video_cover) {
                coverSrc = post.video_cover;
                if (!coverSrc.startsWith('http') && !coverSrc.startsWith('/')) coverSrc = '../' + coverSrc;
            }
            html += '<div class="post-video-cover" onclick="event.stopPropagation();location.href=\'detail.php?id=' + post.id + '\'">';
            if (coverSrc) {
                html += '<img src="' + coverSrc + '" alt="" loading="lazy">';
            } else {
                html += '<div style="width:100%;height:160px;background:#1a1a1a;display:flex;align-items:center;justify-content:center;">';
                html += '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="1.5"><polygon points="5 3 19 12 5 21 5 3"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>';
                html += '</div>';
            }
            html += '<div class="video-play-icon"><svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg></div>';
            html += '<div class="video-badge-tag"><svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>视频</div>';
            html += '</div>';
        }

        if (post.external_videos) {
            var extVideos = [];
            try {
                if (post.external_videos) {
                    extVideos = typeof post.external_videos === 'string' ? JSON.parse(post.external_videos) : post.external_videos;
                }
            } catch(e) { extVideos = []; }
            if (extVideos.length > 0) {
                html += '<div style="margin-top:8px;display:flex;flex-direction:column;gap:6px;">';
                extVideos.forEach(function(vUrl, vIdx) {
                    html += '<div class="post-video-cover ext-video-cover" data-ext-url="' + escapeHtml(vUrl) + '" onclick="event.stopPropagation();location.href=\'detail.php?id=' + post.id + '\'">';
                    html += '<div class="ext-video-poster" id="extPoster_' + post.id + '_' + vIdx + '" style="width:100%;height:160px;background:#1a1a1a;display:flex;align-items:center;justify-content:center;">';
                    html += '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="1.5"><polygon points="5 3 19 12 5 21 5 3"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>';
                    html += '</div>';
                    html += '<div class="video-play-icon"><svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg></div>';
                    html += '<div class="video-badge-tag"><svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>外部视频</div>';
                    html += '</div>';
                });
                html += '</div>';
            }
        }

        html += '<div class="post-actions">';
        html += '<div class="post-action' + (post.is_liked ? ' liked' : '') + '" data-action="like" data-post-id="' + post.id + '">';
        html += (post.is_liked
            ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" fill="#EF4444" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" fill="#FCA5A5" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>');
        html += '<span class="action-count">' + (post.likes_count || 0) + '</span>';
        html += '</div>';

        html += '<div class="post-action" data-action="comment" data-post-id="' + post.id + '">';
        html += '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7A8.38 8.38 0 0 1 4 11.5a8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M8 12h8M8 16h5" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round"/></svg>';
        html += '<span class="action-count">' + (post.comments_count || 0) + '</span>';
        html += '</div>';

        html += '<div class="post-action' + (post.is_favorited ? ' bookmarked' : '') + '" data-action="bookmark" data-post-id="' + post.id + '">';
        html += (post.is_favorited
            ? '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 3L14.39 8.88L20.6 9.25L15.8 13.07L17.42 19.2L12 15.9L6.58 19.2L8.2 13.07L3.4 9.25L9.61 8.88L12 3Z" fill="#F59E0B"/></svg>'
            : '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l3.09 6.26 6.91 1.01-5 4.87 1.18 6.88L12 18.76l-6.18 3.26 1.18-6.88-5-4.87 6.91-1.01L12 2.5z" fill="#ffffff" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>');
        html += '</div>';

        html += '<div class="post-action-spacer"></div>';

        html += '<div class="post-action-more" data-action="more" data-post-id="' + post.id + '">';
        html += '<svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle></svg>';
        html += '</div>';

        html += '</div>';

        card.innerHTML = html;
        container.appendChild(card);
    });
}

document.getElementById('postList').addEventListener('click', function(e) {
    var actionEl = e.target.closest('[data-action]');
    if (actionEl) {
        e.stopPropagation();
        var action = actionEl.getAttribute('data-action');
        var postId = actionEl.getAttribute('data-post-id');

        if (action === 'like') {
            toggleLike(postId, actionEl);
        } else if (action === 'comment') {
            location.href = 'detail.php?id=' + postId;
        } else if (action === 'bookmark') {
            toggleBookmark(postId, actionEl);
        } else if (action === 'more') {
            showMorePopup(postId, actionEl);
        }
        return;
    }

    var card = e.target.closest('.post-card');
    if (card) {
        var postId = card.getAttribute('data-post-id');
        location.href = 'detail.php?id=' + postId;
    }
});

function toggleLike(postId, el) {
    fetch('api/api.php?module=likes&action=toggle', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ target_id: parseInt(postId), target_type: 'post' })
    }).then(function(r) { return r.json(); }).then(function(res) {
        if (res.success) {
            var isLiked = res.data.is_liked;
            var count = res.data.likes_count;
            if (isLiked) {
                el.classList.add('liked');
            } else {
                el.classList.remove('liked');
            }
            el.querySelector('svg').outerHTML = isLiked
                ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" fill="#EF4444" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" fill="#FCA5A5" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>';
            el.querySelector('.action-count').textContent = count;
        }
    }).catch(function() {
        showToast('操作失败');
    });
}

function toggleBookmark(postId, el) {
    fetch('api/api.php?module=favorites&action=toggle', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ post_id: parseInt(postId) })
    }).then(function(r) { return r.json(); }).then(function(res) {
        if (res.success) {
            var isFavorited = res.data.is_favorited;
            if (isFavorited) {
                el.classList.add('bookmarked');
            } else {
                el.classList.remove('bookmarked');
            }
            el.querySelector('svg').outerHTML = isFavorited
                ? '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 3L14.39 8.88L20.6 9.25L15.8 13.07L17.42 19.2L12 15.9L6.58 19.2L8.2 13.07L3.4 9.25L9.61 8.88L12 3Z" fill="#F59E0B"/></svg>'
                : '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l3.09 6.26 6.91 1.01-5 4.87 1.18 6.88L12 18.76l-6.18 3.26 1.18-6.88-5-4.87 6.91-1.01L12 2.5z" fill="#ffffff" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        }
    }).catch(function() {
        showToast('操作失败');
    });
}

function showMorePopup(postId, el) {
    reportPostId = postId;
    var popup = document.getElementById('morePopup');
    var rect = el.getBoundingClientRect();
    var popupWidth = 100;
    popup.style.top = (rect.top - 40) + 'px';
    popup.style.left = (rect.right - popupWidth) + 'px';
    popup.style.right = 'auto';
    popup.classList.add('show');

    var popupRect = popup.getBoundingClientRect();
    if (popupRect.left < 0) {
        popup.style.left = '8px';
    }

    setTimeout(function() {
        document.addEventListener('click', closeMorePopup);
    }, 10);
}

function closeMorePopup() {
    document.getElementById('morePopup').classList.remove('show');
    document.removeEventListener('click', closeMorePopup);
}

function openReport() {
    closeMorePopup();
    document.getElementById('reportSheetOverlay').classList.add('show');
}

function closeActionSheet() {
    document.getElementById('reportSheetOverlay').classList.remove('show');
    reportPostId = null;
}

function submitReport(reason) {
    if (!reportPostId) return;
    if (!reason) {
        showToast('请选择举报原因');
        return;
    }

    fetch('api/api.php?module=reports&action=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ target_id: parseInt(reportPostId), target_type: 'post', reason: reason })
    }).then(function(r) { return r.json(); }).then(function(res) {
        if (res.success) {
            showToast('举报已提交');
            closeActionSheet();
        } else {
            showToast(res.error || '举报失败');
        }
    }).catch(function() {
        showToast('网络错误');
    });
}

function openIslandSearch() {
    var island = document.getElementById('dynamicIsland');
    island.classList.add('show');
    setTimeout(function() {
        island.classList.add('expanded');
        setTimeout(function() {
            document.getElementById('searchInput').focus();
        }, 400);
    }, 200);
}

function closeIslandSearch() {
    var island = document.getElementById('dynamicIsland');
    island.classList.remove('expanded');
    document.getElementById('searchInput').value = '';
    setTimeout(function() {
        island.classList.remove('show');
    }, 350);
    if (isSearchMode) {
        isSearchMode = false;
        searchKeyword = '';
        currentPage = 1;
        hasMore = true;
        loadPosts(1, false);
    }
}

function doSearch() {
    var keyword = document.getElementById('searchInput').value.trim();
    if (!keyword) return;
    searchKeyword = keyword;
    isSearchMode = true;
    currentPage = 1;
    hasMore = true;
    loadPosts(1, false);
    closeIslandSearch();
}

var scrollContainer = document.getElementById('scrollContainer');
var fabTimer = null;
scrollContainer.addEventListener('scroll', function() {
    var scrollTop = scrollContainer.scrollTop;
    var scrollHeight = scrollContainer.scrollHeight;
    var clientHeight = scrollContainer.clientHeight;

    var fab = document.getElementById('fabPublish');
    fab.classList.add('hidden');
    clearTimeout(fabTimer);
    fabTimer = setTimeout(function() {
        fab.classList.remove('hidden');
    }, 400);

    if (scrollHeight - scrollTop - clientHeight < 200 && hasMore && !isLoading) {
        loadPosts(currentPage + 1, true);
    }
});

loadFollows();
loadPosts(1, false);
checkUnreadCount();

document.getElementById('reportSheetOverlay').addEventListener('click', function() {
    this.classList.remove('show');
});

var _frameQueue = [];
var _frameProcessing = false;

function _processFrameQueue() {
    if (_frameProcessing || _frameQueue.length === 0) return;
    _frameProcessing = true;
    var task = _frameQueue.shift();

    fetch('api/video_frame.php?url=' + encodeURIComponent(task.url))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.poster && task.posterEl) {
                task.posterEl.innerHTML = '';
                task.posterEl.style.backgroundImage = 'url(' + data.poster + ')';
                task.posterEl.style.backgroundSize = 'cover';
                task.posterEl.style.backgroundPosition = 'center';
                task.posterEl.style.height = '';
            }
            _frameProcessing = false;
            if (_frameQueue.length > 0) setTimeout(_processFrameQueue, 50);
        })
        .catch(function() {
            _tryClientFrameExtract(task.url, task.posterEl);
        });
}

function _tryClientFrameExtract(videoUrl, posterEl) {
    var video = document.createElement('video');
    video.preload = 'metadata';
    video.muted = true;
    video.playsInline = true;
    var cleaned = false;
    function cleanup() {
        if (cleaned) return;
        cleaned = true;
        video.removeAttribute('src');
        video.load();
        _frameProcessing = false;
        if (_frameQueue.length > 0) setTimeout(_processFrameQueue, 50);
    }
    video.src = videoUrl;
    video.addEventListener('loadeddata', function() { video.currentTime = 0; });
    video.addEventListener('seeked', function() {
        try {
            var canvas = document.createElement('canvas');
            canvas.width = video.videoWidth || 640;
            canvas.height = video.videoHeight || 360;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            var dataUrl = canvas.toDataURL('image/jpeg', 0.7);
            if (posterEl) {
                posterEl.innerHTML = '';
                posterEl.style.backgroundImage = 'url(' + dataUrl + ')';
                posterEl.style.backgroundSize = 'cover';
                posterEl.style.backgroundPosition = 'center';
                posterEl.style.height = '';
                posterEl.style.aspectRatio = canvas.width + '/' + canvas.height;
            }
        } catch(e) {}
        cleanup();
    });
    video.addEventListener('error', function() { cleanup(); });
    setTimeout(function() { cleanup(); }, 8000);
}

function extractVideoFrame(videoUrl, posterEl) {
    _frameQueue.push({ url: videoUrl, posterEl: posterEl });
    _processFrameQueue();
}

var _extVideoObserver = null;
function renderExtVideoPosters() {
    if (!_extVideoObserver) {
        _extVideoObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var el = entry.target;
                    if (el.dataset.frameStarted) return;
                    el.dataset.frameStarted = '1';
                    _extVideoObserver.unobserve(el);
                    var url = el.getAttribute('data-ext-url');
                    var posterEl = el.querySelector('.ext-video-poster');
                    if (url && posterEl) {
                        extractVideoFrame(url, posterEl);
                    }
                }
            });
        }, { rootMargin: '200px' });
    }
    var covers = document.querySelectorAll('.ext-video-cover:not([data-frame-observed])');
    covers.forEach(function(el) {
        el.dataset.frameObserved = '1';
        _extVideoObserver.observe(el);
    });
}

function playExternalVideo(url) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.9);z-index:500;display:flex;align-items:center;justify-content:center;flex-direction:column;';
    var playerContainer = document.createElement('div');
    playerContainer.style.cssText = 'width:100%;max-width:500px;';
    overlay.appendChild(playerContainer);
    var closeBtn = document.createElement('button');
    closeBtn.textContent = '✕';
    closeBtn.style.cssText = 'position:absolute;top:16px;right:16px;width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,0.2);border:none;color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:10;';
    var player = null;
    closeBtn.onclick = function() {
        if (player) player.destroy();
        document.body.removeChild(overlay);
    };
    overlay.appendChild(closeBtn);
    document.body.appendChild(overlay);
    if (typeof MoonyaVideoPlayer !== 'undefined') {
        player = new MoonyaVideoPlayer(playerContainer, {
            src: url,
            primaryColor: '<?php echo $primaryColor; ?>',
            autoplay: true,
            compact: false
        });
    }
}
</script>
<script src="js/hls.min.js"></script>
<script src="js/video-player.js"></script>
</body>
</html>
