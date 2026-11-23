<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\Bridge\BridgeActions;
use App\Gameplay\Bridge\BridgeDrain;
use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignDirector;
use App\Gameplay\CampaignHealth;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\EngineGate;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Gameplay\SpaceTradersApi;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\ShipAction;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class CampaignBridgeActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private const RESET = '2026-11-22';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(12, 0));
        Http::preventStrayRequests();
        $this->operator = User::factory()->create();
        config(['gameplay.operator_id' => $this->operator->id]);
        $this->actingAs($this->operator);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_bridge_records_authority_changes_in_the_log_on_reset_day(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 22)->setTime(12, 0));
        [$primary, $agent] = $this->campaign();
        [$arm] = $this->arm($primary, $agent);

        $this->submit($agent, $this->review($agent, 'fleet_pause'))->assertSessionHasNoErrors();
        $this->submit($agent, $this->review($agent, 'primary_resume'))->assertSessionHasNoErrors();
        app(CampaignControl::class)->resumeArm($arm);
        app(CampaignControl::class)->pause($arm, 'arm_loss_stop');
        $this->submit($agent, $this->review($agent, 'arm_retire', $arm))->assertSessionHasNoErrors();

        foreach (['pause', 'resume', 'revoked'] as $kind) {
            $this->assertDatabaseHas('campaign_events', ['kind' => $kind, 'data->reset_id' => self::RESET]);
        }
        $this->get('/campaign/bridge/log?agent='.$agent->name)->assertOk()->assertInertia(fn ($page) => $page
            ->where('log.rows', fn ($rows) => collect($rows)->pluck('kind')->intersect(['pause', 'resume', 'revoked'])->unique()->count() === 3));
    }

    public function test_arm_resume_resets_review_state_and_preserves_other_arms(): void
    {
        [$primary, $agent] = $this->campaign();
        [$arm, $episode] = $this->arm($primary, $agent);
        [$other] = $this->arm($primary, $agent, 'R2');
        $episode->update(['state->pending_pause' => 'consecutive_losing_loads']);
        app(CampaignControl::class)->pause($arm, 'consecutive_losing_loads');
        app(CampaignControl::class)->pause($other, 'operator_pause');
        DB::table('game_runtime')->insert(['key' => 'arm_director_failures:'.$arm->id, 'value' => '4']);
        $review = $this->review($agent, 'arm_resume', $arm);
        $this->assertSame([], $review['blockers']);
        $this->submit($agent, $review)->assertSessionHasNoErrors()->assertRedirect(route('campaign.bridge.brief', ['agent' => $agent->name]));
        $this->assertNull($arm->fresh()->paused_at);
        $this->assertNotNull($other->fresh()->paused_at);
        $this->assertArrayNotHasKey('pending_pause', $episode->fresh()->state);
        $this->assertSame(now()->toIso8601String(), $episode->fresh()->state['losses_reviewed_at']);
        $this->assertSame('0', DB::table('game_runtime')->where('key', 'arm_director_failures:'.$arm->id)->value('value'));
        $this->assertDatabaseHas('campaign_events', ['campaign_grant_id' => $arm->id, 'kind' => 'resume']);
    }

    public function test_arm_resume_review_shows_the_latest_stored_director_error(): void
    {
        [$primary, $agent] = $this->campaign();
        [$arm] = $this->arm($primary, $agent);
        $control = app(CampaignControl::class);
        $control->event($arm, null, 'director_error', ['message' => 'Earlier failure.', 'consecutive' => 4]);
        $control->event($arm, null, 'director_error', ['message' => 'No usable route remained.', 'consecutive' => 5]);
        $control->pause($arm, 'director_error');
        $control->event($primary, null, 'director_error', ['message' => 'Main campaign failure.', 'consecutive' => 5]);

        $review = $this->reviewFromPage($agent, 'arm_resume', $arm);
        $facts = array_column($review['facts'], 'text');
        $this->assertContains('No usable route remained.', $facts);
        $this->assertContains('The director reported 5 consecutive failures.', $facts);
        $this->assertNotContains('Earlier failure.', $facts);
        $this->assertNotContains('Main campaign failure.', $facts);
    }

    public function test_over_cap_result_harvests_before_authority_transaction_and_survives_refusal(): void
    {
        [$primary, $agent] = $this->campaign();
        [$arm, $episode] = $this->arm($primary, $agent);
        app(CampaignControl::class)->pause($arm, 'consecutive_losing_loads');
        $episode->update(['state->pending_pause' => 'consecutive_losing_loads']);
        $run = $this->makeRun($episode, 'completed');
        $run->update(['totals' => ['purchased' => ['IRON_ORE' => 1], 'sold' => ['IRON_ORE' => 1], 'purchase_cost' => 1000, 'sale_proceeds' => 500]]);
        $review = $this->review($agent, 'arm_resume', $arm);
        $this->assertSame([], $review['blockers']);
        $this->assertDatabaseMissing('campaign_events', ['campaign_episode_id' => $episode->id, 'kind' => 'load_result']);

        $probe = new class($episode->id, $agent->id, DB::transactionLevel()) extends HostLock
        {
            public bool $observed = false;

            public function __construct(private int $episodeId, private int $agentId, private int $initialLevel) {}

            public function run(string $key, callable $work): mixed
            {
                if ($key !== 'campaign-results-'.$this->episodeId) {
                    return parent::run($key, $work);
                }

                // A separate scorecard harvester uses results → SQLite. Bridge must enter
                // that lock before its own authority transaction, while both scope locks are held.
                CampaignBridgeActionsTest::assertSame($this->initialLevel, DB::transactionLevel());
                foreach (['campaign', 'agent-'.$this->agentId] as $scope) {
                    $lock = fopen(config('gameplay.lock_path').'/'.hash('sha256', $scope).'.lock', 'c');
                    CampaignBridgeActionsTest::assertFalse(flock($lock, LOCK_EX | LOCK_NB));
                    fclose($lock);
                }
                $this->observed = true;

                return parent::run($key, $work);
            }
        };
        $this->app->instance(HostLock::class, $probe);
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');
        $this->assertTrue($probe->observed);
        $this->assertSame('consecutive_losing_loads', $arm->fresh()->pause_reason);
        $this->assertDatabaseHas('campaign_events', ['campaign_episode_id' => $episode->id, 'kind' => 'load_result', 'data->net' => -500]);
        $this->assertSame([], $this->review($agent, 'arm_retire', $arm)['blockers']);
    }

    public function test_independent_scorecard_harvest_can_commit_while_resume_waits_for_its_results_lock(): void
    {
        $directory = config('gameplay.lock_path');
        mkdir($directory, 0700);
        $database = $directory.'/shared.sqlite';
        touch($database);
        $default = DB::getDefaultConnection();
        config(['database.connections.bridge_test' => ['database' => $database] + config('database.connections.sqlite')]);
        DB::setDefaultConnection('bridge_test');
        $holder = null;
        try {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'bridge_test', '--force' => true]));
            $this->operator = User::factory()->create();
            config(['gameplay.operator_id' => $this->operator->id]);
            $this->actingAs($this->operator);
            [$primary, $agent] = $this->campaign();
            [$arm, $episode] = $this->arm($primary, $agent);
            app(CampaignControl::class)->pause($arm, 'consecutive_losing_loads');
            $run = $this->makeRun($episode, 'completed');
            $run->update(['totals' => ['purchased' => ['IRON_ORE' => 1], 'sold' => ['IRON_ORE' => 1], 'purchase_cost' => 1000, 'sale_proceeds' => 500]]);
            $review = $this->review($agent, 'arm_resume', $arm);

            $key = 'campaign-results-'.$episode->id;
            $attempted = $directory.'/results-attempted';
            $holder = new Process([PHP_BINARY, '-r', <<<'PHP_CODE'
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->useStoragePath($argv[1]);
                $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['gameplay.lock_path' => $argv[1], 'database.connections.sqlite.busy_timeout' => 800]);
                $episode = \App\Models\CampaignEpisode::findOrFail($argv[2]);
                app(\App\Gameplay\HostLock::class)->scoped($argv[3], function () use ($episode, $argv) {
                    fwrite(STDOUT, "ready\n");
                    fflush(STDOUT);
                    $deadline = microtime(true) + 3;
                    while (! is_file($argv[4])) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Bridge did not attempt the results lock.');
                        }
                        usleep(10000);
                    }
                    app(\App\Gameplay\CampaignTrade::class)->recordResults($episode);
                });
                PHP_CODE, $directory, (string) $episode->id, $key, $attempted], base_path(), [
                'APP_ENV' => 'testing',
                'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $database,
                'DATABASE_URL' => '',
            ]);
            $this->app->instance(HostLock::class, new class($key, $attempted) extends HostLock
            {
                public function __construct(private string $resultsKey, private string $attempted) {}

                public function run(string $key, callable $work): mixed
                {
                    if ($key === $this->resultsKey) {
                        touch($this->attempted);
                    }

                    return parent::run($key, $work);
                }
            });
            $holder->start();
            $this->assertTrue($holder->waitUntil(fn ($type, $buffer) => str_contains($buffer, 'ready')));
            $this->submit($agent, $review)->assertSessionHasErrors(['bridge' => 'This arm hit its loss limit. Review and retire it.']);
            $this->assertSame(0, $holder->wait(), $holder->getErrorOutput());
            $this->assertDatabaseHas('campaign_events', ['campaign_episode_id' => $episode->id, 'kind' => 'load_result', 'data->net' => -500]);
            $this->assertNotNull($arm->fresh()->paused_at);
        } finally {
            $holder?->stop(0);
            DB::setDefaultConnection($default);
            DB::purge('bridge_test');
        }
    }

    public function test_phone_retire_refuses_a_running_or_operator_paused_arm_without_a_loss_stop(): void
    {
        [$primary, $agent] = $this->campaign();
        [$arm] = $this->arm($primary, $agent);
        foreach ([false, true] as $paused) {
            if ($paused) {
                app(CampaignControl::class)->pause($arm, 'operator_pause');
            }
            $this->assertNull($this->review($agent, 'arm_retire', $arm));
            $snapshot = (array) $this->review($agent, 'fleet_pause')['expected'];
            $this->submit($agent, [
                'action' => route('campaign.bridge.arm.retire', ['grant' => $arm->id], absolute: false),
                'intent' => null,
                'reset_id' => $agent->reset_id,
                'expected' => [$arm->id => $snapshot[$arm->id]],
            ])->assertSessionHasErrors([
                'bridge' => 'Phone retirement is available for an arm that hit its loss limit, stopped after repeated director failures, or expired.',
            ]);
            $this->assertNull($arm->fresh()->revoked_at);
        }
        $this->assertDatabaseMissing('campaign_events', ['campaign_grant_id' => $arm->id, 'kind' => 'revoked']);
    }

    public function test_retirement_checks_current_wipe_associations_without_an_open_episode_and_stops_sell_aboard(): void
    {
        [$primary, $agent] = $this->campaign();
        [$arm, $episode] = $this->arm($primary, $agent);
        app(CampaignControl::class)->drainPause($arm, 'host_rate_limits');
        app(CampaignControl::class)->pause($arm, 'director_error');
        $episode->update(['closed_at' => now()]);
        $run = $this->makeRun($episode, 'running', 'sell_aboard');
        $direct = $this->action($agent, $arm->arm()['ship'], 'pending');
        $associated = $this->action($agent, '', 'sending', $run);
        $review = $this->review($agent, 'arm_retire', $arm);
        $this->assertSame(['settling'], array_column($review['blockers'], 'code'));
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');
        $direct->update(['state' => 'confirmed']);
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');
        $associated->update(['state' => 'confirmed']);
        $this->submit($agent, $this->review($agent, 'arm_retire', $arm))->assertSessionHasNoErrors();
        $this->assertNotNull($arm->fresh()->revoked_at);
        $this->assertSame('stopping', $run->fresh()->state->value);
        $this->assertNotNull($run->fresh()->active_agent_id);
        $this->assertFalse(app(RequestBudget::class)->isDraining($agent->name));
    }

    public function test_fleet_resume_checks_interrupted_cargo_and_retained_previews_before_clearing_all_markers(): void
    {
        [$primary, $agent, $episode, $command] = $this->campaign();
        [$one, $oneEpisode] = $this->arm($primary, $agent);
        [$two, $twoEpisode] = $this->arm($primary, $agent, 'R2');
        $drain = new \ReflectionMethod(CampaignHealth::class, 'drainWithArms');
        $drain->invoke(app(CampaignHealth::class), $primary, 'host_rate_limits');
        $this->assertNull($primary->fresh()->paused_at);
        $this->assertSame('host_rate_limits', $twoEpisode->fresh()->state['draining']);
        app(CampaignControl::class)->pause($primary, 'recovery_stalled');
        $this->assertTrue(BridgeDrain::incomplete($primary->fresh(), $episode->fresh()));
        $queued = app(CampaignControl::class)->approve($primary->revision(), $this->operator);
        app(CampaignControl::class)->activatePending($this->operator->id, $agent->name);
        $this->assertSame(2, $queued->fresh()->slot);

        $command->update(['snapshot->cargo->units' => 8]);
        $review = $this->review($agent, 'fleet_resume');
        $this->assertCount(3, (array) $review['expected']);
        $this->assertStringContainsString('8 units of cargo', implode(' ', array_column($review['blockers'], 'message')));
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');
        $this->assertSame('recovery_stalled', $primary->fresh()->pause_reason);

        $command->update(['snapshot->cargo->units' => 0]);
        $preview = $this->makeRun($oneEpisode, 'preview', 'sell_aboard');
        $review = $this->review($agent, 'fleet_resume');
        $this->assertStringContainsString('Preview #'.$preview->id, implode(' ', array_column($review['blockers'], 'message')));
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');
        $this->assertNull($one->fresh()->paused_at);
        $this->assertTrue($one->fresh()->isDraining());
        $this->assertTrue(app(RequestBudget::class)->isDraining($agent->name));

        $preview->update(['state' => 'stopped']);
        $this->submit($agent, $this->review($agent, 'fleet_resume'))->assertSessionHasNoErrors();
        foreach ([[$primary, $episode], [$one, $oneEpisode], [$two, $twoEpisode]] as [$grant, $open]) {
            $this->assertNull($grant->fresh()->paused_at);
            $this->assertNull(BridgeDrain::marker($grant->fresh(), $open->fresh()));
            $this->assertTrue($grant->fresh()->available());
        }
        $this->assertFalse(app(RequestBudget::class)->isDraining($agent->name));
        app(CampaignControl::class)->activatePending($this->operator->id, $agent->name);
        $this->assertSame(1, $queued->fresh()->slot);
    }

    public function test_an_unpinned_interrupted_drain_requires_command_cargo_evidence_even_with_an_empty_probe(): void
    {
        [$primary, $agent, $episode, $command] = $this->campaign();
        $probe = $this->ship($agent, $agent->name.'-3', 'SATELLITE');
        $probe->update(['snapshot->frame->symbol' => 'FRAME_PROBE']);
        $episode->update(['state' => ['role_ships' => ['probe' => $probe->symbol]]]);
        app(CampaignControl::class)->drainPause($primary, 'host_rate_limits', $episode);
        $command->delete();
        $this->enableDirector();
        $this->assertTrue(app(CampaignDirector::class)->tick());
        $this->assertNull($primary->fresh()->paused_at);
        $this->assertSame('host_rate_limits', $episode->fresh()->state['draining']);
        $this->assertDatabaseHas('campaign_events', ['campaign_grant_id' => $primary->id, 'data->choice' => 'awaiting_cargo_snapshot']);
        app(CampaignControl::class)->pause($primary, 'recovery_stalled');

        $review = $this->review($agent, 'fleet_resume');
        $this->assertStringContainsString('cargo snapshot for the command ship', implode(' ', array_column($review['blockers'], 'message')));
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');
        $this->assertSame('recovery_stalled', $primary->fresh()->pause_reason);
        $this->assertTrue(app(RequestBudget::class)->isDraining($agent->name));

        $this->ship($agent, $command->symbol, 'COMMAND');
        $this->submit($agent, $this->review($agent, 'fleet_resume'))->assertSessionHasNoErrors();
        $this->assertFalse(app(RequestBudget::class)->isDraining($agent->name));
    }

    public function test_director_keeps_a_drain_open_when_the_pinned_legacy_probe_snapshot_is_missing(): void
    {
        [$primary, $agent, $episode] = $this->campaign();
        $probe = $this->ship($agent, $agent->name.'-3', 'SATELLITE');
        $probe->update(['snapshot->cargo->units' => 8]);
        $this->ship($agent, $agent->name.'-4', 'SATELLITE');
        $episode->update(['state->role_ships->probe' => $probe->symbol]);
        app(CampaignControl::class)->drainPause($primary, 'host_rate_limits', $episode);
        $probe->delete();
        $this->enableDirector();

        $this->assertTrue(app(CampaignDirector::class)->tick());
        $this->assertNull($primary->fresh()->paused_at);
        $this->assertSame($probe->symbol, $episode->fresh()->state['role_ships']['probe']);
        $this->assertDatabaseHas('campaign_events', ['campaign_grant_id' => $primary->id, 'data->choice' => 'awaiting_cargo_snapshot']);
        $review = $this->review($agent, 'fleet_resume');
        $this->assertContains('Confirm the cargo aboard '.$probe->symbol.'.', array_column($review['blockers'], 'message'));

        $this->ship($agent, $probe->symbol, 'SATELLITE');
        $this->travel(31)->seconds();
        $this->assertTrue(app(CampaignDirector::class)->tick());
        $this->assertSame('drain_pause:host_rate_limits', $primary->fresh()->pause_reason);
        $this->assertSame([], $this->review($agent, 'fleet_resume')['blockers']);
    }

    public function test_director_and_resume_wait_for_manual_commands_on_started_recovery_ships(): void
    {
        [$primary, $agent, $episode] = $this->campaign();
        $spare = $this->ship($agent, $agent->name.'-3', 'HAULER');
        $this->makeRun($episode, 'stopped')->update(['ship_symbol' => $spare->symbol]);
        app(CampaignControl::class)->drainPause($primary, 'host_rate_limits', $episode);
        $action = $this->action($agent, $spare->symbol, 'pending');
        $action->update(['origin' => 'manual']);
        $this->enableDirector();

        $this->assertTrue(app(CampaignDirector::class)->tick());
        $this->assertNull($primary->fresh()->paused_at);
        $this->assertSame('pending', $action->fresh()->state->value);
        $this->assertDatabaseHas('campaign_events', ['campaign_grant_id' => $primary->id, 'data->choice' => 'awaiting_action']);
        $review = $this->review($agent, 'fleet_resume');
        $this->assertContains('Wait for command #'.$action->id.' to settle.', array_column($review['blockers'], 'message'));

        $action->update(['state' => 'confirmed']);
        $this->travel(31)->seconds();
        $this->assertTrue(app(CampaignDirector::class)->tick());
        $this->assertSame('drain_pause:host_rate_limits', $primary->fresh()->pause_reason);
        $this->assertSame([], $this->review($agent, 'fleet_resume')['blockers']);
    }

    public function test_an_unreadable_budget_file_blocks_all_resumes_when_the_only_drain_marker_is_in_the_file(): void
    {
        [$primary, $agent] = $this->campaign();
        [$arm] = $this->arm($primary, $agent);
        $control = app(CampaignControl::class);
        $control->pause($primary, 'operator_pause');
        $control->pause($arm, 'operator_pause');
        config(['gameplay.request_budget_enabled' => true]);
        $budget = app(RequestBudget::class);
        $budget->drainAgent($agent->name, true);
        $path = config('gameplay.request_budget_path').'/budget.json';
        $saved = file_get_contents($path);
        file_put_contents($path, '{');

        try {
            foreach (['primary_resume', 'fleet_resume', 'arm_resume'] as $kind) {
                $review = $this->review($agent, $kind, $kind === 'arm_resume' ? $arm : null);
                $this->assertContains('drain_pressure', array_column($review['blockers'], 'code'));
                $this->submit($agent, $review)->assertSessionHasErrors([
                    'bridge' => 'Request budget hold status is unavailable. Reopen this review once it can be read.',
                ]);
            }
            unlink($path);
            $review = $this->review($agent, 'primary_resume');
            $this->assertContains('drain_pressure', array_column($review['blockers'], 'code'));
            $this->submit($agent, $review)->assertSessionHasErrors('bridge');
        } finally {
            file_put_contents($path, $saved);
        }
        $this->assertNotNull($primary->fresh()->paused_at);
        $this->assertNotNull($arm->fresh()->paused_at);
        $this->assertTrue($budget->isDraining($agent->name));
    }

    public function test_standalone_budget_hold_waits_for_any_unsettled_work_and_resumes_no_authority(): void
    {
        [$primary, $agent, $episode, $ship] = $this->campaign();
        app(RequestBudget::class)->drainAgent($agent->name, true);
        $run = $this->makeRun($episode, 'running', 'sell_aboard');
        $price = $this->action($agent, $ship->symbol, 'confirmed', $run, true);
        $runner = app(AutomationRunner::class);
        $runner->stop($run);
        $this->assertSame('stopping', $run->fresh()->state->value);
        $runner->advance($run->id);
        $this->assertSame('blocked', $run->fresh()->state->value);
        $this->assertNotNull($run->fresh()->active_agent_id);
        $this->assertNotNull($run->fresh()->stop_requested_at);

        $review = $this->review($agent, 'fleet_resume');
        $this->assertSame([], (array) $review['expected']);
        $this->assertStringContainsString('price review required', implode(' ', array_column($review['blockers'], 'message')));
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');
        $this->assertTrue(app(RequestBudget::class)->isDraining($agent->name));

        $price->update(['review_required' => false]);
        $runner->advance($run->id);
        $this->assertSame('stopped', $run->fresh()->state->value);
        $this->assertNull($run->fresh()->active_agent_id);
        $this->submit($agent, $this->review($agent, 'fleet_resume'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'No campaign grants changed. The budget hold cleared.');
        $this->assertFalse(app(RequestBudget::class)->isDraining($agent->name));
        $this->assertDatabaseMissing('campaign_events', ['campaign_grant_id' => $primary->id, 'kind' => 'resume']);
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');
    }

    public function test_a_fleet_resume_review_cannot_clear_a_replacement_standalone_budget_hold(): void
    {
        [$primary, $agent] = $this->campaign();
        $budget = app(RequestBudget::class);
        $budget->drainAgent($agent->name, true, $agent->reset_id);
        $review = $this->reviewFromPage($agent, 'fleet_resume');
        $this->assertSame([], (array) $review['expected']);
        $this->submit($agent, $review)->assertSessionHasNoErrors();
        $this->assertFalse($budget->isDraining($agent->name));

        $this->travel(10)->minutes();
        $budget->drainAgent($agent->name, true, $agent->reset_id);
        $this->submit($agent, $review)->assertSessionHasErrors([
            'bridge' => 'This changed since you opened the review. Review it again.',
        ]);
        $this->assertTrue($budget->isDraining($agent->name));

        $this->submit($agent, $this->reviewFromPage($agent, 'fleet_resume'))->assertSessionHasNoErrors();
        $this->assertFalse($budget->isDraining($agent->name));
        $this->assertDatabaseMissing('campaign_events', ['campaign_grant_id' => $primary->id, 'kind' => 'resume']);
    }

    #[DataProvider('existingBudgetHoldFormats')]
    public function test_a_fleet_resume_review_can_clear_an_existing_budget_hold_format(bool|array $hold): void
    {
        [, $agent] = $this->campaign();
        $budget = app(RequestBudget::class);
        $budget->drainAgent($agent->name, true, $agent->reset_id);
        $this->budgetState(['draining' => [hash('sha256', base_path()).':'.$agent->name => $hold]]);

        $this->submit($agent, $this->reviewFromPage($agent, 'fleet_resume'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'No campaign grants changed. The budget hold cleared.');
        $this->assertFalse($budget->isDraining($agent->name));
    }

    public static function existingBudgetHoldFormats(): array
    {
        return ['boolean flag' => [true], 'timestamped hold' => [['since' => 1000.5, 'reset_id' => self::RESET]]];
    }

    public function test_a_standalone_hold_replaced_between_review_validation_and_budget_write_stays_held(): void
    {
        [, $agent] = $this->campaign();
        $budget = app(RequestBudget::class);
        $budget->drainAgent($agent->name, true, $agent->reset_id);
        $review = $this->reviewFromPage($agent, 'fleet_resume');
        $mock = $this->partialMock(RequestBudget::class);
        $mock->shouldReceive('clearDrainHold')->once()->with($agent->name, $review['budget_hold_id'])
            ->andReturnUsing(function (string $symbol, string $reviewedId) use ($budget, $agent) {
                $budget->drainAgent($symbol, true, $agent->reset_id);

                return $budget->clearDrainHold($symbol, $reviewedId);
            });

        $this->submit($agent, $review)->assertSessionHasErrors([
            'bridge' => 'This changed since you opened the review. Review it again.',
        ]);
        $this->assertTrue($budget->isDraining($agent->name));
        $this->app->forgetInstance(RequestBudget::class);
        $this->submit($agent, $this->reviewFromPage($agent, 'fleet_resume'))->assertSessionHasNoErrors();
        $this->assertFalse($budget->isDraining($agent->name));
    }

    #[DataProvider('archiveMarkers')]
    public function test_archive_clears_standalone_and_episode_holds_but_preserves_grants_and_history(bool $episodeMarker): void
    {
        [$primary, $agent, $episode] = $this->campaign();
        if ($episodeMarker) {
            $episode->update(['state->draining' => 'host_rate_limits']);
        }
        $budget = app(RequestBudget::class);
        $budget->drainAgent($agent->name, true);
        $this->assertTrue(app(AgentSynchronizer::class)->archiveForReset($agent, '2026-11-29'));
        $this->assertFalse($budget->isDraining($agent->name));
        $this->assertSame(1, $primary->fresh()->slot);
        $this->assertSame($episodeMarker ? 'host_rate_limits' : null, $episode->fresh()->state['draining'] ?? null);
        $this->assertNull($this->review($agent, 'fleet_resume'));
        RequestBudget::forAgent($agent->name, fn () => $budget->reserve(priority: 'run', maxWait: 0));
    }

    #[DataProvider('replacementHoldCases')]
    public function test_archive_budget_reconciliation_retries_without_clearing_a_newer_hold(bool $replacementHold): void
    {
        [$primary, $agent] = $this->campaign();
        $budget = app(RequestBudget::class);
        $budget->drainAgent($agent->name, true);
        $synchronizer = app(AgentSynchronizer::class);
        $mock = $this->partialMock(RequestBudget::class);
        $mock->shouldReceive('clearDrainBefore')->once()->andThrow(new RequestDeferred('fixture write failed', 1));
        $this->assertTrue($synchronizer->archiveForReset($agent, '2026-11-29'));
        $this->assertTrue($agent->fresh()->isArchived());
        $this->assertTrue($budget->isDraining($agent->name));
        $this->assertSame(1, $primary->fresh()->slot);
        $key = 'archived_budget_hold:'.$agent->user_id.':'.$agent->id;
        $this->assertDatabaseHas('game_runtime', ['key' => $key]);
        $this->app->instance(RequestBudget::class, $budget);

        if ($replacementHold) {
            $this->operator->agents()->create([
                'name' => $agent->name, 'faction' => 'VOID', 'token' => 'new-fixture', 'reset_id' => '2026-11-29',
                'canonical_identity' => AgentSynchronizer::identity('2026-11-29', $agent->name), 'connection_state' => 'connected',
            ]);
            $budget->drainAgent($agent->name, true);
            $this->assertFalse($synchronizer->archiveForReset($agent, '2026-11-29'));
        } else {
            $this->get('/agents')->assertOk();
        }
        $this->assertSame($replacementHold, $budget->isDraining($agent->name));
        $this->assertDatabaseMissing('game_runtime', ['key' => $key]);
    }

    public function test_archiving_a_late_old_agent_preserves_a_hold_already_raised_for_the_current_reset(): void
    {
        [, $agent] = $this->campaign();
        $budget = app(RequestBudget::class);
        $budget->drainAgent($agent->name, true, '2026-11-29');

        $this->assertTrue(app(AgentSynchronizer::class)->archiveForReset($agent, '2026-11-29'));
        $this->assertTrue($budget->isDraining($agent->name));
    }

    public function test_archive_keeps_cleanup_intent_while_budget_state_is_missing(): void
    {
        [, $agent] = $this->campaign();
        config(['gameplay.request_budget_enabled' => true]);
        $budget = app(RequestBudget::class);
        $budget->drainAgent($agent->name, true, $agent->reset_id);
        $file = config('gameplay.request_budget_path').'/budget.json';
        rename($file, $file.'.fixture');

        try {
            $this->assertTrue(app(AgentSynchronizer::class)->archiveForReset($agent, '2026-11-29'));
            $key = 'archived_budget_hold:'.$agent->user_id.':'.$agent->id;
            $this->assertDatabaseHas('game_runtime', ['key' => $key]);
        } finally {
            rename($file.'.fixture', $file);
        }
        app(AgentSynchronizer::class)->retryArchivedDrains($agent->user_id);
        $this->assertFalse($budget->isDraining($agent->name));
        $this->assertDatabaseMissing('game_runtime', ['key' => $key]);
    }

    public function test_director_reconciles_the_archived_hold_before_requesting_an_uncached_reset_schedule(): void
    {
        [, $agent] = $this->campaign();
        config(['gameplay.request_budget_enabled' => true]);
        $budget = app(RequestBudget::class);
        $budget->drainAgent($agent->name, true, $agent->reset_id);
        $mock = $this->partialMock(RequestBudget::class);
        $mock->shouldReceive('clearDrainBefore')->once()->andThrow(new RequestDeferred('fixture write failed', 1));
        $this->assertTrue(app(AgentSynchronizer::class)->archiveForReset($agent, '2026-11-29'));
        $this->app->instance(RequestBudget::class, $budget);

        $this->enableDirector();
        $key = 'archived_budget_hold:'.$agent->user_id.':'.$agent->id;
        $this->partialMock(SpaceTradersApi::class)->shouldReceive('resetSchedule')->once()->andReturnUsing(function () use ($budget, $agent, $key) {
            $this->assertFalse($budget->isDraining($agent->name));
            $this->assertDatabaseMissing('game_runtime', ['key' => $key]);

            throw new RequestDeferred('Stop after the schedule boundary in this fixture.', 1);
        });
        $this->assertTrue(app(CampaignDirector::class)->tick());
        $this->assertFalse($budget->isDraining($agent->name));
    }

    #[DataProvider('fleetReviewQueryFixtures')]
    public function test_fleet_review_with_six_draining_arms_stays_within_the_get_query_cap(bool $pooled, int $probes, bool $priceReview, bool $blockedRun = false): void
    {
        $playbook = $pooled ? 'weekly-probe-bootstrap.yaml' : 'weekly-default.yaml';
        [$primary, $agent, $episode, $command] = $this->campaign(playbook: $playbook);
        $this->campaign('GIANTSOUP');
        config(['gameplay.request_budget_enabled' => true]);
        $control = app(CampaignControl::class);
        $control->drainPause($primary, 'host_rate_limits');
        for ($index = 1; $index <= 6; $index++) {
            [$arm] = $this->arm($primary, $agent, 'R'.$index);
            $control->drainPause($arm, 'host_rate_limits');
        }
        for ($index = 8; $index <= 14; $index++) {
            $this->ship($agent, $agent->name.'-'.$index, $index < 8 + $probes ? 'SATELLITE' : 'HAULER');
        }
        if ($blockedRun) {
            $this->makeRun($episode, 'blocked');
        }
        if ($priceReview) {
            $this->action($agent, $command->symbol, 'confirmed', review: true);
        }
        $url = '/campaign/bridge?agent='.$agent->name;
        $version = $this->get($url)->assertOk()->inertiaPage()['version'];
        DB::enableQueryLog();
        try {
            $response = $this->get($url.'&review=fleet_resume', [
                'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Data' => 'reviews',
                'X-Inertia-Partial-Component' => 'Campaign/Bridge/Brief',
            ])->assertOk();
            $queries = count(DB::getQueryLog());
            $response->assertJsonCount(7, 'props.reviews.fleet_resume.expected');
            $blockerCodes = array_column($response->json('props.reviews.fleet_resume.blockers'), 'code');
            $this->assertSame(array_filter([$priceReview ? 'uncertain_action' : null, $blockedRun ? 'settling' : null]), $blockerCodes);
            $this->assertLessThanOrEqual(40, $queries, "Fleet resume review ran $queries queries.");
        } finally {
            DB::disableQueryLog();
        }
    }

    public static function fleetReviewQueryFixtures(): array
    {
        return [
            'legacy with no probe' => [false, 0, false],
            'legacy with price review' => [false, 7, true],
            'legacy with price review and blocked run' => [false, 7, true, true],
            'three pooled probes' => [true, 3, false],
            'three pooled probes with price review' => [true, 3, true],
            'seven pooled probes' => [true, 7, false],
            'seven pooled probes with price review' => [true, 7, true],
            'seven pooled probes with price review and blocked run' => [true, 7, true, true],
        ];
    }

    public static function replacementHoldCases(): array
    {
        return ['retry the old hold' => [false], 'preserve a newer hold' => [true]];
    }

    public static function archiveMarkers(): array
    {
        return ['budget flag only' => [false], 'episode and budget flag' => [true]];
    }

    public function test_reset_boundary_wins_over_a_fresh_matching_cache_extension_for_resume_and_retire(): void
    {
        [$primary, $agent, $episode] = $this->campaign();
        [$arm] = $this->arm($primary, $agent);
        app(CampaignControl::class)->pause($primary, 'operator_pause');
        app(CampaignControl::class)->pause($arm, 'director_error');
        $resume = $this->review($agent, 'primary_resume');
        $retire = $this->review($agent, 'arm_retire', $arm);
        $this->travelTo($episode->next_reset_at);
        $this->cacheSchedule($agent->reset_id);
        $this->submit($agent, $resume)->assertSessionHasErrors(['bridge' => 'Waiting to confirm the current wipe. Reopen this review once it is confirmed.']);
        $this->submit($agent, $retire)->assertSessionHasErrors('bridge');
        $this->travel(1)->minute();
        $this->cacheSchedule($agent->reset_id);
        $this->submit($agent, $resume)->assertSessionHasErrors('bridge');
        $this->submit($agent, $retire)->assertSessionHasErrors('bridge');
        $this->assertNotNull($primary->fresh()->paused_at);
        $this->assertNull($arm->fresh()->revoked_at);
        $this->assertDatabaseMissing('campaign_events', ['campaign_grant_id' => $primary->id, 'kind' => 'resume']);
    }

    public function test_disagreeing_schedule_refuses_an_old_sheet_after_registration_and_accepts_a_fresh_one(): void
    {
        [$primary, $old] = $this->campaign();
        app(CampaignControl::class)->pause($primary, 'operator_pause');
        $review = $this->review($old, 'primary_resume');
        $reset = '2026-11-24';
        $this->cacheSchedule($reset);
        $this->submit($old, $review)->assertSessionHasErrors('bridge');
        app(AgentSynchronizer::class)->archiveForReset($old, $reset);
        $current = $this->operator->agents()->create([
            'name' => $old->name, 'faction' => 'VOID', 'token' => 'fresh-fixture', 'reset_id' => $reset,
            'canonical_identity' => AgentSynchronizer::identity($reset, $old->name), 'credits' => 1000, 'connection_state' => 'connected',
        ]);
        $this->submit($current, $review)->assertSessionHasErrors('bridge');
        $this->submit($current, $this->review($current, 'primary_resume'))->assertSessionHasNoErrors();
        $this->assertNull($primary->fresh()->paused_at);
    }

    public function test_an_expired_unpaused_arm_can_be_retired_after_the_reset_checks(): void
    {
        [$primary, $agent, $episode] = $this->campaign();
        [$arm, $armEpisode] = $this->arm($primary, $agent);
        $episode->update(['next_reset_at' => now()->addDays(3)]);
        $armEpisode->update(['next_reset_at' => now()->addDays(3)]);
        $this->travelTo($arm->expires_at);
        $this->assertNull($arm->fresh()->paused_at);
        $review = $this->review($agent, 'arm_retire', $arm);
        $this->assertSame([], $review['blockers']);
        $this->submit($agent, $review)->assertSessionHasNoErrors();
        $this->assertNotNull($arm->fresh()->revoked_at);
        $this->assertNotNull($armEpisode->fresh()->closed_at);
    }

    public function test_primary_resume_does_not_include_arm_and_a_changed_sheet_refuses(): void
    {
        [$primary, $agent] = $this->campaign();
        [$arm] = $this->arm($primary, $agent);
        app(CampaignControl::class)->pause($primary, 'operator_pause');
        app(CampaignControl::class)->pause($arm, 'operator_pause');
        $review = $this->review($agent, 'primary_resume');
        $extra = (array) $review['expected'] + [$arm->id => (array) ((array) $review['expected'])[$primary->id]];
        $this->submit($agent, $review, ['expected' => $extra])->assertSessionHasErrors('bridge');
        $this->submit($agent, $review, ['intent' => 'arm'])->assertSessionHasErrors('bridge');
        $primary->update(['pause_reason' => 'recovery_stalled']);
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');
        $this->submit($agent, $this->review($agent, 'primary_resume'))->assertSessionHasNoErrors();
        $this->assertNotNull($arm->fresh()->paused_at);
        $this->assertNull($primary->fresh()->paused_at);
    }

    public function test_a_resume_review_cannot_replay_against_a_new_pause_in_the_same_second(): void
    {
        [$primary, $agent] = $this->campaign();
        $control = app(CampaignControl::class);
        $control->pause($primary, 'operator_pause');
        $review = $this->reviewFromPage($agent, 'primary_resume');
        $this->assertSame(['text' => 'Main campaign. Paused by the operator', 'at' => $primary->fresh()->paused_at->toIso8601String()], $review['facts'][0]);
        $this->submit($agent, $review)->assertSessionHasNoErrors();

        $control->pause($primary, 'operator_pause');
        $newReview = $this->reviewFromPage($agent, 'primary_resume');
        $this->assertSame($review['expected'][$primary->id]['paused_at'], $newReview['expected'][$primary->id]['paused_at']);
        $this->submit($agent, $review)->assertSessionHasErrors([
            'bridge' => 'This changed since you opened the review. Review it again.',
        ]);
        $this->assertSame('operator_pause', $primary->fresh()->pause_reason);
        $this->assertSame(1, CampaignEvent::where('campaign_grant_id', $primary->id)->where('kind', 'resume')->count());

        $this->submit($agent, $newReview)->assertSessionHasNoErrors();
        $this->assertNull($primary->fresh()->paused_at);
    }

    public function test_fleet_review_uses_the_full_scope_even_with_a_grant_id_from_an_attention_card(): void
    {
        [$primary, $agent] = $this->campaign();
        [$arm] = $this->arm($primary, $agent);
        app(CampaignControl::class)->pause($primary, 'operator_pause');
        app(CampaignControl::class)->pause($arm, 'operator_pause');
        $review = app(BridgeActions::class)->reviews($this->operator->id, $agent->name, 'fleet_resume', $primary->id)['fleet_resume'];
        $this->assertSame([$primary->id, $arm->id], array_keys((array) $review['expected']));
        $this->submit($agent, $review)->assertSessionHasNoErrors();
        $this->assertNull($primary->fresh()->paused_at);
        $this->assertNull($arm->fresh()->paused_at);
        $resumes = CampaignEvent::where('kind', 'resume')->orderBy('id')->pluck('campaign_grant_id')->all();
        $this->assertSame([$primary->id, $arm->id], $resumes);
    }

    public function test_fleet_pause_waits_for_an_existing_agent_registration_to_settle(): void
    {
        [$primary, $agent] = $this->campaign();
        $agent->update(['reset_id' => null]);
        $review = $this->review($agent, 'fleet_pause');

        $this->assertSame(['reset_unconfirmed'], array_column($review['blockers'], 'code'));
        $this->submit($agent, $review)->assertSessionHasErrors([
            'bridge' => 'Wait for agent registration to settle, then reopen this review.',
        ]);
        $this->assertNull($primary->fresh()->paused_at);

        $agent->update(['connection_state' => 'reset']);
        $review = $this->review($agent, 'fleet_pause');
        $this->assertSame([], $review['blockers']);
        $this->submit($agent, $review)->assertSessionHasNoErrors();
        $this->assertSame('operator_pause', $primary->fresh()->pause_reason);
    }

    public function test_fleet_pause_updates_current_targets_and_continues_after_one_pausing_failure(): void
    {
        [$primary, $agent, $episode] = $this->campaign();
        [$other] = $this->campaign('OTHER');
        [$fail] = $this->arm($primary, $agent);
        $review = $this->review($agent, 'fleet_pause');
        [$joined] = $this->arm($primary, $agent, 'R2');
        $run = $this->makeRun($episode, 'running');
        $this->partialMock(CampaignControl::class)->shouldReceive('pause')->withArgs(fn ($grant) => $grant->id === $fail->id)
            ->once()->andThrow(new GameException('Synthetic refusal.'));

        $response = $this->submit($agent, $review)->assertSessionHasNoErrors();
        $flash = $response->getSession()->get('status');
        $this->assertStringContainsString('(R1) could not be paused', $flash);
        $this->assertStringContainsString('(R2) joined the fleet since review', $flash);
        $this->assertStringContainsString('Stop requested for 1 run', $flash);
        $this->assertSame('stopping', $run->fresh()->state->value);
        $this->assertSame('operator_pause', $primary->fresh()->pause_reason);
        $this->assertSame('operator_pause', $joined->fresh()->pause_reason);
        $this->assertNull($fail->fresh()->paused_at);
        $this->assertNull($other->fresh()->paused_at);
    }

    public function test_campaign_lock_contention_skips_both_directors_without_failures(): void
    {
        $this->campaign();
        (new HostLock)->run('campaign', function () {
            $this->assertFalse(app(CampaignDirector::class)->tick());
            $this->assertFalse(app(CampaignDirector::class)->tickArms());
        });
        $this->assertDatabaseMissing('campaign_events', ['kind' => 'director_error']);
        $this->assertSame(0, DB::table('game_runtime')->where('key', 'like', '%director_failures%')->count());
    }

    public function test_an_open_resume_sheet_cannot_replay_after_the_fifth_director_failure(): void
    {
        [$primary, $agent] = $this->campaign();
        [$arm] = $this->arm($primary, $agent);
        $control = app(CampaignControl::class);
        $control->pause($arm, 'operator_pause');
        $review = $this->review($agent, 'arm_resume', $arm);
        $control->resumeArm($arm);
        DB::table('game_runtime')->where('key', 'arm_director_failures:'.$arm->id)->update(['value' => '4']);
        config(['gameplay.enabled' => true]);
        $this->partialMock(EngineGate::class)->shouldReceive('enabled')->andReturn(true)->getMock()
            ->shouldReceive('session')->andReturn('fixture-engine');
        $this->partialMock(SpaceTradersApi::class)->shouldReceive('resetSchedule')->once()->andThrow(new \TypeError('fixture failure'));
        $this->assertTrue(app(CampaignDirector::class)->tickArms());
        $this->assertSame('director_error', $arm->fresh()->pause_reason);
        $this->submit($agent, $review)->assertSessionHasErrors(['bridge' => 'This changed since you opened the review. Review it again.']);
        $this->assertSame('director_error', $arm->fresh()->pause_reason);
        $this->assertDatabaseHas('campaign_events', ['campaign_grant_id' => $arm->id, 'kind' => 'director_error', 'data->consecutive' => 5]);
    }

    public function test_episode_only_drain_blocks_scoped_resume_but_can_be_cleared_by_the_fleet(): void
    {
        [$primary, $agent] = $this->campaign();
        [$arm, $episode] = $this->arm($primary, $agent);
        app(CampaignControl::class)->pause($arm, 'operator_pause');
        $episode->update(['state->draining' => 'host_rate_limits']);
        $this->assertFalse(app(RequestBudget::class)->isDraining($agent->name));
        $review = $this->review($agent, 'arm_resume', $arm);
        $this->assertSame('fleet_review', $review['blockers'][0]['code']);
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');
        $this->submit($agent, $this->review($agent, 'fleet_resume'))->assertSessionHasNoErrors();
        $this->assertNull($arm->fresh()->paused_at);
        $this->assertNull(BridgeDrain::marker($arm->fresh(), $episode->fresh()));
    }

    public function test_bookkeeping_and_authority_roll_back_on_a_drained_batch_write_failure(): void
    {
        [$primary, $agent, $episode] = $this->campaign();
        [$arm, $armEpisode] = $this->arm($primary, $agent);
        $control = app(CampaignControl::class);
        $control->drainPause($primary, 'host_rate_limits');
        $control->drainPause($arm, 'host_rate_limits');
        $this->partialMock(CampaignControl::class)->shouldReceive('resumeArm')->once()->andThrow(new \RuntimeException('Synthetic write failure.'));

        $this->submit($agent, $this->review($agent, 'fleet_resume'))->assertSessionHasErrors('bridge');
        $this->assertTrue($primary->fresh()->isDraining());
        $this->assertTrue($arm->fresh()->isDraining());
        $this->assertSame('host_rate_limits', $episode->fresh()->state['draining']);
        $this->assertSame('host_rate_limits', $armEpisode->fresh()->state['draining']);
        $this->assertTrue(app(RequestBudget::class)->isDraining($agent->name));
        $this->assertDatabaseMissing('campaign_events', ['campaign_grant_id' => $primary->id, 'kind' => 'resume']);
    }

    public function test_budget_reconciliation_retries_without_replaying_the_grant_changes(): void
    {
        [$primary, $agent] = $this->campaign();
        app(CampaignControl::class)->drainPause($primary, 'host_rate_limits');
        $mock = $this->partialMock(RequestBudget::class);
        $mock->shouldReceive('clearDrainHold')->once()->with($agent->name, $this->review($agent, 'fleet_resume')['budget_hold_id'])
            ->andThrow(new RequestDeferred('Synthetic budget write failure.', 1));

        $reply = $this->submit($agent, $this->review($agent, 'fleet_resume'))->assertSessionHasNoErrors();
        $this->assertStringContainsString('The budget hold could not be reconciled', $reply->getSession()->get('status'));
        $this->assertNull($primary->fresh()->wait_reason);
        $this->assertTrue(app(RequestBudget::class)->isDraining($agent->name));

        $this->app->forgetInstance(RequestBudget::class);
        $review = $this->review($agent, 'fleet_resume');
        $this->assertSame([], (array) $review['expected']);
        $this->submit($agent, $review)->assertSessionHasNoErrors();
        $this->assertFalse(app(RequestBudget::class)->isDraining($agent->name));
        $this->assertSame(1, CampaignEvent::where('campaign_grant_id', $primary->id)->where('kind', 'resume')->count());
    }

    #[DataProvider('pressureCauses')]
    public function test_fleet_review_and_post_check_each_current_pressure_cause(string $cause, string $message): void
    {
        [$control, $controlAgent, $controlEpisode] = $this->campaign('GIANTSOUP');
        [$primary, $agent, $episode, $ship] = $this->campaign();
        app(CampaignControl::class)->drainPause($primary, 'test_pressure');
        config(['gameplay.request_budget_enabled' => true]);
        $now = microtime(true);
        $tiers = ['t1' => [], 't24' => []];
        $state = ['last_drain_at' => $now - 30, 'breaches' => ['host_rate_limits' => now()->subHour()->timestamp]];
        $overrides = [];
        if ($cause === 'host_operating_pressure') {
            $tiers['t24'] = array_fill(0, 87, $now);
        } elseif ($cause === 'host_total_pressure') {
            $tiers['t1'] = array_fill(0, 102, $now);
        } elseif ($cause === 'host_rate_limits') {
            $overrides['host_rate_limits'] = array_fill(0, 3, $now - 5);
        } else {
            $state['baseline'] = ['loads_per_hour' => 10, 'cr_per_hour' => 1000, 'pair_margin' => 100, 'goods' => ['IRON_ORE'], 'market_bids' => [
                'X1-SK70-1:WATER' => 50, 'X1-SK70-1:FOOD' => 50, 'X1-SK70-1:FUEL' => 50,
            ]];
            app(CampaignControl::class)->event($control, $controlEpisode, 'load_result', ['net' => 100, 'units' => 10, 'good' => 'IRON_ORE']);
            if ($cause === 'control_degradation') {
                $overrides['agents'] = ['agent:GIANTSOUP' => ['t1' => array_fill(0, 10, $now), 'deferred_at' => [$now]]];
            } else {
                app(CampaignControl::class)->event($primary, $episode, 'load_result',
                    ['net' => 100, 'units' => 10, 'good' => 'IRON_ORE', 'buyer' => 'X1-SK70-2', 'ship' => $ship->symbol]);
                GameObservation::create(['agent_id' => $controlAgent->id, 'reset_id' => $controlAgent->reset_id, 'kind' => 'market', 'symbol' => 'X1-SK70-1',
                    'observed_at' => now(), 'data' => ['tradeGoods' => array_map(fn ($symbol) => ['symbol' => $symbol, 'sellPrice' => 50], ['WATER', 'FOOD', 'FUEL'])]]);
            }
        }
        $this->budgetState($overrides + ['host_tiers' => $tiers, 'host_rate_limits' => array_fill(0, 3, $now - 60)]);
        DB::table('game_runtime')->insert(['key' => 'kill_switch:'.$this->operator->id.':'.$controlEpisode->reset_id, 'value' => json_encode($state, JSON_THROW_ON_ERROR)]);
        $review = $this->review($agent, 'fleet_resume');
        $reported = collect($review['blockers'])->where('code', 'drain_pressure')->pluck('message')->all();
        $this->assertSame([$message], $reported);
        $this->submit($agent, $review)->assertSessionHasErrors(['bridge' => $message]);
        $this->assertTrue($primary->fresh()->isDraining());

        if ($cause === 'host_rate_limits') {
            $this->budgetState(['host_rate_limits' => array_fill(0, 3, $now - 60)]);
            $this->submit($agent, $this->review($agent, 'fleet_resume'))->assertSessionHasNoErrors();
            $this->assertFalse($primary->fresh()->isDraining());
        }
    }

    public static function pressureCauses(): array
    {
        return [
            ['host_operating_pressure', 'Host Tier 2–4 request rate is still high.'],
            ['host_total_pressure', 'Total host request rate is still high.'],
            ['host_rate_limits', 'SpaceTraders is still rate-limiting requests.'],
            ['control_degradation', 'The control campaign is still slowing while requests wait for budget.'],
            ['shared_system_cannibalization', 'The two campaigns are still competing in the control system.'],
        ];
    }

    public function test_missing_and_malformed_telemetry_block_a_drain_until_budgeting_is_disabled(): void
    {
        [$primary, $agent] = $this->campaign();
        app(CampaignControl::class)->drainPause($primary, 'host_rate_limits');
        config(['gameplay.request_budget_enabled' => true]);
        $this->budgetState(['host_tiers' => ['t24' => 'unreadable']]);
        $review = $this->review($agent, 'fleet_resume');
        $this->assertSame('drain_pressure', $review['blockers'][0]['code']);
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');

        unlink(config('gameplay.request_budget_path').'/budget.json');
        $review = $this->review($agent, 'fleet_resume');
        $this->assertSame('drain_pressure', $review['blockers'][0]['code']);
        $this->submit($agent, $review)->assertSessionHasErrors('bridge');
        $this->assertTrue($primary->fresh()->isDraining());

        config(['gameplay.request_budget_enabled' => false]);
        $this->submit($agent, $this->review($agent, 'fleet_resume'))->assertSessionHasNoErrors();
        $this->assertFalse($primary->fresh()->isDraining());
    }

    public function test_price_review_is_episode_scoped_but_unexplained_balance_and_uncertainty_are_agent_wide(): void
    {
        [$primary, $agent] = $this->campaign();
        [$arm] = $this->arm($primary, $agent);
        [$other, $otherEpisode] = $this->arm($primary, $agent, 'R2');
        app(CampaignControl::class)->pause($arm, 'operator_pause');
        app(CampaignControl::class)->pause($other, 'uncertain_action');
        $run = $this->makeRun($otherEpisode, 'stopped');
        $price = $this->action($agent, $other->arm()['ship'], 'confirmed', $run, true);
        $review = $this->review($agent, 'arm_resume', $arm);
        $this->assertSame([], $review['blockers']);
        $this->submit($agent, $review)->assertSessionHasNoErrors();
        $this->assertNotNull($other->fresh()->paused_at);

        $price->update(['review_required' => false]);
        app(CampaignControl::class)->resumeArm($other);
        app(CampaignControl::class)->pause($arm, 'operator_pause');
        $review = $this->review($agent, 'arm_resume', $arm);
        DB::table('agent_credit_baselines')->insert(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'credits' => 1000,
            'observed_at' => now(), 'source' => 'test']);
        $agent->update(['credits' => 1500]);
        $this->submit($agent, $review)->assertSessionHasErrors(['bridge' => 'Review and resume the whole fleet to resolve this agent-wide stop.']);
        $agent->update(['credits' => 1000]);
        $this->action($agent, 'UNRELATED-1', 'uncertain');
        $this->submit($agent, $review)->assertSessionHasErrors(['bridge' => 'Review and resume the whole fleet to resolve this agent-wide stop.']);
        $this->assertNotNull($arm->fresh()->paused_at);
    }

    public function test_arm_action_rejects_foreign_primary_and_revoked_targets(): void
    {
        [$primary, $agent] = $this->campaign();
        [$otherPrimary, $otherAgent] = $this->campaign('OTHER');
        [$arm] = $this->arm($primary, $agent);
        [$foreign] = $this->arm($otherPrimary, $otherAgent);
        app(CampaignControl::class)->pause($arm, 'operator_pause');
        $review = $this->review($agent, 'arm_resume', $arm);
        foreach ([$primary, $foreign] as $target) {
            $this->submit($agent, ['action' => route('campaign.bridge.arm.resume', ['grant' => $target->id]), ...array_diff_key($review, ['action' => true])])->assertNotFound();
        }
        app(CampaignControl::class)->revokeArm($arm);
        $this->submit($agent, $review)->assertNotFound();
    }

    public function test_a_sender_finishes_under_its_own_lock_and_bridge_reads_its_new_blocker(): void
    {
        [$primary, $agent] = $this->campaign();
        app(CampaignControl::class)->pause($primary, 'operator_pause');
        $review = $this->review($agent, 'fleet_resume');
        $sender = fn () => $this->action($agent, $agent->name.'-1', 'uncertain');
        $this->app->instance(HostLock::class, new class($agent->id, $sender) extends HostLock
        {
            public function __construct(private int $agentId, private \Closure $sender) {}

            public function run(string $key, callable $work): mixed
            {
                if ($key === 'agent-'.$this->agentId) {
                    (new HostLock)->run($key, $this->sender);
                }

                return parent::run($key, $work);
            }
        });
        $this->submit($agent, $review)->assertSessionHasErrors(['bridge' => 'Reconcile the uncertain command on the flight deck.']);
        $this->assertSame('operator_pause', $primary->fresh()->pause_reason);
    }

    public function test_campaign_and_agent_lock_waits_share_one_deadline(): void
    {
        [$primary, $agent] = $this->campaign();
        $review = $this->review($agent, 'fleet_pause');
        $campaignFile = config('gameplay.lock_path').'/'.hash('sha256', 'campaign').'.lock';
        $holder = new Process([PHP_BINARY, '-r', <<<'PHP_CODE'
            $file = fopen($argv[1], 'c');
            flock($file, LOCK_EX);
            fwrite(STDOUT, "ready\n");
            fflush(STDOUT);
            usleep(2000000);
            PHP_CODE, $campaignFile]);
        try {
            $holder->start();
            $this->assertTrue($holder->waitUntil(fn ($type, $buffer) => str_contains($buffer, 'ready')));
            $started = microtime(true);
            (new HostLock)->run('agent-'.$agent->id, fn () => $this->submit($agent, $review)->assertSessionHasErrors('bridge'));
            $elapsed = microtime(true) - $started;
            $this->assertGreaterThan(9.5, $elapsed);
            $this->assertLessThan(10.75, $elapsed);
            $this->assertNull($primary->fresh()->paused_at);
        } finally {
            $holder->stop(0);
        }
    }

    private function enableDirector(): void
    {
        config(['gameplay.enabled' => true]);
        $this->partialMock(EngineGate::class)->shouldReceive('enabled')->andReturn(true)->getMock()
            ->shouldReceive('session')->andReturn('fixture-engine');
    }

    private function budgetState(array $overrides): void
    {
        $path = config('gameplay.request_budget_path').'/budget.json';
        $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($path, json_encode(array_replace($state, $overrides), JSON_THROW_ON_ERROR));
    }

    private function campaign(string $symbol = 'SOUPFLEET', string $playbook = 'weekly-default.yaml'): array
    {
        $revision = app(CampaignPlaybook::class)->import(str_replace('GIANTSOUP', $symbol, file_get_contents(base_path('campaigns/'.$playbook))));
        $grant = app(CampaignControl::class)->approve($revision, $this->operator);
        $agent = $this->operator->agents()->create([
            'name' => $symbol, 'faction' => 'VOID', 'token' => 'fixture-token', 'reset_id' => self::RESET,
            'canonical_identity' => AgentSynchronizer::identity(self::RESET, $symbol), 'credits' => 1000, 'connection_state' => 'connected',
        ]);
        $ship = $this->ship($agent, $symbol.'-1', 'COMMAND');

        return [$grant, $agent, $this->episode($grant, $agent, $ship), $ship];
    }

    private function arm(CampaignGrant $primary, Agent $agent, string $label = 'R1'): array
    {
        $ship = $this->ship($agent, $agent->name.'-'.(1 + (int) substr($label, 1)), 'HAULER');
        $grant = app(CampaignControl::class)->approveArm($primary->revision(), $this->operator,
            ['label' => $label, 'ship' => $ship->symbol, 'max_net_loss' => 100], now()->addDays(2)->toImmutable());

        return [$grant, $this->episode($grant, $agent, $ship), $ship];
    }

    private function episode(CampaignGrant $grant, Agent $agent, Ship $ship): CampaignEpisode
    {
        return CampaignEpisode::create([
            'campaign_grant_id' => $grant->id, 'agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'phase' => 'earn',
            'opened_at' => now()->subHours(4), 'next_reset_at' => now()->addDay(), 'start_credits' => 1000,
            'state' => ['role_ships' => ['command' => $ship->symbol]] + ($grant->arm() ? ['arm' => $grant->arm()['label']] : []),
        ]);
    }

    private function ship(Agent $agent, string $symbol, string $role): Ship
    {
        $snapshot = FakeSpaceTraders::ship($symbol);
        $snapshot['registration']['role'] = $role;
        $snapshot['nav']['systemSymbol'] = 'X1-SK70';
        $snapshot['cargo']['units'] = 0;

        return Ship::create(['agent_id' => $agent->id, 'symbol' => $symbol, 'snapshot' => $snapshot, 'observed_at' => now()]);
    }

    private function makeRun(CampaignEpisode $episode, string $state, string $strategy = 'trade'): AutomationRun
    {
        return AutomationRun::create([
            'agent_id' => $episode->agent_id, 'active_agent_id' => in_array($state, ['running', 'stopping', 'blocked'], true) ? $episode->agent_id : null,
            'campaign_episode_id' => $episode->id, 'reset_id' => $episode->reset_id, 'ship_symbol' => $episode->state['role_ships']['command'],
            'strategy' => $strategy, 'state' => $state, 'started_at' => $state === 'preview' ? null : now()->subMinute(), 'expires_at' => now()->addHour(),
            'plan' => ['symbol' => 'IRON_ORE', 'site' => 'X1-SK70-1', 'market' => 'X1-SK70-2'] + ($strategy === 'sell_aboard' ? ['sell_only' => true] : []),
            'totals' => ['credit_delta' => 0],
        ]);
    }

    private function action(Agent $agent, string $ship, string $state, ?AutomationRun $run = null, bool $review = false): ShipAction
    {
        return ShipAction::create([
            'agent_id' => $agent->id, 'automation_run_id' => $run?->id, 'reset_id' => $agent->reset_id, 'ship_symbol' => $ship,
            'key' => Str::random(16), 'state' => $state, 'origin' => 'automation', 'command' => 'sell', 'arguments' => [],
            'review_required' => $review, 'expires_at' => now()->addMinute(),
        ]);
    }

    private function cacheSchedule(string $reset): void
    {
        DB::table('game_runtime')->updateOrInsert(['key' => 'reset_schedule:'.hash('sha256', SpaceTradersClient::BASE_URL)], ['value' => json_encode([
            'reset_id' => $reset, 'next_reset' => now()->addDay()->toIso8601String(), 'observed_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR)]);
    }

    private function review(Agent $agent, string $kind, ?CampaignGrant $arm = null): ?array
    {
        $reviews = app(BridgeActions::class)->reviews($this->operator->id, $agent->name, $kind, $arm?->id);

        return $arm ? ($reviews['arms'][$arm->id][substr($kind, 4)] ?? null) : $reviews[$kind];
    }

    private function reviewFromPage(Agent $agent, string $kind, ?CampaignGrant $arm = null): array
    {
        $url = '/campaign/bridge?agent='.$agent->name;
        $version = $this->get($url)->assertOk()->inertiaPage()['version'];

        $review = $url.'&review='.$kind.($arm ? '&review_grant='.$arm->id : '');
        $prop = $arm ? 'arms.'.$arm->id.'.'.substr($kind, 4) : $kind;

        return $this->get($review, [
            'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Campaign/Bridge/Brief',
            'X-Inertia-Partial-Data' => 'reviews',
        ])->assertOk()->json('props.reviews.'.$prop);
    }

    private function submit(Agent $agent, array $review, array $overrides = []): TestResponse
    {
        return $this->post($review['action'], $overrides + [
            'agent' => $agent->name, 'intent' => $review['intent'], 'expected' => (array) $review['expected'], 'reset_id' => $review['reset_id'],
            'budget_hold_id' => $review['budget_hold_id'] ?? null,
        ]);
    }
}
