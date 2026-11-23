# Strategy 4 (Conditional In-Agent Module): `SOUPMAKER` & Shared `GateWatch` Trigger

**Status:** **No 4th agent registered this wipe.** `SOUPMAKER` is scoped as a bounded opt-in module for Day 3+ (or next wipe), and `SOUPGATE` is retired as a standalone agent and replaced by a read-only `GateWatch` trigger.

---

## Part A — Strategy 4: `SOUPMAKER` (Bounded Supply-Chain Priming + Drought Fallback)

### Why Demoted from Day-1 Agent to Bounded Module
Council audit found two decisive reasons not to dedicate a standalone Day-1 agent to `SOUPMAKER` in the remaining 6.5 days:
1. **No existing supply/activity DAG substrate:** Current market observations record prices and `tradeVolume` on the opportunity path, but no supply-chain DAG or priming budget ledger exists yet.
2. **Request budget & signal clarity:** Stages 1–2 allocate 100% of our 5,760 req/hr Tiers 2–4 envelope across `GIANTSOUP`, `SOUPBURN`, and `SOUPFLEET`. Testing priming first as a **bounded single-exporter experiment inside an existing agent** gives a faster, cleaner ROI signal without competing for a 4th agent's director/probe overhead.

### 1. Scoped V1 Experiment: Single-Exporter Supply-Chain Priming
- **Scope:** Select **ONE stalled high-tier exporter** (`supply IN [SCARCE, LIMITED]` and `activity IN [RESTRICTED, WEAK]`, e.g., `SHIP_PARTS`, `SHIP_PLATING`, `MICROPROCESSORS`, `FABRICS`, `FUEL`) and **ONE required upstream input** from SpaceTraders' supply-chain map (`exports ← imports`).
- **Hard Bounds:**
  - Explicit per-hour credit spend cap (e.g., `≤25,000 CR/hr` max priming subsidy / break-even allocation),
  - Explicit test duration window (e.g., `6 hours`),
  - Bounded to at most 1 designated ship (never touching `GIANTSOUP:C0`).
- **Measurement & Automatic Stop Rule:**
  - Track downstream `supply`, `activity`, `tradeVolume`, and **incremental realized net margin** on the primed exporter compared against an unprimed comparable route in the same system.
  - **Auto-Stop:** Automatically disable priming if incremental net margin (after priming cost) is negative at the end of the 6h window or if the system's exporter fails to advance `activity` / `tradeVolume`.

### 2. Separate Drought Fallback (Zero-Capital Extraction & Optional Contracts)
Wired strictly as a separate low-spread fallback (triggered only when best trade CR/min drops below threshold or cash falls below working-capital floor), with its own earnings and request telemetry:
- **Zero-Capital Gas Siphoning (`MOUNT_GAS_SIPHON_II`):** Uses the starter frigate's free gas siphon + processor when a `GAS_GIANT` sits near a gas buyer/exchange (`HYDROCARBON`, `LIQUID_HYDROGEN`, `LIQUID_NITROGEN`). Zero working capital, zero spread-decay risk.
- **Early-Game Procurement Contracts (`ContractPlanner.php`):** Uses our already-built `ContractPlanner` for guaranteed `onAccepted` + `onFulfilled` seed capital when `estimated_net > 0` and `estimated_cr_per_min` beats best available trade.
  - **Approved governance gate (2026-11-22):** Taylor reversed the blanket contract prohibition for this bounded drought fallback. Use the existing `ContractPlanner` only when `estimated_net > 0` and `estimated_cr_per_min` beats the best available trade. This remains opt-in and off by default. Implementing the Day 3+ module requires its own bounded configuration.

---

## Part B — Shared `GateWatch` Trigger (Replaces Retired `SOUPGATE` Agent)

### Why Standalone `SOUPGATE` Agent Was Retired
- Solo Jump Gate construction requires `1,600 FAB_MATS` + `400 ADVANCED_CIRCUITRY` (verified live per gate; **not** fixed constants—note exact symbol `ADVANCED_CIRCUITRY`), equaling `≥25` full 80u hauler loads and roughly **3.2M–10M+ CR** in materials with **zero direct payout** and **public access** for every player in the system.
- Over the remaining ~6.5 days of this wipe, solo construction has negative expected payback vs deploying that capital and fleet capacity into local trading.

### Council-Approved `GateWatch::pollGate()` Design (Strictly Read-Only)
- **Entry Point:** New dedicated bounded read-only method `GateWatch::pollGate()`—**never** calling `GateRecon::start()`, **never** calling scheduled `GateRecon::approve(..., dryRun: true)` (which mutates local agent/ship state and catalogues connected systems), creating **zero** `ReconMission` or `AutomationRun` rows, and dispatching **zero** upstream writes.
- **Correct Polling Endpoint (`spacetraders-openapi-2.3.0.json:3145, 3372` & `GateRecon.php:415`):**
  - Uses its **1-read-per-30-minute Tier 4 budget** to poll **`get-waypoint`** on the local Jump Gate (which exposes `isUnderConstruction` both before AND after completion—unlike `get-construction`, which fails once `isUnderConstruction === false` and would miss completion between polls!).
  - Takes priority whenever it uses the full Tier 4 allowance; only when `isUnderConstruction === true` may an optional bounded `get-construction` progress read run within the Tier 4 cap.
- **Configuration:** Set `CAMPAIGN_GATE_WATCH_WAYPOINT` to GIANTSOUP’s local Jump Gate to enable polling. Set `CAMPAIGN_GATE_WATCH_PROGRESS=true` to allow the optional progress read.
- **Notifications & Operator Escalation Only:**
  - Alerts the operator (dashboard/Discord) if local gate progress crosses **≥80%** (by system-mates) OR flips **`isUnderConstruction: true → false`** (gate open!).
  - Any subsequent on-demand `GateRecon::approve(..., dryRun: true)` (counted in Tier 2–4 budget), live recon jump, co-build contribution, or remote shipyard purchase (which requires an owned ship present at that remote yard) requires **separate explicit operator approval**—never automatic this wipe.
- **Required Unit Tests:** Explicitly assert (1) the `isUnderConstruction: true → false` completion detection transition, (2) zero `ReconMission` or `AutomationRun` records created, and (3) zero upstream write requests dispatched.

---

## Approval Checklist for Taylor
- [x] Approved 2026-11-22: keep `SOUPMAKER` as a conditional Day 3+ bounded in-agent module (no 4th agent this wipe).
- [x] Approved 2026-11-22: allow the bounded `ContractPlanner` drought fallback under the positive-net and better-than-trade gates above. Opt-in, off by default.
- [x] Approved 2026-11-22: retire standalone `SOUPGATE` in favor of the read-only `GateWatch::pollGate()` trigger.
