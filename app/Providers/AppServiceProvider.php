<?php

namespace App\Providers;

use App\Gameplay\SpaceTradersApi;
use App\Services\SpaceTradersClient;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Tests\Support\FakeSpaceTraders;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SpaceTradersClient::class);
        $this->app->scoped(SpaceTradersApi::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            if ($event->connection->getDriverName() === 'sqlite') {
                $event->connection->statement('PRAGMA fullfsync = ON');
            }
        });

        if (config('gameplay.fixture_path')) {
            if (! $this->app->environment(['local', 'testing'])) {
                throw new \RuntimeException('Synthetic SpaceTraders transport is restricted to local/testing.');
            }
            require_once base_path('tests/Support/FakeSpaceTraders.php');
            FakeSpaceTraders::install(config('gameplay.fixture_path'));
        }
    }
}
