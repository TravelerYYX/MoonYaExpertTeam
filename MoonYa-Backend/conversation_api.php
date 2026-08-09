<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/Services/ConversationTaskState.php';
require_once __DIR__ . '/Services/TeamRepository.php';
require_once __DIR__ . '/Services/TeamMediaStore.php';
require_once __DIR__ . '/Services/ExecutionJobRepository.php';

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
} catch (Exception $e) {
    sendError(500, '数据库连接失败');
}

$tokenUserId = null;
$authHeader = null;
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif (isset($_GET['token']) && $_GET['token'] !== '') {
    $authHeader = 'Bearer ' . $_GET['token'];
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    if (!empty($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
    } elseif (!empty($headers['authorization'])) {
        $authHeader = $headers['authorization'];
    }
}

if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
    try {
        $stmt = $pdo->prepare("SELECT id, username, api_token, token_created_at FROM users WHERE api_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            if ($user['token_created_at']) {
                $elapsed = time() - strtotime($user['token_created_at']);
                if ($elapsed <= 1296000) {
                    $tokenUserId = $user['id'];
                    $_SESSION['user_id'] = $tokenUserId;
                }
            } else {
                $tokenUserId = $user['id'];
                $_SESSION['user_id'] = $tokenUserId;
            }
        }
    } catch (Exception $e) {
        // Token验证失败，继续尝试Session验证
    }
}

// 检查登录状态（优先Token验证，其次Session验证）
if (!isset($_SESSION['user_id'])) {
    sendError(401, '请先登录');
}

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($method === 'GET') {
    if ($action === 'list') {
        getConversations($pdo, $userId);
    } elseif ($action === 'get' && isset($_GET['conversation_id'])) {
        getConversation($pdo, $userId, intval($_GET['conversation_id']));
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($action === 'create') {
        createConversation($pdo, $userId, $input);
    } elseif ($action === 'save_message') {
        saveMessage($pdo, $userId, $input);
    } elseif ($action === 'mark_viewed') {
        $conversationId = intval($input['conversation_id'] ?? 0);
        if ($conversationId <= 0) sendError(400, 'conversation_id is required');
        (new ConversationTaskState($pdo))->markViewed((int)$userId, $conversationId);
        sendSuccess(['conversation_id' => $conversationId, 'unread_terminal' => false]);
    } elseif ($action === 'stop_task') {
        $conversationId = intval($input['conversation_id'] ?? 0);
        $taskId = trim((string)($input['client_message_id'] ?? ''));
        if ($conversationId <= 0 || $taskId === '') {
            sendError(400, 'conversation_id and client_message_id are required');
        }
        $taskState = new ConversationTaskState($pdo);
        $active = $taskState->get((int)$userId, $conversationId);
        if (!$active || (string)($active['active_task_id'] ?? '') !== $taskId) {
            sendError(409, 'conversation_task_not_active');
        }
        $executionJobs = new ExecutionJobRepository($pdo);
        if ($executionJobs->isInstalled()) {
            $cancelledJob = $executionJobs->cancelForOwnerClient(
                (int)$userId,
                $conversationId,
                $taskId
            );
            $guardRunId = trim((string)($cancelledJob['run_id'] ?? $active['active_run_id'] ?? ''));
            if ($guardRunId !== '') {
                (new TeamRepository($pdo))->cancelRun((int)$userId, $guardRunId);
            }
        }
        $taskState->finish((int)$userId, $conversationId, $taskId, 'cancelled');
        sendSuccess([
            'conversation_id' => $conversationId,
            'client_message_id' => $taskId,
            'status' => 'cancelled',
        ]);
    }
} elseif ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $conversationId = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;
    if ($action === 'update' && $conversationId > 0) {
        updateConversation($pdo, $userId, $conversationId, $input);
    } elseif ($action === 'pin' && $conversationId > 0) {
        togglePin($pdo, $userId, $conversationId);
    }
} elseif ($method === 'DELETE') {
    $conversationId = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;
    if ($conversationId > 0) {
        deleteConversation($pdo, $userId, $conversationId);
    }
}

