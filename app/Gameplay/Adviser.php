<?php

namespace App\Gameplay;

use App\Models\AdviserDecision;
use App\Models\AdviserPolicy;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\OpportunityInvestigation;
use App\Models\Ship;
use App\Models\ShipAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Adviser
{
    public function __construct(private ShipCommandExecutor $executor, private AdviserSelector $selector, private OperationPlanner $operations, private OpportunityEvaluator $opportunities) {}

    public function grant(Agent $agent, array $input): AdviserPolicy
    {
        return $this->executor->locked($agent, function () use ($agent, $input) {
            $agent->refresh();
            $ship = Ship::where('agent_id', $agent->id)->where('symbol', $input['ship_symbol'])->first();
            if ($agent->isArchived() || ! $agent->reset_id || ! $agent->canonical_identity || ! $ship || ($ship->snapshot['nav']['systemSymbol'] ?? null) !== $input['system_symbol']) {
                throw new GameException('Choose one owned observed ship and its current system in an active world.');
            }
            $latest = AdviserPolicy::where('agent_id', $agent->id)->latest('version')->first();
            if ($latest?->id !== ($input['previous_id'] === null ? null : (int) $input['previous_id'])) {
                throw new GameException('Policy version changed. Reload before granting a replacement.');
            }
            $terms = $input['terms'];
            foreach (['reserve', 'max_spend', 'max_loss', 'max_seconds', 'max_fuel', 'refuel_units', 'api_request_cap'] as $key) {
                $terms[$key] = (int) $terms[$key];
            }
            foreach (['max_cost', 'max_fuel', 'max_seconds', 'interval_seconds'] as $key) {
                $terms['exploration'][$key] = (int) $terms['exploration'][$key];
            }
            $terms['allow_trial'] = (bool) $terms['allow_trial'];
            $terms['exploration']['enabled'] = (bool) $terms['exploration']['enabled'];
            sort($terms['activities']);
            foreach ($terms['cargo'] as &$cargo) {
                $cargo['units'] = (int) $cargo['units'];
            }
            unset($cargo);
            usort($terms['cargo'], fn ($a, $b) => $a['symbol'] <=> $b['symbol']);
            $grant = ['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'token_version' => $agent->token_version,
                'version' => ($latest?->version ?? 0) + 1, 'schema_version' => 1, 'ship_symbol' => $ship->symbol,
                'system_symbol' => $input['system_symbol'], 'terms' => $terms, 'expires_at' => CarbonImmutable::parse($input['expires_at'])->utc()->toIso8601String()];

            return DB::transaction(function () use ($latest, $grant, $agent) {
                $latest?->update(['revoked_at' => now()]);

                return AdviserPolicy::create($grant + ['grant_hash' => AdviserSelector::hash($grant), 'credential_hash' => hash('sha256', $agent->getRawOriginal('token'))]);
            });
        });
    }

    public function pause(AdviserPolicy $policy): AdviserPolicy
    {
        // Monotonic and independent of the host lock, like Stop. A late assessment cannot restore authority.
        AdviserPolicy::whereKey($policy->id)->whereNull('paused_at')->update(['paused_at' => now()]);

        return $policy->fresh();
    }

    public function requireAvailable(Agent $agent, AdviserPolicy $policy): void
    {
        $agent->refresh();
        $policy->refresh();
        if (! $policy->available($agent)) {
            throw new GameException('Policy paused, replaced, expired or from another world. Review and grant a new version.');
        }
    }

    public function assess(Agent $agent, AdviserPolicy $policy, ?OpportunityInvestigation $investigation): AdviserDecision
    {
        return $this->executor->locked($agent, function () use ($agent, $policy, $investigation) {
            $this->requireAvailable($agent, $policy);
            if ($investigation && ($investigation->reset_id !== $agent->reset_id || $investigation->system_symbol !== $policy->system_symbol)) {
                throw new GameException('Investigation must match this policy world and system.');
            }
            $now = CarbonImmutable::now()->utc();
            $owned = Ship::where('agent_id', $agent->id)->where('symbol', $policy->ship_symbol)->first();
            $observations = GameObservation::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->orderBy('kind')->orderBy('symbol')->get();
            $possibleYields = [];
            foreach ($owned?->snapshot['mounts'] ?? [] as $mount) {
                if (str_starts_with($mount['symbol'], 'MOUNT_MINING_LASER_')) {
                    $possibleYields = array_merge($possibleYields, ! empty($mount['deposits']) ? $mount['deposits'] : json_decode(file_get_contents(resource_path('data/mining-yields.json')), true));
                }
            }
            $evidence = ['as_of' => $now->toIso8601String(), 'reset_id' => $agent->reset_id, 'ship' => $owned?->snapshot,
                'ship_observed_at' => $owned?->observed_at?->toIso8601String(), 'credits' => $agent->credits, 'balance_observed_at' => $agent->synced_at?->toIso8601String(),
                'accounting' => app(CreditAccounting::class)->summary($agent),
                'observations' => $observations->map(fn ($o) => ['id' => $o->id, 'kind' => $o->kind, 'symbol' => $o->symbol, 'data' => $o->data, 'observed_at' => $o->observed_at->toIso8601String()])->all(),
                'investigation' => $investigation ? ['id' => $investigation->id, 'status' => $investigation->status, 'coverage' => $investigation->coverage, 'observations' => $investigation->observations] : null,
                'unsettled' => ShipAction::where('agent_id', $agent->id)->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))->orderBy('id')->get(['id', 'ship_symbol', 'state', 'review_required'])->toArray(),
                'active_run' => AutomationRun::where('agent_id', $agent->id)->whereNotNull('active_agent_id')->first(['id', 'ship_symbol', 'strategy', 'state', 'stop_requested_at'])?->toArray(),
                'last_exploration_at' => AutomationRun::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('strategy', 'scout')->max('started_at'),
                'parameters' => ['snapshot_seconds' => config('gameplay.snapshot_seconds'), 'scheduling_allowance_seconds' => config('gameplay.scheduling_allowance_seconds')],
                'possible_yields' => array_values(array_unique($possibleYields)),
                'scout_plans' => []];
            if ($owned && isset($owned->snapshot['nav']['flightMode'], $owned->snapshot['nav']['waypointSymbol'], $owned->snapshot['nav']['systemSymbol'], $owned->snapshot['fuel']['current'], $owned->snapshot['fuel']['capacity'], $owned->snapshot['cargo']['inventory'])) {
                foreach ($observations->where('kind', 'market_listing')->take(24) as $listing) {
                    if ($listing->symbol === $owned->snapshot['nav']['waypointSymbol'] || ($listing->data['symbol'] ?? $listing->symbol) !== $listing->symbol) {
                        continue;
                    }
                    try {
                        $plan = $this->operations->preview($agent, $owned->snapshot, ['strategy' => 'scout', 'destination' => $listing->symbol,
                            'reserve' => $policy->terms['reserve'], 'max_spend' => $policy->terms['max_spend'], 'max_loss' => $policy->terms['max_loss'], 'max_fuel' => $policy->terms['max_fuel']]);
                        $evidence['scout_plans'][$listing->symbol] = ['plan' => $plan];
                    } catch (GameException $e) {
                        $evidence['scout_plans'][$listing->symbol] = ['reason' => $e->getMessage()];
                    }
                }
            }
            $grant = $policy->only(['id', 'version', 'schema_version', 'grant_hash', 'ship_symbol', 'system_symbol', 'terms']);
            $evidence['policy'] = $grant;
            $evidence['mining_candidates'] = $this->miningCandidates($evidence);
            $result = $this->selector->evaluate($evidence);
            // Also cap by the oldest critical observation or feasible quote. A new assessment cannot renew stale evidence.
            $deadlines = [$policy->expires_at, $now->addSeconds(120)];
            foreach (['ship_observed_at', 'balance_observed_at'] as $key) {
                if ($evidence[$key]) {
                    $deadlines[] = CarbonImmutable::parse($evidence[$key])->addSeconds(config('gameplay.snapshot_seconds'));
                }
            }

            foreach ($result['candidates'] as $candidate) {
                if ($candidate['classification'] !== 'feasible') {
                    continue;
                }
                foreach ([$candidate['quote_at'] ?? null, $candidate['source']['observed_at'] ?? null,
                    $candidate['source']['route']['quote_at'] ?? null, $candidate['plan']['route']['outbound']['fuel_price_at'] ?? null,
                    $candidate['plan']['route']['return']['fuel_price_at'] ?? null] as $at) {
                    if ($at) {
                        $deadlines[] = CarbonImmutable::parse($at)->addSeconds(config('gameplay.snapshot_seconds'));
                    }
                }
            }

            return AdviserDecision::create(['agent_id' => $agent->id, 'adviser_policy_id' => $policy->id, 'reset_id' => $agent->reset_id, 'selector_version' => AdviserSelector::VERSION,
                'evidence' => $evidence, 'evidence_hash' => AdviserSelector::hash($evidence), 'candidates' => $result['candidates'], 'selection' => $result['selection'], 'expires_at' => min($deadlines)]);
        });
    }

    private function miningCandidates(array $evidence): ?array
    {
        $investigation = $evidence['investigation'];
        $ship = $evidence['ship'];
        if (! $investigation || ! $ship) {
            return null;
        }
        $input = $investigation['observations'];
        $input['ships'] = array_values(array_filter($input['ships'] ?? [], fn ($s) => $s['symbol'] === $evidence['policy']['ship_symbol']));
        foreach (['waypoints', 'markets'] as $key) {
            $input[$key] ??= [];
            ksort($input[$key]);
        }
        $sameShip = isset($input['ships'][0]) && AdviserSelector::hash($input['ships'][0]) === AdviserSelector::hash($ship);
        $complete = $investigation['status'] === 'complete' && $sameShip && ($input['credits'] ?? null) === $evidence['credits'];
        foreach ($evidence['observations'] as $market) {
            if ($market['kind'] === 'market' && isset($input['markets'][$market['symbol']]) && ! empty($market['data']['invalidated_by_action'])) {
                $input['markets'][$market['symbol']] = $market['data'];
            }
        }

        return $this->opportunities->evaluate($input, 'mining', CarbonImmutable::parse($evidence['as_of']), $complete);
    }

    public function history(Agent $agent, ?int $beforeDecision = null, ?int $beforePolicy = null): array
    {
        $policies = AdviserPolicy::where('agent_id', $agent->id)->when($beforePolicy, fn ($q) => $q->where('id', '<', $beforePolicy))->latest('id')->limit(11)->get();
        $decisions = AdviserDecision::where('agent_id', $agent->id)->when($beforeDecision, fn ($q) => $q->where('id', '<', $beforeDecision))->latest('id')->limit(11)->get();

        return ['execution_enabled' => false, 'model_enabled' => false, 'policies' => $policies->take(10)->map(fn ($policy) => $policy->toArray() + ['available_for_assessment' => $policy->available($agent)])->values(),
            'decisions' => $decisions->take(10)->map(function ($decision) use ($agent) {
                $policy = AdviserPolicy::find($decision->adviser_policy_id);
                $data = $decision->toArray();
                $data['status'] = ! $policy->available($agent) ? 'policy_invalidated' : ($decision->expires_at->isPast() ? 'expired' : 'review_only');

                return $data;
            })->values(), 'more_policies' => $policies->count() > 10, 'more_decisions' => $decisions->count() > 10];
    }
}
