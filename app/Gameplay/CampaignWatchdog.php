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
                // State was {key: lastAlertTs}; it is now {key: {last, first}}. Read both.
                [$last, $first] = $this->entry($previous[$key] ?? null);
                $first ??= now()->timestamp;
                if ($last <= now()->subHours(2)->timestamp) {
                    $last = now()->timestamp;
                    app(CampaignNotifier::class)->alert($key, $message, $first, $last + 7200);
                }
                $state[$key] = ['last' => $last, 'first' => $first];
            }
            foreach (array_diff_key($previous, $problems) as $key => $entry) {
                app(CampaignNotifier::class)->recovered($key, $this->entry($entry)[1]);
            }
            File::ensureDirectoryExists(dirname($path));
            File::replace($path, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

            return $problems;
        });
    }

    /** @return array{0: int, 1: ?int} last alert and first seen timestamps */
    private function entry(mixed $entry): array
    {
        if (is_int($entry)) {
            return [$entry, null];
        }
        if (is_array($entry)) {
            return [is_int($entry['last'] ?? null) ? $entry['last'] : 0, is_int($entry['first'] ?? null) ? $entry['first'] : null];
        }

        return [0, null];
    }
}
