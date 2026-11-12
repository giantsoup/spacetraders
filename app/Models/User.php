<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'spacetraders_account_token',
        'spacetraders_account_token_saved_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'spacetraders_account_token' => 'encrypted',
        'spacetraders_account_token_saved_at' => 'immutable_datetime',
    ];

    public function hasSavedAccountToken(): bool
    {
        return $this->savedAccountToken() !== null;
    }

    public function savedAccountToken(): ?string
    {
        try {
            return $this->spacetraders_account_token;
        } catch (DecryptException) {
            $this->forgetAccountToken();

            return null;
        }
    }

    public function rememberAccountToken(string $token): void
    {
        $this->forceFill([
            'spacetraders_account_token' => $token,
            'spacetraders_account_token_saved_at' => now(),
        ])->save();
    }

    public function forgetAccountToken(): void
    {
        $this->forceFill([
            'spacetraders_account_token' => null,
            'spacetraders_account_token_saved_at' => null,
        ])->save();
    }

    /**
     * Forget the saved token only if it is still the one this request read, so a
     * slow rejected registration cannot erase a token saved meanwhile elsewhere.
     */
    public function forgetAccountTokenIfUnchanged(?string $ciphertext): void
    {
        if ($ciphertext === null) {
            return;
        }
        static::whereKey($this->getKey())->where('spacetraders_account_token', $ciphertext)->update([
            'spacetraders_account_token' => null,
            'spacetraders_account_token_saved_at' => null,
        ]);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
