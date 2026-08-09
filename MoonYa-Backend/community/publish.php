<?php
session_start();
$config = require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php?auth=1&return=' . rawurlencode('/community/publish.php'));
    exit;
}
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
$maxTitleLength = $communityConfig['max_title_length'] ?? 100;
$maxContentLength = $communityConfig['max_content_length'] ?? 100000;
$maxImages = $communityConfig['max_images_per_post'] ?? 3;
$maxImageSize = $communityConfig['max_image_size'] ?? 3 * 1024 * 1024;
$videoConfig = $communityConfig['video'] ?? [];
$maxVideoSize = $videoConfig['max_size'] ?? 100 * 1024 * 1024;
$allowedVideoTypes = $videoConfig['allowed_types'] ?? ['mp4', 'webm', 'mov', 'avi', 'mkv'];
$maxExternalVideos = $communityConfig['max_external_videos_per_post'] ?? 4;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="moonya-authenticated" content="1">
<meta name="moonya-community-csrf" content="<?php echo htmlspecialchars($communityCsrf, ENT_QUOTES, 'UTF-8'); ?>">
<script src="auth-bridge.js" defer></script>
<title>发布动态</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    -webkit-tap-highlight-color: transparent;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
    background: #e8e8e8;
    color: <?php echo $textPrimary; ?>;
    min-height: 100vh;
    padding-top: 50px;
    padding-bottom: 0;
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

.nav-cancel {
    font-size: 14px;
    color: #666;
    cursor: pointer;
    background: none;
    border: none;
    padding: 8px 0;
    min-width: 48px;
}

.nav-title {
    font-size: 18px;
    font-weight: 600;
    color: <?php echo $textPrimary; ?>;
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
}

.nav-publish {
    font-size: 14px;
    color: #fff;
    background: <?php echo $primaryColor; ?>;
    border: none;
    border-radius: 16px;
    padding: 6px 16px;
    cursor: pointer;
    min-width: 60px;
    transition: opacity 0.2s;
}

.nav-publish:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.title-section {
    background: #fff;
    padding: 0 16px;
    display: flex;
    align-items: center;
    border-bottom: 1px solid #f0f0f0;
}

.title-input {
    flex: 1;
    border: none;
    outline: none;
    font-size: 18px;
    font-weight: 600;
    color: <?php echo $textPrimary; ?>;
    padding: 16px 0;
    background: transparent;
}

.title-input::placeholder {
    color: #ccc;
}

.title-count {
    font-size: 12px;
    color: <?php echo $textSecondary; ?>;
    white-space: nowrap;
    margin-left: 8px;
}

.content-section {
    background: #fff;
}

.content-textarea {
    width: 100%;
    border: none;
    outline: none;
    font-size: 15px;
    font-weight: 400;
    line-height: 1.6;
    color: <?php echo $textPrimary; ?>;
    padding: 16px;
    min-height: 200px;
    resize: none;
    background: transparent;
    font-family: inherit;
}

.content-textarea::placeholder {
    color: #ccc;
}

.format-toolbar {
    display: flex;
    gap: 8px;
    padding: 8px 16px 12px;
    background: #fff;
}

.format-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: <?php echo $bgColor; ?>;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: <?php echo $textPrimary; ?>;
    transition: all 0.2s;
}

.format-btn.active {
    background: <?php echo $primaryColor; ?>;
    color: #fff;
}

.format-btn.bold-btn {
    font-weight: 700;
    font-size: 18px;
}

.format-btn.italic-btn {
    font-style: italic;
    font-size: 18px;
}

.format-btn.link-btn {
    font-size: 16px;
}

.image-section {
    background: #fff;
    padding: 12px 16px 16px;
    border-top: 1px solid #f0f0f0;
}

.image-scroll {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 4px;
}

.image-scroll::-webkit-scrollbar {
    display: none;
}

.image-thumb {
    position: relative;
    width: 80px;
    height: 80px;
    flex-shrink: 0;
}

.image-thumb img {
    width: 80px;
    height: 80px;
    border-radius: 8px;
    object-fit: cover;
    display: block;
}

.image-thumb .delete-btn {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #ff4d4f;
    border: none;
    color: #fff;
    font-size: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    z-index: 2;
}

.image-thumb .upload-loading {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    border-radius: 8px;
    background: rgba(0,0,0,0.4);
    display: flex;
    align-items: center;
    justify-content: center;
}

.image-thumb .upload-loading::after {
    content: '';
    width: 24px;
    height: 24px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.add-image-btn {
    width: 80px;
    height: 80px;
    flex-shrink: 0;
    border: 2px dashed #ddd;
    border-radius: 8px;
    background: transparent;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    transition: border-color 0.2s;
}

.add-image-btn:active {
    border-color: <?php echo $primaryColor; ?>;
}

.add-image-btn .plus-icon {
    font-size: 24px;
    color: #bbb;
    line-height: 1;
}

.add-image-btn .add-text {
    font-size: 12px;
    color: #999;
}

.mention-dropdown {
    position: absolute;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    max-height: 200px;
    overflow-y: auto;
    z-index: 200;
    display: none;
    min-width: 180px;
}

.mention-dropdown.show {
    display: block;
}

.mention-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    cursor: pointer;
    transition: background 0.15s;
}

