# Strategy 2: `SOUPBURN` — Lean Velocity / Zero-Deadhead Specialist

**Role:** Core Agent #2 — First New Agent (Canary → Chained Routing → Calibrated `BURN`)\
**Agent Symbol:** `SOUPBURN`\
**Faction:** `VOID` (held constant with `GIANTSOUP` to minimize extra variables; actual HQ system recorded on registration)\
**Starting Capital:** Fresh reset starting credits + 1 Command Frigate + 1 Starter Probe (no credit transfers exist in SpaceTraders)\
**Request Cap:** **1,600 req/hr (27.8%)** in Stage 1 → **720 req/hr (12.5%)** in Stage 2 (+ 300 req/hr Tier-1 settlement reserve).

---

## 1. Strategic Hypothesis

Inspired by the leaderboard's lean 2-ship archetype (such as `M-004`, noting its raw balance is a snapshot rather than a verified CR/hr rate), `SOUPBURN` tests whether maximizing **single-frigate velocity and utilization** beats heavy hull capex:
- **Zero cargo-hull capex drag:** Runs **1 Command Frigate + 1–3 probes** only—keeping 100% of cash liquid for full 40-unit high-margin loads (`MICROPROCESSORS`, `FABRICS`, `CLOTHING`, `JEWELRY`, `SHIP_PLATING`).
- **Eliminate empty return legs (Chaining):** Today's `OperationPlanner` forces `source → buyer → empty return to source` (`OperationPlanner.php:66`, `empty_return_included => true`), burning ~40–50% of flight time and fuel empty. Chaining immediately scores the next outbound trade from buyer `B` (or distance-0 orbital neighbors of `B`), while still allowing profitable empty repositioning whenever no outbound trade from `B` beats moving to a superior source.
- **Double travel speed when economics pay (`BURN`):** SpaceTraders v2 candidate `BURN` formula consumes `max(2, 2 × round(d))` fuel and cuts distance travel time in half (`12.5 × d / speed + 15s` vs `25 × d / speed + 15s`). On high-margin legs where `extra_fuel_cost + mode_switch_overhead < saved_minutes × route_cr_per_min`, `BURN` raises throughput without adding ships or director overhead.

---

## 2. Hard Launch Gate & Sequential Isolation Ladder

`SOUPBURN` NEVER launches until **Milestone 0 + Stage 1 Gate** pass:
- Request Budget Phases 1–3 + 4-tier admission priorities + per-agent `agent:<symbol>` buckets deployed,
- 15-file Multi-Agent Scaffolding + cross-agent `FleetClaims` + `CampaignHealth::evaluateKillSwitch()` deployed and tested,
- Live measured all-in rate **`≤600 req/active-trader-hr`** (probes in numerator only),
- Before cutoff **`2026-11-27 13:00Z`**.

Once registered, check `SOUPBURN`'s actual HQ system immediately. If it lands in `X1-SK70`, cross-agent `FleetClaims` automatically prevents bidding against `GIANTSOUP` and logs the shared-system covariate.

### Step 2a — `CRUISE` Canary (Baseline & Multi-Agent Proof)
- **Config:** `routing: round_trip`, `flight_mode_policy: cruise`, `probes: { target: 3, max_price: 50000, min_working_capital: 75000, pool: true }`.
- **Purpose:** Verify multi-agent isolation, per-agent request cap enforcement, and establish `SOUPBURN`'s own starting-system baseline.
- **Pass Gate:** `≥6 hours` AND `≥10 completed loads`, zero safety pauses, zero stalled settlements, and `GIANTSOUP:C0` rolling 3h CR/hr holds within 15% of pre-launch baseline.

