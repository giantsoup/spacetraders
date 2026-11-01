<?php

namespace Tests\Support;

use App\Gameplay\MiningRunner;
use App\Models\AutomationRun;
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
            'reactor' => $part + ['symbol' => 'REACTOR_FUSION_I', 'powerOutput' => 20], 'engine' => $part + ['symbol' => 'ENGINE_ION_DRIVE_I'],
            'crew' => ['current' => 20, 'capacity' => 20, 'required' => 5],
            'mounts' => [['symbol' => 'MOUNT_MINING_LASER_I', 'strength' => 10, 'deposits' => ['IRON_ORE', 'COPPER_ORE'], 'requirements' => ['power' => 1, 'crew' => 1]]],
            'modules' => [['symbol' => 'MODULE_MINERAL_PROCESSOR_I', 'requirements' => ['power' => 1, 'crew' => 1]], ['symbol' => 'MODULE_CARGO_HOLD_I', 'capacity' => 40, 'requirements' => []]],
        ];
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
            'goods' => $goods, 'offer' => $offer, 'transactions' => [], 'calls' => [], 'writes' => [], 'yields' => [['symbol' => 'IRON_ORE', 'units' => 10], ['symbol' => 'COPPER_ORE', 'units' => 8]],
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
                if ($request->method() === 'POST' && ($world['verify_worker_storage'] ?? false)) {
                    $world['worker_storage'] = ['pid' => getmypid(), 'synchronous' => (int) DB::selectOne('PRAGMA synchronous')->synchronous, 'fullfsync' => (int) DB::selectOne('PRAGMA fullfsync')->fullfsync];
                }
                if ($request->method() === 'POST' && isset($world['write_stop_run_id'])) {
                    app(MiningRunner::class)->stop(AutomationRun::findOrFail($world['write_stop_run_id']));
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
        $world['calls'][] = ['method' => $request->method(), 'path' => $path];
        if ($request->method() === 'GET') {
            foreach ($world['ships'] as &$arriving) {
                if ($arriving['nav']['status'] === 'IN_TRANSIT' && strtotime($arriving['nav']['route']['arrival']) <= time()) {
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
            if ($path === '/my/ships' || preg_match('~/waypoints$~', $path)) {
                $rows = $path === '/my/ships' ? $world['ships'] : $world['waypoints'];
                $page = (int) ($args['page'] ?? 1);
                // Deliberately small pages exercise pagination even with few fixtures.
                $size = $world['page_size'];

                return [['data' => array_slice($rows, ($page - 1) * $size, $size), 'meta' => ['total' => count($rows), 'page' => $page, 'limit' => 20]], 200];
            }
            if (str_ends_with($path, '/market')) {
                $symbol = explode('/', $path)[4];
                $visible = collect($world['ships'])->contains(fn ($s) => $s['nav']['waypointSymbol'] === $symbol);

                return [['data' => ['symbol' => $symbol, 'imports' => [], 'exports' => [], 'exchange' => array_map(fn ($g) => ['symbol' => $g['symbol']], $world['goods']), 'transactions' => $world['transactions']] + ($visible ? ['tradeGoods' => $world['goods']] : [])], 200];
            }
            if (str_ends_with($path, '/shipyard')) {
                $symbol = explode('/', $path)[4];

                return [['data' => ['symbol' => $symbol, 'shipTypes' => [['type' => 'SHIP_MINING_DRONE']], 'ships' => [$world['offer']], 'transactions' => $world['transactions']]], 200];
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
        if ($path === '/my/ships') {
            $ship = self::ship('LOCAL-'.(count($world['ships']) + 1), $args['waypointSymbol']);
            $world['ships'][] = $ship;
            $price = $world['offer']['purchasePrice'];
            $world['credits'] -= $price;

            return [['data' => ['ship' => $ship, 'agent' => ['credits' => $world['credits']], 'transaction' => ['price' => $price]]], 201];
        }
        if (! preg_match('~^/my/ships/([^/]+)/([^/]+)$~', $path, $match)) {
            return [['error' => ['code' => 404]], 404];
        }
        foreach ($world['ships'] as &$ship) {
            if ($ship['symbol'] !== $match[1]) {
                continue;
            }
            $command = $match[2];
            if (in_array($command, ['orbit', 'dock'], true)) {
                $ship['nav']['status'] = $command === 'orbit' ? 'IN_ORBIT' : 'DOCKED';

                return [['data' => ['nav' => $ship['nav']]], 200];
            }
            if ($command === 'navigate') {
                $ship['nav']['waypointSymbol'] = $args['waypointSymbol'];
                $ship['nav']['status'] = 'IN_TRANSIT';
                $ship['nav']['route']['arrival'] = now()->addSeconds($world['travel_seconds'])->toIso8601String();
                $ship['fuel']['current'] -= 5;

                return [['data' => ['nav' => $ship['nav'], 'fuel' => $ship['fuel']]], 200];
            }
            if ($command === 'extract') {
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
            $good = collect($world['goods'])->firstWhere('symbol', $symbol);
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
