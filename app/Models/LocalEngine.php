<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalEngine extends Model
{
    protected $guarded = [];

    protected $casts = ['gate_open' => 'boolean', 'processes' => 'array', 'heartbeat_at' => 'immutable_datetime', 'stop_requested_at' => 'immutable_datetime'];
}
