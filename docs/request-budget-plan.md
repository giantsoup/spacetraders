# Request budget plan: fewer requests per ship

Written 2026-11-17 (UTC). Status: revision 3, after council round 2. No code has changed yet.

## Why

SpaceTraders limits us to 2 requests per second with a burst of 30. All 183 production
budget samples report the limit's scope as **IP address**. The limit is shared by every
process on this host:

- the engine;
- the web dashboard (`AgentDashboardController:122` runs a full sync with discovery);
- anything else using this IP.

About 7,200 requests an hour is the ceiling for the whole fleet. Campaign "arms" are ships
of one agent (agent 2 owns all 12 ships, and arm grants 6–10 use its token), so more arms do
not add budget. Extra accounts would not raise the limit either, and we won't try to get
around it.

The fleet plan (`docs/fleet-expansion-plan.md`) assumed 12–30 requests per ship-hour for a
fleet of 70–300 ships. Measured usage is about 920, so today the budget caps the fleet at
about 7 trading ships. Request efficiency has to come before the v2 buying rule.

## Measured baseline

Production was read only through a copy of its database. The `fleet_metrics` window is
2026-11-17 03:16–03:53Z (36 minutes, the first window after PR #115). GIANTSOUP-1 traded for
the whole window. GIANTSOUP-8 and -9 traded from 03:21–03:22. Four more ships appeared
briefly.

| Measure | Value |
| --- | --- |
| All requests (window) | 1,455 = 1,378 reads + 77 writes |
| Fleet rate | 0.67 req/s average, 0.78 req/s peak over 60 s |
| Requests per active trading ship-hour, all-in | about 920 (1,455 / 1.58 ship-hours) |
| Requests per active trading ship-hour, attributed only | about 600 |
| Requests per completed load | about 290 (1,455 / 5 loads) |
| Writes per load | about 14–15 (72 non-purchase writes / 5 loads) |
| Unattributed (`shared`) | 422 requests (29%), all reads |
| Director and arm passes | 160 in 36 minutes (4.4 a minute), about 480 requests, about a third of all traffic |
| Gap between confirmed actions in a run | median 35 s, p90 183 s; after non-navigate actions, median 21 s |
| Gap from action creation to send | median 5 s, maximum 15 s |
| 429s | 4 in 45.6 hours, all before 2026-11-15 11:23Z |

The `shared` requests are not lost: they come from two specific places.

- **148 single-request rows** are the `resetSchedule` `GET /` in `CampaignDirector::direct()`
  (`:137`), which runs outside `FleetMetrics::as()`.
- **About 270 requests** are, by inference, `refreshBuyer` market reads. They are wrapped in
  `FleetMetrics::as(null, …)` (`AutomationRunner.php:336`), so they are recorded as
  `shared`.

No endpoint is recorded per request. `FleetMetrics::count()` ignores `$path`, and it runs
before send (`SpaceTradersClient.php:96`), so it cannot see response headers.

### Fixture count

This counts one arm's full cycle (position, k buy lots, travel, m sell batches, return,
refuel) against `FakeSpaceTraders`, using a throwaway copy of `ArmDirectorTest`.

| k buys / m sells | Requests | Writes |
| --- | --- | --- |
| 1 / 1 | 102 | 9 |
| 3 / 2 | 134 | 12 |
| 5 / 3 | 166 | 15 |

Each write step reads three things twice. The step is planned in `advance`, then run a few
seconds later in a separate `ExecuteShipCommand` job, under a new lock and scope. Both
halves call:

- `guardReset` (`GET /`): `AutomationRunner.php:212`, `CommandRules.php:27`;
- `GET /my/agent`: `:214`, `:28`;
- `GET /my/ships/{s}`: `:213`, `:29`.

Market reads come on top:

- `refreshBuyer` reads the buyer and source markets on every advance whenever any ship is
  parked there (`:239-248`, `:333`);
- `execute` reads the market again (`CommandRules.php:71`, `:490`, `:522-525`).

The fixture misses about half of live cost: 134 per cycle against about 290 per load. The gap
comes from two things:

