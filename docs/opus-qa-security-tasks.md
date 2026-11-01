# Opus QA and security review: exploration, calibration, loads, scout/trade, surveys/transfers

| Item | Value |
| --- | --- |
| Checkout | `/Users/Taylor/.t3/worktrees/spacetraders/feature-local-automation` |
| Branch | `feature/local-automation` |
| Reviewed head | `ae78240e4a4a04543d9cc5e4fd9ae1406a35227e` |
| Primary diff base | `032b822dffab2353189ea683eea81e707702356b` |
| Context range | `88bc0262b65b70e611e2fdc37e64a5225591d5c0..ae78240` |
| Reviewer | Claude Opus 5.5, single independent reviewer with no delegation |
| Date | 2026-11-01 |

## Verdicts

| Stage | Verdict | Basis |
| --- | --- | --- |
| **QA** | **CHANGES REQUESTED** | T1 is a confirmed regression in the shared extraction preflight. T2 is an unexplained intermittent failure in the existing staged-automation browser check. T3 is the new per-token rate-limit requirement, which is not implemented. |
| **Security** | **No confirmed vulnerabilities in scope** | Authorization, validation, serialization, export, storage, queue and dependency checks found no exploitable issue. Limits are listed below. This is not final acceptance, because QA has not passed. |
| **Overall** | **Not accepted at `ae78240`** | Fix T1–T5, then send a new review at the new exact revision. |

The user added a requirement during this review: account for SpaceTraders per-token API rate limits, staying clear of 429s while making full use of the allowance. It is recorded as T3.

## Scope

- R01–R08 from `docs/exploration-fuel-and-opportunity-recommendations.md`. Also the research document and `docs/exploration-implementation.md`.
- Confirmed decisions:
  - Conservative CRUISE is the default; other modes and unusual ships stay blocked.
  - Our own travel data is collected for later validated calculations.
  - One extraction stays the default; bounded loads are opt-in with comparable evidence.
  - Bounded scout and trade runners require explicit budgets.
  - No live activation or purchases.
- Integration with the shared manual and automation command path:
  - preview, start and stop; engine session gate
  - spending, loss and inventory caps; reserves
  - reset invalidation; deadlines
  - uncertain-write reconciliation
  - contracts and credit attribution
- Diff reviewed: `032b822..ae78240` (82 files; source, tests, scripts, migration, UI, built assets).

## Executed checks and evidence

All execution used in-memory SQLite or owned temporary fixture databases and the synthetic `FakeSpaceTraders` provider. Stray HTTP was prevented, browsers ran headless in isolated contexts, and loopback fixture servers were ephemeral. No `.env` exists in this checkout, and none was created. The original game database, credentials, Herd app and the dirty `/Users/Taylor/Sites/spacetraders` checkout were not touched. No authenticated or live SpaceTraders request was made. **All evidence is synthetic.** None of it shows live API behavior, exact fuel formulas or profitability.

Evidence files are in `storage/app/opus-review/`, which git ignores.

| Check | Result | Evidence |
| --- | --- | --- |
| `scripts/verify.sh`: build determinism, store/gameplay/time/tutorial/guided checks, script syntax, environment and Ignition controls, PHPUnit, Pint, `git diff --check` | **Pass.** 253 tests / 1,334 assertions, matching the parent's claim. Rebuilt assets identical; tree unchanged. | `verify-sh.txt` |
| Review probes, synthetic (P1–P4) | P1 **defect confirmed**, with a passing control. P2 and P3 **confirmed** low defects. P4 access controls **pass**. | `ReviewProbeTest.php`, `probe-run-1.txt` |
| `scripts/verify-opportunities-browser.mjs` with my own artifact directory | **Pass.** Coverage and unknown prices, scout visit, 4/4/2 trade, export, 1440/390 dark layout, no browser errors. Screenshots inspected. | `browser-opportunities/` |
| `scripts/verify-automation-browser.mjs`, run 1 | **FAIL.** Contract run blocked, "Local execution failed" on `orbit` (see T2). Service removed. | `browser-automation/` (result.json, failure.png, server.log) |
| `scripts/verify-automation-browser.mjs`, run 2 | Pass. The unexplained retry pass does not close T2. Service removed. | `browser-automation-2/` |
| `scripts/verify-automation-worker.php` | **Pass.** 5 cases: complete, contract accept/deliver/fulfill, buy. | `automation-worker.txt` |
| `scripts/verify-mvp-worker.php` | **Pass.** Includes non-replay of uncertain intent and offline Stop. | `mvp-worker.txt` |
| Runtime route inventory: `route:list --json -v`, isolated boot with in-memory DB and no config/route cache | 50 routes; this diff adds no routes. | `routes.json` |
| Upstream request accounting for the synthetic scout + trade journey | 236 calls for 18 writes: 70 market, 48 `GET /`, 47 `/my/agent`, 40 ship reads | Fixture `world.json` (temp dir listed in `browser-opportunities/results.json`) |
| `composer audit --locked`, `pnpm audit --prod` | No advisories. Dependencies unchanged since `032b822`. | Command output in this review |
| Input preservation | All four untracked inputs match their recorded SHA-256 values; `git status` unchanged | `inputs-before.sha256`, `status-before.txt` |
| launchd cleanup | No `io.spacetraders.engine.*` job or LaunchAgent left behind | `launchctl list` |

