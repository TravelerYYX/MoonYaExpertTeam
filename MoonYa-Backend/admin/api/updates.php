<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';

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
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            // 获取所有版本更新列表
            $stmt = $pdo->query("SELECT * FROM version_updates ORDER BY created_at DESC");
            $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $updates]);
            break;
            
        case 'latest':
            // 获取最新版本（用于前端弹窗）
            $stmt = $pdo->query("SELECT * FROM version_updates WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1");
            $update = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $update]);
            break;
            
        case 'create':
            // 创建新版本
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['version']) || empty($data['title']) || empty($data['content'])) {
                echo json_encode(['success' => false, 'message' => '版本号、标题和内容不能为空']);
                exit;
            }
            
            // 验证版本号格式（支持 x.x.x 格式）
            if (!preg_match('/^\d+(\.\d+)*$/', $data['version'])) {
                echo json_encode(['success' => false, 'message' => '版本号格式不正确，应为如 1.2.3 的格式']);
                exit;
            }
            
            if (!empty($data['video_url']) && !empty($data['image_url'])) {
                echo json_encode(['success' => false, 'message' => '视频链接和图片链接只能填写一个']);
                exit;
            }
            
            try {
                $stmt = $pdo->prepare("INSERT INTO version_updates (version, title, content, video_url, image_url, is_force, is_active, close_delay) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data['version'],
                    $data['title'],
                    $data['content'],
                    $data['video_url'] ?? '',
                    $data['image_url'] ?? '',
                    $data['is_force'] ?? 0,
                    $data['is_active'] ?? 1,
                    $data['close_delay'] ?? 0
                ]);
                echo json_encode(['success' => true, 'message' => '版本更新创建成功']);
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    echo json_encode(['success' => false, 'message' => '版本号已存在']);
                } else {
                    throw $e;
                }
            }
            break;
            
        case 'update':
            // 更新版本信息
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID不能为空']);
                exit;
            }
            
            if (!empty($data['video_url']) && !empty($data['image_url'])) {
                echo json_encode(['success' => false, 'message' => '视频链接和图片链接只能填写一个']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE version_updates SET version = ?, title = ?, content = ?, video_url = ?, image_url = ?, is_force = ?, is_active = ?, close_delay = ? WHERE id = ?");
            $stmt->execute([
                $data['version'],
                $data['title'],
                $data['content'],
                $data['video_url'] ?? '',
                $data['image_url'] ?? '',
                $data['is_force'] ?? 0,
                $data['is_active'] ?? 1,
                $data['close_delay'] ?? 0,
                $id
            ]);
            
            echo json_encode(['success' => true, 'message' => '版本更新修改成功']);
            break;
            
        case 'delete':
            // 删除版本
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID不能为空']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM version_updates WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => '版本更新删除成功']);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
