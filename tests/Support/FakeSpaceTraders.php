<?php

namespace Tests\Support;

use App\Gameplay\AutomationRunner;
use App\Gameplay\ShipCommandExecutor;
use App\Models\AutomationRun;
use App\Models\ShipAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class FakeSpaceTraders
{
    public static function ship(string $symbol = 'LOCAL-1', string $waypoint = 'X1-TEST-ROCK'): array
    {
        $part = ['condition' => 1.0, 'integrity' => 1.0, 'quality' => 1, 'requirements' => ['crew' => 1, 'power' => 1]];

        return [
            'symbol' => $symbol, 'registration' => ['role' => 'EXCAVATOR'],
            'nav' => ['systemSymbol' => 'X1-TEST', 'waypointSymbol' => $waypoint, 'status' => 'DOCKED', 'flightMode' => 'CRUISE', 'route' => ['arrival' => '2020-01-01T00:00:00Z']],
            'fuel' => ['current' => 100, 'capacity' => 100], 'cargo' => ['capacity' => 40, 'units' => 0, 'inventory' => []],
            'cooldown' => ['remainingSeconds' => 0, 'expiration' => '2020-01-01T00:00:00Z'],
            'frame' => $part + ['symbol' => 'FRAME_MINER', 'fuelCapacity' => 100, 'moduleSlots' => 5, 'mountingPoints' => 3],
            'reactor' => $part + ['symbol' => 'REACTOR_FUSION_I', 'powerOutput' => 20], 'engine' => $part + ['symbol' => 'ENGINE_ION_DRIVE_I', 'speed' => 30],
            'crew' => ['current' => 20, 'capacity' => 20, 'required' => 5],
            'mounts' => [['symbol' => 'MOUNT_MINING_LASER_I', 'strength' => 10, 'deposits' => ['IRON_ORE', 'COPPER_ORE'], 'requirements' => ['power' => 1, 'crew' => 1]]],
            'modules' => [['symbol' => 'MODULE_MINERAL_PROCESSOR_I', 'requirements' => ['power' => 1, 'crew' => 1]], ['symbol' => 'MODULE_CARGO_HOLD_I', 'capacity' => 40, 'requirements' => []]],
        ];
    }

    public static function contract(string $id = 'contract-1'): array
    {
        return ['id' => $id, 'type' => 'PROCUREMENT', 'accepted' => false, 'fulfilled' => false,
            'expiration' => now()->addHour()->toIso8601String(), 'deadlineToAccept' => now()->addHour()->toIso8601String(),
            'terms' => ['deadline' => now()->addDay()->toIso8601String(), 'payment' => ['onAccepted' => 100, 'onFulfilled' => 1500],
                'deliver' => [['tradeSymbol' => 'IRON_ORE', 'destinationSymbol' => 'X1-TEST-PORT', 'unitsRequired' => 10, 'unitsFulfilled' => 0]]]];
    }

    public static function world(): array
    {
        $ship = self::ship();
        $offer = array_intersect_key($ship, array_flip(['frame', 'reactor', 'engine', 'crew', 'mounts', 'modules']));
        $offer += ['type' => 'SHIP_MINING_DRONE', 'name' => 'Complete test miner', 'purchasePrice' => 20000];
        $goods = [
            ['symbol' => 'IRON_ORE', 'tradeVolume' => 4, 'purchasePrice' => 30, 'sellPrice' => 25],
            ['symbol' => 'COPPER_ORE', 'tradeVolume' => 4, 'purchasePrice' => 35, 'sellPrice' => 30],
            ['symbol' => 'FUEL', 'tradeVolume' => 10, 'purchasePrice' => 5, 'sellPrice' => 3],
        ];

        return [
            'reset' => '2026-10-25', 'credits' => 100000, 'symbol' => 'LOCAL', 'ships' => [$ship],
            'waypoints' => [
                ['symbol' => 'X1-TEST-ROCK', 'systemSymbol' => 'X1-TEST', 'type' => 'ASTEROID_FIELD', 'x' => 0, 'y' => 0, 'traits' => [['symbol' => 'MARKETPLACE'], ['symbol' => 'SHIPYARD']]],
                ['symbol' => 'X1-TEST-PORT', 'systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'x' => 3, 'y' => 4, 'traits' => [['symbol' => 'MARKETPLACE']]],
            ],
            'contracts' => [self::contract()], 'goods' => $goods, 'offer' => $offer, 'transactions' => [], 'calls' => [], 'writes' => [], 'yields' => [['symbol' => 'IRON_ORE', 'units' => 10], ['symbol' => 'COPPER_ORE', 'units' => 8]],
            'travel_seconds' => 0, 'cooldown_seconds' => 0, 'page_size' => 20, 'failure' => null,
        ];
    }

    public static function install(string $file): void
    {
        if (! app()->environment(['local', 'testing']) || ! is_file($file)) {
            throw new \RuntimeException('Synthetic provider requires an existing local fixture.');
        }
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($file) {
            if (! str_starts_with($request->url(), 'https://api.spacetraders.io/v2')) {
                throw new \RuntimeException('Synthetic mode denies all other HTTP.');
            }
            $handle = fopen($file, 'r+');
            flock($handle, LOCK_EX);
            try {
                $world = json_decode(stream_get_contents($handle), true, flags: JSON_THROW_ON_ERROR);
                [$body, $status] = self::respond($world, $request);
                if ($request->method() === 'POST' && str_ends_with($request->url(), '/deliver') && ($world['extra_delivery_payment'] ?? false)) {
                    $body['data']['agent'] = ['credits' => 999999999];
                    $body['data']['transaction'] = ['totalPrice' => 123456];
                }
                if ($request->method() === 'POST' && ($world['verify_worker_storage'] ?? false)) {
                    $world['worker_storage'] = ['pid' => getmypid(), 'synchronous' => (int) DB::selectOne('PRAGMA synchronous')->synchronous, 'fullfsync' => (int) DB::selectOne('PRAGMA fullfsync')->fullfsync];
                }
                if ($request->method() === 'POST' && isset($world['write_stop_action_id'])) {
                    app(ShipCommandExecutor::class)->stopManual(ShipAction::findOrFail($world['write_stop_action_id']));
                }
                if ($request->method() === 'POST' && isset($world['write_stop_run_id'])) {
                    app(AutomationRunner::class)->stop(AutomationRun::findOrFail($world['write_stop_run_id']));
                }
                $pause = $request->method() === 'POST' && $world['failure'] === 'pause_after';
                $timeout = $request->method() === 'POST' && $world['failure'] === 'timeout_after';
                if ($timeout) {
                    $world['failure'] = null;
                }
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($world, JSON_THROW_ON_ERROR));
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            if ($pause) {
                file_put_contents($file.'.sent', 'synthetic upstream committed');
                sleep(20); // Fault injection only: verification kills this worker during the lost-response window.
            }
            if ($timeout) {
                throw new ConnectionException('Synthetic lost response after upstream mutation.');
            }

            return Http::response($body, $status, $status === 429 ? ['Retry-After' => '5'] : []);
        });
    }

    public static function respond(array &$world, Request $request): array
    {
        $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/v2')) ?: '/';
        $args = $request->data();
        if (($world['foundation'] ?? false) && $request->method() === 'GET') {
            $response = self::foundationRead($world, $path, $args);
            if ($response !== null) {
                $world['calls'][] = ['method' => 'GET', 'path' => $path];

                return $response;
            }
        }
        $world['calls'][] = ['method' => $request->method(), 'path' => $path];
        if ($request->method() === 'GET') {
            foreach ($world['ships'] as &$arriving) {
                if ($arriving['nav']['status'] === 'IN_TRANSIT' && strtotime($arriving['nav']['route']['arrival']) <= now()->timestamp) {
                    $arriving['nav']['status'] = 'IN_ORBIT';
                }
            }
            unset($arriving);
            if ($path === '/') {
                return [['version' => 'v2.3.0', 'resetDate' => $world['reset']], 200];
            }
            if ($path !== '/' && str_starts_with($world['failure'] ?? '', 'read_')) {
                $status = (int) substr($world['failure'], 5);

                return [['error' => ['code' => $status]], $status];
            }
            if ($path === '/my/agent') {
                return [['data' => ['symbol' => $world['symbol'], 'credits' => $world['credits'], 'accountId' => 'synthetic-account', 'startingFaction' => 'COSMIC']], 200];
            }
            if ($path === '/my/ships' || $path === '/my/contracts' || preg_match('~/waypoints$~', $path)) {
                $rows = match ($path) {
                    '/my/ships' => $world['ships'], '/my/contracts' => $world['contracts'], default => $world['waypoints']
                };
                $page = (int) ($args['page'] ?? 1);
                if ($path !== '/my/ships' && ($world['fail_waypoint_page'] ?? null) === $page) {
                    return [['error' => ['code' => 503]], 503];
                }
                // Deliberately small pages exercise pagination even with few fixtures.
                $size = $world['page_size'];

                return [['data' => array_slice($rows, ($page - 1) * $size, $size), 'meta' => ['total' => count($rows), 'page' => $page, 'limit' => 20]], 200];
            }
            if (preg_match('~^/my/contracts/([^/]+)$~', $path, $m)) {
                $contract = collect($world['contracts'])->firstWhere('id', $m[1]);

                return $contract ? [['data' => $contract], 200] : [['error' => ['code' => 404]], 404];
            }
            if (str_ends_with($path, '/market')) {
                $symbol = explode('/', $path)[4];
                $visible = collect($world['ships'])->contains(fn ($s) => $s['nav']['waypointSymbol'] === $symbol);
                $goods = $world['market_goods'][$symbol] ?? $world['goods'];

                return [['data' => ['symbol' => $symbol, 'imports' => [], 'exports' => [], 'exchange' => array_map(fn ($g) => ['symbol' => $g['symbol']], $goods), 'transactions' => $world['transactions']] + ($visible ? ['tradeGoods' => $goods] : [])], 200];
            }
            if (str_ends_with($path, '/shipyard')) {
                $symbol = explode('/', $path)[4];

                return [['data' => ['symbol' => $symbol, 'shipTypes' => [['type' => 'SHIP_MINING_DRONE']], 'ships' => [$world['offer']], 'transactions' => $world['transactions']]], 200];
            }
            if (preg_match('~^/systems/[^/]+/waypoints/([^/]+)$~', $path, $matches)) {
                return [['data' => collect($world['waypoints'])->firstWhere('symbol', $matches[1])], 200];
            }
            if (preg_match('~^/my/ships/([^/]+)$~', $path, $matches)) {
                foreach ($world['ships'] as &$ship) {
                    if ($ship['symbol'] === $matches[1]) {
                        return [['data' => $ship], 200];
                    }
                }
            }

            return [['error' => ['code' => 404]], 404];
        }
        if ($world['failure'] === '429' || $world['failure'] === '401' || $world['failure'] === '503') {
            $code = (int) $world['failure'];

            return [['error' => ['code' => $code, 'message' => 'Never expose raw synthetic error']], $code];
        }
        $world['writes'][] = ['path' => $path, 'arguments' => $args];
        if (preg_match('~^/my/contracts/([^/]+)/(accept|deliver|fulfill)$~', $path, $m)) {
            foreach ($world['contracts'] as &$contract) {
                if ($contract['id'] !== $m[1]) {
                    continue;
                }
                if ($m[2] === 'accept') {
                    if ($contract['accepted']) {
                        return [['error' => ['code' => 4500]], 400];
                    }
                    $contract['accepted'] = true;
                    $world['credits'] += $contract['terms']['payment']['onAccepted'];
                } elseif ($m[2] === 'fulfill') {
                    if ($contract['fulfilled'] || ! $contract['accepted'] || collect($contract['terms']['deliver'])->contains(fn ($t) => $t['unitsRequired'] !== $t['unitsFulfilled'])) {
                        return [['error' => ['code' => 4501]], 400];
                    }
                    $contract['fulfilled'] = true;
                    $world['credits'] += $contract['terms']['payment']['onFulfilled'];
                } else {
                    foreach ($world['ships'] as &$ship) {
                        if ($ship['symbol'] !== $args['shipSymbol']) {
                            continue;
                        }
                        foreach ($contract['terms']['deliver'] as &$term) {
                            if ($term['tradeSymbol'] === $args['tradeSymbol'] && $term['destinationSymbol'] === $ship['nav']['waypointSymbol']) {
                                $owned = array_column($ship['cargo']['inventory'], 'units', 'symbol')[$args['tradeSymbol']] ?? 0;
                                if (! $contract['accepted'] || $ship['nav']['status'] !== 'DOCKED' || $args['units'] > min($owned, $term['unitsRequired'] - $term['unitsFulfilled'])) {
                                    return [['error' => ['code' => 4502]], 400];
                                }
                                self::cargo($ship, $args['tradeSymbol'], -$args['units']);
                                $term['unitsFulfilled'] += $args['units'];

                                return [['data' => ['contract' => $contract, 'cargo' => $ship['cargo']]], 200];
                            }
                        }
                    }

                    return [['error' => ['code' => 4503]], 400];
                }

                return [['data' => ['contract' => $contract, 'agent' => ['credits' => $world['credits']]]], 200];
            }
        }
        if ($path === '/my/ships') {
            $ship = self::ship('LOCAL-'.(count($world['ships']) + 1), $args['waypointSymbol']);
            $world['ships'][] = $ship;
            $price = $world['offer']['purchasePrice'];
            $world['credits'] -= $price;

            return [['data' => ['ship' => $ship, 'agent' => ['credits' => $world['credits']], 'transaction' => ['price' => $price]]], 201];
        }
        if (! preg_match('~^/my/ships/([^/]+)/(.+)$~', $path, $match)) {
            return [['error' => ['code' => 404]], 404];
        }
        foreach ($world['ships'] as &$ship) {
            if ($ship['symbol'] !== $match[1]) {
                continue;
            }
            $command = $match[2];
            if ($command === 'scan/waypoints') {
                if (! collect($ship['mounts'])->contains(fn ($m) => str_starts_with($m['symbol'], 'MOUNT_SENSOR_ARRAY_'))) {
                    return [['error' => ['code' => 4250]], 400];
                }
                $seconds = $world['cooldown_seconds'];
                $ship['cooldown'] = ['shipSymbol' => $ship['symbol'], 'totalSeconds' => $seconds, 'remainingSeconds' => $seconds, 'expiration' => now()->addSeconds($seconds)->toIso8601String()];
                $rows = $world['scan_waypoints'] ?? [];

                return [['data' => ['cooldown' => $ship['cooldown'], 'waypoints' => $rows]], 201];
            }
            if ($command === 'chart') {
                foreach ($world['waypoints'] as &$point) {
                    if ($point['symbol'] !== $ship['nav']['waypointSymbol']) {
                        continue;
                    }
                    if (! in_array('UNCHARTED', array_column($point['traits'], 'symbol'), true)) {
                        return [['error' => ['code' => 4230]], 400];
                    }
                    $point['traits'] = $world['chart_traits'] ?? [];
                    $chart = ['waypointSymbol' => $point['symbol'], 'submittedBy' => $world['symbol'], 'submittedOn' => now()->toIso8601String()];
                    $point['chart'] = $chart;
                    $world['credits'] += $world['chart_reward'] ?? 100;

                    return [['data' => ['chart' => $chart, 'waypoint' => $point, 'agent' => ['symbol' => $world['symbol'], 'credits' => $world['credits']]]], 201];
                }

                return [['error' => ['code' => 404]], 404];
            }
            if ($command === 'survey') {
                $ship['cooldown'] = ['remainingSeconds' => $world['cooldown_seconds'], 'expiration' => now()->addSeconds($world['cooldown_seconds'])->toIso8601String()];

                return [['data' => ['cooldown' => $ship['cooldown'], 'surveys' => $world['surveys'] ?? []]], 201];
            }
            if ($command === 'transfer') {
                self::cargo($ship, $args['tradeSymbol'], -$args['units']);
                foreach (array_keys($world['ships']) as $index) {
                    if ($world['ships'][$index]['symbol'] === $args['shipSymbol']) {
                        self::cargo($world['ships'][$index], $args['tradeSymbol'], $args['units']);

                        return [['data' => ['cargo' => $ship['cargo'], 'targetCargo' => $world['ships'][$index]['cargo']]], 200];
                    }
                }
            }
            if (in_array($command, ['orbit', 'dock'], true)) {
                $ship['nav']['status'] = $command === 'orbit' ? 'IN_ORBIT' : 'DOCKED';

                return [['data' => ['nav' => $ship['nav']]], 200];
            }
            if ($command === 'navigate') {
                $ship['nav']['waypointSymbol'] = $args['waypointSymbol'];
                $ship['nav']['status'] = 'IN_TRANSIT';
                $ship['nav']['route']['departureTime'] = now()->toIso8601String();
                $ship['nav']['route']['arrival'] = now()->addSeconds($world['travel_seconds'])->toIso8601String();
                $ship['fuel']['current'] -= $world['fuel_consumption'] ?? 5;

                return [['data' => ['nav' => $ship['nav'], 'fuel' => $ship['fuel']]], 200];
            }
            if (in_array($command, ['extract', 'extract/survey'], true)) {
                if ($command === 'extract/survey' && ($world['survey_exhausted'] ?? false)) {
                    return [['error' => ['code' => 4224]], 400];
                }
                $yield = $world['yields'][count(array_filter($world['writes'], fn ($w) => str_ends_with($w['path'], '/extract'))) % count($world['yields'])];
                self::cargo($ship, $yield['symbol'], $yield['units']);
                $ship['cooldown'] = ['remainingSeconds' => $world['cooldown_seconds'], 'expiration' => now()->addSeconds($world['cooldown_seconds'])->toIso8601String()];
                if ($world['failure'] === 'mismatched_yield') {
                    $yield = ['symbol' => 'IRON_ORE', 'units' => 999];
                }
                if ($world['failure'] === 'malformed') {
                    return [['data' => ['unexpected' => true]], 200];
                }

                return [['data' => ['cargo' => $ship['cargo'], 'cooldown' => $ship['cooldown'], 'extraction' => ['yield' => $yield]]], 200];
            }
            $symbol = $args['symbol'] ?? 'FUEL';
            $good = collect($world['market_goods'][$ship['nav']['waypointSymbol']] ?? $world['goods'])->firstWhere('symbol', $symbol);
            if (! $good) {
                return [['error' => ['code' => 4600]], 400];
            }
            $units = $args['units'];
            $price = ($command === 'refuel' ? (int) ceil($units / 100) : $units) * ($command === 'sell' ? $good['sellPrice'] : $good['purchasePrice']);
            if ($world['failure'] === 'price_jump') {
                $price *= 3;
            }
            if ($command === 'refuel') {
                $ship['fuel']['current'] += $units;
            } else {
                self::cargo($ship, $symbol, $command === 'sell' ? -$units : $units);
            }
            $world['credits'] += $command === 'sell' ? $price : -$price;
            $transaction = ['totalPrice' => $price, 'tradeSymbol' => $symbol, 'units' => $units, 'shipSymbol' => $ship['symbol'], 'timestamp' => now()->toIso8601String()];
            array_unshift($world['transactions'], $transaction);

            return [['data' => ['agent' => ['credits' => $world['credits']], 'transaction' => $transaction, 'cargo' => $ship['cargo'], 'fuel' => $ship['fuel']]], 200];
        }

        return [['error' => ['code' => 404]], 404];
    }

    private static function foundationRead(array $world, string $path, array $args): ?array
    {
        $system = ['symbol' => 'X1-TEST', 'sectorSymbol' => 'X1', 'type' => 'RED_STAR', 'x' => 0, 'y' => 0, 'waypoints' => $world['waypoints'], 'factions' => [['symbol' => 'COSMIC']]];
        $faction = ['symbol' => 'COSMIC', 'name' => 'Cosmic', 'description' => 'Synthetic faction', 'headquarters' => 'X1-TEST-ROCK', 'traits' => [], 'isRecruiting' => true];
        $profile = ['symbol' => 'LOCAL', 'credits' => $world['credits'], 'shipCount' => count($world['ships']), 'headquarters' => 'X1-TEST-ROCK', 'startingFaction' => 'COSMIC', 'accountId' => 'synthetic-account'];
        $lists = ['/systems' => [$system], '/agents' => [$profile], '/factions' => [$faction], '/my/ships' => $world['ships'], '/my/contracts' => $world['contracts']];
        if (preg_match('~^/systems/([^/]+)/waypoints$~', $path)) {
            $lists[$path] = array_values(array_filter($world['waypoints'], function ($point) use ($args) {
                return (! isset($args['type']) || $point['type'] === $args['type']) && (! isset($args['traits']) || ! array_diff((array) $args['traits'], array_column($point['traits'], 'symbol')));
            }));
        }
        if (isset($lists[$path])) {
            $limit = (int) ($args['limit'] ?? 10);
            $page = (int) ($args['page'] ?? 1);

            return [['data' => array_slice($lists[$path], ($page - 1) * $limit, $limit), 'meta' => ['total' => count($lists[$path]), 'limit' => $limit, 'page' => $page]], 200];
        }
        if ($path === '/systems/X1-TEST') {
            return [['data' => $system], 200];
        }
        if ($path === '/factions/COSMIC') {
            return [['data' => $faction], 200];
        }
        if (in_array($path, ['/agents/LOCAL', '/my/agent'], true)) {
            return [['data' => $profile], 200];
        }
        if ($path === '/market/supply-chain') {
            return [['data' => ['exportToImportMap' => ['IRON_ORE' => ['IRON']]]], 200];
        }
        if (str_ends_with($path, '/jump-gate')) {
            return [['data' => ['symbol' => 'X1-TEST-ROCK', 'connections' => ['X1-NEXT-GATE']]], 200];
        }
        if (str_ends_with($path, '/construction')) {
            return [['data' => $world['construction'] ?? ['symbol' => 'X1-TEST-ROCK', 'isComplete' => false, 'materials' => [['tradeSymbol' => 'IRON_ORE', 'required' => 100, 'fulfilled' => 0]]]], 200];
        }
        if (preg_match('~^/my/ships/([^/]+)/(cargo|nav|cooldown|mounts|modules|repair|scrap)$~', $path, $match)) {
            $ship = collect($world['ships'])->firstWhere('symbol', $match[1]);
            if (! $ship) {
                return [['error' => ['code' => 404]], 404];
            }
            $field = $match[2];
            if ($field === 'cooldown' && ($world['cooldown_204'] ?? false)) {
                return ['', 204];
            }
            if (in_array($field, ['repair', 'scrap'], true)) {
                return [['data' => ['transaction' => ['shipSymbol' => $ship['symbol'], 'waypointSymbol' => $ship['nav']['waypointSymbol'], 'totalPrice' => $world[$field.'_price'] ?? 100, 'timestamp' => now()->toIso8601String()]]], 200];
            }
            $data = $ship[$field];
            if ($field === 'cooldown') {
                $data += ['shipSymbol' => $ship['symbol'], 'totalSeconds' => 0];
            }

            return [['data' => $data], 200];
        }

        return null;
    }

    private static function cargo(array &$ship, string $symbol, int $delta): void
    {
        $inventory = array_column($ship['cargo']['inventory'], 'units', 'symbol');
        $inventory[$symbol] = ($inventory[$symbol] ?? 0) + $delta;
        $ship['cargo']['inventory'] = [];
        foreach (array_filter($inventory) as $good => $units) {
            $ship['cargo']['inventory'][] = ['symbol' => $good, 'units' => $units, 'name' => $good, 'description' => 'Synthetic cargo'];
        }
        $ship['cargo']['units'] = array_sum($inventory);
    }
}
