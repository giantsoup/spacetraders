<?php

namespace Tests\Feature;

use App\Gameplay\CampaignPlaybook;
use App\Gameplay\CampaignStrategy;
use App\Models\CampaignGrant;
use App\Models\CampaignRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\CampaignStatusFixture;
use Tests\TestCase;

class CampaignStrategyTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->operator = User::factory()->create();
        config(['gameplay.operator_id' => (string) $this->operator->id]);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_strategy_uses_the_same_operator_boundary_as_status(): void
    {
        $this->get('/campaign/strategy')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/campaign/strategy')->assertNotFound();
        config(['gameplay.operator_id' => null]);
        $this->actingAs($this->operator)->get('/campaign/strategy')->assertNotFound();
    }

    public function test_slot_one_is_preserved_with_pause_expiry_and_only_a_notice_from_slot_two(): void
    {
        ['grant' => $grant] = CampaignStatusFixture::seed($this->operator);
        // Expiry is immutable, so set it as fixture data rather than updating the model.
        DB::table('campaign_grants')->where('id', $grant->id)->update(['paused_at' => now(), 'pause_reason' => 'operator_pause', 'expires_at' => now()->subHour()]);
        $pending = $this->revision('queued-only');
        $this->grant($pending, 2);
        $this->grant($this->revision('arm-only'), null, ['arm' => ['label' => 'test', 'ship' => 'OFFLINE-2']]);
        $this->grant($this->revision('historical-only'), null);
        $response = $this->readWithoutEffects();
        $response->assertInertia(fn (Assert $page) => $page->component('Campaign/Strategy')
            ->where('source.name', 'weekly-default')->where('source.status', 'Paused · Expired C0 revision')
            ->where('source.example', false)->where('source.pauseReason', 'operator_pause')
            ->where('source.pending.name', 'queued-only')->where('graph.root', 'authority')->has('graph.chapters', 8));
        $graph = $response->inertiaProps('graph');
        $this->assertStringNotContainsString('queued-only', json_encode($graph));
        $this->assertStringNotContainsString('arm-only', $response->getContent());
        $this->assertStringNotContainsString('historical-only', $response->getContent());
        $this->assertStringNotContainsString('synthetic-only', $response->getContent());
        $this->assertGraph($graph);
    }

    public function test_absent_slot_one_uses_validated_bundle_in_memory_without_importing_or_activating(): void
    {
        $this->grant($this->revision('pending-only'), 2);
        $this->grant($this->revision('arm-only'), null, ['arm' => ['label' => 'test']]);
        $response = $this->readWithoutEffects();
        $response->assertInertia(fn (Assert $page) => $page->where('source.status', 'Bundled example · unapproved')
            ->where('source.name', 'weekly-default')->where('source.pending.name', 'pending-only')->has('graph.chapters', 8));
        $this->assertDatabaseCount('campaign_revisions', 2);
        $this->assertGraph($response->inertiaProps('graph'));
    }

    public function test_custom_revision_projects_order_gates_and_thresholds_and_reload_changes_source(): void
    {
        $body = $this->body();
        $body['name'] = 'custom-order';
        $body['phases'] = [
            ['id' => 'survey', 'exit' => ['cycles_completed_gte' => 3], 'activities' => [
                ['role' => 'probe', 'strategy' => 'scout', 'when' => ['any' => [['credits_gte' => 1234], ['minutes_without_trade_gte' => 12]]]],
                ['role' => 'command', 'strategy' => 'mining', 'when' => ['minutes_elapsed_gte' => 7]],
                ['role' => 'command', 'strategy' => 'trade'],
            ]],
            ['id' => 'earn', 'exit' => ['any' => [['hours_to_reset_lte' => 0.5], ['minutes_elapsed_gte' => 25]]], 'activities' => [['role' => 'command', 'strategy' => 'sell_aboard']]],
        ];
        $body['trade']['proof'] = ['max_units_batches' => 2, 'max_credits' => 43210, 'min_realized_ratio' => 0.7];
        $body['trade']['lot'] = ['max_buyer_batches' => 7, 'first_scaled_max_buyer_batches' => 4, 'default_batch_decay' => 0.06];
        $body['trade']['switch'] = ['bid_below_first_seen' => 0.85, 'better_pair_ratio' => 1.025];
        $body['trade']['quote_max_age_seconds'] = 75;
        $body['trade']['losses']['pause_after_consecutive_losing_loads'] = 4;
        app(CampaignPlaybook::class)->validate($body);
        $revision = CampaignRevision::create(['name' => $body['name'], 'content_hash' => str_repeat('c', 64), 'body' => $body, 'source' => 'synthetic']);
        $grant = $this->grant($revision, 1);
        $response = $this->readWithoutEffects();
        $graph = $response->inertiaProps('graph');
        $groups = $graph['nodes']['phase']['groups'];
        $this->assertSame(['survey', 'earn'], array_column($groups, 'id'));
        $this->assertSame('Configured exit conditions · any one is sufficient', $groups[1]['exitLabel']);
        $this->assertSame(['At most 0.5 hours remain before reset', 'At least 25 minutes have elapsed'], $groups[1]['exits']);
        $this->assertSame('End of the phase sequence.', $groups[1]['note']);
        $this->assertSame('Probe scouts markets', $groups[0]['activities'][0]['label']);
        $this->assertStringContainsString('any one', $groups[0]['activities'][0]['condition']);
        $this->assertStringContainsString('1,234 CR', $groups[0]['activities'][0]['condition']);
        $this->assertStringContainsString('7 minutes', $groups[0]['activities'][1]['condition']);
        foreach (['43,210', '70%', '4 buyer batches', '7 on later', '6%', '85%', '1.025', '75 seconds', '4 losing loads', 'no literal wind_down', 'final-hour restriction', 'outside Earn', 'deferred to the next pass'] as $text) {
            $this->assertStringContainsString($text, json_encode($graph, JSON_UNESCAPED_UNICODE));
        }
        $this->assertGraph($graph);
        $grant->update(['slot' => null]);
        $this->grant($this->revision('replacement-now-current'), 1);
        $this->actingAs($this->operator)->get('/campaign/strategy')->assertInertia(fn (Assert $page) => $page->where('source.name', 'replacement-now-current'));
    }

    public function test_no_trade_revision_uses_six_chapters_and_activity_specific_final_outcomes(): void
    {
        $body = $this->body();
        $body['phases'] = [['id' => 'survey', 'activities' => [['role' => 'probe', 'strategy' => 'scout'], ['role' => 'command', 'strategy' => 'mining'], ['role' => 'command', 'strategy' => 'sell_aboard']]]];
        app(CampaignPlaybook::class)->validate($body);
        $graph = app(CampaignStrategy::class)->graph($body);
        $this->assertSame(['authority', 'world', 'safety', 'cargo', 'phase', 'learn'], $graph['chapters']);
        $this->assertArrayNotHasKey('proof', $graph['nodes']);
        $this->assertArrayNotHasKey('trade', $graph['nodes']);
        $this->assertStringContainsString('Mining uses a bounded investigation', $graph['nodes']['learn.configured']['summary']);
        $this->assertGraph($graph);
    }

    private function readWithoutEffects()
    {
        $before = $this->snapshot();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $response = $this->actingAs($this->operator)->get('/campaign/strategy')->assertOk();
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $sql);
        }
        $this->assertSame($before, $this->snapshot(), 'Every table and episode state must stay unchanged.');
        Http::assertNothingSent();

        return $response;
    }

    private function snapshot(): array
    {
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table->name] = DB::table($table->name)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function body(): array
    {
        return Yaml::parse(file_get_contents(base_path('campaigns/weekly-default.yaml')));
    }

    private function revision(string $name): CampaignRevision
    {
        $body = $this->body();
        $body['name'] = $name;

        return CampaignRevision::create(['name' => $name, 'content_hash' => hash('sha256', $name), 'body' => $body, 'source' => 'synthetic']);
    }

    private function grant(CampaignRevision $revision, ?int $slot, array $limits = []): CampaignGrant
    {
        return CampaignGrant::create(['campaign_revision_id' => $revision->id, 'revision_hash' => $revision->content_hash,
            'user_id' => $this->operator->id, 'slot' => $slot, 'limits' => $limits]);
    }

    private function assertGraph(array $graph): void
    {
        $this->assertSame($graph['chapters'][0], $graph['root']);
        $this->assertCount(count(array_unique($graph['chapters'])), $graph['chapters']);
        foreach ($graph['nodes'] as $id => $node) {
            $this->assertSame($id, $node['id']);
            foreach ($node['links'] as $link) {
                $this->assertArrayHasKey($link['target'], $graph['nodes']);
                $this->assertNotEmpty($link['condition']);
                $this->assertNotEmpty($link['label']);
            }
        }
        foreach ($graph['chapters'] as $id) {
            $this->assertSame('chapter', $graph['nodes'][$id]['kind']);
        }
    }
}
