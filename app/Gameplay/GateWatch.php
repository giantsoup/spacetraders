<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Strictly read-only Jump Gate watcher (Council Revision 6).
 *
 * Polls get-waypoint (spacetraders-openapi-2.3.0.json:3145) at most once per 30 minutes under
 * Tier 4 budget priority so completion between polls (isUnderConstruction: true -> false) is
 * always detected. Only when isUnderConstruction === true may an optional bounded get-construction
 * progress read run (spacetraders-openapi-2.3.0.json:3372). Never calls GateRecon::start() or
 * GateRecon::approve(), creates zero ReconMission/AutomationRun rows, mutates zero agent/ship
 * state, and dispatches zero upstream writes.
 */
class GateWatch
{
    public const POLL_INTERVAL_MINUTES = 30;

    public const PROGRESS_ALERT_RATIO = 0.80;

    public function __construct(private SpaceTradersApi $api, private SpaceTradersClient $client) {}

    /**
     * @return array{polled: bool, gate: string, is_under_construction: ?bool, completed_transition: bool, progress_ratio: ?float, alert: ?string}
     */
    public function pollGate(Agent $agent, string $gateSymbol, bool $readProgressWhenUnderConstruction = false): array
    {
        $gateSymbol = strtoupper(trim($gateSymbol));
        $key = 'gate_watch:'.$agent->reset_id.':'.$gateSymbol;

        return app(HostLock::class)->run($key, fn () => $this->poll($agent, $gateSymbol, $key, $readProgressWhenUnderConstruction));
    }

    private function poll(Agent $agent, string $gateSymbol, string $key, bool $readProgressWhenUnderConstruction): array
    {
        $raw = DB::table('game_runtime')->where('key', $key)->value('value');
        $previous = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        if (isset($previous['polled_at']) && CarbonImmutable::parse($previous['polled_at'])->gt(now()->subMinutes(self::POLL_INTERVAL_MINUTES))) {
            return [
                'polled' => false,
                'gate' => $gateSymbol,
                'is_under_construction' => $previous['is_under_construction'] ?? null,
                'completed_transition' => false,
                'progress_ratio' => $previous['progress_ratio'] ?? null,
                'alert' => null,
            ];
        }

        $waypoint = RequestBudget::forAgent('SHARED', fn () => FleetMetrics::as('gate_watch', fn () => $this->client->scope(
            fn () => $this->api->manualRead($agent, 'get-waypoint', ['systemSymbol' => FleetClaims::systemOf($gateSymbol), 'waypointSymbol' => $gateSymbol])['data'],
            priority: 'discovery',
        )));
        if (($waypoint['type'] ?? null) !== 'JUMP_GATE' || ! is_bool($waypoint['isUnderConstruction'] ?? null)) {
            throw new GameException('Jump gate waypoint lacks construction status.');
        }
        $underConstruction = $waypoint['isUnderConstruction'];
        $wasUnderConstruction = array_key_exists('is_under_construction', $previous) ? (bool) $previous['is_under_construction'] : null;
        $completedTransition = $wasUnderConstruction === true && ! $underConstruction;

        $progressRatio = $underConstruction ? ($previous['progress_ratio'] ?? null) : 1.0;
        if ($underConstruction && $readProgressWhenUnderConstruction) {
            try {
                $system = FleetClaims::systemOf($gateSymbol);
                $construction = RequestBudget::forAgent('SHARED', fn () => FleetMetrics::as('gate_watch', fn () => $this->client->scope(
                    fn () => $this->api->manualRead($agent, 'get-construction', ['systemSymbol' => $system, 'waypointSymbol' => $gateSymbol]),
                    priority: 'discovery',
                )));
                $materials = $construction['data']['materials'] ?? [];
                $required = array_sum(array_map(fn ($m) => (int) ($m['required'] ?? 0), $materials));
                $fulfilled = array_sum(array_map(fn ($m) => (int) ($m['fulfilled'] ?? 0), $materials));
                $progressRatio = $required > 0 ? round($fulfilled / $required, 4) : null;
            } catch (GameException) {
                // Bounded read only; waypoint poll already succeeded.
            }
        }

        $alert = match (true) {
            $completedTransition => 'gate_opened',
            $underConstruction && $progressRatio !== null && $progressRatio >= self::PROGRESS_ALERT_RATIO && (($previous['progress_ratio'] ?? 0) < self::PROGRESS_ALERT_RATIO) => 'gate_near_complete',
            default => null,
        };

        $state = [
            'gate' => $gateSymbol,
            'polled_at' => now()->toIso8601String(),
            'is_under_construction' => $underConstruction,
            'progress_ratio' => $progressRatio,
            'last_alert' => $alert ?? ($previous['last_alert'] ?? null),
        ];
        $this->save($key, $state);

        if ($alert !== null) {
            app(CampaignNotifier::class)->alert($key.':'.$alert, 'Jump gate '.$gateSymbol.': '.$alert.'. Operator approval is required before recon or construction.', now()->timestamp, now()->addMinutes(30)->timestamp);
        }

        return [
            'polled' => true,
            'gate' => $gateSymbol,
            'is_under_construction' => $underConstruction,
            'completed_transition' => $completedTransition,
            'progress_ratio' => $progressRatio,
            'alert' => $alert,
        ];
    }

    private function save(string $key, array $state): void
    {
        DB::table('game_runtime')->upsert([['key' => $key, 'value' => json_encode($state, JSON_THROW_ON_ERROR)]], ['key'], ['value']);
    }
}
