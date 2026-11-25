<?php

namespace Tests\Unit\Engine;

use App\Console\Commands\SuperviseLocalEngine;
use App\Gameplay\Engine\EngineAutoRecovery;
use App\Gameplay\Engine\EngineHost;
use App\Models\Agent;
use App\Models\LocalEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeEngineHost;
use Tests\TestCase;

class EngineAutoRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeEngineHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gameplay.local_engine_enabled' => true, 'gameplay.engine_contention_auto_restart' => true, 'gameplay.engine_contention_auto_restart_limit' => 2]);
        $this->host = new FakeEngineHost;
        $this->app->instance(EngineHost::class, $this->host);
        LocalEngine::create(['id' => 1, 'state' => 'faulted', 'session_id' => 'old', 'gate_open' => false, 'heartbeat_at' => now()->subMinutes(10),
            'stop_requested_at' => now()->subMinutes(10), 'processes' => [], 'message' => SuperviseLocalEngine::CONTENTION_FAULT_MESSAGE]);
    }

    public function test_it_restarts_a_contention_fault_within_its_limit(): void
    {
        $this->assertSame('restarted', app(EngineAutoRecovery::class)->attempt());
        $this->assertContains(['launch'], $this->host->calls);
        $this->assertSame('restarted', app(EngineAutoRecovery::class)->attempt());
        $this->assertStringContainsString('limit', app(EngineAutoRecovery::class)->attempt());
        $this->assertCount(2, array_filter($this->host->calls, fn ($call) => $call === ['launch']));
    }

    public function test_it_ignores_other_faults_and_disabled_recovery(): void
    {
        LocalEngine::whereKey(1)->update(['message' => 'Supervisor failed. Reconcile any reserved action before a fresh preview.']);
        $this->assertNull(app(EngineAutoRecovery::class)->attempt());
        LocalEngine::whereKey(1)->update(['message' => SuperviseLocalEngine::CONTENTION_FAULT_MESSAGE]);
        config(['gameplay.engine_contention_auto_restart' => false]);
        $this->assertNull(app(EngineAutoRecovery::class)->attempt());
        $this->assertNotContains(['launch'], $this->host->calls);
    }

    public function test_it_leaves_a_surviving_process_or_reserved_action_for_the_operator(): void
    {
        $this->host->alive = ['worker' => true];
        $this->assertStringContainsString('worker', app(EngineAutoRecovery::class)->attempt());
        $this->host->alive = [];
        $user = User::factory()->create();
        $agent = Agent::create(['user_id' => $user->id, 'name' => 'TESTER', 'token' => 'synthetic-only', 'faction' => 'VOID', 'reset_id' => 'r1'])->id;
        DB::table('ship_actions')->insert(['agent_id' => $agent, 'key' => 'k1', 'reset_id' => 'r1', 'ship_symbol' => 'TESTER-1', 'origin' => 'test',
            'command' => 'navigate', 'state' => 'uncertain', 'arguments' => '{}', 'expires_at' => now()->addHour()]);
        $this->assertStringContainsString('uncertain', app(EngineAutoRecovery::class)->attempt());
        $this->assertNotContains(['launch'], $this->host->calls);
    }
}