sendError(400, '无效的请求');

function getConversations($pdo, $userId) {
    (new ConversationTaskState($pdo))->reconcileStale((int)$userId);
    // 只返回有消息的对话，过滤掉空对话（创建但从未发送消息的）。
    // 根治空对话堆积问题：前端创建对话后可能未发送消息就离开，数据库中留下大量空"新对话"。
    $stmt = $pdo->prepare("SELECT c.id, c.title, c.pinned, c.created_at, c.updated_at,
                            (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.user_id = ?) AS message_count
                            FROM conversations c
                            WHERE c.user_id = ?
                              AND EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.user_id = ?)
                            ORDER BY c.pinned DESC, c.updated_at DESC");
    $stmt->execute([$userId, $userId, $userId]);
    $conversations = $stmt->fetchAll();

    // Keep task state independent from message persistence. A background task can
    // update this projection without ever mutating whichever conversation is visible.
    $taskStmt = $pdo->prepare(
        'SELECT phase, active_task_id, active_run_id, last_terminal_status,
                unread_terminal, state_version FROM conversation_task_state
         WHERE conversation_id=? AND user_id=?'
    );
    foreach ($conversations as &$taskConversation) {
        $taskStmt->execute([(int)$taskConversation['id'], (int)$userId]);
        $task = $taskStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $taskConversation['phase'] = (string)($task['phase'] ?? 'idle');
        $taskConversation['active_task_id'] = $task['active_task_id'] ?? null;
        $taskConversation['active_run_id'] = $task['active_run_id'] ?? null;
        $taskConversation['last_terminal_status'] = $task['last_terminal_status'] ?? null;
        $taskConversation['unread_terminal'] = (bool)($task['unread_terminal'] ?? false);
        $taskConversation['task_state_version'] = (int)($task['state_version'] ?? 0);
    }
    unset($taskConversation);

    // 自动修复标题：如果标题是"新对话"但对话有消息，从 user 消息提取标题并更新数据库。
    // 根治"删除后重新同步，对话标题变成'新对话'"的问题——后端自动维护标题，不依赖前端同步。
    // 遍历最多 5 条 user 消息，跳过只有图片/HTML标签无文本的消息（strip_tags 后为空）。
    foreach ($conversations as &$conv) {
        if ($conv['title'] === '新对话' && $conv['message_count'] > 0) {
            $msgStmt = $pdo->prepare("SELECT content FROM messages WHERE conversation_id = ? AND user_id = ? AND role = 'user' ORDER BY id ASC LIMIT 5");
            $msgStmt->execute([$conv['id'], $userId]);
            while ($msgRow = $msgStmt->fetch()) {
                $plainText = trim(strip_tags($msgRow['content']));
                if ($plainText !== '') {
                    $newTitle = mb_substr($plainText, 0, 20) . (mb_strlen($plainText) > 20 ? '...' : '');
                    $conv['title'] = $newTitle;
                    $updateStmt = $pdo->prepare("UPDATE conversations SET title = ? WHERE id = ? AND user_id = ?");
                    $updateStmt->execute([$newTitle, $conv['id'], $userId]);
                    break;
                }
            }
        }
    }
    unset($conv);

    sendSuccess(['conversations' => $conversations]);
}

