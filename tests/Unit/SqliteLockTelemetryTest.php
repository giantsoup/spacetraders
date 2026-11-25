<?php

namespace Tests\Unit;

use App\Support\SqliteLockTelemetry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SqliteLockTelemetryTest extends TestCase
{
    public function test_it_logs_an_outermost_transaction_over_the_threshold_once(): void
    {
        (new SqliteLockTelemetry(0.001))->register($this->app['events']);
        $logged = [];
        Log::shouldReceive('channel')->with('engine')->andReturnSelf();
        Log::shouldReceive('getChannels')->andReturn([]);
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$logged) {
            $logged[] = [$message, $context];
        });

        DB::transaction(function () {
            DB::transaction(fn () => DB::select('select 1'));
            usleep(5000);
        });

        $transactions = array_values(array_filter($logged, fn ($entry) => $entry[0] === 'Long SQLite write transaction.'));
        $this->assertCount(1, $transactions);
        $this->assertSame('commit', $transactions[0][1]['outcome']);
        $this->assertSame(1, $transactions[0][1]['statements']);
        $this->assertIsArray($transactions[0][1]['begun_at']);
    }
}
