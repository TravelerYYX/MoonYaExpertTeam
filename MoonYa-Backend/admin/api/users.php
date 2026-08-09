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

    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    if ($method === 'POST' && $action === 'login') {
        handleLogin($conn, $auth);
    } elseif (!$currentAdmin) {
        sendError(401, '未授权访问，请先登录');
    }

    switch ($method) {
        case 'GET':
            if ($action === 'get' && $userId > 0) {
                getUser($conn, $userId, $currentAdmin, $auth, $logger);
            } elseif ($action === 'login_as' && $userId > 0) {
                loginAsUser($conn, $userId, $currentAdmin, $auth, $logger, $config);
            } else {
                getUsers($conn, $currentAdmin, $auth, $logger);
            }
            break;
        case 'POST':
        case 'PUT':
            if ($action === 'update_username' && $userId > 0) {
                updateUsername($conn, $userId, $currentAdmin, $auth, $logger);
            } elseif ($action === 'update_real_name' && $userId > 0) {
                updateRealName($conn, $userId, $currentAdmin, $auth, $logger);
            } elseif ($action === 'update_password' && $userId > 0) {
                updatePassword($conn, $userId, $currentAdmin, $auth, $logger);
            } elseif ($action === 'update_email' && $userId > 0) {
                updateEmail($conn, $userId, $currentAdmin, $auth, $logger);
            } elseif ($action === 'update_gender' && $userId > 0) {
                updateGender($conn, $userId, $currentAdmin, $auth, $logger);
            } elseif ($action === 'ban' && $userId > 0) {
                banUser($conn, $userId, $currentAdmin, $auth, $logger);
            } elseif ($action === 'unban' && $userId > 0) {
                unbanUser($conn, $userId, $currentAdmin, $auth, $logger);
            } elseif ($action === 'create_user') {
                createUser($conn, $currentAdmin, $auth, $logger);
            }
            break;
        case 'DELETE':
            if ($userId > 0) {
                deleteUser($conn, $userId, $currentAdmin, $auth, $logger);
            }
            break;
        default:
            sendError(405, '不支持的请求方法');
    }
} catch (Exception $e) {
    sendError(500, '服务器错误: ' . $e->getMessage());
}

function handleLogin($conn, $auth) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['username']) || empty($input['password'])) {
        sendError(400, '用户名和密码不能为空');
    }
    
    $result = $auth->login($input['username'], $input['password']);
    
    if (!$result) {
        sendError(401, '用户名或密码错误');
    }
    
    sendSuccess($result);
}

