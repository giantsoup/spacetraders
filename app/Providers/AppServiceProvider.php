<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Tests\Support\FakeSpaceTraders;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('gameplay.fixture_path')) {
            if (! $this->app->environment(['local', 'testing'])) {
                throw new \RuntimeException('Synthetic SpaceTraders transport is restricted to local/testing.');
            }
            require_once base_path('tests/Support/FakeSpaceTraders.php');
            FakeSpaceTraders::install(config('gameplay.fixture_path'));
        }
    }
}
