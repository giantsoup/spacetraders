<?php

namespace App\Console\Commands;

use App\Gameplay\Engine\EngineHost;
use App\Gameplay\GameException;
use App\Gameplay\LocalEngineManager;
use Illuminate\Console\Command;

class LocalEngineService extends Command
{
    protected $signature = 'spacetraders:engine-service {operation : install, uninstall or status}';

    protected $description = 'Install, remove or inspect only this checkout/database’s on-demand local engine service';

    public function handle(LocalEngineManager $manager, EngineHost $host): int
    {
        try {
            match ($this->argument('operation')) {
                'install' => $manager->install(),
                'uninstall' => $manager->uninstall(),
                'status' => null,
                default => throw new GameException('Choose install, uninstall or status.'),
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
}
