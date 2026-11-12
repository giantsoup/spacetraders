<?php

namespace Tests\Support;

use App\Gameplay\Engine\EngineHost;
use App\Gameplay\Engine\OwnedChild;
use App\Models\LocalEngine;

class FakeEngineHost implements EngineHost
{
    public array $calls = [];

    public array $alive = [];

    public array $children = [];

    public array $identities = [];

    public bool $marker = true;

    public bool $isRegistered = true;

    public bool $isSupported = true;

    public ?\Closure $stop = null;

    public ?\Closure $onIdentify = null;

    public function supported(): bool
    {
        return $this->isSupported;
    }

    public function platform(): string
    {
        return 'Fake';
    }

    public function serviceName(): string
    {
        return 'test-owned-engine';
    }

    public function hasServiceMarker(): bool
    {
        return $this->marker;
    }

    public function registered(): bool
    {
        return $this->isRegistered;
    }

    public function install(string $php, array $env): void
    {
        $this->calls[] = ['install', $php, $env];
        $this->marker = $this->isRegistered = true;
    }

    public function launch(): void
    {
        $this->calls[] = ['launch'];
    }

    public function forceStop(LocalEngine $engine): void
    {
        $this->calls[] = ['forceStop', $engine->session_id];
    }

    public function uninstall(): void
    {
        $this->calls[] = ['uninstall'];
        $this->marker = $this->isRegistered = false;
    }

    public function alive(LocalEngine $engine, string $role): bool
    {
        $this->calls[] = ['alive', $engine->session_id, $role];

        return $this->alive[$role] ?? false;
    }

    public function claim(string $session, string $role): void
    {
        $this->calls[] = ['claim', $session, $role];
        $this->alive[$role] = true;
    }

    public function spawn(string $session, string $role, array $artisanArguments): OwnedChild
    {
        $this->calls[] = ['spawn', $session, $role, $artisanArguments];

        return $this->children[$role] ??= new FakeOwnedChild(count($this->children) + 10001);
    }

    public function onStopSignal(callable $stop): void
    {
        $this->calls[] = ['onStopSignal'];
        $this->stop = \Closure::fromCallable($stop);
    }

    public function identify(array $pids): array
    {
        $this->calls[] = ['identify', $pids];
        if ($this->onIdentify) {
            ($this->onIdentify)();
        }

        return $this->identities;
    }

    public function logPaths(): array
    {
        return [];
    }
}

class FakeOwnedChild implements OwnedChild
{
    public array $calls = [];

    public bool $running = true;

    public bool $isReady = true;

    public bool $stopOnRequest = false;

    public ?\Closure $onReady = null;

    public function __construct(private ?int $processId = 10001) {}

    public function pid(): ?int
    {
        return $this->processId;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function ready(float $seconds): bool
    {
        $this->calls[] = ['ready', $seconds];
        if ($this->onReady) {
            ($this->onReady)();
        }

        return $this->isReady && $this->running;
    }

    public function requestStop(): void
    {
        $this->calls[] = ['requestStop'];
        if ($this->stopOnRequest) {
            $this->running = false;
        }
    }

    public function kill(): void
    {
        $this->calls[] = ['kill'];
        $this->running = false;
    }
}
