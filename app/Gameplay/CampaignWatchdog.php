<?php

namespace App\Gameplay;

use Illuminate\Support\Facades\File;

class CampaignWatchdog
{
    public function check(): array
    {
        return app(HostLock::class)->run('campaign-watchdog', function () {
            $path = config('campaign.watchdog_state_path');
            $previous = is_file($path) ? json_decode(File::get($path), true) : [];
            $previous = is_array($previous) ? $previous : [];
            $problems = app(CampaignHealth::class)->problems();
            $state = [];
            foreach ($problems as $key => $message) {
                $last = $previous[$key] ?? 0;
                if (! is_int($last) || $last <= now()->subHours(2)->timestamp) {
                    app(CampaignNotifier::class)->send('Alert ['.$key.']: '.$message);
                    $last = now()->timestamp;
                }
                $state[$key] = $last;
            }
            foreach (array_diff_key($previous, $problems) as $key => $last) {
                app(CampaignNotifier::class)->send('Recovered ['.$key.']: problem cleared.');
            }
            File::ensureDirectoryExists(dirname($path));
            File::replace($path, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

            return $problems;
        });
    }
}
