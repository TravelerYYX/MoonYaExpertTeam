<?php
/**
 * DownloadMonitor - 下载监控和统计模块
 * 提供实时下载统计、异常告警和性能指标收集
 */
class DownloadMonitor {
    private $config;
    private $logPath;
    private $statsFile;
    private $alertThreshold;
    private $windowMinutes;
    private $stats;

    public function __construct($config) {
        $this->config = $config['download'] ?? [];
        $this->logPath = $this->config['log_path'] ?? (__DIR__ . '/logs/');
        $this->statsFile = $this->logPath . 'download_stats.json';
        $this->alertThreshold = $this->config['monitor_alert_threshold'] ?? 0.3;
        $this->windowMinutes = $this->config['monitor_window_minutes'] ?? 5;
        
        $this->stats = $this->loadStats();

        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }
    }

    /**
     * Load stats from persistent storage
     */
    private function loadStats() {
        if (file_exists($this->statsFile)) {
            $data = json_decode(file_get_contents($this->statsFile), true);
            if ($data) return $data;
        }
        
        return [
            'total_requests' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'total_bytes' => 0,
            'active_downloads' => 0,
            'recent_events' => [],     // [(timestamp, success_bool, duration_ms)]
            'recent_durations' => [],   // [duration_ms, ...] for percentile calc
            'last_alert_at' => 0,
            'alert_count' => 0,
        ];
    }

    /**
     * Save stats to persistent storage
     */
    private function saveStats() {
        @file_put_contents($this->statsFile, json_encode($this->stats, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * Record a download start
     */
    public function recordStart() {
        $this->stats['active_downloads']++;
        $this->saveStats();
    }

    /**
     * Record a download completion
     */
    public function recordComplete($success, $durationMs = 0, $fileSize = 0) {
        $this->stats['total_requests']++;
        $this->stats['active_downloads'] = max(0, $this->stats['active_downloads'] - 1);
        
        if ($success) {
            $this->stats['total_success']++;
            $this->stats['total_bytes'] += $fileSize;
        } else {
            $this->stats['total_failed']++;
        }

        // Add to recent events
        $now = microtime(true);
        $this->stats['recent_events'][] = [$now, $success, $durationMs];
        
        // Track duration for percentile calculation
        $this->stats['recent_durations'][] = $durationMs;
        
        // Trim old events outside the window
        $windowStart = $now - ($this->windowMinutes * 60);
        $this->stats['recent_events'] = array_filter(
            $this->stats['recent_events'],
            function($e) use ($windowStart) { return $e[0] > $windowStart; }
        );
        
        // Keep only last 1000 durations for percentile calc
        if (count($this->stats['recent_durations']) > 1000) {
            $this->stats['recent_durations'] = array_slice($this->stats['recent_durations'], -1000);
        }

        $this->saveStats();

        // Check for alert condition
        $this->checkAlert();

        // Persist stats to log file every minute
        $this->persistStatsLog();
    }

    /**
     * Check if alert threshold is exceeded
     */
    private function checkAlert() {
        $recent = $this->stats['recent_events'];
        if (count($recent) < 5) return; // Not enough data
        
        $total = count($recent);
        $failed = count(array_filter($recent, function($e) { return !$e[1]; }));
        $failureRate = $failed / $total;
        
        if ($failureRate >= $this->alertThreshold) {
            $now = time();
            // Don't alert more than once per 5 minutes
            if ($now - $this->stats['last_alert_at'] < 300) return;
            
            $this->stats['last_alert_at'] = $now;
            $this->stats['alert_count']++;
            $this->saveStats();
            
            $this->triggerAlert($failureRate, $total, $failed);
        }
    }

    /**
     * Trigger an alert
     */
    private function triggerAlert($failureRate, $total, $failed) {
        $alertMsg = sprintf(
            "[ALERT] 下载失败率 %.1f%%（最近%d次请求中%d次失败），已超过阈值 %.0f%%",
            $failureRate * 100, $total, $failed, $this->alertThreshold * 100
        );
        
        // Write alert to log
        @file_put_contents(
            $this->logPath . 'download_alerts.log',
            date('Y-m-d H:i:s') . ' ' . $alertMsg . "\n",
            FILE_APPEND | LOCK_EX
        );

        // Collect recent failed request details
        $recentFailed = array_filter($this->stats['recent_events'], function($e) { return !$e[1]; });
        if (count($recentFailed) > 0) {
            $detailMsg = "  最近失败请求详情:\n";
            foreach (array_slice($recentFailed, -5) as $fe) {
                $detailMsg .= sprintf("    时间: %s, 耗时: %dms\n", date('H:i:s', (int)$fe[0]), $fe[2]);
            }
            @file_put_contents($this->logPath . 'download_alerts.log', $detailMsg, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Persist stats to log periodically
     */
    private function persistStatsLog() {
        static $lastPersist = 0;
        $now = time();
        if ($now - $lastPersist < 60) return; // Only every 60 seconds
        $lastPersist = $now;
        
        $stats = $this->getSummary();
        @file_put_contents(
            $this->logPath . 'download_stats_' . date('Y-m-d') . '.log',
            date('Y-m-d H:i:s') . ' ' . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Calculate P50, P95, P99 from recent durations
     */
    public function calculatePercentiles() {
        $durations = $this->stats['recent_durations'];
        if (empty($durations)) {
            return ['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0];
        }
        
        sort($durations);
        $count = count($durations);
        
        $avg = array_sum($durations) / $count;
        $p50 = $durations[(int)($count * 0.50)] ?? end($durations);
        $p95 = $durations[(int)($count * 0.95)] ?? end($durations);
        $p99 = $durations[(int)($count * 0.99)] ?? end($durations);
        
        return [
            'p50' => $p50,
            'p95' => $p95,
            'p99' => $p99,
            'avg' => round($avg, 2),
        ];
    }

    /**
     * Get download speed from recent events
     */
    public function getAverageSpeed() {
        $recent = $this->stats['recent_events'];
        if (empty($recent)) return 0;
        
        $totalDuration = 0;
        foreach ($recent as $e) {
            $totalDuration += $e[2];
        }
        
        $avgDurationMs = $totalDuration / count($recent);
        $totalBytes = $this->stats['total_bytes'];
        
        if ($avgDurationMs > 0 && $totalBytes > 0) {
            // Approximate: bytes / avg_duration = bytes/ms, convert to KB/s
            return round(($totalBytes / max(count($recent), 1)) / ($avgDurationMs / 1000) / 1024, 2);
        }
        
        return 0;
    }

    /**
     * Get current failure rate in the monitoring window
     */
    public function getFailureRate() {
        $recent = $this->stats['recent_events'];
        if (empty($recent)) return 0;
        
        $failed = count(array_filter($recent, function($e) { return !$e[1]; }));
        return round($failed / count($recent) * 100, 2);
    }

    /**
     * Get comprehensive summary
     */
    public function getSummary() {
        $recent = $this->stats['recent_events'];
        $total = count($recent);
        $success = count(array_filter($recent, function($e) { return $e[1]; }));
        $failed = $total - $success;
        
        return [
            'total_requests' => $this->stats['total_requests'],
            'total_success' => $this->stats['total_success'],
            'total_failed' => $this->stats['total_failed'],
            'success_rate' => $this->stats['total_requests'] > 0 
                ? round($this->stats['total_success'] / $this->stats['total_requests'] * 100, 2) 
                : 100,
            'window_total' => $total,
            'window_success' => $success,
            'window_failed' => $failed,
            'window_failure_rate' => $this->getFailureRate(),
            'total_bytes' => $this->stats['total_bytes'],
            'active_downloads' => $this->stats['active_downloads'],
            'avg_speed_kbps' => $this->getAverageSpeed(),
            'percentiles' => $this->calculatePercentiles(),
            'alert_count' => $this->stats['alert_count'],
            'last_alert_at' => $this->stats['last_alert_at'] > 0 
                ? date('Y-m-d H:i:s', $this->stats['last_alert_at']) 
                : null,
        ];
    }

    /**
     * Get stats as JSON
     */
    public function getStatsJson() {
        return json_encode($this->getSummary(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Reset all stats
     */
    public function reset() {
        $this->stats = [
            'total_requests' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'total_bytes' => 0,
            'active_downloads' => 0,
            'recent_events' => [],
            'recent_durations' => [],
            'last_alert_at' => 0,
            'alert_count' => 0,
        ];
        $this->saveStats();
    }
}
