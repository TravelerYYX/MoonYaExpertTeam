<?php
declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Services/MultiAgentSchema.php';

$isCli = PHP_SAPI === 'cli';
$confirmed = $isCli ? in_array('--confirm', $argv ?? [], true) : (($_GET['confirm'] ?? '') === 'YES');

if (!$confirmed) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>MoonYa 多 Agent v1 迁移</title></head>';
    echo '<body style="font-family:system-ui;max-width:820px;margin:40px auto;line-height:1.7">';
    echo '<h1>MoonYa 多 Agent v1 迁移</h1>';
    echo '<p>将创建 Agent、工具注册表、MCP、运行事件、产出物与审批表，并幂等写入七个默认 Agent。</p>';
    echo '<p>不会删除旧表或旧会话。再次执行会更新种子描述和缺失授权。</p>';
    echo '<a href="?confirm=YES" style="display:inline-block;padding:10px 18px;border-radius:10px;background:#111827;color:white;text-decoration:none">确认执行</a>';
    echo '</body></html>';
    exit;
}

try {
    $config = require __DIR__ . '/config.php';
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $steps = MultiAgentSchema::migrate($pdo, __DIR__);
    $result = [
        'success' => true,
        'version' => MultiAgentSchema::VERSION,
        'steps' => $steps,
    ];
    if ($isCli) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    if (!$isCli) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . ($isCli ? PHP_EOL : '');
    exit(1);
}
