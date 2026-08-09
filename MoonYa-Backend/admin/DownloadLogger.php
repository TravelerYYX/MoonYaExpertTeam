<?php
/**
 * DownloadLogger - 下载专用日志记录模块
 * 记录所有下载操作的详细信息，支持文件日志和数据库日志
 */
class DownloadLogger {
    private $logPath;
    private $db;
    private $enabled = true;

    public function __construct($config, $dbConnection = null) {
        $this->logPath = $config['log_path'] ?? (__DIR__ . '/logs/');
        $this->db = $dbConnection;
        $this->enabled = $config['log_enabled'] ?? true;

        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }
    }

    /**
     * Log a download event
     */
    public function log($data) {
        if (!$this->enabled) return;

        $date = date('Y-m-d');
        $logFile = $this->logPath . 'download_' . $date . '.log';
        $timestamp = date('Y-m-d H:i:s.v');

        $entry = [
            'request_id' => $data['request_id'] ?? uniqid('dl_', true),
            'source' => $data['source'] ?? 'unknown',
            'url' => $data['url'] ?? '',
            'timestamp' => $timestamp,
            'status' => $data['status'] ?? 'unknown',
            'error_message' => $data['error_message'] ?? '',
            'error_stack' => $data['error_stack'] ?? '',
            'file_info' => $data['file_info'] ?? [],
            'duration_ms' => $data['duration_ms'] ?? 0,
            'client_ip' => $data['client_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            'user_agent' => $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'),
            'user_role' => $data['user_role'] ?? 'guest',
            'file_size' => $data['file_size'] ?? 0,
        ];

        // Write to file log with rotation check
        $this->rotateIfNeeded($logFile);
        $logLine = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

        // Write to database if available
        if ($this->db) {
            $this->logToDatabase($entry);
        }
    }

    /**
     * Rotate log file if it exceeds max size
     */
    private function rotateIfNeeded($logFile) {
        if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) { // 10MB
            $rotated = $logFile . '.' . date('Ymd_His') . '.rotated';
            @rename($logFile, $rotated);
        }
    }

    /**
     * Clean up old log files
     */
    public function cleanupOldLogs($daysToKeep = 30) {
        $files = glob($this->logPath . 'download_*.log*');
        $cutoff = time() - ($daysToKeep * 86400);
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Log to database
     */
    private function logToDatabase($entry) {
        try {
            // Try to create table if not exists
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS download_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    request_id VARCHAR(64) NOT NULL,
                    source VARCHAR(32) NOT NULL DEFAULT 'unknown',
                    url TEXT,
                    status VARCHAR(32) NOT NULL DEFAULT 'unknown',
                    error_message TEXT,
                    error_stack TEXT,
                    file_name VARCHAR(512),
                    file_size BIGINT DEFAULT 0,
                    file_type VARCHAR(128),
                    duration_ms INT DEFAULT 0,
                    client_ip VARCHAR(45),
                    user_agent TEXT,
                    user_role VARCHAR(32) DEFAULT 'guest',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_request_id (request_id),
                    INDEX idx_status (status),
                    INDEX idx_created_at (created_at),
                    INDEX idx_source (source)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $stmt = $this->db->prepare("
                INSERT INTO download_logs 
                (request_id, source, url, status, error_message, error_stack, 
                 file_name, file_size, file_type, duration_ms, client_ip, user_agent, user_role)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $fileInfo = $entry['file_info'] ?? [];
            $stmt->execute([
                $entry['request_id'],
                $entry['source'],
                $entry['url'],
                $entry['status'],
                $entry['error_message'],
                $entry['error_stack'],
                $fileInfo['name'] ?? '',
                $entry['file_size'],
                $fileInfo['type'] ?? '',
                $entry['duration_ms'],
                $entry['client_ip'],
                $entry['user_agent'],
                $entry['user_role'],
            ]);
        } catch (PDOException $e) {
            // Silently fail - don't break the main flow
            @file_put_contents($this->logPath . 'db_error.log',
                date('Y-m-d H:i:s') . ' DB Log Error: ' . $e->getMessage() . "\n",
                FILE_APPEND);
        }
    }

    /**
     * Get client IP safely
     */
    public static function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
    }
}
