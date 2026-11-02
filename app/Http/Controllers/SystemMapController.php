<?php

namespace App\Http\Controllers;

use App\Maps\SystemMapKnowledge;
use App\Models\Agent;
use App\Models\Ship;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemMapController extends Controller
{
    public function __invoke(Request $request, Agent $agent, SystemMapKnowledge $knowledge): Response
    {
        abort_unless($agent->user_id === $request->user()->id, 404);
        $input = $request->validate(['ship' => ['sometimes', 'nullable', 'string', 'max:80'], 'location' => ['sometimes', 'nullable', 'string', 'max:80']]);
        $ships = Ship::where('agent_id', $agent->id)->orderBy('symbol')->get();
        $ship = empty($input['ship']) ? $ships->first() : $ships->firstWhere('symbol', $input['ship']);
        abort_if(! empty($input['ship']) && ! $ship, 404);

        return Inertia::render('Agent/SystemMap', [
            'agent' => $agent->only(['id', 'name', 'reset_id', 'connection_state']),
            'ships' => $ships->map(fn ($row) => ['symbol' => $row->symbol]),
            'map' => $knowledge->forShip($agent, $ship),
            'selectedLocation' => $input['location'] ?? null,
            'freshnessSeconds' => (int) config('gameplay.snapshot_seconds'),
        ]);
    }
}
