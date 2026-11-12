<#
.SYNOPSIS
Protect and verify checkout .env, database and storage ACLs without other setup.
.PARAMETER DryRun
Report secret paths and intended ACLs without modifying anything.
.EXAMPLE
.\scripts\windows\secure.ps1 -DryRun
#>
param([switch]$DryRun)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
try {
    . "$PSScriptRoot/common.ps1"
    Protect-CheckoutSecrets -DryRun:$DryRun
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
