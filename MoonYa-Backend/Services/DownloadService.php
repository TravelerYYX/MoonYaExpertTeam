<?php

class DownloadService {
    private $config;
    private $logger;

    public function __construct($config) {
        $this->config = $config['download'] ?? [];
        
        // Ensure directories exist
        if (!is_dir($this->config['storage_path'] ?? '')) {
            @mkdir($this->config['storage_path'], 0755, true);
        }
        if (!is_dir($this->config['temp_path'] ?? '')) {
            @mkdir($this->config['temp_path'], 0755, true);
        }
    }

    public function setLogger($logger) {
        $this->logger = $logger;
    }

    /**
     * Validate download parameters
     */
    public function validateParams($params) {
        if (empty($params['url'])) {
            return ['success' => false, 'code' => 400, 'error' => '缺少必要参数', 'details' => 'url 参数为必填项'];
        }
        $url = $params['url'];
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $url)) {
            return ['success' => false, 'code' => 400, 'error' => 'URL格式无效', 'details' => '仅支持 http/https 协议的URL'];
        }
        return ['success' => true];
    }

    /**
     * Extract filename from URL or use provided name
     */
    public function extractFilename($url, $suggestedName = null) {
        if (!empty($suggestedName)) {
            return $suggestedName;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if ($path) {
            $filename = basename($path);
            if (!empty($filename)) {
                return $filename;
            }
        }
        return 'download_' . date('Ymd_His') . '.bin';
    }

    /**
     * Normalize path to prevent traversal attacks
     */
    public function normalizePath($filename) {
        // Remove any path components from filename
        $filename = basename($filename);
        // Remove dangerous characters
        $filename = preg_replace('/[<>:"\/\\|?*\\x00-\\x1f]/', '_', $filename);
        if (empty($filename)) {
            $filename = 'download_' . time();
        }
        return $filename;
    }

    /**
     * Check if file extension is allowed
     */
    public function isExtensionAllowed($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (empty($ext)) return true; // allow if no extension
        $allowed = $this->config['allowed_extensions'] ?? [];
        return in_array($ext, $allowed);
    }

    /**
     * Check MIME type against whitelist
     */
    public function isMimeTypeAllowed($mimeType) {
        $allowed = $this->config['allowed_mime_types'] ?? [];
        if (empty($allowed)) return true;
        return in_array($mimeType, $allowed);
    }

    /**
     * Verify file magic bytes to detect spoofed MIME types
     */
    public function verifyMagicBytes($filePath, $claimedMime) {
        if (!file_exists($filePath) || filesize($filePath) < 4) {
            return false;
        }
        $handle = fopen($filePath, 'rb');
        $header = fread($handle, 8);
        fclose($handle);

        // Common magic bytes map
        $magicMap = [
            // Images
            "\xFF\xD8\xFF" => 'image/jpeg',
            "\x89PNG" => 'image/png',
            "GIF8" => 'image/gif',
            "RIFF" => 'image/webp', // also .avi, check sub-type
            // PDF
            "%PDF" => 'application/pdf',
            // ZIP-based (docx, xlsx, pptx)
            "PK\x03\x04" => 'application/zip',
            // GZIP
            "\x1F\x8B" => 'application/gzip',
            // RAR
            "Rar!" => 'application/x-rar-compressed',
            // 7z
            "7z\xBC\xAF\x27\x1C" => 'application/x-7z-compressed',
        ];

        foreach ($magicMap as $magic => $expectedMime) {
            if (strncmp($header, $magic, strlen($magic)) === 0) {
                // Special handling: RIFF could be WEBP or AVI
                if ($magic === 'RIFF') {
                    $subType = substr($header, 8, 4);
                    if ($subType === 'WEBP') return strpos($claimedMime, 'image/') === 0;
                    if ($subType === 'AVI ') return strpos($claimedMime, 'video/') === 0;
                }
                // ZIP can be many formats, just trust it
                if ($magic === "PK\x03\x04" && strpos($claimedMime, 'zip') !== false) return true;
                if ($magic === "PK\x03\x04") return true; // allow all ZIP-based formats
                return true;
            }
        }
        
        // If no magic bytes matched but header is non-empty, it's suspicious
        // But allow common text-based files
        $textLike = ['text/', 'application/json', 'application/xml'];
        foreach ($textLike as $t) {
            if (strpos($claimedMime, $t) === 0) return true;
        }
        
        return false; // suspicious - refuse
    }

    /**
     * Check user permission for file type category
     */
    public function checkPermission($ext, $userRole = 'guest') {
        if (!($this->config['enable_permission_check'] ?? true)) {
            return true;
        }
        
        $categories = $this->config['permission_categories'] ?? [];
        $fileCategory = 'unknown';
        foreach ($categories as $cat => $exts) {
            if (in_array(strtolower($ext), $exts)) {
                $fileCategory = $cat;
                break;
            }
        }
        
        $levels = $this->config['permission_levels'] ?? [];
        $allowed = $levels[$userRole] ?? $levels['guest'] ?? [];
        
        if ($allowed === ['all']) return true;
        return in_array($fileCategory, $allowed);
    }

    /**
     * Stream file content to output
     */
    public function streamFile($filePath, $filename, $mimeType) {
        if (!file_exists($filePath)) {
            return ['success' => false, 'code' => 404, 'error' => '文件不存在'];
        }
        
        $fileSize = filesize($filePath);
        
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . $fileSize);
        header('X-Download-Status: success');
        header('X-Download-Metadata: ' . json_encode([
            'name' => $filename,
            'size' => $fileSize,
            'type' => $mimeType,
            'path' => $filePath,
            'modified_at' => date('c', filemtime($filePath))
        ]));
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
        
        // Stream with rate limiting if configured
        $rateLimit = $this->config['rate_limit_kbps'] ?? 0;
        
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return ['success' => false, 'code' => 500, 'error' => '无法读取文件'];
        }
        
        $bytesSent = 0;
        $startTime = microtime(true);
        
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            echo $chunk;
            $bytesSent += strlen($chunk);
            
            // Rate limiting
            if ($rateLimit > 0) {
                $elapsed = microtime(true) - $startTime;
                $expectedTime = ($bytesSent / 1024) / $rateLimit;
                if ($elapsed < $expectedTime) {
                    usleep((int)(($expectedTime - $elapsed) * 1000000));
                }
            }
            
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
        
        fclose($handle);
        return ['success' => true];
    }

    /**
     * Standard success response
     */
    public static function successResponse($fileInfo) {
        return [
            'success' => true,
            'code' => 200,
            'message' => '下载成功',
            'file' => $fileInfo,
            'progress' => [
                'percentage' => 100,
                'downloaded_bytes' => $fileInfo['size'] ?? 0,
                'total_bytes' => $fileInfo['size'] ?? 0
            ]
        ];
    }

    /**
     * Standard error response
     */
    public static function errorResponse($code, $error, $details = '') {
        $result = [
            'success' => false,
            'code' => $code,
            'error' => $error
        ];
        if ($details) {
            $result['details'] = $details;
        }
        return $result;
    }

    /**
     * Clean up old temporary files
     */
    public function cleanupTempFiles() {
        $tempPath = $this->config['temp_path'] ?? '';
        $ttl = $this->config['temp_file_ttl'] ?? 3600;
        
        if (!is_dir($tempPath)) return;
        
        $files = glob($tempPath . '*');
        $now = time();
        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $ttl) {
                @unlink($file);
            }
        }
    }

    /**
     * Download a remote file to local temp storage
     */
    public function downloadToLocal($url, $filename) {
        $tempPath = $this->config['temp_path'] ?? sys_get_temp_dir() . '/';
        $safeName = $this->normalizePath($filename);
        $localPath = $tempPath . $safeName;
        
        // Check file type before downloading
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if (!$this->isExtensionAllowed($safeName)) {
            return DownloadService::errorResponse(403, '文件类型不允许下载', "扩展名 .{$ext} 不在允许列表中");
        }
        
        // Download using cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'MoonYa-Agent/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $fp = fopen($localPath, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        
        if ($httpCode !== 200 || $error) {
            @unlink($localPath);
            return DownloadService::errorResponse(502, '下载失败', $error ?: "HTTP {$httpCode}");
        }
        
        // Verify magic bytes
        $mimeType = $contentType ?: 'application/octet-stream';
        if (!$this->verifyMagicBytes($localPath, $mimeType)) {
            @unlink($localPath);
            return DownloadService::errorResponse(403, '安全警告：文件类型与声称的不匹配，拒绝下载');
        }
        
        $fileSize = filesize($localPath);
        $maxSize = $this->config['max_file_size'] ?? 524288000;
        if ($fileSize > $maxSize) {
            @unlink($localPath);
            return DownloadService::errorResponse(413, "文件过大，超过最大允许大小 {$maxSize} 字节");
        }
        
        return [
            'success' => true,
            'local_path' => $localPath,
            'file' => [
                'name' => $safeName,
                'size' => $fileSize,
                'type' => $mimeType,
                'path' => $localPath,
                'modified_at' => date('c')
            ]
        ];
    }
}
