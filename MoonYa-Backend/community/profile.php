<?php
session_start();
$config = require_once __DIR__ . '/../config.php';

$isLoggedIn = isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0;
if (empty($_SESSION['community_csrf'])) {
    $_SESSION['community_csrf'] = bin2hex(random_bytes(32));
}
$communityCsrf = (string)$_SESSION['community_csrf'];

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : ($isLoggedIn ? intval($_SESSION['user_id']) : 0);
if (!$isLoggedIn && !isset($_GET['user_id'])) {
    header('Location: ../index.php?auth=1&return=' . rawurlencode('/community/profile.php'));
    exit;
}
if ($userId <= 0) {
    die('用户不存在');
}

$isSelf = $isLoggedIn && ($userId === intval($_SESSION['user_id']));

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
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    $stmt = $pdo->prepare("SELECT id, username, real_name, gender, avatar, bio, likes_count FROM users WHERE id = ? AND status != 'deleted'");
    $stmt->execute([$userId]);
    $profileUser = $stmt->fetch();

    if (!$profileUser) {
        die('用户不存在');
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) as following_count FROM community_follows WHERE follower_id = ?");
    $stmt->execute([$userId]);
    $followingCount = intval($stmt->fetch()['following_count']);

    $stmt = $pdo->prepare("SELECT COUNT(*) as followers_count FROM community_follows WHERE following_id = ?");
    $stmt->execute([$userId]);
    $followersCount = intval($stmt->fetch()['followers_count']);

    $isFollowing = false;
    if ($isLoggedIn && !$isSelf) {
        $stmt = $pdo->prepare("SELECT id FROM community_follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$_SESSION['user_id'], $userId]);
        $isFollowing = boolval($stmt->fetch());
    }

    $defaultAvatar = '/image/mr.png';
    $userAvatar = !empty($profileUser['avatar']) ? '../' . $profileUser['avatar'] : $defaultAvatar;
    $displayName = !empty($profileUser['real_name']) ? $profileUser['real_name'] : $profileUser['username'];
    $userBio = $profileUser['bio'] ?? '';
    $likesCount = intval($profileUser['likes_count'] ?? 0);
} catch (Exception $e) {
    die('数据库错误: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="moonya-authenticated" content="<?php echo $isLoggedIn ? '1' : '0'; ?>">
    <meta name="moonya-community-csrf" content="<?php echo htmlspecialchars($communityCsrf, ENT_QUOTES, 'UTF-8'); ?>">
    <script src="auth-bridge.js" defer></script>
    <title>个人主页 - 雅泫云社区</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif;
            background-color: #e8e8e8;
            color: <?php echo $textPrimary; ?>;
            min-height: 100vh;
            padding-top: 0;
            padding-bottom: 20px;
            -webkit-font-smoothing: antialiased;
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
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 100;
            border-bottom: 1px solid #f0f0f0;
            max-width: 500px;
            margin: 0 auto;
        }

        .top-nav .back-btn {
            width: 28px;
            height: 28px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .top-nav .back-btn img {
            width: 20px;
            height: 20px;
        }

        .top-nav .nav-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
        }

        .top-nav .settings-btn {
            width: 28px;
            height: 28px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .top-nav .settings-btn svg {
            width: 22px;
            height: 22px;
            fill: #666;
        }

        .user-info-section {
            background: #fff;
            padding: 20px 16px;
        }

        .section-divider {
            height: 1px;
            background: #f5f5f5;
        }

        .user-info-top {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .user-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            border: 3px solid #f0f0f0;
            object-fit: cover;
        }

        .user-name {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-top: 12px;
        }

        .user-gender {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            margin-top: 6px;
            line-height: 1.5;
        }

        .user-gender.gender-male {
            background: #E6F0FF;
            color: #0057ff;
        }

        .user-gender.gender-female {
            background: #FCE4EC;
            color: #E91E63;
        }

        .user-gender.gender-private {
            background: #F5F5F5;
            color: #999;
        }

        .user-bio {
            font-size: 14px;
            color: #666;
            margin-top: 6px;
            max-width: 280px;
            text-align: center;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.5;
        }

        .user-stats {
            display: flex;
            justify-content: center;
            gap: 32px;
            margin-top: 16px;
        }

        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            text-decoration: none;
        }

        .stat-item .stat-number {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .stat-item .stat-label {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
        }

        .follow-btn-wrapper {
            margin-top: 16px;
            display: flex;
            justify-content: center;
        }

        .follow-btn {
            padding: 6px 24px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }

        .follow-btn.not-following {
            background: <?php echo $primaryColor; ?>;
            color: #fff;
        }

        .follow-btn.is-following {
            background: #fff;
            color: #666;
            border: 1px solid #ddd;
        }

        .follow-btn:active {
            opacity: 0.8;
        }

        .posts-section {
            background: #fff;
        }

        .tab-bar {
            display: flex;
            border-bottom: 1px solid #f0f0f0;
            padding: 0 16px;
        }

        .tab-item {
            padding: 12px 0;
            margin-right: 24px;
            font-size: 15px;
            color: #999;
            cursor: pointer;
            position: relative;
            font-weight: 500;
        }

        .tab-item.active {
            color: #333;
            font-weight: 600;
        }

        .tab-item.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: <?php echo $primaryColor; ?>;
            border-radius: 1px;
        }

        .post-list {
            padding: 0;
        }

        .post-card {
            padding: 16px;
            border-bottom: 1px solid #f5f5f5;
            cursor: pointer;
        }

        .post-card:active {
            background: #fafafa;
        }

        .post-publisher {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .post-publisher-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
        }

        .post-publisher-info {
            flex: 1;
        }

        .post-publisher-name {
            font-size: 14px;
            font-weight: 500;
            color: #333;
        }

        .post-publisher-time {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
        }

        .post-content-area {
            margin-bottom: 10px;
        }

        .post-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
            line-height: 1.4;
        }

        .post-text {
            font-size: 14px;
            color: #555;
            line-height: 1.6;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .post-image {
            width: 100%;
            max-height: 200px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .post-video-thumb {
            position: relative;
            margin-bottom: 10px;
            cursor: pointer;
        }

        .post-interaction {
            display: flex;
            align-items: center;
            padding-top: 8px;
        }

        .interaction-btn {
            display: flex;
            align-items: center;
            margin-right: 24px;
            cursor: pointer;
            padding: 4px 0;
        }

        .interaction-btn svg {
            width: 18px;
            height: 18px;
            margin-right: 4px;
        }

        .interaction-btn .count {
            font-size: 13px;
            color: #999;
        }

        .interaction-btn.liked .count {
            color: #EF4444;
        }

        .interaction-btn.favorited .count {
            color: #F59E0B;
        }

        .interaction-btn.more-btn {
            margin-left: auto;
            margin-right: 0;
        }

        .loading-indicator {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 14px;
        }

        @keyframes shimmerWave {
            0% { background-position: 300% 0; }
            100% { background-position: -300% 0; }
        }

        .shimmer-card {
            background: #fff;
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

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state svg {
            width: 64px;
            height: 64px;
            fill: #ddd;
            margin-bottom: 12px;
        }

        .empty-state p {
            font-size: 14px;
        }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 56px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: space-around;
            border-top: 1px solid #f0f0f0;
            z-index: 100;
            max-width: 500px;
            margin: 0 auto;
        }

        .bottom-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            text-decoration: none;
            padding: 4px 12px;
            -webkit-tap-highlight-color: transparent;
        }

        .bottom-nav-item svg {
            width: 24px;
            height: 24px;
            margin-bottom: 2px;
        }

        .bottom-nav-item .nav-label {
            font-size: 11px;
            color: #999;
        }

        .bottom-nav-item.active svg {
            fill: <?php echo $primaryColor; ?>;
        }

        .bottom-nav-item.active .nav-label {
            color: <?php echo $primaryColor; ?>;
        }

        .bottom-nav-item.publish-btn svg {
            width: 36px;
            height: 36px;
        }

        .action-sheet-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 200;
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

        .action-sheet-item.danger {
            color: #EF4444;
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

        .share-sheet-items {
            display: flex;
            justify-content: space-around;
            padding: 12px 0;
        }

        .share-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .share-item-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .share-item-icon svg {
            width: 24px;
            height: 24px;
            fill: #fff;
        }

        .share-item-label {
            font-size: 12px;
            color: #666;
        }

        .toast {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.75);
            color: #fff;
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 14px;
            z-index: 300;
            opacity: 0;
            transition: opacity 0.3s;
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
        <div class="back-btn" onclick="window.location.href='index.php'">
            <img src="/image/exit2.png" alt="返回">
        </div>
        <span class="nav-title">个人主页</span>
        <?php if ($isSelf): ?>
        <div class="settings-btn" onclick="location.href='../user/user_xinxi.php'">
            <svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 0 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z"/></svg>
        </div>
        <?php else: ?>
        <div style="width:28px;"></div>
        <?php endif; ?>
    </div>

    <div class="user-info-section">
        <div class="user-info-top">
            <img class="user-avatar" src="<?php echo htmlspecialchars($userAvatar); ?>" alt="头像" onerror="this.src='/image/mr.png'">
            <div class="user-name"><?php echo htmlspecialchars($displayName); ?></div>
            <?php
                $userGender = $profileUser['gender'] ?? null;
                if ($userGender === 'male') {
                    echo '<span class="user-gender gender-male">♂ 男</span>';
                } elseif ($userGender === 'female') {
                    echo '<span class="user-gender gender-female">♀ 女</span>';
                } elseif ($userGender === 'private') {
                    echo '<span class="user-gender gender-private">保密</span>';
                }
            ?>
            <?php if (!empty($userBio)): ?>
            <div class="user-bio"><?php echo htmlspecialchars($userBio); ?></div>
            <?php endif; ?>
            <div class="user-stats">
                <a class="stat-item" href="follows.php?user_id=<?php echo $userId; ?>&type=following">
                    <span class="stat-number" id="followingCount"><?php echo $followingCount; ?></span>
                    <span class="stat-label">关注</span>
                </a>
                <a class="stat-item" href="follows.php?user_id=<?php echo $userId; ?>&type=followers">
                    <span class="stat-number" id="followersCount"><?php echo $followersCount; ?></span>
                    <span class="stat-label">粉丝</span>
                </a>
                <div class="stat-item">
                    <span class="stat-number" id="likesCount"><?php echo $likesCount; ?></span>
                    <span class="stat-label">获赞</span>
                </div>
            </div>
            <?php if (!$isSelf): ?>
            <div class="follow-btn-wrapper">
                <button class="follow-btn <?php echo $isFollowing ? 'is-following' : 'not-following'; ?>" id="followBtn" onclick="toggleFollow()">
                    <?php echo $isFollowing ? '已关注' : '关注'; ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="section-divider"></div>
    <div class="posts-section">
        <div class="tab-bar">
            <div class="tab-item active" data-tab="posts">动态</div>
        </div>
        <div class="section-divider"></div>
        <div class="post-list" id="postList"></div>
        <div class="loading-indicator" id="loadingIndicator" style="display:none;"></div>
        <div class="empty-state" id="emptyState" style="display:none;">
            <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z"/></svg>
            <p>暂无动态</p>
        </div>
    </div>

    <div class="action-sheet-overlay" id="postActionSheet">
        <div class="action-sheet" onclick="event.stopPropagation()">
            <div class="action-sheet-title">操作</div>
            <div class="action-sheet-items" id="postActionItems">
            </div>
            <button class="action-sheet-cancel" onclick="closeActionSheet('postActionSheet')">取消</button>
        </div>
    </div>

    <div class="action-sheet-overlay" id="shareSheet">
        <div class="action-sheet" onclick="event.stopPropagation()">
            <div class="action-sheet-title">分享</div>
            <div class="share-sheet-items">
                <div class="share-item" onclick="shareToQQ()">
                    <div class="share-item-icon" style="background:#12B7F5;"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg></div>
                    <span class="share-item-label">QQ</span>
                </div>
                <div class="share-item" onclick="shareToWeChat()">
                    <div class="share-item-icon" style="background:#07C160;"><svg viewBox="0 0 24 24"><path d="M8.691 2.188C3.891 2.188 0 5.476 0 9.53c0 2.212 1.17 4.203 3.002 5.55a.59.59 0 01.213.665l-.39 1.48c-.019.07-.048.141-.048.213 0 .163.13.295.29.295a.326.326 0 00.167-.054l1.903-1.114a.864.864 0 01.717-.098 10.16 10.16 0 002.837.403c.276 0 .543-.027.811-.05-.857-2.578.157-4.972 1.932-6.446 1.703-1.415 3.882-1.98 5.853-1.838-.576-3.583-4.196-6.348-8.596-6.348zM5.785 5.991c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 01-1.162 1.178A1.17 1.17 0 014.623 7.17c0-.651.52-1.18 1.162-1.18zm5.813 0c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 01-1.162 1.178 1.17 1.17 0 01-1.162-1.178c0-.651.52-1.18 1.162-1.18zm5.34 2.867c-1.797-.052-3.746.512-5.28 1.786-1.72 1.428-2.687 3.72-1.78 6.22.942 2.453 3.666 4.229 6.884 4.229.826 0 1.622-.12 2.361-.336a.722.722 0 01.598.082l1.584.926a.272.272 0 00.14.047c.134 0 .24-.111.24-.247 0-.06-.023-.12-.038-.177l-.327-1.233a.582.582 0 01-.023-.156.49.49 0 01.201-.398C23.024 18.48 24 16.82 24 14.98c0-3.21-2.931-5.837-7.062-6.122zM14.033 13.4c.535 0 .969.44.969.982a.976.976 0 01-.969.983.976.976 0 01-.969-.983c0-.542.434-.982.97-.982zm4.844 0c.535 0 .969.44.969.982a.976.976 0 01-.969.983.976.976 0 01-.969-.983c0-.542.434-.982.97-.982z"/></svg></div>
                    <span class="share-item-label">微信</span>
                </div>
                <div class="share-item" onclick="shareByLink()">
                    <div class="share-item-icon" style="background:#666;"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div>
                    <span class="share-item-label">复制链接</span>
                </div>
            </div>
            <button class="action-sheet-cancel" onclick="closeActionSheet('shareSheet')">取消</button>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        var currentUserId = <?php echo $isLoggedIn ? intval($_SESSION['user_id']) : 0; ?>;
        var profileUserId = <?php echo $userId; ?>;
        var isSelf = <?php echo $isSelf ? 'true' : 'false'; ?>;
        var isFollowing = <?php echo $isFollowing ? 'true' : 'false'; ?>;
        var currentPage = 1;
        var isLoading = false;
        var hasMore = true;
        var reportPostId = null;

        function showToast(msg) {
            var t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.add('show');
            setTimeout(function() { t.classList.remove('show'); }, 2000);
        }

        function formatTime(timeStr) {
            var date = new Date(timeStr.replace(/-/g, '/'));
            var now = new Date();
            var diff = now - date;
            var seconds = Math.floor(diff / 1000);
            if (seconds < 60) return '刚刚';
            var minutes = Math.floor(seconds / 60);
            if (minutes < 60) return minutes + '分钟前';
            var hours = Math.floor(minutes / 60);
            if (hours < 24) return hours + '小时前';
            var days = Math.floor(hours / 24);
            if (days < 30) return days + '天前';
            var months = Math.floor(days / 30);
            if (months < 12) return months + '个月前';
            return Math.floor(months / 12) + '年前';
        }

        function createPostCard(post) {
            var card = document.createElement('div');
            card.className = 'post-card';
            card.setAttribute('data-post-id', post.id);

            var avatar = post.avatar ? '../' + post.avatar : '/image/mr.png';
            var name = post.real_name || post.username || '用户';
            var images = [];
            if (post.images) {
                try { images = JSON.parse(post.images); } catch(e) { images = []; }
            }

            var html = '<div class="post-publisher">';
            html += '<img class="post-publisher-avatar" src="' + avatar + '" onerror="this.src=\'/image/mr.png\'">';
            html += '<div class="post-publisher-info">';
            html += '<div class="post-publisher-name">' + escapeHtml(name) + '</div>';
            html += '<div class="post-publisher-time">' + formatTime(post.created_at) + '</div>';
            html += '</div></div>';

            html += '<div class="post-content-area">';
            if (post.title) {
                html += '<div class="post-title">' + escapeHtml(post.title) + '</div>';
            }
            html += '<div class="post-text">' + renderContent(post.content) + '</div>';
            html += '</div>';

            if (images.length > 0) {
                html += '<img class="post-image" src="../' + images[0] + '" onerror="this.style.display=\'none\'">';
            }

            if (post.video_url) {
                var coverSrc = '';
                if (post.video_cover) {
                    coverSrc = post.video_cover;
                    if (!coverSrc.startsWith('http') && !coverSrc.startsWith('/')) coverSrc = '../' + coverSrc;
                }
                html += '<div class="post-video-thumb" onclick="event.stopPropagation();location.href=\'detail.php?id=' + post.id + '\'">';
                if (coverSrc) {
                    html += '<img src="' + coverSrc + '" alt="" style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;display:block;" loading="lazy">';
                } else {
                    html += '<div style="width:100%;height:120px;background:#1a1a1a;border-radius:8px;display:flex;align-items:center;justify-content:center;">';
                    html += '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="1.5"><polygon points="5 3 19 12 5 21 5 3"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>';
                    html += '</div>';
                }
                html += '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:40px;height:40px;border-radius:50%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;">';
                html += '<svg viewBox="0 0 24 24" width="18" height="18" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg></div>';
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
                        html += '<div class="post-video-thumb ext-video-cover" data-ext-url="' + escapeHtml(vUrl) + '" onclick="event.stopPropagation();playExternalVideo(\'' + vUrl.replace(/'/g, "\\'") + '\')">';
                        html += '<div class="ext-video-poster" id="extPoster_' + post.id + '_' + vIdx + '" style="width:100%;height:160px;background:#1a1a1a;display:flex;align-items:center;justify-content:center;border-radius:8px;">';
                        html += '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="1.5"><polygon points="5 3 19 12 5 21 5 3"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>';
                        html += '</div>';
                        html += '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:40px;height:40px;border-radius:50%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;">';
                        html += '<svg viewBox="0 0 24 24" width="18" height="18" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg></div>';
                        html += '</div>';
                    });
                    html += '</div>';
                }
            }

            html += '<div class="post-interaction">';
            html += '<div class="interaction-btn like-btn ' + (post.is_liked ? 'liked' : '') + '" onclick="toggleLike(event, ' + post.id + ')">';
            html += (post.is_liked
                ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" fill="#EF4444" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" fill="#FCA5A5" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>');
            html += '<span class="count">' + (post.likes_count || 0) + '</span></div>';

            html += '<div class="interaction-btn comment-btn" onclick="goToDetail(' + post.id + ')">';
            html += '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7A8.38 8.38 0 0 1 4 11.5a8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M8 12h8M8 16h5" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round"/></svg>';
            html += '<span class="count">' + (post.comments_count || 0) + '</span></div>';

            html += '<div class="interaction-btn bookmark-btn ' + (post.is_favorited ? 'favorited' : '') + '" onclick="toggleBookmark(event, ' + post.id + ')">';
            html += (post.is_favorited
                ? '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 3L14.39 8.88L20.6 9.25L15.8 13.07L17.42 19.2L12 15.9L6.58 19.2L8.2 13.07L3.4 9.25L9.61 8.88L12 3Z" fill="#F59E0B"/></svg>'
                : '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l3.09 6.26 6.91 1.01-5 4.87 1.18 6.88L12 18.76l-6.18 3.26 1.18-6.88-5-4.87 6.91-1.01L12 2.5z" fill="#ffffff" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>');
            html += '</div>';

            html += '<div class="interaction-btn more-btn" onclick="showPostAction(event, ' + post.id + ')">';
            html += '<svg viewBox="0 0 24 24"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>';
            html += '</div>';

            html += '</div>';

            card.innerHTML = html;
            card.addEventListener('click', function(e) {
                if (e.target.closest('.interaction-btn')) return;
                goToDetail(post.id);
            });

            return card;
        }

        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
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

        function goToDetail(postId) {
            location.href = 'detail.php?id=' + postId;
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

        function loadPosts() {
            if (isLoading || !hasMore) return;
            isLoading = true;

            var loadingEl = document.getElementById('loadingIndicator');
            if (currentPage === 1) {
                document.getElementById('postList').innerHTML = '';
                loadingEl.innerHTML = buildShimmerCards(3);
                loadingEl.style.display = 'block';
            } else {
                loadingEl.innerHTML = '<div style="text-align:center;padding:12px;color:#999;font-size:13px;">加载中...</div>';
                loadingEl.style.display = 'block';
            }

            fetch('api/api.php?module=posts&action=list&user_id=' + profileUserId + '&page=' + currentPage)
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        var posts = data.data.posts;
                        if (posts.length === 0 && currentPage === 1) {
                            document.getElementById('emptyState').style.display = 'block';
                            hasMore = false;
                        } else {
                            var list = document.getElementById('postList');
                            posts.forEach(function(post) {
                                list.appendChild(createPostCard(post));
                            });
                            setTimeout(renderExtVideoPosters, 100);
                            if (posts.length < (data.data.per_page || 20)) {
                                hasMore = false;
                            } else {
                                currentPage++;
                            }
                        }
                    }
                })
                .catch(function(err) {
                    showToast('加载失败');
                })
                .finally(function() {
                    isLoading = false;
                    var loadingEl = document.getElementById('loadingIndicator');
                    loadingEl.style.display = 'none';
                    loadingEl.innerHTML = '';
                });
        }

        function toggleFollow() {
            var btn = document.getElementById('followBtn');
            fetch('api/api.php?module=follows&action=toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ following_id: profileUserId })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    isFollowing = data.data.is_following;
                    if (isFollowing) {
                        btn.className = 'follow-btn is-following';
                        btn.textContent = '已关注';
                    } else {
                        btn.className = 'follow-btn not-following';
                        btn.textContent = '关注';
                    }
                    loadFollowStats();
                } else {
                    showToast(data.error || '操作失败');
                }
            })
            .catch(function() { showToast('网络错误'); });
        }

        function loadFollowStats() {
            fetch('api/api.php?module=follows&action=stats&user_id=' + profileUserId)
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        document.getElementById('followingCount').textContent = data.data.following_count;
                        document.getElementById('followersCount').textContent = data.data.followers_count;
                    }
                })
                .catch(function() {});
        }

        function toggleLike(e, postId) {
            e.stopPropagation();
            var btn = e.currentTarget;
            fetch('api/api.php?module=likes&action=toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ target_id: postId, target_type: 'post' })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    var isLiked = data.data.is_liked;
                    var count = data.data.likes_count;
                    if (isLiked) {
                        btn.classList.add('liked');
                    } else {
                        btn.classList.remove('liked');
                    }
                    btn.querySelector('svg').outerHTML = isLiked
                        ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" fill="#EF4444" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" fill="#FCA5A5" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                        : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>';
                    btn.querySelector('.count').textContent = count;
                }
            })
            .catch(function() { showToast('操作失败'); });
        }

        function toggleBookmark(e, postId) {
            e.stopPropagation();
            var btn = e.currentTarget;
            fetch('api/api.php?module=favorites&action=toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ post_id: postId })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    if (data.data.is_favorited) {
                        btn.classList.add('favorited');
                        showToast('已收藏');
                    } else {
                        btn.classList.remove('favorited');
                        showToast('已取消收藏');
                    }
                    btn.querySelector('svg').outerHTML = data.data.is_favorited
                        ? '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 3L14.39 8.88L20.6 9.25L15.8 13.07L17.42 19.2L12 15.9L6.58 19.2L8.2 13.07L3.4 9.25L9.61 8.88L12 3Z" fill="#F59E0B"/></svg>'
                        : '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l3.09 6.26 6.91 1.01-5 4.87 1.18 6.88L12 18.76l-6.18 3.26 1.18-6.88-5-4.87 6.91-1.01L12 2.5z" fill="#ffffff" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                }
            })
            .catch(function() { showToast('操作失败'); });
        }

        function showPostAction(e, postId) {
            e.stopPropagation();
            reportPostId = postId;
            var items = document.getElementById('postActionItems');
            var html = '';
            if (isSelf) {
                html += '<button class="action-sheet-item danger" onclick="deletePost(' + postId + ')">删除</button>';
                html += '<button class="action-sheet-item" onclick="editPost(' + postId + ')">编辑</button>';
            }
            html += '<button class="action-sheet-item" onclick="openShareSheet()">分享</button>';
            items.innerHTML = html;
            document.getElementById('postActionSheet').classList.add('show');
        }

        function closeActionSheet(id) {
            document.getElementById(id).classList.remove('show');
        }

        function deletePost(postId) {
            closeActionSheet('postActionSheet');
            fetch('api/api.php?module=posts&action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: postId })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    showToast('已删除');
                    currentPage = 1;
                    hasMore = true;
                    document.getElementById('postList').innerHTML = '';
                    loadPosts();
                } else {
                    showToast(data.error || '删除失败');
                }
            })
            .catch(function() { showToast('网络错误'); });
        }

        function editPost(postId) {
            closeActionSheet('postActionSheet');
            location.href = 'publish.php?edit=' + postId;
        }

        function openShareSheet() {
            closeActionSheet('postActionSheet');
            document.getElementById('shareSheet').classList.add('show');
        }

        function shareToQQ() {
            closeActionSheet('shareSheet');
            shareCurrentPost('链接已复制，可粘贴到 QQ 分享');
        }

        function shareToWeChat() {
            closeActionSheet('shareSheet');
            shareCurrentPost('链接已复制，可粘贴到微信分享');
        }

        function shareCurrentPost(copyMessage) {
            var link = location.origin + '/community/detail.php?id=' + reportPostId;
            if (navigator.share) {
                navigator.share({ title: '动态详情', url: link }).catch(function(error) {
                    if (error && error.name !== 'AbortError') showToast('分享失败');
                });
            } else {
                navigator.clipboard.writeText(link).then(function() {
                    showToast(copyMessage || '链接已复制');
                }).catch(function() { showToast('复制失败'); });
            }
        }

        function shareByLink() {
            closeActionSheet('shareSheet');
            var link = location.origin + '/community/detail.php?id=' + reportPostId;
            navigator.clipboard.writeText(link).then(function() {
                showToast('链接已复制');
            }).catch(function() { showToast('复制失败'); });
        }

        window.addEventListener('scroll', function() {
            if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 200) {
                loadPosts();
            }
        });

        loadPosts();

        document.querySelectorAll('.action-sheet-overlay').forEach(function(el) {
            el.addEventListener('click', function() {
                this.classList.remove('show');
            });
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
                player = null;
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
