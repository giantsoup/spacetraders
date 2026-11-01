<?php

// Real queue workers, duplicate deliveries and process-death recovery. Every case owns a new fake world/database.
require __DIR__.'/../vendor/autoload.php';

use App\Gameplay\AutomationRunner;
use App\Gameplay\AutomationStaging;
use App\Gameplay\EngineGate;
use App\Gameplay\OpportunityInvestigator;
use App\Gameplay\ShipCommandExecutor;
use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ExecuteShipCommand;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\LocalEngine;
use App\Models\ShipAction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$case = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--case=')) {
        $case = substr($argument, 7);
    }
}
if ($case === null) {
    foreach (['complete', 'contract_accept', 'buy', 'contract_deliver', 'contract_fulfill'] as $scenario) {
        $process = new Process([PHP_BINARY, __FILE__, '--case='.$scenario], $root);
        $process->setTimeout(120)->mustRun();
        echo $process->getOutput();
    }
    exit(0);
}
$fixture = new Process([PHP_BINARY, 'scripts/mvp-fixture.php', '--isolated'], $root);
$fixture->mustRun();
preg_match('/Owned fixture directory: (.+)/', $fixture->getOutput(), $matches);
$directory = $matches[1];
$env = array_merge(Dotenv\Dotenv::parse(file_get_contents($directory.'/.env')), ['SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'true', 'APP_CONFIG_CACHE' => $directory.'/no-config.php']);
foreach ($env as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$agent = Agent::firstOrFail();
$file = $env['SPACETRADERS_FIXTURE_PATH'];
$steps = [];
function checkAutomation(bool $value, string $message): void
{
    global $steps;
    if (! $value) {
        throw new RuntimeException($message);
    }
    $steps[] = $message;
}
function pulse(): void
{
    LocalEngine::whereKey(1)->update(['state' => 'running', 'gate_open' => true, 'heartbeat_at' => now()]);
}
function finiteWorkers(): void
{
    global $root, $directory, $env;
    pulse();
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $process = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], $root, $env);
        $process->setTimeout(30)->start();
        $workers[] = $process;
    }
    foreach ($workers as $process) {
        $process->wait();
        checkAutomation($process->isSuccessful(), 'Finite database worker exited successfully');
    }
}
$investigator = app(OpportunityInvestigator::class);
$investigation = $investigator->create($agent, 'contracts', 'X1-TEST');
while ($investigation->fresh()->status === 'investigating') {
    $investigator->advance($agent, $investigation);
}
$candidate = collect($investigation->fresh()->candidates)->firstWhere('classification', 'ready');
checkAutomation($candidate !== null, 'Read-only investigation found a verified contract');
checkAutomation(json_decode(file_get_contents($file), true)['writes'] === [], 'Investigation caused no game mutations');
$draft = app(AutomationStaging::class)->save($agent, $investigation->fresh(), $candidate['id'], ['reserve' => 2000, 'max_spend' => 500, 'max_loss' => 500, 'minutes' => 60, 'cycles' => 1, 'delivery_limit' => 10, 'purchase_limit' => 40]);
app(EngineGate::class)->begin();
pulse();
$run = app(AutomationRunner::class)->previewDraft($agent, $draft);
app(AutomationRunner::class)->start($agent, $run, true);
app(AutomationRunner::class)->start($agent, $run, true);
AdvanceAutomationRun::dispatch($run->id);
if ($case === 'complete') {
    for ($i = 0; $i < 40 && $run->fresh()->active_agent_id; $i++) {
        AutomationRun::whereKey($run->id)->update(['next_action_at' => now(), 'last_dispatched_at' => null]);
        Artisan::call('spacetraders:dispatch-due');
        foreach (ShipAction::where('state', 'pending')->get() as $pending) {
            ExecuteShipCommand::dispatch($pending->id);
        }
        finiteWorkers();
    }
    $run->refresh();
    $world = json_decode(file_get_contents($file), true);
    checkAutomation($run->state->value === 'completed', 'Complete contract crossed real worker restarts: '.($run->blocked_reason ?? ''));
    checkAutomation($run->totals['cash_contribution'] === 1300 && $run->totals['receipts'] === 1600 && $run->totals['acquisition_cost'] === 300, 'Confirmed contract contribution and receipts persisted separately');
    checkAutomation(count($world['writes']) === ShipAction::where('state', 'confirmed')->count(), 'Duplicate jobs and two workers produced exactly one write per confirmed intent');
    checkAutomation(DB::table('confirmed_credit_entries')->count() === count($world['writes']), 'Each action was accounted exactly once');
    checkAutomation(count(array_filter($world['writes'], fn ($w) => str_ends_with($w['path'], '/accept'))) === 1 && count(array_filter($world['writes'], fn ($w) => str_ends_with($w['path'], '/fulfill'))) === 1, 'Acceptance and completion paid once');
} else {
    // Keep advancement in this controlling process so the crash lands on the exact requested command.
    DB::table('jobs')->delete();
    $target = null;
    for ($i = 0; $i < 40; $i++) {
        pulse();
        $run->update(['next_action_at' => now()]);
        app(AutomationRunner::class)->advance($run->id);
        $pending = ShipAction::where('state', 'pending')->first();
        DB::table('jobs')->delete();
        if (! $pending) {
            continue;
        }
        if ($pending->command === $case) {
            $target = $pending;
            break;
        }
        ExecuteShipCommand::dispatch($pending->id);
        finiteWorkers();
    }
    checkAutomation($target !== null, 'Reached crash boundary '.$case);
    $world = json_decode(file_get_contents($file), true);
    $world['failure'] = 'pause_after';
    file_put_contents($file, json_encode($world));
    ExecuteShipCommand::dispatch($target->id);
    $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], $root, $env);
    $worker->start();
    try {
        $deadline = microtime(true) + 8;
        while (! is_file($file.'.sent') && microtime(true) < $deadline) {
            usleep(20000);
        }
        checkAutomation(is_file($file.'.sent'), 'Real worker reached committed-upstream/lost-response window for '.$case);
        $worker->signal(SIGKILL);
        $worker->wait();
    } finally {
        if ($worker->isRunning()) {
            $worker->stop(1, SIGKILL);
        }
    }
    $target->refresh();
    checkAutomation($target->state->value === 'sending', 'Killed sender retains durable sending intent');
    $world = json_decode(file_get_contents($file), true);
    $world['failure'] = null;
    $writes = count($world['writes']);
    file_put_contents($file, json_encode($world));
    $target->update(['sent_at' => now()->subMinutes(4)]);
    DB::table('jobs')->whereNotNull('reserved_at')->update(['reserved_at' => time() - 200, 'attempts' => 0]);
    pulse();
    Artisan::call('spacetraders:dispatch-due');
    ExecuteShipCommand::dispatch($target->id);
    finiteWorkers();
    checkAutomation($target->fresh()->state->value === 'uncertain', 'Recovery holds the uncertain '.$case.' rather than replaying');
    checkAutomation(count(json_decode(file_get_contents($file), true)['writes']) === $writes, 'No duplicate game mutation after '.$case.' process death');
    app(ShipCommandExecutor::class)->reconcile($agent, $target);
    checkAutomation(isset($target->fresh()->outcome['contract']), 'Fresh contract progress retained as reconciliation evidence');
    app(EngineGate::class)->begin();
    pulse();
    AdvanceAutomationRun::dispatch($run->id);
    finiteWorkers();
    checkAutomation(count(json_decode(file_get_contents($file), true)['writes']) === $writes, 'Engine session restart cannot replay uncertain work');
}
checkAutomation(DB::table('failed_jobs')->count() === 0, 'No failed jobs in the disposable queue');
file_put_contents($directory.'/automation-worker-result.json', json_encode(['case' => $case, 'passed' => true, 'steps' => $steps], JSON_PRETTY_PRINT));
echo 'PASS '.$case.'; '.count($steps).' checks. Owned offline evidence: '.$directory."\n";
