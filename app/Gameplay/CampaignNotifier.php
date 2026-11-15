<?php

namespace App\Gameplay;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class CampaignNotifier
{
    private const RED = 15548997;

    private const GREEN = 5763719;

    private const SUPPRESS_NOTIFICATIONS = 4096;

    public function alert(string $key, string $message, ?int $firstSeen, int $nextReminder): void
    {
        $embed = $this->embed('🚨 Red alert: '.$key, $message, self::RED);
        $embed['fields'] = array_values(array_filter([
            ['name' => 'Problem', 'value' => '`'.$key.'`', 'inline' => true],
            $firstSeen ? ['name' => 'First seen', 'value' => '<t:'.$firstSeen.':R>', 'inline' => true] : null,
            ['name' => 'Next reminder', 'value' => '<t:'.$nextReminder.':R>', 'inline' => true],
        ]));
        $payload = ['allowed_mentions' => ['parse' => []]];
        $mention = config('campaign.alert_mention_user_id');
        if (is_string($mention) && preg_match('/\A\d{5,25}\z/', $mention)) {
            $payload['content'] = '<@'.$mention.'>';
            $payload['allowed_mentions']['users'] = [$mention];
        }
        $dashboard = config('campaign.alert_dashboard_url');
        $components = is_string($dashboard) && str_starts_with($dashboard, 'https://');
        if ($components) {
            $embed['url'] = $dashboard;
            $payload['components'] = [['type' => 1, 'components' => [['type' => 2, 'style' => 5, 'label' => 'Open dashboard', 'url' => $dashboard]]]];
        }
        $payload['embeds'] = [$embed];
        $this->deliver('Alert ['.$key.']: '.$message, $payload, $components);
    }

    public function recovered(string $key, ?int $firstSeen): void
    {
        $description = 'Problem cleared.';
        if ($firstSeen) {
            $description .= ' Lasted '.now()->diffForHumans(now()->setTimestamp($firstSeen), ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]).'.';
        }
        $this->deliver('Recovered ['.$key.']: problem cleared.', [
            'allowed_mentions' => ['parse' => []],
            'flags' => self::SUPPRESS_NOTIFICATIONS,
            'embeds' => [$this->embed('✅ All clear: '.$key, $description, self::GREEN)],
        ], false);
    }

    private function embed(string $title, string $description, int $color): array
    {
        return ['title' => Str::limit($title, 253), 'description' => Str::limit($description, 4093), 'color' => $color,
            'footer' => ['text' => 'Deep Soup Nine · campaign watchdog'], 'timestamp' => now()->utc()->toIso8601String()];
    }

    private function deliver(string $text, array $payload, bool $components): void
    {
        $this->log($text);
        try {
            $this->toast($text);
        } catch (\Throwable) {
            $this->log('Windows toast delivery failed.');
        }
        $url = config('campaign.alert_webhook_url');
        if (is_string($url) && $url !== '') {
            if ($components) {
                $url .= (str_contains($url, '?') ? '&' : '?').'with_components=true';
            }
            try {
                Http::timeout(5)->connectTimeout(3)->post($url, $payload)->throw();
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
            .'[Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier("{1AC14E77-02E7-4E5D-B744-2EB1AE5198B7}\WindowsPowerShell\v1.0\powershell.exe").Show($toast);';
        $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-WindowStyle', 'Hidden', '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))]);
        $process->setTimeout(10)->mustRun();
    }
}
