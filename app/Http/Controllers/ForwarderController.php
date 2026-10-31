<?php

namespace App\Http\Controllers;

use App\Gameplay\GameException;
use App\Services\SpaceTradersClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ForwarderController extends Controller
{
    public function forward(Request $request, SpaceTradersClient $client): Response
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['get'])],
            'url' => ['required', Rule::in(['/', '/factions'])],
            'data' => ['sometimes', 'array:page,limit'],
            'data.page' => ['sometimes', 'integer', 'between:1,100'],
            'data.limit' => ['sometimes', 'integer', 'between:1,20'],
            'headers' => ['prohibited'],
        ]);
        try {
            $upstream = $client->request('get', $data['url'], $data['data'] ?? []);
        } catch (ConnectionException|GameException) {
            return response(['error' => ['message' => 'SpaceTraders public read unavailable.']], 503, ['Cache-Control' => 'no-store']);
        }

        return response($upstream->body(), $upstream->status(), ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
