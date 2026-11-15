<?php

namespace Tests\Feature;

use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignNotifier;
use App\Gameplay\CampaignPlaybook;
use App\Gameplay\Engine\EngineHost;
use App\Models\CampaignGrant;
use App\Models\LocalEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\EngineFixture;
use Tests\Support\FakeEngineHost;
use Tests\TestCase;

class CampaignWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private string $state;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now());
        $this->state = tempnam(sys_get_temp_dir(), 'campaign-watchdog-state-');
        $this->log = tempnam(sys_get_temp_dir(), 'campaign-watchdog-log-');
        config(['campaign.watchdog_state_path' => $this->state, 'campaign.watchdog_log_path' => $this->log,
            'campaign.alert_webhook_url' => null]);
        $revision = app(CampaignPlaybook::class)->import(file_get_contents(base_path('campaigns/weekly-default.yaml')));
        app(CampaignControl::class)->approve($revision, User::factory()->create());
        $this->app->instance(EngineHost::class, new FakeEngineHost);
        EngineFixture::healthy();
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        unlink($this->state);
        unlink($this->log);
        parent::tearDown();
    }

    public function test_no_webhook_still_logs_and_toasts_once_then_repeats_and_recovers(): void
    {
        $this->partialMock(CampaignNotifier::class)->shouldReceive('toast')->times(3);
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()->subMinutes(6)]);
        $this->artisan('campaign:watchdog')->assertFailed();
        $this->artisan('campaign:watchdog')->assertFailed();
        $this->assertSame(1, substr_count(file_get_contents($this->log), 'engine_heartbeat_stale'));
        $this->travel(2)->hours();
        $this->artisan('campaign:watchdog')->assertFailed();
        EngineFixture::healthy();
        $this->artisan('campaign:watchdog')->assertSuccessful();
        $this->artisan('campaign:watchdog')->assertSuccessful();
        $this->assertStringContainsString('Recovered', file_get_contents($this->log));
        $this->assertCount(3, array_filter(explode("\n", file_get_contents($this->log))));
        Http::assertNothingSent();
    }

    public function test_webhook_and_distinct_pause_reasons_are_alerted_without_disclosing_the_url(): void
    {
        $url = 'https://example.invalid/synthetic-hook';
        config(['campaign.alert_webhook_url' => $url]);
        $this->partialMock(CampaignNotifier::class)->shouldReceive('toast')->times(2);
        $grant = CampaignGrant::where('slot', 1)->firstOrFail();
        app(CampaignControl::class)->pause($grant, 'operator_pause');
        $this->artisan('campaign:watchdog')->assertFailed();
        $this->artisan('campaign:watchdog')->assertFailed();
        app(CampaignControl::class)->resume();
        $this->artisan('campaign:watchdog')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === $url && str_contains($request['content'], 'campaign_paused'));
        $this->assertStringNotContainsString($url, file_get_contents($this->state).file_get_contents($this->log));
    }

    public function test_distinct_problems_are_tracked_and_recover_independently(): void
    {
        config(['campaign.alert_webhook_url' => 'https://example.invalid/synthetic-hook']);
        $this->partialMock(CampaignNotifier::class)->shouldReceive('toast')->times(5);
        $grant = CampaignGrant::where('slot', 1)->firstOrFail();
        app(CampaignControl::class)->pause($grant, 'operator_pause');
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()->subMinutes(6)]);
        $this->artisan('campaign:watchdog')->assertFailed();
        $this->artisan('campaign:watchdog')->assertFailed();
        EngineFixture::healthy();
        $this->artisan('campaign:watchdog')->assertFailed();
        $grant->refresh()->update(['pause_reason' => 'unexplained_change']);
        $this->artisan('campaign:watchdog')->assertFailed();
        Http::assertSentCount(5);
        $state = json_decode(file_get_contents($this->state), true);
        $this->assertCount(1, $state);
        $this->assertArrayHasKey('campaign_paused:'.$grant->id.':unexplained_change', $state);
    }

    public function test_webhook_failure_is_redacted_and_does_not_suppress_local_alerts(): void
    {
        $url = 'https://example.invalid/synthetic-hook';
        config(['campaign.alert_webhook_url' => $url]);
        Http::fake(fn () => throw new \RuntimeException('Do not log '.$url));
        $this->partialMock(CampaignNotifier::class)->shouldReceive('toast')->once();
        LocalEngine::whereKey(1)->update(['heartbeat_at' => now()->subMinutes(6)]);
        $this->artisan('campaign:watchdog')->assertFailed();
        $this->assertStringContainsString('Webhook delivery failed', file_get_contents($this->log));
        $this->assertStringNotContainsString($url, file_get_contents($this->log));
    }
}