- director passes, which cost 3 requests each at N=12;
- advances that send no write (waits, holds, retries). Each one still pays the reset, ship,
  agent and market reads. By subtraction, these are about 25–35% of attributed reads. This
  is an estimate, which phase 0 replaces with measurements.

The fixture runs instantly. Live, each read waits its turn in `RequestBudget`, so cache
timing differs. Live per-load data, not the fixture, decides whether a phase met its goal.

### Facts the design relies on

- **The agent lock serialises the agent.** Every advance and execute for an agent runs under
  `HostLock 'agent-{id}'` (`ShipCommandExecutor.php:24`, `AutomationRunner.php:182`). The
  lock is a non-blocking flock; a collision throws. Each confirm updates the agent row and
  records its credit entry in one transaction (`ShipCommandExecutor.php:443`, `:514`). Under
  the lock, the agent row and the confirmed-delta sum therefore always agree. Only a change
  made outside the engine can break the run credit check or `unexplained_change`
  (`CampaignSafety.php:24-26`), and only a fresh `GET /my/agent` can detect one.
- **`synced_at` doesn't mean a fresh balance today.**
  - `confirm()` sets `credits = data.agent.credits ?? before.credits` and `synced_at = now()`
    for every command (`:419`, `:455`), including navigate, dock and orbit, whose responses
    have no agent.
  - The real agent reads in `advance` (`AutomationRunner.php:214-215`) and `CommandRules`
    (`:28`) update `credits` but don't stamp `synced_at`.
  - Buy, sell and refuel compare `credits − before.credits` with the price, and a mismatch
    blocks the run (`ShipCommandExecutor.php:421-432`).
  - `SpaceTradersApi::agent()` keeps only `symbol` and `credits` and discards `shipCount`
    (`:187`).
- **Quote freshness.** `snapshot_seconds` is 180 (`config/gameplay.php:22`, used in
  `OperationPlanner::quote` around `:248`). `quote_max_age_seconds` is 180 in both
  playbooks, and the validator caps it at 180 (`CampaignPlaybook.php:61`). `quoteLife` needs
  `observed_at + 180 > now + planned loading time` (`CampaignTrade.php:258`). Every buy, sell
  or refuel marks that market's quote `invalidated_by_action` (`AgentSynchronizer.php:145`),
  and `quote()` refuses invalidated quotes. **So one market read per lot at the write market
  is required, and stays.**
- **Reset signals.**
  - `status()` returns only `resetDate`; `resetSchedule()` also returns `next_reset`
    (`SpaceTradersApi.php:163-171`).
  - On a 401, `call()` sets `connection_state = invalid_credential` (`:577`) and throws a
    rejected (not uncertain) `GameException` (`GameplayTest:593`). Nothing archives the
    agent or rechecks the reset at that point.
  - The published error list includes `4113 TokenResetDateMismatchError`
    (`docs/reports/wipe-strategy-evidence-2026-11-14/error-codes.json:84`). Its HTTP status
    is not verified.
- **Enqueue.** `enqueue` creates the pending action inside `DB::transaction` and dispatches
  `ExecuteShipCommand` with `afterCommit()` (`AutomationRunner.php:361-377`).
- **Dispatch.** `dispatch-due` runs every 5 s. Progress clears `last_dispatched_at`, so a run
  steps again on the next tick. A run that makes no progress is advanced at most every 30 s.
- **Scope cache.** `SpaceTradersClient` serves an identical GET from cache for 2 s inside one
  `scope()`, and any write clears the cache (`:57`). Reads paced by `RequestBudget` can
  outlast that 2 s.

## Goals

- **Fixture:** each phase lowers committed ceilings in the regression test. Indicative
  path for k=3, m=2: 134 → about 98 after phase 1 → about 85 after phase 2 → about 80 after
  phases 3–4 → about 55–60 after phase 5. Reaching 45 or fewer would also need "cheaper
  waits" (last section). The phase-0 baseline sets the real ceilings.
- **Live, provisional:** about 200 requests or fewer per active trading ship-hour, all-in
  including `shared` and director traffic, down from about 920. That is about 32 trading
  ships (0.9 × 7,200 / 200). The 70-ship scenario needs about 90 per ship-hour. The real goal
  is set from phase 0 production data before phase 2 starts.
