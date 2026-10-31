<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ship extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['snapshot' => 'array', 'observed_at' => 'immutable_datetime'];
}
