<#
.SYNOPSIS
Run the portable negative pin probes from verify-runtime.py with the Windows preflight.
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/common.ps1"
$root = Get-RepositoryRoot
$php = Get-HerdPhp
$fixture = [IO.Path]::GetFullPath((Join-Path $env:TEMP ('st-windows-runtime-' + [Guid]::NewGuid().ToString('N'))))
try {
    [void][IO.Directory]::CreateDirectory((Join-Path $fixture 'scripts/windows'))
    foreach ($relative in @('.php-version', 'composer.json', 'composer.lock', 'scripts/windows/runtime.php')) {
        [IO.File]::Copy((Join-Path $root $relative), (Join-Path $fixture $relative))
    }
    $scriptFile = Join-Path $fixture 'scripts/windows/runtime.php'
    $lockFile = Join-Path $fixture 'composer.lock'
    $lock = Get-Content -LiteralPath $lockFile -Raw | ConvertFrom-Json
    $lock.'platform-overrides'.php = '8.4.1'
    [IO.File]::WriteAllText($lockFile, ($lock | ConvertTo-Json -Depth 100), (New-Object Text.UTF8Encoding($false)))
    $result = Invoke-NativeResult $php @($scriptFile)
    if ($result.ExitCode -eq 0 -or ($result.Output + $result.Error) -notmatch 'pins must agree') { throw 'Stale lockfile pin was accepted.' }
    Write-Host 'PASS stale Composer lock pin rejected.'
    [IO.File]::WriteAllText((Join-Path $fixture '.php-version'), '8.5.0')
    $manifestFile = Join-Path $fixture 'composer.json'
    $manifest = Get-Content -LiteralPath $manifestFile -Raw | ConvertFrom-Json
    $manifest.require.php = '~8.5.0'; $manifest.config.platform.php = '8.5.0'
    $lock.platform.php = '~8.5.0'; $lock.'platform-overrides'.php = '8.5.0'
    [IO.File]::WriteAllText($manifestFile, ($manifest | ConvertTo-Json -Depth 100), (New-Object Text.UTF8Encoding($false)))
    [IO.File]::WriteAllText($lockFile, ($lock | ConvertTo-Json -Depth 100), (New-Object Text.UTF8Encoding($false)))
    $result = Invoke-NativeResult $php @($scriptFile)
    if ($result.ExitCode -eq 0 -or ($result.Output + $result.Error) -notmatch 'pins must agree') { throw 'Untested PHP patch was accepted.' }
    Write-Host 'PASS untested PHP patch rejected even when manifests agree.'
} finally {
    if ([IO.Path]::GetDirectoryName($fixture) -ne [IO.Path]::GetFullPath($env:TEMP).TrimEnd('\') -or -not [IO.Path]::GetFileName($fixture).StartsWith('st-windows-runtime-')) { throw 'Runtime fixture cleanup guard failed.' }
    Assert-PlainPath $fixture
    if (Test-Path -LiteralPath $fixture) { Remove-Item -LiteralPath $fixture -Recurse -Force }
}
