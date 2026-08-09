<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function __construct($config) {
        $this->host = $config['db_host'];
        $this->db_name = $config['db_name'];
        $this->username = $config['db_user'];
        $this->password = $config['db_pass'];
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch(PDOException $e) {
            throw new Exception("数据库连接失败: " . $e->getMessage());
        }
        return $this->conn;
    }

    public function initializeTables() {
        $sql = [
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email VARCHAR(100) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                status ENUM('active', 'banned', 'deleted') DEFAULT 'active',
                ban_reason TEXT,
                ban_until DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_username (username),
                INDEX idx_email (email),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS admin_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT,
                action VARCHAR(100) NOT NULL,
                target_user_id INT,
                details TEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin_id (admin_id),
                INDEX idx_target_user_id (target_user_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS admins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                role ENUM('super_admin', 'admin') DEFAULT 'admin',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS version_updates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                version VARCHAR(50) NOT NULL,
                title VARCHAR(200) NOT NULL,
                content TEXT NOT NULL,
                video_url VARCHAR(500) DEFAULT '',
                image_url VARCHAR(500) DEFAULT '',
                is_force TINYINT(1) DEFAULT 0,
                close_delay INT DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_version (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS splash_pages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                image_url VARCHAR(500) NOT NULL,
                jump_url VARCHAR(500) DEFAULT '',
                sort_order INT DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS mobile_updates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                version VARCHAR(50) NOT NULL,
                title VARCHAR(200) NOT NULL,
                content TEXT NOT NULL,
                download_url VARCHAR(500) NOT NULL DEFAULT '',
                is_force TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_version (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];

        foreach ($sql as $query) {
            $this->conn->exec($query);
        }

        $migrations = [
            "ALTER TABLE version_updates ADD COLUMN video_url VARCHAR(500) DEFAULT '' AFTER content",
            "ALTER TABLE version_updates ADD COLUMN image_url VARCHAR(500) DEFAULT '' AFTER video_url",
            "ALTER TABLE version_updates ADD COLUMN close_delay INT DEFAULT 0 AFTER is_force"
        ];

        foreach ($migrations as $migration) {
            try {
                $this->conn->exec($migration);
            } catch (PDOException $e) {
                if ($e->getCode() != '42S21') {
                    throw $e;
                }
            }
        }
    }
}
