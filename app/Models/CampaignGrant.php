<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CampaignGrant extends Model
{
    protected $guarded = [];

    protected $casts = ['limits' => 'array', 'slot' => 'integer', 'expires_at' => 'immutable_datetime',
        'paused_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime', 'last_directed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $grant) {
            $grant->agent_symbol = $grant->resolvedAgentSymbol();
            if ($grant->agent_symbol === '') {
                throw new LogicException('Campaign grants require an agent symbol.');
            }
        });
        static::updating(function (self $grant) {
            if (array_diff(array_keys($grant->getDirty()), ['slot', 'paused_at', 'pause_reason', 'wait_reason', 'revoked_at', 'superseded_at', 'last_directed_at', 'updated_at'])) {
                throw new LogicException('Campaign grant terms are immutable. Approve a new revision.');
            }
        });
        static::deleting(fn () => throw new LogicException('Campaign grants are retained for audit.'));
    }

    public function available(): bool
    {
        return ($this->slot === 1 || ($this->slot === null && $this->arm())) && ! $this->paused_at && ! $this->revoked_at && ! $this->superseded_at
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function resolvedAgentSymbol(): string
    {
        if (is_string($this->agent_symbol) && $this->agent_symbol !== '') {
            return strtoupper($this->agent_symbol);
        }

        return strtoupper((string) ($this->revision()->body['agent']['symbol'] ?? ''));
    }

    public function isDraining(): bool
    {
        return is_string($this->wait_reason) && str_starts_with($this->wait_reason, 'drain_pause:');
    }

    public static function primaryFor(int $userId, string $agentSymbol): Builder
    {
        return static::where('user_id', $userId)->where('agent_symbol', strtoupper($agentSymbol))->where('slot', 1);
    }

    public static function pendingFor(int $userId, string $agentSymbol): Builder
    {
        return static::where('user_id', $userId)->where('agent_symbol', strtoupper($agentSymbol))->where('slot', 2);
    }

    public static function allPrimaries(?int $userId = null): Builder
    {
        return static::where('slot', 1)->when($userId !== null, fn ($q) => $q->where('user_id', $userId));
    }

    /** An arm grant directs one test ship under its own episode: ['label', 'ship', 'follow', 'max_net_loss', 'max_load_spend', 'exposure_share']. */
    public function arm(): ?array
    {
        return $this->limits['arm'] ?? null;
    }

    /** Arm grants that are neither revoked nor superseded (paused or expired ones included). */
    public static function liveArms(?string $agentSymbol = null): Builder
    {
        return static::whereNull('slot')->whereNotNull('limits->arm')->whereNull('revoked_at')->whereNull('superseded_at')
            ->when($agentSymbol !== null && $agentSymbol !== '', fn ($q) => $q->where('agent_symbol', strtoupper($agentSymbol)));
    }

    public function revision(): CampaignRevision
    {
        return CampaignRevision::findOrFail($this->campaign_revision_id);
    }
}
