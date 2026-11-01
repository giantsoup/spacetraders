# Exploration and opportunity implementation

Automation baseline `032b822`, branch `feature/local-automation`, checkout `/Users/Taylor/.t3/worktrees/spacetraders/feature-local-automation`. The exploration changes were integrated with the existing engine, investigation, staged mining, contracts and credit ledger. The four pre-existing untracked documents remain unchanged. Applicable instructions are Taylor's supplied AGENTS.md requirement for dark personal reports. No filesystem AGENTS.md was found in this checkout or its ancestors.

## Phases and dependency gates

1. **R01, observations.** Extend synchronization with reset-scoped catalogue coverage, distinct listing and detailed-price records, provenance, and immutable history. Partial pagination and hidden traits remain incomplete. Verify remote refreshes cannot renew old prices.
2. **R02, R07, R08, decisions.** Add deterministic continuation checks and opportunity assessments using current local evidence. Separate fuel, elapsed time, capital, unsold cargo, estimates, and realized results. Recheck safety through the common command rules. Unvalidated modes and cargo refueling stay unavailable.
3. **R03, scouting.** Implement only after a purposeful visit, fuel-safe continuation, explicit travel, time, spending and visit bounds can be previewed. Stop after the required observation or exhausted limits. A probe purchase requires separate authorization and measured payback.
4. **R04, mining.** Fix modifier hazards first. Record all extraction outcomes and complete-cycle evidence. Longer loads require an explicit departure policy, capacity allowance, extraction count and elapsed bounds. Keep one extraction as the default until Taylor chooses otherwise. No fixture proves improved live earnings.
5. **R06, trading.** Proceed when source and buyer prices, a complete fuel-safe cycle, explicit inventory exposure, spending, reserve and loss bounds exist. Refresh each batch. Falling spreads stop purchases; owned cargo receives a separate remaining-cost assessment. Live trading requires separate authorization.
6. **R05, R08, fleet support.** Preserve signed surveys, shared exhaustion and transfer-state constraints when their command support is implemented. Dedicated surveyors, haulers, refining and fleet expansion require measured bottlenecks and incremental returns. No speculative purchases or broader fleet automation follows from a setting.
7. **R01–R08, acceptance.** Run deterministic fixture cases, affected application checks, and owned worker verification. Document implemented behavior and exact remaining gates. Preserve explicit preview/start/stop, shared request budget, reset invalidation and uncertain-write reconciliation throughout.

## Initial inspection

The existing `GameObservation` is a latest-value record. Synchronization paginates ships and waypoints but throws at its page limit without saving coverage. It fetches up to 24 combined market and shipyard observations, prioritizing occupied locations. Market snapshots currently mix goods listings with optional prices.

`MiningPlanner`, `AutomationRunner`, `CommandRules`, and `ShipCommandExecutor` share manual and automated writes. The automation branch also owns `OpportunityInvestigator`, `AutomationStaging`, the engine-session gate and `CreditAccounting`; those implementations are retained. Runs have explicit reserve, spending, loss, cycle and duration limits. One active run owns each agent. Agent locks serialize shared credits and commands, and the host request budget covers the API client. Non-idempotent uncertain writes require reconciliation and operator review.

The current route model supports CRUISE only, using `ceil(distance) + 2` per distinct waypoint. It is a client estimate. Navigation checks only outbound fuel. Mining requires a buyer with fuel, but checks `UNSTABLE` among traits rather than modifiers. The runner performs one extraction per trip and reports cash contribution rather than comparable economic profit.

Baseline verification: PHP 8.4.27, Node 26.13.0, PHPUnit 164 tests / 874 assertions passed. The local branch is 22 commits ahead of the stored origin/main reference; no fetch or publication was needed. The served public OpenAPI was retrieved without credentials and reports v2.3.0. No authenticated discovery or gameplay is authorized.

## Operating assumptions

