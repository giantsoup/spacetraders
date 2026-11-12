<#
.SYNOPSIS
Run Composer with pinned Herd PHP, forwarding all arguments and its exit code.
.EXAMPLE
.\scripts\windows\composer.ps1 install --no-interaction
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
try {
    . "$PSScriptRoot/common.ps1"
    $php = Get-HerdPhp
    $composer = Get-ComposerPhar
    & $php $composer @args
    exit $LASTEXITCODE
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
