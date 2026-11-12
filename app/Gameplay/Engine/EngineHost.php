<?php

namespace App\Gameplay\Engine;

use App\Models\LocalEngine;

/**
 * Operating-system seam for the owned local engine.
 *
 * LocalEngineManager, EngineGate and the supervisor keep the shared lifecycle
 * (sessions, admission, heartbeat, End, approval invalidation). A host only
 * registers, launches, observes and terminates the owned processes.
 */
interface EngineHost
{
    /** Whether this host can run the owned engine on the current platform. */
    public function supported(): bool;

    /** Human-readable platform name for CLI and error messages. */
    public function platform(): string;

    /** Stable per-checkout/database service identifier (launchd label or task name). */
    public function serviceName(): string;

    /** The private service marker written at install exists (plist or service.json). */
    public function hasServiceMarker(): bool;

    /** The service is registered with the operating system. */
    public function registered(): bool;

    /**
     * Register the on-demand service. The caller holds the engine-control lock
     * and has already validated local mode, operator, SQLite and preflight.
     *
     * @param  string  $php  Absolute, verified PHP CLI executable.
     * @param  array<string, string>  $env  Complete process environment for the supervisor.
     */
    public function install(string $php, array $env): void;

    /** Ask the operating system to start the supervisor. Must never restart a running one. */
    public function launch(): void;

    /** Terminate only the verified owned process tree and remove the service registration. */
    public function forceStop(LocalEngine $engine): void;

    /** Remove the service registration and its marker after the caller verified no owned process remains. */
    public function uninstall(): void;

    /**
     * Cheap liveness probe for one recorded role (supervisor, worker or scheduler).
     * Called inside SQLite transactions, so it must not block or spawn processes.
     */
    public function alive(LocalEngine $engine, string $role): bool;

    /** Called by the current process to register itself for a session role. */
    public function claim(string $session, string $role): void;

    /**
     * Start an owned child artisan process for a session role.
     *
     * @param  list<string>  $artisanArguments
     */
    public function spawn(string $session, string $role, array $artisanArguments): OwnedChild;

    /** Install an OS stop-signal handler where the platform supports it. */
    public function onStopSignal(callable $stop): void;

    /**
     * Capture identities used to verify processes before a force stop.
     *
     * @param  array<string, int>  $pids  role => pid
     * @return array<string, mixed>
     */
    public function identify(array $pids): array;

    /** @return array<string, string> Log file paths by role, for operator output. */
    public function logPaths(): array;
}
