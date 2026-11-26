<?php

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Enums\RunState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\AutomationRunner;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignProbeBootstrap;
use App\Gameplay\CampaignSaturation;
use App\Gameplay\CommandRules;
use App\Gameplay\CreditPromotionDisposition;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\EngineGate;
use App\Gameplay\FleetMetrics;
use App\Gameplay\GameException;
use App\Gameplay\MiningPlanner;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Gameplay\StateChain;
use App\Jobs\ExecuteShipCommand;
use App\Models\AutomationRun;
use App\Models\CampaignEpisode;
use App\Models\CampaignEvent;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use App\Services\SpaceTradersClient;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

class PurchaseIntentReissueTest extends TestCase
{
    use SaturationFixture;

    private const YARD = 'X1-TEST-ROCK';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    public static function expiryBoundaries(): array
    {
        return [
            'before preflight' => ['preflight', 'before'],
            'during preflight' => ['preflight', 'reservation'],
            'pre-transport' => ['pretransport', 'transport'],
            'budget wait' => ['budget_wait', 'budget'],
        ];
    }

    #[DataProvider('expiryBoundaries')]
    public function test_expired_unsent_probe_intent_rechecks_and_reissues_once(string $stage, string $boundary): void
    {
        $run = $this->probeRun();
        $runner = app(AutomationRunner::class);
        $runner->advance($run->id);
        $original = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->sole();
        $dispatchTime = now()->utc()->toIso8601String();
        Queue::assertPushed(ExecuteShipCommand::class, fn ($job) => $job->actionId === $original->id);
        $reached = $boundary === 'before';
        if ($boundary === 'before') {
            $original->update(['expires_at' => now()->subSecond()]);
        } elseif ($boundary === 'reservation') {
            DB::connection()->beforeStartingTransaction(function () use ($original, &$reached) {
                if (! $reached && $this->startingExecutorTransaction('executeWithWorkload')) {
                    $reached = true;
                    $this->assertSame(ActionState::Pending, $original->fresh()->state);
                    $original->update(['expires_at' => now()->subSecond()]);
                }
            });
        } else {
            $this->atWriteReservation(function () use ($original, $boundary, &$reached) {
                $reached = true;
                $this->assertSame(ActionState::Sending, $original->fresh()->state);
                $this->assertNotNull($original->fresh()->sent_at);
                $original->update(['expires_at' => now()->subSecond()]);
                if ($boundary === 'budget') {
                    throw new RequestDeferred('Fixture budget wait', 5, 'blocked_until');
                }
            });
        }

        $this->job($original);

        $this->assertTrue($reached, $original->fresh()->message ?? '');
        $this->assertTrue(ShipCommandExecutor::reissuableExpiry($original->refresh()), $original->message ?? '');
        $this->assertSame($stage, $original->outcome['expiry']['stage']);
        $this->assertSame($dispatchTime, $original->outcome['expiry']['last_dispatched_at']);
        $this->assertSame($boundary === 'budget' ? 'blocked_until' : null, $original->outcome['expiry']['defer_source']);
        $this->assertSame(RunState::Waiting, $run->refresh()->state);
        $this->assertEquals(now()->addSeconds(5), $run->next_action_at);
        $this->assertSame($this->agent->id, $run->active_agent_id);
        $this->assertTrue(app(CampaignProbeBootstrap::class)->reservations($this->agent)->isNotEmpty());
        $this->assertSame(0, $this->purchasePosts());
        FleetMetrics::flush();
        $this->assertTrue(DB::table('fleet_metrics')->where('kind', 'purchase_intent_expired')->exists());

        $this->travel(5)->seconds();
        $runner->advance($run->id);
        $runner->advance($run->id);
        $replacement = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->whereKeyNot($original->id)->sole();
        $this->job($original);
        $this->job($replacement);
        $this->job($replacement);
        $this->workRun($run);

        $this->assertSame(1, $this->purchasePosts());
        $this->assertSame(RunState::Completed, $run->refresh()->state, $run->blocked_reason ?? '');
        $this->assertSame(0, CampaignEvent::where('kind', 'probe_shipyard_rejected')->count());
    }

    #[DataProvider('haulerExpiries')]
    public function test_expired_unsent_hauler_intent_reissues_after_reservation(string $stage): void
    {
        $this->setUpSaturation(hubs: 2);
        $episode = $this->episode('mesh');
        $this->schedulerPass();
        $runner = app(AutomationRunner::class);
        $run = $runner->previewHaulerPurchase($this->agent, 'LOCAL-2', self::YARD, ['minutes' => 15], $episode);
        $runner->startCampaign($this->agent, $run, $episode);
        $runner->advance($run->id);
        $original = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->sole();
        $pin = $original->arguments['offer_configuration_hash'];
        $this->atWriteReservation(function () use ($original, $stage) {
            $this->assertSame(ActionState::Sending, $original->fresh()->state);
            $this->assertNotNull($original->fresh()->sent_at);
            $original->update(['expires_at' => now()->subSecond()]);
            if ($stage === 'budget_wait') {
                throw new RequestDeferred('Fixture request delay', 5, 'blocked_until');
            }
        });

        $this->job($original);

        $this->assertTrue(ShipCommandExecutor::reissuableExpiry($original->refresh()), $original->message ?? '');
        $this->assertSame($stage, $original->outcome['expiry']['stage']);
        $this->assertNull($original->sent_at);
        $this->assertSame(0, $this->purchasePosts());
        $this->travel(5)->seconds();
        $runner->advance($run->id);
        $replacement = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->whereKeyNot($original->id)->sole();
        $this->assertSame($pin, $replacement->arguments['offer_configuration_hash']);
        $this->job($replacement);
        $this->workRun($run);

        $this->assertSame(1, $this->purchasePosts());
        $this->assertSame(RunState::Completed, $run->refresh()->state, $run->blocked_reason ?? '');
    }

    public static function haulerExpiries(): array
    {
        return [['pretransport'], ['budget_wait']];
    }

    #[DataProvider('manualStops')]
    public function test_purchase_final_transport_check_honors_committed_stops(string $boundary): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $this->addManualOffer();
        $action = $this->manualPurchase('SHIP_MINING_DRONE');
        $marker = now()->utc()->toIso8601String();
        $stopped = false;
        $stop = function () use ($action, &$stopped) {
            $stopped = true;
            $this->assertSame(ActionState::Sending, $action->fresh()->state);
            $this->assertNotNull($action->fresh()->sent_at);
            app(ShipCommandExecutor::class)->stopManual($action->fresh());
        };
        if ($boundary === 'early') {
            $this->atWriteReservation($stop);
        } else {
            DB::connection()->beforeStartingTransaction(function () use ($stop, &$stopped) {
                if (! $stopped && $this->startingExecutorTransaction('finalPurchaseTransportCheck')) {
                    $stop();
                }
            });
        }

        $this->job($action);

