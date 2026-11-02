# SpaceTraders client

A private web client and gameplay engine for [SpaceTraders](https://spacetraders.io/), an online space game controlled through an API. Use the flight deck to inspect your fleet, travel, mine resources, trade cargo, and complete contracts. Every gameplay command has a preview, an approval, and a recorded outcome.

The app currently runs locally on macOS through Laravel Herd. Laravel 13 owns the gameplay rules, encrypted SpaceTraders credentials, queue jobs, and activity history. Inertia 3, Vue 3, Pinia 4, and Tailwind 4 provide the dark web interface. SpaceTraders remains the authority for the game world, prices, travel, cargo, and credits.

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
- SpaceTraders agent tokens stay encrypted on the server and are omitted from page data. Account tokens are used for registration and are not saved.

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

### Local engine and offline practice

With `SPACETRADERS_FIXTURE_PATH` unset or empty, the app uses the real SpaceTraders API and shows **Live SpaceTraders API**. An owned fixture shows **Offline practice**. Never put live credentials into a fixture.

Approved manual commands require the database worker, but do not require autonomous strategies to be enabled. For a configured local engine installation, use **Start local engine** and **End session** in the web interface. **Stop automation** ends a run without ending the engine session. Keep `SPACETRADERS_AUTOMATION_ENABLED=false` when autonomous runs are not intended.

When the native local engine is disabled, the terminal alternative is one worker and one scheduler:

```bash
"$PHP_BIN" artisan queue:work database --queue=spacetraders --sleep=1 --tries=1 --timeout=120
"$PHP_BIN" artisan schedule:work
```

Run those commands in separate terminals. Do not start a second worker or scheduler alongside the native engine. All processes must share the intended database, lock directory, and request budget. Closing a tab does not stop them.

See the [local automation plan](docs/local-automation-implementation-plan.md), [gameplay implementation notes](docs/mvp-implementation.md), and [deployment drain runbook](docs/issue-11/deployment-gates.md) for lifecycle, migration, and recovery details. Those documents contain dated plans and evidence; check their status against the current source before a cutover.

## Verification

With locked dependencies and the pinned runtimes installed:

```bash
PHP_BIN="$PWD/scripts/herd-php" ./scripts/verify.sh
python3 scripts/verify-base-api-coverage.py
```

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
