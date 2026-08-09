<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../Database.php';
    require_once __DIR__ . '/../Auth.php';
    require_once __DIR__ . '/../Logger.php';

    $config = require __DIR__ . '/../config.php';

    $db = new Database($config);
    $conn = $db->getConnection();

    $logger = new Logger($config, $conn);
    $auth = new Auth($conn, $config, $logger);

    $headers = getallheaders();
    $token = null;
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
    } elseif (isset($headers['authorization'])) {
        $token = str_replace('Bearer ', '', $headers['authorization']);
    }

    $currentAdmin = $auth->authenticate($token);
    if (!$currentAdmin) {
        sendError(401, '未授权访问，请先登录');
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    switch ($action) {
        case 'list':
            listPosts($conn);
            break;
        case 'delete':
            deletePost($conn);
            break;
        case 'stats':
            getCommunityStats($conn);
            break;
        case 'reports':
            listReports($conn);
            break;
        case 'resolve_report':
            resolveReport($conn);
            break;
        case 'delete_report':
            deleteReport($conn);
            break;
        case 'send_notification':
            sendNotification($conn);
            break;
        case 'list_notifications':
            listNotifications($conn);
            break;
        case 'update_notification':
            updateNotification($conn);
            break;
        case 'delete_notification':
            deleteNotificationAdmin($conn);
            break;
        default:
            sendError(400, '无效的操作');
    }
} catch (Exception $e) {
    sendError(500, '服务器错误: ' . $e->getMessage());
}

function listPosts($conn) {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(p.title LIKE ? OR p.content LIKE ? OR u.username LIKE ? OR u.real_name LIKE ?)";
        $searchPattern = '%' . $search . '%';
        $params = array_merge($params, [$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
    }

    $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) as total FROM community_posts p INNER JOIN users u ON p.user_id = u.id $whereClause";
    $stmt = $conn->prepare($countSql);
    $stmt->execute($params);
    $total = intval($stmt->fetch()['total']);

    $sql = "SELECT p.id, p.user_id, p.title, p.content, p.images, p.likes_count, p.comments_count, p.favorites_count, p.created_at,
                   u.username, u.real_name, u.avatar, u.email
            FROM community_posts p
            INNER JOIN users u ON p.user_id = u.id
            $whereClause
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?";

    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($posts as &$post) {
        $post['id'] = intval($post['id']);
        $post['user_id'] = intval($post['user_id']);
        $post['likes_count'] = intval($post['likes_count']);
        $post['comments_count'] = intval($post['comments_count']);
        $post['favorites_count'] = intval($post['favorites_count']);
        if ($post['images']) {
            $post['images'] = json_decode($post['images'], true);
        }
        $post['content_preview'] = mb_strlen($post['content']) > 100 ? mb_substr($post['content'], 0, 100) . '...' : $post['content'];
    }
    unset($post);

    sendSuccess([
        'posts' => $posts,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage
    ]);
}

function deletePost($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $postId = intval($input['id'] ?? 0);

    if ($postId <= 0) sendError(400, '无效的帖子ID');

    $stmt = $conn->prepare("SELECT id, user_id, title FROM community_posts WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) sendError(404, '帖子不存在');

    $stmt = $conn->prepare("DELETE FROM community_posts WHERE id = ?");
    $stmt->execute([$postId]);

    sendSuccess(['message' => '帖子已删除']);
}

function getCommunityStats($conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM community_posts");
    $stmt->execute();
    $totalPosts = intval($stmt->fetch()['count']);

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM community_posts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->execute();
    $todayPosts = intval($stmt->fetch()['count']);

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM community_comments");
    $stmt->execute();
    $totalComments = intval($stmt->fetch()['count']);

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM community_reports WHERE status = 'pending'");
    $stmt->execute();
    $pendingReports = intval($stmt->fetch()['count']);

    sendSuccess([
        'total_posts' => $totalPosts,
        'today_posts' => $todayPosts,
        'total_comments' => $totalComments,
        'pending_reports' => $pendingReports
    ]);
}

function listReports($conn) {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $status = isset($_GET['status']) ? $_GET['status'] : '';

    $where = [];
    $params = [];

    if ($status && in_array($status, ['pending', 'reviewed', 'resolved', 'dismissed'])) {
        $where[] = "r.status = ?";
        $params[] = $status;
    }

    $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) as total FROM community_reports r $whereClause";
    $stmt = $conn->prepare($countSql);
    $stmt->execute($params);
    $total = intval($stmt->fetch()['total']);

    $sql = "SELECT r.id, r.reporter_id, r.target_id, r.target_type, r.reason, r.status, r.created_at,
                   ru.username as reporter_username, ru.real_name as reporter_real_name
            FROM community_reports r
            LEFT JOIN users ru ON r.reporter_id = ru.id
            $whereClause
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?";

    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reports as &$report) {
        $report['id'] = intval($report['id']);
        $report['reporter_id'] = intval($report['reporter_id']);
        $report['target_id'] = intval($report['target_id']);

        if ($report['target_type'] === 'post') {
            $stmt = $conn->prepare("SELECT title, content FROM community_posts WHERE id = ?");
            $stmt->execute([$report['target_id']]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            $report['target_title'] = $target ? $target['title'] : '已删除';
            $report['target_content'] = $target ? mb_substr($target['content'], 0, 50) : '';
        } elseif ($report['target_type'] === 'comment') {
            $stmt = $conn->prepare("SELECT content FROM community_comments WHERE id = ?");
            $stmt->execute([$report['target_id']]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            $report['target_title'] = '评论';
            $report['target_content'] = $target ? mb_substr($target['content'], 0, 50) : '已删除';
        } else {
            $report['target_title'] = '用户';
            $report['target_content'] = '';
        }
    }
    unset($report);

    sendSuccess([
        'reports' => $reports,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage
    ]);
}

function resolveReport($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $reportId = intval($input['id'] ?? 0);
    $action = $input['action'] ?? '';

    if ($reportId <= 0) sendError(400, '无效的举报ID');
    if (!in_array($action, ['resolved', 'dismissed'])) sendError(400, '无效的操作');

    $stmt = $conn->prepare("UPDATE community_reports SET status = ? WHERE id = ?");
    $stmt->execute([$action, $reportId]);

    sendSuccess(['message' => '操作成功']);
}

function deleteReport($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) sendError(400, '无效的举报ID');

    $stmt = $conn->prepare("DELETE FROM community_reports WHERE id = ?");
    $stmt->execute([$id]);
    sendSuccess(['message' => '已删除']);
}

