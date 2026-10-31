<?php

namespace App\Models;

use App\Gameplay\GameException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'token',
        'account_id',
        'faction',
        'email', 'canonical_identity', 'reset_id', 'credits', 'connection_state', 'synced_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected $casts = ['token' => 'encrypted', 'token_version' => 'integer', 'credits' => 'integer', 'synced_at' => 'immutable_datetime'];

    public function setTokenAttribute(string $value): void
    {
        $this->attributes['token'] = encrypt($value, false);
        $this->attributes['token_version'] = 1;
    }

    public function credential(): string
    {
        if ($this->token_version !== 1) {
            throw new GameException('Convert legacy credentials before using this agent.');
        }
        try {
            return $this->token;
        } catch (DecryptException) {
            throw new GameException('Saved credential is unreadable. Re-import the agent.');
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
