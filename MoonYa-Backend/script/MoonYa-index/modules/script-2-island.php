
    <script>
    (function() {
        var island = document.getElementById('dynamicIsland');
        var albumArtSmall = document.getElementById('islandAlbumArtSmall');
        var albumArtLarge = document.getElementById('islandAlbumArtLarge');
        var songTitle = document.getElementById('islandSongTitle');
        var artistName = document.getElementById('islandArtistName');
        var currentTimeEl = document.getElementById('islandCurrentTime');
        var totalTimeEl = document.getElementById('islandTotalTime');
        var progressBar = document.getElementById('islandProgressBar');
        var progressFill = document.getElementById('islandProgressFill');
        var playBtn = document.getElementById('islandPlayBtn');
        var playIcon = document.getElementById('islandPlayIcon');
        var prevBtn = document.getElementById('islandPrevBtn');
        var nextBtn = document.getElementById('islandNextBtn');
        var pipBtn = document.getElementById('islandPipBtn');
        var poppedStatus = document.getElementById('islandPoppedStatus');
        var poppedCloseBtn = document.getElementById('islandPoppedCloseBtn');
        var noPipTip = document.getElementById('islandNoPipTip');
        var searchContent = document.getElementById('islandSearchContent');
        var searchInput = document.getElementById('islandSearchInput');
        var searchCancel = document.getElementById('islandSearchCancel');
        var searchIcon = document.getElementById('islandSearchIcon');

        var musicPlaylist = [];
        var currentPlaylistIndex = -1;
        var pipWindow = null;
        var islandUpdateInterval = null;
        var isDragging = false;
        var isSearchMode = false;
        var wasPlayingBeforeSearch = false;

        function formatTime(sec) {
            if (!sec || isNaN(sec)) return '0:00';
            var m = Math.floor(sec / 60);
            var s = Math.floor(sec % 60);
            return m + ':' + (s < 10 ? '0' : '') + s;
        }

        function updateIslandUI() {
            var audio = window.currentAudio;
            if (!audio) {
                songTitle.textContent = '未在播放';
                artistName.textContent = '--';
                progressFill.style.width = '0%';
                currentTimeEl.textContent = '0:00';
                totalTimeEl.textContent = '0:00';
                playIcon.innerHTML = '<path d="M8 5v14l11-7z"/>';
                island.classList.add('paused');
                return;
            }
            var isPlaying = !audio.paused;
            playIcon.innerHTML = isPlaying
                ? '<path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>'
                : '<path d="M8 5v14l11-7z"/>';
            if (isPlaying) {
                island.classList.remove('paused');
            } else {
                island.classList.add('paused');
            }
            if (audio.duration && !isDragging) {
                var pct = (audio.currentTime / audio.duration) * 100;
                progressFill.style.width = pct + '%';
                currentTimeEl.textContent = formatTime(audio.currentTime);
                totalTimeEl.textContent = formatTime(audio.duration);
            }
            if (currentPlaylistIndex >= 0 && currentPlaylistIndex < musicPlaylist.length) {
                var track = musicPlaylist[currentPlaylistIndex];
                songTitle.textContent = track.name || '未知歌曲';
                artistName.textContent = track.artist || '未知歌手';
            }
        }

        function updateAlbumArt(logoUrl) {
            var url = logoUrl || '/image/yax.png';
            albumArtSmall.src = url;
            albumArtLarge.src = url;
            albumArtSmall.onerror = function() { this.src = '/image/yax.png'; };
            albumArtLarge.onerror = function() { this.src = '/image/yax.png'; };
        }

        function showIslandPlayer() {
            island.classList.add('visible');
            if (!islandUpdateInterval) {
                islandUpdateInterval = setInterval(updateIslandUI, 200);
            }
        }

        function hideIslandPlayer() {
            island.classList.remove('visible');
            island.classList.remove('expanded');
            island.classList.add('compact');
            isIslandExpanded = false;
            if (islandUpdateInterval) {
                clearInterval(islandUpdateInterval);
                islandUpdateInterval = null;
            }
        }

        var isIslandExpanded = false;

        function playTrackByIndex(index) {
            if (index < 0 || index >= musicPlaylist.length) return;
            currentPlaylistIndex = index;
            var track = musicPlaylist[index];
            if (window.currentAudio) {
                window.currentAudio.pause();
                if (window.currentAudio.src && window.currentAudio.src.startsWith('blob:')) {
                    URL.revokeObjectURL(window.currentAudio.src);
                }
            }
            if (window.currentPlayBtn) {
                window.currentPlayBtn.classList.remove('playing');
            }
            if (window.progressInterval) {
                clearInterval(window.progressInterval);
                window.progressInterval = null;
            }
            updateAlbumArt(track.logo_url);

            if (track.file_url) {
                playTrackWithUrl(track);
            } else if (track._keyword) {
                // 任意多源（QQ/网易/酷我/酷狗）file_url 为空时走详情兜底取链
                fetchMusicDetailForIsland(track, index);
            } else {
                console.error('No play URL available for track:', track);
            }
        }

        function playTrackWithUrl(track) {
            var fetchUrl = track.file_url;
            if (fetchUrl && fetchUrl.indexOf('http://') === 0) { fetchUrl = fetchUrl.replace('http://', 'https://'); }
            // 先尝试 blob 方式（避免 CORS 对 Web Audio API 的限制）
            fetch(fetchUrl).then(function(response) {
                return response.blob();
            }).then(function(blob) {
                var blobUrl = URL.createObjectURL(blob);
                startIslandAudio(blobUrl, track);
            }).catch(function(err) {
                // blob 失败（CORS 限制等），回退直接播放（Audio 元素不受 CORS 限制）
                console.warn('blob 加载失败，回退直接播放:', err);
                startIslandAudio(fetchUrl, track);
            });
        }

        function startIslandAudio(audioUrl, track) {
            var audio = new Audio(audioUrl);
            window.currentAudio = audio;
            window.currentMusicId = track.id;
            window.currentPlayBtn = null;

            audio.play().then(function() {
                showIslandPlayer();
                updateIslandUI();
                triggerIslandPulse();
                var progressCircle = document.getElementById('progress-' + track.id);
                if (progressCircle) {
                    if (window.progressInterval) clearInterval(window.progressInterval);
                    window.progressInterval = setInterval(function() {
                        if (window.currentAudio && window.currentAudio.duration) {
                            var progress = window.currentAudio.currentTime / window.currentAudio.duration;
                            var circumference = 126;
                            progressCircle.style.strokeDashoffset = circumference - (progress * circumference);
                        }
                    }, 100);
                }
            }).catch(function(err) { console.error('播放失败:', err); });

            audio.onended = function() {
                var pc = document.getElementById('progress-' + track.id);
                if (pc) pc.style.strokeDashoffset = 126;
                if (currentPlaylistIndex < musicPlaylist.length - 1) {
                    playTrackByIndex(currentPlaylistIndex + 1);
                } else {
                    window.currentAudio = null;
                    window.currentMusicId = null;
                    updateIslandUI();
                    hideIslandPlayer();
                }
            };
            audio.onerror = function() {
                window.currentAudio = null;
                window.currentMusicId = null;
                updateIslandUI();
            };
            updateMusicCardStates(track.id);
        }

        function fetchMusicDetailForIsland(track, playlistIndex) {
            songTitle.textContent = '加载中...';
            artistName.textContent = '正在获取播放链接';

            var detailUrl = 'music_api.php?action=detail&name=' + encodeURIComponent(track._keyword)
                          + '&n=' + (track._searchIndex + 1);
            if (track._source) {
                detailUrl += '&source=' + encodeURIComponent(track._source);
            }
            fetch(detailUrl)
                .then(function(resp) { return resp.json(); })
                .then(function(detailData) {
                    // 后端归一化响应：{ success: true, data: { url: "..." } }
                    var url = '';
                    if (detailData && detailData.success && detailData.data && detailData.data.url) {
                        url = detailData.data.url;
                    }
                    if (url && url.indexOf('http://') === 0) { url = url.replace('http://', 'https://'); }
                    if (url) {
                        track.file_url = url;
                        playTrackWithUrl(track);
                    } else {
                        songTitle.textContent = '播放失败';
                        artistName.textContent = '无法获取播放链接';
                        setTimeout(function() {
                            if (currentPlaylistIndex < musicPlaylist.length - 1) {
                                playTrackByIndex(currentPlaylistIndex + 1);
                            } else {
                                updateIslandUI();
                            }
                        }, 2000);
                    }
                })
                .catch(function(err) {
                    console.error('获取播放链接失败:', err);
                    songTitle.textContent = '播放失败';
                    artistName.textContent = '网络错误';
                    setTimeout(function() {
                        if (currentPlaylistIndex < musicPlaylist.length - 1) {
                            playTrackByIndex(currentPlaylistIndex + 1);
                        } else {
                            updateIslandUI();
                        }
                    }, 2000);
                });
        }

        function updateMusicCardStates(playingId) {
            document.querySelectorAll('.music-play-btn').forEach(function(btn) {
                var card = btn.closest('.music-card');
                if (card && card.dataset.musicId == playingId && window.currentAudio && !window.currentAudio.paused) {
                    btn.classList.add('playing');
                    window.currentPlayBtn = btn;
                } else {
                    btn.classList.remove('playing');
                }
            });
        }

        window.updateDynamicIslandPlaylist = function(tracks) {
            musicPlaylist = tracks || [];
            currentPlaylistIndex = -1;
        };

        // 返回 true 表示已处理（歌曲在播放列表中），false 表示未找到
        window.onIslandTrackPlay = function(musicUrl, musicId) {
            for (var i = 0; i < musicPlaylist.length; i++) {
                if (musicPlaylist[i].id == musicId) {
                    if (currentPlaylistIndex === i && window.currentAudio && !window.currentAudio.paused) {
                        window.currentAudio.pause();
                        updateIslandUI();
                        updateMusicCardStates(musicId);
                    } else {
                        playTrackByIndex(i);
                    }
                    return true;
                }
            }
            return false;
        };

        var _origAudio = null;
        setInterval(function() {
            if (window.currentAudio !== _origAudio) {
                _origAudio = window.currentAudio;
                if (_origAudio && !_origAudio.paused) showIslandPlayer();
            }
        }, 500);

        window.openIslandMusicSearch = function() {
            if (isSearchMode) {
                if (searchInput) searchInput.focus();
                return;
            }
            isSearchMode = true;
            wasPlayingBeforeSearch = window.currentAudio && !window.currentAudio.paused;
            island.classList.remove('compact', 'expanded');
            island.classList.add('search-mode', 'visible');
            if (searchInput) {
                searchInput.value = '';
                setTimeout(function() { searchInput.focus(); }, 300);
            }
        };

        window.closeIslandMusicSearch = function() {
            if (!isSearchMode) return;
            isSearchMode = false;
            island.classList.remove('search-mode');
            if (wasPlayingBeforeSearch && window.currentAudio && !window.currentAudio.paused) {
                island.classList.add('compact');
            } else if (!window.currentAudio || window.currentAudio.paused) {
                island.classList.remove('visible');
                island.classList.add('compact');
            } else {
                island.classList.add('compact');
            }
        };

        if (searchInput) {
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var keyword = searchInput.value.trim();
                    if (keyword && typeof window.handleMusicSearch === 'function') {
                        window.handleMusicSearch(keyword);
                    }
                } else if (e.key === 'Escape') {
                    window.closeIslandMusicSearch();
                }
            });
            searchInput.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }

        if (searchIcon) {
            searchIcon.addEventListener('click', function(e) {
                e.stopPropagation();
                var keyword = searchInput ? searchInput.value.trim() : '';
                if (keyword && typeof window.handleMusicSearch === 'function') {
                    window.handleMusicSearch(keyword);
                }
            });
            searchIcon.style.cursor = 'pointer';
        }

        if (searchCancel) {
            searchCancel.addEventListener('click', function(e) {
                e.stopPropagation();
                window.closeIslandMusicSearch();
            });
        }

        if (searchContent) {
            searchContent.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }

        function triggerIslandPulse() {
            if (!island.classList.contains('visible')) return;
            if (island.classList.contains('expanded')) return;
            if (island.classList.contains('pulse-expand')) return;
            island.classList.add('pulse-expand');
            setTimeout(function() {
                island.classList.remove('pulse-expand');
            }, 600);
        }

        island.addEventListener('mouseenter', function() {
            if (isSearchMode) return;
            if (!island.classList.contains('visible')) return;
            island.classList.remove('pulse-expand');
            island.classList.remove('compact');
            island.classList.add('expanded');
            isIslandExpanded = true;
        });
        island.addEventListener('mouseleave', function() {
            if (isSearchMode) return;
            island.classList.remove('expanded');
            island.classList.add('compact');
            isIslandExpanded = false;
        });

        playBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!window.currentAudio) return;
            if (window.currentAudio.paused) window.currentAudio.play();
            else window.currentAudio.pause();
            updateIslandUI();
            if (currentPlaylistIndex >= 0 && currentPlaylistIndex < musicPlaylist.length) {
                updateMusicCardStates(musicPlaylist[currentPlaylistIndex].id);
            }
        });

        prevBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (musicPlaylist.length === 0) return;
            var idx = currentPlaylistIndex - 1;
            if (idx < 0) idx = musicPlaylist.length - 1;
            playTrackByIndex(idx);
        });

        nextBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (musicPlaylist.length === 0) return;
            var idx = currentPlaylistIndex + 1;
            if (idx >= musicPlaylist.length) idx = 0;
            playTrackByIndex(idx);
        });

        var dragStartTime = 0;
        var isMouseDownOnProgress = false;

        progressBar.addEventListener('click', function(e) {
            if (isMouseDownOnProgress) return;
            e.stopPropagation();
            if (!window.currentAudio || !window.currentAudio.duration) return;
            var elapsed = Date.now() - dragStartTime;
            if (elapsed < 100) return;
            var rect = progressBar.getBoundingClientRect();
            var pct = (e.clientX - rect.left) / rect.width;
            pct = Math.max(0, Math.min(1, pct));
            window.currentAudio.currentTime = pct * window.currentAudio.duration;
            updateIslandUI();
        });

        progressBar.addEventListener('mousedown', function(e) {
            if (!window.currentAudio || !window.currentAudio.duration) return;
            isDragging = true;
            isMouseDownOnProgress = true;
            dragStartTime = Date.now();
            e.preventDefault();
            e.stopPropagation();

            function onMove(ev) {
                if (!isDragging) return;
                var rect = progressBar.getBoundingClientRect();
                var pct = (ev.clientX - rect.left) / rect.width;
                pct = Math.max(0, Math.min(1, pct));
                progressFill.style.width = (pct * 100) + '%';
                currentTimeEl.textContent = formatTime(pct * window.currentAudio.duration);
            }
            function onUp(ev) {
                if (!isDragging) return;
                isDragging = false;
                dragStartTime = Date.now();
                setTimeout(function() { isMouseDownOnProgress = false; }, 200);
                var rect = progressBar.getBoundingClientRect();
                var pct = (ev.clientX - rect.left) / rect.width;
                pct = Math.max(0, Math.min(1, pct));
                var targetTime = pct * window.currentAudio.duration;
                var maxRetries = 50;
                var retryCount = 0;
                var seekCompleted = false;

                function attemptSeek() {
                    if (seekCompleted) return;
                    retryCount++;
                    window.currentAudio.currentTime = targetTime;
                    updateIslandUI();
                    setTimeout(function() {
                        var diff = Math.abs(window.currentAudio.currentTime - targetTime);
                        if (diff < 0.5 || window.currentAudio.currentTime >= targetTime - 0.5) {
                            seekCompleted = true;
                        } else if (retryCount < maxRetries) {
                            attemptSeek();
                        }
                    }, 100);
                }
                attemptSeek();
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        var expandedContent = island.querySelector('.island-expanded-content');
        if (expandedContent) {
            expandedContent.addEventListener('click', function(e) { e.stopPropagation(); });
        }

        function copyStylesToPipWindow(doc) {
            var styleSheets = document.styleSheets;
            var styleEl = doc.createElement('style');
            var cssText = '';
            for (var i = 0; i < styleSheets.length; i++) {
                try {
                    var rules = styleSheets[i].cssRules || styleSheets[i].rules;
                    if (rules) { for (var j = 0; j < rules.length; j++) cssText += rules[j].cssText + '\n'; }
                } catch (e) {
                    try { if (styleSheets[i].href) { var linkEl = doc.createElement('link'); linkEl.rel = 'stylesheet'; linkEl.href = styleSheets[i].href; doc.head.appendChild(linkEl); } } catch (e2) {}
                }
            }
            styleEl.textContent = cssText;
            doc.head.appendChild(styleEl);
        }

        pipBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!('documentPictureInPicture' in window)) {
                noPipTip.classList.add('visible');
                setTimeout(function() { noPipTip.classList.remove('visible'); }, 3000);
                return;
            }
            if (pipWindow) { pipWindow.close(); return; }

            (async function() {
                try {
                    pipWindow = await documentPictureInPicture.requestWindow({ width: 400, height: 200 });
                    if (!pipWindow) throw new Error('No window returned');
                    copyStylesToPipWindow(pipWindow.document);

                    var pipBody = pipWindow.document.body;
                    pipBody.style.margin = '0';
                    pipBody.style.padding = '0';
                    pipBody.style.background = 'transparent';
                    pipBody.style.overflow = 'hidden';
                    pipBody.style.display = 'flex';
                    pipBody.style.alignItems = 'center';
                    pipBody.style.justifyContent = 'center';

                    var pipIsland = pipWindow.document.createElement('div');
                    pipIsland.className = 'dynamic-island compact visible';
                    pipIsland.id = 'pipDynamicIsland';
                    pipIsland.style.position = 'relative';
                    pipIsland.style.transform = 'none';
                    pipIsland.style.left = 'auto';
                    pipIsland.style.top = 'auto';
                    pipIsland.style.margin = '0 auto';
                    pipBody.appendChild(pipIsland);

                    var pipCompact = pipWindow.document.createElement('div');
                    pipCompact.className = 'island-compact-content';
                    pipCompact.style.opacity = '1';
                    pipIsland.appendChild(pipCompact);

                    var pipAlbumSmall = pipWindow.document.createElement('img');
                    pipAlbumSmall.className = 'island-album-art-small';
                    pipAlbumSmall.id = 'pipAlbumSmall';
                    pipAlbumSmall.src = albumArtSmall.src;
                    pipAlbumSmall.alt = 'album';
                    pipCompact.appendChild(pipAlbumSmall);

                    var pipWaveform = pipWindow.document.createElement('div');
                    pipWaveform.className = 'island-waveform';
                    pipCompact.appendChild(pipWaveform);
                    for (var wi = 0; wi < 5; wi++) {
                        var wBar = pipWindow.document.createElement('div');
                        wBar.className = 'island-wave-bar';
                        pipWaveform.appendChild(wBar);
                    }

                    function syncPipCompact() {
                        if (!pipWindow) return;
                        var pAlbum = pipWindow.document.getElementById('pipAlbumSmall');
                        if (pAlbum) pAlbum.src = albumArtSmall.src;
                    }

                    var pipSyncInterval = setInterval(syncPipCompact, 200);
                    island.classList.remove('visible');
                    poppedStatus.classList.add('visible');

                    pipWindow.addEventListener('pagehide', function() {
                        clearInterval(pipSyncInterval);
                        pipWindow = null;
                        poppedStatus.classList.remove('visible');
                        if (window.currentAudio && !window.currentAudio.paused) {
                            island.classList.add('visible');
                        }
                    });
                } catch (err) {
                    console.error('画中画弹出失败:', err);
                    noPipTip.querySelector('span').textContent = '⚠️ 画中画失败: ' + err.message;
                    noPipTip.classList.add('visible');
                    setTimeout(function() { noPipTip.classList.remove('visible'); }, 3000);
                }
            })();
        });

        poppedCloseBtn.addEventListener('click', function() {
            if (pipWindow) pipWindow.close();
        });

        var origToggleMusicPlay = window.toggleMusicPlay;
        window.toggleMusicPlay = function(btn, musicUrl, musicId) {
            if (musicPlaylist.length > 0) {
                window.onIslandTrackPlay(musicUrl, musicId);
                return;
            }
            if (origToggleMusicPlay) origToggleMusicPlay(btn, musicUrl, musicId);
            if (window.currentAudio && !window.currentAudio.paused) showIslandPlayer();
        };
    })();
