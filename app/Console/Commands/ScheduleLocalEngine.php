<?php

namespace App\Console\Commands;

use App\Models\LocalEngine;
use Illuminate\Console\Command;

class ScheduleLocalEngine extends Command
{
    protected $signature = 'spacetraders:engine-schedule';

    protected $description = 'Dispatch due work for the current owned engine session';

    public function handle(): int
    {
        pcntl_async_signals(true);
        $stop = false;
        pcntl_signal(SIGTERM, function () use (&$stop) {
            $stop = true;
        });
        pcntl_signal(SIGINT, function () use (&$stop) {
            $stop = true;
        });
        while (! $stop) {
            if (LocalEngine::find(1)?->gate_open) {
                $this->callSilent('spacetraders:dispatch-due');
            }
            for ($i = 0; $i < 25 && ! $stop; $i++) {
                usleep(200000);
            }
        }

        return self::SUCCESS;
    }
}
