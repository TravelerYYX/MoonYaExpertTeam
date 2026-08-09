(function(global) {
    'use strict';

    function MoonyaVideoPlayer(container, options) {
        this.container = typeof container === 'string' ? document.querySelector(container) : container;
        if (!this.container) return;

        this.options = Object.assign({
            src: '',
            poster: '',
            primaryColor: '#6B92F2',
            autoplay: false,
            muted: false,
            preload: 'metadata',
            compact: false
        }, options || {});

        this.video = null;
        this.hls = null;
        this.isPlaying = false;
        this.isMuted = this.options.muted;
        this.isFullscreen = false;
        this.isPiP = false;
        this.isLongPress = false;
        this.playbackRate = 1;
        this.loopPlay = false;
        this.longPressTimer = null;
        this.controlsTimer = null;
        this.dragging = false;
        this.init();
    }

    MoonyaVideoPlayer.prototype.init = function() {
        this.container.classList.add('moonya-video-player', 'mvp-paused');
        if (this.options.compact) {
            this.container.classList.add('moonya-video-player-compact');
        }

        this.container.innerHTML = this.buildHTML();

        this.video = this.container.querySelector('.mvp-video');
        this.controlsOverlay = this.container.querySelector('.mvp-controls-overlay');
        this.playBtn = this.container.querySelector('.mvp-play-btn');
        this.centerPlayBtn = this.container.querySelector('.mvp-center-play');
        this.progressTrack = this.container.querySelector('.mvp-progress-track');
        this.progressBuffered = this.container.querySelector('.mvp-progress-buffered');
        this.progressPlayed = this.container.querySelector('.mvp-progress-played');
        this.progressThumb = this.container.querySelector('.mvp-progress-thumb');
        this.timeDisplay = this.container.querySelector('.mvp-time-display');
        this.muteBtn = this.container.querySelector('.mvp-mute-btn');
        this.fullscreenBtn = this.container.querySelector('.mvp-fullscreen-btn');
        this.pipBtn = this.container.querySelector('.mvp-pip-btn');
        this.speedBadge = this.container.querySelector('.mvp-speed-badge');
        this.loadingSpinner = this.container.querySelector('.mvp-loading');
        this.settingsBtn = this.container.querySelector('.mvp-settings-btn');
        this.settingsPanel = this.container.querySelector('.mvp-settings-panel');
        this.loopSwitch = this.container.querySelector('.mvp-switch-loop');

        this.bindEvents();
        this.loadSource(this.options.src);

        if (this.options.autoplay) {
            this.video.play().catch(function() {});
        }
    };

    MoonyaVideoPlayer.prototype.loadSource = function(src) {
        if (!src) return;

        var isHls = /\.m3u8(\?|$)/i.test(src);

        if (isHls && typeof Hls !== 'undefined' && Hls.isSupported()) {
            this.hls = new Hls({
                enableWorker: false,
                autoStartLoad: true
            });
            this.video.muted = true;
            this.isMuted = true;
            if (this.muteBtn) this.muteBtn.classList.add('muted');
            this.hls.loadSource(src);
            this.hls.attachMedia(this.video);
            var self = this;
            this._wantPlay = false;

            this.hls.on(Hls.Events.FRAG_BUFFERED, function() {
                self.loadingSpinner.style.display = 'none';
                if (self._wantPlay && self._bufferReady()) {
                    self._wantPlay = false;
                    self._userClickedPlay = true;
                    self.video.play().catch(function() {});
                }
            });

            this.hls.on(Hls.Events.ERROR, function(event, data) {
                if (data.fatal) {
                    switch (data.type) {
                        case Hls.ErrorTypes.NETWORK_ERROR:
                            self.hls.startLoad();
                            break;
                        case Hls.ErrorTypes.MEDIA_ERROR:
                            self.hls.recoverMediaError();
                            break;
                        default:
                            self.hls.destroy();
                            self.hls = null;
                            break;
                    }
                }
            });
        } else if (isHls && this.video.canPlayType('application/vnd.apple.mpegurl')) {
            this.video.src = src;
        } else {
            this.video.src = src;
        }
    };

    MoonyaVideoPlayer.prototype._bufferReady = function() {
        if (!this.video || !this.video.duration) return false;
        if (this.video.buffered.length === 0) return false;
        var end = this.video.buffered.end(this.video.buffered.length - 1);
        return end >= this.video.duration * 0.8;
    };

    MoonyaVideoPlayer.prototype.buildHTML = function() {
        var o = this.options;
        var html = '<video class="mvp-video"';
        if (o.poster) html += ' poster="' + o.poster + '"';
        html += ' playsinline webkit-playsinline preload="auto"';
        if (o.muted) html += ' muted';
        html += '></video>';

        html += '<div class="mvp-loading"><div class="mvp-spinner"></div></div>';
        html += '<div class="mvp-speed-badge">2x</div>';
        html += '<div class="mvp-center-play"><svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg></div>';

        html += '<div class="mvp-controls-overlay">';
        html += '<div class="mvp-progress-bar">';
        html += '<div class="mvp-progress-track">';
        html += '<div class="mvp-progress-buffered"></div>';
        html += '<div class="mvp-progress-played"></div>';
        html += '<div class="mvp-progress-thumb"></div>';
        html += '</div>';
        html += '</div>';
        html += '<div class="mvp-controls-row">';
        html += '<button class="mvp-play-btn" type="button"><svg class="mvp-icon-play" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg><svg class="mvp-icon-pause" viewBox="0 0 24 24"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg></button>';
        html += '<span class="mvp-time-display"><span class="mvp-time-current">0:00</span> / <span class="mvp-time-duration">0:00</span></span>';
        html += '<span class="mvp-controls-spacer"></span>';
        html += '<button class="mvp-mute-btn" type="button"><svg class="mvp-icon-unmuted" viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg><svg class="mvp-icon-muted" viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><line x1="23" y1="9" x2="17" y2="15"></line><line x1="17" y1="9" x2="23" y2="15"></line></svg></button>';

        html += '<div class="mvp-settings-wrap">';
        html += '<button class="mvp-settings-btn" type="button"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94L14.4 2.81a.47.47 0 0 0-.48-.41h-3.84a.47.47 0 0 0-.48.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 0 0-.59.22L2.72 8.87a.48.48 0 0 0 .12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.48-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z"/></svg></button>';
        html += '<div class="mvp-settings-panel">';
        html += '<div class="mvp-setting-row"><span>洗脑循环</span><div class="mvp-switch mvp-switch-loop"></div></div>';
        html += '</div>';
        html += '</div>';

        if (document.pictureInPictureEnabled) {
            html += '<button class="mvp-pip-btn" type="button"><svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><rect x="11" y="9" width="9" height="6" rx="1" ry="1"></rect></svg></button>';
        }

        html += '<button class="mvp-fullscreen-btn" type="button"><svg class="mvp-icon-expand" viewBox="0 0 24 24"><polyline points="15 3 21 3 21 9"></polyline><polyline points="9 21 3 21 3 15"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg><svg class="mvp-icon-shrink" viewBox="0 0 24 24"><polyline points="4 14 10 14 10 20"></polyline><polyline points="20 10 14 10 14 4"></polyline><line x1="14" y1="10" x2="21" y2="3"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg></button>';
        html += '</div>';
        html += '</div>';

        return html;
    };

    MoonyaVideoPlayer.prototype.bindEvents = function() {
        var self = this;

        this.video.addEventListener('loadedmetadata', function() {
            self.updateTimeDisplay();
        });

        this.video.addEventListener('timeupdate', function() {
            self.updateProgress();
            self.updateTimeDisplay();
        });

        this.video.addEventListener('progress', function() {
            self.updateBuffered();
        });

        this.video.addEventListener('play', function() {
            self.isPlaying = true;
            self.container.classList.add('mvp-playing');
            self.container.classList.remove('mvp-paused');
            self.showControls();
        });

        this.video.addEventListener('pause', function() {
            self.isPlaying = false;
            self.container.classList.remove('mvp-playing');
            self.container.classList.add('mvp-paused');
            self.showControls();
        });

        this.video.addEventListener('waiting', function() {
            self.loadingSpinner.style.display = 'flex';
        });

        this.video.addEventListener('canplay', function() {
            self.loadingSpinner.style.display = 'none';
        });

        this.video.addEventListener('playing', function() {
            self.loadingSpinner.style.display = 'none';
        });

        this.video.addEventListener('ended', function() {
            self._userClickedPlay = false;
            if (self.loopPlay) {
                self.video.currentTime = 0;
                self.video.play().catch(function() {});
                return;
            }
            self.isPlaying = false;
            self.container.classList.remove('mvp-playing');
            self.container.classList.add('mvp-paused', 'mvp-ended');
            self.showControls();
        });

        this.centerPlayBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            self.togglePlay();
        });

        this.playBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            self.togglePlay();
        });

        this.muteBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            self.toggleMute();
        });

        this.fullscreenBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            self.toggleFullscreen();
        });

        if (this.pipBtn) {
            this.pipBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                self.togglePiP();
            });
        }

        if (this.settingsBtn) {
            this.settingsBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                self.toggleSettings();
            });
        }

        if (this.loopSwitch) {
            this.loopSwitch.addEventListener('click', function(e) {
                e.stopPropagation();
                self.toggleLoop();
            });
        }

        document.addEventListener('click', function(e) {
            if (self.settingsPanel && !self.settingsPanel.contains(e.target) && e.target !== self.settingsBtn) {
                self.settingsPanel.classList.remove('show');
            }
        });

        this.container.addEventListener('click', function(e) {
            if (e.target.closest('.mvp-controls-overlay')) return;
            self.togglePlay();
        });

        this.container.addEventListener('mouseenter', function() {
            self.showControls();
        });

        this.container.addEventListener('mousemove', function() {
            self.showControls();
        });

        this.container.addEventListener('touchstart', function(e) {
            self.handleLongPressStart(e);
        }, { passive: true });

        this.container.addEventListener('touchend', function() {
            self.handleLongPressEnd();
        });

        this.container.addEventListener('touchcancel', function() {
            self.handleLongPressEnd();
        });

        this.container.addEventListener('touchmove', function() {
            self.handleLongPressEnd();
        }, { passive: true });

        this.bindProgressEvents();

        document.addEventListener('fullscreenchange', function() {
            self.handleFullscreenChange();
        });
        document.addEventListener('webkitfullscreenchange', function() {
            self.handleFullscreenChange();
        });

        this.video.addEventListener('enterpictureinpicture', function() {
            self.isPiP = true;
            if (self.pipBtn) self.pipBtn.classList.add('active');
        });

        this.video.addEventListener('leavepictureinpicture', function() {
            self.isPiP = false;
            if (self.pipBtn) self.pipBtn.classList.remove('active');
        });
    };

    MoonyaVideoPlayer.prototype.togglePlay = function() {
        if (this.video.ended && !this.loopPlay) {
            this.video.currentTime = 0;
            this.container.classList.remove('mvp-ended');
        }
        if (this.video.paused) {
            if (this.hls && !this._bufferReady()) {
                this._wantPlay = true;
                this.container.classList.add('mvp-playing');
                this.container.classList.remove('mvp-paused');
                this.centerPlayBtn.style.display = 'none';
                this.loadingSpinner.style.display = 'flex';
                return;
            }
            this._userClickedPlay = true;
            this.video.play().catch(function() {});
        } else {
            this._userClickedPlay = false;
            this.video.pause();
        }
    };

    MoonyaVideoPlayer.prototype.toggleMute = function() {
        this.isMuted = !this.isMuted;
        this.video.muted = this.isMuted;
        if (this.isMuted) {
            this.muteBtn.classList.add('muted');
        } else {
            this.muteBtn.classList.remove('muted');
        }
    };

    MoonyaVideoPlayer.prototype.toggleSettings = function() {
        if (this.settingsPanel.classList.contains('show')) {
            this.settingsPanel.classList.remove('show');
        } else {
            this.settingsPanel.classList.add('show');
        }
    };

    MoonyaVideoPlayer.prototype.toggleLoop = function() {
        this.loopPlay = !this.loopPlay;
        if (this.loopPlay) {
            this.loopSwitch.classList.add('on');
        } else {
            this.loopSwitch.classList.remove('on');
        }
    };

    MoonyaVideoPlayer.prototype.toggleFullscreen = function() {
        if (this.isFullscreen) {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            }
        } else {
            var el = this.container;
            if (el.requestFullscreen) {
                el.requestFullscreen();
            } else if (el.webkitRequestFullscreen) {
                el.webkitRequestFullscreen();
            }
        }
    };

    MoonyaVideoPlayer.prototype.handleFullscreenChange = function() {
        var fsEl = document.fullscreenElement || document.webkitFullscreenElement;
        this.isFullscreen = fsEl === this.container;
        if (this.isFullscreen) {
            this.container.classList.add('mvp-fullscreen');
            this.fullscreenBtn.classList.add('active');
        } else {
            this.container.classList.remove('mvp-fullscreen');
            this.fullscreenBtn.classList.remove('active');
        }
    };

    MoonyaVideoPlayer.prototype.togglePiP = function() {
        if (!document.pictureInPictureEnabled) return;
        if (this.isPiP) {
            document.exitPictureInPicture().catch(function() {});
        } else {
            this.video.requestPictureInPicture().catch(function() {});
        }
    };

    MoonyaVideoPlayer.prototype.handleLongPressStart = function(e) {
        if (e.target.closest('.mvp-controls-overlay')) return;
        var self = this;
        this.longPressTimer = setTimeout(function() {
            if (self.isPlaying) {
                self.isLongPress = true;
                self.video.playbackRate = 2;
                self.speedBadge.classList.add('show');
            }
        }, 500);
    };

    MoonyaVideoPlayer.prototype.handleLongPressEnd = function() {
        clearTimeout(this.longPressTimer);
        if (this.isLongPress) {
            this.isLongPress = false;
            this.video.playbackRate = 1;
            this.speedBadge.classList.remove('show');
        }
    };

    MoonyaVideoPlayer.prototype.bindProgressEvents = function() {
        var self = this;
        var progressBar = this.container.querySelector('.mvp-progress-bar');

        function getProgressRatio(e) {
            var rect = progressBar.getBoundingClientRect();
            var x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
            return Math.max(0, Math.min(1, x / rect.width));
        }

        function onProgressDown(e) {
            e.stopPropagation();
            self.dragging = true;
            var ratio = getProgressRatio(e);
            self.seekTo(ratio);
        }

        function onProgressMove(e) {
            if (!self.dragging) return;
            e.stopPropagation();
            var ratio = getProgressRatio(e);
            self.seekTo(ratio);
        }

        function onProgressUp() {
            self.dragging = false;
        }

        progressBar.addEventListener('mousedown', onProgressDown);
        progressBar.addEventListener('touchstart', onProgressDown, { passive: false });
        document.addEventListener('mousemove', onProgressMove);
        document.addEventListener('touchmove', onProgressMove, { passive: false });
        document.addEventListener('mouseup', onProgressUp);
        document.addEventListener('touchend', onProgressUp);
    };

    MoonyaVideoPlayer.prototype.seekTo = function(ratio) {
        if (!this.video.duration || !isFinite(this.video.duration)) return;
        this.video.currentTime = ratio * this.video.duration;
        this.updateProgress();
    };

    MoonyaVideoPlayer.prototype.updateProgress = function() {
        if (this.dragging) return;
        if (!this.video.duration || !isFinite(this.video.duration)) return;
        var pct = (this.video.currentTime / this.video.duration) * 100;
        this.progressPlayed.style.width = pct + '%';
        this.progressThumb.style.left = pct + '%';
    };

    MoonyaVideoPlayer.prototype.updateBuffered = function() {
        if (this.video.buffered.length > 0 && this.video.duration > 0) {
            var end = this.video.buffered.end(this.video.buffered.length - 1);
            var pct = (end / this.video.duration) * 100;
            this.progressBuffered.style.width = pct + '%';
        }
    };

    MoonyaVideoPlayer.prototype.updateTimeDisplay = function() {
        var current = this.formatTime(this.video.currentTime);
        var duration = this.formatTime(this.video.duration);
        this.container.querySelector('.mvp-time-current').textContent = current;
        this.container.querySelector('.mvp-time-duration').textContent = duration;
    };

    MoonyaVideoPlayer.prototype.formatTime = function(seconds) {
        if (!seconds || !isFinite(seconds)) return '0:00';
        seconds = Math.floor(seconds);
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    };

    MoonyaVideoPlayer.prototype.showControls = function() {
        var self = this;
        this.container.classList.add('mvp-controls-visible');
        clearTimeout(this.controlsTimer);
        if (this.isPlaying) {
            this.controlsTimer = setTimeout(function() {
                self.container.classList.remove('mvp-controls-visible');
                if (self.settingsPanel) self.settingsPanel.classList.remove('show');
            }, 3000);
        }
    };

    MoonyaVideoPlayer.prototype.destroy = function() {
        clearTimeout(this.controlsTimer);
        clearTimeout(this.longPressTimer);
        if (this.hls) {
            this.hls.destroy();
            this.hls = null;
        }
        if (this.video) {
            this.video.pause();
            this.video.src = '';
        }
        this.container.innerHTML = '';
    };

    MoonyaVideoPlayer.prototype.pause = function() {
        if (this.video) this.video.pause();
    };

    MoonyaVideoPlayer.prototype.play = function() {
        if (this.video) this.video.play().catch(function() {});
    };

    global.MoonyaVideoPlayer = MoonyaVideoPlayer;
})(window);
