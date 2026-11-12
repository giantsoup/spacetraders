<?php

namespace App\Gameplay\Engine\Windows;

use App\Gameplay\Engine\EngineHost;
use App\Gameplay\Engine\EngineLease;
use App\Gameplay\Engine\HostCommands;
use App\Gameplay\Engine\HostResult;
use App\Gameplay\Engine\OwnedChild;
use App\Gameplay\GameException;
use App\Models\LocalEngine;
use Illuminate\Support\Facades\Log;

final class WindowsEngineHost implements EngineHost
{
    public function __construct(private readonly HostCommands $commands) {}

    public function supported(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public function platform(): string
    {
        return 'Windows';
    }

    public function serviceName(): string
    {
        return 'SpaceTraders-Engine-'.substr(hash('sha256', realpath(base_path()).'|'.config('database.connections.sqlite.database')), 0, 24);
    }

    private function directory(): string
    {
        return config('gameplay.lock_path').'/engine';
    }

    public function hasServiceMarker(): bool
    {
        return is_file($this->directory().'/service.json');
    }

    public function registered(): bool
    {
        return $this->task('/Query')->successful();
    }

    public function install(string $php, array $env): void
    {
        if ($this->registered()) {
            throw new GameException('The engine task is already registered. Uninstall it before installing again.');
        }
        $php = realpath($php) ?: throw new GameException('The configured PHP binary does not exist.');
        $windowless = $this->windowlessPhp($php);
        $directory = $this->directory();
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new GameException('Could not create the private engine directory.');
        }
        $security = $this->powershell()->run(<<<'POWERSHELL'
$path = $env:SPACETRADERS_ENGINE_DIRECTORY
$user = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
$item = Get-Item -LiteralPath $path -Force
for ($ancestor = $item; $null -ne $ancestor; $ancestor = $ancestor.Parent) {
    if ($ancestor.Attributes -band [System.IO.FileAttributes]::ReparsePoint) { throw 'Unsafe engine directory' }
}
$items = @($item) + @(Get-ChildItem -LiteralPath $path -Recurse -Force)
foreach ($entry in $items) {
    if ($entry.Attributes -band [System.IO.FileAttributes]::ReparsePoint) { throw 'Unsafe engine file' }
    $acl = Get-Acl -LiteralPath $entry.FullName
    if ($acl.GetOwner([System.Security.Principal.SecurityIdentifier]).Value -ne $user.Value) { throw 'Engine owner differs' }
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($rule in @($acl.GetAccessRules($true, $false, [System.Security.Principal.SecurityIdentifier]))) {
        $acl.RemoveAccessRuleSpecific($rule)
    }
    $inheritance = if ($entry.PSIsContainer) { 'ContainerInherit, ObjectInherit' } else { 'None' }
    foreach ($sid in @($user.Value, 'S-1-5-18', 'S-1-5-32-544')) {
        $identity = New-Object System.Security.Principal.SecurityIdentifier($sid)
        $rule = New-Object System.Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', $inheritance, 'None', 'Allow')
        $acl.AddAccessRule($rule)
    }
    $entry.SetAccessControl($acl)
}
$user.Value
POWERSHELL, ['SPACETRADERS_ENGINE_DIRECTORY' => $directory]);
        $sid = trim($security->out);
        if (! $security->successful() || ! preg_match('/\AS-1-\d+(?:-\d+)+\z/', $sid)) {
            throw new GameException('Could not secure the engine directory for its Windows owner.');
        }
        foreach ($this->logPaths() as $log) {
            self::rotate($log);
        }
        $env = array_replace($env, [
            'LOG_CHANNEL' => 'engine', 'SPACETRADERS_ENGINE_LOG' => $directory.'/supervisor.log', 'SPACETRADERS_PHP_BINARY' => $php,
        ]);
        $this->write($directory.'/service.json', json_encode($env, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->write($directory.'/task.xml', ScheduledTaskXml::render($this->serviceName(), $sid, $windowless, base_path(), $directory.'/service.json'));
        if (! $this->task('/Create', ['/XML', $directory.'/task.xml'])->successful()) {
            throw new GameException('Could not register the on-demand engine task. Inspect the Windows Task Scheduler.');
        }
    }

    public function launch(): void
    {
        if (! $this->task('/Run')->successful()) {
            throw new GameException('Could not start the registered engine task.');
        }
    }

    public function uninstall(): void
    {
        $this->task('/Delete', ['/F']);
        if ($this->registered()) {
            throw new GameException('Could not confirm the engine task was removed. Inspect the Windows Task Scheduler.');
        }
        $this->removeMarkers();
    }

    public function forceStop(LocalEngine $engine): void
    {
        $processes = $engine->processes ?? [];
        $pids = [];
        foreach (['worker', 'scheduler', 'supervisor'] as $role) {
            if (isset($processes[$role]) && is_int($processes[$role]) && $processes[$role] > 0) {
                $pids[$role] = $processes[$role];
            }
        }
        $identities = $processes['identity'] ?? [];
        $current = $this->identify($pids);
        foreach ($current as $role => $identity) {
            if (! isset($identities[$role]) && $engine->session_id !== null && ! $this->alive($engine, $role)) {
                Log::channel('engine')->warning('Skipping unverifiable engine PID because its session lease is released.', [
                    'session' => $engine->session_id, 'role' => $role, 'pid' => $identity['pid'],
                ]);
                unset($current[$role]);

                continue;
            }
            if (isset($identities[$role]['created_at']) && $identities[$role]['created_at'] !== $identity['created_at']) {
                unset($current[$role]);

                continue;
            }
            if (! $this->matches($identities[$role] ?? null, $identity)) {
                throw new GameException('Could not confirm ownership of an engine process. Inspect it before force stopping.');
            }
        }
        foreach ($pids as $role => $pid) {
            if (isset($current[$role])) {
                $this->commands->run([PowerShell::executable('taskkill.exe'), '/PID', (string) $pid, '/F']);
            }
        }
        $this->task('/End');
        $this->task('/Delete', ['/F']);
        $remaining = $this->identify($pids);
        foreach ($remaining as $role => $identity) {
            if (($identities[$role]['created_at'] ?? null) === $identity['created_at']) {
                throw new GameException('Could not confirm all owned engine processes exited. Inspect the engine logs.');
            }
        }
        if ($this->registered()) {
            throw new GameException('Could not confirm the engine task was removed. Inspect the Windows Task Scheduler.');
        }
        $this->removeMarkers();
    }

    public function alive(LocalEngine $engine, string $role): bool
    {
        return $engine->session_id !== null && EngineLease::held(EngineLease::path($engine->session_id, $role));
    }

    public function claim(string $session, string $role): void
    {
        EngineLease::hold(EngineLease::path($session, $role));
    }

    public function spawn(string $session, string $role, array $artisanArguments): OwnedChild
    {
        $lease = EngineLease::path($session, $role);
        if ($role === 'supervisor') {
            throw new \InvalidArgumentException('Only worker and scheduler are child roles.');
        }
        $log = $this->logPaths()[$role];
        self::rotate($log);
        $env = array_replace(getenv(), [
            'LOG_CHANNEL' => 'engine', 'SPACETRADERS_ENGINE_SESSION' => $session, 'SPACETRADERS_ENGINE_ROLE' => $role,
            'SPACETRADERS_ENGINE_LEASE' => $lease, 'SPACETRADERS_ENGINE_LOG' => $log,
        ]);

        return new WindowsChild([$this->windowlessPhp(config('gameplay.php_binary')), base_path('artisan'), ...$artisanArguments], base_path(), $env, $log, $lease, $this->commands);
    }

    public function onStopSignal(callable $stop): void {}

    public function identify(array $pids): array
    {
        if ($pids === []) {
            return [];
        }
        foreach ($pids as $pid) {
            if (! is_int($pid) || $pid < 1) {
                throw new \InvalidArgumentException('Invalid engine process ID.');
            }
        }
        $result = $this->powershell()->run(<<<'POWERSHELL'
$ids = ConvertFrom-Json -InputObject $env:SPACETRADERS_ENGINE_PIDS
$filter = ($ids | ForEach-Object { 'ProcessId = ' + [int]$_ }) -join ' OR '
$hash = [System.Security.Cryptography.SHA256]::Create()
try {
    $records = @(Get-CimInstance Win32_Process -Filter $filter | ForEach-Object {
        $process = Get-Process -Id $_.ProcessId -ErrorAction SilentlyContinue
        if ($null -eq $process -or $process.HasExited) { return }
        if ($null -eq $_.CreationDate) { throw 'Process identity unavailable' }
        $commandHash = $null
        if ($null -ne $_.CommandLine) {
            $digest = $hash.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($_.CommandLine))
            $commandHash = ([System.BitConverter]::ToString($digest)).Replace('-', '').ToLowerInvariant()
        }
        @{ pid = [int]$_.ProcessId; created_at = $_.CreationDate.ToUniversalTime().ToString('o'); command_hash = $commandHash }
    })
    ConvertTo-Json -InputObject $records -Compress
} finally { $hash.Dispose() }
POWERSHELL, ['SPACETRADERS_ENGINE_PIDS' => json_encode(array_values($pids), JSON_THROW_ON_ERROR)]);
        $records = json_decode($result->out, true);
        if (! $result->successful() || ! is_array($records) || ! array_is_list($records)) {
            throw new GameException('Could not confirm Windows engine process identities.');
        }
        $identities = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! is_int($record['pid'] ?? null) || ! is_string($record['created_at'] ?? null)
                || ! array_key_exists('command_hash', $record)
                || ($record['command_hash'] !== null && (! is_string($record['command_hash']) || ! preg_match('/\A[0-9a-f]{64}\z/', $record['command_hash'])))) {
                throw new GameException('Could not confirm Windows engine process identities.');
            }
            foreach ($pids as $role => $pid) {
                if ($pid === $record['pid']) {
                    $identities[$role] = $record;
                }
            }
        }

