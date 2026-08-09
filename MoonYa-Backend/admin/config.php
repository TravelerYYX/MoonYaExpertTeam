<?php
require_once __DIR__ . '/../env_loader.php';
return [
    'db_host'      => env('DB_HOST') ?: 'localhost',
    'db_name'      => env('DB_NAME') ?: 'ai_system',
    'db_user'      => env('DB_USER') ?: '',
    'db_pass'      => env('DB_PASS') ?: '',
    'admin_secret' => env('ADMIN_SECRET') ?: '',
    'log_path'     => __DIR__ . '/logs/',   // 本地路径，非凭证
    'jwt_secret'   => env('JWT_SECRET') ?: '',
    'site_url'     => '',                   // 后台站点 URL，非凭证
];
