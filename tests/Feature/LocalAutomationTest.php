<?php

namespace Tests\Feature;

use App\Enums\RunState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\AutomationStaging;
use App\Gameplay\ContractPlanner;
use App\Gameplay\CreditAccounting;
use App\Gameplay\EngineGate;
use App\Gameplay\GameException;
use App\Gameplay\MiningPlanner;
use App\Gameplay\OpportunityEvaluator;
use App\Gameplay\OpportunityInvestigator;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Models\Agent;
use App\Models\AutomationDraft;
use App\Models\AutomationRun;
use App\Models\LocalEngine;
use App\Models\OpportunityInvestigation;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\ResponseFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class LocalAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gameplay.enabled' => true, 'gameplay.local_engine_enabled' => true]);
        $this->file = tempnam(sys_get_temp_dir(), 'st-automation-');
        file_put_contents($this->file, json_encode(FakeSpaceTraders::world()));
        FakeSpaceTraders::install($this->file);
        $user = User::factory()->create();
        $this->agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'account_id' => 'synthetic', 'faction' => 'COSMIC', 'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL')]);
        $this->actingAs($user);
        app(AgentSynchronizer::class)->sync($this->agent);
        Queue::fake();
        $this->engine();
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    private function engine(): void
    {
        LocalEngine::updateOrCreate(['id' => 1], ['state' => 'running', 'session_id' => (string) Str::uuid(), 'gate_open' => true, 'heartbeat_at' => now(), 'processes' => ['supervisor' => getmypid(), 'worker' => getmypid(), 'scheduler' => getmypid()]]);
    }

    private function world(?callable $edit = null): array
    {
        $world = json_decode(file_get_contents($this->file), true);
        if ($edit) {
            $edit($world);
            file_put_contents($this->file, json_encode($world));
        }

        return $world;
    }

    private function investigation(string $strategy = 'contracts'): OpportunityInvestigation
    {
        $investigator = app(OpportunityInvestigator::class);
        $record = $investigator->create($this->agent->fresh(), $strategy, 'X1-TEST');
        for ($i = 0; $i < 40 && $record->fresh()->status === 'investigating'; $i++) {
            $record = $investigator->advance($this->agent->fresh(), $record);
        }
        $this->assertSame('complete', $record->fresh()->status, json_encode($record->coverage));

        return $record->fresh();
    }

    private function draft(string $strategy = 'contracts', array $limits = [], ?callable $select = null): AutomationDraft
    {
        $investigation = $this->investigation($strategy);
        $candidate = collect($investigation->candidates)->first($select ?? fn ($c) => $c['classification'] === 'ready');
        $this->assertNotNull($candidate, json_encode($investigation->candidates));

        return app(AutomationStaging::class)->save($this->agent->fresh(), $investigation, $candidate['id'], array_merge(['reserve' => 2000, 'max_spend' => 500, 'max_loss' => 500, 'minutes' => 60, 'cycles' => 1, 'delivery_limit' => 10, 'purchase_limit' => 40], $limits));
    }

    private function preview(string $strategy = 'contracts', array $limits = [], ?callable $select = null): AutomationRun
    {
        return app(AutomationRunner::class)->previewDraft($this->agent->fresh(), $this->draft($strategy, $limits, $select));
    }

    private function drain(AutomationRun $run, int $steps = 100): void
    {
        for ($i = 0; $i < $steps && $run->fresh()->active_agent_id && $run->fresh()->state !== RunState::Blocked; $i++) {
            $run->update(['next_action_at' => now()]);
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
        }
    }

    public function test_preview_explains_departure_before_cooldown_at_each_approved_limit(): void
    {
        foreach ([
            ['max_extractions' => 1, 'extraction_headroom' => 1, 'site_seconds' => 300],
            ['max_extractions' => 5, 'extraction_headroom' => 40, 'site_seconds' => 300],
            ['max_extractions' => 5, 'extraction_headroom' => 1, 'site_seconds' => 10],
        ] as $limits) {
            $run = $this->preview('mining', $limits);
            $this->assertStringContainsString('travel can overlap the remaining extraction cooldown', $run->plan['ordered_actions'][2]);
            $ship = Ship::where('agent_id', $this->agent->id)->where('symbol', 'LOCAL-1')->firstOrFail()->snapshot;
            $ship['nav']['status'] = 'IN_ORBIT';
            $ship['cooldown']['expiration'] = now()->addSeconds(80)->toIso8601String();
            $ship['cargo']['units'] = 4;
            $ship['cargo']['inventory'] = [['symbol' => 'IRON_ORE', 'units' => 4]];
            $totals = ['phase' => 'mine', 'no_progress' => 0, 'load_attempts' => 1,
                'load_started_at' => now()->subSeconds(10)->toIso8601String(), 'extracted' => ['IRON_ORE' => 4], 'sold' => []];
            $planner = app(MiningPlanner::class);
            $this->assertSame('depart', $planner->next($ship, $run->plan, $totals, [], now()->toImmutable())->kind);
            $totals['phase'] = 'sell';
            $plan = $run->plan;
            $plan['route']['market'] = 'X1-TEST-PORT';
            $step = $planner->next($ship, $plan, $totals, [], now()->toImmutable());
            $this->assertSame('navigate', $step->command);
            $this->assertSame('X1-TEST-PORT', $step->arguments['waypointSymbol']);
        }
        $this->assertSame([], $this->world()['writes']);
        $this->assertSame(0, ShipAction::count());
    }

    public function test_investigation_and_staging_are_read_only_and_work_with_engine_off(): void
    {
        app(EngineGate::class)->close();
        $draft = $this->draft();
        $this->assertSame([], $this->world()['writes']);
        $this->assertSame(0, ShipAction::count());
        $this->assertSame(0, AutomationRun::count());
        $this->assertSame(2, $draft->plan_version);
        $this->assertTrue(OpportunityInvestigation::first()->coverage['requests'] <= 40);
    }

    public function test_pagination_uses_contract_id_and_cancellation_stops_reads(): void
    {
        $this->world(function (&$w) {
            $w['page_size'] = 1;
            $w['contracts'][] = FakeSpaceTraders::contract('contract-2');
        });
        $this->assertCount(2, app(SpaceTradersApi::class)->contracts($this->agent));
        $record = app(OpportunityInvestigator::class)->create($this->agent, 'contracts', 'X1-TEST');
        app(OpportunityInvestigator::class)->cancel($record);
        $before = count($this->world()['calls']);
        app(OpportunityInvestigator::class)->advance($this->agent, $record);
        $this->assertSame($before, count($this->world()['calls']));
        $this->assertSame('cancelled', $record->fresh()->status);
    }

    public function test_completed_contracts_are_not_automation_candidates(): void
    {
        $record = $this->investigation();
        $observations = $record->observations;
        $completed = $observations['contracts'][0];
        $completed['fulfilled'] = true;
        $completed['id'] = 'completed-contract';
        $observations['contracts'] = array_merge(array_fill(0, 110, $completed), $observations['contracts']);

        $candidates = app(OpportunityEvaluator::class)->evaluate($observations, 'contracts', CarbonImmutable::now());

        $this->assertNotEmpty($candidates);
        $this->assertSame(['contract-1'], array_values(array_unique(array_column($candidates, 'contract_id'))));
        $observations['contracts'] = [$completed];
        $this->assertSame([], app(OpportunityEvaluator::class)->evaluate($observations, 'contracts', CarbonImmutable::now()));
    }

    public function test_contract_search_stops_before_market_discovery_when_all_contracts_are_fulfilled(): void
    {
        $this->world(function (&$w) {
            $w['contracts'][0]['fulfilled'] = true;
        });
        $before = count($this->world()['calls']);
        $record = $this->investigation();

        $this->assertSame([], $record->candidates);
        $this->assertArrayNotHasKey('markets', $record->observations);
        $calls = array_slice($this->world()['calls'], $before);
        $this->assertFalse(collect($calls)->contains(fn ($call) => str_contains($call['path'], '/waypoints')));
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_request_and_page_bounds_preserve_partial_status(): void
    {
        config(['gameplay.investigation_requests' => 2]);
        $investigator = app(OpportunityInvestigator::class);
        $record = $investigator->create($this->agent, 'contracts', 'X1-TEST');
        $investigator->advance($this->agent, $record);
        $investigator->advance($this->agent, $record);
        $this->assertSame('partial', $record->fresh()->status);
        $this->assertSame(2, $record->fresh()->coverage['requests']);
        $this->assertNotEmpty($record->fresh()->coverage['limits_reached']);
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_partial_market_search_can_stage_a_verified_mining_route_without_gameplay(): void
    {
        config(['gameplay.max_observations' => 1]);
        $investigator = app(OpportunityInvestigator::class);
        $record = $investigator->create($this->agent, 'mining', 'X1-TEST');
        for ($i = 0; $i < 10 && $record->status === 'investigating'; $i++) {
            $record = $investigator->advance($this->agent->fresh(), $record);
        }
        $this->assertSame('partial', $record->status);
        $candidate = collect($record->candidates)->firstWhere('classification', 'ready');
        $this->assertNotNull($candidate, json_encode($record->candidates));
        $draft = app(AutomationStaging::class)->save($this->agent->fresh(), $record, $candidate['id'], ['reserve' => 2000]);
        $this->assertSame($candidate['id'], $draft->candidate['id']);
        $this->assertSame([], $this->world()['writes']);
        $this->assertSame(0, ShipAction::count());
        $this->travel(181)->seconds();
        $this->expectException(GameException::class);
        app(AutomationStaging::class)->save($this->agent->fresh(), $record, $candidate['id'], ['reserve' => 2000]);
    }

    public function test_unknown_mining_prices_offer_a_specific_observation_step_and_remain_unselectable(): void
    {
        $record = $this->investigation('mining');
        $candidate = collect($record->candidates)->first(fn ($c) => $c['market'] === 'X1-TEST-PORT');
        $this->assertSame('needs_information', $candidate['classification']);
        $this->assertSame(['kind' => 'observe_market', 'ship_symbol' => 'LOCAL-1', 'market' => 'X1-TEST-PORT', 'at_market' => false], $candidate['preparation']);
        $this->expectException(GameException::class);
        app(AutomationStaging::class)->save($this->agent->fresh(), $record, $candidate['id'], ['reserve' => 2000]);
    }

    public function test_candidate_limit_prioritizes_capable_ships_safe_nearby_sites_and_priced_markets(): void
    {
        $observations = $this->investigation('mining')->observations;
        $miner = $observations['ships'][0];
        $probe = $miner;
        $probe['symbol'] = 'LOCAL-0';
        $probe['mounts'] = [];
        $observations['ships'] = [$probe, $miner];
        $site = $observations['waypoints']['X1-TEST-ROCK'];
        $observations['waypoints'] = array_filter($observations['waypoints'], fn ($point) => $point['symbol'] !== $site['symbol']);
        for ($i = 0; $i < 205; $i++) {
            $far = $site;
            $far['symbol'] = 'X1-TEST-FAR'.$i;
            $far['x'] = 1000 + $i;
            $observations['waypoints'][$far['symbol']] = $far;
        }
        $observations['waypoints'][$site['symbol']] = $site;
        $observations['markets'] = array_reverse($observations['markets'], true);
        $candidates = app(OpportunityEvaluator::class)->evaluate($observations, 'mining', CarbonImmutable::now());
        $this->assertCount(200, $candidates);
        $this->assertSame('ready', $candidates[0]['classification']);
        $this->assertSame('LOCAL-1', $candidates[0]['ship_symbol']);
        $this->assertSame('X1-TEST-ROCK', $candidates[0]['site']);
        $this->assertSame('X1-TEST-ROCK', $candidates[0]['market']);
    }

    public function test_partial_search_keeps_real_equipment_and_fuel_blockers(): void
    {
        config(['gameplay.max_observations' => 1]);
        $this->world(function (&$world) {
            $world['ships'][0]['fuel']['current'] = 0;
            $probe = $world['ships'][0];
            $probe['symbol'] = 'LOCAL-2';
            $probe['mounts'] = [];
            $world['ships'][] = $probe;
        });
        $investigator = app(OpportunityInvestigator::class);
        $record = $investigator->create($this->agent, 'mining', 'X1-TEST');
        for ($i = 0; $i < 10 && $record->status === 'investigating'; $i++) {
            $record = $investigator->advance($this->agent->fresh(), $record);
        }
        $this->assertSame('partial', $record->status);
        $this->assertFalse(collect($record->candidates)->contains('classification', 'ready'));
        $this->assertSame(['needs_preparation'], array_values(array_unique(array_column($record->candidates, 'classification'))));
        $this->assertContains('Missing mining laser.', array_merge(...array_column($record->candidates, 'reasons')));
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_evaluation_is_deterministic_and_unknown_yield_has_no_estimate(): void
    {
        $record = $this->investigation('mining');
        $evaluator = app(OpportunityEvaluator::class);
        $now = CarbonImmutable::now();
        $first = $evaluator->evaluate($record->observations, 'mining', $now);
        $this->assertSame($first, $evaluator->evaluate($record->observations, 'mining', $now));
        $ready = collect($first)->firstWhere('classification', 'ready');
        $this->assertTrue($ready['trial_required']);
        $this->assertNull($ready['estimated_net']);
        $this->assertNull($ready['estimated_per_hour']);
        $this->assertSame(0, $ready['yield_history']['sample_count']);
    }

    public function test_missing_prices_and_intersystem_routes_cannot_be_recommended(): void
    {
        $this->world(function (&$w) {
            $w['goods'] = [];
            $w['contracts'][0]['terms']['deliver'][0]['destinationSymbol'] = 'X9-OTHER-PORT';
        });
        $record = $this->investigation();
        $this->assertFalse(collect($record->candidates)->contains('classification', 'ready'));
        $this->assertFalse(collect($record->candidates)->contains('recommended', true));
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_contract_estimates_exclude_paid_reward_and_delivered_units(): void
    {
        $w = $this->world();
        $contract = $w['contracts'][0];
        $contract['accepted'] = true;
        $contract['terms']['deliver'][0]['unitsFulfilled'] = 4;
        $plan = app(ContractPlanner::class)->plan($w['ships'][0], $contract, array_column($w['waypoints'], null, 'symbol'), ['X1-TEST-ROCK' => ['tradeGoods' => $w['goods']]], 'X1-TEST-ROCK', false, CarbonImmutable::now());
        $this->assertSame(1500, $plan['future_receipts']);
        $this->assertSame(100, $plan['already_paid_acceptance']);
        $this->assertSame(180, $plan['purchase_cost']);
        $this->assertSame(2, $plan['purchase_batches']);
        $this->assertFalse(collect($plan['actions'])->contains('command', 'contract_accept'));
    }

    public function test_contract_completes_in_batches_and_accounts_each_confirmation_once(): void
    {
        $run = $this->preview();
        $this->assertSame([], $this->world()['writes']);
        $runner = app(AutomationRunner::class);
        $runner->start($this->agent, $run, true);
        $runner->start($this->agent, $run, true);
        $this->drain($run);
        $run->refresh();
        $this->assertSame(RunState::Completed, $run->state, $run->blocked_reason ?? '');
        $this->assertSame(1300, $run->totals['cash_contribution']);
        $this->assertSame(1600, $run->totals['receipts']);
        $this->assertSame(300, $run->totals['acquisition_cost']);
        $this->assertSame(3, count(array_filter($this->world()['writes'], fn ($w) => str_ends_with($w['path'], '/purchase'))));
        $this->assertTrue($this->world()['contracts'][0]['fulfilled']);
        $before = count($this->world()['writes']);
        foreach (ShipAction::all() as $action) {
            app(ShipCommandExecutor::class)->execute($action->id);
        }
        $this->assertSame($before, count($this->world()['writes']));
        $this->assertSame(ShipAction::where('state', 'confirmed')->count(), DB::table('confirmed_credit_entries')->count());
        $this->assertSame(1300, app(CreditAccounting::class)->summary($this->agent->fresh())['total_balance_change']);
        $this->assertSame(0, app(CreditAccounting::class)->summary($this->agent->fresh())['unexplained_change']);
    }

    public function test_acceptance_requires_explicit_acknowledgement(): void
    {
        $run = $this->preview();
        $this->expectExceptionMessage('Explicitly acknowledge');
        try {
            app(AutomationRunner::class)->start($this->agent, $run);
        } finally {
            $this->assertSame([], $this->world()['writes']);
        }
    }

    public function test_existing_cargo_is_explicit_and_unrelated_cargo_is_protected(): void
    {
        $this->world(function (&$w) {
            $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 12, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 10], ['symbol' => 'GOLD', 'units' => 2]]];
        });
        $run = $this->preview('contracts', [], fn ($c) => $c['classification'] === 'ready' && $c['use_existing_cargo'] && $c['market'] === 'X1-TEST-ROCK');
        $this->assertSame(['IRON_ORE' => 10], $run->plan['contract_plan']['approved_existing_cargo']);
        $this->assertSame(250, $run->plan['contract_plan']['direct_sale_alternative']);
        app(AutomationRunner::class)->start($this->agent, $run, true);
        $this->drain($run);
        $this->assertSame(RunState::Completed, $run->fresh()->state, $run->fresh()->blocked_reason ?? '');
        $this->assertSame([['symbol' => 'GOLD', 'units' => 2, 'name' => 'GOLD', 'description' => 'Synthetic cargo']], $this->world()['ships'][0]['cargo']['inventory']);
        $this->assertFalse(collect($this->world()['writes'])->contains(fn ($w) => str_ends_with($w['path'], '/sell') || str_ends_with($w['path'], '/purchase')));
    }

    public function test_versioned_mining_keeps_one_extraction_and_complete_flow(): void
    {
        $run = $this->preview('mining');
        app(AutomationRunner::class)->start($this->agent, $run);
        $this->drain($run);
        $this->assertSame(RunState::Completed, $run->fresh()->state, $run->fresh()->blocked_reason ?? '');
        $this->assertCount(1, array_filter($this->world()['writes'], fn ($w) => str_ends_with($w['path'], '/extract')));
        $this->assertSame(240, $run->fresh()->totals['last_cycle_contribution']);
    }

    public static function materialChanges(): array
    {
        return array_map(fn ($name) => [$name], ['cargo', 'credits', 'fuel', 'equipment', 'price', 'terms', 'revision', 'expiry', 'session']);
    }

    #[DataProvider('materialChanges')]
    public function test_material_changes_cannot_execute_an_old_preview(string $change): void
    {
        $run = $this->preview();
        $this->world(function (&$w) use ($change) {
            match ($change) {
                'cargo' => $w['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 1, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 1]]],
                'credits' => $w['credits']++, 'fuel' => $w['ships'][0]['fuel']['current']--,
                'equipment' => $w['ships'][0]['engine']['condition'] = 0.9,
                'price' => $w['goods'][0]['purchasePrice']++, 'terms' => $w['contracts'][0]['terms']['payment']['onFulfilled']++, default => null,
            };
        });
        if ($change === 'revision') {
            AutomationDraft::whereKey($run->draft_id)->increment('revision');
        }
        if ($change === 'expiry') {
            $run->update(['expires_at' => now()->subSecond()]);
        }
        if ($change === 'session') {
            $this->engine();
        }
        $this->expectException(GameException::class);
        try {
            app(AutomationRunner::class)->start($this->agent, $run, true);
        } finally {
            $this->assertSame([], $this->world()['writes']);
        }
    }

    public static function uncertainCommands(): array
    {
        return [['contract_accept'], ['buy'], ['contract_deliver'], ['contract_fulfill']];
    }

    #[DataProvider('uncertainCommands')]
    public function test_lost_contract_responses_are_never_replayed(string $command): void
    {
        $run = $this->preview();
        app(AutomationRunner::class)->start($this->agent, $run, true);
        for ($i = 0; $i < 50; $i++) {
            $run->update(['next_action_at' => now()]);
            app(AutomationRunner::class)->advance($run->id);
            $action = ShipAction::where('automation_run_id', $run->id)->where('state', 'pending')->first();
            if (! $action) {
                continue;
            }
            if ($action->command === $command) {
                $this->world(function (&$w) {
                    $w['failure'] = 'timeout_after';
                });
                app(ShipCommandExecutor::class)->execute($action->id);
                $this->assertSame('uncertain', $action->fresh()->state->value);
                $before = count($this->world()['writes']);
                app(ShipCommandExecutor::class)->execute($action->id);
                $this->assertSame($before, count($this->world()['writes']));
                app(ShipCommandExecutor::class)->reconcile($this->agent, $action);
                $this->assertArrayHasKey('contract', $action->fresh()->outcome);
                $this->assertSame('uncertain', $action->fresh()->state->value);

                return;
            }
            app(ShipCommandExecutor::class)->execute($action->id);
        }
        $this->fail('Never reached command '.$command.'; '.$run->fresh()->blocked_reason);
    }

    public function test_stop_cancels_pending_reservation_and_engine_restart_cancels_all_unsent_approvals(): void
    {
        $run = $this->preview();
        app(AutomationRunner::class)->start($this->agent, $run, true);
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('state', 'pending')->firstOrFail();
        app(AutomationRunner::class)->stop($run);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('rejected', $action->fresh()->state->value);
        $this->assertSame([], $this->world()['writes']);
        app(AutomationRunner::class)->advance($run->id);
        $this->assertNull($run->fresh()->active_agent_id);
        $this->assertSame(0, $run->fresh()->totals['cash_contribution']);
        $this->assertIsInt($run->fresh()->totals['elapsed_seconds']);
        $this->assertSame(100, $run->fresh()->totals['ending_fuel']);
        $manual = app(ShipCommandExecutor::class)->preview($this->agent, 'LOCAL-1', 'orbit', [], (string) Str::uuid());
        app(ShipCommandExecutor::class)->approve($this->agent, $manual);
        app(EngineGate::class)->begin();
        $this->assertSame('rejected', $manual->fresh()->state->value);
        app(ShipCommandExecutor::class)->execute($manual->id);
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_other_owners_and_guests_cannot_access_new_records_or_controls(): void
    {
        $draft = $this->draft();
        $other = User::factory()->create();
        $this->actingAs($other);
        foreach (['investigations', 'investigations/1/advance', 'investigations/1/cancel', 'drafts', 'drafts/'.$draft->id.'/preview', 'drafts/'.$draft->id.'/update', 'engine/start', 'engine/end', 'engine/force-stop'] as $path) {
            $this->postJson('/agents/'.$this->agent->id.'/'.$path)->assertNotFound();
        }
        auth()->logout();
        $this->postJson('/agents/'.$this->agent->id.'/engine/start')->assertUnauthorized();
    }

    public function test_engine_controls_reject_non_operator_and_browser_supplied_process_commands(): void
    {
        config(['gameplay.operator_id' => 99999]);
        $this->postJson('/agents/'.$this->agent->id.'/engine/start', ['command' => 'touch /tmp/no', 'pid' => getmypid()])->assertForbidden();
        $this->postJson('/agents/'.$this->agent->id.'/engine/force-stop')->assertUnprocessable();
        $this->assertTrue(posix_kill(getmypid(), 0));
    }

    public function test_staged_routes_validate_integer_limits_and_revision(): void
    {
        $investigation = $this->investigation();
        $this->postJson('/agents/'.$this->agent->id.'/drafts', ['investigation_id' => $investigation->id, 'candidate_id' => $investigation->candidates[0]['id'], 'limits' => ['reserve' => -1, 'max_spend' => 1.5]])->assertUnprocessable()->assertJsonValidationErrors(['limits.reserve', 'limits.max_spend']);
        $draft = $this->draft();
        $this->postJson('/agents/'.$this->agent->id.'/drafts/'.$draft->id.'/preview', ['revision' => 99])->assertUnprocessable()->assertJsonPath('message', 'Draft revision changed. Reload before previewing.');
    }

    public function test_balance_reviews_keep_the_first_observation_and_never_invent_operating_receipts(): void
    {
        $this->world(function (&$w) {
            $w['credits'] += 123;
        });
        app(AgentSynchronizer::class)->sync($this->agent, false);
        $this->assertSame(123, app(CreditAccounting::class)->summary($this->agent)['unexplained_change']);
        $this->postJson('/agents/'.$this->agent->id.'/accounting/review', ['observed_balance' => 100123, 'starting_balance' => 90000, 'note' => 'Observed external test adjustment.', 'acknowledge' => true])->assertAccepted();
        $summary = app(CreditAccounting::class)->summary($this->agent->fresh());
        $this->assertSame(100000, $summary['baseline']['credits']);
        $this->assertSame(90000, $summary['baseline']['user_starting_credits']);
        $this->assertSame(10123, $summary['total_balance_change']);
        $this->assertSame(0, $summary['unexplained_change']);
        $this->assertSame(0, $summary['confirmed_change']);
        $this->assertSame(123, $summary['reviewed_adjustments']);
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_balance_or_contract_changes_between_dispatch_and_reservation_block_the_write(): void
    {
        $run = $this->preview();
        app(AutomationRunner::class)->start($this->agent, $run, true);
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('state', 'pending')->firstOrFail();
        $this->world(function (&$w) {
            $w['credits']++;
        });
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('rejected', $action->fresh()->state->value);
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_a_loss_making_mining_cycle_stops_even_when_cumulative_contribution_is_positive(): void
    {
        $this->world(function (&$w) {
            $w['ships'][] = FakeSpaceTraders::ship('LOCAL-2', 'X1-TEST-PORT');
        });
        $run = $this->preview('mining', ['cycles' => 3], fn ($c) => $c['classification'] === 'ready' && $c['ship_symbol'] === 'LOCAL-1' && $c['market'] === 'X1-TEST-PORT');
        app(AutomationRunner::class)->start($this->agent, $run);
        for ($i = 0; $i < 100 && $run->fresh()->active_agent_id; $i++) {
            $run->update(['next_action_at' => now()]);
            app(AutomationRunner::class)->advance($run->id);
            foreach (ShipAction::where('state', 'pending')->get() as $action) {
                app(ShipCommandExecutor::class)->execute($action->id);
            }
            if (($run->fresh()->totals['cycles'] ?? 0) >= 1) {
                $this->world(function (&$w) {
                    $w['yields'] = [['symbol' => 'IRON_ORE', 'units' => 1]];
                    $w['goods'][0]['sellPrice'] = 1;
                });
            }
        }
        $this->assertSame(2, $run->fresh()->totals['cycles']);
        $this->assertGreaterThan(0, $run->fresh()->totals['cash_contribution']);
        $this->assertLessThanOrEqual(0, $run->fresh()->totals['last_cycle_contribution']);
        $this->assertNull($run->fresh()->active_agent_id);
    }

    public function test_deadlines_and_unbounded_or_unpriced_contracts_are_blocked(): void
    {
        $this->world(function (&$w) {
            $w['contracts'][0]['terms']['deadline'] = now()->addSeconds(60)->toIso8601String();
        });
        $record = $this->investigation();
        $this->assertFalse(collect($record->candidates)->contains('classification', 'ready'));
        $this->assertTrue(collect($record->candidates)->contains(fn ($c) => str_contains(implode(' ', $c['reasons']), 'deadline')));
    }

    public function test_a_fresh_preview_cannot_expand_approved_limits_or_cross_a_sleep_gap(): void
    {
        $draft = $this->draft('contracts', ['purchase_limit' => 1]);
        try {
            app(AutomationRunner::class)->previewDraft($this->agent, $draft);
            $this->fail('Expected insufficient batch limit');
        } catch (GameException $e) {
            $this->assertStringContainsString('bounds', $e->getMessage());
        }
        $run = $this->preview();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()->subSeconds(16)]);
        $this->expectException(GameException::class);
        try {
            app(AutomationRunner::class)->start($this->agent, $run, true);
        } finally {
            $this->assertSame([], $this->world()['writes']);
        }
    }

    public function test_dead_processes_close_permission_even_with_a_fresh_heartbeat(): void
    {
        $run = $this->preview();
        app(AutomationRunner::class)->start($this->agent, $run, true);
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('state', 'pending')->firstOrFail();
        LocalEngine::whereKey(1)->update(['processes' => json_encode(['supervisor' => 2147483647, 'worker' => getmypid(), 'scheduler' => getmypid()])]);
        app(ShipCommandExecutor::class)->execute($action->id);
        $this->assertSame('rejected', $action->fresh()->state->value);
        $this->assertFalse(LocalEngine::find(1)->gate_open);
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_elapsed_bounds_and_stale_observations_never_produce_a_ready_recommendation(): void
    {
        $investigator = app(OpportunityInvestigator::class);
        $record = $investigator->create($this->agent, 'contracts', 'X1-TEST');
        $record->update(['created_at' => now()->subSeconds(46)]);
        $before = count($this->world()['calls']);
        $investigator->advance($this->agent, $record);
        $this->assertSame('partial', $record->fresh()->status);
        $this->assertSame($before, count($this->world()['calls']));
        $complete = $this->investigation();
        $observations = $complete->observations;
        $observations['observed_at'] = now()->subMinutes(4)->toIso8601String();
        $candidates = app(OpportunityEvaluator::class)->evaluate($observations, 'contracts', CarbonImmutable::now());
        $this->assertFalse(collect($candidates)->contains('classification', 'ready'));
        $this->assertFalse(collect($candidates)->contains('recommended', true));
    }

    public function test_ranking_uses_unpaid_receipts_and_known_inputs_and_keeps_blocked_candidates(): void
    {
        $this->world(function (&$w) {
            $better = FakeSpaceTraders::contract('better-contract');
            $better['accepted'] = true;
            $better['terms']['payment']['onAccepted'] = 999999;
            $better['terms']['payment']['onFulfilled'] = 2000;
            $w['contracts'][] = $better;
        });
        $record = $this->investigation();
        $ranked = $record->candidates;
        $this->assertSame('better-contract', $ranked[0]['contract_id']);
        $this->assertSame(2000, $ranked[0]['contract_plan']['future_receipts']);
        $this->assertTrue($ranked[0]['recommended']);
        $this->assertTrue(collect($ranked)->contains(fn ($c) => $c['classification'] !== 'ready'));
    }

    public function test_pending_contract_steps_reject_external_location_and_fuel_changes_before_send(): void
    {
        $run = $this->preview();
        app(AutomationRunner::class)->start($this->agent, $run, true);
        app(AutomationRunner::class)->advance($run->id);
        $pending = ShipAction::where('state', 'pending')->firstOrFail();
        $this->world(function (&$w) {
            $w['ships'][0]['nav']['waypointSymbol'] = 'X1-TEST-PORT';
            $w['ships'][0]['fuel']['current'] -= 5;
        });
        app(ShipCommandExecutor::class)->execute($pending->id);
        $this->assertSame('rejected', $pending->fresh()->state->value);
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_new_responses_and_jobs_do_not_contain_credentials_or_process_identifiers(): void
    {
        $draft = $this->draft();
        $response = $this->postJson('/agents/'.$this->agent->id.'/drafts/'.$draft->id.'/preview', ['revision' => 1])->assertAccepted();
        $this->assertStringNotContainsString('synthetic-only', $response->getContent());
        $this->assertStringNotContainsString(config('app.key'), $response->getContent());
        $dashboard = $this->get('/agents/'.$this->agent->id, ['X-Inertia' => 'true', 'X-Inertia-Version' => app(ResponseFactory::class)->getVersion()])->assertOk();
        $this->assertStringNotContainsString('synthetic-only', $dashboard->getContent());
        $this->assertStringNotContainsString(config('app.key'), $dashboard->getContent());
        $this->assertArrayNotHasKey('processes', $dashboard->json('props.engine'));
    }

    public function test_engine_endpoint_denies_a_wrong_host_or_non_loopback_client_even_for_operator(): void
    {
        $this->withHeader('Sec-Fetch-Site', 'same-origin');
        $this->app->detectEnvironment(fn () => 'local');
        config(['gameplay.operator_id' => $this->agent->user_id, 'gameplay.local_host' => 'spacetraders.test']);
        $this->postJson('http://wrong.test/agents/'.$this->agent->id.'/engine/end')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->postJson('http://spacetraders.test/agents/'.$this->agent->id.'/engine/end')->assertForbidden();
        $this->assertTrue(LocalEngine::find(1)->gate_open);
    }

    public function test_same_owner_cannot_reuse_another_agents_or_resets_draft_and_investigation(): void
    {
        $draft = $this->draft();
        $sibling = $this->agent->user->agents()->create(['name' => 'OTHER', 'token' => 'synthetic-other', 'account_id' => 'synthetic', 'faction' => 'COSMIC', 'reset_id' => $this->agent->reset_id, 'canonical_identity' => AgentSynchronizer::identity($this->agent->reset_id, 'OTHER')]);
        foreach (['investigations/'.$draft->investigation_id.'/advance', 'investigations/'.$draft->investigation_id.'/cancel'] as $path) {
            $this->postJson('/agents/'.$sibling->id.'/'.$path)->assertNotFound();
        }
        $this->postJson('/agents/'.$sibling->id.'/drafts/'.$draft->id.'/preview', ['revision' => 1])->assertNotFound();
        $this->agent->update(['reset_id' => 'another-world']);
        $this->postJson('/agents/'.$this->agent->id.'/drafts/'.$draft->id.'/preview', ['revision' => 1])->assertNotFound();
        $this->postJson('/agents/'.$this->agent->id.'/investigations/'.$draft->investigation_id.'/advance')->assertNotFound();
    }

    public function test_manual_contract_preview_needs_approval_and_rejects_arbitrary_payloads(): void
    {
        $input = ['key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'command' => 'contract_accept', 'arguments' => ['contractId' => 'contract-1']];
        $this->postJson('/agents/'.$this->agent->id.'/commands/preview', $input)->assertAccepted()->assertJsonPath('state', 'preview');
        $before = count($this->world()['calls']);
        $input['arguments']['url'] = 'https://example.test/unapproved';
        $this->postJson('/agents/'.$this->agent->id.'/commands/preview', $input)->assertUnprocessable();
        $this->assertSame($before, count($this->world()['calls']));
        $this->assertSame([], $this->world()['writes']);
    }

    public function test_delivery_extra_fields_cannot_invent_a_payment_or_change_fuel(): void
    {
        $this->world(function (&$w) {
            $w['extra_delivery_payment'] = true;
        });
        $run = $this->preview();
        app(AutomationRunner::class)->start($this->agent, $run, true);
        $this->drain($run);
        $this->assertSame(RunState::Completed, $run->fresh()->state, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(1600, $run->fresh()->totals['receipts']);
        $this->assertSame(1300, $run->fresh()->totals['cash_contribution']);
        $delivery = ShipAction::where('command', 'contract_deliver')->firstOrFail();
        $this->assertSame(0, $delivery->outcome['credit_delta']);
        $this->assertSame(0, $delivery->outcome['transaction_total']);
    }

    public function test_accepted_partial_contract_completes_multiple_trips_and_delivery_terms(): void
    {
        $this->world(function (&$w) {
            $w['contracts'][0]['accepted'] = true;
            $w['contracts'][0]['terms']['deliver'][0]['unitsRequired'] = 50;
            $w['contracts'][0]['terms']['deliver'][0]['unitsFulfilled'] = 5;
            $w['contracts'][0]['terms']['deliver'][] = ['tradeSymbol' => 'COPPER_ORE', 'destinationSymbol' => 'X1-TEST-ROCK', 'unitsRequired' => 8, 'unitsFulfilled' => 2];
        });
        $run = $this->preview('contracts', ['max_spend' => 2000, 'max_loss' => 2000]);
        $this->assertSame(1500, $run->plan['contract_plan']['future_receipts']);
        $this->assertSame(3, $run->plan['contract_plan']['deliveries']);
        app(AutomationRunner::class)->start($this->agent, $run);
        $this->drain($run);
        $this->assertSame(RunState::Completed, $run->fresh()->state, $run->fresh()->blocked_reason ?? '');
        $this->assertSame(1500, $run->fresh()->totals['receipts']);
        $this->assertSame(1560, $run->fresh()->totals['acquisition_cost']);
        $this->assertSame(-60, $run->fresh()->totals['cash_contribution']);
        $this->assertSame(3, $run->fresh()->totals['deliveries']);
        $this->assertSame(0, $this->world()['ships'][0]['cargo']['units']);
        $this->assertFalse(collect($this->world()['writes'])->contains(fn ($w) => str_ends_with($w['path'], '/accept')));
        $this->assertSame([50, 8], array_column($this->world()['contracts'][0]['terms']['deliver'], 'unitsFulfilled'));
    }
}
