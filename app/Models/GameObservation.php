<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameObservation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['data' => 'array', 'observed_at' => 'immutable_datetime'];
}
