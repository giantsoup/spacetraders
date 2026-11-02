<?php

namespace App\Http\Requests;

use App\Gameplay\ManualCommands;
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
        if ($name === 'game.economics.investment') {
            return [
                'reference_run' => ['required', 'integer', 'min:1'],
                'basis_note' => ['required', 'string', 'min:10', 'max:500'],
                'costs_complete' => ['required', 'accepted'],
                'base_net' => ['nullable', 'integer', 'between:-1000000000,1000000000'],
                'conservative_net' => ['nullable', 'integer', 'between:-1000000000,1000000000', 'lte:base_net'],
                'elapsed_seconds' => ['required', 'integer', 'between:1,31536000'],
                'ship_seconds' => ['nullable', 'integer', 'gte:elapsed_seconds', 'max:3153600000'],
                'ship_capital' => ['nullable', 'integer', 'between:0,1000000000'],
                'equipment_capital' => ['nullable', 'integer', 'between:0,1000000000'],
                'production_units' => ['nullable', 'integer', 'between:0,1000000000'],
                'round_trip_seconds' => ['nullable', 'integer', 'between:1,31536000'],
                'hauler_capacity' => ['nullable', 'integer', 'between:0,1000000000'],
                'buyer_capacity' => ['nullable', 'integer', 'between:0,1000000000'],
            ];
        }
        if ($name === 'game.commands.preview') {
            return ManualCommands::rules((string) $this->input('command'));
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
                'wear_allowance' => ['sometimes', 'nullable', 'integer', 'between:0,1000000000'],
            ];
        }
        if ($name === 'game.runs.start') {
            return ['accept_contract' => ['sometimes', 'boolean']];
        }
        if ($name === 'game.commands.acknowledge') {
            return ['acknowledge' => ['accepted'], 'note' => ['required', 'string', 'min:10', 'max:500']];
        }

        return [];
    }
}
