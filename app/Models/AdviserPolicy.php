<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AdviserPolicy extends Model
{
    protected $guarded = [];

    protected $hidden = ['credential_hash'];

    protected $casts = ['terms' => 'array', 'version' => 'integer', 'schema_version' => 'integer', 'token_version' => 'integer', 'expires_at' => 'immutable_datetime', 'paused_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $policy) {
            if (array_diff(array_keys($policy->getDirty()), ['paused_at', 'revoked_at', 'updated_at'])) {
                throw new LogicException('Grant terms are immutable. Save a new policy version.');
            }
        });
    }

    public function available(Agent $agent): bool
    {
        return ! $this->paused_at && ! $this->revoked_at && $this->expires_at->isFuture()
            && ! $agent->isArchived() && $agent->reset_id === $this->reset_id && $agent->token_version === $this->token_version
            && hash_equals($this->credential_hash, hash('sha256', $agent->getRawOriginal('token')));
    }
}
