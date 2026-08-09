<?php
declare(strict_types=1);

/**
 * Cross-request advisory resource locks for desktop/browser/app/file mutations.
 * Keys are derived from the registered transport and concrete call arguments,
 * never from a client-provided current-agent identity.
 */
final class ResourceLockManager
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?: rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'moonya-team-locks';
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('无法创建团队资源锁目录');
        }
    }

    public function keysFor(
        array $tool,
        string $toolKey,
        array $arguments,
        string $effectiveEffect = 'write',
        ?array $taskScope = null
    ): array
    {
        if ($effectiveEffect === 'read') {
            return [];
        }
        $transport = (string)($tool['transport'] ?? '');
        $keys = [];
        if ($transport === 'launcher_cu') {
            $keys[] = 'desktop:primary';
        }
        if ($transport === 'browser') {
            $session = (string)($arguments['session_id'] ?? $arguments['browser_session'] ?? 'default');
            $keys[] = 'browser:' . $session;
        }
        if (in_array($toolKey, ['install_app', 'uninstall_app', 'open_app', 'close_app', 'download_app'], true)) {
            $app = (string)($arguments['app_name'] ?? $arguments['package_id'] ?? $arguments['name'] ?? 'system');
            $normalizedApp = trim($app);
            $keys[] = 'app:' . (
                function_exists('mb_strtolower')
                    ? mb_strtolower($normalizedApp, 'UTF-8')
                    : strtolower($normalizedApp)
            );
        }
        $pathKeys = [
            'path', 'file', 'file_path', 'folder', 'directory',
            'source', 'source_path', 'destination', 'destination_path', 'target_path',
        ];
        foreach ($pathKeys as $pathKey) {
            if (!isset($arguments[$pathKey]) || !is_string($arguments[$pathKey]) || trim($arguments[$pathKey]) === '') {
                continue;
            }
            $keys[] = 'path:' . $this->normalizePath($arguments[$pathKey]);
        }
        foreach ((array)($arguments['affected_paths'] ?? []) as $affectedPath) {
            if (is_string($affectedPath) && trim($affectedPath) !== '') {
                $keys[] = 'path:' . $this->normalizePath($affectedPath);
            }
        }
        // An opaque mutation without a concrete target remains conservative,
        // but `cwd` is never treated as a target when explicit paths exist.
        if ($keys === []) {
            $projectRoot = (string)($taskScope['project_root'] ?? $arguments['project_path'] ?? $arguments['cwd'] ?? '');
            $keys[] = $projectRoot !== ''
                ? 'project:' . $this->normalizePath($projectRoot)
                : 'tool:' . $toolKey;
        }
        $keys = array_values(array_unique(array_filter($keys, static fn(string $key): bool => $key !== '')));
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * @return array<int, resource>
     */
    public function acquire(
        array $keys,
        int $timeoutSeconds = 0,
        ?callable $cancelled = null,
        ?callable $heartbeat = null
    ): array
    {
        $handles = [];
        $deadline = $timeoutSeconds > 0 ? microtime(true) + $timeoutSeconds : null;
        $lastHeartbeat = 0.0;
        try {
            foreach ($keys as $key) {
                $path = $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.lock';
                $handle = fopen($path, 'c+');
                if ($handle === false) {
                    throw new RuntimeException("无法打开资源锁：{$key}");
                }
                while (!flock($handle, LOCK_EX | LOCK_NB)) {
                    $cooperativePump = $GLOBALS['teamCooperativePump'] ?? null;
                    if (is_callable($cooperativePump)) {
                        $cooperativePump();
                    }
                    if ($deadline !== null && microtime(true) >= $deadline) {
                        fclose($handle);
                        throw new RuntimeException("等待资源锁超时：{$key}");
                    }
                    if (($cancelled !== null && $cancelled()) || connection_aborted()) {
                        fclose($handle);
                        throw new RuntimeException('run_cancelled');
                    }
                    if ($heartbeat !== null && microtime(true) - $lastHeartbeat >= 5.0) {
                        $heartbeat($key);
                        $lastHeartbeat = microtime(true);
                    }
                    usleep(50000);
                }
                ftruncate($handle, 0);
                fwrite($handle, json_encode([
                    'key' => $key,
                    'pid' => getmypid(),
                    'acquired_at' => gmdate('c'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                fflush($handle);
                $handles[] = $handle;
            }
            return $handles;
        } catch (Throwable $e) {
            $this->release($handles);
            throw $e;
        }
    }

    /**
     * @param array<int, resource> $handles
     */
    public function release(array $handles): void
    {
        foreach (array_reverse($handles) as $handle) {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?: $path;
        if (preg_match('/^[A-Za-z]:\//', $path)) {
            $path = strtolower(substr($path, 0, 1)) . substr($path, 1);
        }
        return rtrim($path, '/');
    }
}
