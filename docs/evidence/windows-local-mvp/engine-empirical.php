<?php

use App\Gameplay\Engine\EngineLease;
use App\Gameplay\Engine\HostCommands;
use App\Gameplay\Engine\Windows\PowerShell;
use App\Gameplay\Engine\Windows\ScheduledTaskXml;
use App\Gameplay\Engine\Windows\WindowsChild;
use App\Gameplay\Engine\Windows\WindowsEngineHost;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Log\LogManager;

if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('These empirical checks require Windows.');
}
$base = dirname(__DIR__, 3);
require $base.'/vendor/autoload.php';
$directory = sys_get_temp_dir().'/SpaceTraders-Engine-Test-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$app = new Application($base);
$app->useStoragePath($directory);
$app->instance('config', new Repository([
    'gameplay' => ['lock_path' => $directory, 'php_binary' => PHP_BINARY],
    'database' => ['connections' => ['sqlite' => ['database' => ':memory:']]],
]));
putenv('SPACETRADERS_ENGINE_LOG='.$directory.'/engine-channel.log');
$_ENV['SPACETRADERS_ENGINE_LOG'] = $_SERVER['SPACETRADERS_ENGINE_LOG'] = $directory.'/engine-channel.log';
$app['config']->set('logging', require $base.'/config/logging.php');
$commands = new class extends HostCommands
{
    public array $failures = [];

    public function run(array $argv, int $timeout = 10, array $env = []): \App\Gameplay\Engine\HostResult
    {
        $result = parent::run($argv, $timeout, $env);
        if (! $result->successful() && in_array('-EncodedCommand', $argv, true)) {
            $this->failures[] = ['out' => $result->out, 'err' => $result->err];
        }

        return $result;
    }
};
$host = new WindowsEngineHost($commands);
$powershell = new PowerShell($commands);
$task = 'SpaceTraders-Engine-Test-'.bin2hex(random_bytes(8));
$phpWin = dirname(PHP_BINARY).'/php-win.exe';
$processes = [];
$child = null;
$taskCreated = false;
$results = ['date' => date(DATE_ATOM), 'php' => PHP_BINARY, 'task' => $task, 'temporary_directory' => $directory];
$taskCommand = function (string $operation, array $arguments = []) use ($commands, $task) {
    $argv = [PowerShell::executable('schtasks.exe'), $operation, '/TN', $task, ...$arguments];
    $result = $commands->run($argv);

    return ['argv' => $argv, 'exit' => $result->exit, 'out' => trim($result->out), 'err' => trim($result->err)];
};
$waitFor = function (callable $condition, float $seconds = 10): bool {
    $until = microtime(true) + $seconds;
    do {
        if ($condition()) {
            return true;
        }
        usleep(20000);
    } while (microtime(true) < $until);

    return false;
};
try {
    $results['non_elevated'] = trim($powershell->run(<<<'POWERSHELL'
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
POWERSHELL)->out) === 'False';
    $sid = trim($powershell->run('[System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value')->out);
    $xml = ScheduledTaskXml::render($task, $sid, $phpWin, $base, $directory.'/unused.json');
    $xml = mb_convert_encoding(substr($xml, 2), 'UTF-8', 'UTF-16LE');
    $code = <<<'PHP'
file_put_contents($argv[1], json_encode(['pid'=>getmypid(), 'started'=>microtime(true)]).PHP_EOL, FILE_APPEND);
$child = proc_open([$argv[2], '-r', 'file_put_contents($argv[1], (string)getmypid()); echo chr(99).chr(104).chr(105).chr(108).chr(100); sleep(60);', $argv[3]], [0=>['file','NUL','r'],1=>['file',$argv[4],'a'],2=>['file',$argv[4],'a']], $pipes, null, null, ['bypass_shell'=>true]);
file_put_contents($argv[5], 'scheduled parent wrote log'.PHP_EOL, FILE_APPEND);
sleep(20);
PHP;
    $arguments = '-r "'.str_replace(["\r", "\n"], ' ', $code).'" "'.$directory.'/starts.jsonl" "'.$phpWin.'" "'.$directory.'/descendant.pid" "'.$directory.'/output.log" "'.$directory.'/parent.log"';
    $xml = preg_replace_callback('~<Arguments>.*?</Arguments>~s', fn () => '<Arguments>'.htmlspecialchars($arguments, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</Arguments>', $xml);
    file_put_contents($directory.'/task.xml', "\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));
    $results['E2_create'] = $taskCommand('/Create', ['/XML', $directory.'/task.xml']);
    if ($results['E2_create']['exit'] !== 0) {
        throw new RuntimeException('Test task creation failed.');
    }
    $taskCreated = true;
    $results['E2_run'] = $taskCommand('/Run');
    $started = $waitFor(fn () => is_file($directory.'/starts.jsonl') && is_file($directory.'/descendant.pid'));
    if (! $started) {
        throw new RuntimeException('Scheduled php-win did not publish its PID markers.');
    }
    $first = json_decode(file($directory.'/starts.jsonl', FILE_IGNORE_NEW_LINES)[0], true);
    $parentPid = $first['pid'];
    $descendantPid = (int) file_get_contents($directory.'/descendant.pid');
    $processes = ['parent' => $parentPid, 'descendant' => $descendantPid];
    $results['E3_second_run'] = $taskCommand('/Run');
    usleep(1500000);
    $results['E3_start_count'] = count(file($directory.'/starts.jsonl', FILE_IGNORE_NEW_LINES));
    $results['E4_windows'] = json_decode($powershell->run(<<<'POWERSHELL'
ConvertTo-Json -InputObject @(Get-Process -Id ($env:SPACETRADERS_TEST_PIDS -split ',') | Select-Object Id, ProcessName, MainWindowHandle) -Compress
POWERSHELL, ['SPACETRADERS_TEST_PIDS' => implode(',', $processes)])->out, true);
    $results['E4_output'] = file_get_contents($directory.'/output.log');
    $logs = new LogManager($app);
    $logs->channel('engine')->info('engine channel empirical check');
    $logs->channel('engine')->getLogger()->close();
    $results['E4_engine_channel'] = file_get_contents($directory.'/engine-channel.log');
    $identity = $host->identify($processes);
    usleep(100000);
    $again = $host->identify($processes);
    $results['E8_identities'] = $identity;
    $results['E8_stable'] = $identity === $again;
    $results['E5_end'] = $taskCommand('/End');
    usleep(500000);
    $results['E5_remaining'] = $host->identify($processes);
    $lease = $directory.'/engine/leases/11111111-2222-3333-4444-555555555555-worker.lease';
    $leaseCode = 'require $argv[1]; App\\Gameplay\\Engine\\EngineLease::hold($argv[2]); file_put_contents($argv[3], (string)getmypid()); echo "child stdout"; sleep(60);';
    $child = new WindowsChild([$phpWin, '-r', $leaseCode, $base.'/vendor/autoload.php', $lease, $directory.'/direct.pid'], $base, getenv(), $directory.'/direct.log', $lease, $commands);
    if (! $child->ready(10) || ! $waitFor(fn () => is_file($directory.'/direct.pid'))) {
        throw new RuntimeException('Array-form child did not acquire its lease.');
    }
    $results['E10_proc_pid'] = $child->pid();
    $results['E10_php_pid'] = (int) file_get_contents($directory.'/direct.pid');
    $results['E10_identity'] = $host->identify(['worker' => $child->pid()]);
    $results['E4_direct_output'] = file_get_contents($directory.'/direct.log');
    $before = hrtime(true);
    $kill = $commands->run([PowerShell::executable('taskkill.exe'), '/PID', (string) $child->pid(), '/F']);
    $freed = $waitFor(fn () => ! EngineLease::held($lease), 1);
    $results['E6_taskkill_exit'] = $kill->exit;
    $results['E6_lease_free'] = $freed;
    $results['E6_release_ms_including_taskkill'] = round((hrtime(true) - $before) / 1_000_000, 3);
    $registrationFake = new class extends HostCommands
    {
        public function run(array $argv, int $timeout = 10, array $env = []): \App\Gameplay\Engine\HostResult
        {
            if (str_ends_with($argv[0], 'schtasks.exe')) {
                return new \App\Gameplay\Engine\HostResult($argv[1] === '/Query' ? 1 : 0);
            }

            return parent::run($argv, $timeout, $env);
        }
    };
    (new WindowsEngineHost($registrationFake))->install(PHP_BINARY, ['APP_KEY' => 'empirical-placeholder-not-a-secret']);
    $results['engine_acl'] = json_decode($powershell->run(<<<'POWERSHELL'
$acl = Get-Acl -LiteralPath $env:SPACETRADERS_TEST_ENGINE_DIRECTORY
ConvertTo-Json -InputObject @{ protected = $acl.AreAccessRulesProtected; allowed = @($acl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier]) | ForEach-Object { $_.IdentityReference.Value }) } -Compress
POWERSHELL, ['SPACETRADERS_TEST_ENGINE_DIRECTORY' => $directory.'/engine'])->out, true);
} catch (Throwable $exception) {
    $results['error'] = $exception->getMessage();
} finally {
    if ($child !== null && $child->isRunning()) {
        $child->kill();
    }
    if ($processes === [] && is_file($directory.'/starts.jsonl')) {
        $first = json_decode(file($directory.'/starts.jsonl', FILE_IGNORE_NEW_LINES)[0], true);
        $processes['parent'] = $first['pid'];
    }
    if (! isset($processes['descendant']) && is_file($directory.'/descendant.pid')) {
        $processes['descendant'] = (int) file_get_contents($directory.'/descendant.pid');
    }
    $results['cleanup_owned'] = trim($powershell->run(<<<'POWERSHELL'
$directory = $env:SPACETRADERS_TEST_DIRECTORY.Replace('\', '/')
Get-CimInstance Win32_Process -Filter "Name = 'php-win.exe'" | Where-Object {
    $null -ne $_.CommandLine -and $_.CommandLine.Replace('\', '/').Contains($directory)
} | ForEach-Object { Stop-Process -Id $_.ProcessId -Force; $_.ProcessId }
POWERSHELL, ['SPACETRADERS_TEST_DIRECTORY' => $directory])->out);
    if ($taskCreated) {
        $results['cleanup_delete'] = $taskCommand('/Delete', ['/F']);
    }
    $results['cleanup_query'] = $taskCommand('/Query');
    try {
        $results['cleanup_processes'] = $host->identify($processes);
    } catch (Throwable $exception) {
        $results['cleanup_identity_error'] = $exception->getMessage();
    }
    $results['powershell_failures'] = $commands->failures;
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
}
exit(isset($results['error']) ? 1 : 0);
