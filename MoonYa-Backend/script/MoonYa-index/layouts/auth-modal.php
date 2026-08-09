            <!-- 登录注册弹窗 -->
            <div id="authOverlay" class="auth-overlay">
                <div class="auth-modal liquid-glass" id="authModalBox">
                    <div class="modal-glow" id="modalGlow"></div>
                    <button class="auth-close-btn" id="authCloseBtn">✕</button>
                    
                    <div class="auth-modal-left">
                        <div class="image-wrapper">
                            <img src="/image/bg.png" alt="background">
                            <div class="image-overlay"></div>
                        </div>
                        <div class="left-content">
                            <h2>MoonYa</h2>
                            <p>极简美学，温润通透</p>
                        </div>
                    </div>
                    
                    <div class="auth-modal-right">
                        <div class="auth-tabs">
                            <button class="auth-tab-btn active" data-tab="sms">验证码登录</button>
                            <button class="auth-tab-btn" data-tab="password">密码登录</button>
                            <button class="auth-tab-btn" data-tab="register">注册账号</button>
                        </div>
                        <div class="auth-form-container">
                            <form class="auth-form active" id="sms-form">
                                <div class="input-group">
                                    <label>QQ号</label>
                                    <div class="auth-qq-input-wrapper">
                                        <input type="text" id="loginQQ" placeholder="请输入QQ号" maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                        <span class="auth-qq-suffix">@qq.com</span>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <label>验证码</label>
                                    <div class="input-row">
                                        <input type="text" id="loginCode" placeholder="请输入验证码" maxlength="6">
                                        <button type="button" class="auth-btn-secondary" id="loginSendCodeBtn">获取验证码</button>
                                    </div>
                                </div>
                                <button type="button" class="auth-btn-primary" id="loginByCodeBtn">登录</button>
                            </form>
                            
                            <form class="auth-form" id="password-form">
                                <div class="input-group">
                                    <label>账号</label>
                                    <input type="text" id="loginAccount" placeholder="请输入账号">
                                </div>
                                <div class="input-group">
                                    <label>密码</label>
                                    <input type="password" id="loginPassword" placeholder="请输入密码">
                                </div>
                                <button type="button" class="auth-btn-primary" id="loginBtn">登录</button>
                            </form>
                            
                            <form class="auth-form" id="register-form">
                                <div class="input-group">
                                    <label>QQ号</label>
                                    <div class="auth-qq-input-wrapper">
                                        <input type="text" id="registerQQ" placeholder="请输入QQ号" maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                        <span class="auth-qq-suffix">@qq.com</span>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <label>验证码</label>
                                    <div class="input-row">
                                        <input type="text" id="registerCode" placeholder="请输入验证码" maxlength="6">
                                        <button type="button" class="auth-btn-secondary" id="sendCodeBtn">获取验证码</button>
                                    </div>
                                </div>
                                <button type="button" class="auth-btn-primary" id="registerBtn">注册</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
