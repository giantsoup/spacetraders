<?php

namespace Tests\Feature;

use App\Gameplay\CreditAccounting;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\ShipAction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CreditAccountingTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        $this->agent = User::factory()->create()->agents()->create([
            'name' => 'LOCAL', 'token' => 'synthetic-only', 'faction' => 'VOID', 'reset_id' => '2026-10-25', 'credits' => 1000,
        ]);
    }

    public function test_summary_reads_one_select_without_starting_a_transaction(): void
    {
        $queries = [];
        $transactionsStarted = 0;
        $transactionLevel = DB::transactionLevel();
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = ['sql' => $query->sql, 'transaction_level' => $query->connection->transactionLevel()];
        });
        Event::listen(TransactionBeginning::class, function () use (&$transactionsStarted) {
            $transactionsStarted++;
        });

        app(CreditAccounting::class)->summary($this->agent);

        $this->assertSame(0, $transactionsStarted, 'Summary opened a transaction or savepoint.');
        $this->assertCount(1, $queries);
        $this->assertMatchesRegularExpression('/^select\b/i', ltrim($queries[0]['sql']));
        $this->assertSame($transactionLevel, $queries[0]['transaction_level']);
        $this->assertSame($transactionLevel, DB::transactionLevel());
    }

    public function test_summary_explains_a_persisted_sale_when_the_agent_model_is_stale(): void
    {
        app(CreditAccounting::class)->baseline($this->agent);
        DB::transaction(function () {
            $this->persistCredits(1250);
            $this->recordSale(250, now());
        });

        $summary = app(CreditAccounting::class)->summary($this->agent);

        $this->assertSame(0, $summary['unexplained_change']);
        $this->assertSame(250, $summary['total_balance_change']);
    }

    public function test_summary_reports_an_unrecorded_persisted_delta_when_the_agent_model_is_stale(): void
    {
        app(CreditAccounting::class)->baseline($this->agent);
        $this->persistCredits(875);

        $this->assertSame(-125, app(CreditAccounting::class)->summary($this->agent)['unexplained_change']);
    }

    public function test_summary_preserves_a_null_persisted_balance(): void
    {
        app(CreditAccounting::class)->baseline($this->agent);
        $this->persistCredits(null);

        $summary = app(CreditAccounting::class)->summary($this->agent);

        $this->assertNull($summary['total_balance_change']);
        $this->assertNull($summary['unexplained_change']);
    }

    public function test_summary_without_a_baseline_includes_all_entries_for_the_persisted_reset(): void
    {
        app(CreditAccounting::class)->baseline($this->agent);
        $this->recordSale(500, now());
        $reset = '2026-11-01';
        Agent::whereKey($this->agent->id)->update(['reset_id' => $reset]);
        $this->recordSale(30, now()->subDay(), reset: $reset);
        $this->recordSale(70, now(), reset: $reset);

        $summary = app(CreditAccounting::class)->summary($this->agent);

        $this->assertNull($summary['baseline']);
        $this->assertNull($summary['total_balance_change']);
        $this->assertNull($summary['unexplained_change']);
        $this->assertSame(100, $summary['confirmed_change']);
        $this->assertSame(100, $summary['manual_change']);
    }

    public function test_summary_uses_the_baseline_cutoff_only_for_confirmed_change(): void
    {
        $observedAt = now()->subMinutes(2);
        $userStartingAt = now()->subMinute();
        DB::table('agent_credit_baselines')->insert([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id,
            'credits' => 1000, 'source' => 'first_observed_local', 'observed_at' => $observedAt,
            'user_starting_credits' => 850, 'user_starting_at' => $userStartingAt,
        ]);
        $run = AutomationRun::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'ship_symbol' => 'LOCAL-1',
            'state' => 'completed', 'strategy' => 'trade', 'plan' => [], 'totals' => [], 'expires_at' => now(),
        ]);
        $this->recordSale(40, now()->subMinutes(3));
        $this->recordSale(120, now(), run: $run);
        DB::table('agent_balance_reviews')->insert([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'adjustment' => 15,
            'observed_balance' => 1135, 'note' => 'Reviewed external credit adjustment.', 'reviewed_at' => now(),
        ]);
        $this->agent->update(['credits' => 1140]);

        $summary = app(CreditAccounting::class)->summary($this->agent);

        $this->assertSame([
            'credits' => 1000, 'source' => 'first_observed_local', 'user_starting_credits' => 850,
            'user_starting_at' => $userStartingAt->toDateTimeString(), 'observed_at' => $observedAt->toDateTimeString(),
        ], $summary['baseline']);
        $this->assertSame(120, $summary['confirmed_change']);
        $this->assertSame(40, $summary['manual_change']);
        $this->assertSame(15, $summary['reviewed_adjustments']);
        $this->assertSame(290, $summary['total_balance_change']);
        $this->assertSame(5, $summary['unexplained_change']);
    }

    private function persistCredits(?int $credits): void
    {
        Agent::whereKey($this->agent->id)->update(['credits' => $credits]);
    }

    private function recordSale(int $credits, CarbonInterface $at, ?AutomationRun $run = null, ?string $reset = null): void
    {
        $reset ??= $this->agent->reset_id;
        $action = ShipAction::create([
            'agent_id' => $this->agent->id, 'automation_run_id' => $run?->id, 'ship_symbol' => 'LOCAL-1',
            'key' => 'sale-'.$credits, 'reset_id' => $reset, 'command' => 'sell', 'state' => 'confirmed',
            'origin' => $run ? 'automation' : 'manual', 'arguments' => ['symbol' => 'IRON_ORE', 'units' => 1],
            'outcome' => ['credit_delta' => $credits], 'expires_at' => $at, 'settled_at' => $at,
        ]);
        $this->travelTo($at, fn () => app(CreditAccounting::class)->record($this->agent, $action, $credits));
    }
}
