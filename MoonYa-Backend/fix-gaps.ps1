$sourceFile = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\index.php"
$baseDir = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\MoonYa-index"

$lines = [System.IO.File]::ReadAllLines($sourceFile)
$utf8NoBom = New-Object System.Text.UTF8Encoding $false

# Re-extract message-sender.php to include the sendMessage function (lines 9140-10999 in 1-based, 9139-10998 in 0-based)
$sb = New-Object System.Text.StringBuilder
for ($i = 9139; $i -le 10998; $i++) { [void]$sb.AppendLine($lines[$i]) }
$target = "$baseDir\modules\message-sender.php"
[System.IO.File]::WriteAllText($target, $sb.ToString(), $utf8NoBom)
Write-Host "  Re-wrote message-sender.php: 1860 lines"

# agent-status.php now starts at 0-based 10999 (1-based 11000)
$sb = New-Object System.Text.StringBuilder
for ($i = 10999; $i -le 11999; $i++) { [void]$sb.AppendLine($lines[$i]) }
$target = "$baseDir\modules\agent-status.php"
[System.IO.File]::WriteAllText($target, $sb.ToString(), $utf8NoBom)
Write-Host "  Re-wrote agent-status.php: 1001 lines"

Write-Host "Done."