function getUsers($conn, $admin, $auth, $logger) {
    if (!$auth->hasPermission($admin, 'view_users')) {
        sendError(403, '没有权限查看用户');
    }
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(10, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;
    
    $limit = intval($limit);
    $offset = intval($offset);
    
    $where = [];
    $params = [];
    
    if (!empty($_GET['id'])) {
        $where[] = "id = ?";
        $params[] = $_GET['id'];
    }
    if (!empty($_GET['username'])) {
        $where[] = "(username LIKE ? OR real_name LIKE ?)";
        $params[] = '%' . $_GET['username'] . '%';
        $params[] = '%' . $_GET['username'] . '%';
    }
    if (!empty($_GET['email'])) {
        $where[] = "email LIKE ?";
        $params[] = '%' . $_GET['email'] . '%';
    }
    if (!empty($_GET['status'])) {
        $where[] = "status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['gender'])) {
        $allowedGenders = ['male', 'female', 'private'];
        if (in_array($_GET['gender'], $allowedGenders, true)) {
            $where[] = "gender = ?";
            $params[] = $_GET['gender'];
        }
    }
    
    // 确保不显示已删除的用户
    if (empty($where)) {
        $where[] = "status != 'deleted'";
    } else {
        $where[] = "status != 'deleted'";
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $where);
    
    $countSql = "SELECT COUNT(*) as total FROM users $whereClause";
    $stmt = $conn->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    $sql = "SELECT id, username, email, real_name, gender, status, ban_reason, ban_until, created_at, updated_at
            FROM users $whereClause
            ORDER BY created_at DESC
            LIMIT " . intval($limit) . " OFFSET " . intval($offset);
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    
    $logger->log('INFO', 'Users queried', ['admin_id' => $admin['id'], 'filters' => $_GET]);
    
    sendSuccess([
        'users' => $users,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function getUser($conn, $userId, $admin, $auth, $logger) {
    if (!$auth->hasPermission($admin, 'view_users')) {
        sendError(403, '没有权限查看用户');
    }
    
    $stmt = $conn->prepare("SELECT id, username, email, real_name, gender, status, ban_reason, ban_until, created_at, updated_at
                            FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendError(404, '用户不存在');
    }
    
    sendSuccess($user);
}

function updateUsername($conn, $userId, $admin, $auth, $logger) {
    if (!$auth->hasPermission($admin, 'edit_users')) {
        sendError(403, '没有权限修改用户信息');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['username'])) {
        sendError(400, '用户名不能为空');
    }
    
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$input['username'], $userId]);
    if ($stmt->fetch()) {
        sendError(400, '用户名已被使用');
    }
    
    $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
    $stmt->execute([$input['username'], $userId]);
    
    $logger->logAdminAction($admin['id'], 'update_username', $userId, json_encode(['new' => $input['username']]));
    
    sendSuccess(['message' => '用户名更新成功']);
}

function updateRealName($conn, $userId, $admin, $auth, $logger) {
    if (!$auth->hasPermission($admin, 'edit_users')) {
        sendError(403, '没有权限修改用户信息');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $stmt = $conn->prepare("UPDATE users SET real_name = ? WHERE id = ?");
    $stmt->execute([$input['real_name'] ?? null, $userId]);
    
    $logger->logAdminAction($admin['id'], 'update_real_name', $userId, json_encode(['new' => $input['real_name'] ?? null]));
    
    sendSuccess(['message' => '昵称更新成功']);
}

function updatePassword($conn, $userId, $admin, $auth, $logger) {
    if (!$auth->hasPermission($admin, 'edit_users')) {
        sendError(403, '没有权限修改用户密码');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['password'])) {
        sendError(400, '密码不能为空');
    }
    
    if (strlen($input['password']) < 6) {
        sendError(400, '密码长度至少为6位');
    }
    
    $hashedPassword = password_hash($input['password'], PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->execute([$hashedPassword, $userId]);
    
    $logger->logAdminAction($admin['id'], 'update_password', $userId, 'Password updated');
    
    sendSuccess(['message' => '密码更新成功']);
}

function updateEmail($conn, $userId, $admin, $auth, $logger) {
    if (!$auth->hasPermission($admin, 'edit_users')) {
        sendError(403, '没有权限修改用户邮箱');
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['email']) || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        sendError(400, '请输入有效的邮箱地址');
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$input['email'], $userId]);
    if ($stmt->fetch()) {
        sendError(400, '邮箱已被使用');
    }

    $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
    $stmt->execute([$input['email'], $userId]);

    $logger->logAdminAction($admin['id'], 'update_email', $userId, json_encode(['new_email' => $input['email']]));

    sendSuccess(['message' => '邮箱更新成功']);
}

