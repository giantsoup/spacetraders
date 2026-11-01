# Exploring efficiently and choosing the next SpaceTraders automation

Retrieved 2026-11-01. Research only for `/Users/Taylor/Sites/spacetraders`.

This note uses current official documentation, the public OpenAPI document, public first-party data endpoints, the `SpaceTradersAPI/api-docs` repository, and dated Reddit and player reports. It contains no fleet inspection, authenticated requests, credentials, gameplay actions, or measured local prices. No particular asteroid, commodity, route, ship purchase, or profit rate can be declared best with the information supplied.

## Recommendation

Build local intelligence before adding another money-making loop. The first useful result is a complete local waypoint catalogue, a market goods directory, a fuel-safe shortlist of visits, and an opportunity list that shows missing evidence. A ship does not need to fly to every waypoint just to learn its position or known traits. Listing, scanning, charting, surveying, and observing prices solve different problems. [Waypoint listing and exploration operations][spec], [waypoint model][waypoint], [market model][market]

My recommended order is:

1. Catalogue the current system and its known markets without moving a ship.
2. Plan a bounded scout trip to collect the missing prices or hidden traits that matter to a specific decision.
3. Improve mining or start a small trade loop according to measured net returns over a complete cycle.
4. Add surveyor, hauler, probe, refinery, or intersystem expansion only when the measured bottleneck justifies the capital.

This is a policy recommendation. The current app already stores local waypoints and market snapshots. The application assessment below identifies which parts need extending.

## Evidence and limits

The labels used throughout are deliberate:

- **Verified mechanics** means the inspected first-party source documents the behavior. It does not mean this research exercised the behavior in the game.
- **Observed public data** means an unauthenticated GET returned the stated result during this research.
- **Policy** means a proposed client rule or operating procedure.
- **Inference** means a conclusion from those sources that the server has not explicitly guaranteed.
- **Unknown** means the inspected sources do not establish the answer.
- **Community reports** are dated anecdotes and estimates. They suggest strategies to test, rather than establish current mechanics or profits.

The served OpenAPI reports `v2.3.0`. The first-party repository's `main` resolved to `45fbb04130aca3fa0bd9a634ab77b35fa6c468ab`, whose commit date is 2025-04-27. Retrieval in November 2026 does not make every explanatory paragraph newly maintained. The served specification also has response details absent from the pinned repository, including additional transfer cargo data. Use the served specification for current request and response shapes; use pinned models for reproducible field definitions. These are related documentation sources, not independent server implementations. [Served OpenAPI][spec], [pinned repository][repo]

The official-source research retrieved documentation and these public data endpoints: `/v2/documentation/json`, `/v2/error-codes`, and `/v2/market/supply-chain`, plus public GitHub files and repository metadata. The supply-chain GET succeeded without a token even though its OpenAPI operation declares `AgentToken`. That is an observation, not an authentication guarantee. No local system identifier, ship loadout, credits, inventory, survey, or price series was supplied. [Supply-chain response][chain], [error catalogue][errors], [OpenAPI security declarations][spec]

The documentation repository does not expose the game server's economic or navigation implementation. A community wiki linked from Reddit gives candidate fuel and time formulas, discussed below. No authoritative equation was found for travel duration, distance rounding, flight-mode multipliers, extraction quantity, survey selection probabilities, depletion thresholds, market price response, or production rates. Numerical accounting below defines comparisons; it does not claim to reproduce those game mechanics.

## Map the system without spending exploration fuel

### What each operation actually reveals

Verified mechanics:

| Operation | Information obtained | Documented requirements and costs | Important limit |
| --- | --- | --- | --- |
| `GET /systems/{systemSymbol}` | System coordinates and a waypoint position/type directory | Read request; system details require the system to have been visited or charted | `SystemWaypoint` omits the full trait/market information |
| `GET /systems/{systemSymbol}/waypoints` | Paginated waypoint details, including coordinates, orbitals, known traits, and optional modifiers/chart | Read requests; optional `type` and `traits` filters | Uncharted waypoints return `UNCHARTED` instead of actual traits |
| `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}` | One waypoint's details | Read request | The same hidden-trait restriction applies |
| `POST /my/ships/{shipSymbol}/scan/waypoints` | Detailed waypoints within sensor range, including traits despite uncharted state | Installed Sensor Array; ship enters cooldown | Range is limited; this is not documented as globally charting the results |
| `POST /my/ships/{shipSymbol}/chart` | Chart and revealed waypoint details at the ship's location | Ship must be at that waypoint; first chart earns a one-time rarity-based credit reward | Everyone gains trait visibility; another agent may chart it first |
| `POST /my/ships/{shipSymbol}/scan/systems` | Nearby system information and distance | Sensor Array and cooldown | The current `ScannedSystem` model contains no waypoint list despite broader operation prose |

Sources: [system and waypoint operations][spec], [system model][system], [system waypoint model][system-waypoint], [scanned waypoint model][scanned-waypoint], [scanned system model][scanned-system], [chart model][chart]. These operations are listed for future planning; none was executed in this research.

Calling scanning "expensive" should mean equipment, cooldown, API budget, and the ship's displaced work. The inspected scan operation does not publish a credit charge or fuel-consumption formula. A list request does not command movement or consume a ship action cooldown. Scanning is valuable when hidden traits change the next decision; repeating it to redraw already known positions wastes a ship action. [Scanning and listing operations][spec]

### Pagination and filtering

Verified mechanics: waypoint lists use `page`, starting at 1, and `limit`, default 10 and maximum 20. Responses include `meta.total`, `meta.page`, and `meta.limit`. The `type` filter accepts a waypoint type. `traits` accepts either one trait or an array. The schema does not clearly define multi-trait AND/OR behavior or the serialization style. [Waypoint list operation][spec], [pagination model][meta], [waypoint types][waypoint-type], [trait symbols][trait-symbol]

