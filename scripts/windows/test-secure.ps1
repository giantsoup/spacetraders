<#
.SYNOPSIS
Exercise secret ACL hardening on disposable checkout copies under TEMP.
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/common.ps1"

function Get-TestSnapshot {
    param([string]$Path)
    return (@(Get-ChildItem -LiteralPath $Path -Recurse -Force | Sort-Object FullName | ForEach-Object {
        $acl = Get-Acl -LiteralPath $_.FullName
        $hash = if ($_.PSIsContainer) { '' } else { (Get-FileHash -LiteralPath $_.FullName).Hash }
        "$($_.FullName)|$($acl.Sddl)|$hash"
    }) -join [Environment]::NewLine)
}

function Assert-TestAcl {
    param([string]$Path)
    $expected = @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value, 'S-1-5-18', 'S-1-5-32-544') | Sort-Object -Unique
    $acl = Get-Acl -LiteralPath $Path
    $rules = @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
    $actual = @($rules | ForEach-Object { $_.IdentityReference.Value } | Sort-Object -Unique)
    if (@(Compare-Object $expected $actual).Count -ne 0) { throw "Unexpected principals: $Path" }
    foreach ($rule in $rules) {
        if ($rule.AccessControlType -ne 'Allow' -or $rule.FileSystemRights -ne [Security.AccessControl.FileSystemRights]::FullControl) { throw "Unexpected rights: $Path" }
    }
}

$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')
$fixture = Join-Path $tempRoot ('spacetraders-secret-acl-' + [Guid]::NewGuid().ToString('N'))
try {
    [void][IO.Directory]::CreateDirectory($fixture)
    Invoke-Checked 'icacls.exe' @($fixture, '/grant:r', '*S-1-5-32-545:(OI)(CI)RX')
    foreach ($directory in @('database', 'storage/logs', 'storage/framework/sessions', 'storage/framework/game-locks', 'storage/framework/cache', 'storage/framework/views', 'public', 'vendor')) {
        [void][IO.Directory]::CreateDirectory((Join-Path $fixture $directory))
    }
    foreach ($file in @('.env', 'database/database.sqlite', 'database/database.sqlite-wal', 'database/database.sqlite-shm', 'storage/logs/x.log', 'storage/framework/sessions/s', 'storage/framework/game-locks/lock', 'storage/framework/cache/cache', 'storage/framework/views/view', 'public/index.php', 'vendor/example.php')) {
        [IO.File]::WriteAllText((Join-Path $fixture $file), 'fixture, not a secret')
    }
    Invoke-Checked 'icacls.exe' @((Join-Path $fixture 'storage/logs/x.log'), '/grant:r', '*S-1-5-32-545:R')
    Write-Host 'BEFORE: inherited BUILTIN\Users (RX), plus explicit log read grant'
    Invoke-Checked 'icacls.exe' @((Join-Path $fixture '.env'))
    Invoke-Checked 'icacls.exe' @((Join-Path $fixture 'storage/logs/x.log'))
    $before = Get-TestSnapshot $fixture
    Protect-CheckoutSecrets -Path $fixture -DryRun
    if ((Get-TestSnapshot $fixture) -cne $before) { throw 'Dry run changed ACLs or contents.' }
    $publicBefore = Get-TestSnapshot (Join-Path $fixture 'public')
    $vendorBefore = Get-TestSnapshot (Join-Path $fixture 'vendor')
    $publicAcl = (Get-Acl -LiteralPath (Join-Path $fixture 'public')).Sddl
    $vendorAcl = (Get-Acl -LiteralPath (Join-Path $fixture 'vendor')).Sddl
    Protect-CheckoutSecrets -Path $fixture
    foreach ($relative in @('.env', 'database', 'storage')) {
        $target = Join-Path $fixture $relative
        Assert-TestAcl $target
        if (-not (Get-Acl -LiteralPath $target).AreAccessRulesProtected) { throw "Root still inherits: $target" }
        if (Test-Path -LiteralPath $target -PathType Container) {
            foreach ($child in Get-ChildItem -LiteralPath $target -Recurse -Force) { Assert-TestAcl $child.FullName }
        }
    }
    Write-Host 'AFTER: only current user, SYSTEM, Administrators, all (F)'
    Invoke-Checked 'icacls.exe' @((Join-Path $fixture '.env'))
    Invoke-Checked 'icacls.exe' @((Join-Path $fixture 'database'), '/T')
    Invoke-Checked 'icacls.exe' @((Join-Path $fixture 'storage/logs/x.log'))
    $secured = Get-TestSnapshot $fixture
    Protect-CheckoutSecrets -Path $fixture
    if ((Get-TestSnapshot $fixture) -cne $secured) { throw 'Second run was not idempotent.' }
    Protect-CheckoutSecrets -Path $fixture -DryRun
    if ((Get-TestSnapshot $fixture) -cne $secured) { throw 'Secured dry run changed ACLs or contents.' }
    foreach ($relative in @('database/new.sqlite-wal', 'storage/framework/sessions/new-session')) {
        $target = Join-Path $fixture $relative
        [IO.File]::WriteAllText($target, 'new child')
        Assert-TestAcl $target
    }
    if ((Get-TestSnapshot (Join-Path $fixture 'public')) -cne $publicBefore -or (Get-TestSnapshot (Join-Path $fixture 'vendor')) -cne $vendorBefore -or (Get-Acl -LiteralPath (Join-Path $fixture 'public')).Sddl -cne $publicAcl -or (Get-Acl -LiteralPath (Join-Path $fixture 'vendor')).Sddl -cne $vendorAcl) {
        throw 'Public or vendor was modified.'
    }
    $before = Get-TestSnapshot $fixture
    $junction = Join-Path $fixture 'storage/linked-public'
    [void](New-Item -ItemType Junction -Path $junction -Target (Join-Path $fixture 'public'))
    try {
        $rejected = $false
        try { Protect-CheckoutSecrets -Path $fixture } catch {
            if ($_.Exception.Message -notlike 'Refusing reparse point:*') { throw }
            $rejected = $true
        }
        if (-not $rejected) { throw 'Reparse point was not rejected.' }
    } finally { [IO.Directory]::Delete($junction) }
    if ((Get-TestSnapshot $fixture) -cne $before) { throw 'Reparse rejection changed ACLs or contents.' }
    Write-Host 'PASS: dry run unchanged; all secret ACLs exact; idempotent; future children private; public/vendor untouched; reparse point rejected before writes.'
} finally {
    $resolved = [IO.Path]::GetFullPath($fixture)
    if ([IO.Path]::GetDirectoryName($resolved) -ne $tempRoot -or [IO.Path]::GetFileName($resolved) -notlike 'spacetraders-secret-acl-*') { throw "Unsafe cleanup target: $resolved" }
    if (Test-Path -LiteralPath $resolved) { Remove-Item -LiteralPath $resolved -Recurse -Force }
    Write-Host "Removed temp fixture: $resolved"
}
