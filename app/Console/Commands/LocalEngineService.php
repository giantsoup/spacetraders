<?php

namespace App\Console\Commands;

use App\Gameplay\GameException;
use App\Gameplay\LocalEngineManager;
use Illuminate\Console\Command;

class LocalEngineService extends Command
{
    protected $signature = 'spacetraders:engine-service {operation : install or uninstall}';

    protected $description = 'Install or remove only this checkout/database’s on-demand user LaunchAgent';

    public function handle(LocalEngineManager $manager): int
    {
        try {
            match ($this->argument('operation')) {
                'install' => $manager->install(),
                'uninstall' => $manager->uninstall(),
                default => throw new GameException('Choose install or uninstall.'),
            };
            $this->info('Owned service operation verified. No gameplay approved.');

            return self::SUCCESS;
        } catch (GameException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
