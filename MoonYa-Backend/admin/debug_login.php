<?php
/**
 * 调试登录流程
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Logger.php';

try {
    $config = require __DIR__ . '/config.php';
    $db = new Database($config);
    $pdo = $db->getConnection();
    $logger = new Logger($config, $pdo);
    $auth = new Auth($pdo, $config, $logger);

    echo "<h2>登录调试</h2>";
    echo "<pre>";

    // 测试用户名和密码
    $testUsername = 'yueyaxuan';
    $testPassword = '20091201';

    echo "测试登录:\n";
    echo "用户名: {$testUsername}\n";
    echo "密码: {$testPassword}\n\n";

    // 1. 检查用户是否存在
    $stmt = $pdo->prepare("SELECT id, username, password, email, role FROM admins WHERE username = ?");
    $stmt->execute([$testUsername]);
    $admin = $stmt->fetch();

    if (!$admin) {
        echo "✗ 用户不存在！\n";

        // 列出所有用户
        echo "\n数据库中的用户:\n";
        $stmt = $pdo->query("SELECT username FROM admins");
        $users = $stmt->fetchAll();
        foreach ($users as $user) {
            echo "  - {$user['username']}\n";
        }
    } else {
        echo "✓ 用户存在\n";
        echo "  ID: {$admin['id']}\n";
        echo "  用户名: {$admin['username']}\n";
        echo "  密码字段: {$admin['password']}\n";
        echo "  邮箱: {$admin['email']}\n";
        echo "  角色: {$admin['role']}\n\n";

        // 2. 测试密码验证
        echo "密码验证测试:\n";
        $verifyResult = password_verify($testPassword, $admin['password']);
        echo "  password_verify('{$testPassword}', '{$admin['password']}') = " . ($verifyResult ? 'true' : 'false') . "\n\n";

        // 3. 尝试完整登录流程
        echo "尝试完整登录流程:\n";
        $result = $auth->login($testUsername, $testPassword);
        if ($result) {
            echo "✓ 登录成功！\n";
            echo "  Token: " . substr($result['token'], 0, 50) . "...\n";
        } else {
            echo "✗ 登录失败\n";
        }
    }

    echo "</pre>";

} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
    echo "<br>堆栈跟踪:<br>";
    echo nl2br($e->getTraceAsString());
}
