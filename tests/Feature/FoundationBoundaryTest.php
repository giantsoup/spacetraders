<?php

namespace Tests\Feature;

use App\Enums\ActionState;
use App\Gameplay\ShipCommandExecutor;
use App\Gameplay\SpaceTradersApi;
use App\Models\ShipAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class FoundationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stop_cancels_any_unsent_manual_intent_and_duplicate_delivery_cannot_send(): void
    {
        $agent = User::factory()->create()->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC']);
        $action = ShipAction::create(['agent_id' => $agent->id, 'reset_id' => '2026-10-25', 'key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'origin' => 'manual', 'command' => 'orbit', 'arguments' => [], 'before_state' => [], 'state' => ActionState::Pending, 'expires_at' => now()->addMinute()]);
        Http::preventStrayRequests();
        $executor = app(ShipCommandExecutor::class);
        $executor->stopManual($action);
        $executor->execute($action->id);
        $executor->execute($action->id);
        $this->assertSame(ActionState::Rejected, $action->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_stop_after_reservation_preserves_settlement_state(): void
    {
        $agent = User::factory()->create()->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC']);
        $action = ShipAction::create(['agent_id' => $agent->id, 'reset_id' => '2026-10-25', 'key' => (string) Str::uuid(), 'ship_symbol' => 'LOCAL-1', 'origin' => 'manual', 'command' => 'refuel', 'arguments' => [], 'before_state' => [], 'state' => ActionState::Sending, 'sent_at' => now(), 'expires_at' => now()->addMinute()]);
        app(ShipCommandExecutor::class)->stopManual($action);
        $this->assertSame(ActionState::Sending, $action->fresh()->state);
        $this->assertNotEmpty($action->fresh()->outcome['stop_requested_at']);
    }

    public function test_patch_is_explicit_and_uses_only_approved_fields_and_agent_credential(): void
    {
        Http::fake(['*' => Http::response(['data' => ['nav' => FakeSpaceTraders::ship()['nav'], 'fuel' => ['current' => 100, 'capacity' => 100], 'events' => []]])]);
        $agent = User::factory()->create()->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC']);
        app(SpaceTradersApi::class)->mutate($agent, 'LOCAL-1', 'flight_mode', ['flightMode' => 'CRUISE', 'url' => 'ignored']);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->url() === 'https://api.spacetraders.io/v2/my/ships/LOCAL-1/nav' && $r->data() === ['flightMode' => 'CRUISE'] && $r->hasHeader('Authorization', 'Bearer synthetic'));
    }

    public function test_missing_raw_cooldown_expiration_and_construction_flag_survive_normalization(): void
    {
        $raw = FakeSpaceTraders::ship();
        unset($raw['cooldown']['expiration']);
        $raw['cooldown']['remainingSeconds'] = 30;
        $ship = app(SpaceTradersApi::class)->shipData($raw);
        $this->assertArrayNotHasKey('expiration', $ship['cooldown']);
        $this->assertSame('derived_from_remaining_seconds', $ship['cooldown']['deadlineSource']);
        $this->assertNotEmpty($ship['cooldown']['readyAt']);
    }
}
