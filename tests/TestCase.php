<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

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
        parent::tearDown();
        if ($this->gameLockDirectory && is_dir($this->gameLockDirectory)) {
            if (is_dir($this->gameLockDirectory.'/deployment')) {
                File::deleteDirectory($this->gameLockDirectory.'/deployment');
            }
            foreach (glob($this->gameLockDirectory.'/*.lock') as $file) {
                unlink($file);
            }
            foreach (glob($this->gameLockDirectory.'/budget/*') as $file) {
                unlink($file);
            }
            if (is_dir($this->gameLockDirectory.'/budget')) {
                rmdir($this->gameLockDirectory.'/budget');
            }
            rmdir($this->gameLockDirectory);
        }
    }
}
