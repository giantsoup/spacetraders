<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CampaignRevision extends Model
{
    protected $guarded = [];

    protected $casts = ['body' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Campaign revisions are immutable. Import a new revision.'));
        static::deleting(fn () => throw new LogicException('Campaign revisions are retained for audit.'));
    }
}
