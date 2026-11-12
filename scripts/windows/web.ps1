<#
.SYNOPSIS
Install, uninstall or inspect a loopback-only web server for this checkout, for `tailscale serve`.
.DESCRIPTION
Registers a per-user Scheduled Task that starts PHP's built-in server on 127.0.0.1 at logon,
inside a headless console host so no window appears (php-win.exe cannot run the built-in server). It never listens on the LAN. Publish it on the tailnet with
`tailscale serve --bg <port>`. Engine controls stay limited to the local Herd host.
.EXAMPLE
.\scripts\windows\web.ps1 Install
.EXAMPLE
.\scripts\windows\web.ps1 Status
#>
param(
    [Parameter(Mandatory = $true)][ValidateSet('Install', 'Uninstall', 'Status')][string]$Operation,
    [ValidateRange(1024, 65535)][int]$Port = 8081
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-WebTaskName { return 'SpaceTraders-Web-' + (Get-CheckoutHash) }

function Test-WebTask {
    $result = Invoke-NativeResult 'schtasks.exe' @('/Query', '/TN', (Get-WebTaskName))
    return $result.ExitCode -eq 0
}

function Get-WebTaskXml {
    param([string]$Php, [string]$Root, [int]$Port)
    $escape = { param($value) [Security.SecurityElement]::Escape($value) }
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $router = Join-Path $Root 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
    if (-not (Test-Path -LiteralPath $router -PathType Leaf)) { throw "Laravel router script not found: $router" }
    $conhost = Join-Path $env:SystemRoot 'System32\conhost.exe'
    $arguments = "--headless `"$Php`" -S 127.0.0.1:$Port -t `"$(Join-Path $Root 'public')`" `"$router`""
    return @"
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><URI>\$(& $escape (Get-WebTaskName))</URI><Description>SpaceTraders loopback web server for tailscale serve ($(& $escape $Root)).</Description></RegistrationInfo>
  <Triggers><LogonTrigger><Enabled>true</Enabled><UserId>$sid</UserId></LogonTrigger></Triggers>
  <Principals><Principal id="Owner"><UserId>$sid</UserId><LogonType>InteractiveToken</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
  <Settings>
    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
    <AllowHardTerminate>true</AllowHardTerminate>
    <IdleSettings><StopOnIdleEnd>false</StopOnIdleEnd></IdleSettings>
    <RestartOnFailure><Interval>PT1M</Interval><Count>999</Count></RestartOnFailure>
    <ExecutionTimeLimit>PT0S</ExecutionTimeLimit>
    <Priority>5</Priority>
  </Settings>
  <Actions Context="Owner"><Exec><Command>$(& $escape $conhost)</Command><Arguments>$(& $escape $arguments)</Arguments><WorkingDirectory>$(& $escape (Join-Path $Root 'public'))</WorkingDirectory></Exec></Actions>
</Task>
"@
}

function Stop-WebServer {
    param([int]$Port)
    if (Test-WebTask) { [void](Invoke-NativeResult 'schtasks.exe' @('/End', '/TN', (Get-WebTaskName))) }
    # Ending the task stops conhost; make sure its PHP child for this checkout and port goes too.
    $public = Join-Path (Get-RepositoryRoot) 'public'
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
        $_.CommandLine -and $_.CommandLine.Contains("-S 127.0.0.1:$Port") -and $_.CommandLine.Contains($public)
    } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

function Get-WebResponse {
    param([int]$Port)
    try {
        $request = [Net.HttpWebRequest]::Create("http://127.0.0.1:$Port/")
        $request.AllowAutoRedirect = $false
        $request.Timeout = 15000
        $response = $request.GetResponse()
    } catch [Net.WebException] {
        if (-not $_.Exception.Response) { return $null }
        $response = $_.Exception.Response
    }
    try { return [int]$response.StatusCode } finally { $response.Close() }
}

try {
    . "$PSScriptRoot/common.ps1"
    $root = Get-RepositoryRoot
    $name = Get-WebTaskName
    Write-Host "Web checkout: $root"
    Write-Host "Task: $name"

    if ($Operation -eq 'Install') {
        $php = Get-HerdPhp
        Stop-WebServer $Port
        $listener = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
        if ($listener) { throw "Port $Port is already in use by process $($listener[0].OwningProcess)." }
        $xmlPath = Join-Path ([IO.Path]::GetTempPath()) "$name.xml"
        try {
            [IO.File]::WriteAllText($xmlPath, (Get-WebTaskXml $php $root $Port), [Text.Encoding]::Unicode)
            Invoke-Checked 'schtasks.exe' @('/Create', '/F', '/TN', $name, '/XML', $xmlPath)
        } finally { Remove-Item -LiteralPath $xmlPath -Force -ErrorAction SilentlyContinue }
        Invoke-Checked 'schtasks.exe' @('/Run', '/TN', $name)
        $status = $null
        foreach ($attempt in 1..20) {
            $status = Get-WebResponse $Port
            if ($status) { break }
            Start-Sleep -Milliseconds 500
        }
        if (-not $status -or $status -ge 500) { throw "Web server did not answer on http://127.0.0.1:$Port/ (status: $status)." }
        Write-Host "Web server answers on http://127.0.0.1:$Port/ (HTTP $status)."
        Write-Host "Publish it on the tailnet with: tailscale serve --bg $Port"
    } elseif ($Operation -eq 'Uninstall') {
        if (Test-WebTask) {
            Stop-WebServer $Port
            Invoke-Checked 'schtasks.exe' @('/Delete', '/F', '/TN', $name)
            Write-Host 'Web server task removed.'
        } else { Write-Host 'Web server task is not installed.' }
    } else {
        if (-not (Test-WebTask)) { Write-Host 'Web server task is not installed.'; exit 0 }
        $query = Invoke-NativeResult 'schtasks.exe' @('/Query', '/TN', $name, '/FO', 'LIST')
        $state = ($query.Output -split "`r?`n" | Where-Object { $_ -match '^Status:' } | Select-Object -First 1) -replace '^Status:\s*', ''
        Write-Host "Task state: $state"
        $status = Get-WebResponse $Port
        if ($status) { Write-Host "http://127.0.0.1:$Port/ answers HTTP $status." } else { Write-Host "Nothing answers on http://127.0.0.1:$Port/." ; exit 1 }
    }
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
