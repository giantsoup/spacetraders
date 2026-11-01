# SpaceTraders client

A Laravel 13, Inertia 3, Vue 3, and Pinia 4 client for SpaceTraders. Register or import an agent, then open its flight deck. The flight deck provides previewed manual commands and a bounded local mining runner.

## API compatibility

The integration targets [SpaceTraders API v2.3.0](https://docs.spacetraders.io/openapi), verified against the [official bundled OpenAPI specification](https://spacetraders.io/SpaceTraders.json).

- New-agent registration sends `POST /v2/register` with an **account token** as the bearer credential. The returned **agent token** is saved locally. The account token is not saved.
- `symbol` must contain 3 to 14 characters. Symbol and faction inputs are uppercased before registration. Email remains optional and is omitted from the request when empty.
- Existing-agent import sends `GET /v2/my/agent` with the agent token and saves the response's `startingFaction`. It does not fetch or infer the faction from headquarters.
- Saved agents are scoped to the signed-in user. Tokens are encrypted at rest and absent from page data, including partial previews. Existing plaintext credentials require the explicit maintenance conversion below.
- Upstream errors appear on the active form. Failed or incomplete responses do not create local agents. Registration requests are not automatically retried because an ambiguous failure may already have created an upstream agent.

SpaceTraders resets invalidate agent tokens. An account token and an agent token have different purposes and are not interchangeable. See the [official authentication definitions](https://docs.spacetraders.io/openapi).

## Current and archived agents

The Agents page separates **Current agents** from **Archived agents**. It checks the current world when the page opens and archives owned records with a different known world ID. Registration and import perform the same check. Gameplay reset detection also archives the affected agent. An unavailable status check leaves saved records unchanged and displays a retry message. Agents with an unknown world ID remain unarchived until synchronization establishes their identity.

Archived agents retain their tokens, saved fleet, balance, and activity history. Active automation receives a stop request. Their flight decks show an archive notice and disable gameplay controls, and archived records are excluded from tutorial choices. Register or import an agent for the current world to play again; a reused name creates a separate record for that world.

Run `node scripts/verify-agents-browser.mjs` with the existing Playwright setup to check same-name current and archived agents, automatic world-change detection, archived flight decks, empty states, and mobile layouts. The check creates an isolated offline fixture and does not use live game credentials.

## Gameplay command boundary

The authenticated legacy forwarder now accepts only public `GET /` and `GET /factions` requests, with bounded pagination. Caller credentials and every upstream mutation are rejected. This deliberate compatibility change closes the command-ownership bypass. Gameplay uses owner-scoped saved-agent routes, server credentials, previews and the shared durable executor.

## Local setup

Use Herd's existing PHP 8.4 runtime, Node 26.13.0, and pnpm 12.16.1. The application requires PHP 8.4.1 or later within the 8.4 series. Composer resolves against PHP 8.4.1; verify the actual runtime with `composer check-platform-reqs`.

Install pnpm 12.16.1 through your existing package-manager tooling. For a temporary invocation without changing global Node or pnpm, use `npm exec --yes --package=pnpm@12.16.1 -- pnpm`.

For a new local environment:

```bash
composer install
pnpm install --frozen-lockfile --strict-peer-dependencies
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
pnpm build
```

Copy the environment template and generate a key only for a new environment. For an existing environment, retain its key, database, session cookie, and cache prefixes. The template uses an owned local SQLite database, file sessions/cache, database queues, and log mail. Select and verify your database before migrations. Serve the application through Herd at `http://spacetraders.test`; use `pnpm dev` for Vite HMR. Herd can manage that Vite process.

The MVP retains the existing kernels, providers and authentication screens, with additive gameplay and queue migrations. Session serialization stays `php` to preserve existing sessions. Cache object unserialization is disabled. Inertia SSR and request-recording devtools are disabled because this application does not use them. Ziggy loads from Composer's package through Vite, with `@routes` providing route metadata.

The frontend uses Tailwind 4 and requires Safari 16.4+, Chrome 111+, or Firefox 128+. Older browsers are outside this build's support. Inertia uses its own HTTP client; `window.axios` remains available separately.

## Verify changes

With locked dependencies installed:

```bash
./scripts/verify.sh
composer validate --strict
composer check-platform-reqs
composer audit
pnpm audit
```

Set `PHP_BIN` or `PNPM_BIN` to the matching executable when it is not on your path. The verification script checks PHP 8.4 and pnpm 12.16.1, builds production assets twice to verify that unrelated Markdown and generated views cannot change them, checks Pinia's saved-state compatibility, runs the complete backend suite, checks affected PHP files with Pint, and checks whitespace. Source and tracked `public/build` files must stay synchronized.

A focused browser regression is available with `node scripts/verify-modal.mjs`. Use an existing Playwright installation, or set `PLAYWRIGHT_MODULE` to its module path. Set `QA_URL`, `QA_EMAIL`, and `QA_PASSWORD` for an owned local `.test` account. The check waits for modal transitions to finish, verifies pointer access, submits an intentionally incorrect password, and checks Cancel and Escape. Set `QA_ARTIFACT_DIR` to retain screenshots. It does not delete the account.

PHPUnit uses disposable in-memory SQLite and an isolated test key. External API regression tests fake SpaceTraders responses and prevent stray requests. Upgrade regressions exercise normal request-forgery middleware and Sanctum bearer authentication. The suite covers authentication, profiles, registration, import, validation, token handling, owner-only listings, connection failures, malformed responses, forwarding, pagination, and rate limits. It does not prove live authenticated SpaceTraders acceptance.

The approved scope and acceptance requirements are in [the migration plan](docs/laravel13-upgrade-plan.md). The baseline commit `a3ea1d4` remains the rollback point for code and locks. Its old dependencies require PHP 8.2, so it is not a PHP 8.4 fallback. This upgrade requires no schema migration beyond the existing migrations.

## Early-game MVP

### Play against the live API

The tutorial uses the same server-side SpaceTraders client and saved encrypted agent credential as manual gameplay. With `SPACETRADERS_FIXTURE_PATH` empty or unset, requests go to `https://api.spacetraders.io/v2`. The guide, agent setup, and flight deck show **Live SpaceTraders API**. A configured offline fixture shows **Offline practice** instead. Offline fixtures are for verification; there is no separate simulated tutorial in the normal live environment.

Open your saved agent and choose **Start guided flight**, then **Synchronize**. Approve each highlighted command to queue a real game action. The tutorial advances when Activity history confirms its result. Unknown or expired outcomes remain visible and are never replayed automatically. If an old approval expired while the worker was offline, review the rejected action and restart guidance to create fresh previews.

Approved commands need a running database queue worker. The database connection defaults to the `spacetraders` queue, matching both gameplay jobs, so Herd’s queue process consumes them without an extra queue flag. Start **Queue** for this site in Herd, or use the explicit `queue:work` command in Local operation below. Run `php artisan schedule:work` alongside it for pending-intent recovery. Manual tutorial commands work with `SPACETRADERS_AUTOMATION_ENABLED=false`; enabling autonomous mining is a separate choice.

### New player guide

Choose **Start guided flight** in the player guide or on the flight deck for the interactive tutorial. Before connecting an agent, **Begin guided agent setup** highlights the required token, name, faction, and submit controls. Successful guided registration or import opens that exact agent’s guided flight.

The guided flight chooses a specific empty CRUISE miner, same-system mining site, and buyer from synchronized observations, with stable sorting for tied routes. It requires observed prices for every possible laser yield, fuel for the route, and a funded reserve. When a live laser omits its optional deposits, `resources/data/mining-yields.json` supplies the complete ore set from the official ShipMount schema. Scouting prioritizes markets whose public listings cover that set. A scout requires enough fuel for its destination and a continuation to a recently observed, affordable fuel seller, plus two tank units of uncertainty reserve. An advertised destination fuel listing alone cannot justify a one-way trip. Refueling still needs a fresh priced preview and separate approval. CRUISE estimates round distance upward and add two tank units per movement. If the trip needs preparation fuel, the guide docks and refuels at the observed starting market before departure. Preparation and return fuel must both fit above the reserve. If no operational miner exists, an affordable verified complete offer can produce a separate one-time setup path. An occupied or unusable existing miner produces a prerequisite message instead of another purchase recommendation.

Each step highlights one button, field, or area and names the expected value. Unrelated command controls are disabled while guidance runs. The sticky instructions remain visible as you follow the highlighted target. Preview and approval are separate steps; exact command IDs are retained before approval dispatch, and progress advances from confirmed results. Lost responses, paused guidance, reloads, saved previews, expired previews, rejected commands, uncertain writes, and zero-yield extraction have explicit recovery or stopping behavior. A completed trip requires confirmed extraction and sales, empty cargo, a full tank, and docking at the planned buyer. Progress is stored per user, agent, and observed reset in this browser.

Run `node scripts/verify-guided-flight.mjs` for deterministic planning and transition checks. `node scripts/verify-guided-browser.mjs` creates its own private offline fixture and loopback server, like the handbook browser check below. Set `QA_GUIDED_SCENARIO` to `mining`, `setup`, `uncertain`, `rejected`, `recovery`, or `preparation` to exercise a normal trip, setup purchase, stopped writes, lost approval responses, and refueling with optional laser deposits absent. Screenshots and results are saved under `storage/app/mvp-evidence/guided/<scenario>`. These checks use synthetic game responses, real local handlers, and finite database workers.

After signing in, open **Player guide** in navigation or **Start the new player guide** on Agents. The ten lessons cover connecting an agent, observing the fleet, choosing a miner, planning fuel and a buyer, travel, extraction, sales, refueling, and reviewing outcomes. The last lesson introduces optional bounded automation and recovery. Mid-game and late-game courses remain future work.

Select an agent in the guide to use its checklist. The flight deck has a collapsible lesson card with the same progress and links to the relevant controls. Checkpoints are player-confirmed learning notes, saved in browser storage per signed-in user, agent, and observed world reset. Preparation has its own checklist. These notes do not run commands or certify gameplay completion. Ship selection stays with the agent in the current browser tab when moving between the guide and flight deck.

Run `node scripts/verify-tutorial.mjs` for progress and course checks. Run `node scripts/verify-tutorial-browser.mjs` with an existing Playwright module via `PLAYWRIGHT_MODULE` and, if needed, `PLAYWRIGHT_EXECUTABLE` or `PLAYWRIGHT_BROWSERS_PATH`. The browser check creates a fresh private offline fixture and a temporary loopback PHP server, uses finite queue-worker sessions, and closes its server and browser afterward. It does not change the checkout’s `.env`, connect to the live API, or spend real credits. It retains its synthetic fixture and writes results and screenshots under `storage/app/mvp-evidence/tutorial` or `QA_ARTIFACT_DIR`.

Open a saved agent to use the responsive dark flight deck. Synchronize explicitly to observe the reset, credits, fleet, waypoints, markets and shipyards. Polling refreshes saved local state only. Discovery is limited to 10 pages per collection, three fleet systems and 24 market/yard reads per synchronization. Unknown prices stay unknown. If a market or yard needs an observer, approve manual orbit and navigation commands to scout it, then synchronize there.

Arrival, cooldown, approval expiry, quote, activity, and reconciliation timestamps display in your browser's local timezone, with a timezone label that follows daylight saving time. The flight deck names the detected timezone. Stored timestamps, API values, and scheduling remain in UTC; date-only reset identifiers keep their calendar date. Run `node scripts/verify-local-time.mjs` for timezone, date rollover, daylight-saving, and unchanged-source checks.

Manual orbit, dock, navigate, refuel, mine, cargo purchase and cargo sale all use expiring previews. Enter a total maximum cost for purchases/refueling, a minimum sale total for sales, and a protected-credit floor. Limits are checked against fresh quotes before execution. SpaceTraders provides no upstream limit-price parameter, so these are estimates rather than guaranteed execution ceilings. Unsupported hard guarantees block spending. Trade-volume, cargo, credits, transit, cooldown and equipment checks run on the server.

A missing miner opens a separate one-time setup preview. It requires a complete offered configuration, an owned observer at that yard, a verified same-system route and enough post-purchase reserve. It never installs equipment or automatically buys another ship after a lost response. Review delivered configuration before generating a new mining preview.

Mining starts with empty cargo, CRUISE mode and enough observed fuel. Each trip defaults to one extraction, waits for server timestamps, sells attributable cargo in fresh-quote batches, refuels within the approved budget and records the outcome. Opt-in longer loads require an extraction count, headroom and site-time limit. A selected signed survey is used unchanged; expiry or exhaustion pauses the run without an unsurveyed fallback. One trip is the default. Explicit previews can approve up to ten trips within 60 minutes. Zero-yield extraction blocks further attempts. Nonpositive observed cash contribution stops repeats. Unknown buyers retain cargo and block the run. Credits, fuel, yields, sold/retained units and cash contribution are recorded separately. Cash contribution is not an economic-profit forecast.

To navigate, choose a destination other than the current location. If docked, preview orbit, approve it and let the queue worker settle that command first. Then preview navigation and approve the route. The controls explain missing prerequisites; the server still checks fresh state before sending.

Only one run owns an agent. The selected ship requires Stop before manual takeover. Other ships share the agent lock and the run's reserved-credit floor. Confirmed manual changes on other ships are accounted separately and preserve the run. Unexplained external credit or cargo changes pause it. Stop persists even while workers are offline. A reserved/in-flight upstream operation cannot be cancelled. Uncertain actions block all writes until reconciliation; idempotent dock/orbit can settle from observed nav state. Other writes retain fresh fleet/cargo/credit/transaction evidence for operator inspection. A confirmed transaction outside its price bound stays confirmed with a review flag. Explicit review clears that flag and ends the run. Acknowledging an unprovable write records an unresolved adjustment with an acknowledged state; neither operation replays the intent.

Account deletion requires stopped, settled gameplay. Credential replacement shares the agent lock and requires Stop with no pending or sending work. It can repair credentials for reconciliation while preserving every uncertain action and review flag. Legacy duplicates remain inert until one record claims the canonical environment/reset/symbol identity. Re-import for the same owner updates that authority; another owner cannot acquire it. A world reset archives the old authority and requires import of current credentials and a new preview. Never auto-register to recover.

### Owned offline fixture

`php scripts/mvp-fixture.php` creates a private disposable SQLite database, fake upstream world and synthetic local login, and verifies a database/key backup restore. It refuses to overwrite an existing `.env` or fixture directory. Use `php scripts/mvp-fixture.php --isolated` to create a fresh private fixture and `.env` in a new temporary directory without changing this checkout's environment. The fixture uses SQLite `VACUUM INTO` to capture WAL data consistently and verifies restored credential decryption. The resulting fixture environment intercepts every SpaceTraders HTTP call locally. Its login metadata stays in the ignored private fixture directory. Do not put live tokens into this environment. PHPUnit explicitly overrides fixture configuration and uses separate disposable databases/locks.

`php scripts/verify-mvp-worker.php` creates its own new file-backed SQLite/world and exercises two actual database workers, duplicate delivery, a complete trip across worker restarts, SIGKILL after a simulated upstream commit, uncertain-response recovery and offline Stop. It prints its owned artifact directory. No live API or shared database is used.

### Local operation

Serve HTTP with Herd at `http://spacetraders.test`. On a new environment install the locked dependencies, choose the owned SQLite database and retain its key. Back up before migrations. Use PHP 8.4.1+ in the 8.4 series, Node 26 and pnpm 12.16.1.

For an existing plaintext database, stop workers and scheduler, enter maintenance mode, back up the SQLite database with SQLite's backup mechanism and save its matching APP_KEY privately as `app-key.txt`. The backup directory must contain `database.sqlite` and `app-key.txt`. Verify restoration on a disposable copy. Apply additive migrations, then run:

```bash
php artisan down
php artisan migrate --force
php artisan spacetraders:encrypt-tokens --backup=/absolute/private/backup-directory
php artisan up
```

The resumable converter checks the backup's integrity, matching key and legacy credential rows, encrypts each row atomically and verifies decryption. Existing APP_KEY is required forever for those credentials. A code-only rollback to the plaintext implementation is incompatible. Restore compatible code and data together after stopping and reconciling all work. Never delete action history to clear an uncertain write.

Automation defaults to disabled. After owned-data backup, conversion and local verification, set `SPACETRADERS_AUTOMATION_ENABLED=true` in that environment, then run these in two terminals from this checkout:

```bash
php artisan queue:work database --queue=spacetraders --sleep=1 --tries=1 --timeout=120
php artisan schedule:work
```

Request-budget contention before HTTP transport safely returns the intent to pending. A rate limit or lost response after dispatch still requires reconciliation. The feature jobs always use the `database` connection and `spacetraders` queue. HTTP timeout is 30 seconds, job timeout 120 seconds, queue retry-after 180 seconds. OS file locks serialize shared commands and a persisted global request budget on this Mac; no expiring lock can be stolen while a process still runs. Keep the same database and lock directory for every worker. A scheduler pass dispatches due work every five seconds, bounds redispatch frequency and recovers lost dispatch and stale senders. Closing the browser leaves those processes running. Mac sleep/shutdown pauses them. Restarting reconciles saved state and honors expiry/Stop rather than replaying missed writes.

To stop: click **Stop automation**, wait for the status to settle or reconcile an uncertain action, then press Ctrl-C in the worker and scheduler terminals. Set `SPACETRADERS_AUTOMATION_ENABLED=false` to disable new starts. Pending manual commands still need to settle. `php artisan spacetraders:dispatch-due` runs one recovery/dispatch pass when using finite worker sessions. No launchd service is installed.

### Evidence boundary

The acceptance contract is in [mvp-implementation.md](docs/mvp-implementation.md). Local fake-provider workflows prove application behavior, not live SpaceTraders acceptance, fresh-agent economics or profitability. No live agent creation, credit spending, recurring service, push or deployment is part of this work. General outfitting, contracts, repairs, intersystem travel and fleet expansion remain outside this implementation. Manual survey creation, surveyed extraction, cargo transfers, bounded market scouting and same-system trading are now supported. Dedicated surveyor and hauler automation still require measured fleet bottlenecks. Conservative fuel estimates and missing observations can block an otherwise viable live route; inspect the visible reason and scout manually.

A repeatable browser journey is available as `node scripts/verify-mvp-browser.mjs` using an existing Playwright installation via `PLAYWRIGHT_MODULE`. It requires a fresh owned database from `php scripts/mvp-fixture.php --isolated` and this checkout's explicitly configured synthetic fixture. Configure that isolated environment only on an owned local test checkout; preserve any existing environment and history. The journey refuses a previously recorded setup purchase or unsettled gameplay before changing fixture state, never starts a web server, blocks external browser traffic and writes per-step results and state screenshots. It requests Stop and drains queued work on failure, retains a redacted original stack, and reports any unverified cleanup. It resets only the owned fake upstream world, then drives real previews, approvals, database workers, manual mining, batch sales, reload, Stop, a complete trip, uncertainty/reconciliation and the one-time purchase. Treat it as a fixture reset, not a command for live or shared environments. Set `QA_ARTIFACT_DIR` for output and optionally `PLAYWRIGHT_EXECUTABLE` for a matching installed Chromium binary.


### Exploration and opportunity updates

The flight deck now shows catalogue coverage, hidden traits, independently dated market directories and detailed prices, missing observation questions, candidate cycles, and remaining-cost choices for owned cargo. A remote response without prices preserves the previous detailed quote and its original age. Local transactions invalidate their market quote until it is observed again. New observation history starts at migration; older snapshots do not establish trends.

Apply the additive observation-history migration to an owned fixture first. The existing local game database and environment were not migrated by this implementation. Back up the database with its matching application key and stop/settle existing commands before any later live-local migration.

`SPACETRADERS_ROUTE_EVIDENCE=conservative` retains the existing CRUISE distance allowance with a separate two-tank-unit continuation reserve. `measured` restricts automation to observed directed edges for the exact ship configuration and reset. Explicit manual CRUISE previews can collect calibration evidence under the conservative policy. These are client policies, not server travel guarantees. Other modes, zero-capacity ships and cargo refueling remain blocked pending validation.

**Bounded scouting and trading** uses the selected ship, the existing preview/start/stop flow, and explicit credit, loss, fuel and duration limits. Begin with empty cargo at a verified fuel seller. Scouting visits one chosen market for a stated price question, then returns home. Trading buys the approved total in legal batches, refreshes prices, sells at the approved unit floor, and includes an empty return. Maximum cash drawdown includes inventory before sale. A changed spread stops purchases and retains owned cargo for a new decision. No automatic disposal, alternate buyer, ship purchase or repeated trade cycle is authorized by a preview.

The complete behavior, R01–R08 mapping, configuration, remaining gates and rerunnable evidence are in [exploration-implementation.md](docs/exploration-implementation.md). Run `scripts/verify-opportunities-browser.mjs` with the documented `PLAYWRIGHT_MODULE` and optional `PLAYWRIGHT_EXECUTABLE` to create a private synthetic fixture and exercise scouting/trading through real handlers and finite workers. Its temporary loopback server serves only that fixture and exits at the end. It does not use the Herd site's environment or credentials.

### Security defaults and local scope

The environment template disables debug output and Ignition runnable solutions. Keep `IGNITION_ENABLE_RUNNABLE_SOLUTIONS=false` in existing local environments too. Runnable solutions can rotate APP_KEY and invalidate saved credentials. `php scripts/verify-environment.php` tests the shipped defaults, debug and production controls, and a vulnerable positive control entirely in private disposable base paths; the checkout key is never changed.

This MVP targets one operator on this Mac. Game POST routes are limited to 60 requests per minute per signed-in user. The shared upstream budget remains below the API ceiling across all agents. Public multi-user hosting and fair scheduling between users remain separate scope. Scoped agent binding returns 404 for both absent and foreign agents before applying command controls.

SQLite uses WAL with `synchronous=FULL` and macOS `fullfsync` support to flush saved intents at commit. The real-worker check reads both settings inside a separate queue worker and exercises process death/restart. No actual power-loss or hardware-storage trial is claimed. See [SQLite's durability documentation](https://www.sqlite.org/pragma.html#pragma_synchronous). Discovery prioritizes owned-ship locations within the same 24-observation cap, so a large system cannot spend that budget entirely on unvisited markets first.

An explicit offline-fixture banner distinguishes synthetic responses from gameplay. The browser replay check uses accessible select names and redacts its synthetic login password from failure messages. Retain a separate encrypted backup of APP_KEY. The converter's private verification directory needs a temporary matching key copy; remove that temporary copy after conversion while preserving the original key and its separate backup.