Policy:

- Start with a complete, unfiltered local catalogue using `limit=20`, and fetch pages until the reported total is covered. Deduplicate by waypoint symbol and record completion time. Do not label the system fully mapped after page one.
- For a quick targeted lookup, a single `traits=MARKETPLACE` or `traits=SHIPYARD` filter is useful. Use separate requests or local filtering when correctness depends on multi-trait semantics.
- Keep `UNCHARTED` waypoints in the catalogue. A market filter cannot establish that a hidden waypoint lacks a market. Treat hidden traits as unknown.
- Include the asteroid types actually returned. The current enum includes `ASTEROID`, `ENGINEERED_ASTEROID`, `ASTEROID_BASE`, and `ASTEROID_FIELD`; the quickstart's engineered-asteroid query is an example, not a complete mining census. Neither a type nor a deposit trait alone proves that the current ship can extract there. [Type enum][waypoint-type], [mining quickstart][quick-mine], [extraction errors][errors]
- Store listing evidence, scan evidence, and chart evidence separately. A scan can reveal useful traits without proving that the public chart now exists. Re-read a waypoint after charting or a chart race.
- Revisit unresolved `UNCHARTED` records with occasional read requests. Another agent's chart may reveal the traits before our scout needs to move. The public chart error is `4230 WaypointChartedError`; re-read rather than retrying for a duplicate reward. [Chart operation][spec], [error catalogue][errors]

Use waypoint coordinates within their system. System coordinates belong to the larger map. The `Waypoint` and `SystemWaypoint` models explicitly call waypoint positions system-relative, despite inconsistent wording in `ScannedWaypoint`. Nearby or co-located orbitals are useful visit candidates, but zero coordinate distance does not prove zero travel time or fuel. [Waypoint][waypoint], [system waypoint][system-waypoint], [scanned waypoint][scanned-waypoint]

### A practical exploration sequence

Policy:

1. Locate known fuel sellers, markets, shipyards, mining candidates, uncharted waypoints, and any jump gate from the catalogue.
2. Fetch market goods directories for known marketplaces. This can reveal likely buyers, sellers, and fuel listings before any ship visits.
3. Form a small question-driven shortlist, such as "where can this miner sell its likely ores?" or "is the farther buyer worth the extra trip?"
4. Visit the few locations whose missing information could change the choice. Prefer useful clusters and visits that combine a price observation, chart, refuel, or sale.
5. Scan from an existing useful position if hidden nearby traits warrant the cooldown. Travel only when the likely value of the information exceeds its fuel, time, and displaced income.

The last comparison is an inference about operating cost. Exploration and chart rewards may be worthwhile, but their value cannot be estimated here because neither actual hidden traits nor reward amounts were supplied. Do not budget an unearned chart reward as return fuel money.

## Observe markets and keep honest freshness

### Listings are not prices

Verified mechanics: `GET …/market` returns imports, exports, and exchange goods. Detailed `tradeGoods` and recent `transactions` require a ship at the market waypoint. These fields are optional in the market model. Official guidance explicitly describes parking cheap probes in orbit to monitor prices, so docking is not required merely for price visibility. Buying, selling, and ordinary market refueling require docking. [Market operation][spec], [market model][market], [market visibility guide][markets]

`purchasePrice` is what the player pays to buy. `sellPrice` is what the market pays the player. A goods directory tells us which routes are candidates; it supplies no spread. A visible recent transaction is a historical trade, not a current offer. [Market trade good model][market-good], [transaction model][market-transaction]

Policy:

- Maintain two records per market: the known goods directory and timestamped detailed observations. A response without `tradeGoods` must not overwrite older detailed prices with an empty "current" market.
- Record observation time, waypoint, observing ship if known, and all returned prices, `tradeVolume`, supply, and activity. `MarketTradeGood` has no quote timestamp or validity duration. A transaction's timestamp does not date the surrounding quote. [Market model][market], [market trade good model][market-good], [transaction model][market-transaction]
- Label opportunities as goods-match only, priced but stale, recently observed, or locally executable after a fresh check. Those labels are client policy, not API states.
- Refresh on arrival and before buying. If the destination has no parked observer, a pre-departure buyer quote remains uncertain until the cargo ship arrives. Price monitoring reduces uncertainty; it does not reserve the price or future demand.
- Refresh between trade batches, after local trades, and when a planned trip becomes late. Other agents can trade while our ship travels.

No official safe quote lifetime was found. A fixed five-minute cache must not be represented as a game guarantee. Set refresh policy from observed volatility, trip duration, transaction size relative to `tradeVolume`, and the cost of being wrong. If the price history is absent, display "unknown" rather than a fabricated confidence percentage.

### Use probes selectively

Inference: a parked observer can provide repeated visibility without repeatedly diverting a working miner or hauler. Policy: cover the busiest source, buyer, or fuel hub first. Compare probe purchase and positioning costs with the expected reduction in stale-price losses and wasted trips. A probe at one endpoint does not cover the other. [Official probe use][markets]

Do not assume every probe is free to move, available locally, or worth buying. This research retrieved no shipyard offers or actual probe fuel capacity. Inspect the actual ship or offer later. The schema describes capacities and equipment, not one universal affordable starter fleet. [Shipyard ship model][shipyard-ship], [fuel model][fuel]

## Conserve fuel without guessing navigation formulas

### Flight modes and timing

Verified mechanics:

