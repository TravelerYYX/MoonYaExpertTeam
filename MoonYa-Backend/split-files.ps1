$sourceFile = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\index.php"
$baseDir = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\MoonYa-index"

$lines = [System.IO.File]::ReadAllLines($sourceFile)
$totalLines = $lines.Count
Write-Host "Total lines: $totalLines"

$utf8NoBom = New-Object System.Text.UTF8Encoding $false

# STYLES
Write-Host "=== STYLES ==="
$specs = @(
    @{ start=21;    end=59;    file="styles\base.php" },
    @{ start=61;    end=445;   file="styles\auth.php" },
    @{ start=446;   end=1099;  file="styles\sidebar.php" },
    @{ start=1189;  end=1619;  file="styles\chat.php" },
    @{ start=1619;  end=1843;  file="styles\agent-status.php" },
    @{ start=1843;  end=2244;  file="styles\animations.php" },
    @{ start=2244;  end=2919;  file="styles\music.php" },
    @{ start=2919;  end=3173;  file="styles\uploader.php" },
    @{ start=3173;  end=3264;  file="styles\feature-modes.php" },
    @{ start=3264;  end=3485;  file="styles\update-modal.php" },
    @{ start=3485;  end=3599;  file="styles\responsive.php" },
    @{ start=3599;  end=4499;  file="styles\dynamic-island.php" },
    @{ start=4499;  end=4751;  file="styles\animations-rest.php" }
)

foreach ($spec in $specs) {
    $sb = New-Object System.Text.StringBuilder
    for ($i = $spec.start; $i -le $spec.end; $i++) {
        [void]$sb.AppendLine($lines[$i])
    }
    $target = Join-Path $baseDir $spec.file
    $dir = Split-Path $target -Parent
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    [System.IO.File]::WriteAllText($target, $sb.ToString(), $utf8NoBom)
    $count = $spec.end - $spec.start + 1
    Write-Host ("  Wrote {0} lines to {1}" -f $count, $target)
}

# LAYOUTS
Write-Host "=== LAYOUTS ==="
$layouts = @(
    @{ start=4754;  end=4889;  file="layouts\container-start.php" },     # <body> + container + sidebar
    @{ start=4890;  end=4965;  file="layouts\auth-overlay.php" },          # auth overlay
    @{ start=4966;  end=5287;  file="layouts\main-content.php" },          # main content
    @{ start=5290;  end=5290;  file="layouts\toast.php" },                 # toast
    @{ start=5292;  end=5352;  file="layouts\dynamic-island.php" },        # dynamic island
    @{ start=14367; end=14373; file="layouts\video-player.php" }           # video player overlay
)

foreach ($spec in $layouts) {
    $sb = New-Object System.Text.StringBuilder
    for ($i = $spec.start; $i -le $spec.end; $i++) {
        [void]$sb.AppendLine($lines[$i])
    }
    $target = Join-Path $baseDir $spec.file
    $dir = Split-Path $target -Parent
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    [System.IO.File]::WriteAllText($target, $sb.ToString(), $utf8NoBom)
    $count = $spec.end - $spec.start + 1
    Write-Host ("  Wrote {0} lines to {1}" -f $count, $target)
}

Write-Host "Done with styles and layouts."
