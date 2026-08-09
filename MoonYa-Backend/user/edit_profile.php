<?php
session_start();
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

    $stmt = $pdo->prepare("SELECT id, username, email, real_name, gender, avatar, password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: ../index.php');
        exit;
    }

    $hasPassword = !empty($user['password']);
    $currentGender = $user['gender'] ?? null;

    $defaultAvatar = trim((string)($config['profile']['default_avatar_url'] ?? ''));
    if ($defaultAvatar === '') throw new RuntimeException('Missing required configuration: profile.default_avatar_url');
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
    <title>编辑资料 - 雅泫云AI</title>
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
            max-width: 800px;
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
            border-color: #1890ff;
            box-shadow: 0 0 0 3px rgba(24, 144, 255, 0.1);
        }

        .form-group input[readonly] {
            background-color: #fafafa;
            color: #999;
        }

        .avatar-section {
            text-align: center;
            padding: 20px 0;
            position: relative;
            width: 120px;
            margin: 0 auto;
            cursor: pointer;
        }

        .avatar-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 20px;
            display: block;
            border: 4px solid #e8e8e8;
        }

        .avatar-upload-wrapper {
            position: absolute;
            bottom: 28px;
            right: 8px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            overflow: hidden;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .avatar-upload-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-upload-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
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
            margin-top: 20px;
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

        .gender-options {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .gender-option {
            flex: 1 1 0;
            min-width: 90px;
            padding: 12px 16px;
            border: 1px solid #e8e8e8;
            background: #fff;
            border-radius: 8px;
            font-size: 14px;
            color: #333;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s, color 0.2s;
            text-align: center;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }

        .gender-option:hover {
            border-color: #bfbfbf;
        }

        .gender-option.selected {
            border-color: #0057ff;
            background: #E6F0FF;
            color: #0057ff;
            font-weight: 500;
        }

        .gender-option .gender-icon {
            margin-right: 4px;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <div id="alert" class="alert"></div>

    <div class="container">
         <div class="exittop" onclick="location.href='user_xinxi.php'" style="cursor:pointer;">
            <img src="/image/exit2.png" alt="退出">
        </div>
    </div>

    <div class="container">
        <div class="avatar-section" onclick="document.getElementById('avatarInput').click()">
            <img id="avatarPreview" src="<?php echo htmlspecialchars($userAvatar); ?>" alt="用户头像" class="avatar-preview">
            <div class="avatar-upload-wrapper" onclick="event.stopPropagation()">
                <img src="/image/tp.png" alt="上传头像" class="avatar-upload-img">
                <input type="file" id="avatarInput" class="avatar-upload-input" accept="image/*">
            </div>
        </div>

        <div class="form-group">
            <label>账号</label>
            <input type="text" id="newUsername" value="<?php echo htmlspecialchars($user['username']); ?>" readonly style="background-color: #fafafa; color: #999;">
        </div>
        
        <div class="form-group">
            <label>邮箱</label>
            <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly style="background-color: #fafafa; color: #999;">
        </div>

        <div class="form-group">
            <label>昵称</label>
            <input type="text" id="newRealName" placeholder="请输入昵称" value="<?php echo htmlspecialchars($user['real_name'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label>性别</label>
            <div class="gender-options" id="genderOptions">
                <div class="gender-option<?php echo $currentGender === 'male' ? ' selected' : ''; ?>" data-value="male"><span class="gender-icon">♂</span>男</div>
                <div class="gender-option<?php echo $currentGender === 'female' ? ' selected' : ''; ?>" data-value="female"><span class="gender-icon">♀</span>女</div>
                <div class="gender-option<?php echo $currentGender === 'private' ? ' selected' : ''; ?>" data-value="private"><span class="gender-icon">⊕</span>保密</div>
            </div>
        </div>

        <?php if (!$hasPassword): ?>
        <div style="padding: 12px 16px; background: #fff7e6; border: 1px solid #ffd591; border-radius: 8px; margin-bottom: 20px; font-size: 14px; color: #d48806;">
            您还未设置密码，<a href="account_settings.php" style="color: #1890ff;">点此设置密码</a>后可使用账号密码方式登录
        </div>
        <?php endif; ?>

        <button class="btn btn-primary" onclick="saveAllProfile()">完成</button>
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

        async function updateRealName(newRealName) {
            try {
                const response = await fetch('../user_profile.php?action=update_real_name', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ real_name: newRealName })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || '昵称修改失败');
                return true;
            } catch (error) {
                throw error;
            }
        }

        async function updateGender(gender) {
            try {
                const response = await fetch('../user_profile.php?action=update_gender', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ gender: gender })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || '性别修改失败');
                return true;
            } catch (error) {
                throw error;
            }
        }

        // 性别单选卡片点击切换
        document.querySelectorAll('.gender-option').forEach(function(opt) {
            opt.addEventListener('click', function() {
                document.querySelectorAll('.gender-option').forEach(function(o) {
                    o.classList.remove('selected');
                });
                this.classList.add('selected');
            });
        });

        function getSelectedGender() {
            const el = document.querySelector('.gender-option.selected');
            return el ? el.getAttribute('data-value') : null;
        }

        // 记录当前数据库中的性别（用于未选择时跳过请求）
        const initialGender = <?php echo json_encode($currentGender, JSON_UNESCAPED_UNICODE); ?>;

        async function saveAllProfile() {
            const newRealName = document.getElementById('newRealName').value.trim();

            if (!newRealName) {
                showAlert('error', '请输入昵称');
                return;
            }

            const saveBtn = document.querySelector('.btn-primary');
            saveBtn.disabled = true;
            saveBtn.textContent = '保存中...';

            try {
                const selectedGender = getSelectedGender();
                const tasks = [updateRealName(newRealName)];
                // 仅当用户做出了选择（与初始值不同或显式有值）时才提交性别
                if (selectedGender && selectedGender !== initialGender) {
                    tasks.push(updateGender(selectedGender));
                } else if (selectedGender) {
                    // 显式再次点击同一选项也允许幂等保存
                    tasks.push(updateGender(selectedGender));
                }
                await Promise.all(tasks);

                showAlert('success', '资料修改成功');
            } catch (error) {
                showAlert('error', error.message || '修改失败，请稍后重试');
                console.error('保存失败：', error);
            } finally {
                saveBtn.disabled = false;
                saveBtn.textContent = '完成';
            }
        }

        document.getElementById('avatarInput').addEventListener('change', async function(e) {
            const file = e.target.files[0];
            if (!file) return;

            if (!file.type.startsWith('image/')) {
                showAlert('error', '请选择图片文件');
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                showAlert('error', '图片大小不能超过5MB');
                return;
            }

            const formData = new FormData();
            formData.append('avatar', file);

            try {
                const response = await fetch('../user_profile.php?action=upload_avatar', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                if (data.success) {
                    document.getElementById('avatarPreview').src = data.data.avatar_url + '?t=' + Date.now();
                    showAlert('success', '头像上传成功');
                } else {
                    showAlert('error', data.error || '头像上传失败');
                }
            } catch (error) {
                showAlert('error', '上传失败，请稍后重试');
                console.error('头像上传失败：', error);
            }
        });
    </script>
</body>
</html>