| Mode | Documented speed | Documented fuel use | Suggested policy |
| --- | --- | --- | --- |
| `CRUISE` | Normal; default | Normal | Baseline for a measured economic route |
| `BURN` | Faster | Higher consumption rate | Use only when the value of earlier arrival covers extra fuel and wear |
| `DRIFT` | Much slower | Least fuel | Candidate for low-value repositioning or fuel scarcity when the delay is acceptable |
| `STEALTH` | Reduced | Normal | No fuel-saving case is established by these docs |

The mode descriptions are qualitative. No numeric multiplier, minimum cost, or universal zero-fuel DRIFT guarantee is established here. Higher engine speed reduces travel time. The server returns expected arrival and actual fuel state, including the most recent consumed amount when supplied. [Navigation guide][nav], [engine model][engine], [fuel model][fuel], [route model][route]

The February 27, 2025 changelog allows an in-transit switch to a faster mode at additional fuel cost and says slowing in transit throws an error. The public error catalogue includes `4269 CantSlowDownWhileInTransitError`. Policy: choose the mode before departure; do not plan to burn first and switch to drift to recover fuel. [Changelog][changes], [error catalogue][errors]

### Tank units, cargo fuel, and rounding

Verified mechanics: one market or cargo unit of `FUEL` replenishes 100 tank units. The refuel request's `units` means tank units to add, not cargo units. Omitting `units` requests a full tank; a request above the remaining capacity is capped at capacity. `fromCargo=true` selects fuel carried in cargo. Ordinary market refueling requires a docked ship at a marketplace selling fuel. The official navigation guide also describes cargo refueling, but does not fully specify its location/state exceptions. [Refuel operation][spec], [navigation refueling guide][nav], [fuel model][fuel]

There is a documentation inconsistency: the operation's broad prose says refueling always fills the tank, while its request property explicitly permits a requested amount. Prefer the detailed request property when planning a partial refill, then verify the returned state during later authorized gameplay. [Refuel request schema][spec]

Unknown: the inspected sources do not state exactly how a requested amount that is not a multiple of 100 rounds billed market units or consumed cargo, whether unused portions are lost, or which edge cases apply when the tank has less than 100 free units. Do not assert `ceil(requested / 100)` as a verified server formula.

Policy: keep tank units and cargo units separate in records and UI. Budget conservatively for whole cargo/market units, state that this is a buffer, and compare the returned fuel, cargo, transaction, and credit changes before relying on partial top-ups. Repeated tiny refuels have no proven rounding advantage. Cargo fuel also occupies cargo capacity and ties up credits that could fund trades. Until cargo-refuel location rules are verified, exclude it as the only escape plan from a remote waypoint.

### Fuel-safe route planning

Policy: represent the local system as a graph with a node for each waypoint. Each candidate travel edge belongs to a particular ship configuration and mode. Keep estimated fuel, estimated time, their evidence and age, and whether the edge is verified by an actual prior trip. A fuel purchase is a separate state change at a known seller, with a credit requirement. A route planner that tracks only distance misses fuel capacity and refueling constraints.

Use coordinate distance to shortlist nearby visits. Use observed `fuel.consumed.amount` and `arrival - departureTime` to calibrate route estimates once gameplay is authorized. These are measured values, not an authoritative equation for untried distances. Keep model uncertainty explicit, especially after equipment, condition, or mode changes. [Waypoint coordinates][waypoint], [fuel measurements][fuel], [route timestamps][route], [ship frame condition][frame]

For an exploration leg, require enough fuel to reach the destination and then reach a known usable refuel location, plus a chosen uncertainty reserve. At the destination, recalculate using fresh fuel. A `MARKETPLACE` trait or `FUEL_STATION` type does not alone prove that fuel is currently sold at an affordable price. Confirm its market goods and retain a credit reserve. [Refuel operation][spec], [market model][market], [waypoint types][waypoint-type]

For route search, use states that include current waypoint and remaining tank fuel, and include carried fuel only after its use rules are established. Track credit limits as well. Dijkstra or A* can find the cheapest route only after edge costs and feasible refuel transitions have been defined. There is no proof that the geometrically shortest route is the cheapest in credits, or that extra stops save fuel after server rounding and time overhead.

Compare a small set of feasible plans: direct travel, a known refuel stop, and a slower mode. Rank fuel and elapsed time separately before combining them with the ship's measured opportunity cost. Include positioning, empty return legs, docking, API waits, and the price of fuel actually purchased. Stop exploration when a fuel seller becomes unusable or the return reserve fails. Do not use repeated failed navigation attempts as a fuel estimator.

Jumping and warping need different edges. Navigate is within a system. Warp requires a Warp Drive and consumes fuel. A jump targets a connected waypoint, consumes a purchased unit of antimatter, and returns cooldown. The public errors also identify gates under construction. No gate connectivity, construction status, or antimatter price was inspected here. Keep local profitable routes ahead of speculative expansion unless local options are exhausted. [Travel operations][spec], [jump gate model][gate], [jump errors][errors]

## Find mining opportunities by saleable output

### Capability, yields, and cooldown

Verified mechanics: extraction needs an extractable location and an orbiting ship. For mineral extraction, check both a Mining Laser mount and Mineral Processor module. The public catalogue explicitly lists `4242 ShipMissingMineralProcessorError` and `4243 ShipMissingMiningLasersError`. Siphoning uses a separate endpoint and requires siphon mounts plus a gas processor at a gas giant. [Extraction and siphon operations][spec], [error catalogue][errors]

The official extraction guide says location traits affect available resources and yields are random; mounts and modules affect quantity. Mining mounts can expose `strength` and `deposits`, but the schemas provide no quantity formula or complete per-tier resource table. A stronger reactor can reduce cooldown for actions that tax power. The returned cooldown and actual extraction result are the reliable measurements. [Extraction guide][extract-guide], [mount model][mount], [reactor model][reactor], [cooldown model][cooldown], [extraction yield][yield]

