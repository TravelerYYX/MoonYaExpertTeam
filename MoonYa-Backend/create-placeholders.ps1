$baseDir = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\MoonYa-index"

# DATA - 6 个占位符
$dataFiles = @(
    "data\api-client.php|API client logic is currently inlined in modules/message-sender.php and modules/agent-status.php",
    "data\chat-stream.php|SSE stream parsing is currently inlined in modules/stream-processor.php",
    "data\image-gen.php|Image generation logic is currently inlined in modules/agent-status.php",
    "data\video-gen.php|Video generation parameters are currently in modules/feature-modes.php and layouts/main-content.php",
    "data\web-search.php|Web search is currently inlined in modules/agent-status.php",
    "data\hot-topics-data.php|Hot topics rendering is currently inlined in modules/chat-render.php"
)

foreach ($f in $dataFiles) {
    $parts = $f -split "\|"
    $file = $parts[0]
    $desc = $parts[1]
    $target = Join-Path $baseDir $file
    $dir = Split-Path $target -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $content = "<?php`r`n// $desc`r`n"
    [System.IO.File]::WriteAllText($target, $content, [System.Text.UTF8Encoding]::new($false))
    Write-Host "Created $target"
}

# COMPONENTS - 8 个占位符
$componentFiles = @(
    "components\hot-topics.php|Hot topics buttons (lines 4990-4991 in source) are currently rendered in layouts/main-content.php and the data is populated by modules/chat-render.php",
    "components\mode-pills.php|Mode toggle pills (lines 4790-4802 in source) are currently in layouts/container-start.php",
    "components\model-subselectors.php|Model subselectors (Kimi, DeepSeek, MiniMax, GLM) are in layouts/main-content.php",
    "components\file-card.php|File card UI is in layouts/main-content.php",
    "components\image-uploader.php|Image uploader UI and logic are in layouts/main-content.php and modules/message-sender.php",
    "components\code-block.php|Code block rendering is in modules/code-block.php and modules/chat-render.php",
    "components\agent-progress.php|Agent progress UI is in modules/agent-status.php and styles/agent-status.php",
    "components\new-chat-btn.php|New chat button UI is in layouts/container-start.php and main-content.php"
)

foreach ($f in $componentFiles) {
    $parts = $f -split "\|"
    $file = $parts[0]
    $desc = $parts[1]
    $target = Join-Path $baseDir $file
    $dir = Split-Path $target -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $content = "<?php`r`n// $desc`r`n"
    [System.IO.File]::WriteAllText($target, $content, [System.Text.UTF8Encoding]::new($false))
    Write-Host "Created $target"
}

# UTILS - 5 个空占位符 (dynamic-island-player.php 已有内容)
$utilsFiles = @(
    "utils\dom-utils.php|Shared DOM helpers - currently inlined across modules",
    "utils\text-utils.php|Text utilities - currently inlined across modules",
    "utils\time-utils.php|Time formatting - currently inlined",
    "utils\code-detect.php|Code language detection is in modules/code-block.php",
    "utils\music-utils.php|Music utilities are in utils/dynamic-island-player.php and modules/feature-modes.php"
)

foreach ($f in $utilsFiles) {
    $parts = $f -split "\|"
    $file = $parts[0]
    $desc = $parts[1]
    $target = Join-Path $baseDir $file
    $dir = Split-Path $target -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $content = "<?php`r`n// $desc`r`n"
    [System.IO.File]::WriteAllText($target, $content, [System.Text.UTF8Encoding]::new($false))
    Write-Host "Created $target"
}

Write-Host "All placeholders created."
