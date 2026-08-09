<?php
declare(strict_types=1);

// 视频子系统与主站共用唯一配置加载器，环境变量优先级由主配置统一处理。
return require dirname(__DIR__) . '/config.php';
