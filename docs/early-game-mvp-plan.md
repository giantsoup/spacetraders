# Early-game mining and manual-play MVP

Prepared October 31, 2026. Source rechecked at `78d30d824f3cc27763a64209f9cdef867bc2afee` on `chore/laravel-13-php-84`.

This is a future implementation plan. It does not create a SpaceTraders agent, spend credits, install services, or change application behavior.

## Goal and agreed scope

Build a dark, 8-bit dashboard where Taylor can play the early game manually and start a bounded mining-and-selling bot. Target a fresh agent and run the application, scheduler, and worker on this Mac. Show a preview before automation starts and provide a stop button.

Taylor selected these manual controls: navigate, orbit, dock, refuel, mine, buy cargo, and sell cargo. Include a one-time setup purchase preview, with Taylor's approval, if a fresh fleet cannot mine. General ship/equipment management, contracts, surveys, refining, siphoning, combat, intersystem travel, and fleet expansion remain later work. "Money" means in-game credits.

The first automated outcome is one completed mining trip: extract cargo, sell supported goods, account for fuel purchases, and show the resulting credit change. A successful trip does not establish long-term profitability. Continue only when observed results and the remaining budget support another trip.

## What the repository already provides

The current implementation has useful authentication and API boundaries, but no gameplay state or runner.

| Existing code | Verified behavior and implication |
| --- | --- |
| `composer.json`, `composer.lock`, `package.json` | Laravel 13.39.0, PHP 8.4, Inertia 3, Vue 3, Pinia 4, Tailwind 4, and Vite 8. Keep the locked stack and JavaScript conventions. A framework migration is unnecessary. |
| `app/Services/SpaceTradersClient.php` | Laravel HTTP transport with fixed upstream host, timeouts, and redirects disabled. Registration and agent lookup validate responses. There are no gameplay operations or retry policies. |
| `app/Http/Controllers/AgentController.php` | Registers and imports agents and lists owner-scoped records. Registration retains the agent token and saves the account token encrypted only when the user opts in. |
| `app/Models/Agent.php`, agent migration | Tokens are hidden from serialization but stored without an encryption cast. There is no reset identity or uniqueness constraint. Repeated imports can create duplicate local records. |
| `resources/js/Pages/Agent/Agents.vue` | Lists agents. Agent cards do not open a gameplay page. |
| `resources/js/store.js` | Persists `activeAgentAccountID`. Account identity must not become the gameplay identity: one account can have multiple agents. |
| `app/Http/Controllers/ForwarderController.php` | Authenticated proxy supports arbitrary relative API operations and caller-supplied tokens. Its write path can bypass future command ownership and automation locks. |
| `app/Console/Kernel.php`, `config/queue.php`, `.env.example` | Empty scheduler, synchronous queue default, file cache. Database queue configuration exists, but the repository has no `jobs` migration. |
| `tests/Feature/AgentTest.php`, `ForwarderTest.php`, `UpgradeCompatibilityTest.php`, `scripts/verify.sh` | Existing HTTP fakes, ownership/token regressions, auth protections, and verification script. Extend these rather than replacing them. `public/build` is tracked. |

The older [Laravel upgrade plan](laravel13-upgrade-plan.md) describes historical preparation. The current code and locks above are the starting point for this feature.

## Why mining comes first

Extraction avoids financing a cargo purchase before the first sale. It still costs travel time and fuel, produces variable goods, and can affect ship condition. A starter contract adds sourcing, delivery requirements, and deadlines. Trading adds working-capital risk and price changes between purchase and sale. Those are reasonable later strategies after the common navigation and transaction controls work.

Mining is conditional on the actual fresh fleet and starting system. Do not assume a ship suffix, starting credit balance, mining laser, mining location, market price, or profitable route. If no compatible ship exists, offer the setup preview below. If setup is unaffordable or no viable route exists, show the missing requirement and stop. Do not purchase without the approved preview or switch strategies.

