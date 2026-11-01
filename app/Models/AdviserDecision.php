<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AdviserDecision extends Model
{
    protected $guarded = [];

    protected $casts = ['evidence' => 'array', 'candidates' => 'array', 'selection' => 'array', 'expires_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Decision snapshots are immutable. Create a new assessment.'));
        static::deleting(fn () => throw new LogicException('Decision history cannot be deleted individually.'));
    }
}
