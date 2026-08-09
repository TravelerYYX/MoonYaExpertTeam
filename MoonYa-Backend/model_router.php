<?php
/**
 * 专精模式 - 模型路由系统
 * 智能分析用户请求，路由到最适合的专家模型
 */

// 加载配置
$config = require_once __DIR__ . '/config.php';

/**
 * 模型路由器类
 */
class ModelRouter {
    private $config;
    private $keywordCache = [];
    
    public function __construct($config) {
        $this->config = $config;
        $this->initKeywordCache();
    }
    
    /**
     * 初始化关键词缓存
     */
    private function initKeywordCache() {
        $keywords = $this->config['specialist_keywords'] ?? [];
        foreach ($keywords as $keyword => $model) {
            $this->keywordCache[mb_strtolower($keyword, 'UTF-8')] = $model;
        }
    }
    
    /**
     * 轻量级关键词路由
     * 通过分析prompt中的关键词进行快速路由
     * 
     * @param string $message 用户输入
     * @return array|null 路由结果，包含model和reason
     */
    public function keywordRoute($message) {
        if (empty($message)) {
            return null;
        }
        
        $messageLower = mb_strtolower($message, 'UTF-8');
        $modelScores = [];
        $allMatchedKeywords = [];
        
        // 统计每个模型的匹配次数
        foreach ($this->keywordCache as $keyword => $model) {
            if (mb_strpos($messageLower, $keyword) !== false) {
                if (!isset($modelScores[$model])) {
                    $modelScores[$model] = [
                        'count' => 0,
                        'keywords' => []
                    ];
                }
                $modelScores[$model]['count']++;
                $modelScores[$model]['keywords'][] = $keyword;
                $allMatchedKeywords[] = $keyword;
            }
        }
        
        if (empty($modelScores)) {
            return null;
        }
        
        // 选择匹配次数最多的模型
        $bestModel = null;
        $bestScore = 0;
        $matchedKeywords = [];
        
        foreach ($modelScores as $model => $data) {
            if ($data['count'] > $bestScore) {
                $bestScore = $data['count'];
                $bestModel = $model;
                $matchedKeywords = $data['keywords'];
            }
        }
        
        if ($bestModel) {
            $modelDesc = $this->config['model_descriptions'][$bestModel] ?? null;
            
            // 分析用户意图
            $intent = $this->analyzeIntent($matchedKeywords);
            
            // 构建竖列流程格式的回复 - 简洁风格
            // 输入内容显示完整文本，不做截断
            $inputPreview = $message;
            $keywordsStr = implode('、', array_slice($matchedKeywords, 0, 5)) . 
                          (count($matchedKeywords) > 5 ? ' 等' . count($matchedKeywords) . '个关键词' : '');
            
            $formattedResponse = "1. 分析请求\n";
            $formattedResponse .= "识别用户意图：{$intent}\n";
            $formattedResponse .= "2. 分析prompt中的关键词\n";
            $formattedResponse .= "{$keywordsStr}\n";
            $formattedResponse .= "3. 分析用户输入\n";
            $formattedResponse .= "输入内容：{$inputPreview}\n";
            $formattedResponse .= "匹配模型：" . ($modelDesc['name'] ?? $bestModel) . "\n";
            $formattedResponse .= "置信度：" . round(min($bestScore * 0.3, 0.9) * 100, 0) . '%';
            
            return [
                'model' => $bestModel,
                'name' => $modelDesc['name'] ?? $bestModel,
                'method' => 'keyword',
                'confidence' => min($bestScore * 0.3, 0.9),
                'reason' => $formattedResponse,
                'matched_keywords' => $matchedKeywords,
                'all_keywords' => $allMatchedKeywords,
                'intent_analysis' => $intent,
                'analysis_details' => [
                    'input_preview' => mb_substr($message, 0, 50) . (mb_strlen($message) > 50 ? '...' : ''),
                    'keyword_count' => count($allMatchedKeywords),
                    'matched_models' => array_keys($modelScores),
                    'routing_method' => '关键词匹配路由'
                ]
            ];
        }
        
        return null;
    }
    
