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
- Reserve 20,000 CR. A cumulative loss cap stops the whole plan (not one run).
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

```yaml
name: weekly-default
version: 1
agent: { symbol: GIANTSOUP, faction: COSMIC }   # used for re-registration
limits:
  reserve_credits: 20000
  episode_loss_cap: 25000          # stop the plan when credits < start_credits - cap
  trade_exposure_credits: 5000     # max unrealized cargo value per trade run
  run_max_minutes: 60
  run_max_cycles: 10
phases:
  - id: discover
    exit: { any: [ { minutes_elapsed_gte: 30 }, { markets_observed_gte: 6 } ] }
    activities: [ { role: probe, strategy: scout } ]
  - id: earn
    exit: { hours_to_reset_lte: 12 }
    activities:
      - { role: command, strategy: trade, when: { conservative_margin_gte: 1 } }
      - { role: command, strategy: mining }
  - id: wind_down              # no new trade buys; mining and selling only
    activities: [ { role: command, strategy: mining } ]
predictions:
  - { metric: credits, at_hours: 24, gte: 0 }       # filled in per version by the planning session
```

Gate metrics in v1: `minutes_elapsed_gte`, `hours_to_reset_lte`, `credits_gte`, `markets_observed_gte`,
`cycles_completed_gte`. Activities are tried in order; the first feasible one is started. Unknown
keys fail validation. Keep the vocabulary this small.

## The director

`CampaignDirector`, called from the existing `spacetraders:dispatch-due` schedule, at most once per
30 s, doing one step per call under the agent lock:

1. No active, unpaused grant → nothing.
2. Engine gate closed → wait (`awaiting_engine`). This is how deploys and restarts are survived.
3. Reset changed (or no agent for the current reset) → close the previous episode, register a new
   agent with the saved token and the file's symbol/faction, sync, open a new episode. If the public
   reset schedule is unknown or the reset is overdue and unconfirmed, wait; never assume.
4. Safety checks, any failure **pauses** the campaign with a reason (manual `campaign:resume` needed):
   any `uncertain` ship action; an unexplained credit change (`CreditAccounting`); credits below
   reserve; credits below `start_credits - episode_loss_cap`.
5. An active run exists → nothing (runs advance through the existing jobs).
6. Leftover cargo on the role ship from a stopped run → start a sell-only recovery step (or pause if
   no buyer is known).
7. Evaluate the phase exits, advance phase, journal the transition.
8. Pick the first feasible activity, create its run through the **existing** preview/start code with
   limits from the grant, and start it. The grant replaces only the human approval; every existing
   validation, fingerprint and reserve check still runs. Add an internal authorization path; do not
   fake an HTTP request or a checkbox.
9. Journal every decision: inputs (credits, phase, candidates considered and why rejected), choice.

One active run per agent stays as today. Probe and command ship take turns; that is acceptable for v1.

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
  replay; uncertain action, unexplained balance, reserve and loss cap each pause; phase gates advance;
  trade skipped when margin/exposure fails and mining chosen; wind-down blocks new trade buys;
  leftover-cargo recovery; report output.
- Full existing suite passes.
- A dry run against the dev checkout's database (engine off) shows `campaign:status` and a
  `campaign:report` for a faked episode.
