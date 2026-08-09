<?php
// 一次性迁移脚本：CU 模式 Plan-Act-Verify 架构
// 部署后访问一次 /migrate_cu_plan_act_verify.php?confirm=YES 即可
// 变更内容：
//   1. cu_runtime_config 新增 7 个字段（plan_enabled / plan_model / verify_model 等）
//   2. system_prompts 新增 3 行（cu_planner / cu_verifier / cu_step_router）
//   3. scenario_hints JSON 新增 2 个键（self_drawing_methodology / drag_task_strategy）
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/env_loader.php';

$confirm = $_GET['confirm'] ?? '';
if ($confirm !== 'YES') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>迁移：CU Plan-Act-Verify 架构</title></head><body style="font-family:system-ui;padding:32px;max-width:760px;margin:0 auto">';
    echo '<h2>CU 模式 Plan-Act-Verify 架构迁移</h2>';
    echo '<p>本次迁移内容：</p><ul>';
    echo '<li><strong>cu_runtime_config</strong> 新增 7 个字段：plan_enabled / plan_model / verify_model / verify_max_rounds / plan_max_steps / step_action_max_iterations / verify_token_budget</li>';
    echo '<li><strong>system_prompts</strong> 新增 3 行（id=15 cu_planner / id=16 cu_verifier / id=17 cu_step_router）</li>';
    echo '<li><strong>scenario_hints</strong> JSON 新增 2 个键：self_drawing_methodology / drag_task_strategy</li>';
    echo '</ul>';
    echo '<p>迁移后 <code>plan_enabled</code> 默认为 0（关闭），需手动 <code>UPDATE cu_runtime_config SET plan_enabled=1</code> 启用。</p>';
    echo '<p style="color:#c00">点击下方按钮确认执行：</p>';
    echo '<a href="?confirm=YES" style="display:inline-block;background:#52c41a;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">确认执行迁移</a>';
    echo '</body></html>';
    exit;
}

