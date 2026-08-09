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
    
    $stmt = $pdo->prepare("SELECT id, username, email, password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        session_destroy();
        header('Location: ../index.php');
        exit;
    }
    
    $hasPassword = !empty($user['password']);
    
} catch (Exception $e) {
    die('数据库错误: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>修改密码 - 雅泫云AI</title>
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
        .exittop {
    padding-left: 0;
    display: inline-block;
    text-decoration: none;
}
.exittop img {
    width: 40px;
    height: 40px;
    border: none;
}
.header-title{
    font-size: 18px;
    text-align: center;
    margin-top: -38px;
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
            max-width: 600px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f5f5f5;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: all 0.3s;
        }
        
        .form-group input:focus {
            border-color: #0057ff;
       
        }
        
        .form-group input[readonly] {
            background-color: #fafafa;
            color: #999;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
        }
        
        .btn-primary {
            background: #0057ff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #40a9ff;
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
                .toast {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
            pointer-events: none;
        }

        .toast.show {
            opacity: 1;
            visibility: visible;
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
         <a href="user_xinxi.php" class="exittop">
            <img src="/image/exit2.png" alt="退出">
        </a>
        <h1 class="header-title">修改密码</h1>
    </div>
     
    



    <div class="container">
       
            <h2 class="card-title"><?php echo $hasPassword ? '修改密码' : '设置密码'; ?></h2>
            <?php if (!$hasPassword): ?>
            <div style="padding: 12px 16px; background: #fff7e6; border: 1px solid #ffd591; border-radius: 8px; margin-bottom: 20px; font-size: 14px; color: #d48806;">
                您还未设置密码，设置密码后可使用账号密码方式登录。账号为：<strong><?php echo htmlspecialchars($user['username']); ?></strong>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label><?php echo $hasPassword ? '新密码' : '设置密码'; ?></label>
                <input type="password" id="newPassword" placeholder="请输入<?php echo $hasPassword ? '新' : ''; ?>密码">
            </div>
            <div class="form-group">
                <label>确认<?php echo $hasPassword ? '新' : ''; ?>密码</label>
                <input type="password" id="confirmNewPassword" placeholder="请再次输入<?php echo $hasPassword ? '新' : ''; ?>密码">
            </div>
            <button class="btn btn-primary" onclick="updatePassword()"><?php echo $hasPassword ? '保存密码' : '设置密码'; ?></button>
    
    </div>
    
    <script>

        
        async function updatePassword() {
            const newPassword = document.getElementById('newPassword').value;
            const confirmNewPassword = document.getElementById('confirmNewPassword').value;
            const hasPassword = <?php echo $hasPassword ? 'true' : 'false'; ?>;
            
            if (!newPassword) {
         
                 showToast('请输入密码');
                return;
            }
            
            if (newPassword !== confirmNewPassword) {
      
                showToast('两次输入的密码不一致');
                return;
            }
            
            if (newPassword.length < 8) {
         
                showToast('密码长度至少8位');
                return;
            }
            
            try {
                const action = hasPassword ? 'update_password' : 'set_password';
                const response = await fetch('../user_profile.php?action=' + action, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        password: newPassword
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('newPassword').value = '';
                    document.getElementById('confirmNewPassword').value = '';
                    showAlert('success', hasPassword ? '密码修改成功' : '密码设置成功');
                    if (!hasPassword) {
                        setTimeout(() => { location.reload(); }, 1500);
                    }
                } else {
                    showAlert('error', data.error || '操作失败');
                }
            } catch (error) {
                showAlert('error', '操作失败，请稍后重试');
            }
        }
        
        function showToast(message) {
            const toast = document.createElement('div');
            toast.className = 'toast show';
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }
    </script>
</body>
</html>