The [official OpenAPI specification](https://spacetraders.io/SpaceTraders.json) currently reports API `2.3.0`. It defines the following constraints. Refresh them before implementation:

- Ore extraction requires an eligible waypoint, suitable mining equipment, and orbit. Use ordinary extraction first, without surveys.
- Navigation is within one system, requires orbit, consumes fuel, and returns an arrival time. Most actions are unavailable during travel.
- Selling requires docking at a marketplace that trades the cargo. Detailed market prices require a ship at that market.
- Respect each good's `tradeVolume` per transaction and refresh cargo, credits, and prices after each trade.
- Use returned cooldown and navigation state. Do not hard-code a mining interval or arrival delay.

The extraction endpoint's general description mentions gas equipment, while the API also exposes a separate siphon operation. This MVP uses mining lasers and ore extraction only. Preflight must also inspect modules: the [official error reference](https://docs.spacetraders.io/api-guide/response-errors) identifies missing mineral processors as `4242` and missing mining lasers as `4243`. Require the appropriate mineral processor as well as a laser and compatible crew/power. Resolve endpoint-specific requirements from the current schemas and observed API errors. See [the API research note](early-game-api-research.md) for sources and additional mechanics.

## The dashboard Taylor uses

### An 8-bit style with readable controls

Use CSS for pixel borders, square panels, stepped shadows, compact status lamps, and a restrained arcade palette. Keep the dashboard dark regardless of OS theme. Use a readable monospace body font, with a self-hosted, license-checked pixel font only for short headings if useful. CSS and small SVG icons are enough for the MVP; custom sprite production is unnecessary.

Keep text crisp at ordinary browser zoom. Do not apply pixelation filters to text or charts. Preserve visible focus, keyboard navigation, semantic buttons, explicit labels, and at least WCAG AA contrast. Show status in words as well as color. Respect reduced motion. Avoid flashing, scan-line overlays, and sound by default.

Deliver these views within one responsive dashboard:

- Agent header with symbol, credits, reset identity, connection state, and snapshot age.
- Fleet list and selected-ship panel with location, nav status, fuel, cargo, mounts, condition, and cooldown.
- Local-system waypoint list with coordinates, relevant traits, distance, and a selected destination. Start with a compact schematic or list, not a galaxy renderer. Every visual marker also has an accessible list entry.
- Market panel with cargo quantities, buy/sell quotes, trade limits, quote age, and quantity input. Mark unavailable prices as unknown.
- Manual command panel for the agreed controls. Explain unavailable actions and show travel or spend information before submission.
- Automation panel with route, eligible ship, budget, expected next action, start/stop controls, and a plain-language blocked reason.
- Activity log with command origin, confirmed outcomes, API error codes, and cash changes. Include worker and scheduler health separately from game status.

On narrow screens, keep ship status and stop controls near the top, then stack the panels. Use semantic tables for cargo and transactions where comparison helps. Use the existing Vue components and Tailwind setup. No new UI framework is required.

### Manual play and bot ownership

All controls submit to owner-scoped Laravel routes with CSRF protection. The browser sends the saved agent ID, ship symbol, command, and validated arguments. It never supplies a bearer token, upstream URL, or trusted credit balance.

Use the same command executor for manual play and the bot. MVP automation manages one ship and permits one active run per upstream agent. Manual reads remain available. To command the automated ship, Taylor stops the run, waits for any in-flight action to settle, and takes manual control. Commands for another ship still share the agent's write lock, credit checks, and request budget. Apply the active run's reserved-credit floor to those commands too, so manual spending cannot consume its safe-return reserve.

Manual cargo purchases are supported, but the bot never purchases speculative cargo. Both purchase and sale previews show quantity, available credits, cargo capacity, current quote, and a spend or price bound. Revalidate on execution. If the observed quote changes outside the approved bound, request a fresh preview instead of silently spending more.

The inspected trade and ship-purchase requests have no limit-price parameter. Local revalidation cannot guarantee the execution price if it changes before the write reaches the server. Show that limitation in spending previews, use small trade batches and a reserve buffer, and pause after an unexpected transaction price. If Taylor requires a guaranteed hard execution-price ceiling, block that purchase as unsupported. Do not describe a client-side quote check as an upstream price guarantee.

Actions that have already reached SpaceTraders cannot be cancelled. Stop records intent immediately and shows `stopping` while an already reserved or in-flight action settles. After that action, no further mutation starts. If the result is uncertain, show that uncertainty and require reconciliation before manual takeover.

## One-time mining setup

If the fleet already has an operational miner, skip purchasing. Otherwise discover local shipyards and inspect available ship templates. For the first setup flow, purchase a complete mining-capable ship with a mineral processor, mining laser, compatible crew/power, useful cargo capacity, and a viable route. This keeps general equipment installation outside the first release while satisfying fresh-agent setup.

The [purchase endpoint](https://spacetraders.io/SpaceTraders.json) requires an owned ship at a waypoint with a shipyard that sells the selected ship type. Read `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}/shipyard`; detailed offers may require a visit. Preview and approve any scouting movement first. Never infer a ship's complete equipment from its marketing name.

Show the ship configuration, yard, observed price, maximum approved price, scouting cost, remaining credits, fuel reserve, and mining-route eligibility. Make the purchase a separate explicit approval from starting automation. Send `POST /my/ships` with `shipType` and `waypointSymbol` only after revalidating the offer and budget. If the price exceeds the approved ceiling or configuration cannot be verified, invalidate the preview.

Consume that approval once. Record the purchase intent before sending and reconcile fleet, credits, and visible shipyard transactions after an uncertain response. Do not buy a second miner because the first response was lost. After a confirmed purchase, refresh the actual delivered ship and generate a new mining preview. An unexpected configuration blocks Start.

If no affordable complete miner is available, show the blocker and an equipment-outfitting follow-up. An outfitting implementation would need cargo purchases, shipyard installation fees, module/mount requirements, and additional approval/recovery tests. It is not a fallback that the bot can start spending on automatically.

## First automated trip

1. Taylor registers a fresh agent through the existing registration form, then opens its dashboard. Do not register another agent on page load or retry an uncertain registration automatically.
2. Synchronize public reset status, agent balance, the complete fleet, and current-system waypoints with pagination. Identify a mining-capable ship from its installed equipment, operational requirements, cargo capacity, and condition. Complete the separately approved setup purchase if required.
3. Build candidate same-system mining-and-market routes. Check mining eligibility, travel feasibility, refueling access, and potential buyers for the possible yields. Bound discovery requests and record observations with timestamps.
4. Produce a read-only preview. If price data requires visiting a market, show a separate bounded scouting-trip preview. Movement needs explicit approval even though the purpose is discovery. After scouting, produce the mining preview using observed quotes.
5. Show the ship, mining site, sale stop or stops, fuel plan, known and unknown yields, quote age, current cargo, credit reserve, spending ceiling, and stop conditions. Taylor starts the trip explicitly. Revalidate the preview against current state before accepting it.
6. Orbit, navigate, wait for arrival, and reconcile the ship. Extract while capacity and budget permit. Wait for the reported cooldown between extractions. Reserve space conservatively for the next yield and stop extraction when full or when the remaining capacity is unsafe.
7. Navigate to the chosen buyer, dock, refresh the market, and sell in permitted batches. Update the local snapshot from each confirmed response. Sell only cargo attributable to this run, or cargo Taylor explicitly included in the preview.
8. Refuel where permitted and within the approved ceiling. Keep enough fuel or a verified affordable refueling stop for the next safe leg. Finish the first trip with an outcome report and the bot stopped.
9. Offer another preview for bounded repeated trips. Recheck route economics after each trip and stop at the duration, cycle, spending, reserve, or loss limit.

Proposed first-run defaults are one trip and no automatic restart. For later repeated runs, propose a 60-minute duration cap and a maximum trip count. Calculate the reserve and fuel ceiling from the fresh account and selected route, display actual values, and require them before Start is enabled. Do not invent an affordable fixed reserve before inspecting the agent.

If cargo has no verified buyer, pause with the cargo retained. Never silently jettison goods. If reaching a second buyer would consume the reserve, pause instead. The implementation must also bound extraction attempts that repeatedly return no usable yield.

## Proposed Laravel design

Keep gameplay orchestration in PHP. Vue renders saved state and submits intents. Pinia may remember the selected local agent and panel preferences, but never tokens, balances, authoritative navigation state, or runner progress.

The following names are proposed additions, not existing files:

| Component | Responsibility |
| --- | --- |
| `AgentDashboardController`, `AgentCommandController`, `AutomationRunController` | Render safe state and accept sync, preview, command, start, and stop requests. Use Form Requests, policies, and scoped route binding. |
| `SpaceTradersClient` | Retain the fixed-host HTTP boundary; add validated, named gameplay operations and normalized API errors. Avoid a generated client until current schema irregularities are understood. |
| `AgentSynchronizer` | Refresh complete paginated fleet and bounded waypoint/market observations; persist upstream timestamps and local fetch time. |
| `ShipCommandExecutor` | Serialize commands, check ownership and run state, persist action intent, call the API, reconcile results, and apply credit and request limits. Both UI and bot use it. |
| `MiningPlanner` | Pure route eligibility and next-action decisions from validated snapshots and limits. Return an action, a wait-until timestamp, a completion, or a specific blocker. |
| `ExecuteShipCommand`, `AdvanceMiningRun` jobs | Short asynchronous work carrying local identifiers, never token strings or serialized credential-bearing models. Each execution performs at most one gameplay mutation. |
| `spacetraders:dispatch-due` command | Find due runs and recovery work. Schedule it through the existing `app/Console/Kernel.php`. |

Use PHP enums for run and action states, integer credit and unit amounts, and immutable UTC timestamps. A small typed result object should make "wait", "act", and "blocked" distinct. Validate external payloads at the HTTP boundary, then let the planner trust its inputs. Avoid a plugin system, generic workflow engine, repository wrappers, and separate bot service.

Suggested owner-scoped routes are `GET /agents/{agent}` for the dashboard and POST endpoints beneath that agent for sync, command preview, commands, automation preview, automation start, and automation stop. Mutations return an accepted command or run identifier, not a claim of upstream success. Repeated form submissions reuse a local command key.

### Durable data and identity

Use the existing local database with additive migrations. SQLite is sufficient for one local worker if contention and crash recovery are verified. Use conditional database updates and unique constraints, not assumptions that SQLite row locks behave like MySQL.

| Data | Minimum contents and invariants |
| --- | --- |
| Agent additions | Upstream symbol, reset identity, credits, sync time, credential status. Canonical upstream identity is environment plus reset plus symbol, not `account_id`. |
| `ships` | Unique agent and ship symbol, nav, fuel, cargo, mounts and operational state, condition, cooldown, and snapshot time. Store bounded upstream detail as JSON where relational queries are unnecessary. |
| `waypoint_observations`, `market_observations` | System, waypoint, reset identity, traits or quotes, visit visibility, and observation times. Never reuse observations across resets. |
| `automation_runs` | Ship, route, explicit limits, state, generation, next action time, heartbeat, stop intent, trip totals, and blocked reason. Enforce one active run per canonical agent using a unique active-run reference or equivalent database constraint. |
| `ship_actions` | Local idempotency key, origin, run/ship, action and sanitized arguments, before-state, execution state, timestamps, upstream error code, and confirmed outcome. Persist intent before the outbound mutation. |

States for runs are `preview`, `running`, `waiting`, `stopping`, `stopped`, `blocked`, `completed`, and `failed`. Actions distinguish `pending`, `sending`, `confirmed`, `rejected`, and `uncertain`. On restart, a stale `sending` action becomes uncertain and goes through reconciliation. Never erase it or replay it as pending.

Add queue storage and, if selecting database cache locks, `cache` and `cache_locks` migrations. Reuse the existing `failed_jobs` table. Jobs are recoverable delivery mechanisms; the run and action tables determine what remains due. A scheduler pass recovers the gap between committing a due action and dispatching its job.

### Credentials and duplicate imports

Before unattended jobs, migrate stored agent tokens to Laravel encrypted casting. Existing plaintext must be converted deliberately; adding an encrypted cast alone breaks old rows. Use a backed-up database, a resumable conversion with explicit progress, round-trip verification, and an application maintenance boundary. Retain `APP_KEY` and its backup. Fail closed on unreadable credentials. See [Laravel encrypted casts](https://laravel.com/framework/docs/13.x/eloquent-mutators#encrypted-casting).

Deduplicate imports before adding the canonical-agent constraint. Re-importing an agent for its owner updates credentials rather than creating a second runner. An import belonging to another local user fails without leaking that user's details. Resolve existing ownership conflicts explicitly. Lock and rate-limit by canonical upstream identity so two local rows cannot drive the same fleet independently.

Keep tokens out of Inertia props, Pinia, URLs, action history, exceptions, failed-job payloads, and HTTP diagnostics. Discard account tokens after registration unless the user opts in to encrypted storage with the app key. Allow users to forget saved account tokens and forget them automatically when SpaceTraders rejects them. Redact upstream errors before showing or storing them.

### Request limits, retries, and uncertain writes

Use one shared request budget for sync, scouting, manual commands, and automation. The [current rate-limit guide](https://docs.spacetraders.io/api-guide/rate-limits) describes account and IP limits. Enforce an app-wide budget for this Mac as well as account-scoped budgets, including registration/import, so adding agents does not multiply allowed traffic. Other clients on the same network can still consume the IP allowance. Begin below the documented ceiling, honor server rate-limit and retry headers, and back off with jitter. Do not let dashboard polling create extra upstream traffic. Persist the next eligible request time so restarting the worker does not reset the budget.

Serialize agent mutations and reserve the action before sending it. Re-fetch credits before purchases or refueling. All local command paths use this mechanism. Once typed controls exist, reject upstream mutations through `/forward` and remove its browser-supplied authenticated gameplay path. Preserve only explicitly needed public reads. Update the forwarder tests and README together. This deliberate compatibility change closes the existing bypass.

Safe reads may retry with bounded backoff. Do not put automatic HTTP retries around all POST requests. After a timeout, lost response, malformed success payload, or crash after send, reconcile ship state, cargo, cooldown, credits, and available market transactions. These observations do not always prove which write occurred. If they cannot settle the action, block and ask Taylor to inspect it. Local idempotency keys prevent local duplicate dispatch, but do not guarantee exactly-once execution at SpaceTraders.

On `429`, respect the server delay and reconcile before retrying a mutation unless the response definitively establishes rejection. Recover transit and cooldown from the server's current timestamps. A cooldown read can return `204` when none exists; its expiration field is optional. Use the remaining duration when necessary and verify readiness after waiting. Pause on invalid credentials, a confirmed reset, incompatible responses, repeated no-progress actions, deteriorating ship condition, or budget exhaustion.

Use public status reset metadata to distinguish world resets from isolated authentication failures. Mark old runs and observations stale on a changed reset identity. Archive history and require a new registration/import plus preview. Never auto-register after a reset.

### Worker and scheduler on this Mac

Switch this feature's execution to the database queue. A synchronous queue cannot provide durable waiting or background progress. Start with one named worker and file locks on this single host, plus the database action and run constraints. Redis and Horizon are optional later, not MVP requirements.

The scheduler dispatches due work every few seconds; jobs return when waiting and record `next_action_at`. There are no long sleeps in requests or jobs. Configure HTTP timeout below job timeout, job timeout below queue `retry_after`, and lock expiry beyond the job's bounded lifetime. Dispatch after commit. Test worker death and expired locks. See [Laravel queues](https://laravel.com/framework/docs/13.x/queues) and [scheduling](https://laravel.com/framework/docs/13.x/scheduling).

Keep Herd for HTTP. During development, run the scheduler and worker explicitly with the project's PHP 8.4 binary. Later add opt-in `launchd` supervision with the correct working directory, absolute runtime paths, bounded logs, and restart instructions. Do not install a recurring task during implementation without that operational step being in scope.

Closing the browser does not stop a healthy worker. Mac sleep, shutdown, or stopped processes do. On wake or restart, reconcile instead of issuing missed actions in a burst. Resume only still-active, unexpired runs with no stop intent and unchanged reset/credentials. The UI shows stale heartbeats and explains when it cannot reach the worker. The stop button records a persistent stop even if the worker is offline.

Use Inertia partial polling of local state, with a modest interval and background-tab throttling. The [Inertia polling helper](https://inertiajs.com/docs/v3/data-props/polling) fits the current adapter. WebSockets and browser timers that drive gameplay are unnecessary.

## Credit and resource accounting

Record extracted units by symbol, sold units, unsold cargo, fuel purchased, other explicitly approved costs, and trip elapsed time. Credit changes and transaction totals come from confirmed API responses.

Report trip cash contribution as sale proceeds minus attributable fuel and other approved costs. Report the agent's balance change separately. Fuel purchased for later trips and fuel already in the tank make cash contribution an imperfect measure of economic profit. Track starting and ending fuel as well as costs. Do not count unsold cargo at an invented price.

Initial cargo or out-of-band game actions can distort totals. Track the starting inventory and known extraction quantities, reconcile unexpected differences, and label unresolved adjustments. Display credits per hour only after completed observed trips, alongside sample duration and fuel/inventory caveats. No fixed credit target or guaranteed return belongs in acceptance criteria.

## Implementation sequence and effort

These are planning estimates for one developer, including ordinary tests and iteration. They assume the current stack remains usable. Calendar time depends on availability and live-game travel waits.

| Phase | Reviewable result | Effort |
| --- | --- | --- |
| 1. Confirm API and dashboard shape | Refreshed official API fixtures, two lightweight CSS layout sketches, chosen responsive 8-bit direction, fresh-agent preflight rules, and acceptance scenarios. | 1–2 days |
| 2. Establish safe saved state | Owned-agent policies, reset identity, duplicate-import handling, token conversion, fleet/market observations, and read-only synchronization. | 2–3 days |
| 3. Deliver manual gameplay commands | Shared command executor, validated previews, queue storage, local request budget, all agreed manual actions, and forwarder bypass closure. | 2–3 days |
| 4. Finish the playable dashboard | Fleet and waypoint panels, market quantities, travel/cooldown feedback, activity history, accessible dark styling, and narrow-screen polish. | 2–3 days |
| 5. Add one-time miner setup | Shipyard discovery, complete-miner eligibility, purchase preview/approval, price ceiling, and lost-response reconciliation. | 1–2 days |
| 6. Add mining automation | Route preview and approved scouting, one-trip planner, durable waits, budgets, stop/takeover, accounting, and recovery. | 3–4 days |
| 7. Verify and prepare local operation | Real worker/restart tests, browser workflows, scoped QA/security review, optional supervised-process setup, documentation, and one explicitly authorized live trip. | 2–4 days |

The combined estimate is **13–21 developer days**, roughly 3–4.5 full working weeks. Phases 1–4 total 7–11 days for a polished manual-play foundation. The dashboard's visual design and frontend work occupy roughly 3–5 days across phases 1 and 4; that effort is already included in the total. The setup estimate covers a complete-ship purchase, not general outfitting. An economy without an affordable compatible miner, or material API changes, requires a scope decision.

Keep each phase independently reviewable. Deliver local source, migrations, tests, and tracked build assets together where the behavior changes. Do not publish or deploy as part of this plan.

Before enabling the runner, back up the owned database and key, verify restoration on a disposable copy, and keep automation disabled by default. Apply additive migrations and verify credential conversion before starting the worker. To roll back, disable new starts, stop and reconcile active work, stop the worker/scheduler, and restore compatible code and data together. A code-only rollback cannot make encrypted tokens readable by the old plaintext implementation. Preserve the action history needed to resolve any upstream writes that already happened.

## Verification and acceptance

Automate these checks with HTTP fakes at the SpaceTraders boundary and `Http::preventStrayRequests()`. Use synthetic agents and an owned disposable database. Follow the existing [HTTP testing approach](https://laravel.com/framework/docs/13.x/http-client#testing).

| Boundary | Required evidence |
| --- | --- |
| Agent ownership and credentials | Another user cannot read, preview, start, stop, or command the agent. Tokens remain encrypted and absent from props, logs, jobs, and errors. Repeated import cannot create two command authorities. |
| One-time setup | Eligible offers include actual modules/mounts and a safe post-purchase reserve. Changed prices invalidate approval. A successful purchase with a lost response does not buy another ship. An already-equipped fleet skips setup. |
| Fresh-agent preflight | Fleet and waypoints paginate. Missing mining laser or mineral processor, incompatible operational requirements, no cargo capacity, no buyer, and insufficient fuel/reserve each produce a specific blocker. |
| Manual controls | Navigate, orbit, dock, refuel, extract, buy, and sell reach the named endpoint with the intended payload. Invalid nav state, quantities, price bounds, funds, and capacity fail before send where locally knowable. |
| Normal mining trip | Orbit → navigate → arrival → extraction/cooldown → market travel → dock → batch sales produces recorded cargo and credit outcomes. Include several yield symbols and a partial-sale case. |
| Safety and contention | Double-click, duplicate queue delivery, two worker processes, stop during travel, stop while offline, and manual takeover do not start overlapping local mutations. Known costs below the reserve are rejected. Test an execution-price change that breaches a quote check and verify the recorded discrepancy and stop. |
| Uncertain outcomes | Timeout after a sale succeeds, malformed successful extraction, and process death before local confirmation trigger reconciliation. Unprovable outcomes block rather than repeat. |
| Limits and recovery | `429`, server errors, invalid token, reset, stale quote, no-progress extraction, depleted/changed site, condition change, lost job dispatch, and restart/wake take the intended bounded path. |
| Real background execution | File-backed disposable SQLite and the actual database queue/worker prove persistence and contention. In-memory SQLite, array-cache locks, or `Queue::fake()` alone cannot prove this. |
| Dashboard behavior | Real browser interaction with fake upstream responses proves previews, controls, pending states, stop acknowledgement, refresh/reload recovery, keyboard focus, contrast, responsive panels, and no token exposure. Inspect screenshots. |

After implementation, run `./scripts/verify.sh`, Composer validation/platform checks, and dependency audits as documented in README. Extend the script's Pint coverage to new PHP files. Rebuild tracked `public/build` and run `git diff --check`. Run scoped Laravel QA followed by a separate security review on the final implementation revision, then repeat affected checks after fixes.

Local fixtures prove the app's control and recovery behavior. They do not establish that a fresh live agent can mine profitably. With later explicit authorization for gameplay, create/import the chosen fresh agent, inspect the real preview, approve its spending bound, complete one trip, and record actual balances, extracted/sold cargo, fuel, API version, and remaining uncertainties. This planning task authorizes no live registration or game mutation.

The MVP is ready when Taylor can manually complete the agreed early-game actions, approve a mining preview, complete a recorded trip, stop safely, and recover from a worker restart without an unexplained repeated write. If the live fleet or economy cannot support that route, a truthful, actionable blocked state is required before enabling automation.

## Later work and remaining decisions

The chosen MVP scope is settled. Implementation still needs an observed fresh-agent fleet and system, actual reserve/spend values, and selection of the CSS dashboard sketch. These can be resolved from the read-only preflight and prototype; no credentials belong in this document.

After the first measured trips, consider survey-assisted yields, a dedicated selling ship, contract fulfillment, equipment-outfitting previews, repair policies, and trade-route selection. Add one strategy at a time against the shared executor. Broader manual game controls and an always-on host are separate follow-up scope.

## Planning evidence and limits

The repository started clean. This investigation read source, migrations, manifests, locks, tests, configuration, and the existing verification script. It checked official SpaceTraders and framework documentation. Only Markdown planning/research files are added by this task. Concurrent edits to frontend styling, README, verification scripts, and generated assets are left untouched. Recheck that work before implementation; the source snapshot above identifies this plan's committed baseline.

No authenticated game request, browser session, dependency change, migration, worker, scheduler, test suite, or profitability trial was run for this plan. Documentation checks cover local links and references, 30 distinct upstream method/path pairs, effort arithmetic, whitespace, and the final document text. Source URLs were checked directly or through the web tool where command-line retrieval returned `403`. These checks do not prove the proposed implementation.
