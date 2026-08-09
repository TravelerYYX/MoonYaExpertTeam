<?php
declare(strict_types=1);

// 保留旧调试入口的 URL 兼容性，但复用唯一的正式实现，避免调试端点继续使用
// 已废弃的内容去重、created_at 排序和非幂等保存逻辑。
require __DIR__ . '/conversation_api.php';
