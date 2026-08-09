<?php

declare(strict_types=1);

/**
 * Single backend transport contract for every Browser Agent entry point.
 * Deployment addresses are deliberately absent: the desktop bridge resolves
 * these relative routes from its runtime service manifest.
 */
final class BrowserAutomationGateway
{
    public const EXECUTE_ROUTE = '/browser/execute';
    public const AUTHORIZE_ROUTE = '/browser/authorize';
    /**
     * The desktop relay may disappear when MoonYa is restarted. Browser calls
     * must therefore always have a finite server-side deadline so a detached
     * worker cannot hold browser:default forever.
     */
    public const DEFAULT_RELAY_TIMEOUT_SECONDS = 90;
    public const RESOURCE_LOCK_TIMEOUT_SECONDS = 30;

    public const ACTIONS = [
        'start', 'status', 'stop',
        'navigate', 'back', 'forward', 'reload',
        'inspect', 'screenshot',
        'click', 'fill', 'hover', 'press', 'select', 'check', 'uncheck',
        'scroll', 'wait',
        'new_tab', 'list_tabs', 'switch_tab', 'close_tab',
        'list_downloads',
    ];

    public static function browserResultSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'error_code' => ['type' => ['string', 'null']],
                'error' => ['type' => ['string', 'null']],
                'action' => ['type' => 'string'],
                'browser' => ['type' => 'object'],
                'page_url' => ['type' => 'string'],
                'page_title' => ['type' => 'string'],
                'page_version' => ['type' => 'integer'],
                'page_text' => ['type' => 'string'],
                'dom_elements' => ['type' => 'array'],
                'focused_element' => ['type' => ['object', 'null']],
                'page_changed' => ['type' => 'boolean'],
                'change_hint' => ['type' => 'string'],
                'change_evidence' => ['type' => 'object'],
                'screenshot' => ['type' => ['string', 'null'], 'contentEncoding' => 'base64'],
                'data' => ['type' => ['object', 'array', 'null']],
            ],
            'required' => ['success'],
        ];
    }

    public static function vlsResultSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ok' => ['type' => 'boolean'],
                'content' => ['type' => 'string'],
                'structured_content' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'page_version' => ['type' => 'integer'],
                        'summary' => ['type' => 'string'],
                        'visible_regions' => ['type' => 'array'],
                        'visible_elements' => ['type' => 'array'],
                        'visible_text' => ['type' => 'array'],
                        'uncertainties' => ['type' => 'array'],
                    ],
                ],
                'artifacts' => ['type' => 'array'],
            ],
        ];
    }

    /** @var callable(string,string,int):string */
    private $relay;
    private int $timeoutSeconds;
    private string $userContext;

    /**
     * @param callable(string,string,int):string $relay
     */
    public function __construct(
        callable $relay,
        int $timeoutSeconds = self::DEFAULT_RELAY_TIMEOUT_SECONDS,
        string $userContext = ''
    )
    {
        if ($timeoutSeconds < 0) {
            throw new InvalidArgumentException('browser relay timeout cannot be negative');
        }
        $this->relay = $relay;
        // Zero means "no deadline" in the shared launcher relay. That is not
        // safe for browser calls because their worker owns an exclusive session
        // lock while waiting for the desktop callback.
        $this->timeoutSeconds = $timeoutSeconds > 0
            ? $timeoutSeconds
            : self::DEFAULT_RELAY_TIMEOUT_SECONDS;
        $this->userContext = $userContext;
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments): array
    {
        $action = trim((string)($arguments['action'] ?? ''));
        if (!in_array($action, self::ACTIONS, true)) {
            return [
                'success' => false,
                'error_code' => $action === '' ? 'invalid_request' : 'unknown_action',
                'error' => $action === '' ? '缺少浏览器 action。' : '未知浏览器 action。',
            ];
        }

        $payload = $arguments;
        $payload['action'] = $action;
        $payload['user_context'] = $this->userContext;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return ['success' => false, 'error_code' => 'invalid_request', 'error' => '浏览器参数无法序列化。'];
        }

        $raw = ($this->relay)(self::EXECUTE_ROUTE, $json, $this->timeoutSeconds);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'error_code' => 'invalid_response',
                'error' => '本机浏览器服务返回了无效响应。',
            ];
        }
        return $decoded;
    }

    /** @param array<string,mixed> $arguments */
    public function executeJson(array $arguments): string
    {
        return (string)json_encode(
            $this->execute($arguments),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
