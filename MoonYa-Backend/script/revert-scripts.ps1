Write-Host "Reverting script-2-island.php and script-2-download.php to original content..."
Write-Host "Will use backup file to restore the exact content."

# Re-extract from backup
$src = "D:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\bin\x64\Debug\net8.0-windows10.0.19041.0\wwwroot\index.php"
$base = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\MoonYa-index"

$lines = [System.IO.File]::ReadAllLines($src)
$utf8NoBom = New-Object System.Text.UTF8Encoding $False

# script-2-island.php: lines 13676-14241 (566 lines)
# This is from the BACKUP, not the corrupted index.php
$slice1 = $lines[13675..14240]
$content1 = [String]::Join("`n", $slice1) + "`n"
[System.IO.File]::WriteAllText("$base\modules\script-2-island.php", $content1, $utf8NoBom)
Write-Host "Wrote script-2-island.php: $($slice1.Count) lines"

# script-2-download.php: lines 14242-14484 (243 lines)
$slice2 = $lines[14241..14483]
$content2 = [String]::Join("`n", $slice2) + "`n"
[System.IO.File]::WriteAllText("$base\modules\script-2-download.php", $content2, $utf8NoBom)
Write-Host "Wrote script-2-download.php: $($slice2.Count) lines"

# Verify
$island = [System.IO.File]::ReadAllLines("$base\modules\script-2-island.php")
$download = [System.IO.File]::ReadAllLines("$base\modules\script-2-download.php")
Write-Host "island: $($island.Count) lines, first: '$($island[0])', last: '$($island[-1])'"
Write-Host "download: $($download.Count) lines, first: '$($download[0])', last: '$($download[-1])'"