Policy: compare the desired resources with the actual mount's reported `deposits` when available. Do not derive tier-specific capabilities from the generic enum or assume every mining laser can recover every surveyed resource. The guide also ties the number of surveys returned to survey mount strength, without specifying an exact relationship. [Mount fields][mount], [surveying guide][extract-guide]

The guide permits navigation during extraction cooldown, while restricting most other actions. Policy: overlap cooldown with travel when permitted and useful, then refresh current state before the next action. Do not blindly add cooldown and travel times when they overlap. Do not infer that selling, transfers, or surveying are always allowed during every cooldown. [Extraction guide][extract-guide], [cooldown model][cooldown]

Policy: rank asteroid candidates by observed saleable output over the whole extraction-and-sale cycle. Record all outputs, not just the valuable successes. Include low-value cargo, full-hold interruptions, time spent selling or discarding, and the route to a real buyer. A rich deposit without an economical buyer is an unpriced prospect.

Use a bounded sample budget per candidate. Repeated samples improve an estimate; one diamond extraction does not establish a stable diamond income rate. Include sample count and observation period. Check the actual loadout before planning an upgrade, because power, crew, module slots, and mounting limits can make an otherwise attractive loadout invalid. [Outfitting requirements][outfitting], [frame model][frame], [mount model][mount]

### Surveys preserve weighted deposits

Verified mechanics: creating surveys needs a Surveyor mount and causes cooldown. A survey contains a signature, waypoint symbol, deposits, expiration, and size. Duplicate deposit entries increase that resource's extraction chance. Size is `SMALL`, `MODERATE`, or `LARGE` and indicates how much can be extracted before exhaustion. Surveys can expire or exhaust before expiry; multiple ships may use the same survey. [Survey operation][spec], [survey model][survey]

Use `POST .../extract/survey` with the complete original survey object as the request body. Its signature validation rejects altered properties. Older guide examples send a nested survey to `/extract`; the current specification deprecates that pattern. Do not remove duplicate deposits or rewrite the signed object. Only survey creation is documented as requiring a Surveyor mount; the extraction ship must still satisfy its mining requirements. [Current extraction-with-survey operation][spec], [survey model][survey], [equipment errors][errors]

Unknown: duplicate count is not documented as an exact probability formula. Size does not publish a guaranteed number of uses, total units, or lifespan. The survey's expiration timestamp is explicit, but it does not promise availability until that instant. The public errors distinguish signature verification `4220`, expiry `4221`, exhaustion `4224`, and invalid survey location `4270`. [Survey model][survey], [error catalogue][errors]

Policy:

- Preserve the signed survey verbatim and build a separate summary for scoring. Include duplicate counts in that summary.
- Score the entire deposit mix using conservative buyer values and expected disposal cost. A large survey dominated by unwanted resources may be worse than a smaller useful one.
- Prioritize a useful survey that will expire before the next practical opportunity to use it. Consider the miner's travel and cooldown before assigning it.
- Coordinate survey use across the fleet. Mark exhaustion immediately for all local users; local coordination cannot guarantee remaining server uses.
- Stop retrying expired or exhausted surveys. Refresh the survey pool or fall back to an explicitly allowed unsurveyed action.

A separate surveyor may keep miners extracting while it pays survey cooldown, but its purchase, positioning, survey output quality, and API use must earn back the cost. There is no proof that surveying on every extraction cycle is profitable.

### Asteroid hazards and wear

Verified mechanics: waypoint modifiers include `CRITICAL_LIMIT`, `UNSTABLE`, and `STRIPPED`; the trait enum also includes `STRIPPED`. These are distinct fields. The systems guide describes critical limit as an over-extraction warning, and historical changelog guidance says excessive extraction causes temporary instability and sharply reduced yields. The current public catalogue includes `4253 ShipExtractDestabilizedError` and `4261 WaypointNoYieldError`. [Modifier symbols][modifier-symbol], [trait symbols][trait-symbol], [systems guide][systems-guide], [changelog][changes], [error catalogue][errors]

Unknown: the sources do not establish a safe extraction rate, recovery timer, exact yield penalty, or a current effect for every modifier. The systems guide's statement that only critical limit has an effect is less complete than the changelog and error catalogue. Treat hazard descriptions as warnings, not a simulator of depletion.

Policy: refresh modifiers when selecting a target and after extraction responses that provide them. Back off or rotate on critical-limit warnings; suspend a destabilized or no-yield target and recheck later under a bounded schedule. Account for the relocation cost. Exhausting one survey does not establish that the whole asteroid is empty.

Extraction and travel responses can report condition events. The model identifies frame, reactor, and engine wear/damage. Condition is repairable; integrity is permanent wear, and the condition model says repairs reduce integrity. Repair cost can be quoted at a shipyard. Include observed wear and repair exposure when comparing routes; do not assume a stationary miner earns without equipment cost forever. [Condition events][condition-events], [condition][condition], [integrity][integrity], [repair operations][spec]

### Refining and a miner-hauler split

Verified mechanics: refining needs suitable input cargo and an appropriate Refinery module. The current operation states a conversion of 100 basic goods to 10 processed goods and returns actual `consumed`, `produced`, cargo, and cooldown. Its allowed outputs include metals and `FUEL`. A Mineral Processor alone does not establish refining capability. [Refine operation][spec], [module model][module]

