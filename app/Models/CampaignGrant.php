<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CampaignGrant extends Model
{
    protected $guarded = [];

    protected $casts = ['limits' => 'array', 'slot' => 'integer', 'expires_at' => 'immutable_datetime',
        'paused_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime', 'last_directed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $grant) {
            if (array_diff(array_keys($grant->getDirty()), ['slot', 'paused_at', 'pause_reason', 'wait_reason', 'revoked_at', 'superseded_at', 'last_directed_at', 'updated_at'])) {
                throw new LogicException('Campaign grant terms are immutable. Approve a new revision.');
            }
        });
        static::deleting(fn () => throw new LogicException('Campaign grants are retained for audit.'));
    }

    public function available(): bool
    {
        return $this->slot === 1 && ! $this->paused_at && ! $this->revoked_at && ! $this->superseded_at
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function revision(): CampaignRevision
    {
        return CampaignRevision::findOrFail($this->campaign_revision_id);
    }
}
