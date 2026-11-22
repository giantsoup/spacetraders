<?php

namespace App\Gameplay;

use App\Maps\SystemMapKnowledge;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\CampaignSample;
use App\Models\LocalEngine;
use App\Models\Ship;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CampaignStatus
{
    private const REQUIREMENTS = ['power' => 'integer', 'crew' => 'integer', 'slots' => 'integer'];

    private const COMPONENT = [
        'symbol' => 'string', 'name' => 'string', 'description' => 'string',
        'condition' => 'number', 'integrity' => 'number', 'quality' => 'number',
        'requirements' => self::REQUIREMENTS,
    ];

    private const ROUTE_WAYPOINT = [
        'symbol' => 'string', 'type' => 'string', 'systemSymbol' => 'string', 'x' => 'integer', 'y' => 'integer',
    ];

    public const SHIP_DETAILS = [
        'symbol' => 'string',
        'registration' => ['name' => 'string', 'factionSymbol' => 'string', 'role' => 'string'],
        'nav' => [
            'systemSymbol' => 'string', 'waypointSymbol' => 'string', 'status' => 'string', 'flightMode' => 'string',
            'route' => [
                'origin' => self::ROUTE_WAYPOINT, 'destination' => self::ROUTE_WAYPOINT,
                'departureTime' => 'string', 'arrival' => 'string',
            ],
        ],
        'crew' => [
            'current' => 'integer', 'required' => 'integer', 'capacity' => 'integer',
            'rotation' => 'string', 'morale' => 'integer', 'wages' => 'integer',
        ],
        'frame' => self::COMPONENT + ['moduleSlots' => 'integer', 'mountingPoints' => 'integer', 'fuelCapacity' => 'integer'],
        'reactor' => self::COMPONENT + ['powerOutput' => 'integer'],
        'engine' => self::COMPONENT + ['speed' => 'integer'],
        'cooldown' => [
            'shipSymbol' => 'string', 'totalSeconds' => 'integer', 'remainingSeconds' => 'integer',
            'expiration' => 'string', 'readyAt' => 'string',
        ],
        'mounts' => ['*' => [
            'symbol' => 'string', 'name' => 'string', 'description' => 'string', 'strength' => 'integer',
            'deposits' => ['*' => 'string'], 'requirements' => self::REQUIREMENTS,
        ]],
        'modules' => ['*' => [
            'symbol' => 'string', 'name' => 'string', 'description' => 'string',
            'capacity' => 'integer', 'range' => 'integer', 'requirements' => self::REQUIREMENTS,
        ]],
        'cargo' => [
            'capacity' => 'integer', 'units' => 'integer',
            'inventory' => ['*' => ['symbol' => 'string', 'name' => 'string', 'description' => 'string', 'units' => 'integer']],
        ],
        'fuel' => ['current' => 'integer', 'capacity' => 'integer', 'consumed' => ['amount' => 'integer', 'timestamp' => 'string']],
    ];

    public function authority(): array
    {
        $grant = CampaignGrant::where('slot', 1)->first() ?? CampaignGrant::latest('id')->first();
        $episode = $grant ? CampaignEpisode::where('campaign_grant_id', $grant->id)->latest('id')->first() : null;
        $decision = $grant ? CampaignEvent::where('campaign_grant_id', $grant->id)->where('kind', 'decision')->latest('id')->first() : null;

        return compact('grant', 'episode', 'decision') + [
            'problems' => app(CampaignHealth::class)->problems(),
            'pending_grant' => CampaignGrant::where('slot', 2)->value('id'),
        ];
    }

    public function read(): array
    {
        ['grant' => $grant, 'episode' => $episode, 'decision' => $decision, 'problems' => $problems, 'pending_grant' => $pending] = $this->authority();
        $agent = $episode ? Agent::find($episode->agent_id) : null;
        $revision = $grant?->revision();
        $engine = LocalEngine::find(1);
        $activeRuns = $episode ? AutomationRun::where('campaign_episode_id', $episode->id)->whereNotNull('active_agent_id')->orderBy('id')->get() : collect();
        $commandSymbol = $episode?->state['role_ships']['command'] ?? null;
        $run = $activeRuns->firstWhere('ship_symbol', $commandSymbol) ?? $activeRuns->first();
        $loads = $episode ? CampaignEvent::where('campaign_episode_id', $episode->id)->whereIn('kind', ['load_result', 'recovery_result'])->latest('id')->limit(15)->get() : collect();
        $loadRuns = AutomationRun::whereIn('id', $loads->pluck('data.run_id')->filter())->get()->keyBy('id');
        $ships = $agent ? Ship::where('agent_id', $agent->id)->orderBy('symbol')->get() : collect();
        $unlistedFields = [];
        foreach ($ships as $ship) {
            $unlistedFields[$ship->id] = [];
            $ship->snapshot = $this->projectShipValue($ship->snapshot, self::SHIP_DETAILS, '', $unlistedFields[$ship->id]);
        }
        $roles = array_flip(Arr::only($episode?->state['role_ships'] ?? [], ['command', 'probe']));
        if ($episode && app(CampaignFleet::class)->poolEnabled($episode)) {
            foreach (app(CampaignFleet::class)->probes($episode) as $probe) {
                $roles[$probe['symbol']] = 'probe';
            }
        }
        $locations = $ships->unique(fn ($ship) => $ship->snapshot['nav']['systemSymbol'] ?? null)
            ->flatMap(fn ($ship) => collect(app(SystemMapKnowledge::class)->forShip($agent, $ship)['locations'])
                ->map(fn ($point) => Arr::only($point, ['symbol', 'type', 'traits', 'description'])))->values();

        return [
            'updatedAt' => now()->toIso8601String(),
            'grant' => $grant ? [
                'id' => $grant->id, 'active' => $grant->available(), 'paused_at' => $grant->paused_at?->toIso8601String(),
                'pause_reason' => $grant->pause_reason, 'wait_reason' => $grant->wait_reason,
                'expires_at' => $grant->expires_at?->toIso8601String(), 'last_directed_at' => $grant->last_directed_at?->toIso8601String(),
                'revision' => ['name' => $revision->name, 'version' => $revision->body['version'], 'hash' => substr($grant->revision_hash, 0, 12)],
                'pending_grant' => $pending,
            ] : null,
            'episode' => $episode ? [
                'id' => $episode->id, 'reset_id' => $episode->reset_id, 'phase' => $episode->phase,
                'opened_at' => $episode->opened_at->toIso8601String(), 'next_reset_at' => $episode->next_reset_at->toIso8601String(),
                'start_credits' => $episode->start_credits, 'current_credits' => $agent?->credits,
                'net' => $agent?->credits !== null ? $agent->credits - $episode->start_credits : null,
                'hours_elapsed' => round(max(0, $episode->opened_at->diffInSeconds($episode->closed_at ?? now())) / 3600, 2),
                'agent' => $agent ? ['id' => $agent->id, 'name' => $agent->name] : null,
            ] : null,
            'health' => [
                'problems' => collect($problems)->map(fn ($message, $key) => compact('key', 'message'))->values()->all(),
                'engine' => ['state' => $engine?->state ?? 'off', 'heartbeat_at' => $engine?->heartbeat_at?->toIso8601String()],
            ],
            'currentRun' => $run ? [
                'id' => $run->id, 'strategy' => $run->strategy, 'state' => $run->state->value,
                'step' => $run->totals['step_index'] ?? null, 'phase' => $run->totals['phase'] ?? null,
                'ship' => $run->ship_symbol, 'ship_name' => $ships->firstWhere('symbol', $run->ship_symbol)?->displayName() ?? $run->ship_symbol, 'started_at' => $run->started_at?->toIso8601String(), 'blocked_reason' => $run->blocked_reason,
                'plan' => $run->strategy === 'trade' ? ['good' => $run->plan['symbol'] ?? null, 'source' => $run->plan['site'] ?? null,
                    'buyer' => $run->plan['market'] ?? null, 'units' => $run->plan['units'] ?? null] : null,
            ] : null,
            'lastDecision' => $decision ? [
                'at' => $decision->created_at->toIso8601String(), 'choice' => $decision->data['choice'] ?? null, 'run_id' => $decision->data['run_id'] ?? null,
                'rejections' => collect($decision->data['rejections'] ?? [])->sortDesc()->take(5)
                    ->map(fn ($count, $reason) => compact('reason', 'count'))->values()->all(),
            ] : null,
            'loads' => $loads->map(function ($event) use ($loadRuns) {
                $data = $event->data;
                $run = $loadRuns->get($data['run_id'] ?? null);
                $good = $data['good'] ?? null;

                return ['at' => $event->created_at->toIso8601String(), 'good' => $good, 'buyer' => $data['buyer'] ?? null,
                    'mode' => $event->kind === 'recovery_result' ? 'recovery' : ($run?->plan['campaign_trade']['mode'] ?? (! empty($data['proof']) ? 'proof' : 'scaled')),
                    'units' => $data['units'] ?? $run?->totals['purchased'][$good] ?? $run?->plan['initial_inventory'][$good] ?? $run?->plan['units'] ?? null,
                    'predicted_margin' => $data['predicted_margin'] ?? null, 'net' => $data['net'] ?? null, 'losing' => isset($data['net']) ? CampaignTrade::losing($data) : ($data['losing'] ?? false)];
            })->all(),
            'credits' => $episode ? CampaignSample::where('campaign_episode_id', $episode->id)->orderByDesc('observed_at')->orderByDesc('id')->limit(24)->get()->reverse()->values()
                ->map(fn ($sample) => ['at' => $sample->observed_at->toIso8601String(), 'credits' => $sample->credits])->all() : [],
            'events' => $grant ? CampaignEvent::where('campaign_grant_id', $grant->id)->whereNotIn('kind', ['decision', 'load_result', 'recovery_result'])->latest('id')->limit(15)->get()
                ->map(fn ($event) => ['at' => $event->created_at->toIso8601String(), 'kind' => $event->kind,
                    'summary' => $this->eventSummary($event)])->all() : [],
            'ships' => $ships
                ->map(fn ($ship) => ['symbol' => $ship->symbol,
                    'display_name' => $ship->displayName(),
                    'nickname' => $ship->nickname,
                    'observed_at' => $ship->observed_at?->toIso8601String(),
                    'details' => $ship->snapshot,
                    'unlisted_fields' => $unlistedFields[$ship->id],
                    'role' => $roles[$ship->symbol] ?? 'fleet',
                    'waypoint' => $ship->snapshot['nav']['waypointSymbol'] ?? null, 'nav_status' => $ship->snapshot['nav']['status'] ?? null,
                    'nav' => [
                        'status' => $ship->snapshot['nav']['status'] ?? null,
                        'waypointSymbol' => $ship->snapshot['nav']['waypointSymbol'] ?? null,
                        'route' => [
                            'departureTime' => $ship->snapshot['nav']['route']['departureTime'] ?? null,
                            'arrival' => $ship->snapshot['nav']['route']['arrival'] ?? null,
                            'destination' => [
                                'symbol' => $ship->snapshot['nav']['route']['destination']['symbol'] ?? null,
                                'type' => $ship->snapshot['nav']['route']['destination']['type'] ?? null,
                            ],
                        ],
                    ],
                    'locations' => collect(array_filter([
                        $ship->snapshot['nav']['waypointSymbol'] ?? null,
                        $ship->snapshot['nav']['route']['destination']['symbol'] ?? null,
                    ]))->unique()->map(fn ($symbol) => $locations->firstWhere('symbol', $symbol)
                        ?? ['symbol' => $symbol, 'type' => null, 'traits' => null, 'description' => null])->values()->all(),
                    'fuel' => ['current' => $ship->snapshot['fuel']['current'] ?? null, 'capacity' => $ship->snapshot['fuel']['capacity'] ?? null],
                    'cargo' => ['units' => $ship->snapshot['cargo']['units'] ?? null, 'capacity' => $ship->snapshot['cargo']['capacity'] ?? null]])->values()->all(),
        ];
    }

    private function projectShipValue(mixed $value, array|string $projection, string $path, array &$unlisted): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($projection)) {
            $valid = match ($projection) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            };
            if ($valid) {
                return $value;
            }
            $this->unlistedShipPaths($value, $path, $unlisted);

            return null;
        }

        if (! is_array($value) || (isset($projection['*']) ? ! array_is_list($value) : ($value !== [] && array_is_list($value)))) {
            $this->unlistedShipPaths($value, $path, $unlisted);

            return null;
        }

        $result = [];
        foreach ($value as $key => $child) {
            $childPath = is_int($key) ? $path.'['.$key.']' : ($path === '' ? $key : $path.'.'.$key);
            if (isset($projection['*'])) {
                $result[] = $this->projectShipValue($child, $projection['*'], $childPath, $unlisted);
            } elseif (array_key_exists($key, $projection)) {
                $result[$key] = $this->projectShipValue($child, $projection[$key], $childPath, $unlisted);
            } else {
                $this->unlistedShipPaths($child, $childPath, $unlisted);
            }
        }

        return $result;
    }

    private function unlistedShipPaths(mixed $value, string $path, array &$unlisted): void
    {
        $unlisted[] = $path;
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->unlistedShipPaths($child, is_int($key) ? $path.'['.$key.']' : $path.'.'.$key, $unlisted);
            }
        }
    }

    private function eventSummary(CampaignEvent $event): string
    {
        $data = $event->data;

        return Str::limit((string) ($data['reason'] ?? $data['message'] ?? match ($event->kind) {
            'phase' => ($data['from'] ?? 'unknown').' → '.($data['to'] ?? 'unknown'),
            'approval' => 'Revision #'.($data['revision'] ?? '?').(! empty($data['pending']) ? ' · Pending safe point' : ' · Active'),
            'resume' => 'The next decision uses fresh state.',
            'registration' => 'Agent #'.($data['agent_id'] ?? '?').' · '.($data['reset_id'] ?? ''),
            'freshness_hold' => 'Run #'.($data['run_id'] ?? '?'),
            'probe_purchased' => ($data['ship'] ?? 'SHIP_PROBE').' at '.($data['shipyard'] ?? '?').' · '.number_format((int) ($data['price'] ?? 0)).' CR',
            default => $data['symbol'] ?? $data['reset_id'] ?? $event->kind,
        }), 180);
    }
}
