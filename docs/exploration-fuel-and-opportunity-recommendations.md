# Recommendations for future exploration, fuel, mining, and trade algorithms

Prepared 2026-11-01 from the [exploration and opportunity research](exploration-fuel-and-opportunity-research.md). This document proposes future changes. It does not enable automation or establish profitable routes for the current fleet.

Start with local information and route feasibility. Then improve the loop whose measured bottleneck matters most. Keep the existing preview, start, stop, spending, and reconciliation controls throughout.

The linked research contains the official API sources, dated Reddit discussions, community estimates, and application assessment. Recheck API contracts before implementation. Treat numerical thresholds below as settings to choose from measurements, not game rules.

## Choose the next update

Use this development order unless observed fleet performance supports a different choice:

1. Complete the local catalogue and distinguish missing information from poor opportunities.
2. Add fuel-safe route planning and explain which observations would improve a decision.
3. Add bounded scouting for those observations.
4. Improve mining loads or implement a small trade loop according to measured complete-cycle returns.
5. Add probes, dedicated surveyors, haulers, refining, or wider exploration when their incremental benefit justifies the cost.

If fresh prices already support a feasible trade, evaluate that trade before further scouting. If a miner has no verified buyer, find a buyer before extending extraction. Avoid a fixed rule that always prefers mining, trading, or exploration.

## R01. Build on the existing observations

Extend the existing waypoint and market snapshots into an explicit account of what is known.

- Record whether waypoint pagination completed and which reset the catalogue belongs to. Describe a complete listing as complete known geography, with hidden traits still unresolved.
- Keep listing, scan, chart, and market-price observations distinct. Preserve uncharted waypoints as candidates with unknown traits.
- Fetch remotely available geography and goods directories before assigning travel. Use scans when hidden information could change a decision.
- Separate market goods listings from detailed price observations. A response without prices must not erase the last known prices or refresh their timestamp.
- Preserve timestamped market history and extraction outcomes alongside the latest snapshot. Record the observing ship when available.
- Invalidate planning evidence when its reset changes. Refresh dynamic hazards and market conditions independently of mostly static coordinates.

The existing `GameObservation` table stores the latest observation for each agent, kind, and symbol. Its timestamps alone cannot establish price trends. The current discovery limits also need an explicit partial-coverage state.

Accept this update when a partially fetched system, an uncharted waypoint, and a remotely observed market with no prices each remain visibly incomplete.

