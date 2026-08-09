</head>
<?php $officePopoutMode = isset($_GET['office_popout']) && $_GET['office_popout'] === '1'; ?>
<body class="work-mode office-active<?php echo $officePopoutMode ? ' office-popout-mode' : ''; ?>"
      data-office-popout="<?php echo $officePopoutMode ? '1' : '0'; ?>"
      data-authenticated="<?php echo isset($_SESSION['user_id']) ? '1' : '0'; ?>">
    <div class="container">
        <!-- 浮动按钮组（侧边栏收起时显示） -->
        <div class="floating-controls" id="floatingControls">
            <div class="floating-btn-group">
                <div class="floating-btn-small" id="expandSidebarBtn">
                    <img src="/image/zd.png" alt="展开">
                </div>
                <div class="floating-btn-large" id="newChatFloatingBtn">
                    <img src="/image/new_chat.svg" alt="新建对话">
                </div>
            </div>
        </div>
