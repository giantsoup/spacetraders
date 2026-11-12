# Windows local engine port: implementation design

The Windows port keeps the existing lifecycle. Supervisor, admission and End semantics, heartbeat, the 40 s grace, fault rules and session invalidation stay shared. Only the OS-specific parts move behind one `EngineHost` seam. On Windows, a per-user on-demand Scheduled Task replaces launchd, cooperative stop replaces signals, and lease files replace `posix_kill`. macOS behavior must not change.

## Findings that shape the design

1. **Every OS call is POSIX.**
   - `LocalEngineManager.php`: `posix_geteuid` (:34), `/bin/launchctl` (:37-47, :65, :99, :115-119, :141-144, :157-158), `posix_kill` (:166), Darwin-only check (:51).
   - `SuperviseLocalEngine.php`: pcntl (:30-33), `SIGTERM` (:71, :97). Without pcntl the `SIGTERM` constant is undefined, so both lines throw `Error`.
   - `ScheduleLocalEngine.php`: pcntl (:17-24).
   - `EngineGate.php`: `posix_kill` (:51).
2. **Symfony Process on Windows wraps commands in `cmd /V:ON /E:ON /D /C`** (`vendor/symfony/process/Process.php:1618`). `getPid()` therefore returns cmd.exe's PID. Its signal and stop always run `taskkill /F /T` (:1561), which is never graceful.
3. **Without pcntl, the Laravel queue worker has no job timeout and no signal stop** (`Worker.php:242, 279, 1073`), so `--timeout` is ignored. Two cooperative hooks remain: the public `$shouldQuit` flag, checked in `stopIfNecessary` (:440), and a `Looping` listener returning `false`, which stops it popping a job (:405-409).
4. **Herd php-cgi runs as the logged-in user under Herd.exe**, an Electron/libuv app. libuv puts spawned children in a kill-on-close job object, and PHP `proc_open` cannot request job breakaway. Anything spawned from a web request would die when Herd restarts or quits.
5. **Herd PHP 8.5.12:** no pcntl or posix, FFI available in the CLI only, `php-win.exe` next to `php.exe`.
6. **NTFS flock is mandatory locking** (`LockFileEx` over the whole file).
   - HostLock, DeploymentGate's `admission.lock` and leases, and `budget.lock` never read the locked file, so they are safe.
   - Never put readable data inside a lease file.
   - `verify-local-engine.php:73, 80, 93, 120` reads the fixture with `file_get_contents` while a worker holds `LOCK_EX` (`FakeSpaceTraders.php:75-76`). That read fails on Windows, so read under `LOCK_SH` instead.

## Architecture

### New namespace `App\Gameplay\Engine`

```php
interface EngineHost {
    public function supported(): bool;                       // Darwin | Windows
    public function serviceName(): string;                   // launchd label | task name
    public function installed(): bool;                       // marker + launchctl print | schtasks /Query
    public function install(string $php, array $env): void;  // caller holds engine-control lock
    public function launch(): void;                          // kickstart (no -k) | schtasks /Run
    public function forceStop(LocalEngine $engine): void;    // kill owned tree + bootout | verified taskkill + /Delete
    public function uninstall(): void;
    public function alive(LocalEngine $engine, string $role): bool;  // cheap; called inside SQLite txns
    public function claim(string $session, string $role): void;      // self-registration (no-op on Darwin)
    public function spawn(string $role, array $artisanArgs): OwnedChild;
    public function onStopSignal(callable $stop): void;      // pcntl SIGTERM/SIGINT | no-op
    public function identify(array $pids): array;            // [] | CIM identities for force-stop
}
interface OwnedChild { public function pid(): ?int; public function isRunning(): bool;
    public function ready(float $seconds): bool; public function requestStop(): void; public function kill(): void; }
final class HostCommands { public function run(array $argv, int $timeout, array $env = []): HostResult; } // fakeable seam
final class HostResult { public function __construct(public int $exit, public string $out, public string $err) {} }
final class EngineLease { public static function hold(string $path): void; public static function held(string $path): bool;
    public static function path(string $session, string $role): string; } // {lock_path}/engine/leases/{session}-{role}.lease
final class EngineChildWatch { public function shouldStop(): bool; public static function listen(): void; }
```

### Hosts and children

