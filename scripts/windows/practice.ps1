<#
.SYNOPSIS
Manage an owned offline fixture, HTTP server and one worker/scheduler pair.
.DESCRIPTION
Uses LOCALAPPDATA/SpaceTraders/practice/checkout-hash with private ACLs. No real
.env/database is changed. Background PHP windows are hidden; logs stay in the
practice folder. The native engine is always disabled. Windows has no pcntl
job timeout: --timeout=120 is retained, but cannot kill a hung job on Windows.
.PARAMETER Operation
Start performs Init, Serve and Worker. Verify exercises a real queued command.
.PARAMETER Force
Required to Reset (delete) this checkout's owned practice folder.
.EXAMPLE
.\scripts\windows\practice.ps1 Start
.EXAMPLE
.\scripts\windows\practice.ps1 Stop
#>
param([ValidateSet('Init', 'Serve', 'Worker', 'Stop', 'Status', 'Reset', 'Start', 'Verify')][string]$Operation = 'Status', [switch]$Force)
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
    $mutex = New-Object Threading.Mutex($false, ('Local\SpaceTradersPractice-' + $hash))
    try { $locked = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $locked = $true }
    if (-not $locked) { throw 'Another practice operation is running for this checkout.' }
    Assert-PlainPath $practiceHome
    if (Test-Path -LiteralPath $practiceHome) {
        if (-not (Test-Path -LiteralPath $ownerFile) -or [IO.File]::ReadAllText($ownerFile).Trim() -ne $root) {
            throw 'Practice folder ownership does not match this checkout; nothing was changed.'
        }
    }
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
        Invoke-Checked $php @($runner, $fixture, 'env')
        Write-Host "Practice environment: $fixture"
        Write-Host "Generated login file (password not displayed): $fixture/browser.json"
    }

    function Start-PracticeProcess {
        param([string]$Role, [string[]]$Arguments)
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
        if ($Operation -in @('Start', 'Serve')) { Start-PracticeServer }
        if ($Operation -in @('Start', 'Worker')) {
            Start-PracticeProcess 'worker' @('queue:work', 'database', '--queue=spacetraders', '--sleep=1', '--tries=1', '--timeout=120')
            Start-PracticeProcess 'scheduler' @('schedule:work')
        }
        if ($Operation -eq 'Verify') { Invoke-Checked $php @($runner, $fixture, '--verify-worker') }
    }
    if ($Operation -eq 'Stop') { Stop-Practice }
    if ($Operation -eq 'Status') {
        Write-Host "Practice folder: $practiceHome"
        if (Test-Path -LiteralPath $practiceHome) {
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
