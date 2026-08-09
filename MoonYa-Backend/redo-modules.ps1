$sourceFile = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\index.php"
$baseDir = "d:\Project\Project\MoonYa\MoonYa-Win\MoonYa-Solution\MoonYa\wwwroot\script\MoonYa-index"

$lines = [System.IO.File]::ReadAllLines($sourceFile)
$utf8NoBom = New-Object System.Text.UTF8Encoding $false

# All ranges are 0-based, inclusive on start, exclusive on end
# The modules cover the full range of the first script block
$modules = @(
    @{ s=5353;  e=5388;  f="modules\_script1-header.php" },         # <script>...<empty line>
    @{ s=5389;  e=5948;  f="modules\model-selector.php" },           # // 模型配置 ... line before chat-history
    @{ s=5949;  e=7598;  f="modules\chat-history.php" },             # chat history
    @{ s=7599;  e=7798;  f="modules\auth.php" },                     # auth overlay
    @{ s=7799;  e=8408;  f="modules\dom-init.php" },                 # DOM init
    @{ s=8409;  e=9138;  f="modules\feature-modes.php" },            # feature modes
    @{ s=9139;  e=10998; f="modules\message-sender.php" },          # message sender (sendMessage function)
    @{ s=10999; e=11998; f="modules\agent-status.php" },             # agent status
    @{ s=11999; e=12298; f="modules\stream-processor.php" },         # stream processor
    @{ s=12299; e=13172; f="modules\code-block.php" },               # code block
    @{ s=13173; e=13310; f="modules\chat-render.php" },              # chat render
    @{ s=13311; e=13516; f="modules\version-update.php" },           # version update IIFE
    @{ s=13517; e=13557; f="modules\chat-render-rest.php" }          # tail of chat render
)

foreach ($spec in $modules) {
    $sb = New-Object System.Text.StringBuilder
    for ($i = $spec.s; $i -le $spec.e; $i++) { [void]$sb.AppendLine($lines[$i]) }
    $target = Join-Path $baseDir $spec.f
    [System.IO.File]::WriteAllText($target, $sb.ToString(), $utf8NoBom)
    $count = $spec.e - $spec.s + 1
    Write-Host ("  Wrote {0} lines to {1}" -f $count, $target)
}

# Verify coverage
$total = 0
foreach ($spec in $modules) { $total += ($spec.e - $spec.s + 1) }
Write-Host ("Total covered lines: {0}" -f $total)
# Expected: 5353 to 13557 = 8205 lines
$expected = 13557 - 5353 + 1
Write-Host ("Expected: {0}" -f $expected)

# UTILS
$utils = @(
    @{ s=13560; e=14125; f="utils\dynamic-island-player.php" },
    @{ s=14126; e=14366; f="modules\download.php" }
)
foreach ($spec in $utils) {
    $sb = New-Object System.Text.StringBuilder
    for ($i = $spec.s; $i -le $spec.e; $i++) { [void]$sb.AppendLine($lines[$i]) }
    $target = Join-Path $baseDir $spec.f
    [System.IO.File]::WriteAllText($target, $sb.ToString(), $utf8NoBom)
    $count = $spec.e - $spec.s + 1
    Write-Host ("  Wrote {0} lines to {1}" -f $count, $target)
}

Write-Host "Done."