Limits:
- SQLite only, so no MySQL locking evidence.
- Single-Mac coordination only.
- Measured-mode behavior was tested only with synthetic samples.
- The browser checks cover scout/trade and the staged mining/contract journeys. There is no browser journey for survey, transfer or bounded loads; those are covered by feature tests and source review.

## Blocking tasks

- [ ] **T1. Extraction preflight requires a fresh buyer quote, blocking mining when the buyer is not the site.** High · Confirmed · QA regression
  - **Source:** `app/Gameplay/CommandRules.php`, `case 'mine'/'mine_survey'`: new `if ($run) { $this->planner->route(...) }`. `MiningPlanner::evaluateRoute()` throws when the buyer quote is older than 180 s. `AutomationRunner::refreshBuyer()` only refreshes a market where an owned ship is present. At `032b822` the mine preflight had no buyer-quote check.
  - **Entry points:** `POST agents/{agent}/runs/{run}/start` (unstaged path), staged `drafts/{draft}/preview` then start, and every automated `mine`/`mine_survey`.
  - **Reproduction:** `ReviewProbeTest::test_p1_default_single_extraction_with_remote_buyer_after_181_seconds`. Setup: miner docked at the buyer PORT, mining at ROCK, no other ship at PORT, default `max_extractions=1`, then 181 s at the site.
    - Result: the `mine` action is rejected and the run blocked with "Buyer prices are unknown or stale…", leaving the ship at the site.
    - Control (`..._within_180_seconds_extracts`): the same route at 60 s extracts.
  - **Why it matters:**
    - Any real route where travel plus cooldown exceeds 180 s now fails before extraction.
    - Opt-in loads with a remote buyer cannot go much past two extractions, because each cooldown ages the quote.
    - That defeats R04 and leaves runs blocked mid-load.
    - Current tests use `market = site`, so the buyer is always observed.
  - **Required fix:**
    - At extraction, keep rechecking hazards, capability, cooldown, headroom and the fuel/continuation route.
    - Do not require a buyer quote that cannot be refreshed remotely.
    - Treat a stale buyer as a reason to depart for the sell phase, or a policy-defined pause, not as a reason to block extraction. Check prices at sale, as already done.
    - Keep the unknown-output-buyer check (`known_buyers`).
  - **Acceptance:**
    - Feature tests with a remote buyer and no observer, more than 180 s elapsed: default single extraction completes the cycle, and `max_extractions=3` with an 80 s cooldown departs or completes without a stale-quote block.
    - Cover both the unstaged and the staged version-2 paths.
    - A changed hazard still blocks before the write.
    - Full suite passes.

- [ ] **T2. Intermittent "Local execution failed" in the existing staged contract journey.** Medium · Suspected (1 of 2 runs failed) · QA
  - **Evidence:**
    - Run 1 of `verify-automation-browser.mjs`, contract run #4: three `buy` actions confirmed, then action #10 `orbit` went `rejected` with "Local execution failed. Reconcile before further commands."
    - The run blocked and the script timed out ("Timed out waiting for offline state").
    - Fixture: `/var/folders/…/spacetraders-fixture-a00353f4c7be`, kept for diagnosis.
    - The failure is in preflight, because the state went from pending to rejected rather than uncertain.
    - Run 2 passed. The parent's earlier evidence also passed.
  - **Source:** `ShipCommandExecutor::execute()` `catch (\Throwable)` discards the exception class and location, and no logs were written. That makes the defect impossible to diagnose from evidence. This diff changed shared preflight (`CommandRules::inspect`), so a regression has not been ruled out.
  - **Why it matters:** contracts and the shared command path must be preserved. An unexplained, unlogged preflight crash can block live runs.
  - **Required fix:**
    - Record the sanitized exception class and source file/line in a local log or a non-sensitive action field: no tokens, no payloads.
    - Find and fix the root cause.
  - **Acceptance:**
    - The root cause is identified and named.
    - A regression test covers it if reproducible.
    - `verify-automation-browser.mjs` passes 3 consecutive isolated runs with no launchd service left behind.

