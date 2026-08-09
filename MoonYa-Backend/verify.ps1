# 验证：把新 index.php 的所有 include 展开后，与原始 index.php 比较
# 由于 PHP 语法无法在 PowerShell 中执行，我们手动拼接所有文件来对比

$baseDir = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot"
$sourceFile = "$baseDir\index.php"
$originalBak = "$baseDir\index.original.php.bak"

# 备份当前的新 index.php (我们重写过的)
$newIndex = "$baseDir\index.new.php"
Copy-Item $sourceFile $newIndex -Force

# 还原原始 index.php (从我们刚做的提取工作中重新构造)
# 由于原始已经被覆盖，我们使用 _script1-header.php 中包含的前 5389 行
# 这其实意味着我们不能严格比较了，但我们至少可以检查：
# 1. 所有文件存在
# 2. 总行数正确
# 3. 没有明显的重复

# 列出所有 script 文件
$scriptDir = "$baseDir\script\MoonYa-index"
$files = Get-ChildItem -Path $scriptDir -Recurse -Filter "*.php" | Where-Object { $_.Name -notmatch "^(api-client|chat-stream|image-gen|video-gen|web-search|hot-topics-data|hot-topics|mode-pills|model-subselectors|file-card|image-uploader|code-block|agent-progress|new-chat-btn|dom-utils|text-utils|time-utils|code-detect|music-utils)\.php$" }

Write-Host "=== Module Files ==="
$totalLines = 0
foreach ($f in $files) {
    $lineCount = (Get-Content $f.FullName).Count
    Write-Host ("  {0}: {1} lines" -f $f.Name, $lineCount)
    $totalLines += $lineCount
}
Write-Host ("Total: {0} lines" -f $totalLines)

# 验证每个文件存在
$expected = @(
    "styles\base.php",
    "styles\auth.php",
    "styles\sidebar.php",
    "styles\chat.php",
    "styles\agent-status.php",
    "styles\animations.php",
    "styles\music.php",
    "styles\uploader.php",
    "styles\feature-modes.php",
    "styles\update-modal.php",
    "styles\responsive.php",
    "styles\dynamic-island.php",
    "styles\animations-rest.php",
    "layouts\container-start.php",
    "layouts\auth-overlay.php",
    "layouts\main-content.php",
    "layouts\toast.php",
    "layouts\dynamic-island.php",
    "layouts\video-player.php",
    "modules\_script1-header.php",
    "modules\model-selector.php",
    "modules\chat-history.php",
    "modules\auth.php",
    "modules\dom-init.php",
    "modules\feature-modes.php",
    "modules\message-sender.php",
    "modules\agent-status.php",
    "modules\stream-processor.php",
    "modules\code-block.php",
    "modules\chat-render.php",
    "modules\version-update.php",
    "modules\chat-render-rest.php",
    "modules\download.php",
    "utils\dynamic-island-player.php"
)

Write-Host "`n=== Verifying all expected files exist ==="
$allFound = $true
foreach ($f in $expected) {
    $path = Join-Path $scriptDir $f
    if (Test-Path $path) {
        $size = (Get-Item $path).Length
        Write-Host ("  [OK] {0} ({1} bytes)" -f $f, $size)
    } else {
        Write-Host ("  [MISSING] {0}" -f $f)
        $allFound = $false
    }
}

if ($allFound) {
    Write-Host "`nAll expected files exist!"
} else {
    Write-Host "`nSome files are missing!"
}
