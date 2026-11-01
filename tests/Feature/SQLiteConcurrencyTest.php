<?php

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Gameplay\AgentSynchronizer;
use App\Gameplay\ShipCommandExecutor;
use App\Models\LocalEngine;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class SQLiteConcurrencyTest extends TestCase
{
    public function test_engine_heartbeat_cannot_break_action_reservation_by_invalidating_a_read_snapshot(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'st-sqlite-race-');
        $world = $file.'.json';
        file_put_contents($world, json_encode(FakeSpaceTraders::world()));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $file, 'gameplay.local_engine_enabled' => true]);
        DB::purge();
        try {
            Artisan::call('migrate:fresh', ['--force' => true]);
            FakeSpaceTraders::install($world);
            $user = User::factory()->create();
            $agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-race-token', 'account_id' => 'fixture', 'faction' => 'COSMIC', 'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL')]);
            LocalEngine::create(['id' => 1, 'state' => 'running', 'session_id' => (string) Str::uuid(), 'gate_open' => true, 'heartbeat_at' => now(), 'processes' => ['supervisor' => getmypid(), 'worker' => getmypid(), 'scheduler' => getmypid()]]);
            app(AgentSynchronizer::class)->sync($agent);
            $executor = app(ShipCommandExecutor::class);
            $action = $executor->preview($agent, 'LOCAL-1', 'orbit', [], (string) Str::uuid());
            $executor->approve($agent, $action);
            $contender = new \PDO('sqlite:'.$file);
            $contender->exec('PRAGMA busy_timeout = 1');
            $injected = false;
            DB::listen(function ($query) use (&$injected, $contender) {
                if (! $injected && $query->connection->transactionLevel() > 0 && str_starts_with($query->sql, 'select * from "local_engines"')) {
                    $injected = true;
                    try {
                        $contender->exec("UPDATE local_engines SET message = 'Concurrent heartbeat' WHERE id = 1");
                    } catch (\PDOException $e) {
                        // IMMEDIATE transactions reserve the writer before taking the gate snapshot.
                        if (($e->errorInfo[1] ?? null) !== 5) {
                            throw $e;
                        }
                    }
                }
            });
            $executor->execute($action->id);
            $this->assertTrue($injected, 'The real engine gate transaction must face a competing writer.');
            $this->assertSame(ActionState::Confirmed, $action->fresh()->state, $action->fresh()->message);
            $this->assertCount(1, json_decode(file_get_contents($world), true)['writes']);
        } finally {
            DB::purge();
            foreach ([$file, $file.'-wal', $file.'-shm', $world] as $owned) {
                if (is_file($owned)) {
                    unlink($owned);
                }
            }
        }
    }
}
