<?php

namespace App\Gameplay;

use App\Models\Agent;
use App\Models\Ship;
use App\Services\SpaceTradersClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class SpaceTradersApi
{
    public function __construct(private SpaceTradersClient $client) {}

    private ?\Closure $readGuard = null;

    public function readOnly(\Closure $guard, callable $work): mixed
    {
        $this->readGuard = $guard;
        try {
            return $this->client->scope($work, 'discovery');
        } finally {
            $this->readGuard = null;
        }
    }

    public function manualRead(Agent $agent, string $operation, array $parameters): array
    {
        $parameters = Validator::make(['operation' => $operation, 'parameters' => $parameters], ManualReads::rules($operation))->validate()['parameters'];
        $system = $parameters['systemSymbol'] ?? '';
        $waypoint = $parameters['waypointSymbol'] ?? '';
        if ($waypoint && ! str_starts_with($waypoint, $system.'-')) {
            throw new GameException('Waypoint does not belong to the selected system.');
        }
        $ship = $parameters['shipSymbol'] ?? '';
        if ($ship && ! Ship::where('agent_id', $agent->id)->where('symbol', $ship)->exists()) {
            throw new GameException('Select an owned saved ship. Refresh the fleet first.');
        }
        $shipPath = '/my/ships/'.$ship;
        $waypointPath = '/systems/'.$system.'/waypoints/'.$waypoint;
        $path = match ($operation) {
            'get-status' => '/', 'get-systems' => '/systems', 'get-system' => '/systems/'.$system,
            'get-system-waypoints' => '/systems/'.$system.'/waypoints', 'get-waypoint' => $waypointPath,
            'get-market' => $waypointPath.'/market', 'get-shipyard' => $waypointPath.'/shipyard',
            'get-jump-gate' => $waypointPath.'/jump-gate', 'get-construction' => $waypointPath.'/construction',
            'get-factions' => '/factions', 'get-faction' => '/factions/'.$parameters['factionSymbol'],
            'get-my-agent' => '/my/agent', 'get-agents' => '/agents', 'get-agent' => '/agents/'.$parameters['agentSymbol'],
            'get-contracts' => '/my/contracts', 'get-contract' => '/my/contracts/'.$parameters['contractId'],
            'get-my-ships' => '/my/ships', 'get-my-ship' => $shipPath,
            'get-my-ship-cargo' => $shipPath.'/cargo', 'get-ship-nav' => $shipPath.'/nav',
            'get-ship-cooldown' => $shipPath.'/cooldown', 'get-mounts' => $shipPath.'/mounts',
            'get-ship-modules' => $shipPath.'/modules', 'get-repair-ship' => $shipPath.'/repair',
            'get-scrap-ship' => $shipPath.'/scrap', 'get-supply-chain' => '/market/supply-chain',
            default => throw new GameException('Unsupported named read.'),
        };
        $paged = ManualReads::OPERATIONS[$operation][2];
        $query = $paged ? ['page' => (int) ($parameters['page'] ?? 1), 'limit' => (int) ($parameters['limit'] ?? 10)] : [];
        if ($operation === 'get-system-waypoints') {
            $query += Arr::only($parameters, ['type', 'traits']);
        }
        // Agent-only gameplay, including inherited-auth operations. Never use account-token fallback.
        $private = str_starts_with($path, '/my/') || in_array($operation, ['get-supply-chain', 'get-faction', 'get-market', 'get-shipyard'], true);
        $body = $this->call('get', $path, $query, $private ? $agent : null, $operation === 'get-ship-cooldown');
        $data = $operation === 'get-status' ? $body : $this->payload($body);
        if ($paged) {
            $identity = $operation === 'get-contracts' ? 'id' : 'symbol';
            $this->validate($body, ['data' => 'present|array|max:'.$query['limit'], 'data.*.'.$identity => 'required|string|distinct', 'meta.page' => 'required|integer|in:'.$query['page'], 'meta.limit' => 'required|integer|in:'.$query['limit'], 'meta.total' => 'required|integer|min:0']);
        } else {
            $rules = match ($operation) {
                'get-status' => ['resetDate' => 'required|string', 'version' => 'required|string'],
                'get-my-ship-cargo' => ['capacity' => 'required|integer|min:0', 'units' => 'required|integer|min:0', 'inventory' => 'present|array'],
                'get-ship-nav' => ['systemSymbol' => 'required|string', 'waypointSymbol' => 'required|string', 'status' => 'required|in:DOCKED,IN_ORBIT,IN_TRANSIT', 'flightMode' => 'required|in:DRIFT,STEALTH,CRUISE,BURN', 'route.arrival' => 'required|date'],
                'get-ship-cooldown' => $data === [] ? [] : ['shipSymbol' => 'required|in:'.$ship, 'remainingSeconds' => 'required|integer|min:0', 'totalSeconds' => 'required|integer|min:0', 'expiration' => 'sometimes|nullable|date'],
                'get-mounts', 'get-ship-modules' => ['*' => 'array', '*.symbol' => 'required|string'],
                'get-repair-ship', 'get-scrap-ship' => ['transaction.shipSymbol' => 'required|in:'.$ship, 'transaction.totalPrice' => 'required|integer|min:0', 'transaction.timestamp' => 'required|date', 'transaction.waypointSymbol' => 'required|string'],
                'get-supply-chain' => ['exportToImportMap' => 'present|array', 'exportToImportMap.*' => 'array', 'exportToImportMap.*.*' => 'string'],
                'get-construction' => ['symbol' => 'required|in:'.$waypoint, 'materials' => 'present|array', 'materials.*.tradeSymbol' => 'required|string', 'materials.*.required' => 'required|integer|min:0', 'materials.*.fulfilled' => 'required|integer|min:0', 'isComplete' => 'required|boolean'],
                'get-jump-gate' => ['symbol' => 'required|in:'.$waypoint, 'connections' => 'present|array', 'connections.*' => 'string'],
                'get-contract' => ['id' => 'required|in:'.$parameters['contractId']],
                default => ['symbol' => 'required|in:'.match ($operation) {
                    'get-system' => $system, 'get-faction' => $parameters['factionSymbol'],
                    'get-agent' => $parameters['agentSymbol'], 'get-my-agent' => $agent->name,
                    'get-my-ship' => $ship, default => $waypoint,
                }],
            };
            $this->validate($data, $rules);
        }
        $result = ['operation' => $operation, 'parameters' => $parameters, 'data' => $data, 'meta' => $body['meta'] ?? null,
            'observed_at' => now()->utc()->toIso8601String(), 'source' => 'official_api', 'no_content' => ($body['_http_status'] ?? null) === 204];
        if ($operation === 'get-ship-cooldown') {
            if ($data === [] && ! $result['no_content']) {
                throw new GameException('Cooldown HTTP 200 requires a cooldown object.');
            }
            $result['deadline'] = $data === [] ? null : Arr::only($this->cooldownData($data), ['readyAt', 'deadlineSource']);
        }
        app(AgentSynchronizer::class)->observe($agent, 'manual_read', $operation.'-'.substr(hash('sha256', json_encode($parameters)), 0, 16), $result);
        if (in_array($operation, ['get-waypoint', 'get-market', 'get-shipyard'], true)) {
            app(AgentSynchronizer::class)->observe($agent, match ($operation) {
                'get-waypoint' => 'waypoint', 'get-market' => 'market', default => 'shipyard'
            }, $waypoint, $data);
        }
        if ($operation === 'get-my-ships') {
            foreach ($data as $row) {
                app(AgentSynchronizer::class)->saveShip($agent, $this->shipData($row));
            }
        }

        return $result;
    }

    public function contracts(Agent $agent): array
    {
        return array_map($this->contractData(...), $this->pages('/my/contracts', $agent, 'id'));
    }

    public function contract(Agent $agent, string $id): array
    {
        $data = $this->contractData($this->payload($this->call('get', '/my/contracts/'.$id, [], $agent)));
        if ($data['id'] !== $id) {
            throw new GameException('Contract identity mismatch.');
        }

        return $data;
    }

    public function contractData(array $data, bool $uncertain = false): array
    {
        $this->validate($data, [
            'id' => 'required|string|max:80|regex:/^[a-zA-Z0-9_-]+$/', 'type' => 'required|string|max:80',
            'accepted' => 'required|boolean', 'fulfilled' => 'required|boolean',
            'deadlineToAccept' => 'sometimes|nullable|date', 'expiration' => 'required|date',
            'terms.deadline' => 'required|date', 'terms.payment.onAccepted' => 'required|integer|between:0,1000000000',
            'terms.payment.onFulfilled' => 'required|integer|between:0,1000000000', 'terms.deliver' => 'present|array|max:20',
            'terms.deliver.*.tradeSymbol' => 'required|string|max:80|regex:/^[A-Z0-9_]+$/',
            'terms.deliver.*.destinationSymbol' => 'required|string|max:80|regex:/^[A-Z0-9-]+$/',
            'terms.deliver.*.unitsRequired' => 'required|integer|between:1,1000000',
            'terms.deliver.*.unitsFulfilled' => 'required|integer|between:0,1000000',
        ], $uncertain);
        foreach ($data['terms']['deliver'] as $term) {
            if ($term['unitsFulfilled'] > $term['unitsRequired']) {
                throw new GameException('Contract delivery exceeds requirement.', uncertain: $uncertain);
            }
        }

        return Arr::only($data, ['id', 'factionSymbol', 'type', 'accepted', 'fulfilled', 'terms', 'deadlineToAccept', 'expiration']);
    }

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

    public function waypoints(Agent $agent, string $system): array
    {
        return $this->waypointRows($this->pages('/systems/'.$system.'/waypoints', $agent), $system);
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
            $row = Arr::only($row, ['symbol', 'systemSymbol', 'type', 'x', 'y', 'traits', 'modifiers', 'chart', 'isUnderConstruction', 'faction', 'orbitals', 'orbits']);
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

    public function commandMetadata(string $command, string $ship, array $arguments): array
    {
        if (! isset(ManualCommands::OPERATIONS[$command])) {
            throw new GameException('Unsupported command.');
        }

        return ['method' => $command === 'flight_mode' ? 'PATCH' : 'POST',
            'actor' => in_array($command, ['contract_accept', 'contract_fulfill', 'buy_ship'], true) ? 'agent' : 'ship',
            'ship' => $ship !== '' ? $ship : null,
            'target_type' => str_starts_with($command, 'contract_') ? 'contract' : ($command === 'supply' ? 'construction' : ($command === 'buy_ship' ? 'fleet' : 'ship')),
            'target' => $arguments['contractId'] ?? $arguments['waypointSymbol'] ?? $arguments['targetShip'] ?? $ship,
            'variant' => match ($command) {
                'refuel' => (($arguments['fromCargo'] ?? false) ? 'cargo' : 'market').(isset($arguments['units']) ? '_explicit_units' : '_fill_tank'),
                'mine' => isset($arguments['surveySignature']) ? 'nested_signed_survey' : 'plain',
                'mine_survey' => 'signed_survey_body', 'flight_mode' => isset($arguments['flightMode']) ? 'set_mode' : 'unchanged_mode', default => 'standard',
            },
            'response_effects' => $this->responseFields($command, $arguments)];
    }

    public function mutate(Agent $agent, string $ship, string $command, array $arguments, ?callable $receipt = null): array
    {
        $path = str_starts_with($command, 'contract_')
            ? '/my/contracts/'.$arguments['contractId'].'/'.match ($command) {
                'contract_accept' => 'accept', 'contract_deliver' => 'deliver', 'contract_fulfill' => 'fulfill', default => throw new GameException('Unsupported contract command.')
            }
        : ($command === 'supply' ? $this->waypointPath($arguments['waypointSymbol']).'/construction/supply' : ($command === 'buy_ship' ? '/my/ships' : '/my/ships/'.$ship.'/'.match ($command) {
            'scrap' => 'scrap', 'jump' => 'jump', 'warp' => 'warp', 'scan_systems' => 'scan/systems', 'scan_ships' => 'scan/ships', 'siphon' => 'siphon', 'refine' => 'refine', 'repair' => 'repair', 'install_mount' => 'mounts/install', 'remove_mount' => 'mounts/remove', 'install_module' => 'modules/install', 'remove_module' => 'modules/remove', 'jettison' => 'jettison', 'negotiate' => 'negotiate/contract', 'flight_mode' => 'nav', 'scan_waypoints' => 'scan/waypoints', 'chart' => 'chart', 'mine' => 'extract', 'mine_survey' => 'extract/survey', 'survey' => 'survey', 'transfer' => 'transfer', 'buy' => 'purchase', 'sell' => 'sell', 'navigate' => 'navigate', 'dock' => 'dock', 'orbit' => 'orbit', 'refuel' => 'refuel',
            default => throw new GameException('Unsupported command.'),
        }));
        $body = match ($command) {
            'install_mount', 'remove_mount', 'install_module', 'remove_module' => Arr::only($arguments, ['symbol']),
            'contract_deliver' => ['shipSymbol' => $ship, 'tradeSymbol' => $arguments['symbol'], 'units' => $arguments['units']],
            'buy_ship' => Arr::only($arguments, ['shipType', 'waypointSymbol']),
            'flight_mode' => Arr::only($arguments, ['flightMode']),
            'navigate', 'jump', 'warp' => Arr::only($arguments, ['waypointSymbol']),
            'supply' => ['shipSymbol' => $ship, 'tradeSymbol' => $arguments['symbol'], 'units' => $arguments['units']],
            'buy', 'sell', 'jettison' => Arr::only($arguments, ['symbol', 'units']),
            'refuel' => Arr::only($arguments, ['units', 'fromCargo']),
            'refine' => Arr::only($arguments, ['produce']),
            'mine' => isset($arguments['surveySignature']) ? ['survey' => app(SurveyPool::class)->usable($agent, $arguments['surveySignature'], $arguments['surveyWaypoint'])] : [],
            'mine_survey' => app(SurveyPool::class)->usable($agent, $arguments['surveySignature'], $arguments['surveyWaypoint']),
            'transfer' => ['tradeSymbol' => $arguments['symbol'], 'units' => $arguments['units'], 'shipSymbol' => $arguments['targetShip']],
            default => [],
        };
        $data = $this->payload($this->call(strtolower($this->commandMetadata($command, $ship, $arguments)['method']), $path, $body, $agent, receipt: $receipt), true);
        $rules = match ($command) {
            'scrap' => ['agent.credits' => 'required|integer|min:0', 'transaction.shipSymbol' => 'required|in:'.$ship, 'transaction.waypointSymbol' => 'required|string', 'transaction.timestamp' => 'required|date', 'transaction.totalPrice' => 'required|integer|min:0'],
            'jump' => ['nav' => 'required|array', 'nav.waypointSymbol' => 'required|in:'.$arguments['waypointSymbol'], 'cooldown.remainingSeconds' => 'required|integer|min:0', 'transaction.totalPrice' => 'required|integer|min:0', 'agent.credits' => 'required|integer|min:0'],
            'warp' => ['nav' => 'required|array', 'nav.status' => 'required|in:IN_TRANSIT', 'nav.route.arrival' => 'required|date', 'fuel.current' => 'required|integer|min:0'],
            'scan_systems' => ['cooldown.remainingSeconds' => 'required|integer|min:0', 'systems' => 'present|array', 'systems.*.symbol' => 'required|string|distinct', 'systems.*.sectorSymbol' => 'required|string', 'systems.*.type' => 'required|string', 'systems.*.x' => 'required|integer', 'systems.*.y' => 'required|integer', 'systems.*.distance' => 'required|integer|min:0'],
            'scan_ships' => ['cooldown.remainingSeconds' => 'required|integer|min:0', 'ships' => 'present|array', 'ships.*.symbol' => 'required|string|distinct', 'ships.*.registration' => 'required|array', 'ships.*.nav' => 'required|array', 'ships.*.engine' => 'required|array'],
            'supply' => ['cargo' => 'required|array', 'construction.symbol' => 'required|in:'.$arguments['waypointSymbol'], 'construction.isComplete' => 'required|boolean', 'construction.materials' => 'present|array', 'construction.materials.*.tradeSymbol' => 'required|string', 'construction.materials.*.required' => 'required|integer|min:0', 'construction.materials.*.fulfilled' => 'required|integer|min:0'],
            'siphon' => ['cargo' => 'required|array', 'cooldown.remainingSeconds' => 'required|integer|min:0', 'siphon.yield.symbol' => 'required|string', 'siphon.yield.units' => 'required|integer|min:0', 'events' => 'present|array'],
            'refine' => ['cargo' => 'required|array', 'cooldown.remainingSeconds' => 'required|integer|min:0', 'produced' => 'present|array', 'consumed' => 'present|array', 'produced.*.tradeSymbol' => 'required|string', 'produced.*.units' => 'required|integer|min:0', 'consumed.*.tradeSymbol' => 'required|string', 'consumed.*.units' => 'required|integer|min:0'],
            'repair' => ['ship.symbol' => 'required|in:'.$ship, 'ship' => 'required|array', 'agent.credits' => 'required|integer|min:0', 'transaction.totalPrice' => 'required|integer|min:0', 'transaction.shipSymbol' => 'required|in:'.$ship],
            'install_mount', 'remove_mount', 'install_module', 'remove_module' => ['agent.credits' => 'required|integer|min:0', (str_ends_with($command, 'mount') ? 'mounts' : 'modules') => 'present|array', 'cargo' => 'required|array', 'transaction.totalPrice' => 'required|integer|min:0', 'transaction.shipSymbol' => 'required|in:'.$ship],
            'jettison' => ['cargo' => 'required|array'],
            'negotiate' => ['contract' => 'required|array'],
            'contract_accept', 'contract_fulfill' => ['contract' => 'required|array', 'agent.credits' => 'required|integer|min:0'],
            'contract_deliver' => ['contract' => 'required|array', 'cargo' => 'required|array'],
            'flight_mode' => ['nav' => 'required|array', 'nav.flightMode' => 'required|in:DRIFT,STEALTH,CRUISE,BURN', 'fuel.current' => 'required|integer|min:0', 'events' => 'present|array'],
            'dock' => ['nav' => 'required|array', 'nav.status' => 'required|in:DOCKED'],
            'orbit' => ['nav' => 'required|array', 'nav.status' => 'required|in:IN_ORBIT'],
            'navigate' => ['nav' => 'required|array', 'nav.status' => 'required|in:IN_TRANSIT', 'nav.route.arrival' => 'required|date', 'fuel.current' => 'required|integer|min:0'],
            'mine', 'mine_survey' => ['cargo' => 'required|array', 'cooldown.remainingSeconds' => 'required|integer|min:0', 'cooldown.expiration' => 'sometimes|nullable|date', 'extraction.yield.symbol' => 'required|string|max:80', 'extraction.yield.units' => 'required|integer|min:0'],
            'scan_waypoints' => ['cooldown.shipSymbol' => 'required|in:'.$ship, 'cooldown.totalSeconds' => 'required|integer|min:0', 'cooldown.remainingSeconds' => 'required|integer|min:0', 'cooldown.expiration' => 'sometimes|nullable|date', 'waypoints' => 'present|array|max:1000', 'waypoints.*.symbol' => 'required|string|distinct'],
            'chart' => ['chart' => 'present|array', 'chart.waypointSymbol' => 'sometimes|in:'.$arguments['waypointSymbol'], 'chart.submittedBy' => 'sometimes|in:'.$agent->name, 'chart.submittedOn' => 'sometimes|date', 'waypoint' => 'required|array', 'agent.symbol' => 'required|in:'.$agent->name, 'agent.credits' => 'required|integer|min:0'],
            'survey' => ['cooldown.remainingSeconds' => 'required|integer|min:0', 'surveys' => 'present|array', 'surveys.*.signature' => 'required|string|max:255', 'surveys.*.symbol' => 'required|string|max:80', 'surveys.*.expiration' => 'required|date', 'surveys.*.size' => 'required|in:SMALL,MODERATE,LARGE', 'surveys.*.deposits' => 'required|array', 'surveys.*.deposits.*.symbol' => 'required|string|max:80'],
            'transfer' => ['cargo' => 'required|array', 'targetCargo' => 'required|array'],
            'buy_ship' => ['ship' => 'required|array', 'ship.symbol' => 'required|string', 'agent.credits' => 'required|integer|min:0', 'transaction.price' => 'required|integer|min:0'],
            'refuel' => ['fuel.current' => 'required|integer|min:0', 'agent.credits' => 'required|integer|min:0', 'transaction.totalPrice' => 'required|integer|min:0'],
            default => ['cargo' => 'required|array', 'agent.credits' => 'required|integer|min:0', 'transaction.totalPrice' => 'required|integer|min:0', 'transaction.units' => 'required|integer|min:1', 'transaction.tradeSymbol' => 'required|string'],
        };
        if ($command === 'refuel' && ($arguments['fromCargo'] ?? false)) {
            $rules['cargo'] = 'required|array';
        }
        $this->validate($data, $rules, true);
        if (isset($data['agent']['symbol']) && $data['agent']['symbol'] !== $agent->name) {
            throw new GameException('Returned agent differs from the credential owner.', uncertain: true);
        }
        $expectedShip = $command === 'buy_ship' ? $data['ship']['symbol'] : $ship;
        if (isset($data['transaction']['shipSymbol']) && $data['transaction']['shipSymbol'] !== $expectedShip) {
            throw new GameException('Transaction ship differs from the approved actor.', uncertain: true);
        }

        if (ExplorationPolicy::command($command)) {
            try {
                $origin = $arguments['waypointSymbol'] ?? Ship::where('agent_id', $agent->id)->where('symbol', $ship)->firstOrFail()->snapshot['nav']['waypointSymbol'];
                $system = implode('-', array_slice(explode('-', $origin), 0, 2));
                if ($command === 'scan_waypoints') {
                    $data['waypoints'] = $this->waypointRows($data['waypoints'], $system);
                    foreach ($data['waypoints'] as $point) {
                        if (! str_starts_with($point['symbol'], $system.'-')) {
                            throw new GameException('Scan waypoint symbol does not match its system.');
                        }
                    }
                    $data['cooldown'] = $this->cooldownData($data['cooldown']);
                    if ($data['cooldown']['remainingSeconds'] > $data['cooldown']['totalSeconds']) {
                        throw new GameException('Inconsistent scan cooldown.');
                    }
                } else {
                    $data['waypoint'] = $this->waypointRows([$data['waypoint']], $system)[0];
                    if ($data['waypoint']['symbol'] !== $arguments['waypointSymbol'] || ! ExplorationPolicy::traitsKnown($data['waypoint'])) {
                        throw new GameException('Chart did not return the approved waypoint with revealed traits.');
                    }
                }
            } catch (GameException $e) {
                throw new GameException($e->getMessage(), uncertain: true);
            }
        }

        if ($command === 'negotiate') {
            $data['contract'] = $this->contractData($data['contract'], true);
            if ($data['contract']['accepted'] || $data['contract']['fulfilled']) {
                throw new GameException('Negotiation did not return a new offered contract.', uncertain: true);
            }
        }
        if (str_starts_with($command, 'contract_')) {
            $data['contract'] = $this->contractData($data['contract'], true);
            if ($data['contract']['id'] !== $arguments['contractId']) {
                throw new GameException('Returned contract target differs from approval.', uncertain: true);
            }
        }

        return Arr::only($data, $this->responseFields($command, $arguments));
    }

    private function responseFields(string $command, array $arguments): array
    {
        return match ($command) {
            'scrap' => ['agent', 'transaction'],
            'jump' => ['nav', 'cooldown', 'transaction', 'agent'],
            'warp' => ['nav', 'fuel'],
            'scan_systems' => ['cooldown', 'systems'], 'scan_ships' => ['cooldown', 'ships'],
            'supply' => ['construction', 'cargo'],
            'siphon' => ['cargo', 'cooldown', 'siphon', 'events'],
            'refine' => ['cargo', 'cooldown', 'produced', 'consumed'],
            'repair' => ['ship', 'agent', 'transaction'],
            'install_mount', 'remove_mount' => ['mounts', 'cargo', 'agent', 'transaction'],
            'install_module', 'remove_module' => ['modules', 'cargo', 'agent', 'transaction'],
            'jettison' => ['cargo'], 'negotiate' => ['contract'],
            'contract_accept', 'contract_fulfill' => ['contract', 'agent'],
            'contract_deliver' => ['contract', 'cargo'],
            'orbit', 'dock' => ['nav'],
            'navigate', 'flight_mode' => ['nav', 'fuel', 'events'],
            'mine', 'mine_survey' => ['cargo', 'cooldown', 'extraction', 'events', 'modifiers'],
            'scan_waypoints' => ['cooldown', 'waypoints'],
            'chart' => ['chart', 'waypoint', 'agent'],
            'survey' => ['cooldown', 'surveys'],
            'transfer' => ['cargo', 'targetCargo'],
            'buy_ship' => ['ship', 'agent', 'transaction'],
            'refuel' => ($arguments['fromCargo'] ?? false) ? ['fuel', 'cargo', 'agent', 'transaction'] : ['fuel', 'agent', 'transaction'],
            'buy', 'sell' => ['cargo', 'agent', 'transaction'],
        };
    }

    public function shipData(array $data): array
    {
        $this->validate($data, [
            'symbol' => 'required|string|regex:/^[A-Z0-9_-]+$/|max:80', 'nav.systemSymbol' => 'required|string|regex:/^[A-Z0-9-]+$/',
            'nav.waypointSymbol' => 'required|string|regex:/^[A-Z0-9-]+$/', 'nav.status' => 'required|in:DOCKED,IN_ORBIT,IN_TRANSIT',
            'nav.flightMode' => 'required|in:CRUISE,DRIFT,BURN,STEALTH', 'nav.route.arrival' => 'required|date',
            'nav.route.departureTime' => 'sometimes|date',
            'fuel.current' => 'required|integer|min:0', 'fuel.capacity' => 'required|integer|min:0',
            'cargo.capacity' => 'required|integer|min:0', 'cargo.units' => 'required|integer|min:0', 'cargo.inventory' => 'present|array',
            'cargo.inventory.*.symbol' => 'required|string|distinct|max:80|regex:/^[A-Z0-9_]+$/', 'cargo.inventory.*.units' => 'required|integer|min:1',
            'mounts' => 'present|array|max:100', 'modules' => 'present|array|max:100',
            'mounts.*.symbol' => 'required|string|max:80', 'mounts.*.requirements' => 'present|array', 'mounts.*.requirements.*' => 'integer|min:0',
            'mounts.*.deposits' => 'sometimes|array|max:100', 'mounts.*.deposits.*' => 'string|max:80',
            'modules.*.symbol' => 'required|string|max:80', 'modules.*.capacity' => 'sometimes|integer|min:0', 'modules.*.requirements' => 'present|array', 'modules.*.requirements.*' => 'integer|min:0', 'crew.current' => 'required|integer|min:0', 'crew.required' => 'required|integer|min:0', 'crew.capacity' => 'required|integer|min:0',
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
        $data['cooldown'] = $this->cooldownData($data['cooldown']);

        return $data;
    }

    public function cooldownData(array $data): array
    {
        $this->validate($data, ['remainingSeconds' => 'required|integer|min:0', 'totalSeconds' => 'sometimes|integer|min:0', 'expiration' => 'sometimes|nullable|date']);
        if (isset($data['totalSeconds']) && $data['remainingSeconds'] > $data['totalSeconds']) {
            throw new GameException('Inconsistent cooldown duration.');
        }
        $data['readyAt'] = isset($data['expiration']) ? CarbonImmutable::parse($data['expiration'])->utc()->toIso8601String() : now()->addSeconds($data['remainingSeconds'])->utc()->toIso8601String();
        $data['deadlineSource'] = isset($data['expiration']) ? 'api_expiration' : 'derived_from_remaining_seconds';

        return $data;
    }

    private function waypointPath(string $waypoint): string
    {
        if (! preg_match('/^[A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+$/D', $waypoint)) {
            throw new GameException('Invalid waypoint symbol.');
        }

        return '/systems/'.implode('-', array_slice(explode('-', $waypoint), 0, 2)).'/waypoints/'.$waypoint;
    }

    private function pages(string $path, Agent $agent, string $identity = 'symbol'): array
    {
        $all = [];
        $total = null;
        for ($page = 1; $page <= config('gameplay.max_pages'); $page++) {
            $body = $this->call('get', $path, ['page' => $page, 'limit' => 20], $agent);
            $this->validatePage($body, $page, $identity);
            $total ??= $body['meta']['total'];
            $all = array_merge($all, $body['data']);
            if ($total !== $body['meta']['total'] || count(array_unique(array_column($all, $identity))) !== count($all) || count($all) > $total) {
                throw new GameException('Discovery changed or repeated records while paginating. Synchronize again.');
            }
            if (count($all) === $total) {
                return $all;
            }
        }
        throw new GameException('Discovery exceeds the bounded page limit. Narrow the local system before continuing.');
    }

    private function validatePage(array $body, int $page, string $identity = 'symbol'): void
    {
        $this->validate($body, ['data' => 'present|array|max:20', 'data.*' => 'array', 'data.*.'.$identity => 'required|string|distinct|max:80', 'meta.total' => 'required|integer|min:0', 'meta.page' => 'required|integer|in:'.$page, 'meta.limit' => 'required|integer|in:20']);
    }

    private function call(string $method, string $path, array $body = [], ?Agent $agent = null, bool $allowNoContent = false, ?callable $receipt = null): array
    {
        $deadline = null;
        if ($this->readGuard) {
            if ($method !== 'get') {
                throw new GameException('Investigation forbids game mutations.');
            }
            $deadline = ($this->readGuard)();
        }
        try {
            $response = $this->client->request($method, $path, $body, $agent?->credential(), $deadline, $agent?->account_id);
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
        if ($response->status() === 204 && $allowNoContent) {
            return ['data' => [], '_http_status' => 204];
        }
        $data = $response->json();
        if ($receipt) {
            $safe = is_array($data) && is_array($data['data'] ?? null) ? Arr::only($data['data'], ['agent', 'ship', 'transaction', 'nav', 'fuel', 'cargo', 'targetCargo', 'cooldown', 'contract', 'mounts', 'modules', 'extraction', 'siphon', 'produced', 'consumed', 'surveys', 'systems', 'waypoints', 'ships', 'chart', 'waypoint', 'construction', 'events', 'modifiers']) : [];
            if (isset($safe['agent']) && ! is_array($safe['agent'])) {
                unset($safe['agent']);
            }
            if (isset($safe['agent'])) {
                $safe['agent'] = Arr::only($safe['agent'], ['symbol', 'credits', 'shipCount', 'headquarters', 'startingFaction', 'accountId']);
            }
            $receipt(['http_status' => $response->status(), 'body_sha256' => hash('sha256', $response->body()), 'response' => $safe]);
        }
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
