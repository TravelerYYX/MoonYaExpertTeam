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
    echo json_encode(['success' => false, 'message' => '数据库连接失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $stmt = $pdo->query("SELECT * FROM mobile_updates ORDER BY created_at DESC");
            echo json_encode(['success' => true, 'data' => ['updates' => $stmt->fetchAll()]], JSON_UNESCAPED_UNICODE);
            break;

        case 'latest':
            $stmt = $pdo->query("SELECT * FROM mobile_updates WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1");
            echo json_encode(['success' => true, 'data' => ['update' => $stmt->fetch()]], JSON_UNESCAPED_UNICODE);
            break;

        case 'get':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'ID不能为空'], JSON_UNESCAPED_UNICODE); exit; }
            $stmt = $pdo->prepare("SELECT * FROM mobile_updates WHERE id = ?");
            $stmt->execute([$id]);
            $update = $stmt->fetch();
            if (!$update) { echo json_encode(['success' => false, 'message' => '移动端更新不存在'], JSON_UNESCAPED_UNICODE); exit; }
            echo json_encode(['success' => true, 'data' => ['update' => $update]], JSON_UNESCAPED_UNICODE);
            break;

        case 'create':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['version']) || empty($data['title']) || empty($data['content'])) {
                echo json_encode(['success' => false, 'message' => '版本号、标题和内容不能为空'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!preg_match('/^\d+(\.\d+)*$/', $data['version'])) {
                echo json_encode(['success' => false, 'message' => '版本号格式不正确，应为如 1.2.3 的格式'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            try {
                $stmt = $pdo->prepare("INSERT INTO mobile_updates (version, title, content, download_url, is_force, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$data['version'], $data['title'], $data['content'], $data['download_url'] ?? '', $data['is_force'] ?? 0, $data['is_active'] ?? 1]);
                echo json_encode(['success' => true, 'message' => '移动端更新创建成功', 'id' => $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    echo json_encode(['success' => false, 'message' => '版本号已存在'], JSON_UNESCAPED_UNICODE);
                } else {
                    throw $e;
                }
            }
            break;

        case 'update':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            if (!$id) { echo json_encode(['success' => false, 'message' => 'ID不能为空'], JSON_UNESCAPED_UNICODE); exit; }
            if (empty($data['version']) || empty($data['title']) || empty($data['content'])) {
                echo json_encode(['success' => false, 'message' => '版本号、标题和内容不能为空'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE mobile_updates SET version = ?, title = ?, content = ?, download_url = ?, is_force = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$data['version'], $data['title'], $data['content'], $data['download_url'] ?? '', $data['is_force'] ?? 0, $data['is_active'] ?? 1, $id]);
            echo json_encode(['success' => true, 'message' => '移动端更新修改成功'], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            if (!$id) { echo json_encode(['success' => false, 'message' => 'ID不能为空'], JSON_UNESCAPED_UNICODE); exit; }
            $stmt = $pdo->prepare("DELETE FROM mobile_updates WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => '移动端更新删除成功'], JSON_UNESCAPED_UNICODE);
            break;

        case 'toggle':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            if (!$id) { echo json_encode(['success' => false, 'message' => 'ID不能为空'], JSON_UNESCAPED_UNICODE); exit; }
            $stmt = $pdo->prepare("UPDATE mobile_updates SET is_active = ? WHERE id = ?");
            $stmt->execute([$data['is_active'] ?? 1, $id]);
            echo json_encode(['success' => true, 'message' => '移动端更新状态更新成功'], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