- [ ] **T3. Per-token SpaceTraders rate-limit accounting that avoids 429s and uses the available allowance.** Medium · Confirmed gap against the new requirement · QA plus resilience
  - **Current state:**
    - `RequestBudget` keeps one key, `request_at`, in each application database, with a fixed 650 ms spacing (`config/gameplay.php`).
    - `SpaceTradersClient` defers that single global key after a 429, a 5xx, or `X-RateLimit-Remaining: 0`.
    - The repository research (`docs/early-game-api-research.md`) records IP and account limits of 2 req/s, burst 30 per 60 s.
    - The MVP plan called for an app-wide budget *and* account-scoped budgets.
  - **Concrete gaps:**
    1. **No per-token state.** One token's account-type 429 defers every agent. Traffic on the same token from elsewhere is not visible to the budget. `X-RateLimit-Type` is ignored.
    2. **The budget is per database, not per IP/host.** Two checkouts or databases on this Mac (for example this worktree and the original checkout) each get a full budget against one IP limit.
    3. **The allowance is underused.**
       - The fixed 650 ms spacing is about 77% of the sustained rate.
       - Burst capacity is never used.
       - `X-RateLimit-Limit-Per-Second`, `-Limit-Burst`, `-Remaining` and `-Reset` are not used to adapt, except for `Remaining == 0`.
    4. **Reads dominate.** The synthetic scout + trade journey made 236 calls for 18 writes. `GET /` (reset guard) and `/my/agent` were each called about once per write, and ship and market reads repeated between `advance()` and `CommandRules::inspect()` in the same tick.
    5. **No priority.** Discovery, investigation and sync compete equally with time-critical run writes such as sell batches and post-arrival actions.
    6. **No telemetry.** There is no record of request count, 429s, deferral time or achieved rate per token and host, so the limits cannot be tuned from evidence.
  - **Required fix:**
    - A host-wide limiter in a store shared by every checkout and database on this Mac for the IP limit, plus a per-account limiter keyed by a hash of the account or token for the account limit. Never key it on a raw token.
    - Token buckets with a conservative sustained rate and partial burst use.
    - Header-driven correction with a safety margin.
    - Type-aware 429 handling: IP limits defer everyone; account limits defer only that token.
    - Priority lanes: run writes, then run-critical reads, then discovery and sync.
    - Reuse identical reads inside one tick or preflight within a short window, without weakening the pre-write state checks.
    - Persisted, sanitized usage telemetry.
    - Keep "budget contention before transport returns the intent to pending" unchanged.
  - **Acceptance:**
    - Fake-header tests:
      - an account 429 on agent A does not delay agent B;
      - an IP 429 delays both;
      - a simulated 60 s window never exceeds the configured sustained and burst ceilings;
      - a restart preserves the budget;
      - two databases or checkouts share the host limiter.
    - The number of upstream requests per scout and trade cycle drops measurably, reported before and after.
    - Existing uncertain-write and request-deferral tests still pass.

- [ ] **T4. Measured fuel mode accepts zero-consumption samples between distinct waypoints.** Low · Confirmed · QA (calibration correctness)
  - **Source:** `FuelPlanner::units()` uses `where('data->actual_fuel', '>=', 0)->max(...)`. `EvidenceAnalysis` treats 0 as valid.
  - **Reproduction:** `ReviewProbeTest::test_p2_*`. With `SPACETRADERS_ROUTE_EVIDENCE=measured` and 0-fuel samples on both directed edges, `leg()` returns `outbound_fuel=0` and `required=2`.
  - **Why it matters:** CRUISE between distinct waypoints always consumes fuel. A stale `before_state` or a server anomaly could authorize an under-fuelled leg in measured mode. The fuel discrepancy check only catches this after the ship has moved.
  - **Required fix:**
    - For distinct waypoints, treat `actual_fuel < 1` as invalid, and also any sample that disagrees with a reported `fuel.consumed`.
    - Exclude invalid samples from measured routing and count them as invalid in the analysis.
  - **Acceptance:** a feature test shows zero samples are rejected by measured routing and counted under `invalid_fuel_samples`.

