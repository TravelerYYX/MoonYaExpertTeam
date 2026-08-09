<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = require_once 'config.php';
$requiredModelGroups = ['kimi', 'deepseek', 'minmax', 'glm'];
foreach ($requiredModelGroups as $groupName) {
    $group = $config['ui_model_groups'][$groupName] ?? null;
    if (!is_array($group) || trim((string)($group['default'] ?? '')) === '' || empty($group['models'])) {
        throw new RuntimeException("Missing required configuration: ui_model_groups.{$groupName}");
    }
}
$webAssets = $config['web_assets'] ?? [];
foreach (['highlight_css', 'highlight_js', 'mammoth_js', 'pdf_js', 'pdf_worker_js'] as $assetName) {
    if (trim((string)($webAssets[$assetName] ?? '')) === '') {
        throw new RuntimeException("Missing required configuration: web_assets.{$assetName}");
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="icon" type="image/png" sizes="32x32" href="/icon.png">
<link rel="icon" type="image/png" sizes="16x16" href="/icon.png">
    <title>MoonYa</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($webAssets['highlight_css'], ENT_QUOTES, 'UTF-8'); ?>">
    <script src="<?php echo htmlspecialchars($webAssets['highlight_js'], ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars($webAssets['mammoth_js'], ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars($webAssets['pdf_js'], ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script>
        // 从后端加载语音播报配置
        window.VOICE_CONFIG = <?php echo json_encode($config['voice_config'] ?? ['lang' => 'zh-CN', 'rate' => 1.0, 'pitch' => 1.0, 'volume' => 1.0]); ?>;
    </script>
    <script>
        // Push-to-Talk 配置（仅暴露最小信息，不泄露密钥）
        window.PTT_CONFIG = <?php echo json_encode([
            'asrEnabled'  => !empty($config['aliyun_asr']['api_key']),
            'asrEndpoint' => '/api/asr.php',
            'mode'        => 'hold',
            'model'       => $config['aliyun_asr']['model'] ?? '',
        ]); ?>;
        window.WEB_RUNTIME_CONFIG = <?php echo json_encode([
            'pdfWorkerUrl' => $webAssets['pdf_worker_js'],
            'videoPortalUrl' => $config['video_portal']['url'] ?? '',
        ], JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <style>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-01-base.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-02-auth.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-03-floating.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-04-sidebar.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-05-toast.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-06-main.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-07-agent.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-08-code.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-09-animations.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-10-music.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-11-uploader.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-12-features.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-13-update.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-14-responsive.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-15-result.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-16-agent-experience.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/styles/css-17-office.php'; ?>
    </style>
<?php include __DIR__ . '/script/MoonYa-index/layouts/container.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/layouts/sidebar.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/layouts/auth-modal.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/layouts/main-content.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/layouts/toast.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/layouts/dynamic-island.php'; ?>

<?php include __DIR__ . '/script/MoonYa-index/modules/script-1a-vars.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/modules/script-1b-features.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/modules/script-1c-save.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/modules/script-1d-dom.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/modules/script-1e-rest.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/modules/script-3-push-to-talk.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/modules/script-4-voice-chat.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/modules/script-2-island.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/modules/script-2-download.php'; ?>
<?php include __DIR__ . '/script/MoonYa-index/modules/script-5-office.php'; ?>

<?php include __DIR__ . '/script/MoonYa-index/layouts/video-player.php'; ?>
</body>
</html>
