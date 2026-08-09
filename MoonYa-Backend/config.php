<?php
require_once __DIR__ . '/env_loader.php';

/**
 * 从 api_domain_config 表读取域名配置（APCu 缓存 30 秒）。
 * APCu 不可用时降级为每次查库。查询失败返回 $default。
 */
if (!function_exists('get_api_domain_config')) {
function get_api_domain_config($key, $default = '') {
    $cacheKey = 'moonya_api_domain_' . $key;

    // APCu 缓存命中
    if (function_exists('apcu_fetch') && apcu_exists($cacheKey)) {
        $cached = apcu_fetch($cacheKey, $ok);
        if ($ok && $cached !== false) {
            return $cached;
        }
    }

    // 查库
    $value = $default;
    try {
        $dsn = 'mysql:host=' . (env('DB_HOST') ?: 'localhost')
             . ';dbname=' . (env('DB_NAME') ?: 'ai_system')
             . ';charset=utf8mb4';
        $pdo = new PDO($dsn, env('DB_USER') ?: '', env('DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $stmt = $pdo->prepare('SELECT config_value FROM api_domain_config WHERE config_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetchColumn();
        if ($row !== false && $row !== null) {
            $value = $row;
        }
    } catch (Exception $e) {
        // 降级：返回 default
        $value = $default;
    }

    // 写入 APCu 缓存（30 秒 TTL）
    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, $value, 30);
    }
    return $value;
}
} // end function_exists guard

// 环境变量优先于数据库中的组件配置；缺失时由调用方返回具体字段错误。
$configured_python_domain = trim((string)(env('MOONYA_PYTHON_SERVICE_URL') ?: ''));
$python_service_domain = $configured_python_domain !== ''
    ? $configured_python_domain
    : (string)get_api_domain_config('python_service_domain', '');

// Web 附件配置：环境变量可覆盖部署默认值，业务代码和前端不得另写限制。
$web_attachment_max_files = max(1, (int)(env('WEB_UPLOAD_MAX_FILES') ?: 100));
$web_attachment_max_file_size = max(1, (int)(env('WEB_UPLOAD_MAX_FILE_BYTES') ?: 5242880));
$web_attachment_ttl_seconds = max(60, (int)(env('WEB_UPLOAD_TTL_SECONDS') ?: 5400));
$web_attachment_temp_dir = env('WEB_UPLOAD_TEMP_DIR')
    ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moonya-web-attachments');

