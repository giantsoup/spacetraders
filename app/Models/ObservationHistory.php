<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObservationHistory extends Model
{
    protected $table = 'observation_history';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['data' => 'array', 'observed_at' => 'immutable_datetime'];
}
