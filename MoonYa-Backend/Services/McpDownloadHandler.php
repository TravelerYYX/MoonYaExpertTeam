<?php
/**
 * McpDownloadHandler - MCP（Model Context Protocol）下载协议处理器
 * 实现 MCP tools/list 和 tools/call 协议，供外部AI平台通过标准化协议调用下载功能。
 */

require_once __DIR__ . '/DownloadService.php';

class McpDownloadHandler {
    private $service;
    private $config;

    public function __construct($config) {
        $this->config = $config;
        $this->service = new DownloadService($config);
    }

    /**
     * Handle MCP request and return response
     */
    public function handle($method, $body) {
        $request = json_decode($body, true);
        if (!$request) {
            return $this->errorResponse(null, -32600, 'Invalid Request', 'JSON parse error');
        }

        $requestId = $request['id'] ?? null;
        $rpcMethod = $request['method'] ?? '';

        switch ($rpcMethod) {
            case 'tools/list':
                return $this->handleToolsList($requestId);
            case 'tools/call':
                return $this->handleToolsCall($requestId, $request['params'] ?? []);
            case 'initialize':
                return $this->handleInitialize($requestId);
            default:
                return $this->errorResponse($requestId, -32601, 'Method not found', "Unknown method: {$rpcMethod}");
        }
    }

    /**
     * Handle MCP initialize request
     */
    private function handleInitialize($id) {
        return $this->jsonResponse($id, [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => [
                    'listChanged' => false
                ]
            ],
            'serverInfo' => [
                'name' => 'MoonYa Download Server',
                'version' => '1.0.0'
            ]
        ]);
    }

    /**
     * Handle MCP tools/list
     * Returns list of available tools with JSON Schema parameter definitions
     */
    private function handleToolsList($id) {
        $tools = [
            [
                'name' => 'download_file',
                'description' => '从指定URL下载文件到本地。支持HTTP/HTTPS协议，自动检测文件类型并验证安全性。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => '要下载的文件的URL地址（http/https）'
                        ],
                        'filename' => [
                            'type' => 'string',
                            'description' => '保存到本地的文件名（可选，不指定则从URL自动提取）'
                        ],
                        'method' => [
                            'type' => 'string',
                            'enum' => ['direct', 'sandbox', 'mcp'],
                            'description' => '下载方式：direct=直接平台SDK，sandbox=沙箱环境，mcp=通过MCP协议'
                        ],
                        'user_role' => [
                            'type' => 'string',
                            'enum' => ['admin', 'user', 'guest'],
                            'description' => '用户角色，影响下载权限（默认guest）'
                        ]
                    ],
                    'required' => ['url']
                ]
            ],
            [
                'name' => 'download_status',
                'description' => '查询下载功能的运行状态和统计信息',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new stdClass()
                ]
            ],
            [
                'name' => 'cleanup_downloads',
                'description' => '清理过期的临时下载文件',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'max_age_hours' => [
                            'type' => 'integer',
                            'description' => '文件最大保留时间（小时），默认1小时'
                        ]
                    ]
                ]
            ]
        ];

        return $this->jsonResponse($id, ['tools' => $tools]);
    }

    /**
     * Handle MCP tools/call
     * Execute download operation and return result
     */
    private function handleToolsCall($id, $params) {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        $startTime = microtime(true);

        try {
            switch ($toolName) {
                case 'download_file':
                    $url = $arguments['url'] ?? '';
                    $filename = $arguments['filename'] ?? '';
                    $userRole = $arguments['user_role'] ?? 'guest';

                    if (empty($url)) {
                        return $this->toolErrorResponse($id, '缺少必要参数：url 为必填项');
                    }

                    // Extract and validate filename
                    $filename = $this->service->extractFilename($url, $filename);
                    $safeName = $this->service->normalizePath($filename);

                    // Check extension whitelist
                    if (!$this->service->isExtensionAllowed($safeName)) {
                        return $this->toolErrorResponse($id, '文件类型不允许下载：扩展名被限制');
                    }

                    // Check permission
                    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
                    if (!$this->service->checkPermission($ext, $userRole)) {
                        return $this->toolErrorResponse($id, "权限不足：用户角色 {$userRole} 不允许下载此类文件");
                    }

                    // Download file
                    $result = $this->service->downloadToLocal($url, $safeName);

                    if (!$result['success']) {
                        return $this->toolErrorResponse($id, $result['error'] ?? '下载失败');
                    }

                    $fileInfo = $result['file'];
                    $durationMs = round((microtime(true) - $startTime) * 1000);

                    // Build success response with file meta
                    $content = [
                        [
                            'type' => 'text',
                            'text' => json_encode([
                                'success' => true,
                                'code' => 200,
                                'message' => '下载成功',
                                'file' => [
                                    'name' => $fileInfo['name'],
                                    'size' => $fileInfo['size'],
                                    'type' => $fileInfo['type'],
                                    'path' => $fileInfo['path'],
                                    'modified_at' => $fileInfo['modified_at']
                                ],
                                'duration_ms' => $durationMs
                            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                        ]
                    ];

                    return $this->jsonResponse($id, [
                        'content' => $content,
                        'isError' => false
                    ]);

                case 'download_status':
                    // Try to load monitor
                    $stats = ['service' => 'MoonYa Download Service', 'status' => 'running'];
                    
                    if (file_exists(__DIR__ . '/../admin/DownloadMonitor.php')) {
                        require_once __DIR__ . '/../admin/DownloadMonitor.php';
                        $monitor = new DownloadMonitor($this->config);
                        $stats = array_merge($stats, $monitor->getSummary());
                    }

                    return $this->jsonResponse($id, [
                        'content' => [[
                            'type' => 'text',
                            'text' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                        ]],
                        'isError' => false
                    ]);

                case 'cleanup_downloads':
                    $this->service->cleanupTempFiles();
                    
                    return $this->jsonResponse($id, [
                        'content' => [[
                            'type' => 'text',
                            'text' => json_encode([
                                'success' => true,
                                'message' => '临时文件清理完成'
                            ], JSON_UNESCAPED_UNICODE)
                        ]],
                        'isError' => false
                    ]);

                default:
                    return $this->toolErrorResponse($id, "未知工具: {$toolName}");
            }
        } catch (Exception $e) {
            return $this->toolErrorResponse($id, '执行失败: ' . $e->getMessage());
        }
    }

    /**
     * Build JSON-RPC success response
     */
    private function jsonResponse($id, $result) {
        $response = [
            'jsonrpc' => '2.0',
            'id' => $id
        ];
        // MCP sends result directly (not in 'result' wrapper)
        return json_encode(array_merge($response, $result), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Build JSON-RPC error response
     */
    private function errorResponse($id, $code, $message, $data = null) {
        $error = [
            'code' => $code,
            'message' => $message
        ];
        if ($data) $error['data'] = $data;
        
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Build tool call error response
     */
    private function toolErrorResponse($id, $message) {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'content' => [[
                'type' => 'text',
                'text' => $message
            ]],
            'isError' => true
        ], JSON_UNESCAPED_UNICODE);
    }
}
