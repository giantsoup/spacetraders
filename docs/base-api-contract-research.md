# Base API foundation contract

Research date: 2026-11-01. Scope: official API contracts and foundation planning. No gameplay implementation, authenticated API calls, game mutation, credentials, service changes, or publication occurred. This is contract research, not an independent implementation review.

## Contract baseline and evidence

The supplied schema is OpenAPI **3.0.1**, API **2.3.0**, with **59 operations: 26 GET, 32 POST, and 1 PATCH**. Its server is `https://api.spacetraders.io/v2`. The pinned file and a fresh unauthenticated download from the official schema URL both have SHA256 `c07d04f575df43e4d05eea4ba539f2d33ef8c5d9a3c863d49520193ef5431ad8`. This verifies document identity, not live server behavior. [S, `/openapi`, `/info/version`, `/servers`, `/paths`]

Sources and citation conventions:

- **S**: [Official bundled OpenAPI](https://spacetraders.io/SpaceTraders.json), read from the [pinned file](api/spacetraders-openapi-2.3.0.json). The [official OpenAPI guide](https://docs.spacetraders.io/api-guide/open-api-spec) identifies this download URL. Operation IDs below locate exact operations. Their JSON pointer is `/paths/{escaped-path}/{lowercase-method}`, where each `/` in the path becomes `~1`. For example, `repair-ship` is `/paths/~1my~1ships~1{shipSymbol}~1repair/post`. Model pointers are `/components/schemas/{name}`.
- **A**: [Official agent and account registration guide](https://docs.spacetraders.io/quickstart/new-game) and [agent concepts](https://docs.spacetraders.io/game-concepts/agents-and-factions).
- **R**: [Official rate limits](https://docs.spacetraders.io/api-guide/rate-limits).
- **E**: [Official response errors](https://docs.spacetraders.io/api-guide/response-errors). This page calls its error list non-exhaustive. Error names indicate constraints but do not define complete mechanics.
- **C**: inspected source at commit `99be3c474dddd7bf9fd1c1aec5bf2147cf9b5868`, using `git show <commit>:<path>`. Relevant files are `app/Http/Requests/GameplayRequest.php`, `app/Gameplay/{CommandRules,SpaceTradersApi,ShipCommandExecutor,RequestBudget,ExplorationPolicy,EngineGate}.php`, `app/Services/SpaceTradersClient.php`, `app/Http/Controllers/AgentCommandController.php`, `resources/js/Pages/Agent/Dashboard.vue`, `routes/web.php`, and `config/gameplay.php`. Source observations below cite the file and method. The parent planning worktree was not changed.

**Schema** below means an explicit request, response, or description in S. **Design** means a proposed local rule or inference. **Calibration** means behavior that remains unverified against the game and needs a separately authorized test. No community formulas are used.

Reproduce the pinned count without API access:

```python
import hashlib, json
from collections import Counter
from pathlib import Path

p = Path("docs/api/spacetraders-openapi-2.3.0.json")
b = p.read_bytes()
s = json.loads(b)
methods = {"get", "post", "put", "patch", "delete", "head", "options", "trace"}
counts = Counter(m for item in s["paths"].values() for m in item if m in methods)
print(s["openapi"], s["info"]["version"], sum(counts.values()), dict(counts))
print(hashlib.sha256(b).hexdigest())
```

## Completion means accepted behavior for every operation

The foundation gate needs one acceptance record per operation in the inventory below, including dedicated reads. Each record needs request variants, local ownership rules, response fidelity, UI access, freshness, failure handling, reconciliation where applicable, and evidence. An adapter method or a shipped source mapping alone proves neither usable functionality nor acceptance. This is Taylor's completion requirement, not an OpenAPI guarantee.

Current source restrictions make several mappings partial:

- `buy_ship` allows one setup purchase, requires no operational miner already exists, and accepts only verified complete mining configurations. The API supports purchasing the offered ship types, including non-miners. [C, `CommandRules::inspect`, `buy_ship`; S, `purchase-ship`, `ShipType`]
- `scan_waypoints` and `chart` require a saved unresolved target and a `MARKETPLACE` or `SHIPYARD` question. The API accepts no such target or question in either body. A scan returns the in-range collection. A chart concerns the current waypoint. [C, `GameplayRequest::rules`, `ExplorationPolicy::inspect`; S, `create-ship-waypoint-scan`, `create-chart`]
- Navigation requires CRUISE. The schema supports DRIFT, STEALTH, CRUISE, and BURN, plus a separate PATCH operation. [C, `CommandRules::inspect`, `navigate`; S, `patch-ship-nav`, `ShipNavFlightMode`]
- Contract mutation mappings exist, but `CommandRules` admits them only through an exact approved contract run. Foundation acceptance needs manual contract actions while strategies remain disabled. [C, `CommandRules::inspect`; S, `accept-contract`, `deliver-contract`, `fulfill-contract`]
- `SpaceTradersApi::status()` returns only the reset date, and `agent()` retains only symbol and credits. These helpers do not themselves expose the complete status or agent contracts. Waypoint normalization also discards fields such as `isUnderConstruction`. Other read paths may supply them, but they need separate acceptance evidence. [C, `SpaceTradersApi::{status,agent,waypointRows}`; S, `get-status`, `get-my-agent`, `Waypoint`]

Per the task's supplied branch status, cargo refueling, cruise restoration, and itinerary/profiling work are unmerged local branches. They are dependencies or evidence to assess later, not completed main functionality. This research did not re-audit those branches.

Adopted priority: new AI, strategy, automatic scouting, trade-selection, mining-optimization and autonomous-itinerary development is deferred until the foundation gate passes. This plan does not revoke already-authorized local cutover or bounded playtesting. The engine/queue needed to send an approved manual intent is infrastructure, not a strategy prerequisite.

## Actors and shared transport

Schema auth is explicit for most operations. `register` requires an account token. All explicitly protected gameplay operations require an agent token. Public alternatives are declared for status, systems, waypoint reads, market, shipyard, jump gate, construction, factions list, and public agents. `get-supply-chain` explicitly requires an agent token, despite its informational purpose. Public market and shipyard reads can omit prices, available configurations, and transactions without an owned ship present. Absence is unknown visibility, not zero price or no supply. [S, each operation's `security`; `get-market`, `get-shipyard`; A]

There is a schema defect to preserve in the plan. Root `/security` is `[{"AgentToken": [], "AccountToken": []}]`, an AND requirement under OpenAPI semantics. `get-faction`, `get-ship-modules`, `install-ship-module`, and `remove-ship-module` inherit it. Both schemes use HTTP bearer auth, while the official guide assigns account tokens to agent creation and agent tokens to gameplay. **Design:** use the owning agent's token for `/my` operations, never concatenate or transmit both tokens, and record the inherited declaration as unresolved. Treat anonymous `get-faction` support as unproven even though `get-factions` is public. **Calibration:** verify accepted auth behavior later or obtain an official clarification. [S, `/security`, `/components/securitySchemes`, operation `security`; A]

Local user authorization remains separate from API auth. Ship symbols, contract IDs, intents, evidence, and history must belong to the selected local user, canonical agent, and reset. A syntactically valid ship symbol is not ownership evidence. Account tokens should never become a fallback for failed gameplay auth. [Design; C, `GameplayRequest::authorize`, `CommandRules::inspect`, `ShipCommandExecutor::clear`]

R documents limits by both IP and account, currently 2 requests per second, with a listed burst of 30 over 60 seconds. It also documents limit headers and warns that infrastructure can emit 429 without the game's headers. **Design:** retain one shared transport budget for reads, writes, pagination, quotes, preflight, and reconciliation, across workers and local checkouts sharing an IP. Honor observed `Retry-After` and reset information. Defer rather than occupy a worker through long waits. A backoff deadline never authorizes replay of an uncertain write. [R; C, `RequestBudget::{reserve,observe}`, `SpaceTradersClient::request`]

## Required missing write contracts

All ship paths below have a required `shipSymbol` path parameter. Use the owning agent actor, subject to the inherited-auth defect above. Empty-body operations have no documented request body. Status codes and field lists below are the declared success contracts, not exhaustive error catalogs. Preconditions omitted by the schema are called out rather than invented.

### Repair and scrap need quotes and separate execution

| Operation IDs | Schema contract | Design and unresolved details |
| --- | --- | --- |
| `get-repair-ship`, `repair-ship` | GET returns 200 `data.transaction`. POST has no body and returns 200 `data.agent`, `ship`, `transaction`. Execution requires DOCKED at a SHIPYARD and restores maximum condition. `RepairTransaction` contains waypoint, ship, nonnegative `totalPrice`, timestamp. | Preview uses the dedicated GET and records quote time. There is no quote identifier, expiry, reservation, or server price ceiling. Re-read before dispatch, enforce local reserve and consent limits, then compare actual cost. Repair does not restore integrity. |
| `get-scrap-ship`, `scrap-ship` | GET returns 200 `data.transaction`. POST has no body and returns 200 `data.agent`, `transaction`, without a ship. Execution requires DOCKED at a SHIPYARD, removes the ship, and returns part of its value. `ScrapTransaction` has the same field names as repair. | Destructive action needs exact ship identity and explicit one-action confirmation. Invalidate pending intents, equipment plans, selection, and fleet caches after confirmed removal. Preserve history. Do not assume cargo or equipment survives or is separately credited. The schema does not specify their disposition. |

Sources: [S, `get-repair-ship`, `repair-ship`, `get-scrap-ship`, `scrap-ship`, `RepairTransaction`, `ScrapTransaction`, `ShipComponentCondition`, `ShipComponentIntegrity`].

Condition is repairable, but its description says repairs reduce integrity. Integrity is non-repairable wear. The plan must show both separately and must not describe repair as restoring a ship to new. The exact repair degradation and valuation formulas remain calibration questions. The scrap POST description mistakenly points to the Get Ship action for a preview. The dedicated scrap GET explicitly defines the quote. Neither quote GET states a docking or cooldown precondition. Do not silently copy POST constraints onto GET as schema guarantees. [S, same operations and models]

### Equipment changes alter capabilities and cargo

| Operation IDs | Schema contract | Limits and calibration |
| --- | --- | --- |
| `install-mount`, `remove-mount` | POST `{symbol}` returns 201 `data.agent`, `mounts`, `cargo`, `transaction`. Both require DOCKED at a SHIPYARD and deduct a fee. Installation requires the mount in cargo. Removal requires the installed mount. | Refresh equipment, cargo, credits, and dependent capabilities. Symbol identifies a mount type, not a slot or instance. Duplicate-symbol selection, returned cargo details, exact fees, and full-hold removal behavior need evidence. No fee quote or server spending cap is defined. |
| `install-ship-module`, `remove-ship-module` | POST `{symbol}` returns 201 `data.agent`, `modules`, `cargo`, `transaction`. Installation requires the module in cargo. Removal places it in cargo. | These operation descriptions do not specify docking, shipyard, cooldown, or fee rules. The response contains a transaction price, but that does not prove a particular fee. Do not copy mount requirements as documented module rules. Verify them later. |

Sources: [S, the four operations, `ShipModificationTransaction`, `ShipModule`, `ShipMount`, `ShipRequirements`, `ShipFrame`].

`ShipRequirements` has optional `power`, `crew`, and `slots`. `ShipFrame` defines module slots and mounting points, and explains that each fitted item consumes capacity. **Design:** preflight available cargo, power, crew, slots, and mounting points using returned configuration and documented requirements. **Calibration:** confirm aggregate accounting and removal constraints, especially removing a cargo hold or crew quarters while occupied. Do not treat optional requirements as known zero without a stated policy. These are checks on a fit, not permission to invent equipment recipes. [S, `ShipRequirements`, `ShipFrame`; E, equipment error names]

The `ShipModule` description still says installations are permanent, while the same pinned schema defines `remove-ship-module` and explicitly returns the module to cargo. Implement and accept the removal operation; retain this contradiction as a documentation issue. Refresh full ship data because module changes can affect capacity and capabilities beyond the returned module list. Mount removal returns cargo but does not explicitly promise its exact inventory delta in prose. [S, `ShipModule`, `remove-ship-module`, `remove-mount`; Design]

### Contracts, cargo disposal, and resource processing

| Operation ID | Input and declared output | Preconditions, side effects, and limits |
| --- | --- | --- |
| `negotiateContract` | Empty POST, 201 `data.contract`. The operation ID's camel case is exact. | Ship at a waypoint with a faction present. Offered and ongoing contracts count against the stated current maximum of 1. Negotiation creates an offered contract. Acceptance is separate. No target faction field, fee, cooldown, dock state, or envoy-module requirement is specified. Do not make acceptance an automatic follow-up. |
| `jettison` | POST `{symbol: TradeSymbol, units: integer >= 1}`, 200 `data.cargo`. | Discards cargo. No nav state, equipment, or cooldown restriction is stated. Design validates fresh owned quantity and previews permanent loss. No credit or salvage return is promised. |
| `siphon-resources` | Empty POST, 201 `data.siphon`, `cooldown`, `cargo`, `events`. | Gas giant, IN_ORBIT, siphon mounts, and gas processor. Output amount and cooldown duration are returned, not client choices. Preserve condition events. Exact yields, capacity-edge behavior, and equipment scaling need calibration. |
| `ship-refine` | POST `{produce}`, 201 `data.cargo`, `cooldown`, `produced`, `consumed`. Each result entry has `tradeSymbol` and `units`. | Requires refinable cargo and a suitable refinery module. Description states 100 basic goods become 10 processed goods. `produce` enum is IRON, COPPER, SILVER, GOLD, ALUMINUM, PLATINUM, URANITE, MERITIUM, FUEL. There is no quantity parameter. Exact input mapping, batch behavior, and module compatibility are not fully specified here. Nav state is unstated. |

Sources: [S, each named operation, `Siphon`, `Cooldown`, `ShipConditionEvent`].

**Design:** resource preview records cargo headroom and cooldown evidence, then displays actual `produced`, `consumed`, or `yield` values. Never substitute expected yield for execution output. Cooldown readiness can be a conservative local guard, but label unstated per-operation restrictions as client policy. Refine's published conversion ratio does not justify community recipes or a fixed cooldown formula. [S, `ship-refine`, `siphon-resources`, `Cooldown`; Design]

### Flight modes, jump, warp, and construction

| Operation ID | Input and declared output | Preconditions, side effects, and limits |
| --- | --- | --- |
| `patch-ship-nav` | PATCH object with optional `flightMode: ShipNavFlightMode`, 200 `data.nav`, `fuel`, `events`. | Only flight mode is supported. Enum DRIFT, STEALTH, CRUISE, BURN, default CRUISE. No explicit nav-state or cooldown restriction appears. The schema does not define speed or fuel formulas. An intentional mode change and a travel action need distinct approval records. |
| `jump-ship` | POST `{waypointSymbol}`, 200 `data.nav`, `cooldown`, `transaction`, `agent`. | IN_ORBIT and a connected destination waypoint. Buys and consumes one antimatter at a variable market price. It jumps instantly but incurs a returned cooldown. The body has no maximum cost or quote token. |
| `warp-ship` | POST `{waypointSymbol}`, 200 `data.nav`, `fuel`. | IN_ORBIT, Warp Drive installed, destination in another system. Consumes ship fuel and returns a route with arrival time. Most actions are unavailable during travel. No cooldown or condition events field is promised in this response. |
| `supply-construction` | POST to the system and waypoint path with `{shipSymbol, tradeSymbol, units}`, 201 `data.construction`, `cargo`. | Waypoint `isUnderConstruction` must be true. Good must be in ship cargo and is removed to increase construction materials. `units` is integer with no schema minimum. No payment, refund, nav-state requirement, or automatic jump is promised. |

Sources: [S, each named operation, `ShipNavFlightMode`, `ShipNav`, `JumpGate`, `Construction`, `ConstructionMaterial`].

Jump's operation description omits a drive requirement, while official errors include missing jump module, gate-to-gate restriction, and origin or destination under construction. **Design:** plan gate-to-gate travel, fresh connections, construction checks, readiness, and a jump-drive capability check, with the extra constraints traced to E rather than falsely attributed to the POST description. **Calibration:** exact drive tiers, range constraints, cooldown, pricing availability, and gate completion races need verification. Construction errors also name invalid location and fulfilled or unneeded materials. Use fresh site demand and available cargo, require positive units locally, and label that validation as a client rule. Docking requirements for supply remain unconfirmed. [E, ship and construction errors; S, `jump-ship`, `supply-construction`]

Navigation within a system, warp between systems, and instantaneous jump are distinct operations. Use server route arrival and fresh nav as authority. Do not reuse a CRUISE fuel or timing formula for BURN, STEALTH, DRIFT, or warp without primary evidence and calibration. PATCH returns fuel and events, so treating it as a change to a single cached string would lose documented effects. A universal in-transit prohibition in `CommandRules::inspect` must be evaluated per operation; it is a local restriction, not proof that the schema disallows PATCH during transit. [S, `navigate-ship`, `warp-ship`, `jump-ship`, `patch-ship-nav`; C, `CommandRules::inspect`]

## Scans and required read contracts

`create-ship-system-scan`, `create-ship-waypoint-scan`, and `create-ship-ship-scan` are POSTs without bodies. Each requires a Sensor Array mount, creates a cooldown, and returns 201 with `data.cooldown` and its collection, respectively `systems`, `waypoints`, or `ships`. No orbit requirement, range formula, or fixed duration is stated in these operation descriptions. An orbit/readiness guard may be a local policy pending calibration. Empty collections are valid. A scan consumes a gameplay opportunity even if used only to collect information, so it still requires durable write intent and manual approval. [S, three scan operations]

The result types differ. `ScannedSystem` requires symbol, sector, type, x, y, distance. Despite the system-scan prose mentioning waypoints, its item schema contains no waypoint list. `ScannedShip` requires symbol, registration, nav, and engine, with optional partial frame, reactor, and mounts. It is not an owned full `Ship`. `ScannedWaypoint` exposes traits for uncharted locations. Scan knowledge is not a public chart. `create-chart` charts the current location for everyone, records the submitting agent, and provides a one-time credit reward. Preserve all returned scan rows, their provenance, and observation time rather than only one selected target. [S, `ScannedSystem`, `ScannedShip`, `ScannedWaypoint`, `create-chart`]

| Read operation IDs | Contract and acceptance consequence |
| --- | --- |
| `get-status` | Public status includes version, reset information, announcements, and leaderboards. A reset-only helper is partial. |
| `get-systems`, `get-system` | Public list and detail. A `System` includes waypoints and factions. The system's waypoint entries do not replace full waypoint detail. |
| `get-system-waypoints`, `get-waypoint` | Public paginated catalogue and detail. Catalogue supports `type` and one or more `traits`, including the declared scalar-or-array form. Uncharted locations hide actual traits. Detail and normalization must retain `isUnderConstruction`. |
| `get-agents`, `get-agent`, `get-my-agent` | Public agent list/detail and private self detail are separate operations. Agent schema includes headquarters, startingFaction, shipCount, credits, symbol, and optional accountId. Do not replace public detail or private self refresh with an old fleet snapshot. |
| `get-factions`, `get-faction` | Paginated list and faction detail, including traits and recruiting state. Detail has the inherited-auth ambiguity described above. |
| `get-jump-gate` | Public read at a JUMP_GATE returns `JumpGate`, with `symbol` and `connections`, a list of connected waypoint symbols. System symbols are not accepted destination substitutes. No range or construction state is included in this model. |
| `get-construction` | Public read requires `isUnderConstruction` true. Returns symbol, materials with tradeSymbol/required/fulfilled, and isComplete. Completion can race with reads and contributions. Cache absence and completion carefully. |
| `get-supply-chain` | Agent-authenticated 200 `data.exportToImportMap`, an object mapping strings to arrays of strings. No pagination or quantities. The mapping describes dependencies, not manufacturing ratios, prices, or an optimization strategy. |
| `get-my-ships`, `get-my-ship` | Agent-owned list and detail return full `Ship`, including cargo, nav, cooldown, modules, and mounts. Preserve component quality and other documented fields. List pagination can span different observation times. |
| `get-my-ship-cargo`, `get-ship-nav` | Dedicated reads return 200 `data` as `ShipCargo` and `ShipNav`. Freshness matters independently of the last aggregate snapshot. |
| `get-ship-cooldown` | 200 `data: Cooldown` or **204 without a body** when no cooldown. A mandatory JSON `data` parser is wrong for 204. `expiration` is optional even on Cooldown objects. Preserve an absent expiration separately from any local derived deadline. |
| `get-mounts`, `get-ship-modules` | Dedicated reads return 200 `data` arrays of installed `ShipMount` and `ShipModule`. Do not confuse installed items with equipment in cargo. Modules inherit ambiguous root auth. |
| `get-market`, `get-shipyard`, `get-contracts`, `get-contract` | Existing read mappings remain in the acceptance denominator. Test visibility-dependent fields, full configurations and terms, pagination, stale observations, and missing or expired records. |

Sources: [S, listed operation IDs and referenced schemas]. Paginated lists declare page >= 1, limit 1 to 20, default limit 10, and `Meta` containing total/page/limit. Budget exhaustion or a configured page cap must produce resumable incomplete coverage, not a claim that the complete universe or fleet was fetched. [S, `get-systems`, `get-agents`, `get-factions`, `get-system-waypoints`, `get-my-ships`, `get-contracts`, `Meta`; Design]

### Aggregate data does not silently retire dedicated reads

Schema establishes shape overlap: `Ship.cargo`, `.nav`, `.cooldown`, `.mounts`, and `.modules` use the same models as dedicated responses. It does **not** establish equal cache behavior, observation times, permissions in practice, or 204 semantics. [S, `Ship`, dedicated read response schemas]

Adopted foundation acceptance implements all five dedicated read routes as user-accessible refreshes through the shared budget. Aggregate reads can inform presentation but do not retire published operations. Response shape overlap alone cannot pass the completion gate. Any later removal of an operation would be an explicit scope change outside the adopted plan. A local snapshot read alone is not an API operation implementation. [Design based on Taylor's coverage requirement]

## Durable intent and reconciliation must cover the new outcomes

Reusing `GameplayRequest` → `CommandRules` → `SpaceTradersApi` → `ShipCommandExecutor` is a sound foundation, provided strategy restrictions move out of the generic manual path. Keep owner/reset checks, immutable preview arguments, agent-wide serialization of credit and cargo effects, shared budgets, short-lived approvals, and the pending/sending/confirmed/rejected/uncertain record. Validate and retain operation-specific outputs before normalizing them for the UI. [Design; C, named classes, `ShipCommandExecutor::{preview,approve,execute,clear}`]

Reuse the Vue/Inertia dashboard's manual preview, approval, activity history, reconciliation, and acknowledgement patterns. Extend them with operation-specific controls and complete read views rather than routing manual work through the automation console. The existing command Stop endpoint calls `stopExploration()`, which rejects commands other than waypoint scans and charts. Its UI is likewise exploration-only. General manual Stop requires a deliberate extension and race tests before the broader foundation can claim it. [Design; C, `resources/js/Pages/Agent/Dashboard.vue`, `routes/web.php`, `AgentCommandController::stop`, `ShipCommandExecutor::stopExploration`]

No generic idempotency key, write replay guarantee, quote lock, or server spending ceiling appears in the pinned contracts. Dock and orbit explicitly describe idempotent state changes. Local intent keys prevent duplicate local approvals but are not upstream exactly-once guarantees. A timeout, dropped success response, malformed success payload, process death after sending, or persistence failure after receipt can leave an uncertain result. Record it and reconcile; never automatically resend. E's broad status-code rules do not prove that a proxy 5xx or broken body means a mutation never happened. [S, write request contracts, `dock-ship`, `orbit-ship`; E; Design]

| Outcome family | Required fresh reconciliation evidence and limit |
| --- | --- |
| Repair | Agent, full ship component condition and integrity, known response transaction if retained. A changed condition or credit balance alone may have another cause. |
| Scrap | Complete paginated owned fleet, agent, and any retained authoritative transaction. A missing-ship error alone cannot distinguish scrap from stale identity, auth failure, or reset. The current unconditional ship refresh must not prevent recording disappearance. |
| Install/remove | Installed lists, full ship capacities, cargo multiplicities, agent, and retained transaction. Compare duplicate symbols as quantities. A matching final fit alone does not prove the exact fee. |
| Negotiate | Fresh complete contract list, contract terms and ID if known, faction/location evidence. Do not negotiate again because the first response was lost. |
| Jettison/refine/siphon | Fresh cargo and cooldown, plus retained result/events. Current inventory can corroborate deltas but cannot reconstruct a lost random yield or prove a particular attempt by itself. |
| PATCH/navigate/warp/jump | Fresh nav, fuel, cooldown where relevant, agent for jump, and retained transaction/events. Arrival is a later observation, not another write. Re-check system identity and destination. |
| Construction | Fresh cargo and site materials/completion. Other agents can contribute concurrently, so an increased fulfilled count alone is insufficient proof. |
| Scan/chart | Retained results and cooldown; fresh chart/waypoint/agent as relevant. Re-reading public waypoint data cannot reconstruct all lost scan results or prove which agent caused a global change. |

This table is a design proposal, not a promise that reconciliation always proves an outcome. Keep unresolved results uncertain, distinguish an operator acknowledgement from proven confirmation, and retain evidence history. The schema exposes no general historical ledger for repair, scrap, or equipment modifications. Do not invent one. [S, operation inventory; C, `ShipCommandExecutor::reconcile`]

Current confirmation merges only nav, fuel, cargo, and cooldown into a pre-action ship, except purchase uses the returned new ship. It needs explicit handling for repair's full replacement ship, equipment lists and derived capacities, cross-system travel, and scrap's absence. The present reconciliation path always refreshes the original ship, which is incompatible with a successful scrap. Adding API path strings without these outcome changes is incomplete. [C, `ShipCommandExecutor::{confirm,reconcile}`]

## Implementation dependency order

This is a proposed order for the parent plan, not implementation performed by this research task.

1. **Freeze coverage and defer new autonomous development.** Check in the schema/hash and a 59-operation acceptance ledger. Mark existing mappings unaccepted until verified. Keep new AI/strategy enablement gated; preserve existing approved manual execution and operational work. Keep manual reads and one approved command operable without a strategy.
2. **Finish transport, read models, and evidence.** Add full public reads, dedicated ship reads, quotes, jump gates, construction, and supply chain. Accept 204, pagination/filter forms, optional visibility, source timestamps, reset scope, and budget deferrals. Preserve fields required by later preflight.
3. **Generalize the manual intent pipeline.** Add method-aware dispatch for PATCH, per-operation input/result contracts, fresh preflight, mutation-specific reconciliation, and preview/history/Stop UI. Remove mining, target-question, CRUISE-only, and strategy-run requirements from generic manual capability while preserving owner and spending controls.
4. **Accept normal manual operations and variants.** Complete existing contract actions, general ship purchases, arbitrary supported scans/charts, flight-mode PATCH and navigation in all four modes, and both market and cargo refueling. Add negotiation, jettison, siphon, and refine with explicit side-effect previews.
5. **Accept fit changes and repair.** Equipment reads and cargo/credits models precede installation/removal. Refresh capabilities and invalidate dependent approvals. Repair follows full component modeling and quote handling. Resolve module auth and precondition gaps before claiming live acceptance.
6. **Accept cross-system travel and construction.** Gate/connection/site reads and equipment support precede jump and warp. Construction supply needs fresh demand/cargo and concurrent completion handling. No route optimizer is required.
7. **Accept scrap last and close the foundation gate.** Its read quote can ship earlier. Execution depends on deletion-safe history, selection, cache invalidation, and reconciliation. Run the complete acceptance ledger, record remaining calibration gaps, and require a separate decision before enabling any engine, AI, or strategy work.

## Acceptance criteria missing from a source-mapping plan

The parent should include these explicit gate criteria:

- Every one of the 59 operation IDs has a manual user path including every dedicated read. Each variant, including non-mining purchases and all flight modes, is accounted for. Bounded task-specific scans, hard-coded miner setup, and CRUISE-only travel remain partial.
- Preview itself never performs a gameplay write. It shows actor, ship or contract, exact inputs, observed preconditions and freshness, cargo loss/gain, credit estimate, reserve, fee uncertainty, cooldown/travel consequences, destructive effects, and unsupported guarantees. Quote refresh does not imply reserved pricing.
- The UI can inspect complete read results, filter/page lists, refresh individual ship state, view history and unresolved evidence, and approve one exact action. Forms preserve `tradeSymbol` versus `symbol`, destination waypoint versus system, `produce`, and PATCH method semantics.
- Stop prevents an approved-but-unsent intent from being sent, with a tested race at reservation. It cannot undo a sent request, scrap, jettison, resource consumption, or construction contribution. After a send race, Stop leaves visible settlement/reconciliation work. No automatic reversal or restoration action runs without its own intent.
- Tests cover wrong local owner, wrong remote agent, reset mismatch, stale preview, insufficient inventory/credits/equipment, empty results, optional fields, cooldown 204, pagination caps and changing totals, auth ambiguity, rate-limit deferral, transit/arrival, price drift, construction races, and removal with occupied capacities. Document conservative client restrictions separately from server contracts.
- Crash/recovery tests exercise duplicate delivery, lost response after a committed write, malformed 2xx, failure to persist a valid success, and process death after `sending`. Reconciliation never blindly replays. Acknowledgement never becomes fabricated proof.
- Confirmation retains all required result fields, actual transaction amounts, events, component changes, new contract IDs, scan collections, and authoritative timestamps. Local UTC storage and comparisons remain intact; the UI labels displayed local time.
- Fixture and contract tests prove local behavior. Browser interaction proves usable manual flows. They do not prove live game mechanics. Later live calibration must be separately authorized, bounded, and tied to each unresolved contract question. Unavailable equipment, no eligible gate/site, or an untested destructive operation remains an explicit gap, not a passing acceptance result.
- Cargo-refuel, restoration, and itinerary/profiling branch claims are verified only after integration at the actual accepted revision. Automatic itineraries and profiling-driven optimization remain outside this foundation gate.

## Traps and calibration backlog

The most material unresolved questions are module auth and location/fee/removal rules, duplicate equipment selection and capacity changes, repair integrity loss, scrap cargo/equipment disposition, PATCH during transit, jump drive tier/range/pricing rules, warp fuel/timing, scan range/cooldown, siphon headroom/yields, refinery recipes/batching, and construction location/oversupply behavior. S and E support only the boundaries described above. None was tested live here.

The documentation can drift independently of the pinned file. For example, E references an `/error-codes` operation, but no such operation exists in these 59 paths. Record that discrepancy rather than silently expanding or omitting the agreed baseline. Recompare the official schema when implementation starts. Future documented additions require a deliberate coverage update. Weapon-like equipment symbols and broad game descriptions do not establish combat operations. The pinned schema has no combat endpoint. [S, `/paths`; E]

## Complete pinned operation inventory

This inventory defines the denominator, not the delivered state. Each row is cited to S at the pointer rule above. Reads and writes at the same path remain separate rows.

<!-- The rows below are generated from the pinned schema. -->

| Method | Path | Operation ID |
| --- | --- | --- |
| GET | `/` | `get-status` |
| POST | `/register` | `register` |
| GET | `/systems` | `get-systems` |
| GET | `/systems/{systemSymbol}` | `get-system` |
| GET | `/systems/{systemSymbol}/waypoints` | `get-system-waypoints` |
| GET | `/systems/{systemSymbol}/waypoints/{waypointSymbol}` | `get-waypoint` |
| GET | `/systems/{systemSymbol}/waypoints/{waypointSymbol}/market` | `get-market` |
| GET | `/systems/{systemSymbol}/waypoints/{waypointSymbol}/shipyard` | `get-shipyard` |
| GET | `/systems/{systemSymbol}/waypoints/{waypointSymbol}/jump-gate` | `get-jump-gate` |
| GET | `/systems/{systemSymbol}/waypoints/{waypointSymbol}/construction` | `get-construction` |
| POST | `/systems/{systemSymbol}/waypoints/{waypointSymbol}/construction/supply` | `supply-construction` |
| GET | `/factions` | `get-factions` |
| GET | `/factions/{factionSymbol}` | `get-faction` |
| GET | `/my/agent` | `get-my-agent` |
| GET | `/agents` | `get-agents` |
| GET | `/agents/{agentSymbol}` | `get-agent` |
| GET | `/my/contracts` | `get-contracts` |
| GET | `/my/contracts/{contractId}` | `get-contract` |
| POST | `/my/contracts/{contractId}/accept` | `accept-contract` |
| POST | `/my/contracts/{contractId}/deliver` | `deliver-contract` |
| POST | `/my/contracts/{contractId}/fulfill` | `fulfill-contract` |
| GET | `/my/ships` | `get-my-ships` |
| POST | `/my/ships` | `purchase-ship` |
| GET | `/my/ships/{shipSymbol}` | `get-my-ship` |
| GET | `/my/ships/{shipSymbol}/cargo` | `get-my-ship-cargo` |
| POST | `/my/ships/{shipSymbol}/orbit` | `orbit-ship` |
| POST | `/my/ships/{shipSymbol}/refine` | `ship-refine` |
| POST | `/my/ships/{shipSymbol}/chart` | `create-chart` |
| GET | `/my/ships/{shipSymbol}/cooldown` | `get-ship-cooldown` |
| POST | `/my/ships/{shipSymbol}/dock` | `dock-ship` |
| POST | `/my/ships/{shipSymbol}/survey` | `create-survey` |
| POST | `/my/ships/{shipSymbol}/extract` | `extract-resources` |
| POST | `/my/ships/{shipSymbol}/siphon` | `siphon-resources` |
| POST | `/my/ships/{shipSymbol}/extract/survey` | `extract-resources-with-survey` |
| POST | `/my/ships/{shipSymbol}/jettison` | `jettison` |
| POST | `/my/ships/{shipSymbol}/jump` | `jump-ship` |
| POST | `/my/ships/{shipSymbol}/navigate` | `navigate-ship` |
| PATCH | `/my/ships/{shipSymbol}/nav` | `patch-ship-nav` |
| GET | `/my/ships/{shipSymbol}/nav` | `get-ship-nav` |
| POST | `/my/ships/{shipSymbol}/warp` | `warp-ship` |
| POST | `/my/ships/{shipSymbol}/sell` | `sell-cargo` |
| POST | `/my/ships/{shipSymbol}/scan/systems` | `create-ship-system-scan` |
| POST | `/my/ships/{shipSymbol}/scan/waypoints` | `create-ship-waypoint-scan` |
| POST | `/my/ships/{shipSymbol}/scan/ships` | `create-ship-ship-scan` |
| POST | `/my/ships/{shipSymbol}/refuel` | `refuel-ship` |
| POST | `/my/ships/{shipSymbol}/purchase` | `purchase-cargo` |
| POST | `/my/ships/{shipSymbol}/transfer` | `transfer-cargo` |
| POST | `/my/ships/{shipSymbol}/negotiate/contract` | `negotiateContract` |
| GET | `/my/ships/{shipSymbol}/mounts` | `get-mounts` |
| POST | `/my/ships/{shipSymbol}/mounts/install` | `install-mount` |
| POST | `/my/ships/{shipSymbol}/mounts/remove` | `remove-mount` |
| GET | `/my/ships/{shipSymbol}/scrap` | `get-scrap-ship` |
| POST | `/my/ships/{shipSymbol}/scrap` | `scrap-ship` |
| GET | `/my/ships/{shipSymbol}/repair` | `get-repair-ship` |
| POST | `/my/ships/{shipSymbol}/repair` | `repair-ship` |
| GET | `/market/supply-chain` | `get-supply-chain` |
| GET | `/my/ships/{shipSymbol}/modules` | `get-ship-modules` |
| POST | `/my/ships/{shipSymbol}/modules/install` | `install-ship-module` |
| POST | `/my/ships/{shipSymbol}/modules/remove` | `remove-ship-module` |
