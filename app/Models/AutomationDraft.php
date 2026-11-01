<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationDraft extends Model
{
    protected $guarded = [];

    protected $casts = ['candidate' => 'array', 'limits' => 'array'];
}
