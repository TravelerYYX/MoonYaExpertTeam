<?php
class Logger {
    private $logPath;
    private $db;

    public function __construct($config, $dbConnection) {
        $this->logPath = $config['log_path'];
        $this->db = $dbConnection;
    }

    public function log($level, $message, $context = []) {
        $date = date('Y-m-d');
        $logFile = $this->logPath . $date . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[{$timestamp}] [{$level}] {$message} {$contextStr}\n";
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    public function logAdminAction($adminId, $action, $targetUserId = null, $details = null) {
        try {
            $ip = $this->getClientIP();
            $stmt = $this->db->prepare("
                INSERT INTO admin_logs (admin_id, action, target_user_id, details, ip_address)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$adminId, $action, $targetUserId, $details, $ip]);
            
            $this->log('INFO', "Admin action: {$action}", [
                'admin_id' => $adminId,
                'target_user_id' => $targetUserId,
                'ip' => $ip
            ]);
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to log admin action', ['error' => $e->getMessage()]);
        }
    }

    private function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
    }
}
