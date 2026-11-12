<#
.SYNOPSIS
Install locked dependencies and build without replacing an existing environment.
.PARAMETER DryRun
Verify runtimes and describe actions without installing or modifying anything.
.PARAMETER Migrate
Allow migration of an existing environment after displaying its effective database.
.EXAMPLE
.\scripts\windows\setup.ps1 -DryRun
#>
param([switch]$DryRun, [switch]$Migrate)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
try {
    . "$PSScriptRoot/common.ps1"
    $root = Get-RepositoryRoot
    $php = Get-HerdPhp
    $composer = Get-ComposerPhar
    $pnpm = Get-Pnpm
    Test-RuntimePins
    $environmentFile = Join-Path $root '.env'
    $fresh = -not (Test-Path -LiteralPath $environmentFile)
    if ($DryRun) {
        Write-Host 'DRY RUN: composer install --no-interaction; pnpm install --frozen-lockfile --strict-peer-dependencies; pnpm build'
        if ($fresh) {
            Write-Host "DRY RUN: exclusively create .env, configure SQLite at $root/database/database.sqlite, create it only if absent, generate an empty key, inspect effective database, migrate --force."
        } elseif ($Migrate) {
            Write-Host 'DRY RUN: preserve .env/key/database; inspect effective database then migrate --force.'
        } else { Write-Host 'DRY RUN: preserve .env/key/database; do not migrate existing environment.' }
        exit 0
    }
    Invoke-Checked $php @($composer, 'install', '--no-interaction')
    Invoke-Checked $pnpm @('install', '--frozen-lockfile', '--strict-peer-dependencies')
    if ($fresh) {
        Assert-PlainPath $environmentFile
        $database = (Join-Path $root 'database/database.sqlite').Replace('\', '/')
        if ((Test-Path -LiteralPath $database) -and (Get-Item -LiteralPath $database).Length -gt 0) {
            throw 'A database already exists without .env. Restore its matching key/environment instead of generating a new key.'
        }
        $values = @{
            DB_DATABASE = $database
            SPACETRADERS_REQUEST_BUDGET_PATH = (Join-Path $env:LOCALAPPDATA 'SpaceTraders/request-budget').Replace('\', '/')
            SPACETRADERS_PHP_BINARY = $php.Replace('\', '/')
        }
        $contents = Get-Content -LiteralPath (Join-Path $root '.env.example') -Raw
        foreach ($name in $values.Keys) {
            $line = $name + '="' + $values[$name] + '"'
            $pattern = '(?m)^' + [regex]::Escape($name) + '=.*$'
            if ([regex]::IsMatch($contents, $pattern)) { $contents = [regex]::Replace($contents, $pattern, [Text.RegularExpressions.MatchEvaluator]{ param($match) $line }) }
            else { $contents += [Environment]::NewLine + $line + [Environment]::NewLine }
        }
        $stream = [IO.File]::Open($environmentFile, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        try { $bytes = (New-Object Text.UTF8Encoding($false)).GetBytes($contents); $stream.Write($bytes, 0, $bytes.Length) }
        finally { $stream.Dispose() }
        Assert-PlainPath $database
        if (-not (Test-Path -LiteralPath $database)) {
            $stream = [IO.File]::Open($database, [IO.FileMode]::CreateNew); $stream.Dispose()
        }
        if ($contents -match '(?m)^APP_KEY=\s*(?:""|'''')?\s*$') {
            Invoke-Checked $php @('artisan', 'key:generate', '--force', '--no-interaction')
        }
    }
    if ($fresh -or $Migrate) {
        $info = Get-ApplicationInfo $php
        Write-Host "Effective database ($($info.connection)): $($info.database)"
        if ($info.config_cached) { throw 'Clear cached configuration explicitly before migrating; it may belong to another environment.' }
        Invoke-Checked $php @('artisan', 'migrate', '--force', '--no-interaction')
    } else { Write-Host 'Existing environment preserved; migrations skipped (opt in with -Migrate).' }
    Invoke-Checked $pnpm @('build')
    Write-Host 'Next: .\scripts\windows\php.ps1 artisan spacetraders:operator create'
    Write-Host 'Configure the operator ID/engine flags, then: .\scripts\windows\engine.ps1 Install'
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
