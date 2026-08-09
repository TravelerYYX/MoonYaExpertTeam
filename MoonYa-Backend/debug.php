<?php
// 调试文件 - 检查 Kimi API 响应
header('Content-Type: application/json');

$config = require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    echo json_encode([
        'received_data' => $data,
        'config' => [
            'api_url' => $config['api_url'],
            'model_normal' => $config['model_normal'],
            'has_images' => isset($data['images']) ? count($data['images']) : 0
        ]
    ], JSON_PRETTY_PRINT);
}
?>