.mention-item:active {
    background: <?php echo $bgColor; ?>;
}

.mention-item img {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    object-fit: cover;
}

.mention-item .mention-name {
    font-size: 14px;
    color: <?php echo $textPrimary; ?>;
}

.link-popup {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.4);
    z-index: 300;
    display: none;
    align-items: center;
    justify-content: center;
}

.link-popup.show {
    display: flex;
}

.link-popup-inner {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    width: 85%;
    max-width: 320px;
}

.link-popup-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 14px;
    color: <?php echo $textPrimary; ?>;
}

.link-popup input {
    width: 100%;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 14px;
    outline: none;
    margin-bottom: 14px;
    font-family: inherit;
}

.link-popup input:focus {
    border-color: <?php echo $primaryColor; ?>;
}

.link-popup-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.link-popup-actions button {
    padding: 8px 18px;
    border-radius: 8px;
    border: none;
    font-size: 14px;
    cursor: pointer;
    font-family: inherit;
}

.link-popup-cancel {
    background: <?php echo $bgColor; ?>;
    color: <?php echo $textPrimary; ?>;
}

.link-popup-confirm {
    background: <?php echo $primaryColor; ?>;
    color: #fff;
}

.toast {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(0,0,0,0.75);
    color: #fff;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    z-index: 500;
    opacity: 0;
    transition: opacity 0.3s;
    pointer-events: none;
    text-align: center;
    max-width: 80%;
}

.toast.show {
    opacity: 1;
}

.video-section {
    background: #fff;
    padding: 12px 16px 16px;
    border-top: 1px solid #f0f0f0;
}

.video-section-label {
    font-size: 13px;
    color: <?php echo $textSecondary; ?>;
    margin-bottom: 8px;
}

.video-upload-area {
    position: relative;
    width: 100%;
    border: 2px dashed #ddd;
    border-radius: 12px;
    background: <?php echo $bgColor; ?>;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    transition: border-color 0.2s;
    gap: 8px;
}

.video-upload-area:active {
    border-color: <?php echo $primaryColor; ?>;
}

.video-upload-area.has-video {
    border: none;
    padding: 0;
    background: transparent;
}

.video-upload-icon {
    width: 40px;
    height: 40px;
    color: #bbb;
}

.video-upload-text {
    font-size: 14px;
    color: #999;
}

.video-upload-hint {
    font-size: 12px;
    color: #bbb;
}

.video-preview {
    position: relative;
    width: 100%;
    min-height: 180px;
    border-radius: 12px;
    overflow: hidden;
    background: #000;
}

.video-preview video {
    width: 100%;
    max-height: 240px;
    display: block;
    object-fit: contain;
}

.video-preview-cover {
    position: relative;
    width: 100%;
}

.video-preview-cover img {
    width: 100%;
    max-height: 240px;
    object-fit: cover;
    display: block;
}

.video-play-overlay {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}

.video-play-overlay svg {
    width: 24px;
    height: 24px;
    fill: #fff;
    margin-left: 2px;
}

.video-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    background: rgba(0,0,0,0.6);
    color: #fff;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.video-badge svg {
    width: 12px;
    height: 12px;
    fill: #fff;
}

.video-delete-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(0,0,0,0.6);
    border: none;
    color: #fff;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2;
}

.video-upload-progress {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: rgba(0,0,0,0.15);
    border-radius: 0 0 12px 12px;
    overflow: hidden;
}

.video-upload-progress-bar {
    height: 100%;
    background: <?php echo $primaryColor; ?>;
    width: 0%;
    transition: width 0.3s ease;
    border-radius: 0 0 12px 12px;
}

.video-upload-progress-bar.transcoding {
    width: 100% !important;
    animation: transcoding-pulse 1.5s ease-in-out infinite;
}

@keyframes transcoding-pulse {
    0%, 100% { opacity: 0.5; }
    50% { opacity: 1; }
}

.video-upload-loading {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    border-radius: 12px;
}

