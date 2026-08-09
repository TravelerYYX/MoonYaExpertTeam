<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录</title>
    <link rel="stylesheet" href="assets/admin-ui.css">
    <style>
        .login-page {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }

        .form-input.error {
            border-color: var(--danger);
        }

        .form-input.error:focus {
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }

        .debug-info {
            margin-top: 16px;
            padding: 12px;
            background: var(--border-light);
            border-radius: var(--radius-sm);
            font-size: 12px;
            color: var(--text-secondary);
            display: none;
            white-space: pre-wrap;
        }
    </style>
</head>
<body class="admin-body login-page">
    <div class="login-card">
        <div class="login-header">
            <h1>管理员登录</h1>
            <p>用户管理系统</p>
        </div>

        <div id="alert" class="alert"></div>

        <form id="loginForm">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" class="form-input" placeholder="请输入用户名" value="yueyaxuan">
                <div id="usernameError" class="error-message">请输入用户名</div>
            </div>

            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" class="form-input" placeholder="请输入密码" autocomplete="current-password">
                <div id="passwordError" class="error-message">请输入密码</div>
            </div>

            <button type="submit" id="loginBtn" class="btn-primary btn-block">登录</button>
        </form>

        <div id="debugInfo" class="debug-info"></div>
    </div>

    <script>
        const loginForm = document.getElementById('loginForm');
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');
        const loginBtn = document.getElementById('loginBtn');
        const alertDiv = document.getElementById('alert');
        const usernameError = document.getElementById('usernameError');
        const passwordError = document.getElementById('passwordError');
        const debugInfo = document.getElementById('debugInfo');

        function showDebug(message) {
            debugInfo.style.display = 'block';
            debugInfo.textContent += message + '\n';
        }

        function showAlert(type, message) {
            alertDiv.className = 'alert alert-' + type + ' show';
            alertDiv.textContent = message;
            setTimeout(() => {
                alertDiv.className = 'alert';
            }, 3000);
        }

        function showError(inputId, errorId) {
            const input = document.getElementById(inputId);
            const error = document.getElementById(errorId);
            input.classList.add('error');
            error.classList.add('show');
        }

        function hideError(inputId, errorId) {
            const input = document.getElementById(inputId);
            const error = document.getElementById(errorId);
            input.classList.remove('error');
            error.classList.remove('show');
        }

        function validate() {
            let isValid = true;

            if (!usernameInput.value.trim()) {
                showError('username', 'usernameError');
                isValid = false;
            } else {
                hideError('username', 'usernameError');
            }

            if (!passwordInput.value.trim()) {
                showError('password', 'passwordError');
                isValid = false;
            } else {
                hideError('password', 'passwordError');
            }

            return isValid;
        }

        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();

            if (!validate()) return;

            loginBtn.disabled = true;
            loginBtn.textContent = '登录中...';

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'api/users.php?action=login', true);
            xhr.setRequestHeader('Content-Type', 'application/json');

            xhr.onload = function() {
                loginBtn.disabled = false;
                loginBtn.textContent = '登录';

                showDebug('响应状态: ' + xhr.status);
                showDebug('响应内容: ' + xhr.responseText);

                try {
                    const data = JSON.parse(xhr.responseText);

                    if (data.success) {
                        localStorage.setItem('adminToken', data.data.token);
                        localStorage.setItem('adminInfo', JSON.stringify(data.data.admin));
                        showAlert('success', '登录成功！正在跳转...');
                        setTimeout(() => {
                            window.location.href = 'dashboard.php';
                        }, 1000);
                    } else {
                        showAlert('error', data.error || '登录失败');
                    }
                } catch (e) {
                    showAlert('error', '服务器响应错误: ' + e.message);
                }
            };

            xhr.onerror = function() {
                loginBtn.disabled = false;
                loginBtn.textContent = '登录';
                showAlert('error', '网络错误，请检查网络连接');
            };

            xhr.send(JSON.stringify({
                username: usernameInput.value.trim(),
                password: passwordInput.value.trim()
            }));
        });
    </script>
</body>
</html>
