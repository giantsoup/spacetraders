<#
.SYNOPSIS
Install, uninstall or inspect this checkout's native local engine service.
.EXAMPLE
.\scripts\windows\engine.ps1 Status
#>
param([Parameter(Mandatory = $true)][ValidateSet('Install', 'Uninstall', 'Status')][string]$Operation)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
try {
    . "$PSScriptRoot/common.ps1"
    $php = Get-HerdPhp
    Write-Host "Engine checkout: $(Get-RepositoryRoot)"
    if ($Operation -eq 'Install') {
        Invoke-Checked $php @('artisan', 'config:clear')
        $code = 'echo json_encode(["SPACETRADERS_LOCAL_ENGINE_ENABLED" => config("gameplay.local_engine_enabled"), "SPACETRADERS_OPERATOR_ID" => config("gameplay.operator_id"), "SPACETRADERS_AUTOMATION_ENABLED" => config("gameplay.enabled")]);'
        Invoke-Checked $php @('artisan', 'tinker', '--execute', $code)
    }
    Invoke-Checked $php @('artisan', 'spacetraders:engine-service', $Operation.ToLowerInvariant())
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
