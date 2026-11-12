<#
.SYNOPSIS
Snapshot effective SQLite with VACUUM INTO and preserve .env privately.
.PARAMETER Destination
Optional new folder. Defaults to LOCALAPPDATA/SpaceTraders/backups/checkout-hash/UTC.
.PARAMETER Force
Allow backup while the local engine is starting, running or stopping.
.EXAMPLE
.\scripts\windows\backup.ps1
#>
param([string]$Destination, [switch]$Force)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
try {
    . "$PSScriptRoot/common.ps1"
    $php = Get-HerdPhp
    $root = Get-RepositoryRoot
    $environmentFile = Join-Path $root '.env'
    if (-not (Test-Path -LiteralPath $environmentFile -PathType Leaf)) { throw 'No checkout .env exists to back up.' }
    Assert-PlainPath $environmentFile
    $environmentHash = (Get-FileHash -LiteralPath $environmentFile -Algorithm SHA256).Hash
    $info = Get-ApplicationInfo $php
    $code = 'echo json_encode(["state" => \Illuminate\Support\Facades\Schema::hasTable("local_engines") ? (\App\Models\LocalEngine::find(1)?->state ?? "off") : "unknown"]);'
    $state = Invoke-NativeResult $php @('artisan', 'tinker', '--execute', $code)
    if ($state.ExitCode -ne 0) { throw 'Could not inspect LocalEngine via artisan tinker.' }
    $info.engine_state = ($state.Output | ConvertFrom-Json).state
    Write-Host "Effective database ($($info.connection)): $($info.database); engine: $($info.engine_state)"
    if ($info.connection -ne 'sqlite' -or -not (Test-Path -LiteralPath $info.database -PathType Leaf)) { throw 'An existing SQLite file is required.' }
    if ($info.engine_state -in @('starting', 'running', 'stopping', 'unknown')) {
        Write-Warning 'The engine may be writing. End the session first; -Force explicitly accepts an online SQLite snapshot.'
        if (-not $Force) { throw 'Backup refused while engine may be active.' }
    }
    if ($info.config_cached) { throw 'Cached configuration can contain a different key. Explicitly clear it before backing up .env.' }
    if (-not $Destination) {
        $Destination = Join-Path $env:LOCALAPPDATA ('SpaceTraders/backups/' + (Get-CheckoutHash) + '/' + [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffffffZ'))
    }
    $Destination = [IO.Path]::GetFullPath($Destination)
    if (Test-Path -LiteralPath $Destination) { throw 'Backup destination already exists; nothing will be replaced.' }
    New-PrivateDirectory $Destination
    $code = '$script = $argv[1]; array_splice($argv, 1, 1); require $script;'
    Invoke-Checked $php @('-r', $code, (Join-Path $PSScriptRoot 'application.php'), 'backup', $Destination)
    if (-not (Test-Path -LiteralPath (Join-Path $Destination '.snapshot-verified'))) { throw 'Snapshot did not complete; backup is incomplete.' }
    [IO.File]::Copy($environmentFile, (Join-Path $Destination '.env'), $false)
    if ((Get-FileHash -LiteralPath (Join-Path $Destination '.env') -Algorithm SHA256).Hash -ne $environmentHash) {
        throw 'Environment changed during backup; this folder is incomplete and must not be restored.'
    }
    Write-Host "Backup: $Destination"
    Write-Host 'Restore with app/engine processes stopped: preserve current files, restore database.sqlite and its matching .env (APP_KEY), adjust DB_DATABASE if moved, clear configuration, then integrity-check before restarting. Never mix keys and databases.'
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
