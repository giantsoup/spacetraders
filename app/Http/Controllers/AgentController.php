<?php

namespace App\Http\Controllers;

use App\Services\SpaceTradersClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Agent/Agents', [
            'agents' => $request->user()->agents->map(function ($agent) {
                return array_merge($agent->toArray(), [
                    'token_preview' => substr($agent->token, 0, 5).' ... '.substr($agent->token, -5),
                ]);
            }),
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

        $payload = $client->register(array_filter([
            'symbol' => strtoupper($data['symbol']),
            'faction' => strtoupper($data['faction']),
            'email' => $data['email'] ?? null,
        ], fn ($value) => $value !== null), $data['account_token']);

        $request->user()->agents()->create([
            'token' => $payload['token'],
            'name' => $payload['agent']['symbol'],
            'account_id' => $payload['agent']['accountId'],
            'faction' => $payload['agent']['startingFaction'],
            'email' => $data['email'] ?? null,
        ]);

        return redirect()->route('agents.index');
    }

    public function storeExisting(Request $request, SpaceTradersClient $client): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/\A[A-Za-z0-9\-._~+\/]+=*\z/'],
        ]);

        $agent = $client->agent($data['token']);

        $request->user()->agents()->create([
            'token' => $data['token'],
            'name' => $agent['symbol'],
            'account_id' => $agent['accountId'],
            'faction' => $agent['startingFaction'],
        ]);

        return redirect()->route('agents.index');
    }
}
