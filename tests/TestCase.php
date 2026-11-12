<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private ?string $gameLockDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gameLockDirectory = sys_get_temp_dir().'/st-test-locks-'.bin2hex(random_bytes(8));
        config(['gameplay.request_budget_enabled' => false, 'gameplay.request_budget_path' => $this->gameLockDirectory.'/budget', 'gameplay.lock_path' => $this->gameLockDirectory]);
        config(['deployment.path' => $this->gameLockDirectory.'/deployment']);
    }

    protected function tearDown(): void
    {
        $this->beforeApplicationDestroyed(function () {
            foreach ($this->app['log']->getChannels() as $channel) {
                $channel->getLogger()->close();
            }
        });
        parent::tearDown();
        if ($this->isolatedTestStorage && is_dir($this->isolatedTestStorage)) {
            $this->deleteTestDirectory($this->isolatedTestStorage);
        }
        if ($this->gameLockDirectory && is_dir($this->gameLockDirectory)) {
            $this->deleteTestDirectory($this->gameLockDirectory);
        }
    }

    private function deleteTestDirectory(string $directory): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isLink()) {
                @chmod($file->getPathname(), 0600);
            }
        }
        unset($files, $file);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            (new Filesystem)->deleteDirectory($directory);
            clearstatcache(true, $directory);
            if (! is_dir($directory)) {
                return;
            }
            usleep(20000);
        }
        throw new \RuntimeException('Could not remove isolated test directory: '.$directory);
    }
}
