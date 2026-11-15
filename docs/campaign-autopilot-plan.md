# Campaign autopilot: weekly playbooks executed without AI

Status: approved for implementation 2026-11-14. Target: live in production for the
2026-11-15T13:00Z reset, or as soon after it as it passes review. The autopilot registers
whenever it first sees the new reset, so a late deploy loses hours, not the week.

Design came from a three-model council (Claude Opus, GPT Sol, Muse Wonder). They converged on
the shape below. This document is the build contract; keep scope to it.

## Taylor's decisions

- Goal is a mix: income, final cash and learning. The plan states predictions; the report scores them.
- The campaign resumes on its own after a deploy or engine restart, deriving the next step from
  fresh game state. It never replays a stale preview or an uncertain write.
- The campaign re-registers the agent at each weekly reset with the operator's saved account token.
- No credit floor and no loss cap (revised 2026-11-15: "risk it all"; a lost wipe means re-registering under a new symbol by hand). Only a fuel reserve for the next delivery, and a pause after two losing loads in a row as a bug guard.
- The PC keeps the engine running all week.
- **Not authorized:** ship purchases, equipment changes, accepting contracts. Leave them out.
- **No LLM** anywhere in the execution path.

## What a campaign is

A playbook file, `campaigns/<name>.yaml`, validated against a schema, versioned in git. Ships are
referenced by role (`command`, `probe`), never by symbol or waypoint, because both change each reset.

`php artisan campaign:import campaigns/<name>.yaml` stores an immutable revision (content hash,
parsed body). Editing the file and importing again creates a new revision; nothing needs a deploy.

`php artisan campaign:approve <revision>` creates the single active grant: the revision hash, the
operator user, an optional expiry, and the limits copied from the file. Only one grant is active.
Approving a new revision supersedes the old one at the next safe point (between runs). The grant
outlives engine restarts and resets. `campaign:pause`, `campaign:resume`, `campaign:revoke` are the
kill switch. `campaign:status` prints the grant, episode, phase, last decision and pause reason.

An **episode** is one reset world under the grant. It records start credits, the reset id, and all
accounting. Limits apply per episode.

### File contents (v1 schema)

Revised 2026-11-15 from the [one-hour trade test](reports/one-hour-trade-test-2026-11-14.md).

```yaml
name: weekly-default
version: 1
agent: { symbol: GIANTSOUP, faction: VOID }     # used for re-registration; email reused from DB
limits:
  reserve_credits: 0               # Taylor 2026-11-15: no credit floor; a wipe can be lost
  fuel_reserve: next_leg           # a buy must leave cash for fuel to reach the buyer and refuel
  run_max_minutes: 15              # one load per trade run
trade:
  proof: { max_units_batches: 1, max_credits: 10000, min_realized_ratio: 0.8 }
  lot:
    max_buyer_batches: 5           # units <= 5 x buyer tradeVolume, and <= hold
    first_scaled_max_buyer_batches: 3   # first load after a proof
    default_batch_decay: 0.04      # predicted bid drop per buyer batch until learned
  losses:
    losing_load: reprove           # a completed load with negative net (incl. fuel) -> next load is a proof
    pause_after_consecutive_losing_loads: 2   # likely a bug or bad data, not bad luck
  switch: { bid_below_first_seen: 0.9, better_pair_ratio: 1.1 }
  quote_max_age_seconds: 180       # unchanged; buy only if quotes outlive planned loading
phases:
  - id: discover
    exit: { any: [ { minutes_elapsed_gte: 15 }, { markets_observed_gte: 3 } ] }
    activities: [ { role: probe, strategy: scout } ]
  - id: earn
    exit: { hours_to_reset_lte: 1 }
    activities:
      - { role: command, strategy: trade }
      - { role: command, strategy: mining, when: { minutes_without_trade_gte: 15 } }
  - id: wind_down                  # sell cargo aboard only; no buys, no mining
    activities: [ { role: command, strategy: sell_aboard } ]
predictions:                       # learning metrics, scored by campaign:report
  - { metric: proof_realized_ratio, gte: 0.8 }
  - { metric: minutes_to_first_profit, lte: 45 }
  - { metric: freshness_holds, lte: 2 }
```

Gate metrics in v1: `minutes_elapsed_gte`, `hours_to_reset_lte`, `credits_gte`, `markets_observed_gte`,
`cycles_completed_gte`, `minutes_without_trade_gte`. Activities are tried in order; the first feasible
one is started. Unknown keys fail validation. Keep the vocabulary this small.

### Trade selection (deterministic)

Each time the command ship is free, rank every observed source→buyer pair for one good:

- **Units** = min(hold, `max_buyer_batches` × buyer tradeVolume, affordable while leaving the fuel
  reserve). There is no credit floor or loss cap. The first trade of an episode (and the first on
  any new good+buyer pair, and the next after a losing load) is a **proof** limited by `trade.proof`;
  the first load after a passing proof uses `first_scaled_max_buyer_batches`.
- **Predicted revenue** prices buyer batch *n* at bid × (1 − decay)^(n−1). Decay comes from our own
  realized sales for that good at that buyer this episode, else `default_batch_decay`. Purchase
  cost rises the same way using source batches.
- **Score** = (predicted revenue − cost − fuel) ÷ (travel + trade minutes). Positive estimated margin
  is required. Do not use the existing `conservative_cash_margin`: it assumes cargo is never sold
  and is always negative.
