<?php

namespace App\Console\Commands;

use App\Gameplay\Engine\EngineHost;
use App\Gameplay\GameException;
use App\Gameplay\LocalEngineManager;
use App\Models\LocalEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

class LocalEngineService extends Command
{
    protected $signature = 'spacetraders:engine-service {operation : install, uninstall, status, start or end}';

    protected $description = 'Install, remove, inspect, start or end only this checkout/database’s on-demand local engine service';

    public function handle(LocalEngineManager $manager, EngineHost $host): int
    {
        try {
            if ($this->argument('operation') === 'start') {
                return $this->startEngine($manager);
            }
            if ($this->argument('operation') === 'end') {
                return $this->endEngine($manager);
            }

            match ($this->argument('operation')) {
                'install' => $manager->install(),
                'uninstall' => $manager->uninstall(),
                'status' => null,
                default => throw new GameException('Choose install, uninstall, status, start or end.'),
            };
            if ($this->argument('operation') !== 'uninstall') {
                $this->line('Platform: '.$host->platform());
                $this->line('Service name: '.$host->serviceName());
                $this->line('Checkout: '.base_path());
                $this->line('Engine directory: '.$manager->directory());
                foreach ($host->logPaths() as $role => $path) {
                    $this->line(ucfirst($role).' log: '.$path);
                }
                if ($this->argument('operation') === 'status') {
                    $registered = $host->registered();
                    $this->line('Installed: '.($host->hasServiceMarker() && $registered ? 'yes' : 'no'));
                    $this->line('Registered: '.($registered ? 'yes' : 'no'));
                    $status = $manager->status();
                    $this->line('Engine state: '.$status['state']);
                    $this->line('Engine message: '.($status['message'] ?? ''));
                } else {
                    $this->info('Engine remains off. Start it from the flight deck. No gameplay approved.');
                }
            } else {
                $this->info('Owned service operation verified. No gameplay approved.');
            }

            return self::SUCCESS;
        } catch (GameException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function startEngine(LocalEngineManager $manager): int
    {
        if ($manager->status()['state'] === 'faulted') {
            throw new GameException('Engine is faulted. Review it on spacetraders.test before starting.');
        }

        $deadline = now()->addSeconds(60);
        $manager->start();
        do {
            $status = $manager->status();
            if ($status['state'] === 'running') {
                $this->line('Engine state: running');

                return self::SUCCESS;
            }
            if (now()->gte($deadline)) {
                break;
            }
            Sleep::for(500)->milliseconds();
        } while (true);

        $this->line('Engine state: '.$status['state']);
        $this->line('Engine message: '.($status['message'] ?? ''));
        throw new GameException('Engine did not reach running within 60 seconds.');
    }

    private function endEngine(LocalEngineManager $manager): int
    {
        if ($manager->status()['state'] === 'off') {
            $this->line('Engine state: off');

            return self::SUCCESS;
        }

        $engine = LocalEngine::find(1);
        $deadline = now()->addSeconds(60);
        $manager->end();
        do {
            $status = $manager->status();
            $alive = array_filter(['supervisor', 'worker', 'scheduler'], fn ($role) => $manager->alive($engine, $role));
            if ($status['state'] === 'off' && $alive === []) {
                $this->line('Engine state: off');

                return self::SUCCESS;
            }
            if (now()->gte($deadline)) {
                break;
            }
            Sleep::for(500)->milliseconds();
        } while (true);

        $this->line('Engine state: '.$status['state']);
        $this->line('Engine message: '.($status['message'] ?? ''));
        throw new GameException('Engine did not finish ending within 60 seconds. Review Force stop on spacetraders.test.');
    }
}