Taylor confirmed conservative CRUISE as the default, with collection and analysis of our own travel data before using our own calculations. Other modes and unusual ships remain blocked pending validation. Taylor also confirmed opt-in bounded mining loads with evidence collection, and implementation of bounded scouting and trading.

These choices authorize the implementation, not live activation or capital spending. One extraction and one trip remain the default. Every run still requires explicit budgets and approval. No live spending limit or profitability assumption was inferred.

## Implemented behavior

| Recommendation | Usable change | Remaining gate |
| --- | --- | --- |
| R01 | Reset-scoped catalogue completion and page counts; partial results survive failures; separate listing, scan and chart evidence kinds; market directories cannot renew detailed prices; immutable price, travel and extraction history with observer and source | Scan/chart commands are not enabled. Legacy snapshots have no reconstructed history. Hidden traits remain unresolved. |
| R02 | Shared navigation preflight requires outbound fuel, a continuation to a verified fuel seller, affordability and a reserve. New approvals require fresh prices; an approved mining itinerary retains its fuel-seller evidence during transit. Direct and one-refuel-stop candidates expose fuel, time and extra costs separately. Actual fuel and server route timestamps are recorded beside predictions. | Automatic runs use direct same-system legs. Detour candidates need separate manual movement/refuel previews. Other modes, unusual ships, cargo refueling and intersystem travel need validated mechanics. |
| R03 | One price question generates one bounded market visit and a return home. The preview names its goods, purpose, fuel, refueling ceiling and stopping condition. Re-read remotely before departure; stop travel if that answers the question. | No hidden-trait scanning tour, probe purchase, multi-market tour or assumed information profit. Budgeted direct return must already fit. |
| R04 | Refresh hazards before extraction; check traits and modifiers for UNCHARTED, UNSTABLE, CRITICAL_LIMIT, STRIPPED and DEBRIS_FIELD. Opt-in count/headroom/site-time departure limits persist the sale phase. Record every yield, cooldown and condition event; stop on unknown output buyers and no yield. | A headroom setting is an approved allowance, not a proven maximum yield. Fuller loads have no measured live earnings advantage and are not automatically preferred. Unsafe sites pause rather than choosing an unapproved replacement. |
| R05 | Survey creation, signed extraction and cargo transfer commands use the shared executor. Preserve duplicate deposits, show an entire-mix price index with unknown outputs unresolved, share expiry/exhaustion, verify both transfer states and cargoes. Mining can explicitly use one chosen survey. | No surveyor/hauler/refinery purchase or coordinated fleet loop. Need measured output, round-trip capacity, buyer throughput, loadouts and incremental income. The index is not a probability or expected yield. |
| R06 | Explicit quantity, inventory exposure, reserve, spending, cash-drawdown, unit sale floor and margin limits. Refresh every batch and again at dispatch. A falling/nonpositive spread prevents later purchases. Retain cargo and list hold/local-sale/reachable-buyer choices using remaining costs. Include an empty return and refuel. | Future prices and execution prices are not guaranteed. No automatic diversion, disposal, production-chain investment or refining. One complete trade cycle per approval. |
| R07 | Deterministic cycle accounting subtracts acquisition, fuel and supplied operating costs; records elapsed wall time once; discloses location, tank and inventory mismatches plus unmeasured wear. Separate capital and incremental fleet payback calculations. Candidate lists do not rank cash margin as earnings/hour. | Net earnings/hour stays unknown while any required adjustment is unmeasured. No fabricated confidence score or invented mining yield forecast. Comparable live cycles are still required. |
| R08 | Existing agent locks, single active run, shared request budget, durable deadlines, reset invalidation, preview/start/stop and uncertainty review protect every new command. Recheck remaining spending, cash drawdown, travel fuel, cargo and price conditions at dispatch. Successful local trades invalidate quotes. Exhausted response budgets defer later paths. | Single-Mac coordination only. Manual actions on unrelated ships are allowed above the active reserve and are accounted separately. No distributed-worker or multi-host guarantee. |

## Configuration and controls

