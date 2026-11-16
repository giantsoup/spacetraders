<?php

namespace Tests\Feature;

use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignStatus;
use App\Models\CampaignEvent;
use App\Models\CampaignGrant;
use App\Models\GameObservation;
use App\Models\Ship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CampaignStatusFixture;
use Tests\TestCase;

class CampaignStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo(now());
        $this->operator = User::factory()->create();
        config(['gameplay.operator_id' => (string) $this->operator->id]);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_operator_sees_local_status_and_bounded_props_without_credentials(): void
    {
        ['grant' => $grant, 'episode' => $episode, 'run' => $run] = CampaignStatusFixture::seed($this->operator);
        $response = $this->actingAs($this->operator)->get('/campaign')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('Campaign/Status')
            ->where('auth.campaignOperator', true)->where('grant.id', $grant->id)->where('grant.active', true)
            ->where('grant.revision.version', 1)->where('grant.pending_grant', null)
            ->where('episode.id', $episode->id)->where('episode.current_credits', 115000)->where('episode.net', 15000)
            ->where('episode.hours_elapsed', 2)->where('currentRun.id', $run->id)->where('currentRun.phase', 'buy')
            ->where('currentRun.plan.good', 'IRON_ORE')->where('lastDecision.rejections.0.reason', 'quote_stale')
            ->where('lastDecision.rejections.0.count', 2)->where('health.engine.state', 'running')->has('health.problems', 0)
            ->has('credits', 24)->where('credits.23.credits', 115000)->has('loads', 1)->where('loads.0.units', 20)
            ->where('loads.0.mode', 'proof')->has('ships', 1)->where('ships.0.fuel.current', 90)->has('events', 2));
        $this->assertStringNotContainsString('synthetic-only', $response->getContent());
    }

    public function test_guests_redirect_and_non_operators_get_404_for_every_endpoint(): void
    {
        ['grant' => $grant, 'run' => $run] = CampaignStatusFixture::seed($this->operator);
        $this->get('/campaign')->assertRedirect('/login');
        foreach (['pause', 'resume'] as $action) {
            $this->post('/campaign/'.$action)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->create());
        $this->get('/campaign')->assertNotFound();
        foreach (['pause', 'resume'] as $action) {
            $this->post('/campaign/'.$action)->assertNotFound();
        }
        $this->assertTrue($grant->fresh()->available());
        $this->assertSame('running', $run->fresh()->state->value);
        $this->get('/profile')->assertInertia(fn (Assert $page) => $page->where('auth.campaignOperator', false));
        config(['gameplay.operator_id' => null]);
        $this->actingAs($this->operator)->get('/campaign')->assertNotFound();
    }

    public function test_pause_stops_campaign_work_and_resume_clears_authority_hold(): void
    {
        ['grant' => $grant, 'run' => $run] = CampaignStatusFixture::seed($this->operator);
        $this->actingAs($this->operator)->from('/campaign')->post('/campaign/pause')
            ->assertRedirect('/campaign')->assertSessionHas('status', 'Campaign paused.');
        $this->assertSame('operator_pause', $grant->fresh()->pause_reason);
        $this->assertSame('stopping', $run->fresh()->state->value);
        $this->assertNotNull($run->fresh()->stop_requested_at);
        $this->get('/campaign')->assertInertia(fn (Assert $page) => $page->where('grant.active', false)->has('health.problems', 1));
        $this->post('/campaign/resume')->assertRedirect('/campaign')
            ->assertSessionHas('status', 'Campaign resumed; the next decision uses fresh state.');
        $this->assertTrue($grant->fresh()->available());
        $this->assertNull($grant->fresh()->last_directed_at);
        $this->assertDatabaseHas('campaign_events', ['kind' => 'resume', 'campaign_grant_id' => $grant->id]);
    }

    public function test_ship_scenes_use_saved_navigation_and_waypoint_knowledge_only(): void
    {
        ['agent' => $agent, 'ship' => $ship, 'episode' => $episode] = CampaignStatusFixture::seed($this->operator);
        $episode->update(['state' => ['role_ships' => ['command' => 'OFFLINE-1', 'probe' => 'OFFLINE-2']]]);
        $snapshot = $ship->snapshot;
        $snapshot['nav']['systemSymbol'] = 'X1-DEMO';
        $ship->update(['snapshot' => $snapshot]);
        foreach (['SOURCE' => 'PLANET', 'BUYER' => 'ORBITAL_STATION'] as $suffix => $type) {
            GameObservation::create(['agent_id' => $agent->id, 'reset_id' => $agent->reset_id, 'kind' => 'waypoint',
                'symbol' => 'X1-DEMO-'.$suffix, 'observed_at' => now(),
                'data' => ['symbol' => 'X1-DEMO-'.$suffix, 'systemSymbol' => 'X1-DEMO', 'type' => $type,
                    'traits' => [['symbol' => 'MARKETPLACE']], 'description' => 'Saved local waypoint']]);
        }
        $departure = now()->subMinute()->toIso8601String();
        $arrival = now()->addMinute()->toIso8601String();
        $transit = $snapshot;
        $transit['registration']['role'] = 'SATELLITE';
        $transit['nav']['status'] = 'IN_TRANSIT';
        $transit['nav']['route'] = ['departureTime' => $departure, 'arrival' => $arrival,
            'destination' => ['symbol' => 'X1-DEMO-BUYER', 'type' => 'ORBITAL_STATION', 'secret' => 'not-public']];
        Ship::create(['agent_id' => $agent->id, 'symbol' => 'OFFLINE-2', 'observed_at' => now(), 'snapshot' => $transit]);
        $unknown = $snapshot;
        $unknown['nav']['waypointSymbol'] = 'X1-DEMO-UNSAVED';
        unset($unknown['nav']['status']);
        Ship::create(['agent_id' => $agent->id, 'symbol' => 'OFFLINE-3', 'observed_at' => now(), 'snapshot' => $unknown]);
        $this->actingAs($this->operator)->get('/campaign')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('ships', 3)->where('ships.0.symbol', 'OFFLINE-1')->where('ships.0.role', 'command')
            ->where('ships.0.waypoint', 'X1-DEMO-SOURCE')->where('ships.0.nav_status', 'DOCKED')
            ->where('ships.0.nav.status', 'DOCKED')->where('ships.0.nav.waypointSymbol', 'X1-DEMO-SOURCE')
            ->where('ships.0.nav.route.departureTime', null)->where('ships.0.nav.route.arrival', null)
            ->where('ships.0.nav.route.destination', ['symbol' => null, 'type' => null])
            ->where('ships.0.locations.0.symbol', 'X1-DEMO-SOURCE')->where('ships.0.locations.0.type', 'PLANET')
            ->where('ships.0.locations.0.traits', [['symbol' => 'MARKETPLACE']])
            ->where('ships.0.fuel.current', 90)->where('ships.0.cargo.units', 20)
            ->where('ships.1.role', 'probe')->where('ships.1.nav_status', 'IN_TRANSIT')->where('ships.1.waypoint', 'X1-DEMO-SOURCE')
            ->where('ships.1.nav.route.departureTime', $departure)->where('ships.1.nav.route.arrival', $arrival)
            ->where('ships.1.nav.route.destination', ['symbol' => 'X1-DEMO-BUYER', 'type' => 'ORBITAL_STATION'])
            ->has('ships.1.locations', 2)->where('ships.1.locations.1.symbol', 'X1-DEMO-BUYER')
            ->where('ships.1.locations.1.type', 'ORBITAL_STATION')->where('ships.1.locations.1.traits', [['symbol' => 'MARKETPLACE']])
            ->where('ships.2.role', 'fleet')->where('ships.2.nav.status', null)->where('ships.2.nav.waypointSymbol', 'X1-DEMO-UNSAVED')
            ->where('ships.2.locations.0.type', null)->where('ships.2.locations.0.traits', null));
    }

    public function test_resume_surfaces_game_errors_without_changing_an_expired_grant(): void
    {
        $revision = app(CampaignPlaybook::class)->import(file_get_contents(base_path('campaigns/weekly-default.yaml')));
        $grant = app(CampaignControl::class)->approve($revision, $this->operator, now()->toImmutable()->addMinute());
        app(CampaignControl::class)->pause($grant, 'operator_pause');
        $this->travel(2)->minutes();
        $this->actingAs($this->operator)->from('/campaign')->post('/campaign/resume')
            ->assertRedirect('/campaign')->assertSessionHasErrors(['campaign' => 'Grant expired. Approve a new grant.']);
        $this->assertNotNull($grant->fresh()->paused_at);
    }

    public function test_projection_tree_covers_every_pinned_ship_schema_leaf_and_only_the_ready_at_extension(): void
    {
        $schema = $this->shipSchema();
        $documented = $this->schemaLeaves($schema['components']['schemas']['Ship'], $schema);
        $projected = $this->projectionLeaves(CampaignStatus::SHIP_DETAILS);
        $documented['cooldown.readyAt'] = 'string';
        ksort($documented);
        ksort($projected);
        $this->assertSame($documented, $projected);
    }

    public function test_matrix_roundtrips_all_documented_values_and_preserves_missing_null_empty_and_instance_order(): void
    {
        CampaignStatusFixture::seed($this->operator, shipMatrix: true);
        $schema = $this->shipSchema();
        $shipSchema = $schema['components']['schemas']['Ship'];
        $schema['components']['schemas']['Cooldown']['properties']['readyAt'] = ['type' => 'string'];
        $ships = collect(app(CampaignStatus::class)->read()['ships'])->keyBy('symbol');
        $scenarios = CampaignStatusFixture::shipScenarios();
        $this->assertCount(24, $scenarios);
        $this->assertCount(count($scenarios), $ships);
        $observedLeaves = [];
        foreach ($scenarios as $scenario) {
            $ship = $ships[$scenario['ship']['symbol']];
            $this->assertSame($this->documentedValues($scenario['ship'], $shipSchema, $schema), $ship['details'], $scenario['id']);
            $this->assertSame(CarbonImmutable::parse($scenario['observed_at'])->toIso8601String(), $ship['observed_at']);
            $this->assertArrayNotHasKey('ship_type', $ship['details']);
            $this->assertArrayNotHasKey('synthetic', $ship['details']);
            $this->assertSame($ship['details'], json_decode(json_encode($ship['details'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
            $observedLeaves += $this->observedSchemaLeaves($ship['details'], $shipSchema, $schema);
        }
        foreach ($this->schemaLeaves($shipSchema, $schema) as $path => $type) {
            $this->assertArrayHasKey($path, $observedLeaves, 'Matrix never observes '.$path);
        }
        $missing = $ships['QA-MISSING-EQUIPMENT']['details'];
        $empty = $ships['QA-EMPTY-EQUIPMENT']['details'];
        $this->assertArrayNotHasKey('mounts', $missing);
        $this->assertArrayNotHasKey('modules', $missing);
        $this->assertSame([], $empty['mounts']);
        $this->assertSame([], $empty['modules']);
        $this->assertNull($ships['QA-UNKNOWN-RESOURCES']['details']['fuel']['capacity']);
        $this->assertSame(0.991, $ships['QA-DISTINCT-DUPLICATES']['details']['frame']['condition']);
        $this->assertSame(0.994, $ships['QA-DISTINCT-DUPLICATES']['details']['frame']['integrity']);
        $this->assertSame(3, $ships['QA-DISTINCT-DUPLICATES']['details']['frame']['quality']);
        $this->actingAs($this->operator)->get('/campaign')->assertOk()->assertInertia(fn (Assert $page) => $page->has('ships', 24));
    }

    public function test_matrix_covers_all_canonical_ship_types_mounts_and_modules_without_inferring_owned_types(): void
    {
        $schema = $this->shipSchema()['components']['schemas'];
        $canonical = array_values(array_filter(CampaignStatusFixture::shipScenarios(), fn ($scenario) => $scenario['ship_type'] !== null));
        $this->assertCount(13, $canonical);
        $this->assertEqualsCanonicalizing($schema['ShipType']['enum'], array_column($canonical, 'ship_type'));
        foreach (['mounts' => ['ShipMount', 15], 'modules' => ['ShipModule', 20]] as $field => [$type, $count]) {
            $symbols = collect($canonical)->flatMap(fn ($scenario) => array_column($scenario['ship'][$field], 'symbol'))->unique()->values()->all();
            $this->assertCount($count, $schema[$type]['properties']['symbol']['enum']);
            $this->assertEqualsCanonicalizing($schema[$type]['properties']['symbol']['enum'], $symbols);
        }
    }

    public function test_unlisted_fields_include_nested_names_but_never_unknown_or_token_values(): void
    {
        CampaignStatusFixture::seed($this->operator, shipMatrix: true);
        $ships = collect(app(CampaignStatus::class)->read()['ships'])->keyBy('symbol');
        $excluded = $ships['QA-EXCLUDED-FIELDS'];
        $this->assertEqualsCanonicalizing([
            'token', 'futureField', 'frame.newStat', 'nav.route.destination.secret',
            'mounts[0].requirements.secret', 'modules[0].newField', 'cargo.inventory[0].secret',
        ], $excluded['unlisted_fields']);
        $response = $this->actingAs($this->operator)->get('/campaign')->assertOk();
        $scenario = collect(CampaignStatusFixture::shipScenarios())->firstWhere('id', 'excluded-fields');
        foreach ($this->unknownValues($scenario['ship'], $this->shipSchema()['components']['schemas']['Ship'], $this->shipSchema()) as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($ships, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_wrong_shapes_and_tokens_cannot_escape_through_approved_scalar_or_array_fields_or_legacy_props(): void
    {
        ['ship' => $ship] = CampaignStatusFixture::seed($this->operator);
        $snapshot = CampaignStatusFixture::shipScenarios()[0]['ship'];
        $snapshot['nav']['waypointSymbol'] = ['token' => 'poison-waypoint'];
        $snapshot['nav']['route']['destination']['symbol'] = ['token' => 'poison-destination'];
        $snapshot['registration']['role'] = ['token' => 'poison-role'];
        $snapshot['fuel']['capacity'] = ['token' => 'poison-capacity'];
        $snapshot['frame']['condition'] = ['token' => 'poison-condition'];
        $snapshot['frame']['quality'] = 'poison-quality';
        $snapshot['engine'] = [['token' => 'poison-engine']];
        $snapshot['mounts'] = [['symbol' => 'MOUNT_FUTURE', 'deposits' => ['IRON_ORE', ['token' => 'poison-deposit']]]];
        $snapshot['modules'] = ['token' => 'poison-modules'];
        $snapshot['cargo']['inventory'] = [null, 'poison-inventory', ['symbol' => 'IRON_ORE', 'units' => 1]];
        $snapshot['crew'] = 'poison-crew';
        $snapshot['future'] = ['nested' => [['token' => 'poison-future']]];
        $ship->update(['snapshot' => $snapshot]);
        $props = app(CampaignStatus::class)->read()['ships'][0];
        $this->assertSame($ship->observed_at->toIso8601String(), $props['observed_at']);
        $this->assertNull($props['waypoint']);
        $this->assertNull($props['fuel']['capacity']);
        $this->assertNull($props['details']['crew']);
        $this->assertNull($props['details']['engine']);
        $this->assertNull($props['details']['modules']);
        $this->assertSame(['IRON_ORE', null], $props['details']['mounts'][0]['deposits']);
        $this->assertSame([null, null, ['symbol' => 'IRON_ORE', 'units' => 1]], $props['details']['cargo']['inventory']);
        $this->assertContains('future.nested[0].token', $props['unlisted_fields']);
        $this->assertContains('mounts[0].deposits[1].token', $props['unlisted_fields']);
        $this->assertContains('nav.waypointSymbol.token', $props['unlisted_fields']);
        $this->assertSame($snapshot, $ship->fresh()->snapshot);
        $response = $this->actingAs($this->operator)->get('/campaign')->assertOk();
        $this->assertStringNotContainsString('poison-', $response->getContent());
        $ship->update(['snapshot' => ['mounts' => null, 'modules' => null, 'cargo' => ['inventory' => null]]]);
        $this->assertSame(['mounts' => null, 'modules' => null, 'cargo' => ['inventory' => null]], app(CampaignStatus::class)->read()['ships'][0]['details']);
    }

    public function test_unknown_fields_at_every_observed_object_depth_are_names_only(): void
    {
        ['ship' => $ship] = CampaignStatusFixture::seed($this->operator);
        $original = collect(CampaignStatusFixture::shipScenarios())->firstWhere('id', 'distinct-duplicates')['ship'];
        $paths = [];
        $inject = function (array $value, string $path = '') use (&$inject, &$paths): array {
            foreach ($value as $key => $child) {
                if (is_array($child)) {
                    $childPath = is_int($key) ? $path.'['.$key.']' : ($path === '' ? $key : $path.'.'.$key);
                    $value[$key] = $inject($child, $childPath);
                }
            }
            if (! array_is_list($value)) {
                $tokenPath = $path === '' ? 'token' : $path.'.token';
                $paths[] = $tokenPath;
                $value['token'] = 'never-expose-'.$tokenPath;
            }

            return $value;
        };
        $ship->update(['snapshot' => $inject($original)]);
        $props = app(CampaignStatus::class)->read()['ships'][0];
        $this->assertSame($original, $props['details']);
        $this->assertEqualsCanonicalizing($paths, $props['unlisted_fields']);
        $this->assertGreaterThan(20, count($paths));
        $this->assertStringNotContainsString('never-expose-', json_encode($props, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('never-expose-', $this->actingAs($this->operator)->get('/campaign')->assertOk()->getContent());
    }

    public function test_refresh_retains_distinct_equipment_instances_and_per_ship_observation_times(): void
    {
        CampaignStatusFixture::seed($this->operator, shipMatrix: true);
        $ship = Ship::where('symbol', 'QA-DISTINCT-DUPLICATES')->firstOrFail();
        $before = collect(app(CampaignStatus::class)->read()['ships'])->keyBy('symbol');
        $snapshot = $ship->snapshot;
        $snapshot['mounts'][0]['requirements']['power'] = 11;
        $snapshot['mounts'][1]['requirements']['power'] = 19;
        $snapshot['modules'][0]['requirements']['slots'] = 2;
        $snapshot['modules'][1]['requirements']['slots'] = 5;
        $observedAt = $ship->observed_at->addMinutes(3);
        $ship->update(['snapshot' => $snapshot, 'observed_at' => $observedAt]);
        $after = collect(app(CampaignStatus::class)->read()['ships'])->keyBy('symbol');
        $this->assertSame($snapshot, $after[$ship->symbol]['details']);
        $this->assertSame($observedAt->toIso8601String(), $after[$ship->symbol]['observed_at']);
        $this->assertNotSame($before[$ship->symbol]['observed_at'], $after[$ship->symbol]['observed_at']);
        $this->assertSame($before['QA-1']['observed_at'], $after['QA-1']['observed_at']);
        $this->assertNotSame($before[$ship->symbol]['details']['mounts'], $after[$ship->symbol]['details']['mounts']);
        $this->assertNotSame($before[$ship->symbol]['details']['modules'], $after[$ship->symbol]['details']['modules']);
    }

    public function test_fixed_thirteen_ship_payload_stays_within_two_hundred_kibibytes(): void
    {
        CampaignStatusFixture::seed($this->operator, shipMatrix: true);
        $canonical = array_column(array_filter(CampaignStatusFixture::shipScenarios(), fn ($scenario) => $scenario['ship_type'] !== null), 'ship');
        $symbols = array_column($canonical, 'symbol');
        $ships = array_values(array_filter(app(CampaignStatus::class)->read()['ships'], fn ($ship) => in_array($ship['symbol'], $symbols, true)));
        $this->assertCount(13, $ships);
        $bytes = strlen(json_encode($ships, JSON_THROW_ON_ERROR));
        $this->assertLessThanOrEqual(200 * 1024, $bytes);
        $detailsBytes = strlen(json_encode(array_column($ships, 'details'), JSON_THROW_ON_ERROR));
        $sizes = [];
        foreach ($ships as $ship) {
            $sizes[$ship['symbol']] = [
                'ship' => strlen(json_encode($ship, JSON_THROW_ON_ERROR)),
                'details' => strlen(json_encode($ship['details'], JSON_THROW_ON_ERROR)),
            ];
        }
        fwrite(STDERR, 'Campaign fixed13 ships JSON: '.$bytes.' bytes; details JSON: '.$detailsBytes.' bytes; per ship: '.json_encode($sizes, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function shipSchema(): array
    {
        return json_decode(file_get_contents(base_path('docs/api/spacetraders-openapi-2.3.0.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function resolveSchema(array $node, array $schema): array
    {
        while (isset($node['$ref'])) {
            $node = $schema['components']['schemas'][basename($node['$ref'])];
        }

        return $node;
    }

    private function schemaLeaves(array $node, array $schema, string $path = ''): array
    {
        $node = $this->resolveSchema($node, $schema);
        if ($node['type'] === 'array') {
            return $this->schemaLeaves($node['items'], $schema, $path.'[]');
        }
        if ($node['type'] !== 'object') {
            return [$path => $node['type']];
        }
        $leaves = [];
        foreach ($node['properties'] as $key => $child) {
            $leaves += $this->schemaLeaves($child, $schema, $path === '' ? $key : $path.'.'.$key);
        }

        return $leaves;
    }

    private function projectionLeaves(array|string $projection, string $path = ''): array
    {
        if (is_string($projection)) {
            return [$path => $projection];
        }
        $leaves = [];
        foreach ($projection as $key => $child) {
            $leaves += $this->projectionLeaves($child, $key === '*' ? $path.'[]' : ($path === '' ? $key : $path.'.'.$key));
        }

        return $leaves;
    }

    private function documentedValues(mixed $value, array $node, array $schema): mixed
    {
        if ($value === null) {
            return null;
        }
        $node = $this->resolveSchema($node, $schema);
        if ($node['type'] === 'array') {
            return array_map(fn ($child) => $this->documentedValues($child, $node['items'], $schema), $value);
        }
        if ($node['type'] !== 'object') {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $child) {
            if (isset($node['properties'][$key])) {
                $result[$key] = $this->documentedValues($child, $node['properties'][$key], $schema);
            }
        }

        return $result;
    }

    private function observedSchemaLeaves(mixed $value, array $node, array $schema, string $path = ''): array
    {
        if ($value === null) {
            return [];
        }
        $node = $this->resolveSchema($node, $schema);
        if ($node['type'] !== 'object' && $node['type'] !== 'array') {
            return [$path => true];
        }
        $leaves = [];
        foreach ($value as $key => $child) {
            if ($node['type'] === 'array') {
                $leaves += $this->observedSchemaLeaves($child, $node['items'], $schema, $path.'[]');
            } elseif (isset($node['properties'][$key])) {
                $leaves += $this->observedSchemaLeaves($child, $node['properties'][$key], $schema, $path === '' ? $key : $path.'.'.$key);
            }
        }

        return $leaves;
    }

    private function unknownValues(mixed $value, array $node, array $schema): array
    {
        $node = $this->resolveSchema($node, $schema);
        $unknown = [];
        if (! is_array($value)) {
            return [];
        }
        foreach ($value as $key => $child) {
            if ($node['type'] === 'array') {
                $unknown = array_merge($unknown, $this->unknownValues($child, $node['items'], $schema));
            } elseif (isset($node['properties'][$key])) {
                $unknown = array_merge($unknown, $this->unknownValues($child, $node['properties'][$key], $schema));
            } else {
                $this->assertIsString($child);
                $unknown[] = $child;
            }
        }

        return $unknown;
    }

    public function test_no_grant_and_no_episode_have_empty_props_and_actions_do_not_create_authority(): void
    {
        $this->actingAs($this->operator)->get('/campaign')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('grant', null)->where('episode', null)->where('currentRun', null)->where('lastDecision', null)
            ->has('loads', 0)->has('credits', 0)->has('ships', 0)->has('events', 0));
        $this->post('/campaign/pause')->assertNotFound();
        $this->post('/campaign/resume')->assertNotFound();
        $this->assertDatabaseCount('campaign_grants', 0);
        $revision = app(CampaignPlaybook::class)->import(file_get_contents(base_path('campaigns/weekly-default.yaml')));
        app(CampaignControl::class)->approve($revision, $this->operator);
        $this->get('/campaign')->assertInertia(fn (Assert $page) => $page->where('episode', null)->where('currentRun', null));
    }

    public function test_recent_lists_exclude_other_grants_and_decisions_and_have_hard_limits(): void
    {
        ['grant' => $grant, 'episode' => $episode] = CampaignStatusFixture::seed($this->operator);
        foreach (range(1, 18) as $index) {
            foreach (['wait', 'recovery_result'] as $kind) {
                CampaignEvent::create(['campaign_grant_id' => $grant->id, 'campaign_episode_id' => $episode->id,
                    'kind' => $kind, 'data' => ['reason' => 'awaiting_fresh_state', 'net' => -10, 'losing' => true]]);
            }
        }
        $other = CampaignGrant::create(['campaign_revision_id' => $grant->campaign_revision_id, 'revision_hash' => $grant->revision_hash,
            'user_id' => $this->operator->id, 'limits' => $grant->limits, 'slot' => 2]);
        CampaignEvent::create(['campaign_grant_id' => $other->id, 'kind' => 'wait', 'data' => ['reason' => 'unrelated']]);
        $this->actingAs($this->operator)->get('/campaign')->assertInertia(fn (Assert $page) => $page
            ->where('grant.id', $grant->id)->where('grant.pending_grant', $other->id)->has('loads', 15)->has('events', 15)
            ->where('events.0.kind', 'wait')->where('events.0.summary', 'awaiting_fresh_state'));
    }

    public function test_historical_authority_and_latest_decision_are_shown_with_only_five_rejection_reasons(): void
    {
        ['grant' => $grant, 'episode' => $episode] = CampaignStatusFixture::seed($this->operator);
        CampaignEvent::create(['campaign_grant_id' => $grant->id, 'campaign_episode_id' => $episode->id, 'kind' => 'decision',
            'data' => ['choice' => 'idle', 'rejections' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6]]]);
        $grant->update(['slot' => null, 'revoked_at' => now()]);
        $this->actingAs($this->operator)->get('/campaign')->assertInertia(fn (Assert $page) => $page
            ->where('grant.id', $grant->id)->where('grant.active', false)->where('lastDecision.choice', 'idle')
            ->has('lastDecision.rejections', 5)->where('lastDecision.rejections.0.reason', 'f')
            ->where('lastDecision.rejections.4.reason', 'b')->where('events.0.summary', 'discover → earn'));
        $this->post('/campaign/resume')->assertNotFound();
    }
}