Policy: compare selling the raw input with selling the output from the documented conversion batch. Ten processed units must cover the sale value of the hundred raw units, refining delay, capital, and any extra transport cost. Cargo compression may improve hauling even when the headline processed unit price is misleading. The supply-chain map describes market production dependencies; it does not define ship refining recipes or override the refine operation.

Transfers require the same waypoint, matching states with both ships docked or both orbiting, and enough receiving capacity. The current served response includes both `cargo` and `targetCargo`, while the pinned repository version only requires source cargo. Do not expect matching coordinates alone to permit a transfer. [Transfer operation][spec], [pinned operation source][reference]

Inference: a miner that stays at an asteroid can use more of its time extracting while a hauler sells mixed output and returns. Policy: adopt this split only when avoided miner travel exceeds transfer waits, hauler travel, market saturation, and extra capital cost. Measure hauler capacity against combined miner output over its full round trip. An undersized or late hauler leaves full miners idle; an oversized one ties up credits. Carrying fuel for the miner may help only after cargo-refuel behavior is verified.

## Find trade opportunities that survive execution

### Volume, supply, activity, and price changes

Verified mechanics: `tradeVolume` is the maximum number of units of that good bought or sold in one transaction at that market. It also indicates price resilience: low volume means larger price swings. It is not the market's total stock, total demand, or hourly throughput. Supply is an ordinal enum from `SCARCE` to `ABUNDANT`. Activity is optional and means consumption strength for imports and production strength for exports; `RESTRICTED` can indicate a bottleneck. These fields provide no exact stock quantity or production clock. [Market trade good][market-good], [supply levels][supply], [activity levels][activity]

Official market guidance says exports are produced, imports are consumed, and missing inputs constrain export production. Exchange goods are neither produced nor consumed by that market. Export-to-import routes are useful candidates, but no source guarantees their spread remains profitable. [Market guide][markets]

Policy: join source and destination goods by symbol, then use the source `purchasePrice` and destination `sellPrice`. Check both endpoint trade volumes. Limit each individual buy/sell to its local cap, available cargo or inventory, and the approved credit budget. Refresh and re-evaluate between batches instead of pricing a whole hold at the first quote. The returned transaction establishes actual units and price. [Trade operations][spec], [transaction model][market-transaction]

Price deterioration across execution is slippage. Multiple legal transactions can still exhaust a profitable margin, especially when a large hold is many times the local volume. Account for API calls and market refreshes as well as travel. A return cargo may improve the cycle, but only if that second trade independently clears its fuel, time, and capital checks. An empty return remains a real route cost.

### Use the actual production dependency map

Observed public data: on 2026-11-01, `GET /v2/market/supply-chain` returned `data.exportToImportMap`. Examples include:

| Export | Supporting import goods in the returned map |
| --- | --- |
| `FUEL` | `HYDROCARBON` |
| `IRON` | `IRON_ORE` |
| `ELECTRONICS` | `SILICON_CRYSTALS`, `COPPER` |
| `SHIP_PLATING` | `ALUMINUM`, `MACHINERY` |
| `SHIP_PARTS` | `EQUIPMENT`, `ELECTRONICS` |
| `SHIP_PROBE` and `SHIP_MINING_DRONE` | `SHIP_PLATING`, `SHIP_PARTS` |

Each row reports a dependency, not an input/output quantity, guaranteed availability, or market price. The response lists no conversion ratios, consumption intervals, or production capacities. [Public supply-chain response][chain], [operation schema][spec]

Policy: if a desirable export has weak or restricted production, inspect that market's actual imports and their supply. Look for a profitable delivery of a required input, and later measure whether the downstream export improves. Supporting fuel production may reduce future travel costs; supplying ship materials may support ship availability. Both are investments with delayed, shared benefits, so do not credit the plan with future cheap fuel or ships before they appear.

Distinguish delivering hydrocarbons to a fuel-producing market from buying a ship refinery and producing fuel onboard. They are separate strategies with different equipment, time, and capital requirements. The relationship map is a research aid, not permission to assume either strategy is locally executable.

## Compare complete cycles in credits per hour

Policy: rank an opportunity only after identifying its resource, buyer, feasible route, ship capability, and enough price/yield evidence. Until then rank the next observation that would make the opportunity assessable.

Use a complete-cycle ledger. For a trade cycle that returns to comparable location, fuel, and inventory, operating net is sale receipts less purchased goods, fuel, jump charges, repairs, and other operating costs. Divide by the whole cycle's elapsed hours. Keep ship/equipment purchases separate as capital investment. For mining, the ledger includes every extracted good and its actual disposition; unsold cargo is not realized income.

These are accounting definitions, not server formulas. An open cycle needs an explicit inventory adjustment: value remaining cargo conservatively at a reachable buyer after selling and transport costs. Mark unpriced or illiquid cargo as uncertain. Match starting and ending tank fuel, or disclose the fuel adjustment. Treat previously purchased inventory's acquisition cost consistently; selling it once is not a repeatable free-profit cycle.

Include:

- Positioning, loaded travel, selling, empty return, refueling, and any time waiting for buyers or a hauler.
- Cooldown on the actual timeline. Travel may overlap extraction cooldown; parallel ships may overlap one another. Do not sum overlapping durations as if they were sequential.
- Observed yield mix, sale batches, price changes, discarded cargo, market caps, failed attempts, API waits, and idle time.
- An explicit wear allowance if wear has not yet produced a repair bill. Label the estimate and show results before and after it.
- Initial ship/equipment capital, maximum cash needed for cargo plus operating reserves, and credits currently tied up in inventory. Inventory funded from that cash requirement is a component of it, not an extra amount to count twice.
- Fleet net per elapsed hour and net per ship-hour for plans involving several ships. A larger fleet's higher total income alone does not establish better capital efficiency.

