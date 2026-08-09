<?php
/**
 * 修复管理员密码（将明文密码转换为哈希）
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

try {
    $config = require __DIR__ . '/config.php';
    $db = new Database($config);
    $pdo = $db->getConnection();

    echo "<h2>修复管理员密码</h2>";
    echo "<pre>";

    // 获取所有管理员账号
    $stmt = $pdo->query("SELECT id, username, password FROM admins");
    $admins = $stmt->fetchAll();

    foreach ($admins as $admin) {
        $currentPassword = $admin['password'];

        // 检查密码是否已经是哈希格式（哈希通常以 $2y$ 开头）
        if (strpos($currentPassword, '$2y$') === 0 || strpos($currentPassword, '$2a$') === 0) {
            echo "用户 {$admin['username']} 的密码已经是哈希格式，跳过...\n";
            continue;
        }

        // 将明文密码转换为哈希
        $hashedPassword = password_hash($currentPassword, PASSWORD_DEFAULT);

        // 更新数据库
        $updateStmt = $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?");
        $updateStmt->execute([$hashedPassword, $admin['id']]);

        echo "✓ 已修复用户 {$admin['username']} 的密码\n";
        echo "  原密码: {$currentPassword}\n";
        echo "  新哈希: {$hashedPassword}\n\n";
    }

    echo "</pre>";
    echo "<p><a href='check_admin.php'>查看修复结果</a></p>";

} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
}
