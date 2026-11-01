<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlayerGuideController extends Controller
{
    public function show(Request $request, ?Agent $agent = null): Response|RedirectResponse
    {
        abort_if($agent && $agent->user_id !== $request->user()->id, 404);
        if ($agent?->isArchived()) {
            return redirect()->to(route('agents.index').'#archived-agents');
        }

        return Inertia::render('Guide/Show', [
            'agent' => $agent?->only(['id', 'name', 'reset_id']),
            'agents' => $request->user()->agents()->unarchived()->orderBy('name')->get(['id', 'name', 'reset_id']),
        ]);
    }
}