- **`LaunchdEngineHost` and `PosixChild`** hold the current code, moved verbatim:
  - From `LocalEngineManager`: target and launchctl (:32-47), plist (:64-101), kickstart (:119), kill and bootout (:141-146), uninstall (:157-161).
  - `alive()` still calls `posix_kill(pid, 0)`.
  - `PosixChild` wraps Symfony Process. `requestStop` sends SIGTERM; `ready` is the current 300 ms wait plus `isRunning`.
  - argv, plist and behavior are identical to today.
- **`Windows/WindowsEngineHost`, `Windows/WindowsChild`, `Windows/ScheduledTaskXml`, `Windows/PowerShell`** are described below.
- **`EngineServiceProvider`** is new and registered in `bootstrap/providers.php`. It:
  - binds `EngineHost` by `PHP_OS_FAMILY`: `Windows` gets the Windows host; everything else gets launchd, whose `supported()` is false off Darwin;
  - calls `EngineChildWatch::listen()`;
  - calls `EngineLease::hold(...)` when the `SPACETRADERS_ENGINE_LEASE` environment variable is set.

### Changes to existing files

- **`LocalEngineManager`** keeps `authorize`, `label`, `directory`, the preflight, the env map (:73-84) and all state and precondition logic, and delegates OS calls to the host.
  - `install()` at :51 becomes `! $this->host->supported() || …`. On Windows it also checks that the configured operator user exists.
  - `alive(?int)` becomes `alive(LocalEngine $snapshot, string $role)`.
  - `status()` (:175-180) keeps its formula, using the new `alive`.
- **`EngineGate`** :47-57: `processesAlive` loops over roles and calls `$host->alive($engine, $role)`.
- **`SuperviseLocalEngine`**:

  | Lines | Change |
  |---|---|
  | :23 | `$host->installed()` |
  | after `begin()` (:28) | `$host->claim($session, 'supervisor')`, identify that PID, and persist `identity.supervisor` before child startup (Darwin still returns `[]`) |
  | :30-33 | `$host->onStopSignal(fn () => $gate->close())` |
  | :40-41 | `$host->spawn($name, $arguments)` |
  | :44-47 | `ready(10)` for each child |
  | :48-49 | also store `'identity' => $host->identify([...pids])` in `processes`. EngineGate reads only the role keys, and the dashboard already strips `processes`. |
  | :54-58 | fault on `max(wall delta, hrtime delta) > 15` |
  | :71 | `requestStop()` |
  | :97 | `kill()` |
  | new | job watchdog |

- **`ScheduleLocalEngine`** :17-24: `$host->onStopSignal(...)`. The loop exits on `$stop || $watch->shouldStop()`, with the DB read throttled to once per second.
- **`LocalEngineService`**:
  - Adds a read-only `status` operation.
  - Install output shows platform, service name, engine directory and log paths, followed by "Engine remains off. Start it from the flight deck."
- **`scripts/runtime-preflight.php:19`**: on Windows, require the same extensions minus pcntl and posix, and print `PASS … Windows engine profile (cooperative stop; no pcntl/posix)`. Unix is unchanged.
- **`config/logging.php`**: add an `engine` single channel whose path comes from `SPACETRADERS_ENGINE_LOG`.

## Windows launch: on-demand Scheduled Task

### Why not spawn from the web request

