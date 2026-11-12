# Windows local MVP plan

Status: implemented on local branch `feature/windows-local-mvp` (started 2026-11-11 from `main` at `f84bd82`). Not pushed. No hosting, mobile, or adviser work is in scope.

## Completion record (2026-11-11)

| MVP criterion | Result |
|---|---|
| 1. Suite on Windows | 687 PHPUnit tests and 3,644 assertions pass with no skips. Pint is clean, `pnpm build` passes, and `scripts/windows/verify.ps1` reports 39 PASS, 5 SKIP and 0 FAIL. The skips are POSIX-only checks, each named in the output. macOS behavior was checked by pinned-argv tests and line-by-line review, not run on a Mac. |
| 2. Herd site with an engine | `http://spacetraders.test` serves this branch from `C:\Users\taylo\Herd\spacetraders`. The operator is configured, the engine and automation are enabled, and the `SpaceTraders-Engine-fa35…` task is installed. A CLI start reached `running` with the gate open, and End returned it to `off` with all processes exited. |
| 3. Automation through the engine | In offline practice, the browser drove Start local engine, mining Investigate → Stage → Preview → Approve and a run that completed: orbit, mine, dock and two sells, all confirmed. End session then shut the engine down cleanly. `scripts/verify-windows-engine.php` passes all 25 real Task Scheduler lifecycle checks ([evidence](evidence/windows-local-mvp/)). |
| 4. One-command offline practice | `scripts/windows/practice.ps1 Start -Engine` (or plain `Start` for worker mode). |
| 5. Windows verification entry point | `scripts/windows/verify.ps1`. |
| 6. Token-day runbook | Below, plus the README's Windows section. |

Two independent Claude reviews found no critical defects. Every medium and low finding was fixed. One watchdog limitation is documented in the [engine design](windows-engine-design.md): it fails open when a job's start marker cannot be written.

**Still unverified:**
- Real Modern Standby and sleep behavior on this PC (E7).
- Sign-out and screen-lock behavior (E9).
- Herd's job object killing processes that a request spawns (E1). This is the reason the design uses a Scheduled Task.
- All live SpaceTraders behavior. It needs a token.

The full 59-operation live acceptance ledger remains the longer-term gate.

## Goal

Play SpaceTraders and run bounded automation entirely on one Windows 11 PC through Laravel Herd for Windows, with the same safety rules the macOS build has: previews, explicit approvals, reserves, Stop, End session, and uncertainty reconciliation. Everything must be ready before a SpaceTraders token exists, so the first live session only needs the token pasted into the web UI.

## Where the build stands

Assessment of `main` at `f84bd82`, made on 2026-11-11.

**Gameplay code is largely complete.**
- All 59 operations in the pinned SpaceTraders v2.3.0 spec are mapped: 26 reads, 32 manual actions, and registration.
- Mining and Contracts automation provide Investigate → Stage → Preview → Approve with explicit limits.
- Scout and trade strategies also exist.
- Manual mining and contract journeys were verified live on macOS on 2026-11-01.
- The formal per-variant acceptance ledger is 0/59. That means official evidence for every variant is missing, not that operations are missing.

**Unmerged `origin/plan/*` branches (27) are documentation only.** They cover adviser, exploration, live-acceptance, mobile, and ops/Linode work, and none of them blocks local play.

**Windows was the blocker.** The baseline on this PC was:
- PHPUnit: 355 of 512 tests failing.
- Pint: all 127 files flagged, because CRLF line endings come from `core.autocrlf=true` while the repository has no `.gitattributes`.
- Request budget: it rejects drive-letter paths and calls `posix_geteuid()`.
- Local engine supervisor: it depends on launchd, pcntl, and posix, so it cannot run on Windows.
- Runtime preflight and the verification scripts assume POSIX.

**Uncommitted Windows work exists in `C:\Users\taylo\Herd\spacetraders`** (the Herd-served checkout) from November 2–3: Windows request-budget ACL handling, its tests, and a PowerShell worker/scheduler launcher. It is being brought into this branch, with the launcher replaced by the real engine port.

**The Herd checkout:**
- has all 12 migrations applied and one operator user;
- has the engine and automation disabled;
- does not set `SPACETRADERS_OPERATOR_ID`.

**Tokens:** SpaceTraders tokens never go in `.env`.
- To create a new agent, paste an **account token** on the Create agent page. Laravel registers the agent and saves only the encrypted **agent token**.
- To use an existing agent, paste its **agent token** under Import.

## MVP definition (done means all of these)

1. `php vendor/bin/phpunit` passes on Windows with zero failures or warnings. Pint is clean. `pnpm build` passes. macOS and Linux behavior is unchanged.
2. `http://spacetraders.test`, served by Herd from `C:\Users\taylo\Herd\spacetraders`, runs this branch with a configured operator. **Start local engine**, **Stop automation**, and **End session** work from the flight deck on Windows.
3. Approved manual commands and bounded Mining and Contracts automation execute through the Windows engine. They survive closing the browser and stop cleanly on End session.
4. Offline practice, an isolated fixture world with no token, can be launched on Windows with one command. It completes a mining trip and a contract run in a real browser.
5. A single PowerShell verification entry point runs the checks that apply to Windows.
6. A token-day runbook documents what to paste, where, and what to check first.

## Milestones

### M1: Windows foundation and a green test suite

Owner: GPT-6.1 Sol (high).

