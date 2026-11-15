param([switch]$Install, [switch]$Uninstall)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
try {
    if ($Install -and $Uninstall) { throw 'Choose -Install or -Uninstall, not both.' }
    . "$PSScriptRoot/common.ps1"
    $root = Get-RepositoryRoot
    $hash = [Security.Cryptography.SHA256]::Create()
    try { $suffix = ([BitConverter]::ToString($hash.ComputeHash([Text.Encoding]::UTF8.GetBytes($root.ToLowerInvariant())))).Replace('-', '').Substring(0, 12) }
    finally { $hash.Dispose() }
    $name = "SpaceTraders-CampaignWatchdog-$suffix"
    if ($Uninstall) {
        $task = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        if ($task) { Unregister-ScheduledTask -TaskName $name -Confirm:$false }
        Write-Host "Watchdog task removed: $name"
        exit 0
    }
    $php = Get-HerdPhp
    if ($Install) {
        $executable = Join-Path $PSHOME 'powershell.exe'
        $arguments = '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File ' + (ConvertTo-NativeArgument $PSCommandPath)
        $action = New-ScheduledTaskAction -Execute $executable -Argument $arguments -WorkingDirectory $root
        $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 15)
        $trigger.Repetition.StopAtDurationEnd = $false
        if ($trigger.Repetition.Duration -or $trigger.EndBoundary) { throw 'Watchdog trigger must repeat indefinitely.' }
        $principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
        $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 3) -Hidden
        Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "Independent campaign health alerts for $root" -Force | Out-Null
        Write-Host "Watchdog installed every 15 minutes: $name"
        Write-Host "Checkout: $root"
        exit 0
    }
    $result = Invoke-NativeResult $php @('artisan', 'campaign:watchdog', '--no-interaction')
    Show-NativeResult $result
    exit $result.ExitCode
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
