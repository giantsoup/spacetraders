# SpaceTraders client

A private web client and gameplay engine for [SpaceTraders](https://spacetraders.io/), an online space game controlled through an API. Use the flight deck to inspect your fleet, travel, mine resources, trade cargo, and complete contracts. Every gameplay command has a preview, an approval, and a recorded outcome.

The app currently runs locally through Laravel Herd on macOS or Windows. Laravel 13 owns the gameplay rules, encrypted SpaceTraders credentials, queue jobs, and activity history. Inertia 3, Vue 3, Pinia 4, and Tailwind 4 provide the dark web interface. SpaceTraders remains the authority for the game world, prices, travel, cargo, and credits.

The next product goal is a personal iPhone app connected to an always-available Laravel engine. The mobile roadmap below builds on the existing engine rather than moving gameplay execution onto the phone.

## What works today

- **Fleet and agent management.** Register or import an agent, select an owned ship, inspect fuel, cargo, equipment, travel, cooldowns, and credits, and synchronize observations. Previous game worlds remain available as archived history.
- **Manual gameplay.** The flight deck has shortcuts for common commands, an API library for reads, and a Manual API form for the broader command set. Contract previews show fresh obligations, deadlines, delivery progress, and rewards.
- **Guided mining.** The player guide walks through a mining trip with separate travel, extraction, sale, and refuel approvals. Progress follows confirmed actions and survives a page reload.
- **Bounded automation.** Mining and Contracts can investigate opportunities, stage a plan, preview its exact limits, and run after approval when enabled. Automation is opt-in. Starting the local engine authorizes no gameplay by itself.
- **Activity and recovery.** Durable intents distinguish previews, queued work, requests in flight, confirmed outcomes, and uncertainty. Activity history retains receipts and supports reconciliation.
- **Local engine controls.** A macOS supervisor owns one worker and scheduler. Start, Stop automation, and End session have separate effects. Closing the browser does not stop approved work. Mac sleep or shutdown can delay it.

The adviser currently provides policy configuration and deterministic assessments. Paid model calls and adviser execution remain disabled.

### Verified live behavior

On November 1, 2026, the local app at revision `69d2dff` completed two manual journeys through real Chrome controls and real SpaceTraders responses:

- A mining trip with confirmed extraction, return travel, docking, sale of all six extracted ice water, and a full-tank refill.
- A procurement contract with 52 aluminum ore delivered in two loads, followed by confirmed fulfillment and payment.

Fresh API reads and page reloads confirmed the results and persisted activity. The final code passed 501 backend tests with 2,890 assertions, affected frontend checks, Pint, and an independent review. These are dated results for those journeys, not acceptance of every command, automated strategy, or mobile workflow.

### API coverage and remaining acceptance

All 59 operations in the pinned SpaceTraders v2.3.0 specification have source mappings: 26 dedicated reads, 32 manual actions, and account-token registration. The complete operation and input-variant acceptance gate is still open.

The [base API foundation plan](docs/base-api-foundation-plan.md) remains the delivery priority before new strategy, adviser, or fleet-investment automation. The [operation inventory](docs/api/base-api-inventory.md), [capability map](docs/api/foundation-capability-map.md), and [variant gates](docs/api/foundation-variant-gates.md) separate implemented paths from verified official behavior. Older reports describe their own revisions and evidence boundaries.

## How gameplay executes

The browser submits an instruction to Laravel. Laravel uses the server's saved agent credential to check the current world, ship state, cargo, fuel, and prices. It checks ownership and configured limits before creating a preview. Approval queues one durable intent. The worker checks it again before sending the upstream request and records the response.

The same executor serves manual commands and approved automation. The interfaces do not bypass its locks, credit accounting, or uncertainty handling.

- Manual spending preserves at least 2,000 CR by default. Higher requested or active-run reserves take precedence.
- Quotes and fuel or time thresholds are local checks. They cannot guarantee the server's execution price, consumption, or arrival time.
- Stop cancels work that has not been reserved. A reserved request may still settle. Uncertain writes require reconciliation and are never replayed automatically.
- World resets archive the old agent and its history. Recovery requires current credentials and fresh previews, not automatic registration.
- SpaceTraders agent tokens stay encrypted on the server and are omitted from page data. Account tokens are saved only when you opt in, encrypted with the app key, and never shown again. You can forget a saved account token from Register new agent. The app forgets it automatically if SpaceTraders rejects it.
  The Profile page lets you save, replace, or forget this server's OpenAI API key, which is verified before saving, stored encrypted, and never shown again.

## Roadmap to mobile

The target is a native SwiftUI iPhone app, initially for iOS 17 or later, distributed privately through TestFlight. The existing web interface remains available for setup, broader gameplay, and operator recovery.

The [iPhone plan](docs/ios-app-plan.md) and [first iPhone release decision](docs/adr/0001-first-iphone-release.md) define the selected design. There is no native app project or `/api/mobile/v1` implementation in this checkout yet. Hosted operation and TestFlight installation remain release gates.

### 1. Finish backend readiness

Continue the base API acceptance work and verify the command variants needed by the first mobile workflow. Cover stale observations, expired previews, rate limits, duplicate submissions, lost responses, world resets, and concurrent web controls. Preserve the existing spending, reserve, duration, and Stop rules.

This stage is complete when the required gameplay services and recovery paths have evidence at the release revision. Source mappings and fixture tests alone do not establish live acceptance.

### 2. Run the engine reliably on Linode

Prepare the private HTTPS service described in the [server deployment plan](docs/server-deployment-plan.md). Verify capacity on the existing host, persistent storage, worker and scheduler supervision, health checks, recovery, and deployment drains. Use the plan's fresh production environment rather than copying local test or gameplay data by default.

Adapt the current macOS engine controls for hosted operation while preserving approval invalidation, Stop, and uncertainty handling. Before the same agent moves to the hosted engine, stop and reconcile its local executor. Only one engine may control that agent. Keep application keys and credentials out of Git.

This stage is complete when approved bounded work survives service restarts and continues independently of an open browser or phone. Hosting capacity remains a measurement to verify, not a promise about the existing server.

### 3. Expose a versioned mobile API

Implement `/api/mobile/v1` over the existing owner-scoped gameplay services. Include saved fleet state, explicit synchronization, previews, approvals, action status, paginated history, mining investigation and staging, and Stop.

Use [Sanctum mobile authentication](https://laravel.com/docs/13.x/sanctum#mobile-application-authentication) with per-device application tokens, expiry, and revocation. Store the application token in the iPhone Keychain. SpaceTraders credentials remain in Laravel. Keep the web interface's session and CSRF protections intact.

Persist a request identity before dispatch and provide status lookup after a lost response. A retry must resolve the original submission rather than create another purchase or action. Define compatible JSON responses and distinguish a queued intent from an upstream-confirmed result.

### 4. Build the first iPhone workflow

Create the SwiftUI client in the planned `mobile/ios/` directory. Follow the dark [Horizon design direction](docs/design-brief.md): ship home, a report sheet, and focused Actions, Mining, and Activity screens. Support accessible text sizes, VoiceOver, reduced motion, and usable touch targets.

The first release uses an existing connected agent and capable miner. It supports six manual commands: orbit, dock, same-system CRUISE navigation, refuel, unsurveyed extraction, and cargo sale. It also supports one investigated, staged, explicitly approved mining trip with visible progress and Stop.

Laravel continues approved work while the app is suspended or disconnected. The phone refreshes saved server state and requests upstream synchronization explicitly. Offline views are read-only. Do not queue gameplay writes offline. Keep UTC in storage and API values, and display dates in the phone's detected timezone.

Contracts, agent provisioning, cargo and ship purchases, surveys, transfers, and broader automation stay in the web interface for the first mobile release. iPad-specific layouts, Android, push notifications, and widgets are later scope decisions.

### 5. Verify on a device and distribute privately

Test the complete app-to-engine journey on a physical iPhone. Exercise locked-phone operation, interrupted connectivity, token expiry and revocation, stale previews, uncertain outcomes, concurrent web controls, engine restarts, and Stop during recovery.

Prepare signing, an archive, and a private [TestFlight release](https://developer.apple.com/testflight/). Confirm the current Apple tooling and distribution requirements at release time, and establish replacement builds before beta access expires.

The first mobile release is complete when the installed app can inspect the fleet, approve a manual action, control a bounded mining trip, and recover its state after disconnection. The hosted engine must continue only approved work, and web Stop and recovery must remain reachable.

## Local development

The tested runtime is PHP 8.5.12, Node 26.13.0, and pnpm 12.16.1. Use the committed lockfiles. The [PHP runtime runbook](docs/issue-7/php85-runtime.md) explains the exact patch pin and supported executable wrappers.

On macOS, select this checkout's Herd PHP without changing global settings:

```bash
export PHP_BIN="$PWD/scripts/herd-php"
"$PHP_BIN" -v
./scripts/composer install
pnpm install --frozen-lockfile --strict-peer-dependencies
```

Only for a new environment, create its configuration, application key, and database:

```bash
cp .env.example .env
"$PHP_BIN" artisan key:generate
touch database/database.sqlite
"$PHP_BIN" artisan migrate
pnpm build
```

For an existing environment, retain its application key and database. Verify the effective database and storage paths before migrations. Herd serves `http://spacetraders.test`; do not start another application server for that host. Use `pnpm dev` when frontend hot reload is needed.

The default local configuration uses SQLite, file sessions and cache, database queues, and non-delivering array mail. Public account signup and password-reset delivery are disabled. Provision a private operator account with `"$PHP_BIN" artisan spacetraders:operator create`; use the command's interactive input rather than placing credentials in shell history. SpaceTraders agent registration is a separate action after login.

### Windows

Run `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\secure.ps1` to secure and verify `.env`, `database\` and `storage\` ACLs on an existing checkout (also done by setup; use `-DryRun` to preview).

The app also runs on Windows 11 with Laravel Herd for Windows, using the same PHP, Node and pnpm pins. Herd's PHP has no `pcntl` or `posix` extension. On Windows, the local engine runs as an on-demand, per-user Scheduled Task instead of a launchd agent. Run the PowerShell entry points from the checkout root.

Windows blocks `.ps1` scripts by default. Running `.\scripts\windows\php.ps1` directly fails with "running scripts is disabled on this system". Use one of these:

- Prefix each command with `powershell -NoProfile -ExecutionPolicy Bypass -File`, as the examples below do.
- Allow scripts for the current PowerShell window only: `Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass`.
- Allow local scripts for your user permanently: `Set-ExecutionPolicy -Scope CurrentUser -ExecutionPolicy RemoteSigned`.

After either `Set-ExecutionPolicy` option, `.\scripts\windows\setup.ps1` and the other scripts run without the prefix.

```powershell
# Dependencies, build, and a new .env/database only when none exists. Never overwrites .env, APP_KEY or the database.
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\setup.ps1 -DryRun
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\setup.ps1
# Interactive operator account (prompts appear in this console).
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\php.ps1 artisan spacetraders:operator create
```

`setup.ps1` writes an absolute `DB_DATABASE` path and sets `SPACETRADERS_REQUEST_BUDGET_PATH` to `%LOCALAPPDATA%\SpaceTraders\request-budget`. The request-budget directory is restricted to the current user, SYSTEM and Administrators. To migrate an existing environment, pass `-Migrate`; the script prints the effective database first.

To enable the local engine and automation, set these values in `.env`, then install the engine's Scheduled Task:

```dotenv
SPACETRADERS_OPERATOR_ID=<your operator user id>
SPACETRADERS_LOCAL_ENGINE_ENABLED=true
SPACETRADERS_AUTOMATION_ENABLED=true
SPACETRADERS_PHP_BINARY=C:/Users/<you>/.config/herd/bin/php85/php.exe # already set by setup.ps1
```

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\engine.ps1 Install   # also: Status, Uninstall
```

- **What install does:** it registers a task with no triggers, so the engine never starts by itself. The task runs one supervisor at a time, with no time limit, and it keeps running on battery power.
- **Starting and stopping:** use **Start local engine**, **Stop automation**, and **End session** on the flight deck as on macOS. The supervisor's worker and scheduler run without console windows. Closing the browser does not stop approved work. Signing out of Windows does stop it; the next status check then reports a fault.
- **Logs:** they are written to `storage\framework\game-locks\engine\`.
- **Before changing configuration:** End the session, run `engine.ps1 Uninstall`, change `.env`, and run `engine.ps1 Install` again.
- **Back up before any migration or cutover:** run `scripts\windows\backup.ps1`. It writes a consistent SQLite snapshot and a copy of `.env` to a private folder under `%LOCALAPPDATA%\SpaceTraders\backups`.

**Offline practice** runs a synthetic game world in a private folder under `%LOCALAPPDATA%\SpaceTraders\practice`. Its separate database, locks and engine task never touch your real `.env` or agent, and it needs no SpaceTraders token:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\practice.ps1 Start -Engine   # http://127.0.0.1:8010 with the full automation workflow
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\practice.ps1 Status
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\practice.ps1 Stop            # Ends the practice engine session, then stops the server
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\practice.ps1 Reset -Force    # Removes the practice world and its task
```

The script prints the location of the generated practice login file. Without `-Engine`, practice runs one plain worker and scheduler instead of the engine. In that mode, manual commands work, but the Investigate → Stage automation console is hidden.

In engine practice, `Reset -Force` performs a verified force stop if graceful End times out, before removing the practice world and its task.

**Viewing on an iPad or phone over Tailscale.** `web.ps1` adds a second Herd nginx site for this checkout on `127.0.0.1:8081` only. It runs on Herd's PHP pool, so it handles several requests at once, and it uses the same `.env`, database and sessions as `spacetraders.test`. The site accepts only the names `tailscale serve` publishes for that port, plus `127.0.0.1` and `localhost`; any other name gets HTTP 421. `tailscale serve` publishes it to your tailnet with HTTPS:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\web.ps1 Install   # also: Status, Uninstall; -Port to change 8081; run it again after changing tailscale serve
& "C:\Program Files\Tailscale\tailscale.exe" serve --service=svc:spacetraders --https=443 http://127.0.0.1:8081   # tailnet only; never use `tailscale funnel`
```

- **Access:** open `https://spacetraders.chimp-hamal.ts.net` from any device signed into the tailnet. It is a [Tailscale Service](https://tailscale.com/kb/1552/tailscale-services) (`svc:spacetraders`), so the address doesn't depend on the machine name: the PC is `taylor-pc`, tagged `tag:server`, and the tailnet policy auto-approves it as the host. The server listens only on loopback, so no firewall rule is needed.
- **Engine controls:** install, start and stop answer 403 from this address, because they require the `spacetraders.test` host and a loopback client. Never proxy with a `Host: spacetraders.test` rewrite.
- **Herd must be running:** the site is `spacetraders-web-<checkout hash>.conf` in Herd's `valet\Nginx` folder. Install tests the configuration and reloads nginx without restarting PHP or the engine. It also removes the PHP built-in server task that earlier versions used.
- **Removal:** run `tailscale serve drain svc:spacetraders`, then `tailscale serve clear svc:spacetraders`, then `web.ps1 Uninstall`.

See the [Tailscale iPad plan](docs/tailscale-ipad-plan.md).

**Production and development on one PC.** The Herd checkout `C:\Users\taylo\Herd\spacetraders` is production. It stays on `main` and changes only through `scripts\windows\deploy.ps1`: run it alone for a preview, or with `-Yes` to deploy. Development happens in `C:\Users\taylo\Projects\spacetraders`, linked in Herd as `spacetraders-dev.test`, with its own `.env` and database. [AGENTS.md](AGENTS.md) has the full ship-and-deploy procedure.

The deploy gracefully ends a running local engine and restarts it after the site checks pass; ending cancels unsent previews and stops automation runs, which you re-approve afterwards.

The [Windows local MVP plan](docs/windows-local-mvp-plan.md) and [Windows engine design](docs/windows-engine-design.md) describe the port, its safety protocol, and its verification evidence.

### Local engine and offline practice

With `SPACETRADERS_FIXTURE_PATH` unset or empty, the app uses the real SpaceTraders API and shows **Live SpaceTraders API**. An owned fixture shows **Offline practice**. Never put live credentials into a fixture.

Approved manual commands require the database worker, but do not require autonomous strategies to be enabled. For a configured local engine installation, use **Start local engine** and **End session** in the web interface. **Stop automation** ends a run without ending the engine session. Keep `SPACETRADERS_AUTOMATION_ENABLED=false` when autonomous runs are not intended.

When the native local engine is disabled, the terminal alternative is one worker and one scheduler:

```bash
"$PHP_BIN" artisan queue:work database --queue=spacetraders --sleep=1 --tries=1 --timeout=120
"$PHP_BIN" artisan schedule:work
```

Run those commands in separate terminals. Do not start a second worker or scheduler alongside the native engine. All processes must share the intended database, lock directory, and request budget. Closing a tab does not stop them.

Disable Herd's automatic development processes for this checkout. The database connection defaults to the unrelated `default` queue; gameplay jobs explicitly use `spacetraders`. When the local engine is enabled, gameplay jobs also require the current engine-owned worker session. Other workers release those jobs for five seconds without executing them. Do not set `SPACETRADERS_ENGINE_WORKER_SESSION` yourself; the engine hosts supply it only to their worker child.

See the [local automation plan](docs/local-automation-implementation-plan.md), [gameplay implementation notes](docs/mvp-implementation.md), and [deployment drain runbook](docs/issue-11/deployment-gates.md) for lifecycle, migration, and recovery details. Those documents contain dated plans and evidence; check their status against the current source before a cutover.

## Verification

With locked dependencies and the pinned runtimes installed:

```bash
PHP_BIN="$PWD/scripts/herd-php" ./scripts/verify.sh
python3 scripts/verify-base-api-coverage.py
```

On Windows, `scripts\windows\verify.ps1` runs every portable `verify.sh` check. It reads Pint's exact file list from `verify.sh` and names each POSIX-only step it skips. `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\php.ps1 scripts\verify-windows-engine.php` runs the real Scheduled Task lifecycle against an isolated fixture: start, approved execution, End, restart, crash and orphan recovery, the sleep gap, and verified force stop.

Set `PNPM_BIN` if pnpm is not on your path. The verification entry point checks runtime pins, builds assets, runs backend and frontend checks, and checks PHP style and whitespace. PHPUnit uses guarded in-memory SQLite and isolated test storage. Fake-provider browser and worker journeys verify local behavior without spending live credits.

The API inventory checker verifies source integrity. Add `--refresh` to check official-spec drift or `--require-complete` to enforce complete acceptance. A passing integrity check does not mean all official operations have passed live acceptance.

Live gameplay, a hosted deployment, and an installed iPhone build each require their own verification. Keep those outcomes separate from local tests and source coverage.

## Further reading

- [Player and gameplay implementation](docs/mvp-implementation.md)
- [Current web styling](docs/app-styling.md) and [design brief](docs/design-brief.md)
- [Base API implementation and remaining gates](docs/api/foundation-implementation.md)
- [iPhone architecture and release decision](docs/adr/0001-first-iphone-release.md)
- [iPhone delivery plan](docs/ios-app-plan.md)
- [Linode hosting assessment](docs/linode-hosting-research.md) and [server deployment plan](docs/server-deployment-plan.md)
- [Historical batch-two integration record](docs/integration-batch2-20261101/README.md)
