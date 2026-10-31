<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GameplayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('agent')->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        $name = $this->route()->getName();
        if ($name === 'game.commands.preview') {
            $command = $this->input('command');

            return [
                'key' => ['required', 'uuid'], 'ship_symbol' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9_-]+$/'],
                'command' => ['required', Rule::in(['navigate', 'orbit', 'dock', 'refuel', 'mine', 'buy', 'sell', 'buy_ship'])],
                'arguments' => ['present', 'array:units,symbol,waypointSymbol,shipType,maxTotal,minTotal,reserve,site,market,require_guaranteed_price'],
                'arguments.units' => [Rule::requiredIf(in_array($command, ['buy', 'sell', 'refuel'], true)), 'integer', 'between:1,100000'],
                'arguments.symbol' => [Rule::requiredIf(in_array($command, ['buy', 'sell'], true)), 'string', 'regex:/^[A-Z0-9_]+$/', 'max:80'],
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
            return [
                'ship_symbol' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9_-]+$/'],
                'site' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9-]+$/'],
                'market' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9-]+$/'],
                'reserve' => ['required', 'integer', 'between:1,1000000000'],
                'max_spend' => ['required', 'integer', 'between:1,1000000000'],
                'max_loss' => ['required', 'integer', 'between:0,1000000000'],
                'minutes' => ['required', 'integer', 'between:1,60'], 'cycles' => ['required', 'integer', 'between:1,10'],
            ];
        }
        if ($name === 'game.commands.acknowledge') {
            return ['acknowledge' => ['accepted'], 'note' => ['required', 'string', 'min:10', 'max:500']];
        }

        return [];
    }
}
