<?php
declare(strict_types=1);

require_once __DIR__ . '/TeamRepository.php';

/**
 * Extracts image bytes from trusted tool results before TeamEventV1 is encoded.
 * Remote URLs are deliberately never fetched: this class accepts inline image
 * bytes and existing local image artifacts only.
 */
final class TeamMediaStore
{
    private TeamRepository $repository;
    private string $root;

    public function __construct(TeamRepository $repository, ?string $root = null)
    {
        $this->repository = $repository;
        $this->root = $root ?? self::defaultRoot();
    }

    public static function defaultRoot(): string
    {
        $override = trim((string)getenv('MOONYA_WORKLOG_MEDIA_DIR'));
        if ($override !== '') {
            return rtrim($override, "\\/");
        }
        $localAppData = trim((string)getenv('LOCALAPPDATA'));
        if ($localAppData !== '') {
            return rtrim($localAppData, "\\/") . DIRECTORY_SEPARATOR . 'MoonYa' . DIRECTORY_SEPARATOR . 'WorkLogMedia';
        }
        return rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'MoonYa' . DIRECTORY_SEPARATOR . 'WorkLogMedia';
    }

    public function extractFromPayload(
        string $runId,
        ?string $taskId,
        ?string $toolCallId,
        int $eventSeq,
        string $eventName,
        array $payload
    ): array {
        $candidates = [];
        unset($payload['media']);
        $clean = $this->walk($payload, 'payload', $candidates, null);
        if (!is_array($clean)) {
            $clean = [];
        }

        $media = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $fingerprint = hash('sha256', $candidate['bytes']);
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $media[] = $this->store(
                $runId,
                $taskId,
                $toolCallId,
                $eventSeq,
                (string)($payload['source'] ?? $payload['tool_key'] ?? $eventName),
                $candidate
            );
        }
        if ($media !== []) {
            $clean['media'] = $media;
        }
        return $clean;
    }

    /** @return mixed */
    private function walk($value, string $path, array &$candidates, ?string $declaredMime)
    {
        if (is_string($value)) {
            $decoded = $this->decodeInlineImage($value, $declaredMime);
            if ($decoded !== null) {
                $decoded['source_path'] = $path;
                $candidates[] = $decoded;
                return null;
            }
            $trimmed = trim($value);
            if ($trimmed !== '' && strlen($trimmed) <= 80 * 1024 * 1024
                && (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '['))
            ) {
                $json = json_decode($trimmed, true);
                if (is_array($json)) {
                    $before = count($candidates);
                    $cleanJson = $this->walk($json, $path . '.json', $candidates, $declaredMime);
                    if (count($candidates) > $before) {
                        return json_encode($cleanJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            ?: '[media_result_encode_failed]';
                    }
                }
            }
            return $value;
        }
        if (!is_array($value)) {
            return $value;
        }

        $mime = $this->declaredMime($value) ?? $declaredMime;
        $isImageBlock = (($value['type'] ?? '') === 'image')
            || ($mime !== null && str_starts_with(strtolower($mime), 'image/'));
        $result = [];
        foreach ($value as $key => $child) {
            $keyString = strtolower((string)$key);
            $childPath = $path . '.' . (string)$key;
            $imageValueKey = in_array($keyString, [
                'image', 'image_base64', 'base64', 'screenshot', 'screenshot_base64',
                'evidence_image', 'evidence_image_base64', 'image_url', 'data_url'
            ], true) || ($isImageBlock && $keyString === 'data');

            if ($imageValueKey && is_string($child)) {
                $decoded = $this->decodeInlineImage($child, $mime ?? 'image/unknown');
                if ($decoded !== null) {
                    $decoded['source_path'] = $childPath;
                    $candidates[] = $decoded;
                    continue;
                }
            }

            if ($isImageBlock
                && in_array($keyString, ['path', 'local_path', 'file_path'], true)
                && is_string($child)
                && !$this->looksRemote($child)
            ) {
                $decoded = $this->readLocalImage($child, $mime);
                if ($decoded !== null) {
                    $decoded['source_path'] = $childPath;
                    $candidates[] = $decoded;
                    continue;
                }
            }

            $cleanChild = $this->walk($child, $childPath, $candidates, $mime);
            if ($cleanChild !== null) {
                $result[$key] = $cleanChild;
            }
        }
        return $result;
    }

    private function decodeInlineImage(string $value, ?string $declaredMime): ?array
    {
        $value = trim($value);
        $mime = $declaredMime;
        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $value, $match)) {
            $mime = strtolower($match[1]);
            $value = preg_replace('/\s+/', '', $match[2]) ?? '';
        } elseif ($declaredMime === null || !str_starts_with(strtolower($declaredMime), 'image/')) {
            return null;
        } else {
            $value = preg_replace('/\s+/', '', $value) ?? '';
        }
        if ($value === '' || strlen($value) > 80 * 1024 * 1024) {
            return null;
        }
        $bytes = base64_decode($value, true);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        $detected = self::detectMime($bytes);
        if ($detected === null) {
            return null;
        }
        return ['bytes' => $bytes, 'mime_type' => $detected];
    }

    private function readLocalImage(string $path, ?string $declaredMime): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > 80 * 1024 * 1024) {
            return null;
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        $mime = self::detectMime($bytes);
        if ($mime === null) {
            return null;
        }
        return ['bytes' => $bytes, 'mime_type' => $mime];
    }

    private function store(
        string $runId,
        ?string $taskId,
        ?string $toolCallId,
        int $eventSeq,
        string $source,
        array $candidate
    ): array {
        $id = TeamRepository::uuid();
        $mime = (string)$candidate['mime_type'];
        $extension = self::extensionForMime($mime);
        $safeRunId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $runId) ?: 'unknown-run';
        $relative = $safeRunId . '/' . $id . '.' . $extension;
        $thumbnailRelative = $safeRunId . '/' . $id . '.thumb.' . $extension;
        $width = null;
        $height = null;
        $error = null;
        $imageInfo = @getimagesizefromstring($candidate['bytes']);
        if (is_array($imageInfo)) {
            $width = isset($imageInfo[0]) ? (int)$imageInfo[0] : null;
            $height = isset($imageInfo[1]) ? (int)$imageInfo[1] : null;
        }

        try {
            $runDirectory = $this->root . DIRECTORY_SEPARATOR . $safeRunId;
            if (!is_dir($runDirectory) && !@mkdir($runDirectory, 0700, true) && !is_dir($runDirectory)) {
                throw new RuntimeException('media_directory_create_failed');
            }
            $target = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (@file_put_contents($target, $candidate['bytes'], LOCK_EX) === false) {
                throw new RuntimeException('media_write_failed');
            }
            $thumbnailTarget = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $thumbnailRelative);
            if (!$this->writeThumbnail($candidate['bytes'], $mime, $thumbnailTarget)) {
                $thumbnailRelative = $relative;
            }
        } catch (Throwable $exception) {
            $error = self::safeError($exception->getMessage());
            $relative = null;
            $thumbnailRelative = null;
        }

        $record = [
            'id' => $id,
            'run_id' => $runId,
            'task_id' => $taskId,
            'tool_call_id' => $toolCallId,
            'event_seq' => $eventSeq,
            'kind' => $error === null ? 'image' : 'image_error',
            'mime_type' => $mime,
            'width' => $width,
            'height' => $height,
            'relative_path' => $relative,
            'thumbnail_relative_path' => $thumbnailRelative,
            'source' => mb_substr($source !== '' ? $source : 'tool', 0, 160),
            'error_message' => $error,
        ];
        try {
            $this->repository->persistEventMedia($record);
        } catch (Throwable $exception) {
            $record['kind'] = 'image_error';
            $record['error_message'] = 'media_metadata_write_failed';
        }

        $baseUrl = '/api/team.php?action=media&id=' . rawurlencode($id);
        return [
            'id' => $id,
            'kind' => $record['kind'],
            'mime_type' => $mime,
            'width' => $width,
            'height' => $height,
            'thumbnail_url' => $record['error_message'] === null ? $baseUrl . '&variant=thumbnail' : null,
            'content_url' => $record['error_message'] === null ? $baseUrl . '&variant=content' : null,
            'source' => $record['source'],
            'created_at' => gmdate('c'),
            'error' => $record['error_message'],
        ];
    }

    private function writeThumbnail(string $bytes, string $mime, string $target): bool
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            return false;
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return false;
        }
        $width = imagesx($source);
        $height = imagesy($source);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);
            return false;
        }
        $scale = min(1.0, 360 / max($width, $height));
        $targetWidth = max(1, (int)round($width * $scale));
        $targetHeight = max(1, (int)round($height * $scale));
        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefilledrectangle($thumb, 0, 0, $targetWidth, $targetHeight, $transparent);
        }
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        $ok = match ($mime) {
            'image/png' => imagepng($thumb, $target, 7),
            'image/gif' => imagegif($thumb, $target),
            'image/webp' => function_exists('imagewebp') ? imagewebp($thumb, $target, 80) : false,
            'image/bmp' => false,
            default => imagejpeg($thumb, $target, 82),
        };
        imagedestroy($thumb);
        imagedestroy($source);
        return $ok;
    }

    public function resolvePath(array $row, string $variant): ?string
    {
        $field = $variant === 'thumbnail' ? 'thumbnail_relative_path' : 'relative_path';
        $relative = trim((string)($row[$field] ?? ''));
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || str_starts_with($relative, '\\')) {
            return null;
        }
        $root = realpath($this->root);
        $candidate = realpath($this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($root === false || $candidate === false || !is_file($candidate)) {
            return null;
        }
        $rootPrefix = rtrim(strtolower($root), "\\/") . DIRECTORY_SEPARATOR;
        if (!str_starts_with(strtolower($candidate), strtolower($rootPrefix))) {
            return null;
        }
        return $candidate;
    }

    public function deleteRunDirectory(string $runId): void
    {
        $safeRunId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $runId) ?: '';
        if ($safeRunId === '') {
            return;
        }
        $root = realpath($this->root);
        $directory = realpath($this->root . DIRECTORY_SEPARATOR . $safeRunId);
        if ($root === false || $directory === false || !is_dir($directory)) {
            return;
        }
        $rootPrefix = rtrim(strtolower($root), "\\/") . DIRECTORY_SEPARATOR;
        if (!str_starts_with(strtolower($directory), strtolower($rootPrefix))) {
            return;
        }
        $this->removeDirectory($directory);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private function declaredMime(array $value): ?string
    {
        foreach (['mime_type', 'mimeType', 'content_type', 'contentType'] as $key) {
            $mime = trim((string)($value[$key] ?? ''));
            if ($mime !== '') {
                return strtolower($mime);
            }
        }
        return null;
    }

    private function looksRemote(string $path): bool
    {
        return preg_match('#^(?:https?|ftp)://#i', trim($path)) === 1;
    }

    private static function detectMime(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) return 'image/png';
        if (str_starts_with($bytes, "\xff\xd8\xff")) return 'image/jpeg';
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) return 'image/gif';
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';
        if (str_starts_with($bytes, 'BM')) return 'image/bmp';
        return null;
    }

    private static function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            default => 'jpg',
        };
    }

    private static function safeError(string $message): string
    {
        $allowed = ['media_directory_create_failed', 'media_write_failed'];
        return in_array($message, $allowed, true) ? $message : 'media_storage_failed';
    }
}
