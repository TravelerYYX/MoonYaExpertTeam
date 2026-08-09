<?php
ini_set('session.gc_maxlifetime', 1296000);
ini_set('session.cookie_lifetime', 1296000);
session_start();

$config = require_once __DIR__ . '/config.php';
$yxVideoConfig = $config['yx_video'] ?? [];

foreach (['default_avatar_url', 'account_avatar_url_template', 'font_css_url', 'hls_js_url', 'sources', 'cors_proxies', 'placeholder_image_url_template'] as $field) {
    $value = $yxVideoConfig[$field] ?? null;
    if ($value === null || $value === '' || (is_array($value) && $value === [])) {
        throw new RuntimeException("Missing required configuration: yx_video.{$field}");
    }
}

$requireLogin = $yxVideoConfig['require_login'] ?? true;

if (isset($_GET['yx_action']) && $_GET['yx_action'] === 'logout') {
    $_SESSION['yx_video_logged_out'] = true;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (isset($_GET['yx_action']) && $_GET['yx_action'] === 'clear_logout') {
    unset($_SESSION['yx_video_logged_out']);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";port=" . $config['db_port'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (Exception $e) {
    die('数据库连接失败: ' . $e->getMessage());
}

$currentUser = null;
$isLoggedIn = false;
$defaultAvatar = (string)$yxVideoConfig['default_avatar_url'];
$userAvatar = $defaultAvatar;
$userName = '';

function getQQAvatar($email) {
    global $yxVideoConfig;
    if (preg_match('/^(\d{5,11})@qq\.com$/i', $email, $m)) {
        return str_replace('{account}', rawurlencode($m[1]), (string)$yxVideoConfig['account_avatar_url_template']);
    }
    return null;
}

function resolveAvatar($avatar, $email) {
    global $yxVideoConfig;
    $defaultAvatar = (string)$yxVideoConfig['default_avatar_url'];
    if (!empty($avatar)) {
        if (strpos($avatar, 'http') === 0) {
            return $avatar;
        }
        return '/' . ltrim($avatar, '/');
    }
    $qqAvatar = getQQAvatar($email);
    if ($qqAvatar) {
        return $qqAvatar;
    }
    return $defaultAvatar;
}

if (isset($_SESSION['user_id']) && !isset($_SESSION['yx_video_logged_out'])) {
    $stmt = $pdo->prepare("SELECT id, username, email, real_name, avatar FROM users WHERE id = ? AND status != 'deleted'");
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch();
    if ($currentUser) {
        $isLoggedIn = true;
        $userName = $currentUser['real_name'] ?: $currentUser['username'];
        $userAvatar = resolveAvatar($currentUser['avatar'], $currentUser['email']);
    }
}

if ($requireLogin && !$isLoggedIn) {
    $showLoginModal = true;
} else {
    $showLoginModal = false;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.8">
    <title>雅泫视频 - 高清影视大全</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/image/tx.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/image/tx.png">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($yxVideoConfig['font_css_url'], ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        :root { --accent: #15b6b5; --tx-red: #ff5c38; --bg-dark: #0f0f13; --bg-sidebar: #18181b; }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            background: var(--bg-dark);
            color: #fff;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        input, textarea {
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
            user-select: text;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #444; }

        /* ==================== 顶部导航 ==================== */
        .top-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 70px;
            background: rgba(15,15,19,0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            z-index: 100;
        }
        
        .nav-left {
            display: flex;
            align-items: center;
            gap: 32px;
        }
        
        .logo-main {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 22px;
            font-weight: 800;
            color: #fff;
            cursor: pointer;
        }
        
        .logo-main img {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            object-fit: cover;
            display: block;
        }

        .search-main {
            position: relative;
            width: 480px;
        }
        
        .search-main input {
            width: 100%;
            height: 46px;
            padding: 10px 44px 10px 18px;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.08);
            color: #fff;
            font-size: 15px;
            outline: none;
            transition: all 0.3s;
        }
        
        .search-main input:focus {
            background: rgba(255,255,255,0.12);
            border-color: rgba(255,255,255,0.2);
        }
        
        .search-main input::placeholder { color: #888; }
        
        .search-main button {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #888;
            cursor: pointer;
            padding: 8px 12px;
            font-size: 16px;
        }

        /* 搜索建议下拉 */
        .search-suggestions {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            background: rgba(28,28,32,0.98);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 8px;
            display: none;
            flex-direction: column;
            gap: 2px;
            z-index: 200;
            max-height: 320px;
            overflow-y: auto;
            box-shadow: 0 16px 48px rgba(0,0,0,0.6);
        }
        .search-suggestions.show { display: flex; }
        .suggestion-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .suggestion-item:hover { background: rgba(255,255,255,0.06); }
        .suggestion-item img {
            width: 72px;
            height: 40px;
            border-radius: 6px;
            object-fit: cover;
            background: #222;
        }
        .suggestion-item .sg-text {
            flex: 1;
            min-width: 0;
        }
        .suggestion-item .sg-name {
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .suggestion-item .sg-meta {
            font-size: 12px;
            color: #888;
            margin-top: 2px;
        }
        .suggestion-empty {
            padding: 20px;
            text-align: center;
            color: #666;
            font-size: 13px;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        /* ==================== 左侧边栏 —— 透明毛玻璃 ==================== */
        .sidebar {
            position: fixed;
            top: 70px;
            left: 0;
            width: 220px;
            bottom: 0;
            background: rgba(15,15,19,0.25);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-right: 1px solid rgba(255,255,255,0.06);
            overflow-y: auto;
            z-index: 90;
            padding: 12px 0 24px;
        }
        
        .sidebar-group { margin-bottom: 8px; padding: 0 12px; }
        .sidebar-title {
            font-size: 12px;
            color: #999;
            padding: 8px 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-shadow: 0 1px 4px rgba(0,0,0,0.8);
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            margin: 2px 0;
            border-radius: 8px;
            cursor: pointer;
            color: #eee;
            font-size: 14px;
            transition: all 0.2s;
            position: relative;
            text-shadow: 0 1px 4px rgba(0,0,0,0.8);
        }
        
        .nav-item:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .nav-item.active {
            background: linear-gradient(90deg, rgba(21,182,181,0.25), transparent);
            color: #15b6b5;
            font-weight: 600;
        }
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 16px;
            background: #15b6b5;
            border-radius: 0 2px 2px 0;
        }
        .nav-item i { width: 20px; text-align: center; font-size: 15px; }

        /* ==================== 主内容区 ==================== */
        .main-content {
            margin-left: 220px;
            margin-top: 70px;
            padding: 24px 32px 40px;
            min-height: calc(100vh - 70px);
        }

        /* ==================== 白光波浪斜向加载动画 ==================== */
        @keyframes shimmerWave {
            0% { background-position: 300% 0; }
            100% { background-position: -300% 0; }
        }
        
        .shimmer-cover {
            width: 100%;
            aspect-ratio: 16/9;
            background: #1f1f24;
            border-radius: 10px 10px 0 0;
            position: relative;
            overflow: hidden;
        }
        .shimmer-cover::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(105deg, transparent 30%, rgba(255,255,255,0.08) 45%, rgba(255,255,255,0.14) 50%, rgba(255,255,255,0.08) 55%, transparent 70%);
            background-size: 300% 100%;
            animation: shimmerWave 2.5s infinite linear;
        }
        
        .shimmer-mcover {
            width: 100%;
            aspect-ratio: 3/4;
            background: #1f1f24;
            border-radius: 10px 10px 0 0;
            position: relative;
            overflow: hidden;
        }
        .shimmer-mcover::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(105deg, transparent 30%, rgba(255,255,255,0.08) 45%, rgba(255,255,255,0.14) 50%, rgba(255,255,255,0.08) 55%, transparent 70%);
            background-size: 300% 100%;
            animation: shimmerWave 2.5s infinite linear;
        }
        
        .shimmer-rec-top {
            width: 100%;
            aspect-ratio: 16/9;
            background: #1f1f24;
            position: relative;
            overflow: hidden;
        }
        .shimmer-rec-top::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(105deg, transparent 30%, rgba(255,255,255,0.08) 45%, rgba(255,255,255,0.14) 50%, rgba(255,255,255,0.08) 55%, transparent 70%);
            background-size: 300% 100%;
            animation: shimmerWave 2.5s infinite linear;
        }
        
        .shimmer-card {
            background: #1a1a1f;
            border-radius: 10px;
            overflow: hidden;
        }
        .shimmer-info { padding: 10px 12px; }
        .shimmer-line {
            height: 14px;
            background: #25252b;
            border-radius: 4px;
            margin-bottom: 8px;
            position: relative;
            overflow: hidden;
        }
        .shimmer-line::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(105deg, transparent 30%, rgba(255,255,255,0.05) 50%, transparent 70%);
            background-size: 300% 100%;
            animation: shimmerWave 2.5s infinite linear;
        }
        .shimmer-line.short { width: 60%; }
        
        .shimmer-rec-card {
            display: flex;
            flex-direction: column;
            border-radius: 12px;
            background: #1a1a1f;
            border: 1px solid rgba(255,255,255,0.04);
            overflow: hidden;
        }

        /* 底部加载骨架屏容器修复：让子元素直接参与父级 Grid */
        #bottomLoading {
            display: contents;
        }

        /* ==================== Banner轮播 —— 左右顶满，下方留内容区 ==================== */
        .hero-section {
            margin: -24px -32px 0 -252px;
            position: relative;
            z-index: 1;
            width: auto;
            height: calc((100vh - 70px) * 2.2 / 3);
            min-height: 480px;
        }
        
        .carousel-wrap {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #1a1a1f;
            border-radius: 0;
        }
        
        .carousel-slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 0.6s ease;
            cursor: pointer;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            z-index: 1;
        }
        .carousel-slide::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(to right, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0.5) 35%, rgba(0,0,0,0.4) 100%);
            z-index: 2;
        }
        .carousel-slide.active { opacity: 1; z-index: 3; }
        
        /* 轮播内容层：左侧信息 + 右侧缩略图 */
        .carousel-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            padding: 0 50px 80px 260px;
            z-index: 10;
            pointer-events: none;
        }
        .carousel-left {
            pointer-events: auto;
            max-width: 520px;
            padding-bottom: 10px;
        }
        .carousel-right {
            pointer-events: auto;
            display: flex;
            gap: 12px;
            align-items: flex-end;
            padding-bottom: 10px;
        }
        
        .carousel-thumb {
            width: 108px;
            aspect-ratio: 2/3;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            position: relative;
            flex-shrink: 0;
            background: #222;
        }
        .carousel-thumb.active {
            border-color: #fff;
            transform: translateY(-6px);
            box-shadow: 0 12px 28px rgba(0,0,0,0.5);
        }
        .carousel-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .carousel-thumb .thumb-title {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 20px 6px 6px;
            background: linear-gradient(to top, rgba(0,0,0,0.9), transparent);
            font-size: 12px;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-align: center;
        }
        
        .banner-tag {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 14px;
        }
        .banner-tag span {
            font-size: 13px;
            color: #e6a23c;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .banner-title {
            font-size: 38px;
            font-weight: 800;
            margin-bottom: 12px;
            text-shadow: 0 2px 12px rgba(0,0,0,0.8);
            letter-spacing: 1px;
            line-height: 1.2;
        }
        
        .banner-desc {
            font-size: 15px;
            color: #ddd;
            margin-bottom: 24px;
            max-width: 450px;
            line-height: 1.7;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-shadow: 0 1px 6px rgba(0,0,0,0.7);
        }
        
        .banner-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 32px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.25);
            color: #fff;
            border-radius: 28px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            width: fit-content;
        }
        .banner-btn:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }

        .carousel-dots {
            position: absolute;
            bottom: 28px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 8px;
            z-index: 10;
        }
        .carousel-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255,255,255,0.4);
            cursor: pointer;
            transition: all 0.3s;
        }
        .carousel-dot.active { background: #fff; width: 24px; border-radius: 4px; }
        .carousel-dot:hover { background: rgba(255,255,255,0.7); }

        .carousel-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(0,0,0,0.4);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10;
            font-size: 14px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .carousel-wrap:hover .carousel-arrow { opacity: 1; }
        .carousel-arrow:hover { background: rgba(0,0,0,0.6); }
        .carousel-arrow.prev { left: 16px; }
        .carousel-arrow.next { right: 16px; }

        /* ==================== 重磅热播 ==================== */
        .section-block { margin-bottom: 40px; }
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        
        .section-title {
            font-size: 26px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .section-tabs {
            display: flex;
            gap: 20px;
        }
        .section-tabs span {
            font-size: 14px;
            color: #888;
            cursor: pointer;
            padding-bottom: 4px;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
            font-weight: 500;
        }
        .section-tabs span:hover { color: #ccc; }
        .section-tabs span.active { color: var(--tx-red); border-bottom-color: var(--tx-red); }
        
        .section-extra {
            font-size: 13px;
            color: #888;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s;
        }
        .section-extra:hover { color: #fff; }

        .video-grid-6 {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 14px;
        }
        
        @media (max-width: 1600px) { .video-grid-6 { grid-template-columns: repeat(5, 1fr); } }
        @media (max-width: 1200px) { .video-grid-6 { grid-template-columns: repeat(4, 1fr); } }
        @media (max-width: 900px) { .video-grid-6 { grid-template-columns: repeat(3, 1fr); } }

        .v-card {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
            background: #1a1a1f;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .v-card:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: 0 12px 32px rgba(0,0,0,0.5);
            z-index: 2;
        }
        
        .v-card .v-cover {
            position: relative;
            width: 100%;
            aspect-ratio: 16/9;
            overflow: hidden;
            background: #222;
        }
        .v-card .v-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.4s;
        }
        .v-card:hover .v-cover img { transform: scale(1.08); }
        
        .v-card .v-info {
            padding: 10px 12px;
        }
        .v-card .v-name {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .v-card .v-sub {
            font-size: 12px;
            color: #888;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ==================== 为你推荐（3列）- 图2样式 封面在上 ==================== */
        .rec-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        @media (max-width: 1200px) { .rec-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 900px) { .rec-grid { grid-template-columns: repeat(1, 1fr); } }

        .rec-card {
            display: flex;
            flex-direction: column;
            border-radius: 12px;
            background: #1a1a1f;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.04);
            overflow: hidden;
        }
        .rec-card:hover {
            background: #222;
            transform: translateY(-3px);
            border-color: rgba(255,255,255,0.08);
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
        }
        .rec-card .rec-cover-top {
            width: 100%;
            aspect-ratio: 16/9;
            overflow: hidden;
            background: #222;
            position: relative;
        }
        .rec-card .rec-cover-top img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .rec-card .rec-info-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
        }
        .rec-card .rec-small-cover {
            width: 48px;
            height: 64px;
            border-radius: 4px;
            overflow: hidden;
            flex-shrink: 0;
            background: #222;
        }
        .rec-card .rec-small-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .rec-card .rec-text {
            flex: 1;
            min-width: 0;
            overflow: hidden;
        }
        .rec-card .rec-name {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #fff;
        }
        .rec-card .rec-meta {
            font-size: 12px;
            color: #888;
            margin-bottom: 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .rec-card .rec-desc {
            font-size: 12px;
            color: #aaa;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .rec-card .rec-action {
            flex-shrink: 0;
        }
        .rec-card .rec-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 12px;
            border-radius: 14px;
            background: rgba(255,255,255,0.06);
            color: #999;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid rgba(255,255,255,0.08);
            transition: all 0.2s;
        }
        .rec-card:hover .rec-tag {
            background: rgba(255,255,255,0.1);
            color: #ccc;
        }
        .rec-card .rec-tag i {
            font-size: 10px;
            color: var(--accent);
        }

        /* 页脚 */
        .footer {
            margin-top: 60px;
            padding: 30px;
            text-align: center;
            color: #555;
            font-size: 13px;
            border-top: 1px solid rgba(255,255,255,0.04);
        }

        /* ==================== 分类页面 ==================== */
        .category-overlay {
            display: none;
            margin-left: 220px;
            margin-top: 70px;
            padding: 24px 32px 40px;
            min-height: calc(100vh - 70px);
        }
        .category-overlay.show { display: block; }

        /* ==================== 播放器（完整保留） ==================== */
        .player-page {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 200;
            background: #000;
        }
        .player-page.show { display: flex; }
        .player-page.fullscreen .player-sidebar { width: 0; opacity: 0; border-left: none; overflow: hidden; }
        
        .player-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            position: relative;
            background: #000;
            min-width: 0;
        }
        
        .player-video-wrap {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
            overflow: hidden;
            cursor: pointer;
        }
        .player-video-wrap video,
        .player-video-wrap iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }
        .video-watermark {
            position: absolute;
            top: 14px;
            right: 18px;
            color: rgba(255, 255, 255, 0.35);
            font-size: 16px;
            font-weight: 600;
            letter-spacing: 2px;
            pointer-events: none;
            z-index: 10;
            user-select: none;
            text-shadow: 0 1px 3px rgba(0,0,0,0.4);
        }

        /* 播放器加载/缓冲动画 —— 3个白色圆点律动 */
        .player-loading {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.55);
            z-index: 15;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }
        .player-loading.show {
            opacity: 1;
            pointer-events: auto;
        }
        .loading-dots {
            display: flex;
            gap: 6px;
            margin-bottom: 14px;
            align-items: center;
            justify-content: center;
        }
        .loading-dots div {
            width: 10px;
            height: 10px;
            background: #fff;
            border-radius: 50%;
            animation: dotBounce 1.4s infinite ease-in-out both;
        }
        .loading-dots div:nth-child(1) { animation-delay: -0.32s; }
        .loading-dots div:nth-child(2) { animation-delay: -0.16s; }
        .loading-dots div:nth-child(3) { animation-delay: 0s; }
        @keyframes dotBounce {
            0%, 80%, 100% { transform: scale(0.5); opacity: 0.4; }
            40% { transform: scale(1); opacity: 1; }
        }
        .loading-text {
            font-size: 13px;
            color: #aaa;
            letter-spacing: 0.5px;
        }

        .danmu-layer {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 60px;
            pointer-events: none;
            overflow: hidden;
            z-index: 5;
        }
        .danmu-layer.paused .danmu-item { animation-play-state: paused !important; }
        
        .danmu-item {
            position: absolute;
            white-space: nowrap;
            font-size: 18px;
            color: #fff;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.8);
            animation: danmuMove 10s linear forwards;
            pointer-events: none;
            font-weight: 500;
        }
        @keyframes danmuMove {
            from { transform: translateX(100%); left: 100%; }
            to { transform: translateX(-100%); left: 0; }
        }

        /* 播放器顶部栏 - 带Logo和搜索 */
        .player-top-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            padding: 12px 20px;
            background: linear-gradient(to bottom, rgba(0,0,0,0.7), transparent);
            display: flex;
            align-items: center;
            gap: 16px;
            z-index: 10;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .player-video-wrap:hover .player-top-bar,
        .player-video-wrap.paused .player-top-bar { opacity: 1; }
        
        .player-top-bar .player-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .player-top-bar .player-logo img {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            object-fit: cover;
        }
        .player-top-bar .player-logo span {
            font-size: 18px;
            font-weight: 800;
            color: #fff;
            letter-spacing: 1px;
        }
        .player-top-bar .player-search {
            position: relative;
            width: 320px;
            flex-shrink: 0;
        }
        .player-top-bar .player-search input {
            width: 100%;
            height: 38px;
            padding: 8px 36px 8px 14px;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.15);
            background: rgba(255,255,255,0.08);
            color: #fff;
            font-size: 13px;
            outline: none;
        }
        .player-top-bar .player-search input::placeholder { color: #888; }
        .player-top-bar .player-search button {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #888;
            cursor: pointer;
            font-size: 13px;
        }
        .player-top-bar .back-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            background: rgba(255,255,255,0.1);
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            margin-left: auto;
        }
        .player-top-bar .back-btn:hover { background: rgba(255,255,255,0.2); }
        .player-top-bar .video-title {
            flex: 1;
            font-size: 15px;
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #fff;
        }
        .player-top-bar .client-play {
            background: rgba(0,0,0,0.6);
            border: 1px solid rgba(255,255,255,0.2);
            color: #fff;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .center-play-btn {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 2px solid rgba(255,255,255,0.25);
            color: #fff;
            font-size: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 8;
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }
        .center-play-btn i { margin-left: 4px; }
        .center-play-btn.show { opacity: 1; pointer-events: auto; }
        .center-play-btn:hover {
            background: rgba(0,0,0,0.75);
            border-color: rgba(255,255,255,0.4);
            transform: translate(-50%, -50%) scale(1.08);
            box-shadow: 0 12px 40px rgba(0,0,0,0.6);
        }
        .center-play-btn:active { transform: translate(-50%, -50%) scale(0.95); transition: transform 0.1s; }

        .tx-controls {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.9) 0%, rgba(0,0,0,0.5) 60%, transparent 100%);
            padding: 30px 16px 12px;
            opacity: 0;
            transition: opacity 0.3s;
            z-index: 10;
        }
        .player-video-wrap:hover .tx-controls,
        .player-video-wrap.paused .tx-controls { opacity: 1; }
        
        .tx-progress-area {
            position: relative;
            width: 100%;
            height: 20px;
            margin-bottom: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        .tx-progress-area.dragging { cursor: grabbing; }
        .tx-progress-bg {
            width: 100%;
            height: 4px;
            background: rgba(255,255,255,0.2);
            border-radius: 2px;
            position: relative;
            transition: height 0.2s;
        }
        .tx-progress-area:hover .tx-progress-bg,
        .tx-progress-area.dragging .tx-progress-bg { height: 6px; }
        .tx-progress-buffer {
            height: 100%;
            background: rgba(255,255,255,0.25);
            border-radius: 2px;
            position: absolute;
            top: 0;
            left: 0;
        }
        .tx-progress-played {
            height: 100%;
            background: var(--accent);
            border-radius: 2px;
            position: absolute;
            top: 0;
            left: 0;
            width: 0%;
        }
        .tx-progress-dot {
            position: absolute;
            right: -6px;
            top: 50%;
            transform: translateY(-50%);
            width: 12px;
            height: 12px;
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 0 4px rgba(0,0,0,0.5);
            opacity: 0;
            transition: opacity 0.2s;
        }
        .tx-progress-area:hover .tx-progress-dot,
        .tx-progress-area.dragging .tx-progress-dot { opacity: 1; }
        .tx-time-tooltip {
            position: absolute;
            top: -28px;
            background: rgba(0,0,0,0.8);
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            display: none;
            white-space: nowrap;
            transform: translateX(-50%);
        }
        .tx-progress-area:hover .tx-time-tooltip,
        .tx-progress-area.dragging .tx-time-tooltip { display: block; }

        .tx-controls-row {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .tx-controls-row button {
            background: none;
            border: none;
            color: #fff;
            font-size: 16px;
            cursor: pointer;
            padding: 4px;
            opacity: 0.9;
            transition: opacity 0.2s;
            position: relative;
        }
        .tx-controls-row button:hover { opacity: 1; }
        .tx-controls-row .play-btn { font-size: 22px; }
        .tx-controls-row .tx-time {
            font-size: 12px;
            color: #ccc;
            font-variant-numeric: tabular-nums;
            min-width: 90px;
        }
        .tx-danmu-input {
            flex: 1;
            max-width: 300px;
            position: relative;
        }
        .tx-danmu-input input {
            width: 100%;
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.1);
            color: #fff;
            font-size: 13px;
            outline: none;
        }
        .tx-danmu-input input::placeholder { color: #888; }
        .tx-right-btns {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .tx-menu-wrap { position: relative; }
        .tx-menu {
            position: absolute;
            bottom: 36px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(40,40,40,0.98);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px;
            padding: 6px 0;
            min-width: 90px;
            display: none;
            flex-direction: column;
            z-index: 100;
            backdrop-filter: blur(16px);
            box-shadow: 0 -4px 24px rgba(0,0,0,0.7);
        }
        .tx-menu.show { display: flex; }
        .tx-menu-item {
            padding: 9px 18px;
            font-size: 13px;
            color: #ccc;
            cursor: pointer;
            text-align: center;
            white-space: nowrap;
            transition: all 0.15s;
            border: none;
            background: none;
            width: 100%;
            font-family: inherit;
        }
        .tx-menu-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .tx-menu-item.active { color: #fff; font-weight: 600; background: rgba(255,255,255,0.15); }

        .volume-wrap { position: relative; }
        .volume-panel {
            position: absolute;
            bottom: 36px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(40,40,40,0.98);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px;
            padding: 16px 10px 10px;
            display: none;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            z-index: 100;
            backdrop-filter: blur(16px);
            box-shadow: 0 -4px 24px rgba(0,0,0,0.7);
            width: 44px;
        }
        .volume-panel.show { display: flex; }
        .volume-num { font-size: 12px; color: #fff; font-weight: 600; }
        .volume-track {
            width: 4px;
            height: 80px;
            background: rgba(255,255,255,0.15);
            border-radius: 2px;
            position: relative;
            cursor: pointer;
        }
        .volume-fill {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            background: rgba(255,255,255,0.85);
            border-radius: 2px;
            height: 70%;
        }
        .volume-dot {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            width: 10px;
            height: 10px;
            background: #fff;
            border-radius: 50%;
            margin-top: -5px;
            box-shadow: 0 0 6px rgba(0,0,0,0.5);
        }
        .volume-icon {
            font-size: 14px;
            color: #ccc;
            cursor: pointer;
            background: none;
            border: none;
            padding: 2px;
            transition: color 0.2s;
        }
        .volume-icon:hover { color: #fff; }

        .settings-panel {
            position: absolute;
            bottom: 36px;
            right: 0;
            background: rgba(40,40,40,0.98);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px;
            padding: 12px 16px;
            display: none;
            flex-direction: column;
            gap: 12px;
            z-index: 100;
            backdrop-filter: blur(16px);
            box-shadow: 0 -4px 24px rgba(0,0,0,0.7);
            min-width: 160px;
        }
        .settings-panel.show { display: flex; }
        .setting-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            font-size: 13px;
            color: #ccc;
        }
        .switch {
            width: 36px;
            height: 20px;
            border-radius: 10px;
            background: #555;
            position: relative;
            cursor: pointer;
            transition: background 0.25s;
            flex-shrink: 0;
        }
        .switch.on { background: var(--accent); }
        .switch::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #fff;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
        .switch.on::after { transform: translateX(16px); }

        .intro-panel-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0);
            backdrop-filter: blur(0px);
            z-index: 300;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: background 0.35s ease, backdrop-filter 0.35s ease, opacity 0.35s ease;
        }
        .intro-panel-overlay.show {
            opacity: 1;
            pointer-events: auto;
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(6px);
        }
        .intro-panel {
            width: 100%;
            max-width: 520px;
            background: rgba(35,35,40,0.98);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 28px 28px 0 0;
            padding: 28px;
            transform: translateY(110%);
            transition: transform 0.5s cubic-bezier(0.32, 0.72, 0, 1);
            box-shadow: 0 -12px 48px rgba(0,0,0,0.7);
            max-height: 65vh;
            overflow-y: auto;
        }
        .intro-panel-overlay.show .intro-panel { transform: translateY(0); }
        .intro-panel .intro-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .intro-panel .intro-header h4 { font-size: 17px; font-weight: 600; }
        .intro-panel .intro-header .close-intro {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            background: rgba(255,255,255,0.1);
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            transition: background 0.2s;
        }
        .intro-panel .intro-header .close-intro:hover { background: rgba(255,255,255,0.2); }
        .intro-panel .intro-body { font-size: 14px; color: #bbb; line-height: 1.8; }
        .intro-panel .intro-body .intro-meta { font-size: 13px; color: #888; margin-bottom: 12px; line-height: 1.6; }

        .player-sidebar {
            width: 360px;
            flex-shrink: 0;
            background: #141414;
            border-left: 1px solid #222;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
        }
        .sidebar-header {
            padding: 16px 20px;
            border-bottom: 1px solid #222;
        }
        .sidebar-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sidebar-title .intro-btn {
            font-size: 13px;
            color: #888;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 2px;
            transition: color 0.2s;
        }
        .sidebar-title .intro-btn:hover { color: #fff; }
        .sidebar-meta { font-size: 13px; color: #888; line-height: 1.8; }
        .sidebar-meta .score { color: #ff6b00; font-weight: 600; font-size: 16px; }
        .sidebar-meta .play-count { color: #888; margin-left: 8px; }
        .sidebar-meta .play-count i { margin-right: 4px; }
        .sidebar-actions { display: flex; gap: 12px; margin-top: 12px; }
        .sidebar-actions button {
            flex: 1;
            padding: 8px;
            border-radius: 6px;
            border: 1px solid #333;
            background: #1a1a1a;
            color: #ccc;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .sidebar-actions button:hover { border-color: var(--accent); color: #fff; background: rgba(21,182,181,0.1); }

        .ep-section { flex: 1; overflow-y: auto; padding: 0 20px 20px; }
        .ep-tabs {
            display: flex;
            gap: 20px;
            padding: 12px 0;
            border-bottom: 1px solid #222;
            margin-bottom: 12px;
            position: sticky;
            top: 0;
            background: #141414;
            z-index: 2;
        }
        .ep-tabs span {
            font-size: 14px;
            color: #888;
            cursor: pointer;
            padding-bottom: 12px;
            margin-bottom: -12px;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        .ep-tabs span:hover { color: #ccc; }
        .ep-tabs span.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: 600; }

        .ep-groups { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
        .ep-groups span {
            padding: 4px 10px;
            border-radius: 4px;
            background: #1a1a1a;
            color: #888;
            font-size: 12px;
            cursor: pointer;
            border: 1px solid transparent;
        }
        .ep-groups span:hover { color: #fff; }
        .ep-groups span.active { background: rgba(21,182,181,0.15); color: var(--accent); border-color: rgba(21,182,181,0.3); }

        .ep-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; }
        .ep-grid-item {
            aspect-ratio: 1;
            border-radius: 6px;
            background: #1a1a1f;
            border: 1px solid #333;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
            transition: all 0.2s;
        }
        .ep-grid-item:hover { border-color: var(--accent); background: #222; }
        .ep-grid-item.active { border-color: var(--accent); background: rgba(21,182,181,0.15); }
        .ep-grid-item .ep-num { font-size: 16px; font-weight: 600; color: #ccc; }
        .ep-grid-item.active .ep-num { color: var(--accent); }

        .toast {
            position: fixed;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.85);
            color: #fff;
            padding: 10px 20px;
            border-radius: 20px;
            font-size: 14px;
            z-index: 999;
            animation: toastIn 0.25s ease, toastOut 0.25s ease 2s forwards;
        }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(-50%) translateY(10px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }
        @keyframes toastOut {
            from { opacity: 1; }
            to { opacity: 0; transform: translateX(-50%) translateY(-10px); }
        }

        /* 搜索结果页 */
        .search-overlay {
            display: none;
            margin-left: 220px;
            margin-top: 70px;
            padding: 24px 32px;
            min-height: calc(100vh - 70px);
        }
        .search-overlay.show { display: block; }
        
        .search-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        .search-header h2 { font-size: 20px; font-weight: 600; }
        .search-header .back-home {
            padding: 6px 14px;
            border-radius: 20px;
            border: 1px solid #333;
            background: #1a1a1f;
            color: #ccc;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .search-header .back-home:hover { border-color: var(--tx-red); color: #fff; }

        /* 空状态 */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #666;
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
            color: #555;
        }
        .empty-state h3 {
            font-size: 16px;
            color: #999;
            margin-bottom: 8px;
            font-weight: 500;
        }
        .empty-state p {
            font-size: 14px;
            color: #666;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ==================== 代理切换提示弹窗 ==================== */
        .proxy-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }
        .proxy-modal-overlay.show {
            opacity: 1;
            pointer-events: auto;
        }
        .proxy-modal {
            background: #1e1e22;
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 10px;
            padding: 24px;
            width: 90%;
            max-width: 360px;
            box-shadow: 0 16px 40px rgba(0,0,0,0.5);
            text-align: left;
            transform: translateY(8px);
            transition: transform 0.25s ease;
        }
        .proxy-modal-overlay.show .proxy-modal {
            transform: translateY(0);
        }
        .proxy-modal .pm-icon {
            color: #e8a93a;
            font-size: 20px;
            margin-bottom: 12px;
        }
        .proxy-modal h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #fff;
        }
        .proxy-modal p {
            font-size: 13px;
            color: #999;
            line-height: 1.7;
            margin-bottom: 20px;
        }
        .proxy-modal .pm-btn {
            padding: 8px 20px;
            border-radius: 6px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.06);
            color: #ddd;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .proxy-modal .pm-btn:hover {
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.15);
            color: #fff;
        }

        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main-content, .search-overlay, .category-overlay { margin-left: 0; }
            .hero-section {
                margin-left: -32px;
                margin-right: -32px;
            }
            .carousel-overlay {
                padding-left: 50px;
            }
            .carousel-right { display: none; }
            .carousel-left { max-width: 100%; }
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            position: relative;
            padding: 6px 12px;
            border-radius: 20px;
            transition: background 0.2s;
        }
        .user-profile:hover { background: rgba(255,255,255,0.1); }
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.2);
        }
        .user-profile .user-name {
            font-size: 13px;
            color: #eee;
            max-width: 80px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .user-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: rgba(28,28,32,0.98);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 6px 0;
            min-width: 160px;
            display: none;
            z-index: 200;
            box-shadow: 0 16px 48px rgba(0,0,0,0.6);
        }
        .user-dropdown.show { display: block; }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            color: #eee;
            text-decoration: none;
            font-size: 13px;
            transition: background 0.15s;
        }
        .dropdown-item:hover { background: rgba(255,255,255,0.06); }
        .dropdown-item i { width: 16px; text-align: center; color: #888; }

        .login-link {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #ccc;
            text-decoration: none;
            font-size: 13px;
            padding: 6px 14px;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.15);
            transition: all 0.2s;
        }
        .login-link:hover { background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.3); }

        .yx-login-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.35);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .yx-login-overlay.hidden { display: none; }
        .yx-auth-modal {
            position: relative;
            display: flex;
            width: 960px;
            height: 540px;
            border-radius: 24px;
            overflow: hidden;
            opacity: 0;
            animation: yxMaterialize 1s cubic-bezier(0.22, 1, 0.36, 1) 0.1s forwards;
            background: rgba(255,255,255,0.72);
            backdrop-filter: blur(40px) saturate(1.6);
            -webkit-backdrop-filter: blur(40px) saturate(1.6);
            border: 1px solid rgba(255,255,255,0.6);
            box-shadow: 0 24px 80px rgba(0,0,0,0.08), 0 4px 12px rgba(0,0,0,0.03), inset 0 1px 1px rgba(255,255,255,0.8);
        }
        @keyframes yxMaterialize {
            from { opacity: 0; filter: blur(12px); transform: scale(0.97); }
            to { opacity: 1; filter: blur(0); transform: scale(1); }
        }
        .yx-auth-modal::before {
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
        .yx-auth-modal::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: radial-gradient(ellipse 80% 50% at 50% 0%, rgba(255,255,255,0.6) 0%, transparent 60%);
            pointer-events: none;
            mix-blend-mode: overlay;
        }
        .yx-modal-glow {
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
        .yx-auth-modal:hover .yx-modal-glow { opacity: 0.6; }
        .yx-modal-left {
            position: relative;
            width: 66.666%;
            height: 100%;
            overflow: hidden;
            flex-shrink: 0;
        }
        .yx-modal-left .image-wrapper { position: absolute; inset: 0; }
        .yx-modal-left .image-wrapper img { width: 100%; height: 100%; object-fit: cover; filter: brightness(0.95) saturate(1.05); transition: transform 8s ease; }
        .yx-auth-modal:hover .image-wrapper img { transform: scale(1.03); }
        .yx-modal-left .image-overlay { position: absolute; inset: 0; background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 50%, rgba(0,0,0,0.15) 100%); backdrop-filter: blur(0.5px); }
        .yx-modal-left .left-content { position: absolute; bottom: 48px; left: 48px; z-index: 3; color: #FFF; text-shadow: 0 2px 16px rgba(0,0,0,0.2); }
        .yx-modal-left .left-content h2 { font-size: 36px; font-weight: 600; letter-spacing: -0.03em; margin-bottom: 8px; background: linear-gradient(135deg, #FFF 0%, rgba(255,255,255,0.85) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .yx-modal-left .left-content p { font-size: 14px; font-weight: 400; color: rgba(255,255,255,0.8); letter-spacing: 0.05em; }
        .yx-modal-right {
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
        .yx-auth-tabs { display: flex; gap: 6px; margin-bottom: 28px; flex-shrink: 0; }
        .yx-auth-tab-btn {
            flex: 1; padding: 8px 0; border: none; background: transparent; color: #999;
            font-family: inherit; font-size: 12px; font-weight: 500; cursor: pointer;
            border-radius: 10px; transition: all 0.4s cubic-bezier(0.22, 1, 0.36, 1);
            letter-spacing: 0.01em; white-space: nowrap;
        }
        .yx-auth-tab-btn:hover { color: #666; background: rgba(255,255,255,0.55); }
        .yx-auth-tab-btn.active { color: #333; background: rgba(255,255,255,0.85); border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .yx-auth-form-container { position: relative; flex: 1; width: 100%; perspective: 900px; }
        .yx-auth-form {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0; visibility: hidden; display: flex; flex-direction: column;
            justify-content: center; gap: 14px; transform-style: preserve-3d; backface-visibility: hidden;
        }
        .yx-auth-form.active { opacity: 1; visibility: visible; position: relative; z-index: 2; }
        .yx-auth-form.enter-from-left { animation: yxEnterFromLeft 0.95s cubic-bezier(0.22, 1, 0.36, 1) forwards; }
        .yx-auth-form.enter-from-right { animation: yxEnterFromRight 0.95s cubic-bezier(0.22, 1, 0.36, 1) forwards; }
        .yx-auth-form.leave-to-left { animation: yxLeaveToLeft 0.85s cubic-bezier(0.22, 1, 0.36, 1) forwards; z-index: 1; }
        .yx-auth-form.leave-to-right { animation: yxLeaveToRight 0.85s cubic-bezier(0.22, 1, 0.36, 1) forwards; z-index: 1; }
        @keyframes yxEnterFromLeft { 0% { opacity:0; transform: translateX(-120px) translateZ(-80px) rotateY(-32deg) rotateZ(-4deg); } 100% { opacity:1; transform: translateX(0) translateZ(0) rotateY(0) rotateZ(0); } }
        @keyframes yxEnterFromRight { 0% { opacity:0; transform: translateX(120px) translateZ(-80px) rotateY(32deg) rotateZ(4deg); } 100% { opacity:1; transform: translateX(0) translateZ(0) rotateY(0) rotateZ(0); } }
        @keyframes yxLeaveToLeft { 0% { opacity:1; transform: translateX(0) translateZ(0) rotateY(0) rotateZ(0); } 100% { opacity:0; transform: translateX(-120px) translateZ(-80px) rotateY(-32deg) rotateZ(-4deg); } }
        @keyframes yxLeaveToRight { 0% { opacity:1; transform: translateX(0) translateZ(0) rotateY(0) rotateZ(0); } 100% { opacity:0; transform: translateX(120px) translateZ(-80px) rotateY(32deg) rotateZ(4deg); } }
        .yx-auth-form .input-group { display: flex; flex-direction: column; gap: 5px; }
        .yx-auth-form .input-group label { font-size: 12px; font-weight: 500; color: #777; padding-left: 2px; letter-spacing: 0.03em; }
        .yx-auth-form .input-group input {
            width: 100%; padding: 10px 12px; background: rgba(255,255,255,0.7);
            border: 1px solid rgba(0,0,0,0.05); border-radius: 10px;
            font-family: inherit; font-size: 13px; color: #444; outline: none;
            transition: all 0.3s ease; box-sizing: border-box;
        }
        .yx-auth-form .input-group input::placeholder { color: #BBB; }
        .yx-auth-form .input-group input:focus { background: rgba(255,255,255,0.95); border-color: rgba(0,0,0,0.12); box-shadow: 0 0 0 3px rgba(0,0,0,0.04); }
        .yx-auth-form .input-row { display: flex; gap: 8px; }
        .yx-auth-form .input-row input { flex: 1; }
        .yx-auth-btn-primary {
            width: 100%; padding: 11px; margin-top: 4px; border: none; border-radius: 10px;
            background: linear-gradient(135deg, #4A4A4A 0%, #2C2C2C 100%); color: #FFF;
            font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
            letter-spacing: 0.03em; box-shadow: 0 4px 16px rgba(0,0,0,0.18);
            transition: all 0.3s ease; position: relative; overflow: hidden;
        }
        .yx-auth-btn-primary::after {
            content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
            transition: left 0.6s ease;
        }
        .yx-auth-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 22px rgba(0,0,0,0.25); background: linear-gradient(135deg, #555 0%, #333 100%); }
        .yx-auth-btn-primary:hover::after { left: 100%; }
        .yx-auth-btn-secondary {
            padding: 8px 12px; border: 1px solid rgba(0,0,0,0.08); border-radius: 8px;
            background: rgba(255,255,255,0.6); color: #666; font-family: inherit;
            font-size: 11px; font-weight: 500; cursor: pointer; white-space: nowrap;
            transition: all 0.3s ease;
        }
        .yx-auth-btn-secondary:hover { background: rgba(255,255,255,0.9); border-color: rgba(0,0,0,0.12); color: #444; }
        .yx-auth-btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
        .yx-auth-close-btn {
            position: absolute; top: 12px; right: 16px; z-index: 10;
            width: 28px; height: 28px; border: none; background: rgba(255,255,255,0.5);
            border-radius: 50%; cursor: pointer; display: flex; align-items: center;
            justify-content: center; transition: all 0.3s; color: #999; font-size: 14px;
        }
        .yx-auth-close-btn:hover { background: rgba(255,255,255,0.8); color: #555; }
        
        .yx-auth-btn-loading {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(0,0,0,0.1);
            border-top-color: #666;
            border-radius: 50%;
            animation: yxAuthSpin 0.7s linear infinite;
            vertical-align: middle;
        }
        @keyframes yxAuthSpin { to { transform: rotate(360deg); } }
        
        .yx-qq-input-wrapper { position: relative; }
        .yx-qq-input-wrapper input { padding-right: 80px !important; }
        .yx-qq-suffix {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            font-size: 13px; color: #999; pointer-events: none; user-select: none;
        }
        @media (max-width: 1024px) {
            .yx-auth-modal { width: 90vw; height: auto; min-height: 520px; max-height: 90vh; }
            .yx-modal-left { width: 60%; }
            .yx-modal-right { width: 40%; padding: 28px 24px; }
        }
        @media (max-width: 768px) {
            .yx-auth-modal { width: 100%; height: auto; flex-direction: column; }
            .yx-modal-left { width: 100%; height: 200px; }
            .yx-modal-right { width: 100%; padding: 24px; border-left: none; border-top: 1px solid rgba(255,255,255,0.4); }
            .yx-modal-left .left-content { bottom: 24px; left: 24px; }
        }
    </style>
</head>
<body>

    <!-- ==================== 顶部导航 ==================== -->
    <header class="top-nav">
        <div class="nav-left">
            <div class="logo-main" onclick="showHome()">
                <img src="image/tx.png" alt="雅泫视频">
                雅泫视频
            </div>
            <div class="search-main">
                <input type="text" id="searchInput" placeholder="吞噬星空剧场版 决战原始星" autocomplete="off">
                <button id="searchBtn"><i class="fas fa-search"></i></button>
                <div class="search-suggestions" id="searchSuggestions"></div>
            </div>
        </div>
        <div class="nav-right">
            <?php if ($isLoggedIn): ?>
            <div class="user-profile" id="userProfile">
                <img src="<?php echo htmlspecialchars($userAvatar); ?>" alt="用户头像" class="user-avatar" id="navUserAvatar" onerror="this.onerror=null;this.src='/YX_VIDEO/image/tx.png';">
                <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                <div class="user-dropdown" id="userDropdown">
                    <a href="/" class="dropdown-item"><i class="fas fa-home"></i>MoonYa</a>
                    <a href="javascript:void(0)" class="dropdown-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> 退出登录</a>
                </div>
            </div>
            <?php else: ?>
            <a href="javascript:void(0)" class="login-link" id="navLoginBtn"><i class="fas fa-user"></i> 登录</a>
            <?php endif; ?>
        </div>
    </header>

    <!-- ==================== 左侧边栏（透明毛玻璃） ==================== -->
    <aside class="sidebar">
        <div class="sidebar-group">
            <div class="nav-item active" onclick="switchCategory(this, '首页')">
                <i class="fas fa-star"></i> 首页
            </div>
        </div>
        <div class="sidebar-group">
            <div class="sidebar-title">全部频道</div>
            <div class="nav-item" onclick="switchCategory(this, '电视剧')"><i class="fas fa-tv"></i> 电视剧</div>
            <div class="nav-item" onclick="switchCategory(this, '电影')"><i class="fas fa-film"></i> 电影</div>
            <div class="nav-item" onclick="switchCategory(this, '综艺')"><i class="fas fa-laugh-beam"></i> 综艺</div>
            <div class="nav-item" onclick="switchCategory(this, '动漫')"><i class="fas fa-dragon"></i> 动漫</div>
            <div class="nav-item" onclick="switchCategory(this, '少儿')"><i class="fas fa-child"></i> 少儿</div>
            <div class="nav-item" onclick="switchCategory(this, 'NBA')"><i class="fas fa-basketball-ball"></i> NBA</div>
            <div class="nav-item" onclick="switchCategory(this, '短剧')"><i class="fas fa-mobile-alt"></i> 短剧</div>
            <div class="nav-item" onclick="switchCategory(this, '小游戏')"><i class="fas fa-gamepad"></i> 小游戏</div>
            <div class="nav-item" onclick="switchCategory(this, '纪录片')"><i class="fas fa-book-open"></i> 纪录片</div>
            <div class="nav-item" onclick="switchCategory(this, '体育')"><i class="fas fa-running"></i> 体育</div>
            <div class="nav-item" onclick="switchCategory(this, '宠物TV')"><i class="fas fa-paw"></i> 宠物TV</div>
            <div class="nav-item" onclick="switchCategory(this, '游戏')"><i class="fas fa-dice"></i> 游戏</div>
            <div class="nav-item" onclick="switchCategory(this, '中视频')"><i class="fas fa-play-circle"></i> 中视频</div>
            <div class="nav-item" onclick="switchCategory(this, '传奇游戏库')"><i class="fas fa-archive"></i> 传奇游戏库</div>
            <div class="nav-item" onclick="switchCategory(this, 'F1')"><i class="fas fa-flag-checkered"></i> F1</div>
            <div class="nav-item" onclick="switchCategory(this, 'NFL')"><i class="fas fa-football-ball"></i> NFL</div>
            <div class="nav-item" onclick="switchCategory(this, 'WWE')"><i class="fas fa-hand-rock"></i> WWE</div>
            <div class="nav-item" onclick="switchCategory(this, 'WNBA')"><i class="fas fa-basketball-ball"></i> WNBA</div>
            <div class="nav-item" onclick="switchCategory(this, '科技')"><i class="fas fa-microchip"></i> 科技</div>
            <div class="nav-item" onclick="switchCategory(this, '知识')"><i class="fas fa-graduation-cap"></i> 知识</div>
            <div class="nav-item" onclick="switchCategory(this, '学堂')"><i class="fas fa-school"></i> 学堂</div>
            <div class="nav-item" onclick="switchCategory(this, '艺术')"><i class="fas fa-palette"></i> 艺术</div>
            <div class="nav-item" onclick="switchCategory(this, '时尚')"><i class="fas fa-tshirt"></i> 时尚</div>
        </div>
    </aside>

    <!-- ==================== 首页主内容 ==================== -->
    <main class="main-content" id="homeView">
        <section class="hero-section">
            <div class="carousel-wrap" id="carouselWrap">
                <div id="carouselSlides"></div>
                <div class="carousel-overlay">
                    <div class="carousel-left" id="carouselLeft"></div>
                    <div class="carousel-right" id="carouselRight"></div>
                </div>
                <div class="carousel-dots" id="carouselDots"></div>
                <button class="carousel-arrow prev" onclick="prevSlide()"><i class="fas fa-chevron-left"></i></button>
                <button class="carousel-arrow next" onclick="nextSlide()"><i class="fas fa-chevron-right"></i></button>
            </div>
        </section>

        <section class="section-block">
            <div class="section-header">
                <div class="section-title">
                    重磅热播
                    <div class="section-tabs">
                        <span class="active" onclick="switchHotTab(this, 'all')">全部</span>
                        <span onclick="switchHotTab(this, 'tv')">电视剧</span>
                        <span onclick="switchHotTab(this, 'variety')">综艺</span>
                        <span onclick="switchHotTab(this, 'movie')">电影</span>
                        <span onclick="switchHotTab(this, 'anime')">动漫</span>
                        <span onclick="switchHotTab(this, 'kids')">少儿</span>
                        <span onclick="switchHotTab(this, 'doc')">纪录片</span>
                        <span onclick="switchHotTab(this, 'study')">学习时刻</span>
                    </div>
                </div>
                <div class="section-extra" onclick="shuffleHot()"><i class="fas fa-sync-alt"></i> 换一换</div>
            </div>
            <div class="video-grid-6" id="hotRow"></div>
            <div class="rec-grid" id="hotRowMore" style="margin-top:20px;"></div>
        </section>

        <div class="footer">© 2026 MoonYa 版权所有</div>
    </main>

    <!-- ==================== 分类页面 ==================== -->
    <div class="category-overlay" id="categoryView">
        <div class="section-header">
            <div class="section-title" id="categoryTitle">分类</div>
        </div>
        <div class="video-grid-6" id="categoryGrid"></div>
        <div class="rec-grid" id="categoryGridMore" style="margin-top:20px;"></div>
        <div class="footer" style="margin-top:40px;">© 2026 雅泫视频 版权所有</div>
    </div>

    <!-- 搜索结果页 -->
    <div class="search-overlay" id="searchView">
        <div class="search-header">
            <button class="back-home" onclick="showHome()"><i class="fas fa-arrow-left"></i> 返回首页</button>
            <h2 id="searchTitle">搜索结果</h2>
        </div>
        <div class="video-grid-6" id="searchGrid"></div>
        <div class="rec-grid" id="searchGridMore" style="margin-top:20px;"></div>
        
        <div class="empty-state" id="empty" style="display:none;">
            <i class="fas fa-film"></i>
            <h3>未找到相关资源</h3>
            <p>换个关键词试试</p>
        </div>
        <div class="error-box" id="errorBox" style="display:none;background:rgba(255,0,0,0.08);border:1px solid rgba(255,0,0,0.2);border-radius:8px;padding:16px;text-align:center;color:#ff4d4f;margin:12px 0;font-size:14px;line-height:1.6;"></div>
    </div>

    <!-- ==================== 播放器（完整保留） ==================== -->
    <div class="player-page" id="playerPage">
        <div class="player-main">
            <div class="player-video-wrap" id="videoWrap">
                <video id="videoEl" playsinline webkit-playsinline></video>
                <div class="video-watermark">雅泫视频</div>
                
                <!-- 加载/缓冲动画 —— 3个白色圆点律动 -->
                <div class="player-loading" id="playerLoading">
                    <div class="loading-dots">
                        <div></div><div></div><div></div>
                    </div>
                    <div class="loading-text">正在缓冲，请耐心等待</div>
                </div>
                
                <div class="danmu-layer" id="danmuLayer"></div>
                <div class="center-play-btn" id="centerPlayBtn"><i class="fas fa-play" id="centerPlayIcon"></i></div>
                
                <!-- 播放器顶部栏：Logo + 搜索 + 返回 -->
                <div class="player-top-bar">
                    <div class="player-logo" onclick="closePlayer(); showHome();">
                        <img src="image/tx.png" alt="雅泫视频">
                        <span>雅泫视频</span>
                    </div>
                    <div class="player-search">
                        <input type="text" id="playerSearchInput" placeholder="搜索视频..." autocomplete="off">
                        <button id="playerSearchBtn"><i class="fas fa-search"></i></button>
                    </div>
                    <span class="video-title" id="topTitle">正在播放</span>
                    <button class="back-btn" onclick="closePlayer()"><i class="fas fa-times"></i></button>
                </div>
                
                <div class="tx-controls" id="txControls">
                    <div class="tx-progress-area" id="txProgressArea">
                        <div class="tx-progress-bg">
                            <div class="tx-progress-buffer" id="txBuffer" style="width:0%"></div>
                            <div class="tx-progress-played" id="txPlayed" style="width:0%">
                                <div class="tx-progress-dot"></div>
                            </div>
                        </div>
                        <div class="tx-time-tooltip" id="txTooltip">00:00</div>
                    </div>
                    <div class="tx-controls-row">
                        <button class="play-btn" id="txPlayBtn"><i class="fas fa-play"></i></button>
                        <button id="txNextBtn" title="下一集"><i class="fas fa-step-forward"></i></button>
                        <button id="txDanmuToggle" title="弹幕"><span id="danmuToggleIcon" style="display:flex;align-items:center;justify-content:center;width:24px;height:24px;"></span></button>
                        <button id="txDanmuSet" title="弹幕设置"><i class="fas fa-cog"></i></button>
                        <div class="tx-danmu-input">
                            <input type="text" id="danmuInput" placeholder="发弹幕参与互动" maxlength="30">
                        </div>
                        <span class="tx-time" id="txTime">00:00 / 00:00</span>
                        <div class="tx-right-btns">
                            <div class="tx-menu-wrap">
                                <button id="txQualityBtn">自动</button>
                                <div class="tx-menu" id="qualityMenu">
                                </div>
                            </div>
                            <div class="tx-menu-wrap">
                                <button id="txSpeedBtn">倍速</button>
                                <div class="tx-menu" id="speedMenu">
                                    <button class="tx-menu-item" data-s="3.0">3.0X</button>
                                    <button class="tx-menu-item" data-s="2.0">2.0X</button>
                                    <button class="tx-menu-item" data-s="1.5">1.5X</button>
                                    <button class="tx-menu-item" data-s="1.25">1.25X</button>
                                    <button class="tx-menu-item active" data-s="1.0">1.0X</button>
                                    <button class="tx-menu-item" data-s="0.75">0.75X</button>
                                    <button class="tx-menu-item" data-s="0.5">0.5X</button>
                                </div>
                            </div>
                            <div class="volume-wrap">
                                <button id="txVolumeBtn" title="音量"><i class="fas fa-volume-up"></i></button>
                                <div class="volume-panel" id="volumePanel">
                                    <span class="volume-num" id="volumeNum">70</span>
                                    <div class="volume-track" id="volumeTrack">
                                        <div class="volume-fill" id="volumeFill"><div class="volume-dot"></div></div>
                                    </div>
                                    <button class="volume-icon" id="volumeMuteBtn"><i class="fas fa-volume-up"></i></button>
                                </div>
                            </div>
                            <div class="tx-menu-wrap" style="position:relative;">
                                <button id="txSetting" title="设置"><i class="fas fa-cog"></i></button>
                                <div class="settings-panel" id="settingsPanel">
                                    <div class="setting-row"><span>自动连播</span><div class="switch on" id="switchAuto" onclick="toggleSetting('auto')"></div></div>
                                    <div class="setting-row"><span>洗脑循环</span><div class="switch" id="switchLoop" onclick="toggleSetting('loop')"></div></div>
                                </div>
                            </div>
                            <button id="txPip" title="画中画"><i class="fas fa-images"></i></button>
                            <button id="txFullscreenBtn" title="全屏"><i class="fas fa-expand"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="player-sidebar" id="playerSidebar">
            <div class="sidebar-header">
                <div class="sidebar-title">
                    <span id="sidebarTitle">标题</span>
                    <span class="intro-btn" id="introBtn">简介 <i class="fas fa-chevron-right" style="font-size:10px;"></i></span>
                </div>
                <div class="sidebar-meta">
                    <div id="sidebarMetaLine">
                        <span id="sidebarArea">内地</span> · <span id="sidebarYear">2023</span> · <span id="sidebarType">奇幻冒险</span>
                    </div>
                    <div><span class="score" id="sidebarScore">8.2</span>分 <span class="play-count"><i class="fas fa-play-circle"></i> <span id="sidebarCount">15660</span></span></div>
                    <div style="margin-top:4px;color:#666;font-size:12px;" id="sidebarUpdate">更新至150集/全156集 · 每周六10:00更新</div>
                </div>
                <div class="sidebar-actions">
                    <button id="downloadBtn" onclick="downloadCurrent()"><i class="fas fa-download"></i> 下载</button>
                </div>
            </div>
            <div class="ep-section">
                <div class="ep-tabs">
                    <span class="active" onclick="switchEpTab(this, 'list')">播放列表</span>
                    <span onclick="switchEpTab(this, 'rec')">相关推荐</span>
                </div>
                <div id="epTabContent">
                    <div class="ep-groups" id="epGroups"></div>
                    <div class="ep-grid" id="epGrid"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="intro-panel-overlay" id="introOverlay">
        <div class="intro-panel" id="introPanel">
            <div class="intro-header">
                <h4 id="introPanelTitle">简介</h4>
                <button class="close-intro" onclick="closeIntro()"><i class="fas fa-times"></i></button>
            </div>
            <div class="intro-body">
                <div class="intro-meta" id="introMeta"></div>
                <div id="introContent">加载中...</div>
            </div>
        </div>
    </div>

    <!-- 代理切换提示弹窗 -->
    <div class="proxy-modal-overlay" id="proxyModal">
        <div class="proxy-modal">
            <div class="pm-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <h3>代理切换提示</h3>
            <p>因雅泫官方CORS代理不可用，已切换为第三方代理。可能会有卡顿</p>
            <div style="text-align: right;">
                <button class="pm-btn" onclick="closeProxyModal()">知道了</button>
            </div>
        </div>
    </div>

    <div id="toastBox"></div>

    <script src="<?php echo htmlspecialchars($yxVideoConfig['hls_js_url'], ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script>
    (function() {
        const API_SOURCES = <?php echo json_encode($yxVideoConfig['sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        // 免费 CORS 代理列表（当本地 PHP 代理不可用时自动切换）
        const FREE_PROXIES = <?php echo json_encode($yxVideoConfig['cors_proxies'], JSON_UNESCAPED_SLASHES); ?>;
        const PLACEHOLDER_IMAGE_TEMPLATE = <?php echo json_encode($yxVideoConfig['placeholder_image_url_template'], JSON_UNESCAPED_SLASHES); ?>;

        // 轮播动漫池：12个热门国产动漫，每次随机抽6个单独搜索
        const ANIME_POOL = [
            '斗罗大陆','吞噬星空','完美世界','斗破苍穹','神印王座',
            '一念永恒','凡人修仙传','遮天','仙逆','狐妖小红娘',
            '一人之下','星辰变'
        ];

        const CATEGORY_WORDS = {
            '电视剧': ['狂飙','三体','庆余年','赘婿','人世间','隐秘的角落','漫长的季节','繁花','长相思','与凤行','莲花楼','琅琊榜','甄嬛传','知否知否','延禧攻略','陈情令','梦华录','星汉灿烂','长风渡','宁安如梦'],
            '电影': ['流浪地球','封神','哪吒','红海行动','长空之王','独行月球','八角笼中','消失的她','满江红','热辣滚烫','飞驰人生','第二十条','熊出没','奥本海默','芭比','泰坦尼克号','阿凡达','复仇者联盟','星际穿越','盗梦空间'],
            '综艺': ['奔跑吧','乘风破浪','演员请就位','极限挑战','向往的生活','披荆斩棘','声生不息','你好星期六','王牌对王牌','快乐大本营','天天向上','歌手','中国好声音','非诚勿扰','脱口秀大会','德云斗笑社','密室大逃脱','明星大侦探','花儿与少年','五十公里桃花坞'],
            '动漫': ['斗罗大陆','吞噬星空','完美世界','斗破苍穹','神印王座','一念永恒','凡人修仙传','遮天','仙逆','狐妖小红娘','一人之下','间谍过家家','咒术回战','鬼灭之刃','进击的巨人','海贼王','火影忍者','名侦探柯南','七龙珠','蜡笔小新'],
            '少儿': ['海绵宝宝','熊出没','喜羊羊','小猪佩奇','汪汪队','超级飞侠','萌鸡小队','大头儿子','猪猪侠','奥特曼','巴啦啦小魔仙','宝宝巴士','巧虎','托马斯','海底小纵队','小马宝莉','猫和老鼠','哆啦A梦','米奇妙妙屋','爱探险的朵拉'],
            'NBA': ['NBA','湖人','勇士','詹姆斯','库里','杜兰特','哈登','威少','凯尔特人','掘金','雄鹿','太阳','独行侠','快船','热火','76人','猛龙','马刺','灰熊','鹈鹕'],
            '短剧': ['短剧','赘婿','总裁','重生','穿越','甜宠','逆袭','战神','神医','嫡女','豪门','契约','闪婚','替嫁','复仇','真假千金','先婚后爱','破镜重圆','青梅竹马','追妻火葬场'],
            '小游戏': ['游戏','电竞','LOL','王者荣耀','原神','和平精英','我的世界','迷你世界','蛋仔派对','第五人格','永劫无间','绝地求生','穿越火线','英雄联盟','CSGO','DOTA2','守望先锋','炉石传说','金铲铲','暗区突围'],
            '纪录片': ['纪录片','航拍中国','地球脉动','蓝色星球','人生一串','风味人间','我在故宫修文物','河西走廊','大明宫','紫禁城','中国通史','宇宙时空之旅','行星地球','冰冻星球','舌尖上的中国','人间世','手术两百年','大国崛起','创新中国','故宫100'],
            '体育': ['体育','足球','世界杯','奥运会','CBA','网球','乒乓球','羽毛球','田径','游泳','跳水','排球','F1','赛车','马拉松','斯诺克','拳击','体操','滑冰','滑雪'],
            '宠物TV': ['宠物','猫','狗','萌宠','动物世界','野生动物','宠物医院','萌宠成长记','动物宝贝','狗狗冲冲冲','猫咪日常','仓鼠','兔子','鹦鹉','爬宠','柯基','金毛','哈士奇','布偶猫','柴犬'],
            '游戏': ['游戏','电竞','LOL','王者荣耀','原神','和平精英','我的世界','迷你世界','蛋仔派对','第五人格','永劫无间','绝地求生','穿越火线','英雄联盟','CSGO','DOTA2','守望先锋','炉石传说','金铲铲','暗区突围'],
            '中视频': ['中视频','剧情','搞笑','美食','旅行','vlog','手工','测评','科普','历史','军事','汽车','三农','家居','育儿','情感','职场','健身','读书','电影解说'],
            '传奇游戏库': ['传奇','复古传奇','热血传奇','传奇世界','传奇霸业','美杜莎传奇','冰雪传奇','火龙传奇','沉默传奇','超变传奇','单职业传奇','合击传奇','攻速传奇','打金传奇','微变传奇','迷失传奇','变态传奇','公益传奇','176传奇','180传奇'],
            'F1': ['F1','赛车','法拉利','汉密尔顿','舒马赫','红牛','迈凯伦','阿隆索','维斯塔潘','勒克莱尔','梅赛德斯','银石','摩纳哥','蒙扎','铃鹿','斯帕','纽博格林','塞纳','普罗斯特','博塔斯'],
            'NFL': ['NFL','橄榄球','超级碗','酋长','爱国者','包装工','牛仔','布雷迪','马霍姆斯','拉马尔','亨利','瓦特','达阵','四分卫','外接手','跑卫','近端锋','线卫','角卫','安全卫'],
            'WWE': ['WWE','摔跤','格斗','罗曼','塞纳','洛克','冷石','送葬者','大布','兰迪','艾吉','赛斯','贝基','夏洛特','隆达罗西','tripleH','肖恩','科迪','杰乌索','德鲁'],
            'WNBA': ['WNBA','女篮','王牌','自由人','火花','天猫','水星','神秘人','梦想','阳光','飞翼','狂热','风暴','天空','李月汝','韩旭','坎贝奇','斯图尔特','约内斯库','帕克'],
            '科技': ['科技','人工智能','芯片','马斯克','SpaceX','iPhone','华为','小米','电动车','特斯拉','ChatGPT','机器人','无人机','5G','元宇宙','脑机接口','量子计算','区块链','虚拟现实','3D打印'],
            '知识': ['知识','历史','哲学','心理学','经济学','科普','社会学','人类学','考古','文学','艺术史','逻辑思维','批判性思维','沟通','领导力','时间管理','情绪管理','人际关系','演讲','写作'],
            '学堂': ['学堂','教育','考研','英语','编程','数学','物理','化学','生物','语文','奥数','雅思','托福','GRE','公务员','注册会计师','司法考试','建造师','教师资格证','专升本'],
            '艺术': ['艺术','绘画','音乐','舞蹈','书法','雕塑','油画','国画','钢琴','吉他','小提琴','芭蕾','街舞','戏曲','摄影','陶艺','版画','水彩','素描','设计'],
            '时尚': ['时尚','穿搭','美妆','潮流','街拍','时装周','香奈儿','迪奥','路易威登','古驰','普拉达','爱马仕','卡地亚','蒂芙尼','范思哲','阿玛尼','博柏利','圣罗兰','纪梵希','巴黎世家']
        };

        let currentSource = "极速";
        let proxyIdx = 0;
        let proxyMode = 'local';
        let proxyFailCount = 0;
        let proxyWarningShown = false;
        let videoList = [];
        let bufferList = [];
        let carouselList = [];
        let detailData = null;
        let episodes = [];
        let currentEp = 0;
        let hls = null;
        let videoEl = document.getElementById('videoEl');
        let currentVideoUrl = '';
        let isPlaying = false;
        let danmuEnabled = true;
        let danmuList = ["故事精彩纷呈","弹幕注入灵魂","又一个五年计划开始","手握日月摘星辰","把第一季看完过来看第二季","这集太燃了","画质不错","更新快点","主角光环","特效炸裂"];
        let currentSpeed = 1.0;
        let currentQuality = -1;
        let autoPlayNext = true;
        let loopPlay = false;
        let controlsInited = false;
        let isDraggingProgress = false;
        let carouselIndex = 0;
        let carouselTimer = null;
        let searchDebounceTimer = null;

        let isLoading = false;
        let hasMore = true;
        let loadContext = { type: 'home', keyword: '', category: '', page: 1 };

        const apiCache = new Map();
        const CACHE_TTL = 5 * 60 * 1000;

        let viewStates = {
            home: null,
            search: null,
            category: {}
        };

        const $ = s => document.querySelector(s);

        function toast(msg) {
            const t = document.createElement('div');
            t.className = 'toast';
            t.textContent = msg;
            $('#toastBox').appendChild(t);
            setTimeout(() => t.remove(), 2500);
        }

        function safe(str) {
            const d = document.createElement('div');
            d.textContent = str || '';
            return d.innerHTML;
        }

        function cleanTags(str) {
            if (!str) return '';
            return str.replace(/<br\/>/gi, '\n').replace(/<[^>]+>/g, '');
        }

        function parseEpisodes(urlStr) {
            if (!urlStr) return [];
            let main = urlStr;
            if (urlStr.includes('$$$')) {
                const p = urlStr.split('$$$');
                main = p[p.length - 1] || urlStr;
            }
            const list = [];
            main.split('#').forEach((ep, i) => {
                ep = ep.trim();
                if (!ep) return;
                const idx = ep.indexOf('$');
                if (idx > 0) {
                    const name = ep.substring(0, idx).trim();
                    const link = ep.substring(idx + 1).trim();
                    if (link) list.push({ name: name || `第${i+1}集`, link: link });
                } else if (ep.startsWith('http')) {
                    list.push({ name: `第${i+1}集`, link: ep });
                }
            });
            if (list.length === 0 && urlStr.trim().startsWith('http')) list.push({ name: '正片', link: urlStr.trim() });
            return list;
        }

        function isM3U8(url) { return url && (url.includes('.m3u8') || url.includes('m3u8')); }

        function buildApiUrl(keyword, page) {
            const base = API_SOURCES[currentSource];
            return base.replace(/pg=\d+/, `pg=${page}`) + encodeURIComponent(keyword);
        }

        function getFallbackPic(name, w, h) {
            const seed = encodeURIComponent(name || 'default').replace(/[^a-zA-Z0-9]/g, '');
            return PLACEHOLDER_IMAGE_TEMPLATE
                .replace('{seed}', seed)
                .replace('{width}', String(w))
                .replace('{height}', String(h));
        }

        function dedupeByName(list) {
            const seen = new Set();
            return list.filter(item => {
                const name = item.vod_name;
                if (!name || seen.has(name)) return false;
                seen.add(name);
                return true;
            });
        }

        function saveCurrentViewState() {
            const state = {
                videoList: [...videoList],
                bufferList: [...bufferList],
                loadContext: { ...loadContext },
                hasMore: hasMore
            };
            if (loadContext.type === 'home') viewStates.home = state;
            else if (loadContext.type === 'search') viewStates.search = state;
            else if (loadContext.type === 'category') viewStates.category[loadContext.category] = state;
        }

        function restoreViewState(type, categoryName) {
            let state = null;
            if (type === 'home') state = viewStates.home;
            else if (type === 'search') state = viewStates.search;
            else if (type === 'category') state = viewStates.category[categoryName];
            if (!state) return false;
            videoList = state.videoList;
            bufferList = state.bufferList;
            loadContext = state.loadContext;
            hasMore = state.hasMore;
            isLoading = false;
            return true;
        }

        window.closeProxyModal = function() {
            $('#proxyModal').classList.remove('show');
        };

        function showProxyWarning() {
            if (proxyWarningShown) return;
            proxyWarningShown = true;
            $('#proxyModal').classList.add('show');
        }

        async function fetchApiData(keyword, page = 1) {
            const cacheKey = `${currentSource}_${keyword}_${page}`;
            const cached = apiCache.get(cacheKey);
            if (cached && (Date.now() - cached.time < CACHE_TTL)) {
                return cached.data;
            }

            const rawUrl = buildApiUrl(keyword, page);
            
            if (proxyMode === 'local') {
                let localOk = false;
                for (let retry = 0; retry < 2; retry++) {
                    try {
                        const resp = await fetch('api.php?url=' + encodeURIComponent(rawUrl), {
                            headers: { 'Accept': 'application/json' },
                            signal: AbortSignal.timeout(12000)
                        });
                        if (resp.ok) {
                            const text = await resp.text();
                            if (text.trim()) {
                                let data;
                                try { data = JSON.parse(text); } catch(e) {
                                    const m = text.match(/\{[\s\S]*\}/);
                                    if (m) data = JSON.parse(m[0]);
                                }
                                if (data && data.list) {
                                    apiCache.set(cacheKey, { data, time: Date.now() });
                                    localOk = true;
                                    return data;
                                }
                            }
                        }
                        break;
                    } catch(e) {
                        if (retry === 1) {
                            console.warn('本地代理失败，准备切换免费代理:', e);
                        }
                    }
                }
                if (!localOk) {
                    proxyMode = 'free';
                    showProxyWarning();
                }
            }

            for (let i = 0; i < FREE_PROXIES.length; i++) {
                const pIdx = (proxyIdx + i) % FREE_PROXIES.length;
                const proxyUrl = FREE_PROXIES[pIdx] + encodeURIComponent(rawUrl);
                try {
                    const resp = await fetch(proxyUrl, {
                        headers: { 'Accept': 'application/json' },
                        signal: AbortSignal.timeout(12000)
                    });
                    if (!resp.ok) continue;
                    const text = await resp.text();
                    if (!text.trim()) continue;
                    let data;
                    try { data = JSON.parse(text); } catch (e) {
                        const m = text.match(/\{[\s\S]*\}/);
                        if (m) data = JSON.parse(m[0]);
                    }
                    if (data && data.list) {
                        proxyIdx = pIdx;
                        apiCache.set(cacheKey, { data, time: Date.now() });
                        proxyFailCount = 0;
                        return data;
                    }
                } catch (e) { continue; }
            }
            
            proxyFailCount++;
            if (proxyFailCount >= 3) {
                proxyMode = 'local';
                proxyFailCount = 0;
            }
            
            return null;
        }

        async function batchSearch(words, page = 1, categoryHint) {
            const uniqueWords = [...new Set(words)].slice(0, 5);
            const allItems = [];
            const seenIds = new Set();
            const seenNames = new Set();
            
            for (let i = 0; i < uniqueWords.length; i += 3) {
                const batch = uniqueWords.slice(i, i + 3);
                const promises = batch.map(w => fetchApiData(w, page));
                const results = await Promise.allSettled(promises);
                
                results.forEach(r => {
                    if (r.status === 'fulfilled' && r.value?.list && Array.isArray(r.value.list)) {
                        r.value.list.forEach(item => {
                            const idKey = item.vod_id;
                            const nameKey = item.vod_name;
                            if (idKey && seenIds.has(idKey)) return;
                            if (nameKey && seenNames.has(nameKey)) return;
                            if (idKey) seenIds.add(idKey);
                            if (nameKey) seenNames.add(nameKey);
                            allItems.push(item);
                        });
                    }
                });
                
                if (allItems.length >= 12) break;
            }
            
            return allItems;
        }

        async function updateSearchSuggestions(keyword) {
            const sg = $('#searchSuggestions');
            if (!keyword || keyword.length < 2) {
                sg.classList.remove('show');
                return;
            }
            const data = await fetchApiData(keyword, 1);
            sg.innerHTML = '';
            if (data?.list && data.list.length > 0) {
                const items = data.list.slice(0, 6);
                items.forEach(item => {
                    const pic = (item.vod_pic && item.vod_pic.trim()) ? safe(item.vod_pic) : getFallbackPic(item.vod_name, 200, 112);
                    const fallback = getFallbackPic(item.vod_name, 200, 112);
                    const div = document.createElement('div');
                    div.className = 'suggestion-item';
                    div.innerHTML = `
                        <img src="${pic}" onerror="this.onerror=null;this.src='${fallback}';" alt="">
                        <div class="sg-text">
                            <div class="sg-name">${safe(item.vod_name)}</div>
                            <div class="sg-meta">${safe(item.vod_remarks || item.vod_class || '未知')}</div>
                        </div>
                    `;
                    div.onclick = () => {
                        $('#searchInput').value = item.vod_name;
                        sg.classList.remove('show');
                        initSearch(item.vod_name);
                    };
                    sg.appendChild(div);
                });
                sg.classList.add('show');
            } else {
                sg.innerHTML = '<div class="suggestion-empty">暂无相关推荐</div>';
                sg.classList.add('show');
            }
        }

        function shimmerCard(count) {
            let html = '';
            for (let i = 0; i < count; i++) {
                html += `
                <div class="shimmer-card">
                    <div class="shimmer-cover"></div>
                    <div class="shimmer-info">
                        <div class="shimmer-line"></div>
                        <div class="shimmer-line short"></div>
                    </div>
                </div>`;
            }
            return html;
        }

        function shimmerRecCard(count) {
            let html = '';
            for (let i = 0; i < count; i++) {
                html += `
                <div class="shimmer-rec-card">
                    <div class="shimmer-rec-top"></div>
                    <div class="shimmer-info">
                        <div class="shimmer-line"></div>
                        <div class="shimmer-line short"></div>
                    </div>
                </div>`;
            }
            return html;
        }

        function renderCarousel(list) {
            carouselList = list;
            const unique = dedupeByName(list);
            const slides = unique.slice(0, 6);
            if (slides.length === 0) return;
            
            const slidesHTML = slides.map((item, i) => {
                const pic = (item.vod_pic && item.vod_pic.trim()) 
                    ? safe(item.vod_pic) 
                    : getFallbackPic(item.vod_name || 'default', 1200, 600);
                return `
                <div class="carousel-slide ${i === 0 ? 'active' : ''}" data-index="${i}" 
                     style="background-image: url('${pic}')" onclick="playCarouselItem(${i})">
                </div>`;
            }).join('');
            $('#carouselSlides').innerHTML = slidesHTML;
            
            const thumbsHTML = slides.map((item, i) => {
                const title = item.vod_name || '';
                const pic = (item.vod_pic && item.vod_pic.trim()) 
                    ? safe(item.vod_pic) 
                    : getFallbackPic(title, 200, 300);
                const fallback = getFallbackPic(title, 200, 300);
                return `
                <div class="carousel-thumb ${i === 0 ? 'active' : ''}" data-index="${i}" onclick="goToSlide(${i}); event.stopPropagation();">
                    <img src="${pic}" onerror="this.onerror=null;this.src='${fallback}';" alt="${safe(title)}">
                    <div class="thumb-title">${safe(title)}</div>
                </div>`;
            }).join('');
            $('#carouselRight').innerHTML = thumbsHTML;
            
            updateCarouselLeft(0);
            
            const dotsHTML = slides.map((_, i) => 
                `<div class="carousel-dot ${i === 0 ? 'active' : ''}" onclick="goToSlide(${i})"></div>`
            ).join('');
            $('#carouselDots').innerHTML = dotsHTML;
            
            startCarousel();
        }

        function updateCarouselLeft(idx) {
            if (!carouselList[idx]) return;
            const item = carouselList[idx];
            const title = item.vod_name || '';
            const desc = cleanTags(item.vod_content || '').substring(0, 60) || '精彩热播，不容错过';
            const html = `
                <div class="banner-tag">
                    <span><i class="fas fa-fire"></i> 在追破${Math.floor(Math.random()*5000+100)}万</span>
                    <span><i class="fas fa-comment"></i> 讨论破${Math.floor(Math.random()*200+10)}万</span>
                </div>
                <h1 class="banner-title">${safe(title)}</h1>
                <p class="banner-desc">${safe(desc)}</p>
                <button class="banner-btn" onclick="playCarouselItem(${idx}); event.stopPropagation();">
                    <i class="fas fa-play"></i> 立即播放
                </button>
            `;
            $('#carouselLeft').innerHTML = html;
        }

        function startCarousel() {
            if (carouselTimer) clearInterval(carouselTimer);
            carouselTimer = setInterval(() => nextSlide(), 5000);
        }

        window.nextSlide = function() {
            const slides = document.querySelectorAll('.carousel-slide');
            if (slides.length === 0) return;
            let nextIdx = (carouselIndex + 1) % slides.length;
            goToSlide(nextIdx);
        };

        window.prevSlide = function() {
            const slides = document.querySelectorAll('.carousel-slide');
            if (slides.length === 0) return;
            let prevIdx = (carouselIndex - 1 + slides.length) % slides.length;
            goToSlide(prevIdx);
        };

        window.goToSlide = function(idx) {
            const slides = document.querySelectorAll('.carousel-slide');
            const dots = document.querySelectorAll('.carousel-dot');
            const thumbs = document.querySelectorAll('.carousel-thumb');
            if (slides.length === 0) return;
            
            slides[carouselIndex].classList.remove('active');
            dots[carouselIndex].classList.remove('active');
            if (thumbs[carouselIndex]) thumbs[carouselIndex].classList.remove('active');
            
            carouselIndex = idx;
            
            slides[carouselIndex].classList.add('active');
            dots[carouselIndex].classList.add('active');
            if (thumbs[carouselIndex]) thumbs[carouselIndex].classList.add('active');
            
            updateCarouselLeft(carouselIndex);
            
            if (carouselTimer) clearInterval(carouselTimer);
            carouselTimer = setInterval(() => nextSlide(), 5000);
        };

        window.playCarouselItem = function(idx) {
            if (!carouselList[idx]) { toast('视频数据加载中'); return; }
            const item = carouselList[idx];
            detailData = item;
            episodes = parseEpisodes(item.vod_play_url || '');
            if (episodes.length > 0) {
                currentEp = 0;
                play(episodes[0].link, episodes[0].name, item.vod_name);
            } else {
                toast('暂无播放链接');
            }
        };

        function renderVCard(item, i) {
            const title = item.vod_name || '';
            const pic = (item.vod_pic && item.vod_pic.trim()) 
                ? safe(item.vod_pic) 
                : getFallbackPic(title, 400, 225);
            const fallback = getFallbackPic(title, 400, 225);
            return `
            <div class="v-card" onclick="playFromHome(${i})">
                <div class="v-cover">
                    <img src="${pic}" loading="lazy" referrerpolicy="no-referrer" 
                        onerror="this.onerror=null;this.src='${fallback}';"
                        alt="${safe(title)}">
                </div>
                <div class="v-info">
                    <div class="v-name">${safe(title)}</div>
                    <div class="v-sub">${safe(item.vod_remarks || '更新至' + Math.floor(Math.random()*50+1) + '集')}</div>
                </div>
            </div>`;
        }

        function renderRecCard(item, i) {
            const desc = cleanTags(item.vod_content || '').substring(0, 45) || '精彩剧情，不容错过';
            const meta = item.vod_actor || item.vod_class || '未知类型';
            const title = item.vod_name || '';
            const pic = (item.vod_pic && item.vod_pic.trim()) 
                ? safe(item.vod_pic) 
                : getFallbackPic(title, 400, 225);
            const fallback = getFallbackPic(title, 400, 225);
            const smallFallback = getFallbackPic(title, 100, 140);
            return `
            <div class="rec-card" onclick="playFromHome(${i})">
                <div class="rec-cover-top">
                    <img src="${pic}" loading="lazy" referrerpolicy="no-referrer" 
                        onerror="this.onerror=null;this.src='${fallback}';"
                        alt="${safe(title)}">
                </div>
                <div class="rec-info-row">
                    <div class="rec-small-cover">
                        <img src="${pic}" loading="lazy" referrerpolicy="no-referrer" 
                            onerror="this.onerror=null;this.src='${smallFallback}';"
                            alt="${safe(title)}">
                    </div>
                    <div class="rec-text">
                        <div class="rec-name">${safe(title)}</div>
                        <div class="rec-meta">${safe(meta)}</div>
                        <div class="rec-desc">"${safe(desc)}"</div>
                    </div>
                    <div class="rec-action">
                        <span class="rec-tag">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9.79968 11.6001H14.5997" stroke="white" style="stroke:white;stroke-opacity:1;" stroke-width="1.336" stroke-linecap="round"/>
                                <path d="M12.2 9.2002V14.0002" stroke="white" style="stroke:white;stroke-opacity:1;" stroke-width="1.336" stroke-linecap="round"/>
                                <path d="M1.84955 12.5005H6.04955" stroke="white" style="stroke:white;stroke-opacity:1;" stroke-width="1.336" stroke-linecap="round"/>
                                <path d="M1.84955 7.7002H8.74955" stroke="white" style="stroke:white;stroke-opacity:1;" stroke-width="1.336" stroke-linecap="round"/>
                                <path d="M1.84961 2.90039H12.3496" stroke="white" style="stroke:white;stroke-opacity:1;" stroke-width="1.336" stroke-linecap="round"/>
                            </svg>
                            追
                        </span>
                    </div>
                </div>
            </div>`;
        }

        window.playFromHome = function(idx) {
            if (!videoList[idx]) { toast('视频数据加载中'); return; }
            const item = videoList[idx];
            detailData = item;
            episodes = parseEpisodes(item.vod_play_url || '');
            if (episodes.length > 0) {
                currentEp = 0;
                play(episodes[0].link, episodes[0].name, item.vod_name);
            } else {
                toast('暂无播放链接');
            }
        };

        window.switchCategory = function(el, name) {
            if (el.classList.contains('active')) return;
            
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            el.classList.add('active');
            
            if (name === '首页') {
                saveCurrentViewState();
                showHome();
                if (viewStates.home) {
                    restoreViewState('home');
                }
            } else {
                saveCurrentViewState();
                $('#homeView').style.display = 'none';
                $('#searchView').classList.remove('show');
                $('#categoryView').classList.add('show');
                $('#categoryTitle').textContent = name;
                
                if (viewStates.category[name]) {
                    restoreViewState('category', name);
                    rebuildCategoryDOM();
                } else {
                    loadCategory(name);
                }
            }
        };

        window.showHome = function() {
            $('#homeView').style.display = 'block';
            $('#searchView').classList.remove('show');
            $('#categoryView').classList.remove('show');
            document.body.style.overflow = '';
        };

        function rebuildCategoryDOM() {
            $('#categoryGrid').innerHTML = videoList.slice(0, 12).map((item, i) => renderVCard(item, i)).join('');
            const appended = videoList.slice(12);
            $('#categoryGridMore').innerHTML = appended.length > 0
                ? appended.map((item, i) => renderRecCard(item, i + 12)).join('')
                : '';
        }

        async function loadCategory(name) {
            isLoading = true;
            loadContext = { type: 'category', keyword: '', category: name, page: 1 };
            hasMore = true;
            
            $('#categoryGrid').innerHTML = shimmerCard(12);
            $('#categoryGridMore').innerHTML = '';
            
            const words = CATEGORY_WORDS[name] || [name];
            const list = await batchSearch(words, 1, name);
            isLoading = false;
            
            if (list.length === 0) {
                $('#categoryGrid').innerHTML = `
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <i class="fas fa-film"></i>
                        <h3>暂无内容</h3>
                        <p>该分类下暂时没有资源</p>
                    </div>`;
                hasMore = false;
                return;
            }
            
            videoList = list;
            bufferList = list.slice(12);
            hasMore = list.length >= 10;
            
            $('#categoryGrid').innerHTML = list.slice(0, 12).map((item, i) => renderVCard(item, i)).join('');
        }

        async function initSearch(keyword) {
            saveCurrentViewState();
            isLoading = true;
            loadContext = { type: 'search', keyword: keyword, category: '', page: 1 };
            hasMore = true;
            
            $('#homeView').style.display = 'none';
            $('#categoryView').classList.remove('show');
            $('#searchView').classList.add('show');
            $('#searchTitle').textContent = `"${keyword}" 的搜索结果`;
            $('#searchGrid').innerHTML = shimmerCard(12);
            $('#searchGridMore').innerHTML = '';
            $('#empty').style.display = 'none';
            $('#errorBox').style.display = 'none';
            
            const data = await fetchApiData(keyword, 1);
            isLoading = false;
            
            if (data?.list && Array.isArray(data.list) && data.list.length > 0) {
                const seen = new Set();
                const uniqueList = data.list.filter(item => {
                    if (!item.vod_name || seen.has(item.vod_name)) return false;
                    seen.add(item.vod_name);
                    return true;
                });
                videoList = uniqueList;
                bufferList = uniqueList.slice(12);
                hasMore = uniqueList.length >= 10;
                $('#searchGrid').innerHTML = uniqueList.slice(0, 12).map((item, i) => renderVCard(item, i)).join('');
            } else {
                videoList = [];
                bufferList = [];
                hasMore = false;
                $('#searchGrid').innerHTML = '';
                $('#empty').style.display = 'block';
                $('#errorBox').style.display = 'block';
                $('#errorBox').innerHTML = `未找到 "${safe(keyword)}" 的相关资源。`;
            }
        }

        async function initHome() {
            isLoading = true;
            loadContext = { type: 'home', keyword: '', category: '', page: 1 };
            hasMore = true;
            
            $('#hotRow').innerHTML = shimmerCard(12);
            $('#hotRowMore').innerHTML = '';
            
            const pool = [...ANIME_POOL].sort(() => 0.5 - Math.random());
            let searchWords = pool.slice(0, 6);
            let backupWords = pool.slice(6);
            let carouselItems = [];
            
            let promises = searchWords.map(w => fetchApiData(w, 1));
            let results = await Promise.all(promises);
            
            results.forEach((data, i) => {
                if (data?.list && data.list.length > 0) {
                    carouselItems.push(data.list[0]);
                }
            });
            
            while (carouselItems.length < 6 && backupWords.length > 0) {
                const need = 6 - carouselItems.length;
                const batch = backupWords.splice(0, need);
                promises = batch.map(w => fetchApiData(w, 1));
                results = await Promise.all(promises);
                results.forEach(data => {
                    if (data?.list && data.list.length > 0) {
                        carouselItems.push(data.list[0]);
                    }
                });
            }
            
            if (carouselItems.length > 0) {
                renderCarousel(carouselItems);
            }
            
            const hotWords = [
                '斗罗大陆','完美世界','斗破苍穹','吞噬星空','神印王座',
                '一念永恒','凡人修仙传','遮天','仙逆','狐妖小红娘',
                '流浪地球','封神','哪吒','满江红','热辣滚烫',
                '飞驰人生','第二十条','熊出没','奥本海默','芭比'
            ];
            const shuffled = hotWords.sort(() => 0.5 - Math.random()).slice(0, 5);
            
            const list = await batchSearch(shuffled, 1, 'home');
            isLoading = false;
            
            const uniqueList = dedupeByName(list);
            
            if (uniqueList.length === 0) {
                $('#hotRow').innerHTML = `
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <i class="fas fa-film"></i>
                        <h3>暂无内容</h3>
                        <p>当前数据源暂无资源，请稍后重试</p>
                    </div>`;
                hasMore = false;
                return;
            }
            
            videoList = uniqueList;
            bufferList = uniqueList.slice(12);
            hasMore = uniqueList.length >= 10;
            
            $('#hotRow').innerHTML = uniqueList.slice(0, 12).map((item, i) => renderVCard(item, i)).join('');
            
            saveCurrentViewState();
        }

        async function consumeBufferOrLoadMore() {
            if (isLoading) return;
            
            if (bufferList.length >= 3) {
                const chunk = bufferList.splice(0, 3);
                const startIdx = videoList.length;
                videoList = videoList.concat(chunk);
                
                const html = chunk.map((item, i) => renderRecCard(item, startIdx + i)).join('');
                
                if (loadContext.type === 'home') {
                    $('#hotRowMore').insertAdjacentHTML('beforeend', html);
                } else if (loadContext.type === 'category') {
                    $('#categoryGridMore').insertAdjacentHTML('beforeend', html);
                } else if (loadContext.type === 'search') {
                    $('#searchGridMore').insertAdjacentHTML('beforeend', html);
                }
                return;
            }
            
            if (!hasMore) return;
            
            isLoading = true;
            loadContext.page++;
            
            const loadingHtml = '<div id="bottomLoading">' + shimmerRecCard(3) + '</div>';
            if (loadContext.type === 'home') $('#hotRowMore').insertAdjacentHTML('beforeend', loadingHtml);
            else if (loadContext.type === 'category') $('#categoryGridMore').insertAdjacentHTML('beforeend', loadingHtml);
            else if (loadContext.type === 'search') $('#searchGridMore').insertAdjacentHTML('beforeend', loadingHtml);
            
            let newList = [];
            if (loadContext.type === 'home') {
                const hotWords = [
                    '斗罗大陆','完美世界','斗破苍穹','吞噬星空','神印王座',
                    '一念永恒','凡人修仙传','遮天','仙逆','狐妖小红娘',
                    '流浪地球','封神','哪吒','满江红','热辣滚烫',
                    '飞驰人生','第二十条','熊出没','奥本海默','芭比'
                ];
                const shuffled = hotWords.sort(() => 0.5 - Math.random()).slice(0, 5);
                newList = await batchSearch(shuffled, loadContext.page, 'home');
            } else if (loadContext.type === 'category') {
                const words = CATEGORY_WORDS[loadContext.category] || [loadContext.category];
                newList = await batchSearch(words, loadContext.page, loadContext.category);
            } else if (loadContext.type === 'search') {
                const data = await fetchApiData(loadContext.keyword, loadContext.page);
                if (data?.list) newList = data.list;
            }
            
            const bl = $('#bottomLoading');
            if (bl) bl.remove();
            
            isLoading = false;
            
            if (newList.length === 0) {
                hasMore = false;
                return;
            }
            
            const seenIds = new Set(videoList.map(v => v.vod_id).filter(Boolean));
            const seenNames = new Set(videoList.map(v => v.vod_name).filter(Boolean));
            const filtered = newList.filter(v => {
                if (v.vod_id && seenIds.has(v.vod_id)) return false;
                if (v.vod_name && seenNames.has(v.vod_name)) return false;
                if (v.vod_id) seenIds.add(v.vod_id);
                if (v.vod_name) seenNames.add(v.vod_name);
                return true;
            });
            if (filtered.length === 0) {
                hasMore = false;
                return;
            }
            
            bufferList = bufferList.concat(filtered);
            consumeBufferOrLoadMore();
        }

        function setupInfiniteScroll() {
            window.addEventListener('scroll', () => {
                if (isLoading || !hasMore) return;
                const scrollBottom = (window.innerHeight + window.scrollY) >= (document.documentElement.scrollHeight - 400);
                if (!scrollBottom) return;
                consumeBufferOrLoadMore();
            });
        }

        window.switchHotTab = function(el, type) {
            document.querySelectorAll('.section-tabs span').forEach(s => s.classList.remove('active'));
            el.classList.add('active');
            const shuffled = [...videoList].sort(() => Math.random() - 0.5).slice(0, 12);
            $('#hotRow').innerHTML = shuffled.map((item, i) => renderVCard(item, i)).join('');
        };

        window.shuffleHot = function() {
            const shuffled = [...videoList].sort(() => Math.random() - 0.5).slice(0, 12);
            $('#hotRow').innerHTML = shuffled.map((item, i) => renderVCard(item, i)).join('');
        };

        function play(url, epName, title) {
            if (!url) { toast('链接无效'); return; }
            currentEp = episodes.findIndex(e => e.link === url);
            if (currentEp < 0) currentEp = 0;

            $('#playerPage').classList.add('show');
            $('#topTitle').textContent = title ? `${title} ${epName}` : epName;
            $('#sidebarTitle').textContent = title || '未知';
            document.body.style.overflow = 'hidden';

            $('#sidebarArea').textContent = detailData?.vod_area || '内地';
            $('#sidebarYear').textContent = (detailData?.vod_year || '2023');
            $('#sidebarType').textContent = (detailData?.vod_class || '奇幻冒险');
            $('#sidebarScore').textContent = (Math.random() * 2 + 7).toFixed(1);
            $('#sidebarCount').textContent = Math.floor(Math.random() * 50000 + 5000);
            $('#sidebarUpdate').textContent = `更新至${episodes.length}集/全${episodes.length + Math.floor(Math.random()*10)}集`;

            renderSidebarEps();
            loadVideo(url);
            startDanmu();
            if (!controlsInited) { setupControls(); controlsInited = true; }

            if (proxyMode === 'free' && !proxyWarningShown) {
                showProxyWarning();
            }
        }

        function renderSidebarEps() {
            if (episodes.length === 0) {
                $('#epGrid').innerHTML = '<p style="color:#666;font-size:13px;">暂无选集</p>';
                $('#epGroups').innerHTML = '';
                return;
            }
            const groups = [];
            for (let i = 0; i < episodes.length; i += 30) groups.push(`${i+1}-${Math.min(i+30, episodes.length)}`);
            $('#epGroups').innerHTML = groups.map((g, i) => `<span class="${i===0?'active':''}" onclick="switchEpGroup(this,${i})">${g}</span>`).join('');
            renderEpGrid(0);
        }

        window.switchEpGroup = function(el, gi) {
            $('#epGroups').querySelectorAll('span').forEach(s => s.classList.remove('active'));
            el.classList.add('active');
            renderEpGrid(gi);
        };

        window.switchEpTab = function(el, tab) {
            $('.ep-tabs').querySelectorAll('span').forEach(s => s.classList.remove('active'));
            el.classList.add('active');
            if (tab === 'rec') {
                $('#epTabContent').innerHTML = '<div style="color:#666;text-align:center;padding:40px;font-size:13px;">相关推荐功能开发中</div>';
            } else {
                $('#epTabContent').innerHTML = '<div class="ep-groups" id="epGroups"></div><div class="ep-grid" id="epGrid"></div>';
                renderSidebarEps();
            }
        };

        function renderEpGrid(gi) {
            const start = gi * 30;
            const end = Math.min(start + 30, episodes.length);
            $('#epGrid').innerHTML = episodes.slice(start, end).map((ep, i) => {
                const ri = start + i;
                const active = ri === currentEp;
                return `
                <div class="ep-grid-item${active?' active':''}" data-idx="${ri}" onclick="playEpByIdx(${ri})">
                    <span class="ep-num">${ri+1}</span>
                </div>`;
            }).join('');
        }

        window.playEpByIdx = function(idx) {
            if (idx >= 0 && idx < episodes.length) {
                currentEp = idx;
                const ep = episodes[idx];
                $('#topTitle').textContent = $('#sidebarTitle').textContent + ' ' + ep.name;
                loadVideo(ep.link);
                renderEpGrid(Math.floor(idx / 30));
                $('#epGrid').querySelectorAll('.ep-grid-item').forEach(it => it.classList.toggle('active', parseInt(it.dataset.idx) === idx));
            }
        };

        function getQualityLabel(level) {
            if (!level) return '自动';
            const w = level.width || 0;
            const h = level.height || 0;
            const px = Math.max(w, h);
            if (px >= 2160 || h >= 2160) return '4K';
            if (px >= 1440 || h >= 1440) return '2K';
            if (px >= 1080 || h >= 1080) return '1080P';
            if (px >= 720 || h >= 720) return '720P';
            if (px >= 480 || h >= 480) return '480P';
            return '360P';
        }

        function buildQualityMenu() {
            const menu = $('#qualityMenu');
            const btn = $('#txQualityBtn');
            if (!menu || !btn) return;
            menu.innerHTML = '';
            if (!hls || !hls.levels || hls.levels.length === 0) {
                btn.innerHTML = '自动';
                return;
            }
            if (hls.levels.length === 1) {
                const label = getQualityLabel(hls.levels[0]);
                btn.innerHTML = label;
                const singleItem = document.createElement('button');
                singleItem.className = 'tx-menu-item active';
                singleItem.dataset.level = '0';
                singleItem.textContent = label;
                menu.appendChild(singleItem);
                return;
            }
            const autoItem = document.createElement('button');
            autoItem.className = 'tx-menu-item' + (currentQuality === -1 ? ' active' : '');
            autoItem.dataset.level = '-1';
            autoItem.textContent = '自动';
            menu.appendChild(autoItem);

            hls.levels.forEach((level, idx) => {
                const label = getQualityLabel(level);
                const item = document.createElement('button');
                item.className = 'tx-menu-item' + (currentQuality === idx ? ' active' : '');
                item.dataset.level = String(idx);
                item.textContent = label;
                menu.appendChild(item);
            });

            const activeLabel = currentQuality === -1 ? '自动' : getQualityLabel(hls.levels[currentQuality]);
            btn.innerHTML = activeLabel;

            menu.querySelectorAll('.tx-menu-item').forEach(item => {
                item.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const lvl = parseInt(item.dataset.level);
                    hls.currentLevel = lvl;
                    currentQuality = lvl;
                    const newLabel = lvl === -1 ? '自动' : getQualityLabel(hls.levels[lvl]);
                    btn.innerHTML = newLabel;
                    menu.querySelectorAll('.tx-menu-item').forEach(i => i.classList.remove('active'));
                    item.classList.add('active');
                    toast('清晰度已切换为: ' + newLabel);
                    menu.classList.remove('show');
                });
            });
        }

        function loadVideo(url) {
            $('#playerLoading').classList.add('show');
            if (hls) { hls.destroy(); hls = null; }
            videoEl.pause();
            videoEl.removeAttribute('src');
            videoEl.load();

            const decoded = decodeURIComponent(url);
            currentVideoUrl = decoded;
            
            const hideLoading = () => $('#playerLoading').classList.remove('show');
            const onCanPlayOnce = () => { hideLoading(); videoEl.removeEventListener('canplay', onCanPlayOnce); };
            videoEl.addEventListener('canplay', onCanPlayOnce);
            
            if (isM3U8(decoded) && typeof Hls !== 'undefined' && Hls.isSupported()) {
                hls = new Hls({ enableWorker: true, startLevel: -1, capLevelToPlayerSize: false });
                hls.loadSource(decoded);
                hls.attachMedia(videoEl);
                hls.on(Hls.Events.MANIFEST_PARSED, () => {
                    hideLoading();
                    currentQuality = -1;
                    buildQualityMenu();
                    videoEl.play().catch(() => {});
                    isPlaying = true;
                    syncPlayState();
                });
                hls.on(Hls.Events.LEVEL_SWITCHED, () => {
                    const btn = $('#txQualityBtn');
                    if (!btn || !hls || !hls.levels) return;
                    const lvl = hls.currentLevel;
                    if (currentQuality === -1 && lvl >= 0) {
                        btn.innerHTML = '自动(' + getQualityLabel(hls.levels[lvl]) + ')';
                    }
                });
                hls.on(Hls.Events.ERROR, (e, d) => { 
                    if (d.fatal) { 
                        hideLoading();
                        toast('播放失败，尝试备用方案'); 
                        tryIframe(decoded); 
                    } 
                });
            } else {
                const btn = $('#txQualityBtn');
                const menu = $('#qualityMenu');
                if (btn) btn.innerHTML = '自动';
                if (menu) {
                    menu.innerHTML = '';
                    const autoItem = document.createElement('button');
                    autoItem.className = 'tx-menu-item active';
                    autoItem.textContent = '自动';
                    menu.appendChild(autoItem);
                }
                videoEl.src = decoded;
                videoEl.play().catch(() => {});
                isPlaying = true;
                syncPlayState();
            }
        }

        function applyQualityLevel() {
            if (!hls || !hls.levels || hls.levels.length <= 1) return;
            hls.currentLevel = currentQuality;
        }

        function tryIframe(url) {
            $('#playerLoading').classList.remove('show');
            if (hls) { hls.destroy(); hls = null; }
            $('#videoWrap').innerHTML = `<iframe src="${safe(url)}" allow="autoplay; encrypted-media; fullscreen" allowfullscreen style="width:100%;height:100%;border:none;"></iframe><div class="video-watermark">雅泫视频</div>`;
            isPlaying = true;
            syncPlayState();
        }

        window.closePlayer = function() {
            stopDanmu();
            if (hls) { hls.destroy(); hls = null; }
            videoEl.pause();
            videoEl.removeAttribute('src');
            videoEl.load();
            $('#playerLoading').classList.remove('show');
            $('#playerPage').classList.remove('show');
            $('#playerPage').classList.remove('fullscreen');
            document.body.style.overflow = '';
            isPlaying = false;
            syncPlayState();
            if (!$('#videoEl')) {
                $('#videoWrap').innerHTML = `
                    <video id="videoEl" playsinline webkit-playsinline></video>
                    <div class="video-watermark">雅泫视频</div>
                    <div class="player-loading" id="playerLoading">
                        <div class="loading-dots">
                            <div></div><div></div><div></div>
                        </div>
                        <div class="loading-text">正在缓冲，请耐心等待</div>
                    </div>
                    <div class="danmu-layer" id="danmuLayer"></div>
                    <div class="center-play-btn" id="centerPlayBtn"><i class="fas fa-play" id="centerPlayIcon"></i></div>
                    <div class="player-top-bar">
                        <div class="player-logo" onclick="closePlayer(); showHome();">
                            <img src="image/tx.png" alt="雅泫视频">
                            <span>雅泫视频</span>
                        </div>
                        <div class="player-search">
                            <input type="text" id="playerSearchInput" placeholder="搜索视频..." autocomplete="off">
                            <button id="playerSearchBtn"><i class="fas fa-search"></i></button>
                        </div>
                        <span class="video-title" id="topTitle">正在播放</span>
                        <button class="back-btn" onclick="closePlayer()"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="tx-controls">...</div>
                `;
                videoEl = document.getElementById('videoEl');
                videoEl.addEventListener('waiting', () => $('#playerLoading').classList.add('show'));
                videoEl.addEventListener('playing', () => $('#playerLoading').classList.remove('show'));
                videoEl.addEventListener('error', () => $('#playerLoading').classList.remove('show'));
            }
        };

        function setupFullscreenListener() {
            ['fullscreenchange','webkitfullscreenchange','mozfullscreenchange','MSFullscreenChange'].forEach(evt => {
                document.addEventListener(evt, () => {
                    const isFull = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
                    $('#playerPage').classList.toggle('fullscreen', isFull);
                });
            });
        }

        function setupControls() {
            const playBtn = $('#txPlayBtn');
            const progressArea = $('#txProgressArea');
            const played = $('#txPlayed');
            const buffer = $('#txBuffer');
            const time = $('#txTime');
            const tooltip = $('#txTooltip');
            const fullscreen = $('#txFullscreenBtn');
            const next = $('#txNextBtn');
            const danmuToggle = $('#txDanmuToggle');
            const pip = $('#txPip');
            const videoWrap = $('#videoWrap');
            const centerBtn = $('#centerPlayBtn');
            const danmuLayer = $('#danmuLayer');

            let wasPlayingBeforeDrag = false;
            let ignoreNextClick = false;
            let lastDragX = 0;

            const updateProgressUI = (clientX) => {
                lastDragX = clientX;
                const rect = progressArea.getBoundingClientRect();
                const pct = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
                played.style.width = (pct * 100) + '%';
                tooltip.style.left = (pct * 100) + '%';
                if (videoEl.duration) tooltip.textContent = formatTime(pct * videoEl.duration);
            };

            const seekToProgress = (clientX) => {
                const rect = progressArea.getBoundingClientRect();
                const pct = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
                if (videoEl.duration && !isNaN(videoEl.duration)) {
                    videoEl.currentTime = pct * videoEl.duration;
                    played.style.width = (pct * 100) + '%';
                }
            };

            progressArea.addEventListener('mousedown', (e) => {
                wasPlayingBeforeDrag = !videoEl.paused && !videoEl.ended;
                isDraggingProgress = true;
                progressArea.classList.add('dragging');
                updateProgressUI(e.clientX);
                e.preventDefault();
            });

            progressArea.addEventListener('mousemove', (e) => {
                const rect = progressArea.getBoundingClientRect();
                const pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
                tooltip.style.left = (pct * 100) + '%';
                if (videoEl.duration) tooltip.textContent = formatTime(pct * videoEl.duration);
                if (isDraggingProgress) updateProgressUI(e.clientX);
            });

            document.addEventListener('mousemove', (e) => { 
                if (isDraggingProgress) updateProgressUI(e.clientX); 
            });

            document.addEventListener('mouseup', (e) => { 
                if (isDraggingProgress) { 
                    isDraggingProgress = false; 
                    progressArea.classList.remove('dragging');
                    ignoreNextClick = true;
                    setTimeout(() => ignoreNextClick = false, 80);
                    const rect = progressArea.getBoundingClientRect();
                    const pct = Math.max(0, Math.min(1, (lastDragX - rect.left) / rect.width));
                    if (videoEl.duration && !isNaN(videoEl.duration)) {
                        videoEl.currentTime = pct * videoEl.duration;
                        played.style.width = (pct * 100) + '%';
                    }
                    if (wasPlayingBeforeDrag) {
                        videoEl.play().catch(() => {});
                    }
                } 
            });

            progressArea.addEventListener('click', (e) => { 
                e.stopPropagation(); 
                if (ignoreNextClick) return;
                if (!isDraggingProgress) {
                    const wasPlaying = !videoEl.paused && !videoEl.ended;
                    seekToProgress(e.clientX);
                    if (wasPlaying) videoEl.play().catch(() => {});
                }
            });

            const doTogglePlay = () => {
                const v = document.getElementById('videoEl');
                if (!v) return;
                if (v.paused) {
                    v.play().then(() => { isPlaying = true; syncPlayState(); }).catch(() => {});
                } else {
                    v.pause();
                    isPlaying = false;
                    syncPlayState();
                }
            };

            window.syncPlayState = function() {
                const v = document.getElementById('videoEl');
                const ap = v && !v.paused && !v.ended;
                isPlaying = ap;
                const icon = isPlaying ? 'fa-pause' : 'fa-play';
                $('#txPlayBtn').innerHTML = `<i class="fas ${icon}"></i>`;
                const ci = $('#centerPlayIcon');
                if (ci) ci.className = `fas ${icon}`;
                if (isPlaying) {
                    videoWrap.classList.remove('paused');
                    centerBtn.classList.remove('show');
                    danmuLayer.classList.remove('paused');
                } else {
                    videoWrap.classList.add('paused');
                    centerBtn.classList.add('show');
                    danmuLayer.classList.add('paused');
                }
            };

            playBtn.addEventListener('click', (e) => { e.stopPropagation(); doTogglePlay(); });
            videoWrap.addEventListener('click', (e) => {
                if (e.target.closest('.tx-controls') || e.target.closest('.player-top-bar') || e.target.closest('.tx-menu') || e.target.closest('.volume-panel') || e.target.closest('.settings-panel') || e.target.closest('.center-play-btn')) return;
                doTogglePlay();
            });
            centerBtn.addEventListener('click', (e) => { e.stopPropagation(); doTogglePlay(); });

            videoEl.addEventListener('play', () => syncPlayState());
            videoEl.addEventListener('pause', () => syncPlayState());
            videoEl.addEventListener('ended', () => {
                if (autoPlayNext && currentEp < episodes.length - 1) {
                    toast('自动连播下一集');
                    setTimeout(() => playEpByIdx(currentEp + 1), 1500);
                } else if (loopPlay) {
                    toast('洗脑循环中');
                    videoEl.currentTime = 0;
                    videoEl.play().catch(() => {});
                } else {
                    syncPlayState();
                }
            });

            videoEl.addEventListener('timeupdate', () => {
                if (!isDraggingProgress && videoEl.duration) {
                    played.style.width = ((videoEl.currentTime / videoEl.duration) * 100) + '%';
                    time.textContent = formatTime(videoEl.currentTime) + ' / ' + formatTime(videoEl.duration);
                }
                if (videoEl.buffered.length > 0 && videoEl.duration) {
                    buffer.style.width = (videoEl.buffered.end(videoEl.buffered.length - 1) / videoEl.duration * 100) + '%';
                }
            });

            fullscreen.addEventListener('click', (e) => {
                e.stopPropagation();
                if (!document.fullscreenElement) $('#playerPage').requestFullscreen().catch(() => {});
                else document.exitFullscreen();
            });

            next.addEventListener('click', (e) => {
                e.stopPropagation();
                if (currentEp < episodes.length - 1) playEpByIdx(currentEp + 1);
                else toast('已经是最后一集了');
            });

            const danmuOpenSvg = `<svg width="24" height="24" viewBox="-2,-2,28,28"><path d="M15.3468 7C15.6522 7 15.905 7.22505 15.9484 7.51835L15.955 7.60823L15.9549 8.01653L16.2911 8.01726C16.5965 8.01726 16.8493 8.24231 16.8928 8.5356L16.8994 8.62548V12.463C16.8994 12.7989 16.6271 13.0713 16.2911 13.0713L14.4101 13.0707V13.7582L16.2047 13.7588C16.5406 13.7588 16.8129 14.0311 16.8129 14.367C16.8129 14.6724 16.5878 14.9252 16.2946 14.9687L16.2047 14.9753L14.4101 14.9748L14.4104 15.9871C14.4104 16.323 14.1381 16.5953 13.8022 16.5953C13.4968 16.5953 13.244 16.3703 13.2006 16.077L13.194 15.9871L13.1935 14.9748L11.3133 14.9753C10.9773 14.9753 10.705 14.703 10.705 14.367C10.705 14.0617 10.9301 13.8089 11.2234 13.7654L11.3133 13.7588L13.1935 13.7582V13.0707L11.3133 13.0713C11.0079 13.0713 10.7551 12.8462 10.7116 12.5529L10.705 12.463V8.62548C10.705 8.28957 10.9773 8.01726 11.3133 8.01726L11.9315 8.01653L11.932 7.60823C11.932 7.27231 12.2043 7 12.5402 7C12.8456 7 13.0984 7.22505 13.1419 7.51835L13.1485 7.60823L13.1481 8.01653H14.7383L14.7386 7.60823C14.7386 7.27231 15.0109 7 15.3468 7ZM9.40777 7.41055C9.71315 7.41055 9.96596 7.6356 10.0094 7.9289L10.016 8.01878V10.5173C10.016 10.8227 9.79095 11.0755 9.49765 11.119L9.40777 11.1256L7.68831 11.1255V12.2097L9.40777 12.21C9.71315 12.21 9.96596 12.4351 10.0094 12.7284L10.016 12.8183V15.2084C10.016 15.3812 9.94252 15.5458 9.81391 15.6612C9.32173 16.1027 8.42975 16.2805 7.08088 16.2805C6.74497 16.2805 6.47266 16.0082 6.47266 15.6723C6.47266 15.3364 6.74497 15.064 7.08088 15.064C7.86129 15.064 8.42395 14.9934 8.74634 14.8859L8.79911 14.8661V13.4263L7.08088 13.4265C6.77551 13.4265 6.52269 13.2014 6.47925 12.9081L6.47266 12.8183V10.5173C6.47266 10.212 6.69771 9.95916 6.991 9.91571L7.08088 9.90912L8.79911 9.90891V8.62646L7.08088 8.627C6.77551 8.627 6.52269 8.40195 6.47925 8.10866L6.47266 8.01878C6.47266 7.7134 6.69771 7.46059 6.991 7.41715L7.08088 7.41055H9.40777ZM13.1935 11.1524H11.9199L11.9207 11.8539L13.1935 11.8532V11.1524ZM15.6819 11.1524H14.4101V11.8532L15.6827 11.8539L15.6819 11.1524ZM13.1935 9.23227L11.9207 9.233L11.9199 9.93574H13.1935V9.23227ZM15.6827 9.233L14.4101 9.23227V9.93574H15.6819L15.6827 9.233Z" fill="#fff"></path><path d="M17.8763 16.4209H22.7763C24.5804 16.4209 26.043 17.8834 26.043 19.6876C26.043 21.4917 24.5804 22.9542 22.7763 22.9542H17.8763C16.0722 22.9542 14.6096 21.4917 14.6096 19.6876C14.6096 17.8834 16.0722 16.4209 17.8763 16.4209Z" fill="#14A3FF"></path><path d="M19.9201 19.6874C19.9201 21.2661 21.1997 22.5458 22.7784 22.5458C24.3571 22.5458 25.6367 21.2661 25.6367 19.6874C25.6367 18.1088 24.3571 16.8291 22.7784 16.8291C21.1997 16.8291 19.9201 18.1088 19.9201 19.6874Z" fill="#fff"></path><path d="M11.375 21H4.375C2.92525 21 1.75 19.8247 1.75 18.375V5.25C1.75 3.80025 2.92525 2.625 4.375 2.625H19.125C20.5747 2.625 21.75 3.80025 21.75 5.25V13.125" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none"></path></svg>`;
            
            const danmuCloseSvg = `<svg width="24" height="24" viewBox="-2,-2,28,28"><path d="M15.3468 7C15.6522 7 15.905 7.22505 15.9484 7.51835L15.955 7.60823L15.9549 8.01653L16.2911 8.01726C16.5965 8.01726 16.8493 8.24231 16.8928 8.5356L16.8994 8.62548V12.463C16.8994 12.7989 16.6271 13.0713 16.2911 13.0713L14.4101 13.0707V13.7582L16.2047 13.7588C16.5406 13.7588 16.8129 14.0311 16.8129 14.367C16.8129 14.6724 16.5878 14.9252 16.2946 14.9687L16.2047 14.9753L14.4101 14.9748L14.4104 15.9871C14.4104 16.323 14.1381 16.5953 13.8022 16.5953C13.4968 16.5953 13.244 16.3703 13.2006 16.077L13.194 15.9871L13.1935 14.9748L11.3133 14.9753C10.9773 14.9753 10.705 14.703 10.705 14.367C10.705 14.0617 10.9301 13.8089 11.2234 13.7654L11.3133 13.7588L13.1935 13.7582V13.0707L11.3133 13.0713C11.0079 13.0713 10.7551 12.8462 10.7116 12.5529L10.705 12.463V8.62548C10.705 8.28957 10.9773 8.01726 11.3133 8.01726L11.9315 8.01653L11.932 7.60823C11.932 7.27231 12.2043 7 12.5402 7C12.8456 7 13.0984 7.22505 13.1419 7.51835L13.1485 7.60823L13.1481 8.01653H14.7383L14.7386 7.60823C14.7386 7.27231 15.0109 7 15.3468 7ZM9.40777 7.41055C9.71315 7.41055 9.96596 7.6356 10.0094 7.9289L10.016 8.01878V10.5173C10.016 10.8227 9.79095 11.0755 9.49765 11.119L9.40777 11.1256L7.68831 11.1255V12.2097L9.40777 12.21C9.71315 12.21 9.96596 12.4351 10.0094 12.7284L10.016 12.8183V15.2084C10.016 15.3812 9.94252 15.5458 9.81391 15.6612C9.32173 16.1027 8.42975 16.2805 7.08088 16.2805C6.74497 16.2805 6.47266 16.0082 6.47266 15.6723C6.47266 15.3364 6.74497 15.064 7.08088 15.064C7.86129 15.064 8.42395 14.9934 8.74634 14.8859L8.79911 14.8661V13.4263L7.08088 13.4265C6.77551 13.4265 6.52269 13.2014 6.47925 12.9081L6.47266 12.8183V10.5173C6.47266 10.212 6.69771 9.95916 6.991 9.91571L7.08088 9.90912L8.79911 9.90891V8.62646L7.08088 8.627C6.77551 8.627 6.52269 8.40195 6.47925 8.10866L6.47266 8.01878C6.47266 7.7134 6.69771 7.46059 6.991 7.41715L7.08088 7.41055H9.40777ZM13.1935 11.1524H11.9199L11.9207 11.8539L13.1935 11.8532V11.1524ZM15.6819 11.1524H14.4101V11.8532L15.6827 11.8539L15.6819 11.1524ZM13.1935 9.23227L11.9207 9.233L11.9199 9.93574H13.1935V9.23227ZM15.6827 9.233L14.4101 9.23227V9.93574H15.6819L15.6827 9.233Z" fill="#fff"></path><path d="M17.8763 16.4209H22.7763C24.5804 16.4209 26.043 17.8834 26.043 19.6876C26.043 21.4917 24.5804 22.9542 22.7763 22.9542H17.8763C16.0722 22.9542 14.6096 21.4917 14.6096 19.6876C14.6096 17.8834 16.0722 16.4209 17.8763 16.4209Z" fill="#707070"></path><path d="M15.0236 19.6874C15.0236 21.2661 16.3032 22.5458 17.8819 22.5458C19.4606 22.5458 20.7402 21.2661 20.7402 19.6874C20.7402 18.1088 19.4606 16.8291 17.8819 16.8291C16.3032 16.8291 15.0236 18.1088 15.0236 19.6874Z" fill="#fff"></path><path d="M11.375 21H4.375C2.92525 21 1.75 19.8247 1.75 18.375V5.25C1.75 3.80025 2.92525 2.625 4.375 2.625H19.125C20.5747 2.625 21.75 3.80025 21.75 5.25V13.125" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"></path></svg>`;

            const updateDanmuIcon = () => {
                const wrap = $('#danmuToggleIcon');
                if (wrap) wrap.innerHTML = danmuEnabled ? danmuOpenSvg : danmuCloseSvg;
            };
            updateDanmuIcon();

            danmuToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                danmuEnabled = !danmuEnabled;
                danmuLayer.style.display = danmuEnabled ? 'block' : 'none';
                updateDanmuIcon();
                toast(danmuEnabled ? '弹幕已开启' : '弹幕已关闭');
            });

            pip.addEventListener('click', (e) => {
                e.stopPropagation();
                if (document.pictureInPictureEnabled) {
                    if (document.pictureInPictureElement) document.exitPictureInPicture();
                    else videoEl.requestPictureInPicture().catch(() => toast('画中画失败'));
                } else toast('浏览器不支持画中画');
            });

            $('#danmuInput').addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    const text = e.target.value.trim();
                    if (text) { shootDanmu(text); e.target.value = ''; }
                }
            });

            const speedBtn = $('#txSpeedBtn');
            const speedMenu = $('#speedMenu');
            speedBtn.addEventListener('click', (e) => { 
                e.stopPropagation(); 
                const isShowing = speedMenu.classList.contains('show');
                closeAllMenus(); 
                if (!isShowing) speedMenu.classList.add('show');
            });

            speedMenu.querySelectorAll('.tx-menu-item').forEach(item => {
                item.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const s = parseFloat(item.dataset.s);
                    videoEl.playbackRate = s;
                    currentSpeed = s;
                    speedMenu.querySelectorAll('.tx-menu-item').forEach(i => i.classList.remove('active'));
                    item.classList.add('active');
                    speedBtn.innerHTML = s === 1.0 ? '倍速' : s + 'x';
                    toast('播放速度: ' + s + 'x');
                    speedMenu.classList.remove('show');
                });
            });

            const qualityBtn = $('#txQualityBtn');
            const qualityMenu = $('#qualityMenu');
            qualityBtn.addEventListener('click', (e) => { 
                e.stopPropagation(); 
                const isShowing = qualityMenu.classList.contains('show');
                closeAllMenus(); 
                if (!isShowing) qualityMenu.classList.add('show');
            });

            const volumeBtn = $('#txVolumeBtn');
            const volumePanel = $('#volumePanel');
            const volumeTrack = $('#volumeTrack');
            const volumeFill = $('#volumeFill');
            const volumeNum = $('#volumeNum');
            const volumeMuteBtn = $('#volumeMuteBtn');
            let isDraggingVolume = false;

            const updateVolumeUI = (vol) => {
                const pct = Math.max(0, Math.min(100, vol * 100));
                volumeFill.style.height = pct + '%';
                volumeNum.textContent = Math.round(pct);
                videoEl.volume = vol;
                let icon = 'fa-volume-up';
                if (videoEl.muted || vol === 0) icon = 'fa-volume-mute';
                else if (vol < 0.3) icon = 'fa-volume-off';
                else if (vol < 0.6) icon = 'fa-volume-down';
                volumeBtn.innerHTML = `<i class="fas ${icon}"></i>`;
                volumeMuteBtn.innerHTML = `<i class="fas ${icon}"></i>`;
            };
            updateVolumeUI(videoEl.volume || 0.7);

            volumeBtn.addEventListener('click', (e) => { 
                e.stopPropagation(); 
                const isShowing = volumePanel.classList.contains('show');
                closeAllMenus(); 
                if (!isShowing) volumePanel.classList.add('show');
            });

            const setVolumeFromY = (clientY) => {
                const rect = volumeTrack.getBoundingClientRect();
                const pct = 1 - Math.max(0, Math.min(1, (clientY - rect.top) / rect.height));
                updateVolumeUI(pct);
            };
            volumeTrack.addEventListener('mousedown', (e) => { isDraggingVolume = true; setVolumeFromY(e.clientY); });
            document.addEventListener('mousemove', (e) => { if (isDraggingVolume) setVolumeFromY(e.clientY); });
            document.addEventListener('mouseup', () => { isDraggingVolume = false; });
            volumeMuteBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                videoEl.muted = !videoEl.muted;
                if (videoEl.muted) {
                    volumeBtn.innerHTML = '<i class="fas fa-volume-mute"></i>';
                    volumeMuteBtn.innerHTML = '<i class="fas fa-volume-mute"></i>';
                    volumeFill.style.height = '0%';
                    volumeNum.textContent = '0';
                } else updateVolumeUI(videoEl.volume || 0.7);
            });

            $('#txSetting').addEventListener('click', (e) => { 
                e.stopPropagation(); 
                const isShowing = $('#settingsPanel').classList.contains('show');
                closeAllMenus(); 
                if (!isShowing) $('#settingsPanel').classList.add('show');
            });

            $('#introBtn').addEventListener('click', (e) => { e.stopPropagation(); openIntro(); });
            document.addEventListener('click', () => closeAllMenus());
        }

        function closeAllMenus() {
            $('#qualityMenu').classList.remove('show');
            $('#speedMenu').classList.remove('show');
            $('#volumePanel').classList.remove('show');
            $('#settingsPanel').classList.remove('show');
        }

        function formatTime(s) {
            if (!s || isNaN(s)) return '00:00';
            const m = Math.floor(s / 60);
            const sec = Math.floor(s % 60);
            return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
        }

        window.toggleSetting = function(type) {
            const autoSwitch = $('#switchAuto');
            const loopSwitch = $('#switchLoop');
            if (type === 'auto') {
                if (!autoPlayNext) {
                    autoPlayNext = true; loopPlay = false;
                    autoSwitch.classList.add('on');
                    loopSwitch.classList.remove('on');
                    toast('已开启自动连播');
                } else { autoPlayNext = false; autoSwitch.classList.remove('on'); }
            } else if (type === 'loop') {
                if (!loopPlay) {
                    loopPlay = true; autoPlayNext = false;
                    loopSwitch.classList.add('on');
                    autoSwitch.classList.remove('on');
                    toast('已开启洗脑循环');
                } else { loopPlay = false; loopSwitch.classList.remove('on'); }
            }
        };

        window.downloadCurrent = function() {
            let url = videoEl.src;
            if (!url && hls && hls.url) url = hls.url;
            if (!url) { toast('暂无下载链接'); return; }
            const a = document.createElement('a');
            a.href = url;
            const qualityLabel = (hls && hls.levels && currentQuality >= 0) ? getQualityLabel(hls.levels[currentQuality]) : '自动';
            a.download = ($('#sidebarTitle').textContent || 'video') + '_' + qualityLabel + '.mp4';
            document.body.appendChild(a);
            a.click();
            a.remove();
            toast('开始下载当前视频');
        };

        function openIntro() {
            const title = $('#sidebarTitle').textContent || '简介';
            $('#introPanelTitle').textContent = title;
            const meta = [];
            if (detailData) {
                if (detailData.vod_area) meta.push(detailData.vod_area);
                if (detailData.vod_year) meta.push(detailData.vod_year);
                if (detailData.vod_class) meta.push(detailData.vod_class);
            }
            $('#introMeta').textContent = meta.join(' · ') || '暂无信息';
            $('#introContent').textContent = cleanTags(detailData?.vod_content) || '暂无简介';
            $('#introOverlay').classList.add('show');
        }

        window.closeIntro = function() {
            $('#introOverlay').classList.remove('show');
        };

        $('#introOverlay').addEventListener('click', (e) => { if (e.target === $('#introOverlay')) closeIntro(); });

        let danmuTimer = null;
        function startDanmu() {
            stopDanmu();
            $('#danmuLayer').innerHTML = '';
            danmuTimer = setInterval(() => {
                if (!danmuEnabled) return;
                if (Math.random() > 0.6) return;
                shootDanmu(danmuList[Math.floor(Math.random() * danmuList.length)]);
            }, 1500);
        }
        function stopDanmu() { if (danmuTimer) clearInterval(danmuTimer); $('#danmuLayer').innerHTML = ''; }
        function shootDanmu(text) {
            const layer = $('#danmuLayer');
            const item = document.createElement('div');
            item.className = 'danmu-item';
            item.textContent = text;
            item.style.top = Math.random() * 70 + 5 + '%';
            item.style.color = `hsl(${Math.random() * 360}, 80%, 75%)`;
            const dur = 8 + Math.random() * 6;
            item.style.animationDuration = dur + 's';
            layer.appendChild(item);
            setTimeout(() => item.remove(), dur * 1000);
        }

        /* ==================== 自定义画中画（功能完整版） ==================== */
        /* ==================== 画中画事件绑定（8方向拖拽+缩放） ==================== */
        /* ==================== 全局禁止复制 ==================== */
        document.addEventListener('copy', (e) => { if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return; e.preventDefault(); });
        document.addEventListener('cut', (e) => { if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return; e.preventDefault(); });
        document.addEventListener('contextmenu', (e) => { e.preventDefault(); });

        /* ==================== 事件绑定 ==================== */
        $('#searchBtn').addEventListener('click', () => {
            const val = $('#searchInput').value.trim();
            if (val) { $('#searchSuggestions').classList.remove('show'); initSearch(val); }
        });
        $('#searchInput').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const val = $('#searchInput').value.trim();
                if (val) { $('#searchSuggestions').classList.remove('show'); initSearch(val); }
            }
        });
        $('#searchInput').addEventListener('input', (e) => {
            const val = e.target.value.trim();
            clearTimeout(searchDebounceTimer);
            if (val.length >= 2) {
                searchDebounceTimer = setTimeout(() => updateSearchSuggestions(val), 400);
            } else {
                $('#searchSuggestions').classList.remove('show');
            }
        });
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.search-main')) {
                $('#searchSuggestions').classList.remove('show');
            }
        });

        $('#playerSearchBtn').addEventListener('click', () => {
            const val = $('#playerSearchInput').value.trim();
            if (val) {
                closePlayer();
                initSearch(val);
            }
        });
        $('#playerSearchInput').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const val = $('#playerSearchInput').value.trim();
                if (val) {
                    closePlayer();
                    initSearch(val);
                }
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if ($('#proxyModal').classList.contains('show')) { closeProxyModal(); return; }
                if ($('#introOverlay').classList.contains('show')) { closeIntro(); return; }
                if ($('#playerPage').classList.contains('show')) closePlayer();
            }
            if (e.key === ' ') {
                if ($('#playerPage').classList.contains('show')) { e.preventDefault(); $('#txPlayBtn').click(); }
            }
        });

        videoEl.addEventListener('waiting', () => $('#playerLoading').classList.add('show'));
        videoEl.addEventListener('playing', () => $('#playerLoading').classList.remove('show'));
        videoEl.addEventListener('error', () => $('#playerLoading').classList.remove('show'));

        setupFullscreenListener();
        setupInfiniteScroll();
        initHome();
    })();

    // ========== 用户认证与头像交互 ==========
    (function() {
        const userProfile = document.getElementById('userProfile');
        const userDropdown = document.getElementById('userDropdown');
        const logoutBtn = document.getElementById('logoutBtn');

        if (userProfile && userDropdown) {
            userProfile.addEventListener('click', function(e) {
                e.stopPropagation();
                userDropdown.classList.toggle('show');
            });
            document.addEventListener('click', function() {
                userDropdown.classList.remove('show');
            });
        }

        if (logoutBtn) {
            logoutBtn.addEventListener('click', function() {
                window.location.href = '?yx_action=logout';
            });
        }

        var overlay = document.createElement('div');
        overlay.className = 'yx-login-overlay hidden';
        overlay.id = 'yxLoginOverlay';
        overlay.innerHTML =
            '<div class="yx-auth-modal" id="yxAuthModalBox">' +
                '<div class="yx-modal-glow" id="yxModalGlow"></div>' +
                '<button class="yx-auth-close-btn" id="yxAuthCloseBtn">\u2715</button>' +
                '<div class="yx-modal-left">' +
                    '<div class="image-wrapper">' +
                        '<img src="/image/bg.png" alt="background">' +
                        '<div class="image-overlay"></div>' +
                    '</div>' +
                    '<div class="left-content">' +
                        '<h2>MoonYa</h2>' +
                        '<p>\u6781\u7b80\u7f8e\u5b66\uff0c\u6e29\u6da6\u901a\u900f</p>' +
                    '</div>' +
                '</div>' +
                '<div class="yx-modal-right">' +
                    '<div class="yx-auth-tabs">' +
                        '<button class="yx-auth-tab-btn active" data-tab="yx-sms">\u9a8c\u8bc1\u7801\u767b\u5f55</button>' +
                        '<button class="yx-auth-tab-btn" data-tab="yx-password">\u5bc6\u7801\u767b\u5f55</button>' +
                        '<button class="yx-auth-tab-btn" data-tab="yx-register">\u6ce8\u518c\u8d26\u53f7</button>' +
                    '</div>' +
                    '<div class="yx-auth-form-container">' +
                        '<form class="yx-auth-form active" id="yx-sms-form">' +
                            '<div class="input-group"><label>QQ号</label><div class="yx-qq-input-wrapper"><input type="text" id="yxLoginQQ" placeholder="\u8bf7\u8f93\u5165QQ\u53f7" maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,\'\')"><span class="yx-qq-suffix">@qq.com</span></div></div>' +
                            '<div class="input-group"><label>\u9a8c\u8bc1\u7801</label><div class="input-row"><input type="text" id="yxLoginCode" placeholder="\u8bf7\u8f93\u5165\u9a8c\u8bc1\u7801" maxlength="6"><button type="button" class="yx-auth-btn-secondary" id="yxLoginSendCodeBtn">\u83b7\u53d6\u9a8c\u8bc1\u7801</button></div></div>' +
                            '<button type="button" class="yx-auth-btn-primary" id="yxLoginByCodeSubmit">\u767b\u5f55</button>' +
                        '</form>' +
                        '<form class="yx-auth-form" id="yx-password-form">' +
                            '<div class="input-group"><label>\u8d26\u53f7</label><input type="text" id="yxLoginAccount" placeholder="\u8bf7\u8f93\u5165\u8d26\u53f7"></div>' +
                            '<div class="input-group"><label>\u5bc6\u7801</label><input type="password" id="yxLoginPassword" placeholder="\u8bf7\u8f93\u5165\u5bc6\u7801"></div>' +
                            '<button type="button" class="yx-auth-btn-primary" id="yxLoginSubmit">\u767b\u5f55</button>' +
                        '</form>' +
                        '<form class="yx-auth-form" id="yx-register-form">' +
                            '<div class="input-group"><label>QQ号</label><div class="yx-qq-input-wrapper"><input type="text" id="yxRegQQ" placeholder="\u8bf7\u8f93\u5165QQ\u53f7" maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,\'\')"><span class="yx-qq-suffix">@qq.com</span></div></div>' +
                            '<div class="input-group"><label>\u9a8c\u8bc1\u7801</label><div class="input-row"><input type="text" id="yxRegCode" placeholder="\u8bf7\u8f93\u5165\u9a8c\u8bc1\u7801" maxlength="6"><button type="button" class="yx-auth-btn-secondary" id="yxSendCodeBtn">\u83b7\u53d6\u9a8c\u8bc1\u7801</button></div></div>' +
                            '<button type="button" class="yx-auth-btn-primary" id="yxRegisterSubmit">\u6ce8\u518c</button>' +
                        '</form>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        var yxAuthModalBox = document.getElementById('yxAuthModalBox');
        if (yxAuthModalBox) {
            yxAuthModalBox.addEventListener('mousemove', function(e) {
                var rect = yxAuthModalBox.getBoundingClientRect();
                yxAuthModalBox.style.setProperty('--mouse-x', (e.clientX - rect.left) + 'px');
                yxAuthModalBox.style.setProperty('--mouse-y', (e.clientY - rect.top) + 'px');
            });
        }

        var yxAuthTabs = document.querySelectorAll('.yx-auth-tab-btn');
        yxAuthTabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                if (tab.classList.contains('active')) return;
                if (document.querySelector('.yx-auth-form.enter-from-left, .yx-auth-form.enter-from-right, .yx-auth-form.leave-to-left, .yx-auth-form.leave-to-right')) return;
                var target = tab.dataset.tab;
                var currentForm = document.querySelector('.yx-auth-form.active');
                var nextForm = document.getElementById(target + '-form');
                var currentTab = document.querySelector('.yx-auth-tab-btn.active');
                var currentIndex = Array.from(yxAuthTabs).indexOf(currentTab);
                var targetIndex = Array.from(yxAuthTabs).indexOf(tab);
                var direction = targetIndex > currentIndex ? 'right' : 'left';
                yxAuthTabs.forEach(function(t) { t.classList.remove('active'); });
                tab.classList.add('active');
                if (currentForm && currentForm !== nextForm) {
                    currentForm.classList.remove('active');
                    currentForm.classList.add(direction === 'right' ? 'leave-to-right' : 'leave-to-left');
                    currentForm.addEventListener('animationend', function onLeave() {
                        currentForm.classList.remove('leave-to-left', 'leave-to-right');
                        currentForm.removeEventListener('animationend', onLeave);
                    });
                }
                if (nextForm) {
                    nextForm.classList.add('active');
                    nextForm.classList.add(direction === 'right' ? 'enter-from-left' : 'enter-from-right');
                    nextForm.addEventListener('animationend', function onEnter() {
                        nextForm.classList.remove('enter-from-right', 'enter-from-left');
                        nextForm.removeEventListener('animationend', onEnter);
                    });
                }
            });
        });

        function showYxLoginModal() { overlay.classList.remove('hidden'); }
        function hideYxLoginModal() { overlay.classList.add('hidden'); }

        document.getElementById('yxAuthCloseBtn').addEventListener('click', hideYxLoginModal);

        var navLoginBtn = document.getElementById('navLoginBtn');
        if (navLoginBtn) {
            navLoginBtn.addEventListener('click', function(e) {
                e.preventDefault();
                showYxLoginModal();
            });
        }

        <?php if ($showLoginModal): ?>
        showYxLoginModal();
        <?php endif; ?>

        document.getElementById('yxLoginSubmit').addEventListener('click', function() {
            var account = document.getElementById('yxLoginAccount').value.trim();
            var password = document.getElementById('yxLoginPassword').value;
            if (!account || !password) { alert('\u8bf7\u586b\u5199\u8d26\u53f7\u548c\u5bc6\u7801'); return; }
            this.disabled = true;
            this.textContent = '\u767b\u5f55\u4e2d...';
            fetch('user_auth.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ account: account, password: password })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    fetch('?yx_action=clear_logout', { credentials: 'same-origin' })
                        .then(function() { window.location.reload(); });
                } else {
                    alert(data.error || '\u767b\u5f55\u5931\u8d25');
                    document.getElementById('yxLoginSubmit').disabled = false;
                    document.getElementById('yxLoginSubmit').textContent = '\u767b\u5f55';
                }
            })
            .catch(function() {
                alert('\u7f51\u7edc\u9519\u8bef');
                document.getElementById('yxLoginSubmit').disabled = false;
                document.getElementById('yxLoginSubmit').textContent = '\u767b\u5f55';
            });
        });

        document.getElementById('yxLoginByCodeSubmit').addEventListener('click', function() {
            var qq = document.getElementById('yxLoginQQ').value.trim();
            var code = document.getElementById('yxLoginCode').value.trim();
            if (!qq || !/^[0-9]{5,11}$/.test(qq) || !code) { alert('\u8bf7\u8f93\u5165QQ\u53f7\u548c\u9a8c\u8bc1\u7801'); return; }
            this.disabled = true;
            this.textContent = '\u767b\u5f55\u4e2d...';
            fetch('user_auth.php?action=login_by_code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ email: qq + '@qq.com', code: code })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    fetch('?yx_action=clear_logout', { credentials: 'same-origin' })
                        .then(function() { window.location.reload(); });
                } else {
                    alert(data.error || '\u767b\u5f55\u5931\u8d25');
                    document.getElementById('yxLoginByCodeSubmit').disabled = false;
                    document.getElementById('yxLoginByCodeSubmit').textContent = '\u767b\u5f55';
                }
            })
            .catch(function() {
                alert('\u7f51\u7edc\u9519\u8bef');
                document.getElementById('yxLoginByCodeSubmit').disabled = false;
                document.getElementById('yxLoginByCodeSubmit').textContent = '\u767b\u5f55';
            });
        });

        document.getElementById('yxLoginSendCodeBtn').addEventListener('click', function() {
            var qq = document.getElementById('yxLoginQQ').value.trim();
            if (!qq || !/^[0-9]{5,11}$/.test(qq)) { alert('\u8bf7\u8f93\u5165\u6709\u6548\u7684QQ\u53f7'); return; }
            this.disabled = true;
            this.innerHTML = '<span class="yx-auth-btn-loading"></span>';
            fetch('user_auth.php?action=send_code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ email: qq + '@qq.com', purpose: 'login' })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var cd = 60;
                    var btn = document.getElementById('yxLoginSendCodeBtn');
                    var timer = setInterval(function() {
                        cd--;
                        btn.textContent = cd + 's';
                        if (cd <= 0) { clearInterval(timer); btn.disabled = false; btn.textContent = '\u83b7\u53d6\u9a8c\u8bc1\u7801'; }
                    }, 1000);
                } else {
                    alert(data.error || '\u53d1\u9001\u5931\u8d25');
                    document.getElementById('yxLoginSendCodeBtn').disabled = false;
                    document.getElementById('yxLoginSendCodeBtn').textContent = '\u83b7\u53d6\u9a8c\u8bc1\u7801';
                }
            })
            .catch(function() {
                alert('\u7f51\u7edc\u9519\u8bef');
                document.getElementById('yxLoginSendCodeBtn').disabled = false;
                document.getElementById('yxLoginSendCodeBtn').textContent = '\u83b7\u53d6\u9a8c\u8bc1\u7801';
            });
        });

        document.getElementById('yxSendCodeBtn').addEventListener('click', function() {
            var qq = document.getElementById('yxRegQQ').value.trim();
            if (!qq || !/^[0-9]{5,11}$/.test(qq)) { alert('\u8bf7\u8f93\u5165\u6709\u6548\u7684QQ\u53f7'); return; }
            this.disabled = true;
            this.innerHTML = '<span class="yx-auth-btn-loading"></span>';
            fetch('user_auth.php?action=send_code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ email: qq + '@qq.com', purpose: 'register' })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var cd = 60;
                    var btn = document.getElementById('yxSendCodeBtn');
                    var timer = setInterval(function() {
                        cd--;
                        btn.textContent = cd + 's';
                        if (cd <= 0) { clearInterval(timer); btn.disabled = false; btn.textContent = '\u83b7\u53d6\u9a8c\u8bc1\u7801'; }
                    }, 1000);
                } else {
                    alert(data.error || '\u53d1\u9001\u5931\u8d25');
                    document.getElementById('yxSendCodeBtn').disabled = false;
                    document.getElementById('yxSendCodeBtn').textContent = '\u83b7\u53d6\u9a8c\u8bc1\u7801';
                }
            })
            .catch(function() {
                alert('\u7f51\u7edc\u9519\u8bef');
                document.getElementById('yxSendCodeBtn').disabled = false;
                document.getElementById('yxSendCodeBtn').textContent = '\u83b7\u53d6\u9a8c\u8bc1\u7801';
            });
        });

        document.getElementById('yxRegisterSubmit').addEventListener('click', function() {
            var qq = document.getElementById('yxRegQQ').value.trim();
            var code = document.getElementById('yxRegCode').value.trim();
            if (!qq || !/^[0-9]{5,11}$/.test(qq) || !code) { alert('\u8bf7\u8f93\u5165QQ\u53f7\u548c\u9a8c\u8bc1\u7801'); return; }
            this.disabled = true;
            this.textContent = '\u6ce8\u518c\u4e2d...';
            fetch('user_auth.php?action=register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ email: qq + '@qq.com', code: code })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    fetch('?yx_action=clear_logout', { credentials: 'same-origin' })
                        .then(function() { window.location.reload(); });
                } else {
                    alert(data.error || '\u6ce8\u518c\u5931\u8d25');
                    document.getElementById('yxRegisterSubmit').disabled = false;
                    document.getElementById('yxRegisterSubmit').textContent = '\u6ce8\u518c';
                }
            })
            .catch(function() {
                alert('\u7f51\u7edc\u9519\u8bef');
                document.getElementById('yxRegisterSubmit').disabled = false;
                document.getElementById('yxRegisterSubmit').textContent = '\u6ce8\u518c';
            });
        });
    })();
    </script>
</body>
</html>
