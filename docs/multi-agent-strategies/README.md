# Multi-Agent Strategy Portfolio Plan (Council-Approved Revision 6)

**Date:** 2026-11-22\
**Status:** Unanimously approved by Strategy Council (Claude Opus 5.5, GPT-6.2 Sol, Muse Wonder 1.0) after 6 review rounds against `main` (`ccbc20d`). Approved by Taylor on 2026-11-22. Taylor also approved the 3-leg BURN calibration and paired BURN A/B on GIANTSOUP:H1, leaving C0 unchanged, and the opt-in bounded ContractPlanner drought fallback described in Strategy 4.\
**Active Wipe:** `2026-11-22 13:00Z` → `2026-11-29 13:00Z` (Go/No-Go launch cutoff: **`2026-11-27 13:00Z`**).

---

## Executive Summary

Running multiple SpaceTraders agents from one Mac mini lets us test genuinely distinct strategic levers in parallel—**provided we solve our shared 2 req/s (7,200 req/hr) IP rate limit and single-slot campaign architecture first**. Today on production `main` (`ccbc20d`), live telemetry measures **1,549 req/active-ship-hr** (`94.9%` non-write reads), which caps our entire IP at **4.2 active ships**.

After six council audit rounds, the portfolio is refined into **3 core launch agents**, **1 conditional in-agent module**, and **1 read-only shared trigger**:

