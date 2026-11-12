<?php

namespace App\Http\Controllers;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function index(Request $request): Response
    {
        $resetCheckError = null;
        $candidates = $request->user()->agents()->unarchived()->whereNotNull('reset_id')->get();
        if ($candidates->isNotEmpty()) {
            try {
                $reset = app(SpaceTradersApi::class)->status();
                app(AgentSynchronizer::class)->archivePreviousWorlds($candidates, $reset);
            } catch (GameException) {
                $resetCheckError = 'Could not finish checking for a world reset. Some agents may belong to a previous world. Refresh to try again.';
            }
        }

        return Inertia::render('Agent/Agents', [
            'agents' => $request->user()->agents()->unarchived()->orderBy('name')->orderBy('id')->get(),
            'archivedAgents' => $request->user()->agents()->archived()->orderByDesc('reset_id')->orderBy('name')->orderByDesc('id')->get(),
            'resetCheckError' => $resetCheckError,
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        $factions = config('foundation.enums.FactionSymbol');
        $from = $request->query('from');
        $fromId = is_string($from) ? filter_var($from, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $agent = $fromId ? $user->agents()->archived()->find($fromId) : null;
        $hasSavedToken = $user->hasSavedAccountToken();

        return Inertia::render('Agent/CreateAgent', [
            'factions' => $factions,
            'savedAccountToken' => [
                'saved' => $hasSavedToken,
                'savedAt' => $hasSavedToken ? $user->spacetraders_account_token_saved_at?->toIso8601String() : null,
            ],
            'prefill' => $agent ? [
                'symbol' => $agent->name,
                'faction' => in_array($agent->faction, $factions, true) ? $agent->faction : null,
                'email' => $agent->email,
                'fromName' => $agent->name,
            ] : null,
        ]);
    }

    public function forgetAccountToken(Request $request): RedirectResponse
    {
        $request->user()->forgetAccountToken();

        return redirect()->route('agents.create');
    }

    public function store(Request $request, SpaceTradersClient $client): RedirectResponse
    {
        if (PHP_SAPI !== 'cli') {
            // Leave headroom below Herd's 60-second FastCGI timeout; upstream work shares 45 seconds.
            set_time_limit(55);
        }
        if (is_string($request->input('faction'))) {
            $request->merge(['faction' => strtoupper($request->input('faction'))]);
        }
        $savedToken = $request->filled('account_token') ? null : $request->user()->savedAccountToken();
        $usingSavedToken = $savedToken !== null;
        $data = $request->validate([
            'account_token' => ['nullable', Rule::requiredIf(! $usingSavedToken), 'string', 'regex:/\A[A-Za-z0-9\-._~+\/]+=*\z/'],
            'remember_account_token' => ['sometimes', 'boolean'],
            'symbol' => ['required', 'string', 'min:3', 'max:14'],
            'faction' => ['required', 'string', Rule::in(config('foundation.enums.FactionSymbol'))],
            'email' => ['nullable', 'email', 'max:255'],
        ], ['account_token.required' => 'Enter your account token.']);
        $accountToken = $usingSavedToken ? $savedToken : $data['account_token'];

        $saved = $client->scope(function () use ($request, $client, $data, $accountToken, $usingSavedToken) {
            $reset = $this->reset($request);
            try {
                $payload = $client->register(array_filter([
                    'symbol' => strtoupper($data['symbol']),
                    'faction' => strtoupper($data['faction']),
                    'email' => $data['email'] ?? null,
                ], fn ($value) => $value !== null), $accountToken);
            } catch (ValidationException $e) {
                if ($usingSavedToken && in_array($e->errors()['api'][0] ?? null, [SpaceTradersClient::TOKEN_INVALID, SpaceTradersClient::TOKEN_FORBIDDEN], true)) {
                    $request->user()->forgetAccountToken();

                    throw ValidationException::withMessages(['api' => 'Your saved account token was rejected and has been forgotten. Paste a current account token from the SpaceTraders dashboard.']);
                }

                throw $e;
            }

            return $this->save($request, $payload['agent'], $payload['token'], $reset, $data['email'] ?? null);
        }, deadline: microtime(true) + 45);

        if (! $usingSavedToken && ($data['remember_account_token'] ?? false)) {
            $request->user()->rememberAccountToken($accountToken);
        }

        return $request->boolean('guided') ? redirect()->route('agents.show', ['agent' => $saved->id, 'guided' => 1]) : redirect()->route('agents.index');
    }

    public function storeExisting(Request $request, SpaceTradersClient $client): RedirectResponse
    {
        if (PHP_SAPI !== 'cli') {
            // Leave headroom below Herd's 60-second FastCGI timeout; upstream work shares 45 seconds.
            set_time_limit(55);
        }
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/\A[A-Za-z0-9\-._~+\/]+=*\z/'],
        ]);

        $saved = $client->scope(function () use ($request, $client, $data) {
            $reset = $this->reset($request);
            $agent = $client->agent($data['token']);

            return $this->save($request, $agent, $data['token'], $reset);
        }, deadline: microtime(true) + 45);

        return $request->boolean('guided') ? redirect()->route('agents.show', ['agent' => $saved->id, 'guided' => 1]) : redirect()->route('agents.index');
    }

    private function reset(Request $request): string
    {
        try {
            $candidates = $request->user()->agents()->unarchived()->whereNotNull('reset_id')->get();
            $reset = app(SpaceTradersApi::class)->status();
            app(AgentSynchronizer::class)->archivePreviousWorlds($candidates, $reset);

            return $reset;
        } catch (GameException $e) {
            throw ValidationException::withMessages(['api' => $e->getMessage()]);
        }
    }

    private function save(Request $request, array $payload, string $token, string $reset, ?string $email = null): Agent
    {
        try {
            return app(HostLock::class)->run('imports', function () use ($request, $payload, $token, $reset, $email) {
                $identity = AgentSynchronizer::identity($reset, $payload['symbol']);
                $existing = Agent::where('canonical_identity', $identity)->first();
                if (($existing && $existing->user_id !== $request->user()->id) || Agent::where('name', $payload['symbol'])->whereNull('reset_id')->where('user_id', '!=', $request->user()->id)->exists()) {
                    throw new GameException('This agent cannot be imported into this local account.');
                }
                $existing ??= Agent::where('name', $payload['symbol'])->where('user_id', $request->user()->id)->whereNull('reset_id')->orderBy('id')->first();
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

                    return $existing;
                } else {
                    return $request->user()->agents()->create($attributes);
                }
            });
        } catch (GameException $e) {
            throw ValidationException::withMessages(['api' => $e->getMessage()]);
        }
    }
}
