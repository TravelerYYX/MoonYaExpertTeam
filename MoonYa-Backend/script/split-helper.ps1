$src = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\index.php"
$base = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\MoonYa-index"

# Read all lines
$lines = [System.IO.File]::ReadAllLines($src)
$totalLines = $lines.Count
Write-Host "Total lines: $totalLines"

# Define split points (start, end, target_file)
# Format: array of tuples (startLine, endLine, relativePath)
$splits = @(
    # CSS files (lines 21-4775 of the style block, content lines 22-4774)
    @{ Start = 21;   End = 61;   Path = "styles\css-01-base.php" },
    @{ Start = 62;   End = 445;  Path = "styles\css-02-auth.php" },
    @{ Start = 446;  End = 612;  Path = "styles\css-03-floating.php" },
    @{ Start = 613;  End = 1086; Path = "styles\css-04-sidebar.php" },
    @{ Start = 1087; End = 1186; Path = "styles\css-05-toast.php" },
    @{ Start = 1187; End = 1616; Path = "styles\css-06-main.php" },
    @{ Start = 1617; End = 1664; Path = "styles\css-07-agent.php" },
    @{ Start = 1666; End = 1840; Path = "styles\css-08-code.php" },
    @{ Start = 1841; End = 2241; Path = "styles\css-09-animations.php" },
    @{ Start = 2242; End = 2919; Path = "styles\css-10-music.php" },
    @{ Start = 2920; End = 3040; Path = "styles\css-11-uploader.php" },
    @{ Start = 3041; End = 3287; Path = "styles\css-12-features.php" },
    @{ Start = 3288; End = 3508; Path = "styles\css-13-update.php" },
    @{ Start = 3509; End = 4586; Path = "styles\css-14-responsive.php" },
    @{ Start = 4587; End = 4774; Path = "styles\css-15-result.php" },
    # HTML layout files
    @{ Start = 4776; End = 4790; Path = "layouts\container.php" },
    @{ Start = 4791; End = 4908; Path = "layouts\sidebar.php" },
    @{ Start = 4909; End = 4984; Path = "layouts\auth-modal.php" },
    @{ Start = 4985; End = 5307; Path = "layouts\main-content.php" },
    @{ Start = 5309; End = 5310; Path = "layouts\toast.php" },
    @{ Start = 5312; End = 5372; Path = "layouts\dynamic-island.php" },
    # JS module files (script 1: 5373-13675)
    @{ Start = 5373; End = 5986; Path = "modules\script-1a-vars.php" },
    @{ Start = 5987; End = 6782; Path = "modules\script-1b-features.php" },
    @{ Start = 6783; End = 8069; Path = "modules\script-1c-save.php" },
    @{ Start = 8070; End = 10837;Path = "modules\script-1d-dom.php" },
    @{ Start = 10838;End = 13675;Path = "modules\script-1e-rest.php" },
    # JS module file (script 2: 13677-14484)
    @{ Start = 13677;End = 14241;Path = "modules\script-2-island.php" },
    @{ Start = 14243;End = 14484;Path = "modules\script-2-download.php" },
    # Video player HTML
    @{ Start = 14485;End = 14490;Path = "layouts\video-player.php" }
)

# Verify no gaps/overlaps
$sortedSplits = $splits | Sort-Object { $_.Start }
$prevEnd = 0
foreach ($split in $sortedSplits) {
    $start = $split.Start
    $end = $split.End
    if ($start -le $prevEnd) {
        Write-Host "OVERLAP/GAP detected: $start to $end (prevEnd: $prevEnd)"
    } else {
        if ($start - $prevEnd -gt 1) {
            Write-Host "GAP detected: $prevEnd -> $start (missing $($start - $prevEnd - 1) lines)"
        }
    }
    $prevEnd = $end
}
Write-Host "Last split ends at: $prevEnd (total: $totalLines)"

# Calculate total lines split
$totalSplit = 0
foreach ($split in $splits) {
    $totalSplit += ($split.End - $split.Start + 1)
}
Write-Host "Total lines in splits: $totalSplit"
Write-Host "Lines not split (1-4, 5-20, 14491-14492, and gaps):"
$coveredLines = @{}
foreach ($split in $splits) {
    for ($i = $split.Start; $i -le $split.End; $i++) {
        $coveredLines[$i] = $true
    }
}
$missing = @()
for ($i = 1; $i -le $totalLines; $i++) {
    if (-not $coveredLines.ContainsKey($i)) {
        $missing += $i
    }
}
Write-Host "Missing lines count: $($missing.Count)"
if ($missing.Count -lt 30) {
    Write-Host "Missing lines: $($missing -join ', ')"
}
