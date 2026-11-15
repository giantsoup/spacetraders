<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['data' => 'array'];
}
