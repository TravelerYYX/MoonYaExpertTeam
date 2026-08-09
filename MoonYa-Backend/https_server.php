<?php
// HTTPS 服务器配置
$host = '0.0.0.0';
$port = 8443;

// 生成临时 SSL 证书（如果不存在）
$sslCert = __DIR__ . '/ssl/cert.pem';
$sslKey = __DIR__ . '/ssl/key.pem';

if (!file_exists($sslCert) || !file_exists($sslKey)) {
    echo "生成 SSL 证书...\n";
    
    // 尝试使用 PHP 生成证书
    $config = "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\ncommonName = localhost\n";
    
    file_put_contents(__DIR__ . '/ssl/openssl.cnf', $config);
    
    // 使用 PHP 内置函数生成密钥和证书
    $privateKey = openssl_pkey_new(array(
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ));
    
    $csr = openssl_csr_new(array(
        'commonName' => 'localhost',
    ), $privateKey, array('digest_alg' => 'sha256'));
    
    $cert = openssl_csr_sign($csr, null, $privateKey, 365, array('digest_alg' => 'sha256'));
    
    openssl_pkey_export_to_file($privateKey, $sslKey);
    openssl_x509_export_to_file($cert, $sslCert);
    
    echo "SSL 证书生成完成！\n";
}

echo "启动 HTTPS 服务器在 https://localhost:$port\n";
echo "按 Ctrl+C 停止服务器\n";

// 启动 PHP 内置服务器
$command = "php -S $host:$port -t . -c php.ini";

// 注意：PHP 内置服务器在 Windows 上不支持 HTTPS
// 这里我们使用 HTTP，然后通过反向代理或其他方式实现 HTTPS
// 或者使用第三方工具如 ngrok

// 由于环境限制，这里启动 HTTP 服务器
$command = "php -S $host:8000 -t .";
echo "HTTP 服务器地址由启动参数或环境配置决定。\n";
echo "建议使用 ngrok 等工具来实现 HTTPS 访问\n";

exec($command);
?>
