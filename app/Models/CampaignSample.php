<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignSample extends Model
{
    protected $guarded = [];

    protected $casts = ['credits' => 'integer', 'ships' => 'array', 'request_budget' => 'array', 'observed_at' => 'immutable_datetime'];
}
