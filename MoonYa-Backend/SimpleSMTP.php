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
    private $socket = null;
    
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
            echo "[SimpleSMTP] $msg\n";
        }
    }
    
    /**
     * 读取一行 SMTP 响应
     */
    private function readLine() {
        $line = fgets($this->socket);
        if ($line === false) {
            throw new \Exception("SMTP连接已断开");
        }
        return $line;
    }
    
    /**
     * 读取 SMTP 响应（支持多行），返回状态码和完整消息
     */
    private function readResponse($expectedCode = null) {
        $lines = [];
        $code = null;
        
        while (true) {
            $line = $this->readLine();
            $lines[] = $line;
            $this->debug("< " . rtrim($line));
            
            if ($code === null) {
                $code = (int)substr($line, 0, 3);
            }
            
            // 多行响应末尾是以空格分隔的，如 "250 OK"
            if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
                break;
            }
        }
        
        $message = implode('', $lines);
        
        if ($expectedCode !== null && $code !== $expectedCode) {
            throw new \Exception("SMTP错误 [{$code}]: " . rtrim($message));
        }
        
        return ['code' => $code, 'message' => rtrim($message)];
    }
    
    /**
     * 发送 SMTP 命令
     */
    private function sendCommand($cmd, $expectedCode = null) {
        $this->debug("> $cmd");
        fwrite($this->socket, $cmd . "\r\n");
        if ($expectedCode !== null) {
            return $this->readResponse($expectedCode);
        }
        return $this->readResponse();
    }
    
    public function send($to, $subject, $body) {
        $this->debug("Sending mail to: $to");
        
        try {
            // 1. 连接 SMTP 服务器
            $this->debug("Connecting to {$this->host}:{$this->port}...");
            $this->socket = @stream_socket_client(
                "tcp://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                $this->timeout
            );
            
            if (!$this->socket) {
                throw new \Exception("无法连接SMTP服务器 {$this->host}:{$this->port}（{$errstr}）");
            }
            
            stream_set_timeout($this->socket, $this->timeout);
            
            // 2. 读取服务器欢迎信息
            $this->readResponse(220);
            
            // 3. EHLO
            $ehloResp = $this->sendCommand("EHLO localhost", 250);
            
            // 4. STARTTLS
            $this->sendCommand("STARTTLS", 220);
            
            // 5. 升级到 TLS
            $this->debug("Upgrading to TLS...");
            $cryptoResult = @stream_socket_enable_crypto(
                $this->socket, 
                true, 
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            if (!$cryptoResult) {
                throw new \Exception("TLS 加密升级失败");
            }
            $this->debug("TLS upgrade successful");
            
            // 6. 重新 EHLO (TLS 后必须重新 EHLO)
            $this->sendCommand("EHLO localhost", 250);
            
            // 7. AUTH LOGIN
            $authResp = $this->sendCommand("AUTH LOGIN");
            if ($authResp['code'] !== 334) {
                throw new \Exception("SMTP服务器不支持AUTH LOGIN");
            }
            
            // 8. 发送用户名 (base64)
            $userResp = $this->sendCommand(base64_encode($this->username));
            if ($userResp['code'] !== 334) {
                throw new \Exception("SMTP认证失败（用户名错误）");
            }
            
            // 9. 发送密码 (base64)
            $passResp = $this->sendCommand(base64_encode($this->password));
            if ($passResp['code'] !== 235) {
                throw new \Exception("SMTP认证失败，请检查邮箱账号和密码");
            }
            
            // 10. MAIL FROM
            $this->sendCommand("MAIL FROM:<{$this->from}>", 250);
            
            // 11. RCPT TO
            $this->sendCommand("RCPT TO:<{$to}>", 250);
            
            // 12. DATA
            $this->sendCommand("DATA", 354);
            
            // 13. 发送邮件内容
            $subjectEncoded = "=?UTF-8?B?" . base64_encode($subject) . "?=";
            $mailContent = "From: {$this->fromName} <{$this->from}>\r\n"
                         . "To: <{$to}>\r\n"
                         . "Subject: {$subjectEncoded}\r\n"
                         . "MIME-Version: 1.0\r\n"
                         . "Content-Type: text/plain; charset=utf-8\r\n"
                         . "Content-Transfer-Encoding: base64\r\n"
                         . "\r\n"
                         . chunk_split(base64_encode($body));
            
            $this->debug("> (sending email body, " . strlen($mailContent) . " bytes)");
            fwrite($this->socket, $mailContent . "\r\n.\r\n");
            $this->readResponse(250);
            
            // 14. QUIT
            $this->sendCommand("QUIT", 221);
            
            fclose($this->socket);
            $this->debug("Mail sent successfully");
            return true;
            
        } catch (\Exception $e) {
            if ($this->socket) {
                @fclose($this->socket);
            }
            throw $e;
        }
    }
}
