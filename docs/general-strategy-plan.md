# General Strategy & Remediation Plan

Prepared 2026-11-21 from code review and live telemetry of the `2026-11-15` reset (`MACSOUP`, `X1-VK6` plus six gate-recon systems). This plan applies to **every wipe** — opening hours, mid-wipe compounding, and hard or unprimed starts — and connects directly to [fleet-expansion-plan.md](fleet-expansion-plan.md) and [campaign-autopilot.md](campaign-autopilot.md).

## Executive diagnosis

Hard early starts exposed real market headwinds (unprimed `SCARCE`/`RESTRICTED` factories with inverted spreads), **but code bugs and architectural gaps in our strategy accounted for the majority of lost yield**:

1. **Utilization collapse (10.4% active trading)**: `MACSOUP-1` earned **+166,157 CR** across 50 loads (**631 CR/min** active, zero losses), yet averaged only **71 CR/min wall-clock** across 44.2 hours because 32+ hours were lost to slow discovery, stale buyer/source quotes, and static probe lockup.
2. **Three bugs broke long-range positioning and remote recon**:
   - `CampaignTrade::projectPair` subtracted positioning fuel and never simulated refuelling at a verified fuel-selling source — even though positioning runs and trade departure top-ups both refill there. That falsely rejected long local positionings and 5 of 6 visited recon systems (`X1-YH41`, `X1-SN86`, `X1-AC26`, etc.).
   - `ReconProjection::evaluate` projected `proof` only via single-hop travel from the jump gate, rejecting systems whose gate sits >400 units from the inner trade ring (`X1-BB74`) even when a verified 1-stop refuel path exists.
   - `GateRecon::sample` gated confirmation on `! $unsampled`. In any 25–36 market system with `market_reads_per_visit = 10`, `$unsampled` is never empty, so confirmation was dead code. `MACSOUP-2` discovered `FABRICS X1-GV7-K96 → K95` (**6,785 CR/min, +19,450 CR/load, distance 0**) and jumped away without confirming it. It also wasted reads #2–#4 on bare `FUEL_STATION`s because sampling sorted purely by distance from the jump gate.
3. **Capital allocation starved our fastest ship**: Spending 176.8k CR on 2 probes + a speed-15 Light Shuttle and 47.0k CR on 6 recon jumps drained working capital below what `MACSOUP-1` needed for full 40u high-value loads, while the Shuttle earned back only 22.6k CR before stalling on stale quotes.
4. **Single-leg, CRUISE-only, arbitrage-only design leaves throughput on the table in every wipe**:
   - Empty return legs burn ~40–50% of flight time and fuel.
   - Pure spread-skimming drains exporters without feeding upstream inputs to grow `tradeVolume` and lower asks.
   - Built-in frigate equipment (`MOUNT_GAS_SIPHON_II`, `MOUNT_MINING_LASER_II`, `MOUNT_SURVEYOR_II`) and [ContractPlanner.php](../app/Gameplay/ContractPlanner.php) sit completely idle during spread droughts.

---

## Phased General Plan (Tiers 0–3)

### Tier 0: Fix Confirmed Strategy Bugs (Immediate — Hurts Every Wipe)

| # | Bug | Root cause | Fix | Verification |
| --- | --- | --- | --- | --- |
| T0.1 | Long-positioning & remote-system pairs rejected as fuel-unsafe | [CampaignTrade.php](../app/Gameplay/CampaignTrade.php) subtracts positioning fuel and never models arrival/departure refuel at a verified fuel-selling source | Reset `$atSource['fuel']['current']` to capacity when positioning to a different waypoint whose `FuelPlanner::leg` continuation is `$source` itself (preserving single-tank `$source → $buyer → $source` cycle safety) | Unit/feature regression + `TradeFuelEconomicsTest` + offline replay of visited systems |
| T0.2 | Gate-to-source >1 tank blocks valid remote systems | [ReconProjection.php](../app/Gameplay/ReconProjection.php) checks only single-hop direct travel from the jump gate for `proof` | Fall back to verified 1-stop in-system refuel arrival (`oneStopArrival`) when direct gate-to-source exceeds one tank, keeping direct `position_seconds` whenever direct travel fits | `ReconProjectionTest` regression (gate >1 tank with mid-system fuel stop vs. genuinely impossible cycle) |
| T0.3 | Remote recon never confirms qualifying pairs in normal 25+ market systems | [GateRecon.php](../app/Gameplay/GateRecon.php) requires `! $unsampled` before confirming | Confirm the top qualifying pair whenever `$unsampled` is empty **or** remaining usable visit reads (`market_reads_per_visit - 1 - reads`) are `<=` confirmation reads needed | `ReconLifecycleTest` regression on a 15-market system with `market_reads_per_visit = 10` |
| T0.4 | Remote recon wastes limited reads on bare `FUEL_STATION`s | [GateRecon.php](../app/Gameplay/GateRecon.php) sorts `$unsampled` only by travel time from the probe | Prioritize (0) distance-0 orbital clusters (non-`FUEL_STATION` marketplaces sharing `(x, y)`), (1) standalone trade hubs, (2) `FUEL_STATION`s, tie-breaking by travel time | `ReconLifecycleTest` regression verifying orbital clusters are sampled ahead of closer fuel stations |

