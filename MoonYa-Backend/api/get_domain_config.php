<?php
/**
 * get_domain_config.php — 公开只读端点
 *
 * 返回当前主 API 域名配置，供 C# 启动器启动时拉取。
 *
 * 路径：/api/get_domain_config.php
 * 方法：GET（同时处理 OPTIONS 预检）
 * 鉴权：无需登录（公开端点）
 *
 * 返回 JSON：
 *   {"success": true, "data": {"main_api_domain": "https://your-domain.com/"}}
 *
 * 任何异常（DB 连接失败等）都返回空字符串域名，
 * 让启动器回退到本地配置，不向客户端报错。
 */

// ====== CORS & Content-Type ======
require_once dirname(__DIR__) . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

// ====== OPTIONS 预检 ======
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ====== 输出辅助函数 ======
/**
 * 输出成功响应并终止脚本。
 *
 * @param array $data data 字段内容
 */
function sendSuccess($data) {
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// 默认返回空字符串域名（异常 / 记录缺失时使用）
$emptyData = ['main_api_domain' => ''];

try {
    // ====== 加载 env_loader.php 获取 env() 函数 ======
    require_once __DIR__ . '/../env_loader.php';

    // ====== 读取数据库凭证 ======
    $dbHost = env('DB_HOST') ?: 'localhost';
    $dbName = env('DB_NAME') ?: '';
    $dbUser = env('DB_USER') ?: '';
    $dbPass = env('DB_PASS') ?: '';

    if ($dbName === '') {
        // 数据库名缺失，无法连接，回退空字符串
        sendSuccess($emptyData);
    }

    // ====== 创建 PDO 连接 ======
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);

    // ====== 查询 main_api_domain 记录 ======
    $stmt = $pdo->prepare("SELECT config_value FROM api_domain_config WHERE config_key = 'main_api_domain'");
    $stmt->execute();
    $row = $stmt->fetch();

    $domain = '';
    if ($row && isset($row['config_value'])) {
        $domain = (string) $row['config_value'];
    }

    // 记录不存在或值为空 → 返回空字符串
    if ($domain === '') {
        sendSuccess($emptyData);
    }

    // ====== 保证尾部斜杠 ======
    // 非空值若不以 / 结尾，自动追加 /
    if (substr($domain, -1) !== '/') {
        $domain .= '/';
    }

    sendSuccess(['main_api_domain' => $domain]);

} catch (Throwable $e) {
    // 任何异常（DB 连接失败、查询失败等）都返回空字符串域名，不向客户端报错
    sendSuccess($emptyData);
}