function updateGender($conn, $userId, $admin, $auth, $logger) {
    if (!$auth->hasPermission($admin, 'edit_users')) {
        sendError(403, '没有权限修改用户信息');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $gender = isset($input['gender']) ? trim((string)$input['gender']) : '';
    $allowed = ['male', 'female', 'private'];

    if ($gender === '' || !in_array($gender, $allowed, true)) {
        sendError(400, '性别取值必须是 male / female / private 之一');
    }

    $stmt = $conn->prepare("UPDATE users SET gender = ? WHERE id = ?");
    $stmt->execute([$gender, $userId]);

    $logger->logAdminAction($admin['id'], 'update_gender', $userId, json_encode(['new' => $gender]));

    sendSuccess(['message' => '性别更新成功', 'gender' => $gender]);
}

function banUser($conn, $userId, $admin, $auth, $logger) {
    if (!$auth->hasPermission($admin, 'ban_users')) {
        sendError(403, '没有权限封禁用户');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $banReason = $input['reason'] ?? '';
    $banUntil = !empty($input['duration_hours']) ? date('Y-m-d H:i:s', time() + $input['duration_hours'] * 3600) : null;
    
    $stmt = $conn->prepare("UPDATE users SET status = 'banned', ban_reason = ?, ban_until = ? WHERE id = ?");
    $stmt->execute([$banReason, $banUntil, $userId]);
    
    $logger->logAdminAction($admin['id'], 'ban_user', $userId, json_encode(['reason' => $banReason, 'until' => $banUntil]));
    
    sendSuccess(['message' => '用户已封禁']);
}

function unbanUser($conn, $userId, $admin, $auth, $logger) {
    if (!$auth->hasPermission($admin, 'unban_users')) {
        sendError(403, '没有权限解禁用户');
    }
    
    $stmt = $conn->prepare("UPDATE users SET status = 'active', ban_reason = NULL, ban_until = NULL WHERE id = ?");
    $stmt->execute([$userId]);
    
    $logger->logAdminAction($admin['id'], 'unban_user', $userId);
    
    sendSuccess(['message' => '用户已解禁']);
}

function deleteUser($conn, $userId, $admin, $auth, $logger) {
    if ($admin['role'] !== 'super_admin') {
        sendError(403, '只有超级管理员可以删除用户');
    }
    
    // 获取用户信息，用于日志
    $stmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    // 彻底删除用户及其所有关联数据
    $conn->beginTransaction();
    try {
        // 删除用户的所有消息（由于外键ON DELETE CASCADE，可能不需要，但保险起见）
        $stmt = $conn->prepare("DELETE FROM messages WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // 删除用户的所有对话（由于外键ON DELETE CASCADE，可能不需要，但保险起见）
        $stmt = $conn->prepare("DELETE FROM conversations WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // 彻底删除用户
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        
        $conn->commit();
        
        $logger->logAdminAction($admin['id'], 'permanent_delete_user', $userId, 
            json_encode(['username' => $user['username'], 'email' => $user['email']]));
        
        sendSuccess(['message' => '用户已彻底删除']);
    } catch (Exception $e) {
        $conn->rollBack();
        sendError(500, '删除用户失败: ' . $e->getMessage());
    }
}

function createUser($conn, $admin, $auth, $logger) {
    if (!$auth->hasPermission($admin, 'edit_users')) {
        sendError(403, '没有权限创建用户');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendError(400, '请输入有效的邮箱地址');
    }
    
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND status != 'deleted'");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        sendError(400, '该邮箱已被注册');
    }
    
    $username = preg_replace('/@.*/', '', $email);
    $username = preg_replace('/[^a-zA-Z0-9]/', '', $username);
    if (empty($username)) {
        $username = 'user_' . substr(md5($email), 0, 8);
    }
    
    $originalUsername = $username;
    $counter = 1;
    while (true) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND status != 'deleted'");
        $stmt->execute([$username]);
        if (!$stmt->fetch()) break;
        $username = $originalUsername . $counter;
        $counter++;
    }
    
    $hashedPassword = null;
    if (!empty($password)) {
        if (strlen($password) < 6) {
            sendError(400, '密码长度至少6位');
        }
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    }
    
    $stmt = $conn->prepare("INSERT INTO users (username, email, real_name, password, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', NOW(), NOW())");
    $stmt->execute([$username, $email, $username, $hashedPassword]);
    
    $newUserId = $conn->lastInsertId();
    
    $logger->logAdminAction($admin['id'], 'create_user', $newUserId, json_encode(['email' => $email, 'username' => $username]));
    
    sendSuccess([
        'message' => '用户创建成功',
        'user' => [
            'id' => $newUserId,
            'username' => $username,
            'email' => $email
        ]
    ]);
}

function loginAsUser($conn, $userId, $admin, $auth, $logger, $config) {
    if ($admin['role'] !== 'super_admin') {
        sendError(403, '只有超级管理员可以代登录');
    }
    
    $stmt = $conn->prepare("SELECT id, username, email, real_name, status FROM users WHERE id = ? AND status != 'deleted'");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendError(404, '用户不存在');
    }
    
    $loginToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 300);
    
    $stmt = $conn->prepare("INSERT INTO admin_login_tokens (token, user_id, admin_id, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$loginToken, $userId, $admin['id'], $expiresAt]);
    
    $logger->logAdminAction($admin['id'], 'login_as_user', $userId, json_encode(['username' => $user['username']]));
    
    $baseUrl = $config['site_url'] ?? '';
    if (empty($baseUrl)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
    }
    $loginUrl = rtrim($baseUrl, '/') . '/admin_login_as.php?token=' . $loginToken;
    
    sendSuccess([
        'login_url' => $loginUrl,
        'expires_in' => 120
    ]);
}

function validatePasswordStrength($password) {
    return strlen($password) >= 8 &&
           preg_match('/[A-Z]/', $password) &&
           preg_match('/[a-z]/', $password) &&
           preg_match('/[0-9]/', $password) &&
           preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password);
}

function sendSuccess($data) {
    echo json_encode([
        'success' => true,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