| # | Codename | Type | Core Hypothesis | Status / Rollout |
|---|---|---|---|---|
| **1** | [`GIANTSOUP`](strategy-1-giantsoup-control.md) | **Core Agent (Control)** | Proven Tier 1A probe-pool + `CRUISE` round-trip baseline (`C0` untouched; optional paired randomized `BURN` A/B on arm `H1`). | **Live now** in `X1-SK70` (`VOID`) |
| **2** | [`SOUPBURN`](strategy-2-soupburn-velocity.md) | **Core Agent (Canary → Velocity)** | Lean 1-frigate + 1–3 probes. Sequential ladder: `CRUISE` canary → Chained `CRUISE` (zero deadhead) → Calibrated Chained `BURN`. | Launches at **Stage 1 Gate** (`≤600` all-in req/trader-hr) |
| **3** | [`SOUPFLEET`](strategy-3-soupfleet-snowball.md) | **Core Agent (Fleet v2)** | Freezes `SOUPBURN`'s validated routing/flight policy and adds automated, formula-capped, payback-gated Light Hauler compounding. | Launches at **Stage 2 Gate** (`≤300` all-in req/trader-hr + `SOUPBURN` validated) |
| **4** | [`SOUPMAKER`](strategy-4-soupmaker-module.md) | **Conditional In-Agent Module** | Bounded single-exporter supply-chain priming + separate drought fallback (gas siphoning; bounded procurement contracts opt-in and off by default). | **No 4th agent this wipe**; opt-in module Day 3+ if budget & gates allow |
| — | [`GateWatch`](strategy-4-soupmaker-module.md#shared-gatewatch-trigger-replaces-retired-soupgate-agent) | **Shared Read-Only Trigger** | Replaces standalone `SOUPGATE` (solo gate construction is negative-EV over 6.5 days). Polls `get-waypoint` once/30m in Tier 4 to detect completion. | Read-only poll + notify only (no writes / no remote ship buys) |

---

## 1. Shared IP Request-Budget Architecture (Hard Launch Gate)

SpaceTraders enforces **2 req/s = 7,200 req/hr (120 req/min)** scoped to our **IP address** (`docs/live-rate-header-verification.md`). Extra agents under one saved account token add zero API capacity.

### 1.1 Host Envelope & Dedicated Tier-1 Settlement Reserve
- **Upstream IP Ceiling:** `7,200 req/hr` (`120 req/min`)
- **Tiers 2–4 Normal Operating Envelope:** **`5,760 req/hr` (`96 req/min` = 80% of IP ceiling)** — governs all routine buys, positioning, director passes, probes, discovery, and dashboard reads.
- **Host Tier-1 Settlement Reserve:** **`1,080 req/hr` (`18 req/min` = 15% of IP ceiling)** — carved out of the `1,440 req/hr` margin *above* the 5,760 Tiers 2–4 envelope, leaving `360 req/hr` (`6 req/min` = 5%) absolute safety buffer below 120 req/min. Combined Tiers 1–4 ceiling = **`6,840 req/hr` (`114 req/min`)**.
  - Allocated as **300 req/hr (`5 req/min`) per active agent** (`3 × 300 = 900 req/hr`) + **180 req/hr** shared reconciliation/reset reserve.
  - **What Tier 1 admits (even when an agent hits 100% of its Tiers 2–4 cap):** every action required to safely settle cargo aboard and reconcile state — `flight_mode: CRUISE` (specifically the confirmed `BURN → CRUISE` recovery step), `sell_aboard` market reads, `orbit`, `navigate`, `dock`, `refuel`, `sell`, uncertain-write reconciliation, and forced reset checks.
- **Per-Agent Bucket Keying (`app/Gameplay/RequestBudget.php:162`):** Retain the existing `host` and `account` buckets AND add an independent per-agent bucket keyed by **agent token / symbol (`agent:<symbol>`)** so multiple agents sharing one account token never collapse into one bucket.
- **4-Tier Admission Priorities (Phase 6 pattern):**
  1. **Tier 1 (Writes & Settlement/Safety):** Active sales, `sell_aboard` recovery (including `BURN → CRUISE`), uncertain-write reconciliation, reset checks.
  2. **Tier 2 (Active Run Reads):** Ship/agent/market reads required to advance an active trade step.
  3. **Tier 3 (Director Passes):** Campaign planning and candidate ranking.
  4. **Tier 4 (Probes, Discovery, Dashboard, GateWatch):** Shed automatically when host rolling 60s rate exceeds 80% of operating envelope.

### 1.2 Exact Stage Allocations (Enforced in `RequestBudget`, Always `GIANTSOUP >= 50%`)

| Bucket | Stage 1 (RB Phases 1–3, `≤600` all-in) | Share | Stage 2 (RB Phases 4–5, `≤300` all-in) | Share |
|---|---:|---:|---:|---:|
| **`GIANTSOUP`** (Control + H1) | **3,400 req/hr** | **59.0%** | **2,880 req/hr** | **50.0%** |
| **`SOUPBURN`** (Lean Velocity) | **1,600 req/hr** | **27.8%** | **720 req/hr** | **12.5%** |
| **`SOUPFLEET`** (Hauler Snowball) | 0 req/hr | 0.0% | **1,800 req/hr** | **31.25%** |
| **Shared / Dashboard / GateWatch** | **760 req/hr** | **13.2%** | **360 req/hr** | **6.25%** |
| **Total Tiers 2–4 Envelope** | **5,760 req/hr** | **100.0%** | **5,760 req/hr** | **100.0%** |
| **Host Tier-1 Settlement Reserve** | +1,080 req/hr | (15% of IP) | +1,080 req/hr | (15% of IP) |
| **Unallocated IP Safety Margin** | +360 req/hr | (5% of IP) | +360 req/hr | (5% of IP) |

*4th Agent Allocation Policy:* Both stages allocate 100% of the 5,760 req/hr operating envelope. **No 4th agent is registered this wipe.** Any future 4th agent requires a separate operator decision and an explicit reallocation table preserving `GIANTSOUP >= 50%` (or a new-wipe allocation).

### 1.3 Separate Rate Definitions & Formula-Only Fleet Caps (`app/Gameplay/FleetMetrics.php`)
- **Collection-Time Attribution (`FleetMetrics.php:161, 214`):** Record `agent_symbol` / `agent_id` on every agent-scoped `FleetMetrics` row at collection time (requests, director passes, runs) before aggregation. Explicitly tag genuine host-wide rows (such as `scheduler` passes) with `scope = 'host'` so host rows are never conflated with missing agent attribution.
- **Per-Agent Scorecard Keys (`FleetMetrics.php:245`):** Key primary campaigns as `<agent_symbol>:C0` (e.g., `GIANTSOUP:C0`, `SOUPBURN:C0`, `SOUPFLEET:C0`) and arms as `<agent_symbol>:<arm_label>` (`GIANTSOUP:H1`), never collapsing multiple primaries into one shared `C0` bucket.
- **Metric 1 — Rollout Gate Rate (All-In per Active Trader-Hour):**
  $$\text{all\_in\_req\_per\_active\_trader\_hr} = \frac{\text{agent traders} + \text{probes} + \text{director} + \text{agent-attributed shared requests}}{\text{active trading ship-hours (command + haulers + shuttles ONLY)}}$$
  *Probes count in the numerator ONLY, never in the denominator—preventing idle probes from diluting the 600 / 300 gates.*
- **Metric 2 — Fleet Purchase Cap Formula (Trader-Only Rate, Zero Double-Counting):**
  $$\text{trader\_only\_req\_per\_active\_trader\_hr} = \frac{\text{requests attributed strictly to active trading ships}}{\text{active trading ship-hours}}$$
  $$\text{agent\_overhead\_req\_hr} = \text{trailing 6h measured req/hr from (probes + director + agent-attributed shared)}$$
  $$\text{max\_traders}(\text{agent}) = \left\lfloor \frac{\text{agent\_cap\_req\_hr} - \text{agent\_overhead\_req\_hr}}{\text{trader\_only\_req\_per\_active\_trader\_hr}} \right\rfloor$$
  *Purchase Hold Guard:* Hold all ship purchases if the trailing 6h sample is missing, has `< 1.0` active trading ship-hour, `trader_only_req_per_active_trader_hr <= 0`, or trailing 6h agent-scoped attribution is incomplete.

---

## 2. Multi-Agent Campaign Scaffolding (Exhaustive 15-File Checklist)

Today's codebase enforces one global primary campaign (`campaign_grants.slot = 1`, `slot = 2` pending). Before registering any new agent, land and test all 15 verified sites on `main` (`ccbc20d`):

1. **New Migration (leave `2026_11_14_000001` untouched):**
   - (a) Add `campaign_grants.agent_symbol` (`string`, nullable during backfill, then non-null),
   - (b) Backfill **every** existing grant row (including `GIANTSOUP`, `MACSOUP`, and arm grants) from its revision's `body['agent']['symbol']` via raw DB update,
   - (c) **Drop the existing single-column unique index `campaign_grants_slot_unique`**,
   - (d) Create composite unique index on `(user_id, agent_symbol, slot)` (allowing `slot = 1/2` per agent and `null` for arms).
2. `app/Models/CampaignGrant.php` (`:13, :19, :28, :41`) — per-agent primary/pending/arm query scopes.
3. `app/Gameplay/CampaignControl.php` (`:24, :42-43, :49, :92, :104, :154, :178-179, :186, :193, :213, :232`) — scope approve/supersede/activatePending/pause/resume/revoke by `(user_id, agent_symbol)` or explicit `CampaignGrant`; bind arms (`:92`) to their own agent's primary grant instead of global `slot = 1`.
4. `app/Gameplay/CampaignDirector.php` (`:64, :215`) — round-robin across all live primary grants; fix `:215` so `registration_intent` retry identity is keyed strictly by `(user_id, reset_id, symbol)` (surviving grant replacement), storing `campaign_grant_id` for attribution only.
5. `app/Console/Commands/DispatchDueSpaceTraders.php` (`:76, :98-124, :145`) — fair round-robin across all live primaries + arms; per-agent director failure streaks instead of global `slot = 1` pause (`:145`); fair per-agent round-robin/slice on the `limit(50)` pending `ShipAction` and due `AutomationRun` loops (`:98-124`) so a busy multi-hauler agent cannot starve a lean agent.
6. `app/Gameplay/CampaignHealth.php` (`:25`) — per-agent health checks + `evaluateKillSwitch()`.
7. `app/Gameplay/CampaignWatchdog.php` (`:15`) — key alerts, deduplication, and recoveries per agent symbol.
8. `app/Gameplay/CampaignStatus.php` (`:69, :75`) — resolve primary & pending grant per agent symbol.
9. `app/Http/Controllers/CampaignStatusController.php` (`:37`) — web pause button targets the selected agent's primary grant.
10. `app/Gameplay/CampaignStrategy.php` (`:14-15`) — walkthrough reads selected agent's primary/pending grants.
11. `app/Gameplay/CampaignProbeBootstrap.php` (`:28`) — authorize for any agent's primary grant (`slot === 1`).
12. `app/Gameplay/CampaignLocalEarn.php` (`:25`) — per-agent primary grant.
13. `app/Gameplay/CampaignLocalEarnReplacement.php` (`:33, :44, :72, :76`) — scope `slot = 1` and `slot = 2` replacement strictly to `(user_id, agent_symbol)`.
14. `app/Gameplay/ShipPurpose.php` (`:29`) & `app/Gameplay/GateRecon.php` (`:280`) — resolve primary grant by the target ship's `agent_symbol`.
15. `routes/campaign.php` (`:33, :70-108`) — CLI controls count **all current primary grants (`slot = 1`, including paused grants—never `available()`)**:
    - `campaign:pause {--agent=} {--user=}`, `campaign:resume {--agent=} {--user=}`, `campaign:status {--agent=} {--user=}`, and single-agent `campaign:revoke {--agent=} {--user=}` require `--agent=<symbol>` whenever the operator has `>1` current primary grant.
    - `campaign:revoke --all` is **explicitly exempt** from `--agent` and revokes all primaries + live arms for the operator (note in operator docs that bare `campaign:revoke` requires `--agent` once >1 primary exists).

### Cross-Agent `FleetClaims` & Same-System Overlap Guard
- Extend `FleetClaims::taken()` (`app/Gameplay/FleetClaims.php:61`) to check **all our agents trading in the same actual system (`ship.nav.systemSymbol`, including after recon/relocation)**—not only the same `agent_id` or HQ.
- Record **in-flight cargo quantities and arrival ETAs** per `(system, good, buyer)` so multi-hauler fleets project incoming buyer bid decay accurately.
- Immediately upon registering any new agent, log its actual HQ system and market covariates; if it spawns in `X1-SK70`, cross-agent claims activate automatically and the shared-system overlap is recorded as a covariate.

### Required Automated Verification Suite (Milestone 0)
- **Two-Primary Isolation Test:** Prove `CampaignDirector`, `DispatchDueSpaceTraders`, `CampaignHealth`, `CampaignStatus`, `CampaignStatusController:37` (web pause), `CampaignControl`, `CampaignStrategy`, `CampaignLocalEarnReplacement` (including `slot = 2`), `ShipPurpose`, and `GateRecon:280` each act strictly on the intended agent's grant.
- **Ambiguous Registration Replacement Test:** Prove `(user_id, reset_id, symbol)` retry identity survives primary grant replacement.
- **Queue Fairness Test:** Prove a 6-ship agent cannot starve a 1-ship agent in `DispatchDueSpaceTraders` pending-action and due-run loops.
- **Simultaneous Multi-Agent & Host Saturation Test:** Start with cargo aboard a ship in **`BURN`** while all agents saturate their Tiers 2–4 caps; verify Tier-1 settlement completes `flight_mode: CRUISE → read → orbit → navigate → dock → refuel → sell` cleanly and updates the `FuelPlanner::configuration()` / `initial_configuration` fingerprint.
- **Per-Agent Watchdog, Restart Recovery, `HostLock('campaign')` Cadence (3 agents + arms), and Wipe Rollover Tests.**

---

## 3. Automatic Kill Switch (`CampaignHealth::evaluateKillSwitch()`)

- **Owner & Storage:** `CampaignHealth::evaluateKillSwitch()`, executed on each `DispatchDueSpaceTraders` sweep, storing rolling 3h `GIANTSOUP:C0` baselines and breach timers in `game_runtime` (`kill_switch:*`).
- **Strict `C0`-Only Baseline:** Monitors **`GIANTSOUP:C0` ONLY**, explicitly excluding experimental arm `H1` so paired `BURN` A/B on `H1` never false-triggers the kill switch.
- **Two Distinct Actions:**
  1. **`drain_pause` (Stop-New-Exposure — Tiers 2–4 ONLY):** Triggered by:
     - `GIANTSOUP:C0` rolling 3h loads/hr OR CR/hr drops **>15%** vs baseline **AND** `GIANTSOUP` deferrals **>5%** sustained for **15 consecutive minutes**, OR
     - Host Tiers 2–4 rolling 60s rate **≥86.4 req/min** (90% of 96/min envelope) sustained **5 consecutive minutes**, OR
     - Total host Tiers 1–4 rate **≥102 req/min** (85% of 120/min IP ceiling) sustained **5 consecutive minutes**, OR **≥3 HTTP 429s in 15 minutes**, OR
     - Shared-system cannibalization: another of our agents trades in `X1-SK70` AND `GIANTSOUP:C0` rolling 3h pair margin drops **>20%** without market-wide depletion.
     - *Target & Behavior:* Drains **newest agent first (`SOUPFLEET` → `SOUPBURN`)** (or the overlapping agent for cannibalization). Immediately blocks all new buys, scouts, positioning, and ship purchases (Tiers 2–4), while **Tier-1 settlement & `sell_aboard` (including `BURN → CRUISE`) continue running inside the 1,080 req/hr reserve** until cargo aboard and pending/sending/uncertain actions settle—then transitions the grant to `paused`.
  2. **Immediate Hard Pause:** Triggered on unexplained credit changes or uncertain writes for the **affected agent ONLY**, preserving existing `CampaignSafety` behavior without touching healthy agents.

---

## 4. Event-Driven Rollout Timeline, Cutoff & Next-Wipe Policy

- **Milestone 0 (Now — Infra & Approval):** Taylor approves plans. Build & test RB Phases 1–3 + 4-tier admission priorities + per-agent `RequestBudget` buckets + 15-file Multi-Agent Scaffolding + cross-agent `FleetClaims` + Kill Switch + `GateWatch::pollGate()` in `/Users/homemac/Sites/spacetraders-dev`. Deploy cleanly in one restart. Verify multi-agent registration eligibility on saved account token.
- **Milestone 1 (Stage 1 Gate Passes: ≤600 all-in req/active-trader-hr):** Register `SOUPBURN`, inspect HQ system, launch Step 2a (`CRUISE` canary under 1,600 req/hr cap). In parallel (with Taylor's approval), run 3-leg `BURN` calibration on `SOUPBURN-1` & `GIANTSOUP-5` (`H1`) and start randomized paired `BURN` A/B on `H1` (keeping `C0` untouched).
- **Milestone 2 (Stage 2 Gate + `SOUPBURN` Validation):** Ship RB Phases 4–5. Advance `SOUPBURN` through Step 2b (Chained `CRUISE`, ≥6h & ≥10 loads) and Step 2c (Chained `BURN`, if calibration & `H1` A/B pass). Once all-in rate ≤300 req/active-trader-hr, `GIANTSOUP:C0` holds within 15% for ≥6h, AND `SOUPBURN` Step 2b (plus 2c if ready) passes, register and launch `SOUPFLEET` freezing `SOUPBURN`'s validated policy under the formula-derived fleet cap.
- **Go/No-Go Launch Cutoff:** **`2026-11-27 13:00Z`** (48 hours before wipe). No new agent registers after this cutoff; final 48h goes to dev-checkout testing and clean next-wipe readiness.
- **Next-Wipe (`2026-11-29 13:00Z`) Fallback Launch Policy:**
  - *Case 1 (Full Stage 2 cleared before cutoff — RB 4–5 + ≤300 + Step 2b validated + C0 stable):* Minute 0 launches `GIANTSOUP` (`2,880` = 50%, Tier 1–3 bootstrap priority) + `SOUPBURN` (`1,440` = 25%) + Shared/Bootstrap (`1,440` = 25%) = `5,760`. Hour +6 releases `SOUPFLEET` once live new-wipe scorecard confirms `≤300` and `GIANTSOUP:C0` healthy (Stage 2 caps `2,880 / 1,800 / 720 / 360`).
  - *Case 2 (Stage 1 cleared, ANY part of Stage 2 still pending—even if rate is ≤300 while Step 2b is incomplete):* Minute 0 launches `GIANTSOUP` (`3,400` = 59%) + `SOUPBURN` (`1,440` = 25%, clamped to ≤25% bootstrap cap, 1 frigate + ≤2 probes) + Shared/Bootstrap (`920` = 16%) = `5,760`. Hold `SOUPFLEET` until the full Stage 2 gate passes.
  - *Case 3 (Cutoff reached before Stage 1 cleared / no usable post-RB measurements):* Minute 0 launches **`GIANTSOUP:C0` ONLY**. Hold `SOUPBURN` and `SOUPFLEET` until RB Phases 1–3 + live scorecard clear `≤600`.

---

## 5. Strategy Council Audit Record (6 Rounds — Unanimous Approval)

| Round | Claude Opus 5.5 | GPT-6.2 Sol | Muse Wonder 1.0 | Key Outcomes Folded In |
|---|---|---|---|---|
| **Round 1** | `REQUEST_CHANGES` | `REQUEST_CHANGES` | `REQUEST_CHANGES` | Cut 5 → 3 core + 1 conditional module; retired `SOUPGATE` agent; added hard 5,760 IP gate, playbook flags, causal `H1` A/B, `ADVANCED_CIRCUITRY` fix. |
| **Round 2** | `REQUEST_CHANGES` | `REQUEST_CHANGES` | `REQUEST_CHANGES` | Per-agent `agent:<symbol>` bucket keys; active-trader-hr denominator; formula-only fleet caps (`GIANTSOUP ≥50%`); exhaustive `slot` audit; C0-only cargo-safe `drain_pause` kill switch; explicit `BURN` calibration & `2026-11-27` cutoff. |
| **Round 3** | **`APPROVE`** | `REQUEST_CHANGES` | **`APPROVE`** | Dedicated `1,080 req/hr` Tier-1 settlement reserve above 5,760 + reachable kill-switch rates; split all-in vs trader-only rates; CLI `--agent` targeting; `CampaignSafety:66` `flight_mode` path; Step 2c criteria; next-wipe fallbacks. |
| **Round 4** | `REQUEST_CHANGES` | `REQUEST_CHANGES` | **`APPROVE`** | Replaced nonexistent `GateRecon::dryRun()`; explicit drop of `campaign_grants_slot_unique`; `BURN → CRUISE` in Tier-1 drain; collection-time `FleetMetrics` attribution; paused-primary CLI counting. |
| **Round 5** | `REQUEST_CHANGES` | `REQUEST_CHANGES` | *(Approved R4)* | `GateWatch::pollGate()` polls `get-waypoint` first (`spacetraders-openapi-2.3.0.json:3145`); `scope = 'host'` on scheduler rows; non-CLI `(user_id, agent_symbol)` primary resolution + 2-primary test; `campaign:revoke --all` exemption. |
| **Round 6** | **`APPROVE`** | **`APPROVE`** | **`APPROVE`** | **Unanimous approval.** Folded final 4 polish notes (`spacetraders-openapi-2.3.0.json:3145/3372`, keep crew check intact at `CommandRules:50`, bare `campaign:revoke` doc note, include `CampaignStrategy`/`CampaignLocalEarnReplacement`/`GateRecon:280` in 2-primary test). |