- **Safety:** all existing safety behaviour is kept (next section).

## Safety invariants (must hold after every phase)

1. **Reset.** No write takes effect in a world that has reset. Once a reset is detected,
   no further write is sent for the old agent. A response with 4113 (any HTTP status) or a
   401 for the agent's token is treated as a definite non-write. It invalidates the reset
   cache, forces a fresh reset check and archives the agent through `archiveForReset`.
2. **Quotes.** Every buy and sell has a market quote that passes today's checks:
   - `snapshot_seconds`;
   - not invalidated by an action;
   - `quoteLife`;
   - repricing in `validate`.

   No cache or playbook setting can loosen these checks.
3. **Outside credit changes.** A credit change made outside the engine is detected:
   - before any credit-changing write (buy, sell, refuel, buy_ship);
   - otherwise within 60 s by the run credit check and `unexplained_change`. This applies
     whatever state the ship is in: transit, waiting or idle.
4. **Uncertain writes.** Every cache is bypassed in the uncertain-write reconcile path
   (`ShipCommandExecutor.php:560-575`), including the reset cache. An uncertain or rejected
   write forces fresh reset, ship and agent reads before the next write for that agent.
5. **Transit.** No write goes to a ship before its stored arrival time. The state checked
   before a write was read under the agent lock after the agent's last write response.
6. **Pacing.** No change to `RequestBudget` pacing, 429 handling or `Retry-After` handling.
   Only phase 6 changes which request classes are admitted.
7. **Crash safety.** No API write is sent inside a database transaction. A crash at any point
   leaves a `ShipAction` row in a state the existing sweeper or reconcile can find.

## Phases

Each phase is its own PR with its own tests. Every phase depends only on the phases before
it.

### Phase 0: attribution, outcome counts and a cost regression test

- **Endpoint categories.** `SpaceTradersClient::request()` works out a category from the
  path: `status`, `agent`, `ship`, `ships_page`, `market`, `shipyard`, `waypoint`,
  `system`, or `write:<command>`. It passes the category to both
  `FleetMetrics::count()` and `RequestBudget::observe()`.
  - `count()` stores it in the row's `data` JSON.
  - `observe()` stores the `X-RateLimit-Type` count per category, because headers are only
    visible there.
- **Ship attribution.**
  - `refreshBuyer` reads are attributed to the run's ship, not `shared`.
  - The `direct()` `resetSchedule` read is attributed to the grant's arm ship, or labelled
    `director`.
- **Advance outcomes.** Each advance records one outcome: `wrote`, `waited`, `held`,
  `deferred`, `blocked` or `idle`, with the requests it used. Each director pass records
  `started`, `awaiting_run` or `waiting`.
- **Scorecard.** Shows all-in requests per active ship-hour, requests per load, the split by
  category and by outcome, and a "max useful fleet" figure: 0.9 × 7,200 / all-in rate.
- **Regression test.** A feature test runs one arm cycle (k=3, m=2) on `FakeSpaceTraders`. It
  asserts a total ceiling, per-category ceilings and the number of advances that send no
  write. It starts at today's numbers. All lifetimes in later phases read the app clock, so
  the test can move time with `travel()`.