try {
    $dsn = 'mysql:host=' . (env('DB_HOST') ?: 'localhost')
         . ';dbname=' . (env('DB_NAME') ?: 'ai_system')
         . ';charset=utf8mb4';
    $pdo = new PDO($dsn, env('DB_USER') ?: '', env('DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    $steps = [];

    // ===== 1. ALTER TABLE cu_runtime_config 新增 7 字段 =====
    $alterSql = "ALTER TABLE cu_runtime_config
      ADD COLUMN plan_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用 Plan-Act-Verify 架构（0=关闭走原逻辑，1=启用）' AFTER vls_failure_threshold,
      ADD COLUMN plan_model VARCHAR(100) NOT NULL DEFAULT 'deepseek-v4-pro' COMMENT 'Plan 阶段规划模型（文本模型即可）' AFTER plan_enabled,
      ADD COLUMN verify_model VARCHAR(100) NOT NULL DEFAULT 'kimi-k2.5' COMMENT 'Verify 阶段独立裁判模型（必须为视觉模型）' AFTER plan_model,
      ADD COLUMN verify_max_rounds INT(11) NOT NULL DEFAULT 3 COMMENT '单步骤验证失败后最大补全轮次' AFTER verify_model,
      ADD COLUMN plan_max_steps INT(11) NOT NULL DEFAULT 10 COMMENT 'Plan 阶段最大步骤数' AFTER verify_max_rounds,
      ADD COLUMN step_action_max_iterations INT(11) NOT NULL DEFAULT 20 COMMENT '单步骤 Act 阶段最大迭代次数' AFTER plan_max_steps,
      ADD COLUMN verify_token_budget INT(11) NOT NULL DEFAULT 2000 COMMENT 'Verify 阶段 LLM 最大 token 输出' AFTER step_action_max_iterations";
    try {
        $pdo->exec($alterSql);
        $steps[] = '✅ cu_runtime_config 新增 7 字段';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $steps[] = '⏭️ cu_runtime_config 字段已存在，跳过';
        } else {
            throw $e;
        }
    }

    // ===== 2. INSERT system_prompts 3 行 =====
    $plannerPrompt = '你是 MoonYa CU 模式的任务规划器。将用户的高级目标拆解为可执行的步骤列表。

## 输出格式（严格 JSON，无 Markdown 包裹）
{"steps":[{"id":1,"title":"用一句完整中文描述这步做什么","task_type":"drag|click|type|key|observe|scroll","expected_outcome":"这步完成后屏幕上应该看到什么（用于验证器判断）"}]}

## task_type 选择指南
- drag: 需要拖拽/绘画/选区操作（如画线、画圆、拖动文件）
- click: 需要精确点击按钮/菜单/元素
- type: 需要键盘输入文本
- key: 需要快捷键操作（如 Ctrl+S 保存）
- observe: 仅需观察界面状态（如等待加载完成、确认应用已打开）
- scroll: 需要滚动页面

## 规划原则
1. 步骤数 2-10 个，避免过度拆分
2. 每步的 expected_outcome 必须是可视觉验证的具体状态（如"画布上出现一个圆形"而非"画一个圆"）
3. 不假设特定应用——方法论适用于任何自绘应用（画图/PS/QQ画板/任何 Canvas）
4. 如目标过于简单（如"打开记事本"），返回单步骤即可
5. 打开应用类步骤用 observe 类型（仅截图确认已打开）
6. 绘画/拖拽类步骤用 drag 类型
7. 输入文字用 type 类型，快捷键用 key 类型';

    $verifierPrompt = '你是 MoonYa CU 模式的独立验证器。你的任务是判断用户目标的某一步骤是否真正完成。

## 输入
- 用户原始目标
- 当前步骤的 title 和 expected_outcome
- 最新屏幕截图

## 输出格式（严格 JSON，无 Markdown 包裹）
{"completed":true,"reason":"判断依据：截图中实际看到了什么","missing":""}
或
{"completed":false,"reason":"判断依据：截图中实际看到了什么","missing":"还缺什么未完成"}

## 判断原则
1. 严格基于截图可见内容判断，不要假设操作已生效
2. 如果截图显示空白画布但步骤要求"画出小猫"，completed=false
3. 如果截图显示目标内容已出现，completed=true
4. 不关心操作过程，只看最终结果是否符合 expected_outcome
5. 宁可误判未完成（触发补全），不可误判已完成（虚假成功）
6. reason 必须描述截图中实际看到的内容，而非复述步骤目标';

    $stepRouterPrompt = '## 当前步骤路由信息
步骤 {step_id}: {step_title}
任务类型: {task_type}
预期结果: {expected_outcome}

请根据 task_type 选择合适的工具策略：
- drag: 优先 mouse_drag，跳过 get_cursor_pos 坐标验证（绘画不需要精确点击验证）。直线用 from/to，曲线用 points 数组
- click: 遵循 VLS 强制流程（screenshot→move→get_cursor_pos≤30px→click→screenshot验证）
- type: click 激活输入框 → keyboard_type 输入内容
- key: 直接 key_press，不需要鼠标定位
- observe: 仅 take_screenshot 观察界面状态，不执行操作类工具
- scroll: mouse_scroll 后截图验证';

    $prompts = [
        [15, 'cu_planner', 'CU 规划器', $plannerPrompt],
        [16, 'cu_verifier', 'CU 验证器', $verifierPrompt],
        [17, 'cu_step_router', 'CU 步骤路由器', $stepRouterPrompt],
    ];
    foreach ($prompts as [$id, $name, $label, $prompt]) {
        $stmt = $pdo->prepare(
            "INSERT INTO system_prompts (id, name, display_name, prompt, applicable_models, enabled, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, '[\"*\"]', 1, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE prompt = VALUES(prompt), display_name = VALUES(display_name), enabled = 1, updated_at = NOW()"
        );
        $stmt->execute([$id, $name, $label, $prompt, $id]);
    }
    $steps[] = '✅ system_prompts 新增/更新 3 行（cu_planner / cu_verifier / cu_step_router）';

    // ===== 3. UPDATE scenario_hints 新增 2 键 =====
    $stmt = $pdo->query("SELECT scenario_hints FROM cu_runtime_config WHERE id = 1 LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $hints = json_decode($row['scenario_hints'] ?? '{}', true) ?: [];

    $hints['self_drawing_methodology'] = '### 自绘应用通用方法论（适用于画图/PS/QQ画板/任何 Canvas 应用）
1. **识别界面布局**: 截图 → 找到工具栏区域和画布区域（用窗口边界 x/y/w/h 定位）
2. **选择工具**: 如果需要画笔/铅笔/形状工具，先点击工具栏对应图标（用窗口边界计算坐标）
3. **操作画布**: 在画布区域用 mouse_drag 绘制（直线用 from/to，曲线用 points 数组）
4. **逐步验证**: 每画一个元素后截图确认效果
5. **不假设 UIA**: 自绘 Canvas 的内部元素无法被 UIA 定位，必须用坐标操作
6. **通用性**: 本方法论适用于任何自绘应用，不针对特定应用硬编码';

    $hints['drag_task_strategy'] = '### 拖拽/绘画任务策略
- drag 类型步骤跳过 get_cursor_pos 坐标验证（绘画不需要精确点击验证，直接 mouse_drag）
- 画直线: mouse_drag(from_x, from_y, to_x, to_y)
- 画曲线/圆: mouse_drag(points=[{x,y},{x,y},...])，每段间距 20-40 像素
- 画封闭图形（如圆/猫头）: 传 8-12 个点形成闭环
- 用窗口边界计算画布中心: center_x = window_x + window_w/2, center_y = window_y + window_h/2
- 拖拽文件: 先 mouse_move 到文件位置 → mouse_drag 到目标位置';

    $stmt = $pdo->prepare("UPDATE cu_runtime_config SET scenario_hints = ? WHERE id = 1");
    $stmt->execute([json_encode($hints, JSON_UNESCAPED_UNICODE)]);
    $steps[] = '✅ scenario_hints 新增 2 键（self_drawing_methodology / drag_task_strategy）';

    // ===== 输出结果 =====
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>迁移完成</title></head><body style="font-family:system-ui;padding:32px;max-width:760px;margin:0 auto">';
    echo '<h2 style="color:#52c41a">✅ CU Plan-Act-Verify 迁移完成</h2>';
    echo '<p>执行结果：</p><ul>';
    foreach ($steps as $s) {
        echo "<li>{$s}</li>";
    }
    echo '</ul>';
    echo '<p>当前 <code>plan_enabled=0</code>（关闭），原 CU 逻辑不受影响。</p>';
    echo '<p>启用 Plan-Act-Verify：<code>UPDATE cu_runtime_config SET plan_enabled=1 WHERE id=1</code></p>';
    echo '<p style="color:#888;font-size:12px">建议立即删除本文件：<code>' . __FILE__ . '</code></p>';
    echo '</body></html>';
} catch (Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>迁移失败</title></head><body style="font-family:system-ui;padding:32px;color:#c00">';
    echo '<h2>迁移失败</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</body></html>';
}
