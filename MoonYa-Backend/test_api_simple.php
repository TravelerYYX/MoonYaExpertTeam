<?php
/**
 * 简化版API测试
 * 上传到服务器后访问此文件来测试API是否正常工作
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== API简化测试 ===\n\n";

// 步骤1: 加载配置
echo "1. 加载config.php...\n";
try {
    $config = require_once __DIR__ . '/config.php';
    echo "   ✓ 配置加载成功\n";
} catch (Exception $e) {
    echo "   ✗ 失败: " . $e->getMessage() . "\n";
    exit;
}

// 步骤2: 连接数据库
echo "2. 连接数据库...\n";
try {
    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    echo "   ✓ 数据库连接成功\n";
} catch (PDOException $e) {
    echo "   ✗ 失败: " . $e->getMessage() . "\n";
    exit;
}

// 步骤3: 检查users表
echo "3. 检查users表...\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $count = $stmt->fetch()['count'];
    echo "   ✓ users表正常，共有 $count 个用户\n";
} catch (PDOException $e) {
    echo "   ✗ 失败: " . $e->getMessage() . "\n";
    exit;
}

// 步骤4: 检查api_token字段
echo "4. 检查api_token字段...\n";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'api_token'");
    if ($stmt->fetch()) {
        echo "   ✓ api_token字段存在\n";
    } else {
        echo "   ✗ api_token字段不存在\n";
    }
} catch (PDOException $e) {
    echo "   ✗ 失败: " . $e->getMessage() . "\n";
}

// 步骤5: 模拟API请求处理
echo "5. 模拟API请求处理...\n";

// 获取请求头
$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    echo "   ✓ getallheaders()可用\n";
} else {
    echo "   ⚠ getallheaders()不可用，使用$_SERVER替代\n";
}

// 检查Authorization头
$authHeader = null;
if (isset($headers['Authorization'])) {
    $authHeader = $headers['Authorization'];
    echo "   ✓ 找到Authorization头\n";
} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    echo "   ✓ 找到HTTP_AUTHORIZATION\n";
} else {
    echo "   ⚠ 未找到Authorization头\n";
}

// 提取token
$token = null;
if ($authHeader) {
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        echo "   ✓ 提取到Bearer Token (长度: " . strlen($token) . ")\n";
    } else {
        $token = $authHeader;
        echo "   ⚠ Authorization头不包含Bearer前缀\n";
    }
}

// 验证token
if ($token) {
    echo "6. 验证token...\n";
    try {
        $stmt = $pdo->prepare("SELECT id, username, api_token, token_created_at FROM users WHERE api_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            echo "   ✓ Token验证成功，用户: " . $user['username'] . "\n";
        } else {
            echo "   ✗ Token验证失败: 数据库中找不到该token\n";
        }
    } catch (PDOException $e) {
        echo "   ✗ 验证失败: " . $e->getMessage() . "\n";
    }
} else {
    echo "6. 跳过token验证（未提供token）\n";
}

// 步骤7: 检查session
echo "7. 检查session...\n";
session_start();
if (isset($_SESSION['user_id'])) {
    echo "   ✓ Session中有user_id: " . $_SESSION['user_id'] . "\n";
} else {
    echo "   ⚠ Session中没有user_id（用户未通过session登录）\n";
}

echo "\n=== 测试完成 ===\n";
echo "\n结论:\n";
if ($token && $user) {
    echo "✓ Token验证正常工作\n";
} elseif (isset($_SESSION['user_id'])) {
    echo "✓ Session验证可用（但Token验证可能有问题）\n";
} else {
    echo "✗ 用户未登录，需要先登录才能使用API\n";
}
