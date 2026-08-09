        let uploadedImages = []; // 存储已上传的图片 { file_id, preview_url }
        // 用户显式选择的本机文件/文件夹。这里只保存路径，不读取或上传文件内容。
        let localPathSelections = [];
        let uploadingCount = 0; // 正在上传的数量        
        
        // 显示Toast提示
        function showToast(message, duration = 2000) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, duration);
        }
        
        // 获取对话历史（localStorage + 用户隔离）
        function getChatHistory() {
            const history = localStorage.getItem(CHAT_HISTORY_KEY);
            return history ? JSON.parse(history) : [];
        }
        
        // 保存对话历史
        function saveChatHistory(history) {
            localStorage.setItem(CHAT_HISTORY_KEY, JSON.stringify(history));
        }

        // 旧版共享消息包装器曾把真实 DOM 节点误传给 prependHtml，浏览器会把它
        // 序列化为 "[object HTMLDivElement]"。统一在展示、标题和再次保存前清理
        // 这个技术性污染；普通用户文本不做其他改写。
        function cleanAccidentalDomString(value) {
            if (value === null || typeof value === 'undefined') return '';
            if (typeof Node !== 'undefined' && value instanceof Node) {
                value = value.textContent || '';
            }
            return String(value).replace(/(?:\s*\[object HTMLDivElement\]\s*)+/g, '');
        }
        
        // 创建新对话（优先复用已存在的空对话，避免历史列表堆积空的「新对话」）
        async function createNewChat() {
            const history = getChatHistory();
            // 只复用没有 dbConversationId 的真正空对话（本地创建但未发送消息的）
            // 有 dbConversationId 但 messages:[] 的是从数据库同步的对话，数据库里可能有消息，不能复用
            const existingEmpty = history.find(chat => chat.messages.length === 0 && !chat.dbConversationId);
            if (existingEmpty) {
                currentChatId = existingEmpty.id;
                currentDbConversationId = null;
                if (typeof activateConversationRuntime === 'function') activateConversationRuntime(existingEmpty.id);
                renderChatList();
                return existingEmpty;
            }
            const newChat = {
                id: Date.now().toString(),
                title: '新对话',
                messages: [],
                createdAt: new Date().toISOString()
            };
            history.unshift(newChat);
            saveChatHistory(history);
            currentChatId = newChat.id;
            // 数据库对话ID延迟到首次保存消息时创建（见 saveMessagesToDatabase），避免空对话在数据库堆积
            currentDbConversationId = null;
            if (typeof activateConversationRuntime === 'function') activateConversationRuntime(newChat.id);
            renderChatList();
            return newChat;
        }
        
        // 共享 helper：调后端搜索接口，返回归一化音乐列表（后端已预取详情+过滤不可播+限制数量）
        // 返回值：{ music: [...], source: 'qq', sourceName: 'QQ音乐' } 或 null（失败时）
        window.fetchMusicSearchResult = async function(keyword) {
            const response = await fetch('music_api.php?action=search&name=' + encodeURIComponent(keyword));
            const data = await response.json();
            if (!data || !data.success || !data.data || !Array.isArray(data.data.music)) {
                return null;
            }
            return {
                music: data.data.music,
                source: data.data.source || '',
                sourceName: data.data.source_name || '',
            };
        };

        // 共享 helper：渲染音乐卡片 + 更新动态岛播放列表
        window.renderMusicResult = function(musicList, replyText) {
            addAIMessageWithHTML(replyText, buildMusicCardsHtml(musicList));
            if (typeof updateDynamicIslandPlaylist === 'function') {
                updateDynamicIslandPlaylist(musicList);
            }
            saveCurrentChat();
        };

        async function handleMusicRequest(message) {
            window.isSendingMessage = true;
            addMessage('user', message);
            saveCurrentChat();
            messageInput.value = '';

            document.querySelector('.main-title').style.display = 'none';
            const hotTopicsContainer = document.querySelector('.hot-topics-container');
            if (hotTopicsContainer) hotTopicsContainer.style.display = 'none';

            const loadingId = addLoadingIndicator();

            try {
                const keyword = MUSIC_RANDOM_KEYWORDS[Math.floor(Math.random() * MUSIC_RANDOM_KEYWORDS.length)];
                const result = await window.fetchMusicSearchResult(keyword);
                removeLoadingIndicator(loadingId);

                if (result && result.music.length > 0) {
                    window.renderMusicResult(result.music, '为您推荐以下音乐：');
                } else {
                    addMessage('ai', '抱歉，暂时没有找到可用的音乐推荐。请稍后再试或尝试搜索特定歌曲。');
                    saveCurrentChat();
                }
            } catch (error) {
                console.error('获取音乐失败:', error);
                removeLoadingIndicator(loadingId);
                addMessage('ai', '抱歉，获取音乐推荐时出错了，请稍后再试。');
                saveCurrentChat();
            }

            const musicBtn = document.getElementById('musicBtn');
            if (musicBtn) {
                musicBtn.classList.remove('active');
            }
            isMusicMode = false;
            window.isSendingMessage = false;
        }
        
        // 切换音乐播放状态
        // currentAudio 已在语音播报部分声明
        let currentPlayBtn = null;
        let currentMusicId = null;
        let progressInterval = null;
        
        function toggleMusicPlay(btn, musicUrl, musicId) {
            // 无有效 URL 时：尝试通过动态岛详情兜底取链（需歌曲在播放列表中）
            if (!musicUrl || !musicUrl.startsWith('http')) {
                if (typeof window.onIslandTrackPlay === 'function' && window.onIslandTrackPlay(musicUrl, musicId)) {
                    return;
                }
                showToast('该歌曲暂时无法播放，请试试其他歌曲');
                return;
            }
            // 如果点击的是当前正在播放的按钮
            if (currentAudio && currentPlayBtn === btn && !currentAudio.paused) {
                currentAudio.pause();
                window.currentAudio = currentAudio;
                btn.classList.remove('playing');
                // 清除进度条更新
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
                return;
            }
            
            // 停止之前播放的音乐
            if (currentAudio) {
                currentAudio.pause();
                currentAudio.currentTime = 0;
            }
            if (currentPlayBtn) {
                currentPlayBtn.classList.remove('playing');
                // 重置之前音乐的进度条
                if (currentMusicId) {
                    const prevProgress = document.getElementById(`progress-${currentMusicId}`);
                    if (prevProgress) {
                        prevProgress.style.strokeDashoffset = 126;
                    }
                }
            }
            // 清除之前的进度条更新
            if (progressInterval) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
            
            // 创建新的音频对象
            var safeUrl = musicUrl;
            if (safeUrl && safeUrl.indexOf('http://') === 0) { safeUrl = safeUrl.replace('http://', 'https://'); }
            currentAudio = new Audio(safeUrl);
            window.currentAudio = currentAudio;
            currentPlayBtn = btn;
            currentMusicId = musicId;
            
            // 获取进度条元素
            const progressCircle = document.getElementById(`progress-${musicId}`);
            
            // 播放音乐
            currentAudio.play().then(() => {
                btn.classList.add('playing');
                triggerIslandPulse();
                // 开始更新进度条
                if (progressCircle) {
                    progressInterval = setInterval(() => {
                        if (currentAudio && currentAudio.duration) {
                            const progress = currentAudio.currentTime / currentAudio.duration;
                            const circumference = 126; // 2 * PI * 20
                            const offset = circumference - (progress * circumference);
                            progressCircle.style.strokeDashoffset = offset;
                        }
                    }, 100);
                }
            }).catch(error => {
                console.error('播放音乐失败:', error);
                alert('播放音乐失败，请检查音乐文件是否可用');
            });
            
            // 音乐结束时重置按钮状态
            currentAudio.onended = function() {
                btn.classList.remove('playing');
                if (progressCircle) {
                    progressCircle.style.strokeDashoffset = 126;
                }
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
                currentAudio = null;
                window.currentAudio = null;
                currentPlayBtn = null;
                currentMusicId = null;
            };

            currentAudio.onerror = function() {
                btn.classList.remove('playing');
                if (progressCircle) {
                    progressCircle.style.strokeDashoffset = 126;
                }
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
                currentAudio = null;
                window.currentAudio = null;
                currentPlayBtn = null;
                currentMusicId = null;
                alert('音乐文件加载失败');
            };
        }
        
        // HTML转义函数
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function openMusicSearch() {
            if (typeof window.openIslandMusicSearch === 'function') {
                window.openIslandMusicSearch();
            }
        }

        const MUSIC_RANDOM_KEYWORDS = [
            '流行', '经典老歌', '民谣', '摇滚', '轻音乐', '古风',
            '抒情', '粤语', '英文', '日文', '韩语', '电子',
            '爵士', 'R&B', '说唱', '乡村', '蓝调', '治愈',
            '伤感', '欢快', '安静', '励志', '浪漫', '怀旧',
            '周杰伦', '林俊杰', '陈奕迅', '邓紫棋', '薛之谦',
            '毛不易', '李荣浩', '华晨宇', '张学友', '王菲',
            '孙燕姿', '蔡依林', '萧敬腾', '张韶涵', '梁静茹'
        ];

        function parseSongList(data) {
            if (!data) return [];
            // 后端归一化响应：{ success: true, data: { music: [...] } }
            if (data.success !== undefined) {
                if (!data.success || !data.data || !Array.isArray(data.data.music)) return [];
                return data.data.music;
            }
            if (data.name || data.songname) return [data];
            if (Array.isArray(data)) return data;
            if (data.data) {
                if (Array.isArray(data.data)) return data.data;
                if (typeof data.data === 'object' && (data.data.name || data.data.songname)) return [data.data];
            }
            const keys = ['songs', 'result', 'list', 'songList'];
            for (let key of keys) {
                if (data[key]) {
                    if (Array.isArray(data[key])) return data[key];
                    if (typeof data[key] === 'object' && (data[key].name || data[key].songname)) return [data[key]];
                }
            }
            // 不再把未知结构对象当成 1 首歌（修复错误响应被渲染成空 URL 卡片的 bug）
            return [];
        }

        function extractSongInfo(item) {
            const name = item.name || item.songname || '未知歌曲';
            let artist = '未知歌手';
            if (item.singers) {
                if (Array.isArray(item.singers)) {
                    artist = item.singers.map(s => s.name || s).filter(Boolean).join(' / ') || '未知歌手';
                } else if (typeof item.singers === 'string') {
                    artist = item.singers;
                }
            } else if (item.artist || item.artists || item.singer) {
                artist = item.artist || item.artists || item.singer;
            }
            let pic = item.picurl || item.picUrl || item.cover || (item.album && item.album.picUrl) || '';
            let url = item.url && item.url.startsWith('http') ? item.url : '';
            if (url && url.startsWith('http://')) { url = url.replace('http://', 'https://'); }
            if (pic && pic.startsWith('http://')) { pic = pic.replace('http://', 'https://'); }
            return { name, artist, pic, url, raw: item };
        }

        function extractArtistFromObj(songObj) {
            if (songObj.singers) {
                if (Array.isArray(songObj.singers)) {
                    return songObj.singers.map(s => s.name || s).filter(Boolean).join(' / ') || '未知歌手';
                } else if (typeof songObj.singers === 'string') {
                    return songObj.singers;
                }
            }
            return songObj.artist || songObj.artists || songObj.singer || '未知歌手';
        }

        function buildMusicCardsHtml(musicList) {
            let html = '<div class="music-card-container">';
            html += '<div class="music-header">';
            html += '<img class="music-header-logo" src="/icon.png" alt="MoonYa音乐" onerror="this.style.display=\'none\'">';
            html += '<span class="music-header-text">MoonYa音乐</span>';
            html += '</div>';
            musicList.forEach((music) => {
                const logoUrl = music.logo_url || '/image/music/music_tp.png';
                html += '<div class="music-card" data-music-id="' + music.id + '" data-music-url="' + (music.file_url || '') + '">';
                html += '<img class="music-logo" src="' + logoUrl + '" alt="' + escapeHtml(music.name) + '" onerror="this.src=\'/image/music/music_tp.png\'">';
                html += '<div class="music-info">';
                html += '<div class="music-name">' + escapeHtml(music.name) + '</div>';
                html += '<div class="music-artist">' + escapeHtml(music.artist) + '</div>';
                html += '</div>';
                html += '<div class="music-play-wrapper">';
                html += '<svg class="music-progress-ring" viewBox="0 0 44 44">';
                html += '<circle class="music-progress-ring-circle" cx="22" cy="22" r="20"></circle>';
                html += '<circle class="music-progress-ring-progress" cx="22" cy="22" r="20" id="progress-' + music.id + '"></circle>';
                html += '</svg>';
                html += '<button class="music-play-btn" onclick="toggleMusicPlay(this, \'' + (music.file_url || '') + '\', \'' + music.id + '\')">';
                html += '<span class="play-icon"></span>';
                html += '<span class="pause-icon"></span>';
                html += '</button>';
                html += '</div>';
                html += '</div>';
            });
            html += '<button class="music-search-btn" onclick="openMusicSearch()"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.35-4.35"></path></svg>搜索音乐</button>';
            html += '</div>';
            return html;
        }

        const HOROSCOPE_ZODIAC_SIGNS = [
            { name: '白羊座', emoji: '♈' },
            { name: '金牛座', emoji: '♉' },
            { name: '双子座', emoji: '♊' },
            { name: '巨蟹座', emoji: '♋' },
            { name: '狮子座', emoji: '♌' },
            { name: '处女座', emoji: '♍' },
            { name: '天秤座', emoji: '♎' },
            { name: '天蝎座', emoji: '♏' },
            { name: '射手座', emoji: '♐' },
            { name: '摩羯座', emoji: '♑' },
            { name: '水瓶座', emoji: '♒' },
            { name: '双鱼座', emoji: '♓' }
        ];

        function buildHoroscopeCardHtml() {
            const containerId = 'horoscope_' + Date.now();
            let html = '<div class="horoscope-card-container" id="' + containerId + '">';
            html += '<div class="horoscope-header">';
            html += '<img class="horoscope-header-logo" src="/icon.png" alt="MoonYa星座" onerror="this.style.display=\'none\'">';
            html += '<span class="horoscope-header-text">MooYa星座运势</span>';
            html += '</div>';
            html += '<div class="horoscope-zodiac-grid">';
            HOROSCOPE_ZODIAC_SIGNS.forEach(sign => {
                html += '<div class="horoscope-zodiac-item" onclick="selectHoroscopeZodiac(this, \'' + containerId + '\', \'' + sign.name + '\')">';
                html += '<span class="horoscope-zodiac-emoji">' + sign.emoji + '</span>';
                html += '<span class="horoscope-zodiac-name">' + sign.name + '</span>';
                html += '</div>';
            });
            html += '</div>';
            html += '<div class="horoscope-time-selector">';
            html += '<span class="horoscope-time-btn active" onclick="selectHoroscopeTime(this, \'' + containerId + '\', \'today\')">今日</span>';
            html += '<span class="horoscope-time-btn" onclick="selectHoroscopeTime(this, \'' + containerId + '\', \'week\')">本周</span>';
            html += '<span class="horoscope-time-btn" onclick="selectHoroscopeTime(this, \'' + containerId + '\', \'month\')">本月</span>';
            html += '<span class="horoscope-time-btn" onclick="selectHoroscopeTime(this, \'' + containerId + '\', \'year\')">今年</span>';
            html += '</div>';
            html += '<button class="horoscope-fetch-btn" onclick="fetchHoroscopeFortune(\'' + containerId + '\')" disabled>✨ 查看运势</button>';
            html += '<div class="horoscope-result" id="' + containerId + '_result"></div>';
            html += '</div>';
            return html;
        }

        function selectHoroscopeZodiac(el, containerId, signName) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.querySelectorAll('.horoscope-zodiac-item').forEach(item => {
                item.classList.remove('selected');
            });
            el.classList.add('selected');
            container.dataset.selectedSign = signName;
            const btn = container.querySelector('.horoscope-fetch-btn');
            if (btn) btn.disabled = false;
        }

        function selectHoroscopeTime(el, containerId, timePeriod) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.querySelectorAll('.horoscope-time-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            el.classList.add('active');
            container.dataset.selectedTime = timePeriod;
        }

        async function fetchHoroscopeFortune(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const signName = container.dataset.selectedSign;
            const timePeriod = container.dataset.selectedTime || 'today';
            if (!signName) return;

            const resultDiv = document.getElementById(containerId + '_result');
            if (!resultDiv) return;

            const btn = container.querySelector('.horoscope-fetch-btn');
            if (btn) {
                btn.disabled = true;
                btn.textContent = '查询中...';
            }

            resultDiv.innerHTML = '<div class="horoscope-loading"><div class="horoscope-loading-spinner"></div>正在查询星座运势...</div>';

            try {
                const params = new URLSearchParams();
                params.append('token', FEATURE_CONFIG.horoscope.apiToken);
                params.append('msg', signName);
                params.append('time', timePeriod);
                params.append('type', 'json');

                const url = FEATURE_CONFIG.horoscope.apiUrl + '?' + params.toString() + '&_t=' + Date.now();
                const response = await fetch(url, {
                    headers: { 'Accept': 'application/json' }
                });

                if (!response.ok) throw new Error('服务器错误 (' + response.status + ')');

                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    data = { general_fortune: text };
                }
                if (data.data) data = data.data;

                const sign = HOROSCOPE_ZODIAC_SIGNS.find(s => s.name === signName);
                const emoji = sign ? sign.emoji : '🔮';
                const timeLabels = { today: '今日', week: '本周', month: '本月', year: '今年' };
                const timeLabel = timeLabels[timePeriod] || timePeriod;

                let general = String(data.general_fortune || data.content || '暂无');
                const love = String(data.love_fortune || '暂无');
                const work = String(data.work_fortune || '暂无');
                const wealth = String(data.wealth_fortune || '暂无');
                const health = String(data.health_fortune || '暂无');
                const bestMatch = String(data.best_match || '');
                const luckyColor = String(data.lucky_colors || '');
                const luckyNumber = String(data.lucky_numbers || '');
                const luckyDirection = String(data.lucky_direction || '');

                // 提取综合评分
                let scoreNum = '';
                let scoreText = general;
                const scoreMatch = general.match(/综合评分[:：]\s*(\d+)(?:\s*\/\s*100)?/);
                if (scoreMatch) {
                    scoreNum = scoreMatch[1];
                    scoreText = general.replace(/综合评分[:：]\s*\d+(?:\s*\/\s*100)?/, '').trim();
                }

                let resultHtml = '<div class="horoscope-result-card">';

                // 头部：标题 + 评分徽章
                resultHtml += '<div class="horoscope-result-header">';
                resultHtml += '<div class="horoscope-result-title">' + emoji + ' ' + escapeHtml(signName) + ' · ' + timeLabel + '</div>';
                if (scoreNum) {
                    resultHtml += '<div class="horoscope-score-badge">' + escapeHtml(scoreNum) + '<span>分</span></div>';
                }
                resultHtml += '</div>';

                // 运势概要
                if (scoreText) {
                    resultHtml += '<div class="horoscope-summary">' + escapeHtml(scoreText) + '</div>';
                } else {
                    resultHtml += '<div class="horoscope-summary">' + escapeHtml(general) + '</div>';
                }

                // 四项运势
                resultHtml += '<div class="horoscope-fortune-grid">';
                resultHtml += '<div class="horoscope-fortune-item love"><div class="horoscope-fortune-label">💖 爱情</div><div class="horoscope-fortune-value">' + escapeHtml(love) + '</div></div>';
                resultHtml += '<div class="horoscope-fortune-item work"><div class="horoscope-fortune-label">💼 事业</div><div class="horoscope-fortune-value">' + escapeHtml(work) + '</div></div>';
                resultHtml += '<div class="horoscope-fortune-item wealth"><div class="horoscope-fortune-label">💰 财富</div><div class="horoscope-fortune-value">' + escapeHtml(wealth) + '</div></div>';
                resultHtml += '<div class="horoscope-fortune-item health"><div class="horoscope-fortune-label">🌿 健康</div><div class="horoscope-fortune-value">' + escapeHtml(health) + '</div></div>';
                resultHtml += '</div>';

                // 最佳配对
                if (bestMatch) {
                    resultHtml += '<div class="horoscope-pairing"><span class="horoscope-pairing-label">💞 最佳配对：</span><span class="horoscope-pairing-value">' + escapeHtml(bestMatch) + '</span></div>';
                }

                // 幸运详情
                resultHtml += '<div class="horoscope-detail-section">';
                resultHtml += '<div class="horoscope-detail-grid">';
                if (luckyColor) resultHtml += '<div class="horoscope-detail-row"><span class="horoscope-detail-label">🎨 幸运颜色</span><span class="horoscope-detail-value">' + escapeHtml(luckyColor) + '</span></div>';
                if (luckyNumber) resultHtml += '<div class="horoscope-detail-row"><span class="horoscope-detail-label">🔢 幸运数字</span><span class="horoscope-detail-value">' + escapeHtml(luckyNumber) + '</span></div>';
                if (luckyDirection) resultHtml += '<div class="horoscope-detail-row"><span class="horoscope-detail-label">🧭 幸运方向</span><span class="horoscope-detail-value">' + escapeHtml(luckyDirection) + '</span></div>';
                resultHtml += '</div>';
                resultHtml += '</div>';
                resultHtml += '</div>';

                resultDiv.innerHTML = resultHtml;
            } catch (err) {
                resultDiv.innerHTML = '<div style="color:#fca5a5;text-align:center;padding:12px;font-size:13px;">查询失败: ' + escapeHtml(err.message) + '</div>';
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '✨ 查看运势';
                }
            }
        }

        async function handleHoroscopeRequest() {
            window.isSendingMessage = true;
            addMessage('user', '查看星座运势');
            messageInput.value = '';

            document.querySelector('.main-title').style.display = 'none';
            const hotTopicsContainer = document.querySelector('.hot-topics-container');
            if (hotTopicsContainer) hotTopicsContainer.style.display = 'none';

            const replyText = '请选择您的星座，查看今日运势：';
            addAIMessageWithHTML(replyText, buildHoroscopeCardHtml());

            const horoscopeBtn = document.getElementById('horoscopeBtn');
            if (horoscopeBtn) {
                horoscopeBtn.classList.remove('active');
            }
            isHoroscopeMode = false;
            window.isSendingMessage = false;
        }

        function getWeatherEmoji(text) {
            const map = {
                '晴': '☀️', '少云': '🌤️', '多云': '⛅', '阴': '☁️',
                '雨': '🌧️', '雪': '❄️', '雾': '🌫️', '风': '💨',
                '雷': '⛈️', '霾': '😷', '沙': '🌪️'
            };
            if (!text) return '🌡️';
            const lower = text.toLowerCase();
            for (let [key, val] of Object.entries(map)) {
                if (lower.includes(key)) return val;
            }
            if (lower.includes('sun') || lower.includes('clear')) return '☀️';
            if (lower.includes('cloud')) return '☁️';
            if (lower.includes('rain')) return '🌧️';
            if (lower.includes('snow')) return '❄️';
            return '🌡️';
        }

        function buildWeatherCardHtml() {
            const containerId = 'weather_' + Date.now();
            let html = '<div class="weather-card-container" id="' + containerId + '">';
            html += '<div class="weather-card" id="' + containerId + '_card">';
            html += '<div class="wave-loader"></div>';
            html += '<div class="weather-city-row">';
            html += '<input type="text" class="weather-city-input" id="' + containerId + '_city" placeholder="城市" autocomplete="off">';
            html += '<button class="weather-search-btn" id="' + containerId + '_btn" onclick="fetchWeatherData(\'' + containerId + '\')">查询</button>';
            html += '</div>';
            html += '<p class="weather-text" id="' + containerId + '_text">请输入城市查询天气</p>';
            html += '</div>';
            html += '</div>';
            return html;
        }

        async function fetchWeatherData(containerId, cityOverride) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const card = document.getElementById(containerId + '_card');
            const cityInput = document.getElementById(containerId + '_city');
            const btn = document.getElementById(containerId + '_btn');
            if (!card) return;

            let city = cityOverride || (cityInput ? cityInput.value.trim() : '');
            city = city.replace(/[^\u4e00-\u9fa5a-zA-Z0-9\s]/g, '').trim();
            if (!city) {
                showToast('请输入城市名');
                return;
            }

            if (btn) { btn.disabled = true; }
            if (card) { card.classList.add('loading'); }

            try {
                const params = new URLSearchParams({
                    token: FEATURE_CONFIG.weather.apiToken,
                    location: city,
                    language: 'zh-Hans',
                    unit: 'c'
                });
                const url = FEATURE_CONFIG.weather.apiUrl + '?' + params.toString() + '&_t=' + Date.now();
                const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!response.ok) throw new Error('服务器错误 (' + response.status + ')');

                const rawData = await response.json();
                
                let weatherData = null;
                if (rawData.data && rawData.data.results && rawData.data.results.length > 0) {
                    weatherData = rawData.data.results[0];
                } else if (rawData.results && rawData.results.length > 0) {
                    weatherData = rawData.results[0];
                } else if (rawData.location || rawData.now) {
                    weatherData = rawData;
                } else if (rawData.data) {
                    weatherData = rawData.data;
                } else {
                    weatherData = rawData;
                }

                const loc = weatherData.location || {};
                const now = weatherData.now || {};
                const name = loc.name || city || '未知城市';
                const text = now.text || now.code || '未知';
                const temperature = now.temperature;
                const lastUpdate = weatherData.last_update || '';

                let tempDisplay = '--°C';
                if (temperature !== undefined && temperature !== null) {
                    const tempStr = String(temperature).replace(/[°℃℉\s]/g, '').trim();
                    tempDisplay = tempStr + '°C';
                }

                const emoji = getWeatherEmoji(text);

                let html = '<div class="wave-loader"></div>';
                html += '<div class="weather-city-row">';
                html += '<input type="text" class="weather-city-input" id="' + containerId + '_city" value="' + escapeHtml(name) + '" placeholder="城市" autocomplete="off">';
                html += '<button class="weather-search-btn" id="' + containerId + '_btn" onclick="fetchWeatherData(\'' + containerId + '\')">查询</button>';
                html += '</div>';
                html += '<p class="weather-text">' + escapeHtml(text) + '</p>';
                html += '<span class="weather-icon">' + emoji + '</span>';
                html += '<p class="weather-temp">' + escapeHtml(tempDisplay) + '</p>';
                if (lastUpdate) {
                    const updateTime = lastUpdate.replace(/T.*/, '');
                    html += '<p style="font-size:10px;color:rgb(150,150,150);margin-top:8px;">更新于 ' + escapeHtml(updateTime) + '</p>';
                }

                card.innerHTML = html;

                const newCityInput = document.getElementById(containerId + '_city');
                if (newCityInput) {
                    newCityInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') { e.preventDefault(); fetchWeatherData(containerId); }
                    });
                }
            } catch (err) {
                let html = '<div class="wave-loader"></div>';
                html += '<div class="weather-city-row" style="margin-top:30px;">';
                html += '<input type="text" class="weather-city-input" id="' + containerId + '_city" placeholder="重新输入城市" autocomplete="off">';
                html += '<button class="weather-search-btn" id="' + containerId + '_btn" onclick="fetchWeatherData(\'' + containerId + '\')">查询</button>';
                html += '</div>';
                html += '<p class="weather-text" style="color:#fca5a5;">❌ ' + escapeHtml(err.message) + '</p>';
                card.innerHTML = html;

                const newCityInput = document.getElementById(containerId + '_city');
                if (newCityInput) {
                    newCityInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') { e.preventDefault(); fetchWeatherData(containerId); }
                    });
                    newCityInput.focus();
                }
            } finally {
                if (btn) { btn.disabled = false; }
                if (card) { card.classList.remove('loading'); }
            }
        }

        async function handleWeatherRequest(cityOverride) {
            window.isSendingMessage = true;
            addMessage('user', cityOverride ? '查看' + cityOverride + '天气' : '查看今天天气');
            messageInput.value = '';

            document.querySelector('.main-title').style.display = 'none';
            const hotTopicsContainer = document.querySelector('.hot-topics-container');
            if (hotTopicsContainer) hotTopicsContainer.style.display = 'none';

            const replyText = '请输入城市名查询实时天气：';
            addAIMessageWithHTML(replyText, buildWeatherCardHtml());

            const weatherBtn = document.getElementById('weatherBtn');
            if (weatherBtn) {
                weatherBtn.classList.remove('active');
            }
            isWeatherMode = false;
            window.isSendingMessage = false;

            if (cityOverride) {
                setTimeout(() => {
                    const containers = document.querySelectorAll('.weather-card-container');
                    const lastContainer = containers[containers.length - 1];
                    if (lastContainer) {
                        fetchWeatherData(lastContainer.id, cityOverride);
                    }
                }, 100);
            } else {
                setTimeout(async () => {
                    let autoCity = null;
                    const ipApis = FEATURE_CONFIG.weather.ipLocationApis;
                    for (const apiUrl of ipApis) {
                        try {
                            const res = await fetch(apiUrl, { signal: AbortSignal.timeout(5000) });
                            if (!res.ok) continue;
                            const data = await res.json();
                            autoCity = data.city || data.region || null;
                            if (autoCity) break;
                        } catch (e) {
                            
                        }
                    }
                    const containers = document.querySelectorAll('.weather-card-container');
                    const lastContainer = containers[containers.length - 1];
                    if (lastContainer) {
                        if (autoCity) {
                            fetchWeatherData(lastContainer.id, autoCity);
                        } else {
                            const card = document.getElementById(lastContainer.id + '_card');
                            if (card) {
                                const cityInput = document.getElementById(lastContainer.id + '_city');
                                if (cityInput) cityInput.focus();
                            }
                        }
                    }
                }, 300);
            }
        }

        async function handleMusicSearch(keyword) {
            if (!keyword || !keyword.trim()) return;
            keyword = keyword.trim();

            if (typeof window.closeIslandMusicSearch === 'function') {
                window.closeIslandMusicSearch();
            }

            window.isSendingMessage = true;
            addMessage('user', '搜索音乐：' + keyword);
            saveCurrentChat();
            messageInput.value = '';

            document.querySelector('.main-title').style.display = 'none';
            const hotTopicsContainer = document.querySelector('.hot-topics-container');
            if (hotTopicsContainer) hotTopicsContainer.style.display = 'none';

            const loadingId = addLoadingIndicator();

            try {
                const result = await window.fetchMusicSearchResult(keyword);
                removeLoadingIndicator(loadingId);

                if (result && result.music.length > 0) {
                    window.renderMusicResult(result.music, '搜索到以下音乐：');
                } else {
                    addMessage('ai', '没有找到相关歌曲，换个关键词试试吧。');
                    saveCurrentChat();
                }
            } catch (error) {
                console.error('搜索音乐失败:', error);
                removeLoadingIndicator(loadingId);
                addMessage('ai', '搜索音乐时出错了，请稍后再试。');
                saveCurrentChat();
            }

            isMusicMode = false;
            window.isSendingMessage = false;
        }

        window.handleMusicSearch = handleMusicSearch;
