<?php
/**
 * 修复 personality 表结构 - 添加缺失的 use_custom 字段
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

try {
    $config = require __DIR__ . '/config.php';
    $db = new Database($config);
    $pdo = $db->getConnection();

    echo "<h2>修复 personality 表结构</h2>";
    echo "<pre>";

    // 获取当前表结构
    $stmt = $pdo->query("DESCRIBE personality");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "当前表字段: " . implode(', ', $columns) . "\n\n";

    // 检查是否需要添加 use_custom 字段
    if (!in_array('use_custom', $columns)) {
        echo "需要添加 use_custom 字段\n";

        // 添加字段
        $pdo->exec("ALTER TABLE personality ADD COLUMN `use_custom` TINYINT(1) DEFAULT 1 COMMENT '是否使用自定义人格：0-否，1-是' AFTER `description`");

        echo "✓ 已添加 use_custom 字段\n\n";

        // 更新现有数据，设置默认值为 1
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM personality WHERE use_custom IS NULL");
        $count = $stmt->fetch()['count'];

        if ($count > 0) {
            $pdo->exec("UPDATE personality SET use_custom = 1 WHERE use_custom IS NULL");
            echo "✓ 已更新 {$count} 条记录的 use_custom 值为 1\n";
        }

        // 再次检查表结构
        $stmt = $pdo->query("DESCRIBE personality");
        $newColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "\n修复后的表字段: " . implode(', ', $newColumns) . "\n";
    } else {
        echo "✓ use_custom 字段已存在，无需修改\n";
    }

    // 显示当前数据
    echo "\n当前人格数据:\n";
    $stmt = $pdo->query("SELECT * FROM personality");
    $personalities = $stmt->fetchAll();
    foreach ($personalities as $p) {
        echo "  - ID: {$p['id']}, 名称: {$p['name']}, use_custom: " . ($p['use_custom'] ?? 'NULL') . "\n";
    }

    echo "</pre>";

} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
    echo "<br>堆栈跟踪:<br>";
    echo nl2br($e->getTraceAsString());
}
