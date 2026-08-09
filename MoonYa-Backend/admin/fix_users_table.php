<?php
/**
 * 修复 users 表结构 - 添加缺失的字段
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

try {
    $config = require __DIR__ . '/config.php';
    $db = new Database($config);
    $pdo = $db->getConnection();

    echo "<h2>修复 users 表结构</h2>";
    echo "<pre>";

    // 获取当前表结构
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "当前表字段: " . implode(', ', $columns) . "\n\n";

    // 需要添加的字段
    $alterStatements = [];

    if (!in_array('real_name', $columns)) {
        $alterStatements[] = "ADD COLUMN `real_name` VARCHAR(100) DEFAULT NULL COMMENT '真实姓名'";
    }

    if (!in_array('ban_reason', $columns)) {
        $alterStatements[] = "ADD COLUMN `ban_reason` TEXT COMMENT '封禁原因'";
    }

    if (!in_array('ban_until', $columns)) {
        $alterStatements[] = "ADD COLUMN `ban_until` DATETIME COMMENT '封禁时间'";
    }

    // 检查 status 字段类型
    $stmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'status'");
    $statusColumn = $stmt->fetch();
    if ($statusColumn && $statusColumn['Type'] !== "enum('active','banned','deleted')") {
        $alterStatements[] = "MODIFY COLUMN `status` ENUM('active', 'banned', 'deleted') DEFAULT 'active' COMMENT '状态'";
    }

    // 添加索引
    $stmt = $pdo->query("SHOW INDEX FROM users");
    $indexes = $stmt->fetchAll(PDO::FETCH_COLUMN, 2); // Column_name
    $indexes = array_unique($indexes);

    if (!in_array('idx_username', $indexes)) {
        $alterStatements[] = "ADD INDEX `idx_username` (`username`)";
    }
    if (!in_array('idx_email', $indexes)) {
        $alterStatements[] = "ADD INDEX `idx_email` (`email`)";
    }
    if (!in_array('idx_status', $indexes)) {
        $alterStatements[] = "ADD INDEX `idx_status` (`status`)";
    }

    if (empty($alterStatements)) {
        echo "✓ 表结构已经是正确的，无需修改\n";
    } else {
        echo "需要执行的修改:\n";
        foreach ($alterStatements as $sql) {
            echo "  - {$sql}\n";
        }
        echo "\n";

        // 执行修改
        $alterSql = "ALTER TABLE users " . implode(', ', $alterStatements);
        $pdo->exec($alterSql);

        echo "✓ 表结构修复完成！\n";

        // 再次检查表结构
        $stmt = $pdo->query("DESCRIBE users");
        $newColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "\n修复后的表字段: " . implode(', ', $newColumns) . "\n";
    }

    echo "</pre>";

} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
    echo "<br>堆栈跟踪:<br>";
    echo nl2br($e->getTraceAsString());
}
