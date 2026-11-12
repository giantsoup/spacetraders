<#
.SYNOPSIS
Run the portable verify.sh checks and report every result, including POSIX skips.
.DESCRIPTION
Reads logical commands and Pint's exact target list from verify.sh. Unknown
commands fail closed so new checks cannot silently disappear on Windows. Build
output is never restored; public/build status is reported at the end.
.EXAMPLE
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/windows/verify.ps1
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/common.ps1"
$results = New-Object 'Collections.Generic.List[object]'
$root = Get-RepositoryRoot
$previousLocation = Get-Location
$oldPhp = $env:PHP_BIN
$oldPnpm = $env:PNPM_BIN

function Invoke-VerificationStep {
    param([string]$Name, [scriptblock]$Action, [string]$SkipReason)
    if ($SkipReason) {
        Write-Host "SKIP $Name -- POSIX-only: $SkipReason"
        $results.Add([pscustomobject]@{ Check = $Name; Result = 'SKIP'; Detail = $SkipReason })
        return
    }
    Write-Host "CHECK $Name"
    try {
        & $Action
        $results.Add([pscustomobject]@{ Check = $Name; Result = 'PASS'; Detail = '' })
    } catch {
        Write-Host "FAIL $Name -- $($_.Exception.Message)"
        $results.Add([pscustomobject]@{ Check = $Name; Result = 'FAIL'; Detail = $_.Exception.Message })
    }
}

