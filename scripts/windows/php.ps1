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
    $result = Invoke-NativeResult (Get-HerdPhp) $args
    Show-NativeResult $result
    exit $result.ExitCode
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
