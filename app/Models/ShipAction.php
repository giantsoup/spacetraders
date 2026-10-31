<?php

namespace App\Models;

use App\Enums\ActionState;
use Illuminate\Database\Eloquent\Model;

class ShipAction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'state' => ActionState::class, 'arguments' => 'array', 'before_state' => 'array', 'outcome' => 'array',
        'expires_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime',
    ];
}
