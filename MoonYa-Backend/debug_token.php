<?php
/**
 * Token验证调试脚本
 * 
 * 用于调试和测试token验证逻辑
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Token验证调试</h1>";
echo "<hr>";

// 1. 检查所有请求头
echo "<h2>1. 请求头信息</h2>";
echo "<pre>";

$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    echo "使用 getallheaders() 获取:\n";
    print_r($headers);
} else {
    echo "getallheaders() 函数不可用\n";
}

echo "\n使用 $_SERVER 获取:\n";
foreach ($_SERVER as $key => $value) {
    if (strpos(strtolower($key), 'auth') !== false || 
        strpos(strtolower($key), 'token') !== false ||
        strpos(strtolower($key), 'header') !== false) {
        echo "$key: $value\n";
    }
}
echo "</pre>";

// 2. 提取token
echo "<h2>2. Token提取</h2>";
$token = null;
$tokenSource = '';

// 方法1: 从Authorization头提取
if (isset($headers['Authorization'])) {
    $authHeader = $headers['Authorization'];
    $token = str_replace('Bearer ', '', $authHeader);
    $tokenSource = 'Authorization头 (getallheaders)';
    echo "<p>✅ 从Authorization头获取到token</p>";
} elseif (isset($headers['authorization'])) {
    $authHeader = $headers['authorization'];
    $token = str_replace('Bearer ', '', $authHeader);
    $tokenSource = 'authorization头 (小写, getallheaders)';
    echo "<p>✅ 从authorization头(小写)获取到token</p>";
}
// 方法2: 从$_SERVER提取
elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    $token = str_replace('Bearer ', '', $authHeader);
    $tokenSource = 'HTTP_AUTHORIZATION ($_SERVER)';
    echo "<p>✅ 从HTTP_AUTHORIZATION获取到token</p>";
} elseif (isset($_SERVER['Authorization'])) {
    $authHeader = $_SERVER['Authorization'];
    $token = str_replace('Bearer ', '', $authHeader);
    $tokenSource = 'Authorization ($_SERVER)';
    echo "<p>✅ 从Authorization获取到token</p>";
}
// 方法3: 从X-API-Key提取
elseif (isset($headers['X-API-Key'])) {
    $token = $headers['X-API-Key'];
    $tokenSource = 'X-API-Key头';
    echo "<p>✅ 从X-API-Key头获取到token</p>";
} elseif (isset($_SERVER['HTTP_X_API_KEY'])) {
    $token = $_SERVER['HTTP_X_API_KEY'];
    $tokenSource = 'HTTP_X_API_KEY';
    echo "<p>✅ 从HTTP_X_API_KEY获取到token</p>";
}
// 方法4: 从请求参数提取
elseif (isset($_GET['token'])) {
    $token = $_GET['token'];
    $tokenSource = 'URL参数token';
    echo "<p>✅ 从URL参数获取到token</p>";
}

if ($token) {
    echo "<p><strong>Token来源:</strong> $tokenSource</p>";
    echo "<p><strong>Token值:</strong> " . htmlspecialchars(substr($token, 0, 50)) . "...</p>";
    echo "<p><strong>Token长度:</strong> " . strlen($token) . " 字符</p>";
} else {
    echo "<p>❌ <strong>未找到token</strong></p>";
}

// 3. 验证token
echo "<h2>3. Token验证</h2>";

if (!$token) {
    echo "<p>❌ 没有token可供验证</p>";
} else {
    try {
        $config = require_once __DIR__ . '/config.php';
        
        $pdo = new PDO(
            "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        
        echo "<p>✅ 数据库连接成功</p>";
        
        // 查询token
        $stmt = $pdo->prepare("SELECT id, username, email, real_name, avatar, api_token, token_created_at FROM users WHERE api_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            echo "<p>✅ <strong>Token验证成功!</strong></p>";
            echo "<p>用户信息:</p>";
            echo "<pre>";
            print_r([
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'real_name' => $user['real_name'],
                'token_created_at' => $user['token_created_at']
            ]);
            echo "</pre>";
            
            // 检查token是否过期
            if ($user['token_created_at']) {
                $createdTime = strtotime($user['token_created_at']);
                $currentTime = time();
                $expiresIn = 1296000; // 15天
                $elapsed = $currentTime - $createdTime;
                $remaining = $expiresIn - $elapsed;
                
                echo "<p>Token创建时间: " . $user['token_created_at'] . "</p>";
                echo "<p>已使用时间: " . round($elapsed / 86400, 2) . " 天</p>";
                echo "<p>剩余时间: " . round($remaining / 86400, 2) . " 天</p>";
                
                if ($remaining <= 0) {
                    echo "<p>⚠️ <strong>Token已过期!</strong></p>";
                } else {
                    echo "<p>✅ Token有效</p>";
                }
            }
        } else {
            echo "<p>❌ <strong>Token验证失败:</strong> 数据库中找不到该token</p>";
            
            // 显示数据库中前5个token示例（用于调试）
            $stmt = $pdo->query("SELECT id, username, api_token FROM users WHERE api_token IS NOT NULL LIMIT 5");
            $tokens = $stmt->fetchAll();
            
            if ($tokens) {
                echo "<p>数据库中存在的token示例:</p>";
                echo "<pre>";
                foreach ($tokens as $t) {
                    echo "用户: " . $t['username'] . ", Token前20位: " . substr($t['api_token'], 0, 20) . "...\n";
                }
                echo "</pre>";
            } else {
                echo "<p>⚠️ 数据库中没有任何token记录</p>";
            }
        }
        
    } catch (PDOException $e) {
        echo "<p>❌ 数据库错误: " . htmlspecialchars($e->getMessage()) . "</p>";
    } catch (Exception $e) {
        echo "<p>❌ 错误: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// 4. 测试建议
echo "<hr>";
echo "<h2>4. 测试建议</h2>";
echo "<p>使用以下cURL命令测试token验证:</p>";
echo "<pre>";
echo "# 使用Authorization头\n";
echo "curl -X GET http://localhost:8002/debug_token.php \\\n";
echo "  -H \"Authorization: Bearer YOUR_TOKEN_HERE\"\n\n";
echo "# 使用X-API-Key头\n";
echo "curl -X GET http://localhost:8002/debug_token.php \\\n";
echo "  -H \"X-API-Key: YOUR_TOKEN_HERE\"\n\n";
echo "# 使用URL参数\n";
echo "curl -X GET \"http://localhost:8002/debug_token.php?token=YOUR_TOKEN_HERE\"\n";
echo "</pre>";

echo "<hr>";
echo "<h2>5. 在线测试</h2>";
echo "<p>输入你的token，点击测试按钮:</p>";
echo "<input type='text' id='testToken' placeholder='输入token' style='width:400px;padding:5px;'>";
echo "<button onclick='testToken()' style='padding:5px 15px;margin-left:10px;'>测试Token</button>";
echo "<div id='testResult' style='margin-top:10px;padding:10px;background:#f0f0f0;'></div>";

echo "<script>";
echo "function testToken() {";
echo "  var token = document.getElementById('testToken').value;";
echo "  if(!token) { alert('请输入token'); return; }";
echo "  document.getElementById('testResult').innerHTML = '测试中...';";
echo "  fetch('debug_token.php', {";
echo "    method: 'POST',";
echo "    headers: {";
echo "      'Content-Type': 'application/json',";
echo "      'Authorization': 'Bearer ' + token";
echo "    }";
echo "  })";
echo "  .then(r => r.text())";
echo "  .then(text => {";
echo "    document.getElementById('testResult').innerHTML = '<pre>' + text.replace(/</g, '&lt;') + '</pre>';";
echo "  })";
echo "  .catch(e => {";
echo "    document.getElementById('testResult').innerHTML = '错误: ' + e;";
echo "  });";
echo "}";
echo "</script>";

echo "<hr>";
echo "<p><strong>注意:</strong> 将此脚本部署到服务器后，使用上述cURL命令测试token验证逻辑。</p>";
?>