- **Deploy and measure.** Deploy (Taylor's call), then collect at least 6 hours of
  production data before phase 3 starts. Phases 1 and 2 can be built while that data comes
  in.

### Phase 1: run the write right after its advance, reusing the advance's reads

- Split `ShipCommandExecutor::execute` into a core that takes no lock, plus the existing
  locked job wrapper. The core keeps `execute`'s own handling unchanged:
  - the `Pending` re-check;
  - the `sending` reservation;
  - the pending, uncertain and rejected outcomes.
- **Where it runs.** `advance` calls the core only after `enqueue`'s `DB::transaction`
  commits, outside any transaction, while still holding the agent lock (invariant 7). The
  `afterCommit()` job dispatch stays as the fallback. When the job runs it finds the action
  no longer pending, or collides on the lock and is retried, which is harmless.
- **What it reuses.** `advance` passes its pre-write reads to the core explicitly, each with
  its app-clock read time:
  - reset result;
  - agent credits;
  - ship snapshot;
  - market observation.

  The core uses a passed value only if both hold:
  - no write has happened for the agent since it was read. This is guaranteed under the
    lock, and checked against the agent's last confirmed action.
  - it is no more than 10 s old.

  Otherwise it reads fresh. The quote checks in invariant 2 still apply to the passed market
  observation. This does not depend on the 2 s scope cache, which paced reads can outlast.
- **After the call.** `advance` reloads `$run` and the ship snapshot before
  `CycleAccounting::record` and any `$run->update(...)`.
- **Exceptions.** Exceptions from the core are handled as the job handles them today. They
  never block the run through `advance`'s own catch.
- **Tests:**
  - a failure thrown after the write leaves the action `uncertain`, not missing;
  - cycle accounting after an inline write uses the post-write ship;
  - a passed read older than 10 s is read fresh;
  - the fallback job finds no pending action and does nothing.
- **Expected saving:** about 3 reads per write step, about 36 per fixture cycle and about
  45 per live load.

### Phase 2: cached reset check, with rejection handling

- **Gate before building.** Confirm from the SpaceTraders docs, or one controlled call with
  an old token, how the server answers a pre-reset token: status and `error.code`. If it
  cannot be confirmed, the cache still serves reads and non-write advances, but a fresh
  `GET /` stays in front of every write.
- **The cache.** `guardReset` and `direct()` use a cached `resetSchedule()` result:
  `reset_id`, `next_reset` and `observed_at`. The cache:
  - lives in the database (`game_runtime`), shared by the engine and web processes;
  - is keyed by API base only, with `reset_id` as the value;
  - has a 60 s lifetime;
  - is cleared by `deploy.ps1` and by registration or import.
- **A fresh `GET /` is forced when:**
  - the cache is missing, expired or unparsable, or has no `next_reset`;
  - the time is within 10 minutes of `next_reset`, or past it;
  - the agent has no `reset_id` yet. The `reset_id` and `canonical_identity` assignment
    branch always runs on a fresh read.
  - the call is on the reconcile path (invariant 4).
- If reading the cache fails, the guard fails closed: it reads fresh.
- **Rejection handling.** In `SpaceTradersApi::call()` (`:576-583`), a 401 for the agent's
  token or an `error.code` of 4113 with any status does three things:
  - clears the reset cache;
  - marks the `GameException` as a definite non-write, carrying the code;
  - leaves `invalid_credential` set, as today.

  The callers `advance`, `execute` and `direct()` catch that exception and run a fresh
  `guardReset`. `guardReset` archives the agent through `archiveForReset` when the reset id
  has changed.
- **Tests:**
  - a reset detected on a cache hit through a 4113 rejection, followed by archiving;
  - the 10-minute window before `next_reset`;
  - the reconcile path bypasses the cache;
  - a cache read failure reads fresh.
- **Expected saving:** after phase 1, about 1 `GET /` per advance, down to about 1 a minute
  for the host.

### Phase 3: agent freshness, and director passes with almost no requests

**Agent freshness**, which phase 3's director change depends on:

- Every real `GET /my/agent` stamps `synced_at`. That covers `advance:214`, `CommandRules:28`,
  `sync()`, `Adviser.php:93` and `OpportunityInvestigator.php:54`.
- `confirm()` stamps `synced_at` only when the response contains an `agent` object. After
  this, `synced_at` means "last real balance observation".
- `SpaceTradersApi::agent()` keeps `shipCount`.

**Director passes:**

- `directGrant` checks grant expiry and pause before any API call. That is already true
  (`:94`) and gets a test.
- **Which arms get the local pass.** An arm grant whose ship has a live run gets the local
  pass. A live run is either:
  - an `AutomationRun` with `active_agent_id` set and state running, waiting or stopping,
    or with `stop_requested_at` set;
  - a pending or sending `ShipAction` for the ship.
- **What the local pass does:**
  - It takes `reset_id` and `next_reset` from the phase-2 cache, which reads fresh under the
    phase-2 rules. Reset detection therefore keeps running when only arm grants are live.
    `direct()` still closes an episode on a reset mismatch.
  - If the agent's `synced_at` is older than 60 s, it reads `GET /my/agent` fresh before
    `CampaignSafety::reason`. That is at most 1 read a minute per agent, shared by all arms,
    and it keeps invariant 3 for waiting and in-transit ships.
  - Otherwise it makes no API call. It still does, from the database:
    - `sample` (every 15 minutes);
    - `recordResults`;
    - `CampaignSafety::reason`, including `unexplained_change`, `uncertain_action`, the arm
      loss stop and the consecutive-loss check;
    - blocked or stale run handoff;
    - `finishStop`;
    - wind-down;
    - `FleetClaims::release`.
- **A pass that will start a run** uses the cached reset check, reads the agent fresh, and
  reads only the arm's ship (`GET /my/ships/{s}`). It does not page through the fleet.
- **The slot-1 (C0) grant** keeps its fleet sync and discovery.
- **Reconciliation.** A full fleet sync for the agent runs:
  - at most every 5 minutes;
  - straight away after a `buy_ship`;
  - when the agent's `shipCount` differs from the local ship count.

  It keeps the ship deletion, baselines and new-ship pickup that `sync()` does today. The
  dashboard's user-triggered sync is unchanged. Phase 6 gives it the lowest class.
- **Tests:**
  - a loss-limit breach, a wind-down and a blocked-run handoff are still handled during a
    local pass;
  - an outside credit change is detected within 60 s while the arm's ship is in transit;
  - a `shipCount` change and a `buy_ship` trigger reconciliation;
  - a local pass makes no API call except the stale-agent read.
- **Expected saving:** most of the director's third of traffic now. At N=300 an idle pass
  would otherwise cost 17 requests.

### Phase 4: agent reads only where credits can change

- An advance that will send a credit-changing write (buy, sell, refuel, buy_ship) reads the
  agent fresh. Phase 1 hands that read to the write core. `before.credits` therefore stays
  exact, and a price mismatch always means a price problem.
- Other advances (navigate, dock, orbit, waits) use the agent row. They read fresh only when
  `synced_at` is older than 30 s (configurable, 60 s at most).
- Every credit check (`AutomationRunner.php:215-222`, `CampaignSafety`) requires a
  `synced_at` no older than 60 s and reads fresh otherwise.
- No watermark is needed: under the agent lock the row and the delta sum already agree.
- **Tests:** a credit check with a stale `synced_at` reads fresh; a navigate step does not
  read the agent when `synced_at` is fresh.
- **Expected saving:** the agent reads on steps that don't change credits and on no-write
  advances.

### Phase 5: market reads based on freshness

- `refreshBuyer` reads a market only when **both** hold:
  - a ship is present (required to see prices at all);
  - the saved observation would fail the quote checks for the planned work. That means it
    is older than `snapshot_seconds − planned loading time − 30 s`, or invalidated by an
    action, or missing.
- Ships, probes and runs share these observations, so two ships at one market share one
  read.
- The read before each lot at the write market is required (invariant 2) and stays. Only
  duplicate and non-decision reads go.
- The lifetimes come from the existing constants and cannot exceed them. They are not new
  playbook settings.
- **Tests:** quote boundaries at the limit and 1 s past it, an invalidated quote, and
  planned loading time crossing the expiry.
- **Expected saving:** set from phase 0 data. In the fixture it is about 1–2 per advance.

### Phase 6: budget classes and the fleet cap

- The scope priority names are extended, and run reads become a class separate from director
  reads. Today both are `critical` (`RequestBudget.php:28`). Requests are served in this
  order:
  1. writes, and reads that safety requires (reset, stale-agent and reconcile reads);
  2. reads a run step needs;
  3. director decisions;
  4. probes, discovery and dashboard syncs.
- Classes 3 and 4 are refused while the host bucket's last-60-second rate is above 80% of
  its sustained rate.
- The scorecard's "max useful fleet" figure becomes an input to the v2 buying rule (fleet
  plan, council question 4). The buying rule itself is out of scope.

