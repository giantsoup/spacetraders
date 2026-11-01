<?php

namespace App\Console\Commands;

use App\Gameplay\AutomationRunner;
use App\Gameplay\EngineGate;
use App\Gameplay\HostLock;
use App\Gameplay\LocalEngineManager;
use App\Models\AutomationRun;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SuperviseLocalEngine extends Command
{
    protected $signature = 'spacetraders:engine-supervise';

    protected $description = 'Own exactly one worker and scheduler until the local session ends';

    public function handle(HostLock $lock, EngineGate $gate, LocalEngineManager $manager): int
    {
        if (! $gate->enabled() || ! app()->environment('local') || ! is_file($manager->directory().'/service.plist')) {
            return self::FAILURE;
        }

        return $lock->run('engine-instance', function () use ($gate) {
            $engine = $gate->begin();
            $engine->update(['processes' => ['supervisor' => getmypid()]]);
            pcntl_async_signals(true);
            $stop = fn () => $gate->close();
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
            $children = [];
            try {
                foreach ([
                    'worker' => ['queue:work', 'database', '--queue=spacetraders', '--sleep=1', '--tries=1', '--timeout=120'],
                    'scheduler' => ['spacetraders:engine-schedule'],
                ] as $name => $arguments) {
                    $child = new Process(array_merge([config('gameplay.php_binary'), base_path('artisan')], $arguments), base_path());
                    $child->setTimeout(null)->disableOutput()->start();
                    $children[$name] = $child;
                }
                usleep(300000);
                if (! $children['worker']->isRunning() || ! $children['scheduler']->isRunning()) {
                    throw new \RuntimeException('Owned child failed to start.');
                }
                $engine->update(['processes' => ['supervisor' => getmypid(), 'worker' => $children['worker']->getPid(), 'scheduler' => $children['scheduler']->getPid()]]);
                LocalEngine::whereKey(1)->where('session_id', $engine->session_id)->whereNull('stop_requested_at')->update(['state' => 'running', 'gate_open' => true, 'heartbeat_at' => now(), 'processes' => json_encode(['supervisor' => getmypid(), 'worker' => $children['worker']->getPid(), 'scheduler' => $children['scheduler']->getPid()]), 'message' => 'Worker and scheduler alive. Awaiting new approvals.']);
                $last = microtime(true);
                $shutdown = null;
                $signalled = false;
                while (true) {
                    $gap = microtime(true) - $last;
                    $last = microtime(true);
                    $engine->refresh();
                    if ($gap > 15) {
                        $gate->close('faulted', 'Sleep or heartbeat gap detected. Refresh state and start a fresh session.');
                        $engine->refresh();
                    }
                    if (! $engine->stop_requested_at && (! $children['worker']->isRunning() || ! $children['scheduler']->isRunning())) {
                        $gate->close('faulted', 'An owned child exited. New approvals are closed.');
                        $engine->refresh();
                    }
                    LocalEngine::whereKey(1)->where('session_id', $engine->session_id)->update(['heartbeat_at' => now()]);
                    if ($engine->stop_requested_at) {
                        $shutdown ??= microtime(true);
                        if (! $signalled) {
                            foreach ($children as $child) {
                                if ($child->isRunning()) {
                                    $child->signal(SIGTERM);
                                }
                            }
                            $signalled = true;
                        }
                        if (! $children['worker']->isRunning() && ! $children['scheduler']->isRunning()) {
                            foreach (AutomationRun::whereNotNull('active_agent_id')->whereNotNull('stop_requested_at')->get() as $run) {
                                if (! ShipAction::where('agent_id', $run->agent_id)->where(fn ($q) => $q->whereIn('state', ['sending', 'uncertain'])->orWhere('review_required', true))->exists()) {
                                    app(AutomationRunner::class)->finishStop($run, 'Session ended. Reserved work settled; new preview required.');
                                }
                            }
                            $engine->update(['state' => $engine->state === 'faulted' ? 'faulted' : 'off', 'gate_open' => false, 'message' => 'Owned processes exited. Any uncertain action still requires reconciliation.']);
                            break;
                        }
                        if (microtime(true) - $shutdown > 40) {
                            $engine->update(['state' => 'faulted', 'gate_open' => false, 'message' => 'Graceful shutdown exceeded 40 seconds. Force stop may interrupt an upstream request.']);
                        }
                    }
                    usleep(200000);
                }

                return self::SUCCESS;
            } catch (\Throwable) {
                $gate->close('faulted', 'Supervisor failed. Reconcile any reserved action before a fresh preview.');
                foreach ($children as $child) {
                    if ($child->isRunning()) {
                        $child->stop(2, SIGTERM);
                    }
                }

                return self::FAILURE;
            }
        });
    }
}
