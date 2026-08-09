$src = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\index.php"
$base = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\MoonYa-index"

# Read all lines
$lines = [System.IO.File]::ReadAllLines($src)
$totalLines = $lines.Count
Write-Host "Total source lines: $totalLines"

# Define split points (start, end, target_file) - CONTIGUOUS
$splits = @(
    @{ Start = 21;   End = 61;   Path = "styles\css-01-base.php" },
    @{ Start = 62;   End = 445;  Path = "styles\css-02-auth.php" },
    @{ Start = 446;  End = 612;  Path = "styles\css-03-floating.php" },
    @{ Start = 613;  End = 1086; Path = "styles\css-04-sidebar.php" },
    @{ Start = 1087; End = 1186; Path = "styles\css-05-toast.php" },
    @{ Start = 1187; End = 1616; Path = "styles\css-06-main.php" },
    @{ Start = 1617; End = 1664; Path = "styles\css-07-agent.php" },
    @{ Start = 1665; End = 1840; Path = "styles\css-08-code.php" },
    @{ Start = 1841; End = 2241; Path = "styles\css-09-animations.php" },
    @{ Start = 2242; End = 2919; Path = "styles\css-10-music.php" },
    @{ Start = 2920; End = 3040; Path = "styles\css-11-uploader.php" },
    @{ Start = 3041; End = 3287; Path = "styles\css-12-features.php" },
    @{ Start = 3288; End = 3508; Path = "styles\css-13-update.php" },
    @{ Start = 3509; End = 4586; Path = "styles\css-14-responsive.php" },
    @{ Start = 4587; End = 4775; Path = "styles\css-15-result.php" },
    @{ Start = 4776; End = 4790; Path = "layouts\container.php" },
    @{ Start = 4791; End = 4908; Path = "layouts\sidebar.php" },
    @{ Start = 4909; End = 4984; Path = "layouts\auth-modal.php" },
    @{ Start = 4985; End = 5307; Path = "layouts\main-content.php" },
    @{ Start = 5308; End = 5310; Path = "layouts\toast.php" },
    @{ Start = 5311; End = 5372; Path = "layouts\dynamic-island.php" },
    @{ Start = 5373; End = 5986; Path = "modules\script-1a-vars.php" },
    @{ Start = 5987; End = 6782; Path = "modules\script-1b-features.php" },
    @{ Start = 6783; End = 8069; Path = "modules\script-1c-save.php" },
    @{ Start = 8070; End = 10837;Path = "modules\script-1d-dom.php" },
    @{ Start = 10838;End = 13675;Path = "modules\script-1e-rest.php" },
    @{ Start = 13676;End = 14241;Path = "modules\script-2-island.php" },
    @{ Start = 14242;End = 14484;Path = "modules\script-2-download.php" },
    @{ Start = 14485;End = 14490;Path = "layouts\video-player.php" }
)

# Check if the last line of the source (line 14492) is `</html>` with no trailing newline
# So we need to handle the line 14492 specially: it's NOT followed by \n
# But our splits don't include line 14492 directly. The last file (video-player) ends at 14490
# The new index.php will include `</body>` (14491) and `</html>` (14492)
# So the video-player file should have trailing \n (because line 14490 has \n in original)
# And the new index.php should end with `</body>\n</html>` (no trailing newline on </html>)

# Write the files
Write-Host "`n=== Writing files ==="
foreach ($split in $splits) {
    $start = $split.Start - 1
    $end = $split.End - 1
    $count = $end - $start + 1
    $slice = $lines[$start..$end]
    
    # Use String.Join + trailing newline to preserve line endings correctly
    # This ensures every line in the slice is followed by \n in the output
    $content = [String]::Join("`n", $slice) + "`n"
    
    $targetPath = Join-Path $base $split.Path
    $targetDir = Split-Path $targetPath -Parent
    if (-not (Test-Path $targetDir)) {
        New-Item -ItemType Directory -Force -Path $targetDir | Out-Null
    }
    
    # Write as UTF-8 without BOM
    $utf8NoBom = New-Object System.Text.UTF8Encoding $False
    [System.IO.File]::WriteAllText($targetPath, $content, $utf8NoBom)
    
    # Verify
    $readBack = [System.IO.File]::ReadAllLines($targetPath).Count
    $expected = $count
    if ($readBack -ne $expected) {
        Write-Host "MISMATCH: $($split.Path) - expected $expected, got $readBack"
    } else {
        Write-Host "OK: $($split.Path) ($count lines)"
    }
}

# Final verification
Write-Host "`n=== Final Verification ==="
$totalWritten = 0
$totalExpected = 0
foreach ($split in $splits) {
    $targetPath = Join-Path $base $split.Path
    $fileLines = [System.IO.File]::ReadAllLines($targetPath).Count
    $expected = $split.End - $split.Start + 1
    $totalWritten += $fileLines
    $totalExpected += $expected
    if ($fileLines -ne $expected) {
        Write-Host "MISMATCH: $($split.Path) - expected $expected, got $fileLines"
    }
}
Write-Host "Total lines written: $totalWritten (expected: $totalExpected)"

# Sum the bytes
$totalBytes = 0
foreach ($split in $splits) {
    $targetPath = Join-Path $base $split.Path
    $totalBytes += (Get-Item $targetPath).Length
}
$origBytes = (Get-Item $src).Length
Write-Host "Total bytes written: $totalBytes (original: $origBytes)"