- [ ] **T5. The unstaged mining preview accepts extraction headroom above cargo capacity when `max_extractions=1`.** Low · Confirmed · QA
  - **Source:**
    - `AutomationRunner::prepare()` validates headroom only when `max_extractions > 1`.
    - `MiningPlanner::next()` then blocks at the site because `capacity - units < headroom`.
    - The staged `compose()` path already rejects headroom above capacity.
  - **Reproduction:** `ReviewProbeTest::test_p3_*`. A preview with headroom 500 on a 40-unit hold is accepted. The run would travel to the site, spending fuel, and then block.
  - **Required fix:** reject headroom above capacity whatever the extraction count, matching `compose()`.
  - **Acceptance:** a feature test shows the unstaged preview rejects it, and the staged behavior is unchanged.

## Decisions needed (not blockers)

- [ ] **D1. A 429 or 5xx on a write is marked uncertain** (`SpaceTradersApi::call()`). That is safe, but every rate-limited write then needs manual reconciliation. Taylor should decide whether a documented, header-bearing application 429 counts as definitely not executed and can be retried after `Retry-After`. Infrastructure 429/502 responses without headers would stay uncertain. This interacts with T3.
- [ ] **D2. The 180 s quote freshness limits what scouting and trading can do.**
  - Prices from a scout visit expire before any later trade preview can use them.
  - Trades effectively need an owned ship parked at the buyer.
  - Options: keep this conservative policy and say so in the UI; or separate planning-age limits from the dispatch freshness checks (dispatch checks stay strict at the source).

## Optional enhancements

- [ ] **O1.** Overlap extraction cooldown with travel when the departure policy allows (R04). This is not implemented and not required.
- [ ] **O2.** `FuelPlanner::leg()` runs one `market_listing` query per fresh fuel market, and is called repeatedly by `OpportunityAssessor::report()` on every dashboard render. This is acceptable at the current scale; batch the listings if the market count grows.
- [ ] **O3.** The browser scripts leave their owned temporary fixture directories under the system temp folder. Optionally remove them on success.

## Security assessment detail

Security evidence is listed separately from the QA evidence above.

| Component / entry point | Disposition |
| --- | --- |
| Route inventory (50 runtime routes, including HEAD) | Tested by isolated boot. No new routes in the diff. Every `agents/{agent}/*` game POST has `auth` and `throttle:60,1`. `GameplayRequest`/`LocalAutomationRequest` return 404 for a non-owner. |
| `runs/preview` with `strategy=scout/trade` and manual `commands/preview` with `transfer/survey/mine_survey` | Tested (P4): another user gets 404, a guest gets 401 or a login redirect, and no run is created. Validation enforces symbol regexes, integer bounds, `max_visits=1` and the `strategy` allow-list (`contracts` cannot be injected). |
| Version-3 previews and the engine gate | Source-reviewed and covered by tests. Engine session bound at preview and rechecked at start, enqueue and execute. The unexplained-balance gate applies when the engine is enabled. |
| Transfer target | Source-reviewed. The target must be another ship owned by the same agent and not an active run's ship. Both ships' state, cooldown and capacity are verified, and the receiving cargo is checked after the write. |
| Signed surveys | Source-reviewed. The request body comes from the stored signed object, scoped to agent and reset, never from client input. A signature conflict raises an uncertain error. |
| Inertia `intelligence` props and evidence export | Source-reviewed. Owner-only page; contains only agent-scoped observations and hashes; no token (Agent `$hidden`). The export is client-side JSON with a sanitized filename and no server route. |
| Frontend rendering | Source-reviewed. No `v-html` or `innerHTML` in `resources/js`. |
| Ignition and debug endpoints | Tested by `verify-environment.php`: 404 in the template, local-debug and production controls. |
| Storage and queue | Source-reviewed. The migration is additive. Queue payloads carry IDs only. Browser checks verified real database workers and duplicate-delivery and uncertain-write handling. |
| Dependencies | Lockfiles unchanged since `032b822`. `composer audit` and `pnpm audit --prod` report no advisories. |
| Rate-limit resilience | See T3. This is an availability and robustness gap, not a confidentiality or authorization vulnerability. |

