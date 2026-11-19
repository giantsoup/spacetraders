<?php

namespace App\Gameplay;

use App\Models\CampaignEvent;
use App\Models\ObservationHistory;
use App\Models\ReconMission;

class ReconReport
{
    public function build(ReconMission $mission): string
    {
        $status = app(GateRecon::class)->status($mission);
        $episode = app(GateRecon::class)->episode($mission);
        $decisions = $episode ? CampaignEvent::where('campaign_episode_id', $episode->id)->where('kind', 'decision')->where('created_at', '>=', $mission->approved_at)->get() : collect();
        $jumps = $mission->actions()->where('command', 'jump')->where('state', 'confirmed')->orderBy('id')->get();
        $abroadSince = null;
        $abroadSeconds = 0;
        foreach ($jumps as $jump) {
            $target = implode('-', array_slice(explode('-', $jump->arguments['waypointSymbol']), 0, 2));
            if ($target !== $mission->origin_system && $abroadSince === null) {
                $abroadSince = $jump->settled_at;
            } elseif ($target === $mission->origin_system && $abroadSince !== null) {
                $abroadSeconds += $abroadSince->diffInSeconds($jump->settled_at);
                $abroadSince = null;
            }
        }
        if ($abroadSince) {
            $abroadSeconds += $abroadSince->diffInSeconds(now());
        }
        $status['time_abroad_seconds'] = $abroadSeconds;
        $status['gate_asks_seen'] = ObservationHistory::where('agent_id', $mission->agent_id)->where('reset_id', $mission->reset_id)
            ->where('kind', 'market')->where('observer_ship', $mission->ship_symbol)->where('observed_at', '>=', $mission->approved_at)
            ->whereIn('symbol', array_column($mission->graph, 'gate'))->get()->map(fn ($quote) => ['gate' => $quote->symbol,
                'observed_at' => $quote->observed_at->toIso8601String(), 'age_seconds' => $quote->observed_at->diffInSeconds(now()),
                'ask' => collect($quote->data['tradeGoods'] ?? [])->firstWhere('symbol', 'ANTIMATTER')['purchasePrice'] ?? null])->all();
        $lines = ['# Recon mission '.$mission->id, '', 'Provisional. Prices age. Trader jump eligibility and relocation cost unknown. C0 relocation not authorized.', '',
            'Marketplace count describes sampling opportunity, not quality.', '',
            'Acknowledging a known overcharge stops ALL active agent runs. C0 requires a fresh preview or cargo recovery afterward.', '',
            '## Mission ledger', '', '```json', json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), '```', '',
            'C0 decision count during the loan: '.$decisions->count(),
            'C0 latest best_available_score: '.json_encode($decisions->last()?->data['best_available_score'] ?? $decisions->last()?->data['candidates'][0]['best_available_score'] ?? null), ''];
        foreach ($mission->visits as $system => $visit) {
            $lines[] = '## '.$system;
            $lines[] = '';
            $lines[] = 'Provisional. Prices age. Trader jump eligibility and relocation cost unknown. C0 relocation not authorized.';
            $lines[] = '';
            $detail = $visit;
            $pairs = $detail['evaluation']['pairs'] ?? [];
            usort($pairs, fn ($a, $b) => ($b['scaled']['candidate']['score'] ?? 0) <=> ($a['scaled']['candidate']['score'] ?? 0));
            $detail['evaluation']['pairs'] = array_slice($pairs, 0, 5);
            $lines[] = '```json';
            $lines[] = json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $lines[] = '```';
            $lines[] = '';
        }
        $lines[] = '## Jump receipts and cooldowns';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = json_encode($mission->actions()->where('command', 'jump')->orderBy('id')->get()->map(fn ($action) => ['id' => $action->id,
            'run' => $action->automation_run_id, 'state' => $action->state->value, 'arguments' => $action->arguments, 'settled_at' => $action->settled_at,
            'debit' => $action->outcome['transaction_total'] ?? null, 'cooldown' => $action->outcome['response']['cooldown'] ?? null,
            'review_required' => $action->review_required, 'api_code' => $action->api_code])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $lines[] = '```';

        return implode("\n", $lines)."\n";
    }
}
