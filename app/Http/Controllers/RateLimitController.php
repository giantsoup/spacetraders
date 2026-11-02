<?php

namespace App\Http\Controllers;

use App\Gameplay\GameException;
use App\Gameplay\RequestBudget;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RateLimitController extends Controller
{
    public function __invoke(Request $request, RequestBudget $budget): JsonResponse
    {
        $input = $request->validate(['agent' => ['nullable', 'integer', 'min:1']]);
        $agent = isset($input['agent']) ? Agent::where('user_id', $request->user()->id)->findOrFail($input['agent']) : null;

        try {
            $status = $budget->status($agent?->credential(), $agent?->account_id);
        } catch (GameException) {
            return response()->json(['message' => 'Rate-limit status is unavailable. Try again shortly.'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json($status)->header('Cache-Control', 'no-store');
    }
}
