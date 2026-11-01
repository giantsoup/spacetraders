<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'game.investigations.create' => ['strategy' => ['required', Rule::in(['mining', 'contracts'])], 'system' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9]+-[A-Z0-9]+$/']],
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
}
