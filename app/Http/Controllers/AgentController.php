<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
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
        return Inertia::render('Agents', ['agents' => $user->agents]);
    }

    public function create(): Response
    {
        return Inertia::render('Agent/CreateAgent');
    }

    public function store(Request $request)
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

        [$keys, $values] = Arr::divide($response->json('error.data'));
        $errors = [
            'header' => $keys[0],
            'message' => $values[0][0],
        ];

        // if the request was not successful, return the errors
        return Inertia::render('Agent/CreateAgent', ['errors' => $errors]);
    }
}