function getConversation($pdo, $userId, $conversationId) {
    $stmt = $pdo->prepare("SELECT id, title, pinned, created_at, updated_at 
                            FROM conversations 
                            WHERE id = ? AND user_id = ?");
    $stmt->execute([$conversationId, $userId]);
    $conversation = $stmt->fetch();
    
    if (!$conversation) {
        sendError(404, '对话不存在');
    }
    
    // 检查扩展字段是否存在（兼容旧库）
    $hasImages = false; $hasStatuses = false; $hasAgent = false;
    $hasClientMessageId = false; $hasSourceRunId = false;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'images'");
        $hasImages = $stmt->fetch() !== false;
        $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'statuses'");
        $hasStatuses = $stmt->fetch() !== false;
        $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'agent'");
        $hasAgent = $stmt->fetch() !== false;
        $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'client_message_id'");
        $hasClientMessageId = $stmt->fetch() !== false;
        $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'source_run_id'");
        $hasSourceRunId = $stmt->fetch() !== false;
    } catch (Exception $e) {}

    $extraCols = '';
    if ($hasImages) $extraCols .= ', images';
    if ($hasStatuses) $extraCols .= ', statuses';
    if ($hasAgent) $extraCols .= ', agent';
    if ($hasClientMessageId) $extraCols .= ', client_message_id';
    if ($hasSourceRunId) $extraCols .= ', source_run_id';

    $stmt = $pdo->prepare("SELECT id, role, content, thinking, specialist_analysis, created_at{$extraCols}
                            FROM messages
                            WHERE conversation_id = ? AND user_id = ?
                            ORDER BY id ASC");
    $stmt->execute([$conversationId, $userId]);
    $messages = $stmt->fetchAll();

    foreach ($messages as &$msg) {
        if ($hasImages && isset($msg['images']) && $msg['images']) {
            $msg['images'] = json_decode($msg['images'], true);
        } else {
            $msg['images'] = [];
        }
        if ($hasStatuses && isset($msg['statuses']) && $msg['statuses']) {
            $msg['statuses'] = json_decode($msg['statuses'], true);
        } else {
            $msg['statuses'] = [];
        }
        if (!$hasAgent) {
            $msg['agent'] = '';
        }
        if (!$hasClientMessageId) {
            $msg['client_message_id'] = null;
        }
        if (!$hasSourceRunId) {
            $msg['source_run_id'] = null;
        }
    }
    
    sendSuccess([
        'conversation' => $conversation,
        'messages' => $messages
    ]);
}

function createConversation($pdo, $userId, $input) {
    $title = trim($input['title'] ?? '新对话');
    
    $stmt = $pdo->prepare("INSERT INTO conversations (user_id, title) VALUES (?, ?)");
    $stmt->execute([$userId, $title]);
    $conversationId = $pdo->lastInsertId();
    
    sendSuccess([
        'conversation_id' => $conversationId,
        'title' => $title
    ]);
}

function saveMessage($pdo, $userId, $input) {
    $conversationId = intval($input['conversation_id'] ?? 0);
    $role = $input['role'] ?? '';
    $content = $input['content'] ?? '';
    $images = $input['images'] ?? [];
    $thinking = $input['thinking'] ?? '';
    $specialistAnalysis = $input['specialist_analysis'] ?? '';
    $agent = $input['agent'] ?? '';
    $statuses = $input['statuses'] ?? [];
    $clientMessageId = trim((string)($input['client_message_id'] ?? ''));
    $sourceRunId = trim((string)($input['source_run_id'] ?? ''));

    if ($conversationId <= 0 || !in_array($role, ['user', 'ai'])) {
        sendError(400, '参数错误');
    }
    if ($clientMessageId !== '' && !isUuid($clientMessageId)) {
        sendError(400, 'client_message_id 格式错误');
    }
    if ($sourceRunId !== '' && !isUuid($sourceRunId)) {
        sendError(400, 'source_run_id 格式错误');
    }

    $stmt = $pdo->prepare("SELECT id FROM conversations WHERE id = ? AND user_id = ?");
    $stmt->execute([$conversationId, $userId]);
    if (!$stmt->fetch()) {
        sendError(404, '对话不存在');
    }

    // 检查扩展字段是否存在（兼容旧库）
    $hasImages = false; $hasStatuses = false; $hasAgent = false;
    $hasClientMessageId = false; $hasSourceRunId = false;
    try {
        $stmt2 = $pdo->query("SHOW COLUMNS FROM messages LIKE 'images'");
        $hasImages = $stmt2->fetch() !== false;
        $stmt2 = $pdo->query("SHOW COLUMNS FROM messages LIKE 'statuses'");
        $hasStatuses = $stmt2->fetch() !== false;
        $stmt2 = $pdo->query("SHOW COLUMNS FROM messages LIKE 'agent'");
        $hasAgent = $stmt2->fetch() !== false;
        $stmt2 = $pdo->query("SHOW COLUMNS FROM messages LIKE 'client_message_id'");
        $hasClientMessageId = $stmt2->fetch() !== false;
        $stmt2 = $pdo->query("SHOW COLUMNS FROM messages LIKE 'source_run_id'");
        $hasSourceRunId = $stmt2->fetch() !== false;
    } catch (Exception $e) {}

    // 旧客户端没有消息 ID 时，只允许对紧邻的最后一条同角色、同内容消息兜底去重。
    // 不扫描全历史，确保用户连续发送相同文字仍能作为两条独立消息保存。
    if ($clientMessageId === '' && $sourceRunId === '') {
        $lastStmt = $pdo->prepare(
            "SELECT id, role, content FROM messages
             WHERE conversation_id = ? AND user_id = ?
             ORDER BY id DESC LIMIT 1"
        );
        $lastStmt->execute([$conversationId, $userId]);
        $lastMessage = $lastStmt->fetch();
        if ($lastMessage
            && (string)$lastMessage['role'] === $role
            && (string)$lastMessage['content'] === (string)$content
        ) {
            sendSuccess([
                'message_id' => (int)$lastMessage['id'],
                'deduplicated' => true,
            ]);
        }
    }

    // 动态构建 INSERT 列与占位符，按字段存在性追加扩展字段
    $cols = ['conversation_id', 'user_id', 'role', 'content', 'thinking', 'specialist_analysis'];
    $vals = [$conversationId, $userId, $role, $content, $thinking, $specialistAnalysis];
    if ($hasImages) { $cols[] = 'images'; $vals[] = json_encode($images); }
    if ($hasStatuses) { $cols[] = 'statuses'; $vals[] = json_encode($statuses); }
    if ($hasAgent) { $cols[] = 'agent'; $vals[] = $agent; }
    if ($hasClientMessageId && $clientMessageId !== '') {
        $cols[] = 'client_message_id';
        $vals[] = $clientMessageId;
    }
    if ($hasSourceRunId && $sourceRunId !== '') {
        $cols[] = 'source_run_id';
        $vals[] = $sourceRunId;
    }

    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $colList = implode(', ', $cols);
    $idempotent = ($hasClientMessageId && $clientMessageId !== '')
        || ($hasSourceRunId && $sourceRunId !== '');
    $upsert = '';
    if ($idempotent) {
        // Idempotent retries may refresh a partially persisted live message, but
        // never change the identity or role of the existing message.
        $updates = [
            'id = LAST_INSERT_ID(id)',
            'content = IF(role = VALUES(role), VALUES(content), content)',
            'thinking = IF(role = VALUES(role), VALUES(thinking), thinking)',
            'specialist_analysis = IF(role = VALUES(role), VALUES(specialist_analysis), specialist_analysis)',
        ];
        if ($hasImages) {
            $updates[] = 'images = IF(role = VALUES(role), VALUES(images), images)';
        }
        if ($hasStatuses) {
            $updates[] = 'statuses = IF(role = VALUES(role), VALUES(statuses), statuses)';
        }
        if ($hasAgent) {
            $updates[] = 'agent = IF(role = VALUES(role), VALUES(agent), agent)';
        }
        $upsert = ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }
    $stmt = $pdo->prepare("INSERT INTO messages ({$colList}) VALUES ({$placeholders}){$upsert}");
    $stmt->execute($vals);
    $messageId = $pdo->lastInsertId();

    if ($hasClientMessageId && $clientMessageId !== '') {
        $identityStmt = $pdo->prepare(
            'SELECT role FROM messages
             WHERE id = ? AND conversation_id = ? AND user_id = ? AND client_message_id = ?'
        );
        $identityStmt->execute([(int)$messageId, $conversationId, $userId, $clientMessageId]);
        $storedRole = $identityStmt->fetchColumn();
        if ($storedRole === false || (string)$storedRole !== $role) {
            sendError(409, 'client_message_id 已被另一条消息使用');
        }
    }
    
    $stmt = $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?");
    $stmt->execute([$conversationId]);

    // 自动更新标题：如果保存的是 user 消息且对话标题还是"新对话"，从消息内容提取标题。
    // 根治"标题永远是'新对话'"的问题——后端自动维护标题，不依赖前端同步。
    if ($role === 'user') {
        $titleStmt = $pdo->prepare("SELECT title FROM conversations WHERE id = ? AND user_id = ?");
        $titleStmt->execute([$conversationId, $userId]);
        $currentTitle = $titleStmt->fetchColumn();
        if ($currentTitle === '新对话') {
            $plainText = trim(strip_tags($content));
            if ($plainText !== '') {
                $newTitle = mb_substr($plainText, 0, 20) . (mb_strlen($plainText) > 20 ? '...' : '');
                $updateTitleStmt = $pdo->prepare("UPDATE conversations SET title = ? WHERE id = ? AND user_id = ?");
                $updateTitleStmt->execute([$newTitle, $conversationId, $userId]);
            }
        }
    }

    sendSuccess([
        'message_id' => (int)$messageId,
        'client_message_id' => $clientMessageId !== '' ? $clientMessageId : null,
        'source_run_id' => $sourceRunId !== '' ? $sourceRunId : null,
    ]);
}

function isUuid($value) {
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        (string)$value
    ) === 1;
}

