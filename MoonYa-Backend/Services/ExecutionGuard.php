<?php
declare(strict_types=1);

require_once __DIR__ . '/ExecutionJobRepository.php';

final class ExecutionGuard
{
    public static function supports(array $request): bool
    {
        return !empty($request['computer_user_mode'])
            || in_array((string)($request['agent_mode'] ?? ''), ['agent', 'code_agent'], true);
    }

    public static function launch(string $backendRoot, string $jobId): void
    {
        $worker = $backendRoot . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'conversation_task_worker.php';
        if (!is_file($worker)) {
            throw new RuntimeException('execution_guard_worker_missing');
        }
        $php = self::phpCliBinary();
        if ($php === null) {
            throw new RuntimeException('execution_guard_php_binary_missing');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start "" /B ' . escapeshellarg($php)
                . ' ' . escapeshellarg($worker)
                . ' --job=' . escapeshellarg($jobId)
                . ' >NUL 2>&1';
            $handle = @popen($command, 'r');
            if (!is_resource($handle)) {
                throw new RuntimeException('execution_guard_launch_failed');
            }
            @pclose($handle);
            return;
        }

        $command = escapeshellarg($php) . ' ' . escapeshellarg($worker)
            . ' --job=' . escapeshellarg($jobId)
            . ' >/dev/null 2>&1 &';
        @exec($command, $unused, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException('execution_guard_launch_failed');
        }
    }

    public static function stream(
        ExecutionJobRepository $repository,
        array $job,
        int $afterTeamSeq = 0,
        bool $isResume = false
    ): void {
        ignore_user_abort(false);
        $jobId = (string)$job['id'];
        $cursor = 0;
        $lastHeartbeat = 0.0;
        $startedAt = microtime(true);

        if ($isResume) {
            echo 'data: ' . json_encode([
                'type' => 'network.reconnected',
                'run_id' => $job['run_id'] ?? null,
                'job_id' => $jobId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            streamFlush();
        } else {
            echo 'data: ' . json_encode([
                'type' => 'execution.accepted',
                'job_id' => $jobId,
                'status' => (string)$job['status'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            streamFlush();
        }

        while (true) {
            foreach ($repository->eventsAfter($jobId, $cursor) as $event) {
                $cursor = max($cursor, (int)$event['seq']);
                if ($isResume && !self::shouldReplay($event, $afterTeamSeq)) {
                    continue;
                }
                echo (string)$event['frame'];
                streamFlush();
                if (connection_aborted()) {
                    return;
                }
            }

            $job = $repository->find($jobId);
            if (!is_array($job)) {
                echo "data: {\"type\":\"error\",\"content\":\"执行任务不存在\",\"error_code\":\"execution_job_not_found\"}\n\n";
                echo "data: {\"type\":\"done\"}\n\n";
                streamFlush();
                return;
            }

            if (self::isStale($job)) {
                $repository->finish($jobId, 'failed', '执行守护进程心跳超时');
                $repository->appendFrame($jobId, 'data: ' . json_encode([
                    'type' => 'error',
                    'content' => '独立执行守护进程意外中止，请重新发送任务。',
                    'error_code' => 'execution_guard_stale',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $repository->appendFrame($jobId, 'data: {"type":"done"}');
                continue;
            }

            if (ExecutionJobRepository::isTerminal((string)$job['status'])
                && $cursor >= (int)$job['last_event_seq']) {
                return;
            }

            $now = microtime(true);
            if ($now - $lastHeartbeat >= 5.0) {
                echo ": execution-guard-heartbeat\n\n";
                streamFlush();
                $lastHeartbeat = $now;
            }
            if ((string)$job['status'] === 'queued' && $now - $startedAt > 15.0) {
                $repository->finish($jobId, 'failed', '执行守护进程未能启动');
                $repository->appendFrame($jobId, 'data: ' . json_encode([
                    'type' => 'error',
                    'content' => '独立执行守护进程未能启动。',
                    'error_code' => 'execution_guard_launch_timeout',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $repository->appendFrame($jobId, 'data: {"type":"done"}');
                continue;
            }
            if (connection_aborted()) {
                return;
            }
            usleep(100000);
        }
    }

    private static function shouldReplay(array $event, int $afterTeamSeq): bool
    {
        $type = (string)($event['event_type'] ?? '');
        if ($type === 'team_event') {
            return (int)($event['team_seq'] ?? 0) > $afterTeamSeq;
        }
        // Launcher requests are idempotent and must be delivered again until a
        // durable result exists. Terminal/error frames are safe to replay.
        return in_array($type, ['launcher_request', 'error', 'done'], true);
    }

    private static function isStale(array $job): bool
    {
        if ((string)($job['status'] ?? '') !== 'running') {
            return false;
        }
        $heartbeat = strtotime((string)($job['heartbeat_at'] ?? '')) ?: 0;
        return $heartbeat > 0 && time() - $heartbeat > 180;
    }

    private static function phpCliBinary(): ?string
    {
        $configured = getenv('PHP_CLI_BINARY');
        $candidates = [
            is_string($configured) ? $configured : '',
            defined('PHP_BINDIR') ? PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe' : '',
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe',
            'D:\\xampp\\php\\php.exe',
            PHP_BINARY,
        ];
        foreach (array_unique($candidates) as $candidate) {
            if ($candidate === '' || !is_file($candidate)) {
                continue;
            }
            $name = strtolower(pathinfo($candidate, PATHINFO_FILENAME));
            if ($name === 'php' || $name === 'php-cgi') {
                return $candidate;
            }
        }
        return null;
    }
}
