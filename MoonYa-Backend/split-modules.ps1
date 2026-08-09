$sourceFile = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\index.php"
$baseDir = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\MoonYa-index"

$lines = [System.IO.File]::ReadAllLines($sourceFile)
$totalLines = $lines.Count
Write-Host "Total lines: $totalLines"

$utf8NoBom = New-Object System.Text.UTF8Encoding $false

# MODULES
Write-Host "=== MODULES ==="

$modules = @(
    @{ s=5353;  e=5389;  f="modules\_script1-header.php" },
    @{ s=5389;  e=5949;  f="modules\model-selector.php" },
    @{ s=5949;  e=7599;  f="modules\chat-history.php" },
    @{ s=7599;  e=7799;  f="modules\auth.php" },
    @{ s=7799;  e=8409;  f="modules\dom-init.php" },
    @{ s=8409;  e=9139;  f="modules\feature-modes.php" },
    @{ s=9139;  e=10749; f="modules\message-sender.php" },
    @{ s=10999; e=11999; f="modules\agent-status.php" },
    @{ s=11999; e=12299; f="modules\stream-processor.php" },
    @{ s=12299; e=13173; f="modules\code-block.php" },
    @{ s=13173; e=13557; f="modules\chat-render.php" },
    @{ s=13229; e=13517; f="modules\version-update.php" }
)

foreach ($spec in $modules) {
    $sb = New-Object System.Text.StringBuilder
    for ($i = $spec.s; $i -le $spec.e; $i++) {
        [void]$sb.AppendLine($lines[$i])
    }
    $target = Join-Path $baseDir $spec.f
    $dir = Split-Path $target -Parent
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    [System.IO.File]::WriteAllText($target, $sb.ToString(), $utf8NoBom)
    $count = $spec.e - $spec.s + 1
    Write-Host ("  Wrote {0} lines to {1}" -f $count, $target)
}

# UTILS
Write-Host "=== UTILS ==="

$utils = @(
    @{ s=13560; e=14125; f="utils\dynamic-island-player.php" },
    @{ s=14126; e=14366; f="modules\download.php" }
)

foreach ($spec in $utils) {
    $sb = New-Object System.Text.StringBuilder
    for ($i = $spec.s; $i -le $spec.e; $i++) {
        [void]$sb.AppendLine($lines[$i])
    }
    $target = Join-Path $baseDir $spec.f
    $dir = Split-Path $target -Parent
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    [System.IO.File]::WriteAllText($target, $sb.ToString(), $utf8NoBom)
    $count = $spec.e - $spec.s + 1
    Write-Host ("  Wrote {0} lines to {1}" -f $count, $target)
}

Write-Host "Done with modules and utils."
