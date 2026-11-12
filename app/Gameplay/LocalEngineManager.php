<?php

namespace App\Gameplay;

use App\Gameplay\Engine\EngineHost;
use App\Gameplay\Engine\EngineIdentity;
use App\Models\LocalEngine;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class LocalEngineManager
{
    public function __construct(private HostLock $lock, private EngineGate $gate, private EngineHost $host) {}

    public function authorize(Request $request): void
    {
        abort_unless(app()->environment('local') && $this->gate->enabled()
            && (string) $request->user()->id === (string) config('gameplay.operator_id')
            && $request->getHost() === config('gameplay.local_host')
            && in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
    }

    public function label(): string
    {
        return EngineIdentity::label();
    }

    public function directory(): string
    {
        return EngineIdentity::directory();
    }

    public function install(): void
    {
        if (! $this->host->supported() || ! app()->environment('local') || ! $this->gate->enabled() || ! config('gameplay.operator_id') || config('database.default') !== 'sqlite') {
            throw new GameException('Engine installation requires a supported platform (macOS or Windows), local mode, an operator, SQLite and the local engine feature.');
        }
        if (! User::find(config('gameplay.operator_id'))) {
            throw new GameException('Engine installation requires the configured operator user to exist.');
        }
        if (parse_url(config('app.url'), PHP_URL_HOST) !== config('gameplay.local_host')) {
            throw new GameException('Engine installation requires the APP_URL host to match the configured local engine host.');
        }
        $php = realpath(config('gameplay.php_binary'));
        $version = trim(file_get_contents(base_path('.php-version')));
        if (! $php || ! is_executable($php)) {
            throw new GameException('Configure an absolute executable PHP '.$version.' path.');
        }
        $runtime = new Process([$php, base_path('scripts/runtime-preflight.php')]);
        $runtime->setTimeout(10)->run();
        if (! $runtime->isSuccessful()) {
            throw new GameException('Configure a PHP '.$version.' executable with the required worker extensions and matching Composer pins.');
        }
        $this->lock->run('engine-control', function () use ($php) {
            if ($this->host->registered()) {
                throw new GameException('Owned engine is already installed. End the session and uninstall before reconfiguration.');
            }
            $directory = $this->directory();
            $env = [
                'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
                'APP_CONFIG_CACHE' => $directory.'/no-config.php', 'LARAVEL_STORAGE_PATH' => storage_path(),
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => config('database.connections.sqlite.database'), 'DATABASE_URL' => '',
                'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'file', 'CACHE_DRIVER' => 'file', 'CACHE_PATH' => config('cache.stores.file.path'), 'LOG_CHANNEL' => 'stderr',
                'SPACETRADERS_DEPLOYMENT_PATH' => config('deployment.path'), 'SPACETRADERS_RELEASE' => config('deployment.release'),
                'SPACETRADERS_LOCK_PATH' => config('gameplay.lock_path'), 'SPACETRADERS_FIXTURE_PATH' => config('gameplay.fixture_path') ?: '',
                'SPACETRADERS_REQUEST_BUDGET_PATH' => config('gameplay.request_budget_path'),
                'SPACETRADERS_AUTOMATION_ENABLED' => config('gameplay.enabled') ? 'true' : 'false',
                'SPACETRADERS_LOCAL_ENGINE_ENABLED' => 'true', 'SPACETRADERS_OPERATOR_ID' => (string) config('gameplay.operator_id'),
                'SPACETRADERS_LOCAL_HOST' => config('gameplay.local_host'), 'SPACETRADERS_PHP_BINARY' => $php,
            ];
            $this->host->install($php, $env);
        });
    }

    public function start(): array
    {
        return $this->lock->run('engine-control', function () {
            $status = $this->status();
            if (in_array($status['state'], ['running', 'starting', 'stopping'], true)) {
                return $status;
            }
            if ($this->alive(LocalEngine::find(1), 'supervisor')) {
                throw new GameException('The previous owned supervisor is still exiting. Wait for shutdown or review the force-stop control.');
            }
            if (! $this->host->hasServiceMarker() || ! $this->host->registered()) {
                throw new GameException('Install this checkout’s owned engine service from the CLI first.');
            }
            $this->host->launch();

            return ['state' => 'starting', 'message' => 'Start requested. Waiting for verified child processes.'];
        });
    }

    public function end(): array
    {
        $this->gate->close();

        return $this->status();
    }

    public function forceStop(): array
    {
        $engine = LocalEngine::find(1);
        if (! $engine || $engine->state !== 'faulted' || ! $engine->stop_requested_at || $engine->stop_requested_at->gt(now()->subSeconds(40))) {
            throw new GameException('Force stop is available only after the 40-second graceful shutdown allowance.');
        }
        $this->gate->close('faulted', 'Force stop requested. Interrupted writes require reconciliation.');
        $this->host->forceStop($engine);

        return $this->status();
    }

    public function uninstall(): void
    {
        $status = $this->status();
        if (in_array($status['state'], ['starting', 'running', 'stopping'], true) || $this->alive(LocalEngine::find(1), 'supervisor')) {
            throw new GameException('End the session and wait for all owned processes to exit before uninstalling.');
        }
        $this->host->uninstall();
    }

    public function alive(?LocalEngine $engine, string $role): bool
    {
        return $engine && $this->host->alive($engine, $role);
    }

    public function status(): array
    {
        $engine = LocalEngine::find(1);
        if (! $engine) {
            return ['state' => 'off', 'gate_open' => false, 'message' => 'Local engine is off.'];
        }
        if (in_array($engine->state, ['starting', 'running', 'stopping'], true)
            && ! ($engine->state === 'starting' && $engine->updated_at->gt(now()->subSeconds(5)))
            && (! $this->alive($engine, 'supervisor') || ($engine->state === 'running' && (! $this->alive($engine, 'worker') || ! $this->alive($engine, 'scheduler'))) || $engine->heartbeat_at?->lt(now()->subSeconds(15)))) {
            $this->gate->close('faulted', 'Engine heartbeat or supervisor lost. Old approvals are closed.');
            $engine->refresh();
        }

        return $engine->only(['state', 'gate_open', 'session_id', 'heartbeat_at', 'message', 'stop_requested_at']);
    }
}