    /**
     * 分析用户意图
     * 
     * @param array $keywords 匹配到的关键词
     * @return string 意图描述
     */
    private function analyzeIntent($keywords) {
        $intentMap = [
            // 编程意图
            '代码' => '编程开发', '编程' => '编程开发', '程序' => '编程开发', '开发' => '编程开发',
            'bug' => '编程开发', 'debug' => '编程开发', '算法' => '编程开发', '函数' => '编程开发',
            '类' => '编程开发', '接口' => '编程开发', 'python' => '编程开发', 'javascript' => '编程开发',
            'java' => '编程开发', 'php' => '编程开发', 'sql' => '编程开发', 'html' => '编程开发',
            'css' => '编程开发', 'react' => '编程开发', 'vue' => '编程开发', 'node' => '编程开发',
            
            // 创意写作意图
            '创意' => '创意写作', '写作' => '创意写作', '文章' => '创意写作', '小说' => '创意写作',
            '故事' => '创意写作', '诗歌' => '创意写作', '文案' => '创意写作', '润色' => '创意写作',
            '改写' => '创意写作',
            
            // 翻译意图
            '翻译' => '翻译转换', '英文' => '翻译转换', '英语' => '翻译转换', '中文' => '翻译转换',
            '日语' => '翻译转换', '韩语' => '翻译转换', '法语' => '翻译转换', '德语' => '翻译转换',
            '文言文' => '翻译转换',
            
            // 深度分析意图
            '研究' => '深度分析', '分析' => '深度分析', '深度' => '深度分析', '推理' => '深度分析',
            '逻辑' => '深度分析', '数学' => '深度分析', '计算' => '深度分析', '证明' => '深度分析',
            
            // 长文本处理意图
            '总结' => '长文本处理', '摘要' => '长文本处理', '长文' => '长文本处理', '报告' => '长文本处理',
            '论文' => '长文本处理'
        ];
        
        $intents = [];
        foreach ($keywords as $keyword) {
            if (isset($intentMap[$keyword]) && !in_array($intentMap[$keyword], $intents)) {
                $intents[] = $intentMap[$keyword];
            }
        }
        
        if (empty($intents)) {
            return '通用问答';
        }
        
        return implode(' + ', $intents);
    }
    
