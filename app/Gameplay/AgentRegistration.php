<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\Ship;
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

        $agent = $this->save($user, $payload['agent'], $payload['token'], $reset, $data['email'] ?? null);
        $hq = $payload['agent']['headquarters'] ?? null;
        $system = FleetClaims::systemOf(is_string($hq) ? $hq : null);
        if ($campaign && $system) {
            $otherAgents = $user->agents()->unarchived()->where('reset_id', $reset)->whereKeyNot($agent->id);
            $overlap = Ship::whereIn('agent_id', $otherAgents->select('id'))->where('snapshot->nav->systemSymbol', $system)->exists();
            $markets = GameObservation::whereIn('agent_id', $otherAgents->select('id'))->where('reset_id', $reset)
                ->where('kind', 'market_listing')->where('symbol', 'like', $system.'-%')->latest('observed_at')->limit(50)
                ->get()->unique('symbol')->map(fn ($market) => ['waypoint' => $market->symbol, 'observed_at' => $market->observed_at->toIso8601String(),
                    'imports' => array_column($market->data['imports'] ?? [], 'symbol'), 'exports' => array_column($market->data['exports'] ?? [], 'symbol'),
                    'exchange' => array_column($market->data['exchange'] ?? [], 'symbol')])->values()->all();
            app(AgentSynchronizer::class)->observe($agent, 'registration', $agent->name, [
                'headquarters' => $hq, 'system' => $system, 'shared_system' => $overlap, 'giantsoup_control_system' => $system === 'X1-SK70',
                'known_markets' => $markets,
            ], 'registration');
        }

        return $agent;
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