function sendNotification($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $image = trim($input['image'] ?? '');
    $userId = intval($input['user_id'] ?? 0);

    if (empty($content)) sendError(400, '消息内容不能为空');

    $fullContent = ($title ? $title . "\n" : '') . $content;

    if ($userId > 0) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) sendError(400, '目标用户不存在');
        $recipientCount = 1;
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE status = 'active'");
        $stmt->execute();
        $recipientCount = intval($stmt->fetchColumn());
    }

    $stmt = $conn->prepare("INSERT INTO community_system_messages (title, content, image, target_user_id, recipient_count) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$title ?: null, $content, $image ?: null, $userId > 0 ? $userId : null, $recipientCount]);
    $groupId = intval($conn->lastInsertId());

    if ($userId > 0) {
        $stmt = $conn->prepare("INSERT INTO community_notifications (user_id, type, content, image, message_group_id) VALUES (?, 'system', ?, ?, ?)");
        $stmt->execute([$userId, $fullContent, $image ?: null, $groupId]);
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE status = 'active'");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $conn->prepare("INSERT INTO community_notifications (user_id, type, content, image, message_group_id) VALUES (?, 'system', ?, ?, ?)");
        foreach ($users as $uid) {
            $stmt->execute([$uid, $fullContent, $image ?: null, $groupId]);
        }
    }

    sendSuccess(['message' => '消息已发送', 'id' => $groupId]);
}

function listNotifications($conn) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $countStmt = $conn->query("SELECT COUNT(*) FROM community_system_messages");
    $total = intval($countStmt->fetchColumn());

    $stmt = $conn->prepare("SELECT sm.*, u.real_name as target_user_name, u.username as target_username
                            FROM community_system_messages sm
                            LEFT JOIN users u ON sm.target_user_id = u.id
                            ORDER BY sm.created_at DESC
                            LIMIT ? OFFSET ?");
    $stmt->execute([$perPage, $offset]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($messages as &$m) {
        $m['id'] = intval($m['id']);
        $m['recipient_count'] = intval($m['recipient_count']);
        $m['target_user_id'] = $m['target_user_id'] ? intval($m['target_user_id']) : null;

        $readStmt = $conn->prepare("SELECT COUNT(*) as total, SUM(is_read) as read_count FROM community_notifications WHERE message_group_id = ?");
        $readStmt->execute([$m['id']]);
        $readInfo = $readStmt->fetch(PDO::FETCH_ASSOC);
        $m['read_count'] = intval($readInfo['read_count'] ?? 0);
    }
    unset($m);

    sendSuccess([
        'messages' => $messages,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage
    ]);
}

function updateNotification($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) sendError(400, '无效的消息ID');

    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $image = trim($input['image'] ?? '');

    $stmt = $conn->prepare("SELECT id FROM community_system_messages WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) sendError(404, '消息不存在');

    $fullContent = ($title ? $title . "\n" : '') . $content;

    $stmt = $conn->prepare("UPDATE community_system_messages SET title = ?, content = ?, image = ? WHERE id = ?");
    $stmt->execute([$title ?: null, $content, $image ?: null, $id]);

    $stmt = $conn->prepare("UPDATE community_notifications SET content = ?, image = ? WHERE message_group_id = ?");
    $stmt->execute([$fullContent, $image ?: null, $id]);

    sendSuccess(['message' => '已更新']);
}

function deleteNotificationAdmin($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) sendError(400, '无效的消息ID');

    $stmt = $conn->prepare("DELETE FROM community_notifications WHERE message_group_id = ?");
    $stmt->execute([$id]);

    $stmt = $conn->prepare("DELETE FROM community_system_messages WHERE id = ?");
    $stmt->execute([$id]);

    sendSuccess(['message' => '已删除']);
}

function sendSuccess($data) {
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
