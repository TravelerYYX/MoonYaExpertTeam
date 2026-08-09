<?php
// 一次性迁移脚本：把数据库里的 agent_planning_instruction 提示词更新为强制使用 title 字段的版本
// 部署后访问一次 /migrate_fix_planning_prompt.php?confirm=YES 即可
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/env_loader.php';

$confirm = $_GET['confirm'] ?? '';
if ($confirm !== 'YES') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>迁移：修正规划提示词</title></head><body style="font-family:system-ui;padding:32px;max-width:760px;margin:0 auto">';
    echo '<h2>修正 agent_planning_instruction 提示词</h2>';
    echo '<p>本次更新内容：</p><ul>';
    echo '<li>强制要求 AI 返回 <code>title</code> 字段（而不是 name/description/step_name 等）</li>';
    echo '<li>明确禁止 Markdown 代码块包裹 JSON</li>';
    echo '<li>要求 title 为完整通顺的中文短语</li></ul>';
    echo '<p>将使用 <code>ON DUPLICATE KEY UPDATE</code> 写入（id=8），已存在的同名记录会被覆盖。</p>';
    echo '<p style="color:#c00">点击下方按钮确认执行：</p>';
    echo '<a href="?confirm=YES" style="display:inline-block;background:#52c41a;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">确认执行迁移</a>';
    echo '</body></html>';
    exit;
}

$newContent = "请根据上述用户需求进行任务规划。如果需要多步骤执行，返回如下 JSON 计划（字段名严格使用 title 和 id，禁止使用 name/description/step_name 等其他字段）：\n{\"need_plan\": true, \"steps\": [{\"id\": 1, \"title\": \"第一步：用一句完整通顺的中文描述这一步要做什么\"}, {\"id\": 2, \"title\": \"第二步：...\"}]}\n如果是单一简单任务，返回 {\"need_plan\": false, \"steps\": []}。\n要求：1) 每个 title 必须是完整、通顺、可被用户直接阅读的中文短语；2) 仅返回 JSON，不要任何额外文字、解释、Markdown 代码块包裹。";

try {
    $dsn = 'mysql:host=' . (env('DB_HOST') ?: 'localhost')
         . ';dbname=' . (env('DB_NAME') ?: 'ai_system')
         . ';charset=utf8mb4';
    $pdo = new PDO($dsn, env('DB_USER') ?: '', env('DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDOERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
    ]);

    // 优先尝试按 name 更新（兼容性最好）
    $stmt = $pdo->prepare("UPDATE system_prompts SET content = ?, updated_at = NOW() WHERE name = 'agent_planning_instruction'");
    $stmt->execute([$newContent]);
    $rows = $stmt->rowCount();

    if ($rows === 0) {
        // 没找到则尝试按 id=8 插入
        $stmt = $pdo->prepare("INSERT INTO system_prompts (id, name, label, content, applies_to, sort_order, group_id, created_at, updated_at) VALUES (8, 'agent_planning_instruction', 'Agent 规划指令', ?, '[\"*\"]', 1, 7, NOW(), NOW()) ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = NOW()");
        $stmt->execute([$newContent]);
        $rows = $stmt->rowCount();
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>迁移完成</title></head><body style="font-family:system-ui;padding:32px;max-width:760px;margin:0 auto">';
    echo '<h2 style="color:#52c41a">✅ 迁移完成</h2>';
    echo "<p>agent_planning_instruction 提示词已更新（影响行数：{$rows}）。</p>";
    echo '<p>请刷新浏览器，再发送一次 agent 模式请求（带规划），验证"待办"列表能否显示任务标题。</p>';
    echo '<p style="color:#888;font-size:12px">为安全起见，建议立即删除本文件：<code>' . __FILE__ . '</code></p>';
    echo '</body></html>';
} catch (Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>迁移失败</title></head><body style="font-family:system-ui;padding:32px;color:#c00">';
    echo '<h2>迁移失败</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</body></html>';
}
