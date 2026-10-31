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

Open a saved agent to use the responsive dark flight deck. Synchronize explicitly to observe the reset, credits, fleet, waypoints, markets and shipyards. Polling refreshes saved local state only. Discovery is limited to 10 pages per collection, three fleet systems and 24 market/yard reads per synchronization. Unknown prices stay unknown. If a market or yard needs an observer, approve manual orbit and navigation commands to scout it, then synchronize there.

Manual orbit, dock, navigate, refuel, mine, cargo purchase and cargo sale all use expiring previews. Enter a total maximum cost for purchases/refueling, a minimum sale total for sales, and a protected-credit floor. Limits are checked against fresh quotes before execution. SpaceTraders provides no upstream limit-price parameter, so these are estimates rather than guaranteed execution ceilings. Unsupported hard guarantees block spending. Trade-volume, cargo, credits, transit, cooldown and equipment checks run on the server.

A missing miner opens a separate one-time setup preview. It requires a complete offered configuration, an owned observer at that yard, a verified same-system route and enough post-purchase reserve. It never installs equipment or automatically buys another ship after a lost response. Review delivered configuration before generating a new mining preview.

Mining starts with empty cargo, CRUISE mode and enough observed fuel. Each trip makes one extraction, waits for server timestamps, sells attributable cargo in fresh-quote batches, refuels within the approved budget and records the outcome. One trip is the default. Explicit previews can approve up to ten trips within 60 minutes. Zero-yield extraction blocks further attempts. Nonpositive observed cash contribution stops repeats. Unknown buyers retain cargo and block the run. Credits, fuel, yields, sold/retained units and cash contribution are recorded separately. Cash contribution is not an economic-profit forecast.

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

The acceptance contract is in [mvp-implementation.md](docs/mvp-implementation.md). Local fake-provider workflows prove application behavior, not live SpaceTraders acceptance, fresh-agent economics or profitability. No live agent creation, credit spending, recurring service, push or deployment is part of this work. General outfitting, contracts, surveys, repairs, intersystem travel and fleet expansion remain outside this MVP. Conservative fuel estimates and missing observations can block an otherwise viable live route; inspect the visible reason and scout manually.

A repeatable browser journey is available as `node scripts/verify-mvp-browser.mjs` using an existing Playwright installation via `PLAYWRIGHT_MODULE`. It requires this checkout's explicitly configured synthetic fixture, refuses unsettled prior gameplay, never starts a web server, blocks external browser traffic and writes per-step results and state screenshots. It resets only the owned fake upstream world, then drives real previews, approvals, database workers, manual mining, batch sales, reload, Stop, a complete trip, uncertainty/reconciliation and the one-time purchase. Treat it as a fixture reset, not a command for live or shared environments. Set `QA_ARTIFACT_DIR` for output and optionally `PLAYWRIGHT_EXECUTABLE` for a matching installed Chromium binary.

### Security defaults and local scope

The environment template disables debug output and Ignition runnable solutions. Keep `IGNITION_ENABLE_RUNNABLE_SOLUTIONS=false` in existing local environments too. Runnable solutions can rotate APP_KEY and invalidate saved credentials. `php scripts/verify-environment.php` tests the shipped defaults, debug and production controls, and a vulnerable positive control entirely in private disposable base paths; the checkout key is never changed.

This MVP targets one operator on this Mac. Game POST routes are limited to 60 requests per minute per signed-in user. The shared upstream budget remains below the API ceiling across all agents. Public multi-user hosting and fair scheduling between users remain separate scope. Scoped agent binding returns 404 for both absent and foreign agents before applying command controls.

SQLite uses WAL with `synchronous=FULL` and macOS `fullfsync` support to flush saved intents at commit. The real-worker check reads both settings and exercises process death/restart. No actual power-loss or hardware-storage trial is claimed. See [SQLite's durability documentation](https://www.sqlite.org/pragma.html#pragma_synchronous). Discovery prioritizes owned-ship locations within the same 24-observation cap, so a large system cannot spend that budget entirely on unvisited markets first.

An explicit offline-fixture banner distinguishes synthetic responses from gameplay. The browser replay check uses accessible select names and redacts its synthetic login password from failure messages. Retain a separate encrypted backup of APP_KEY. The converter's private verification directory needs a temporary matching key copy; remove that temporary copy after conversion while preserving the original key and its separate backup.
