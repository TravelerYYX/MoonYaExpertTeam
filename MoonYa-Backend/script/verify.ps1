$base = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\MoonYa-index"
$origPath = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\index.php"
$newPath = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\index.php"

# Simulate the new index.php by concatenating all split files in order
$content = ""

# Lines 1-20 of the new index.php (up to and including </script>)
$newIndex = [System.IO.File]::ReadAllText($newPath)
$lines = [System.IO.File]::ReadAllLines($newPath)

# Read all split files in order and concatenate
$styleFiles = Get-ChildItem "$base\styles" -Filter "*.php" | Sort-Object Name
$layoutFiles = @(
    "container.php",
    "sidebar.php",
    "auth-modal.php",
    "main-content.php",
    "toast.php",
    "dynamic-island.php"
)
$moduleFiles = @(
    "script-1a-vars.php",
    "script-1b-features.php",
    "script-1c-save.php",
    "script-1d-dom.php",
    "script-1e-rest.php",
    "script-2-island.php",
    "script-2-download.php"
)
$lastLayoutFiles = @("video-player.php")

# Count total lines in all split files
$totalSplitLines = 0
$totalSplitBytes = 0

Write-Host "=== Style files ==="
foreach ($f in $styleFiles) {
    $content = [System.IO.File]::ReadAllText($f.FullName)
    $lines = [System.IO.File]::ReadAllLines($f.FullName)
    Write-Host "  $($f.Name): $($lines.Count) lines, $($content.Length) bytes"
    $totalSplitLines += $lines.Count
    $totalSplitBytes += $content.Length
}

Write-Host "`n=== Layout files ==="
foreach ($f in $layoutFiles) {
    $content = [System.IO.File]::ReadAllText("$base\layouts\$f")
    $lines = [System.IO.File]::ReadAllLines("$base\layouts\$f")
    Write-Host "  $f : $($lines.Count) lines, $($content.Length) bytes"
    $totalSplitLines += $lines.Count
    $totalSplitBytes += $content.Length
}

Write-Host "`n=== Module files ==="
foreach ($f in $moduleFiles) {
    $content = [System.IO.File]::ReadAllText("$base\modules\$f")
    $lines = [System.IO.File]::ReadAllLines("$base\modules\$f")
    Write-Host "  $f : $($lines.Count) lines, $($content.Length) bytes"
    $totalSplitLines += $lines.Count
    $totalSplitBytes += $content.Length
}

Write-Host "`n=== Last layout files ==="
foreach ($f in $lastLayoutFiles) {
    $content = [System.IO.File]::ReadAllText("$base\layouts\$f")
    $lines = [System.IO.File]::ReadAllLines("$base\layouts\$f")
    Write-Host "  $f : $($lines.Count) lines, $($content.Length) bytes"
    $totalSplitLines += $lines.Count
    $totalSplitBytes += $content.Length
}

Write-Host "`n=== Summary ==="
Write-Host "Total lines in split files: $totalSplitLines"
Write-Host "Total bytes in split files: $totalSplitBytes"

$origBytes = (Get-Item $origPath).Length
$origLines = [System.IO.File]::ReadAllLines($origPath).Count
Write-Host "Original lines: $origLines"
Write-Host "Original bytes: $origBytes"

# Read new index.php lines
$newLines = [System.IO.File]::ReadAllLines($newPath).Count
Write-Host "New index.php lines: $newLines"

# Now simulate the PHP output by building it
# This is the lines 1-20 of new index.php (static) + the includes + lines 54-55
# Lines 1-20 of new index.php (PHP config + head) is 20 lines
# But each include adds 1 PHP line
# Total new index.php has 55 lines

# Static part: lines 1-20 from new index.php (the head)
# Includes: 15 CSS + 6 layouts (before scripts) + 7 modules + 1 video + closing tags
# Actually the new index.php is 55 lines

# Let me simulate: build a virtual HTML output by combining content
$virtualOutput = ""

# Add lines 1-20 of new index.php (until end of head's first script)
# But these are the SAME as original lines 1-20
$origLinesArr = [System.IO.File]::ReadAllLines($origPath)
for ($i = 0; $i -lt 20; $i++) {
    $virtualOutput += $origLinesArr[$i] + "`n"
}

# Add CSS files (with 8-space indent for inside <style>)
foreach ($f in $styleFiles) {
    $content = [System.IO.File]::ReadAllText($f.FullName)
    # Remove trailing newline
    $content = $content.TrimEnd("`n")
    # Indent each line by 8 spaces
    $indented = ($content -split "`n") | ForEach-Object { if ($_ -ne "") { "        $_" } else { $_ } }
    $virtualOutput += ($indented -join "`n") + "`n"
}

# Add </style> and </head>
$virtualOutput += "    </style>`n"
$virtualOutput += "</head>`n"
$virtualOutput += "<body>`n"

# Add layout files (container.php starts with </head><body>... so we need to handle this)
# Actually container.php starts with </head>, so we should NOT add </head> and <body> above
# Let me re-examine container.php

$containerContent = [System.IO.File]::ReadAllText("$base\layouts\container.php")
Write-Host "`n=== Container.php first 3 lines ==="
$containerLines = [System.IO.File]::ReadAllLines("$base\layouts\container.php")
for ($i = 0; $i -lt 3; $i++) {
    Write-Host "  Line $($i+1): '$($containerLines[$i])'"
}
