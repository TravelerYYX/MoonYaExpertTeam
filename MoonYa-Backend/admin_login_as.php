<?php
session_start();

$adminConfig = require_once __DIR__ . '/admin/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . $adminConfig['db_host'] . ";dbname=" . $adminConfig['db_name'] . ";charset=utf8mb4",
        $adminConfig['db_user'],
        $adminConfig['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('<html><body style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;color:#ff4d4f;font-size:16px;">数据库连接失败: ' . htmlspecialchars($e->getMessage()) . '</div></body></html>');
}

try {
    $pdo->query("SELECT 1 FROM admin_login_tokens LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->query("CREATE TABLE IF NOT EXISTS `admin_login_tokens` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `token` VARCHAR(64) NOT NULL,
            `user_id` INT NOT NULL,
            `admin_id` INT NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `used` TINYINT(1) DEFAULT 0,
            `used_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_token` (`token`),
            INDEX `idx_expires` (`expires_at`, `used`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e2) {
        die('<html><body style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;color:#ff4d4f;font-size:16px;">无法创建登录令牌表: ' . htmlspecialchars($e2->getMessage()) . '</div></body></html>');
    }
}

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('<html><body style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;color:#ff4d4f;font-size:18px;">无效的登录链接</div></body></html>');
}

$stmt = $pdo->prepare("SELECT alt.*, u.username, u.email, u.real_name, u.status 
                        FROM admin_login_tokens alt 
                        JOIN users u ON alt.user_id = u.id 
                        WHERE alt.token = ? AND alt.used = 0");
$stmt->execute([$token]);
$loginData = $stmt->fetch();

if (!$loginData) {
    die('<html><body style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;color:#ff4d4f;font-size:18px;">令牌不存在</div></body></html>');
}

if ($loginData['used'] == 1) {
    die('<html><body style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;color:#ff4d4f;font-size:18px;">令牌已被使用过</div></body></html>');
}

$expiresAtTs = strtotime($loginData['expires_at']);
if ($expiresAtTs !== false && time() > $expiresAtTs + 86400) {
    die('<html><body style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;color:#ff4d4f;font-size:18px;">令牌已过期</div></body></html>');
}

$pdo->prepare("UPDATE admin_login_tokens SET used = 1, used_at = NOW() WHERE token = ?")->execute([$token]);

$_SESSION['user_id'] = $loginData['user_id'];
$_SESSION['username'] = $loginData['username'];
$_SESSION['email'] = $loginData['email'];
$_SESSION['status'] = $loginData['status'];
$_SESSION['last_activity'] = time();

setcookie(session_name(), session_id(), time() + 1296000, '/');

$apiToken = bin2hex(random_bytes(32));
$pdo->prepare("UPDATE users SET api_token = ?, token_created_at = NOW() WHERE id = ?")->execute([$apiToken, $loginData['user_id']]);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>代登录中...</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            display: flex; align-items: center; justify-content: center; height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .card {
            background: white; border-radius: 16px; padding: 40px; text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2); max-width: 400px; width: 90%;
        }
        .spinner {
            width: 40px; height: 40px; border: 4px solid #f0f0f0; border-top-color: #667eea;
            border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 20px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        h2 { color: #333; margin-bottom: 8px; font-size: 20px; }
        p { color: #666; font-size: 14px; margin-bottom: 20px; }
        .user-info { background: #f8f9fa; border-radius: 8px; padding: 12px; margin-bottom: 20px; }
        .user-info span { display: block; font-size: 13px; color: #555; }
        .user-info .name { font-size: 16px; font-weight: 600; color: #333; margin-bottom: 4px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner"></div>
        <h2>正在登录</h2>
        <div class="user-info">
            <div class="name"><?php echo htmlspecialchars($loginData['real_name'] ?: $loginData['username']); ?></div>
            <span><?php echo htmlspecialchars($loginData['email']); ?></span>
        </div>
        <p>即将跳转到首页...</p>
    </div>
    <script>
        localStorage.setItem('api_token', '<?php echo $apiToken; ?>');
        localStorage.setItem('api_token_expires', Date.now() + 1296000 * 1000);
        setTimeout(function() {
            window.location.href = '/';
        }, 1500);
    </script>
</body>
</html>
