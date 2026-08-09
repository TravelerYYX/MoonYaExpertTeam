<?php
/**
 * 修复 tool_settings 表结构 - 添加缺失的 tool_display_name 字段
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

try {
    $config = require __DIR__ . '/config.php';
    $db = new Database($config);
    $pdo = $db->getConnection();

    echo "<h2>修复 tool_settings 表结构</h2>";
    echo "<pre>";

    // 获取当前表结构
    $stmt = $pdo->query("DESCRIBE tool_settings");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "当前表字段: " . implode(', ', $columns) . "\n\n";

    // 检查是否需要添加 tool_display_name 字段
    if (!in_array('tool_display_name', $columns)) {
        echo "需要添加 tool_display_name 字段\n";

        // 添加字段
        $pdo->exec("ALTER TABLE tool_settings ADD COLUMN `tool_display_name` VARCHAR(100) DEFAULT NULL COMMENT '工具显示名称' AFTER `tool_name`");

        echo "✓ 已添加 tool_display_name 字段\n\n";

        // 更新现有数据，设置默认的显示名称
        $displayNames = [
            'writing' => '写作助手',
            'translation' => '翻译助手',
            'programming' => '编程助手'
        ];

        foreach ($displayNames as $toolName => $displayName) {
            $stmt = $pdo->prepare("UPDATE tool_settings SET tool_display_name = ? WHERE tool_name = ?");
            $stmt->execute([$displayName, $toolName]);
            echo "✓ 已更新 {$toolName} 的显示名称为: {$displayName}\n";
        }

        // 再次检查表结构
        $stmt = $pdo->query("DESCRIBE tool_settings");
        $newColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "\n修复后的表字段: " . implode(', ', $newColumns) . "\n";
    } else {
        echo "✓ tool_display_name 字段已存在，无需修改\n";
    }

    // 显示当前数据
    echo "\n当前工具设置数据:\n";
    $stmt = $pdo->query("SELECT * FROM tool_settings");
    $tools = $stmt->fetchAll();
    foreach ($tools as $tool) {
        echo "  - {$tool['tool_name']}: {$tool['tool_display_name']}\n";
    }

    echo "</pre>";

} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
    echo "<br>堆栈跟踪:<br>";
    echo nl2br($e->getTraceAsString());
}
