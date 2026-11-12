<?php

namespace App\Providers;

use App\Gameplay\Engine\EngineHost;
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
        // Owned-child wiring (lease hold and cooperative stop listeners) is added in M2 task B.
    }
}
