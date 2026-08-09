<?php
/**
 * 版本更新表初始化 / 迁移脚本
 * 使用方法：php init_version_updates.php 或 浏览器直接访问
 */

$config = require __DIR__ . '/config.php';

$host = $config['db_host'];
$name = $config['db_name'];
$user = $config['db_user'];
$pass = $config['db_pass'];

try {
    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    // 建表（如果不存在）
    $pdo->exec("CREATE TABLE IF NOT EXISTS `version_updates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `version` VARCHAR(50) NOT NULL,
        `title` VARCHAR(200) NOT NULL,
        `content` TEXT NOT NULL,
        `video_url` VARCHAR(500) DEFAULT '',
        `image_url` VARCHAR(500) DEFAULT '',
        `is_force` TINYINT(1) DEFAULT 0,
        `close_delay` INT DEFAULT 0 COMMENT '关闭延迟(秒)',
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_version` (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "version_updates 表已就绪\n";

    // 补充迁移（兼容老表，添加缺失列）
    $migrations = [
        "ALTER TABLE version_updates ADD COLUMN video_url VARCHAR(500) DEFAULT '' AFTER content",
        "ALTER TABLE version_updates ADD COLUMN image_url VARCHAR(500) DEFAULT '' AFTER video_url",
        "ALTER TABLE version_updates ADD COLUMN close_delay INT DEFAULT 0 AFTER is_force",
    ];

    foreach ($migrations as $sql) {
        try {
            $pdo->exec($sql);
            echo "迁移执行: $sql\n";
        } catch (PDOException $e) {
            if ($e->getCode() == '42S21') {
                echo "跳过（列已存在）\n";
            } else {
                throw $e;
            }
        }
    }

    echo "\n初始化完成！\n";

} catch (PDOException $e) {
    echo "失败: " . $e->getMessage() . "\n";
    exit(1);
}
