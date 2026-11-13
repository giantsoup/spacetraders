<#
.SYNOPSIS
Install, uninstall or inspect the loopback-only Herd nginx site that `tailscale serve` publishes.
.DESCRIPTION
Writes an nginx server block for this checkout into Herd's valet\Nginx folder. It listens on
127.0.0.1 only, accepts only the names `tailscale serve` publishes for the port plus 127.0.0.1
and localhost (anything else gets HTTP 421), and hands PHP to Herd's PHP 8.5 FastCGI pool, so a
long request such as a first fleet synchronization no longer blocks every other request.
Install tests the configuration and reloads nginx in place; PHP and the engine keep running.
It also removes the older PHP built-in server task if it is still registered. Engine controls
stay limited to the local Herd host, because no tailnet request carries that host name.
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

$FastCgi = '127.0.0.1:9085'

function Get-NginxExe { return (Join-Path $env:ProgramFiles 'Herd\resources\app.asar.unpacked\resources\bin\nginx\nginx.exe') }
function Get-NginxPrefix { return ((Join-Path $env:USERPROFILE '.config\herd\config\nginx\').Replace('\', '/')) }
function Get-SiteDirectory { return (Join-Path $env:USERPROFILE '.config\herd\config\valet\Nginx') }
function Get-SiteConfPath { return (Join-Path (Get-SiteDirectory) ('spacetraders-web-' + (Get-CheckoutHash) + '.conf')) }

function Invoke-Nginx {
    param([string[]]$Arguments)
    return Invoke-NativeResult (Get-NginxExe) (@('-p', (Get-NginxPrefix), '-c', 'nginx.conf') + $Arguments)
}

function Remove-LegacyWebTask {
    # Earlier versions ran PHP's single-process built-in server on this port from a Scheduled Task.
    $name = 'SpaceTraders-Web-' + (Get-CheckoutHash)
    if ((Invoke-NativeResult 'schtasks.exe' @('/Query', '/TN', $name)).ExitCode -eq 0) {
        [void](Invoke-NativeResult 'schtasks.exe' @('/End', '/TN', $name))
        Invoke-Checked 'schtasks.exe' @('/Delete', '/F', '/TN', $name)
        Write-Host "Removed the old built-in server task $name."
    }
    $script = Join-Path $PSScriptRoot 'web.ps1'
    Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" | Where-Object {
        $_.ProcessId -ne $PID -and $_.CommandLine -and $_.CommandLine.Contains($script) -and $_.CommandLine -match '\sRun\s'
    } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    $public = Join-Path (Get-RepositoryRoot) 'public'
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
        $_.CommandLine -and $_.CommandLine.Contains("-S 127.0.0.1:$Port") -and $_.CommandLine.Contains($public)
    } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

function Assert-Herd {
    if (-not (Test-Path -LiteralPath (Get-NginxExe) -PathType Leaf)) { throw 'Herd nginx was not found; install and start Herd.' }
    $config = [IO.File]::ReadAllText((Join-Path (Get-NginxPrefix) 'nginx.conf'))
    $include = (Join-Path (Get-SiteDirectory) '*.conf').Replace('\', '/')
    if ($config -notmatch ('include\s+"?' + [regex]::Escape($include) + '"?\s*;')) { throw "Herd nginx.conf no longer includes $include." }
    $listener = @(Get-NetTCPConnection -State Listen -LocalAddress 127.0.0.1 -LocalPort 9085 -ErrorAction SilentlyContinue)
    if ($listener.Count -eq 0) { throw "Herd's PHP 8.5 FastCGI pool is not listening on $FastCgi; start Herd." }
    $process = Get-CimInstance Win32_Process -Filter "ProcessId=$($listener[0].OwningProcess)"
    if (-not $process -or $process.Name -ne 'php-cgi.exe' -or $process.ExecutablePath -notlike '*\herd\bin\php85\php-cgi.exe') {
        throw "Port 9085 is not Herd's PHP 8.5 FastCGI pool."
    }
}

function Assert-PortFree {
    param([string]$Conf)
    foreach ($other in @(Get-ChildItem -LiteralPath (Get-SiteDirectory) -Filter '*.conf' | Where-Object { $_.FullName -ne $Conf })) {
        if ([IO.File]::ReadAllText($other.FullName) -match "listen\s+127\.0\.0\.1:$Port\s*;") { throw "$($other.Name) already listens on port $Port." }
    }
    foreach ($listener in @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)) {
        $process = Get-CimInstance Win32_Process -Filter "ProcessId=$($listener.OwningProcess)"
        if (-not $process -or $process.ExecutablePath -ne (Get-NginxExe)) { throw "Port $Port is in use by process $($listener.OwningProcess)." }
    }
}

function Get-SiteConf {
    param([string]$Root, [string[]]$Names)
    $public = (Join-Path $Root 'public').Replace('\', '/')
    $log = (Join-Path $env:USERPROFILE ('.config\herd\Log\spacetraders-web-' + (Get-CheckoutHash) + '.log')).Replace('\', '/')
    $hostPattern = ($Names | ForEach-Object { [regex]::Escape($_) }) -join '|'
    return @"
# Managed by scripts\windows\web.ps1 in $($Root.Replace('\', '/')). Do not edit here; run web.ps1 Install.
# Herd does not manage this file. tailscale serve proxies the tailnet name to it.
server {
    listen 127.0.0.1:$Port;
    server_name $($Names -join ' ');
    root "$public";
    index index.php;
    charset utf-8;
    client_max_body_size 16M;

    # Only the published tailnet names and loopback. A request naming the Herd host never reaches PHP here.
    if (`$host !~* "^($hostPattern)`$") { return 421; }

    access_log off;
    error_log "$log";

    location = /favicon.ico { log_not_found off; }
    location = /robots.txt  { log_not_found off; }
    location ~ /\.(?!well-known).* { deny all; }

    # Vite's file names carry a content hash, so the iPad may keep them.
    location /build/assets/ {
        try_files `$uri =404;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }

    location / {
        try_files `$uri `$uri/ /index.php?`$query_string;
    }

    location = /index.php {
        fastcgi_pass $FastCgi;
        fastcgi_param SCRIPT_FILENAME   `$realpath_root`$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT     `$realpath_root;
        fastcgi_param QUERY_STRING      `$query_string;
        fastcgi_param REQUEST_METHOD    `$request_method;
        fastcgi_param CONTENT_TYPE      `$content_type;
        fastcgi_param CONTENT_LENGTH    `$content_length;
        fastcgi_param SCRIPT_NAME       `$fastcgi_script_name;
        fastcgi_param REQUEST_URI       `$request_uri;
        fastcgi_param DOCUMENT_URI      `$document_uri;
        fastcgi_param SERVER_PROTOCOL   `$server_protocol;
        fastcgi_param GATEWAY_INTERFACE CGI/1.1;
        fastcgi_param SERVER_SOFTWARE   nginx;
        fastcgi_param REMOTE_ADDR       `$remote_addr;
        fastcgi_param REMOTE_PORT       `$remote_port;
        fastcgi_param SERVER_ADDR       `$server_addr;
        fastcgi_param SERVER_PORT       `$server_port;
        fastcgi_param SERVER_NAME       `$server_name;
        fastcgi_param REDIRECT_STATUS   200;
        fastcgi_param HTTP_PROXY        "";
        fastcgi_param HTTP_X_FORWARDED_PORT "";
        fastcgi_param HTTP_X_FORWARDED_PREFIX "";
        fastcgi_buffer_size 512k;
        fastcgi_buffers 16 512k;
        # Synchronization may run for up to a minute; leave room above PHP's own limit.
        fastcgi_read_timeout 120s;
    }

    location ~ \.php`$ { return 404; }
}
"@
}

function Set-SiteConf {
    # Write the file, test the whole Herd configuration, and reload; on any failure put the old file back.
    # PowerShell turns a $null [string] into '', so removal is its own switch.
    param([string]$Path, [string]$Content, [switch]$Remove)
    $previous = if (Test-Path -LiteralPath $Path) { [IO.File]::ReadAllText($Path) } else { $null }
    if (-not $Remove -and $previous -ceq $Content) { Write-Host 'nginx site is already current.'; return }
    $baseline = Invoke-Nginx @('-t')
    if ($baseline.ExitCode -ne 0) { throw "Herd's nginx configuration fails its test before any change:`n$($baseline.Error.Trim())" }
    try {
        if ($Remove) { Remove-Item -LiteralPath $Path -Force }
        else { [IO.File]::WriteAllText($Path, $Content, (New-Object Text.UTF8Encoding $false)) }
        $test = Invoke-Nginx @('-t')
        if ($test.ExitCode -ne 0) { throw "nginx rejected the site:`n$($test.Error.Trim())" }
        $reload = Invoke-Nginx @('-s', 'reload')
        if ($reload.ExitCode -ne 0) { throw "nginx did not reload:`n$($reload.Error.Trim())" }
    } catch {
        if ($null -eq $previous) { Remove-Item -LiteralPath $Path -Force -ErrorAction SilentlyContinue }
        else { [IO.File]::WriteAllText($Path, $previous, (New-Object Text.UTF8Encoding $false)) }
        [void](Invoke-Nginx @('-s', 'reload'))
        throw
    }
    Write-Host 'nginx configuration tested and reloaded.'
}

function Get-WebResponse {
    param([int]$Port)
    try {
        $request = [Net.HttpWebRequest]::Create("http://127.0.0.1:$Port/login")
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
    $conf = Get-SiteConfPath
    Write-Host "Web checkout: $root"
    Write-Host "nginx site: $conf"

    if ($Operation -eq 'Install') {
        Assert-Herd
        Remove-LegacyWebTask
        foreach ($attempt in 1..10) {
            if (-not (Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue | Where-Object { (Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue).Name -eq 'php' })) { break }
            Start-Sleep -Milliseconds 500
        }
        Assert-PortFree $conf
        $tailscale = Join-Path $env:ProgramFiles 'Tailscale\tailscale.exe'
        $names = @()
        if (Test-Path -LiteralPath $tailscale) { $names = @(Get-TailnetHosts $tailscale $Port) }
        if ($names.Count -eq 0) { Write-Warning "tailscale serve publishes nothing that proxies to 127.0.0.1:$Port; only loopback names are accepted." }
        $names = @($names) + @('127.0.0.1', 'localhost')
        Set-SiteConf $conf (Get-SiteConf $root $names)
        $status = $null
        foreach ($attempt in 1..20) {
            $status = Get-WebResponse $Port
            if ($status) { break }
            Start-Sleep -Milliseconds 500
        }
        if ($status -ne 200) { throw "http://127.0.0.1:$Port/login did not answer HTTP 200 (status: $status)." }
        Write-Host "Herd nginx answers on http://127.0.0.1:$Port/ for: $($names -join ', ')."
        Write-Host "Publish it on the tailnet with: tailscale serve --bg $Port"
    } elseif ($Operation -eq 'Uninstall') {
        Remove-LegacyWebTask
        if (Test-Path -LiteralPath $conf) { Set-SiteConf $conf -Remove; Write-Host 'nginx site removed.' }
        else { Write-Host 'nginx site is not installed.' }
    } else {
        if (-not (Test-Path -LiteralPath $conf)) { Write-Host 'nginx site is not installed.'; exit 0 }
        $names = ([IO.File]::ReadAllText($conf) -split "`n" | Where-Object { $_ -match '^\s*server_name\s' } | Select-Object -First 1) -replace '^\s*server_name\s+|;\s*$', ''
        Write-Host "Accepted names: $names"
        $status = Get-WebResponse $Port
        if ($status) { Write-Host "http://127.0.0.1:$Port/login answers HTTP $status." } else { Write-Host "Nothing answers on http://127.0.0.1:$Port/."; exit 1 }
    }
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