Show realized results separately from estimates. For estimates, show a base case and a conservative case with weaker yields, adverse prices, an empty return, or delayed sale. Choose explicit local operating thresholds later; no default minimum margin, acceptable payback period, sample size, or fuel reserve is a SpaceTraders rule.

For an added ship or mount, estimate payback from incremental net income after its own running costs, relative to the best use of existing ships. If the incremental gain is unmeasured or nonpositive, payback is unknown. Do not divide the purchase price by the whole fleet's existing income.

## Prioritized automation options and decision gates

The order below is a proposed development sequence. It may change after the parent compares these needs with the actual client and after authorized fleet observations become available.

| Priority | Automation | Why it earns its place | Gate before it can run gameplay |
| --- | --- | --- | --- |
| 1 | Local catalogue, market directory, and fuel-aware opportunity planner | Reduces blind travel and exposes missing information; supports every later loop | Complete pagination; separate hidden traits from known absence; show source age and route uncertainty |
| 2 | Bounded scout and price-observation route | Answers the highest-value unresolved question with a fuel/time budget | Actual scout capability; return/refuel plan; stop budget; clear purpose per visit; explicit authorization for movement/chart/scan |
| 3 | Adaptive mining and sale, with hazard rotation and optional surveys | Can improve use of existing mining capability without buying speculative trade inventory | Capable miner; permitted target; saleable yield evidence; real buyer; fuel reserve; cooldown and hazard handling |
| 4 | Small repeatable trade loop with refresh between batches | Tests whether a priced spread survives volume, travel, and repeated execution | Both endpoint observations; affordable cargo; conservative complete-cycle net; maximum spend/inventory and loss stop |
| 5 | Selective parked probes, dedicated surveyor, or miner-hauler split | Removes a measured visibility, survey, or hauling bottleneck | Existing route performance; actual offers/loadouts; incremental income and payback case; coordinated cargo/survey handling |
| 6 | Refining, production-chain support, or intersystem expansion | Adds more dependencies, capital, and delayed benefits | Suitable refinery or travel capability; input/output buyers; production or sale evidence; construction/connectivity/fuel checks |

These priorities are conditional. If an existing hauler already has fresh prices and a proven safe profitable trade route, trading can precede further mining work. If no known buyer makes mining viable, scout for buyers before repeating extractions. If a full miner waits mainly on hauling, a hauler may beat another miner. If there is no fresh evidence, the best next automation is observation.

### Minimum evidence for the next decision

Policy: collect the following during a separately authorized discovery session, then choose one bounded loop:

1. Actual ships, loadouts, cargo, navigation, cooldowns, fuel, credits, and the user's operating limits.
2. Every local waypoint page, known market goods directories, hidden-trait candidates, and fuel-selling locations.
3. Recent detailed observations at the relevant source, buyer, and refuel market, with collection times and visibility state.
4. Measured travel and fuel for relevant ship/mode combinations, or estimates explicitly too uncertain to authorize the leg automatically.
5. A bounded mining sample with every output and cooldown, current hazards, and any unexpired signed surveys; or a small trade cycle with actual transaction prices.
6. The complete-cycle ledger, capital requirement, conservative downside, stopping conditions, and evidence gaps.

An opportunity record should answer: what will the ship do, where will the goods sell, what must be spent, when will the ship and credits be available again, how old is the evidence, and what stops the run? If any essential answer is unknown, propose a smaller observation step instead of presenting a profit forecast.

## Execution constraints for a future implementation

Policy: share an API request budget across mapping, observation, manual commands, and bots. Cache static position/type facts for the current universe while refreshing dynamic modifiers, markets, and construction status according to use. Namespace caches by universe/reset. Official guidance describes traits as static during a reset and modifiers as temporary. The rate-limit guide currently lists two requests per second for both account and IP, with burst limit 30 and burst duration 60 seconds. Honor returned limits and retry guidance rather than independently scheduling each ship at that rate. [Systems guide][systems-guide], [rate-limit guide][limits]

Serialize each ship's commands and coordinate shared credits, cargo transfers, and survey exhaustion. Persist future arrival/cooldown times and wake near them instead of constantly polling. After a timeout on a purchase, sale, extraction, refuel, chart, or transfer, reconcile actual state before retrying. The navigation guide explicitly calls dock/orbit idempotent; no general exactly-once write guarantee was found for these economic operations. [Navigation guide][nav], [operation contracts][spec]

Stop or replan on insufficient return fuel, stale or adverse prices, invalid/exhausted surveys, destabilization, a blocked buyer, a filled hold, a credit reserve breach, or unresolved write state. These are proposed client safeguards, not claims that the current application already has them.

## What Reddit and player experience add

Taylor requested Reddit and broader community research during this investigation. The material below was accessed 2026-11-01. These are dated player reports, not measurements of your fleet or a representative survey of players. Search results mixed in unrelated games, which were excluded. A direct read of r/SpaceTradersAPI found more useful material than broad search. The retrieved threads do not establish a current consensus about the best ship, ore, or route.