    /**
     * LLM路由器
     * 使用低成本的Kimi模型分析用户输入，返回推荐使用的模型
     * 
     * @param string $message 用户输入
     * @return array|null 路由结果
     */
    public function llmRoute($message) {
        $routerConfig = $this->config['llm_router'] ?? null;
        
        if (!$routerConfig || !($routerConfig['enabled'] ?? false)) {
            return null;
        }
        
        $modelDescriptions = $this->config['model_descriptions'] ?? [];
        
        // 构建模型描述文本
        $modelInfoText = "";
        foreach ($modelDescriptions as $modelId => $info) {
            $modelInfoText .= "- {$info['name']} ($modelId): {$info['description']}\n";
            $modelInfoText .= "  擅长: " . implode('、', $info['strengths']) . "\n";
        }
        
        $systemPrompt = "你是一个智能模型路由助手。请分析用户的请求，并从以下模型中选择最适合的一个来回答：\n\n{$modelInfoText}\n\n请分析用户的意图，并返回JSON格式的结果。JSON格式如下：\n{\n  \"model\": \"模型ID\",\n  \"reason\": \"选择原因（简短说明）\",\n  \"intent\": \"用户意图分析\",\n  \"keywords\": [\"关键词1\", \"关键词2\"]\n}";
        
        $requestData = [
            'model' => $routerConfig['model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $message]
            ],
            'temperature' => $routerConfig['temperature'] ?? 0.3,
            'max_tokens' => 200,
            'stream' => false
        ];
        
        $ch = curl_init($routerConfig['api_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->config['api_key']
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $routerConfig['timeout'] ?? 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($response)) {
            return null;
        }
        
        $result = json_decode($response, true);
        $content = $result['choices'][0]['message']['content'] ?? '';
        
        // 解析JSON响应
        if (preg_match('/\{[^}]+\}/s', $content, $matches)) {
            $jsonStr = $matches[0];
            $routeData = json_decode($jsonStr, true);
            
            if ($routeData && isset($routeData['model'])) {
                $modelId = $routeData['model'];
                $modelDesc = $this->config['model_descriptions'][$modelId] ?? null;
                $intent = $routeData['intent'] ?? '智能分析';
                $keywords = $routeData['keywords'] ?? [];
                
                // 构建竖列流程格式的回复 - 简洁风格
                // 输入内容显示完整文本，不做截断
                $inputPreview = $message;
                $keywordsStr = !empty($keywords) ? implode('、', array_slice($keywords, 0, 5)) : '通过语义分析识别需求';
                
                $formattedResponse = "1. 分析请求\n";
                $formattedResponse .= "识别用户意图：{$intent}\n";
                $formattedResponse .= "2. 分析prompt中的关键词\n";
                $formattedResponse .= "{$keywordsStr}\n";
                $formattedResponse .= "3. 分析用户输入\n";
                $formattedResponse .= "输入内容：{$inputPreview}\n";
                $formattedResponse .= "匹配模型：" . ($modelDesc['name'] ?? $modelId) . "\n";
                $formattedResponse .= "置信度：85%";
                
                return [
                    'model' => $modelId,
                    'name' => $modelDesc['name'] ?? $modelId,
                    'method' => 'llm',
                    'confidence' => 0.85,
                    'reason' => $formattedResponse,
                    'intent_analysis' => $intent,
                    'extracted_keywords' => $keywords,
                    'analysis_details' => [
                        'input_preview' => mb_substr($message, 0, 50) . (mb_strlen($message) > 50 ? '...' : ''),
                        'routing_method' => 'LLM智能路由',
                        'model_reasoning' => $routeData['reason'] ?? ''
                    ]
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 智能路由 - 结合关键词路由和LLM路由
     * 优先使用关键词路由，如果置信度不够则使用LLM路由
     * 
     * @param string $message 用户输入
     * @return array 路由结果
     */
    public function route($message) {
        // 首先尝试关键词路由
        $keywordResult = $this->keywordRoute($message);
        
        if ($keywordResult && $keywordResult['confidence'] >= 0.6) {
            return $keywordResult;
        }
        
        // 关键词路由置信度不够，尝试LLM路由
        $llmResult = $this->llmRoute($message);
        
        if ($llmResult) {
            return $llmResult;
        }
        
        // 如果关键词路由有结果但置信度低，也返回它
        if ($keywordResult) {
            return $keywordResult;
        }
        
        // 默认使用通用模型
        // 构建竖列流程格式的回复 - 简洁风格
        // 输入内容显示完整文本，不做截断
        $inputPreview = $message;
        
        $formattedResponse = "1. 分析请求\n";
        $formattedResponse .= "识别用户意图：通用问答\n";
        $formattedResponse .= "2. 分析prompt中的关键词\n";
        $formattedResponse .= "未检测到特定领域关键词\n";
        $formattedResponse .= "3. 分析用户输入\n";
        $formattedResponse .= "输入内容：{$inputPreview}\n";
        $formattedResponse .= "匹配模型：Moonshot 32K Vision\n";
        $formattedResponse .= "置信度：50%（默认模型）";
        
        return [
            'model' => $this->config['model_normal'],
            'name' => 'Moonshot 32K Vision',
            'method' => 'default',
            'confidence' => 0.5,
            'reason' => $formattedResponse,
            'intent_analysis' => '通用问答',
            'analysis_details' => [
                'input_preview' => mb_substr($message, 0, 50) . (mb_strlen($message) > 50 ? '...' : ''),
                'routing_method' => '默认路由',
                'note' => '未匹配到特定关键词，使用通用模型'
            ]
        ];
    }
    
    /**
     * 获取模型配置
     * 
     * @param string $modelId 模型ID
     * @return array 模型配置
     */
    public function getModelConfig($modelId) {
        $modelDesc = $this->config['model_descriptions'][$modelId] ?? null;
        $capabilities = $this->config['model_capabilities'][$modelId] ?? null;
        if (!is_array($capabilities)) {
            throw new InvalidArgumentException("Model is missing from model_capabilities: {$modelId}");
        }
        $provider = (string)($capabilities['provider'] ?? '');
        $route = $this->config['model_provider_routes'][$provider] ?? null;
        if (!is_array($route)) {
            throw new RuntimeException("Missing model provider route: {$provider}");
        }
        $urlKey = (string)($route['url_key'] ?? '');
        $apiKeyKey = (string)($route['api_key_key'] ?? '');
        $apiUrl = trim((string)($this->config[$urlKey] ?? ''));
        $apiKey = trim((string)($this->config[$apiKeyKey] ?? ''));
        if ($apiUrl === '' || $apiKey === '') {
            throw new RuntimeException("Missing endpoint configuration for model provider: {$provider}");
        }
        return [
            'api_url' => $apiUrl,
            'api_key' => $apiKey,
            'model' => $modelId,
            'name' => $modelDesc['name'] ?? $modelId,
            'supports_images' => ($capabilities['supports_images'] ?? false) === true,
        ];
    }
}

/**
 * 便捷函数：路由用户请求
 * 
 * @param string $message 用户输入
 * @return array 路由结果
 */
function routeToModel($message) {
    global $config;
    $router = new ModelRouter($config);
    return $router->route($message);
}

/**
 * API端点：处理路由请求
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $message = $data['message'] ?? '';
    
    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['error' => '消息不能为空']);
        exit;
    }
    
    $router = new ModelRouter($config);
    $result = $router->route($message);
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    exit;
}
