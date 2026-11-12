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
    $exitCode = Invoke-NativeConsole $php (@($composer) + $args)
    exit $exitCode
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