Spawning from the request (`start /B`, `cmd /c start`, `Start-Process`, or Symfony's `create_new_console`) has three problems:

- The child inherits the php-cgi/Herd job object and dies when Herd restarts PHP or quits.
- It inherits the FastCGI environment.
- Two fast clicks can start two engines.

A Scheduled Task gives a parent that does not depend on Herd (the Task Scheduler service), a single-instance policy, registration at CLI install time only (mirroring `launchctl bootstrap`), and same-user operation with no elevation or stored password.

### Install (CLI, run from the Herd checkout)

1. **Write `{lock_path}/engine/service.json`.** It contains the existing env map (:73-84), with:
   - `LOG_CHANNEL=engine`;
   - `SPACETRADERS_PHP_BINARY` set to the realpath of `php.exe`;
   - children launched with the sibling `php-win.exe`, which must exist and pass preflight.
2. **Harden the `engine\` directory ACL** to the user, SYSTEM and Administrators, using the same SIDs as `RequestBudget`. `service.json` contains `APP_KEY`.
3. **Generate `task.xml`** in UTF-16LE with a BOM:
   - `URI=\SpaceTraders-Engine-<label hash>`, at the root level, because a non-admin user may be unable to create a task folder.
   - Principal: the current user's SID, `LogonType=InteractiveToken`, `RunLevel=LeastPrivilege`.
   - **No triggers**, the equivalent of `RunAtLoad=false`.
   - `MultipleInstancesPolicy=IgnoreNew`, the equivalent of kickstart without `-k`.
   - `ExecutionTimeLimit=PT0S`. The default 72 h would kill a session.
   - `Priority=5` keeps the task at normal process priority.
   - `DisallowStartIfOnBatteries=false` and `StopIfGoingOnBatteries=false`. The defaults are true and would kill the engine on an unplugged laptop.
   - `StopOnIdleEnd=false` and `AllowHardTerminate=true`.
   - Action: `php-win.exe "<base>\scripts\engine-launch.php" "<engine>\service.json"`, with working directory `<base>`.
4. **Register the task** with `schtasks /Create /TN <name> /XML task.xml`, without `/F`. If `schtasks /Query` already succeeds, refuse, as :65 does today.

All Windows calls use absolute paths: `%SystemRoot%\System32\schtasks.exe`, `taskkill.exe`, and the Windows PowerShell executable. Timeouts are 10 s, or 20 s for PowerShell.

### `scripts/engine-launch.php` (new, standalone)

1. Validate that the JSON path is inside the engine directory.
2. Set every key with `putenv`, `$_ENV` and `$_SERVER`. Laravel's immutable env makes these override `.env`.
3. Set `$_SERVER['argv'] = ['artisan', 'spacetraders:engine-supervise']`, then `require` artisan.

The supervisor is therefore php-win itself: a real PID and no console window.

### Task operations

- **Start (web):** `schtasks /Query /TN` must succeed and the marker must exist; then `schtasks /Run /TN`.
- **Uninstall:** `schtasks /Delete /F`, verify that `/Query` fails, then delete `service.json` and `task.xml`.
- **Force stop:** deletes the task. As on macOS, where bootout follows the kill, the operator must reinstall from the CLI.

### Children (`WindowsChild`)

- Spawned with array-form `proc_open([$phpWin, artisan, ...args], [0 => ['file','NUL','r'], 1 => ['file',$log,'a'], 2 => ['file',$log,'a']], …, ['bypass_shell' => true])`. There is no cmd wrapper, so `proc_get_status()['pid']` is the real php PID.
- The env adds `SPACETRADERS_ENGINE_SESSION`, `SPACETRADERS_ENGINE_ROLE` and `SPACETRADERS_ENGINE_LEASE`.
- The supervisor holds the proc handle, so the PID cannot be reused. `kill()` runs `taskkill /PID <pid> /F`, targeting only that owned PID. It never follows parent-PID links into descendants.
- `ready(10)` waits until `EngineLease::held()` reports the child's lease.
- `requestStop()` is a no-op, because children stop by watching the DB.

### Logs

- Logs go to `{lock_path}\engine\supervisor.log`, `worker.log` and `scheduler.log`.
- Each file rotates to `.1` above 10 MB when the supervisor starts.
- The launcher rotates `supervisor.log` before Laravel boots. Child logs rotate before spawn. Rotation failures warn without blocking startup.
- Pre-Laravel launcher failures append a timestamp, exception class, message, and the rejected `service.json` argument path to `supervisor.log`, without service contents. Before the marker directory is validated, even a practice launch can log in the launching checkout's engine directory; the path identifies the rejected practice configuration. Supervisor exceptions use the engine log channel without dumping the service environment.

## Protocol on Windows

### Gameplay worker ownership

Both engine hosts set `SPACETRADERS_ENGINE_WORKER_SESSION` to the current session only for their worker child. Scheduler children never inherit that marker. The launchd host does not add the Windows watcher variables: macOS does not hold engine leases, so activating that watcher would stop the worker.

Both queued gameplay entry points, `ExecuteShipCommand` and `AdvanceAutomationRun`, run `RequireEngineWorker` middleware before their handlers. With the engine enabled, a missing marker, missing engine, non-running engine, or mismatched session releases the job for five seconds. The middleware logs at most one warning per process. It does not update actions, automation, or the gameplay heartbeat, or call upstream. Engine-disabled terminal and practice workers are unchanged. Investigation is a synchronous read workflow, not a separate queued job; automation advances through the guarded job.

Middleware releases count as Laravel attempts. These jobs therefore allow unlimited releases (`tries=0`) but fail on their first unexpected exception (`maxExceptions=1`), preserving the no-blind-retry policy. The existing engine gate still checks approval sessions, health, and admission at the mutation boundary.

The database connection defaults to `default`, not `spacetraders`. Both gameplay constructors select the database connection and gameplay queue explicitly; engine, terminal, practice, and verification workers select `--queue=spacetraders`.

Deployment participants use process-lifetime file leases. While admission is open, each gate operation removes finished participants and participants with an unheld lease before capturing a deployment's required acknowledgements. Dead worker records therefore cannot block a new drain. A participant lost after closure remains a deliberate fail-closed blocker: losing its lease does not prove an interrupted write settled. The deployment deadline aborts the drain rather than inventing that acknowledgement. Heartbeat age alone never expires a live participant.

Deployment state commits sync and close the temporary file before atomic replacement. While holding the admission lock, Windows retries failed replacements for up to 250 ms with 2 ms sleeps, matching the request-budget policy. Temporary-state and obsolete-lease deletion use the same Windows-only retry policy. A failed commit preserves the old state and removes its temporary file; cleanup failure raises an explicit error. POSIX file operations do not retry.

### Liveness

- The supervisor and each child hold an exclusive lock on `leases/{session}-{role}.lease` for their whole life.
- Windows releases the lock when the process dies, including on TerminateProcess.
- `alive()` opens the lease and tries `LOCK_EX|LOCK_NB`. Failure means alive. Success means dead: release the lock and return false.
- This takes microseconds, is safe inside transactions, and is immune to PID reuse (the same technique as `DeploymentGate.php:215-228`).
- The lease holds no data.
- Acquisition retries for up to 200 ms so a brief liveness probe cannot prevent a child from claiming its lease.
- Supervisor startup removes unheld lease and job-marker files from older sessions, preserving the current session and live owners.

### Heartbeat and sleep

- The heartbeat is unchanged: written every 200 ms and stale after 15 s.
- Startup also refreshes the session-guarded heartbeat between child-readiness checks and before and after process identification.
- The sleep-gap check uses `max(microtime delta, hrtime delta)`.
- The real guard stays the `heartbeat_at >= now-15s` check in the reservation transaction (`EngineGate.php:42`).

### End session

1. Web `EngineGate::close()` (unchanged) closes admission, invalidates unsent approvals and sets `stop_requested_at`.
2. The supervisor loop (unchanged) calls `requestStop()`.
3. Each child's `EngineChildWatch::shouldStop()` is true if any of these holds:
   - the `local_engines` row is missing;
   - `session_id` differs from the child's session;
   - `stop_requested_at` is set;
   - the supervisor lease is free (orphan watchdog).
4. The worker's `Looping` listener sets `app('queue.worker')->shouldQuit = true` and returns `false`. The worker finishes its current job and exits.
5. The scheduler exits on its next 200 ms tick.
6. The supervisor waits, settles runs (:76-83), sets state off and exits. The existing 40 s fault message is unchanged.

Use the DB signal, never `queue:restart`: it is a global cache key and stops unrelated workers.

### Child loss and supervisor loss

- **Child loss:** unchanged. `isRunning` goes false and the engine faults.
- **Supervisor loss:** web `status()` sees the supervisor lease free and faults. Orphaned children exit within about 1 s.

### Job watchdog (replaces the missing `--timeout=120`)

- `JobProcessing` publishes `engine/{session}-worker.job` containing `{attempt, started_at, finished_at:null}`. Replacement retries for up to 250 ms.
- Each attempt holds a separate empty lease at `{marker}.{attempt}.lease`. The marker itself stays readable and unlocked.
- Processed, failed, and exception events publish `{attempt, started_at:null, finished_at}` and release the attempt lease, even if publication fails.
- The watchdog ignores idle content and attempts whose lease is free. A stale start marker therefore cannot time out a completed job.
- Marker failures warn on the engine channel and never fail the job. **The job watchdog fails open for an attempt whose start marker cannot be published:** it has no start time, so the 150 s kill cannot protect that attempt. Stop, orphan, admission, and heartbeat checks still apply.
- Repeated marker failures do not automatically fault the session. A safe supervisor-side threshold would need a separate, session-guarded failure signal; reading log warnings or relying on the same failing marker path is not a reliable cross-process protocol. Inspect warnings and End the session before repairing marker storage.
- Job timestamps use `microtime(true)`, a wall clock shared between processes, not a monotonic elapsed timer. A backward clock adjustment can delay the 150 s timeout; a forward adjustment can trigger it early. The heartbeat-gap check also compares `hrtime`, but does not make job-duration measurements monotonic.
- The supervisor kills the worker if a job has run for more than 150 s. The engine then faults on "owned child exited".
- Upstream HTTP is already bounded (30 s, 10 s connect; `SpaceTradersClient.php:58-65`), and SQLite `busy_timeout` is 5000.
- `retry_after=180` is longer than the 150 s watchdog.

### Force stop (web, after the 40 s gate at :137)

1. `close('faulted', …)`.
2. Run one PowerShell CIM query for the stored PIDs: `ProcessId`, `CreationDate` as UTC ISO, and `CommandLine`.
3. A process is killed only if its creation time matches exactly **and** its command-line SHA-256 matches. Kill children first, then the supervisor, with `taskkill /PID n /F`. Do not use `/T`: descendant discovery follows parent PIDs, which can be stale and point at unrelated processes. Each recorded role is verified and killed explicitly.
4. `schtasks /End`, then `/Delete /F`.
5. Re-query. If a match remains, throw "Could not confirm…".
6. A different creation time means the recorded process exited and its PID was reused. Skip it, even if its command line is unreadable.
7. When creation time matches, a missing or different command hash refuses force stop before any kills. Exit confirmation also refuses a surviving matching creation time.
8. If a role has no stored identity, skip its live PID with an explicit warning only when the recorded session's role lease is free: the original lease-owning process is gone. Never kill that PID. A held lease, unknown session, or unreadable lease still refuses force stop.

### Windows wrappers and offline practice

- `php.ps1` and `composer.ps1` use `System.Diagnostics.Process` with native argument quoting, the repository working directory, and inherited console streams (no redirection). Embedded quotes and trailing backslashes survive PowerShell 5.1; prompts appear before input and the native exit code is forwarded. Inspection scripts retain buffered native-command capture.
- Practice `Stop -Force` and `Reset -Force` re-read the stop timestamp on every poll and wait for the current `stop_requested_at + 40 s` gate and a faulted session before verified force stop. A mid-wait fault can restart that allowance. The total wait is bounded at 120 s; if eligibility never arrives, the server stays running for inspection.
- Windows fixtures set explicit deployment, cache, lock, request-budget, storage, and application-cache paths inside their owned directory.
- The practice runner checks effective configuration after boot, including path boundaries and resolved ancestors. It refuses commands when mutable paths escape the practice directory.

### Engine-backed offline practice

Run these commands from the project checkout, not the Herd checkout:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/windows/practice.ps1 Start -Engine
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/windows/practice.ps1 Status
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/windows/practice.ps1 Stop
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/windows/practice.ps1 Reset -Force
~~~

`Init -Engine` creates and installs without serving. The persisted
`environment/.practice-mode` selects engine or worker mode for later operations;
old environments without that marker remain worker environments. Stop and reset
before switching modes. `Start` without a flag preserves an existing engine mode.

Engine practice serves only at `http://127.0.0.1:8010`. Use that host in the
browser: the seeded operator, `APP_URL`, and local engine host must agree.
The generated login stays in `environment/browser.json`; commands never print
its password. Press **Start local engine** in the flight deck to start the
separate practice Scheduled Task. Starting practice does not start the engine
or approve gameplay. No separate practice worker or scheduler runs in this mode.
`Worker` and direct plain-worker runner commands refuse engine mode; engine
controls refuse worker mode.

The database, fixture, locks, engine service configuration, lifetime leases,
request budget, deployment state, storage, caches, and logs stay inside the
practice directory. Installation preserves those paths in the service's
environment. Before any engine control, the runner compares the practice task
identity against the main checkout's effective database identity, loading only
main environment/configuration (no providers or database connection). A matching
identity is refused. The real `.env` is only read, never rewritten.

`Stop` requests the same End session operation as the UI and waits up to 45
seconds for a terminal engine state, closed gate, and all three released leases,
then stops only the recorded HTTP server tree. If graceful shutdown times out,
the server and environment are preserved. `Stop -Force` may invoke the existing
identity-verified force-stop path only after the manager's 40-second allowance.
`Reset -Force` first stops, uninstalls with the isolated environment, verifies
`schtasks /Query` fails for the practice task, then deletes the owned directory.

For a repeatable lifecycle check, run `practice.ps1 Verify` in engine mode. This
explicitly starts the engine, waits for running state, checks the open gate's
session and all three lifetime leases, and prints state/task/PIDs (no secrets).
Run `Stop`, then `Start -Engine` to return to a served practice with the engine
off and ready for a browser test. Worker-mode `Verify` keeps its existing queued
command check.

## Operator configuration (Herd checkout)

1. Check out the branch.
2. Set `SPACETRADERS_OPERATOR_ID` to the id of the single user (`User::sole()->id`).
3. Set `SPACETRADERS_LOCAL_ENGINE_ENABLED=true` and `SPACETRADERS_AUTOMATION_ENABLED=true`.
4. Run `config:clear`.
5. Run `php artisan spacetraders:engine-service install` with Herd `php.exe`.

Install refuses when the operator is empty or missing, and when `app.url`'s host differs from `local_host`. It prints `base_path()`.

## Tests

### Unit tests

- **`WindowsEngineHostTest`** uses a fake `HostCommands` and asserts:
  - the task XML contains `PT0S`, `IgnoreNew`, no `<Triggers>`, battery settings false, `InteractiveToken`, the exact php-win path and the launcher;
  - install refuses an existing task, passes no `/F`, and refuses an empty operator;
  - Start issues only `/Run`;
  - force stop with a mismatched creation time or command line issues no taskkill;
  - force stop kills only matches, then runs `/End` and `/Delete`;
  - uninstall verifies removal.
- **`LaunchdEngineHostTest`** pins the exact launchctl argv and plist, with the uid injected.
- **`EngineLeaseTest`** has a real child `php -r` hold a lease, then asserts held → kill → free.
- **`EngineChildWatchTest`** covers the four stop conditions and the throttling.

### Feature tests and verification scripts

- **`tests/Support/EngineFixture.php`**:
  - `healthy()` writes the running row and calls `claim()` for all three roles in-process.
  - `kill('supervisor')` sets the PID to 2147483647 and releases the lease.
- Use the fixture for the inline engine setups in:
  - `LocalAutomationTest.php:67, 612`;
  - `LocalAutomationTest.php:508`, replacing the `posix_kill` assertion with "no host command was issued";
  - `OpportunityTest.php:663, 680`;
  - `SQLiteConcurrencyTest.php:30`;
  - `scripts/verify-automation-worker.php:61`.
- **`scripts/verify-windows-engine.php`** is new, Windows-only, uses an owned fixture, and mirrors `verify-local-engine.php`:
  - install a temporary task and confirm `/Query /XML` shows no triggers;
  - `taskkill /F` the scheduler and expect a fault;
  - kill the supervisor and expect orphans to exit within 5 s;
  - simulate a heartbeat gap by suspending and resuming the supervisor through FFI `ntdll` `NtSuspendProcess`/`NtResumeProcess`;
  - force stop, and confirm a sentinel php process survives;
  - always uninstall at the end.

## Empirical checks

- **E1.** php-cgi runs inside a Herd job object, and a `proc_open` child spawned from a request dies when Herd quits.
- **E2.** A non-elevated `schtasks /Create /XML` with no triggers at the root level works, and `/Run` works when called from php-cgi.
- **E3.** With `IgnoreNew`, a second `/Run` starts no second process. Record the exit code.
- **E4.** php-win shows no console window, logs fill, and the engine log channel works.
- **E5.** Whether `/End` kills descendants. Record the result only.
- **E6.** A lease is released within 1 s of `taskkill /F`.
- **E7.** On Modern Standby, the engine faults on wake. Record whether hrtime advances during sleep.
- **E8.** CIM `CreationDate` and `CommandLine` are readable without elevation and stable.
- **E9.** Signing out kills the engine and the next status shows faulted. Locking the screen and unplugging power do not stop it.
- **E10.** Array-form `proc_open` returns the real php PID.

## Risks

- There is no in-process job timeout. The 150 s watchdog is coarse.
- An orphan could run if both the supervisor and the watch fail. The gate's lease and heartbeat checks still block writes.
- Install could run from the wrong checkout. Install guards against this.
- `APP_KEY` is stored in `service.json`. Mitigated by the ACL hardening.
- Antivirus can slow php-win startup. `ready(10)` absorbs it.
