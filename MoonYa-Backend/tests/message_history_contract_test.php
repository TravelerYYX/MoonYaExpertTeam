<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Services/TeamRepository.php';

function historyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE conversations (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, updated_at TEXT
)');
$pdo->exec('CREATE TABLE messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL,
    content TEXT NOT NULL,
    thinking TEXT,
    specialist_analysis TEXT,
    agent TEXT,
    client_message_id TEXT,
    source_run_id TEXT
)');
$pdo->exec(
    'CREATE UNIQUE INDEX uq_messages_client
     ON messages (conversation_id, user_id, client_message_id)'
);
$pdo->exec(
    'CREATE UNIQUE INDEX uq_messages_run_role
     ON messages (conversation_id, user_id, source_run_id, role)'
);
$pdo->exec('CREATE TABLE team_runs (
    id TEXT PRIMARY KEY, final_message_id INTEGER
)');
$pdo->exec("INSERT INTO conversations VALUES (1, 7, CURRENT_TIMESTAMP)");

$insert = $pdo->prepare(
    'INSERT INTO messages
     (conversation_id, user_id, role, content, thinking, client_message_id)
     VALUES (1, 7, ?, ?, "", ?)'
);
for ($index = 1; $index <= 25; $index++) {
    $role = $index % 2 === 0 ? 'ai' : 'user';
    $insert->execute([$role, "message-{$index}", sprintf('00000000-0000-4000-8000-%012d', $index)]);
}
$currentId = '00000000-0000-4000-8000-000000000026';
$insert->execute(['user', '当前消息', $currentId]);

$history = $pdo->prepare(
    "SELECT id, role, content
     FROM (
        SELECT id, role, content
        FROM messages
        WHERE conversation_id=? AND user_id=?
          AND (client_message_id IS NULL OR client_message_id<>?)
        ORDER BY id DESC
        LIMIT 20
     ) latest_messages
     ORDER BY id ASC"
);
$history->execute([1, 7, $currentId]);
$historyRows = $history->fetchAll();
historyAssert(count($historyRows) === 20, 'History did not keep exactly the latest 20 prior messages');
historyAssert(
    (int)$historyRows[0]['id'] === 6
        && (int)$historyRows[19]['id'] === 25
        && !in_array('当前消息', array_column($historyRows, 'content'), true),
    'History ordering or current-message exclusion is invalid'
);

// 相同文字使用不同 client_message_id，必须作为两条消息保留。
$pdo->exec("INSERT INTO conversations VALUES (2, 7, CURRENT_TIMESTAMP)");
$repeatInsert = $pdo->prepare(
    'INSERT INTO messages
     (conversation_id, user_id, role, content, client_message_id)
     VALUES (2, 7, "user", "重复文字", ?)'
);
$repeatInsert->execute(['10000000-0000-4000-8000-000000000001']);
$repeatInsert->execute(['10000000-0000-4000-8000-000000000002']);
historyAssert(
    (int)$pdo->query(
        'SELECT COUNT(*) FROM messages
         WHERE conversation_id=2 AND role="user" AND content="重复文字"'
    )->fetchColumn() === 2,
    'Two identical user messages were incorrectly collapsed'
);
try {
    $repeatInsert->execute(['10000000-0000-4000-8000-000000000002']);
    throw new RuntimeException('Duplicate client_message_id was inserted twice');
} catch (PDOException $expected) {
    // Unique message ID is the idempotency boundary.
}

// Work 最终回复按 run_id 幂等保存，刷新后仍是完整 AI 消息。
$pdo->exec("INSERT INTO conversations VALUES (3, 7, CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO team_runs VALUES ('30000000-0000-4000-8000-000000000001', NULL)");
$repository = new TeamRepository($pdo);
$firstFinalId = $repository->persistFinalAssistantMessage(
    '30000000-0000-4000-8000-000000000001',
    7,
    3,
    '最终回复'
);
$secondFinalId = $repository->persistFinalAssistantMessage(
    '30000000-0000-4000-8000-000000000001',
    7,
    3,
    '最终回复（更新）'
);
historyAssert(
    $firstFinalId === $secondFinalId
        && (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE conversation_id=3')->fetchColumn() === 1
        && (string)$pdo->query('SELECT content FROM messages WHERE conversation_id=3')->fetchColumn() === '最终回复（更新）',
    'Work final assistant message is not idempotent or refreshable'
);

$backendApi = (string)file_get_contents(dirname(__DIR__) . '/api.php');
$conversationApi = (string)file_get_contents(dirname(__DIR__) . '/conversation_api.php');
$saveScript = (string)file_get_contents(
    dirname(__DIR__) . '/script/MoonYa-index/modules/script-1c-save.php'
);
historyAssert(
    str_contains($backendApi, 'client_message_id <> ?')
        && str_contains($backendApi, 'ORDER BY id DESC')
        && str_contains($backendApi, 'LIMIT 20')
        && str_contains($backendApi, 'history_first_message_id')
        && str_contains($backendApi, 'content = IF(role = "user", VALUES(content), content)')
        && str_contains($backendApi, 'client_message_id_role_conflict'),
    'api.php no longer follows the stable latest-history contract'
);
historyAssert(
    str_contains($conversationApi, 'ORDER BY id DESC LIMIT 1')
        && str_contains($conversationApi, 'content = IF(role = VALUES(role), VALUES(content), content)')
        && str_contains($conversationApi, 'client_message_id 已被另一条消息使用')
        && !str_contains($saveScript, 'savedMessages.some')
        && !str_contains($saveScript, 'seenAiContents')
        && str_contains($saveScript, 'let _saveQueue = Promise.resolve()'),
    'Legacy adjacent fallback or front-end ID-based save contract regressed'
);

echo "message history contract: PASS\n";
