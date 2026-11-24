<?php

namespace App\Http\Requests;

use App\Gameplay\MiningPlanner;
use App\Models\GameObservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LocalAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->route('agent')->user_id === $this->user()->id, 404);
        abort_unless(config('gameplay.local_engine_enabled'), 403);

        return true;
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'game.investigations.create' => [
                'strategy' => ['required', Rule::in(['mining', 'contracts'])],
                'system' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9]+-[A-Z0-9]+$/'],
                'buyer' => ['missing_unless:strategy,mining', 'required_with:sites', 'nullable', 'string', 'max:80'],
                'sites' => ['missing_unless:strategy,mining', 'nullable', 'array', 'list', 'max:2'],
                'sites.*' => ['required', 'string', 'max:80', 'distinct:strict'],
            ],
            'game.drafts.create', 'game.drafts.update' => [
                'investigation_id' => ['required', 'integer', 'min:1'], 'candidate_id' => ['required', 'string', 'size:24', 'regex:/^[a-f0-9]+$/'],
                'revision' => [Rule::requiredIf($this->route()->getName() === 'game.drafts.update'), 'integer', 'min:1'],
                'limits' => ['required', 'array:reserve,max_spend,max_loss,minutes,cycles,delivery_limit,purchase_limit,max_extractions,extraction_headroom,site_seconds,survey_signature,wear_allowance'],
                'limits.wear_allowance' => ['sometimes', 'nullable', 'integer', 'between:0,1000000000'],
                'limits.reserve' => ['required', 'integer', 'between:1,1000000000'],
                'limits.max_spend' => ['required', 'integer', 'between:0,1000000000'],
                'limits.max_loss' => ['required', 'integer', 'between:0,1000000000'],
                'limits.minutes' => ['required', 'integer', 'between:1,60'], 'limits.cycles' => ['required', 'integer', 'between:1,10'],
                'limits.max_extractions' => ['sometimes', 'integer', 'between:1,10'],
                'limits.extraction_headroom' => [Rule::requiredIf((int) $this->input('limits.max_extractions', 1) > 1), 'integer', 'between:1,100000'],
                'limits.site_seconds' => [Rule::requiredIf((int) $this->input('limits.max_extractions', 1) > 1), 'integer', 'between:1,900'],
                'limits.survey_signature' => ['sometimes', 'nullable', 'string', 'max:255'],
                'limits.delivery_limit' => ['required', 'integer', 'between:1,10'], 'limits.purchase_limit' => ['required', 'integer', 'between:0,40'],
            ],
            'game.drafts.preview' => ['revision' => ['required', 'integer', 'min:1']],
            'game.accounting.review' => ['observed_balance' => ['required', 'integer', 'between:0,1000000000'], 'starting_balance' => ['nullable', 'integer', 'between:0,1000000000'], 'note' => ['required', 'string', 'min:10', 'max:500'], 'acknowledge' => ['required', 'accepted']],
            'game.engine.force-stop' => ['acknowledge' => ['required', 'accepted']],
            default => [],
        };
    }

    public function after(): array
    {
        if ($this->route()->getName() !== 'game.investigations.create') {
            return [];
        }

        return [function (Validator $validator) {
            $buyer = $this->input('buyer');
            $sites = $this->input('sites') ?? [];
            if ($validator->errors()->isNotEmpty() || $buyer === null) {
                return;
            }

            $agent = $this->route('agent');
            $system = $this->input('system');
            $points = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)
                ->where('kind', 'waypoint')->whereIn('symbol', [...$sites, $buyer])->get()
                ->mapWithKeys(fn ($point) => [$point->symbol => $point->data])
                ->filter(fn ($point) => ($point['systemSymbol'] ?? null) === $system);

            if (! in_array('MARKETPLACE', array_column($points[$buyer]['traits'] ?? [], 'symbol'), true)) {
                $validator->errors()->add('buyer', 'Choose an observed marketplace in the selected system.');
            }
            foreach ($sites as $index => $site) {
                if (! in_array($points[$site]['type'] ?? null, MiningPlanner::SITE_TYPES, true)) {
                    $validator->errors()->add('sites.'.$index, 'Choose an observed asteroid site in the selected system.');
                }
            }
        }];
    }
}
