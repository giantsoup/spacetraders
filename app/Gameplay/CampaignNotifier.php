<?php

namespace App\Gameplay;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class CampaignNotifier
{
    public function send(string $message): void
    {
        $this->log($message);
        try {
            $this->toast($message);
        } catch (\Throwable) {
            $this->log('Windows toast delivery failed.');
        }
        $url = config('campaign.alert_webhook_url');
        if (is_string($url) && $url !== '') {
            try {
                Http::timeout(5)->connectTimeout(3)->post($url, ['content' => $message, 'allowed_mentions' => ['parse' => []]])->throw();
            } catch (\Throwable) {
                $this->log('Webhook delivery failed; local alert retained.');
            }
        }
    }

    public function log(string $message): void
    {
        $path = config('campaign.watchdog_log_path');
        File::ensureDirectoryExists(dirname($path));
        File::append($path, now()->utc()->toIso8601String().' '.$message.PHP_EOL, LOCK_EX);
    }

    public function toast(string $message): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }
        $xml = '<toast><visual><binding template="ToastGeneric"><text>SpaceTraders campaign</text><text>'.htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</text></binding></visual></toast>';
        $encoded = base64_encode($xml);
        $script = '$ErrorActionPreference = "Stop"; [Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime] > $null; '
            .'$xml = New-Object Windows.Data.Xml.Dom.XmlDocument; $xml.LoadXml([Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("'.$encoded.'"))); '
            .'$toast = [Windows.UI.Notifications.ToastNotification]::new($xml); '
            .'[Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier("Microsoft.Windows.PowerShell").Show($toast);';
        $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-WindowStyle', 'Hidden', '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))]);
        $process->setTimeout(10)->mustRun();
    }
}
