<?php

namespace App\Http\Controllers;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\SpaceTradersApi;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Agent/Agents', [
            'agents' => $request->user()->agents()->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Agent/CreateAgent');
    }

    public function store(Request $request, SpaceTradersClient $client): RedirectResponse
    {
        $data = $request->validate([
            'account_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9\-._~+\/]+=*\z/'],
            'symbol' => ['required', 'string', 'min:3', 'max:14'],
            'faction' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $reset = $this->reset();
        $payload = $client->register(array_filter([
            'symbol' => strtoupper($data['symbol']),
            'faction' => strtoupper($data['faction']),
            'email' => $data['email'] ?? null,
        ], fn ($value) => $value !== null), $data['account_token']);

        $this->save($request, $payload['agent'], $payload['token'], $reset, $data['email'] ?? null);

        return redirect()->route('agents.index');
    }

    public function storeExisting(Request $request, SpaceTradersClient $client): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/\A[A-Za-z0-9\-._~+\/]+=*\z/'],
        ]);

        $reset = $this->reset();
        $agent = $client->agent($data['token']);

        $this->save($request, $agent, $data['token'], $reset);

        return redirect()->route('agents.index');
    }

    private function reset(): string
    {
        try {
            return app(SpaceTradersApi::class)->status();
        } catch (GameException $e) {
            throw ValidationException::withMessages(['api' => $e->getMessage()]);
        }
    }

    private function save(Request $request, array $payload, string $token, string $reset, ?string $email = null): void
    {
        try {
            app(HostLock::class)->run('imports', function () use ($request, $payload, $token, $reset, $email) {
                $identity = AgentSynchronizer::identity($reset, $payload['symbol']);
                $existing = Agent::where('canonical_identity', $identity)->first();
                if (($existing && $existing->user_id !== $request->user()->id) || Agent::where('name', $payload['symbol'])->whereNull('reset_id')->where('user_id', '!=', $request->user()->id)->exists()) {
                    throw new GameException('This agent cannot be imported into this local account.');
                }
                $existing ??= Agent::where('name', $payload['symbol'])->where('user_id', $request->user()->id)->whereNull('reset_id')->orderBy('id')->first();
                $attributes = [
                    'token' => $token, 'name' => $payload['symbol'], 'account_id' => $payload['accountId'],
                    'faction' => $payload['startingFaction'], 'email' => $email, 'reset_id' => $reset,
                    'canonical_identity' => $identity, 'connection_state' => 'unsynchronized',
                ];
                if ($existing) {
                    if (ShipAction::where('agent_id', $existing->id)->whereIn('state', ['pending', 'sending', 'uncertain'])->exists() || AutomationRun::where('agent_id', $existing->id)->whereNotNull('active_agent_id')->exists()) {
                        throw new GameException('Stop and reconcile this agent before replacing credentials.');
                    }
                    $existing->update($attributes);
                } else {
                    $request->user()->agents()->create($attributes);
                }
            });
        } catch (GameException $e) {
            throw ValidationException::withMessages(['api' => $e->getMessage()]);
        }
    }
}
