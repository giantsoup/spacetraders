# Opus QA and security review: exploration, calibration, loads, scout/trade, surveys/transfers

Current status: **Accepted for local fixture scope** by Claude Opus 5.5 at `f56513bad2db946ce83ca29e88df30eb30a8e7bc`. All QA/security blockers T1–T6 are closed. See [round 3](#round-3-independent-re-review) for the independent evidence and live gate. Earlier verdicts below are preserved as review history.

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

---

## Round 2: independent re-review

| Item | Value |
| --- | --- |
| Reviewed head | `05910dca4ff4a530c5b823daa9b68f95efcd2775` (`Fix mining continuation and coordinate local API budgets`) |
| Correction base | `ae78240e4a4a04543d9cc5e4fd9ae1406a35227e` (round 1 head) |
| Overall implementation base | `032b822dffab2353189ea683eea81e707702356b` |
| Reviewer | Claude Opus 5.5, single independent reviewer with no delegation |
| Date | 2026-11-01 |

### Round 2 verdicts

| Stage | Verdict | Basis |
| --- | --- | --- |
| **QA** | **CHANGES REQUESTED** | T1, T2, T4 and T5 are closed. T3 is closed except for one confirmed regression it introduced: T6. Lock contention on the shared host budget, *after* a write has succeeded upstream, turns that write into `uncertain` and blocks the run. |
| **Security** | **No confirmed vulnerabilities in scope** | The new host store, the account hashing, the log line, the usage telemetry and the export were reviewed and probed. No token is written to disk and no cross-user exposure was found. See below. |
| **Overall** | **Not accepted at `05910dc`** | Fix T6, then send a new review at the new exact revision. Everything else is acceptable for local fixture scope. |

### Round 2 executed evidence

All of it is synthetic. Every check ran against owned temporary SQLite or in-memory databases, the `FakeSpaceTraders` provider with stray HTTP prevented, and owned loopback servers.
- No authenticated or live SpaceTraders request.
- No `.env`, game database, credentials or Herd app touched.
- `/Users/Taylor/Sites/spacetraders` not modified.

Evidence is in `storage/app/opus-review-r2/`, which git ignores.

| Check | Result | Evidence |
| --- | --- | --- |
| `PNPM_BIN=… bash scripts/verify.sh` (build determinism, store/gameplay/time/tutorial/guided checks, environment and Ignition controls, script syntax, PHPUnit, Pint, `git diff --check`) | **Pass.** 275 tests / 1,425 assertions. Tree unchanged after the rebuild. | `verify-sh.txt` |
| Round-1 probes rerun at HEAD | P1 now extracts after 181 s with a remote buyer and no observer. The probe "fails" only because it asserted the old blocking behavior, so this inversion is the expected fix. P1 control pass. P2 measured routing rejects 0-fuel samples. P3 preview rejects headroom 500. P4 access controls pass. | `round1-probes-at-head.txt` |
| Round-2 probes (`ReviewProbeR2Test.php`) | **P5: T6 confirmed.** The upstream orbit executed (1 write, ship `IN_ORBIT`), but the action became `uncertain` ("Could not save upstream budget…"). P5 control: the same write is `confirmed` with no contention. **P5b:** 3 real processes sharing the host store, 1,200 `observe()` calls, 207 and 296 busy-lock failures in two runs; this is an aggressive stress case. P9 is informational (see D3). | `probe-run-1.txt`, `probe-run-2.txt` |
| `verify-automation-browser.mjs`, **3 serial isolated runs**, each with its own `QA_ARTIFACT_DIR` | **3/3 pass.** `passed=true`, `errors=[]`, `serviceRemoved=true`. No launchd job after each run. Screenshots inspected; dark theme at 1440/390/320. | `browser-automation-{1,2,3}/`, `browser-serial.txt` |
| `verify-opportunities-browser.mjs` | **Pass.** Scout and 4/4/2 trade journey, evidence export, API-usage panel; dark layout at 1440/390 inspected. | `browser-opportunities/` |
| `scripts/verify-automation-worker.php` | **Pass.** 5 cases: complete, buy, contract accept/deliver/fulfill. | `automation-worker.txt` |
| `scripts/verify-mvp-worker.php` | **Pass.** Duplicate delivery, non-replay of uncertain writes, offline Stop, no failed jobs. | `mvp-worker.txt` |
| Inputs and cleanup | The four untracked inputs match `/tmp/spacetraders-preserved-docs.json`. `git status` is unchanged apart from this report. `launchctl list` shows no SpaceTraders job and `~/Library/LaunchAgents` has no SpaceTraders plist. | `inputs-before.sha256`, `status-before.txt` |

Reproduce the probes:

```sh
cd /Users/Taylor/.t3/worktrees/spacetraders/feature-local-automation
php vendor/bin/phpunit --display-all-issues storage/app/opus-review-r2/ReviewProbeR2Test.php
```

### Round 1 task closure

- [x] **T1 closed.**
  - **Source:** `CommandRules::inspect`, `mine`/`mine_survey` (`app/Gameplay/CommandRules.php:135-152`) still checks, before the write:
    - capability;
    - a fresh hazard read of the waypoint;
    - continuation fuel and protected credits through `FuelPlanner::miningLeg`;
    - approved headroom;
    - cooldown and free cargo.
  - It no longer requires a buyer quote that cannot be refreshed remotely.
  - `MiningPlanner::next()` still blocks on outputs with no known buyer (line 125). The sell and refuel steps still use the fresh quote at the buyer.
  - Navigation in a mining run is limited to the approved site or buyer, in CRUISE, within the same system.
  - **Tests:** the new 4-case data-provider test (unstaged and staged v2, max 1 and max 3, 181 s travel, 80 s cooldown) passes. The inverted round-1 P1 probe confirms the behavior. The existing hazard-change regressions pass in the full suite.
- [x] **T2 closed.**
  - **Root cause:** a DEFERRED SQLite transaction in WAL mode that reads the engine gate and then writes fails with a busy-snapshot error (SQLITE_BUSY_SNAPSHOT) if another connection commits between the read and the write. `busy_timeout` cannot recover from that. This is a known SQLite behavior and is consistent with a rejected `orbit` during preflight.
  - `transaction_mode=IMMEDIATE` (`config/database.php:47`) is supported by the installed Laravel `SQLiteConnection`.
  - `SQLiteConcurrencyTest` commits a real competing write on a disk database, giving a deterministic red/green check.
  - The sanitized log carries only class, relative file, line and action ID, with no message or payload, in a 0600 file.
  - The heartbeat `upsert` fix is reasonable.
  - My **3 serial** browser runs all passed. The parent's runs were concurrent, so these serial runs add separate evidence for the acceptance criterion.
- [~] **T3 closed except T6.**
  - **Verified:**
    - host and hashed-account buckets;
    - type-aware 429 handling: account-only pause versus host pause;
    - restart and cross-process sharing;
    - a 60 s simulation within 1.9/s plus burst 10;
    - header correction that only ever lowers limits;
    - reserved write/critical capacity;
    - telemetry with no raw keys;
    - fail-closed handling of relative or unsafe paths;
    - read reuse limited to the agent-locked scope and cleared by any write, including rejected ones.
  - Calls fell from 236 to 227 (−4%). The criterion asked only for a measurable drop, so this meets it, but the gain is small; see O4.
  - Pacing is disabled in fixtures, which is why the panel shows 3.78 req/s. That is disclosed.
- [x] **T4 closed.** `FuelPlanner::validSample` is shared by measured routing and the analysis. The data-provider cases cover 0, negative, mismatched and malformed `reported_consumed`. P2 rerun confirms it.
- [x] **T5 closed.** Both `prepare()` (`AutomationRunner.php:221`) and `compose()` (`:356`) reject headroom outside 1..capacity. Unstaged and staged tests exist, and the P3 rerun confirms it.
- **D1 and D2:** I accept the documented decisions. Neither weakens live safety.

### Round 2 blocking task

- [ ] **T6. Host-budget lock contention after a successful write marks the write uncertain and blocks the run.** Medium · Confirmed (deterministic probe plus real multi-process stress) · QA regression introduced by the T3 fix
  - **Source:**
    - `app/Gameplay/RequestBudget.php:184`: `change()` takes the shared lock with `flock(LOCK_EX | LOCK_NB)` and throws `RequestDeferred('…busy.')` immediately if the lock is held.
    - `observe()` (`:68`) now runs after **every** response, not only after 429/5xx as at `ae78240`.
    - `app/Services/SpaceTradersClient.php:67-71` turns any failure in `observe()` into `GameException('Could not save upstream budget. Reconcile before another write.', uncertain: true)` for non-GET requests.
    - `ShipCommandExecutor::execute` then marks the `sending` action `Uncertain` and blocks the run, even though upstream returned 2xx and the body was valid.
  - **Contenders on the same lock:**
    - any other worker's `reserve()`/`observe()`, including other checkouts, which is now by design;
    - every dashboard poll: `usePoll(5000…'intelligence')` (`resources/js/Pages/Agent/Dashboard.vue:99`) → `EvidenceAnalysis::report` → `RequestBudget::usage()` (`app/Gameplay/EvidenceAnalysis.php:59`), which takes the same lock and rewrites the file every 5 s.

    An operator watching a running automation is therefore a regular contender.
  - **Reproduction:**
    - `ReviewProbeR2Test::test_p5_budget_lock_contention_after_successful_write_marks_it_uncertain`: the upstream orbit is applied (`writes=1`, `IN_ORBIT`) and the local action is `uncertain`. The control without contention confirms.
    - `test_p5b_real_multi_process_contention_rate`: 3 OS processes on one store got 207/1,200 and 296/1,200 immediate busy failures. That is a stress rate, not a live estimate, but a nonzero rate is enough for long runs to hit it.
  - **Why it matters:**
    - No write is replayed, because the failure happens after transport, so it fails safe.
    - But each occurrence discards a valid response: credits, cargo, transaction. It needs manual reconciliation and stops automation.
    - That is the same class of availability failure T3 was meant to remove.
    - It contradicts `docs/exploration-implementation.md:135` ("A … busy store defers transport") and README:143, which describe busy only as a pre-transport deferral.
  - **Required fix:**
    - Acquire the budget lock with a bounded blocking wait. For example, retry `LOCK_NB` with short sleeps up to about 1–2 s, or use `LOCK_EX` under a short timeout. The critical section is a few milliseconds.
    - After a 2xx write, telemetry or budget persistence failure must not discard the confirmed result. Record it best-effort and log it sanitized.
    - If a 429/5xx pause cannot be persisted, keep the existing uncertain semantics for that non-2xx response. Also block further transport for this process, in memory, for that interval.
    - Make `usage()` read-only. Do not rewrite the state on every dashboard poll; a shared lock or a lock-free snapshot read of the atomically replaced file is enough.
    - Correct the docs if the behavior differs.
  - **Acceptance:**
    1. P5 (another holder of `budget.lock` during the post-response `observe()` of a successful write, released within the bounded wait) ends `confirmed` with one upstream write.
    2. A holder that exceeds the bound on a **2xx** write still ends `confirmed`, with a sanitized telemetry-loss log line.
    3. A 429/5xx write that cannot persist its pause stays `uncertain`, and no further transport starts in that process until the pause expires.
    4. The P5b-style 3-process stress test reports 0 failures on post-transport observe.
    5. Pre-transport `reserve()` contention still returns the intent to `pending` (the existing test passes).
    6. `usage()` does not modify `budget.json` (check mtime or content).
    7. Full suite, `verify.sh` and both browser scripts pass.

### Decisions or live gates (not blockers)

- [ ] **D3. `X-RateLimit-Remaining` semantics are unverified live.**
  - `observe()` clamps tokens to `Remaining − 1` and holds `server_remaining` until `Reset`.
  - If the live header reports the per-second pool (for example `Remaining: 1`, with `Reset` about 1 s later), every following request in that window defers, including writes. P9 showed write, critical and discovery all deferred.
  - That would underuse the allowance rather than exceed it, so it fails safe.
  - The public guide could not be fetched in readable form during this review. Before any live activation, confirm the semantics against real headers on a read-only call, or treat `Remaining` as advisory unless it is 0.

### Optional enhancements (not required)

- [ ] **O4.** Calls fell only 4% (236 → 227). The reset guard `GET /` and `/my/agent` still run about once per write. Reusing them within one dispatch, or caching the reset check for some seconds, would cut more.
- [ ] **O5.** The usage panel shows raw floats and epoch seconds (`Available 0.873`, `Blocked until 0`). Formatting `blocked_until` as local time and rounding `available` would make it readable.
- [ ] **O6.** The mining preview reads "Up to 1 extractions per trip": a pluralization issue. Dashboard synchronize runs inside `locked()`, so it gets `critical` priority rather than discovery. That is acceptable, but differs from the round-1 lane wording.
- [ ] **O7.** A corrupt `budget.json` defers everything indefinitely, which is fail-closed. Document how an operator can recover it, distinct from "do not delete to bypass a pause".
- O1–O3 from round 1 remain optional.

### Round 2 security assessment

| Component | Disposition |
| --- | --- |
| Host budget store | Source and test reviewed. Requires an absolute path; directory 0700, owner must equal the effective UID; no symlinks for the directory, state or lock files; file 0600 by atomic temporary-file replace. Raw tokens and account IDs are never written; buckets use unsalted SHA-256 of `account:` or `token:` identifiers. A test showed the token and account strings are absent from the file. The design is single-user and local; other OS users cannot read the store. |
| Account isolation | An account-type 429 pauses only that bucket; IP, unknown and 5xx pause the host (tests plus source). A missing `account_id` falls back to the token hash, which is acceptable. |
| Read reuse | The key includes path, query and token, so nothing crosses agents. Reuse exists only inside `locked()`/`guarded()` scopes and is cleared by any write and at scope exit. The client and API are `scoped` singletons, reset between queue jobs and requests. Error responses are not cached. |
| Usage telemetry and export | Owner-only Inertia prop and client-side export. Contains counts, rates and timestamps only, with no keys or hashes. Host totals aggregate every local agent, which is acceptable for the single-operator scope (README:170). |
| `gameplay.log` | Class, relative path, line and action ID only. No exception message, payload or token. File mode 0600 at creation. |
| Engine propagation | `SPACETRADERS_REQUEST_BUDGET_PATH` is passed to the engine environment explicitly. No secret is added to the plist environment. |
| Routes, authorization, dependencies | No route or dependency change since `ae78240`. The round-1 P4 rerun passes. |

T6 is a robustness and availability defect, not a confidentiality or authorization vulnerability.

### Round 2 decision

**CHANGES REQUESTED at `05910dca4ff4a530c5b823daa9b68f95efcd2775`.** The only open blocking task is T6. Once T6 meets its acceptance cases, request a fresh review at the new exact SHA. D3 must be resolved before any live activation, which remains unauthorized.


## Parent response to round 2, pending independent re-review

Opus's round-2 verdict and T6 checkbox remain unchanged above. The next local correction implements T6; this section records evidence for the next review rather than claiming acceptance.

| T6 acceptance case | Implementation and evidence |
| --- | --- |
| Short contention after a 2xx write | Writers retry the nonblocking lock with 5 ms sleeps for at most 1.5 seconds, measured by a monotonic clock. A real competing process holds it for 150 ms; the orbit confirms once, with all response counters recorded. |
| Contention exceeds the bound after a 2xx write | A real holder runs for 1.9 seconds. The valid orbit still confirms once. Its one missing response counter is visible in the test, a private warning records only the HTTP status, and further process transport pauses. Redispatch adds no write. |
| Unsaved 429/5xx pause | The action remains uncertain. An in-memory hold, shared by subsequent budget instances in the same PHP process for that store, blocks all further transport for at least ten seconds or the required response pause, whichever is later. Tests cover 429, 503, a 502 three-minute minimum, Retry-After 90 s and a 2xx exhausted/reset response. The hold expires under a simulated clock. Healthy persisted account pauses retain the existing account isolation. |
| Three-process stress | The suite runs 1,200 actual response recordings across three processes, asserts zero failures and all 1,200 counters present. Opus's original P5b scratch probe also now reports 0/1,200 busy failures. |
| Pre-transport contention | The existing pending-intent test now holds the budget lock before execution rather than during a GET response. It still returns to pending with no mutation, then confirms once after release. |
| Side-effect-free usage | Usage reads the atomically replaced snapshot without locking or writing. A test verifies no store creation for an empty read, unchanged bytes and mtime, and successful reading while the writer lock is held. |
| Application checks | Final standard verification passes **286 tests / 1,491 assertions**, asset build determinism, application checks, environment controls, Pint and diff checks. Browser and worker evidence is recorded below. |

The client treats response-budget recording as an optional post-response operation. Its exception boundary catches budget errors of any class, then retains successful responses and known non-rate-limit rejections. It continues normal game-response validation: a malformed 2xx extraction still becomes uncertain. A regression proves both the valid and malformed cases. Warning failures cannot override an upstream result either.

Successful-result preservation does not erase the failed budget write. It logs the failure without message, token, account ID, payload or SQL, and uses the process-local hold. This unsaved hold is not inherited by other processes or a restart; the durable pre-transport admission still accounts for the attempt. A 429/5xx response whose pause cannot persist retains uncertain-write handling. This is documented explicitly.

Small optional review items were addressed alongside T6:

- O5: usage values round for display and active pause dates use the existing local-time presentation; no active pause is labeled. Exported numeric source values are unchanged.
- O6: the one-extraction preview now uses the singular wording.
- O7: documented a stopped-process, preserved-evidence and cooldown-based corrupt-file recovery procedure. It does not clear action uncertainty or authorize gameplay.
- D3 remains a live validation gate. No authenticated header discovery occurred and no throughput guarantee is claimed. O4 read reduction remains optional; dispatch keeps its fresh reset and balance checks.

Evidence is under `storage/app/review-fixes-r3/`: standard verification log, T6 red/green tests, Opus P5b rerun, both real-worker suites, and browser artifact directories. All calls used synthetic fixtures and owned databases. Original input checksums and cleanup are checked again before the next review.

Final parent reruns for the T6 correction: both browser journeys pass with no console errors, the existing engine journey reports serviceRemoved=true, and both real-worker suites pass. The 390px dark usage panel and mining preview were visually inspected. All four protected input hashes still match. Fresh independent acceptance remains pending.

## Round 3: independent re-review

| Item | Value |
| --- | --- |
| Reviewed head | `f56513bad2db946ce83ca29e88df30eb30a8e7bc` on `feature/local-automation` |
| Correction base | `05910dca4ff4a530c5b823daa9b68f95efcd2775` (round 2 head) |
| Overall implementation base | `032b822dffab2353189ea683eea81e707702356b` |
| Reviewer | Claude Opus 5.5, single independent reviewer with no delegation |
| Date | 2026-11-01 |

### Round 3 verdicts

| Stage | Verdict | Basis |
| --- | --- | --- |
| **QA** | **ACCEPTED** | T6 meets all seven acceptance cases. I reproduced each case independently and added probes for the boundaries the parent's tests do not cover. No regressions in R01–R08 behavior. |
| **Security** | **No confirmed vulnerabilities in scope** | The new log line, process hold, read-only usage path, symlink and corrupt-store handling, and account isolation were reviewed and probed. |
| **Overall** | **Accepted for local fixture scope at `f56513b`** | No blocking tasks remain. D3 still gates any live activation, and live activation remains unauthorized. |

### Round 3 executed evidence

All checks were synthetic: owned temporary SQLite or in-memory databases, `FakeSpaceTraders` with stray HTTP prevented, and owned loopback servers.
- No authenticated or live SpaceTraders request.
- No `.env`, game database, credentials or Herd configuration touched.
- `/Users/Taylor/Sites/spacetraders` not modified.

Evidence is in `storage/app/opus-review-r3/`, which git ignores.

| Check | Result | Evidence |
| --- | --- | --- |
| `PNPM_BIN=… bash scripts/verify.sh` | **Pass.** 286 tests / 1,491 assertions, build determinism, all application, time and environment checks, Ignition controls, Pint, `git diff --check`. Tree unchanged after the rebuild. | `verify-sh.txt` |
| Round-2 probes at HEAD | **P5 inverted as expected.** The in-process lock is held for the whole post-response record, longer than the 1.5 s bound. The orbit is now `confirmed` with 1 upstream write. The probe's old "uncertain" assertion is what fails, and that failure is the fix. **P5b: 0 / 1,200** busy failures across 3 real processes (was 207 and 296). P5 control and P9 unchanged. | `r2-probes-at-head.txt` |
| Round-3 probes (`ReviewProbeR3Test.php`, 8 tests / 31 assertions) | **Pass.** See the table below. | `r3-probes.txt` |
| `verify-automation-browser.mjs` | **Pass.** 7 steps, `errors=[]`, `serviceRemoved=true`. `launchctl list` shows no SpaceTraders job and `~/Library/LaunchAgents` has no plist. | `browser-automation/`, `browser-automation.log` |
| `verify-opportunities-browser.mjs` | **Pass.** Scout, 4/4/2 trade, evidence export and usage panel; `errors=[]`. | `browser-opportunities/`, `browser-opportunities.log` |
| `scripts/verify-automation-worker.php` | **Pass.** 5 cases. | `automation-worker.txt` |
| `scripts/verify-mvp-worker.php` | **Pass.** Duplicate, non-replay, offline Stop, no failed jobs. | `mvp-worker.txt` |
| Screenshots inspected | Dark theme throughout. Usage panel values are rounded (`0.811`, `3.783`) and an inactive pause shows "No active pause". An active pause is passed as ISO and rendered through `formatLocalTime` in `DataDetails`, so it displays in local time. The mining preview at 390 px reads "Up to 1 extraction per trip" in both the warning and ordered step 2. The cut-off right edge in `api-usage-panel-1440.png` comes from the element crop; the full-page `api-usage-1440.png` lays out correctly. | PNGs listed above |
| Inputs and cleanup | All four untracked inputs match `/tmp/spacetraders-preserved-docs.json`. `git status` is identical before and after, apart from this report. A `queue:listen` running in `/Users/Taylor/Sites/spacetraders` predates this review and belongs to the user's own session; I left it untouched. | `inputs-before.sha256`, `status-before.txt` |

Round-3 probes (`php vendor/bin/phpunit storage/app/opus-review-r3/ReviewProbeR3Test.php`):

| Probe | What it challenges | Result |
| --- | --- | --- |
| R3-1 | A 2xx write whose recording fails, followed by the *next* action in the same process, using a fresh `RequestBudget` instance | First action `confirmed`. The next preview's read throws `RequestDeferred` with no transport. After the hold expires (simulated clock), the second write confirms once, for 2 writes total. |
| R3-1b | An already-approved write that reaches `execute()` during a process hold | Returns to `pending`, `sent_at` null, no upstream write, not uncertain. |
| R3-2 | A known 4xx write rejection (401) whose recording fails | `rejected`, neither `uncertain` nor `confirmed`. The certainty boundary holds: only 429/5xx without a saved pause stay uncertain. |
| R3-3 | HTTP-date `Retry-After` (240 s) and an absurd `Retry-After` (999999) on an unsaved pause | Hold of 230–242 s; the absurd value is capped at 3,600 s. |
| R3-4 | Dashboard evidence with a corrupt `budget.json`, then with a symlinked store directory | `request_usage.unavailable` is shown. Corrupt bytes and lock mtime are unchanged, and nothing is created inside the symlink target. |
| R3-5 | `usage()` while another descriptor holds the writer lock | Returns in under 0.2 s. `budget.json` mtime is unchanged, and the token and account ID are absent from the file. |
| R3-6 | Log contents when the response carries an `Authorization` header and a secret-looking body | The log line contains only `{"http_status":200}`, and `gameplay.log` is mode 0600. |
| R3-7 | A real second process holds the lock for 1.2 s, inside the bound, before a pre-transport `reserve()` | Waited 1.208 s, then succeeded with no deferral. |

### T6 closure

- [x] **T6 closed.** Each acceptance case was independently verified:
  1. **Short contention after a 2xx write confirms once.** Parent test (150 ms real holder); R2 P5 inverted.
  2. **Contention beyond the bound after a 2xx write still confirms.** The parent test's 1.9 s real holder and the R2 P5 indefinite holder both confirm. The sanitized warning carries the status only (R3-6). Redispatch adds no write.
  3. **An unsaved 429/5xx write stays uncertain and further process transport is blocked until expiry.** Covered by parent tests for 429, 503, 502 (180 s minimum), Retry-After 90 and a 2xx exhausted response; R3-3 adds the HTTP-date and cap cases. R3-1 and R3-1b show the hold survives new instances, defers approved writes to `pending` without transport, and releases after expiry.
  4. **3-process stress has 0 failures.** Shown by the new regression and by my rerun of P5b (0 / 1,200).
  5. **Pre-transport contention still returns to pending.** The parent's reworked test passes, and R3-1b covers the same pending path for the process hold.
  6. **`usage()` does not modify the store.** Shown by the parent test and by R3-4 and R3-5. No lock is taken, nothing is written, created or chmodded, and corrupt or unsafe stores degrade to "unavailable".
  7. **Full suite, `verify.sh`, both browser scripts and both workers pass.** All rerun independently at this exact HEAD.
- **Source check.**
  - The `change()` retry is bounded at 1.5 s by `hrtime`. Its critical section only reads, transforms and atomically replaces a small JSON file, so it cannot deadlock against SQLite.
  - `SpaceTradersClient::request` catches any `Throwable` from `observe()`. For 429 or 5xx it throws `uncertain: ! $read`; otherwise it returns the response. Normal body validation then still runs: the parent test shows a malformed 2xx stays uncertain.
  - `recordingFailed()` wraps logging in its own `try`. The static hold key is a hash of the store path, so it contains no secret.

### Remaining items (none blocking)

- [ ] **D3 (live gate, unchanged).** The live semantics of `X-RateLimit-Remaining` and `Reset` are unverified (R2 P9 still shows a `Remaining: 1` window deferring everything). This must be resolved with a separately authorized, bounded, read-only header check before any live activation. It fails safe by underusing the allowance.
- [ ] **N1 (informational, accepted by design).** The process hold is in memory only.
  - Another process, or a restart, does not inherit a pause that failed to save, and a 2xx `Remaining: 0` correction that failed to save is lost to other processes.
  - The durable admission still counts the request, and this behavior is documented in `docs/exploration-implementation.md`.
  - An account-type 429 whose pause cannot be saved holds all accounts in that process. That is more conservative than account isolation, and acceptable.
- [ ] **N2 (informational).** Pre-transport `reserve()` and post-response recording can each wait up to 1.5 s for the lock, which can add latency to a web request under heavy contention. This is bounded and acceptable.
- O4 (read reduction) remains optional. O5, O6 and O7 are addressed and verified above. O1–O3 remain optional. D1 and D2 remain accepted.

### Round 3 security assessment

| Component | Disposition |
| --- | --- |
| Recording-failure log | Writes only `http_status` to `storage/logs/gameplay.log` at mode 0600. No exception message, header, body, token or account ID (R3-6, plus a parent test that a thrown message is absent). Logging failure is swallowed and cannot change the result. |
| Process hold | A static array keyed by `sha256(serialize(path))`. It holds no secret and cannot be influenced by users. It only *blocks* transport, never relaxes it, and is checked before any admission. |
| Read-only usage | No `mkdir`, `chmod`, lock or write. A symlinked directory or state file is refused, and a foreign owner is refused. Corrupt state raises `RequestDeferred` (a `GameException`), which `EvidenceAnalysis` turns into a static "unavailable" message (R3-4). Rename-based writes mean reads never see a partial file. |
| Budget file | Still written through a 0600 `tempnam` file and an atomic rename under the 0700, owner-checked directory. Raw tokens and account IDs are absent (R3-5). |
| Telemetry, export and authorization | The display-only rounding in `EvidencePanel.vue` leaves the exported numbers raw. No route, controller, middleware or dependency changed since `05910dc`. Owner-only access from round 2 still applies. |
| Account isolation | Saved account-type 429 pauses still affect only that account's bucket (existing tests pass). The unsaved-pause fallback is broader, which is conservative. |

No confidentiality, authorization or injection issue was found. T6 is closed.

### Round 3 decision

**ACCEPTED at `f56513bad2db946ce83ca29e88df30eb30a8e7bc` for local fixture scope.** No blocking QA or security task remains. This acceptance covers synthetic and local-fixture behavior only; it guarantees no live mechanics, throughput or profit. Before any live activation, which remains unauthorized, resolve D3 through a separately authorized, bounded, read-only header check, and keep the existing explicit approvals, protected credits, limits and reconciliation in force.
