$backupPath = "D:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\bin\x64\Debug\net8.0-windows10.0.19041.0\wwwroot\index.php"
$newPath = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\index.php"
$base = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\MoonYa-index"

# Read original
$origLines = [System.IO.File]::ReadAllLines($backupPath)
$origCount = $origLines.Count
Write-Host "Original lines (from backup): $origCount"

# Read new index.php
$newLinesArr = [System.IO.File]::ReadAllLines($newPath)
$newCount = $newLinesArr.Count
Write-Host "New index.php lines: $newCount"

# Build the simulated PHP output
# The new index.php output would be:
# 1. Lines 1-20 of new index.php (the static head, including <style> opening)
# 2. CSS content from all CSS files (indented 8 spaces to be inside <style>)
# 3. </style> from new index.php line 37
# 4. container.php (starts with </head><body>)
# 5. sidebar.php
# 6. auth-modal.php
# 7. main-content.php
# 8. toast.php
# 9. dynamic-island.php
# 10. All module files
# 11. video-player.php
# 12. </body></html> from new index.php lines 54-55

# Let me build the output and compare line by line

$output = @()

# Add new index.php lines 1-20 (up to and including the VOICE_CONFIG </script>)
# Then add `<style>` (line 21)
for ($i = 0; $i -lt 20; $i++) {
    $output += $newLinesArr[$i]
}
# Add <style>
$output += "    <style>"

# Add CSS files - but the original CSS is at lines 22-4775 of the original
# In the original, the CSS is indented 8 spaces (inside <style> at 4 spaces)
# In my CSS split files, the CSS is at indent 8 spaces (already indented)
# So I can add them as-is
$styleFiles = Get-ChildItem "$base\styles" -Filter "*.php" | Sort-Object Name
foreach ($f in $styleFiles) {
    $fileLines = [System.IO.File]::ReadAllLines($f.FullName)
    foreach ($line in $fileLines) {
        $output += $line
    }
}

# Add </style>
$output += "    </style>"

# Add container.php (starts with </head><body>)
$containerLines = [System.IO.File]::ReadAllLines("$base\layouts\container.php")
foreach ($line in $containerLines) {
    $output += $line
}

# Add sidebar.php, auth-modal.php, main-content.php, toast.php, dynamic-island.php
$otherLayouts = @("sidebar.php", "auth-modal.php", "main-content.php", "toast.php", "dynamic-island.php")
foreach ($f in $otherLayouts) {
    $fileLines = [System.IO.File]::ReadAllLines("$base\layouts\$f")
    foreach ($line in $fileLines) {
        $output += $line
    }
}

# Add module files in correct order
$moduleFiles = @(
    "$base\modules\script-1a-vars.php",
    "$base\modules\script-1b-features.php",
    "$base\modules\script-1c-save.php",
    "$base\modules\script-1d-dom.php",
    "$base\modules\script-1e-rest.php",
    "$base\modules\script-2-island.php",
    "$base\modules\script-2-download.php"
)
foreach ($f in $moduleFiles) {
    $fileLines = [System.IO.File]::ReadAllLines($f)
    foreach ($line in $fileLines) {
        $output += $line
    }
}

# Add video-player.php
$videoLines = [System.IO.File]::ReadAllLines("$base\layouts\video-player.php")
foreach ($line in $videoLines) {
    $output += $line
}

# Add </body></html>
$output += "</body>"
$output += "</html>"

# Compare
Write-Host "`n=== Comparison ==="
Write-Host "Output lines (simulated): $($output.Count)"
Write-Host "Original lines: $origCount"
Write-Host "Difference: $($output.Count - $origCount)"

# Compare line by line
$diffCount = 0
$firstDiff = -1
$maxLen = [Math]::Max($output.Count, $origCount)
for ($i = 0; $i -lt $maxLen; $i++) {
    $outLine = if ($i -lt $output.Count) { $output[$i] } else { "<MISSING>" }
    $origLine = if ($i -lt $origCount) { $origLines[$i] } else { "<MISSING>" }
    if ($outLine -ne $origLine) {
        $diffCount++
        if ($firstDiff -lt 0) {
            $firstDiff = $i
            Write-Host "First diff at line $($i+1):"
            Write-Host "  Output: '$outLine'"
            Write-Host "  Orig:   '$origLine'"
        }
    }
}

Write-Host "`nTotal differences: $diffCount"
if ($diffCount -gt 0 -and $firstDiff -ge 0) {
    Write-Host "Showing first 10 diffs:"
    $diffShown = 0
    for ($i = $firstDiff; $i -lt $maxLen -and $diffShown -lt 10; $i++) {
        $outLine = if ($i -lt $output.Count) { $output[$i] } else { "<MISSING>" }
        $origLine = if ($i -lt $origCount) { $origLines[$i] } else { "<MISSING>" }
        if ($outLine -ne $origLine) {
            Write-Host "  Line $($i+1):"
            Write-Host "    Output: '$outLine'"
            Write-Host "    Orig:   '$origLine'"
            $diffShown++
        }
    }
}