- Add a `.gitattributes` with LF line endings (CRLF for `.ps1`, `.bat`, and `.cmd`), then renormalize the worktree.
- Port and harden the Windows request-budget ACL code: reparse-point checks and ACL cache identity.
- Default the budget path to `%LOCALAPPDATA%\SpaceTraders\request-budget` on Windows.
- Isolate the budget path in tests.
- Fix the startup guard, its path handling, and Windows test cleanup.
- Gate only the POSIX engine tests until M2 lands.

### M2: Windows engine port

Design: Claude Opus.

**Architecture**
- One lifecycle behind an `EngineHost` seam, with `LaunchdEngineHost` (current code, moved unchanged) and `WindowsEngineHost`.
- Each host spawns an `OwnedChild` through a fakeable `HostCommands` runner.

**Windows behavior**
- **Launch:** a per-user on-demand Scheduled Task, registered by the CLI install command.
  - No triggers, `IgnoreNew`, `ExecutionTimeLimit=PT0S`, battery stops disabled, `InteractiveToken`, `LeastPrivilege`.
  - A `php-win.exe` launcher (`scripts/engine-launch.php`) loads an ACL-protected `service.json` environment.
  - This avoids Herd's php-cgi job object, which would kill children spawned during a request.
- **Liveness:** each process holds an exclusive lock on its own empty lease file. `alive()` uses a non-blocking lock probe and does not depend on PIDs.
- **Stop:** cooperative and driven by the database (`EngineChildWatch`).
  - The worker uses `Looping` and `shouldQuit`.
  - The scheduler polls.
  - Orphaned children exit when the supervisor's lease is free.
- **Job watchdog:** replaces the queue timeout that is missing without pcntl. A worker job running longer than 150 s is killed and the session faults.
- **Force stop:** kills only processes whose CIM creation time and command-line hash match identities recorded at startup, using `taskkill /T /F`. The task is then removed.
- **Preflight:** runtime preflight gets a Windows profile without pcntl or posix.

**Tasks**
- Task 0: interfaces, wiring, and Task A (launchd extraction plus the shared refactor).
- Task B: Windows host, leases, watch, launcher, and unit tests.
- Task C: supervisor, scheduler, preflight, engine test fixture, test migration, and the Windows verification script.

### M3: Windows operations scripts

- PowerShell entry points under `scripts/windows/`:
  - `php.ps1` / `composer.ps1` wrappers pinned to Herd PHP 8.5.
  - `setup.ps1`, an idempotent first-time setup that never overwrites an existing `.env`, key, or database.
  - `verify.ps1`, the Windows equivalent of `verify.sh`.
  - `engine.ps1` for install, uninstall, and status.
  - `backup.ps1` for the SQLite database, `.env`, and app key.
- `verify.sh` and the POSIX scripts stay as they are. Windows skips only the checks that are inherently POSIX, and says which ones.

### M4: Offline practice on Windows

- Verify that `scripts/mvp-fixture.php --isolated` works on Windows.
- Add `scripts/windows/practice.ps1`. It generates or reuses the isolated fixture environment and serves it on `127.0.0.1` with `artisan serve`. It also runs the engine for that environment and prints the generated practice login location.
- The fixture's engine uses its own Scheduled Task name, so it never collides with the live engine.

### M5: Acceptance

Owners: Claude reviews; browser checks use the Playwright MCP.

- Full suite and verification on Windows.
- Windows engine empirical checks E1–E10 from the design, recorded under `docs/evidence/windows-local-mvp/`.
- A browser journey in offline practice, using the Windows engine:
  1. Log in.
  2. Start the engine.
  3. Manual orbit, navigate, dock, sell, and refuel.
  4. Mining automation: investigate, stage, approve, and Stop.
  5. Contract automation run.
  6. Close the browser.
  7. End session.
  8. Restart the engine.
- An independent Claude code review of the whole branch, with findings fixed.

### M6: Cut over the Herd site

1. Back up the Herd checkout's database and `.env`.
2. Discard the now-superseded uncommitted Herd changes; this branch contains them.
3. Fetch the local branch from `C:\Users\taylo\Projects\spacetraders` and check it out.
4. Run Composer and pnpm installs and the build, then migrate (no pending migrations are expected).
5. Set `SPACETRADERS_OPERATOR_ID`, `SPACETRADERS_LOCAL_ENGINE_ENABLED=true`, and `SPACETRADERS_AUTOMATION_ENABLED=true`, then clear the config cache.
6. Install the engine task.
7. Smoke-check `http://spacetraders.test` with the Playwright MCP.

Enabling automation authorizes nothing by itself: every run still needs a staged plan and an explicit approval, and no agent exists yet.

## Out of scope for the local MVP

Linode and hosting, the mobile API and iPhone app, adviser model calls and Discord, the leaderboard, fleet-investment strategies, and full 59-operation live acceptance. The full acceptance remains the documented longer-term gate.

## Token day runbook

1. Open `http://spacetraders.test`, sign in as the operator, and choose **Create agent**.
2. Paste the **account token** from the SpaceTraders dashboard, pick a symbol (3–14 characters) and a faction, and submit. Only the returned agent token is stored, encrypted.
   - If registration is ambiguous, check the official dashboard and use **Import** with the agent token instead of retrying.
3. Choose **Synchronize** and confirm the fleet, credits, and contract.
4. Start the local engine, then do one manual dock or orbit to confirm the worker path is live.
5. Try a guided mining trip before enabling longer automation limits. Keep the default 2,000 CR reserve.
