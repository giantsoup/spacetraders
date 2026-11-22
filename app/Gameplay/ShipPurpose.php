<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use App\Models\ReconMission;
use App\Models\Ship;
use Illuminate\Support\Collection;

class ShipPurpose
{
    /**
     * Resolves a concise, live purpose label for each ship of an agent from local campaign,
     * arm, recon, and active-run records without contacting SpaceTraders.
     *
     * @param  Collection<int, Ship>  $ships
     * @return array<string, string|null>
     */
    public function forAgent(Agent $agent, Collection $ships): array
    {
        if ($ships->isEmpty()) {
            return [];
        }

        $grant = CampaignGrant::where('slot', 1)->first() ?? CampaignGrant::latest('id')->first();
        $episode = $grant
            ? CampaignEpisode::where('campaign_grant_id', $grant->id)
                ->where('agent_id', $agent->id)
                ->whereNull('closed_at')
                ->latest('id')
                ->first()
            : null;

        $activeRuns = AutomationRun::where('active_agent_id', $agent->id)
            ->orderByDesc('id')
            ->get()
            ->keyBy('ship_symbol');

        $arms = [];
        foreach (CampaignGrant::liveArms()->get() as $armGrant) {
            $arm = $armGrant->arm();
            if (! empty($arm['ship']) && ! empty($arm['label'])) {
                $armEpisode = CampaignEpisode::where('campaign_grant_id', $armGrant->id)
                    ->where('agent_id', $agent->id)
                    ->whereNull('closed_at')
                    ->latest('id')
                    ->first();
                $arms[$arm['ship']] = [
                    'label' => $arm['label'],
                    'paused' => $armGrant->paused_at !== null,
                    'episode' => $armEpisode,
                ];
            }
        }

        $reconShips = ReconMission::where('agent_id', $agent->id)
            ->whereNull('closed_at')
            ->pluck('ship_symbol')
            ->flip()
            ->all();

        $fleet = app(CampaignFleet::class);
        $commandSymbol = $episode?->state['role_ships']['command']
            ?? ($episode ? $ships->first(fn ($ship) => ($ship->snapshot['registration']['role'] ?? null) === 'COMMAND')?->symbol : null);
        $probeSymbols = [];
        if ($episode) {
            if ($fleet->poolEnabled($episode)) {
                $homeSystem = $ships->firstWhere('symbol', $commandSymbol)?->snapshot['nav']['systemSymbol'] ?? null;
                foreach ($ships as $ship) {
                    if ($fleet->isProbe($ship->snapshot)
                        && ! isset($arms[$ship->symbol])
                        && ! isset($reconShips[$ship->symbol])
                        && ($homeSystem === null || ($ship->snapshot['nav']['systemSymbol'] ?? null) === $homeSystem)) {
                        $probeSymbols[] = $ship->symbol;
                    }
                }
            } elseif (isset($episode->state['role_ships']['probe'])) {
                $probeSymbols[] = $episode->state['role_ships']['probe'];
            } else {
                $fallbackProbe = $ships->first(fn ($ship) => $fleet->isProbe($ship->snapshot))?->symbol;
                if ($fallbackProbe !== null) {
                    $probeSymbols[] = $fallbackProbe;
                }
            }
        }
        $probeSymbols = array_values(array_unique($probeSymbols));
        $activeBuyer = $this->activeBuyer($episode, $commandSymbol, $activeRuns);

        $purposes = [];
        foreach ($ships as $ship) {
            $purposes[$ship->symbol] = $this->resolveShip(
                $ship,
                $episode,
                $commandSymbol,
                $probeSymbols,
                $activeBuyer,
                $activeRuns->get($ship->symbol),
                $arms[$ship->symbol] ?? null,
                isset($reconShips[$ship->symbol]),
            );
        }

        return $purposes;
    }

    /** @param Collection<string, AutomationRun> $activeRuns */
    private function activeBuyer(?CampaignEpisode $episode, ?string $commandSymbol, Collection $activeRuns): ?string
    {
        if (! $episode || $episode->phase !== 'earn') {
            return null;
        }
        if ($commandSymbol !== null) {
            $run = $activeRuns->get($commandSymbol);
            if ($run && $run->strategy === 'trade' && is_string($run->plan['market'] ?? null) && $run->plan['market'] !== '') {
                return $run->plan['market'];
            }
        }
        $claimBuyer = $episode->state['claim']['buyer'] ?? null;
        if (is_string($claimBuyer) && $claimBuyer !== '') {
            return $claimBuyer;
        }
        $pair = $episode->state['current_pair'] ?? null;
        if (is_string($pair) && str_contains($pair, '@')) {
            return explode('@', $pair, 2)[1];
        }

        return null;
    }

