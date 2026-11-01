<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpportunityInvestigation extends Model
{
    protected $guarded = [];

    protected $casts = ['coverage' => 'array', 'observations' => 'array', 'candidates' => 'array', 'cancelled_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
}
