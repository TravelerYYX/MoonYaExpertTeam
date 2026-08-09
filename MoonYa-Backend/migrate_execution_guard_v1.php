<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$sql = file_get_contents(__DIR__ . '/sql/migrate_execution_guard_v1.sql');
if ($sql === false) {
    throw new RuntimeException('无法读取执行守护迁移文件');
}
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}
fwrite(STDOUT, "[PASS] execution guard v1 schema migrated.\n");