try {
    Set-Location -LiteralPath $root
    $php = Get-HerdPhp
    $node = (Get-Command node.exe -CommandType Application).Source
    $pnpm = Get-Pnpm
    $env:PHP_BIN = $php
    $env:PNPM_BIN = $pnpm
    Invoke-VerificationStep 'Windows runtime pins/extensions' { Test-RuntimePins }
    $source = Get-Content -LiteralPath (Join-Path $root 'scripts/verify.sh') -Raw
    $logical = [regex]::Replace($source, '\\\r?\n\s*', ' ')
    foreach ($line in ($logical -split '\r?\n')) {
        $command = $line.Trim()
        if (-not $command -or $command.StartsWith('#') -or $command -cmatch '^(set |cd |php_bin=|pnpm_bin=|export )') { continue }
        if ($command -eq '"$php_bin" scripts/runtime-preflight.php') {
            Invoke-VerificationStep 'runtime-preflight.php: pcntl/posix requirements' {} 'Windows has no pcntl/posix; portable pin/extension checks run above.'
        } elseif ($command -eq 'python3 scripts/verify-runtime.py') {
            Invoke-VerificationStep 'verify-runtime.py: executable shell wrapper probes' {} 'Executes a shebang Herd wrapper; Windows regression probes run below.'
            Invoke-VerificationStep 'runtime negative pin probes' { & "$PSScriptRoot/verify-runtime.ps1" }
        } elseif ($command -match '^\[\[.*pnpm_bin') {
            Invoke-VerificationStep 'pnpm exact pin' { Test-RuntimePins }
        } elseif ($command -eq 'PNPM_BIN="$pnpm_bin" node scripts/verify-build-sources.mjs') {
            Invoke-VerificationStep 'verify-build-sources.mjs: bash launcher' {} 'Uses bash exec; identical asset-isolation assertions use PowerShell below.'
            Invoke-VerificationStep 'production build/source isolation' { & "$PSScriptRoot/verify-build-sources.ps1" }
        } elseif ($command -eq 'node --test scripts/verify-verification-lifecycle.mjs') {
            Invoke-VerificationStep 'verify-verification-lifecycle.mjs POSIX ownership harness' {} 'Requires process.getuid(), Unix permission modes, symlinks and POSIX signal escalation.'
            Invoke-VerificationStep 'verification lifecycle syntax' { Invoke-Checked $node @('--check', 'scripts/verify-verification-lifecycle.mjs') }
        } elseif ($command -match '^node (.+)$') {
            $arguments = @($Matches[1] -split '\s+')
            Invoke-VerificationStep $command { Invoke-Checked $node $arguments }
        } elseif ($command.StartsWith('python3 -c ')) {
            Invoke-VerificationStep 'verify-operator-terminal.py AST parse' {
                $python = Get-Command py.exe -CommandType Application -ErrorAction SilentlyContinue
                $code = "import ast; ast.parse(open('scripts/verify-operator-terminal.py').read())"
                if ($python) { Invoke-Checked $python.Source @('-3', '-c', $code) }
                else { Invoke-Checked (Get-Command python.exe -CommandType Application).Source @('-c', $code) }
            }
        } elseif ($command -eq '"$php_bin" scripts/verify-environment.php') {
            Invoke-VerificationStep 'verify-environment.php isolated Ignition probes' {} 'Requires POSIX symlinks and /dev/null; Windows cannot run this harness unchanged.'
        } elseif ($command.StartsWith('"$php_bin" ')) {
            $arguments = @()
            foreach ($token in (($command -replace '^"\$php_bin"\s+', '') -split '\s+')) {
                if ($token.Contains('*')) {
                    $expanded = @(Get-ChildItem -Path (Join-Path $root $token) -File | Sort-Object Name)
                    if ($expanded.Count -eq 0) { throw "No matches for verify.sh target: $token" }
                    $arguments += @($expanded | ForEach-Object { $_.FullName })
                } else { $arguments += $token }
            }
            $name = ($arguments | Select-Object -First 2) -join ' '
            Invoke-VerificationStep $name { Invoke-Checked $php $arguments }
        } elseif ($command -eq 'git diff --check') {
            Invoke-VerificationStep $command { Invoke-Checked 'git.exe' @('diff', '--check') }
        } else { throw "Unmapped verify.sh step (must port or explain): $command" }
    }
    Invoke-VerificationStep 'Windows runtime/wrapper regressions' {
        $wrapper = Join-Path $PSScriptRoot 'php.ps1'
        $powershell = Join-Path $PSHOME 'powershell.exe'
        $code = 'echo json_encode(array_slice($argv, 1));'
        $result = Invoke-NativeResult $powershell @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $wrapper, '-r', $code, '--', 'a b', '$literal', 'embedded"quote', 'trailing\')
        $wanted = @('a b', '$literal', 'embedded"quote', 'trailing\') | ConvertTo-Json -Compress
        if ($result.ExitCode -ne 0 -or (($result.Output | ConvertFrom-Json) | ConvertTo-Json -Compress) -ne $wanted) { throw 'PHP wrapper argument forwarding failed.' }
        $result = Invoke-NativeResult $powershell @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $wrapper, '-r', 'exit(37);')
        if ($result.ExitCode -ne 37) { throw 'PHP wrapper did not preserve exit code 37.' }
        $oldOverride = $env:HERD_PHP85_BIN
        try {
            $env:HERD_PHP85_BIN = Join-Path $root 'missing-herd-php.exe'
            $result = Invoke-NativeResult $powershell @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $wrapper, '-v')
            if ($result.ExitCode -eq 0) { throw 'Missing Herd executable was accepted.' }
        } finally { $env:HERD_PHP85_BIN = $oldOverride }
    }
    Invoke-VerificationStep 'PowerShell 5.1 parser' {
        foreach ($file in Get-ChildItem -LiteralPath $PSScriptRoot -Filter '*.ps1') {
            $tokens = $null; $parseErrors = $null
            [void][Management.Automation.Language.Parser]::ParseFile($file.FullName, [ref]$tokens, [ref]$parseErrors)
            if (@($parseErrors).Count -gt 0) { throw "PowerShell parse errors in $($file.Name): $parseErrors" }
        }
    }
    Invoke-VerificationStep 'Windows PHP helper lint' {
        foreach ($file in Get-ChildItem -LiteralPath $PSScriptRoot -Filter '*.php') { Invoke-Checked $php @('-l', $file.FullName) }
    }
    if (Get-Command Invoke-ScriptAnalyzer -ErrorAction SilentlyContinue) {
        Invoke-VerificationStep 'PSScriptAnalyzer' {
            $issues = @(Invoke-ScriptAnalyzer -Path $PSScriptRoot -Recurse -Severity Error)
            if ($issues.Count -gt 0) { $issues | Format-Table | Out-Host; throw 'ScriptAnalyzer found errors.' }
        }
    } else { Write-Host 'PSScriptAnalyzer unavailable; every PowerShell file was parsed instead.' }
} catch {
    $results.Add([pscustomobject]@{ Check = 'verification orchestration'; Result = 'FAIL'; Detail = $_.Exception.Message })
} finally {
    Write-Host 'Build output left unchanged by cleanup; git status --short public/build:'
    Invoke-Checked 'git.exe' @('status', '--short', 'public/build')
    $env:PHP_BIN = $oldPhp
    $env:PNPM_BIN = $oldPnpm
    Set-Location -LiteralPath $previousLocation
    $results | Format-Table -AutoSize -Wrap | Out-Host
}
if (@($results | Where-Object { $_.Result -eq 'FAIL' }).Count -gt 0) { exit 1 }
exit 0