// API 配置
return [
    // API访问密钥 - 用于外部系统对接时的身份验证
    // 请求时需要在Header中设置: X-API-Key: yueyaxuan_ai_key
    'api_access_key' => env('API_ACCESS_KEY') ?: '',

    'browser_security' => [
        'confirmation_ttl_seconds' => max(30, (int)(env('BROWSER_CONFIRMATION_TTL_SECONDS') ?: 120)),
    ],
    'web_assets' => [
        'highlight_css' => env('HIGHLIGHT_CSS_URL') ?: 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css',
        'highlight_js' => env('HIGHLIGHT_JS_URL') ?: 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js',
        'mammoth_js' => env('MAMMOTH_JS_URL') ?: 'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.8.0/mammoth.browser.min.js',
        'pdf_js' => env('PDF_JS_URL') ?: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js',
        'pdf_worker_js' => env('PDF_WORKER_JS_URL') ?: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js',
    ],
    'profile' => [
        'default_avatar_url' => env('DEFAULT_AVATAR_URL') ?: '/image/default-avatar.png',
    ],
    'video_portal' => [
        'url' => env('VIDEO_PORTAL_URL') ?: '/YX_VIDEO/',
    ],
    'internal_api_url' => env('MOONYA_INTERNAL_API_URL') ?: '',
    'internal_api_timeout_seconds' => max(1, (int)(env('MOONYA_INTERNAL_API_TIMEOUT_SECONDS') ?: 30)),
    

'api_key' => env('KIMI_API_KEY') ?: '',
'deepseek_api_key' => env('DEEPSEEK_API_KEY') ?: '',
 'minmax_api_key' => env('MINMAX_API_KEY') ?: '',
 'glm_api_key' => env('GLM_API_KEY') ?: '',








    // ==================== API 配置 ====================
    'api_url' => env('KIMI_API_URL') ?: 'https://api.moonshot.cn/v1/chat/completions',

    'upload_api_url' => env('KIMI_UPLOAD_API_URL') ?: 'https://api.moonshot.cn/v1/files',
    'deepseek_api_url' => env('DEEPSEEK_API_URL') ?: 'https://api.deepseek.com/v1/chat/completions',
    'minmax_api_url' => env('MINMAX_API_URL') ?: 'https://api.minimaxi.com/v1/chat/completions',
    'minmax_image_api_url' => env('MINMAX_IMAGE_API_URL') ?: 'https://api.minimaxi.com/v1/image_generation',
   
    'glm_api_url' => env('GLM_API_URL') ?: 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
   
    'cogview_api_url' => env('COGVIEW_API_URL') ?: 'https://open.bigmodel.cn/api/paas/v4/images/generations',
    'cogview_model' => env('COGVIEW_MODEL') ?: 'cogview-3-flash',
    'cogvideo_api_url' => env('COGVIDEO_API_URL') ?: 'https://open.bigmodel.cn/api/paas/v4/videos/generations',
    'cogvideo_async_result_url' => env('COGVIDEO_ASYNC_RESULT_URL') ?: 'https://open.bigmodel.cn/api/paas/v4/async-result',
    'cogvideo_model' => env('COGVIDEO_MODEL') ?: 'cogvideox-flash',
    'cogvideo_upload_dir' => __DIR__ . '/video_gen/uploads',

    // ==================== Web 附件统一配置 ====================
    'web_attachments' => [
        'max_files' => $web_attachment_max_files,
        'max_file_size' => $web_attachment_max_file_size,
        'ttl_seconds' => $web_attachment_ttl_seconds,
        'temp_dir' => $web_attachment_temp_dir,
        'image_agent_model' => env('IMAGE_AGENT_MODEL') ?: 'kimi-k2.5',
        'provider_connect_timeout_seconds' => max(1, (int)(env('WEB_ATTACHMENT_CONNECT_TIMEOUT_SECONDS') ?: 10)),
        'provider_timeout_seconds' => max(1, (int)(env('WEB_ATTACHMENT_PROVIDER_TIMEOUT_SECONDS') ?: 90)),
        'image_agent_temperature' => (float)(env('IMAGE_AGENT_TEMPERATURE') ?: 1.0),
        'image_agent_max_tokens' => max(256, (int)(env('IMAGE_AGENT_MAX_TOKENS') ?: 4096)),
        'image_agent_timeout_seconds' => max(30, (int)(env('IMAGE_AGENT_TIMEOUT_SECONDS') ?: 180)),
        'image_agent_batch_size' => max(1, (int)(env('IMAGE_AGENT_BATCH_SIZE') ?: 10)),
        'max_extracted_chars' => max(1000, (int)(env('WEB_UPLOAD_MAX_EXTRACTED_CHARS') ?: 500000)),
        'categories' => [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'svg'],
            'video' => ['mp4', 'mov', 'webm', 'mkv', 'avi'],
            'audio' => ['mp3', 'wav', 'm4a', 'ogg', 'webm', 'pcm'],
            'document' => [
                'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'rtf',
                'md', 'txt', 'json', 'xml', 'yaml', 'yml', 'go', 'h', 'c', 'cpp',
                'cxx', 'cc', 'cs', 'java', 'js', 'css', 'jsp', 'php', 'py', 'py3',
                'asp', 'ini', 'conf', 'ts', 'tsx', 'html', 'log',
            ],
        ],
    ],
    
    // ==================== Kimi 文件上传配置 ====================
    'kimi_upload' => [
        'max_file_size' => 100 * 1024 * 1024,
        'max_file_count' => 5,
        'max_video_resolution' => 2048 * 1080,
        'document_extensions' => [
            'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'md', 'dot', 'epub', 'mobi', 'log',
            'go', 'h', 'c', 'cpp', 'cxx', 'cc', 'cs', 'java', 'js', 'css',
            'jsp', 'php', 'py', 'py3', 'asp', 'yaml', 'yml', 'ini', 'conf',
            'ts', 'tsx', 'html', 'json',
        ],
        'image_extensions' => ['jpeg', 'png', 'webp', 'gif'],
        'image_purpose' => 'image',
        'video_extensions' => ['mp4', 'mpeg', 'mov', 'avi', 'x-flv', 'mpg', 'webm', 'wmv', '3gpp'],
        'video_purpose' => 'video',
        'extra_image_extensions' => [
            'bmp', 'svg', 'svgz', 'ico', 'xbm', 'dib', 'pjp', 'tif',
            'pjpeg', 'avif', 'apng', 'tiff', 'jfif',
        ],
    ],
    
    // ==================== DeepSeek 文件上传配置 ====================
    'deepseek_upload' => [
        'max_file_size' => 100 * 1024 * 1024,
        'max_file_count' => 5,
        'allowed_types' => [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
        ],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'doc', 'docx', 'txt'],
        'image_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
        'document_extensions' => ['pdf', 'doc', 'docx', 'txt'],
        'max_text_length' => 500000,
    ],
    
    // ==================== OCR 图片文字识别配置 ====================
    'ocr' => [
        'enabled' => true,
        'api_url' => env('OCR_API_URL') ?: 'https://yunzhiapi.cn/API/ocrwzsb.php',
        'token' => env('YUNZHI_API_TOKEN') ?: '',
        'max_image_size' => 1 * 1024 * 1024,
        'supported_formats' => ['pdf', 'gif', 'png', 'jpg', 'tif', 'bmp', 'jpeg'],
        'target' => 'chs',
        'type' => 'json',
        'site_url' => rtrim(get_api_domain_config('main_api_domain', ''), '/'),
        'temp_dir' => __DIR__ . '/image/',
        'temp_url_path' => '/image/',
    ],
    
    // ==================== 模型配置 ====================
    // 各模式使用的模型名称
    'search_models' => [
        'search' => 'kimi-k2.6',                     // 联网搜索模型
    ],
    'tool_models' => ['kimi-k2.6'],
    'default_chat_model_group' => env('DEFAULT_CHAT_MODEL_GROUP') ?: 'deepseek',
    'prompt_optimizer_model' => env('PROMPT_OPTIMIZER_MODEL') ?: 'deepseek-v4-flash',
    'deep_thinking_model' => env('DEEP_THINKING_MODEL') ?: 'kimi-k2.6',
    'browser_vls_temperature' => (float)(env('BROWSER_VLS_TEMPERATURE') ?: 0.2),

    // 生产模型目录的唯一来源。界面、路由和 Agent 只读取这份组件配置。
    'ui_model_groups' => [
        'kimi' => [
            'default' => env('KIMI_DEFAULT_MODEL') ?: 'moonshot-v1-32k-vision-preview',
            'models' => [
                ['id' => 'moonshot-v1-8k', 'label' => 'Moonshot v1 8K', 'supports_images' => false],
                ['id' => 'moonshot-v1-32k', 'label' => 'Moonshot v1 32K', 'supports_images' => false],
                ['id' => 'moonshot-v1-128k', 'label' => 'Moonshot v1 128K', 'supports_images' => false],
                ['id' => 'moonshot-v1-8k-vision-preview', 'label' => 'Moonshot v1 8K Vision', 'supports_images' => true],
                ['id' => 'moonshot-v1-32k-vision-preview', 'label' => 'Moonshot v1 32K Vision', 'supports_images' => true],
                ['id' => 'moonshot-v1-128k-vision-preview', 'label' => 'Moonshot v1 128K Vision', 'supports_images' => true],
                ['id' => 'kimi-k2.5', 'label' => 'Kimi K2.5', 'supports_images' => true, 'supports_thinking' => true, 'fixed_temperature' => 1.0],
                ['id' => 'kimi-k2.6', 'label' => 'Kimi K2.6', 'supports_images' => true, 'supports_thinking' => true, 'fixed_temperature' => 1.0],
            ],
        ],
        'deepseek' => [
            'default' => env('DEEPSEEK_DEFAULT_MODEL') ?: 'deepseek-v4-flash',
            'models' => [
                ['id' => 'deepseek-v4-flash', 'label' => 'v4-flash', 'supports_images' => false],
                ['id' => 'deepseek-v4-pro', 'label' => 'v4-pro', 'supports_images' => false],
            ],
        ],
        'minmax' => [
            'default' => env('MINMAX_DEFAULT_MODEL') ?: 'MiniMax-M2.5',
            'models' => [
                ['id' => 'MiniMax-M2.7', 'label' => 'MiniMax-M2.7', 'supports_images' => false],
                ['id' => 'MiniMax-M2.7-highspeed', 'label' => 'MiniMax-M2.7 高速', 'supports_images' => false],
                ['id' => 'MiniMax-M2.5', 'label' => 'MiniMax-M2.5', 'supports_images' => false, 'force_thinking' => true],
                ['id' => 'MiniMax-M2.5-highspeed', 'label' => 'MiniMax-M2.5 高速', 'supports_images' => false, 'force_thinking' => true],
                ['id' => 'MiniMax-M2.1', 'label' => 'MiniMax-M2.1', 'supports_images' => false, 'force_thinking' => true],
                ['id' => 'MiniMax-M2.1-highspeed', 'label' => 'MiniMax-M2.1 高速', 'supports_images' => false, 'force_thinking' => true],
                ['id' => 'MiniMax-M2', 'label' => 'MiniMax-M2', 'supports_images' => false, 'force_thinking' => true],
            ],
        ],
        'glm' => [
            'default' => env('GLM_DEFAULT_MODEL') ?: 'GLM-4.6V-Flash',
            'models' => [
                ['id' => 'GLM-4.5-Air', 'label' => 'GLM-4.5-Air', 'supports_images' => false],
                ['id' => 'GLM-4.7-Flash', 'label' => 'GLM-4.7-Flash', 'supports_images' => false],
                ['id' => 'GLM-4.6V-Flash', 'label' => 'GLM-4.6V-Flash', 'supports_images' => true],
                ['id' => 'GLM-4.1V-Thinking-Flash', 'label' => 'GLM-4.1V-Thinking-Flash', 'supports_images' => true, 'supports_thinking' => true],
                ['id' => 'GLM-4-Flash-250414', 'label' => 'GLM-4-Flash-250414', 'supports_images' => false],
                ['id' => 'GLM-4-Flash', 'label' => 'GLM-4-Flash', 'supports_images' => false],
            ],
        ],
    ],
    'model_provider_routes' => [
        'kimi' => ['url_key' => 'api_url', 'api_key_key' => 'api_key'],
        'deepseek' => ['url_key' => 'deepseek_api_url', 'api_key_key' => 'deepseek_api_key'],
        'minmax' => ['url_key' => 'minmax_api_url', 'api_key_key' => 'minmax_api_key'],
        'glm' => ['url_key' => 'glm_api_url', 'api_key_key' => 'glm_api_key'],
    ],
    'model_capabilities' => [
        'moonshot-v1-8k' => ['provider' => 'kimi', 'supports_images' => false, 'cu_max_tokens' => 2048],
        'moonshot-v1-32k' => ['provider' => 'kimi', 'supports_images' => false],
        'moonshot-v1-128k' => ['provider' => 'kimi', 'supports_images' => false],
        'moonshot-v1-8k-vision-preview' => ['provider' => 'kimi', 'supports_images' => true, 'cu_max_tokens' => 2048],
        'moonshot-v1-32k-vision-preview' => ['provider' => 'kimi', 'supports_images' => true],
        'moonshot-v1-128k-vision-preview' => ['provider' => 'kimi', 'supports_images' => true],
        'kimi-k2.5' => ['provider' => 'kimi', 'supports_images' => true, 'disable_thinking_for_tools' => true, 'fixed_temperature' => 1.0, 'planning_thinking_disabled' => true],
        'kimi-k2.6' => ['provider' => 'kimi', 'supports_images' => true, 'disable_thinking_for_tools' => true, 'fixed_temperature' => 1.0, 'planning_thinking_disabled' => true],
        'kimi-vision-v1' => ['provider' => 'kimi', 'supports_images' => true],
        'deepseek-v4-flash' => ['provider' => 'deepseek', 'supports_images' => false, 'disable_thinking_for_tools' => true, 'reasoning_control' => 'binary_strength', 'strip_sampling_when_thinking' => true, 'omit_tool_choice_when_thinking' => true, 'supports_thinking_with_tools' => true, 'uses_scene_prompt' => true, 'normalize_multimodal_to_text' => true, 'planning_thinking_disabled' => true],
        'deepseek-v4-pro' => ['provider' => 'deepseek', 'supports_images' => false, 'disable_thinking_for_tools' => true, 'reasoning_control' => 'binary_strength', 'strip_sampling_when_thinking' => true, 'omit_tool_choice_when_thinking' => true, 'supports_thinking_with_tools' => true, 'uses_scene_prompt' => true, 'normalize_multimodal_to_text' => true, 'planning_thinking_disabled' => true],
        'MiniMax-M2' => ['provider' => 'minmax', 'supports_images' => false, 'reasoning_split' => true],
        'MiniMax-M2.1' => ['provider' => 'minmax', 'supports_images' => false, 'reasoning_split' => true],
        'MiniMax-M2.1-highspeed' => ['provider' => 'minmax', 'supports_images' => false, 'reasoning_split' => true],
        'MiniMax-M2.5' => ['provider' => 'minmax', 'supports_images' => false, 'reasoning_split' => true],
        'MiniMax-M2.5-highspeed' => ['provider' => 'minmax', 'supports_images' => false, 'reasoning_split' => true],
        'MiniMax-M2.7' => ['provider' => 'minmax', 'supports_images' => false],
        'MiniMax-M2.7-highspeed' => ['provider' => 'minmax', 'supports_images' => false],
        'MiniMax-M3' => ['provider' => 'minmax', 'supports_images' => true, 'reasoning_split' => true],
        'GLM-4.5-Air' => ['provider' => 'glm', 'supports_images' => false, 'supports_thinking_with_tools' => true],
        'GLM-4.7-Flash' => ['provider' => 'glm', 'supports_images' => false, 'supports_thinking_with_tools' => true],
        'GLM-4.6V-Flash' => ['provider' => 'glm', 'supports_images' => true, 'supports_thinking_with_tools' => true],
        'GLM-4.1V-Thinking-Flash' => ['provider' => 'glm', 'supports_images' => true, 'omit_disabled_thinking' => true, 'supports_thinking_with_tools' => true],
        'GLM-4-Flash-250414' => ['provider' => 'glm', 'supports_images' => false, 'supports_thinking_with_tools' => true],
        'GLM-4-Flash' => ['provider' => 'glm', 'supports_images' => false, 'supports_thinking_with_tools' => true],
    ],

    // ==================== Kimi 联网搜索统一配置（Kimi 官方两阶段 tool_calls 协议） ====================
    // 参考：https://platform.kimi.com/docs/guide/use-web-search
    // 关键流程：phase1 非流式 chat/completions 拿 tool_calls → 客户端把 arguments 原样回 role=tool →
    //          phase2 流式 chat/completions 拿最终回答
    // 业务侧（api.php）应读取本配置块，不要在代码中硬编码模型名/URL/温度/tool schema
    'kimi_web_search' => [
        'enabled' => true,
        'primary_model' => 'kimi-k2.6',           // Kimi 官方推荐：使用联网搜索时优先 kimi-k2.6
        'supported_models' => [
            'kimi-k2.5',                          // 兼容旧 dropdown
            'kimi-k2.6',                          // 官方推荐
            'moonshot-v1-8k',                     // 实验性，v1 系列对 $web_search 触发不稳定
            'moonshot-v1-32k',
            'moonshot-v1-128k',
        ],
        'endpoint' => env('KIMI_WEB_SEARCH_API_URL') ?: (env('KIMI_API_URL') ?: 'https://api.moonshot.cn/v1/chat/completions'),
        'temperature' => 0.6,                     // 官方：$web_search 固定 0.6
        'max_tokens' => 32768,
        'phase1_max_tokens' => 32768,             // 官方：搜索结果会占用大量 token，phase 1 必须 32768
        'thinking' => ['type' => 'disabled'],     // 官方：$web_search 必须禁用思考
        'phase1_stream' => false,                 // 第一阶段：非流式拿 tool_calls
        'phase2_stream' => true,                  // 第二阶段：可流式拿最终回答

        // ★ v4.7 (2026-06-20): tool_choice 配置化（严禁硬编码）
        //   auto = 让模型自主判断是否需要调用工具
        //   required = 强制每次都调用工具（旧行为，违反"AI 自主判断"原则）
        'tool_choice' => 'auto',

        // ★ v4.8 (2026-06-20): 日期格式配置化（严禁硬编码）
        //   api.php 用此 format 调用 date() 替换 {DATE} 占位符
        //   修改这里即可调整日期显示格式
        'date_format' => 'Y-m-d l H:i',

        // ★ v4.7 (2026-06-20): system prompt 配置化（严禁硬编码）
        // ★ v4.8 (2026-06-20): 增加 {DATE} 占位符 + 引导 Kimi 输出 query_count
        //   引导式 prompt：告诉模型什么时候该搜、什么时候不该搜
        //   修改这里即可调整 Kimi 搜索行为，无需改 api.php 代码
        //   {DATE} 会被 api.php 用 date_format 替换
        // ★ v4.9.4 (2026-06-20): 删除模板里残留的【当前日期】块
        //   根因：v4.9 dateHint 模板（"当前日期：{DATE}"）已统一为全模型日期注入入口。
        //   之前此模板仍带【当前日期】{DATE} 块 + api.php:1688 去重判断用的是
        //   `strpos('【当前日期】')`，因 v4.9 模板用"当前日期："（中文冒号、无方括号）不匹配，
        //   导致 webSearchHint 整体被追加，system prompt 中出现两份日期块（格式不同）。
        //   LLM 看到两份不同格式的日期块 + v4.9 模板的"严禁复述"+"照搬示例"矛盾指令时
        //   倾向输出两次（重复 bug 在 Kimi 模型上 100% 复现）。
        //   修复：从此处删除【当前日期】块，日期统一由 system_prompt_date_injection 注入。
        'web_search_system_prompt' => "\n\n【联网搜索能力】\n你可以使用 web_search 工具搜索互联网获取实时信息。\n\n请按以下原则判断是否需要调用：\n- ✅ 需要搜索：用户问新闻、股价、天气、今天/最近/最新发生的事、你不知道的事实、要求\"搜一下/查一下\"的内容\n- ❌ 不需要搜索：问候、闲聊、常识问题、数学计算、编程问题、翻译、你能直接回答的内容\n\n对于不需要搜索的问题，直接回答，不要调用工具。\n对于需要搜索的问题，先调用工具再基于结果用简洁自然的中文给出最终答案。\n\n【搜索参数】\n调用 web_search 时，根据问题复杂度决定 query_count（并行搜索数量）：\n- query_count=4：简单明确的问题（如\"今天有什么新闻\"、\"Python 教程\"）\n- query_count=6：稍复杂的问题（如\"AI 最新发展\"、\"Vibe Coding 是什么\"）\n- query_count=8：复杂或不确定的问题（如开放式话题、多角度分析）\n系统会自动基于你的 query 扩展为对应数量的变体并行搜索。",

        // Moonshot 内置 $web_search 工具声明（用于 moonshot_search_backend）
        'builtin_tool' => [
            'type' => 'builtin_function',
            'function' => [
                'name' => '$web_search',
            ],
        ],

        // 自定义 Function Calling web_search 工具（用于 function_calling_search_backend）
        // ★ v4.8 (2026-06-20): 增加 query_count 字段（引导 Kimi 评估复杂度）
        'function_calling_tool' => [
            'type' => 'function',
            'function' => [
                'name' => 'web_search',
                'description' => '联网搜索互联网信息。系统会自动将你提供的 query 扩展为 4-8 个变体并行搜索。请根据问题复杂度通过 query_count 参数指定并行搜索数量。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => '核心搜索关键词（系统会自动扩展为多个变体并行搜索）',
                        ],
                        'query_count' => [
                            'type' => 'integer',
                            'description' => '期望并行搜索的数量（4-8），系统会基于此生成对应数量的变体查询',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ],

        // 自有 Python 搜索服务（function_calling_search_backend 走这里）
        'search_api_url' => rtrim($python_service_domain, '/'),
        'search_api_timeout' => 30,
        'search_api_connect_timeout' => 10,
    ],

    // ==================== 全模型注入当前日期 ====================
    // ★ v4.9 (2026-06-20): 全模型注入当前日期（与 kimi_web_search 联动）
    //   之前只有 kimi 走联网搜索时会注入"当前日期"，现在所有模型（kimi / minmax / glm / deepseek）
    //   在非排除模式（写作/编程/研究/普通/Agent/Work）下都会注入当前日期。
    //   排除模式：图片生成、视频生成、音乐生成、文言文翻译、翻译（这 5 类无意义且走独立端点）。
    //   template 必须含 {DATE} 占位符，api.php 会在请求时用 date(date_format) 替换。
    //   date_format 与 kimi_web_search.date_format 保持完全一致，避免后端出现两种日期格式。
    'system_prompt_date_injection' => [
        'enabled' => true,
        // ★ v4.9.4 (2026-06-20): 简化为中性"系统时间"陈述
        //   根因：v4.9.2 模板用"严禁复述/翻译/重新格式化"+"用户问起时直接照搬"+"例如「今天是{REPLY_DATE}。」"
        //   是矛盾指令 + 强示例锚点，Kimi 等模型会把"照搬示例"理解为"必须输出示例格式"，
        //   再叠加 system prompt 出现两份日期块的 bug → 100% 输出两遍。
        //   v4.9.4 改为纯陈述："系统当前时间：{DATE}"，不给指令、不给示例，让模型按自己的自然格式回答日期。
        'date_format' => 'Y年n月j日 l H:i',
        'template' => "\n\n系统当前时间：{DATE}",
    ],

    // 各模型最大token限制配置
    // 键为模型名称，值为最大token数，null表示不限制
    'model_max_tokens' => [
        'deepseek-v4-flash' => 393216,
        'deepseek-v4-pro' => 393216,
        'kimi-k2.5' => 256000,
        'kimi-k2.6' => 256000,
        'moonshot-v1-8k' => 8192,
        'moonshot-v1-32k' => 32768,
        'moonshot-v1-128k' => 131072,
        'moonshot-v1-8k-vision-preview' => 8192,
        'moonshot-v1-32k-vision-preview' => 32768,
        'moonshot-v1-128k-vision-preview' => 131072,
        'MiniMax-M2.7' => 204800,
        'MiniMax-M2.7-highspeed' => 204800,
        'MiniMax-M2.5' => 204800,
        'MiniMax-M2.5-highspeed' => 204800,
        'MiniMax-M2.1' => 204800,
        'MiniMax-M2.1-highspeed' => 204800,
        'MiniMax-M2' => 204800,
        'MiniMax-M3' => 1048576,
        'GLM-4.5-Air' => 98304,
        'GLM-4.7-Flash' => 131072,
        'GLM-4.6V-Flash' => 32768,
        'GLM-4.1V-Thinking-Flash' => 16384,
        'GLM-4-Flash-250414' => 16384,
        'GLM-4-Flash' => 16384,
    ],
    
    // ==================== 温度配置 ====================
    // DeepSeek 动态 temperature 配置（基于使用场景）
    'deepseek_temperature' => [
        'enabled' => true,
        'default' => 0.7,
        'scenes' => [
            'coding' => [
                'temperature' => 0.0,
                'keywords' => ['代码', '编程', '程序', '开发', 'bug', 'debug', '函数', '类', '接口', 'python', 'javascript', 'java', 'php', 'sql', 'html', 'css', 'react', 'vue', 'node', 'typescript', 'api', '算法', '实现', '编写', '脚本', '编译', '运行', '部署', '框架', '库', '组件', '模块'],
            ],
            'math' => [
                'temperature' => 0.0,
                'keywords' => ['数学', '计算', '证明', '方程', '函数', '积分', '微分', '概率', '统计', '几何', '代数', '求解', '推导', '公式', '定理'],
            ],
            'data_extraction' => [
                'temperature' => 1.0,
                'keywords' => ['提取', '抽取', '解析', '抓取', '采集', '导出', '整理', '分类', '标注', '识别', '检测', '筛选', '匹配', '比对', '转换格式', '结构化'],
            ],
            'translation' => [
                'temperature' => 1.3,
                'keywords' => ['翻译', 'translate', '英文', '中文', '日语', '韩语', '法语', '德语', '俄语', '西班牙语', '译成', '翻成', '互译'],
            ],
            'creative' => [
                'temperature' => 1.5,
                'keywords' => ['创意', '写作', '文章', '小说', '故事', '诗歌', '诗', '文案', '润色', '改写', '散文', '剧本', '歌词', '对联', '续写', '创作', '想象', '幻想', '童话'],
            ],
        ],
    ],

    // DeepSeek 深度思考模式 stop 参数（可选，未设置则默认 ["\n\n\n\n"]）
    // 'deepseek_stop' => ["\n\n\n\n"],

    // ==================== Code-Agent 模型配置 ====================
    // Code-Agent 使用的 DeepSeek 模型，强制启用深度思考
    'code_agent_models' => [
        'fast' => 'deepseek-v4-flash',   // 简单代码任务（单文件、小函数）
        'deep' => 'deepseek-v4-pro',     // 复杂代码任务（项目级、多文件、重构）
    ],

    // ==================== DeepSeek penalty 配置 ====================
    // ★ v4.10 (2026-06-20): 抑制 self-repetition 倾向
    //   默认值从 0.0 调整为 0.3/0.4，经实测可显著降低 deepseek-v4-flash 的"重复整段输出"行为
    //   frequency_penalty: 惩罚已出现过的 token 频率
    //   presence_penalty:  惩罚已出现过的 token 主题
    'deepseek_penalty' => [
        'enabled' => true,
        'default' => [
            'frequency_penalty' => 0.3,
            'presence_penalty' => 0.4,
        ],
        'scenes' => [
            'creative' => [
                'frequency_penalty' => 0.2,
                'presence_penalty' => 0.3,
            ],
            'coding' => [
                'frequency_penalty' => 0.0,
                'presence_penalty' => 0.0,
            ],
            'math' => [
                'frequency_penalty' => 0.0,
                'presence_penalty' => 0.0,
            ],
        ],
    ],

    // 邮箱配置
    'smtp_host' => env('SMTP_HOST') ?: 'smtp.qq.com',
    'smtp_port' => (int)(env('SMTP_PORT') ?: 587),
    'smtp_user' => env('SMTP_USER') ?: '',
    'smtp_pass' => env('SMTP_PASS') ?: '',
    'email_from_name' => 'AI Assistant', // 发送名称

    // 数据库配置
    'db_host' => env('DB_HOST') ?: 'localhost',
    'db_port' => (int)(env('DB_PORT') ?: 3306),
    'db_name' => env('DB_NAME') ?: 'ai_system',
    'db_user' => env('DB_USER') ?: '',
    'db_pass' => env('DB_PASS') ?: '',

    // 注意：jwt_secret 实际由 admin/config.php 提供（Auth.php 读的是 admin config）
    // 这里不再重复声明，避免双源不一致

    // Token 限制配置
    // ULID白名单用户token限制（留空或设为null则不限制）
    'max_tokens_ulid' => null,
    // ULID白名单列表，填入ULID则该用户无限制
    'ulid_whitelist' => [],
    
    // 深度思考 + 联网搜索配置
    // 启用后，深度思考模式会先进行思考分析，然后自动进行联网搜索，最后综合生成回答
    'enable_deep_thinking_with_search' => true, // 是否启用深度思考+联网搜索组合功能
    'search_model' => 'moonshot-v1-32k', // 用于联网搜索的模型（需要支持 tools 参数）
    
    // 简单问题关键词列表 - 如果用户消息包含这些关键词，跳过联网搜索，直接使用深度思考回答
    // 这样可以避免"你好"、"谢谢"等简单问候也触发耗时的联网搜索流程
    'simple_queries' => [
        '你好', '您好', '嗨', '哈喽', 'hello', 'hi', 'hey',
        '谢谢', '感谢', '多谢', 'thanks', 'thank',
        '再见', '拜拜', 'bye', 'goodbye',
        '嗯', '哦', '啊', '好', '是的', '没错', '对的',
        '在吗', '在不在', '有人吗',
        '早上好', '下午好', '晚上好', '晚安'
    ],
    
    // 音乐功能配置
    'music' => [
        'default_domain' => rtrim(get_api_domain_config('main_api_domain', ''), '/'),
        // 预取详情数量（对需二次取链的源，最多预取 N 首详情以筛选可播放）
        'prefetch_count' => 8,
        // 最终返回最多可播放歌曲数
        'return_count' => 6,
        // 单源 HTTP 请求超时（秒）
        'timeout' => 15,
        // 详情响应中可能存放真实播放 URL 的字段（按优先级尝试，避免硬编码单一字段）
        'detail_link_fields' => [
            'url', 'mp3Url', 'songUrl', 'music', 'src', 'playUrl', 'downloadUrl',
            'audioHttpsUrl', 'raw.audioHttpsUrl', 'raw.url', 'link',
        ],
        // 多源在线音乐配置（按优先级排序，逐源故障转移）
        // 每源字段：url=基础地址, search_param=搜索参数名, detail_param=详情序号参数名,
        //           fields=响应字段映射（artist 支持 # 表示嵌套数组取子字段）,
        //           list_path=歌曲列表在响应 JSON 中的路径（如 'data'；空字符串表示根为数组）,
        //           link_mode=direct(link字段直接可播放) | detail(需二次调 detail_param 取链)
        'online_sources' => [
            'netease' => [
                'name' => '网易云音乐',
                'url' => 'https://oiapi.net/API/Music_163',
                'search_param' => 'name',
                'detail_param' => 'n',
                'fields' => ['name' => 'name', 'artist' => 'singers#name', 'pic' => 'picurl', 'link' => 'url'],
                'list_path' => 'data',
                'link_mode' => 'detail',
            ],
            'qq' => [
                'name' => 'QQ音乐',
                'url' => 'https://oiapi.net/API/QQ_Music/',
                'search_param' => 'msg',
                'detail_param' => 'n',
                'fields' => ['name' => 'song', 'artist' => 'singer', 'pic' => 'picture', 'link' => 'music'],
                'list_path' => 'data',
                'link_mode' => 'direct',
            ],
            'kuwo' => [
                'name' => '酷我音乐',
                'url' => 'https://api.xingzhige.com/API/Kuwo_BD_new/',
                'search_param' => 'name',
                'detail_param' => 'n',
                'fields' => ['name' => 'songname', 'artist' => 'name', 'pic' => 'cover', 'link' => 'src'],
                'list_path' => 'data',
                'link_mode' => 'detail',
            ],
            'kugou' => [
                'name' => '酷狗音乐',
                'url' => 'https://api.xingzhige.com/API/Kugou_GN_new/',
                'search_param' => 'name',
                'detail_param' => 'n',
                'fields' => ['name' => 'songname', 'artist' => 'name', 'pic' => 'cover', 'link' => 'src'],
                'list_path' => 'data',
                'link_mode' => 'detail',
            ],
        ],
    ],
    
    // 语音播报配置
    'voice_config' => [
        'enabled' => true,                      // 是否启用语音播报
        'use_browser_tts' => false,            // 是否使用浏览器原生TTS（false则使用MinMax TTS）
        'lang' => 'zh-CN',                     // 语言设置
        'rate' => 1.0,                         // 语速 (0.1 - 10)
        'pitch' => 1.0,                        // 音调 (0 - 2)
        'volume' => 1.0,                       // 音量 (0 - 1)
        'voice_name' => "Microsoft Huihui",     // 浏览器TTS音色名称
        // MinMax语音合成配置
        'minimax' => [
            'enabled' => true,                  // 是否启用MinMax TTS
            'api_key' => env('MINMAX_TTS_API_KEY') ?: '',  // MinMax API Key
            'api_url' => env('MINMAX_TTS_API_URL') ?: 'https://api.minimaxi.com/v1/t2a_v2',
            'voice_id' => env('MINMAX_TTS_VOICE_ID') ?: 'Moonya_yueyaxuan',
            'model' => env('MINMAX_TTS_MODEL') ?: 'speech-2.8-hd',
            'connect_timeout_seconds' => max(1, (int)(env('MINMAX_TTS_CONNECT_TIMEOUT_SECONDS') ?: 10)),
            'timeout_seconds' => max(1, (int)(env('MINMAX_TTS_TIMEOUT_SECONDS') ?: 90)),
            'need_noise_reduction' => true,     // 是否需要降噪
            'language_boost' => 'auto',          // 语言增强
        ],
    ],
    //社区配置
    'community' => [
        'enabled' => true,
        'posts_per_page' => 20,
        'comments_per_page' => 20,
        'max_title_length' => 100,
        'max_content_length' => 100000,
        'max_images_per_post' => 3,
        'max_image_size' => 3 * 1024 * 1024,
        'allowed_image_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'video' => [
            'max_size' => 100 * 1024 * 1024,
            'max_per_post' => 1,
            'allowed_types' => ['mp4', 'webm', 'mov', 'avi', 'mkv'],
            'allowed_mime_types' => ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'],
            'cover_max_width' => 800,
            'cover_quality' => 80,
            'cover_format' => 'jpeg',
            'storage_path' => 'uploads/community/videos/',
            'cover_storage_path' => 'uploads/community/covers/',
        ],
        'video_processing' => [
            'enabled' => true,
            'ffmpeg_path' => env('COMMUNITY_FFMPEG_PATH') ?: 'ffmpeg',
            'ffprobe_path' => env('COMMUNITY_FFPROBE_PATH') ?: 'ffprobe',
            'binary_candidate_directories' => array_values(array_filter(array_map(
                'trim',
                explode(PATH_SEPARATOR, env('COMMUNITY_MEDIA_BINARY_DIRECTORIES') ?: '')
            ))),
            'input_formats' => ['mp4', 'avi', 'mov', 'flv', 'wmv', 'mkv', 'webm', '3gp', 'ts', 'mts'],
            'max_resolution' => [
                'width' => 1920,
                'height' => 1080,
            ],
            'hls' => [
                'segment_duration' => 1,
                'video_codec' => 'libx264',
                'audio_codec' => 'aac',
                'video_bitrate' => '1200k',
                'audio_bitrate' => '64k',
                'preset' => 'veryfast',
                'crf' => 23,
            ],
            'output_path' => 'uploads/community/hls/',
            'delete_original' => false,
            'log_enabled' => true,
        ],
        'max_external_videos_per_post' => 4,
        'default_follow_accounts' => [],
        'colors' => [
            'primary' => '#6B92F2',
            'secondary' => '#F28AB2',
            'background' => '#F5F7FA',
            'card_background' => '#FFFFFF',
            'text_primary' => '#333333',
            'text_secondary' => '#999999',
        ],
        'share_card_font_path' => env('COMMUNITY_SHARE_CARD_FONT_PATH') ?: __DIR__ . '/community/font/qaddin_medium.otf',
    ],
    'yx_video' => [
        'enabled' => true,
        'require_login' => false,
        'api_whitelist' => [
            'jszyapi.com', 'ffzyapi.com', 'tiankongapi.com',
            'lovedan.net', 'apibdzy.com', 'feisuzy.com',
            'kczyapi.com', 'moduapi.cc', 'guangsuapi.com',
            'ukuapi.com', 'wolongzy.cc',
        ],
        'api_timeout' => 10,
        'api_connect_timeout' => 5,
        'default_avatar_url' => env('YX_VIDEO_DEFAULT_AVATAR_URL') ?: '/YX_VIDEO/image/tx.png',
        'account_avatar_url_template' => env('YX_VIDEO_ACCOUNT_AVATAR_URL_TEMPLATE') ?: 'https://q1.qlogo.cn/g?b=qq&nk={account}&s=640',
        'font_css_url' => env('YX_VIDEO_FONT_CSS_URL') ?: 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
        'hls_js_url' => env('YX_VIDEO_HLS_JS_URL') ?: 'https://cdn.jsdelivr.net/npm/hls.js@latest/dist/hls.min.js',
        'sources' => [
            '极速' => 'https://jszyapi.com/api.php/provide/vod/?ac=detail&pg=1&wd=',
            '非凡' => 'https://cj.ffzyapi.com/api.php/provide/vod/?ac=detail&pg=1&wd=',
            '天空' => 'https://m3u8.tiankongapi.com/api.php/provide/vod/?ac=detail&pg=1&wd=',
            '爱旦' => 'https://lovedan.net/api.php/provide/vod/?ac=detail&pg=1&wd=',
            '百度' => 'https://api.apibdzy.com/api.php/provide/vod/?ac=detail&pg=1&wd=',
            '飞速' => 'https://www.feisuzy.com/api.php/provide/vod/?ac=detail&pg=1&wd=',
            '快车' => 'https://caiji.kczyapi.com/api.php/provide/vod/?ac=detail&pg=1&wd=',
            '魔都' => 'https://caiji.moduapi.cc/api.php/provide/vod/?ac=detail&pg=1&wd=',
            '樱花' => 'https://caiji.kczyapi.com/api.php/provide/vod/?ac=detail&pg=1&wd=',
            '光速' => 'https://api.guangsuapi.com/api.php/provide/vod/?ac=detail&pg=1&wd=',
            'U酷' => 'https://api.ukuapi.com/api.php/provide/vod/?ac=detail&pg=1&wd=',
            '卧龙' => 'https://collect.wolongzy.cc/api.php/provide/vod/?ac=detail&pg=1&wd=',
        ],
        'cors_proxies' => [
            'https://api.codetabs.com/v1/proxy?quest=',
            'https://api.allorigins.win/raw?url=',
            'https://corsproxy.io/?',
            'https://cors.eu.org/',
            'https://cors-anywhere.herokuapp.com/',
        ],
        'placeholder_image_url_template' => env('YX_VIDEO_PLACEHOLDER_IMAGE_URL_TEMPLATE') ?: 'https://picsum.photos/seed/{seed}/{width}/{height}',
    ],
    'horoscope' => [
        'enabled' => true,
        'api_url' => 'https://yunzhiapi.cn/API/xzyspd.php',
        'api_token' => env('YUNZHI_API_TOKEN') ?: '',
    ],
    'weather' => [
        'enabled' => true,
        'api_url' => 'https://yunzhiapi.cn/API/zxtqsk.php',
        'api_token' => env('YUNZHI_API_TOKEN') ?: '',
        'ip_location_apis' => [
            'https://ip.useragentinfo.com/json',
            'https://api.ip.sb/geoip',
            'https://ipapi.co/json/',
            'https://ipinfo.io/json',
        ],
        'default_timezone' => 'UTC+8',
    ],

    // ==================== 文件下载配置 ====================
    'download' => [
        'storage_path' => __DIR__ . '/downloads/',           // 文件存储根路径
        'temp_path' => __DIR__ . '/downloads/temp/',         // 临时文件路径
        'max_file_size' => 524288000,                        // 最大单文件下载大小（字节，默认500MB）
        'max_concurrent' => 5,                               // 最大并发下载数
        'rate_limit_kbps' => 0,                              // 单连接下载速率限制（KB/s，0=不限制）
        'allowed_extensions' => [                            // 允许下载的文件类型扩展名列表
            // 文档类
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
            'md', 'epub', 'mobi', 'log', 'rtf', 'odt', 'ods', 'odp',
            // 图片类
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'tiff', 'avif', 'apng',
            // 音视频类
            'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma',
            'mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv',
            // 压缩包类
            'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz',
            // 代码类
            'py', 'js', 'ts', 'jsx', 'tsx', 'html', 'css', 'scss', 'less',
            'java', 'c', 'cpp', 'h', 'cs', 'go', 'rs', 'rb', 'php',
            'swift', 'kt', 'dart', 'lua', 'sh', 'bat', 'ps1',
            'json', 'xml', 'yaml', 'yml', 'ini', 'conf', 'toml',
            'sql', 'r', 'm', 'mm', 'pl', 'pm',
        ],
        'allowed_mime_types' => [                            // 允许的 MIME 类型列表
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv', 'text/markdown',
            'text/html', 'text/css', 'text/javascript',
            'application/json', 'application/xml', 'text/xml',
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
            'image/svg+xml', 'image/avif', 'image/apng', 'image/tiff',
            'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/flac', 'audio/aac',
            'video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo',
            'video/x-matroska', 'video/x-flv', 'video/x-ms-wmv',
            'application/zip', 'application/x-rar-compressed',
            'application/x-7z-compressed', 'application/gzip',
            'application/x-tar', 'application/x-bzip2', 'application/x-xz',
        ],
        'temp_file_ttl' => 3600,                              // 临时文件保留时间（秒，默认1小时）
        'temp_cleanup_interval' => 1800,                      // 临时文件清理间隔（秒，默认30分钟）
        'enable_permission_check' => true,                    // 是否启用下载权限校验
        'permission_levels' => [                              // 权限级别映射
            'admin' => ['all'],                               // 管理员：所有类型
            'user' => ['image', 'document', 'archive', 'code', 'audio', 'video'],
            'guest' => ['image', 'document'],                 // 访客：仅图片和文档
        ],
        'permission_categories' => [                          // 扩展名到类别的映射
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'tiff', 'avif', 'apng'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'md', 'epub', 'mobi', 'rtf', 'odt', 'ods', 'odp'],
            'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz'],
            'code' => ['py', 'js', 'ts', 'jsx', 'tsx', 'html', 'css', 'scss', 'less', 'java', 'c', 'cpp', 'h', 'cs', 'go', 'rs', 'rb', 'php', 'swift', 'kt', 'dart', 'lua', 'sh', 'bat', 'ps1', 'json', 'xml', 'yaml', 'yml', 'ini', 'conf', 'toml', 'sql', 'r', 'm', 'mm', 'pl', 'pm'],
            'audio' => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'],
            'video' => ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv'],
        ],
        'log_enabled' => true,                                // 是否启用下载日志
        'log_path' => __DIR__ . '/admin/logs/',               // 日志路径
        'enable_monitoring' => true,                          // 是否启用下载监控
        'monitor_alert_threshold' => 0.3,                     // 失败率告警阈值（30%）
        'monitor_window_minutes' => 5,                        // 监控滚动窗口（分钟）
        'sandbox_timeout' => 300,                             // 沙箱下载超时时间（秒）
    ],

    // ==================== 爬虫服务配置 ====================
    'crawler_api_url' => rtrim($python_service_domain, '/'),
    // 爬虫输出目录 = {软件安装目录}/crawler_output（由 C# 应用通过环境变量传入）
    'crawler_output_dir' => rtrim(env('MOONYA_CRAWLER_DATA_DIR') ?: dirname(__DIR__, 4), '/\\') . '/crawler_output',

    // ==================== 搜索服务配置 ====================
    // 实际生效的配置已统一到 kimi_web_search.search_api_url
    // 保留此 key 作为向后兼容（api.php 旧代码会读取），新代码请读 kimi_web_search
    'search_api_url' => rtrim($python_service_domain, '/'),
    // 兼容老配置：旧版 kimi_function_calling.tools/models/model 字段
    // 新代码应使用 kimi_web_search.function_calling_tool / supported_models / primary_model
    'kimi_function_calling' => [
        'models' => ['kimi-k2.5', 'kimi-k2.6'],
        'tools' => [], // 由 kimi_web_search.function_calling_tool 提供
    ],

    // ==================== Agent 模式配置 ====================
    'agent_mode' => require __DIR__ . '/agent_config.php',

    // ==================== 阿里云 ASR 语音识别配置 ====================

    'aliyun_asr' => [
        'api_key'         => env('ALIYUN_ASR_API_KEY') ?: '',  // 百炼平台 API-Key
        'model'           => env('ALIYUN_ASR_MODEL') ?: 'fun-asr',
        'fallback_models' => array_values(array_filter(array_map('trim', explode(',', env('ALIYUN_ASR_FALLBACK_MODELS') ?: 'fun-asr,paraformer-v2,paraformer-v1,fun-asr-mtl')))),
        'realtime_models' => array_values(array_filter(array_map('trim', explode(',', env('ALIYUN_ASR_REALTIME_MODELS') ?: 'fun-asr-realtime,paraformer-realtime-v2,paraformer-realtime-v1')))),
        'websocket_url' => env('ALIYUN_ASR_WEBSOCKET_URL') ?: 'wss://dashscope.aliyuncs.com/api-ws/v1/inference/',
        'upload_policy_url' => env('ALIYUN_ASR_UPLOAD_POLICY_URL') ?: 'https://dashscope.aliyuncs.com/api/v1/uploads',
        'transcription_url' => env('ALIYUN_ASR_TRANSCRIPTION_URL') ?: 'https://dashscope.aliyuncs.com/api/v1/services/audio/asr/transcription',
        'task_url_template' => env('ALIYUN_ASR_TASK_URL_TEMPLATE') ?: 'https://dashscope.aliyuncs.com/api/v1/tasks/{task_id}',
        'max_wait_seconds' => max(1, (int)(env('ALIYUN_ASR_MAX_WAIT_SECONDS') ?: 30)),
        'poll_interval_microseconds' => max(1000, (int)(env('ALIYUN_ASR_POLL_INTERVAL_MICROSECONDS') ?: 500000)),
        'connect_timeout_seconds' => max(1, (int)(env('ALIYUN_ASR_CONNECT_TIMEOUT_SECONDS') ?: 5)),
        'request_timeout_seconds' => max(1, (int)(env('ALIYUN_ASR_REQUEST_TIMEOUT_SECONDS') ?: 20)),
        'upload_timeout_seconds' => max(1, (int)(env('ALIYUN_ASR_UPLOAD_TIMEOUT_SECONDS') ?: 60)),
        'result_timeout_seconds' => max(1, (int)(env('ALIYUN_ASR_RESULT_TIMEOUT_SECONDS') ?: 20)),
        'region'          => 'cn-beijing',                     // 地域
        'enable_vad'      => true,                             // 静音检测
        'enable_interim'  => false,                            // 是否返回中间结果
        'sample_rate'     => 16000,                            // 采样率
        'language_hints'  => ['zh', 'en'],                     // 语言提示
        // 以下字段保留兼容性（设计方案中提到，但实际使用 api_key 即可）
        'access_key_id'     => '',
        'access_key_secret' => '',
        'app_key'           => '',
    ],

];
