<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Services\SpaceTradersClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class SpaceTradersApi
{
    public function __construct(private SpaceTradersClient $client) {}

    public function status(): string
    {
        $body = $this->call('get', '/');
        $this->validate($body, ['resetDate' => 'required|string|max:80', 'version' => 'required|string|max:80']);

        return $body['resetDate'];
    }

    public function agent(Agent $agent): array
    {
        $data = $this->payload($this->call('get', '/my/agent', [], $agent));
        $this->validate($data, ['symbol' => 'required|string|max:80', 'credits' => 'required|integer|min:0']);
        if ($data['symbol'] !== $agent->name) {
            throw new GameException('Credential belongs to a different agent. Re-import it.');
        }

        return Arr::only($data, ['symbol', 'credits']);
    }

    public function ships(Agent $agent): array
    {
        return array_map($this->shipData(...), $this->pages('/my/ships', $agent));
    }

    public function ship(Agent $agent, string $symbol): array
    {
        $ship = $this->shipData($this->payload($this->call('get', '/my/ships/'.$symbol, [], $agent)));
        if ($ship['symbol'] !== $symbol) {
            throw new GameException('SpaceTraders returned a different ship.');
        }

        return $ship;
    }

    public function catalogue(Agent $agent, string $system, callable $pageSaved): array
    {
        $coverage = ['complete' => false, 'pages' => 0, 'fetched' => 0, 'total' => null, 'reason' => 'Discovery in progress.'];
        $seen = [];
        $pageSaved([], $coverage);
        try {
            for ($page = 1; $page <= config('gameplay.max_pages'); $page++) {
                $body = $this->call('get', '/systems/'.$system.'/waypoints', ['page' => $page, 'limit' => 20], $agent);
                $this->validatePage($body, $page);
                $rows = $this->waypointRows($body['data'], $system);
                $total = $body['meta']['total'];
                $symbols = array_column($rows, 'symbol');
                if (($coverage['total'] !== null && $coverage['total'] !== $total) || array_intersect($seen, $symbols) || count($seen) + count($rows) > $total) {
                    throw new GameException('Catalogue changed while paginating. Synchronize again.');
                }
                $seen = array_merge($seen, $symbols);
                $coverage = ['complete' => count($seen) === $total, 'pages' => $page, 'fetched' => count($seen), 'total' => $total, 'listed_symbols' => $seen,
                    'reason' => count($seen) === $total ? 'Complete known geography; hidden traits remain unresolved.' : 'Page budget reached or listing still incomplete.'];
                $pageSaved($rows, $coverage);
                if ($coverage['complete']) {
                    break;
                }
            }
        } catch (GameException $e) {
            $coverage['complete'] = false;
            $coverage['reason'] = $e->getMessage();
            $pageSaved([], $coverage);
            throw $e;
        }

        return $coverage;
    }

    public function waypoint(Agent $agent, string $symbol): array
    {
        $data = $this->payload($this->call('get', $this->waypointPath($symbol), [], $agent));
        if (($data['symbol'] ?? null) !== $symbol) {
            throw new GameException('Waypoint response does not match the request.');
        }

        return $this->waypointRows([$data], implode('-', array_slice(explode('-', $symbol), 0, 2)))[0];
    }

    private function waypointRows(array $rows, string $system): array
    {
        foreach ($rows as &$row) {
            $this->validate($row, ['symbol' => 'required|string|regex:/^[A-Z0-9-]+$/', 'systemSymbol' => 'required|string', 'type' => 'required|string', 'x' => 'required|integer', 'y' => 'required|integer', 'traits' => 'present|array', 'traits.*.symbol' => 'required|string', 'modifiers' => 'sometimes|array', 'modifiers.*.symbol' => 'required|string']);
            if ($row['systemSymbol'] !== $system) {
                throw new GameException('Waypoint system mismatch.');
            }
            $row = Arr::only($row, ['symbol', 'systemSymbol', 'type', 'x', 'y', 'traits', 'modifiers', 'chart']);
        }

        return $rows;
    }

    public function market(Agent $agent, string $waypoint): array
    {
        $data = $this->payload($this->call('get', $this->waypointPath($waypoint).'/market', [], $agent));
        $this->validate($data, [
            'symbol' => 'required|in:'.$waypoint, 'imports' => 'present|array', 'exports' => 'present|array', 'exchange' => 'present|array',
            'imports.*.symbol' => 'required|string|max:80', 'exports.*.symbol' => 'required|string|max:80', 'exchange.*.symbol' => 'required|string|max:80',
            'tradeGoods' => 'sometimes|array', 'tradeGoods.*.symbol' => 'required|string|max:80',
            'tradeGoods.*.tradeVolume' => 'required|integer|min:1', 'tradeGoods.*.purchasePrice' => 'required|integer|min:0', 'tradeGoods.*.sellPrice' => 'required|integer|min:0',
        ]);

        return Arr::only($data, ['symbol', 'imports', 'exports', 'exchange', 'tradeGoods', 'transactions']);
    }

    public function shipyard(Agent $agent, string $waypoint): array
    {
        $data = $this->payload($this->call('get', $this->waypointPath($waypoint).'/shipyard', [], $agent));
        $this->validate($data, ['symbol' => 'required|in:'.$waypoint, 'shipTypes' => 'present|array', 'ships' => 'sometimes|array', 'ships.*.purchasePrice' => 'required|integer|min:1']);

        return Arr::only($data, ['symbol', 'shipTypes', 'ships', 'transactions']);
    }

    public function mutate(Agent $agent, string $ship, string $command, array $arguments): array
    {
        $path = $command === 'buy_ship' ? '/my/ships' : '/my/ships/'.$ship.'/'.match ($command) {
            'mine' => 'extract', 'mine_survey' => 'extract/survey', 'survey' => 'survey', 'transfer' => 'transfer',
            'buy' => 'purchase', 'sell' => 'sell', 'navigate' => 'navigate', 'dock' => 'dock', 'orbit' => 'orbit', 'refuel' => 'refuel',
            default => throw new GameException('Unsupported command.'),
        };
        $body = match ($command) {
            'buy_ship' => Arr::only($arguments, ['shipType', 'waypointSymbol']),
            'navigate' => Arr::only($arguments, ['waypointSymbol']),
            'buy', 'sell' => Arr::only($arguments, ['symbol', 'units']),
            'refuel' => Arr::only($arguments, ['units']),
            'mine_survey' => app(SurveyPool::class)->usable($agent, $arguments['surveySignature'], $arguments['surveyWaypoint']),
            'transfer' => ['tradeSymbol' => $arguments['symbol'], 'units' => $arguments['units'], 'shipSymbol' => $arguments['targetShip']],
            default => [],
        };
        $data = $this->payload($this->call('post', $path, $body, $agent), true);
        $rules = match ($command) {
            'dock' => ['nav' => 'required|array', 'nav.status' => 'required|in:DOCKED'],
            'orbit' => ['nav' => 'required|array', 'nav.status' => 'required|in:IN_ORBIT'],
            'navigate' => ['nav' => 'required|array', 'nav.status' => 'required|in:IN_TRANSIT', 'nav.route.arrival' => 'required|date', 'fuel.current' => 'required|integer|min:0'],
            'mine', 'mine_survey' => ['cargo' => 'required|array', 'cooldown.remainingSeconds' => 'required|integer|min:0', 'cooldown.expiration' => 'sometimes|nullable|date', 'extraction.yield.symbol' => 'required|string|max:80', 'extraction.yield.units' => 'required|integer|min:0'],
            'survey' => ['cooldown.remainingSeconds' => 'required|integer|min:0', 'surveys' => 'required|array', 'surveys.*.signature' => 'required|string|max:255', 'surveys.*.symbol' => 'required|string|max:80', 'surveys.*.expiration' => 'required|date', 'surveys.*.size' => 'required|in:SMALL,MODERATE,LARGE', 'surveys.*.deposits' => 'required|array', 'surveys.*.deposits.*.symbol' => 'required|string|max:80'],
            'transfer' => ['cargo' => 'required|array', 'targetCargo' => 'required|array'],
            'buy_ship' => ['ship' => 'required|array', 'ship.symbol' => 'required|string', 'agent.credits' => 'required|integer|min:0', 'transaction.price' => 'required|integer|min:0'],
            'refuel' => ['fuel.current' => 'required|integer|min:0', 'agent.credits' => 'required|integer|min:0', 'transaction.totalPrice' => 'required|integer|min:0'],
            default => ['cargo' => 'required|array', 'agent.credits' => 'required|integer|min:0', 'transaction.totalPrice' => 'required|integer|min:0', 'transaction.units' => 'required|integer|min:1', 'transaction.tradeSymbol' => 'required|string'],
        };
        $this->validate($data, $rules, true);

        return Arr::only($data, ['nav', 'fuel', 'cargo', 'cooldown', 'extraction', 'agent', 'transaction', 'ship', 'events', 'modifiers', 'surveys', 'targetCargo']);
    }

    public function shipData(array $data): array
    {
        $this->validate($data, [
            'symbol' => 'required|string|regex:/^[A-Z0-9_-]+$/|max:80', 'nav.systemSymbol' => 'required|string|regex:/^[A-Z0-9-]+$/',
            'nav.waypointSymbol' => 'required|string|regex:/^[A-Z0-9-]+$/', 'nav.status' => 'required|in:DOCKED,IN_ORBIT,IN_TRANSIT',
            'nav.flightMode' => 'required|in:CRUISE,DRIFT,BURN,STEALTH', 'nav.route.arrival' => 'required|date',
            'fuel.current' => 'required|integer|min:0', 'fuel.capacity' => 'required|integer|min:0',
            'cargo.capacity' => 'required|integer|min:0', 'cargo.units' => 'required|integer|min:0', 'cargo.inventory' => 'present|array',
            'cargo.inventory.*.symbol' => 'required|string|distinct|max:80|regex:/^[A-Z0-9_]+$/', 'cargo.inventory.*.units' => 'required|integer|min:1',
            'mounts' => 'present|array|max:100', 'modules' => 'present|array|max:100',
            'mounts.*.symbol' => 'required|string|max:80', 'mounts.*.requirements' => 'present|array', 'mounts.*.requirements.*' => 'integer|min:0',
            'mounts.*.deposits' => 'sometimes|array|max:100', 'mounts.*.deposits.*' => 'string|max:80',
            'modules.*.symbol' => 'required|string|max:80', 'modules.*.capacity' => 'sometimes|integer|min:0', 'modules.*.requirements' => 'present|array', 'modules.*.requirements.*' => 'integer|min:0', 'crew.current' => 'required|integer|min:0', 'crew.required' => 'required|integer|min:0',
            'frame.moduleSlots' => 'required|integer|min:0', 'frame.mountingPoints' => 'required|integer|min:0',
            'frame.integrity' => 'required|numeric|between:0,1', 'engine.integrity' => 'required|numeric|between:0,1', 'reactor.integrity' => 'required|numeric|between:0,1',
            'frame.condition' => 'required|numeric|between:0,1', 'engine.condition' => 'required|numeric|between:0,1', 'reactor.condition' => 'required|numeric|between:0,1',
            'engine.speed' => 'sometimes|integer|min:1',
            'reactor.powerOutput' => 'required|integer|min:0', 'cooldown.remainingSeconds' => 'required|integer|min:0', 'cooldown.expiration' => 'sometimes|nullable|date',
        ]);
        if (array_sum(array_column($data['cargo']['inventory'], 'units')) !== $data['cargo']['units'] || $data['cargo']['units'] > $data['cargo']['capacity'] || $data['fuel']['current'] > $data['fuel']['capacity']) {
            throw new GameException('Inconsistent ship cargo or fuel response.');
        }
        $data = Arr::only($data, ['symbol', 'nav', 'fuel', 'cargo', 'cooldown', 'mounts', 'modules', 'crew', 'frame', 'reactor', 'engine', 'registration']);
        $remaining = $data['cooldown']['remainingSeconds'];
        $data['cooldown']['expiration'] = $data['cooldown']['expiration'] ?? now()->addSeconds($remaining)->toIso8601String();

        return $data;
    }

    private function waypointPath(string $waypoint): string
    {
        if (! preg_match('/^[A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+$/D', $waypoint)) {
            throw new GameException('Invalid waypoint symbol.');
        }

        return '/systems/'.implode('-', array_slice(explode('-', $waypoint), 0, 2)).'/waypoints/'.$waypoint;
    }

    private function pages(string $path, Agent $agent): array
    {
        $all = [];
        $total = null;
        for ($page = 1; $page <= config('gameplay.max_pages'); $page++) {
            $body = $this->call('get', $path, ['page' => $page, 'limit' => 20], $agent);
            $this->validatePage($body, $page);
            $total ??= $body['meta']['total'];
            $all = array_merge($all, $body['data']);
            if ($total !== $body['meta']['total'] || count(array_unique(array_column($all, 'symbol'))) !== count($all) || count($all) > $total) {
                throw new GameException('Discovery changed or repeated records while paginating. Synchronize again.');
            }
            if (count($all) === $total) {
                return $all;
            }
        }
        throw new GameException('Discovery exceeds the bounded page limit. Narrow the local system before continuing.');
    }

    private function validatePage(array $body, int $page): void
    {
        $this->validate($body, ['data' => 'present|array|max:20', 'data.*' => 'array', 'data.*.symbol' => 'required|string|distinct|max:80', 'meta.total' => 'required|integer|min:0', 'meta.page' => 'required|integer|in:'.$page, 'meta.limit' => 'required|integer|in:20']);
    }

    private function call(string $method, string $path, array $body = [], ?Agent $agent = null): array
    {
        try {
            $response = $this->client->request($method, $path, $body, $agent?->credential());
        } catch (ConnectionException) {
            throw new GameException('SpaceTraders connection failed.', uncertain: $method !== 'get', retryAfter: 10);
        }
        if (! $response->successful()) {
            $code = $response->json('error.code');
            if ($response->status() === 401 && $agent) {
                $agent->update(['connection_state' => 'invalid_credential']);
            }
            throw new GameException('SpaceTraders rejected the request, HTTP '.$response->status().'.', is_int($code) && $code > 0 ? $code : null,
                $method !== 'get' && ($response->serverError() || $response->status() === 429), max(5, (int) $response->header('Retry-After')));
        }
        if (strlen($response->body()) > 2 * 1024 * 1024) {
            throw new GameException('SpaceTraders response exceeds the local state limit.', uncertain: $method !== 'get');
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new GameException('SpaceTraders returned an incompatible response.', uncertain: $method !== 'get');
        }

        return $data;
    }

    private function payload(array $body, bool $uncertain = false): array
    {
        if (! isset($body['data']) || ! is_array($body['data'])) {
            throw new GameException('SpaceTraders returned an incompatible data envelope.', uncertain: $uncertain);
        }

        return $body['data'];
    }

    private function validate(array $data, array $rules, bool $uncertain = false): void
    {
        if (Validator::make($data, $rules)->fails()) {
            throw new GameException('SpaceTraders returned incomplete or incompatible state.', uncertain: $uncertain);
        }
    }
}
