<?php

namespace App\Providers;

use App\Gameplay\Engine\EngineChildWatch;
use App\Gameplay\Engine\EngineHost;
use App\Gameplay\Engine\EngineLease;
use App\Gameplay\Engine\LaunchdEngineHost;
use App\Gameplay\Engine\Windows\WindowsEngineHost;
use Illuminate\Support\ServiceProvider;

class EngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EngineHost::class, fn ($app) => PHP_OS_FAMILY === 'Windows'
            ? $app->make(WindowsEngineHost::class)
            : $app->make(LaunchdEngineHost::class));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole() || ! getenv('SPACETRADERS_ENGINE_SESSION') || ! getenv('SPACETRADERS_ENGINE_ROLE')) {
            return;
        }
        if ($lease = getenv('SPACETRADERS_ENGINE_LEASE')) {
            EngineLease::hold($lease);
        }
        EngineChildWatch::listen();
    }
}