*Status: T0.1–T0.4 implemented on branch `t3/early-start-strategy-improvements`.*

---

### Tier 1: Base Strategy Upgrades (Every Wipe, Minute 0 Onward)

These become the **default base strategy for every reset**, raising wall-clock utilization from ~10% toward 70%+ and turning Minute 0 into an immediate, full-visibility launch.

1. **Minute-0 Probe Bootstrap & Multi-Probe Fleet Role (Base Wipe Opening)**
   - **Buy probes immediately at wipe start**: As part of the default base strategy, every reset begins by locating the system's `SHIP_PROBE` shipyard (typically colocated at HQ, e.g., `A2`) and purchasing probes up to a playbook-configured `opening_probe_target` (recommended default: **3 total probes** = 1 starter + **2 purchased immediately** at ~40–42k CR each), bounded by:
     - `max_probe_price` (e.g., `50,000 CR`) so price spikes never drain cash, and
     - `min_working_capital` (e.g., `75,000–85,000 CR`) so the starter frigate always retains enough cash for immediate full-hold proofs and mid-tier loads (`COPPER`, `ALUMINUM`, `AMMUNITION`, `FUEL`, contracts).
   - **Payback math**: Two probes cost ~82k CR. Even at our hard-start `X1-VK6` active rate (631 CR/min), recovering just **2.2 hours** of discovery/stale-quote idle time pays them back completely; in a normal/mature market they pay back in minutes.
   - **Upgrade from singular `probe` to managed `probes[]`**: Replace `CampaignFleet`'s single `probe` slot with a managed pool of all campaign probes, each running its own concurrent `one-run-per-ship` scout/refresh loop:
     - **In `discover`**: Partition unpriced trade hubs (distance-0 orbital clusters and planets/moons/stations first; `FUEL_STATION`s last) across all probes in parallel, cutting full-system pricing from hours to **~15–20 minutes**.
     - **In `earn`**: Keep 1 probe shadowing the active trade's buyer (if unoccupied) while the remaining `N-1` probes rotate continuously across the system's highest-turnover trade hubs so **no active hub goes stale for hours**.
     - **Recon guard**: Only lend a probe to Gate Recon when `available_probes >= 2` (ideally `>= 3` total), guaranteeing the home system never goes blind while exploring neighbors.
   - **Post-opening probe scaling**: Once working capital clears `>= 200k CR` (and before buying slower cargo ships), optionally scale probes up to `min(hub_count / 2, 1 per 4 haulers)` whenever stale-quote idle time exceeds 15%.

2. **Decouple Planning Freshness from Execution Freshness ("Scout-and-Verify")**
   - Keep strict `< 180s` execution freshness before spending credits (`OperationPlanner::quote`), **but** allow `CampaignLocalEarn` and `CampaignTrade` to dispatch positioning/probe verification on quotes up to 30–60 minutes old when no fresh pair is available, aborting cleanly if the spread collapsed on arrival.
   - Eliminate the `discover` bottleneck: lower the early-exit bar from `1,500 CR/min` ([CampaignTrade.php:20](../app/Gameplay/CampaignTrade.php:20)) or make it configurable per playbook (e.g., exit `discover` as soon as any `>= 400 CR/min` trade or positive contract is observed, while the probe pool keeps discovering remaining markets in `earn`).

