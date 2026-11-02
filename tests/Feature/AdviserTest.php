<?php

namespace Tests\Feature;

use App\Gameplay\Adviser;
use App\Gameplay\AdviserSelector;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\OpportunityEvaluator;
use App\Gameplay\OpportunityInvestigator;
use App\Models\AdviserDecision;
use App\Models\AdviserPolicy;
use App\Models\AutomationRun;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class AdviserTest extends TestCase
{
    use RefreshDatabase;

    private $agent;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'adviser-world-');
        file_put_contents($this->file, json_encode(FakeSpaceTraders::world()));
        FakeSpaceTraders::install($this->file);
        $user = User::factory()->create();
        $this->agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-only', 'account_id' => 'synthetic', 'faction' => 'COSMIC', 'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL')]);
        $this->actingAs($user);
        app(AgentSynchronizer::class)->sync($this->agent);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        unlink($this->file);
        parent::tearDown();
    }

    private function input(array $terms = []): array
    {
        return ['previous_id' => null, 'ship_symbol' => 'LOCAL-1', 'system_symbol' => 'X1-TEST', 'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'), 'acknowledge' => true,
            'terms' => array_replace(['activities' => ['wait', 'read', 'sale', 'refuel', 'mining', 'scout', 'contracts'], 'cargo' => [], 'reserve' => 10000,
                'max_spend' => 100, 'max_loss' => 100, 'max_seconds' => 1800, 'max_fuel' => 100, 'refuel_units' => 100, 'allow_trial' => true, 'api_request_cap' => 40,
                'exploration' => ['enabled' => false, 'max_cost' => 0, 'max_fuel' => 0, 'max_seconds' => 0, 'interval_seconds' => 0]], $terms)];
    }

    private function grant(array $terms = []): AdviserPolicy
    {
        $response = $this->postJson('/agents/'.$this->agent->id.'/adviser/policies', $this->input($terms))->assertStatus(202);

        return AdviserPolicy::findOrFail($response->json('id'));
    }

    private function assess(AdviserPolicy $policy, $investigation = null): AdviserDecision
    {
        return app(Adviser::class)->assess($this->agent->fresh(), $policy, $investigation);
    }

    private function candidates(AdviserDecision $decision, string $activity): array
    {
        return array_values(array_filter($decision->candidates, fn ($c) => $c['activity'] === $activity));
    }

    private function investigation()
    {
        $investigator = app(OpportunityInvestigator::class);
        $record = $investigator->create($this->agent, 'mining', 'X1-TEST');
        for ($i = 0; $i < 40 && $record->fresh()->status === 'investigating'; $i++) {
            $investigator->advance($this->agent, $record);
        }

        return $record->fresh();
    }

    public function test_grants_are_versioned_expiring_owner_scoped_and_cannot_silently_replace(): void
    {
        $policy = $this->grant();
        $this->assertSame(1, $policy->version);
        $this->postJson('/agents/'.$this->agent->id.'/adviser/policies', $this->input())->assertStatus(422)->assertJsonFragment(['message' => 'Policy version changed. Reload before granting a replacement.']);
        $input = $this->input(['max_spend' => 0]);
        $input['previous_id'] = $policy->id;
        $this->postJson('/agents/'.$this->agent->id.'/adviser/policies', $input)->assertStatus(202)->assertJsonPath('version', 2);
        $this->assertNotNull($policy->fresh()->revoked_at);
        $this->assertSame(100, $policy->terms['max_spend']);
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/agents/'.$this->agent->id.'/adviser/history')->assertNotFound();
        $this->postJson('/agents/'.$this->agent->id.'/adviser/policies/'.$policy->id.'/pause')->assertNotFound();
        $this->postJson('/agents/'.$this->agent->id.'/adviser/assess', ['policy_id' => $policy->id])->assertNotFound();
    }

    public function test_validation_rejects_unowned_ship_system_fractional_units_and_unbounded_exploration(): void
    {
        $input = $this->input();
        $input['ship_symbol'] = 'OTHER-1';
        $this->postJson('/agents/'.$this->agent->id.'/adviser/policies', $input)->assertStatus(422);
        $input['ship_symbol'] = 'LOCAL-1';
        $input['system_symbol'] = 'X1-OTHER';
        $this->postJson('/agents/'.$this->agent->id.'/adviser/policies', $input)->assertStatus(422);
        $input = $this->input(['cargo' => [['symbol' => 'IRON_ORE', 'units' => 1.5]]]);
        $this->postJson('/agents/'.$this->agent->id.'/adviser/policies', $input)->assertUnprocessable()->assertJsonValidationErrors('terms.cargo.0.units');
        $input = $this->input();
        $input['terms']['exploration']['enabled'] = true;
        $this->postJson('/agents/'.$this->agent->id.'/adviser/policies', $input)->assertUnprocessable()->assertJsonValidationErrors('terms.exploration.interval_seconds');
        $input['expires_at'] = now()->addDays(2)->format('Y-m-d\TH:i:s\Z');
        $this->postJson('/agents/'.$this->agent->id.'/adviser/policies', $input)->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    }

    public function test_replay_preserves_ids_hashes_and_snapshot_after_source_changes_without_any_dispatch(): void
    {
        $policy = $this->grant();
        $record = $this->investigation();
        Http::preventStrayRequests();
        $before = count(json_decode(file_get_contents($this->file), true)['writes']);
        $decision = $this->assess($policy, $record);
        $replay = app(AdviserSelector::class)->evaluate($decision->evidence);
        $this->assertSame($decision->candidates, $replay['candidates']);
        $this->assertSame($decision->selection, $replay['selection']);
        $this->assertSame(AdviserSelector::hash($decision->evidence), $decision->evidence_hash);
        config(['gameplay.snapshot_seconds' => 1, 'gameplay.scheduling_allowance_seconds' => 999]);
        $this->assertSame($replay, app(AdviserSelector::class)->evaluate($decision->evidence));
        $snapshot = $decision->candidates;
        GameObservation::where('agent_id', $this->agent->id)->update(['data' => json_encode(['changed' => true])]);
        $record->update(['candidates' => []]);
        $this->assertSame($snapshot, $decision->fresh()->candidates);
        $this->assertSame($before, count(json_decode(file_get_contents($this->file), true)['writes']));
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('ship_actions', 0);
        $this->assertFalse($decision->selection['execution_enabled']);
        $this->assertSame('disabled', $decision->selection['model_status']);
        $this->expectException(LogicException::class);
        $decision->update(['selection' => []]);
    }

    public function test_unknown_mining_yields_are_a_trial_not_an_income_forecast(): void
    {
        $policy = $this->grant();
        $decision = $this->assess($policy, $this->investigation());
        $ready = collect($this->candidates($decision, 'mining'))->firstWhere('classification', 'feasible');
        $this->assertNotNull($ready, json_encode($decision->candidates));
        $this->assertTrue($ready['trial']);
        $this->assertNull($ready['base_net']);
        $this->assertNull($ready['conservative_net']);
        $this->assertSame('explicit_unknown_income_trial', $decision->selection['rule']);
        $evidence = $decision->evidence;
        $evidence['policy']['terms']['allow_trial'] = false;
        $result = app(AdviserSelector::class)->evaluate($evidence);
        $this->assertEmpty(array_filter($result['candidates'], fn ($c) => $c['activity'] === 'mining' && $c['classification'] === 'feasible'));
    }

    public function test_protected_cargo_needs_explicit_units_and_sale_is_only_one_observed_batch(): void
    {
        $owned = Ship::where('agent_id', $this->agent->id)->first();
        $snapshot = $owned->snapshot;
        $snapshot['cargo'] = ['units' => 12, 'capacity' => 40, 'inventory' => [['symbol' => 'IRON_ORE', 'units' => 12]]];
        $owned->update(['snapshot' => $snapshot]);
        $policy = $this->grant();
        $sale = $this->candidates($this->assess($policy), 'sale')[0];
        $this->assertSame('blocked', $sale['classification']);
        $input = $this->input(['cargo' => [['symbol' => 'IRON_ORE', 'units' => 3]]]);
        $input['previous_id'] = $policy->id;
        $next = $this->postJson('/agents/'.$this->agent->id.'/adviser/policies', $input)->assertStatus(202)->json('id');
        $sale = $this->candidates($this->assess(AdviserPolicy::find($next)), 'sale')[0];
        $this->assertSame('feasible', $sale['classification']);
        $this->assertSame(3, $sale['target']['units']);
        $this->assertNull($sale['conservative_net']);
    }

    public function test_pause_invalidates_assessments_without_stopping_current_activity_and_stop_uses_runner(): void
    {
        $policy = $this->grant();
        $this->assess($policy);
        $run = AutomationRun::create(['agent_id' => $this->agent->id, 'active_agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1', 'state' => 'running', 'plan' => [], 'totals' => [], 'expires_at' => now()->addHour()]);
        $this->postJson('/agents/'.$this->agent->id.'/adviser/policies/'.$policy->id.'/pause')->assertStatus(202);
        $this->assertNull($run->fresh()->stop_requested_at);
        $this->getJson('/agents/'.$this->agent->id.'/adviser/history')->assertOk()->assertJsonPath('decisions.0.status', 'policy_invalidated');
        $this->postJson('/agents/'.$this->agent->id.'/adviser/assess', ['policy_id' => $policy->id])->assertStatus(422);
        $this->postJson('/agents/'.$this->agent->id.'/runs/'.$run->id.'/stop')->assertStatus(202);
        $this->assertNotNull($run->fresh()->stop_requested_at);
        $this->assertSame('stopping', $run->fresh()->state->value);
    }

    public function test_expiry_reset_credential_change_and_archived_agent_revoke_authority(): void
    {
        $policy = $this->grant();
        $this->travel(61)->minutes();
        $this->postJson('/agents/'.$this->agent->id.'/adviser/assess', ['policy_id' => $policy->id])->assertStatus(422);
        $this->travelBack();
        $changed = $this->agent->fresh();
        $changed->token = 'synthetic-replacement';
        $changed->save();
        $this->assertFalse($policy->available($changed));
        $this->assertSame(1, $changed->token_version);
        foreach ([['reset_id' => 'new-world'], ['token_version' => 2], ['connection_state' => 'reset']] as $change) {
            $agent = $this->agent->fresh();
            $agent->forceFill($change);
            $this->assertFalse($policy->available($agent));
        }
    }

    public function test_stale_quotes_incomplete_investigation_and_uncertain_actions_never_become_feasible(): void
    {
        $policy = $this->grant();
        $record = $this->investigation();
        $record->update(['status' => 'partial']);
        $decision = $this->assess($policy, $record);
        $this->assertEmpty(array_filter($this->candidates($decision, 'mining'), fn ($c) => $c['classification'] === 'feasible'));
        $evidence = $decision->evidence;
        $evidence['unsettled'] = [['state' => 'uncertain']];
        $evidence['investigation']['status'] = 'complete';
        $result = app(AdviserSelector::class)->evaluate($evidence);
        $this->assertEmpty(array_filter($result['candidates'], fn ($c) => ! in_array($c['activity'], ['wait', 'read']) && $c['classification'] === 'feasible'));
        $this->travel(181)->seconds();
        $decision = $this->assess($policy, $record);
        $this->assertTrue($decision->expires_at->isPast());
        $this->assertEmpty(array_filter($decision->candidates, fn ($c) => ! in_array($c['activity'], ['wait', 'read']) && $c['classification'] === 'feasible'));
    }

    public function test_reserve_equality_and_unknown_balance_prevent_positive_debits(): void
    {
        $policy = $this->grant();
        $decision = $this->assess($policy, $this->investigation());
        $evidence = $decision->evidence;
        $evidence['credits'] = 10000;
        $evidence['investigation']['observations']['credits'] = 10000;
        $result = app(AdviserSelector::class)->evaluate($evidence);
        $this->assertEmpty(array_filter($result['candidates'], fn ($c) => $c['max_debit'] > 0 && $c['classification'] === 'feasible'));
        $evidence['credits'] = null;
        $result = app(AdviserSelector::class)->evaluate($evidence);
        $this->assertEmpty(array_filter($result['candidates'], fn ($c) => $c['max_debit'] > 0 && $c['classification'] === 'feasible'));
    }

    public function test_exploration_requires_due_interval_named_question_and_explicit_allowances(): void
    {
        $policy = $this->grant(['exploration' => ['enabled' => true, 'max_cost' => 100, 'max_fuel' => 100, 'max_seconds' => 1000, 'interval_seconds' => 60]]);
        $decision = $this->assess($policy);
        $scout = collect($this->candidates($decision, 'scout'))->firstWhere('classification', 'feasible');
        $this->assertNotNull($scout, json_encode($decision->candidates));
        $this->assertNotEmpty($scout['question']);
        $this->assertSame('due_exploration', $decision->selection['rule']);
        $this->assertNull($scout['conservative_net']);
        $evidence = $decision->evidence;
        $evidence['last_exploration_at'] = now()->toIso8601String();
        $result = app(AdviserSelector::class)->evaluate($evidence);
        $this->assertEmpty(array_filter($result['candidates'], fn ($c) => $c['activity'] === 'scout' && $c['classification'] === 'feasible'));
    }

    public function test_selection_is_reproducible_with_income_capital_time_and_stable_id_ties(): void
    {
        $selector = app(AdviserSelector::class);
        $base = ['classification' => 'feasible', 'activity' => 'mining', 'plan_hash' => 'hash', 'priority' => 9, 'base_net' => 100, 'conservative_net' => 60, 'conservative_seconds' => 60, 'max_debit' => 10, 'duration_seconds' => 100, 'max_seconds' => 1800];
        $a = $base + ['id' => 'a'];
        $b = array_replace($base, ['id' => 'b', 'conservative_net' => 120, 'conservative_seconds' => 120, 'max_debit' => 9]);
        $c = array_replace($b, ['id' => 'c', 'duration_seconds' => 90]);
        $d = array_replace($c, ['id' => 'd']);
        foreach ([[$a, $b, $c, $d], [$d, $c, $a, $b]] as $rows) {
            $this->assertSame('c', $selector->select($rows)['candidate_id']);
        }
        $a['conservative_net'] = 61;
        $this->assertSame('a', $selector->select([$a, $b])['candidate_id']);
    }

    public function test_historical_income_uses_matching_worst_trip_inputs_and_rejects_nonpositive_history(): void
    {
        $policy = $this->grant();
        $record = $this->investigation();
        $input = $record->observations;
        $ship = $input['ships'][0];
        $sample = ['site' => 'X1-TEST-ROCK', 'market' => 'X1-TEST-ROCK', 'equipment' => OpportunityEvaluator::equipment($ship),
            'quote_signature' => OpportunityEvaluator::quoteSignature($input['markets']['X1-TEST-ROCK']),
            'initial_waypoint' => $ship['nav']['waypointSymbol'], 'initial_fuel' => $ship['fuel']['current'], 'at' => now()->toIso8601String()];
        $input['history'] = [array_merge($sample, ['contribution' => 90, 'seconds' => 100]), array_merge($sample, ['contribution' => 60, 'seconds' => 200]), array_merge($sample, ['contribution' => 120, 'seconds' => 150])];
        $record->update(['observations' => $input]);
        $legacy = $this->assess($policy, $record->fresh());
        foreach ($this->candidates($legacy, 'mining') as $candidate) {
            $this->assertNull($candidate['base_net'], 'Cash-only history cannot establish comparable cycle income');
            $this->assertNull($candidate['conservative_net']);
        }
        foreach ($input['history'] as &$entry) {
            $entry['accounting'] = ['state' => 'estimated_comparable', 'net_after_wear' => $entry['contribution'] - 10];
        }
        unset($entry);
        $record->update(['observations' => $input]);
        $decision = $this->assess($policy, $record->fresh());
        $mining = collect($this->candidates($decision, 'mining'))->firstWhere('classification', 'feasible');
        $this->assertSame(80, $mining['base_net']);
        $this->assertSame(50, $mining['conservative_net']);
        $this->assertSame(200, $mining['conservative_seconds']);
        $this->assertSame('historical_conservative_income_rate', $decision->selection['rule']);
        $comparable = $input;
        $input['history'][0]['at'] = now()->addHour()->toIso8601String();
        $record->update(['observations' => $input]);
        $future = $this->assess($policy, $record->fresh());
        $this->assertEmpty(array_filter($this->candidates($future, 'mining'), fn ($c) => $c['classification'] === 'feasible'));
        $input = $comparable;
        $input['history'][0]['accounting']['state'] = 'unresolved';
        $record->update(['observations' => $input]);
        $incomplete = $this->assess($policy, $record->fresh());
        $this->assertEmpty(array_filter($this->candidates($incomplete, 'mining'), fn ($c) => $c['classification'] === 'feasible'));
        $input = $comparable;
        $input['history'][0]['accounting']['net_after_wear'] = -5;
        $record->update(['observations' => $input]);
        $decision = $this->assess($policy, $record->fresh());
        $this->assertEmpty(array_filter($this->candidates($decision, 'mining'), fn ($c) => $c['classification'] === 'feasible'));
    }

    public function test_quote_invalidation_is_not_renewed_by_assessment_and_changed_bounds_change_hash_only(): void
    {
        $policy = $this->grant();
        $record = $this->investigation();
        $first = $this->assess($policy, $record);
        $evidence = $first->evidence;
        $evidence['policy']['terms']['max_spend'] = 99;
        $result = app(AdviserSelector::class)->evaluate($evidence);
        $this->assertSame(array_column($first->candidates, 'id'), array_column($result['candidates'], 'id'));
        $this->assertNotSame(array_column($first->candidates, 'plan_hash'), array_column($result['candidates'], 'plan_hash'));
        $quote = GameObservation::where('agent_id', $this->agent->id)->where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->sole();
        $data = $quote->data;
        $data['invalidated_by_action'] = 123;
        $quote->update(['data' => $data]);
        $next = $this->assess($policy, $record);
        $this->assertEmpty(array_filter($this->candidates($next, 'mining'), fn ($c) => $c['classification'] === 'feasible'));
    }

    public function test_read_collection_has_an_owned_request_cap_and_foreign_records_are_hidden(): void
    {
        $policy = $this->grant(['api_request_cap' => 1]);
        $response = $this->postJson('/agents/'.$this->agent->id.'/adviser/collect', ['policy_id' => $policy->id])->assertStatus(202)->assertJsonPath('coverage.request_limit', 1);
        $id = $response->json('id');
        $this->postJson('/agents/'.$this->agent->id.'/adviser/investigations/'.$id.'/advance')->assertStatus(202)->assertJsonPath('status', 'partial');
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/agents/'.$this->agent->id.'/adviser/investigations/'.$id.'/advance')->assertNotFound();
        $this->postJson('/agents/'.$this->agent->id.'/adviser/investigations/'.$id.'/cancel')->assertNotFound();
    }

    public function test_incomplete_or_absent_ship_observations_produce_missing_information_not_a_crash(): void
    {
        $policy = $this->grant();
        $owned = Ship::where('agent_id', $this->agent->id)->first();
        $owned->update(['snapshot' => ['symbol' => 'LOCAL-1', 'nav' => ['systemSymbol' => 'X1-TEST']]]);
        $decision = $this->assess($policy);
        $this->assertSame('read_only_refresh', $decision->selection['rule']);
        $owned->delete();
        $decision = $this->assess($policy);
        $this->assertSame('read_only_refresh', $decision->selection['rule']);
        Queue::assertNothingPushed();
    }

    public function test_decision_expiry_cannot_extend_a_still_fresh_quotes_lifetime(): void
    {
        $policy = $this->grant();
        $record = $this->investigation();
        $input = $record->observations;
        foreach ($input['market_times'] as &$at) {
            $at = now()->subSeconds(175)->toIso8601String();
        }
        unset($at);
        $record->update(['observations' => $input]);
        $decision = $this->assess($policy, $record->fresh());
        $this->assertTrue($decision->expires_at->lte(now()->addSeconds(5)));
        $this->assertTrue($decision->expires_at->isFuture());
    }

    public function test_grant_terms_are_immutable_and_history_paginates(): void
    {
        $policy = $this->grant();
        for ($i = 0; $i < 12; $i++) {
            $this->assess($policy);
        }
        $first = $this->getJson('/agents/'.$this->agent->id.'/adviser/history')->assertOk()->assertJsonPath('more_decisions', true);
        $this->assertCount(10, $first->json('decisions'));
        $before = $first->json('decisions.9.id');
        $second = $this->getJson('/agents/'.$this->agent->id.'/adviser/history?before_decision='.$before)->assertOk()->assertJsonPath('more_decisions', false);
        $this->assertCount(2, $second->json('decisions'));
        $this->expectException(LogicException::class);
        $policy->update(['terms' => []]);
    }
}
