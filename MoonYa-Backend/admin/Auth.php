<?php
class Auth {
    private $db;
    private $jwtSecret;
    private $logger;

    public function __construct($db, $config, $logger) {
        $this->db = $db;
        $this->jwtSecret = $config['jwt_secret'];
        $this->logger = $logger;
    }

    public function authenticate($token) {
        if (empty($token)) {
            return null;
        }

        try {
            $payload = $this->decodeToken($token);
            if (!$payload || !isset($payload['admin_id'])) {
                return null;
            }

            $stmt = $this->db->prepare("SELECT id, username, email, role FROM admins WHERE id = ?");
            $stmt->execute([$payload['admin_id']]);
            $admin = $stmt->fetch();

            return $admin ?: null;
        } catch (Exception $e) {
            $this->logger->log('ERROR', 'Authentication failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function login($username, $password) {
        $stmt = $this->db->prepare("SELECT id, username, password, email, role FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password'])) {
            $this->logger->log('WARNING', 'Failed login attempt', ['username' => $username]);
            return null;
        }

        $token = $this->generateToken($admin);
        $this->logger->log('INFO', 'Admin logged in', ['admin_id' => $admin['id']]);
        return [
            'token' => $token,
            'admin' => [
                'id' => $admin['id'],
                'username' => $admin['username'],
                'email' => $admin['email'],
                'role' => $admin['role']
            ]
        ];
    }

    private function generateToken($admin) {
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode([
            'admin_id' => $admin['id'],
            'username' => $admin['username'],
            'role' => $admin['role'],
            'exp' => time() + (24 * 60 * 60)
        ]));
        $signature = hash_hmac('sha256', $header . '.' . $payload, $this->jwtSecret, true);
        $signature = base64_encode($signature);
        return $header . '.' . $payload . '.' . $signature;
    }

    private function decodeToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($header, $payload, $signature) = $parts;
        $expectedSignature = hash_hmac('sha256', $header . '.' . $payload, $this->jwtSecret, true);
        if (base64_encode($expectedSignature) !== $signature) {
            return null;
        }

        $decoded = json_decode(base64_decode($payload), true);
        if (isset($decoded['exp']) && $decoded['exp'] < time()) {
            return null;
        }

        return $decoded;
    }

    public function hasPermission($admin, $permission) {
        if (!$admin) return false;
        if ($admin['role'] === 'super_admin') return true;
        $adminPermissions = [
            'view_users',
            'edit_users',
            'ban_users',
            'unban_users'
        ];
        return in_array($permission, $adminPermissions);
    }
}
