<?php

namespace App\Providers;

use App\Gameplay\DeploymentGate;
use App\Gameplay\SpaceTradersApi;
use App\Queue\DeploymentDatabaseQueue;
use App\Services\SpaceTradersClient;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Tests\Support\FakeSpaceTraders;

class AppServiceProvider extends ServiceProvider
{
    private array $schedulerAdmissions = [];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SpaceTradersClient::class);
        $this->app->scoped(SpaceTradersApi::class);
        $this->app->singleton(DeploymentGate::class);
        $this->app->resolving('queue', function ($manager) {
            $manager->addConnector('database', fn () => new class($this->app['db']) extends DatabaseConnector
            {
                public function connect(array $config)
                {
                    return new DeploymentDatabaseQueue($this->connections->connection($config['connection'] ?? null),
                        $config['table'], $config['queue'], $config['retry_after'] ?? 60, $config['after_commit'] ?? null);
                }
            });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(JobPopping::class, function (JobPopping $event) {
            if ($event->connectionName === 'database' && in_array('spacetraders', explode(',', $event->queue ?? 'spacetraders'), true)) {
                app(DeploymentGate::class)->workerPolling();
            }
        });
        Event::listen(WorkerStopping::class, fn () => app(DeploymentGate::class)->workerStopped());
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command === 'schedule:run') {
                $this->schedulerAdmissions[] = app(DeploymentGate::class)->begin('scheduler');
            }
        });
        Event::listen(CommandFinished::class, function (CommandFinished $event) {
            if ($event->command === 'queue:work') {
                app(DeploymentGate::class)->workerStopped();
            }
            if ($event->command === 'schedule:run' && ($id = array_pop($this->schedulerAdmissions))) {
                app(DeploymentGate::class)->finish($id);
            }
        });
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