3. **Chained Multi-Leg / Backhaul Routing**
   - Replace mandatory `source → buyer → empty return` with **next-leg chaining**: when ranking from waypoint `W` (or evaluating a trade ending at `B`), score outbound pairs starting at `B` or any distance-0 neighbor of `B` (e.g., `H49 → A3` Copper → `A3 → D43` Microprocessors → `D43 → H50` Ship Plating, where `H50` is distance 0 from `H49`).
   - Support 1-stop in-system refueling for high-margin outer-ring routes (e.g., `MEDICINE D42 → J56` via `I54`) under a configurable `run_max_minutes` ceiling.

4. **Flight Mode Economics (`BURN` vs. `CRUISE`)**
   - Extend [FuelPlanner.php](../app/Gameplay/FuelPlanner.php) and `OperationPlanner` to evaluate `BURN` (2× speed, ~2× fuel) whenever `extra_fuel_cost < saved_minutes × route_cr_per_min` and fuel continuation remains safe.
   - On inner-ring high-value legs (72–144 CR extra fuel to save 45–90s on a 1,500–6,000 CR/min route), `BURN` increases net CR/hr by 35–60% across the entire wipe.

5. **Capital Allocation & Working-Capital Discipline**
   - Distinguish **high-leverage visibility capital** (opening probes at Minute 0, paid back in ~1–2 hours by unlocking routes) from **premature hull capital** (slow speed-15 Light Shuttles or long-shot recon jumps that starve inventory capital):
     - **Minute 0**: Buy up to 2 probes immediately (target 3 total) while keeping `>= 75k–85k CR` working capital for the frigate.
     - **Early compounding**: Rebuild working capital to `>= 150k–200k CR` per active frigate so 40u high-value loads (`MICROPROCESSORS`, `FABRICS`, `SHIP_PLATING`, `CLOTHING`) are never credit-capped.
     - **Light Shuttle (~95k CR, speed 15, 40u)**: skip unless a confirmed distance-0 cluster loop exists; speed 15 underperforms badly on cross-system legs.
     - **Light Hauler (~450k CR, 80u)**: primary scaling workhorse once working capital + measured spreads support <4–6 hour payback.

---

### Tier 2: Adaptive Market Regimes (Early-Start & Stalled-System Resilience)

Make the director regime-aware so it automatically shifts strategy when pure spot-arbitrage yields drop below a configurable floor (e.g., `< 400 CR/min` or low working capital):

1. **Early-Game Procurement Contracts (Hour 0–4 Seed Capital)**
   - Wire the existing [ContractPlanner.php](../app/Gameplay/ContractPlanner.php) into the campaign playbook as an optional activity gated on `estimated_net > 0`, comfortable deadline headroom, and `estimated_cr_per_min` exceeding the best available trade.
   - Starter contracts provide guaranteed upfront `onAccepted` cash and `onFulfilled` profit independent of unprimed market spreads.

2. **Supply-Chain Priming (Market-Making)**
   - Track `supply` (`SCARCE`/`LIMITED`/`MODERATE`/`HIGH`/`ABUNDANT`), `activity` (`RESTRICTED`/`WEAK`/`GROWING`/`STRONG`), and `tradeVolume` on system exporters.
   - When high-tier exporters (`SHIP_PARTS`, `SHIP_PLATING`, `MICROPROCESSORS`, `ELECTRONICS`, `FABRICS`, `FUEL`) stall at `SCARCE`/`RESTRICTED`, authorize **bounded upstream input deliveries** (break-even or small positive margin, capped per hour) to supply their `SCARCE` imports (e.g., `IRON_ORE`/`ALUMINUM_ORE` → smelters, `COPPER`+`SILICON_CRYSTALS` → electronics/microprocessors, gases → `G48` fuel/plastics/fertilizers).
   - Measure downstream spread recovery and `tradeVolume` expansion in the weekly report so priming spend stays strictly evidence-backed.

