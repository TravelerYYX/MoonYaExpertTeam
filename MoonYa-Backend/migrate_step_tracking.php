<?php
// 一次性迁移脚本：向 agent_planning 提示词追加"步骤进度上报"指令
// 让 AI 在执行每个计划步骤时输出 <step id="N" /> 标记，后端据此驱动待办列表状态。
// 部署后访问一次 /migrate_step_tracking.php?confirm=YES 即可
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/env_loader.php';

$confirm = $_GET['confirm'] ?? '';
if ($confirm !== 'YES') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>迁移：追加步骤进度上报指令</title></head><body style="font-family:system-ui;padding:32px;max-width:760px;margin:0 auto">';
    echo '<h2>追加步骤进度上报指令到 agent_planning 提示词</h2>';
    echo '<p>本次更新内容：</p><ul>';
    echo '<li>要求 AI 在开始执行计划中的某个步骤时，在回复最前面输出 <code>&lt;step id="N" /&gt;</code> 标记</li>';
    echo '<li>该标记对用户不可见（前端会过滤），仅用于驱动左侧"待办"列表的状态切换</li>';
    echo '<li>从根源解决待办状态与实际执行进度不同步的问题（不再依赖 URL 变化）</li></ul>';
    echo '<p>将更新 <code>system_prompts</code> 表中 <code>name=\'agent_planning\'</code> 记录的 <code>prompt</code> 字段。</p>';
    echo '<p style="color:#c00">点击下方按钮确认执行：</p>';
    echo '<a href="?confirm=YES" style="display:inline-block;background:#52c41a;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">确认执行迁移</a>';
    echo '</body></html>';
    exit;
}

// 要追加的步骤进度上报指令
$stepTrackingInstruction = "\n\n5. 步骤进度上报（强制）：\n   - 当你开始执行计划中的第 N 步时，必须在你这一轮回复的最前面输出标记：<step id=\"N\" />\n   - 该标记用于同步左侧\"待办\"列表的状态，对用户不可见（前端会自动过滤）。\n   - 每次切换到新步骤时输出一次即可，不要在同一步骤中重复输出。\n   - 示例：如果你规划了 3 步，开始执行第 2 步时，回复开头输出 <step id=\"2\" />，然后再写正常内容或调用工具。\n   - 即使是单一简单任务（need_plan=false），如果用户指令被识别为需要执行，也无需输出此标记。";

try {
    $dsn = 'mysql:host=' . (env('DB_HOST') ?: 'localhost')
         . ';dbname=' . (env('DB_NAME') ?: 'ai_system')
         . ';charset=utf8mb4';
    $pdo = new PDO($dsn, env('DB_USER') ?: '', env('DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
    ]);

    // 读取当前 agent_planning 提示词
    $stmt = $pdo->prepare("SELECT id, prompt FROM system_prompts WHERE name = 'agent_planning' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>迁移失败</title></head><body style="font-family:system-ui;padding:32px;color:#c00">';
        echo '<h2>迁移失败</h2>';
        echo '<p>未找到 name=\'agent_planning\' 的记录。请确认数据库已正确初始化。</p>';
        echo '</body></html>';
        exit;
    }

    $currentPrompt = $row['prompt'];

    // 幂等检查：如果已包含步骤进度上报指令，跳过
    if (strpos($currentPrompt, '步骤进度上报') !== false) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>无需迁移</title></head><body style="font-family:system-ui;padding:32px;max-width:760px;margin:0 auto">';
        echo '<h2 style="color:#faad14">⚠️ 已迁移过，无需重复执行</h2>';
        echo '<p>agent_planning 提示词中已包含"步骤进度上报"指令，无需再次迁移。</p>';
        echo '</body></html>';
        exit;
    }

    // 追加指令并更新
    $newPrompt = $currentPrompt . $stepTrackingInstruction;
    $stmt = $pdo->prepare("UPDATE system_prompts SET prompt = ?, updated_at = NOW() WHERE name = 'agent_planning'");
    $stmt->execute([$newPrompt]);
    $rows = $stmt->rowCount();

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>迁移完成</title></head><body style="font-family:system-ui;padding:32px;max-width:760px;margin:0 auto">';
    echo '<h2 style="color:#52c41a">✅ 迁移完成</h2>';
    echo "<p>agent_planning 提示词已追加步骤进度上报指令（影响行数：{$rows}）。</p>";
    echo '<p>请重新构建项目（让 PHP 文件复制到输出目录），然后发送一次 agent 模式多步骤任务，验证"待办"列表状态是否与实际执行同步。</p>';
    echo '<p style="color:#888;font-size:12px">为安全起见，建议立即删除本文件：<code>' . __FILE__ . '</code></p>';
    echo '</body></html>';
} catch (Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>迁移失败</title></head><body style="font-family:system-ui;padding:32px;color:#c00">';
    echo '<h2>迁移失败</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</body></html>';
}