        $this->assertTrue($stopped, $action->fresh()->message ?? '');
        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertSame($marker, $action->outcome['stop_requested_at']);
        $this->assertSame($boundary === 'early' ? 'pretransport' : 'final_transport_check', $action->outcome['local_refusal']['stage']);
        $this->assertNull($action->sent_at);
        $this->assertArrayNotHasKey('expired_unsent', $action->outcome);
        $this->assertSame(0, $this->purchasePosts());
    }

    public static function manualStops(): array
    {
        return [['early'], ['final']];
    }

    #[DataProvider('finalFleetVetoes')]
    public function test_fleet_final_transport_veto_settles_the_action_and_only_writes_under_run_authority(string $veto): void
    {
        [$run, $action] = $this->fleetIntent();
        $originalWake = $run->fresh()->next_action_at;
        $reached = false;
        $stop = null;
        DB::connection()->beforeStartingTransaction(function () use ($run, $action, $veto, &$reached, &$stop) {
            if ($reached || ! $this->startingExecutorTransaction('finalPurchaseTransportCheck')) {
                return;
            }
            $reached = true;
            $this->assertSame(ActionState::Sending, $action->fresh()->state);
            $this->assertSame('pending', $this->hold($action)->state);
            if ($veto === 'stop') {
                app(AutomationRunner::class)->stop($run);
                $stop = $run->fresh();
                $this->travel(1)->second();
            } elseif ($veto === 'run_expired') {
                $run->update(['expires_at' => now()->subSecond()]);
            } elseif (in_array($veto, ['acquisition_cutoff', 'settlement_deadline'], true)) {
                $run->update(['plan' => array_replace($run->plan, [$veto => now()->subSecond()->toIso8601String()])]);
            } elseif ($veto === 'lost_owner') {
                $run->update(['active_agent_id' => null]);
            } elseif ($veto === 'reset') {
                $this->agent->update(['reset_id' => 'different-reset']);
            } elseif ($veto === 'different_attempt') {
                $action->update(['sent_at' => now()->addSecond()]);
            } elseif ($veto === 'receipt') {
                $action->update(['outcome' => ['response' => []]]);
            } elseif ($veto === 'intent_expired') {
                $action->update(['expires_at' => now()->subSecond()]);
            }
        });

        $this->job($action);

        $this->assertTrue($reached, $action->fresh()->message ?? '');
        $this->assertSame(0, $this->purchasePosts());
        $run->refresh();
        $action->refresh();
        if ($veto === 'intent_expired') {
            $this->assertTrue(ShipCommandExecutor::reissuableExpiry($action));
            $this->assertSame('pretransport', $action->outcome['expiry']['stage']);
            $this->assertSame(RunState::Waiting, $run->state);
            $this->assertEquals(now()->addSeconds(5), $run->next_action_at);
        } elseif ($veto === 'different_attempt') {
            $this->assertSame(ActionState::Sending, $action->state);
            $this->assertEquals(now()->addSecond(), $action->sent_at);
            $this->assertSame('pending', $this->hold($action)->state);
            $this->assertSame(RunState::Running, $run->state);
        } elseif ($veto === 'receipt') {
            $this->assertSame(ActionState::Uncertain, $action->state);
            $this->assertSame([], $action->outcome['response']);
            $this->assertNotNull($action->sent_at);
            $this->assertSame('uncertain', $this->hold($action)->state);
            $this->assertSame(RunState::Blocked, $run->state);
        } else {
            $this->assertSame(ActionState::Rejected, $action->state);
            $this->assertSame('final_transport_check', $action->outcome['local_refusal']['stage']);
            $this->assertNull($action->sent_at);
            $this->assertSame('released', $this->hold($action)->state);
            $this->assertArrayNotHasKey('expired_unsent', $action->outcome);
            if ($veto === 'stop') {
                $this->assertSame(RunState::Stopping, $run->state);
                $this->assertEquals($stop->stop_requested_at, $run->stop_requested_at);
                $this->assertEquals($stop->next_action_at, $run->next_action_at);
                $this->assertSame($this->agent->id, $run->active_agent_id);
            } else {
                $this->assertSame($veto === 'lost_owner' ? RunState::Running : RunState::Blocked, $run->state);
                $this->assertEquals($originalWake, $run->next_action_at);
            }
        }
    }

    public static function finalFleetVetoes(): iterable
    {
        foreach (['stop', 'run_expired', 'acquisition_cutoff', 'settlement_deadline', 'lost_owner', 'reset', 'different_attempt', 'receipt', 'intent_expired'] as $veto) {
            yield $veto => [$veto];
        }
    }

    #[DataProvider('queueVetoes')]
    public function test_credit_promotion_skips_gate_for_invalid_queued_expiry(string $evidence, bool $review): void
    {
        [$run, $waiting] = $this->fleetIntent();
        $reserved = $this->hold($waiting);
        DB::table('credit_reservations')->where('id', $reserved->id)->update(['state' => 'reserved', 'created_at' => now()->subMinute()]);
        $eligible = $this->copyIntent($waiting, ActionState::Preview);
        $eligibleHold = $this->reserve($run, $eligible);
        DB::table('credit_reservations')->where('id', $eligibleHold)->update(['created_at' => now()->subMinutes(2)]);
        $rejected = $this->copyIntent($waiting, ActionState::Rejected);
        $this->reserve($run, $rejected);
        $rejected->update($evidence === 'receipt' ? ['outcome' => ['response' => []]] : ['sent_at' => now()]);
        if ($review) {
            $this->copyIntent($waiting, ActionState::Rejected)->update(['review_required' => true]);
        }
        $wake = $run->fresh()->next_action_at;
        Queue::fake();

        $this->assertSame(CreditPromotionDisposition::EvidenceVeto, app(ShipCommandExecutor::class)->promoteCreditIntent($this->agent));
        $this->job($waiting);

        $this->assertSame(ActionState::Rejected, $rejected->fresh()->state);
        $this->assertSame($rejected->outcome, $rejected->fresh()->outcome);
        $this->assertEquals($rejected->sent_at, $rejected->fresh()->sent_at);
        $this->assertSame('reserved', $this->hold($rejected)->state);
        $this->assertSame(ActionState::Preview, $eligible->fresh()->state);
        $this->assertSame('reserved', $this->hold($eligible)->state);
        $this->assertSame('reserved', $this->hold($waiting)->state);
        $this->assertSame(['reason_code' => 'rejected_purchase_evidence', 'action_id' => $rejected->id], $waiting->refresh()->outcome['promotion_vetoed']);
        $this->assertEquals($wake, $run->fresh()->next_action_at);
        $this->assertSame(0, $this->purchasePosts());
        Queue::assertNothingPushed();
    }

    public static function queueVetoes(): array
    {
        return [['receipt', false], ['sent_at', false], ['receipt', true]];
    }

    #[DataProvider('purchaseSendVetoes')]
    public function test_purchase_job_honors_rejected_purchase_evidence_at_transport(bool $manual, string $holdState, string $evidence): void
    {
        if ($manual) {
            $this->setUpSaturation(seedCoverage: false);
            $action = $this->manualPurchase('SHIP_PROBE');
        } else {
            [, $action] = $this->fleetIntent();
            $this->assertSame('pending', $this->hold($action)->state);
        }
        $rejected = $this->copyIntent($action, ActionState::Rejected);
        $rejected->update($evidence === 'receipt' ? ['outcome' => ['response' => []]] : ['sent_at' => now()]);
        DB::table('credit_reservations')->where('id', app(StateChain::class)->reserveCredit(
            $this->agent, 'purchase:'.$rejected->id, 'purchase', 'buy_ship', 25000, $rejected->id,
        ))->update(['state' => $holdState]);
        $rejected->refresh();
        $hold = $this->hold($rejected);

        $this->assertSame(CreditPromotionDisposition::EvidenceVeto, app(ShipCommandExecutor::class)->promoteCreditIntent($this->agent));
        Queue::fake();
        $this->job($action);

        $this->assertSame(0, $this->purchasePosts());
        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertSame('final_transport_check', $action->outcome['local_refusal']['stage']);
        $this->assertStringContainsString("Rejected purchase {$rejected->id} has retained transport evidence", $action->message);
        $this->assertNull($action->sent_at);
        $this->assertFalse(ShipCommandExecutor::reissuableExpiry($action));
        if (! $manual) {
            $this->assertSame('released', $this->hold($action)->state);
        }
        $this->assertSame($rejected->getAttributes(), $rejected->fresh()->getAttributes());
        $this->assertEquals($hold, $this->hold($rejected));
        Queue::assertNothingPushed();
    }

    public static function purchaseSendVetoes(): array
    {
        return [
            'already promoted with rejected receipt' => [false, 'reserved', 'receipt'],
            'manual with rejected receipt' => [true, 'reserved', 'receipt'],
            'already promoted with rejected timestamp' => [false, 'pending', 'sent_at'],
            'manual with rejected timestamp' => [true, 'pending', 'sent_at'],
        ];
    }

    public function test_runner_records_a_promotion_veto_without_a_waiting_action(): void
    {
        [$run, $rejected] = $this->fleetIntent();
        $rejected->update(['state' => ActionState::Rejected, 'outcome' => ['response' => []]]);
        DB::table('credit_reservations')->where('ship_action_id', $rejected->id)->update(['state' => 'reserved']);
        $hold = $this->hold($rejected);
        $wake = $run->next_action_at;
        Queue::fake();

        app(AutomationRunner::class)->advance($run->id);
        FleetMetrics::flush();

        $metric = DB::table('fleet_metrics')->where('agent_symbol', $this->agent->name)
            ->where('kind', 'rejected_purchase_evidence')->sole();
        $this->assertSame(1.0, (float) $metric->value);
        $this->assertSame($rejected->ship_symbol, $metric->ship);
        $this->assertSame($rejected->getAttributes(), $rejected->fresh()->getAttributes());
        $this->assertEquals($hold, $this->hold($rejected));
        $this->assertEquals($wake, $run->fresh()->next_action_at);
        Queue::assertNothingPushed();
    }

    #[DataProvider('queuedEvidence')]
    public function test_purchase_job_routes_each_receipt_key_to_reconciliation_without_posting(string $key, mixed $value, bool $expired): void
    {
        [$run, $action] = $this->fleetIntent();
        $outcome = [$key => $value];
        $action->update(['outcome' => $outcome, 'expires_at' => $expired ? now()->subSecond() : now()->addMinute()]);
        $exposure = $this->hold($action)->exposure;

        $this->job($action);
        $this->job($action);

        $this->assertSame(ActionState::Uncertain, $action->refresh()->state);
        $this->assertSame($outcome, $action->outcome);
        $this->assertSame('uncertain', $this->hold($action)->state);
        $this->assertSame($exposure, $this->hold($action)->exposure);
        $this->assertSame(RunState::Blocked, $run->fresh()->state);
        $this->assertSame($this->agent->id, $run->fresh()->active_agent_id);
        $this->assertSame(0, $this->purchasePosts());
    }

    public static function queuedEvidence(): iterable
    {
        foreach (['response' => [], 'body_sha256' => 'hash', 'response_received_at' => null, 'http_status' => 201, 'response_validated' => false] as $key => $value) {
            yield $key.' before expiry' => [$key, $value, false];
            yield $key.' after expiry' => [$key, $value, true];
        }
    }

    #[DataProvider('terminalQueueCases')]
    public function test_queued_purchase_evidence_survives_engine_close_and_operator_stop(string $boundary, string $evidence, bool $preview): void
    {
        [$run, $action] = $this->fleetIntent();
        if ($preview) {
            $action->update(['state' => ActionState::Preview]);
            DB::table('credit_reservations')->where('ship_action_id', $action->id)->update(['state' => 'reserved']);
        }
        $marker = '2026-11-20T16:00:00Z';
        $outcome = ['stop_requested_at' => $marker];
        if ($evidence === 'receipt') {
            $outcome['response'] = [];
        }
        $sentAt = $evidence === 'sent_at' ? now()->subMinute() : null;
        $action->update(['outcome' => $outcome, 'sent_at' => $sentAt]);
        if ($boundary === 'engine') {
            app(EngineGate::class)->close();
        } else {
            $runner = app(AutomationRunner::class);
            $runner->stop($run);
            $runner->advance($run->id);
        }

        $this->assertSame($evidence === 'none' ? ActionState::Rejected : ActionState::Uncertain, $action->refresh()->state);
        $this->assertEquals($sentAt, $action->sent_at);
        $this->assertSame($marker, $action->outcome['stop_requested_at']);
        $this->assertArrayNotHasKey('expired_unsent', $action->outcome);
        $this->assertSame($evidence === 'none' ? 'released' : 'uncertain', $this->hold($action)->state);
        $this->assertSame($evidence === 'none' && $boundary !== 'engine' ? RunState::Stopped : RunState::Stopping, $run->fresh()->state);
        if ($evidence !== 'none') {
            $this->assertSame($outcome, $action->outcome);
            $this->assertSame($this->agent->id, $run->fresh()->active_agent_id);
        }
        $this->assertSame(0, $this->purchasePosts());
    }

    public static function terminalQueueCases(): iterable
    {
        foreach (['engine', 'runner'] as $boundary) {
            foreach (['receipt', 'sent_at', 'none'] as $evidence) {
                yield "$boundary preview $evidence" => [$boundary, $evidence, true];
                yield "$boundary pending $evidence" => [$boundary, $evidence, false];
            }
        }
    }

    #[DataProvider('preflightBlockers')]
    public function test_expired_buy_ship_behind_pending_action_in_clear_marks_reissuable(string $blocker): void
    {
        $run = $this->probeRun();
        app(AutomationRunner::class)->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->sole();
        $action->update(['expires_at' => now()->subSecond()]);
        $other = $this->copyIntent($action, $blocker === 'uncertain' ? ActionState::Uncertain : ActionState::Pending);
        $other->update(['ship_symbol' => 'LOCAL-1', 'automation_run_id' => null, 'origin' => 'manual', 'command' => 'orbit',
            'outcome' => $blocker === 'receipt' ? ['body_sha256' => 'already-received'] : []]);

        $this->job($action);

        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertSame($blocker === 'pending', ShipCommandExecutor::reissuableExpiry($action));
        $this->assertSame($blocker === 'pending' ? 'action_in_flight' : null, $action->outcome['expiry']['defer_source'] ?? null);
        $this->assertSame(0, $this->purchasePosts());
    }

    public static function preflightBlockers(): array
    {
        return [['pending'], ['uncertain'], ['receipt']];
    }

    #[DataProvider('fleetBudgetCases')]
    public function test_fleet_budget_wait_expiry_reissues_only_with_exempt_or_bound_allowance(string $case, array $answers): void
    {
        [$run, $action] = $this->fleetIntent();
        if ($case !== 'exempt') {
            $this->bindAllowance($run);
        }
        $reached = false;
        $lookups = 0;
        $this->atWriteReservation(function () use ($action, &$reached) {
            $reached = true;
            $this->assertSame(ActionState::Sending, $action->fresh()->state);
            $action->update(['expires_at' => now()->subSecond()]);
            throw new RequestDeferred('Fixture budget wait', 5, 'blocked_until');
        }, function () use (&$lookups, $answers, &$reached) {
            $this->assertTrue($reached);
            $this->assertSame(0, DB::transactionLevel());
            $answer = $answers[$lookups++];
            if ($answer === null) {
                throw new RequestDeferred('Allowance store unavailable', 5, 'budget_storage');
            }

            return $answer;
        });
        $oldCreditExposure = $this->hold($action)->exposure;

        $this->job($action);

        $this->assertTrue($reached);
        $this->assertSame(count($answers), $lookups);
        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertNull($action->sent_at);
        $this->assertSame('released', $this->hold($action)->state);
        $this->assertSame($oldCreditExposure, $this->hold($action)->exposure);
        $eligible = in_array($case, ['exempt', 'bound'], true);
        $this->assertSame($eligible, ShipCommandExecutor::reissuableExpiry($action));
        $this->assertSame($eligible ? 'budget_wait' : null, $action->outcome['expiry']['stage'] ?? null);
        $this->assertSame($eligible ? RunState::Waiting : RunState::Blocked, $run->refresh()->state);
        $this->assertSame(0, $this->purchasePosts());
        if ($eligible) {
            $this->assertEquals(now()->addSeconds(5), $run->next_action_at);
            $this->completeReplacement($run, $action);
        } elseif ($answers[1] === false) {
            $this->assertNull($run->active_agent_id);
            $this->assertNull($run->next_action_at);
        }
    }

    public static function fleetBudgetCases(): array
    {
        return [
            ['exempt', []], ['bound', [true]], ['unbound', [false, false]], ['store unavailable', [null, null]],
            ['unavailable then lost', [null, false]], ['unavailable then bound', [null, true]],
        ];
    }

    public function test_fleet_intent_expiring_at_transport_reissues_after_fresh_ranked_admission(): void
    {
        $ranked = [];
        [$run, $action] = $this->fleetIntent(onRank: function ($ignoreRun, $exact) use (&$ranked) {
            if ($ignoreRun !== null) {
                $intentCount = ShipAction::where('automation_run_id', $ignoreRun)->where('command', 'buy_ship')->count();
                $ranked[] = [$ignoreRun, $exact, $intentCount];
            }
        });
        $duringBudget = false;
        $this->atWriteReservation(function () use ($action, &$duringBudget) {
            $duringBudget = true;
            $this->assertSame(ActionState::Sending, $action->fresh()->state);
            $this->assertNotNull($action->fresh()->sent_at);
            $this->travelTo($action->expires_at->copy()->addSecond());
            LocalEngine::whereKey(1)->update(['heartbeat_at' => now()]);
        });

        $this->job($action);

        $this->assertTrue($duringBudget);
        $this->assertTrue(ShipCommandExecutor::reissuableExpiry($action->refresh()));
        $this->assertSame('pretransport', $action->outcome['expiry']['stage']);
        $this->assertNull($action->sent_at);
        $this->assertSame('released', $this->hold($action)->state);
        $this->assertSame(RunState::Waiting, $run->refresh()->state);
        $this->assertEquals(now()->addSeconds(5), $run->next_action_at);
        $this->assertSame(0, $this->purchasePosts());

        $ranked = [];
        $this->completeReplacement($run, $action);
        $this->assertContains([$run->id, [$run->plan['ship_type'], self::YARD, $run->ship_symbol], 1], $ranked);
    }

    public function test_expired_queued_fleet_intent_reissues_after_fresh_ranked_admission(): void
    {
        [$run, $action] = $this->fleetIntent();
        $action->update(['state' => ActionState::Preview, 'expires_at' => now()->subSecond()]);
        DB::table('credit_reservations')->where('ship_action_id', $action->id)->update(['state' => 'reserved']);

        app(AutomationRunner::class)->advance($run->id);

        $this->assertTrue(ShipCommandExecutor::reissuableExpiry($action->refresh()));
        $this->assertSame('credit_queue', $action->outcome['expiry']['stage']);
        $this->assertSame(1, ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->count());
        $this->assertSame(RunState::Waiting, $run->refresh()->state);
        $this->assertEquals(now()->addSeconds(5), $run->next_action_at);
        $this->assertSame('released', $this->hold($action)->state);
        $this->completeReplacement($run, $action);
    }

    public function test_credit_job_preserves_the_queued_purchase_expiry_wake(): void
    {
        [$run, $expired] = $this->fleetIntent();
        $this->returnToCreditQueue($expired);
        $expired->update(['expires_at' => now()->subSecond()]);
        $waiting = $this->copyIntent($expired, ActionState::Pending);
        $waiting->update(['expires_at' => now()->addMinute()]);
        $this->reserve($run, $waiting);

        $this->job($waiting);

        $this->assertTrue(ShipCommandExecutor::reissuableExpiry($expired->refresh()));
        $this->assertSame('credit_queue', $expired->outcome['expiry']['stage']);
        $this->assertSame('released', $this->hold($expired)->state);
        $this->assertSame(ActionState::Pending, $waiting->refresh()->state);
        $this->assertSame('reserved', $this->hold($waiting)->state);
        $this->assertSame(RunState::Waiting, $run->refresh()->state);
        $this->assertEquals(now()->addSeconds(5), $run->next_action_at);
        $this->assertSame(0, $this->purchasePosts());
    }

    public function test_credit_queue_keeps_real_engine_invalidation_when_the_recheck_fails(): void
    {
        [$run, $action] = $this->fleetIntent();
        $action->update(['state' => ActionState::Preview, 'expires_at' => now()->subSecond()]);
        DB::table('credit_reservations')->where('ship_action_id', $action->id)->update(['state' => 'reserved']);
        $host = app(EngineHost::class);
        $host->alive['supervisor'] = false;
        $host->calls = [];

        app(AutomationRunner::class)->advance($run->id);

        $this->assertSame([['alive', $run->engine_session_id, 'supervisor']], $host->calls);
        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertSame('Owned engine processes are no longer alive. Fresh session and preview required.', $action->message);
        $this->assertArrayNotHasKey('expired_unsent', $action->outcome ?? []);
        $this->assertSame(RunState::Stopping, $run->refresh()->state);
        $this->assertEquals(now(), $run->next_action_at);
        $this->assertSame(1, ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->count());
        $this->assertSame(0, $this->purchasePosts());
    }

    #[DataProvider('governorExpiryCases')]
    public function test_path_d_mesh_governor_expiry_requires_active_allowance_open_cutoffs_and_unstopped_run(string $case): void
    {
        [$run, $action] = $this->fleetIntent();
        $allowance = $this->bindAllowance($run);
        $action->update(['expires_at' => now()->subSecond()]);
        if ($case === 'closed cutoff') {
            $run->update(['plan' => array_replace($run->plan, ['acquisition_cutoff' => now()->subSecond()->toIso8601String()])]);
        }
        $realBudget = app(RequestBudget::class);
        $stop = null;
        $this->atWriteReservation(null, function () use ($run, $case, &$stop) {
            if ($case === 'store unavailable') {
                throw new RequestDeferred('Allowance store unavailable', 5, 'budget_storage');
            }
            if ($case === 'stop during check') {
                app(AutomationRunner::class)->stop($run);
                $stop = $run->fresh();
                $this->travel(1)->second();
            }
            if ($case === 'engine closes') {
                app(EngineHost::class)->alive['supervisor'] = false;
            }

            return $case !== 'unbound';
        }, fn () => throw new RequestDeferred('Fixture governor deferral', 5, 'mesh_allowance'));

        $this->job($action);

        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertNull($action->sent_at);
        $this->assertSame('released', $this->hold($action)->state);
        $this->assertSame(0, $this->purchasePosts());
        $run->refresh();
        $this->assertSame($case === 'bound', ShipCommandExecutor::reissuableExpiry($action));
        $this->assertSame($case === 'bound', $realBudget->hasMesh($allowance, $this->agent->name, $this->agent->reset_id, $run->id));
        if ($case === 'bound') {
            $this->assertSame('mesh_governor', $action->outcome['expiry']['stage']);
            $this->assertSame(RunState::Waiting, $run->state);
            $this->assertSame($this->agent->id, $run->active_agent_id);
            $this->app->instance(RequestBudget::class, $realBudget);
            $this->completeReplacement($run, $action);
        } elseif (in_array($case, ['stop during check', 'engine closes'], true)) {
            $this->assertSame(RunState::Stopping, $run->state);
            $this->assertSame($this->agent->id, $run->active_agent_id);
            if ($stop) {
                $this->assertEquals($stop->next_action_at, $run->next_action_at);
                $this->assertEquals($stop->stop_requested_at, $run->stop_requested_at);
            }
        } else {
            $this->assertSame(RunState::Blocked, $run->state);
            $this->assertNull($run->active_agent_id);
            $this->assertNull($run->next_action_at);
        }
    }

    public static function governorExpiryCases(): iterable
    {
        foreach (['bound', 'unbound', 'closed cutoff', 'store unavailable', 'stop during check', 'engine closes'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('financialGuards')]
    public function test_reissue_refuses_a_repriced_offer_or_a_fresh_cash_shortfall(string $guard): void
    {
        [$run, $action] = $this->fleetIntent();
        $action->update(['expires_at' => now()->subSecond()]);
        $this->job($action);
        $this->assertTrue(ShipCommandExecutor::reissuableExpiry($action->refresh()));
        $before = count($this->world()['calls']);
        $this->editFixture(function (&$world) use ($guard) {
            if ($guard === 'balance') {
                $world['credits'] = 300000;
            } else {
                $world['shipyards'][self::YARD]['ships'][1]['purchasePrice'] = 500000;
            }
        });
        $this->travel(5)->seconds();

        app(AutomationRunner::class)->advance($run->id);

        $calls = collect(array_slice($this->world()['calls'], $before))->pluck('path')->all();
        if ($guard === 'balance') {
            $this->assertContains('/my/agent', $calls);
            $this->assertLessThan(array_search('/my/agent', $calls, true), array_search('/systems/X1-TEST/waypoints/'.self::YARD.'/shipyard', $calls, true));
            $this->assertSame(300000, $this->agent->fresh()->credits);
        } else {
            $this->assertNotContains('/my/agent', $calls);
        }
        $this->assertSame(1, ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->count());
        $this->assertSame(0, $this->purchasePosts());
        $this->assertSame(RunState::Blocked, $run->fresh()->state);
    }

    public static function financialGuards(): array
    {
        return [['balance'], ['price']];
    }

    #[DataProvider('manualExpiries')]
    public function test_manual_shared_slot_purchase_expiry_settles_without_reissue(string $boundary): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $action = $this->manualPurchase('SHIP_PROBE');
        if ($boundary === 'preflight') {
            $action->update(['expires_at' => now()->subSecond()]);
        } else {
            $this->atWriteReservation(function () use ($action) {
                $this->assertSame(ActionState::Sending, $action->fresh()->state);
                $action->update(['expires_at' => now()->subSecond()]);
                throw new RequestDeferred('Fixture budget wait', 5);
            });
        }
        $bootstrap = app(CampaignProbeBootstrap::class);
        $this->assertTrue($bootstrap->reservations($this->agent)->isNotEmpty());

        $this->job($action);

        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertNotNull($action->settled_at);
        $this->assertArrayHasKey('local_refusal', $action->outcome);
        $this->assertArrayNotHasKey('expiry', $action->outcome);
        $this->assertNull($action->sent_at);
        $this->assertTrue($bootstrap->reservations($this->agent)->isEmpty());
        $this->assertSame(0, $this->purchasePosts());
    }

    public static function manualExpiries(): array
    {
        return [['preflight'], ['budget']];
    }

    public function test_manual_purchase_budget_retry_cannot_restore_a_send_after_engine_close(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $action = $this->manualPurchase('SHIP_PROBE');
        $this->atWriteReservation(function () use ($action) {
            $this->assertSame(ActionState::Sending, $action->fresh()->state);
            $this->assertNotNull($action->fresh()->sent_at);
            app(EngineGate::class)->close();
            $this->assertSame(ActionState::Sending, $action->fresh()->state);
            throw new RequestDeferred('Fixture budget wait after engine close', 5);
        });

        $this->job($action);

        $this->assertFalse(LocalEngine::findOrFail(1)->gate_open);
        $this->assertSame(0, $this->purchasePosts());
        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertNull($action->sent_at);
        $this->assertNotNull($action->settled_at);
        $this->assertArrayHasKey('local_refusal', $action->outcome);
        $this->assertArrayNotHasKey('expiry', $action->outcome);
        $this->assertOldIntentIsNotDispatched($action);
    }

    public function test_manual_purchase_approval_reports_refusal_after_committing_settlement(): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $executor = app(ShipCommandExecutor::class);
        $action = $this->previewManualPurchase('SHIP_PROBE');
        Queue::fake();
        $this->atWriteReservation(null, read: fn () => ShipAction::whereKey($action->id)
            ->update(['outcome->response' => []]));

        try {
            $executor->approve($this->agent, $action);
            $this->fail('Approval must report the refused preview.');
        } catch (GameException $exception) {
            $this->assertSame('Preview was stopped or already approved.', $exception->getMessage());
        }

        $this->assertSame(ActionState::Uncertain, $action->refresh()->state);
        $this->assertSame([], $action->outcome['response']);
        $this->assertNotNull($action->settled_at);
        Queue::assertNothingPushed();
    }

    public function test_unrelated_failure_crossing_expiry_is_never_marked_reissuable(): void
    {
        [$run, $action] = $this->fleetIntent();
        $this->atWriteReservation(null, read: function () use ($action) {
            $action->update(['expires_at' => now()->subSecond()]);
            throw new GameException('The live yard rejected preflight.');
        });

        $this->job($action);

        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertSame('The live yard rejected preflight.', $action->message);
        $this->assertFalse(ShipCommandExecutor::reissuableExpiry($action));
        $this->assertSame(RunState::Blocked, $run->fresh()->state);
        $this->assertSame(0, $this->purchasePosts());
    }

    public function test_reissue_cap_blocks_after_two_reissues(): void
    {
        [$run, $attempt] = $this->fleetIntent();
        $runner = app(AutomationRunner::class);
        for ($ordinal = 1; $ordinal <= 3; $ordinal++) {
            $attempt->update(['expires_at' => now()->subSecond()]);
            $this->job($attempt);
            $this->assertTrue(ShipCommandExecutor::reissuableExpiry($attempt->refresh()));
            $this->travel(5)->seconds();
            $runner->advance($run->id);
            $attempt = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->latest('id')->firstOrFail();
        }

        $this->assertSame(3, ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->count());
        $this->assertSame(RunState::Blocked, $run->fresh()->state);
        $this->assertSame(0, $this->purchasePosts());
    }

    public function test_purchase_ttl_is_bound_to_the_run_and_reissue_requires_a_thirty_second_margin(): void
    {
        [$run, $action] = $this->fleetIntent(runSeconds: 90);
        $this->assertEquals($run->expires_at, $action->expires_at);
        $action->update(['expires_at' => now()->subSecond()]);
        $this->job($action);
        $this->assertTrue(ShipCommandExecutor::reissuableExpiry($action->refresh()));

        $run->update([
            'expires_at' => now()->addMinutes(10),
            'plan' => array_replace($run->plan, [
                'acquisition_cutoff' => now()->addSeconds(20)->toIso8601String(),
                'settlement_deadline' => now()->addMinutes(10)->toIso8601String(),
            ]),
        ]);
        $this->travel(5)->seconds();
        app(AutomationRunner::class)->advance($run->id);

        $this->assertSame(RunState::Blocked, $run->fresh()->state);
        $this->assertSame(1, ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->count());
        $this->assertSame(0, $this->purchasePosts());
    }

    public function test_legacy_stop_marked_queued_purchase_settles_without_replay(): void
    {
        [$run, $action] = $this->fleetIntent();
        $marker = '2026-11-20T16:00:00Z';
        $action->update(['outcome' => ['stop_requested_at' => $marker]]);
        $wake = $run->next_action_at;
        Queue::fake();

        $this->job($action);

        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertSame(['stop_requested_at' => $marker], $action->outcome);
        $this->assertNull($action->sent_at);
        $this->assertSame('released', $this->hold($action)->state);
        $this->assertEquals($wake, $run->fresh()->next_action_at);
        app(ShipCommandExecutor::class)->clear($this->agent);
        $this->assertOldIntentIsNotDispatched($action);
        $this->assertSame(0, $this->purchasePosts());
    }

    public function test_stop_marker_committed_before_promotion_preserves_its_value_and_releases_the_hold(): void
    {
        [$run, $action] = $this->fleetIntent();
        $this->returnToCreditQueue($action);
        $marker = '2026-11-20T16:00:00Z';
        $reached = false;
        DB::connection()->beforeStartingTransaction(function () use ($action, $marker, &$reached) {
            if (! $reached && $this->startingExecutorTransaction('promoteCreditIntent')) {
                $reached = true;
                $action->update(['outcome' => ['stop_requested_at' => $marker]]);
            }
        });
        Queue::fake();

        $this->assertSame(CreditPromotionDisposition::PurchaseTransition, app(ShipCommandExecutor::class)->promoteCreditIntent($this->agent));

        $this->assertTrue($reached);
        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertSame(['stop_requested_at' => $marker], $action->outcome);
        $this->assertSame('released', $this->hold($action)->state);
        $this->assertEquals($run->next_action_at, $run->fresh()->next_action_at);
        Queue::assertNothingPushed();
    }

    public function test_promotion_rolls_back_the_reservation_change_before_settling_a_failed_action_update(): void
    {
        [$run, $action] = $this->fleetIntent();
        $this->returnToCreditQueue($action);
        $id = $action->id;
        DB::unprepared(<<<SQL
            CREATE TRIGGER ignore_purchase_promotion BEFORE UPDATE OF state ON ship_actions
            WHEN OLD.id = {$id} AND NEW.state = 'pending'
            BEGIN SELECT RAISE(IGNORE); END;

            CREATE TRIGGER require_promotion_rollback BEFORE UPDATE OF state ON ship_actions
            WHEN OLD.id = {$id} AND NEW.state = 'rejected'
                AND (SELECT state FROM credit_reservations WHERE ship_action_id = {$id}) <> 'reserved'
            BEGIN SELECT RAISE(ABORT, 'Provisional credit promotion still visible'); END;
            SQL);
        Queue::fake();

        $this->assertSame(CreditPromotionDisposition::PurchaseTransition, app(ShipCommandExecutor::class)->promoteCreditIntent($this->agent));

        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertSame('released', $this->hold($action)->state);
        $this->assertNull($action->sent_at);
        $this->assertFalse(ShipCommandExecutor::reissuableExpiry($action));
        $this->assertEquals($run->next_action_at, $run->fresh()->next_action_at);
        Queue::assertNothingPushed();
    }

    #[DataProvider('retryRaces')]
    public function test_purchase_retry_preserves_cancellation_or_other_evidence_committed_before_write(string $race): void
    {
        $this->setUpSaturation(seedCoverage: false);
        $action = $this->manualPurchase('SHIP_PROBE');
        $pending = in_array($race, ['queued_stop', 'engine_close', 'retry_without_race'], true);
        $defer = fn () => throw new RequestDeferred('Fixture retry', 5, 'blocked_until');
        $this->atWriteReservation($pending ? null : $defer, read: $pending ? $defer : null);
        $winner = null;
        $reached = false;
        DB::connection()->beforeStartingTransaction(function () use ($action, $race, &$winner, &$reached) {
            if ($reached || ! $this->startingExecutorTransaction('restorePurchase')) {
                return;
            }
            $reached = true;
            $current = $action->fresh();
            if (in_array($race, ['queued_stop', 'sending_stop'], true)) {
                $winner = app(ShipCommandExecutor::class)->stopManual($current);
                $this->travel(1)->second();
            } elseif ($race === 'engine_close') {
                app(EngineGate::class)->close();
                $winner = $action->fresh();
                $this->travel(1)->second();
            } elseif ($race === 'ttl_lapse') {
                $current->update(['expires_at' => now()->subSecond()]);
            } elseif ($race === 'receipt') {
                $current->update(['outcome' => ['response' => []]]);
                $winner = $current->fresh();
            } elseif ($race === 'other_attempt') {
                $current->update(['sent_at' => $current->sent_at->addSecond()]);
                $winner = $current->fresh();
            }
        });

        $this->job($action);

        $this->assertTrue($reached);
        $action->refresh();
        $this->assertSame(0, $this->purchasePosts());
        $this->assertFalse(ShipCommandExecutor::reissuableExpiry($action));
        if ($race === 'retry_without_race') {
            $this->assertSame(ActionState::Pending, $action->state);
            $this->assertNull($action->sent_at);

            return;
        }
        if ($race === 'other_attempt' || $race === 'receipt') {
            $this->assertSame($race === 'receipt' ? ActionState::Uncertain : ActionState::Sending, $action->state);
            $this->assertEquals($winner->sent_at, $action->sent_at);
            $this->assertSame($winner->outcome, $action->outcome);
        } else {
            $this->assertSame(ActionState::Rejected, $action->state);
            $this->assertNull($action->sent_at);
            if (in_array($race, ['queued_stop', 'engine_close'], true)) {
                $this->assertSame($winner->getAttributes(), $action->getAttributes());
            } elseif ($race === 'sending_stop') {
                $this->assertSame($winner->outcome['stop_requested_at'], $action->outcome['stop_requested_at']);
            }
        }
        $this->assertOldIntentIsNotDispatched($action);
    }

    public static function retryRaces(): iterable
    {
        foreach (['queued_stop', 'engine_close', 'retry_without_race', 'sending_stop', 'ttl_lapse', 'receipt', 'other_attempt'] as $race) {
            yield $race => [$race];
        }
    }

    #[DataProvider('balanceStopCases')]
    public function test_saturation_reissue_balance_wait_and_enqueue_preserve_a_committed_stop(string $case): void
    {
        [$run, $action] = $this->fleetIntent();
        $action->update(['expires_at' => now()->subSecond()]);
        $this->job($action);
        $this->assertTrue(ShipCommandExecutor::reissuableExpiry($action->refresh()));
        $this->travel(5)->seconds();
        $read = false;
        $stopped = null;
        $before = count($this->world()['calls']);
        $real = app(SpaceTradersApi::class);
        $api = Mockery::mock(SpaceTradersApi::class, [app(SpaceTradersClient::class)])->makePartial();
        $api->shouldReceive('agent')->andReturnUsing(function ($agent, $fresh = false) use ($real, $run, $case, &$read, &$stopped, $before) {
            $read = true;
            $paths = collect(array_slice($this->world()['calls'], $before))->pluck('path');
            $this->assertTrue($paths->contains('/systems/X1-TEST/waypoints/'.self::YARD.'/shipyard'));
            if (str_starts_with($case, 'stop')) {
                app(AutomationRunner::class)->stop($run);
                $stopped = $run->fresh();
                $this->travel(1)->second();
            }
            if ($case !== 'stop then valid balance') {
                throw new RequestDeferred('Fixture balance deferred', 70, 'blocked_until');
            }

            return $real->agent($agent, fresh: $fresh);
        });
        $this->app->instance(SpaceTradersApi::class, $api);

        app(AutomationRunner::class)->advance($run->id);

        $this->assertTrue($read);
        $run->refresh();
        if ($stopped) {
            $this->assertSame(RunState::Stopping, $run->state);
            $this->assertEquals($stopped->stop_requested_at, $run->stop_requested_at);
            $this->assertEquals($stopped->next_action_at, $run->next_action_at);
            $this->assertSame($stopped->blocked_reason, $run->blocked_reason);
        } else {
            $this->assertSame(RunState::Waiting, $run->state);
            $this->assertEquals(now()->addSeconds(70), $run->next_action_at);
            $this->assertSame('Fixture balance deferred', $run->blocked_reason);
        }
        $this->assertSame(1, ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->count());
        $this->assertSame(0, $this->purchasePosts());
    }

    public static function balanceStopCases(): array
    {
        return [['stop then defer'], ['defer'], ['stop then valid balance']];
    }

    public function test_reissue_schedules_retry_when_balance_refresh_is_skipped(): void
    {
        [$run, $action] = $this->fleetIntent();
        $action->update(['expires_at' => now()->subSecond()]);
        $this->job($action);
        $this->assertTrue(ShipCommandExecutor::reissuableExpiry($action->refresh()));
        $this->travel(5)->seconds();
        $before = count($this->world()['calls']);
        $rules = Mockery::mock(CommandRules::class, [app(SpaceTradersApi::class), app(AgentSynchronizer::class), app(MiningPlanner::class)])->makePartial();
        $rules->shouldReceive('refreshCredits')->once()->withArgs(fn ($agent, $owner, $creditWrite, $reconcile) => $owner->id === $run->id && $creditWrite && $reconcile)
            ->andReturn(false);
        $this->app->instance(CommandRules::class, $rules);

        app(AutomationRunner::class)->advance($run->id);

        $this->assertSame(RunState::Waiting, $run->refresh()->state);
        $this->assertEquals(now()->addSeconds(30), $run->next_action_at);
        $this->assertSame('Purchase balance refresh deferred.', $run->blocked_reason);
        $this->assertNotContains('/my/agent', collect(array_slice($this->world()['calls'], $before))->pluck('path')->all());
        $this->assertSame(1, ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->count());
    }

    #[DataProvider('receiptStopCases')]
    public function test_replacement_receipt_settles_spending_even_when_operator_stop_wins(bool $stop): void
    {
        [$run, $action] = $this->fleetIntent();
        $replacement = $this->replaceOnce($run, $action);
        if ($stop) {
            $this->editFixture(function (&$world) use ($run) {
                $world['write_stop_run_id'] = $run->id;
                $world['advance_after_write'] = 1;
            });
        }
        $writeTime = now()->toImmutable();
        $changedPrice = false;
        DB::connection()->beforeStartingTransaction(function () use (&$changedPrice) {
            if (! $changedPrice && $this->startingExecutorTransaction('finalPurchaseTransportCheck')) {
                $changedPrice = true;
                $this->editFixture(fn (&$world) => $world['shipyards'][self::YARD]['ships'][1]['purchasePrice'] = 350100);
            }
        });

        $this->job($replacement);

        $this->assertTrue($changedPrice);
        $this->assertSame(ActionState::Confirmed, $replacement->refresh()->state);
        $this->assertTrue($replacement->review_required);
        $this->assertSame('settled', $this->hold($replacement)->state);
        $this->assertSame(350100, $run->refresh()->totals['spending']);
        $this->assertSame($stop ? RunState::Stopping : RunState::Blocked, $run->state);
        if ($stop) {
            $this->assertEquals($writeTime, $run->stop_requested_at);
            $this->assertEquals($writeTime, $run->next_action_at);
            $this->assertNull($run->blocked_reason);
        }
        $this->assertSame(1, $this->purchasePosts());
    }

    public static function receiptStopCases(): array
    {
        return [[true], [false]];
    }

    public function test_replacement_completion_cannot_overtake_a_stop_during_the_balance_read(): void
    {
        [$run, $action] = $this->fleetIntent();
        $replacement = $this->replaceOnce($run, $action);
        $this->job($replacement);
        $this->assertSame(ActionState::Confirmed, $replacement->refresh()->state);
        $real = app(SpaceTradersApi::class);
        $api = Mockery::mock(SpaceTradersApi::class, [app(SpaceTradersClient::class)])->makePartial();
        $stop = null;
        $api->shouldReceive('agent')->once()->andReturnUsing(function ($agent, $fresh = false) use ($real, $run, &$stop) {
            app(AutomationRunner::class)->stop($run);
            $stop = $run->fresh();
            $this->travel(1)->second();

            return $real->agent($agent, fresh: $fresh);
        });
        $this->app->instance(SpaceTradersApi::class, $api);
        $runner = app(AutomationRunner::class);

        $runner->advance($run->id);

        $this->assertNotNull($stop);
        $this->assertSame(RunState::Stopping, $run->refresh()->state);
        $this->assertEquals($stop->next_action_at, $run->next_action_at);
        $this->assertEquals($stop->stop_requested_at, $run->stop_requested_at);
        $this->assertSame($this->agent->id, $run->active_agent_id);
        $runner->advance($run->id);
        $this->assertSame(RunState::Stopped, $run->refresh()->state);
        $this->assertSame(1, $this->purchasePosts());
    }

    #[DataProvider('runRetryRaces')]
    public function test_purchase_reservation_and_retry_preserve_a_committed_run_stop_or_loss_of_owner(string $boundary, bool $stop): void
    {
        [$run, $action] = $this->fleetIntent();
        $winner = null;
        $retryableVeto = false;
        if ($boundary === 'budget') {
            $this->atWriteReservation(fn () => throw new RequestDeferred('Fixture request delay', 5, 'blocked_until'));
        }
        $seam = $boundary === 'initial' ? 'executeWithWorkload' : 'restorePurchase';
        DB::connection()->beforeStartingTransaction(function () use ($run, $action, $boundary, $stop, $seam, &$winner, &$retryableVeto) {
            if ($boundary === 'v8_retry' && ! $retryableVeto && $this->startingExecutorTransaction('finalPurchaseTransportCheck')) {
                $retryableVeto = true;
                throw new GameException('Fixture availability change after verification', retryAfter: 1);
            }
            if ($winner !== null || ! $this->startingExecutorTransaction($seam)) {
                return;
            }
            $this->assertSame($boundary === 'initial' ? ActionState::Pending : ActionState::Sending, $action->fresh()->state);
            if ($stop) {
                app(AutomationRunner::class)->stop($run);
            } else {
                $run->update(['active_agent_id' => null]);
            }
            $winner = $run->fresh();
            $this->travel(1)->second();
        });

        $this->job($action);

        $this->assertNotNull($winner);
        $this->assertSame($boundary === 'v8_retry', $retryableVeto);
        $this->assertSame(ActionState::Rejected, $action->refresh()->state);
        $this->assertNull($action->sent_at);
        $this->assertFalse(ShipCommandExecutor::reissuableExpiry($action));
        $this->assertSame('released', $this->hold($action)->state);
        $this->assertSame($winner->state, $run->refresh()->state);
        $this->assertSame($winner->active_agent_id, $run->active_agent_id);
        $this->assertEquals($winner->next_action_at, $run->next_action_at);
        $this->assertEquals($winner->stop_requested_at, $run->stop_requested_at);
        $this->assertSame(0, $this->purchasePosts());
        $this->assertOldIntentIsNotDispatched($action);
    }

    public static function runRetryRaces(): iterable
    {
        foreach (['initial', 'budget', 'v8_retry'] as $boundary) {
            yield $boundary.' operator stop' => [$boundary, true];
            yield $boundary.' lost owner' => [$boundary, false];
        }
    }

    #[DataProvider('reissueVetoes')]
    public function test_uncertain_or_receipt_bearing_attempt_is_never_reissued(string $state, array $outcome, bool $review): void
    {
        [$run, $action] = $this->fleetIntent();
        $action->update([
            'state' => $state, 'review_required' => $review,
            'outcome' => ['expired_unsent' => true, 'confirmed_not_sent' => true] + $outcome,
            'sent_at' => $state === 'sending' ? now() : null,
        ]);

        $this->assertFalse(ShipCommandExecutor::reissuableExpiry($action->refresh()));
        $this->assertSame('attempt vetoed', app(CampaignProbeBootstrap::class)->reissueBlocker($run, 'attempt vetoed'));
        $this->assertSame(0, $this->purchasePosts());
    }

    public static function reissueVetoes(): iterable
    {
        foreach (['response', 'body_sha256', 'response_received_at', 'http_status', 'response_validated', 'stop_requested_at'] as $key) {
            yield $key => ['rejected', [$key => null], false];
        }
        yield 'review' => ['rejected', [], true];
        foreach (['preview', 'pending', 'uncertain', 'sending', 'acknowledged'] as $state) {
            yield $state => [$state, [], false];
        }
    }

    public function test_promotion_reconciles_a_preview_with_response_only_evidence_before_any_dispatch(): void
    {
        [$run, $action] = $this->fleetIntent();
        $this->returnToCreditQueue($action);
        $action->update(['outcome' => ['response' => []]]);
        $hold = $this->hold($action);
        $engine = app(EngineHost::class);
        $engine->calls = [];
        Queue::fake();

        $this->assertSame(CreditPromotionDisposition::PurchaseTransition, app(ShipCommandExecutor::class)->promoteCreditIntent($this->agent));

        $this->assertSame(ActionState::Uncertain, $action->refresh()->state);
        $this->assertSame(['response' => []], $action->outcome);
        $this->assertSame('uncertain', $this->hold($action)->state);
        $this->assertSame($hold->exposure, $this->hold($action)->exposure);
        $this->assertSame(RunState::Blocked, $run->refresh()->state);
        $this->assertSame([], $engine->calls);
        Queue::assertNothingPushed();
    }

    #[DataProvider('rejectionHelperVetoes')]
    public function test_rejection_helpers_leave_a_different_or_unowned_purchase_attempt_and_hold_intact(string $change): void
    {
        [$run, $action] = $this->fleetIntent();
        $expected = clone $action;
        $details = ['local_refusal' => ['stage' => 'prior_writer']];
        $action->update(['expires_at' => now()->subSecond(), 'outcome' => $details]);
        $owner = $run;
        if ($change === 'owner') {
            $owner = $run->replicate();
            $owner->forceFill(['active_agent_id' => null, 'ship_symbol' => 'LOCAL-2'])->save();
        } elseif ($change === 'rejected') {
            $action->update(['state' => ActionState::Rejected, 'message' => 'Rejected by another writer', 'settled_at' => now()]);
        } elseif ($change === 'sent_at') {
            $action->update(['sent_at' => now()]);
        } elseif ($change === 'unowned Sending') {
            $action->update(['state' => ActionState::Sending, 'sent_at' => now()]);
            $expected = $action->fresh();
        }
        $prior = $action->fresh()->getAttributes();
        $hold = $this->hold($action);
        $engine = app(EngineHost::class);
        $engine->calls = [];
        $executor = app(ShipCommandExecutor::class);
        $v8 = new \ReflectionMethod($executor, 'rejectUnsentRunAction');
        $legacy = new \ReflectionMethod($executor, 'rejectUnsentPurchase');

        $this->assertFalse($v8->invoke($executor, $this->agent, $expected, $owner, 'Fixture refusal', [], true, 'credit_queue'));
        $this->assertFalse($legacy->invoke($executor, $expected, $owner, 'Fixture refusal', 'credit_queue'));

        $this->assertSame($prior, $action->fresh()->getAttributes());
        $this->assertEquals($hold, $this->hold($action));
        $this->assertSame([], $engine->calls);
    }

    public static function rejectionHelperVetoes(): array
    {
        return [['owner'], ['rejected'], ['sent_at'], ['unowned Sending']];
    }

    private function replaceOnce(AutomationRun $run, ShipAction $action): ShipAction
    {
        $action->update(['expires_at' => now()->subSecond()]);
        $this->job($action);
        $this->assertTrue(ShipCommandExecutor::reissuableExpiry($action->refresh()));
        $this->travel(5)->seconds();
        app(AutomationRunner::class)->advance($run->id);

        return ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->whereKeyNot($action->id)->sole();
    }

    private function returnToCreditQueue(ShipAction $action): void
    {
        $action->update(['state' => ActionState::Preview, 'last_dispatched_at' => null]);
        DB::table('credit_reservations')->where('ship_action_id', $action->id)->update(['state' => 'reserved']);
    }

    private function assertOldIntentIsNotDispatched(ShipAction $action): void
    {
        $this->travel(31)->seconds();
        LocalEngine::whereKey(1)->update(['state' => 'running', 'gate_open' => true, 'heartbeat_at' => now()]);
        Queue::fake();
        $this->artisan('spacetraders:dispatch-due')->assertExitCode(0);
        Queue::assertNotPushed(ExecuteShipCommand::class, fn ($job) => $job->actionId === $action->id);
    }

    private function completeReplacement(AutomationRun $run, ShipAction $action): void
    {
        $this->travel(5)->seconds();
        app(AutomationRunner::class)->advance($run->id);
        $replacement = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->whereKeyNot($action->id)->sole();
        $this->assertSame(ActionState::Pending, $replacement->state);
        $this->assertSame('pending', $this->hold($replacement)->state);
        $this->job($action);
        $this->job($replacement);
        $this->assertSame(ActionState::Confirmed, $replacement->fresh()->state, $replacement->fresh()->message ?? '');
        $this->assertSame(1, $this->purchasePosts());
        $this->workRun($run);
        $this->assertSame(RunState::Completed, $run->refresh()->state, $run->blocked_reason ?? '');
    }

    private function bindAllowance(AutomationRun $run): string
    {
        $budget = app(RequestBudget::class);
        $allowance = $budget->admitMesh($this->agent->name, $this->agent->reset_id,
            ['hour' => 300, 'host' => 60, 'arm' => 0, 'trader' => 300], 35, 120);
        $this->assertNotNull($allowance);
        $budget->bindMesh($allowance, $run->id, 600);
        $run->update(['plan' => $run->plan + ['mesh_work' => 'buy_fleet', 'mesh_allowance' => $allowance]]);

        return $allowance;
    }

    private function probeRun(): AutomationRun
    {
        $this->setUpSaturation(seedCoverage: false, credits: 175000);
        $episode = $this->episode('mesh');
        $this->observeYard();
        $runner = app(AutomationRunner::class);
        $run = $runner->previewProbePurchase($this->agent, 'LOCAL-2', self::YARD, ['minutes' => 15], $episode);

        return $runner->startCampaign($this->agent, $run, $episode);
    }

    private function fleetIntent(?int $runSeconds = null, ?Closure $onRank = null): array
    {
        $this->setUpSaturation();
        $episode = $this->episode('fleet');
        $this->observeYard();
        $type = 'SHIP_LIGHT_HAULER';
        $candidate = ['type' => $type, 'yard' => self::YARD, 'purchaser' => 'LOCAL-1', 'slot' => 'trader', 'approved_ceiling' => 375000];
        $this->partialMock(CampaignSaturation::class)->shouldReceive('rankFleetPurchases')->atLeast()->twice()
            ->andReturnUsing(function ($grant, $episode, $agent, $candidates, $ignoreRun = null, $deadline = null, $exact = null) use ($candidate, $onRank) {
                $onRank?->__invoke($ignoreRun, $exact);

                return ['selected' => $candidate];
            });
        $runner = app(AutomationRunner::class);
        $run = $runner->preview($this->agent, 'LOCAL-1', ['minutes' => 15, 'strategy' => 'buy_fleet', 'plan_version' => 8,
            'shipyard' => self::YARD, 'ship_type' => $type, 'allocation_candidate' => $candidate], $episode);
        $runner->startCampaign($this->agent, $run, $episode);
        if ($runSeconds !== null) {
            $run->update(['expires_at' => now()->addSeconds($runSeconds)]);
        }
        $runner->advance($run->id);
        $action = ShipAction::where('automation_run_id', $run->id)->where('command', 'buy_ship')->first();
        $this->assertNotNull($action, $run->fresh()->blocked_reason ?? 'No fleet intent.');
        $this->assertSame(ActionState::Pending, $action->state);

        return [$run->refresh(), $action];
    }

    private function episode(string $flavor): CampaignEpisode
    {
        $body = Yaml::parseFile(base_path('campaigns/weekly-system-saturation-'.$flavor.'.yaml'));
        $body['agent']['symbol'] = $this->agent->name;
        $body['fleet']['auto_arm']['revision_hash'] = $this->armRevision->content_hash;

        return $this->primary(revision: app(CampaignPlaybook::class)->import(Yaml::dump($body, 12)));
    }

    private function observeYard(): void
    {
        app(AgentSynchronizer::class)->observe($this->agent, 'shipyard', self::YARD, $this->world()['shipyards'][self::YARD]);
    }

    private function addManualOffer(): void
    {
        $this->editFixture(function (&$world) {
            $world['shipyards'][self::YARD]['shipTypes'][] = ['type' => 'SHIP_MINING_DRONE'];
            $world['shipyards'][self::YARD]['ships'][] = $world['offer'];
        });
    }

    private function manualPurchase(string $type): ShipAction
    {
        return app(ShipCommandExecutor::class)->approve($this->agent, $this->previewManualPurchase($type));
    }

    private function previewManualPurchase(string $type): ShipAction
    {
        return app(ShipCommandExecutor::class)->preview($this->agent, 'LOCAL-1', 'buy_ship',
            ['shipType' => $type, 'waypointSymbol' => self::YARD, 'maxTotal' => 30000], (string) Str::uuid());
    }

    private function copyIntent(ShipAction $action, ActionState $state): ShipAction
    {
        $copy = $action->replicate();
        $copy->fill(['key' => (string) Str::uuid(), 'state' => $state])->save();

        return $copy;
    }

    private function reserve(AutomationRun $run, ShipAction $action): int
    {
        return app(StateChain::class)->reserveCredit($this->agent, 'run:'.$run->id, 'purchase', 'buy_ship', 350000, $action->id, $run->id, $run->ship_symbol);
    }

    private function hold(ShipAction $action): object
    {
        return DB::table('credit_reservations')->where('ship_action_id', $action->id)->sole();
    }

    private function job(ShipAction $action): void
    {
        (new ExecuteShipCommand($action->id))->handle(app(ShipCommandExecutor::class));
    }

    private function atWriteReservation(?Closure $callback, ?Closure $bound = null, ?Closure $governor = null, ?Closure $read = null): void
    {
        $this->app->instance(RequestBudget::class, new class($callback, $bound, $governor, $read) extends RequestBudget
        {
            public function __construct(private ?Closure $callback, private ?Closure $bound, private ?Closure $governor, private ?Closure $read) {}

            public function hasMesh(?string $id, string $agent, string $reset, int $run): bool
            {
                return $this->bound ? ($this->bound)() : parent::hasMesh($id, $agent, $reset, $run);
            }

            public function refreshMesh(string $id, string $agent, string $reset, int $run, array $limits, ?float $deadline = null): bool
            {
                return $this->governor ? ($this->governor)() : parent::refreshMesh($id, $agent, $reset, $run, $limits, $deadline);
            }

            public function reserve(?string $token = null, ?string $account = null, string $priority = 'discovery', float $maxWait = 0.75, ?float $baseMaxWait = null, ?float $deadline = null): void
            {
                if ($priority === 'write' && $this->callback !== null) {
                    $callback = $this->callback;
                    $this->callback = null;
                    $callback();
                } elseif ($priority !== 'write' && $this->read !== null) {
                    $callback = $this->read;
                    $this->read = null;
                    $callback();
                }
                parent::reserve($token, $account, $priority, $maxWait, $baseMaxWait, $deadline);
            }
        });
    }

    private function startingExecutorTransaction(string $method): bool
    {
        foreach (array_reverse(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) as $frame) {
            if (($frame['class'] ?? null) === ShipCommandExecutor::class && $frame['function'] === $method) {
                return true;
            }
        }

        return false;
    }

    private function purchasePosts(): int
    {
        return collect($this->world()['writes'])->where('path', '/my/ships')->count();
    }
}
