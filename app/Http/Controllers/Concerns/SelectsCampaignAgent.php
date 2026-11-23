<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait SelectsCampaignAgent
{
    private function authorizeOperator(Request $request): void
    {
        abort_unless((string) $request->user()->id === (string) config('gameplay.operator_id'), 404);
    }

    private function requestedCampaignSymbol(Request $request): ?string
    {
        $symbol = $request->validate(['agent' => 'nullable|string|max:32'])['agent'] ?? null;

        return $symbol ? strtoupper($symbol) : null;
    }
}
