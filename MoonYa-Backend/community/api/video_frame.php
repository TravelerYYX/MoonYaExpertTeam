<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('max_execution_time', 15);

header('Content-Type: application/json; charset=utf-8');

$url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($url)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'URL不能为空']);
    exit;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'URL格式无效']);
    exit;
}

$ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
if (empty($ffmpegPath)) {
    $ffmpegPath = trim(shell_exec('where ffmpeg 2>/dev/null') ?: '');
}

if (!empty($ffmpegPath) && is_executable($ffmpegPath)) {
    $tmpDir = sys_get_temp_dir();
    $tmpFile = $tmpDir . '/extvframe_' . md5($url . microtime(true)) . '.jpg';

    $cmd = escapeshellcmd($ffmpegPath) . ' -y -threads 1 -timeout 10000000'
         . ' -i ' . escapeshellarg($url)
         . ' -vframes 1 -q:v 5 -f image2 ' . escapeshellarg($tmpFile)
         . ' 2>/dev/null';

    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && file_exists($tmpFile) && filesize($tmpFile) > 0) {
        $imageData = file_get_contents($tmpFile);
        @unlink($tmpFile);
        $base64 = base64_encode($imageData);
        ob_end_clean();
        echo json_encode(['success' => true, 'poster' => 'data:image/jpeg;base64,' . $base64]);
        exit;
    }
    if (file_exists($tmpFile)) @unlink($tmpFile);
}

ob_end_clean();
echo json_encode(['success' => false, 'poster' => '']);
