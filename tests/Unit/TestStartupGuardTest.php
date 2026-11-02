<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\CreatesApplication;

class TestStartupGuardTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cached_configuration_is_rejected_before_database_setup(): void
    {
        $directory = sys_get_temp_dir().'/st-cached-config-'.bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $path = $directory.'/config.php';
        file_put_contents($path, '<?php throw new RuntimeException("Cached configuration must never execute.");');
        putenv('APP_CONFIG_CACHE='.$path);
        $_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $path;

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Tests refuse cached application configuration.');
            (new class
            {
                use CreatesApplication;
            })->createApplication();
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }
}
