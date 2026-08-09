
    <!-- 灵动岛音乐播放器 -->
    <div class="dynamic-island compact" id="dynamicIsland">
        <div class="island-compact-content">
            <img src="/image/yax.png" alt="album" class="island-album-art-small" id="islandAlbumArtSmall">
            <div class="island-waveform">
                <div class="island-wave-bar"></div>
                <div class="island-wave-bar"></div>
                <div class="island-wave-bar"></div>
                <div class="island-wave-bar"></div>
                <div class="island-wave-bar"></div>
            </div>
        </div>
        <div class="island-expanded-content">
            <div class="island-player-header">
                <img src="/image/yax.png" alt="album" class="island-album-art-large" id="islandAlbumArtLarge">
                <div class="island-song-info">
                    <div class="island-song-title" id="islandSongTitle">未在播放</div>
                    <div class="island-artist-name" id="islandArtistName">--</div>
                </div>
            </div>
            <div class="island-progress-container">
                <div class="island-progress-time">
                    <span id="islandCurrentTime">0:00</span>
                    <span id="islandTotalTime">0:00</span>
                </div>
                <div class="island-progress-bar" id="islandProgressBar">
                    <div class="island-progress-fill" id="islandProgressFill"></div>
                </div>
            </div>
            <div class="island-controls">
                <img src="/image/yax.png" alt="logo" class="island-logo-icon">
                <button class="island-control-btn" id="islandPrevBtn">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
                </button>
                <button class="island-control-btn island-btn-play" id="islandPlayBtn">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" id="islandPlayIcon"><path d="M8 5v14l11-7z"/></svg>
                </button>
                <button class="island-control-btn" id="islandNextBtn">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
                </button>
                <button class="island-pip-btn" id="islandPipBtn" title="弹出灵动岛">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11h-8v2h8v-2zm4-6H1v16h22V5zm-2 14H3V7h18v12z"/></svg>
                </button>
            </div>
        </div>
        <div class="island-search-content" id="islandSearchContent">
            <svg class="island-search-icon" id="islandSearchIcon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.35-4.35"></path></svg>
            <input type="text" class="island-search-input" id="islandSearchInput" placeholder="搜索歌曲..." autocomplete="off">
            <span class="island-search-cancel" id="islandSearchCancel">取消</span>
        </div>
    </div>

    <div class="island-popped-status" id="islandPoppedStatus">
        <span>灵动岛已弹出</span>
        <button class="island-popped-close" id="islandPoppedCloseBtn">关闭</button>
    </div>

    <div class="island-no-pip-tip" id="islandNoPipTip">
        <span>当前浏览器不支持画中画弹出，请使用 Chrome 或 Edge</span>
    </div>
    