- `SPACETRADERS_AUTOMATION_ENABLED` remains false in the environment template. No existing environment was changed.
- `SPACETRADERS_ROUTE_EVIDENCE=conservative` uses `ceil(distance) + 2` for distinct CRUISE legs. The additional `gameplay.fuel_reserve_units=2` is held after the outbound and continuation legs. These are conservative client policies, not official equations. Zero-capacity ships and other modes are rejected.
- `SPACETRADERS_ROUTE_EVIDENCE=measured` requires directed travel samples for the exact configuration and reset for automation. Explicit manual CRUISE previews retain conservative checks so authorized calibration trips can collect evidence. The configuration fingerprint includes equipment and condition; changes require new samples. Distinct-waypoint samples must consume at least one fuel unit. When the response includes consumed fuel, its amount must match the tank difference. Invalid samples remain in raw history and the analysis counts them, but excludes them from fuel statistics and measured routing.
- Mining rechecks physical return fuel, protected credits, hazards, cooldown and headroom before extracting. Its approved bounded load can continue after remote buyer prices age. Return navigation uses the approved buyer and fuel ceiling, rather than requiring remotely unrefreshable prices. Sale and refuel refresh their executable quotes at the buyer. Unknown output buyers and changed hazards still block.
- Detailed quotes expire after `gameplay.snapshot_seconds=180`. That is a client freshness policy. A market mutation invalidates the quote immediately without changing its observation timestamp. A directory-only response cannot remove that invalidation or refresh old prices.
- Discovery keeps its 10-page, 20-row-per-page and 24 combined market/shipyard-read bounds. Occupied locations remain first; missing and oldest remote directories then rotate through the remaining budget. The UI includes known markets whose directory has not yet been read and requests another remote synchronization before travel. In-progress and failed reads remain partial. The opportunity list is bounded to 120 candidates and visibly reports reaching that limit.
- Estimated CRUISE duration uses the community wiki candidate `round(round(max(1,distance))*25/engineSpeed+15)` only when speed is known. Unknown speed gives unknown time. Docking, request waits and displaced earnings remain separate. No duration equation authorizes a deadline promise.
- Refuel quantities are tank units. Cost ceilings use whole market-unit rounding and an extra allowance. Actual fuel/credit/transaction responses remain authoritative; cargo refueling is excluded.
- Longer mining loads allow 1–10 extractions, explicit headroom and 1–900 seconds at the site. Run duration stays at most 60 minutes and trip count at most ten. Headroom does not guarantee the next random yield fits.
- Scouting permits one purposeful price visit and a direct return. Trading permits one complete cycle. Both require explicit travel fuel, total spending, protected credits, cash drawdown and duration. Trading also requires a total quantity, inventory exposure, minimum cycle cash margin and minimum unit sale price. Cash drawdown includes bought inventory until sale.
- Signed surveys are not rewritten. A separate summary counts deposits and lists reachable buyers. Its price index is unavailable if any resource is unpriced, and never used as a yield forecast. Expiry and exhaustion are distinct from asteroid hazards. No automatic unsurveyed fallback occurs.
- Uncertain survey creation, extraction, transfer or purchase retains the sending intent and blocks further commands. Reconciliation saves fresh fleet/cargo evidence and never replays a non-idempotent write. A reset archives old intentions. Stop prevents new reservations; an already reserved command can settle.
- Navigation that consumes more fuel than its prediction records the confirmed result and requires review. It cannot silently repeat a route that contradicted its model.

## Local verification

All application verification uses in-memory or private temporary SQLite databases and a fake upstream that prevents stray HTTP. The only network research retrieved the public API specification and public documentation. The original `.env`, credentials and game database were not replaced, migrated or used for gameplay.