Blocked or not assessed:
- Live SpaceTraders behavior (not authorized).
- MySQL locking (not used).
- Multi-host coordination (out of scope).

## Reproducing the review probes

```sh
cd /Users/Taylor/.t3/worktrees/spacetraders/feature-local-automation
php vendor/bin/phpunit --display-all-issues storage/app/opus-review/ReviewProbeTest.php
```

The probes write their findings to stderr. They are review artifacts under the git-ignored `storage/app` and are not part of the application suite. After fixing, the parent should turn T1, T4 and T5 into proper regression tests.


## Parent response to round 1, pending independent re-review

The original verdict and unchecked reviewer tasks above are preserved. These are implementation responses, not Opus acceptance.

| Task | Change | Local evidence |
| --- | --- | --- |
| T1 | The approved mining itinerary separates physical continuation checks from executable price freshness. It can finish a bounded load after the remote quote ages, then refresh sale/refuel prices at the buyer. Capability, hazards, cooldown, headroom, protected credits, return fuel and unknown-output checks remain. Both previews explain this policy. | Four regression cases: unstaged and version-2 staged runs, one and three extractions, remote buyer without observer, 181-second travel, 80-second cooldown. All complete and sell their attributable yields. Existing hazard-change regressions pass. |
| T2 | Reproduced the generic rejected orbit with a real concurrent SQLite write during the engine-gate read transaction. `IMMEDIATE` transactions prevent the snapshot-upgrade race. Sanitized local errors persist class/location/action ID in private gameplay.log, including from workers whose output is disabled. | Deterministic disk-database red/green test. Retained historical state replays successfully; its original exception was swallowed, so its precise cause cannot be recovered. Three fresh isolated engine browser runs passed without console errors, all with serviceRemoved=true. They ran concurrently on separate owned databases as a contention stress check. |
| T3 | Private durable host-wide buckets shared across cooperating checkouts and separate hashed account buckets, partial burst and 95% sustained allowance, response-header corrections, account/IP-specific pauses, reserved write/preflight capacity, short tick-local read reuse, sanitized dashboard/export usage. Relative paths and unsafe/unavailable stores defer before transport. | Simulated 60-second rate/burst test; header and reset-window tests; A/B account and IP tests; restart and separate-process/database tests; contention and no-replay tests. Scout/trade fixture calls fall from 236 to 227 with 18 writes unchanged; market reads fall from 70 to 61. |
| T4 | A shared sample predicate excludes distinct-waypoint consumption below one and mismatched reported consumed fuel from measured routes and analysis statistics; raw samples remain. | Negative, zero, mismatched and malformed-consumption cases; valid directed/configuration/reset samples remain usable. |
| T5 | Both preview paths reject extraction headroom outside 1..cargo capacity for every extraction count. | Dedicated unstaged and staged single-extraction rejection tests. |

The two-worker check also exposed a first-heartbeat race in read-then-insert `game_runtime` writes. Worker and scheduler heartbeats now use atomic upserts. A targeted test reproduced the unique-key failure before the change. The complete five-case worker verification then passed with no failed jobs.

Latest local results:

- PHPUnit: **275 tests / 1,425 assertions**, passing after the final path guard. Standard `verify.sh` passed at 274 / 1,424 immediately before that guard/test was added; the full suite and formatting were rerun afterward.
- Build determinism, local-time/store/gameplay/tutorial checks, isolated environment and Ignition controls, Pint and diff whitespace: pass.
- Existing automation browser: three fresh isolated passing runs at `storage/app/review-fixes/browser-final-{1,2,3}/`, 1440/390/320 widths, no console errors, owned services removed.
- Scout/trade browser, evidence JSON and request-usage panel: passing fixture evidence under `storage/app/review-fixes/browser-budget/` and a final screenshot rerun under `browser-budget-final/`.
- Worker suites: five automation cases and MVP duplicate/non-replay/offline-stop checks pass.
- Detailed logs and red/green probes: `storage/app/review-fixes/`. All are synthetic, use owned databases and prevent stray HTTP. No authenticated API access or gameplay occurred.
- All four original input documents retain their recorded SHA-256 values. The dirty source checkout was not changed. No service remains installed.

D1 retains uncertain-write reconciliation because the public guide does not guarantee nonexecution. D2 retains the 180-second executable-price policy; the remaining observer/visit limitation is documented. Neither decision silently relaxes live risk or authorizes a live run.