- **Scale** past the proof only if the proof realized ≥ `min_realized_ratio` of its predicted margin.
- **Switch** good or buyer when the current buyer's bid is ≤ `bid_below_first_seen` × its first-seen
  bid this episode, or another pair scores ≥ `better_pair_ratio` × the current one.
- **Freshness:** a buy batch proceeds only if both quotes will still be within `quote_max_age_seconds`
  when it executes. Refresh quotes immediately before starting. Park the probe at the chosen buyer
  (one-way move, not the round-trip scout) so the buyer quote stays fresh while the ship loads.
- **Sale floor** for a run is the lot's average unit cost (break-even), not a fixed price.

## The director

`CampaignDirector`, called from the existing `spacetraders:dispatch-due` schedule, at most once per
30 s, doing one step per call under the agent lock:

1. No active, unpaused grant → nothing.
2. Engine gate closed → wait (`awaiting_engine`). This is how deploys and restarts are survived.
3. Reset changed (or no agent for the current reset) → close the previous episode, register a new
   agent with the saved token and the file's symbol/faction, sync, open a new episode. If the public
   reset schedule is unknown or the reset is overdue and unconfirmed, wait; never assume.
4. Safety checks, any failure **pauses** the campaign with a reason (manual `campaign:resume` needed):
   any `uncertain` ship action; an unexplained credit change (`CreditAccounting`);
   `pause_after_consecutive_losing_loads` reached. Checks run between transactions, never mid-batch.
   A losing-loads pause first sells what is aboard. The fuel reserve is prospective: a buy that
   would leave too little cash to fuel the delivery is reduced or skipped, which is not a fault.
5. An active run that is **blocked or waiting on a freshness hold** with cargo aboard → stop it and
   go to step 6. Otherwise an active run → nothing (runs advance through the existing jobs). This
   check comes before the early return; the test showed a blocked run otherwise waits forever.
6. Cargo aboard the role ship and no active run → start a sell-only recovery (`sell_aboard`): go to
   the best known buyer, refresh its quote on arrival, sell at or above the lot's average cost. If
   no buyer clears the floor for 10 minutes, sell at the best bid anyway (a loss there
   counts toward the losing-loads pause). Pause only if no buyer for the good is known.
7. Evaluate the phase exits, advance phase, journal the transition.
8. Pick the first feasible activity, create its run through the **existing** preview/start code with
   limits from the grant, and start it. The grant replaces only the human approval; every existing
   validation, fingerprint and reserve check still runs. Add an internal authorization path; do not
   fake an HTTP request or a checkbox.
9. Journal every decision: inputs (credits, phase, candidates considered and why rejected), choice.

One active run per agent stays as today. Probe and command ship take turns; that is acceptable for v1.
The probe's one-way move to the chosen buyer is a short run between trade runs.

Known code issue to fix: `OperationPlanner.php:96` reads source and buyer quotes before line 101
checks whether all units are already bought, so a fully loaded ship can be blocked from departing
by a stale source quote.

### Engine liveness

The engine was faulted for about 43 hours before the test, and nobody noticed. The director cannot
report its own absence. `campaign:status` exits non-zero when the engine heartbeat is older than 5
minutes or the campaign is paused, so an outside check can alert on it.

## Measurement

Tables: `campaign_revisions`, `campaign_grants`, `campaign_episodes`, `campaign_events` (decisions,
phase changes, pauses, registrations), `campaign_samples` (every 15 min: credits, phase, ship states,
request budget used). Link runs to the episode.

`php artisan campaign:report [episode]` writes Markdown + JSON: credits over time, net per run and
per strategy, CR/hour of ship time, idle time, requests used, pauses and their causes, predictions
scored. This is what the weekly planning session reads. Each new revision should change only a few
settings, each with a stated hypothesis in the file's comments.

## Docs to update

- `AGENTS.md` "Push to main and update production": a deploy still ends the engine, but an approved
  campaign resumes on its own afterwards; plain automation runs still need re-approval.
- README or a short `docs/campaign-autopilot.md` operator guide: import, approve, status, pause, report.

## Out of scope for this build

UI pages (CLI only; status page later), ship purchases, contracts, multiple concurrent runs,
shared multi-ship ledger, replay/simulation, automatic tuning, jump gates, refining, siphoning,
multi-stop routes, notifications.

## Acceptance

- Feature tests with a faked SpaceTraders client: import/validation errors; approve/supersede/revoke;
  reset → registration → episode; engine closed → waits, reopened → resumes from fresh state without
  replay; uncertain action and unexplained balance each pause; one losing load forces a proof, two
  in a row sell aboard and pause; a buy is reduced or skipped when it would leave too little for fuel;
  proof trade sized by `trade.proof`, first scaled load capped at 3 buyer batches, full size only after
  a passing proof; units limited by hold and buyer batches; decay-adjusted prediction;
  switch on bid ≤ 90% first-seen and on a better pair; buy skipped when the quote would expire
  during loading; freshness-held run with cargo → stopped → cargo delivered; fully loaded ship departs
  despite a stale source quote; mining only after 15 minutes without a trade; wind-down only sells;
  `campaign:status` non-zero on stale heartbeat or pause; report output with learning predictions.
- Full existing suite passes.
- A dry run against the dev checkout's database (engine off) shows `campaign:status` and a
  `campaign:report` for a faked episode.
