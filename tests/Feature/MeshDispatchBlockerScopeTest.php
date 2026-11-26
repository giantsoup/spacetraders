<?php

namespace Tests\Feature;

use App\Gameplay\ActionInFlight;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\ShipCommandExecutor;
use App\Models\ShipAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\SaturationFixture;
use Tests\TestCase;

class MeshDispatchBlockerScopeTest extends TestCase
{
    use RefreshDatabase;
    use SaturationFixture;

    protected function tearDown(): void
    {
        $this->tearDownSaturation();
        parent::tearDown();
    }

    public static function blockers(): iterable
    {
        foreach (['pending', 'sending'] as $state) {
            yield [$state, 'navigate', 'LOCAL-2', true, [], 'ship', 'pending'];
            yield [$state, 'transfer', 'LOCAL-2', true, [], 'agent', 'pending'];
            yield [$state, 'refuel', 'LOCAL-2', true, [], 'agent', 'pending'];
            yield [$state, 'refuel', 'LOCAL-1', true, [], 'agent', 'pending'];
            yield [$state, 'navigate', 'LOCAL-2', false, [], 'agent', 'pending'];
            yield [$state, 'navigate', 'LOCAL-1', false, [], 'agent', 'pending'];
            yield [$state, 'navigate', 'LOCAL-2', true, ['body_sha256' => 'hash'], 'agent', 'uncertain_or_review'];
            yield [$state, 'navigate', 'LOCAL-2', true, ['response_received_at' => 'timestamp'], 'agent', 'uncertain_or_review'];
            yield [$state, 'buy_ship', 'LOCAL-1', true, ['response' => []], 'agent', 'uncertain_or_review'];
        }
        yield ['uncertain', 'navigate', 'LOCAL-2', true, [], 'agent', 'uncertain_or_review'];
        yield ['uncertain', 'navigate', 'LOCAL-2', false, [], 'agent', 'uncertain_or_review'];
        yield ['confirmed', 'navigate', 'LOCAL-2', false, [], 'agent', 'uncertain_or_review'];
    }

    #[DataProvider('blockers')]
    public function test_clear_classifies_blocker_scope_accurately(string $state, string $command, string $ship, bool $split, array $outcome, string $scope, string $kind): void
    {
        $this->setUpSaturation(hubs: 2, seedCoverage: false);
        $body = Yaml::parseFile(base_path('campaigns/weekly-system-saturation-fleet.yaml'));
        $body['agent']['symbol'] = $this->agent->name;
        $body['fleet']['auto_arm']['revision_hash'] = $this->armRevision->content_hash;
        $body['fleet']['saturation']['per_ship_noncredit_locks'] = $split;
        $this->primary(revision: app(CampaignPlaybook::class)->import(Yaml::dump($body, 12)));
        $blocker = ShipAction::create([
            'agent_id' => $this->agent->id, 'reset_id' => $this->agent->reset_id, 'key' => 'blocker',
            'ship_symbol' => $ship, 'origin' => 'manual', 'command' => $command, 'state' => $state,
            'arguments' => [], 'outcome' => $outcome, 'expires_at' => now()->addMinute(), 'review_required' => $state === 'confirmed',
        ]);

        try {
            app(ShipCommandExecutor::class)->clear($this->agent->refresh(), defer: true, ship: 'LOCAL-2', command: 'navigate');
            $this->fail('The action must prevent dispatch.');
        } catch (ActionInFlight $exception) {
            $this->assertSame($scope, $exception->scope);
            $this->assertSame($kind, $exception->kind);
            $this->assertFalse($exception->uncertain);
            $this->assertSame($scope === 'ship', $exception->shipLocalPending());
            if ($kind === 'pending' || $outcome !== []) {
                $this->assertSame([$ship], $exception->ships);
                $this->assertSame([$blocker->id], $exception->actionIds);
                $this->assertSame($kind === 'pending' ? 5 : 0, $exception->retryAfter);
            }
        }
    }
}
