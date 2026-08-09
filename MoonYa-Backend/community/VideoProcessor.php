<?php
class VideoProcessor
{
    private $config;
    private $ffmpegPath;
    private $ffprobePath;
    private $lastError;

    public function __construct(array $config)
    {
        $this->config = $config;
        foreach (['ffmpeg_path', 'ffprobe_path', 'binary_candidate_directories'] as $requiredField) {
            if (!array_key_exists($requiredField, $config)) {
                throw new InvalidArgumentException("Missing required configuration: community.video_processing.{$requiredField}");
            }
        }
        if (!is_array($config['binary_candidate_directories'])) {
            throw new InvalidArgumentException('Invalid configuration: community.video_processing.binary_candidate_directories');
        }

        $this->ffmpegPath = $this->resolveBinary($config['ffmpeg_path'], 'ffmpeg');
        $this->ffprobePath = $this->resolveBinary($config['ffprobe_path'], 'ffprobe');
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    private function resolveBinary($configuredPath, $binaryName)
    {
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        if (!$isWindows && $this->isWindowsBinary($configuredPath)) {
            $this->lastError = "配置的 {$binaryName} 路径 ({$configuredPath}) 是 Windows 版本，无法在 Linux 服务器上运行。请下载 Linux 版 ffmpeg。";
            $this->log($this->lastError);
        }

        if ($this->testBinary($configuredPath)) {
            return $configuredPath;
        }

        if (!$isWindows && function_exists('shell_exec')) {
            $whichPath = $this->safeWhich($binaryName);
            if ($whichPath && $this->testBinary($whichPath)) {
                return $whichPath;
            }
        }

        if ($isWindows && function_exists('exec')) {
            $wherePath = $this->safeWhere($binaryName);
            if ($wherePath && $this->testBinary($wherePath)) {
                return $wherePath;
            }
        }

        foreach ($this->config['binary_candidate_directories'] as $dir) {
            if (!is_string($dir) || trim($dir) === '') {
                continue;
            }
            $dir = rtrim(trim($dir), "\\/");
            $candidate = $isWindows
                ? $dir . '\\' . $binaryName . '.exe'
                : $dir . '/' . $binaryName;
            if ($this->testBinary($candidate)) {
                return $candidate;
            }
        }

        return $configuredPath;
    }

    private function isWindowsBinary($path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['exe', 'bat', 'cmd', 'msi']);
    }

    private function safeWhich($binaryName)
    {
        $found = @shell_exec('which ' . escapeshellarg($binaryName) . ' 2>/dev/null');
        return $found ? trim($found) : null;
    }

    private function safeWhere($binaryName)
    {
        $output = [];
        $ret = -1;
        @exec('where ' . escapeshellarg($binaryName) . ' 2>NUL', $output, $ret);
        if ($ret === 0 && !empty($output)) {
            return trim($output[0]);
        }
        return null;
    }

