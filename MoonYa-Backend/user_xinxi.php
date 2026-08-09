<?php
session_start();
require_once __DIR__ . '/../config.php';
$config = require __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    $stmt = $pdo->prepare("SELECT id, username, email, real_name, avatar FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: ../index.php');
        exit;
    }

    $defaultAvatar = '/image/mr.png';
    $userAvatar = !empty($user['avatar']) ? '../' . $user['avatar'] : $defaultAvatar;
} catch (Exception $e) {
    die('数据库错误: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户中心 - 雅泫云AI</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
            min-height: 100vh;
        }

        .header {
            background: white;
            color: #333;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-bottom: 1px solid #e8e8e8;
        }

        .header h1 {
            font-size: 24px;
            font-weight: 600;
        }

        .back-btn {
            padding: 8px 20px;
            background: white;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            color: #333;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s;
        }

        .back-btn:hover {
            background: #f5f5f5;
            border-color: #bfbfbf;
        }

        .container {
            max-width: 100%;
            margin: 40px auto;
            padding: 0 20px;
        }

        
        .user-info-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e8e8e8;
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
        }

        .user-account {
            font-size: 14px;
            color: #999;
        }

        .menu-list {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 18px 24px;
            cursor: pointer;
            transition: background-color 0.3s;
            border-bottom: 1px solid #f5f5f5;
            text-decoration: none;
            color: inherit;
        }

        .menu-item:last-child {
            border-bottom: none;
        }

        .menu-item:hover {
            background-color: #fafafa;
        }

        .menu-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-right: 16px;
        }

        .menu-icon.profile {}

        .menu-icon.settings {
            background: linear-gradient(135deg, #722ed1 0%, #531dab 100%);
        }

        .menu-icon.logout {
            background: linear-gradient(135deg, #fa8c16 0%, #d4380d 100%);
        }

        .menu-text {
            flex: 1;
            font-size: 16px;
            font-weight: 500;
            color: #333;
        }

        .menu-arrow {
            font-size: 18px;
            color: #d9d9d9;
        }

        .bg-tx1 {
            background-size: cover;
            background-image: url("/image/user.png");
        }

        .bg-tx2 {
            background-size: cover;
            background-image: url("/image/mima.png");
        }

        .bg-tx3 {
            background-size: cover;
            background-image: url("/image/exit.png");
        }

        .alert {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 8px;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            animation: slideIn 0.3s ease;
            display: none;
        }

        .alert.show {
            display: block;
        }

        .alert.success {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            color: #52c41a;
        }

        .alert.error {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: #ff4d4f;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(100px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    </style>
</head>

<body>
    <div id="alert" class="alert"></div>



    <div class="container">
        <div class="user-info-card">
            <img src="<?php echo htmlspecialchars($userAvatar); ?>" alt="用户头像" class="user-avatar">
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($user['real_name'] ?? $user['username']); ?></div>
                <div class="user-account">账号: <?php echo htmlspecialchars($user['username']); ?></div>
            </div>
        </div>

        <div class="menu-list">
            <a href="edit_profile.php" class="menu-item">
                <div class="menu-icon bg-tx1"></div>
                <span class="menu-text">编辑资料</span>
                <span class="menu-arrow">›</span>
            </a>
            <a href="account_settings.php" class="menu-item">
                <div class="menu-icon bg-tx2"></div>
                <span class="menu-text">修改密码</span>
                <span class="menu-arrow">›</span>
            </a>
            <a class="menu-item" onclick="logout()">
                <div class="menu-icon bg-tx3"></div>
                <span class="menu-text">退出登录</span>
                <span class="menu-arrow">›</span>
            </a>
            
                <a href="../index.php" class="menu-item">
                    <div class="menu-icon bg-tx3"></div>
                    <span class="menu-text">返回首页</span>
                    <span class="menu-arrow">›</span>
                </a>
            
        </div>
    </div>

    <script>
        function showAlert(type, message) {
            const alertDiv = document.getElementById('alert');
            alertDiv.className = 'alert alert-' + type + ' show';
            alertDiv.textContent = message;
            setTimeout(() => {
                alertDiv.className = 'alert';
            }, 3000);
        }

        function logout() {
            if (confirm('确定要退出登录吗？')) {
                fetch('../user_auth.php?action=logout', {
                        method: 'GET',
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // 清除token
                            localStorage.removeItem('api_token');
                            localStorage.removeItem('api_token_expires');
                            window.location.href = '../index.php';
                        } else {
                            showAlert('error', data.error || '退出失败');
                        }
                    })
                    .catch(error => {
                        showAlert('error', '退出失败，请稍后重试');
                    });
            }
        }
    </script>
</body>

</html>