### Step 2b — Chained `CRUISE` (Zero-Deadhead Routing)
- **Config:** Enable `routing: chained` behind playbook flag (`flight_mode_policy` remains `cruise`).
- **Planner Invariants (`OperationPlanner.php:59-67` redesign):**
  - Trade run completes at buyer `B` (no mandatory return leg to `home`).
  - Every approved loaded leg requires: (a) verified affordable fuel continuation from `B` to a fuel seller (`B` itself, a distance-0 neighbor, or a reachable fuel station with reserve), (b) `<180s` fresh executable quotes (older quotes up to 30–60m may rank *probe scouting/verification* targets only, never buys), (c) bounded inventory and loss exposure, and (d) explicit `sell_aboard` mid-chain recovery semantics if a departure blocks, a sale is partial, or a bid collapses.
  - Allows profitable empty repositioning when the best outbound trade from `B` scores below moving empty to a superior source `S'`.
- **Pass Gate:** `≥6 hours` AND `≥10 completed chained loads`, zero mid-chain recovery stalls, and concurrent-`GIANTSOUP:C0`-adjusted `net CR/wall-clock-hr` ratio `≥ 1.00` vs Step 2a.

### Step 2c — Calibrated Chained `BURN`
- **Calibration Pre-Step:** Run 3 bounded short inner-ring `BURN` legs (`distance <= 60`, verified fuel-seller destination, conservative fuel allowance `max(2, 2 × ceil(d) + 2)`, mandatory return to `CRUISE`) on `SOUPBURN-1` (Command Frigate, speed 30) to record observed fuel and travel timestamps for its exact `FuelPlanner::configuration()` hash. Probes stay strictly `CRUISE`-only (`fuelFree()`).
- **Activation Gate:** Enabled ONLY after (a) `SOUPBURN-1` calibration passes AND (b) `H1`'s randomized paired `BURN` A/B (`≥6 blocks = 12 loads`) demonstrates positive median paired Δ in `net CR/wall-clock-hr` and non-negative Δ in `net CR/request`.
- **Safety & Recovery Invariants:**
  - `CampaignSafety.php:66` allows `flight_mode` ONLY under `calibrate_burn | auto_burn` (`CRUISE ↔ BURN` only).
  - `CommandRules.php:50` keeps `crew.current >= crew.required` enforced at all times; lifts the CRUISE-only check ONLY for approved `BURN` runs and the `flight_mode{CRUISE}` recovery step of `sell_aboard` (keeping CRUISE mandatory for all subsequent recovery steps and rebuilding the `FuelPlanner::configuration()` / `initial_configuration` fingerprint after transition).
  - Every `BURN` leg verifies fuel continuation from destination and includes `PATCH .../nav` write cost + latency in cycle scoring.
- **Pass Gate:** `≥6 hours` AND `≥10 completed loads`, zero pauses or mid-chain recovery stalls, concurrent-`C0`-adjusted `net CR/wall-clock-hr` ratio **`≥ +5%` above Step 2b**, AND `net CR/request` **`≥ 95%` of Step 2b**. If Step 2c fails, revert `SOUPBURN` to Step 2b (Chained `CRUISE`).

---

## 3. Pre-Registered Metrics & Evidence Classification

- **Primary Metrics:** `net CR/request` (`confirmed load net ÷ attributed load requests`), `net CR/active trading ship-hour`, `net CR/wall-clock hour`, `utilization %`, and starting-system covariates (market count, distance-0 orbital clusters, initial spread distribution).
- **Evidence Label:** Cross-agent comparison (`SOUPBURN` vs `GIANTSOUP:C0`) is explicitly **observational/descriptive** (controlling descriptively via concurrent-window ratios against `C0`), because one agent per random starting system cannot separate system-seed luck from strategy. Causal proof of `BURN` comes from `H1`'s paired blocks.

---

## 4. Approval Checklist for Taylor
- [ ] Approve registering new agent `SOUPBURN` (`VOID`) once Milestone 0 + Stage 1 Gate (`≤600 req/active-trader-hr`) pass.
- [ ] Approve sequential ladder Step 2a → Step 2b (Chained `CRUISE`) → Step 2c (Calibrated Chained `BURN`).
