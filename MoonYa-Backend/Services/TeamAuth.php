<?php
declare(strict_types=1);

final class TeamAuth
{
    public static function connect(array $config): PDO
    {
        return new PDO(
            'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
            $config['db_user'],
            $config['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public static function requireUser(PDO $pdo): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $token = self::bearerToken();
        if ($token !== null) {
            $stmt = $pdo->prepare('SELECT id, token_created_at FROM users WHERE api_token = ? LIMIT 1');
            $stmt->execute([$token]);
            $user = $stmt->fetch();
            if ($user) {
                $createdAt = $user['token_created_at'] ?? null;
                if ($createdAt === null || (time() - strtotime((string)$createdAt)) <= 1296000) {
                    $_SESSION['user_id'] = (int)$user['id'];
                }
            }
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        // Long-running SSE requests and approval POSTs must not block each other.
        session_write_close();

        if ($userId <= 0) {
            throw new RuntimeException('AUTH_REQUIRED');
        }
        return $userId;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($header === null && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }
        if (!is_string($header) || !preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return null;
        }
        $token = trim($matches[1]);
        return $token === '' ? null : $token;
    }
}

