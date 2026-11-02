<?php

namespace Tests\Feature;

use App\Gameplay\AgentSynchronizer;
use App\Gameplay\ManualReads;
use App\Models\Agent;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSpaceTraders;
use Tests\TestCase;

class FoundationReadTest extends TestCase
{
    use RefreshDatabase;

    protected string $file;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'st-foundation-');
        file_put_contents($this->file, json_encode(FakeSpaceTraders::world() + ['foundation' => true]));
        FakeSpaceTraders::install($this->file);
        $user = User::factory()->create();
        $this->agent = $user->agents()->create(['name' => 'LOCAL', 'token' => 'synthetic-agent-credential', 'account_id' => 'synthetic-account', 'faction' => 'COSMIC', 'reset_id' => '2026-10-25', 'canonical_identity' => AgentSynchronizer::identity('2026-10-25', 'LOCAL')]);
        $this->actingAs($user);
        app(AgentSynchronizer::class)->sync($this->agent);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    public static function reads(): array
    {
        return array_map(fn ($id) => [$id], array_keys(ManualReads::OPERATIONS));
    }

    #[DataProvider('reads')]
    public function test_each_dedicated_named_read_reaches_its_official_operation(string $operation): void
    {
        $all = ['systemSymbol' => 'X1-TEST', 'waypointSymbol' => 'X1-TEST-ROCK', 'shipSymbol' => 'LOCAL-1', 'factionSymbol' => 'COSMIC', 'agentSymbol' => 'LOCAL', 'contractId' => 'contract-1'];
        $parameters = array_intersect_key($all, array_flip(ManualReads::OPERATIONS[$operation][1]));
        $response = $this->postJson('/agents/'.$this->agent->id.'/reads', compact('operation', 'parameters'));
        $response->assertAccepted()->assertJsonPath('operation', $operation)->assertJsonStructure(['data', 'observed_at', 'source'])->assertDontSee('synthetic-agent-credential');
        $spec = json_decode(file_get_contents(base_path('docs/api/spacetraders-openapi-2.3.0.json')), true);
        foreach ($spec['paths'] as $path => $methods) {
            if (($methods['get']['operationId'] ?? '') !== $operation) {
                continue;
            }
            foreach ($parameters as $key => $value) {
                $path = str_replace('{'.$key.'}', $value, $path);
            }
            $calls = json_decode(file_get_contents($this->file), true)['calls'];
            $this->assertContains(['method' => 'GET', 'path' => $path], $calls);
        }
    }

    public function test_scalar_and_array_traits_pagination_and_full_fields_are_preserved(): void
    {
        foreach (['MARKETPLACE', ['MARKETPLACE']] as $traits) {
            $this->postJson('/agents/'.$this->agent->id.'/reads', ['operation' => 'get-system-waypoints', 'parameters' => ['systemSymbol' => 'X1-TEST', 'type' => 'PLANET', 'traits' => $traits, 'page' => 1, 'limit' => 1]])->assertAccepted()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.symbol', 'X1-TEST-PORT');
        }
        $this->postJson('/agents/'.$this->agent->id.'/reads', ['operation' => 'get-my-agent', 'parameters' => []])->assertAccepted()->assertJsonPath('data.shipCount', 1)->assertJsonPath('data.startingFaction', 'COSMIC');
    }

    public function test_cooldown_204_and_missing_raw_expiration_are_distinct(): void
    {
        $world = json_decode(file_get_contents($this->file), true);
        $world['cooldown_204'] = true;
        file_put_contents($this->file, json_encode($world));
        $input = ['operation' => 'get-ship-cooldown', 'parameters' => ['shipSymbol' => 'LOCAL-1']];
        $this->postJson('/agents/'.$this->agent->id.'/reads', $input)->assertAccepted()->assertJsonPath('no_content', true)->assertJsonPath('deadline', null);
        $world['cooldown_204'] = false;
        unset($world['ships'][0]['cooldown']['expiration']);
        file_put_contents($this->file, json_encode($world));
        $this->postJson('/agents/'.$this->agent->id.'/reads', $input)->assertAccepted()->assertJsonMissingPath('data.expiration')->assertJsonPath('deadline.deadlineSource', 'derived_from_remaining_seconds');
    }

    public function test_invalid_inputs_and_other_owners_are_rejected_without_transport(): void
    {
        $count = Http::recorded()->count();
        foreach ([['operation' => 'https://example.com', 'parameters' => []], ['operation' => 'get-systems', 'parameters' => ['page' => 0]], ['operation' => 'get-my-ship', 'parameters' => ['shipSymbol' => 'OTHER-1']], ['operation' => 'get-system-waypoints', 'parameters' => ['systemSymbol' => 'X1-TEST', 'traits' => ['BOGUS']]]] as $input) {
            $this->postJson('/agents/'.$this->agent->id.'/reads', $input)->assertUnprocessable();
        }
        $this->actingAs(User::factory()->create())->postJson('/agents/'.$this->agent->id.'/reads', ['operation' => 'get-status', 'parameters' => []])->assertNotFound();
        // A valid owner check can read status before rejecting an unknown saved ship, but no private ship request is made.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'OTHER-1'));
        $this->assertLessThanOrEqual($count + 1, Http::recorded()->count());
    }

    public function test_inherited_auth_uses_one_agent_bearer_and_public_directories_have_no_bearer(): void
    {
        foreach ([['get-faction', ['factionSymbol' => 'COSMIC'], '/factions/COSMIC'], ['get-ship-modules', ['shipSymbol' => 'LOCAL-1'], '/my/ships/LOCAL-1/modules'], ['get-supply-chain', [], '/market/supply-chain']] as [$operation, $parameters, $path]) {
            $this->postJson('/agents/'.$this->agent->id.'/reads', compact('operation', 'parameters'))->assertAccepted();
            Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/v2'.$path && $request->header('Authorization') === ['Bearer synthetic-agent-credential']);
        }
        $this->postJson('/agents/'.$this->agent->id.'/reads', ['operation' => 'get-systems', 'parameters' => []])->assertAccepted();
        Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/v2/systems' && ! $request->hasHeader('Authorization'));
    }

    public function test_multiple_traits_use_openapi_repeated_query_names_on_the_actual_wire(): void
    {
        $this->postJson('/agents/'.$this->agent->id.'/reads', ['operation' => 'get-system-waypoints', 'parameters' => ['systemSymbol' => 'X1-TEST', 'traits' => ['MARKETPLACE', 'SHIPYARD']]])->assertAccepted()->assertJsonPath('meta.total', 1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'traits=MARKETPLACE&traits=SHIPYARD') && ! str_contains($request->url(), 'traits%5B'));
    }

    public function test_malformed_manual_market_yard_and_waypoint_reads_cannot_replace_planning_observations(): void
    {
        $before = GameObservation::where('agent_id', $this->agent->id)->where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->firstOrFail()->data;
        $world = json_decode(file_get_contents($this->file), true);
        $world['goods'][0]['purchasePrice'] = 'invalid';
        $world['offer']['purchasePrice'] = -1;
        unset($world['waypoints'][0]['x']);
        file_put_contents($this->file, json_encode($world));
        foreach (['get-market', 'get-shipyard', 'get-waypoint'] as $operation) {
            $this->postJson('/agents/'.$this->agent->id.'/reads', ['operation' => $operation, 'parameters' => ['systemSymbol' => 'X1-TEST', 'waypointSymbol' => 'X1-TEST-ROCK']])->assertUnprocessable();
        }
        $this->assertSame($before, GameObservation::where('agent_id', $this->agent->id)->where('kind', 'market')->where('symbol', 'X1-TEST-ROCK')->firstOrFail()->data);
    }
}
