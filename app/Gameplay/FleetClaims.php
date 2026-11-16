<?php

namespace App\Gameplay;

use App\Models\CampaignEpisode;

/** W1 stub only. The integrator replaces this file with W2's FleetClaims. */
class FleetClaims
{
    public function claim(CampaignEpisode $episode, array $candidate): void {}

    public function release(CampaignEpisode $episode, string $reason): void {}

    public function taken(CampaignEpisode $self): array
    {
        return [];
    }
}
