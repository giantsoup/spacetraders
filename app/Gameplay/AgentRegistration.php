<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Illuminate\Validation\ValidationException;

class AgentRegistration
{
    public function register(User $user, array $data, string $accountToken, string $reset, bool $usingSavedToken = false, ?string $savedCiphertext = null, bool $campaign = false): Agent
    {
        try {
            $payload = app(SpaceTradersClient::class)->register(array_filter([
                'symbol' => strtoupper($data['symbol']), 'faction' => strtoupper($data['faction']), 'email' => $data['email'] ?? null,
            ], fn ($value) => $value !== null), $accountToken, $campaign);
        } catch (ValidationException $exception) {
            if ($usingSavedToken && in_array($exception->errors()['api'][0] ?? null, [SpaceTradersClient::TOKEN_INVALID, SpaceTradersClient::TOKEN_FORBIDDEN], true)) {
                $user->forgetAccountTokenIfUnchanged($savedCiphertext);
                throw ValidationException::withMessages(['api' => 'Your saved account token was rejected and has been forgotten. Paste a current account token from the SpaceTraders dashboard.']);
            }
            throw $exception;
        }

        return $this->save($user, $payload['agent'], $payload['token'], $reset, $data['email'] ?? null);
    }

    public function reset(User $user): string
    {
        try {
            $candidates = $user->agents()->unarchived()->whereNotNull('reset_id')->get();
            $reset = app(SpaceTradersApi::class)->status();
            app(AgentSynchronizer::class)->archivePreviousWorlds($candidates, $reset);

            return $reset;
        } catch (GameException $e) {
            throw ValidationException::withMessages(['api' => $e->getMessage()]);
        }
    }

    public function save(User $user, array $payload, string $token, string $reset, ?string $email = null): Agent
    {
        try {
            return app(HostLock::class)->run('imports', function () use ($user, $payload, $token, $reset, $email) {
                $identity = AgentSynchronizer::identity($reset, $payload['symbol']);
                $existing = Agent::where('canonical_identity', $identity)->first();
                if (($existing && $existing->user_id !== $user->id) || Agent::where('name', $payload['symbol'])->whereNull('reset_id')->where('user_id', '!=', $user->id)->exists()) {
                    throw new GameException('This agent cannot be imported into this local account.');
                }
                $existing ??= Agent::where('name', $payload['symbol'])->where('user_id', $user->id)->whereNull('reset_id')->orderBy('id')->first();
                $attributes = [
                    'token' => $token, 'name' => $payload['symbol'], 'account_id' => $payload['accountId'] ?? $existing?->account_id,
                    'faction' => $payload['startingFaction'], 'email' => $email, 'reset_id' => $reset,
                    'canonical_identity' => $identity, 'connection_state' => 'unsynchronized',
                ];
                if ($existing) {
                    app(ShipCommandExecutor::class)->locked($existing, function () use ($existing, $attributes) {
                        if (ShipAction::where('agent_id', $existing->id)->whereIn('state', ['pending', 'sending'])->exists() || AutomationRun::where('agent_id', $existing->id)->whereNotNull('active_agent_id')->whereNull('stop_requested_at')->exists()) {
                            throw new GameException('Stop automation and wait for pending or sending work before replacing credentials. Unresolved outcomes remain blocked.');
                        }
                        $existing->update($attributes);
                    });
                    app(SpaceTradersApi::class)->clearResetCache();

                    return $existing;
                } else {
                    $created = $user->agents()->create($attributes);
                    app(SpaceTradersApi::class)->clearResetCache();

                    return $created;
                }
            });
        } catch (GameException $e) {
            throw ValidationException::withMessages(['api' => $e->getMessage()]);
        }
    }
}
