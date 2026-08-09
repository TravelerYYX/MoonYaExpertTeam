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
            $stmt = $pdo->query("SELECT id, image_url, jump_url, sort_order, is_active, created_at, updated_at FROM splash_pages ORDER BY sort_order ASC, id ASC");
            echo json_encode(['success' => true, 'data' => ['splash_pages' => $stmt->fetchAll()]], JSON_UNESCAPED_UNICODE);
            break;

        case 'active':
            $stmt = $pdo->query("SELECT id, image_url, jump_url, sort_order, is_active, created_at, updated_at FROM splash_pages WHERE is_active = 1 ORDER BY RAND() LIMIT 1");
            echo json_encode(['success' => true, 'data' => ['splash_page' => $stmt->fetch() ?: null]], JSON_UNESCAPED_UNICODE);
            break;

        case 'get':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => '启动页ID不能为空'], JSON_UNESCAPED_UNICODE); exit; }
            $stmt = $pdo->prepare("SELECT id, image_url, jump_url, sort_order, is_active, created_at, updated_at FROM splash_pages WHERE id = ?");
            $stmt->execute([$id]);
            $page = $stmt->fetch();
            if (!$page) { echo json_encode(['success' => false, 'message' => '启动页不存在'], JSON_UNESCAPED_UNICODE); exit; }
            echo json_encode(['success' => true, 'data' => ['splash_page' => $page]], JSON_UNESCAPED_UNICODE);
            break;

        case 'add':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['image_url'])) { echo json_encode(['success' => false, 'message' => '启动页图片链接不能为空'], JSON_UNESCAPED_UNICODE); exit; }
            $stmt = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM splash_pages");
            $nextOrder = $stmt->fetch()['next_order'];
            $stmt = $pdo->prepare("INSERT INTO splash_pages (image_url, jump_url, sort_order, is_active) VALUES (?, ?, ?, ?)");
            $stmt->execute([$data['image_url'], $data['jump_url'] ?? '', $data['sort_order'] ?? $nextOrder, $data['is_active'] ?? 1]);
            echo json_encode(['success' => true, 'message' => '启动页添加成功', 'id' => $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
            break;

        case 'update':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['id'])) { echo json_encode(['success' => false, 'message' => '启动页ID不能为空'], JSON_UNESCAPED_UNICODE); exit; }
            if (empty($data['image_url'])) { echo json_encode(['success' => false, 'message' => '启动页图片链接不能为空'], JSON_UNESCAPED_UNICODE); exit; }
            $stmt = $pdo->prepare("UPDATE splash_pages SET image_url = ?, jump_url = ?, sort_order = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$data['image_url'], $data['jump_url'] ?? '', $data['sort_order'] ?? 0, $data['is_active'] ?? 1, $data['id']]);
            echo json_encode(['success' => true, 'message' => '启动页更新成功'], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['id'])) { echo json_encode(['success' => false, 'message' => '启动页ID不能为空'], JSON_UNESCAPED_UNICODE); exit; }
            $stmt = $pdo->prepare("DELETE FROM splash_pages WHERE id = ?");
            $stmt->execute([$data['id']]);
            echo json_encode(['success' => true, 'message' => '启动页删除成功'], JSON_UNESCAPED_UNICODE);
            break;

        case 'toggle':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['id'])) { echo json_encode(['success' => false, 'message' => '启动页ID不能为空'], JSON_UNESCAPED_UNICODE); exit; }
            $stmt = $pdo->prepare("UPDATE splash_pages SET is_active = ? WHERE id = ?");
            $stmt->execute([$data['is_active'] ?? 1, $data['id']]);
            echo json_encode(['success' => true, 'message' => '启动页状态更新成功'], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