        return $identities;
    }

    public function logPaths(): array
    {
        return array_combine(['supervisor', 'worker', 'scheduler'], array_map(fn ($role) => $this->directory().'/'.$role.'.log', ['supervisor', 'worker', 'scheduler']));
    }

    private function matches(mixed $stored, array $current): bool
    {
        return is_array($stored) && ($stored['pid'] ?? null) === $current['pid']
            && ($stored['created_at'] ?? null) === $current['created_at']
            && is_string($current['command_hash'])
            && ($stored['command_hash'] ?? null) === $current['command_hash'];
    }

    private function task(string $operation, array $arguments = []): HostResult
    {
        return $this->commands->run([PowerShell::executable('schtasks.exe'), $operation, '/TN', $this->serviceName(), ...$arguments]);
    }

    private function powershell(): PowerShell
    {
        return new PowerShell($this->commands);
    }

    private function windowlessPhp(string $php): string
    {
        $path = dirname($php).'/php-win.exe';
        if (! is_file($path)) {
            throw new GameException('The configured PHP binary needs a sibling php-win.exe.');
        }

        return $path;
    }

    private static function rotate(string $path): void
    {
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) > 10 * 1024 * 1024) {
            if ((file_exists($path.'.1') && ! @unlink($path.'.1')) || ! @rename($path, $path.'.1')) {
                try {
                    Log::channel('engine')->warning('Could not rotate an engine log; continuing with the existing log.', ['path' => $path]);
                } catch (\Throwable) {
                }
            }
        }
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new GameException('Could not write the private engine service configuration.');
        }
    }

    private function removeMarkers(): void
    {
        foreach (['service.json', 'task.xml'] as $name) {
            $path = $this->directory().'/'.$name;
            if (is_file($path) && ! unlink($path)) {
                throw new GameException('Could not remove the engine service configuration.');
            }
        }
    }
}
