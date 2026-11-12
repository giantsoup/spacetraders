<?php

namespace Tests\Feature;

use App\Gameplay\SpaceTradersApi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class WriteResponseSpecTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('transactions')]
    public function test_write_transaction_fixture_has_every_spec_required_field(string $command, array $arguments, string $schema): void
    {
        $file = tempnam(sys_get_temp_dir(), 'st-write-spec-');
        $world = FakeSpaceTraders::world() + ['foundation' => true];
        $world['ships'][0]['cargo'] = ['capacity' => 40, 'units' => 3, 'inventory' => [
            ['symbol' => 'IRON_ORE', 'units' => 1], ['symbol' => 'MOUNT_MINING_LASER_I', 'units' => 1], ['symbol' => 'MODULE_MINERAL_PROCESSOR_I', 'units' => 1],
        ]];
        $world['ships'][0]['fuel']['current'] = 50;
        file_put_contents($file, json_encode($world));
        FakeSpaceTraders::install($file);
        try {
            $agent = User::factory()->create()->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC']);
            $response = app(SpaceTradersApi::class)->mutate($agent, 'LOCAL-1', $command, $arguments);
            $spec = json_decode(file_get_contents(base_path('docs/api/spacetraders-openapi-2.3.0.json')), true, flags: JSON_THROW_ON_ERROR);
            foreach ($spec['components']['schemas'][$schema]['required'] as $field) {
                $this->assertArrayHasKey($field, $response['transaction'], $command.' '.$field);
            }
            $this->assertSame($command === 'buy_ship' ? $arguments['shipType'] : 'LOCAL-1', $response['transaction']['shipSymbol']);
        } finally {
            unlink($file);
        }
    }

    public static function transactions(): array
    {
        return [
            'purchase ship' => ['buy_ship', ['shipType' => 'SHIP_MINING_DRONE', 'waypointSymbol' => 'X1-TEST-ROCK'], 'ShipyardTransaction'],
            'purchase cargo' => ['buy', ['symbol' => 'IRON_ORE', 'units' => 1], 'MarketTransaction'],
            'sell cargo' => ['sell', ['symbol' => 'IRON_ORE', 'units' => 1], 'MarketTransaction'],
            'market refuel' => ['refuel', ['units' => 10], 'MarketTransaction'],
            'repair' => ['repair', [], 'RepairTransaction'],
            'scrap' => ['scrap', [], 'ScrapTransaction'],
            'install mount' => ['install_mount', ['symbol' => 'MOUNT_MINING_LASER_I'], 'ShipModificationTransaction'],
            'remove mount' => ['remove_mount', ['symbol' => 'MOUNT_MINING_LASER_I'], 'ShipModificationTransaction'],
            'install module' => ['install_module', ['symbol' => 'MODULE_MINERAL_PROCESSOR_I'], 'ShipModificationTransaction'],
            'remove module' => ['remove_module', ['symbol' => 'MODULE_MINERAL_PROCESSOR_I'], 'ShipModificationTransaction'],
            'jump' => ['jump', ['waypointSymbol' => 'X1-TEST-PORT'], 'MarketTransaction'],
        ];
    }

    public function test_offered_contract_can_omit_optional_delivery_terms(): void
    {
        Http::preventStrayRequests();
        $contract = FakeSpaceTraders::contract();
        $contract['type'] = 'TRANSPORT';
        unset($contract['terms']['deliver']);
        Http::fake(['api.spacetraders.io/v2/my/ships/LOCAL-1/negotiate/contract' => Http::response(['data' => ['contract' => $contract]], 201)]);
        $agent = User::factory()->create()->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC']);
        $response = app(SpaceTradersApi::class)->mutate($agent, 'LOCAL-1', 'negotiate', []);
        $this->assertSame([], $response['contract']['terms']['deliver']);
        $this->assertFalse($response['contract']['accepted']);
    }
}
