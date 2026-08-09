<?php
session_start();
$config = require_once __DIR__ . '/../config.php';

$isLoggedIn = isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0;
if (empty($_SESSION['community_csrf'])) {
    $_SESSION['community_csrf'] = bin2hex(random_bytes(32));
}
$communityCsrf = (string)$_SESSION['community_csrf'];

$postId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($postId <= 0) {
    die('帖子不存在');
}

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
    $currentUser = null;
    if ($isLoggedIn) {
        $stmt = $pdo->prepare("SELECT id, username, real_name, avatar FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $currentUser = $stmt->fetch();
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
    <title>动态详情</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        html, body {
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Helvetica Neue', Arial, sans-serif;
            background-color: #e8e8e8;
            color: <?php echo $textPrimary; ?>;
            min-height: 100vh;
            position: relative;
        }

        .page-container {
            max-width: 500px;
            margin: 0 auto;
            min-height: 100vh;
            background: <?php echo $cardBg; ?>;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .header {
            position: sticky;
            top: 0;
            height: 50px;
            background: <?php echo $cardBg; ?>;
            display: flex;
            align-items: center;
            padding: 0 12px;
            z-index: 100;
        }

        .header-back {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
        }

        .header-back img {
            width: 20px;
            height: 20px;
        }

        .header-author {
            display: flex;
            align-items: center;
            flex: 1;
            margin-left: 8px;
            overflow: hidden;
        }

        .header-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }

        .header-username {
            font-size: 15px;
            font-weight: 500;
            margin-left: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: <?php echo $textPrimary; ?>;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .follow-btn {
            padding: 0 16px;
            height: 28px;
            border-radius: 14px;
            background: #4A7BF7;
            color: #fff;
            font-size: 13px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .follow-btn.following {
            background: #f0f0f0;
            color: <?php echo $textSecondary; ?>;
            font-weight: 400;
        }

        .follow-btn:active {
            opacity: 0.7;
        }

        .more-btn {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
        }

        .more-dots {
            display: flex;
            flex-direction: column;
            gap: 3px;
            align-items: center;
        }

        .more-dots span {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: <?php echo $textSecondary; ?>;
        }

        .more-popup {
            position: absolute;
            top: 32px;
            right: 0;
            background: <?php echo $cardBg; ?>;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
            z-index: 200;
            min-width: 80px;
            display: none;
            overflow: hidden;
        }

        .more-popup.show {
            display: block;
        }

        .more-popup-item {
            padding: 10px 16px;
            font-size: 14px;
            color: <?php echo $textPrimary; ?>;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.15s;
        }

        .more-popup-item:active {
            background: #f5f5f5;
        }

        .post-card {
            background: <?php echo $cardBg; ?>;
            padding: 16px;
        }

        .post-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            line-height: 1.4;
            color: <?php echo $textPrimary; ?>;
        }

        .post-content {
            font-size: 15px;
            font-weight: 400;
            line-height: 1.6;
            color: <?php echo $textPrimary; ?>;
            word-break: break-word;
        }

        .post-images {
            margin-top: 12px;
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
            cursor: pointer;
        }

        .post-image-wrap img {
            width: 100%;
            max-height: 300px;
            object-fit: cover;
            display: block;
        }

        .post-time {
            font-size: 12px;
            color: <?php echo $textSecondary; ?>;
            margin-top: 12px;
        }

        .interaction-bar {
            display: flex;
            align-items: center;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #f5f5f5;
            gap: 24px;
        }

        .interaction-item {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .interaction-item:active {
            opacity: 0.6;
        }

        .interaction-item svg {
            width: 20px;
            height: 20px;
        }

        .interaction-item .count {
            font-size: 13px;
            color: <?php echo $textSecondary; ?>;
        }

        .interaction-item.liked .count {
            color: #EF4444;
        }

        .interaction-item.bookmarked .count {
            color: #F59E0B;
        }

        .comments-section {
            background: <?php echo $cardBg; ?>;
            padding: 16px;
            flex: 1;
        }

        .comments-title {
            font-size: 16px;
            font-weight: 600;
            color: <?php echo $textPrimary; ?>;
            margin-bottom: 16px;
        }

        .comments-title span {
            color: <?php echo $textSecondary; ?>;
            font-weight: 400;
            font-size: 14px;
            margin-left: 4px;
        }

        .comment-item {
            margin-bottom: 16px;
        }

        .comment-header {
            display: flex;
            align-items: center;
        }

        .comment-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }

        .comment-info {
            flex: 1;
            margin-left: 8px;
            overflow: hidden;
        }

        .comment-username {
            font-size: 14px;
            font-weight: 500;
            color: <?php echo $textPrimary; ?>;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .comment-time {
            font-size: 12px;
            color: <?php echo $textSecondary; ?>;
            margin-top: 1px;
        }

        .comment-like {
            display: flex;
            align-items: center;
            gap: 3px;
            cursor: pointer;
            flex-shrink: 0;
            padding: 4px;
        }

        .comment-like svg {
            width: 16px;
            height: 16px;
        }

        .comment-like .count {
            font-size: 12px;
            color: <?php echo $textSecondary; ?>;
        }

        .comment-like.liked .count {
            color: #EF4444;
        }

        .comment-more-btn {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            padding: 4px;
            margin-left: 4px;
        }

        .comment-more-btn svg {
            width: 16px;
            height: 16px;
            fill: <?php echo $textSecondary; ?>;
        }

        .comment-body {
            margin-top: 6px;
            margin-left: 40px;
            font-size: 14px;
            line-height: 1.5;
            color: <?php echo $textPrimary; ?>;
            word-break: break-word;
        }

        .reply-toggle {
            margin-left: 40px;
            margin-top: 6px;
            font-size: 13px;
            color: <?php echo $primaryColor; ?>;
            cursor: pointer;
            display: inline-block;
        }

        .reply-toggle:active {
            opacity: 0.7;
        }

        .replies-container {
            margin-left: 40px;
            margin-top: 8px;
        }

        .reply-item {
            display: flex;
            margin-bottom: 10px;
        }

        .reply-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .reply-content {
            flex: 1;
            margin-left: 8px;
        }

        .reply-header {
            display: flex;
            align-items: center;
        }

        .reply-username {
            font-size: 13px;
            font-weight: 500;
            color: <?php echo $textPrimary; ?>;
        }

        .reply-time {
            font-size: 11px;
            color: <?php echo $textSecondary; ?>;
            margin-left: 8px;
        }

        .reply-like {
            display: flex;
            align-items: center;
            gap: 2px;
            cursor: pointer;
            margin-left: auto;
            flex-shrink: 0;
            padding: 2px;
        }

        .reply-like svg {
            width: 14px;
            height: 14px;
        }

        .reply-like .count {
            font-size: 11px;
            color: <?php echo $textSecondary; ?>;
        }

        .reply-like.liked .count {
            color: #EF4444;
        }

        .reply-text {
            font-size: 13px;
            line-height: 1.5;
            color: <?php echo $textPrimary; ?>;
            margin-top: 3px;
            word-break: break-word;
        }

        .load-more-comments {
            text-align: center;
            padding: 12px;
            font-size: 14px;
            color: <?php echo $primaryColor; ?>;
            cursor: pointer;
        }

        .load-more-comments:active {
            opacity: 0.7;
        }

        .no-comments {
            text-align: center;
            padding: 30px 0;
            font-size: 14px;
            color: <?php echo $textSecondary; ?>;
        }

        .bottom-input {
            position: sticky;
            bottom: 0;
            background: <?php echo $cardBg; ?>;
            padding: 8px 16px;
            padding-bottom: calc(8px + env(safe-area-inset-bottom));
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .reply-label {
            position: sticky;
            bottom: 52px;
            background: #FFF8E1;
            padding: 6px 16px;
            font-size: 13px;
            color: #F57C00;
            display: none;
            align-items: center;
            justify-content: space-between;
            z-index: 100;
        }

        .reply-label.show {
            display: flex;
        }

        .reply-label-cancel {
            cursor: pointer;
            font-size: 16px;
            color: #F57C00;
            padding: 0 4px;
        }

        .input-wrap {
            flex: 1;
            position: relative;
        }

        .comment-input {
            width: 100%;
            height: 36px;
            border-radius: 20px;
            background: <?php echo $bgColor; ?>;
            border: none;
            padding: 0 16px;
            font-size: 14px;
            color: <?php echo $textPrimary; ?>;
            outline: none;
        }

        .comment-input::placeholder {
            color: <?php echo $textSecondary; ?>;
        }

        .send-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: <?php echo $primaryColor; ?>;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: opacity 0.2s;
        }

        .send-btn:active {
            opacity: 0.7;
        }

        .send-btn svg {
            width: 18px;
            height: 18px;
            fill: #fff;
        }

        .image-viewer {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            z-index: 300;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .image-viewer.show {
            display: flex;
        }

        .image-viewer img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .image-viewer-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .image-viewer-close svg {
            width: 20px;
            height: 20px;
            fill: #fff;
        }

        .toast {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.75);
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            z-index: 400;
            display: none;
            pointer-events: none;
        }

        .toast.show {
            display: block;
            animation: toastFade 1.5s ease forwards;
        }

        @keyframes toastFade {
            0% { opacity: 0; }
            15% { opacity: 1; }
            70% { opacity: 1; }
            100% { opacity: 0; }
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

        .loading-spinner {
            text-align: center;
            padding: 20px;
            color: <?php echo $textSecondary; ?>;
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
            aspect-ratio: 16/9;
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

        .shimmer-comment {
            display: flex;
            gap: 10px;
            padding: 12px 0;
        }

        .shimmer-comment-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e8e8e8;
            flex-shrink: 0;
            position: relative;
            overflow: hidden;
        }

        .shimmer-comment-avatar::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(105deg, transparent 30%, rgba(255,255,255,0.6) 45%, rgba(255,255,255,0.9) 50%, rgba(255,255,255,0.6) 55%, transparent 70%);
            background-size: 300% 100%;
            animation: shimmerWave 2.5s infinite linear;
        }
    </style>
    <link rel="stylesheet" href="css/video-player.css">
</head>
<body>
    <div class="page-container">
    <div class="header">
        <div class="header-back" onclick="window.location.href='index.php'">
            <img src="/image/exit2.png" alt="返回">
        </div>
        <div class="header-author" id="headerAuthor" style="cursor: pointer;">
            <img class="header-avatar" id="headerAvatar" src="/image/mr.png" alt="">
            <span class="header-username" id="headerUsername"></span>
        </div>
        <div class="header-actions">
            <button class="follow-btn" id="followBtn" style="display:none;" onclick="toggleFollow()">关注</button>
            <div class="more-btn" id="moreBtn" onclick="toggleMorePopup(event)">
                <div class="more-dots">
                    <span></span><span></span><span></span>
                </div>
                <div class="more-popup" id="morePopup">
                    <div class="more-popup-item" id="deletePostBtn" style="display:none;color:#e53e3e;" onclick="deletePost()">删除</div>
                    <div class="more-popup-item" onclick="downloadPost()">下载</div>
                    <div class="more-popup-item" onclick="reportPost()">举报</div>
                </div>
            </div>
        </div>
    </div>

    <div class="post-card" id="postCard">
        <div id="postLoading">
            <div class="shimmer-card">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                    <div class="shimmer-avatar"></div>
                    <div style="flex:1;"><div class="shimmer-line short" style="margin-bottom:6px;height:12px;"></div><div class="shimmer-line" style="width:40%;height:10px;"></div></div>
                </div>
                <div class="shimmer-line medium"></div>
                <div class="shimmer-line short"></div>
                <div class="shimmer-image"></div>
            </div>
        </div>
    </div>

    <div class="comments-section" id="commentsSection">
        <div class="comments-title">评论<span id="commentCount"></span></div>
        <div id="commentsList"></div>
        <div class="loading-spinner" id="commentsLoading" style="display:none;">
            <div class="shimmer-comment"><div class="shimmer-comment-avatar"></div><div style="flex:1;"><div class="shimmer-line short" style="margin-bottom:6px;height:12px;"></div><div class="shimmer-line medium"></div></div></div>
            <div class="shimmer-comment"><div class="shimmer-comment-avatar"></div><div style="flex:1;"><div class="shimmer-line short" style="margin-bottom:6px;height:12px;"></div><div class="shimmer-line short"></div></div></div>
        </div>
    </div>

    <div class="reply-label" id="replyLabel">
        <span id="replyLabelText"></span>
        <span class="reply-label-cancel" onclick="cancelReply()">✕</span>
    </div>

    <div class="bottom-input">
        <div class="input-wrap">
            <input class="comment-input" id="commentInput" type="text" placeholder="写评论..." maxlength="500">
        </div>
        <button class="send-btn" onclick="submitComment()">
            <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
        </button>
    </div>
    </div>

    <div class="image-viewer" id="imageViewer" onclick="closeImageViewer()">
        <div class="image-viewer-close">
            <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </div>
        <img id="viewerImage" src="" alt="">
    </div>

    <div class="action-sheet-overlay" id="actionSheetOverlay">
        <div class="action-sheet" id="actionSheetContent" onclick="event.stopPropagation()">
        </div>
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
            <button class="action-sheet-cancel" onclick="closeActionSheet('reportSheetOverlay')">取消</button>
        </div>
    </div>

    <div class="action-sheet-overlay" id="shareSheetOverlay">
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
            <button class="action-sheet-cancel" onclick="closeActionSheet('shareSheetOverlay')">取消</button>
        </div>
    </div>

    <div class="toast" id="toast"></div>

<script src="js/hls.min.js"></script>
    <script src="js/video-player.js"></script>
    <script>
        const postId = <?php echo $postId; ?>;
        const currentUserId = <?php echo $isLoggedIn ? intval($_SESSION['user_id']) : 0; ?>;
        let postData = null;
        let isFollowing = false;
        let isLiked = false;
        let isFavorited = false;
        let commentPage = 1;
        let commentTotal = 0;
        let commentsLoaded = false;
        let loadingComments = false;
        let hasMoreComments = true;
        let replyTo = null;
        const defaultAvatar = '/image/mr.png';

        function getAvatarUrl(avatar) {
            if (!avatar) return defaultAvatar;
            if (avatar.startsWith('http')) return avatar;
            return '/' + avatar.replace(/^\/+/, '');
        }

        function formatTime(timeStr) {
            if (!timeStr) return '';
            const date = new Date(timeStr.replace(/-/g, '/'));
            const now = new Date();
            const diff = (now - date) / 1000;
            if (diff < 60) return '刚刚';
            if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
            if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
            if (diff < 604800) return Math.floor(diff / 86400) + '天前';
            const m = date.getMonth() + 1;
            const d = date.getDate();
            if (date.getFullYear() === now.getFullYear()) return m + '月' + d + '日';
            return date.getFullYear() + '年' + m + '月' + d + '日';
        }

        function showToast(msg) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.remove('show');
            void t.offsetWidth;
            t.classList.add('show');
        }

        async function loadPost() {
            try {
                const res = await fetch('api/api.php?module=posts&action=detail&id=' + postId);
                const json = await res.json();
                if (!json.success) {
                    document.getElementById('postLoading').innerHTML = '<div style="text-align:center;padding:40px 20px;color:' + '<?php echo $textSecondary; ?>' + ';font-size:14px;">' + (json.error || '加载失败') + '</div>';
                    return;
                }
                const data = json.data;
                postData = data.post;
                isLiked = data.is_liked;
                isFavorited = data.is_favorited;
                isFollowing = data.is_following;
                renderPost();
            } catch (e) {
                document.getElementById('postLoading').innerHTML = '<div style="text-align:center;padding:40px 20px;color:' + '<?php echo $textSecondary; ?>' + ';font-size:14px;">加载失败</div>';
            }
        }

        function renderPost() {
            const p = postData;
            document.getElementById('headerAvatar').src = getAvatarUrl(p.avatar);
            document.getElementById('headerUsername').textContent = p.real_name || p.username;
            document.getElementById('headerAuthor').onclick = function() {
                location.href = 'profile.php?user_id=' + p.user_id;
            };

            const followBtn = document.getElementById('followBtn');
            const deletePostBtn = document.getElementById('deletePostBtn');
            if (p.user_id == currentUserId) {
                followBtn.style.display = 'none';
                deletePostBtn.style.display = 'block';
            } else {
                followBtn.style.display = 'flex';
                deletePostBtn.style.display = 'none';
                updateFollowBtn();
            }

            let html = '';
            if (p.title) {
                html += '<div class="post-title">' + escapeHtml(p.title) + '</div>';
            }
            html += '<div class="post-content">' + renderContent(p.content) + '</div>';

            if (p.images) {
                let images = [];
                try { images = JSON.parse(p.images); } catch (e) { images = []; }
                if (images.length > 0) {
                    const countClass = 'count-' + Math.min(images.length, 3);
                    html += '<div class="post-images ' + countClass + '">';
                    images.forEach(function(img) {
                        const imgUrl = img.startsWith('http') ? img : '/' + img.replace(/^\/+/, '');
                        html += '<div class="post-image-wrap" onclick="viewImage(\'' + imgUrl.replace(/'/g, "\\'") + '\')">';
                        html += '<img src="' + imgUrl + '" alt="" loading="lazy">';
                        html += '</div>';
                    });
                    html += '</div>';
                }
            }

            if (p.video_url) {
                const videoUrl = p.video_url.startsWith('http') ? p.video_url : '/' + p.video_url.replace(/^\/+/, '');
                let coverUrl = '';
                if (p.video_cover) {
                    coverUrl = p.video_cover.startsWith('http') ? p.video_cover : '/' + p.video_cover.replace(/^\/+/, '');
                }
                html += '<div id="videoPlayerContainer" style="margin-top:12px;"></div>';
            }

            if (p.external_videos) {
                let extVideos = [];
                try { extVideos = JSON.parse(p.external_videos); } catch(e) { extVideos = []; }
                if (extVideos.length > 0) {
                    html += '<div style="margin-top:12px;display:flex;flex-direction:column;gap:8px;">';
                    extVideos.forEach(function(vUrl, vIdx) {
                        html += '<div class="ext-video-lazy-player" data-ext-url="' + escapeHtml(vUrl) + '" style="position:relative;border-radius:8px;overflow:hidden;background:#000;cursor:pointer;" onclick="initAndPlayExtVideo(this)">';
                        html += '<div class="ext-video-poster" style="width:100%;aspect-ratio:16/9;background:#1a1a1a;display:flex;align-items:center;justify-content:center;">';
                        html += '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="1.5"><polygon points="5 3 19 12 5 21 5 3"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>';
                        html += '</div>';
                        html += '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:52px;height:52px;border-radius:50%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;pointer-events:none;">';
                        html += '<svg viewBox="0 0 24 24" width="24" height="24" fill="#fff" style="margin-left:3px;"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg></div>';
                        html += '</div>';
                    });
                    html += '</div>';
                }
            }

            html += '<div class="post-time">' + formatTime(p.created_at) + '</div>';

            html += '<div class="interaction-bar">';
            html += buildLikeBtn(isLiked, p.likes_count, 'post', postId);
            html += '<div class="interaction-item" onclick="scrollToComments()">';
            html += '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7A8.38 8.38 0 0 1 4 11.5a8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M8 12h8M8 16h5" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round"/></svg>';
            html += '<span class="count">' + (p.comments_count || 0) + '</span>';
            html += '</div>';
            html += buildBookmarkBtn(isFavorited);
            html += '<div class="interaction-item" onclick="sharePost()">';
            html += '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>';
            html += '</div>';
            html += '</div>';

            document.getElementById('postCard').innerHTML = html;

            if (p.video_url) {
                if (window.currentVideoPlayer) {
                    window.currentVideoPlayer.destroy();
                    window.currentVideoPlayer = null;
                }
                const videoUrl = p.video_url.startsWith('http') ? p.video_url : '/' + p.video_url.replace(/^\/+/, '');
                let coverUrl = '';
                if (p.video_cover) {
                    coverUrl = p.video_cover.startsWith('http') ? p.video_cover : '/' + p.video_cover.replace(/^\/+/, '');
                }
                if (typeof MoonyaVideoPlayer !== 'undefined') {
                    window.currentVideoPlayer = new MoonyaVideoPlayer('#videoPlayerContainer', {
                        src: videoUrl,
                        poster: coverUrl,
                        primaryColor: '<?php echo $primaryColor; ?>',
                        compact: false
                    });
                }
            }

            initExtVideoPlayers();
        }

        function buildLikeBtn(liked, count, type, id) {
            const cls = liked ? 'interaction-item liked' : 'interaction-item';
            const svg = liked
                ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" fill="#EF4444" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" fill="#FCA5A5" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>';
            return '<div class="' + cls + '" onclick="toggleLike(\'' + type + '\',' + id + ',this)">' +
                svg +
                '<span class="count">' + count + '</span></div>';
        }

        function buildBookmarkBtn(bookmarked) {
            const cls = bookmarked ? 'interaction-item bookmarked' : 'interaction-item';
            const svg = bookmarked
                ? '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 3L14.39 8.88L20.6 9.25L15.8 13.07L17.42 19.2L12 15.9L6.58 19.2L8.2 13.07L3.4 9.25L9.61 8.88L12 3Z" fill="#F59E0B"/></svg>'
                : '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l3.09 6.26 6.91 1.01-5 4.87 1.18 6.88L12 18.76l-6.18 3.26 1.18-6.88-5-4.87 6.91-1.01L12 2.5z" fill="#ffffff" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            return '<div class="' + cls + '" onclick="toggleBookmark(this)">' +
                svg +
                '</div>';
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function renderContent(text) {
            if (!text) return '';
            var placeholders = [];
            var processed = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(match, linkText, url) {
                var idx = placeholders.length;
                placeholders.push('<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" style="color:#4A90D9;text-decoration:none;cursor:pointer;" onclick="event.preventDefault();window.open(\'' + url.replace(/'/g, "\\'") + '\', \'_blank\')">' + escapeHtml(linkText) + '</a>');
                return '___LINKPH_' + idx + '___';
            });
            var escaped = escapeHtml(processed);
            for (var i = 0; i < placeholders.length; i++) {
                escaped = escaped.replace('___LINKPH_' + i + '___', placeholders[i]);
            }
            return escaped;
        }

        async function toggleLike(type, id, el) {
            try {
                const res = await fetch('api/api.php?module=likes&action=toggle', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ target_id: id, target_type: type })
                });
                const json = await res.json();
                if (!json.success) { showToast(json.error || '操作失败'); return; }
                const d = json.data;
                if (type === 'post') {
                    isLiked = d.is_liked;
                    renderPost();
                } else {
                    updateCommentLikeUI(el, d.is_liked, d.likes_count);
                }
            } catch (e) {
                showToast('网络错误');
            }
        }

        function updateCommentLikeUI(el, liked, count) {
            if (liked) {
                el.classList.add('liked');
            } else {
                el.classList.remove('liked');
            }
            el.querySelector('svg').outerHTML = liked
                ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" fill="#EF4444" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" fill="#FCA5A5" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>';
            const countEl = el.querySelector('.count');
            if (countEl) countEl.textContent = count;
        }

        async function toggleBookmark(el) {
            try {
                const res = await fetch('api/api.php?module=favorites&action=toggle', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ post_id: postId })
                });
                const json = await res.json();
                if (!json.success) { showToast(json.error || '操作失败'); return; }
                isFavorited = json.data.is_favorited;
                renderPost();
            } catch (e) {
                showToast('网络错误');
            }
        }

        async function toggleFollow() {
            if (!postData) return;
            try {
                const res = await fetch('api/api.php?module=follows&action=toggle', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ following_id: postData.user_id })
                });
                const json = await res.json();
                if (!json.success) { showToast(json.error || '操作失败'); return; }
                isFollowing = json.data.is_following;
                updateFollowBtn();
            } catch (e) {
                showToast('网络错误');
            }
        }

        function updateFollowBtn() {
            const btn = document.getElementById('followBtn');
            if (isFollowing) {
                btn.textContent = '已关注';
                btn.classList.add('following');
            } else {
                btn.textContent = '关注';
                btn.classList.remove('following');
            }
        }

        function toggleMorePopup(e) {
            e.stopPropagation();
            const popup = document.getElementById('morePopup');
            popup.classList.toggle('show');
        }

        document.addEventListener('click', function() {
            document.getElementById('morePopup').classList.remove('show');
        });

        function downloadPost() {
            document.getElementById('morePopup').classList.remove('show');
            if (!postData) return;

            var p = postData;
            var downloads = [];

            if (p.video_url) {
                downloads.push({ url: p.video_url, type: 'video' });
            }

            if (p.images) {
                try {
                    var imgs = typeof p.images === 'string' ? JSON.parse(p.images) : p.images;
                    imgs.forEach(function(img) {
                        downloads.push({ url: img, type: 'image' });
                    });
                } catch(e) {}
            }

            if (downloads.length === 0) {
                showToast('没有可下载的内容');
                return;
            }

            downloads.forEach(function(item, index) {
                var url = item.url;
                if (!url.startsWith('http')) {
                    url = '/' + url.replace(/^\/+/, '');
                }

                var filename;
                if (item.type === 'video') {
                    if (url.indexOf('.m3u8') !== -1) {
                        showToast('视频为流媒体格式，正在下载...');
                        fetch('api/api.php?module=video_process&action=download', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ video_url: item.url, post_id: p.id })
                        }).then(function(r) { return r.blob(); }).then(function(blob) {
                            var a = document.createElement('a');
                            a.href = URL.createObjectURL(blob);
                            a.download = 'video_' + p.id + '.mp4';
                            a.click();
                            URL.revokeObjectURL(a.href);
                        }).catch(function() {
                            showToast('视频下载失败');
                        });
                        return;
                    }
                    filename = 'video_' + p.id + '.' + url.split('.').pop().split('?')[0];
                } else {
                    filename = 'image_' + p.id + '_' + (index + 1) + '.' + url.split('.').pop().split('?')[0];
                }

                var a = document.createElement('a');
                a.href = url;
                a.download = filename;
                a.target = '_blank';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            });

            showToast('开始下载...');
        }

        async function reportPost() {
            document.getElementById('morePopup').classList.remove('show');
            document.getElementById('reportSheetOverlay').classList.add('show');
        }

        async function deletePost() {
            document.getElementById('morePopup').classList.remove('show');
            if (!confirm('确定要删除这篇帖子吗？删除后不可恢复。')) return;
            try {
                const res = await fetch('api/api.php?module=posts&action=delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: postId })
                });
                const json = await res.json();
                if (json.success) {
                    showToast('帖子已删除');
                    setTimeout(function() {
                        window.location.href = 'index.php';
                    }, 1000);
                } else {
                    showToast(json.error || '删除失败');
                }
            } catch (e) {
                showToast('删除失败');
            }
        }

        function closeActionSheet(id) {
            document.getElementById(id).classList.remove('show');
        }

        function showCommentAction(commentId, isOwner) {
            var sheet = document.getElementById('actionSheetContent');
            var html = '<div class="action-sheet-title">操作</div>';
            html += '<div class="action-sheet-items">';
            if (isOwner) {
                html += '<button class="action-sheet-item danger" onclick="deleteComment(' + commentId + ')">删除</button>';
            }
            html += '<button class="action-sheet-item" onclick="reportComment(' + commentId + ')">举报</button>';
            html += '</div>';
            html += '<button class="action-sheet-cancel" onclick="closeActionSheet(\'actionSheetOverlay\')">取消</button>';
            sheet.innerHTML = html;
            document.getElementById('actionSheetOverlay').classList.add('show');
        }

        async function deleteComment(commentId) {
            closeActionSheet('actionSheetOverlay');
            try {
                const res = await fetch('api/api.php?module=comments&action=delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: commentId })
                });
                const json = await res.json();
                if (json.success) {
                    showToast('已删除');
                    commentPage = 1;
                    hasMoreComments = true;
                    document.getElementById('commentsList').innerHTML = '';
                    await loadComments();
                    if (postData) {
                        postData.comments_count = Math.max(0, (postData.comments_count || 0) - 1);
                        renderPost();
                    }
                } else {
                    showToast(json.error || '删除失败');
                }
            } catch (e) {
                showToast('网络错误');
            }
        }

        function reportComment(commentId) {
            closeActionSheet('actionSheetOverlay');
            window._reportCommentId = commentId;
            window._reportType = 'comment';
            document.getElementById('reportSheetOverlay').classList.add('show');
        }

        async function submitReport(reason) {
            if (!reason) { showToast('请选择举报原因'); return; }
            closeActionSheet('reportSheetOverlay');
            var targetId = window._reportCommentId || postId;
            var targetType = window._reportType || 'post';
            try {
                const res = await fetch('api/api.php?module=reports&action=create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ target_id: targetId, target_type: targetType, reason: reason })
                });
                const json = await res.json();
                if (json.success) {
                    showToast('举报已提交');
                } else {
                    showToast(json.error || '举报失败');
                }
                window._reportCommentId = null;
                window._reportType = null;
            } catch (e) {
                showToast('网络错误');
            }
        }

        function sharePost() {
            document.getElementById('shareSheetOverlay').classList.add('show');
        }

        function shareToQQ() {
            closeActionSheet('shareSheetOverlay');
            shareUsingPlatform('链接已复制，可粘贴到 QQ 分享');
        }

        function shareToWeChat() {
            closeActionSheet('shareSheetOverlay');
            shareUsingPlatform('链接已复制，可粘贴到微信分享');
        }

        function shareUsingPlatform(copyMessage) {
            var shareData = {
                title: postData ? (postData.title || '动态详情') : '动态详情',
                url: window.location.href
            };
            if (navigator.share) {
                navigator.share(shareData).catch(function(error) {
                    if (error && error.name !== 'AbortError') showToast('分享失败');
                });
            } else {
                navigator.clipboard.writeText(window.location.href).then(function() {
                    showToast(copyMessage || '链接已复制');
                }).catch(function() {
                    showToast('复制失败');
                });
            }
        }

        function shareByLink() {
            closeActionSheet('shareSheetOverlay');
            navigator.clipboard.writeText(window.location.href).then(function() {
                showToast('链接已复制');
            }).catch(function() {
                showToast('复制失败');
            });
        }

        function viewImage(src) {
            document.getElementById('viewerImage').src = src;
            document.getElementById('imageViewer').classList.add('show');
        }

        function closeImageViewer() {
            document.getElementById('imageViewer').classList.remove('show');
        }

        function scrollToComments() {
            document.getElementById('commentsSection').scrollIntoView({ behavior: 'smooth' });
            document.getElementById('commentInput').focus();
        }

        async function loadComments() {
            if (loadingComments || !hasMoreComments) return;
            loadingComments = true;
            document.getElementById('commentsLoading').style.display = 'block';

            try {
                const res = await fetch('api/api.php?module=comments&action=list&post_id=' + postId + '&page=' + commentPage);
                const json = await res.json();
                if (!json.success) { loadingComments = false; return; }
                const data = json.data;
                commentTotal = data.total;
                document.getElementById('commentCount').textContent = '(' + commentTotal + ')';

                const list = document.getElementById('commentsList');
                if (commentPage === 1 && data.comments.length === 0) {
                    list.innerHTML = '<div class="no-comments">暂无评论，快来抢沙发~</div>';
                } else {
                    if (commentPage === 1) list.innerHTML = '';
                    data.comments.forEach(function(c) {
                        list.appendChild(createCommentEl(c));
                    });
                }

                hasMoreComments = data.comments.length >= data.per_page;
                commentPage++;
                commentsLoaded = true;
            } catch (e) {
            } finally {
                loadingComments = false;
                document.getElementById('commentsLoading').style.display = 'none';
            }
        }

        function createCommentEl(c) {
            const div = document.createElement('div');
            div.className = 'comment-item';
            div.dataset.commentId = c.id;

            let html = '<div class="comment-header">';
            html += '<img class="comment-avatar" src="' + getAvatarUrl(c.avatar) + '" alt="">';
            html += '<div class="comment-info">';
            html += '<div class="comment-username">' + escapeHtml(c.real_name || c.username) + '</div>';
            html += '<div class="comment-time">' + formatTime(c.created_at) + '</div>';
            html += '</div>';
            html += '<div class="comment-more-btn" onclick="showCommentAction(' + c.id + ',' + (c.is_owner ? 'true' : 'false') + ')">';
            html += '<svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>';
            html += '</div>';
            const likeCls = c.is_liked ? 'comment-like liked' : 'comment-like';
            html += '<div class="' + likeCls + '" onclick="toggleLike(\'comment\',' + c.id + ',this)">';
            html += (c.is_liked
                ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" fill="#EF4444" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" fill="#FCA5A5" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>');
            html += '<span class="count">' + (c.likes_count || 0) + '</span>';
            html += '</div>';
            html += '</div>';

            html += '<div class="comment-body">' + renderContent(c.content) + '</div>';

            html += '<div style="margin-left:40px;margin-top:6px;">';
            html += '<span style="font-size:13px;color:' + '<?php echo $primaryColor; ?>' + ';cursor:pointer;" onclick="setReply(' + c.id + ',\'' + escapeHtml(c.real_name || c.username).replace(/'/g, "\\'") + '\')">回复</span>';
            html += '</div>';

            if (c.reply_count > 0) {
                html += '<div class="reply-toggle" onclick="loadReplies(' + c.id + ',this)">展开' + c.reply_count + '条回复 ></div>';
                html += '<div class="replies-container" id="replies-' + c.id + '" style="display:none;"></div>';
            }

            div.innerHTML = html;
            return div;
        }

        async function loadReplies(commentId, toggleEl) {
            const container = document.getElementById('replies-' + commentId);
            if (container.style.display !== 'none') {
                container.style.display = 'none';
                toggleEl.textContent = '展开' + (container.dataset.replyCount || '') + '条回复 >';
                return;
            }

            if (container.children.length > 0) {
                container.style.display = 'block';
                toggleEl.textContent = '收起回复';
                return;
            }

            try {
                const res = await fetch('api/api.php?module=comments&action=replies&comment_id=' + commentId);
                const json = await res.json();
                if (!json.success) return;
                const replies = json.data.comments;
                container.dataset.replyCount = replies.length;
                container.innerHTML = '';
                replies.forEach(function(r) {
                    container.appendChild(createReplyEl(r, commentId));
                });
                container.style.display = 'block';
                toggleEl.textContent = '收起回复';
            } catch (e) {}
        }

        function createReplyEl(r, parentId) {
            const div = document.createElement('div');
            div.className = 'reply-item';

            let html = '<img class="reply-avatar" src="' + getAvatarUrl(r.avatar) + '" alt="">';
            html += '<div class="reply-content">';
            html += '<div class="reply-header">';
            html += '<span class="reply-username">' + escapeHtml(r.real_name || r.username) + '</span>';
            html += '<span class="reply-time">' + formatTime(r.created_at) + '</span>';
            const likeCls = r.is_liked ? 'reply-like liked' : 'reply-like';
            html += '<div class="' + likeCls + '" onclick="toggleLike(\'comment\',' + r.id + ',this)">';
            html += (r.is_liked
                ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" fill="#EF4444" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" fill="#FCA5A5" stroke="#EF4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>');
            html += '<span class="count">' + (r.likes_count || 0) + '</span>';
            html += '</div>';
            html += '</div>';
            html += '<div class="reply-text">' + renderContent(r.content) + '</div>';
            html += '<div style="margin-top:4px;">';
            html += '<span style="font-size:12px;color:' + '<?php echo $primaryColor; ?>' + ';cursor:pointer;" onclick="setReply(' + parentId + ',\'' + escapeHtml(r.real_name || r.username).replace(/'/g, "\\'") + '\')">回复</span>';
            html += '</div>';
            html += '</div>';

            div.innerHTML = html;
            return div;
        }

        function setReply(parentId, username) {
            replyTo = parentId;
            const label = document.getElementById('replyLabel');
            document.getElementById('replyLabelText').textContent = '回复 @' + username;
            label.classList.add('show');
            document.getElementById('commentInput').placeholder = '回复 @' + username;
            document.getElementById('commentInput').focus();
        }

        function cancelReply() {
            replyTo = null;
            document.getElementById('replyLabel').classList.remove('show');
            document.getElementById('commentInput').placeholder = '写评论...';
        }

        async function submitComment() {
            const input = document.getElementById('commentInput');
            const content = input.value.trim();
            if (!content) { showToast('请输入评论内容'); return; }

            try {
                const body = { post_id: postId, content: content };
                if (replyTo) body.parent_id = replyTo;

                const res = await fetch('api/api.php?module=comments&action=create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                const json = await res.json();
                if (!json.success) { showToast(json.error || '评论失败'); return; }

                input.value = '';
                cancelReply();
                showToast('评论成功');

                commentPage = 1;
                hasMoreComments = true;
                document.getElementById('commentsList').innerHTML = '';
                await loadComments();

                if (postData) {
                    postData.comments_count = (postData.comments_count || 0) + 1;
                    renderPost();
                }
            } catch (e) {
                showToast('网络错误');
            }
        }

        document.getElementById('commentInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitComment();
            }
        });

        window.addEventListener('scroll', function() {
            if (!commentsLoaded) return;
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            const scrollHeight = document.documentElement.scrollHeight;
            const clientHeight = document.documentElement.clientHeight;
            if (scrollTop + clientHeight >= scrollHeight - 200) {
                loadComments();
            }
        });

        loadPost();
        loadComments();

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
                    if (data.success && data.poster) {
                        task.callback(data.poster);
                    } else {
                        task.callback('');
                    }
                    _frameProcessing = false;
                    if (_frameQueue.length > 0) setTimeout(_processFrameQueue, 50);
                })
                .catch(function() {
                    _tryClientFrameExtract(task.url, task.callback);
                });
        }

        function _tryClientFrameExtract(videoUrl, callback) {
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
                    callback(dataUrl);
                } catch(e) {
                    callback('');
                }
                cleanup();
            });
            video.addEventListener('error', function() { callback(''); cleanup(); });
            setTimeout(function() { callback(''); cleanup(); }, 8000);
        }

        function extractVideoFrame(videoUrl, callback) {
            _frameQueue.push({ url: videoUrl, callback: callback });
            _processFrameQueue();
        }

        function initExtVideoPlayers() {
            var lazyPlayers = document.querySelectorAll('.ext-video-lazy-player:not([data-poster-loaded])');
            lazyPlayers.forEach(function(el) {
                el.dataset.posterLoaded = '1';
                var url = el.getAttribute('data-ext-url');
                var posterEl = el.querySelector('.ext-video-poster');
                if (url && posterEl) {
                    extractVideoFrame(url, function(posterUrl) {
                        if (posterUrl) {
                            posterEl.innerHTML = '';
                            posterEl.style.backgroundImage = 'url(' + posterUrl + ')';
                            posterEl.style.backgroundSize = 'cover';
                            posterEl.style.backgroundPosition = 'center';
                        }
                    });
                }
            });
        }

        function initAndPlayExtVideo(el) {
            if (el.dataset.playerInit === '1') return;
            el.dataset.playerInit = '1';
            el.onclick = null;
            var url = el.getAttribute('data-ext-url');
            el.innerHTML = '';
            if (typeof MoonyaVideoPlayer !== 'undefined') {
                new MoonyaVideoPlayer(el, {
                    src: url,
                    primaryColor: '<?php echo $primaryColor; ?>',
                    autoplay: true,
                    compact: false
                });
            }
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
</body>
</html>