Basis: [mapping](exploration-fuel-and-opportunity-research.md#map-the-system-without-spending-exploration-fuel), [market freshness](exploration-fuel-and-opportunity-research.md#observe-markets-and-keep-honest-freshness), and [current app assessment](exploration-fuel-and-opportunity-research.md#what-this-app-already-has-and-what-the-research-suggests-next).

## R02. Require a feasible continuation after every exploration leg

Evaluate the destination and the next usable fuel stop together.

- Model route feasibility using location, ship configuration, flight mode, tank capacity, current fuel, available credits, and known refueling options.
- Require enough fuel for the proposed leg and a feasible continuation to a verified fuel seller, with an explicit uncertainty reserve.
- Check fuel listings, price age, affordability, and arrival state. A marketplace or station label alone is insufficient.
- Compare direct travel, refueling stops, and supported flight modes. Show fuel cost and elapsed time separately.
- Account for empty returns, positioning, and time displaced from useful work. Include extra stops only when the complete plan benefits.
- Keep tank units and cargo units of `FUEL` distinct. Budget conservatively for purchase rounding until actual responses establish its behavior.
- Add cargo refueling as an escape option only after its location and state rules have been verified.

Record predicted fuel and duration beside actual consumption and server timestamps. Tag estimates with their source and supported ship configurations. Treat the community wiki equations as candidate models to validate, including unusual or zero-capacity ships.

The current app supports CRUISE navigation and a conservative local distance estimate. Other modes and general refueling routes require explicit support.

Accept this update when it rejects an affordable outbound leg that leaves no feasible continuation. A rescue drift must trigger replanning rather than repeat the original failing route.

Basis: [fuel planning](exploration-fuel-and-opportunity-research.md#conserve-fuel-without-guessing-navigation-formulas) and the [Reddit discussion linking to community calculations](https://www.reddit.com/r/SpaceTradersAPI/comments/1f53i6m/how_to_see_fuel_requirements_before_moving/).

## R03. Scout to answer a specific question

Generate observation tasks from decisions that currently lack evidence.

- Identify the missing fact, the opportunities it affects, and the action it could change.
- Prefer nearby clusters where one visit can collect prices, reveal traits, refuel, or sell existing cargo.
- Estimate travel, fuel, API use, and displaced earnings. State when the expected information benefit is unknown.
- Set travel, spending, visit-count, and elapsed-time limits before starting.
- Re-evaluate after each useful observation. Stop when the question is answered or the budget is exhausted.
- Consider a parked probe for repeatedly important markets. Compare purchase and deployment costs with avoided scouting and stale-price losses over the remaining reset.

Use a clear explanation such as "observe this buyer because its unknown ore price prevents comparison of two nearby mining sites." Avoid scanning or visiting every waypoint by default.

Accept this update when every proposed visit has a purpose, an affordable continuation, and a stopping condition. An unknown market price must remain unknown until observed.

Basis: [exploration sequence](exploration-fuel-and-opportunity-research.md#a-practical-exploration-sequence) and [selective probe use](exploration-fuel-and-opportunity-research.md#use-probes-selectively).

## R04. Improve mining loads before assuming more ships are needed

Evaluate bounded multiple-extraction trips against the current one-extraction round trip.

- Measure yield mix, extraction sizes, cooldowns, travel time, sale proceeds, fuel, and idle time.
- Choose a departure policy using cargo headroom, a maximum extraction count, elapsed time, hazards, and buyer conditions. Avoid "always fill the hold."
- Keep enough headroom for uncertain yields. When the next extraction cannot be justified within capacity and risk limits, return or pause.
- Evaluate all outputs and their buyers. Preserve low-value and unsold outcomes in the performance record.
- Refresh waypoint modifiers as well as traits. Check `CRITICAL_LIMIT`, `UNSTABLE`, and `STRIPPED` before continuing and after relevant responses.
- Rotate or pause on unsafe conditions, destabilization, or no-yield responses. Keep survey exhaustion separate from asteroid condition.
- Overlap permitted extraction cooldown with travel when useful. Use the actual timeline when calculating throughput.
- Retain unwanted cargo unless disposal is explicitly part of the approved policy.

Before longer runs, address the source-level hazard gap documented in the research: `MiningPlanner::route()` checks traits for `UNSTABLE`, while the API represents it as a modifier.

Accept this update when fuller loads improve measured net returns under comparable conditions without breaching capacity, budgets, or hazard limits. Fuel savings per cargo unit alone do not prove better earnings per hour.

Basis: [mining opportunities](exploration-fuel-and-opportunity-research.md#find-mining-opportunities-by-saleable-output) and the [illustrative fuel comparison](exploration-fuel-comparison.html).

## R05. Add surveys and hauling in response to measured constraints

For surveys, preserve the original signed object, including duplicate deposits. Build a separate summary for ranking its entire resource mix against reachable buyers. Account for survey creation time, expiry, exhaustion, and mining capability. Do not treat duplicate counts as exact probabilities or survey size as a guaranteed number of uses.

Compare surveyed and unsurveyed extraction over complete cycles. Prefer a dedicated surveyor only when its contribution exceeds its purchase, positioning, and operating costs. Share exhaustion state across local users of a survey.

For hauling, compare combined miner output during a hauler round trip with usable hauling capacity. Include transfer waits, matching ship states, market batching, and the return leg. Add hauling capacity when miners are blocked on full holds and the buyers can absorb additional output profitably.

The current command wrapper needs new support for surveys and cargo transfers. A planner setting alone cannot enable these roles.

Accept these updates when expired or exhausted surveys stop being assigned, transfers respect both ships' state and capacity, and projected fleet gains include the added ship's costs.

Basis: [surveys](exploration-fuel-and-opportunity-research.md#surveys-preserve-weighted-deposits), [hauling](exploration-fuel-and-opportunity-research.md#refining-and-a-miner-hauler-split), and the [player log describing hauling congestion and oversupply](https://staffordwilliams.com/dev/spacetraders-v2/2024-w13/).

## R06. Trade in batches and re-evaluate the remaining cargo

Match candidate sources and buyers by trade symbol, then evaluate actual prices and feasible routes.

- Use source `purchasePrice` and destination `sellPrice`, with separate observation times.
- Respect the current `tradeVolume` at each endpoint for each transaction. Do not interpret it as total stock or demand.
- Bound purchases by free cargo, affordable inventory, the protected operating reserve, and approved spending limits.
- Refresh between batches. Stop further purchases when revised prices no longer support the plan.
- Re-evaluate cargo already bought separately. Compare retaining, selling locally, and traveling to another buyer using the remaining costs and expected proceeds.
- Include an empty return unless another independently viable trade covers it.
- Keep maximum inventory exposure and a policy for unsold cargo. A protected credit balance does not make a large illiquid cargo position harmless.

Use production relationships to identify candidate routes, not guaranteed profits. Evaluate refining against the value of the raw inputs and its delay. Treat production-chain support as a separate investment whose benefits may also reach other agents.

Accept this update when a deteriorating spread stops subsequent purchases and leaves an explicit plan for existing cargo.

Basis: [trade execution](exploration-fuel-and-opportunity-research.md#find-trade-opportunities-that-survive-execution).

## R07. Rank comparable complete cycles

Use realized net credits per elapsed hour as the main operating comparison. For a closed cycle, deduct goods acquisition, fuel, repairs, jump charges, and other operating costs from sale receipts. Compare equivalent starting and ending location, tank fuel, and inventory, or disclose the adjustments.

Keep these additional measures beside that result:

- Cash required, protected reserve, and inventory still awaiting sale.
- Fuel consumption and purchased fuel cost.
- Travel, extraction, selling, API waits, and idle time, with overlapping work counted once.
- Yield sample count, observation age, and actual price changes.
- Fleet net income and income per ship-hour for plans with multiple ships.
- Separate ship and equipment capital, plus incremental payback relative to the best use of existing assets.

Show estimated, realized, and unresolved results separately. Value remaining inventory conservatively at a reachable buyer after remaining costs. Keep unpriced cargo uncertain. Use either realized wear costs or an explicit allowance consistently, without counting both for the same wear.

For estimates, show a conservative case alongside the base case. Do not invent a confidence percentage without a calibrated model. Select quote-age limits, reserves, sample budgets, and minimum returns from measurements and Taylor's preferences.

Accept this update when routes can be compared without hiding empty returns, unmatched fuel levels, unsold inventory, or extra fleet capital.

Basis: [complete-cycle accounting](exploration-fuel-and-opportunity-research.md#compare-complete-cycles-in-credits-per-hour).

## R08. Preserve execution controls as the planner expands

Keep proposals and execution separate. Put feasibility arithmetic in deterministic code, even if an adviser proposes or explains plans.

- Recheck ship state, credits, prices, hazards, and remaining budgets before dispatch.
- Share request limits across manual commands, discovery, and automation. Respect returned rate-limit guidance.
- Serialize commands per ship and coordinate shared credits, transfers, and survey use.
- Persist arrival and cooldown deadlines. Recheck server state before the next action.
- After an uncertain write, reconcile actual state before retrying. Pause unresolved outcomes.
- Preserve explicit start and stop controls, purchase previews, protected reserves, maximum losses, and reset invalidation.
- Explain why a candidate was selected, rejected, or paused, including missing evidence.

Accept this update when a timeout cannot silently duplicate a purchase or extraction, a reset invalidates old intentions, and a stop request prevents subsequent commands after any pending action settles.

Basis: [execution constraints](exploration-fuel-and-opportunity-research.md#execution-constraints-for-a-future-implementation) and the [Reddit experiment reporting capacity and fuel mistakes](https://www.reddit.com/r/LocalLLaMA/comments/1f09bn4/i_used_gemini_flash_to_autonomously_complete_the/).

## Review each algorithm update against these cases

Use deterministic fixtures for decision rules, then compare against bounded authorized gameplay for behavioral claims:

- Incomplete pagination and uncharted traits do not become a claim of complete knowledge.
- A market refresh without prices preserves the prior detailed quote and its original age.
- An outbound leg that strands the ship fails before dispatch.
- A fuel-price detour includes its own travel costs and displaced time.
- A full hold, unstable modifier, or exhausted survey stops the relevant action.
- A falling spread halts additional purchases while preserving a plan for owned cargo.
- A slow hauler and saturated buyer prevent an unsupported recommendation to add miners.
- An uncertain write, external cargo change, stop request, or world reset prevents blind continuation.

Record the baseline, proposed change, operating limits, observed result, and remaining uncertainty. Promote an algorithm only after it improves its intended measure within those limits.
