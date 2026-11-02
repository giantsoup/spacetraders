<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    private ?string $isolatedTestStorage = null;

    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        if ($app->configurationIsCached()) {
            throw new \RuntimeException('Tests refuse cached application configuration. Select an uncached test configuration path.');
        }

        $this->isolatedTestStorage = sys_get_temp_dir().'/st-phpunit-'.bin2hex(random_bytes(12));
        foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
            mkdir($this->isolatedTestStorage.'/'.$directory, 0700, true);
        }
        $app->useStoragePath($this->isolatedTestStorage);

        $app->make(Kernel::class)->bootstrap();

        if (! $app->environment('testing') || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tests require testing mode and in-memory SQLite before database setup.');
        }

        return $app;
    }
}
