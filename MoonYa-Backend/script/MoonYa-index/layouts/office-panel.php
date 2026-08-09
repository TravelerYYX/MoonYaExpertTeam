        <!-- 办公室 2.5D 视图（主界面内切换 / 独立窗口共用） -->
        <?php
        // 9 个工位：row-major，MoonYa 固定左上角 (0,0)
        $officeAgents = [
            [
                'key' => 'moonya', 'name' => 'MoonYa', 'title' => '团队负责人', 'row' => 0, 'col' => 0,
                'summary' => '负责任务分解、团队委派与最终结果综合。',
                'skills' => ['分派任务', '汇总结果', '团队协作'],
                'avatar' => '/assets/agents/moonya.png',
            ],
            [
                'key' => 'image', 'name' => 'Image Agent', 'title' => '图像生成', 'row' => 0, 'col' => 1,
                'summary' => '负责图像生成以及图片和视频内容理解。',
                'skills' => ['图片理解', '视频分析', '文字识别'],
                'avatar' => '/assets/office/image.png',
            ],
            [
                'key' => 'search', 'name' => 'Search Agent', 'title' => '搜索检索', 'row' => 0, 'col' => 2,
                'summary' => '负责联网搜索、多来源调研和资料溯源。',
                'skills' => ['联网搜索', '多源调研', '来源抓取'],
                'avatar' => '/assets/agents/search-agent.png',
            ],
            [
                'key' => 'file', 'name' => 'File Agent', 'title' => '文件管理', 'row' => 1, 'col' => 0,
                'summary' => '处理文件、目录、内容编辑和 Office 产物。',
                'skills' => ['文件处理', '内容编辑', 'Office 文档'],
                'avatar' => '/assets/agents/file-agent.png',
            ],
            [
                'key' => 'voice', 'name' => 'Voice Agent', 'title' => '语音交互', 'row' => 1, 'col' => 1,
                'summary' => '负责语音输入、语音播报和实时语音交互。',
                'skills' => ['语音识别', '语音播报', '实时交互'],
                'avatar' => '/assets/office/voice.png',
            ],
            [
                'key' => 'app', 'name' => 'App Agent', 'title' => '应用操作', 'row' => 1, 'col' => 2,
                'summary' => '负责应用检测、安装、卸载、打开与关闭。',
                'skills' => ['应用检测', '安装卸载', '启动关闭'],
                'avatar' => '/assets/agents/app-agent.png',
            ],
            [
                'key' => 'browser', 'name' => 'Browser Agent', 'title' => '网页浏览', 'row' => 2, 'col' => 0,
                'summary' => '完成网页浏览、页面交互和浏览器自动化。',
                'skills' => ['网页浏览', '页面交互', '浏览器自动化'],
                'avatar' => '/assets/agents/browser-agent.png',
            ],
            [
                'key' => 'code', 'name' => 'Code Agent', 'title' => '代码开发', 'row' => 2, 'col' => 1,
                'summary' => '负责代码浏览、编辑、执行和项目分析。',
                'skills' => ['代码开发', '项目分析', '运行测试'],
                'avatar' => '/assets/agents/code-agent.png',
            ],
            [
                'key' => 'computer', 'name' => 'Computer Agent', 'title' => '电脑控制', 'row' => 2, 'col' => 2,
                'summary' => '读取系统状态并操作已经打开的桌面界面。',
                'skills' => ['系统状态', '桌面操作', '操作电脑', '结果验证'],
                'avatar' => '/assets/agents/computer-agent.png',
            ],
        ];
        ?>
        <div class="office-view" id="officeView">
            <div class="office-header">
                <div class="office-title">MoonYa 办公室</div>
                <button type="button" class="office-popout-btn" id="officePopoutBtn">单独弹出</button>
            </div>
            <div class="office-stage" id="officeStage">
                <div class="office-floor"></div>
                <?php foreach ($officeAgents as $oa): ?>
                <div class="workstation<?php echo $oa['key'] === 'moonya' ? ' ws-active' : ''; ?>" data-agent="<?php echo $oa['key']; ?>"
                    data-row="<?php echo $oa['row']; ?>" data-col="<?php echo $oa['col']; ?>"
                    data-profile-name="<?php echo htmlspecialchars($oa['name'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-profile-title="<?php echo htmlspecialchars($oa['title'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-profile-summary="<?php echo htmlspecialchars($oa['summary'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-profile-skills="<?php echo htmlspecialchars(implode('|', $oa['skills']), ENT_QUOTES, 'UTF-8'); ?>"
                    data-profile-avatar="<?php echo htmlspecialchars($oa['avatar'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-profile-avatar-fallback="/assets/office/<?php echo $oa['key']; ?>.png">
                    <div class="ws-shadow"></div>
                    <div class="ws-mover">
                        <div class="ws-name"><?php echo htmlspecialchars($oa['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <button type="button" class="ws-character-trigger"
                                aria-label="查看 <?php echo htmlspecialchars($oa['name'], ENT_QUOTES, 'UTF-8'); ?> 的资料"
                                aria-haspopup="dialog" aria-controls="officeAgentCard" aria-expanded="false">
                            <img class="ws-character" src="/assets/office/seated-back/<?php echo $oa['key']; ?>.png"
                                 alt="" draggable="false">
                        </button>
                        <img class="ws-empty-chair" src="/assets/office/seated-back/empty-chair.png"
                             alt="" aria-hidden="true" draggable="false">
                    </div>
                    <div class="ws-desk">
                        <div class="ws-desk-front"></div>
                    </div>
                    <div class="ws-desk-top"></div>
                    <div class="ws-monitor">
                        <div class="ws-monitor-frame">
                            <div class="ws-screen"></div>
                        </div>
                        <div class="ws-monitor-stand"></div>
                        <div class="ws-monitor-base"></div>
                    </div>
                    <div class="ws-work-hands" aria-hidden="true">
                        <span class="ws-keyboard"></span>
                        <span class="ws-mouse"></span>
                        <span class="ws-hand ws-hand-keyboard"></span>
                        <span class="ws-hand ws-hand-mouse"></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <!-- MoonYa 行走精灵（派发任务时从左上角工位走出） -->
                <div class="office-walker" id="officeWalker">
                    <div class="office-walker-name">MoonYa</div>
                    <img src="/assets/office/moonya.png" alt="MoonYa" draggable="false">
                </div>
                <div class="office-bubble" id="officeBubble"></div>
                <section class="office-agent-card" id="officeAgentCard" role="dialog" aria-modal="false"
                         aria-labelledby="officeAgentCardName" hidden>
                    <button type="button" class="office-agent-card-close" aria-label="关闭人物资料">×</button>
                    <div class="office-agent-card-header">
                        <img class="office-agent-avatar" id="officeAgentCardAvatar" src="" alt="">
                        <div class="office-agent-identity">
                            <h2 class="office-agent-name" id="officeAgentCardName"></h2>
                            <div class="office-agent-title" id="officeAgentCardTitle"></div>
                            <div class="office-agent-status" id="officeAgentCardStatus">
                                <span class="office-agent-status-dot" aria-hidden="true"></span>
                                <span class="office-agent-status-text">空闲中</span>
                            </div>
                        </div>
                    </div>
                    <div class="office-agent-card-divider"></div>
                    <div class="office-agent-section">
                        <div class="office-agent-section-label">简介：</div>
                        <p class="office-agent-summary" id="officeAgentCardSummary"></p>
                    </div>
                    <div class="office-agent-section office-agent-skills-section">
                        <div class="office-agent-section-label">技能：</div>
                        <div class="office-agent-skills" id="officeAgentCardSkills"></div>
                    </div>
                </section>
            </div>
        </div>