| Source and date | Useful finding | How to use it here |
| --- | --- | --- |
| [Fuel requirements before moving](https://www.reddit.com/r/SpaceTradersAPI/comments/1f53i6m/how_to_see_fuel_requirements_before_moving/), 2024-08-30, with a later wiki link | A commenter points to community-derived fuel and travel-time calculations. The thread itself supplies no validated equation. | Use the linked wiki as a prediction hypothesis. Compare predictions with returned fuel consumption and arrival timestamps before trusting them. |
| [Systems scan versus systems endpoint](https://www.reddit.com/r/SpaceTradersAPI/comments/13jr7q3/ships_systems_scan_versus_systems_endpoint/), 2023-05-17 | The author notices that a ship scan has limited results and triggers cooldown, unlike a paginated systems listing. | List known geography first. Spend scan actions where they reveal hidden information relevant to a decision. The reported system counts are historical, not current universe sizes. |
| [SpaceTraders introduction and comments](https://www.reddit.com/r/incremental_games/comments/muya7f/spacetraders_api_an_incremental_programming_game/), 2021-04-20 | Advice in the discussion recommends exploring markets, recording prices locally, and referring to that information before trading. | Retain the data-first strategy. Ignore the old loan and industry setup when planning against the current v2 API. |
| [Autonomous contract experiment](https://www.reddit.com/r/LocalLLaMA/comments/1f09bn4/i_used_gemini_flash_to_autonomously_complete_the/), 2024-08-24 | The author reports cargo-capacity and fuel-range mistakes. A commenter recommends calculator tools that return explicit feasibility results. | Keep route, capacity, and spending arithmetic in code. An LLM can explain or propose a plan, while deterministic checks decide whether it is feasible. This is an anecdote about one experiment, not an LLM benchmark result. |

### The Reddit fuel lead changes the research picture

The [Travel Fuel and Time wiki](https://github.com/SpaceTradersAPI/api-docs/wiki/Travel-Fuel-and-Time), last edited 2024-03-02 in the retrieved page, says its numbers were compiled from Discord users. Repository hosting does not make these server guarantees. It reports CRUISE and STEALTH fuel as rounded Euclidean distance with a minimum of one, DRIFT as one, and BURN as twice rounded distance with a minimum of two. It gives navigation duration as `round(round(max(1,d)) * multiplier / engineSpeed + 15)` seconds, with multipliers 25 for CRUISE, 250 for DRIFT, 12.5 for BURN, and 30 for STEALTH. The STEALTH value is marked unconfirmed for v2.1 or later. This is enough to build a candidate estimator, not enough to promise exact travel results. Its warp and jump details need independent checks against the current API. The earlier repository travel note did not inspect this source. Its conclusion that the inspected official guide and schema publish no authoritative equation still stands.

Recommendation: store estimator provenance and prediction error alongside actual route responses. Use a conservative reserve until representative authorized trips verify the estimator for each supported ship and mode. Never test a formula by deliberately stranding a ship. For zero-capacity or unusual ships, establish their actual fuel behavior separately.

### Player logs reveal the expensive failure modes

[Stafford Williams's March 26–31, 2024 log](https://staffordwilliams.com/dev/spacetraders-v2/2024-w13/) reports miners filling faster than a shuttle could empty them. Adding hauling and mining capacity then drove ore prices down. By 20 mining drones, the author's automation was losing credits and had to stop. The author proposed probes near the mining site to improve market monitoring. The transferable lesson is to measure hauling throughput and sale margins before buying more miners. This does not establish a universal fleet limit or recommend jettisoning unwanted cargo.

His [April 18, 2024 contract log](https://staffordwilliams.com/devlog/spacetraders-v2/v2.10/) describes scouting a market with no fuel, then a routing loop that repeatedly returned to a destination requiring another rescue drift. A market icon is not proof of refueling availability. A planner needs a feasible continuation from the destination, not just an affordable outbound leg.

A [July 8, 2023 implementation account](https://www.adyxax.org/blog/2023/07/08/space-traders/) describes caching observations in SQLite and prioritizing income-producing requests over exploration and refreshes. That is a useful scheduling pattern when the API budget becomes scarce. Its historical burst-limit values must not replace today's official rate-limit guide.

## What this app already has, and what the research suggests next

This assessment comes from source inspection at local HEAD `88bc0262b65b70e611e2fdc37e64a5225591d5c0` on 2026-11-01. It is not a live fleet audit or a gameplay test.

| Area | Current behavior | Implication for the next automation |
| --- | --- | --- |
| Local geography | [AgentSynchronizer](../app/Gameplay/AgentSynchronizer.php) lists waypoints in up to three occupied systems. [SpaceTradersApi](../app/Gameplay/SpaceTradersApi.php) handles pagination, bounded to ten pages of 20. | Build on existing observations. Expose whether discovery completed; a bounded or failed sync cannot support a claim that the whole system is mapped. Trait and type filters could reduce requests, but do not exist in the current wrapper. |
| Market coverage | Synchronization prioritizes occupied locations, then fetches market and shipyard observations up to a combined [24-observation cap](../config/gameplay.php). Remote market structure can be saved while prices remain unknown. | A coverage display should separate known goods, current observed prices, stale prices, and unvisited markets. A missing price is not an unprofitable route. |
| Observation history | [GameObservation](../app/Models/GameObservation.php) records reset and observation time, while `observe()` overwrites each agent/kind/symbol record. | The app has latest snapshots, not a price or mining-yield time series in this table. Preserve history before claiming trends or forecasting price recovery. Action records can supply some actual outcomes. |
| Mining loop | [MiningPlanner](../app/Gameplay/MiningPlanner.php) starts with empty cargo and deliberately performs one extraction per trip. [ShipCommandExecutor](../app/Gameplay/ShipCommandExecutor.php) advances the run to selling after confirmed extraction. | Compare bounded multiple-extraction loads with the existing loop. Fuller loads spread route fuel over more saleable cargo, but require limits for cooldown, hazards, capacity, and buyer demand. This is a candidate optimization, not a measured speedup. |
| Fuel and routing | Mining and navigation previews require CRUISE. `fuelEstimate()` uses `ceil(hypot(dx,dy)) + 2` for different waypoints. Mining requires one observed buyer that also sells fuel and a tank-feasible round trip. | This is a conservative local estimate, not the wiki formula or an official equation. A generalized explorer needs separate mode estimates, refueling stops, escape routes, and actual-versus-predicted telemetry. |
| Hazard signals | Waypoint snapshots retain modifiers, but `MiningPlanner::route()` checks `traits` for `UNSTABLE`. The current [WaypointModifierSymbol schema](https://github.com/SpaceTradersAPI/api-docs/blob/main/models/WaypointModifierSymbol.json) defines `UNSTABLE`, `STRIPPED`, and other conditions as modifiers. | Check modifiers as well as traits before extending extraction runs. This is a source-level limitation, not evidence that a current ship is at an unsafe site. |
| Trades and fleet roles | The command wrapper supports basic extraction, buy, sell, refuel, dock, orbit, navigate, and one-time setup ship purchase. It has no survey, chart, scan, transfer, refine, cargo-refuel, or mode-change command. | Surveys and miner-hauler logistics require new command support. They cannot be enabled by a planner configuration change alone. |
| Execution controls | The app already uses previews, spending and loss limits, stop controls, timestamped waits, and reconciliation for uncertain writes. | Keep these controls when adding exploration or trading. The research does not authorize game actions or broader purchases. |

My recommendation is to choose a small local-intelligence addition first: show the current system map, which markets have prices, the price age, candidate buyers for likely mining yields, and fuel-feasible routes. Have it recommend the next useful observation and explain why. Flying to obtain that observation is a separately approved scouting action. This produces useful decisions before committing to a general trader or a larger fleet.

Then let measured constraints choose the implementation:

- If important nearby prices are unknown, add bounded scouting or selective probe placement. Spend only where information is likely to change a route choice. A permanent probe needs expected benefit within the remaining reset to justify its purchase and deployment.
- If travel dominates a measured mining cycle, test bounded fuller cargo loads. Next consider a hauler only when expected collection throughput covers mining output without excessive idle time or a blocked buyer.
- If yield mix wastes cargo or misses valuable buyers, compare surveyed and unsurveyed extraction after accounting for survey cooldown, expiry, depletion, and actual sale proceeds.
- If fresh buy and sell quotes show repeatable net margins, consider a bounded same-system trader. Include deadhead travel, fuel, transaction batching, price movement, and capital reserved for recovery.
- If established local opportunities are poor, assess adjacent systems through verified gate connections and fuel access. Wider exploration has a scouting cost and should not be the default response to missing local information.

[Interactive fuel comparison](exploration-fuel-comparison.html) illustrates the arithmetic behind fuller cargo loads. It uses hypothetical inputs and does not calculate game travel, fuel prices, or profitability.

## Source record

All links below were inspected directly or resolved from the inspected first-party repository tree on 2026-11-01. Pinned model links refer to revision `45fbb04130aca3fa0bd9a634ab77b35fa6c468ab`. The served OpenAPI's retrieved bytes had SHA-256 `3f4eb1b65848675f48b6de861cb77bea0b0ee01e88e45a0bef5981eebc0ff59d`. This hash identifies the research snapshot, not a promised server version.

The official site delivers much of its article content in its page data, so the initial static page text alone is incomplete. Article content was read from that public payload. Historical changelog statements are identified as such. The verified-mechanics sections use first-party sources. The separate community section records the requested Reddit discussions, a community calculator, and player implementation logs with their dates and limits. No SDK behavior or player profitability claim is treated as a current game guarantee. Documentation inconsistencies and live-authentication differences are recorded above rather than silently resolved into stronger claims.

[spec]: https://api.spacetraders.io/v2/documentation/json
[repo]: https://github.com/SpaceTradersAPI/api-docs/tree/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab
[reference]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/reference/SpaceTraders.json
[chain]: https://api.spacetraders.io/v2/market/supply-chain
[errors]: https://api.spacetraders.io/v2/error-codes
[nav]: https://docs.spacetraders.io/game-concepts/ship-navigation
[markets]: https://docs.spacetraders.io/game-concepts/markets
[extract-guide]: https://docs.spacetraders.io/game-concepts/extracting-resources
[systems-guide]: https://docs.spacetraders.io/game-concepts/systems-waypoints
[outfitting]: https://docs.spacetraders.io/game-concepts/outfitting
[quick-mine]: https://docs.spacetraders.io/quickstart/mine-asteroids
[changes]: https://docs.spacetraders.io/resources/changelog
[limits]: https://docs.spacetraders.io/api-guide/rate-limits
[waypoint]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/Waypoint.json
[system]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/System.json
[system-waypoint]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/SystemWaypoint.json
[scanned-waypoint]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ScannedWaypoint.json
[scanned-system]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ScannedSystem.json
[chart]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/Chart.json
[meta]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/Meta.json
[waypoint-type]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/WaypointType.json
[trait-symbol]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/WaypointTraitSymbol.json
[market]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/Market.json
[market-good]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/MarketTradeGood.json
[market-transaction]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/MarketTransaction.json
[supply]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/SupplyLevel.json
[activity]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ActivityLevel.json
[fuel]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipFuel.json
[route]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipNavRoute.json
[frame]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipFrame.json
[engine]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipEngine.json
[reactor]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipReactor.json
[mount]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipMount.json
[module]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipModule.json
[cooldown]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/Cooldown.json
[yield]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ExtractionYield.json
[survey]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/Survey.json
[modifier-symbol]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/WaypointModifierSymbol.json
[condition-events]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipConditionEvent.json
[condition]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipComponentCondition.json
[integrity]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipComponentIntegrity.json
[shipyard-ship]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipyardShip.json
[gate]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/JumpGate.json
