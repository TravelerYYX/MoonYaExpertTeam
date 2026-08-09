param(
    [Parameter(Mandatory = $true)][string]$ProjectDirectory,
    [Parameter(Mandatory = $true)][string]$OutputDirectory
)

$ErrorActionPreference = 'Stop'
$backend = [System.IO.Path]::GetFullPath((Join-Path $ProjectDirectory '..\..\..\MoonYa-Backend'))
$output = [System.IO.Path]::GetFullPath($OutputDirectory)
$wwwroot = Join-Path $output 'wwwroot'

$forbiddenRuntimeDirectories = @(
    'admin\logs',
    'downloads',
    'uploads',
    'tests'
)
foreach ($relative in $forbiddenRuntimeDirectories) {
    $candidate = Join-Path $wwwroot $relative
    if ((Test-Path -LiteralPath $candidate) -and
        (Get-ChildItem -LiteralPath $candidate -Recurse -File -ErrorAction SilentlyContinue | Select-Object -First 1)) {
        throw "Published runtime contains private or development files: $relative"
    }
}

$relativeFiles = @(
    'Services\TeamCoordinator.php',
    'Services\ToolGateway.php',
    'Services\TeamRepository.php',
    'Services\RiskPolicy.php',
    'api\team.php',
    'assets\agents\moonya.png',
    'assets\agents\app-agent.png',
    'assets\agents\computer-agent.png',
    'assets\agents\browser-agent.png',
    'assets\agents\file-agent.png',
    'assets\agents\search-agent.png',
    'assets\agents\code-agent.png'
)

$verified = [ordered]@{}
foreach ($relative in $relativeFiles) {
    $source = Join-Path $backend $relative
    $deployed = Join-Path $wwwroot $relative
    if (!(Test-Path -LiteralPath $source) -or !(Test-Path -LiteralPath $deployed)) {
        throw "Missing team runtime file: $relative"
    }
    $sourceHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $source).Hash
    $deployedHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $deployed).Hash
    if ($sourceHash -ne $deployedHash) {
        throw "Team runtime hash mismatch: $relative"
    }
    $verified[$relative.Replace('\', '/')] = $sourceHash
}

$mcpSource = Join-Path $ProjectDirectory 'Services\McpClientManager.cs'
$launcherBinary = Join-Path $output 'MoonYa.dll'
if (!(Test-Path -LiteralPath $mcpSource) -or !(Test-Path -LiteralPath $launcherBinary)) {
    throw 'Missing MCP client source or compiled MoonYa.dll'
}

$manifest = [ordered]@{
    protocol = 'multi-agent-v1'
    generated_at = [DateTime]::UtcNow.ToString('o')
    deployed_files = $verified
    mcp_client_source_sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $mcpSource).Hash
    launcher_binary_sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $launcherBinary).Hash
}
$manifestPath = Join-Path $output 'team-runtime-hashes.json'
$manifest | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $manifestPath -Encoding UTF8
