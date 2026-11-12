<#
.SYNOPSIS
Manage an owned offline fixture with plain workers or the local engine.
.DESCRIPTION
Uses LOCALAPPDATA/SpaceTraders/practice/checkout-hash with private ACLs. No real
.env/database is changed. Background PHP windows are hidden; logs stay in the
practice folder. Engine mode installs a separate on-demand Scheduled Task.
Start local engine from the UI; Start itself only starts the HTTP server.
Windows has no pcntl job timeout: --timeout=120 is retained, but cannot kill
a hung job on Windows.
.PARAMETER Operation
Start initializes and serves; worker mode also starts workers. Verify starts
and checks the engine in engine mode, or exercises a queued command in worker mode.
.PARAMETER Force
Required to Reset (delete) this checkout's owned practice folder.
With Stop, permits verified force stop after the graceful engine shutdown allowance.
.PARAMETER Engine
Create engine practice instead of worker practice. Reset to change modes.
.EXAMPLE
.\scripts\windows\practice.ps1 Start
.EXAMPLE
.\scripts\windows\practice.ps1 Start -Engine
.EXAMPLE
.\scripts\windows\practice.ps1 Stop
#>
param([ValidateSet('Init', 'Serve', 'Worker', 'Stop', 'Status', 'Reset', 'Start', 'Verify')][string]$Operation = 'Status', [switch]$Force, [switch]$Engine)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$mutex = $null
$locked = $false
try {
    . "$PSScriptRoot/common.ps1"
    $root = Get-RepositoryRoot
    $php = $null
    if ($Operation -notin @('Stop', 'Status', 'Reset')) { $php = Get-HerdPhp }
    $hash = Get-CheckoutHash
    $practiceHome = [IO.Path]::GetFullPath((Join-Path $env:LOCALAPPDATA ('SpaceTraders/practice/' + $hash)))
    $fixture = Join-Path $practiceHome 'environment'
    $ownerFile = Join-Path $practiceHome '.checkout'
    $stateFile = Join-Path $practiceHome 'processes.json'
    $runner = Join-Path $PSScriptRoot 'practice-runner.php'
    $modeFile = Join-Path $fixture '.practice-mode'
    $script:mode = 'worker'
    if ($Engine -and $Operation -notin @('Start', 'Init')) { throw '-Engine is only valid with Init or Start. Other operations use the persisted mode.' }
    $mutex = New-Object Threading.Mutex($false, ('Local\SpaceTradersPractice-' + $hash))
    try { $locked = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $locked = $true }
    if (-not $locked) { throw 'Another practice operation is running for this checkout.' }
    Assert-PlainPath $practiceHome
    if (Test-Path -LiteralPath $practiceHome) {
        if (-not (Test-Path -LiteralPath $ownerFile) -or [IO.File]::ReadAllText($ownerFile).Trim() -ne $root) {
            throw 'Practice folder ownership does not match this checkout; nothing was changed.'
        }
    }
    if (Test-Path -LiteralPath $modeFile) { $script:mode = [IO.File]::ReadAllText($modeFile).Trim() }
    if ($script:mode -notin @('worker', 'engine')) { throw 'Invalid persisted practice mode; nothing was changed.' }
    if ($Engine -and $script:mode -eq 'worker' -and (Test-Path -LiteralPath $fixture)) { throw 'Existing practice uses worker mode. Stop and Reset -Force before changing modes.' }
    $script:owned = @()
    if (Test-Path -LiteralPath $stateFile) {
        $decoded = Get-Content -LiteralPath $stateFile -Raw | ConvertFrom-Json
        $script:owned = @($decoded)
    }

    function Get-ProcessFingerprint {
        param($Process)
        $algorithm = [Security.Cryptography.SHA256]::Create()
        try { return [BitConverter]::ToString($algorithm.ComputeHash([Text.Encoding]::UTF8.GetBytes([string]$Process.CommandLine))) }
        finally { $algorithm.Dispose() }
    }

    function Get-MatchingProcess {
        param($Record)
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $($Record.Id)"
        if (-not $process) { return $null }
        if ($process.CreationDate.ToUniversalTime().Ticks.ToString() -ne $Record.Created -or (Get-ProcessFingerprint $process) -ne $Record.CommandHash) {
            throw "PID $($Record.Id) no longer matches the owned process; refusing to act on it."
        }
        return $process
    }

    function Save-PracticeState {
        $json = ConvertTo-Json -InputObject @($script:owned) -Depth 6
        [IO.File]::WriteAllText($stateFile + '.tmp', $json, (New-Object Text.UTF8Encoding($false)))
        Move-Item -LiteralPath ($stateFile + '.tmp') -Destination $stateFile -Force
    }

    function Add-OwnedProcess {
        param($Process, [string]$Role, [bool]$RootProcess)
        $script:owned += [pscustomobject]@{
            Id = [int]$Process.ProcessId; Created = $Process.CreationDate.ToUniversalTime().Ticks.ToString()
            CommandHash = Get-ProcessFingerprint $Process; Role = $Role; Root = $RootProcess
        }
    }

    function Find-OwnedDescendants {
        $all = @(Get-CimInstance Win32_Process)
        $changed = $true
        while ($changed) {
            $changed = $false
            foreach ($record in @($script:owned)) {
                $parent = Get-MatchingProcess $record
                if (-not $parent) { continue }
                foreach ($child in @($all | Where-Object { $_.ParentProcessId -eq $record.Id -and $_.CreationDate -ge $parent.CreationDate })) {
                    if (@($script:owned | Where-Object { $_.Id -eq $child.ProcessId }).Count -eq 0) {
                        Add-OwnedProcess $child $record.Role $false
                        $changed = $true
                    }
                }
            }
        }
        Save-PracticeState
    }

    function Initialize-Practice {
        if (-not (Test-Path -LiteralPath $practiceHome)) {
            New-PrivateDirectory $practiceHome
            [IO.File]::WriteAllText($ownerFile, $root, (New-Object Text.UTF8Encoding($false)))
        }
        if (-not (Test-Path -LiteralPath $fixture)) {
            Invoke-Checked $php @('scripts/mvp-fixture.php', '--isolated', '--directory', $fixture)
        }
        if (-not (Test-Path -LiteralPath (Join-Path $fixture '.windows-ready'))) { throw 'Fixture initialization did not complete. Inspect its folder, then Reset -Force before retrying.' }
        if ($Engine -and $script:mode -eq 'worker') {
            Invoke-Checked $php @($runner, $fixture, '--configure-engine')
            $script:mode = 'engine'
        }
        if (-not (Test-Path -LiteralPath $modeFile)) { [IO.File]::WriteAllText($modeFile, $script:mode) }
        Invoke-Checked $php @($runner, $fixture, 'env')
        if ($script:mode -eq 'engine') { Install-PracticeEngine }
        Write-Host "Practice mode: $script:mode"
        Write-Host "Practice environment: $fixture"
        Write-Host "Generated login file (password not displayed): $fixture/browser.json"
    }

    function Get-PracticeEngineStatus {
        if (-not $php) { $script:php = Get-HerdPhp }
        $result = Invoke-NativeResult $php @($runner, $fixture, '--engine-status')
        if ($result.ExitCode -ne 0) { Show-NativeResult $result; throw 'Cannot safely inspect the isolated practice engine.' }
        return $result.Output | ConvertFrom-Json
    }

    function Install-PracticeEngine {
        $status = Get-PracticeEngineStatus
        if ($status.registered -and -not $status.installed) { throw 'Practice task exists without its private service marker. Inspect it before proceeding.' }
        if (-not $status.registered) { Invoke-Checked $php @($runner, $fixture, 'spacetraders:engine-service', 'install') }
        $status = Get-PracticeEngineStatus
        if (-not $status.registered -or -not $status.installed) { throw 'Practice engine installation could not be verified.' }
        Write-Host "Practice task: $($status.task); engine state: $($status.state). Start local engine from the UI."
    }

    function Start-PracticeProcess {
        param([string]$Role, [string[]]$Arguments)
        if ($script:mode -eq 'engine' -and $Role -ne 'serve') { throw 'Engine practice owns its worker and scheduler. Plain workers are forbidden.' }
        foreach ($record in @($script:owned | Where-Object { $_.Role -eq $Role })) {
            if (Get-MatchingProcess $record) {
                if (-not $record.Root) { throw "An orphaned $Role child remains; run Stop first." }
                Write-Host "$Role already running (PID $($record.Id))."
                return
            }
        }
        $script:owned = @($script:owned | Where-Object { $_.Role -ne $Role })
        $argumentString = (@($runner, $fixture) + $Arguments | ForEach-Object { ConvertTo-NativeArgument $_ }) -join ' '
        $process = Start-Process -FilePath $php -ArgumentList $argumentString -WorkingDirectory $root -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $practiceHome ($Role + '.log')) -RedirectStandardError (Join-Path $practiceHome ($Role + '.error.log'))
        $identity = Get-CimInstance Win32_Process -Filter "ProcessId = $($process.Id)"
        if (-not $identity) { throw "$Role exited before its identity could be saved. Check $practiceHome/$Role.error.log" }
        Add-OwnedProcess $identity $Role $true
        Save-PracticeState
        Start-Sleep -Milliseconds 1200
        if (-not (Get-MatchingProcess ($script:owned | Where-Object { $_.Id -eq $process.Id }))) { throw "$Role exited. Check $practiceHome/$Role.error.log" }
        Find-OwnedDescendants
        Write-Host "$Role started (PID $($process.Id)); logs: $practiceHome/$Role.log"
    }

    function Start-PracticeServer {
        $existing = @($script:owned | Where-Object { $_.Role -eq 'serve' -and $_.Root })
        $running = $false
        foreach ($record in $existing) { if (Get-MatchingProcess $record) { $running = $true } }
        if (-not $running -and @(Get-NetTCPConnection -LocalPort 8010 -State Listen -ErrorAction SilentlyContinue).Count -gt 0) {
            throw 'Port 8010 is already occupied; no unrelated process will be stopped.'
        }
        Start-PracticeProcess 'serve' @('serve', '--host=127.0.0.1', '--port=8010', '--tries=1', '--no-reload')
        $ready = $false
        for ($attempt = 0; $attempt -lt 20; $attempt++) {
            try {
                $response = Invoke-WebRequest 'http://127.0.0.1:8010/login' -UseBasicParsing -TimeoutSec 2
                if ($response.StatusCode -eq 200) { $ready = $true; break }
            } catch { Start-Sleep -Milliseconds 500 }
        }
        if (-not $ready) { throw 'Practice login did not return HTTP 200. Inspect the private server logs and run Stop.' }
        Find-OwnedDescendants
        Write-Host "HTTP 200: http://127.0.0.1:8010/login; generated login file: $fixture/browser.json (password not displayed)."
    }

    function Stop-Practice {
        if (-not (Test-Path -LiteralPath $practiceHome)) { Write-Host 'Practice is not initialized.'; return }
        if ($script:mode -eq 'engine') {
            foreach ($record in @($script:owned | Where-Object { $_.Role -ne 'serve' })) {
                if (Get-MatchingProcess $record) { throw 'Unexpected plain worker in engine practice. Refusing to kill engine or mixed-mode processes.' }
            }
            if (-not $php) { $script:php = Get-HerdPhp }
            $endArguments = @($runner, $fixture, '--engine-end')
            if ($Force) { $endArguments += '--force' }
            Invoke-Checked $php $endArguments
            Write-Host 'Engine End session confirmed; all engine leases released.'
        }
        Find-OwnedDescendants
        foreach ($record in @($script:owned | Sort-Object Root -Descending)) {
            if (Get-MatchingProcess $record) {
                $result = Invoke-NativeResult 'taskkill.exe' @('/PID', [string]$record.Id, '/T', '/F')
                if ($result.ExitCode -ne 0 -and (Get-MatchingProcess $record)) { throw "Could not stop owned PID $($record.Id)." }
            }
        }
        foreach ($record in $script:owned) {
            if (Get-MatchingProcess $record) { throw "Owned PID $($record.Id) remains alive." }
        }
        $script:owned = @()
        Save-PracticeState
        Write-Host 'Stopped all recorded practice processes; creation times and command hashes verified.'
    }

    if ($Operation -in @('Start', 'Init')) { Initialize-Practice }
    if ($Operation -in @('Start', 'Serve', 'Worker', 'Verify')) {
        if (-not (Test-Path -LiteralPath (Join-Path $fixture 'browser.json'))) { throw 'Run Init first.' }
        if ($Operation -eq 'Worker' -and $script:mode -eq 'engine') { throw 'Worker is forbidden in engine practice. Use Start local engine in the UI.' }
        if ($script:mode -eq 'engine') {
            foreach ($record in @($script:owned | Where-Object { $_.Role -ne 'serve' })) {
                if (Get-MatchingProcess $record) { throw 'Plain practice workers remain. Refusing mixed-mode startup.' }
            }
            if ($Operation -eq 'Serve') { Install-PracticeEngine }
        }
        if ($Operation -in @('Start', 'Serve')) { Start-PracticeServer }
        if ($Operation -in @('Start', 'Worker') -and $script:mode -eq 'worker') {
            Start-PracticeProcess 'worker' @('queue:work', 'database', '--queue=spacetraders', '--sleep=1', '--tries=1', '--timeout=120')
            Start-PracticeProcess 'scheduler' @('schedule:work')
        }
        if ($Operation -eq 'Verify') {
            if ($script:mode -eq 'engine') { Invoke-Checked $php @($runner, $fixture, '--engine-verify') }
            else { Invoke-Checked $php @($runner, $fixture, '--verify-worker') }
        }
    }
    if ($Operation -eq 'Stop') { Stop-Practice }
    if ($Operation -eq 'Status') {
        Write-Host "Practice folder: $practiceHome"
        Write-Host "Practice mode: $script:mode"
        if (Test-Path -LiteralPath $practiceHome) {
            if ($script:mode -eq 'engine') {
                $status = Get-PracticeEngineStatus
                Write-Host "Task name: $($status.task)"
                Write-Host "Registered: $(if ($status.registered) { 'yes' } else { 'no' }); engine state: $($status.state); gate open: $($status.gate_open)"
                Write-Host "Engine leases: $($status.leases | ConvertTo-Json -Compress); recorded engine PIDs: $($status.pids | ConvertTo-Json -Compress)"
            } else { Write-Host 'Task name: none; registered: no; engine state: disabled' }
            Find-OwnedDescendants
            $rows = foreach ($record in $script:owned) {
                [pscustomobject]@{ Role = $record.Role; Id = $record.Id; Root = $record.Root; Running = [bool](Get-MatchingProcess $record) }
            }
            if ($rows) { $rows | Format-Table -AutoSize | Out-Host } else { Write-Host 'No owned practice processes running.' }
        } else { Write-Host 'Not initialized.' }
    }
    if ($Operation -eq 'Reset') {
        if (-not $Force) { throw 'Reset deletes the practice environment, credentials and logs. Confirm with -Force.' }
        Stop-Practice
        if (Test-Path -LiteralPath $practiceHome) {
            if ($script:mode -eq 'engine') {
                $status = Get-PracticeEngineStatus
                Invoke-Checked $php @($runner, $fixture, 'spacetraders:engine-service', 'uninstall')
                $query = Invoke-NativeResult 'schtasks.exe' @('/Query', '/TN', $status.task)
                if ($query.ExitCode -eq 0) { throw 'Practice engine task is still registered. Refusing to delete its environment.' }
                Write-Host "Confirmed practice task removed: $($status.task)"
            }
            $expected = [IO.Path]::GetFullPath((Join-Path $env:LOCALAPPDATA ('SpaceTraders/practice/' + $hash)))
            if ($practiceHome -ne $expected -or [IO.File]::ReadAllText($ownerFile).Trim() -ne $root) { throw 'Reset path/ownership guard failed.' }
            Assert-PlainPath $practiceHome
            foreach ($entry in Get-ChildItem -LiteralPath $practiceHome -Force -Recurse) { Assert-PlainPath $entry.FullName }
            Remove-Item -LiteralPath $practiceHome -Recurse -Force
            Write-Host 'Deleted only this checkout-owned practice environment.'
        }
    }
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
finally {
    if ($locked) { $mutex.ReleaseMutex() }
    if ($mutex) { $mutex.Dispose() }
}
