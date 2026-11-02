<?php

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SurveyPool;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class FoundationCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gameplay.enabled' => true]);
        Queue::fake([ExecuteShipCommand::class]);
        $this->file = tempnam(sys_get_temp_dir(), 'st-foundation-command-');
        file_put_contents($this->file, json_encode(FakeSpaceTraders::world() + ['foundation' => true]));
        FakeSpaceTraders::install($this->file);
        $user = User::factory()->create();
        $this->agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC', 'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL')]);
        $this->actingAs($user);
        app(AgentSynchronizer::class)->sync($this->agent);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    private function world(?callable $change = null): array
    {
        $world = json_decode(file_get_contents($this->file), true);
        if ($change) {
            $change($world);
            file_put_contents($this->file, json_encode($world));
        }

        return $world;
    }

    private function preview(string $command, array $arguments = [], string $ship = 'LOCAL-1'): ShipAction
    {
        $response = $this->postJson('/agents/'.$this->agent->id.'/commands/preview', ['key' => (string) Str::uuid(), 'ship_symbol' => $ship, 'command' => $command, 'arguments' => $arguments])->assertAccepted();

        return ShipAction::findOrFail($response->json('id'));
    }

    private function execute(ShipAction $action): ShipAction
    {
        $this->postJson('/agents/'.$this->agent->id.'/commands/'.$action->id.'/approve')->assertAccepted();
        app(ShipCommandExecutor::class)->execute($action->id);

        return $action->fresh();
    }

    public function test_non_miner_purchase_requires_no_setup_or_strategy_and_keeps_actual_configuration(): void
    {
        $this->world(function (&$world) {
            $world['offer']['type'] = 'SHIP_LIGHT_HAULER';
            $world['offer']['mounts'] = [];
        });
        $action = $this->preview('buy_ship', ['shipType' => 'SHIP_LIGHT_HAULER', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 70000]);
        $this->assertEmpty($this->world()['writes']);
        $action = $this->execute($action);
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertSame('LOCAL-2', $action->outcome['delivered_ship']);
        $this->assertSame([], Ship::where('symbol', 'LOCAL-2')->firstOrFail()->snapshot['mounts']);
        $this->assertSame(80000, $this->agent->fresh()->credits);
    }

    public function test_changed_offer_and_insufficient_reserve_never_dispatch_a_purchase(): void
    {
        $arguments = ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK', 'maxTotal' => 20000, 'reserve' => 90000];
        $this->postJson('/agents/'.$this->agent->id.'/commands/preview', ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'buy_ship', 'arguments' => $arguments])->assertUnprocessable();
        $arguments['reserve'] = 1000;
        $action = $this->preview('buy_ship', $arguments);
        $this->world(fn (&$w) => $w['offer']['mounts'] = []);
        $this->postJson('/agents/'.$this->agent->id.'/commands/'.$action->id.'/approve')->assertUnprocessable();
        $this->assertEmpty($this->world()['writes']);
    }

    public function test_jettison_has_explicit_permanent_loss_and_exact_cargo_delta(): void
    {
        $this->world(fn (&$w) => $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 4, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 4]]]);
        $action = $this->execute($this->preview('jettison', ['symbol' => 'IRON_ORE', 'units' => 3, 'acknowledgeLoss' => true]));
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertSame(1, Ship::firstOrFail()->snapshot['cargo']['units']);
        $this->assertSame(0, $action->outcome['credit_delta']);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_jettison_rejects_missing_acknowledgement_and_contract_committed_goods(): void
    {
        $this->world(function (&$w) {
            $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 4, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 4]]];
            $w['contracts'][0]['accepted'] = true;
        });
        foreach ([false, true] as $acknowledgeLoss) {
            $this->postJson('/agents/'.$this->agent->id.'/commands/preview', ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'jettison', 'arguments' => ['symbol' => 'IRON_ORE', 'units' => 1, 'acknowledgeLoss' => $acknowledgeLoss]])->assertUnprocessable();
        }
        $this->assertEmpty($this->world()['writes']);
    }

    public function test_manual_contract_lifecycle_has_no_run_and_agent_actions_need_no_ship(): void
    {
        $accepted = $this->execute($this->preview('contract_accept', ['contractId' => 'contract-1'], ''));
        $this->assertSame(ActionState::Confirmed, $accepted->state);
        $this->assertSame(100, $accepted->outcome['credit_delta']);
        $this->world(function (&$w) {
            $w['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-PORT';
            $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 10, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 10]]];
        });
        $delivered = $this->execute($this->preview('contract_deliver', ['contractId' => 'contract-1', 'symbol' => 'IRON_ORE', 'units' => 10, 'term_index' => 0]));
        $this->assertSame(ActionState::Confirmed, $delivered->state);
        $fulfilled = $this->execute($this->preview('contract_fulfill', ['contractId' => 'contract-1'], ''));
        $this->assertSame(ActionState::Confirmed, $fulfilled->state);
        $this->assertTrue($fulfilled->outcome['contract']['fulfilled']);
        $this->assertSame(1500, $fulfilled->outcome['credit_delta']);
        $this->assertDatabaseCount('automation_runs', 0);
    }

    public function test_negotiation_records_the_new_contract_id_without_acceptance(): void
    {
        $this->world(function (&$w) {
            $w['contracts'] = [];
            $w['waypoints'][0]['faction'] = ['symbol' => 'COSMIC'];
        });
        $action = $this->execute($this->preview('negotiate'));
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertSame('new-contract-1', $action->outcome['contract']['id']);
        $this->assertFalse($action->outcome['contract']['accepted']);
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_repair_uses_fresh_quote_and_saves_condition_and_nonrepairable_integrity(): void
    {
        $this->world(function (&$w) {
            $w['ships'][0]['frame']['condition'] = 0.5;
            $w['ships'][0]['frame']['integrity'] = 0.7;
        });
        $action = $this->execute($this->preview('repair', ['maxTotal' => 100, 'reserve' => 90000]));
        $this->assertSame(ActionState::Confirmed, $action->state);
        $ship = Ship::firstOrFail()->snapshot;
        $this->assertSame(1, $ship['frame']['condition']);
        $this->assertEqualsWithDelta(0.69, $ship['frame']['integrity'], 0.0001);
        $this->assertSame(-100, $action->outcome['credit_delta']);
        $this->assertSame(100, $action->before_state['estimate']['maintenance_quote']['data']['transaction']['totalPrice']);
    }

    public function test_repair_changed_quote_is_rejected_before_sending_beyond_consent(): void
    {
        $action = $this->preview('repair', ['maxTotal' => 100, 'reserve' => 0]);
        $this->world(fn (&$w) => $w['repair_price'] = 101);
        $this->postJson('/agents/'.$this->agent->id.'/commands/'.$action->id.'/approve')->assertUnprocessable();
        $this->assertEmpty($this->world()['writes']);
    }

    public function test_mount_and_module_install_remove_preserve_multiplicity_and_cargo(): void
    {
        foreach (['mount' => 'MOUNT_MINING_LASER_I', 'module' => 'MODULE_GAS_PROCESSOR_I'] as $kind => $symbol) {
            $this->world(function (&$w) use ($symbol) {
                $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 1, 'inventory' => [['symbol' => $symbol, 'units' => 1]]];
            });
            $installed = $this->execute($this->preview('install_'.$kind, ['symbol' => $symbol, 'maxTotal' => 100, 'reserve' => 1000]));
            $this->assertSame(ActionState::Confirmed, $installed->state);
            $this->assertSame(0, Ship::firstOrFail()->snapshot['cargo']['units']);
            $removed = $this->execute($this->preview('remove_'.$kind, ['symbol' => $symbol, 'maxTotal' => 100, 'reserve' => 1000]));
            $this->assertSame(ActionState::Confirmed, $removed->state);
            $this->assertSame(1, Ship::firstOrFail()->snapshot['cargo']['units']);
            $this->assertSame(-100, $removed->outcome['credit_delta']);
        }
    }

    public function test_removing_occupied_cargo_hold_and_overpowered_fit_is_blocked(): void
    {
        $this->postJson('/agents/'.$this->agent->id.'/commands/preview', ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'remove_module', 'arguments' => ['symbol' => 'MODULE_CARGO_HOLD_I', 'maxTotal' => 100]])->assertUnprocessable();
        $this->world(function (&$w) {
            $w['ships'][0]['reactor']['powerOutput'] = 1;
            $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 1, 'inventory' => [['symbol' => 'MOUNT_MINING_LASER_I', 'units' => 1]]];
        });
        $this->postJson('/agents/'.$this->agent->id.'/commands/preview', ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'install_mount', 'arguments' => ['symbol' => 'MOUNT_MINING_LASER_I', 'maxTotal' => 100]])->assertUnprocessable();
        $this->assertEmpty($this->world()['writes']);
    }

    public function test_lost_equipment_response_blocks_replay_and_preserves_fresh_reconciliation(): void
    {
        $action = $this->preview('remove_mount', ['symbol' => 'MOUNT_MINING_LASER_I', 'maxTotal' => 100]);
        $this->postJson('/agents/'.$this->agent->id.'/commands/'.$action->id.'/approve')->assertAccepted();
        $this->world(fn (&$w) => $w['failure'] = 'timeout_after');
        $executor = app(ShipCommandExecutor::class);
        $executor->execute($action->id);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $executor->execute($action->id);
        $executor->reconcile($this->agent, $action);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $this->assertSame([], $action->fresh()->outcome['ship']['mounts']);
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_siphon_and_refine_settle_actual_produced_consumed_cargo_and_raw_cooldown(): void
    {
        $this->world(function (&$w) {
            $w['waypoints'][0]['type'] = 'GAS_GIANT';
            $w['ships'][0]['nav']['status'] = 'IN_ORBIT';
            $w['ships'][0]['mounts'][] = ['symbol' => 'MOUNT_GAS_SIPHON_I', 'requirements' => []];
            $w['ships'][0]['modules'][] = ['symbol' => 'MODULE_GAS_PROCESSOR_I', 'requirements' => []];
        });
        $siphon = $this->execute($this->preview('siphon'));
        $this->assertSame(ActionState::Confirmed, $siphon->state);
        $this->assertSame(['symbol' => 'HYDROCARBON', 'units' => 10], $siphon->outcome['yield']);
        $this->assertArrayNotHasKey('expiration', Ship::firstOrFail()->snapshot['cooldown']);
        $this->world(function (&$w) {
            $w['ships'][0]['modules'][] = ['symbol' => 'MODULE_ORE_REFINERY_I', 'requirements' => []];
            $w['ships'][0]['cargo'] = ['capacity' => 200, 'units' => 100, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 100]]];
        });
        $refine = $this->execute($this->preview('refine', ['produce' => 'IRON']));
        $this->assertSame(ActionState::Confirmed, $refine->state);
        $this->assertSame([['tradeSymbol' => 'IRON_ORE', 'units' => 100]], $refine->outcome['consumed']);
        $this->assertSame([['symbol' => 'IRON', 'units' => 10, 'name' => 'IRON', 'description' => 'Synthetic cargo']], Ship::firstOrFail()->snapshot['cargo']['inventory']);
    }

    public static function refuelVariants(): array
    {
        return [
            'market omitted' => [[], false], 'market explicit' => [['units' => 100], false], 'market capped' => [['units' => 1000], false],
            'cargo maximum is not exact consumption' => [['units' => 100, 'fromCargo' => true, 'maxCargoUnits' => 2], true],
            'cargo boolean input' => [['units' => 100, 'fromCargo' => 1], true],
            'cargo whole' => [['units' => 100, 'fromCargo' => true], true], 'cargo omitted' => [['fromCargo' => true], true], 'cargo partial' => [['units' => 37, 'fromCargo' => true, 'maxCargoUnits' => 1], true],
        ];
    }

    #[DataProvider('refuelVariants')]
    public function test_refuel_variants_send_only_supported_payload_and_preserve_actual_cargo(array $arguments, bool $cargo): void
    {
        $this->world(function (&$w) {
            $w['ships'][0]['fuel'] = ['current' => 0, 'capacity' => 100];
            $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 2, 'inventory' => [['symbol' => 'FUEL', 'units' => 2]]];
        });
        $action = $this->execute($this->preview('refuel', $arguments + ['maxTotal' => $cargo ? 0 : 5, 'reserve' => 1000]));
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertSame(min($arguments['units'] ?? 100, 100), $action->before_state['estimate']['tank_refuel']['tank_units']);
        $this->assertSame($cargo ? 'cargo' : 'market', $action->before_state['estimate']['tank_refuel']['source']);
        if ($cargo && $action->before_state['estimate']['cargo_refuel']['rounding_unknown']) {
            $this->assertNull($action->before_state['estimate']['cargo_refuel']['cargo_units']);
            $this->assertNull($action->before_state['estimate']['cargo_refuel']['cargo_after']);
            $this->assertSame(1, $action->before_state['estimate']['cargo_refuel']['max_cargo_units']);
        }
        $payload = $this->world()['writes'][0]['arguments'];
        $expectedPayload = array_intersect_key($arguments, array_flip(['units', 'fromCargo']));
        if (isset($expectedPayload['fromCargo'])) {
            $expectedPayload['fromCargo'] = (bool) $expectedPayload['fromCargo'];
        }
        $this->assertSame($expectedPayload, $payload);
        $this->assertSame(min($arguments['units'] ?? 100, 100), Ship::firstOrFail()->snapshot['fuel']['current']);
        $this->assertSame($cargo ? 1 : 2, Ship::firstOrFail()->snapshot['cargo']['units']);
    }

    public function test_plain_extraction_preserves_optional_signed_survey_payload(): void
    {
        $signed = ['signature' => 'signed-proof', 'symbol' => 'X1-TEST-ROCK', 'deposits' => [['symbol' => 'IRON_ORE']], 'expiration' => now()->addHour()->toIso8601String(), 'size' => 'SMALL'];
        app(SurveyPool::class)->save($this->agent, $signed, 'LOCAL-1');
        $this->world(fn (&$w) => $w['ships'][0]['nav']['status'] = 'IN_ORBIT');
        $action = $this->execute($this->preview('mine', ['surveySignature' => 'signed-proof', 'surveyWaypoint' => 'X1-TEST-ROCK']));
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertSame(['survey' => $signed], $this->world()['writes'][0]['arguments']);
    }

    public static function navigationModes(): array
    {
        return [['DRIFT', 100], ['BURN', 100], ['STEALTH', 100], ['CRUISE', 0]];
    }

    #[DataProvider('navigationModes')]
    public function test_manual_navigation_records_unknown_variant_budgets_without_inventing_formula(string $mode, int $capacity): void
    {
        $this->world(function (&$w) use ($mode, $capacity) {
            $w['ships'][0]['nav']['status'] = 'IN_ORBIT';
            $w['ships'][0]['nav']['flightMode'] = $mode;
            $w['ships'][0]['fuel'] = ['current' => $capacity, 'capacity' => $capacity];
            $w['fuel_consumption'] = $capacity ? 5 : 0;
        });
        $action = $this->execute($this->preview('navigate', ['waypointSymbol' => 'X1-TEST-PORT', 'maxFuel' => $capacity ? 5 : 0, 'maxSeconds' => 60, 'acknowledgeUnknown' => true]));
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertSame('pending', $action->before_state['estimate']['route']['calibration']);
        $this->assertSame($mode, $action->outcome['travel']['flight_mode']);
        $this->assertSame(['waypointSymbol' => 'X1-TEST-PORT'], $this->world()['writes'][0]['arguments']);
    }

    public function test_all_scans_and_chart_have_independent_manual_paths_and_preserve_complete_collections(): void
    {
        $this->world(function (&$w) {
            $w['ships'][0]['nav']['status'] = 'IN_ORBIT';
            $w['ships'][0]['mounts'][] = ['symbol' => 'MOUNT_SENSOR_ARRAY_I', 'requirements' => []];
            $w['scan_systems'] = [['symbol' => 'X1-NEXT', 'sectorSymbol' => 'X1', 'type' => 'RED_STAR', 'x' => 1, 'y' => 2, 'distance' => 5]];
            $w['scan_ships'] = [['symbol' => 'OTHER-1', 'registration' => ['role' => 'COMMAND'], 'nav' => $w['ships'][0]['nav'], 'engine' => ['symbol' => 'ENGINE_ION_DRIVE_I']]];
            $w['scan_waypoints'] = [$w['waypoints'][1]];
        });
        foreach (['scan_systems', 'scan_waypoints', 'scan_ships'] as $command) {
            $action = $this->execute($this->preview($command, ['maxCooldownSeconds' => 300]));
            $this->assertSame(ActionState::Confirmed, $action->state);
            $this->assertSame(1, $action->outcome['observation_count']);
        }
        $this->assertDatabaseMissing('ships', ['symbol' => 'OTHER-1']);
        $this->world(fn (&$w) => $w['waypoints'][0]['traits'] = [['symbol' => 'UNCHARTED']]);
        $chart = $this->execute($this->preview('chart', ['waypointSymbol' => 'X1-TEST-ROCK']));
        $this->assertSame(ActionState::Confirmed, $chart->state);
        $this->assertSame(100, $chart->outcome['credit_delta']);
        $this->assertArrayNotHasKey('exploration', $chart->outcome);
    }

    public function test_jump_and_warp_preserve_cross_system_navigation_and_actual_transaction(): void
    {
        foreach (['jump', 'warp'] as $command) {
            $this->world(function (&$w) use ($command) {
                $w['ships'][0]['nav']['status'] = 'IN_ORBIT';
                $w['ships'][0]['nav']['systemSymbol'] = 'X1-TEST';
                $w['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-ROCK';
                $w['waypoints'][0]['type'] = 'JUMP_GATE';
                $w['waypoints'][0]['isUnderConstruction'] = false;
                $w['waypoints'][] = ['symbol' => 'X1-NEXT-GATE', 'systemSymbol' => 'X1-NEXT', 'type' => 'JUMP_GATE', 'x' => 10, 'y' => 10, 'traits' => [], 'isUnderConstruction' => false];
                $w['ships'][0]['modules'][] = ['symbol' => $command === 'jump' ? 'MODULE_JUMP_DRIVE_I' : 'MODULE_WARP_DRIVE_I', 'requirements' => []];
            });
            $arguments = ['waypointSymbol' => 'X1-NEXT-GATE', 'reserve' => 1000] + ($command === 'jump' ? ['maxTotal' => 100] : ['maxFuel' => 10, 'maxSeconds' => 60, 'acknowledgeUnknown' => true]);
            $action = $this->execute($this->preview($command, $arguments));
            $this->assertSame(ActionState::Confirmed, $action->state);
            $this->assertSame('X1-NEXT', Ship::firstOrFail()->snapshot['nav']['systemSymbol']);
            $this->assertSame($command === 'jump' ? -100 : 0, $action->outcome['credit_delta']);
        }
    }

    public function test_construction_uses_fresh_material_demand_and_preserves_cargo_and_completion(): void
    {
        $this->world(function (&$w) {
            $w['waypoints'][0]['isUnderConstruction'] = true;
            $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
            $w['construction'] = ['symbol' => 'X1-TEST-ROCK', 'isComplete' => false, 'materials' => [['tradeSymbol' => 'IRON_ORE', 'required' => 3, 'fulfilled' => 0]]];
        });
        $arguments = ['waypointSymbol' => 'X1-TEST-ROCK', 'symbol' => 'IRON_ORE', 'units' => 3, 'acknowledgeLoss' => true];
        $action = $this->execute($this->preview('supply', $arguments));
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertSame(0, Ship::firstOrFail()->snapshot['cargo']['units']);
        $this->assertTrue($action->outcome['response']['construction']['isComplete']);
        $this->assertSame('waypoint', $action->target_type);
        $this->assertSame(['shipSymbol' => 'LOCAL-1', 'tradeSymbol' => 'IRON_ORE', 'units' => 3], $this->world()['writes'][0]['arguments']);
    }

    public function test_concurrent_construction_completion_blocks_an_unsent_contribution(): void
    {
        $this->world(function (&$w) {
            $w['waypoints'][0]['isUnderConstruction'] = true;
            $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 3]]];
        });
        $action = $this->preview('supply', ['waypointSymbol' => 'X1-TEST-ROCK', 'symbol' => 'IRON_ORE', 'units' => 3, 'acknowledgeLoss' => true]);
        $this->world(fn (&$w) => $w['construction'] = ['symbol' => 'X1-TEST-ROCK', 'isComplete' => true, 'materials' => [['tradeSymbol' => 'IRON_ORE', 'required' => 100, 'fulfilled' => 100]]]);
        $this->postJson('/agents/'.$this->agent->id.'/commands/'.$action->id.'/approve')->assertUnprocessable();
        $this->assertEmpty($this->world()['writes']);
    }

    public function test_confirmed_scrap_removes_only_the_exact_ship_and_preserves_history_and_credit_entry(): void
    {
        $older = $this->preview('orbit');
        $action = $this->execute($this->preview('scrap', ['confirmShip' => 'LOCAL-1', 'acknowledgeLoss' => true, 'minTotal' => 100]));
        $this->assertSame(ActionState::Confirmed, $action->state);
        $this->assertDatabaseCount('ships', 0);
        $this->assertDatabaseHas('ship_actions', ['id' => $action->id, 'ship_symbol' => 'LOCAL-1', 'state' => 'confirmed']);
        $this->assertDatabaseHas('confirmed_credit_entries', ['ship_action_id' => $action->id, 'credits' => 100]);
        $this->assertSame(ActionState::Rejected, $older->fresh()->state);
        $this->assertSame('LOCAL-1', $action->outcome['removed_ship']);
        $this->get('/agents/'.$this->agent->id)->assertOk();
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $this->assertDatabaseCount('ships', 0);
    }

    public function test_lost_scrap_response_records_complete_fleet_disappearance_without_claiming_confirmation_or_replaying(): void
    {
        $action = $this->preview('scrap', ['confirmShip' => 'LOCAL-1', 'acknowledgeLoss' => true, 'minTotal' => 0]);
        $this->postJson('/agents/'.$this->agent->id.'/commands/'.$action->id.'/approve')->assertAccepted();
        $this->world(fn (&$w) => $w['failure'] = 'timeout_after');
        $executor = app(ShipCommandExecutor::class);
        $executor->execute($action->id);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $executor->reconcile($this->agent, $action);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $this->assertFalse($action->fresh()->outcome['actor_present']);
        $this->assertSame([], $action->fresh()->outcome['fleet']);
        $this->assertNull($action->fresh()->outcome['ship']);
        $executor->execute($action->id);
        $this->assertCount(1, $this->world()['writes']);
        $this->assertDatabaseHas('ship_actions', ['id' => $action->id]);
    }

    public function test_scrap_requires_exact_identity_and_rejects_changed_or_committed_cargo(): void
    {
        $input = ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'scrap', 'arguments' => ['confirmShip' => 'LOCAL-2', 'acknowledgeLoss' => true, 'minTotal' => 0]];
        $this->postJson('/agents/'.$this->agent->id.'/commands/preview', $input)->assertUnprocessable();
        $action = $this->preview('scrap', ['confirmShip' => 'LOCAL-1', 'acknowledgeLoss' => true, 'minTotal' => 0]);
        $this->world(function (&$w) {
            $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 1, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 1]]];
        });
        $this->postJson('/agents/'.$this->agent->id.'/commands/'.$action->id.'/approve')->assertUnprocessable();
        $this->world(fn (&$w) => $w['contracts'][0]['accepted'] = true);
        $input['arguments']['confirmShip'] = 'LOCAL-1';
        $input['key'] = (string) Str::uuid();
        $this->postJson('/agents/'.$this->agent->id.'/commands/preview', $input)->assertUnprocessable();
        $this->assertEmpty($this->world()['writes']);
    }

    public function test_malformed_success_retains_status_and_body_hash_without_replaying_extraction(): void
    {
        $this->world(fn (&$w) => $w['ships'][0]['nav']['status'] = 'IN_ORBIT');
        $action = $this->preview('mine');
        $this->postJson('/agents/'.$this->agent->id.'/commands/'.$action->id.'/approve')->assertAccepted();
        $this->world(fn (&$w) => $w['failure'] = 'malformed');
        $executor = app(ShipCommandExecutor::class);
        $executor->execute($action->id);
        $outcome = $action->fresh()->outcome;
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $this->assertSame(200, $outcome['http_status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $outcome['body_sha256']);
        $this->assertArrayNotHasKey('response_validated', $outcome);
        $executor->execute($action->id);
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_new_read_and_preview_routes_enforce_real_csrf_with_valid_owner_controls(): void
    {
        $this->app['env'] = 'local'; // Exercise middleware instead of Laravel's unit-test exemption.
        $this->postJson('/agents/'.$this->agent->id.'/reads', ['operation' => 'get-status', 'parameters' => []])->assertStatus(419);
        $this->postJson('/agents/'.$this->agent->id.'/commands/preview', ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'orbit', 'arguments' => []])->assertStatus(419);
        $csrf = bin2hex(random_bytes(20));
        $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf)->postJson('/agents/'.$this->agent->id.'/reads', ['operation' => 'get-status', 'parameters' => []])->assertAccepted();
        $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf)->postJson('/agents/'.$this->agent->id.'/commands/preview', ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'orbit', 'arguments' => []])->assertAccepted();
        $this->assertEmpty($this->world()['writes']);
    }

    public function test_post_receipt_budget_deferral_stays_uncertain_without_redispatch(): void
    {
        $this->world(function (&$world) {
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 1, 'inventory' => [['symbol' => 'MOUNT_MINING_LASER_I', 'units' => 1]]];
        });
        $action = $this->preview('install_mount', ['symbol' => 'MOUNT_MINING_LASER_I', 'maxTotal' => 100]);
        $this->postJson('/agents/'.$this->agent->id.'/commands/'.$action->id.'/approve')->assertAccepted();
        $this->world(function (&$world) {
            $world['defer_followup_after_write'] = true;
        });
        app(ShipCommandExecutor::class)->execute($action->id);
        $action->refresh();
        $this->assertSame(ActionState::Uncertain, $action->state);
        $this->assertNotNull($action->sent_at);
        $this->assertSame(201, $action->outcome['http_status']);
        $this->assertTrue($action->outcome['response_validated']);
        $this->assertNotEmpty($action->outcome['body_sha256']);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->artisan('spacetraders:dispatch-due')->assertSuccessful();
        Queue::assertPushed(ExecuteShipCommand::class, 1);
        $this->assertSame(ActionState::Uncertain, $action->fresh()->state);
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_http_numeric_strings_have_canonical_integer_approval_arguments(): void
    {
        $this->world(function (&$world) {
            $world['ships'][0]['nav']['status'] = 'IN_ORBIT';
        });
        $action = $this->preview('navigate', ['waypointSymbol' => 'X1-TEST-PORT', 'maxFuel' => '5', 'maxSeconds' => '60', 'acknowledgeUnknown' => true]);
        $this->assertSame(5, $action->arguments['maxFuel']);
        $this->assertSame(60, $action->arguments['maxSeconds']);
        $this->world(function (&$world) {
            $world['ships'][0]['nav']['status'] = 'DOCKED';
            $world['ships'][0]['fuel']['current'] = 0;
            $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 2, 'inventory' => [['symbol' => 'FUEL', 'units' => 2]]];
        });
        $action = $this->preview('refuel', ['units' => 100, 'fromCargo' => true, 'maxTotal' => 0, 'protectedFuel' => '0', 'maxCargoUnits' => '2']);
        $this->assertSame(0, $action->arguments['protectedFuel']);
        $this->assertSame(2, $action->arguments['maxCargoUnits']);
    }

    public function test_agent_contract_preview_ignores_selected_transit_ship_and_unrelated_changes(): void
    {
        $this->world(function (&$w) {
            $w['ships'][0]['nav']['status'] = 'IN_TRANSIT';
            $w['ships'][0]['nav']['route']['arrival'] = now()->addHour()->toIso8601String();
        });
        $action = $this->preview('contract_accept', ['contractId' => 'contract-1'], 'LOCAL-1');
        $this->assertSame('', $action->ship_symbol);
        $this->assertNull($action->before_state['ship']);
        $this->world(fn (&$w) => $w['ships'][0]['fuel']['current'] = 50);
        $this->assertSame(ActionState::Confirmed, $this->execute($action)->state);
    }

    public function test_omitted_or_zero_manual_spending_reserve_cannot_drain_the_agent(): void
    {
        $this->world(fn (&$w) => $w['credits'] = 30);
        foreach ([[], ['reserve' => 0]] as $reserve) {
            $this->postJson('/agents/'.$this->agent->id.'/commands/preview', ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'buy', 'arguments' => ['symbol' => 'IRON_ORE', 'units' => 1, 'maxTotal' => 30] + $reserve])->assertUnprocessable();
        }
        $this->world(fn (&$w) => $w['credits'] = 3000);
        $action = $this->preview('buy', ['symbol' => 'IRON_ORE', 'units' => 1, 'maxTotal' => 30]);
        $this->assertSame(2000, $action->before_state['estimate']['reserve']);
        $this->assertEmpty($this->world()['writes']);
    }

    public function test_plain_signed_extraction_rejection_invalidates_the_survey_without_fallback(): void
    {
        $signed = ['signature' => 'rejected-signed', 'symbol' => 'X1-TEST-ROCK', 'deposits' => [['symbol' => 'IRON_ORE']], 'expiration' => now()->addHour()->toIso8601String(), 'size' => 'SMALL'];
        app(SurveyPool::class)->save($this->agent, $signed, 'LOCAL-1');
        $this->world(fn (&$w) => $w['ships'][0]['nav']['status'] = 'IN_ORBIT');
        $action = $this->preview('mine', ['surveySignature' => 'rejected-signed', 'surveyWaypoint' => 'X1-TEST-ROCK']);
        $this->world(fn (&$w) => $w['force_survey_error'] = 4224);
        $this->assertSame(ActionState::Rejected, $this->execute($action)->state);
        $survey = GameObservation::where('kind', 'survey')->where('symbol', 'rejected-signed')->firstOrFail();
        $this->assertTrue($survey->data['exhausted']);
        $this->postJson('/agents/'.$this->agent->id.'/commands/preview', ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'mine', 'arguments' => ['surveySignature' => 'rejected-signed', 'surveyWaypoint' => 'X1-TEST-ROCK']])->assertUnprocessable();
        $this->assertCount(1, $this->world()['writes']);
    }

    public function test_confirmation_does_not_renew_an_unchanged_derived_cooldown_deadline(): void
    {
        Date::setTestNow(now());
        try {
            $this->world(function (&$w) {
                unset($w['ships'][0]['cooldown']['expiration']);
                $w['ships'][0]['cooldown']['remainingSeconds'] = 30;
            });
            $action = $this->preview('orbit');
            $this->world(fn (&$w) => $w['advance_after_write'] = 5);
            $action = $this->execute($action);
            $this->assertSame(ActionState::Confirmed, $action->state);
            $this->assertSame($action->before_state['ship']['cooldown']['readyAt'], Ship::firstOrFail()->snapshot['cooldown']['readyAt']);
            $this->assertArrayNotHasKey('expiration', Ship::firstOrFail()->snapshot['cooldown']);
        } finally {
            Date::setTestNow();
        }
    }
}