    /**
     * @param  list<string>  $probeSymbols
     * @param  array{label: string, paused: bool, episode: ?CampaignEpisode}|null  $arm
     */
    private function resolveShip(
        Ship $ship,
        ?CampaignEpisode $episode,
        ?string $commandSymbol,
        array $probeSymbols,
        ?string $activeBuyer,
        ?AutomationRun $activeRun,
        ?array $arm,
        bool $onRecon,
    ): ?string {
        if ($onRecon) {
            return 'Recon';
        }
        if ($arm !== null) {
            $prefix = $arm['label'];
            if ($arm['paused']) {
                return "{$prefix} (Paused)";
            }

            return $this->traderPurpose($prefix, $activeRun, $arm['episode']);
        }
        if ($episode && $ship->symbol === $commandSymbol) {
            if ($episode->phase === 'discover') {
                return 'Discovery Lead';
            }
            if ($episode->phase === 'wind_down') {
                return 'Wind-Down Seller';
            }

            return $this->traderPurpose('Main Trader', $activeRun, $episode);
        }
        if ($episode && in_array($ship->symbol, $probeSymbols, true)) {
            if ($activeRun?->strategy === 'buy_probe') {
                return 'Shipyard Buyer';
            }
            $nav = $ship->snapshot['nav'] ?? [];
            $status = $nav['status'] ?? null;
            $waypoint = $nav['waypointSymbol'] ?? null;
            $destination = $status === 'IN_TRANSIT'
                ? ($nav['route']['destination']['symbol'] ?? $waypoint)
                : ($activeRun?->strategy === 'scout' ? ($activeRun->plan['destination'] ?? null) : null);

            if ($activeBuyer !== null) {
                if ($status !== 'IN_TRANSIT' && $waypoint === $activeBuyer && $activeRun === null) {
                    return 'Buyer Watch ('.$this->shortWaypoint($activeBuyer).')';
                }
                if ($destination === $activeBuyer) {
                    return 'Buyer Watch → '.$this->shortWaypoint($activeBuyer);
                }
            }
            if (is_string($destination) && $destination !== '') {
                return 'Market Scout → '.$this->shortWaypoint($destination);
            }

            return 'Market Scout';
        }
        if ($activeRun !== null) {
            return $this->standaloneRunPurpose($activeRun);
        }
        if ($episode !== null) {
            return 'Standby';
        }

        return null;
    }

    private function traderPurpose(string $prefix, ?AutomationRun $activeRun, ?CampaignEpisode $episode): string
    {
        if ($activeRun !== null) {
            if ($activeRun->strategy === 'trade') {
                $good = $this->humanGood($activeRun->plan['symbol'] ?? null);
                $route = $this->shortRoute($activeRun->plan['site'] ?? null, $activeRun->plan['market'] ?? null);
                $detail = trim($good.($route !== '' ? " ({$route})" : ''));

                return $detail !== '' ? "{$prefix}: {$detail}" : "{$prefix} · Trading";
            }
            if ($activeRun->strategy === 'sell_aboard') {
                $market = $this->shortWaypoint($activeRun->plan['market'] ?? '');

                return $market !== '' ? "{$prefix}: Selling Cargo → {$market}" : "{$prefix}: Selling Cargo";
            }
            if ($activeRun->strategy === 'scout') {
                $dest = $this->shortWaypoint($activeRun->plan['destination'] ?? '');

                return $dest !== '' ? "{$prefix}: Positioning → {$dest}" : "{$prefix}: Positioning";
            }
            if ($activeRun->strategy === 'buy_probe') {
                return "{$prefix}: Shipyard Buyer";
            }
        }
        $claim = $episode?->state['claim'] ?? null;
        if (is_array($claim) && ! empty($claim['good'])) {
            $good = $this->humanGood($claim['good']);
            $route = $this->shortRoute($claim['source'] ?? null, $claim['buyer'] ?? null);
            $detail = trim($good.($route !== '' ? " ({$route})" : ''));
            if ($detail !== '') {
                return "{$prefix}: {$detail}";
            }
        }

        return $prefix === 'Main Trader' ? 'Main Trader' : "{$prefix} Trader";
    }

    private function standaloneRunPurpose(AutomationRun $run): string
    {
        return match ($run->strategy) {
            'trade' => $this->traderPurpose('Trader', $run, null),
            'mining' => ($site = $this->shortWaypoint($run->plan['site'] ?? '')) !== '' ? "Miner ({$site})" : 'Miner',
            'scout' => ($dest = $this->shortWaypoint($run->plan['destination'] ?? '')) !== '' ? "Scout → {$dest}" : 'Scout',
            'sell_aboard' => ($market = $this->shortWaypoint($run->plan['market'] ?? '')) !== '' ? "Cargo Seller → {$market}" : 'Cargo Seller',
            'buy_probe' => 'Shipyard Buyer',
            default => ucwords(str_replace('_', ' ', $run->strategy)),
        };
    }

    private function shortRoute(?string $source, ?string $buyer): string
    {
        if (! is_string($source) || $source === '' || ! is_string($buyer) || $buyer === '') {
            return '';
        }

        return $this->shortWaypoint($source).'→'.$this->shortWaypoint($buyer);
    }

    private function shortWaypoint(string $waypoint): string
    {
        if ($waypoint === '') {
            return '';
        }
        $parts = explode('-', $waypoint);

        return count($parts) >= 3 ? end($parts) : $waypoint;
    }

    private function humanGood(?string $symbol): string
    {
        if (! is_string($symbol) || $symbol === '') {
            return '';
        }

        return ucwords(strtolower(str_replace('_', ' ', $symbol)));
    }
}