.video-upload-loading::after {
    content: '';
    width: 32px;
    height: 32px;
    border: 3px solid rgba(255,255,255,0.3);
    border-top-color: <?php echo $primaryColor; ?>;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

.video-upload-loading span {
    color: #fff;
    font-size: 18px;
    font-weight: 600;
    text-shadow: 0 1px 3px rgba(0,0,0,0.3);
}

.external-video-section {
    background: #fff;
    padding: 12px 16px 16px;
    border-top: 1px solid #f0f0f0;
}

.external-video-section-label {
    font-size: 13px;
    color: <?php echo $textSecondary; ?>;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.external-video-section-label .count {
    font-size: 12px;
    color: #bbb;
}

.external-video-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.external-video-item {
    display: flex;
    align-items: center;
    background: <?php echo $bgColor; ?>;
    border-radius: 8px;
    padding: 10px 12px;
    gap: 10px;
}

.external-video-item .ev-icon {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    background: rgba(0,0,0,0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    cursor: pointer;
}

.external-video-item .ev-icon svg {
    width: 18px;
    height: 18px;
    fill: <?php echo $primaryColor; ?>;
}

.external-video-item .ev-url {
    flex: 1;
    font-size: 13px;
    color: <?php echo $textPrimary; ?>;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}

.external-video-item .ev-delete {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #ff4d4f;
    border: none;
    color: #fff;
    font-size: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    line-height: 1;
}

.add-external-video-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px;
    border: 2px dashed #ddd;
    border-radius: 8px;
    background: transparent;
    cursor: pointer;
    font-size: 13px;
    color: #999;
    font-family: inherit;
    transition: border-color 0.2s;
}

.add-external-video-btn:active {
    border-color: <?php echo $primaryColor; ?>;
}

.add-external-video-btn svg {
    width: 18px;
    height: 18px;
    fill: #bbb;
}

.external-video-popup {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 300;
    display: none;
    align-items: center;
    justify-content: center;
}

.external-video-popup.show {
    display: flex;
}

.external-video-popup-inner {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    width: 85%;
    max-width: 320px;
}

.external-video-popup-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 14px;
    color: <?php echo $textPrimary; ?>;
}

.external-video-popup input {
    width: 100%;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 14px;
    outline: none;
    margin-bottom: 14px;
    font-family: inherit;
}

.external-video-popup input:focus {
    border-color: <?php echo $primaryColor; ?>;
}

.external-video-popup-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.external-video-popup-actions button {
    padding: 8px 18px;
    border-radius: 8px;
    border: none;
    font-size: 14px;
    cursor: pointer;
    font-family: inherit;
}

.external-video-popup-cancel {
    background: <?php echo $bgColor; ?>;
    color: <?php echo $textPrimary; ?>;
}

.external-video-popup-confirm {
    background: <?php echo $primaryColor; ?>;
    color: #fff;
}

.video-player-popup {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.85);
    z-index: 400;
    display: none;
    align-items: center;
    justify-content: center;
    flex-direction: column;
}

.video-player-popup.show {
    display: flex;
}

.video-player-popup video {
    width: 100%;
    max-height: 70vh;
    object-fit: contain;
}

.video-player-popup-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    border: none;
    color: #fff;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
}

</style>
<link rel="stylesheet" href="css/video-player.css">
</head>
<body>

<div class="top-nav">
    <button class="nav-cancel" onclick="history.back()">取消</button>
    <span class="nav-title">发布动态</span>
    <button class="nav-publish" id="publishBtn" disabled>发布</button>
</div>

<div class="title-section">
    <input type="text" class="title-input" id="titleInput" placeholder="添加标题（选填）" maxlength="<?php echo $maxTitleLength; ?>">
    <span class="title-count" id="titleCount">0/<?php echo $maxTitleLength; ?></span>
</div>

<div class="content-section">
    <textarea class="content-textarea" id="contentTextarea" placeholder="分享你的想法..." maxlength="<?php echo $maxContentLength; ?>"></textarea>
    <div class="format-toolbar">
        <button class="format-btn bold-btn" id="boldBtn" title="粗体">B</button>
        <button class="format-btn italic-btn" id="italicBtn" title="斜体">I</button>
        <button class="format-btn link-btn" id="linkBtn" title="链接">🔗</button>
    </div>
</div>

<div class="image-section">
    <div class="image-scroll" id="imageScroll">
        <button class="add-image-btn" id="addImageBtn">
            <span class="plus-icon">+</span>
            <span class="add-text">添加图片</span>
        </button>
    </div>
    <input type="file" id="imageFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" multiple>
</div>

<div class="video-section" id="videoSection">
    <div class="video-section-label">视频</div>
    <div class="video-upload-area" id="videoUploadArea">
        <svg class="video-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="23 7 16 12 23 17 23 7"></polygon>
            <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
        </svg>
        <span class="video-upload-text">点击上传视频</span>
        <span class="video-upload-hint">支持 <?php echo implode('/', $allowedVideoTypes); ?> 格式，最大<?php echo ($maxVideoSize / 1024 / 1024); ?>MB</span>
    </div>
    <input type="file" id="videoFileInput" accept="video/mp4,video/webm,video/quicktime,video/x-msvideo,video/x-matroska" style="display:none">
</div>

<div class="external-video-section" id="externalVideoSection">
    <div class="external-video-section-label">
        <span>外部视频链接</span>
        <span class="count" id="externalVideoCount">0/<?php echo $maxExternalVideos; ?></span>
    </div>
    <div class="external-video-list" id="externalVideoList"></div>
    <button class="add-external-video-btn" id="addExternalVideoBtn">
        <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
        添加外部视频链接
    </button>
</div>

<div class="mention-dropdown" id="mentionDropdown"></div>

<div class="link-popup" id="linkPopup">
    <div class="link-popup-inner">
        <div class="link-popup-title">插入链接</div>
        <input type="url" id="linkUrlInput" placeholder="请输入链接地址">
        <div class="link-popup-actions">
            <button class="link-popup-cancel" id="linkCancelBtn">取消</button>
            <button class="link-popup-confirm" id="linkConfirmBtn">确定</button>
        </div>
    </div>