### After phase 0 data: cheaper waits

If phase 0 shows that no-write advances are still a large share after phases 1–5, add one
change. A run whose next step is a pure time wait (transit, cooldown, a hold with a known
end) is not advanced before that time, and its waiting advances use the saved state with no
reads. Invariant 3 still holds, because phase 3's 60 s agent check runs no matter what. The
design waits for the data.

## Out of scope

- Scoring routes by profit per request (a shadow price on requests). Revisit when measured
  use passes 70% of the budget.
- More steps' worth of cargo per write (bigger lots) or fewer fixed steps per cycle. The
  300-ship case needs these, but they change trading behaviour and belong in the v2 council
  design.
- Changes to the buy and sell freshness rules themselves.
- Spreading load over extra accounts or IP addresses.
- Contract work.

## Verification

- **Each phase:**
  - existing tests pass;
  - the regression test runs at the phase's new ceilings;
  - the phase's own invariant tests pass (listed in each phase).
- **After deploy:** compare production by category and by outcome, per load, before and
  after each phase. Per-load figures are less noisy than short time windows. Check 429 and
  deferral counts too. Deploying is Taylor's call, through `deploy.ps1`.

## Council record

The reviewers were Claude Opus 5.5 (high), Claude Sonnet 5.5 (high, standing in for GPT-6.1
Sol at Taylor's request, since the Codex provider is disabled) and Muse Wonder 1.0 (high).
Each round used fresh reviewers.

### Round 1: all three asked for changes

A separate Sonnet pass checked each finding against the code and the production copy.

- **Accepted, baseline:**
  - all-in about 920 per ship-hour;
  - `shared` sources identified;
  - writes per load corrected to about 15;
  - the gap between fixture and live explained;
  - director share measured.
- **Accepted, rate-limit scope:** the limit is IP-scoped, and arms are ships of one agent.
- **Accepted, phase order:** the inline execute became an early phase, and the director pass
  moved earlier.
- **Accepted, credit check:** the credit watermark was dropped. A fresh agent read happens
  before credit-changing writes. `synced_at` was fixed. A maximum observation age applies.
- **Accepted, reset check:** built on `resetSchedule()`, shared across processes, fails
  closed, and forced fresh on rejection. The registration branch is kept.
- **Accepted, market reads:** lifetimes come from `snapshot_seconds` and `quoteLife`, and the
  per-lot read is kept.
- **Accepted, director pass:** the local pass keeps every supervisory duty.
- **Accepted, metrics:** rate-limit type is recorded in `RequestBudget::observe`.
- **Rejected:** a separate 15 s ship-snapshot reuse rule.
- **Deferred to the v2 concurrency design:** the per-agent model, the start-rate limit and
  serialisation through the single agent lock.

### Round 2: all three asked for changes

No reviewer objected to the round-1 rejection or deferrals. Coordinator Opus checked the new
findings against the code (`AutomationRunner.php:361-377`, `SpaceTradersApi.php:187` and
`:576-583`, `AutomationRunner.php:214-215`). Every one of them is accepted:

- **Phase 1:**
  - the write runs after `enqueue` commits, outside transactions (new invariant 7);
  - the advance's reads are passed explicitly with a 10 s maximum age, instead of relying
    on the 2 s scope cache;
  - `$run` and the ship are reloaded before cycle accounting;
  - a crash test was added.
- **Invariant 3 for waiting ships:** the local director pass reads the agent when `synced_at`
  is older than 60 s. `synced_at` is fixed in phase 3, before the director change relies on
  it.
- **Ordering:** the reset cache moved before the director change, so no phase relies on a
  later one. The local pass takes the reset id from that cache.
- **Reconciliation trigger:** `shipCount` is now kept, so the trigger can be implemented.
- **Rejection handling:** spelled out in `SpaceTradersApi::call()` and its callers, with a
  gate to confirm the real pre-reset token response. Invariant 4 covers the reset cache.
- **Fixture goal:** per-phase indicative ceilings replace the unsupported 45. Live per-load
  data decides.
- **Wording:**
  - phase-0 category plumbing into `RequestBudget::observe`;
  - the cache key is the API base only;
  - invariant 6 reworded;
  - the `CreditAccounting.php:63` citation corrected;
  - the active window clarified.
