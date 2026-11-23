# Strategy 3: `SOUPFLEET` — Payback-Gated Hauler Snowball (Fleet v2)

**Role:** Core Agent #3 — Second New Agent (Automated Multi-Hauler Compounding)\
**Agent Symbol:** `SOUPFLEET`\
**Faction:** `VOID` (held constant with `GIANTSOUP` and `SOUPBURN`; actual HQ system recorded on registration)\
**Starting Capital:** Fresh reset starting credits + 1 Command Frigate + 1 Starter Probe (must earn its own first hauler + working-capital buffer; no credit transfer)\
**Request Cap:** `0 req/hr` in Stage 1 → **1,800 req/hr (31.25%)** in Stage 2 (+ 300 req/hr Tier-1 settlement reserve).

---

## 1. Strategic Hypothesis

Tests our Campaign v2 scaling engine (`docs/fleet-expansion-plan.md`) in live production without risking `GIANTSOUP`:
- **Clean Policy Freeze:** `SOUPFLEET` inherits the **exact frozen routing, quote, and flight-mode policy validated on `SOUPBURN`** at launch time (Step 2c Chained `BURN` if it has passed its gate; otherwise Step 2b Chained `CRUISE`). That isolates **automated payback-gated fleet expansion** as the single added mechanism.
- **Compounding Workhorse:** Reinvests earned surplus into **80-unit Light Haulers (`SHIP_LIGHT_HAULER`)** plus supporting probes (~1 probe per 3–4 haulers), adding **one hauler at a time** (with `SHIP_LIGHT_SHUTTLE` permitted ONLY if a confirmed distance-0 orbital cluster loop exists).

---

## 2. Hard Launch Gate (All Four Conditions Required)

`SOUPFLEET` launches ONLY when ALL four gates pass before the **`2026-11-27 13:00Z`** cutoff:
1. **Request Budget Phases 4–5 deployed** (agent reads only on credit-changing writes; freshness-gated market reads),
2. **Measured all-in rate `≤300 req/active-trader-hr`** (probes in numerator only),
3. **`GIANTSOUP:C0` stability verified:** rolling 3h CR/hr holds within 15% of baseline for `≥6 hours` after `SOUPBURN` began trading,
4. **`SOUPBURN` validation passed:** Step 2b (Chained `CRUISE`)—and Step 2c (Chained `BURN`) if ready—has cleared its ≥6h / ≥10-load acceptance gate.

---

## 3. Automated Hauler Buying Rule & Formula-Only Fleet Cap

`SOUPFLEET` generalizes our bounded `buy_probe` (`plan_version = 6`) pattern to automated `buy_hauler` purchases, checking **all five guards** before every purchase:

1. **Formula-Derived Request-Budget Cap (Sole Binding Fleet Limit):**
   $$\text{max\_traders}(\text{SOUPFLEET}) = \left\lfloor \frac{1{,}800 - \text{agent\_overhead\_req\_hr}}{\text{trader\_only\_req\_per\_active\_trader\_hr}} \right\rfloor$$
   - Counts ALL active traders (`starter frigate + haulers + shuttles`).
   - Uses trailing 6h measured `trader_only_req_per_active_trader_hr` and `agent_overhead_req_hr` (`probes + director + agent-attributed shared`, counted once—zero double-counting).
   - **No contradictory fixed fleet maxima.** (For illustration only: at 250 trader-only req/hr and 300 overhead, the formula admits `floor(1500/250) = 6` total traders; at 180 trader-only req/hr and 250 overhead, it admits `8`.)
   - **Sample-Integrity Hold Guard:** Automatically **holds all ship purchases** if the trailing 6h sample is missing, has `< 1.0` active trading ship-hour, `trader_only_req_per_active_trader_hr <= 0`, or agent-scoped `FleetMetrics` attribution is incomplete.
2. **Paced Ramp-Up:** During the first 12 hours after launch, adds **at most 1 hauler per 6-hour evaluation window**.
3. **Full-Fleet Working-Capital Floor:**
   $$\text{cash} \ge \text{ship\_price} + (\text{active\_traders} + 1) \times \text{working\_capital\_per\_trader}$$
   (Default `working_capital_per_trader = 120,000–150,000 CR`, preventing capital starvation on existing ships.)
4. **Positive Incremental Net Earnings & Payback Gate:**
   - Estimated *incremental* net CR/hr (accounting for buyer bid decay, fuel, probe overhead, and displaced trades) must be `> 0`.
   - Payback constraint:
     $$\frac{\text{ship\_price}}{\text{incremental\_net\_cr\_per\_hr}} \le \min(12\text{ hours},\ 0.35 \times \text{hours\_remaining\_in\_wipe})$$
5. **Wind-Down Stop:** Zero ship purchases once payback exceeds `0.35 × hours_remaining` or during wind-down.

---

## 4. Multi-Ship Coordination (`FleetClaims` v2)

- **System-Keyed Cross-Agent Claims:** `FleetClaims` keys claims by each ship's actual trading system (`ship.nav.systemSymbol`) across all of our agents operating there.
- **In-Flight Cargo & ETA Decay Projection:** Every active trade claim stores `(good, buyer, in_flight_units, estimated_arrival_at)` so subsequent haulers ranking the same `(good, buyer)` incorporate projected buyer bid decay before committing capital.
- **Per-Ship Safety Isolation:** A losing load or blocked run pauses/recovers only that hauler; only unexplained agent credit changes or uncertain writes pause the entire `SOUPFLEET` agent.

---

## 5. Evidence Classification & Next-Wipe Behavior

- **Observational Time-Series Label:** Comparing `SOUPFLEET`'s pre-purchase vs post-purchase earnings is explicitly labeled **observational (within-agent time-series)** because market spreads and system supply evolve over time; results are reported alongside concurrent `GIANTSOUP:C0` and `SOUPBURN` windows.
- **Next-Wipe (`2026-11-29 13:00Z`) Release:** Held at Minute 0 during the opening discovery burst; released at **Hour +6** once live new-wipe `fleet:scorecard` confirms `≤300 all-in req/active-trader-hr` and `GIANTSOUP:C0` healthy (Case 1). Held longer if Case 2 or Case 3 fallback applies.

---

## 6. Approval Checklist for Taylor
- [ ] Approve registering and launching `SOUPFLEET` (`VOID`) once Stage 2 Gate + `SOUPBURN` Step 2b validation pass (before `2026-11-27 13:00Z`).
- [ ] Approve automated payback-gated `SHIP_LIGHT_HAULER` purchasing under the formula-derived fleet cap and working-capital floor.
