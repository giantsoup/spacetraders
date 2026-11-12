<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\User;
use App\Services\SpaceTradersClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameConnectionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('connectionModes')]
    public function test_pages_report_the_configured_transport_without_requests_or_credentials(?string $fixture, string $mode): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['gameplay.fixture_path' => $fixture]);
        $this->actingAs(User::factory()->create())->get(route('guide'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Guide/Show')
                ->where('gameConnection', ['mode' => $mode, 'endpoint' => SpaceTradersClient::BASE_URL]));
        Http::assertNothingSent();
    }

    public static function connectionModes(): array
    {
        return [[null, 'live'], ['/private/offline/world.json', 'offline']];
    }

    public function test_connection_mode_is_included_in_partial_updates(): void
    {
        $version = app(HandleInertiaRequests::class)->version(Request::create('/guide'));
        $this->actingAs(User::factory()->create())->get(route('guide'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version ?? '',
            'X-Inertia-Partial-Component' => 'Guide/Show',
            'X-Inertia-Partial-Data' => 'agents',
        ])->assertOk()->assertJsonPath('props.gameConnection.mode', 'live')
            ->assertJsonPath('props.gameConnection.endpoint', SpaceTradersClient::BASE_URL);
    }

    public function test_gameplay_jobs_are_isolated_from_the_default_database_queue(): void
    {
        foreach ([new ExecuteShipCommand(1), new AdvanceAutomationRun(1)] as $job) {
            $this->assertSame('database', $job->connection);
            $this->assertSame('spacetraders', $job->queue);
            $this->assertSame('default', config('queue.connections.database.queue'));
            $this->assertNotSame($job->queue, config('queue.connections.database.queue'));
            $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
            $this->assertSame(0, $job->tries);
            $this->assertSame(1, $job->maxExceptions);
        }
    }
}