`tests/Feature/OpportunityTest.php` covers partial pagination, mid-page failure, uncharted traits, price preservation/history, reset isolation, stranding, stale fuel and affordability, measured configuration evidence, detour cost/time, modifier changes, full-load departure, site time, signed deposits, expiry/exhaustion, matching transfer states and capacity, lost transfers, batch trading, falling spread at dispatch, uncertain purchases, Stop and reset, explicit request limits, quote invalidation and shared rate guidance. Existing gameplay tests retain duplicate-job, process ownership, reset, stop, price discrepancy and reconciliation coverage.

Rerun the standard application checks with PHP 8.4, Node 26.13.0 and pnpm 12.16.1:

```sh
PNPM_BIN=/path/to/pnpm bash scripts/verify.sh
php scripts/verify-mvp-worker.php
PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs PLAYWRIGHT_EXECUTABLE=/path/to/chromium node scripts/verify-opportunities-browser.mjs
```

The browser script creates its own fixture and a finite loopback fixture server. It signs in with synthetic credentials, verifies coverage and unknown prices, previews and starts one scout, then one trade, and advances each through real database workers. It verifies 4/4/2 trade batches, acquired-goods costs, fuel and return state. It blocks external browser traffic, inspects 1440px and 390px layouts under a light browser preference, and writes dark screenshots and results under `storage/app/opportunity-evidence/`.

The real-worker check exercises two workers, duplicate queue delivery, restart, a killed sender after simulated upstream commit, non-replay of the uncertain purchase and offline Stop. These tests establish local execution behavior. They do not establish live API acceptance, exact fuel formulas, rounding mechanics, or profitable operation.

Final evidence from this implementation:

| Check | Result | Saved evidence |
| --- | --- | --- |
| Standard application entry point | Passed builds, source-scan determinism, frontend checks, isolated environment checks, PHPUnit, Pint and whitespace | `storage/app/opportunity-evidence/automation-integration-checks.txt` |
| Final backend suite | 253 tests, 1,334 assertions passed; includes 37 opportunity, calibration and integration tests | `storage/app/opportunity-evidence/automation-integration-final-tests.txt` |
| Real database workers | Passed engine-scoped completion, duplicate delivery and uncertain contract-write recovery across five cases | `storage/app/opportunity-evidence/automation-integration-workers.txt` |
| New browser journey | Passed scouting, 4/4/2 trade batching, return/refuel, focus, dark theme and narrow-layout checks; no browser errors | `storage/app/opportunity-evidence/results.json` and screenshots |
| Existing staged automation browser | Passed investigation, staging/reload, mining, contracts, changed-price rejection, End session, restart and Stop; service removed | `storage/app/opportunity-evidence/existing-automation-browser.txt` |
| Input preservation | SHA-256 checks matched all four inputs in both worktrees; source implementation matches its checkpoint | `storage/app/opportunity-evidence/input-preservation.json` |

Desktop and narrow screenshots for both intelligence and operation controls were visually inspected. Verification used PHP 8.4.27, Node 26.13.0, pnpm 12.16.1 and installed Chrome through Playwright. No browser tab, recurring process, live action or publication was left running by these checks.

## Rollout and remaining decisions

The additive migration is `2026_11_01_000001_add_observation_history.php`. It has been applied only to disposable fixtures. Back up the game database and its matching application key, stop and settle existing work, then apply it before using this branch against the existing local environment. Do not restore an old database to undo an upstream action. Code rollback after new run formats requires stopping runs and a compatible database/application pair.

The remaining operating decisions are live trial budgets, accepted extraction headroom and the measurements needed to justify a future model or departure-policy change. CRUISE defaults, opt-in loads with evidence collection, and bounded scouting/trading are confirmed. No numerical live spending limit or risk tolerance was selected for Taylor. Each preview requires the operator's explicit bounds.

Probe deployment, a dedicated surveyor, hauling, refining, production support and wider exploration remain conditional recommendations. Current local fixtures prove their prerequisites and safety boundaries, not an investment case. Authenticated discovery, live trials, ship purchases, live trades, deployment, pushing and PR creation still require separate authorization.

