<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'success' => true,
    'message' => 'API测试成功',
    'php_version' => PHP_VERSION,
    'time' => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE);
