<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/config.php';

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
    
    // 检查是否有用户ID在会话中
    if (!isset($_SESSION['user_id'])) {
        echo '<h2>请先登录后台管理系统</h2>';
        echo '<p><a href="../admin/login.php">去登录</a></p>';
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    
    echo '<h1>📷 历史图片数据调试</h1>';
    echo '<p>用户ID: ' . $userId . '</p>';
    echo '<hr>';
    
    // 获取用户的所有对话
    $stmt = $pdo->prepare("SELECT id, title, created_at FROM conversations WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$userId]);
    $conversations = $stmt->fetchAll();
    
    if (count($conversations) === 0) {
        echo '<p>没有找到对话记录</p>';
        exit;
    }
    
    foreach ($conversations as $conv) {
        echo '<h3>对话: ' . htmlspecialchars($conv['title']) . ' (' . $conv['created_at'] . ')</h3>';
        
        // 获取该对话的所有消息
        $stmt = $pdo->prepare("SELECT id, role, content, images, created_at FROM messages WHERE conversation_id = ? AND user_id = ? ORDER BY created_at ASC");
        $stmt->execute([$conv['id'], $userId]);
        $messages = $stmt->fetchAll();
        
        if (count($messages) === 0) {
            echo '<p>此对话没有消息</p>';
            continue;
        }
        
        foreach ($messages as $msg) {
            echo '<div style="margin: 10px 0; padding: 10px; background: #f5f5f5; border-radius: 8px;">';
            echo '<strong>' . htmlspecialchars($msg['role']) . '</strong> - ' . $msg['created_at'] . '<br>';
            
            if (!empty($msg['images'])) {
                $images = json_decode($msg['images'], true);
                echo '<p><strong>图片数据:</strong></p>';
                echo '<ul>';
                foreach ($images as $idx => $img) {
                    echo '<li>';
                    echo '<strong>图片 ' . ($idx + 1) . ':</strong><br>';
                    echo '值: ' . htmlspecialchars($img) . '<br>';
                    echo '类型: ' . gettype($img) . '<br>';
                    
                    if (is_string($img)) {
                        if (strpos($img, 'ms://') === 0) {
                            echo '<span style="color: green;">✓ 正确的 ms:// 格式</span><br>';
                            $imgUrl = 'file_content.php?file_id=' . urlencode($img);
                            echo '<img src="' . htmlspecialchars($imgUrl) . '" style="max-width: 200px; margin: 10px 0; border: 1px solid #ccc;"><br>';
                        } elseif (strpos($img, 'blob:') === 0) {
                            echo '<span style="color: orange;">⚠ blob URL（临时格式，刷新后无效）</span><br>';
                        } elseif (strpos($img, 'data:') === 0) {
                            echo '<span style="color: blue;">✓ base64 格式</span><br>';
                            echo '<img src="' . htmlspecialchars($img) . '" style="max-width: 200px; margin: 10px 0; border: 1px solid #ccc;"><br>';
                        } elseif (strpos($img, 'http') === 0) {
                            echo '<span style="color: blue;">✓ HTTP URL</span><br>';
                            echo '<img src="' . htmlspecialchars($img) . '" style="max-width: 200px; margin: 10px 0; border: 1px solid #ccc;"><br>';
                        } else {
                            echo '<span style="color: red;">✗ 未知格式</span><br>';
                        }
                    }
                    echo '</li>';
                }
                echo '</ul>';
            } else {
                echo '<p>无图片</p>';
            }
            
            echo '</div>';
        }
        
        echo '<hr>';
    }
    
} catch (PDOException $e) {
    echo '<h2>错误</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>