3. **Zero-Capital Extraction Floor (Gas Siphoning & Multi-Extraction Surveyed Mining)**
   - **Gas siphoning**: Add a bounded `siphon` strategy using the starter frigate's free `MOUNT_GAS_SIPHON_II` + `MODULE_GAS_PROCESSOR_I` (especially when a `GAS_GIANT` sits at distance 0 from an orbital exchange like `X1-VK6-C39 ↔ C40`, or near a `SCARCE` gas importer like `G48`). Zero working capital, zero spread-decay risk, and primes upstream chemical/fuel factories.
   - **Surveyed multi-extraction mining**: Upgrade [MiningPlanner.php](../app/Gameplay/MiningPlanner.php) from v1's 1-extraction round trip to:
     - Multi-extraction fill-to-headroom trips (jettisoning configurable low-value junk like `ICE_WATER`/`QUARTZ_SAND`).
     - Targeting universal ore buyers (`ASTEROID_BASE` exchanges like `B7` that buy every ore) instead of blocking on the first unexpected deposit.
     - Using `MOUNT_SURVEYOR_II` surveys on `PRECIOUS_METAL_DEPOSITS` / `RARE_METAL_DEPOSITS` waypoints (`MERITIUM_ORE`, `URANITE_ORE`, `GOLD_ORE`, `PLATINUM_ORE`, `DIAMONDS`).
   - Run extraction strictly as an adaptive fallback when trade CR/min drops below threshold or cash is below working-capital floor — never alternating blindly with profitable trade ticks.

4. **Verified Relocation & Remote Shipyard Bootstrapping**
   - Once Tier 0 recon confirms a `promising` neighboring system (e.g., `X1-GV7` or `X1-YH41`), support two bounded entry modes:
     - **Remote ship purchase** (if the target system has a `SHIPYARD` selling haulers/shuttles and cash allows): buy a local trader directly in the better system without paying jump/approach time on the home frigate.
     - **Bounded trader relocation**: multi-hop approach to the jump gate + verified jump (or warp drive if required by server rules) + switching the episode's active system context once arrived.

---

### Tier 3: Scaling to Fleet v2 (Compounding Toward 8B/Wipe)

Tiers 0–2 directly de-risk the v2 fleet model in [fleet-expansion-plan.md](fleet-expansion-plan.md):

- **Shared multi-ship coordinator**: Extend `FleetClaims` + chained backhaul routing so `N` haulers spread across distinct pairs and orbital clusters without self-cannibalizing bids, accounting for in-flight cargo impact on buyer decay.
- **Request-budget tiering**: Reserve API quota (`2 req/s`) strictly in priority order: (1) active trade sells/buys & safety checks, (2) dynamic probe refreshes on active routes, (3) discovery & recon.
- **Automated payback-gated expansion**: Scale probes + haulers (and expand across jump-connected systems once local system absorption flattens) only when rolling realized CR/ship-hour clears the payback threshold over remaining wipe hours.

---

## Rollout Order & Acceptance Gates

Every tier ships behind offline deterministic PHPUnit regressions, read-only replays on protected snapshots (zero live API calls), and explicit operator approval before activation:

1. **Now (Tier 0 — shipped)**: Merge and test T0.1–T0.4 (`CampaignTrade`, `ReconProjection`, `GateRecon`). Eliminates the confirmed fuel-refuel, gate-distance, confirmation-deadlock, and fuel-station-sampling bugs that hurt every wipe.
2. **Next (Tier 1A — Probe Bootstrap & Pool implemented)**: **Minute-0 Probe Bootstrap** (`CampaignProbeBootstrap`, optional `probes:` playbook section + `campaigns/weekly-probe-bootstrap.yaml`, bounded `plan_version = 6` `buy_probe` runs under `max_price` & `min_working_capital + ReconMission::returnExposure` guards) + **Multi-Probe Fleet Pool** (per-ship concurrent runs in `CampaignDirector`, zero duplicate scout targets, non-`FUEL_STATION` trade-hub scout ordering, per-ship `ReconMission` hold isolation). Follow-on Tier 1A items: decoupled planning freshness + distance-0 / chained backhaul routing. Target: full-system pricing in ~15–20 minutes and wall-clock active trading utilization `>= 60%`.
3. **Then (Tier 1B + Tier 2A)**: `BURN` flight mode economics + `ContractPlanner` early-game integration + `siphon` zero-capital fallback.
4. **Finally (Tier 2B + Tier 3)**: Supply-chain priming + surveyed multi-extraction mining + remote relocation / remote shipyard bootstrapping + multi-ship v2 scheduler.
