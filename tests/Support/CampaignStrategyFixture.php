<?php

namespace Tests\Support;

use App\Gameplay\CampaignPlaybook;
use App\Models\CampaignGrant;
use App\Models\User;
use Symfony\Component\Yaml\Yaml;

/** Synthetic sources for the isolated browser harness; never uses a provider or an engine. */
class CampaignStrategyFixture
{
    public static function seed(User $user, string $state): void
    {
        if ($state === 'example') {
            return;
        }
        $body = Yaml::parse(file_get_contents(base_path('campaigns/weekly-default.yaml')));
        $body['name'] = $state === 'no-trade' ? 'offline-survey' : 'offline-approved';
        if ($state === 'no-trade') {
            $body['phases'] = [['id' => 'survey', 'activities' => [
                ['role' => 'probe', 'strategy' => 'scout', 'when' => ['credits_gte' => 1000]],
                ['role' => 'command', 'strategy' => 'mining'],
                ['role' => 'command', 'strategy' => 'sell_aboard'],
            ]]];
        }
        $revision = app(CampaignPlaybook::class)->import(Yaml::dump($body, 8));
        CampaignGrant::create(['campaign_revision_id' => $revision->id, 'revision_hash' => $revision->content_hash,
            'user_id' => $user->id, 'slot' => 1, 'limits' => [],
            'paused_at' => $state === 'paused' ? now() : null, 'pause_reason' => $state === 'paused' ? 'operator_pause' : null,
            'expires_at' => $state === 'expired' ? now()->subHour() : null]);
        if ($state === 'pending') {
            $body['name'] = 'offline-queued';
            $revision = app(CampaignPlaybook::class)->import(Yaml::dump($body, 8));
            CampaignGrant::create(['campaign_revision_id' => $revision->id, 'revision_hash' => $revision->content_hash,
                'user_id' => $user->id, 'slot' => 2, 'limits' => []]);
        }
    }
}
