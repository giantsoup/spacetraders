<?php

namespace SpaceTraders\Windows;

use App\Gameplay\Engine\EngineHost;
use App\Gameplay\EngineGate;
use App\Gameplay\LocalEngineManager;
use App\Models\LocalEngine;
use App\Models\User;
use RuntimeException;

final class PracticeEnvironment
{
    public static function mode(string $directory): string
    {
        $file = $directory.'/.practice-mode';
        $mode = is_file($file) ? trim(file_get_contents($file)) : 'worker';
        if (! in_array($mode, ['worker', 'engine'], true)) {
            throw new RuntimeException('Invalid persisted practice mode. Inspect the owned environment before proceeding.');
        }

        return $mode;
    }

    public static function assertMode(string $directory, bool $enabled, string $command): void
    {
        $engine = self::mode($directory) === 'engine';
        if ($engine !== $enabled) {
            throw new RuntimeException('Practice mode does not match the effective engine configuration. Reset before changing modes.');
        }
        if ($engine && in_array($command, ['queue:work', 'queue:listen', 'schedule:work', 'schedule:run', 'spacetraders:engine-supervise', '--verify-worker'], true)) {
            throw new RuntimeException('Plain workers and schedulers are forbidden in engine practice. Use the local engine controls.');
        }
        if (! $engine && (str_starts_with($command, '--engine-') || str_starts_with($command, 'spacetraders:engine-'))) {
            throw new RuntimeException('Engine controls are forbidden in worker practice. Reset before changing modes.');
        }
    }

    public static function assertEngineIdentity(string $mainTask): void
    {
        if (app(EngineHost::class)->serviceName() === $mainTask) {
            throw new RuntimeException('Practice must never use the main checkout engine task.');
        }
        if (! app()->environment('local') || ! config('gameplay.enabled') || ! config('gameplay.operator_id')
            || config('gameplay.local_host') !== '127.0.0.1' || config('app.url') !== 'http://127.0.0.1:8010') {
            throw new RuntimeException('Engine practice requires local automation, a seeded operator and the 127.0.0.1:8010 host.');
        }
        $login = json_decode(file_get_contents(dirname(config('gameplay.fixture_path')).'/browser.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! User::whereKey(config('gameplay.operator_id'))->where('email', $login['email'] ?? '')->exists()) {
            throw new RuntimeException('Practice operator does not match the seeded login.');
        }
    }

    public static function configureEngine(string $directory): void
    {
        if (self::mode($directory) === 'engine') {
            return;
        }
        $login = json_decode(file_get_contents($directory.'/browser.json'), true, flags: JSON_THROW_ON_ERROR);
        $user = User::where('email', $login['email'] ?? '')->sole();
        $values = [
            'SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'true', 'SPACETRADERS_AUTOMATION_ENABLED' => 'true',
            'SPACETRADERS_OPERATOR_ID' => (string) $user->id, 'SPACETRADERS_LOCAL_HOST' => '127.0.0.1',
            'APP_URL' => 'http://127.0.0.1:8010',
        ];
        $environment = file_get_contents($directory.'/.env');
        foreach ($values as $name => $value) {
            $line = $name.'="'.$value.'"';
            $pattern = '/^'.preg_quote($name, '/').'=[^\r\n]*/m';
            $environment = preg_match($pattern, $environment)
                ? preg_replace_callback($pattern, fn () => $line, $environment)
                : $environment."\n".$line."\n";
        }
        if (file_put_contents($directory.'/.env.tmp', $environment) === false || ! rename($directory.'/.env.tmp', $directory.'/.env')
            || file_put_contents($directory.'/.practice-mode', 'engine') === false) {
            throw new RuntimeException('Could not persist engine practice configuration. Inspect the owned environment.');
        }
    }

    public static function snapshot(): array
    {
        $manager = app(LocalEngineManager::class);
        $status = $manager->status();
        $engine = LocalEngine::find(1);
        $leases = [];
        foreach (['supervisor', 'worker', 'scheduler'] as $role) {
            $leases[$role] = $manager->alive($engine, $role);
        }

        return array_merge($status, [
            'mode' => 'engine', 'task' => app(EngineHost::class)->serviceName(),
            'registered' => app(EngineHost::class)->registered(),
            'installed' => app(EngineHost::class)->hasServiceMarker(),
            'leases' => $leases, 'pids' => array_intersect_key($engine?->processes ?? [], $leases),
        ]);
    }

    public static function stopped(array $status): bool
    {
        return in_array($status['state'], ['off', 'faulted'], true) && ! $status['gate_open']
            && ($status['leases']['supervisor'] ?? null) === false
            && ($status['leases']['worker'] ?? null) === false
            && ($status['leases']['scheduler'] ?? null) === false;
    }

    public static function end(bool $force): array
    {
        $status = self::snapshot();
        if ($status['state'] === 'off' && self::stopped($status)) {
            return $status;
        }
        app(LocalEngineManager::class)->end();
        $deadline = hrtime(true) + ($force ? 120_000_000_000 : 45_000_000_000);
        do {
            $status = self::snapshot();
            if (self::stopped($status)) {
                return $status;
            }
            if ($force && $status['state'] === 'faulted' && ($status['stop_requested_at'] ?? null)?->lte(now()->subSeconds(40))) {
                app(LocalEngineManager::class)->forceStop();
                $status = self::snapshot();
                if (! self::stopped($status)) {
                    throw new RuntimeException('Verified force stop did not confirm all engine leases released. Serve was left running.');
                }

                return $status;
            }
            usleep(200000);
        } while (hrtime(true) < $deadline);
        if (! $force) {
            throw new RuntimeException('Engine processes remain after 45 seconds. Serve was left running. Inspect Status; retry Stop -Force for verified force stop.');
        }
        throw new RuntimeException('Engine force-stop allowance did not become available within 120 seconds. Serve was left running. Inspect Status before retrying Stop -Force.');
    }

    public static function verify(): array
    {
        app(LocalEngineManager::class)->start();
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            $status = self::snapshot();
            if ($status['state'] === 'running' && $status['gate_open'] && ! in_array(false, $status['leases'], true)) {
                if (app(EngineGate::class)->session() !== $status['session_id']) {
                    throw new RuntimeException('Practice engine gate did not authorize the live session.');
                }

                return $status;
            }
            if ($status['state'] === 'faulted') {
                throw new RuntimeException('Practice engine faulted: '.($status['message'] ?? 'Inspect engine logs.'));
            }
            usleep(200000);
        } while (hrtime(true) < $deadline);

        throw new RuntimeException('Practice engine did not reach running with an open gate and all three leases.');
    }
}
