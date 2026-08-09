<?php

require_once __DIR__ . '/Services/BrowserAutomationGateway.php';

if (!function_exists('loadCuRuntimeConfig')) {
function loadCuRuntimeConfig(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    // env() 由 env_loader.php 提供；若本文件被独立 require（绕过 config.php），降级返回空数组
    if (!function_exists('env')) {
        $cache = [];
        return $cache;
    }
    try {
        $dsn = 'mysql:host=' . (env('DB_HOST') ?: 'localhost')
             . ';dbname=' . (env('DB_NAME') ?: 'ai_system')
             . ';charset=utf8mb4';
        $pdo = new PDO($dsn, env('DB_USER') ?: '', env('DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $stmt = $pdo->query("SELECT * FROM cu_runtime_config WHERE id = 1 LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cache = $row;
            return $cache;
        }
    } catch (\Throwable $e) {
        // 静默失败，记录到 error_log（DB 不可用时降级为硬编码默认值）
        error_log("loadCuRuntimeConfig failed: " . $e->getMessage());
    }
    $cache = [];
    return $cache;
}

/**
 * 从 cu_runtime_config.tool_descriptions JSON 加载工具描述并静态缓存。
 * 返回 [工具名 => 描述] 数组；DB 不可用时返回空数组（调用方按需用硬编码兜底）。
 */
}
if (!function_exists('loadToolDescriptions')) {
function loadToolDescriptions(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cfg = loadCuRuntimeConfig();
    if (!empty($cfg['tool_descriptions'])) {
        $decoded = json_decode($cfg['tool_descriptions'], true);
        if (is_array($decoded)) {
            $cache = $decoded;
            return $cache;
        }
    }
    $cache = [];
    return $cache;
}
}

if (!function_exists('filterAgentToolsByMode')) {
/**
 * 按当前会话模式过滤 agent_tools。
 *
 * 仅当工具声明的 mode_gate 数组与当前会话模式有交集时保留；
 * 未声明 mode_gate 的工具视为始终可用（向后兼容现有工具）。
 *
 * 用于在工具列表注入到 LLM 调用前剔除当前模式不可见的工具。
 * 例如 Browser Automation 工具集 mode_gate=>['agent','computer_user']：
 *   - agent 模式：保留
 *   - code_agent 模式：剔除
 *   - normal 模式：剔除（外层通常已不走工具注入分支）
 *
 * @param array  $tools            agent_tools 数组（每项含 type/function/可选 mode_gate）
 * @param string $agentMode        当前 agent_mode（normal/agent/code_agent）
 * @param bool   $computerUserMode 是否启用 Computer User 模式
 * @return array 过滤后的工具数组（已 array_values 重索引）
 */
function filterAgentToolsByMode(array $tools, string $agentMode, bool $computerUserMode): array {
    $currentModes = [];
    if ($agentMode === 'agent') $currentModes[] = 'agent';
    if ($computerUserMode) $currentModes[] = 'computer_user';

    // CU 专用工具名（桌面 UIA 操作 + 截屏 + 键鼠模拟）。
    // 这些工具仅在 computer_user 模式下可用，agent 模式下必须剔除，
    // 否则 AI 会在 BA（浏览器自动化）流程中误调用 get_ui_tree / click_element 等，
    // 触发"未知操作"错误并陷入截图循环。
    // 注意：Trae Work 工具集（edit_file/grep/glob/view_directory/todo_write/
    // get_diagnostics/find_references/goto_definition/get_command_status/stop_command）
    // 不进入此列表，使其在 agent 与 computer_user 模式下均可见（Task 10.1）。
    $cuOnlyTools = [
        'take_screenshot', 'get_cursor_pos', 'mouse_move', 'mouse_click',
        'mouse_drag', 'mouse_hold', 'keyboard_type', 'key_press',
        'task_complete', 'computer_observe', 'computer_interact', 'computer_complete',
        'find_element', 'get_ui_tree', 'click_element',
        'set_text', 'get_text', 'scroll_element', 'invoke_element',
        'get_window_list', 'focus_window', 'wait', 'scroll',
        // Task 12: CU 模式首选感知工具（一次调用同时获取 UIA 树 + 截图 + 焦点 + 窗口元信息）
        'capture_ui_snapshot',
    ];

    return array_values(array_filter($tools, function ($tool) use ($currentModes, $cuOnlyTools, $computerUserMode) {
        $toolName = $tool['function']['name'] ?? '';
        // CU 专用工具仅在 computer_user 模式下保留
        if (in_array($toolName, $cuOnlyTools, true)) {
            return $computerUserMode;
        }
        if (!isset($tool['mode_gate'])) return true;  // 未声明 mode_gate 视为始终可用
        return !empty(array_intersect($tool['mode_gate'], $currentModes));
    }));
}
}

$cuConfig = loadCuRuntimeConfig();
$toolDesc = loadToolDescriptions();

$agentTools = [
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'description' => $toolDesc['get_weather'] ?? '获取用户指定城市或当前所在地的实时天气信息。当用户询问天气相关问题时（如"今天天气怎么样"、"北京明天会下雨吗"、"现在外面多少度"等），调用此工具获取天气数据。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => '用户原始天气查询消息，如"今天天气"、"北京天气"等'
                        ]
                    ]
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'search_music',
                'description' => $toolDesc['search_music'] ?? '搜索并播放音乐。当用户要求听音乐、推荐歌曲、搜索特定歌曲时调用此工具。重要：调用后系统会自动在前端聊天界面渲染音乐卡片（含封面、歌名、歌手和播放按钮），你不需要在回复中输出任何音乐相关的文字描述、歌曲列表或HTML卡片。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => '音乐搜索关键词或用户原始消息，如"来点音乐"、"推荐几首周杰伦的歌"等'
                        ]
                    ]
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_horoscope',
                'description' => $toolDesc['get_horoscope'] ?? '查询星座运势。当用户询问星座相关问题时（如"今天双子座运势怎么样"、"查看星座运程"、"本周运势"等），调用此工具获取星座运势数据。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => '用户原始星座查询消息'
                        ]
                    ]
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'generate_video',
                'description' => $toolDesc['generate_video'] ?? '使用AI生成视频。当用户要求生成视频、制作视频、创作视频时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => [
                            'type' => 'string',
                            'description' => '用户想要生成的视频描述'
                        ]
                    ],
                    'required' => ['prompt']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'translate_classical',
                'description' => $toolDesc['translate_classical'] ?? '将用户输入的现代中文翻译为文言文（古文）。当用户要求文言文翻译、古文翻译、用文言文表达时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => [
                            'type' => 'string',
                            'description' => '需要翻译为文言文的原文内容'
                        ]
                    ],
                    'required' => ['text']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'open_video_site',
                'description' => $toolDesc['open_video_site'] ?? '打开影视视频网站供用户观看电影、电视剧、综艺等。当用户要求看电影、看视频、追剧、浏览影视内容时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new stdClass()
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'web_search',
                'description' => $toolDesc['web_search'] ?? '联网搜索互联网信息。用于查找最新新闻、事实或任何实时信息。当用户询问需要联网查询的问题时（如"今天发生了什么新闻"、"最新科技动态"等），调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => '搜索关键词'
                        ]
                    ],
                    'required' => ['query']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'web_fetch',
                'description' => $toolDesc['web_fetch'] ?? '抓取并阅读网页内容。当用户要求查看某个网页的具体内容、阅读文章、获取网页详情时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => '要抓取和阅读的网址'
                        ]
                    ],
                    'required' => ['url']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'download_file',
                'description' => $toolDesc['download_file'] ?? '从指定的URL下载文件到本地。当用户要求下载文件、保存文件、下载图片/视频/文档等资源时调用此工具。如果知道确切下载链接请填入url；如果不知道链接（如"下载腾讯视频安装包"），请将产品名+下载关键词填入search_query参数，系统会自动搜索下载链接。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => '要下载的文件的URL地址（可选，不提供则使用search_query自动搜索）'
                        ],
                        'search_query' => [
                            'type' => 'string',
                            'description' => '搜索关键词，当url为空时使用。如用户说"下载腾讯视频"，则填"腾讯视频 PC版 官方下载"'
                        ],
                        'filename' => [
                            'type' => 'string',
                            'description' => '保存到本地的文件名（可选，不指定则从URL自动提取）'
                        ],
                        'path' => [
                            'type' => 'string',
                            'description' => '保存路径（可选，默认保存到桌面）'
                        ],
                        'method' => [
                            'type' => 'string',
                            'enum' => ['direct', 'sandbox', 'mcp'],
                            'description' => '下载方式：direct=直接通过平台SDK下载，sandbox=沙箱环境下载，mcp=通过MCP协议下载'
                        ]
                    ],
                    'required' => []
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_file',
                'description' => $toolDesc['create_file'] ?? '在指定路径创建文件并写入内容。当用户要求新建文件、创建文本文件、写入内容到文件时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '文件创建路径，如"桌面/test.txt"或"D:/test.txt"'],
                        'content' => ['type' => 'string', 'description' => '要写入文件的内容']
                    ],
                    'required' => ['path', 'content']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_folder',
                'description' => $toolDesc['create_folder'] ?? '在指定路径创建文件夹。当用户要求新建文件夹、创建目录时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '文件夹创建路径，如"桌面/新文件夹"或"D:/新文件夹"']
                    ],
                    'required' => ['path']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'delete_file',
                'description' => $toolDesc['delete_file'] ?? '删除指定的文件或文件夹。当用户要求删除文件、删除文件夹时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '要删除的文件或文件夹路径']
                    ],
                    'required' => ['path']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'open_file',
                'description' => $toolDesc['open_file'] ?? '使用系统默认程序打开指定路径的文件（AI 无法看到文件内容，仅触发外部程序打开）。当用户明确要求"打开文件"、"用某某程序打开"时调用此工具。注意：若你需要获取文件内容用于后续操作（如执行、按文档实施、分析代码），请改用 read_file 工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '要打开的文件路径']
                    ],
                    'required' => ['path']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'read_file',
                'description' => $toolDesc['read_file'] ?? '读取指定文件的内容并返回给 AI（文本内容直接回填，便于后续按内容执行）。当用户要求"执行 xxx.md/xxx.txt"、"按照 xxx 文件实施"、"读取 xxx 文件内容"、"查看 xxx 文件的具体内容"、"根据 xxx 文档操作"时调用此工具。与 open_file 的区别：open_file 仅用系统默认程序打开文件（AI 看不到内容），read_file 会将文本内容返回给 AI，便于 AI 理解后按文档内容逐步实施。文件大小受系统配置限制（默认 10MB）。返回内容带行号（cat -n 格式：行号右对齐 6 位 + 制表符 + 内容），便于后续 edit_file 精确引用行号。大文件可用 offset 和 limit 参数分段读取。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '要读取内容的文件路径，如"D:/Project/xxx/方案.md"或"桌面/说明.txt"'],
                        'offset' => ['type' => 'integer', 'description' => '起始行号（默认 1）'],
                        'limit' => ['type' => 'integer', 'description' => '读取行数（默认全部）']
                    ],
                    'required' => ['path']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'open_app',
                'description' => $toolDesc['open_app'] ?? '打开指定的应用程序。当用户要求打开软件、启动应用时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '应用名称（如"微信"、"记事本"）或可执行文件路径']
                    ],
                    'required' => ['path']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'close_app',
                'description' => $toolDesc['close_app'] ?? '关闭指定的正在运行的应用程序。当用户要求关闭软件、退出应用时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '要关闭的应用名称（如"微信"、"记事本"）']
                    ],
                    'required' => ['path']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'uninstall_app',
                'description' => $toolDesc['uninstall_app'] ?? '卸载指定的应用程序。当用户要求卸载软件、删除应用时调用此工具。通过Windows注册表查找软件的卸载程序并执行。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '要卸载的应用名称（如"微信"、"记事本"）']
                    ],
                    'required' => ['path']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_files',
                'description' => $toolDesc['list_files'] ?? '列出指定目录下的文件和文件夹。当用户要求浏览文件、查看目录内容时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '要浏览的目录路径，如"桌面"或"D:/"']
                    ],
                    'required' => ['path']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'copy_file',
                'description' => $toolDesc['copy_file'] ?? '复制文件或文件夹到指定位置。当用户要求复制文件、拷贝文件、把文件复制到某处时调用此工具。源路径为文件夹时复制其内容到目标位置。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'source' => [
                            'type' => 'string',
                            'description' => '源文件或文件夹路径，可使用绝对路径或用户目录快捷路径'
                        ],
                        'destination' => [
                            'type' => 'string',
                            'description' => '目标路径，可使用绝对路径或用户目录快捷路径'
                        ]
                    ],
                    'required' => ['source', 'destination']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'move_file',
                'description' => $toolDesc['move_file'] ?? '移动文件或文件夹到指定位置。当用户要求移动文件、剪切文件、把文件移到某处时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'source' => [
                            'type' => 'string',
                            'description' => '源文件或文件夹路径，可使用绝对路径或用户目录快捷路径'
                        ],
                        'destination' => [
                            'type' => 'string',
                            'description' => '目标路径，可使用绝对路径或用户目录快捷路径'
                        ]
                    ],
                    'required' => ['source', 'destination']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'web_crawler',
                'description' => $toolDesc['web_crawler'] ?? '爬取指定网页，将页面及所有资源（CSS/JS/图片/字体等）本地化保存到本地文件夹。当用户要求爬取网页、抓取网站、保存网页、下载网站内容时调用此工具。完成后可打开本地产物文件夹查看。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => '要爬取的目标网页URL地址，如"https://example.com"'
                        ],
                        'folder' => [
                            'type' => 'string',
                            'description' => '用户指定的本地保存文件夹路径（可选）；不指定时使用组件配置的默认位置。'
                        ]
                    ],
                    'required' => ['url']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'shell_executor',
                'description' => $toolDesc['execute_command'] ?? '在安全沙箱中执行 Windows 命令。未知时长或长运行命令必须使用 blocking=false 并显式选择 completion_mode。finite 作业由协调器自动等待最终结果，禁止人工反复查询；persistent 仅用于 act，启动后必须用独立 verify 验证 readiness。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'command' => [
                            'type' => 'string',
                            'description' => '要执行的命令行指令。可以是cmd命令（如dir、echo、type）或PowerShell命令（如Get-Process、Write-Output）。系统会自动检测命令类型并选择合适的shell执行。'
                        ],
                        'shell' => [
                            'type' => 'string',
                            'enum' => ['powershell', 'cmd', 'auto'],
                            'description' => '明确选择 Shell；auto 仅做无歧义自动识别。'
                        ],
                        'phase' => [
                            'type' => 'string',
                            'enum' => ['inspect', 'act', 'verify'],
                            'description' => 'inspect=只读检查，act=执行变更，verify=独立只读验证。'
                        ],
                        'operation_id' => [
                            'type' => 'string',
                            'description' => '当前子任务内稳定的操作标识；act 与其 verify 必须相同。'
                        ],
                        'intent' => [
                            'type' => 'string',
                            'description' => '面向用户说明本次检查、变更或验证的目标。'
                        ],
                        'success_criteria' => [
                            'type' => 'object',
                            'description' => '退出码和输出的确定性断言；verify 至少提供一个输出断言。',
                            'properties' => [
                                'expected_exit_code' => ['type' => 'integer'],
                                'stderr_empty' => ['type' => 'boolean'],
                                'stdout_contains' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'stdout_regex' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'stderr_contains' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'stderr_not_contains' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['expected_exit_code']
                        ],
                        'blocking' => [
                            'type' => 'boolean',
                            'description' => 'true=同步等待结果（默认），false=受管后台运行；后台必须提供 completion_mode。'
                        ],
                        'completion_mode' => [
                            'type' => 'string',
                            'enum' => ['finite', 'persistent'],
                            'description' => 'blocking=false 时必填。finite=最终会退出，由协调器内部等待；persistent=持续服务，仅允许 act，需独立 readiness verify。'
                        ],
                        'cwd' => [
                            'type' => 'string',
                            'description' => '工作目录'
                        ],
                        'affected_paths' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => '代码项目组中的 act 阶段必填：本次命令可能写入的全部文件或目录。服务端会按项目成员文件所有权校验。'
                        ],
                        'timeout' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'description' => '仅当用户明确要求截止时间时填写正数；Work 模式默认不得设置。'
                        ]
                    ],
                    'required' => ['command']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'python_executor',
                'description' => $toolDesc['execute_python'] ?? '在 MoonYa 管理的 Python 3.11 环境中执行脚本。Work 模式不设置隐式总时限；长时间持续运行的进程应改用 shell_executor 受管后台协议。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => [
                            'type' => 'string',
                            'description' => '要执行的Python脚本代码，如"print(\'Hello World\')"、"import os; print(os.getcwd())"等'
                        ],
                        'args' => [
                            'type' => 'string',
                            'description' => '传递给Python脚本的命令行参数（可选）'
                        ]
                    ],
                    'required' => ['code']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_system_status',
                'description' => $toolDesc['get_system_status'] ?? '获取当前系统状态信息，包括 CPU 使用率、内存使用率、磁盘可用空间、网络连接状态。当需要检查系统资源是否充足、诊断性能问题、或执行任务前评估环境时调用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new stdClass()
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'recycle_bin_status',
                'description' => $toolDesc['recycle_bin_status'] ?? '通过 Windows Shell 官方 API 查询回收站中的逻辑已删除项目数和占用空间。它不会把回收站实现目录中的账户目录或系统元数据误判成用户项目。检查回收站状态以及清空后的独立验证必须优先使用此工具，不得通过遍历 $Recycle.Bin 物理目录推断逻辑状态。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'phase' => [
                            'type' => 'string',
                            'enum' => ['inspect', 'verify'],
                            'description' => 'inspect=只读检查；verify=对同一 operation_id 的成功变更做确定性验证。'
                        ],
                        'operation_id' => [
                            'type' => 'string',
                            'description' => '当前任务内稳定的操作标识；verify 必须与对应 act 相同。'
                        ],
                        'intent' => [
                            'type' => 'string',
                            'description' => '面向用户的检查或验证意图。'
                        ],
                        'expected_empty' => [
                            'type' => 'boolean',
                            'description' => 'verify 阶段的机器可判定断言；期望回收站逻辑状态是否为空。'
                        ],
                        'root_path' => [
                            'type' => 'string',
                            'description' => '可选的卷根路径；省略时由 Windows 汇总所有回收站。'
                        ]
                    ],
                    'required' => ['phase', 'operation_id', 'intent']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'check_app_installed',
                'description' => $toolDesc['check_app_installed'] ?? '检测指定的应用程序是否已安装在系统中。当需要确认某款软件是否可用、执行任务前检查依赖、或决定是否需要安装时调用此工具。返回安装状态、可执行文件路径和版本信息（如可获取）。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'app_name' => [
                            'type' => 'string',
                            'description' => '要检测的应用程序名称，如"Notepad++"、"VSCode"、"7-Zip"、"Python"等'
                        ]
                    ],
                    'required' => ['app_name']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'install_app',
                'description' => $toolDesc['install_app'] ?? '自动下载并安装指定的应用程序。当检测到缺失某款软件且任务需要该软件时调用此工具。系统会从配置的安装源下载安装包并执行静默安装，安装过程会推送实时进度。仅支持配置中预定义的软件列表。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'app_name' => [
                            'type' => 'string',
                            'description' => '要安装的应用程序名称，如"Notepad++"、"VSCode"、"7-Zip"、"Python"、"Git"等'
                        ]
                    ],
                    'required' => ['app_name']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'ZTimage-Agent',
                'description' => $toolDesc['ZTimage-Agent'] ?? '委派图片生成任务给 ZTimage-Agent（专用图片生成代理，调用 MiniMax image-01 / image-01-live 模型）。当需要生成图片、为 PPT/文档配图、创作插图时调用此工具。传入 images 数组，每项需指定 prompt（图片描述）、model（image-01 通用文生图；image-01-live 需要画风时用）、aspect_ratio（根据内容选：PPT配图用16:9、人物用3:4或1:1、风景用16:9、方形用1:1）、可选 style_type（仅 image-01-live：漫画/元气/中世纪/水彩）、可选 n（1-9张）。系统按张回报创作进度并返回图片 URL。★返回的 URL 可能过期失效，你必须在收到 URL 后调用 download_file 工具下载到本地，再把本地路径插入到最终的 PPT/文档文件中。严禁生成图片后不插入到最终文件。若返回结果中已含 local_paths 字段，可直接使用该本地路径。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'images' => [
                            'type' => 'array',
                            'description' => '要生成的图片清单。每项含 prompt/model/aspect_ratio，可选 style_type/n。',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'prompt' => [
                                        'type' => 'string',
                                        'description' => '图片的文本描述，最长 1500 字符'
                                    ],
                                    'model' => [
                                        'type' => 'string',
                                        'enum' => ['image-01', 'image-01-live'],
                                        'description' => 'MiniMax 文生图模型。image-01 通用文生图（画面细腻，支持21:9）；image-01-live 需要特定画风时使用（配合 style_type）'
                                    ],
                                    'aspect_ratio' => [
                                        'type' => 'string',
                                        'enum' => ['1:1', '16:9', '4:3', '3:2', '2:3', '3:4', '9:16', '21:9'],
                                        'description' => '图片宽高比。PPT配图用16:9，人物肖像用3:4或1:1，风景用16:9，方形用1:1。21:9仅image-01支持'
                                    ],
                                    'style_type' => [
                                        'type' => 'string',
                                        'enum' => ['漫画', '元气', '中世纪', '水彩'],
                                        'description' => '画风类型，仅当 model=image-01-live 时生效'
                                    ],
                                    'n' => [
                                        'type' => 'integer',
                                        'description' => '单次生成图片数量，1-9，默认1',
                                        'minimum' => 1,
                                        'maximum' => 9
                                    ]
                                ],
                                'required' => ['prompt', 'model', 'aspect_ratio']
                            ]
                        ]
                    ],
                    'required' => ['images']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'take_screenshot',
                'description' => $toolDesc['take_screenshot'] ?? '截取屏幕或前景窗口并返回图像与精确坐标元数据。视觉模型接管后必须传 target="screen"；所有坐标操作只能依据这一次截图返回的坐标系、origin 和 scale。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'target' => [
                            'type' => 'string',
                            'enum' => ['screen', 'window'],
                            'description' => '截图范围。视觉模型层必须使用 screen；window 仅用于明确需要前景窗口局部图像的非坐标观察。',
                        ],
                    ],
                    'required' => []
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_cursor_pos',
                'description' => $toolDesc['get_cursor_pos'] ?? '获取当前鼠标光标在屏幕上的坐标（像素，左上角为 0,0）。在执行 mouse_click 之前可调用此工具确认当前鼠标位置，或用于校验上一次点击是否生效。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => []
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'mouse_move',
                'description' => $toolDesc['mouse_move'] ?? '将鼠标光标移动到指定屏幕坐标 (x, y)，不点击。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'x' => ['type' => 'integer', 'description' => '屏幕横坐标（像素）'],
                        'y' => ['type' => 'integer', 'description' => '屏幕纵坐标（像素）']
                    ],
                    'required' => ['x', 'y']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'mouse_click',
                'description' => $toolDesc['mouse_click'] ?? '在指定屏幕坐标 (x, y) 执行鼠标点击（左键/右键/中键，单击/双击）。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'x' => ['type' => 'integer', 'description' => '屏幕横坐标（像素）'],
                        'y' => ['type' => 'integer', 'description' => '屏幕纵坐标（像素）'],
                        'button' => ['type' => 'string', 'enum' => ['left', 'right', 'middle'], 'description' => '鼠标按键，默认 left'],
                        'click' => ['type' => 'string', 'enum' => ['single', 'double'], 'description' => '单击或双击，默认 single']
                    ],
                    'required' => ['x', 'y']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'mouse_scroll',
                'description' => $toolDesc['mouse_scroll'] ?? '在当前位置滚动鼠标滚轮。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'delta' => ['type' => 'integer', 'description' => '滚动量，正值向上滚，负值向下滚（如 120 或 -120）']
                    ],
                    'required' => ['delta']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'mouse_drag',
                'description' => $toolDesc['mouse_drag'] ?? '鼠标拖动：按住左键拖动画线。支持两种模式：\n1. 直线模式：传 from_x/from_y/to_x/to_y，从起点画直线到终点\n2. 曲线模式：传 points 数组（至少3个点），系统用 Catmull-Rom 样条插值生成平滑曲线（不是直线拼接）\n画曲线/圆形/弧度时用 points 模式传入路径上的多个点（如画圆弧：8-12个点沿圆周分布；画弧线：3-5个点定义弧线形状）。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'from_x' => ['type' => 'integer', 'description' => '直线模式：起点横坐标'],
                        'from_y' => ['type' => 'integer', 'description' => '直线模式：起点纵坐标'],
                        'to_x' => ['type' => 'integer', 'description' => '直线模式：终点横坐标'],
                        'to_y' => ['type' => 'integer', 'description' => '直线模式：终点纵坐标'],
                        'points' => [
                            'type' => 'array',
                            'description' => '曲线模式：路径点数组，按顺序经过每个点。每项含 x,y。非空时忽略 from/to。示例：[{\"x\":100,\"y\":200},{\"x\":120,\"y\":180},{\"x\":140,\"y\":200}] 画一段弧线',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'x' => ['type' => 'integer', 'description' => '横坐标'],
                                    'y' => ['type' => 'integer', 'description' => '纵坐标']
                                ],
                                'required' => ['x', 'y']
                            ]
                        ],
                        'button' => ['type' => 'string', 'enum' => ['left', 'right', 'middle'], 'description' => '鼠标按键，默认 left']
                    ],
                    'required' => []
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'mouse_hold',
                'description' => $toolDesc['mouse_hold'] ?? '鼠标长按：在 (x, y) 位置按下指定按键并保持 duration 毫秒后释放。用于长按操作（如长按右键弹出菜单、长按左键拖动预备、长按文件弹出属性等）。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'x' => ['type' => 'integer', 'description' => '横坐标（像素）'],
                        'y' => ['type' => 'integer', 'description' => '纵坐标（像素）'],
                        'button' => ['type' => 'string', 'enum' => ['left', 'right', 'middle'], 'description' => '鼠标按键，默认 left'],
                        'duration' => ['type' => 'integer', 'description' => '按住时长（毫秒），默认 500，范围 100-5000']
                    ],
                    'required' => ['x', 'y']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'keyboard_type',
                'description' => $toolDesc['keyboard_type'] ?? '模拟键盘输入一段文本（逐字符输入）。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => '要输入的文本内容']
                    ],
                    'required' => ['text']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'key_press',
                'description' => $toolDesc['key_press'] ?? '模拟按下组合键或单键（如 ctrl+c、enter、alt+tab、win、esc）。多个键用 + 连接。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'keys' => ['type' => 'string', 'description' => '按键组合，如 "ctrl+c"、"enter"、"alt+tab"、"win"']
                    ],
                    'required' => ['keys']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'computer_observe',
                'description' => '仅在 API、确定性工具、Shell/Python 和浏览器自动化均不适用后观察原生桌面。返回当前目标窗口的 UIA 语义快照；只有 UIA 无法表达目标时才返回受限的窗口局部视觉观察。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'goal' => ['type' => 'string', 'description' => '本次桌面观察要解决的业务目标'],
                        'scope' => ['type' => 'string', 'description' => '可选的窗口标题、进程或局部区域语义范围，禁止填写像素坐标'],
                        'reasoning' => ['type' => 'string', 'description' => '说明更高优先级能力为何不适用'],
                    ],
                    'required' => ['goal'],
                    'additionalProperties' => false,
                ],
            ],
            'route_class' => 'computer',
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'computer_interact',
                'description' => '对桌面中的语义目标执行一个高层动作。目标必须是“微信发送按钮”一类可读语义，禁止提交绝对像素。运行时依次尝试 UIA 原生 Pattern、经过命中验证的物理输入，最后才使用两阶段局部视觉定位。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'operation' => [
                            'type' => 'string',
                            'enum' => ['invoke', 'set_value', 'select', 'toggle', 'expand', 'collapse', 'scroll', 'key_chord'],
                            'description' => '固定的桌面业务动作',
                        ],
                        'target' => ['type' => 'string', 'description' => '可读的语义目标，禁止绝对像素'],
                        'value' => ['type' => 'string', 'description' => 'set_value/select/key_chord 等动作的值'],
                        'direction' => ['type' => 'string', 'enum' => ['up', 'down', 'left', 'right'], 'description' => '滚动方向'],
                        'amount' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => '滚动档位'],
                        'expected_effect' => ['type' => 'string', 'description' => '动作完成后必须能独立验证的界面效果'],
                        'reasoning' => ['type' => 'string', 'description' => '说明更高优先级能力为何不适用'],
                    ],
                    'required' => ['operation', 'target'],
                    'additionalProperties' => false,
                ],
            ],
            'route_class' => 'computer',
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'computer_complete',
                'description' => '结束本次 CU 任务。只有动作结果已经过 UIA 属性、窗口状态或目标区域视觉变化验证后才能报告 completed；结果不明时必须报告 blocked 或 failed。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['completed', 'blocked', 'failed']],
                        'summary' => ['type' => 'string', 'description' => '基于验证证据的简短总结'],
                    ],
                    'required' => ['status', 'summary'],
                    'additionalProperties' => false,
                ],
            ],
            'route_class' => 'computer',
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'task_complete',
                'description' => $toolDesc['task_complete'] ?? '当用户命令已全部完成时调用此工具结束 Computer User 任务循环。提供简短的完成总结。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string', 'description' => '任务完成总结，说明完成了什么操作']
                    ],
                    'required' => ['summary']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'find_element',
                'description' => $toolDesc['find_element'] ?? '在当前活动窗口（或指定父元素）的 UI 树中按条件查找单个 UI 元素。优先使用此工具精确定位元素而非整树获取。返回 element_id 用于后续 click_element / set_text / get_text 操作。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'parent_element_id' => ['type' => 'string', 'description' => '父元素 ID（来自上一次 find_element）。可选，缺省为当前活动窗口。'],
                        'automation_id' => ['type' => 'string', 'description' => '按 AutomationId 匹配（精确匹配，最稳定）'],
                        'name' => ['type' => 'string', 'description' => '按 Name 匹配（不区分大小写包含匹配）'],
                        'control_type' => ['type' => 'string', 'description' => '按 ControlType 匹配，如 Button / Edit / CheckBox / ComboBox / MenuItem / TabItem'],
                    ],
                    'required' => []
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_ui_tree',
                'description' => $toolDesc['get_ui_tree'] ?? '获取当前活动窗口（或指定根元素）的 UIA 文本树，用于观察名称、控件类型和可用操作；树中的数字是观察索引，不是 element_id。需要操作时再用 find_element 获取 element_id。深度限制默认 6 层，元素数上限 2000。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'root_element_id' => ['type' => 'string', 'description' => '根元素 ID。可选，缺省为当前活动窗口。'],
                        'max_depth' => ['type' => 'integer', 'description' => '最大递归深度，默认 6。超过的层级 children 为空数组。'],
                    ],
                    'required' => []
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'click_element',
                'description' => $toolDesc['click_element'] ?? '点击指定 UI 元素。优先使用 InvokePattern（按钮）/ TogglePattern（复选框/单选框），模式不可用时回退到 SendInput 点击元素中心坐标。比 mouse_click 更稳定，不受窗口位置变化影响。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'element_id' => ['type' => 'string', 'description' => '目标元素 ID（来自 find_element，或 capture_ui_snapshot 的 focused_element_id）'],
                    ],
                    'required' => ['element_id']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'set_text',
                'description' => $toolDesc['set_text'] ?? '设置文本框/编辑框的内容。通过 ValuePattern.SetValue 直接赋值（覆盖原有内容），比 keyboard_type 更快更可靠。失败时回退到清空+keyboard_type 输入。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'element_id' => ['type' => 'string', 'description' => '目标文本框元素 ID'],
                        'text' => ['type' => 'string', 'description' => '要设置的文本内容'],
                    ],
                    'required' => ['element_id', 'text']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_text',
                'description' => $toolDesc['get_text'] ?? '读取 UI 元素的文本内容。优先 TextPattern（富文本控件），次选 ValuePattern（输入框），最后 element.Current.Name。用于读取输入框当前值、列表项标签等。返回文本超过 5000 字符时截断。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'element_id' => ['type' => 'string', 'description' => '目标元素 ID'],
                    ],
                    'required' => ['element_id']
                ]
            ]
        ],
        // === Task 12: capture_ui_snapshot — CU 模式首选感知工具 ===
        // 一次调用同时获取 UIA 树（文本格式）+ 截图（window-relative）+ 焦点元素 + 窗口元信息。
        // 每轮决策前优先调用此工具，替代 take_screenshot + get_ui_tree 两步组合。
        [
            'type' => 'function',
            'function' => [
                'name' => 'capture_ui_snapshot',
                'description' => $toolDesc['capture_ui_snapshot'] ?? 'CU 模式首选感知工具，一次调用同时获取 UIA 树（文本格式）、可用元素数、截图（window-relative）、焦点元素及其 focused_element_id、窗口元信息。每轮决策前优先调用此工具，替代 take_screenshot + get_ui_tree 两步组合。截图坐标系是 window-relative，你输出的坐标会被系统自动还原为屏幕物理坐标。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'max_depth' => ['type' => 'integer', 'description' => 'UIA 树最大深度，默认 6（与 get_ui_tree 一致；uia_tree_depth 配置覆盖此默认值）'],
                        'include_screenshot' => ['type' => 'boolean', 'description' => '是否包含截图，默认 true。设为 false 时仅返回 UIA 树（用于 task_complete 验证等无需视觉的场景，加快响应）'],
                        'screenshot_target' => [
                            'type' => 'string',
                            'enum' => ['window', 'screen'],
                            'description' => '截图目标，默认 window（window-relative 坐标系，origin 为窗口左上角）；screen 为整屏截图（与 take_screenshot 一致）'
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'focus_window',
                'description' => $toolDesc['focus_window'] ?? '切换到已经运行的窗口。通过窗口标题关键词或进程名定位；应用已运行时必须用此工具，禁止再次调用 open_app。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'window_title' => ['type' => 'string', 'description' => '目标窗口标题关键词'],
                        'process_name' => ['type' => 'string', 'description' => '目标进程名，如 QQ.exe'],
                    ],
                    'required' => [],
                    'additionalProperties' => false,
                ],
            ],
        ],
        // === Browser Automation 工具集合（mode_gate 限定为 agent / computer_user 模式可用） ===
        [
            'type' => 'function',
            'function' => [
                'name' => 'browser_automation_control',
                'description' => $toolDesc['browser_automation_control'] ?? '通过统一浏览器协议观察页面并执行导航、元素、页面、标签页和下载操作。写操作后返回同一页面版本的可验证事实。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'enum' => BrowserAutomationGateway::ACTIONS,
                            'description' => '具体浏览器操作'
                        ],
                        'url' => ['type' => 'string', 'description' => '导航目标或 URL 等待条件'],
                        'element_id' => ['type' => 'string', 'description' => 'inspect 返回且绑定页面版本的元素标识；元素操作应优先使用'],
                        'selector' => ['type' => 'string', 'description' => '兼容旧调用的标准 CSS 选择器'],
                        'text' => ['type' => 'string', 'description' => '输入文本或文本等待条件'],
                        'values' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'select 操作要选中的值'],
                        'key' => ['type' => 'string', 'description' => 'press 操作的标准按键名'],
                        'direction' => ['type' => 'string', 'enum' => ['up', 'down'], 'description' => '滚动方向'],
                        'amount' => ['type' => 'integer', 'description' => '滚动量'],
                        'condition' => ['type' => 'string', 'enum' => ['time', 'element', 'text', 'url', 'navigation', 'dom_stable'], 'description' => 'wait 条件'],
                        'state' => ['type' => 'string', 'enum' => ['visible', 'hidden'], 'description' => '元素等待状态'],
                        'ms' => ['type' => 'integer', 'minimum' => 1, 'description' => '等待上限或延迟毫秒数'],
                        'tab_id' => ['type' => 'string', 'description' => '标签页标识'],
                        'risk_category' => ['type' => 'string', 'enum' => ['submit_personal_data', 'purchase', 'change_permissions', 'delete_data'], 'description' => '敏感动作声明；命中时必须逐次确认'],
                        'approval_token' => ['type' => 'string', 'description' => 'Web 确认后返回且绑定当前动作的单次令牌']
                    ],
                    'required' => ['action'],
                    'additionalProperties' => false
                ]
            ],
            'mode_gate' => ['agent', 'computer_user']
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'vls_analyze_browser',
                'description' => $toolDesc['vls_analyze_browser'] ?? '把当前页面版本的真实截图作为图像消息交给视觉模型，只返回可见事实、区域与坐标，不生成猜测选择器。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => []
                ]
            ],
            'mode_gate' => ['agent', 'computer_user']
        ],
        // === Task 8: Trae Work 工具集（agent 与 computer_user 模式下均可用，不进入 $cuOnlyTools） ===
        [
            'type' => 'function',
            'function' => [
                'name' => 'edit_file',
                'description' => '编辑文件的通用工具，支持三个子命令：(1) view - 查看文件内容（带行号）或目录列表；(2) str_replace - 精确字符串替换（要求 old_str 在文件中唯一匹配，不唯一时返回所有匹配行号让你用更长上下文重试）；(3) insert - 在指定行号后插入内容（insert_line=0 表示文件开头）。修改代码时优先使用此工具，禁止整文件重写。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'command' => ['type' => 'string', 'enum' => ['view', 'str_replace', 'insert'], 'description' => '子命令'],
                        'path' => ['type' => 'string', 'description' => '文件或目录的绝对路径'],
                        'old_str' => ['type' => 'string', 'description' => 'str_replace 命令：要被替换的字符串（必须在文件中唯一匹配）'],
                        'new_str' => ['type' => 'string', 'description' => 'str_replace 命令：替换后的新字符串；insert 命令：要插入的内容'],
                        'insert_line' => ['type' => 'integer', 'description' => 'insert 命令：在第几行后插入（0=文件开头，1=第1行后）'],
                        'view_range' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'view 命令：[start, end] 行号范围，end=-1 到文件末尾；查看目录时 [depth_min, depth_max] 深度范围'],
                        'cwd' => ['type' => 'string', 'description' => '工作目录（相对路径基于此解析，可选）']
                    ],
                    'required' => ['command', 'path']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'grep',
                'description' => '基于 ripgrep 的高速内容搜索工具。支持正则表达式、文件类型过滤、上下文行。找不到代码时必须用此工具定位，禁止盲猜路径。无匹配返回空结果（不算错误）。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => ['type' => 'string', 'description' => '正则表达式模式'],
                        'path' => ['type' => 'string', 'description' => '搜索根目录（默认当前目录）'],
                        'output_mode' => ['type' => 'string', 'enum' => ['content', 'files_with_matches', 'count'], 'description' => '输出模式：content=返回匹配行内容（默认），files_with_matches=仅返回文件路径，count=返回每个文件的匹配数'],
                        'context_before' => ['type' => 'integer', 'description' => '上下文行数（匹配行前 N 行）'],
                        'context_after' => ['type' => 'integer', 'description' => '上下文行数（匹配行后 N 行）'],
                        'context' => ['type' => 'integer', 'description' => '上下文行数（同时设置前后 N 行）'],
                        'show_line_numbers' => ['type' => 'boolean', 'description' => '是否显示行号（默认 true）'],
                        'case_insensitive' => ['type' => 'boolean', 'description' => '是否忽略大小写（默认 false）'],
                        'glob_filter' => ['type' => 'string', 'description' => '文件名 glob 过滤（如 *.php）'],
                        'type_filter' => ['type' => 'string', 'description' => '文件类型过滤（如 php/py/js）']
                    ],
                    'required' => ['pattern']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'glob',
                'description' => '按 glob 模式匹配文件名（如 **/*.php）。返回按修改时间倒序排序的文件列表，单次最多 200 个。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => ['type' => 'string', 'description' => 'glob 模式（支持 **、*、?、{a,b} 语法）'],
                        'path' => ['type' => 'string', 'description' => '搜索根目录（默认当前目录）']
                    ],
                    'required' => ['pattern']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'view_directory',
                'description' => '查看目录树结构（递归深度可配置，默认 2 层）。自动排除 .git/node_modules/vendor/bin/obj/.venv/__pycache__/.vs/.idea 等噪声目录。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '目录路径'],
                        'depth' => ['type' => 'integer', 'description' => '递归深度（默认 2）'],
                        'exclude_patterns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '自定义排除模式（追加到默认排除列表）']
                    ],
                    'required' => ['path']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'todo_write',
                'description' => '管理任务列表（创建/更新/查询）。复杂任务（≥3 步）必须先调用此工具规划。同时只能有一个 in_progress 任务。任务状态变化会通过 SSE 事件实时推送到前端 UI 展示。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'todos' => [
                            'type' => 'array',
                            'description' => '任务列表',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string', 'description' => '任务唯一 ID'],
                                    'content' => ['type' => 'string', 'description' => '任务描述'],
                                    'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'completed'], 'description' => '任务状态'],
                                    'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low'], 'description' => '优先级'],
                                    'summary' => ['type' => 'string', 'description' => '任务完成时的总结（仅 status=completed 时填）']
                                ],
                                'required' => ['id', 'content', 'status', 'priority']
                            ]
                        ],
                        'merge' => ['type' => 'boolean', 'description' => 'false=替换整个列表（默认），true=按 id 合并到现有列表']
                    ],
                    'required' => ['todos']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_diagnostics',
                'description' => '获取指定文件的 LSP 诊断信息（错误/警告/提示）。修改代码后必须调用此工具验证是否引入错误。支持 PHP（Intelephense）/Python（Pyright）/JS-TS（tsserver）三种语言。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '文件路径'],
                        'full_project' => ['type' => 'boolean', 'description' => '是否获取全项目诊断（默认 false，仅获取指定文件）']
                    ],
                    'required' => ['path']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'find_references',
                'description' => '查找指定符号的所有引用位置（LSP textDocument/references）。重构函数前用此工具了解调用方。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '文件路径'],
                        'line' => ['type' => 'integer', 'description' => '符号所在行号（1-based）'],
                        'column' => ['type' => 'integer', 'description' => '符号所在列号（1-based）']
                    ],
                    'required' => ['path', 'line', 'column']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'goto_definition',
                'description' => '跳转到指定符号的定义位置（LSP textDocument/definition）。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '文件路径'],
                        'line' => ['type' => 'integer', 'description' => '符号所在行号（1-based）'],
                        'column' => ['type' => 'integer', 'description' => '符号所在列号（1-based）']
                    ],
                    'required' => ['path', 'line', 'column']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_command_status',
                'description' => '查询 persistent 后台命令状态（running/done/killed）、command_ok、stdout/stderr、output_revision、completion_mode 和 operation_receipt。受管 finite 作业由协调器自动等待，不得人工轮询。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'command_id' => ['type' => 'string', 'description' => 'shell_executor blocking=false 返回的 command_id']
                    ],
                    'required' => ['command_id']
                ]
            ]
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'stop_command',
                'description' => '停止后台命令（终止进程树）。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'command_id' => ['type' => 'string', 'description' => '要停止的 command_id']
                    ],
                    'required' => ['command_id']
                ]
            ]
        ],
];

