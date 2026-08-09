<?php

class SimpleSMTP {
    private $host;
    private $port;
    private $username;
    private $password;
    private $from;
    private $fromName;
    private $timeout = 10;
    private $debug = false;
    
    public function __construct($host, $port, $username, $password, $from, $fromName = '') {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->from = $from;
        $this->fromName = $fromName ?: $from;
    }
    
    public function setDebug($debug) {
        $this->debug = $debug;
    }
    
    public function setTimeout($timeout) {
        $this->timeout = $timeout;
    }
    
    private function debug($msg) {
        if ($this->debug) {
            error_log('[SimpleSMTP] ' . $msg);
        }
    }
    
    public function send($to, $subject, $body) {
        $headers = "From: {$this->fromName} <{$this->from}>\r\n";
        $headers .= "To: <{$to}>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";
        
        $rawBody = $headers . "\r\n" . chunk_split(base64_encode($body));
        
        $this->debug("Sending mail to: $to, size: " . strlen($rawBody) . " bytes");
        
        $ch = curl_init();
        
        $url = "smtp://{$this->host}:{$this->port}";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
        
        curl_setopt($ch, CURLOPT_MAIL_FROM, "<{$this->from}>");
        curl_setopt($ch, CURLOPT_MAIL_RCPT, array("<{$to}>"));
        
        curl_setopt($ch, CURLOPT_USERNAME, $this->username);
        curl_setopt($ch, CURLOPT_PASSWORD, $this->password);
        
        curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_STARTTLS);
        
        $bodyStream = fopen('php://temp', 'r+');
        if (!$bodyStream) {
            throw new Exception('无法创建临时数据流');
        }
        fwrite($bodyStream, $rawBody);
        rewind($bodyStream);
        
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $bodyStream);
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($rawBody));
        
        curl_setopt($ch, CURLOPT_VERBOSE, $this->debug);
        if ($this->debug) {
            curl_setopt($ch, CURLOPT_STDERR, fopen('php://stderr', 'w'));
        }
        
        $this->debug("Connecting to SMTP via cURL: $url");
        
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        
        fclose($bodyStream);
        curl_close($ch);
        
        if ($result === false) {
            $msg = "SMTP发送失败";
            if ($errno === CURLE_COULDNT_CONNECT || $errno === CURLE_COULDNT_RESOLVE_HOST) {
                $msg = "无法连接SMTP服务器 {$this->host}:{$this->port}，请检查服务器防火墙是否放行该端口";
            } elseif ($errno === CURLE_OPERATION_TIMEDOUT) {
                $msg = "连接SMTP服务器超时，请检查服务器网络";
            } elseif ($errno === CURLE_LOGIN_DENIED) {
                $msg = "SMTP认证失败，请检查邮箱账号和密码";
            }
            if ($error) {
                $msg .= " (" . $error . ")";
            }
            throw new Exception($msg);
        }
        
        $this->debug("Mail sent successfully");
        return true;
    }
}
