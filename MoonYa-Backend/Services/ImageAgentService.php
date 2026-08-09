<?php
declare(strict_types=1);

/**
 * Runs the database-configured Image Agent on Web image/video attachments.
 * The result is evidence for MoonYa's root turn; it is never streamed as a
 * user-facing assistant answer.
 */
final class ImageAgentService
{
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    public function analyze(array $attachments, string $instruction): array
    {
        $visuals = array_values(array_filter(
            $attachments,
            static fn(array $item): bool => in_array((string)($item['category'] ?? ''), ['image', 'video'], true)
        ));
        if ($visuals === []) {
            throw new InvalidArgumentException('Image Agent 没有收到图片或视频附件');
        }

        $runtime = $this->loadRuntime();
        $batchSize = (int)($this->config['web_attachments']['image_agent_batch_size'] ?? 0);
        if ($batchSize <= 0) {
            throw new RuntimeException('Image Agent 分批配置无效');
        }
        $batches = array_chunk($visuals, $batchSize);
        $aggregate = [
            'summary' => [],
            'observations' => [],
            'visible_text' => [],
            'timeline' => [],
            'inferences' => [],
            'uncertainties' => [],
            'attachment_ids' => [],
            'failed_attachment_ids' => [],
        ];
        $successfulBatches = 0;
        $firstError = null;

        foreach ($batches as $batchIndex => $batch) {
            try {
                $batchResult = $this->analyzeBatch($batch, $instruction, $runtime);
                $successfulBatches++;
                $summary = trim((string)($batchResult['summary'] ?? ''));
                if ($summary !== '') {
                    $aggregate['summary'][] = count($batches) > 1
                        ? sprintf('批次 %d：%s', $batchIndex + 1, $summary)
                        : $summary;
                }
                foreach (['observations', 'visible_text', 'timeline', 'inferences', 'uncertainties'] as $field) {
                    $values = is_array($batchResult[$field] ?? null) ? $batchResult[$field] : [];
                    $aggregate[$field] = array_merge($aggregate[$field], array_values($values));
                }
                $aggregate['attachment_ids'] = array_merge(
                    $aggregate['attachment_ids'],
                    array_map(static fn(array $item): string => (string)$item['id'], $batch)
                );
            } catch (Throwable $error) {
                $firstError ??= $error;
                $aggregate['failed_attachment_ids'] = array_merge(
                    $aggregate['failed_attachment_ids'],
                    array_map(static fn(array $item): string => (string)$item['id'], $batch)
                );
            }
        }

        if ($successfulBatches === 0) {
            throw new RuntimeException(
                $firstError instanceof Throwable ? $firstError->getMessage() : 'Image Agent 分析失败'
            );
        }

        if ($aggregate['failed_attachment_ids'] !== []) {
            $aggregate['uncertainties'][] = '部分图片或视频未能完成视觉理解；MoonYa 不得猜测这些附件的内容。';
        }
        $aggregate['summary'] = implode("\n\n", $aggregate['summary']);

        return [
            'agent' => 'Image Agent',
            'model' => $runtime['model'],
            'batch_count' => count($batches),
            'successful_batch_count' => $successfulBatches,
            'result' => $aggregate,
        ];
    }

    private function analyzeBatch(array $visuals, string $instruction, array $runtime): array
    {
        $content = [];
        $attachmentManifest = [];

        foreach ($visuals as $item) {
            $providerFileId = trim((string)($item['provider_file_id'] ?? ''));
            $category = (string)($item['category'] ?? '');
            if (($item['provider'] ?? '') !== 'moonshot' || $providerFileId === '') {
                throw new RuntimeException('视觉附件尚未准备好，无法交给 Image Agent');
            }

            if ($category === 'video') {
                $content[] = [
                    'type' => 'video_url',
                    'video_url' => ['url' => 'ms://' . $providerFileId],
                ];
            } else {
                $content[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'ms://' . $providerFileId],
                ];
            }

            $attachmentManifest[] = [
                'attachment_id' => (string)$item['id'],
                'filename' => (string)$item['original_name'],
                'relative_path' => (string)$item['relative_path'],
                'category' => $category,
            ];
        }

