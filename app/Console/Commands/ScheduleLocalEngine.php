<?php

namespace App\Console\Commands;

use App\Gameplay\DeploymentGate;
use App\Gameplay\Engine\EngineChildWatch;
use App\Gameplay\Engine\EngineHost;
use App\Models\LocalEngine;
use Illuminate\Console\Command;

class ScheduleLocalEngine extends Command
{
    protected $signature = 'spacetraders:engine-schedule';

    protected $description = 'Dispatch due work for the current owned engine session';

    public function handle(): int
    {
        $stop = false;
        app(EngineHost::class)->onStopSignal(function () use (&$stop) {
            $stop = true;
        });
        $watch = app(EngineChildWatch::class);
        while (! $stop && ! $watch->shouldStop()) {
            $gate = app(DeploymentGate::class);
            $id = $gate->begin('scheduler');
            try {
                if ($id !== null && LocalEngine::find(1)?->gate_open) {
                    $this->callSilent('spacetraders:dispatch-due');
                }
            } finally {
                if ($id !== null) {
                    $gate->finish($id);
                }
            }
            for ($i = 0; $i < 25 && ! $stop && ! $watch->shouldStop(); $i++) {
                usleep(200000);
            }
        }

        return self::SUCCESS;
    }
}