The existing contract and local engine implementation remains intact. Staged mining uses version-2 drafts, including explicitly selected extraction bounds and surveys. Scout/trade approvals use version-3 previews bound to the same engine session, fresh ship/equipment state, quote signatures and shared credit ledger. Engine shutdown/restart invalidates them through the existing gate. No legacy `MiningRunner` or second credit ledger was introduced.

## Source verification

The [served OpenAPI](https://api.spacetraders.io/v2/documentation/json) was retrieved on 2026-11-01 and reports v2.3.0. Survey extraction accepts the original Survey object at `/extract/survey`; transfer responses include `cargo` and `targetCargo`. These contracts were checked before adding their wrappers. The [community travel wiki](https://github.com/SpaceTradersAPI/api-docs/wiki/Travel-Fuel-and-Time) remains the provenance for the optional duration estimate, not an authoritative server formula. The original research and recommendations were preserved byte-for-byte.

## Automation-branch integration verification

The branch correction preserved all five automation commits through `032b822`. It migrated exploration state transitions into `AutomationRunner`/`AutomationStep` and acquisition, survey and travel outcomes into `CreditAccounting`. Contract-specific response filtering, exact contract steps, balance reviews, remaining-spending protection, engine sessions and process-health checks remain active.

The integration adds checks that staged bounded mining completes through the existing investigation/draft/approval path and that expanded operation previews inherit the engine session and cannot execute after End session. A fixture with an unknown engine speed now explicitly omits speed; the automation branch's default fake ship supplies it.

Integration evidence belongs to this checkout's `storage/app/opportunity-evidence/`. The original `/Users/Taylor/Sites/spacetraders` worktree is preserved as the source snapshot. No force checkout of a branch owned by another worktree was used.

## Travel calibration and mining-policy evidence

Every confirmed navigation records the input coordinates and distance, exact ship configuration and hash, flight mode, engine speed, tank capacity, predicted fuel/time, actual tank change, returned fuel-consumption data and server route timestamps. Server departure-to-arrival duration is analyzed only when both timestamps are available. A delayed refresh is not treated as actual travel time. Older records without these inputs remain unknown; they are not silently reconstructed.

The flight deck's **Our travel and mining evidence** section groups current-reset observations by configuration and directed route. It shows sample count, fuel minimum/mean/maximum, prediction error, overruns and available server-duration error. A scatter plot shows fuel error versus distance. Positive errors exceed the prediction; an exceeded fuel prediction already pauses execution for review. Negative fuel changes are retained for diagnosis but excluded from calibration and measured routing.

The same section compares mining departure policies separately by equipment, site, buyer, starting position/fuel, extraction count, headroom and site-time bound. Completed, blocked and stopped runs remain in the record, including retained cargo and missing timing. Cash rates are shown only when timing is available for the whole group. Comparable net rates remain unknown while fuel, inventory, position or wear adjustments are unresolved. This avoids selecting fuller loads from successful sales alone.

**Download evidence summary** exports the group statistics as JSON. Raw observations and action preflight snapshots remain saved for later analysis. The dashboard analyzes the latest 2,000 travel observations and 500 started mining runs, reports those window counts against the full totals, and retains older raw records. No community estimate is relabeled as measured, no fitted formula is promoted automatically, and no load policy is changed automatically. Future calculations should be validated on representative configurations and distances before an explicit opt-in.

## Opus round 1 corrections

The independent review and open tasks are in [opus-qa-security-tasks.md](opus-qa-security-tasks.md). The changes below await a fresh exact-revision review.

Mining acceptance now includes a remote buyer with no observer, 181-second travel and 80-second extraction cooldowns, for both one and three extractions through the unstaged and staged version-2 paths. The default remains one extraction. Every preview rejects extraction headroom outside the hold's capacity, including the single-extraction policy.

SQLite transactions use `IMMEDIATE` mode with WAL, FULL synchronization and the existing busy timeout. A deterministic disk-database regression injects a competing engine write after the gate read. Under deferred transactions this rejects the pending orbit with the same generic local error seen in review; immediate transactions prevent the stale read-snapshot upgrade. The retained historical fixture itself replays successfully, so its exact exception cannot be recovered. Local command exceptions now persist only class, application-relative source location, line and action ID in private `storage/logs/gameplay.log`; no exception message, SQL, credential or response payload is logged.

### Shared API budgets

The public [SpaceTraders rate-limit guide](https://docs.spacetraders.io/api-guide/rate-limits), checked on 2026-11-01, describes IP and account limits and burst capacity. The implementation uses a conservative 1.9 requests/second token bucket and 10 available burst tokens for both host and account. This uses part of the documented burst; it does not assume all published burst capacity is safe. Actual live limiter behavior remains unverified.

The default durable store is `~/Library/Application Support/SpaceTraders/request-budget` for the current macOS user. All cooperating checkouts and databases share it. `SPACETRADERS_REQUEST_BUDGET_PATH` can override it with an absolute private directory, which must be identical for every cooperating web, queue, scheduler and engine process. The engine installer propagates the path. Other OS users, devices, hosts and applications are outside this local budget. Do not delete the state to bypass a pause. A missing, unsafe, corrupt or busy store defers transport.

Account IDs share a hashed bucket across tokens for the same account; unassociated tokens use a token hash. No raw account ID or token is stored. Updates use a private file lock and atomic replacement, and survive process restarts. Deterministic fixtures use an owned temporary store and disable time-based pacing; simulated-clock tests enable the limiter itself.

Headers can reduce sustained rate, burst capacity and available tokens. A reported remaining count with a usable reset timestamp also bounds requests until that reset, independently of local refill. Retry-After accepts seconds or an HTTP date; reset accepts an absolute Unix timestamp or date. Exhaustion adds a safety margin. Explicit account limits pause only that account. IP limits, unidentified cloud 429s and 5xx pause the host; 502 has a minimum three-minute pause. Ambiguous timestamps fall back to conservative backoff. Header corrections never raise the configured allowance automatically.

Writes may use the final token, their preflight reads reserve one token, and discovery leaves three tokens. Admission can wait at most 0.75 seconds; longer waits produce a durable pre-transport deferral. This is reserved-capacity priority, not a promise of strict FIFO scheduling. Identical successful GETs can be reused for at most two seconds within the same agent-locked tick/preflight scope. Every write clears that cache, including rejected and uncertain writes, and each new execution scope starts empty. Pre-write state is not reused from an earlier tick, approval or queued execution.

The dashboard evidence panel and JSON export include host and current-account request admissions, responses, priority counts, 429s, server errors, deferrals, delays, available capacity and rolling 60-second usage. Counts describe local transport admissions; a connection failure or deadline can prevent an admitted request from reaching the server. Unrelated accounts' individual statistics and identities are not exposed.

A 429 received after a write is sent remains uncertain and requires reconciliation. The public guide does not establish a nonexecution guarantee. Price freshness remains 180 seconds for new approvals and executable purchases/sales. Remote scouting alone does not guarantee that a later trade will have fresh buyer prices; an observer or a separately approved visit may still be necessary.

### Correction verification

The final backend suite passes 275 tests with 1,425 assertions. Standard build, application, environment and formatting checks passed, with three additional isolated engine-browser passes and both real-worker suites. The two-worker verification also found a first-heartbeat unique-key race; worker and scheduler heartbeats now use atomic upserts, with a deterministic regression test.

In the same synthetic scout/trade journey, tick-scoped read reuse reduces calls from 236 to 227 and market reads from 70 to 61, retaining the same 18 writes. Separate tick, approval and dispatch reads remain fresh by design. These counts are fixture measurements, not live throughput estimates. The evidence panel exports the persisted budget usage and renders it at desktop and narrow widths.

Evidence and review responses are under `storage/app/review-fixes/` and `docs/opus-qa-security-tasks.md`. The task file retains Opus's original changes-requested verdict until independent review of the correction commit.
