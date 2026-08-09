$ErrorActionPreference = 'Stop'

$projectPath = Join-Path $PSScriptRoot 'MoonYa\MoonYa.csproj'
$outputDirectory = Join-Path $PSScriptRoot 'MoonYa\bin\x64\Debug\net8.0-windows10.0.19041.0'
$executablePath = Join-Path $outputDirectory 'MoonYa.exe'

dotnet build $projectPath -c Debug -p:Platform=x64
if ($LASTEXITCODE -ne 0) {
    throw 'MoonYa x64 Debug build failed.'
}
if (-not (Test-Path -LiteralPath $executablePath)) {
    throw "MoonYa x64 Debug executable is missing: $executablePath"
}

Start-Process -FilePath $executablePath -WorkingDirectory $outputDirectory
