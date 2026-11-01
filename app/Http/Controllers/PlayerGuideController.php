<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlayerGuideController extends Controller
{
    public function show(Request $request, ?Agent $agent = null): Response
    {
        abort_if($agent && $agent->user_id !== $request->user()->id, 404);

        return Inertia::render('Guide/Show', [
            'agent' => $agent?->only(['id', 'name', 'reset_id']),
            'agents' => $request->user()->agents()->orderBy('name')->get(['id', 'name', 'reset_id']),
        ]);
    }
}
