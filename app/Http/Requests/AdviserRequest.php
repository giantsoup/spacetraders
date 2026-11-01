<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdviserRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->route('agent')->user_id === $this->user()->id, 404);

        return true;
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'game.adviser.policies' => [
                'previous_id' => ['present', 'nullable', 'integer', 'min:1'],
                'ship_symbol' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9_-]+$/'],
                'system_symbol' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9]+-[A-Z0-9]+$/'],
                'expires_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'after:now', 'before_or_equal:'.now()->addDay()->format('Y-m-d\TH:i:s\Z')],
                'terms' => ['required', 'array:activities,cargo,reserve,max_spend,max_loss,max_seconds,max_fuel,refuel_units,allow_trial,api_request_cap,exploration'],
                'terms.activities' => ['required', 'array', 'min:1', 'max:7'],
                'terms.activities.*' => ['required', 'distinct', Rule::in(['wait', 'read', 'sale', 'refuel', 'scout', 'mining', 'contracts'])],
                'terms.cargo' => ['present', 'array', 'max:40'],
                'terms.cargo.*' => ['array:symbol,units'],
                'terms.cargo.*.symbol' => ['required', 'distinct', 'string', 'max:80', 'regex:/^[A-Z0-9_]+$/'],
                'terms.cargo.*.units' => ['required', 'integer', 'between:1,100000'],
                'terms.reserve' => ['required', 'integer', 'between:1,1000000000'],
                'terms.max_spend' => ['required', 'integer', 'between:0,1000000000'],
                'terms.max_loss' => ['required', 'integer', 'between:0,1000000000'],
                'terms.max_seconds' => ['required', 'integer', 'between:60,3600'],
                'terms.max_fuel' => ['required', 'integer', 'between:0,100000'],
                'terms.refuel_units' => ['required', 'integer', 'between:0,100000'],
                'terms.allow_trial' => ['required', 'boolean'],
                'terms.api_request_cap' => ['required', 'integer', 'between:1,40'],
                'terms.exploration' => ['required', 'array:enabled,max_cost,max_fuel,max_seconds,interval_seconds'],
                'terms.exploration.enabled' => ['required', 'boolean'],
                'terms.exploration.max_cost' => ['required', 'integer', 'between:0,1000000000', 'lte:terms.max_spend', 'lte:terms.max_loss'],
                'terms.exploration.max_fuel' => ['required', 'integer', 'between:0,100000', 'lte:terms.max_fuel'],
                'terms.exploration.max_seconds' => ['required', 'integer', 'between:0,3600', 'lte:terms.max_seconds'],
                'terms.exploration.interval_seconds' => ['required', 'integer', 'between:0,604800', Rule::when($this->boolean('terms.exploration.enabled'), ['min:60'])],
                'acknowledge' => ['required', 'accepted'],
            ],
            'game.adviser.assess' => ['policy_id' => ['required', 'integer', 'min:1'], 'investigation_id' => ['nullable', 'integer', 'min:1']],
            'game.adviser.collect' => ['policy_id' => ['required', 'integer', 'min:1']],
            default => [],
        };
    }
}