return [
    'default_mode' => 'normal',
    'available_modes' => ['normal', 'agent', 'code_agent', 'computer_user'],
    'mode_labels' => [
        'normal' => '普通模式',
        'agent' => 'Agent模式',
        'code_agent' => '代码生成代理',
    ],
    // ★ system_prompt 已全部删除，统一由后端控制面板（系统提示词/Personality）管理
    //   避免多处配置冲突导致 AI 在同一次响应中重复输出身份介绍
    //   agent/image_gen/code_agent 等场景的提示词请在管理后台设置

    'agent_tools' => $agentTools,
    'tool_models' => ['deepseek-v4-flash', 'deepseek-v4-pro', 'kimi-k2.6', 'kimi-k2.5', 'moonshot-v1-32k-vision-preview', 'moonshot-v1-32k'],
    'cu_model' => $cuConfig['cu_model'] ?? 'MiniMax-M3',
    // VLS-Agent 视觉模型（三层降级第二层使用，自绘应用如 QQ/画图触发）
    // 必须是视觉模型（支持 image_url 输入）；kimi-k2.5 等纯文本模型会被 API 拒绝 400
    'vls_model' => $cuConfig['vls_model'] ?? 'moonshot-v1-8k-vision-preview',
    'code_agent_tool_models' => ['deepseek-v4-flash', 'deepseek-v4-pro'],
    // ===== Plan-Act-Verify 架构配置（cu_runtime_config 透传） =====
    'plan_enabled' => (bool)($cuConfig['plan_enabled'] ?? 0),
    'plan_model' => $cuConfig['plan_model'] ?? 'deepseek-v4-pro',
    'verify_model' => $cuConfig['verify_model'] ?? 'kimi-k2.5',
    'verify_max_rounds' => (int)($cuConfig['verify_max_rounds'] ?? 3),
    'plan_max_steps' => (int)($cuConfig['plan_max_steps'] ?? 10),
    'step_action_max_iterations' => (int)($cuConfig['step_action_max_iterations'] ?? 20),
    'verify_token_budget' => (int)($cuConfig['verify_token_budget'] ?? 2000),
];
