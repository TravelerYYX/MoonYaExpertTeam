$sourceFile = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\index.php"
$baseDir = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\MoonYa-index"

$lines = [System.IO.File]::ReadAllLines($sourceFile)
$utf8NoBom = New-Object System.Text.UTF8Encoding $false

# chat-render.php: 0-based 13173-13310 (1-based 13174-13311) - end with blank line
$sb = New-Object System.Text.StringBuilder
for ($i = 13173; $i -le 13310; $i++) { [void]$sb.AppendLine($lines[$i]) }
$target = "$baseDir\modules\chat-render.php"
[System.IO.File]::WriteAllText($target, $sb.ToString(), $utf8NoBom)
Write-Host "  Re-wrote 138 lines to chat-render.php"

# version-update.php: 0-based 13311-13516 (1-based 13312-13517) - start with comment
$sb = New-Object System.Text.StringBuilder
for ($i = 13311; $i -le 13516; $i++) { [void]$sb.AppendLine($lines[$i]) }
$target = "$baseDir\modules\version-update.php"
[System.IO.File]::WriteAllText($target, $sb.ToString(), $utf8NoBom)
Write-Host "  Re-wrote 206 lines to version-update.php"

# chat-render-rest.php: 0-based 13517-13557 (1-based 13518-13558) - tail
$sb = New-Object System.Text.StringBuilder
for ($i = 13517; $i -le 13557; $i++) { [void]$sb.AppendLine($lines[$i]) }
$target = "$baseDir\modules\chat-render-rest.php"
[System.IO.File]::WriteAllText($target, $sb.ToString(), $utf8NoBom)
Write-Host "  Re-wrote 41 lines to chat-render-rest.php"

Write-Host "Done."
