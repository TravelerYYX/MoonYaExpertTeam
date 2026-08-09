<?php
/**
 * env_loader.php — 轻量 .env 加载器
 *
 * 解析项目根目录的 .env 文件，将 KEY=VALUE 注入到 $_ENV / $_SERVER / putenv()，
 * 业务代码即可通过 getenv('KEY') 或 $_ENV['KEY'] 读取。
 *
 * 用法：require_once __DIR__ . '/env_loader.php';
 *       $value = getenv('KIMI_API_KEY') ?: '';
 *
 * 特性：
 *   - 支持 # 注释、空行、export KEY=VALUE 前缀
 *   - 支持双引号 "value with spaces" 与单引号 'value'（单引号不解析转义）
 *   - .env 不存在时静默 return，不抛错
 *   - 多次 require 仅解析一次（defined('ENV_LOADED') 守卫）
 *   - 提供 env_required($key) 强校验入口
 */

// 防重复 require
if (defined('ENV_LOADED')) {
    return;
}
define('ENV_LOADED', true);

/**
 * 解析 .env 文件并注入到 PHP 环境。
 *
 * @param string|null $path .env 文件绝对路径，null = __DIR__/.env
 * @return void
 */
if (!function_exists('loadEnv')) {
function loadEnv(?string $path = null): void
{
    $path = $path ?? (__DIR__ . '/.env');

    // .env 不存在 → 静默跳过（开发环境兼容）
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        // 去除首尾空白
        $line = trim($line);

        // 跳过空行与 # 注释
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // 去掉可选的 export 前缀（兼容 shell 习惯）
        if (stripos($line, 'export ') === 0) {
            $line = trim(substr($line, 7));
        }

        // 必须是 KEY=VALUE 形式
        $eqPos = strpos($line, '=');
        if ($eqPos === false || $eqPos === 0) {
            continue;
        }

        $key   = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));

        // key 名必须是合法标识符
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }

        // 处理引号包裹的值
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
                // 双引号解析 \n \r \t \\ \"；单引号按字面
                if ($first === '"') {
                    $value = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $value);
                }
            }
        }

        // 注入到三个通道，getenv() / $_ENV / $_SERVER 都能取到
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
        if (function_exists('putenv')) {
            putenv("$key=$value");
        }
    }
}
}

/**
 * 获取环境变量（兼容 putenv 被禁用的环境）。
 *
 * 优先从 getenv() 读取（OS 环境变量），取不到时回退到 $_ENV。
 * 这样即使服务器禁用了 putenv()，已通过 $_ENV[$key] 注入的值仍能正常工作。
 *
 * 也支持 /etc/hosts / Docker 容器等通过 OS 环境变量注入的场景
 * （此时 getenv() 有值，$_ENV 可能没有）。
 *
 * @param string     $key     环境变量名
 * @param mixed|null $default 取不到时返回的默认值
 * @return mixed
 */
if (!function_exists('env')) {
function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    return $_ENV[$key] ?? $default;
}
}

/**
 * 强校验获取环境变量；缺失或为空时抛 RuntimeException。
 *
 * @param string $key 环境变量名
 * @return string 非空值
 * @throws RuntimeException
 */
if (!function_exists('env_required')) {
function env_required(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? '';
    }
    if ($value === '') {
        throw new RuntimeException("Required environment variable '{$key}' is missing or empty. Please set it in .env");
    }
    return $value;
}
}

// 自动加载项目根目录 .env
loadEnv();
