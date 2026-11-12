<?php

namespace Tests\Feature;

use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[RequiresOperatingSystemFamily('Windows')]
class WindowsRequestBudgetTest extends TestCase
{
    public function test_default_storage_is_in_local_app_data_and_tests_override_it(): void
    {
        $settings = require config_path('gameplay.php');
        $this->assertSame(getenv('LOCALAPPDATA').'/SpaceTraders/request-budget', $settings['request_budget_path']);
        $this->assertStringContainsString('/st-test-locks-', config('gameplay.request_budget_path'));
        $this->assertNotSame($settings['request_budget_path'], config('gameplay.request_budget_path'));
    }

    public function test_drive_paths_use_a_private_current_user_acl(): void
    {
        $budget = new RequestBudget;
        $budget->reserve('synthetic-token', null, 'write');
        $directory = config('gameplay.request_budget_path');
        $this->assertSame(1, $budget->usage('synthetic-token')['host']['requests']);
        $result = $this->powershell(<<<'POWERSHELL'
$acl = Get-Acl -LiteralPath $env:SPACETRADERS_TEST_DIRECTORY
$user = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$allowed = @($acl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier]) | Where-Object { $_.AccessControlType -eq 'Allow' } | ForEach-Object { $_.IdentityReference.Value })
@{ owner = $acl.GetOwner([System.Security.Principal.SecurityIdentifier]).Value; user = $user; allowed = $allowed; protected = $acl.AreAccessRulesProtected } | ConvertTo-Json -Compress
POWERSHELL, $directory);
        $acl = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($acl['user'], $acl['owner']);
        $this->assertTrue($acl['protected']);
        $this->assertEqualsCanonicalizing([$acl['user'], 'S-1-5-18', 'S-1-5-32-544'], $acl['allowed']);
        $this->assertStringNotContainsString('synthetic-token', file_get_contents($directory.'/budget.json'));
    }

    public function test_drive_relative_root_relative_and_network_paths_fail_closed(): void
    {
        foreach (['C:relative-budget', '\\relative-budget', '/relative-budget', '\\\\server\\share\\budget', '//server/share/budget', '\\\\?\\C:\\budget'] as $directory) {
            config(['gameplay.request_budget_path' => $directory]);
            try {
                (new RequestBudget)->reserve(null, null, 'write');
                $this->fail('Non-local or relative storage was accepted.');
            } catch (RequestDeferred $error) {
                $this->assertSame('Host request-budget storage requires an absolute shared path.', $error->getMessage());
            }
        }
    }

    public function test_usage_rejects_broad_access_without_changing_permissions(): void
    {
        $directory = config('gameplay.request_budget_path');
        mkdir($directory, 0700, true);
        $this->powershell(<<<'POWERSHELL'
$item = Get-Item -LiteralPath $env:SPACETRADERS_TEST_DIRECTORY
$acl = $item.GetAccessControl()
$everyone = New-Object System.Security.Principal.SecurityIdentifier('S-1-1-0')
$rule = New-Object System.Security.AccessControl.FileSystemAccessRule($everyone, 'ReadAndExecute', 'Allow')
$acl.AddAccessRule($rule)
$item.SetAccessControl($acl)
POWERSHELL, $directory);
        $before = $this->powershell('(Get-Acl -LiteralPath $env:SPACETRADERS_TEST_DIRECTORY).Sddl', $directory);
        try {
            (new RequestBudget)->usage();
            $this->fail('Broadly accessible storage was accepted.');
        } catch (RequestDeferred) {
            $this->assertSame($before, $this->powershell('(Get-Acl -LiteralPath $env:SPACETRADERS_TEST_DIRECTORY).Sddl', $directory));
        }
    }

    public function test_replacing_a_verified_directory_invalidates_the_acl_cache(): void
    {
        $directory = config('gameplay.request_budget_path');
        (new RequestBudget)->reserve(null, null, 'write');
        rename($directory, $directory.'-original');
        mkdir($directory, 0700);
        $this->expectException(RequestDeferred::class);
        (new RequestBudget)->usage();
    }

    public static function reparsePaths(): array
    {
        return [['budget.json'], ['budget.lock'], ['ancestor'], ['directory']];
    }

    #[DataProvider('reparsePaths')]
    public function test_reparse_points_fail_closed_even_after_acl_verification(string $name): void
    {
        $budget = new RequestBudget;
        $budget->reserve(null, null, 'write');
        $directory = config('gameplay.request_budget_path');
        if ($name === 'ancestor' || $name === 'directory') {
            $link = $directory;
            rename($directory, $directory.'-original');
            if ($name === 'ancestor') {
                config(['gameplay.request_budget_path' => $directory.'/nested/budget']);
            }
        } else {
            $link = $directory.'/'.$name;
            unlink($link);
        }
        $this->powershell(<<<'POWERSHELL'
New-Item -ItemType Junction -Path $env:SPACETRADERS_TEST_DIRECTORY -Target ([System.IO.Path]::GetTempPath()) | Out-Null
POWERSHELL, $link);
        try {
            foreach (['usage', 'reserve'] as $operation) {
                try {
                    $budget->$operation();
                    $this->fail('Reparse-point storage was accepted.');
                } catch (RequestDeferred $error) {
                    $this->assertSame('Host request-budget storage is unsafe.', $error->getMessage());
                }
            }
        } finally {
            rmdir($link);
        }
    }

    private function powershell(string $script, string $directory): string
    {
        $process = new Process([
            getenv('SystemRoot').'/System32/WindowsPowerShell/v1.0/powershell.exe',
            '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand',
            base64_encode(mb_convert_encoding('$'."ErrorActionPreference = 'Stop'\n".$script, 'UTF-16LE', 'UTF-8')),
        ], env: ['SPACETRADERS_TEST_DIRECTORY' => $directory], timeout: 10);
        $process->mustRun();

        return $process->getOutput();
    }
}