    private function testBinary($path)
    {
        if (empty($path)) {
            return false;
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';

        if ($isWindows) {
            if (file_exists($path)) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, ['exe', 'bat', 'cmd'])) {
                    return true;
                }
                if (empty($ext) && file_exists($path . '.exe')) {
                    return true;
                }
            }
        } else {
            if (file_exists($path) && is_executable($path)) {
                return true;
            }
        }

        if (function_exists('exec')) {
            $nullDev = $isWindows ? 'NUL' : '/dev/null';
            $output = [];
            $ret = -1;
            @exec(escapeshellarg($path) . ' -version 2>' . $nullDev, $output, $ret);
            if ($ret === 0) {
                return true;
            }
        }

        return false;
    }

    public function isAvailable()
    {
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        if ($this->isWindowsBinary($this->ffmpegPath) && !$isWindows) {
            $this->lastError = 'ffmpeg 是 Windows 版本 (.exe)，无法在 Linux 服务器上运行。请在服务器上安装 Linux 版 ffmpeg。';
            return false;
        }

        if ($this->isWindowsBinary($this->ffprobePath) && !$isWindows) {
            $this->lastError = 'ffprobe 是 Windows 版本 (.exe)，无法在 Linux 服务器上运行。请在服务器上安装 Linux 版 ffmpeg。';
            return false;
        }

        if (!$this->testBinary($this->ffmpegPath)) {
            $this->lastError = 'ffmpeg 未找到或不可执行: ' . $this->ffmpegPath;
            return false;
        }

        if (!$this->testBinary($this->ffprobePath)) {
            $this->lastError = 'ffprobe 未找到或不可执行: ' . $this->ffprobePath;
            return false;
        }

        return true;
    }

    public function getFfmpegPath()
    {
        return $this->ffmpegPath;
    }

    public function getFfprobePath()
    {
        return $this->ffprobePath;
    }

    private function buildProbeCmd($args)
    {
        $nullDev = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        return escapeshellarg($this->ffprobePath)
             . ' ' . $args
             . ' 2>' . $nullDev;
    }

    public function getResolution($filePath)
    {
        if (!function_exists('shell_exec')) {
            $this->lastError = 'shell_exec 函数被禁用，无法执行 ffprobe';
            return null;
        }

        $cmd = $this->buildProbeCmd('-v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0')
             . ' ' . escapeshellarg($filePath);

        $output = @shell_exec($cmd);
        if ($output === false || $output === null) {
            $this->lastError = 'shell_exec 执行失败，可能被服务器禁用';
            return null;
        }

        $output = trim($output);
        if (empty($output)) {
            return null;
        }

        $lines = explode("\n", $output);
        $firstLine = trim($lines[0]);
        $parts = explode(',', $firstLine);
        if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            return ['width' => (int)$parts[0], 'height' => (int)$parts[1]];
        }

        return null;
    }

    public function getVideoInfo($filePath)
    {
        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => '文件不存在'];
        }

        if (!function_exists('shell_exec')) {
            return ['success' => false, 'error' => 'shell_exec 函数被禁用，无法读取视频信息'];
        }

        $cmd = $this->buildProbeCmd('-v error -show_format -show_streams -print_format json')
             . ' ' . escapeshellarg($filePath);

        $output = @shell_exec($cmd);
        if ($output === false || $output === null) {
            return ['success' => false, 'error' => 'shell_exec 执行失败，可能被服务器禁用'];
        }

        $output = trim($output);
        if (empty($output)) {
            return ['success' => false, 'error' => '无法读取视频信息'];
        }

        $info = json_decode($output, true);
        if (!$info) {
            return ['success' => false, 'error' => '视频信息解析失败'];
        }

        $videoStream = null;
        $audioStream = null;
        foreach ($info['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video' && !$videoStream) {
                $videoStream = $stream;
            }
            if (($stream['codec_type'] ?? '') === 'audio' && !$audioStream) {
                $audioStream = $stream;
            }
        }

        $result = [
            'success' => true,
            'format' => [
                'duration' => isset($info['format']['duration']) ? round((float)$info['format']['duration'], 2) : null,
                'size' => isset($info['format']['size']) ? (int)$info['format']['size'] : null,
                'bit_rate' => $info['format']['bit_rate'] ?? null,
                'format_name' => $info['format']['format_name'] ?? null,
            ],
            'video' => null,
            'audio' => null,
        ];

        if ($videoStream) {
            $result['video'] = [
                'width' => (int)($videoStream['width'] ?? 0),
                'height' => (int)($videoStream['height'] ?? 0),
                'codec' => $videoStream['codec_name'] ?? null,
                'fps' => $videoStream['r_frame_rate'] ?? null,
                'bit_rate' => $videoStream['bit_rate'] ?? null,
            ];
        }

        if ($audioStream) {
            $result['audio'] = [
                'codec' => $audioStream['codec_name'] ?? null,
                'sample_rate' => isset($audioStream['sample_rate']) ? (int)$audioStream['sample_rate'] : null,
                'channels' => isset($audioStream['channels']) ? (int)$audioStream['channels'] : null,
                'bit_rate' => $audioStream['bit_rate'] ?? null,
            ];
        }

        $maxWidth = $this->config['max_resolution']['width'] ?? 1920;
        $maxHeight = $this->config['max_resolution']['height'] ?? 1080;
        if ($result['video']) {
            $result['video']['needs_scaling'] = (
                $result['video']['width'] > $maxWidth || $result['video']['height'] > $maxHeight
            );
        }

        return $result;
    }

    public function process($inputPath, $outputDir = null)
    {
        if (!($this->config['enabled'] ?? true)) {
            return ['success' => false, 'error' => '视频处理功能未启用'];
        }

        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => 'ffmpeg 不可用: ' . ($this->lastError ?? '未知错误')];
        }

        $inputExists = file_exists($inputPath);
        if (!$inputExists) {
            $this->log('输入文件检查: file_exists=' . ($inputExists ? 'true' : 'false') . ', path=' . $inputPath . ', open_basedir=' . ini_get('open_basedir'));
        }

        $resolution = $this->getResolution($inputPath);
        if (!$resolution) {
            $err = $this->lastError ?? '无法检测视频分辨率';
            $this->log('分辨率检测失败: ' . $err . ', inputPath=' . $inputPath);
            return ['success' => false, 'error' => $err, 'debug_input' => $inputPath, 'debug_input_exists' => $inputExists];
        }

        $ext = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
        $allowedFormats = $this->config['input_formats'] ?? ['mp4', 'avi', 'mov', 'flv', 'wmv', 'mkv', 'webm'];
        if (!in_array($ext, $allowedFormats)) {
            return ['success' => false, 'error' => '不支持的视频格式: ' . $ext];
        }

        $maxWidth = $this->config['max_resolution']['width'] ?? 1920;
        $maxHeight = $this->config['max_resolution']['height'] ?? 1080;
        $needScale = ($resolution['width'] > $maxWidth || $resolution['height'] > $maxHeight);

        if ($outputDir === null) {
            $outputDir = $this->config['output_path'] ?? 'uploads/community/hls/';
        }

        $rootPath = $this->config['root_path'] ?? __DIR__ . '/../';
        $fullOutputDir = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . trim($outputDir, '/\\');

        if (!is_dir($fullOutputDir)) {
            if (!mkdir($fullOutputDir, 0777, true)) {
                $this->log('无法创建HLS根目录: ' . $fullOutputDir . ', open_basedir=' . ini_get('open_basedir'));
                return ['success' => false, 'error' => '无法创建HLS输出目录: ' . $fullOutputDir, 'debug_root' => $rootPath, 'debug_full_path' => $fullOutputDir];
            }
            @chmod($fullOutputDir, 0777);
        }

        $videoId = uniqid('hls_');
        $hlsDir = $fullOutputDir . DIRECTORY_SEPARATOR . $videoId . DIRECTORY_SEPARATOR;

        if (!is_dir($hlsDir)) {
            if (!mkdir($hlsDir, 0777, true)) {
                return ['success' => false, 'error' => '无法创建输出目录: ' . $hlsDir];
            }
            @chmod($hlsDir, 0777);
        }

        $m3u8Path = $hlsDir . 'index.m3u8';
        $hlsConfig = $this->config['hls'] ?? [];
        $segmentDuration = $hlsConfig['segment_duration'] ?? 6;
        $videoCodec = $hlsConfig['video_codec'] ?? 'libx264';
        $audioCodec = $hlsConfig['audio_codec'] ?? 'aac';
        $videoBitrate = $hlsConfig['video_bitrate'] ?? '4000k';
        $audioBitrate = $hlsConfig['audio_bitrate'] ?? '128k';
        $preset = $hlsConfig['preset'] ?? 'medium';
        $crf = $hlsConfig['crf'] ?? 23;

        $segmentTemplate = $hlsDir . 'segment_%03d.ts';
        $segmentTemplateEsc = DIRECTORY_SEPARATOR === '\\'
            ? '"' . str_replace('"', '""', $segmentTemplate) . '"'
            : escapeshellarg($segmentTemplate);

        $cmd = escapeshellarg($this->ffmpegPath)
             . ' -y'
             . ' -i ' . escapeshellarg($inputPath);

        if ($needScale) {
            $cmd .= ' -vf ' . escapeshellarg(
                'scale=' . $maxWidth . ':' . $maxHeight . ':force_original_aspect_ratio=decrease,pad=ceil(iw/2)*2:ceil(ih/2)*2'
            );
        } else {
            $cmd .= ' -vf ' . escapeshellarg('pad=ceil(iw/2)*2:ceil(ih/2)*2');
        }

        $bufsize = $this->parseBitrate($videoBitrate) * 2;
        $gopSize = (int)$segmentDuration * 30;

        $cmd .= ' -c:v ' . $videoCodec
             . ' -preset ' . $preset
             . ' -crf ' . (int)$crf
             . ' -g ' . $gopSize
             . ' -keyint_min ' . $gopSize
             . ' -sc_threshold 0'
             . ' -maxrate ' . $videoBitrate
             . ' -bufsize ' . $bufsize . 'k'
             . ' -pix_fmt yuv420p'
             . ' -c:a ' . $audioCodec
             . ' -b:a ' . $audioBitrate
             . ' -f hls'
             . ' -hls_time ' . (int)$segmentDuration
             . ' -hls_list_size 0'
             . ' -hls_segment_filename ' . $segmentTemplateEsc
             . ' ' . escapeshellarg($m3u8Path)
             . ' 2>&1';

        $this->log('开始转换: ' . $inputPath . ' (' . $resolution['width'] . 'x' . $resolution['height'] . ', 缩放: ' . ($needScale ? '是' : '否') . ')');
        $this->log('执行命令: ' . $cmd);

        if (!function_exists('exec')) {
            $this->log('exec 函数被禁用，无法执行 ffmpeg');
            return ['success' => false, 'error' => 'exec 函数被服务器禁用，无法执行视频转码。请在 php.ini 中启用 exec 函数。'];
        }

        $output = [];
        $returnCode = -1;
        @exec($cmd, $output, $returnCode);

        $this->log('转换完成: 返回码=' . $returnCode . ', 输出行数=' . count($output));
        if (!empty($output)) {
            $this->log('ffmpeg输出(最后20行): ' . implode("\n", array_slice($output, -20)));
        }

        $m3u8FileExists = file_exists($m3u8Path);
        $this->log('m3u8文件检查: file_exists=' . ($m3u8FileExists ? 'true' : 'false') . ', path=' . $m3u8Path);

        if (!$m3u8FileExists && function_exists('exec')) {
            $checkOutput = [];
            @exec('ls -la ' . escapeshellarg($hlsDir) . ' 2>&1', $checkOutput);
            $this->log('目录内容: ' . implode("\n", $checkOutput));

            @exec('test -f ' . escapeshellarg($m3u8Path) . ' && echo EXISTS || echo NOT_EXISTS', $testOutput);
            $this->log('exec文件检查: ' . implode("\n", $testOutput ?? []));
        }

        $execFileExists = false;
        if (function_exists('exec')) {
            $testOut = [];
            @exec('test -f ' . escapeshellarg($m3u8Path) . ' && echo YES || echo NO', $testOut);
            $execFileExists = (isset($testOut[0]) && trim($testOut[0]) === 'YES');
        }

        if ($returnCode !== 0 || (!$m3u8FileExists && !$execFileExists)) {
            $errorDetail = implode("\n", array_slice($output, -15));
            $this->log('转换失败 (返回码=' . $returnCode . '): ' . $errorDetail);
            $this->cleanupDir($hlsDir);
            return ['success' => false, 'error' => '视频转换失败', 'detail' => $errorDetail, 'debug_cmd' => $cmd, 'debug_return_code' => $returnCode, 'debug_m3u8_exists' => $m3u8FileExists];
        }

        $m3u8Url = '/' . trim($outputDir, '/') . '/' . $videoId . '/index.m3u8';

        $result = [
            'success' => true,
            'm3u8_url' => $m3u8Url,
            'original_resolution' => $resolution,
            'was_scaled' => $needScale,
        ];

        if ($needScale) {
            $result['output_resolution'] = ['width' => $maxWidth, 'height' => $maxHeight];
        }

        if ($this->config['delete_original'] ?? false) {
            @unlink($inputPath);
        }

        $this->log('转换成功: ' . $m3u8Url);

        return $result;
    }

    private function parseBitrate($bitrate)
    {
        return (int)str_ireplace('k', '', $bitrate);
    }

    private function cleanupDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        @rmdir($dir);
    }

    private function log($message)
    {
        if (!($this->config['log_enabled'] ?? true)) {
            return;
        }

        $logDir = __DIR__ . '/../admin/logs/';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . 'video_processing_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