</div>

<div class="external-video-popup" id="externalVideoPopup">
    <div class="external-video-popup-inner">
        <div class="external-video-popup-title">添加外部视频链接</div>
        <input type="url" id="externalVideoUrlInput" placeholder="请输入视频链接地址（如.mp4/.m3u8等）">
        <div class="external-video-popup-actions">
            <button class="external-video-popup-cancel" id="externalVideoCancelBtn">取消</button>
            <button class="external-video-popup-confirm" id="externalVideoConfirmBtn">确定</button>
        </div>
    </div>
</div>

<div class="video-player-popup" id="videoPlayerPopup">
    <button class="video-player-popup-close" id="videoPlayerPopupClose">✕</button>
    <div id="previewVideoContainer" style="width:100%;max-width:500px;"></div>
</div>

<div class="toast" id="toast"></div>



<script>
(function() {
    var titleInput = document.getElementById('titleInput');
    var titleCount = document.getElementById('titleCount');
    var contentTextarea = document.getElementById('contentTextarea');
    var publishBtn = document.getElementById('publishBtn');
    var addImageBtn = document.getElementById('addImageBtn');
    var imageFileInput = document.getElementById('imageFileInput');
    var imageScroll = document.getElementById('imageScroll');
    var boldBtn = document.getElementById('boldBtn');
    var italicBtn = document.getElementById('italicBtn');
    var linkBtn = document.getElementById('linkBtn');
    var linkPopup = document.getElementById('linkPopup');
    var linkUrlInput = document.getElementById('linkUrlInput');
    var linkCancelBtn = document.getElementById('linkCancelBtn');
    var linkConfirmBtn = document.getElementById('linkConfirmBtn');
    var mentionDropdown = document.getElementById('mentionDropdown');
    var toast = document.getElementById('toast');

    var maxTitleLength = <?php echo $maxTitleLength; ?>;
    var maxContentLength = <?php echo $maxContentLength; ?>;
    var maxImages = <?php echo $maxImages; ?>;
    var maxImageSize = <?php echo $maxImageSize; ?>;
    var maxVideoSize = <?php echo $maxVideoSize; ?>;
    var uploadedImages = [];
    var mentionUsers = [];
    var mentionLoaded = false;
    var linkSelectionStart = 0;
    var linkSelectionEnd = 0;
    var linkSelectedText = '';
    var isPublishing = false;
    var uploadedVideo = null;
    var uploadedVideoCover = null;
    var externalVideos = [];
    var maxExternalVideos = <?php echo $maxExternalVideos; ?>;

    var videoUploadArea = document.getElementById('videoUploadArea');
    var videoFileInput = document.getElementById('videoFileInput');
    var addExternalVideoBtn = document.getElementById('addExternalVideoBtn');
    var externalVideoPopup = document.getElementById('externalVideoPopup');
    var externalVideoUrlInput = document.getElementById('externalVideoUrlInput');
    var externalVideoCancelBtn = document.getElementById('externalVideoCancelBtn');
    var externalVideoConfirmBtn = document.getElementById('externalVideoConfirmBtn');
    var externalVideoList = document.getElementById('externalVideoList');
    var videoPlayerPopup = document.getElementById('videoPlayerPopup');
    var videoPlayerPopupClose = document.getElementById('videoPlayerPopupClose');
    var previewVideoContainer = document.getElementById('previewVideoContainer');
    var previewVideoPlayer = null;

    function showToast(msg, duration) {
        toast.textContent = msg;
        toast.classList.add('show');
        setTimeout(function() {
            toast.classList.remove('show');
        }, duration || 2000);
    }

    function updatePublishBtn() {
        var content = contentTextarea.value.trim();
        publishBtn.disabled = content.length === 0 || isPublishing;
    }

    titleInput.addEventListener('input', function() {
        var len = titleInput.value.length;
        if (len > maxTitleLength) {
            titleInput.value = titleInput.value.substring(0, maxTitleLength);
            len = maxTitleLength;
        }
        titleCount.textContent = len + '/' + maxTitleLength;
    });

    contentTextarea.addEventListener('input', function() {
        updatePublishBtn();
        autoResize();
        checkMention();
    });

    function autoResize() {
        contentTextarea.style.height = 'auto';
        contentTextarea.style.height = contentTextarea.scrollHeight + 'px';
    }

    function wrapSelection(before, after) {
        var start = contentTextarea.selectionStart;
        var end = contentTextarea.selectionEnd;
        var text = contentTextarea.value;
        var selected = text.substring(start, end);
        var replacement = before + (selected || '文本') + after;
        contentTextarea.value = text.substring(0, start) + replacement + text.substring(end);
        contentTextarea.focus();
        var cursorPos = start + before.length + (selected || '文本').length;
        contentTextarea.setSelectionRange(cursorPos, cursorPos);
        updatePublishBtn();
        autoResize();
    }

    boldBtn.addEventListener('click', function() {
        wrapSelection('**', '**');
    });

    italicBtn.addEventListener('click', function() {
        wrapSelection('*', '*');
    });

    linkBtn.addEventListener('click', function() {
        var start = contentTextarea.selectionStart;
        var end = contentTextarea.selectionEnd;
        linkSelectionStart = start;
        linkSelectionEnd = end;
        linkSelectedText = contentTextarea.value.substring(start, end);
        linkUrlInput.value = '';
        linkPopup.classList.add('show');
        setTimeout(function() { linkUrlInput.focus(); }, 100);
    });

    linkCancelBtn.addEventListener('click', function() {
        linkPopup.classList.remove('show');
        contentTextarea.focus();
    });

    linkConfirmBtn.addEventListener('click', function() {
        var url = linkUrlInput.value.trim();
        if (!url) {
            showToast('请输入链接地址');
            return;
        }
        if (!/^https?:\/\//i.test(url)) {
            url = 'https://' + url;
        }
        var text = linkSelectedText || '链接';
        var replacement = '[' + text + '](' + url + ')';
        var value = contentTextarea.value;
        contentTextarea.value = value.substring(0, linkSelectionStart) + replacement + value.substring(linkSelectionEnd);
        linkPopup.classList.remove('show');
        contentTextarea.focus();
        var newPos = linkSelectionStart + replacement.length;
        contentTextarea.setSelectionRange(newPos, newPos);
        updatePublishBtn();
        autoResize();
    });

    linkPopup.addEventListener('click', function(e) {
        if (e.target === linkPopup) {
            linkPopup.classList.remove('show');
            contentTextarea.focus();
        }
    });

    function updateImageUI() {
        var thumbs = imageScroll.querySelectorAll('.image-thumb');
        thumbs.forEach(function(t) { t.remove(); });

        for (var i = 0; i < uploadedImages.length; i++) {
            var img = uploadedImages[i];
            var thumb = document.createElement('div');
            thumb.className = 'image-thumb';
            thumb.innerHTML = '<img src="' + img.url + '" alt="">' +
                '<button class="delete-btn" data-index="' + i + '">✕</button>';
            imageScroll.insertBefore(thumb, addImageBtn);
        }

        if (uploadedImages.length >= maxImages) {
            addImageBtn.style.display = 'none';
        } else {
            addImageBtn.style.display = '';
        }
    }

    imageScroll.addEventListener('click', function(e) {
        if (e.target.classList.contains('delete-btn')) {
            var idx = parseInt(e.target.getAttribute('data-index'));
            uploadedImages.splice(idx, 1);
            updateImageUI();
        }
    });

    addImageBtn.addEventListener('click', function() {
        if (uploadedImages.length >= maxImages) {
            showToast('最多上传' + maxImages + '张图片');
            return;
        }
        imageFileInput.click();
    });

    imageFileInput.addEventListener('change', function() {
        var files = imageFileInput.files;
        if (!files || files.length === 0) return;

        var remaining = maxImages - uploadedImages.length;
        var toUpload = Array.prototype.slice.call(files, 0, remaining);

        toUpload.forEach(function(file) {
            if (file.size > maxImageSize) {
                showToast('图片大小不能超过' + (maxImageSize / 1024 / 1024).toFixed(0) + 'MB');
                return;
            }

            var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (allowedTypes.indexOf(file.type) === -1) {
                showToast('不支持的图片格式');
                return;
            }

            uploadImage(file);
        });

        imageFileInput.value = '';
    });

    function uploadImage(file) {
        var thumb = document.createElement('div');
        thumb.className = 'image-thumb';
        thumb.innerHTML = '<div class="upload-loading"></div>';
        imageScroll.insertBefore(thumb, addImageBtn);
        addImageBtn.style.display = 'none';

        var formData = new FormData();
        formData.append('image', file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/api.php?module=upload&action=image', true);

        xhr.onload = function() {
            var loadingEl = thumb.querySelector('.upload-loading');
            if (loadingEl) loadingEl.remove();

            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.data && res.data.url) {
                        uploadedImages.push({ url: res.data.url });
                        updateImageUI();
                    } else {
                        showToast(res.error || '上传失败');
                        thumb.remove();
                        if (uploadedImages.length < maxImages) {
                            addImageBtn.style.display = '';
                        }
                    }
                } catch (e) {
                    showToast('上传失败');
                    thumb.remove();
                    if (uploadedImages.length < maxImages) {
                        addImageBtn.style.display = '';
                    }
                }
            } else {
                try {
                    var res = JSON.parse(xhr.responseText);
                    showToast(res.error || '上传失败');
                } catch (e) {
                    showToast('上传失败');
                }
                thumb.remove();
                if (uploadedImages.length < maxImages) {
                    addImageBtn.style.display = '';
                }
            }
        };

        xhr.onerror = function() {
            var loadingEl = thumb.querySelector('.upload-loading');
            if (loadingEl) loadingEl.remove();
            thumb.remove();
            showToast('网络错误');
            if (uploadedImages.length < maxImages) {
                addImageBtn.style.display = '';
            }
        };

        xhr.send(formData);
    }

    function checkMention() {
        var text = contentTextarea.value;
        var cursorPos = contentTextarea.selectionStart;
        var textBeforeCursor = text.substring(0, cursorPos);

        var atMatch = textBeforeCursor.match(/@([^\s@]*)$/);

        if (atMatch) {
            var query = atMatch[1].toLowerCase();
            showMentionDropdown(query, cursorPos);
        } else {
            hideMentionDropdown();
        }
    }

    function showMentionDropdown(query, cursorPos) {
        if (!mentionLoaded) {
            loadMentionUsers(query, cursorPos);
            return;
        }

        var filtered = mentionUsers.filter(function(u) {
            return u.username.toLowerCase().indexOf(query) !== -1 ||
                   (u.real_name && u.real_name.toLowerCase().indexOf(query) !== -1);
        });

        if (filtered.length === 0) {
            hideMentionDropdown();
            return;
        }

        var html = '';
        filtered.slice(0, 8).forEach(function(u) {
            html += '<div class="mention-item" data-username="' + u.username + '">' +
                '<img src="' + (u.avatar || '../uploads/avatars/default.png') + '" alt="">' +
                '<span class="mention-name">' + (u.real_name || u.username) + '</span>' +
                '</div>';
        });

        mentionDropdown.innerHTML = html;
        mentionDropdown.classList.add('show');

        var textareaRect = contentTextarea.getBoundingClientRect();
        var topOffset = textareaRect.bottom + window.scrollY + 4;
        var leftOffset = textareaRect.left + 16;

        mentionDropdown.style.top = topOffset + 'px';
        mentionDropdown.style.left = leftOffset + 'px';
    }

    function hideMentionDropdown() {
        mentionDropdown.classList.remove('show');
    }

    function loadMentionUsers(query, cursorPos) {
        fetch('api/api.php?module=follows&action=following', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.data && res.data.users) {
                    mentionUsers = res.data.users;
                    mentionLoaded = true;
                    showMentionDropdown(query, cursorPos);
                }
            })
            .catch(function() {});
    }

    mentionDropdown.addEventListener('click', function(e) {
        var item = e.target.closest('.mention-item');
        if (!item) return;

        var username = item.getAttribute('data-username');
        var text = contentTextarea.value;
        var cursorPos = contentTextarea.selectionStart;
        var textBeforeCursor = text.substring(0, cursorPos);
        var atMatch = textBeforeCursor.match(/@([^\s@]*)$/);

        if (atMatch) {
            var atStart = cursorPos - atMatch[0].length;
            contentTextarea.value = text.substring(0, atStart) + '@' + username + ' ' + text.substring(cursorPos);
            var newPos = atStart + username.length + 2;
            contentTextarea.setSelectionRange(newPos, newPos);
        }

        hideMentionDropdown();
        contentTextarea.focus();
        updatePublishBtn();
        autoResize();
    });

    document.addEventListener('click', function(e) {
        if (!mentionDropdown.contains(e.target) && e.target !== contentTextarea) {
            hideMentionDropdown();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            hideMentionDropdown();
        }
    });

    videoUploadArea.addEventListener('click', function() {
        if (uploadedVideo) return;
        videoFileInput.click();
    });

    videoFileInput.addEventListener('change', function() {
        var file = videoFileInput.files[0];
        if (!file) return;

        if (file.size > maxVideoSize) {
            showToast('视频大小不能超过' + (maxVideoSize / 1024 / 1024) + 'MB');
            videoFileInput.value = '';
            return;
        }

        var allowedMimes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'];
        if (allowedMimes.indexOf(file.type) === -1) {
            showToast('不支持的视频格式');
            videoFileInput.value = '';
            return;
        }

        uploadVideo(file);
        videoFileInput.value = '';
    });

    function uploadVideo(file) {
        videoUploadArea.classList.add('has-video');
        videoUploadArea.innerHTML = '<div class="video-preview"><div class="video-upload-loading"><span id="videoUploadPercent">0%</span></div><div class="video-upload-progress"><div class="video-upload-progress-bar" id="videoProgressBar"></div></div></div>';

        var formData = new FormData();
        formData.append('video', file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/api.php?module=upload&action=video', true);
        xhr.timeout = 600000;

        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                var pct = Math.round((e.loaded / e.total) * 100);
                var bar = document.getElementById('videoProgressBar');
                var percentEl = document.getElementById('videoUploadPercent');
                if (bar) bar.style.width = pct + '%';
                if (percentEl) {
                    if (pct >= 100) {
                        percentEl.textContent = '转码中...';
                        var bar = document.getElementById('videoProgressBar');
                        if (bar) bar.classList.add('transcoding');
                    } else {
                        percentEl.textContent = pct + '%';
                    }
                }
            }
        });

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.data && res.data.url) {
                        var percentEl = document.getElementById('videoUploadPercent');
                        var bar = document.getElementById('videoProgressBar');
                        if (percentEl) percentEl.textContent = '100%';
                        if (bar) bar.classList.remove('transcoding');
                        uploadedVideo = res.data.url;
                        if (res.data.process_error) {
                            console.warn('视频转码失败:', res.data.process_error);
                            showToast('视频已上传但未转码: ' + res.data.process_error, 'warning');
                        }
                        if (res.data.m3u8_url) {
                            console.log('视频已转码为HLS:', res.data.m3u8_url);
                        }
                        generateAndUploadCover(file);
                    } else {
                        showToast(res.error || '视频上传失败');
                        resetVideoUpload();
                    }
                } catch (e) {
                    showToast('视频上传失败(响应解析错误)');
                    resetVideoUpload();
                }
            } else {
                var errorMsg = '视频上传失败(' + xhr.status + ')';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.error) {
                        errorMsg = res.error;
                    }
                } catch (e) {
                    if (xhr.responseText && xhr.responseText.length < 200) {
                        errorMsg = '服务器错误(' + xhr.status + '): ' + xhr.responseText.substring(0, 100);
                    } else if (xhr.status === 500) {
                        errorMsg = '服务器内部错误(500)，请查看Nginx错误日志';
                    }
                }
                showToast(errorMsg);
                resetVideoUpload();
            }
        };

        xhr.onerror = function() {
            showToast('网络错误');
            resetVideoUpload();
        };

        xhr.ontimeout = function() {
            showToast('上传超时，请检查网络或减少视频大小');
            resetVideoUpload();
        };

        xhr.send(formData);
    }

    function generateAndUploadCover(file) {
        var videoEl = document.createElement('video');
        videoEl.preload = 'metadata';
        videoEl.muted = true;
        videoEl.playsInline = true;

        var url = URL.createObjectURL(file);
        videoEl.src = url;

        videoEl.onloadeddata = function() {
            videoEl.currentTime = 0.5;
        };

        videoEl.onseeked = function() {
            try {
                var canvas = document.createElement('canvas');
                var maxW = 800;
                var ratio = maxW / videoEl.videoWidth;
                if (ratio > 1) ratio = 1;
                canvas.width = videoEl.videoWidth * ratio;
                canvas.height = videoEl.videoHeight * ratio;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);

                canvas.toBlob(function(blob) {
                    if (!blob) {
                        showVideoPreview();
                        return;
                    }
                    uploadCoverBlob(blob);
                }, 'image/jpeg', 0.8);

                URL.revokeObjectURL(url);
            } catch (e) {
                showVideoPreview();
                URL.revokeObjectURL(url);
            }
        };

        videoEl.onerror = function() {
            showVideoPreview();
            URL.revokeObjectURL(url);
        };
    }

    function uploadCoverBlob(blob) {
        var formData = new FormData();
        formData.append('cover', blob, 'cover.jpg');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/api.php?module=upload&action=video_cover', true);

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.data && res.data.url) {
                        uploadedVideoCover = res.data.url;
                    }
                } catch (e) {}
            }
            showVideoPreview();
        };

        xhr.onerror = function() {
            showVideoPreview();
        };

        xhr.send(formData);
    }

    function showVideoPreview() {
        videoUploadArea.classList.add('has-video');
        var coverHtml = '';
        if (uploadedVideoCover) {
            var coverUrl = uploadedVideoCover;
            if (!coverUrl.startsWith('http') && !coverUrl.startsWith('/')) coverUrl = '../' + coverUrl;
            coverHtml = '<div class="video-preview-cover">' +
                '<img src="' + coverUrl + '" alt="">' +
                '<div class="video-play-overlay"><svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg></div>' +
                '</div>';
        } else {
            coverHtml = '<div style="padding:40px;text-align:center;color:#999;font-size:14px;">视频已上传</div>';
        }

        videoUploadArea.innerHTML = '<div class="video-preview">' +
            coverHtml +
            '<div class="video-badge"><svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>视频</div>' +
            '<button class="video-delete-btn" id="videoDeleteBtn">✕</button>' +
            '</div>';

        document.getElementById('videoDeleteBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            removeVideo();
        });
    }

    function removeVideo() {
        uploadedVideo = null;
        uploadedVideoCover = null;
        resetVideoUpload();
    }

    function resetVideoUpload() {
        uploadedVideo = null;
        uploadedVideoCover = null;
        videoUploadArea.classList.remove('has-video');
        videoUploadArea.innerHTML = '<svg class="video-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
            '<polygon points="23 7 16 12 23 17 23 7"></polygon>' +
            '<rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>' +
            '</svg>' +
            '<span class="video-upload-text">点击上传视频</span>' +
            '<span class="video-upload-hint">支持 <?php echo implode('/', $allowedVideoTypes); ?> 格式，最大<?php echo ($maxVideoSize / 1024 / 1024); ?>MB';
    }

    addExternalVideoBtn.addEventListener('click', function() {
        if (externalVideos.length >= maxExternalVideos) {
            showToast('最多添加' + maxExternalVideos + '个外部视频');
            return;
        }
        externalVideoUrlInput.value = '';
        externalVideoPopup.classList.add('show');
        setTimeout(function() { externalVideoUrlInput.focus(); }, 100);
    });

    externalVideoCancelBtn.addEventListener('click', function() {
        externalVideoPopup.classList.remove('show');
    });

    externalVideoConfirmBtn.addEventListener('click', function() {
        var url = externalVideoUrlInput.value.trim();
        if (!url) {
            showToast('请输入视频链接地址');
            return;
        }
        if (!/^https?:\/\//i.test(url)) {
            url = 'https://' + url;
        }
        if (externalVideos.length >= maxExternalVideos) {
            showToast('最多添加' + maxExternalVideos + '个外部视频');
            return;
        }
        externalVideos.push(url);
        externalVideoPopup.classList.remove('show');
        renderExternalVideos();
    });

    function renderExternalVideos() {
        externalVideoList.innerHTML = '';
        for (var i = 0; i < externalVideos.length; i++) {
            var item = document.createElement('div');
            item.className = 'external-video-item';
            item.innerHTML = '<div class="ev-icon" data-index="' + i + '"><div class="ev-poster" id="evPoster_' + i + '" style="width:36px;height:36px;border-radius:6px;background:#1a1a1a;display:flex;align-items:center;justify-content:center;overflow:hidden;"><svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:' + '<?php echo $primaryColor; ?>' + ';"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg></div></div>' +
                '<span class="ev-url">' + escapeHtmlSimple(externalVideos[i]) + '</span>' +
                '<button class="ev-delete" data-index="' + i + '">✕</button>';
            externalVideoList.appendChild(item);
            (function(idx) {
                extractVideoFrameSimple(externalVideos[idx], document.getElementById('evPoster_' + idx));
            })(i);
        }
        document.getElementById('externalVideoCount').textContent = externalVideos.length + '/' + maxExternalVideos;
        if (externalVideos.length >= maxExternalVideos) {
            addExternalVideoBtn.style.display = 'none';
        } else {
            addExternalVideoBtn.style.display = '';
        }
    }

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
                }
            } catch(e) {}
            cleanup();
        });
        video.addEventListener('error', function() { cleanup(); });
        setTimeout(function() { cleanup(); }, 8000);
    }

    function extractVideoFrameSimple(videoUrl, posterEl) {
        _frameQueue.push({ url: videoUrl, posterEl: posterEl });
        _processFrameQueue();
    }

    function escapeHtmlSimple(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    externalVideoList.addEventListener('click', function(e) {
        if (e.target.classList.contains('ev-delete')) {
            var idx = parseInt(e.target.getAttribute('data-index'));
            externalVideos.splice(idx, 1);
            renderExternalVideos();
        } else if (e.target.closest('.ev-icon')) {
            var idx = parseInt(e.target.closest('.ev-icon').getAttribute('data-index'));
            if (previewVideoPlayer) {
                previewVideoPlayer.destroy();
                previewVideoPlayer = null;
            }
            previewVideoContainer.innerHTML = '';
            videoPlayerPopup.classList.add('show');
            if (typeof MoonyaVideoPlayer !== 'undefined') {
                previewVideoPlayer = new MoonyaVideoPlayer(previewVideoContainer, {
                    src: externalVideos[idx],
                    primaryColor: '<?php echo $primaryColor; ?>',
                    autoplay: true,
                    compact: false
                });
            }
        }
    });

    videoPlayerPopupClose.addEventListener('click', function() {
        if (previewVideoPlayer) {
            previewVideoPlayer.destroy();
            previewVideoPlayer = null;
        }
        previewVideoContainer.innerHTML = '';
        videoPlayerPopup.classList.remove('show');
    });

    publishBtn.addEventListener('click', function() {
        if (publishBtn.disabled || isPublishing) return;

        var title = titleInput.value.trim();
        var content = contentTextarea.value.trim();

        if (!content) {
            showToast('请输入内容');
            return;
        }

        if (title.length > maxTitleLength) {
            showToast('标题长度不能超过' + maxTitleLength + '个字符');
            return;
        }

        if (content.length > maxContentLength) {
            showToast('内容长度不能超过' + maxContentLength + '个字符');
            return;
        }

        if (uploadedImages.length > maxImages) {
            showToast('图片数量不能超过' + maxImages + '张');
            return;
        }

        isPublishing = true;
        publishBtn.disabled = true;
        publishBtn.textContent = '发布中...';

        var imageUrls = uploadedImages.map(function(img) { return img.url; });

        var postData = {
            title: title,
            content: content,
            images: imageUrls
        };
        if (uploadedVideo) {
            postData.video_url = uploadedVideo;
        }
        if (uploadedVideoCover) {
            postData.video_cover = uploadedVideoCover;
        }
        if (externalVideos.length > 0) {
            postData.external_videos = externalVideos;
        }

        fetch('api/api.php?module=posts&action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(postData)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success && res.data && res.data.post_id) {
                showToast('发布成功');
                setTimeout(function() {
                    window.open('detail.php?id=' + res.data.post_id, '_blank');
                    window.close();
                }, 800);
            } else {
                showToast(res.error || '发布失败');
                isPublishing = false;
                publishBtn.disabled = false;
                publishBtn.textContent = '发布';
                updatePublishBtn();
            }
        })
        .catch(function() {
            showToast('网络错误，请重试');
            isPublishing = false;
            publishBtn.disabled = false;
            publishBtn.textContent = '发布';
            updatePublishBtn();
        });
    });
})();
</script>
<script src="js/hls.min.js"></script>
<script src="js/video-player.js"></script>
</body>
</html>
