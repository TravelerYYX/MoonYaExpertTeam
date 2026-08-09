<?php
/**
 * 检查管理员账号信息（调试用）
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

try {
    $config = require __DIR__ . '/config.php';
    $db = new Database($config);
    $pdo = $db->getConnection();

    echo "<h2>管理员账号信息</h2>";
    echo "<pre>";

    // 检查表是否存在
    $stmt = $pdo->query("SHOW TABLES LIKE 'admins'");
    if ($stmt->rowCount() == 0) {
        echo "admins 表不存在！\n";
        exit;
    }
    echo "✓ admins 表存在\n\n";

    // 获取所有管理员账号
    $stmt = $pdo->query("SELECT id, username, password, email, role, created_at FROM admins");
    $admins = $stmt->fetchAll();

    if (empty($admins)) {
        echo "没有管理员账号！\n";
    } else {
        echo "找到 " . count($admins) . " 个管理员账号:\n\n";
        foreach ($admins as $admin) {
            echo "ID: " . $admin['id'] . "\n";
            echo "用户名: " . $admin['username'] . "\n";
            echo "密码哈希: " . $admin['password'] . "\n";
            echo "邮箱: " . $admin['email'] . "\n";
            echo "角色: " . $admin['role'] . "\n";
            echo "创建时间: " . $admin['created_at'] . "\n";

            // 测试密码验证
            $testPassword = '20091201';
            $verifyResult = password_verify($testPassword, $admin['password']);
            echo "密码验证 (20091201): " . ($verifyResult ? "✓ 成功" : "✗ 失败") . "\n";

            echo "\n" . str_repeat('-', 50) . "\n\n";
        }
    }

    echo "</pre>";

} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
}