        $content[] = [
            'type' => 'text',
            'text' => "MoonYa 委派指令：\n" . trim($instruction)
                . "\n\n本次允许分析的附件清单：\n"
                . json_encode($attachmentManifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $payload = [
            'model' => $runtime['model'],
            'messages' => [
                ['role' => 'system', 'content' => $runtime['prompt']],
                ['role' => 'user', 'content' => $content],
            ],
            'stream' => false,
            'temperature' => (float)($this->config['web_attachments']['image_agent_temperature'] ?? 1.0),
            'max_tokens' => (int)($this->config['web_attachments']['image_agent_max_tokens'] ?? 4096),
            'thinking' => ['type' => 'disabled'],
            'response_format' => ['type' => 'json_object'],
        ];

        $apiUrl = (string)($this->config['api_url'] ?? '');
        $apiKey = (string)($this->config['api_key'] ?? '');
        try {
            $response = $this->postJson($apiUrl, $apiKey, $payload);
        } catch (RuntimeException $error) {
            $message = strtolower($error->getMessage());

            // 某些 OpenAI 兼容网关不支持 response_format，移除后重试
            if (str_contains($message, 'response_format')) {
                unset($payload['response_format']);
            } elseif (str_contains($message, 'temperature')
                && preg_match('/only\s+([\d.]+)\s+is\s+allowed/i', $error->getMessage(), $tempMatch)
            ) {
                // 部分视觉模型（如 Moonshot vision）只允许固定 temperature（供应商约束），
                // 从错误信息动态解析允许值并重试，避免硬编码。
                $payload['temperature'] = (float)$tempMatch[1];
            } else {
                throw $error;
            }
            $response = $this->postJson($apiUrl, $apiKey, $payload);
        }
        $rawContent = $response['choices'][0]['message']['content'] ?? '';
        if (is_array($rawContent)) {
            $rawContent = implode('', array_map(
                static fn($part): string => is_array($part) ? (string)($part['text'] ?? '') : (string)$part,
                $rawContent
            ));
        }

        return $this->decodeResult((string)$rawContent);
    }

    private function loadRuntime(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT child.model_override AS model, sp.prompt
             FROM agent_delegations d
             JOIN agents parent ON parent.id=d.parent_agent_id
             JOIN agents child ON child.id=d.child_agent_id
             JOIN system_prompts sp ON sp.name=child.prompt_name AND sp.enabled=1
             WHERE parent.agent_key='moonya' AND parent.enabled=1
               AND child.agent_key='image_agent' AND child.enabled=1 AND d.enabled=1
             LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $model = trim((string)($row['model'] ?? ''));
        $prompt = trim((string)($row['prompt'] ?? ''));
        if ($model === '' || $prompt === '') {
            throw new RuntimeException('Image Agent 尚未完成数据库迁移或未启用');
        }
        return ['model' => $model, 'prompt' => $prompt];
    }

    private function postJson(string $url, string $apiKey, array $payload): array
    {
        if ($url === '' || $apiKey === '') {
            throw new RuntimeException('Image Agent 的 Kimi 服务未配置');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => (int)($this->config['web_attachments']['image_agent_timeout_seconds'] ?? 180),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = is_string($raw) ? json_decode($raw, true) : null;
        if ($curlError !== '' || $status < 200 || $status >= 300 || !is_array($response)) {
            $providerError = is_array($response)
                ? (string)($response['error']['message'] ?? '')
                : '';
            throw new RuntimeException(
                'Image Agent 调用失败：' . ($providerError ?: ($curlError ?: 'HTTP ' . $status))
            );
        }
        return $response;
    }

    private function decodeResult(string $raw): array
    {
        $raw = trim($raw);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/u', $raw, $match)) {
            $decoded = json_decode($match[0], true);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Image Agent 未返回有效的结构化结果');
        }

        return [
            'summary' => (string)($decoded['summary'] ?? ''),
            'observations' => $this->stringList($decoded['observations'] ?? []),
            'visible_text' => $this->stringList($decoded['visible_text'] ?? []),
            'timeline' => is_array($decoded['timeline'] ?? null) ? array_values($decoded['timeline']) : [],
            'inferences' => $this->stringList($decoded['inferences'] ?? []),
            'uncertainties' => $this->stringList($decoded['uncertainties'] ?? []),
        ];
    }

    private function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn($item): string => trim((string)$item),
            $value
        ), static fn(string $item): bool => $item !== ''));
    }
}
