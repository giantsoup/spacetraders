<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GameplayRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->route('agent')->user_id === $this->user()->id, 404);

        return true;
    }

    public function messages(): array
    {
        return $this->input('command') === 'navigate'
            ? ['arguments.waypointSymbol.required' => 'Select a destination before previewing navigation.']
            : [];
    }

    public function rules(): array
    {
        $name = $this->route()->getName();
        if ($name === 'game.commands.preview') {
            $command = $this->input('command');

            return [
                'key' => ['required', 'uuid'], 'ship_symbol' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9_-]+$/'],
                'command' => ['required', Rule::in(['navigate', 'orbit', 'dock', 'refuel', 'mine', 'mine_survey', 'survey', 'transfer', 'buy', 'sell', 'buy_ship'])],
                'arguments' => ['present', 'array:units,symbol,waypointSymbol,shipType,maxTotal,minTotal,reserve,site,market,require_guaranteed_price,targetShip,surveySignature,surveyWaypoint'],
                'arguments.units' => [Rule::requiredIf(in_array($command, ['buy', 'sell', 'refuel', 'transfer'], true)), 'integer', 'between:1,100000'],
                'arguments.symbol' => [Rule::requiredIf(in_array($command, ['buy', 'sell', 'transfer'], true)), 'string', 'regex:/^[A-Z0-9_]+$/', 'max:80'],
                'arguments.targetShip' => [Rule::requiredIf($command === 'transfer'), 'string', 'regex:/^[A-Z0-9_-]+$/', 'max:80'],
                'arguments.surveySignature' => [Rule::requiredIf($command === 'mine_survey'), 'string', 'max:255'],
                'arguments.surveyWaypoint' => [Rule::requiredIf($command === 'mine_survey'), 'string', 'regex:/^[A-Z0-9-]+$/', 'max:80'],
                'arguments.waypointSymbol' => [Rule::requiredIf(in_array($command, ['navigate', 'buy_ship'], true)), 'string', 'regex:/^[A-Z0-9-]+$/', 'max:80'],
                'arguments.shipType' => [Rule::requiredIf($command === 'buy_ship'), 'string', 'regex:/^SHIP_[A-Z0-9_]+$/', 'max:80'],
                'arguments.maxTotal' => [Rule::requiredIf(in_array($command, ['buy', 'buy_ship', 'refuel'], true)), 'integer', 'between:0,1000000000'],
                'arguments.minTotal' => [Rule::requiredIf($command === 'sell'), 'integer', 'between:0,1000000000'],
                'arguments.reserve' => ['sometimes', 'integer', 'between:0,1000000000'],
                'arguments.site' => [Rule::requiredIf($command === 'buy_ship'), 'string', 'max:80', 'regex:/^[A-Z0-9-]+$/'],
                'arguments.market' => [Rule::requiredIf($command === 'buy_ship'), 'string', 'max:80', 'regex:/^[A-Z0-9-]+$/'],
                'arguments.require_guaranteed_price' => ['sometimes', 'boolean'],
            ];
        }
        if ($name === 'game.runs.preview') {
            $strategy = $this->input('strategy', 'mining');

            return [
                'strategy' => ['sometimes', Rule::in(['mining', 'scout', 'trade'])],
                'ship_symbol' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9_-]+$/'],
                'site' => [Rule::requiredIf($strategy === 'mining'), 'string', 'max:80', 'regex:/^[A-Z0-9-]+$/'],
                'market' => [Rule::requiredIf($strategy !== 'scout'), 'string', 'max:80', 'regex:/^[A-Z0-9-]+$/'],
                'destination' => [Rule::requiredIf($strategy === 'scout'), 'string', 'max:80', 'regex:/^[A-Z0-9-]+$/'],
                'reserve' => ['required', 'integer', 'between:1,1000000000'],
                'max_spend' => ['required', 'integer', 'between:1,1000000000'],
                'max_loss' => ['required', 'integer', 'between:0,1000000000'],
                'minutes' => ['required', 'integer', 'between:1,60'], 'cycles' => [Rule::requiredIf($strategy === 'mining'), 'integer', 'between:1,10'],
                'max_fuel' => [Rule::requiredIf($strategy !== 'mining'), 'integer', 'between:1,100000'],
                'max_visits' => [Rule::requiredIf($strategy === 'scout'), 'integer', 'in:1'],
                'symbol' => [Rule::requiredIf($strategy === 'trade'), 'string', 'max:80', 'regex:/^[A-Z0-9_]+$/'],
                'units' => [Rule::requiredIf($strategy === 'trade'), 'integer', 'between:1,100000'],
                'max_inventory' => [Rule::requiredIf($strategy === 'trade'), 'integer', 'between:1,1000000000'],
                'min_margin' => [Rule::requiredIf($strategy === 'trade'), 'integer', 'between:1,1000000000'],
                'min_sell_price' => [Rule::requiredIf($strategy === 'trade'), 'integer', 'between:1,1000000000'],
                'max_extractions' => ['sometimes', 'integer', 'between:1,10'],
                'extraction_headroom' => [Rule::requiredIf((int) $this->input('max_extractions', 1) > 1), 'integer', 'between:1,100000'],
                'site_seconds' => [Rule::requiredIf((int) $this->input('max_extractions', 1) > 1), 'integer', 'between:1,900'],
                'survey_signature' => ['sometimes', 'nullable', 'string', 'max:255'],
            ];
        }
        if ($name === 'game.commands.acknowledge') {
            return ['acknowledge' => ['accepted'], 'note' => ['required', 'string', 'min:10', 'max:500']];
        }

        return [];
    }
}
