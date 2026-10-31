<?php

namespace App\Models;

use App\Enums\RunState;
use Illuminate\Database\Eloquent\Model;

class AutomationRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'state' => RunState::class, 'plan' => 'array', 'totals' => 'array',
        'next_action_at' => 'immutable_datetime', 'heartbeat_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'stop_requested_at' => 'immutable_datetime',
    ];
}
