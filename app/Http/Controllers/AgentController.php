<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;


class AgentController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        return Inertia::render('Agent/Agents', ['agents' => $user->agents]);
    }

    public function create(): Response
    {
        return Inertia::render('Agent/CreateAgent');
    }

    public function store(Request $request): RedirectResponse|Response
    {
        $request_all = $request->all();
        $method = $request_all['method'];
        $url = $request_all['url'];
        $data = $request_all['data'];

        // forward the request to the Space Traders API
        $response = Http::$method('https://api.spacetraders.io/v2'.$url, $data);

        // if the request was successful, create the agent
        if ($response->successful()) {
            $user = Auth::user();
            $user->agents()->create([
                'token' => $response->json('data.token'),
                'name' => $response->json('data.agent.symbol'),
                'account_id' => $response->json('data.agent.accountId'),
                'faction' => $response->json('data.faction.symbol'),
                'email' => $data['email'],
            ]);

            return redirect()->route('agents.index');
        }

        // get error and split into header and message so that it's easily digestible on the frontend
        [$keys, $values] = Arr::divide($response->json('error.data'));
        $errors = [
            'header' => $keys[0],
            'message' => $values[0][0],
        ];

        // if the request was not successful, return the errors
        return Inertia::render('Agent/CreateAgent', ['errors' => $errors]);
    }

    public function storeExisting(Request $request): RedirectResponse|Response
    {
        $request_all = $request->all();
        $method = $request_all['method'];
        $url = $request_all['url'];
        $data = $request_all['data'];

        // forward the request to the Space Traders API
        $response = Http::withHeaders(['Authorization' => 'Bearer '.$data['token']])->$method('https://api.spacetraders.io/v2'.$url);

        // if the request was successful, create the agent
        if ($response->successful()) {
            // TODO: remove this extra step when the Space Traders API is updated to return the faction in the response
            // if agent exists, go fetch their faction as well
            $faction_response = Http::get('https://api.spacetraders.io/v2/factions');
            if ($faction_response->successful()) {
                // get the faction that matches the agent's headquarters
                $agent_faction = Arr::collapse(Arr::where($faction_response->json('data'), function ($faction) use ($response) {
                    return $faction['headquarters'] == $response->json('data.headquarters');
                }));
            } else {
                return Inertia::render('Agent/CreateAgent', ['errors' => [
                    'header' => 'Error',
                    'message' => 'Could not fetch faction data from Space Traders API'
                ]]);
            }

            $user = Auth::user();
            $user->agents()->create([
                'token' => $data['token'],
                'name' => $response->json('data.symbol'),
                'account_id' => $response->json('data.accountId'),
                'faction' => $agent_faction['symbol'],
            ]);

            return redirect()->route('agents.index');
        }

        // get error and split into header and message so that it's easily digestible on the frontend
        [$keys, $values] = Arr::divide($response->json('error.data'));
        $errors = [
            'header' => $keys[0],
            'message' => $values[0][0],
        ];

        // if the request was not successful, return the errors
        return Inertia::render('Agent/CreateAgent', ['errors' => $errors]);
    }
}