function updateConversation($pdo, $userId, $conversationId, $input) {
    $title = trim($input['title'] ?? '');
    
    $stmt = $pdo->prepare("UPDATE conversations SET title = ?, updated_at = NOW() 
                            WHERE id = ? AND user_id = ?");
    $stmt->execute([$title, $conversationId, $userId]);
    
    sendSuccess(['message' => '更新成功']);
}

function togglePin($pdo, $userId, $conversationId) {
    $stmt = $pdo->prepare("SELECT pinned FROM conversations WHERE id = ? AND user_id = ?");
    $stmt->execute([$conversationId, $userId]);
    $conversation = $stmt->fetch();
    
    if (!$conversation) {
        sendError(404, '对话不存在');
    }
    
    $newPinned = $conversation['pinned'] ? 0 : 1;
    $stmt = $pdo->prepare("UPDATE conversations SET pinned = ?, updated_at = NOW() 
                            WHERE id = ? AND user_id = ?");
    $stmt->execute([$newPinned, $conversationId, $userId]);
    
    sendSuccess(['pinned' => $newPinned === 1]);
}

function deleteConversation($pdo, $userId, $conversationId) {
    $runIds = [];
    try {
        $repository = new TeamRepository($pdo);
        $runIds = $repository->runIdsForConversation((int)$userId, (int)$conversationId);
    } catch (Throwable $e) {
        // Older installations may not have the team tables yet.
    }

    $stmt = $pdo->prepare("DELETE FROM messages WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$conversationId, $userId]);
    
    $stmt = $pdo->prepare("DELETE FROM conversations WHERE id = ? AND user_id = ?");
    $stmt->execute([$conversationId, $userId]);

    if ($stmt->rowCount() > 0 && $runIds !== []) {
        $mediaStore = new TeamMediaStore(new TeamRepository($pdo));
        foreach ($runIds as $runId) {
            $mediaStore->deleteRunDirectory($runId);
        }
    }
    
    sendSuccess(['message' => '删除成功']);
}
?>
