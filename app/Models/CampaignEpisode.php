<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignEpisode extends Model
{
    protected $guarded = [];

    protected $casts = ['state' => 'array', 'start_credits' => 'integer', 'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'next_reset_at' => 'immutable_datetime'];

    public function grant(): CampaignGrant
    {
        return CampaignGrant::findOrFail($this->campaign_grant_id);
    }
}
