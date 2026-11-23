# Strategy 1: `GIANTSOUP` — Probe-Pool Steady Compounder (Live Control)

**Role:** Core Agent #1 — Protected Control Baseline (+ Optional Within-Agent `H1` Causal `BURN` A/B)\
**Agent Symbol:** `GIANTSOUP` (already live)\
**Faction / System:** `VOID` / `X1-SK70` (27 priced markets, 3 shipyards)\
**Playbook:** `campaigns/weekly-probe-bootstrap.yaml` (`C0`) + `campaigns/arm-trade.yaml` (`H1`)\
**Request Cap:** **3,400 req/hr (59.0%)** in Stage 1 → **2,880 req/hr (50.0%)** in Stage 2 (always protected `≥50%` of the 5,760 Tiers 2–4 envelope, plus 300 req/hr Tier-1 settlement reserve).

---

## 1. Strategic Objective

`GIANTSOUP:C0` is our **untouched operational control benchmark** and primary credit generator for the `2026-11-22` wipe. Every new capability built for challenger agents (`BURN` flight mode, chained routing, automated hauler purchasing, supply-chain priming) sits behind explicit playbook flags defaulted **OFF**, guaranteeing zero unintended drift on `GIANTSOUP:C0`.

### Measured Live Baseline (2026-11-22 18:18–21:18Z)
- **`GIANTSOUP:C0` (`GIANTSOUP-1`, Command Frigate):** `215,350 CR/hr` (`5.00 loads/hr`, `43,070 CR/load`, `0.94` pair ratio, `0` holds)
- **`GIANTSOUP:H1` (`GIANTSOUP-5`, Light Hauler, 80u):** First two completed loads `+43,196 CR` (`FABRICS`) and `+30,376 CR` (`JEWELRY`)
- **Support Fleet:** 6 fuel-free probes (`GIANTSOUP-2/3/4/6/7/8`) operating in Tier 1A pool mode (1 shadowing active buyer, others refreshing top hubs)

---

## 2. Operating Rules & Control Protection

1. **`C0` Stays Pure Control:**
   - Flight mode: `CRUISE` only (`flight_mode_policy: cruise`, default).
   - Routing: Round-trip (`source → buyer → return to source`, `routing: round_trip`, default).
   - Sizing: Proof-before-scale (`1 → 3 → 5` buyer batches; `60,000 CR` proof cap; `0.8` min realized ratio; `2` consecutive losses → `sell_aboard` + pause).
   - Probe pool: Active (`target: 3`, `pool: true`, buyer shadowing enabled).
2. **Protected Priority & Kill-Switch Reference:**
   - `GIANTSOUP:C0` (explicitly excluding arm `H1`) serves as the kill-switch health reference (`CampaignHealth::evaluateKillSwitch()`).
   - If `GIANTSOUP:C0` rolling 3h loads/hr or CR/hr drops `>15%` alongside `>5%` request deferrals for 15 consecutive minutes, newer agents (`SOUPFLEET`, then `SOUPBURN`) automatically enter cargo-safe `drain_pause`.
3. **Separate Scorecard Attribution:**
   - `GIANTSOUP:C0` and `GIANTSOUP:H1` are always reported on separate rows (`GIANTSOUP:C0`, `GIANTSOUP:H1`) in `fleet:scorecard --by-agent`.

---

## 3. Optional Within-Agent Causal `BURN` A/B Experiment (Arm `H1` Only)

Because cross-agent comparisons occur across different random starting systems, **only a within-agent paired test can prove `BURN`'s true causal ROI**. With Taylor's explicit approval, arm `H1` (`GIANTSOUP-5`, Light Hauler, speed 15) runs a clean paired experiment without ever touching `C0`:

1. **Calibration Pre-Step (3 bounded legs):**
   - Authorize `flight_mode_policy: calibrate_burn` strictly on `GIANTSOUP-5` (`H1`) for **3 short inner-ring `BURN` legs** (`distance <= 60`, verified fuel-seller destination, conservative fuel allowance `max(2, 2 × ceil(d) + 2)`), returning to `CRUISE` after each leg.
   - Records observed `actual_fuel` and `arrival - departure` timestamps into `observation_history` for `H1`'s exact `FuelPlanner::configuration()` hash.
2. **Randomized Paired 2-Load Blocks (`≥6 blocks = 12 completed loads`):**
   - Each block executes 2 completed loads on the same `(good, source, buyer)` pair where feasible, with randomized order (`[CRUISE, BURN]` or `[BURN, CRUISE]`).
   - Every load starts from a **full tank at the source** to eliminate fuel-level and position carryover.
   - `BURN` loads include the `PATCH /my/ships/{s}/nav` mode-switch latency and API request count in their elapsed time and request total.
   - Crew check (`crew.current >= crew.required`), ownership, settlement, and fuel-continuation checks at `CommandRules.php:50` remain strictly enforced.
3. **Causal Pass Criterion (Unlocks Step 2c on `SOUPBURN`):**
   - Positive median paired Δ in **net CR per wall-clock hour**, AND
   - Non-negative median paired Δ in **net CR per request** (`confirmed load net ÷ attributed load requests`).

---

## 4. Approval Checklist for Taylor
- [x] Approved 2026-11-22: Keep `GIANTSOUP:C0` running untouched as the ≥50% protected control baseline.
- [x] Approved 2026-11-22: Run the optional 3-leg `BURN` calibration + randomized paired `BURN` A/B on arm `H1` (`GIANTSOUP-5`) once Milestone 0 infra ships.
