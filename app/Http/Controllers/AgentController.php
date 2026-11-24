<?php

namespace App\Http\Controllers;

use App\Gameplay\AgentRegistration;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\GameException;
use App\Gameplay\SpaceTradersApi;
use App\Models\Agent;
use App\Services\SpaceTradersClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function index(Request $request): Response
    {
        $resetCheckError = null;
        $synchronizer = app(AgentSynchronizer::class);
        $synchronizer->retryArchivedDrains($request->user()->id);
        $candidates = $request->user()->agents()->unarchived()->whereNotNull('reset_id')->get();
        if ($candidates->isNotEmpty()) {
            try {
                $reset = app(SpaceTradersApi::class)->status();
                $synchronizer->archivePreviousWorlds($candidates, $reset);
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
        $from = $request->query('from');
        $fromId = is_string($from) ? filter_var($from, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        // Keep the "register again" context; create() re-checks ownership and archive state.
        return redirect()->route('agents.create', $fromId ? ['from' => $fromId] : []);
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
        $savedCiphertext = $usingSavedToken ? $request->user()->getRawOriginal('spacetraders_account_token') : null;
        $data = $request->validate([
            'account_token' => ['nullable', Rule::requiredIf(! $usingSavedToken), 'string', 'regex:/\A[A-Za-z0-9\-._~+\/]+=*\z/'],
            'remember_account_token' => ['sometimes', 'boolean'],
            'symbol' => ['required', 'string', 'min:3', 'max:14'],
            'faction' => ['required', 'string', Rule::in(config('foundation.enums.FactionSymbol'))],
            'email' => ['nullable', 'email', 'max:255'],
        ], ['account_token.required' => 'Enter your account token.']);
        $accountToken = $usingSavedToken ? $savedToken : $data['account_token'];

        $saved = $client->scope(function () use ($request, $data, $accountToken, $usingSavedToken, $savedCiphertext) {
            $reset = $this->reset($request);

            return app(AgentRegistration::class)->register($request->user(), $data, $accountToken, $reset, $usingSavedToken, $savedCiphertext);
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
        return app(AgentRegistration::class)->reset($request->user());
    }

    private function save(Request $request, array $payload, string $token, string $reset, ?string $email = null): Agent
    {
        return app(AgentRegistration::class)->save($request->user(), $payload, $token, $reset, $email);
    }
}
