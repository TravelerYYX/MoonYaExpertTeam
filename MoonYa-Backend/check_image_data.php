<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

$config = require_once __DIR__ . '/config.php';

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
    
    if (!isset($_SESSION['user_id'])) {
        echo '<p>请先登录</p>';
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    
    echo '<h1>图片数据检查</h1>';
    
    // 获取最近的几条包含图片的消息
    $stmt = $pdo->prepare("SELECT id, conversation_id, role, content, images, created_at 
                            FROM messages 
                            WHERE user_id = ? AND images IS NOT NULL AND images != '[]'
                            ORDER BY created_at DESC 
                            LIMIT 10");
    $stmt->execute([$userId]);
    $messages = $stmt->fetchAll();
    
    foreach ($messages as $msg) {
        echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 8px;">';
        echo '<h3>消息 ID: ' . $msg['id'] . ' - ' . $msg['role'] . '</h3>';
        echo '<p>时间: ' . $msg['created_at'] . '</p>';
        echo '<p>对话 ID: ' . $msg['conversation_id'] . '</p>';
        
        $images = json_decode($msg['images'], true);
        if ($images && is_array($images)) {
            echo '<p>图片数量: ' . count($images) . '</p>';
            foreach ($images as $idx => $img) {
                echo '<div style="margin: 10px 0; padding: 10px; background: #f5f5f5;">';
                echo '<p><strong>图片 ' . ($idx + 1) . ':</strong></p>';
                
                if (is_string($img)) {
                    $length = strlen($img);
                    echo '<p>数据长度: ' . $length . ' 字符</p>';
                    
                    if (strpos($img, 'data:') === 0) {
                        echo '<p style="color: green;">✓ base64 格式</p>';
                        // 显示预览
                        echo '<img src="' . htmlspecialchars($img) . '" style="max-width: 200px; border: 1px solid #ccc;">';
                    } elseif (strpos($img, 'ms://') === 0) {
                        echo '<p style="color: orange;">⚠ ms:// 格式（无法直接显示）</p>';
                        echo '<p>值: ' . htmlspecialchars($img) . '</p>';
                    } elseif (strpos($img, 'blob:') === 0) {
                        echo '<p style="color: red;">✗ blob URL（已失效）</p>';
                    } else {
                        echo '<p style="color: gray;">? 其他格式</p>';
                        echo '<p>值: ' . htmlspecialchars(substr($img, 0, 100)) . '...</p>';
                    }
                }
                echo '</div>';
            }
        }
        
        echo '</div>';
    }
    
} catch (PDOException $e) {
    echo '<p style="color:red">错误: ' . $e->getMessage() . '</p>';
}
?>
