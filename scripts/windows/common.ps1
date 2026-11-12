<#
.SYNOPSIS
Shared Windows PowerShell 5.1 helpers. Dot-source this file; it performs no setup.
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:WindowsScriptsRoot = $PSScriptRoot

function Get-RepositoryRoot {
    return [IO.Path]::GetFullPath((Join-Path $script:WindowsScriptsRoot '../..'))
}

function ConvertTo-NativeArgument {
    param([AllowEmptyString()][string]$Value)
    return '"' + [regex]::Replace([regex]::Replace($Value, '(\\*)"', '$1$1\"'), '(\\+)$', '$1$1') + '"'
}

function Invoke-NativeConsole {
    param([string]$FilePath, [string[]]$ArgumentList = @())
    $start = New-Object Diagnostics.ProcessStartInfo
    $start.FileName = $FilePath
    $start.Arguments = ($ArgumentList | ForEach-Object { ConvertTo-NativeArgument $_ }) -join ' '
    $start.WorkingDirectory = Get-RepositoryRoot
    $start.UseShellExecute = $false
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $start
    try {
        [void]$process.Start()
        $process.WaitForExit()
        return $process.ExitCode
    } finally { $process.Dispose() }
}

function Invoke-NativeResult {
    param([string]$FilePath, [string[]]$ArgumentList = @())
    $start = New-Object Diagnostics.ProcessStartInfo
    $start.FileName = $FilePath
    $arguments = ($ArgumentList | ForEach-Object { ConvertTo-NativeArgument $_ }) -join ' '
    if ([IO.Path]::GetExtension($FilePath) -in @('.cmd', '.bat')) {
        $start.FileName = $env:ComSpec
        $start.Arguments = '/d /s /c "' + (ConvertTo-NativeArgument $FilePath) + ' ' + $arguments + '"'
    } else { $start.Arguments = $arguments }
    $start.WorkingDirectory = Get-RepositoryRoot
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $start
    try {
        [void]$process.Start()
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        return [pscustomobject]@{ ExitCode = $process.ExitCode; Output = $stdout.Result; Error = $stderr.Result }
    } finally { $process.Dispose() }
}

function Show-NativeResult {
    param($Result)
    if ($Result.Output) { [Console]::Out.Write($Result.Output) }
    if ($Result.Error) { [Console]::Error.Write($Result.Error) }
}

function Invoke-Checked {
    param([string]$FilePath, [string[]]$ArgumentList = @())
    $result = Invoke-NativeResult $FilePath $ArgumentList
    Show-NativeResult $result
    if ($result.ExitCode -ne 0) { throw "$([IO.Path]::GetFileName($FilePath)) exited with code $($result.ExitCode)." }
}

function Get-HerdPhp {
    $binary = $env:HERD_PHP85_BIN
    if (-not $binary) { $binary = Join-Path $env:USERPROFILE '.config/herd/bin/php85/php.exe' }
    if (-not (Test-Path -LiteralPath $binary -PathType Leaf)) { throw "Herd PHP executable not found: $binary" }
    $binary = [IO.Path]::GetFullPath($binary)
    $result = Invoke-NativeResult $binary @('-r', 'echo PHP_VERSION;')
    $expected = (Get-Content -LiteralPath (Join-Path (Get-RepositoryRoot) '.php-version') -Raw).Trim()
    if ($result.ExitCode -ne 0 -or $result.Output.Trim() -ne $expected) { throw "Expected Herd PHP $expected; selected executable does not match." }
    return $binary
}

function Get-ComposerPhar {
    $binary = $env:COMPOSER_PHAR
    if (-not $binary) { $binary = Join-Path $env:USERPROFILE '.config/herd/bin/composer.phar' }
    if (-not (Test-Path -LiteralPath $binary -PathType Leaf)) { throw "Composer phar not found: $binary" }
    return [IO.Path]::GetFullPath($binary)
}

function Get-Pnpm {
    return (Get-Command pnpm.cmd -CommandType Application -ErrorAction Stop).Source
}

function Test-RuntimePins {
    $root = Get-RepositoryRoot
    $php = Get-HerdPhp
    $node = (Get-Command node.exe -CommandType Application -ErrorAction Stop).Source
    $expected = (Get-Content -LiteralPath (Join-Path $root '.node-version') -Raw).Trim().TrimStart('v')
    $result = Invoke-NativeResult $node @('--version')
    if ($result.ExitCode -ne 0 -or $result.Output.Trim().TrimStart('v') -ne $expected) { throw "Expected Node $expected." }
    $package = Get-Content -LiteralPath (Join-Path $root 'package.json') -Raw | ConvertFrom-Json
    if ($package.PSObject.Properties.Name -contains 'packageManager') {
        if ($package.packageManager -notmatch '^pnpm@([^+]+)') { throw 'packageManager must pin pnpm.' }
        $expected = $Matches[1]
        $result = Invoke-NativeResult (Get-Pnpm) @('--version')
        if ($result.ExitCode -ne 0 -or $result.Output.Trim() -ne $expected) { throw "Expected pnpm $expected." }
    }
    Invoke-Checked $php @((Join-Path $script:WindowsScriptsRoot 'runtime.php'))
}

function Get-CheckoutHash {
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes((Get-RepositoryRoot).ToLowerInvariant())
        return ([BitConverter]::ToString($algorithm.ComputeHash($bytes))).Replace('-', '').Substring(0, 16).ToLowerInvariant()
    } finally { $algorithm.Dispose() }
}

function Assert-PlainPath {
    param([string]$Path)
    $current = [IO.Path]::GetFullPath($Path)
    while ($current) {
        if (Test-Path -LiteralPath $current) {
            if (((Get-Item -LiteralPath $current -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Refusing reparse point: $current" }
        }
        $current = [IO.Path]::GetDirectoryName($current)
    }
}

function New-PrivateDirectory {
    param([string]$Path)
    Assert-PlainPath $Path
    [void][IO.Directory]::CreateDirectory($Path)
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $currentUser = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    foreach ($identity in @($currentUser, 'S-1-5-18', 'S-1-5-32-544')) {
        $sid = New-Object Security.Principal.SecurityIdentifier($identity)
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
        $acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
    Invoke-Checked 'icacls.exe' @($Path, '/inheritance:r', '/grant:r', ('*' + $currentUser + ':(OI)(CI)F'), '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F')
}

function Get-ApplicationInfo {
    param([string]$Php)
    $result = Invoke-NativeResult $Php @((Join-Path $script:WindowsScriptsRoot 'application.php'), 'info')
    if ($result.ExitCode -ne 0) { throw 'Cannot inspect effective application configuration. Check dependencies, environment and migrations.' }
    return $result.Output | ConvertFrom-Json
}
