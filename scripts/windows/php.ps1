<#
.SYNOPSIS
Run pinned Herd PHP, forwarding all arguments and its exit code.
.EXAMPLE
.\scripts\windows\php.ps1 artisan about
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
try {
    . "$PSScriptRoot/common.ps1"
    $php = Get-HerdPhp
    $exitCode = Invoke-NativeConsole $php $args
    exit $exitCode
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
