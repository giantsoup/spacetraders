# Complete the base SpaceTraders API client

Status: implementation plan, not implementation acceptance. Decision recorded November 1, 2026. Audited source: `99be3c474dddd7bf9fd1c1aec5bf2147cf9b5868`, the PR #85 merge. The protected primary checkout and active live-local cutover are unchanged by this plan.

## Product decision and completion language

Taylor's priority is a complete manual client for the official SpaceTraders API before further strategy development. This plan supersedes the delivery order in the earlier strategy, adviser, exploration-optimization, and optional-efficiency plans. Preserve their dated research; do not treat their queue position as permission to resume them.

The current release is an **early-game baseline**, not a complete local implementation. Being able to mine and sell does not establish full API coverage. Use these separate labels:

- **Ready for a bounded local playtest:** the selected build, credentials, database, runtime, processes, and specific real-API workflow have passed their cutover gates. This can precede base-API completion.
- **Base API implemented, acceptance pending:** all published operations and supported input variants have first-class manual paths and contract/browser coverage, but one or more required acceptance records remain pending.
- **Base API foundation complete:** every pinned operation meets the acceptance contract below, the combined build passes, and the applicable real-API acceptance evidence is recorded. No missing, partial, local-only, or aggregate-only row remains. Unavailable equipment, budgets, or world conditions are recorded as pending gates, never silently counted as passes.
- **Strategy layer:** optional automation and investment decisions built on that completed foundation. Existing bounded runs can remain available; no new strategy scope is required by this plan.

Merging a task brief, completing a local branch, running unit tests, and deploying code are different events. None alone satisfies the foundation-complete label.

## Pinned scope and evidence

The [official OpenAPI specification](https://spacetraders.io/SpaceTraders.json) currently publishes **59 operations: 26 GET reads, 32 POST actions, and one PATCH action**, version 2.3.0. The complete pinned source is [spacetraders-openapi-2.3.0.json](api/spacetraders-openapi-2.3.0.json). Its SHA-256 and retrieval time are in [base-api-coverage.json](api/base-api-coverage.json). The readable [operation inventory](api/base-api-inventory.md) gives every method, path, source status, gap, and implementation owner.

All 59 operations are in scope, including server status, agent registration/profile and public directories, supply-chain data, read-only quotes, and dedicated ship-state reads. Local application-user registration is distinct from official game-agent registration; the private-user security policy remains intact. Creating account credentials through the official website is outside this published operation set. Do not invent account, combat, crew-management, or engine-replacement endpoints that the pinned specification does not publish. Discovering a new official operation must fail drift verification until it is classified and planned.

The baseline audit identifies eight source-mapped, 20 partial, five aggregate-only, one local-only, and 25 missing operations. These are **source classifications, not completion percentages**. No operation receives foundation acceptance in this planning pass. Each existing mapping still needs the full-operation acceptance audit.

Primary references: [OpenAPI documentation](https://docs.spacetraders.io/openapi), [navigation](https://docs.spacetraders.io/game-concepts/ship-navigation), [outfitting](https://docs.spacetraders.io/game-concepts/outfitting), and the pinned specification's exact operation IDs, request schemas, responses, descriptions, and security definitions. Schema guarantees, implementation policy, and inferred mechanics must be labeled separately. Community formulas and synthetic success are not evidence of live server mechanics.

The detailed [primary-source contract research](base-api-contract-research.md) records per-operation inputs/outputs, unresolved rules, and reconciliation evidence. Its proposed sequencing is advisory; this plan owns the adopted delivery order and preserves already-authorized local operation.

## Concrete gaps, including restrictions in existing code

Missing actions include repair, scrap, installing/removing mounts and modules, contract negotiation, jettison, siphoning, refining, navigation-mode PATCH, jump, warp, system/ship scans, and construction supply. Missing reads include systems and public-agent browsing, faction detail, jump gates, construction, supply-chain data, and repair/scrap quotes. Cargo/nav/cooldown/mount/module data partly comes from the aggregate ship response; that does not automatically establish operation equivalence or dedicated refresh behavior.

Existing functionality also needs widening:

- `SpaceTradersApi::mutate()` has an explicit command map but always sends POST. PATCH navigation needs an explicit method/path/payload mapping through the same executor, not an authenticated arbitrary forwarder.
- `CommandRules` requires contract commands to belong to an exact approved contract run. Add a manual contract lifecycle that needs no opportunity investigator, strategy selection, or run plan.
- `purchase-ship` is limited to a verified complete miner and a mining site/buyer. Add general purchases of valid offered ship types without requiring a mining strategy. The observer, affordability, ownership, exact offer, and uncertainty safeguards still apply.
- Charting and waypoint scanning currently require a marketplace/shipyard question. Expose the official manual actions and complete returned observations without requiring a strategy question. Retain safety, cooldown/equipment, reserve, and unknown-evidence controls.
- Navigation requires CRUISE. PATCH can express the official flight modes; accepting those strings in a ship response is not execution support. Model and verify their actual fuel/time/state behavior, including zero-capacity configurations, instead of silently treating every mode as CRUISE.
- Refueling sends explicit market units only. Cover `fromCargo` and omitted-units behavior from the schema. Plain extraction also publishes an optional survey payload; covering `/extract/survey` does not silently waive that variant.
- Waypoint normalization drops `isUnderConstruction`, and ship normalization derives a cooldown expiration when the API omits it. Preserve raw response fields and label derived deadlines. The dedicated cooldown read can return HTTP 204 with no JSON body.
- Full status/profile fields, query filters, and user-driven pagination need review. Bounded automatic discovery is useful, but its ten-page/three-system budgets are not the manual browser's total accessible universe.

The manifest records all request-body and query field names so these variants remain visible during implementation.

## Architecture and ownership

Extend the current Laravel 13/PHP 8.5.12, Vue 3/Inertia 3, Pinia, Tailwind, and database-queue application. Use the pinned Node/pnpm and unchanged dependency constraints unless a concrete operation proves a dependency necessary. No second API client, generic authenticated proxy, parallel executor, or strategy-only execution path is needed.

The intended manual flow is:

```text
Owned agent -> manual destination/action -> validated expiring preview
 -> explicit approval -> durable intent -> fresh preflight -> official API
 -> confirmed result OR rejected/uncertain state -> history/reconciliation
```

Reads use named bounded queries and the shared request budget. They preserve original observation times and provenance. A manual Next page or Refresh action can make another bounded request; no automatic unbounded universe crawl. Show pagination totals/continuation and unavailable data honestly. Keep credentials out of responses and browser storage.

Implementation responsibilities:

| Layer | Responsibility |
| --- | --- |
| Form requests and owner-scoped controllers | Parse official IDs, enums, integers and supported variants; validate the selected actor and both sides of transfers; enforce auth and CSRF. Do not permit user-supplied upstream URLs, verbs, or credentials. |
| `CommandRules` and operation-specific domain logic | Fresh equipment, nav, cooldown, cargo, credit, quote and target checks. Separate game rules from optional strategy restrictions. Validate known rules; label undocumented mechanics instead of inventing them. |
| `SpaceTradersApi` / `SpaceTradersClient` | Explicit method/path/payload mappings; validated authoritative responses; rate limits/timeouts; GET reuse only inside its proved freshness scope; invalidate after POST and PATCH. |
| `ShipCommandExecutor`, intent records and request budgets | Shared ownership/credit locks, exact approval identity, actor/target snapshots, serial dispatch, persistent Stop/reset/drain gates, uncertain outcomes and reconciliation. Support agent-scoped contracts and waypoint construction without pretending every target is only a ship. |
| Existing Vue flight deck and focused destinations | Manual fleet/cargo, Contracts, Outfitting, Maintenance, Navigation, Scans and Construction controls. Reuse preview, history, errors, focus and recovery components. No hidden strategy prerequisite. |
| Observations and accounting | Preserve loss, fuel, cargo, equipment, condition, credit, ship removal/acquisition, cooldown, destination and contract/construction changes with UTC timestamps. Keep unprovable adjustments explicit. |

Use explicit actor/target and operation variants where they differ; do not reuse a buy/sell shape for unrelated actions. Keep validation at system boundaries and domain calculations testable. Prefer the smallest coherent extension to the existing deep modules over a new framework. If an action requires new durable fields, use additive migrations and fixtures that preserve older queued histories and uncertain outcomes.

The foundation denominator has no aggregate-read exception: implement all published dedicated reads. Any later proposal to drop operations changes scope and must not pass the unmodified completion gate.

## Contract ambiguities that must stay visible

The pinned root `security` declaration places `AgentToken` and `AccountToken` together in one requirement, which means AND under OpenAPI semantics, despite both being bearer schemes. `get-faction`, `get-ship-modules`, `install-ship-module`, and `remove-ship-module` inherit it. Official registration guidance instead distinguishes account-token registration from agent-token gameplay. Do not concatenate tokens, send both, or fall back to an account token after a gameplay auth failure. FND-01 must obtain official clarification or bounded authenticated evidence for the affected operations and retain an unresolved acceptance gate meanwhile. `get-supply-chain` explicitly requires an agent token; it is not an anonymous directory read.

Repair restores condition, not non-repairable integrity; the model describes repair-related integrity wear. Module location/fee restrictions are not specified as completely as mount restrictions. Scrap cargo/equipment disposition, exact recipes/rounding, mode/warp formulas and construction location/oversupply behavior need source-backed decisions. Quote reads do not lock execution prices. Never turn missing documentation into an invented server guarantee. See the research operation IDs and model pointers for each distinction.

## Implementation sequence

Every task below is a concrete manual-API deliverable, not an automation strategy. Exact assigned operation IDs are in the manifest; the inventory is the coverage checklist. Tasks may share module files, so default to small sequential integrations. Parallel work must use separate worktrees, declare overlap, and pass a combined gate after integration.

### FND-01. Extend the common command and read boundaries

Prerequisites: none. First establish reusable safety for the missing actions without adding a generic proxy.

- Add explicit operation metadata for method, actor, target, payload variant and expected response effects. Cover POST and PATCH; GET quotes remain read-only.
- A preview quote is an observation, not a server price reservation or guaranteed spending ceiling. Explain unsupported guarantees, refresh before sending, preserve the reserve checks and record any confirmed price discrepancy for review rather than pretending the write failed.
- Make manual operations independent of candidate ranking, profit, mining setup, and strategy selection. Preserve reserve/ownership/equipment/state checks.
- Preserve expiring exact approvals, duplicate delivery, Stop before reservation, settlement after reservation, reset invalidation and uncertain-write blocking. Do not automatically replay writes because a response is missing.
- Generalize manual Stop deliberately: the current command-stop path is exploration-only. Other unsent manual intents need equivalent reservation-race protection and visible settlement after send.
- Extend reconciliation per operation. Ship disappearance after scrap, consumed equipment, new contracts, construction contributions and jump/warp transitions need specific evidence; absence alone is not proof of successful mutation.
- Deliver focused request/executor regressions, real file-backed worker checks, and a minimal manual UI path before downstream commands reuse this work.

### FND-02. Complete the manual read browser

Prerequisites: FND-01. Cover the manifest's status/register, systems/waypoints/markets/shipyards, faction, agent and supply-chain operations.

- Expose the complete relevant response fields, supported pagination and `type`/`traits` filters. Preserve unknown/hidden values, source times and partial-page state.
- Handle 204 cooldown responses without a mandatory JSON parser; keep missing raw expiration separate from a local derived deadline. Retain construction flags and complete documented fields.
- Public agent views are not local ownership authority. Only owned saved credentials can act for a private agent. Public reads must not leak an account or agent credential.
- Preserve account-token registration versus saved agent-token import and never automatically retry ambiguous registration.
- Specify manual navigation destinations and keyboard/mobile behavior. Data obtainable only through a legacy forwarding textbox is not accepted as a finished first-class view.

### FND-03. General fleet and cargo actions

Prerequisites: FND-01, FND-02. Cover fleet listing/detail, cargo/cooldown reads, purchase ship, orbit/dock, buy/sell/transfer cargo, and jettison.

- General ship purchase works for valid observed offers, without a miner/site/buyer prerequisite. Preserve a fresh exact offer and post-purchase reserve; inspect the actual delivered configuration.
- Add jettison with explicit symbol/quantity and irreversible-loss acknowledgement. Protect reserved cargo; validate that both transfer ships are owned and eligible.
- Implement the dedicated reads and expose all required fields and freshness. Aggregate snapshots can feed presentation but do not retire a published read operation. Inventory and cooldown refresh must not renew unrelated price observations.
- Verify pagination, valid non-miner purchase, insufficient funds/capacity, changed offer, stale cargo, duplicate approvals and lost responses. A lost purchase response cannot buy a second ship.

### FND-04. Manual contract lifecycle

Prerequisites: FND-01, FND-02. Cover contract list/detail, negotiation, acceptance, delivery and fulfillment without staging a strategy.

- Show offered/accepted/fulfilled/expired states, acceptance and delivery deadlines, terms, rewards and progress from authoritative data.
- Preview a specific negotiating ship or contract action. Validate current eligibility, exact terms, goods/destination/units and remaining obligations.
- Acceptance rewards and obligations are real side effects. Refresh contract and agent state before dispatch. Fulfillment and delivery cannot be replayed after an uncertain result.
- Keep existing contract automation as a caller of the same manual domain/executor rules. It must not become the required entry point.

### FND-05. Repair execution and maintenance quotes

Prerequisites: FND-01, FND-02, FND-03. Cover repair GET/POST and the scrap GET quote. Destructive scrap execution is FND-12, after other manual operations are integrated.

The scrap requirements here define the quote and preview contract. POST dispatch, deletion-safe confirmation and unknown-result tests belong exclusively to FND-12.

- Repair preview shows condition, authoritative quote, available credits and protected reserve. Revalidate immediately before execution and record the actual condition and transaction.
- Scrap preview names the exact ship and irreversible effects, quote and relevant cargo/equipment/active-intent state. Prevent scrapping a ship with unresolved authority; never silently choose another ship.
- Require explicit destructive confirmation. Reconcile loss of the ship and credit/transaction evidence without automatically replaying scrap. Test changed quotes, unknown success, insufficient funds and subsequent fleet refresh.

### FND-06. Mount and module outfitting

Prerequisites: FND-01, FND-02. Cover installed mount/module reads and all four install/remove actions.

- Show installed components and eligible cargo; validate actual ship/equipment requirements, location/state, power/crew/capacity/cargo and any quoted charge from the official contract.
- Preview exact symbol and before/after inventory/equipment effects. Record authoritative changes, invalidate affected plans/surveys/fuel provenance, and require fresh approvals.
- Test incompatible equipment, missing cargo, insufficient room/funds, multiple equivalent items, changed configuration and lost response. Do not claim reactor/engine/frame replacement if no endpoint exists.

### FND-07. Resource extraction and processing

Prerequisites: FND-01, FND-02; outfitting is useful for live prerequisites, not a new strategy dependency. Cover survey, both extraction endpoints/variants, siphon and refine.

- Manual siphoning and refining show eligible equipment/location, requested product, required inputs, cargo headroom and cooldown. Preserve unknown yields and unsupported conversions as explicit blockers.
- Reuse validated signed-survey payloads unchanged, including supported variants on plain extraction. Expiry/exhaustion or a changed survey requires a fresh choice; no silent fallback.
- Record actual consumed/produced cargo, condition and cooldown. Test capacity, partial/zero yield, invalid produce, unavailable input, cooldown restrictions, changed equipment and uncertain outcomes.

### FND-08. Navigation modes and refueling

Prerequisites: FND-01, FND-02. Cover GET/PATCH nav, same-system navigation and refuel input variants.

- Expose explicit CRUISE/DRIFT/BURN/STEALTH choices and separate mode-change approval from travel approval. Show the exact known fuel/time/continuation consequences and blockers.
- Cover market versus cargo fuel and explicit versus omitted `units` where allowed. Validate conversion/rounding and legal location/state from primary evidence; do not substitute an assumed formula.
- Reuse local EXP02 CRUISE-restoration and EXP03 cargo-refuel work after source review. Those branches are not merged acceptance and do not finish all modes or partial-unit semantics.
- Record reset/equipment/mode/directed-edge provenance for calibrated behavior. Where public contracts do not determine a safe model, obtain separately approved minimal live calibration. Unknown or zero-capacity mechanics remain pending foundation gates, not claimed support.
- Test changed mode, fuel shortage, unexpected consumption, zero capacity, protected FUEL cargo, rounding, partial tanks, unknown write and no rescue/replan loops. No automatic itinerary or profit ranking is required.

### FND-09. General scans and charting

Prerequisites: FND-01, FND-02. Cover system, waypoint and ship scans plus charting.

- Add manual operations without a mandatory marketplace/shipyard strategy question. Validate actual equipment, state, cooldown and any relevant bounds.
- Store and expose returned observations with distinct listing/scan/chart provenance and reset/time identity. Partial scan results do not prove completeness; missing targets remain unknown.
- Capture actual cooldown, range/evidence and chart reward/credit effects. Test unsupported sensors, overlapping observations, partial/empty results, Stop, reset and unknown results. Never turn one approval into an automatic exploration tour.

### FND-10. Jump-gate and warp travel

Prerequisites: FND-01, FND-02, FND-08. Cover jump-gate details, jump and warp.

- Read actual connections, construction/access restrictions and destination details. Validate engine/module capability, state, cooldown, fuel and any cost from the official operation.
- Present one exact destination with known travel/arrival consequences and an approved recovery reserve. Do not require discovering a profitable route before allowing otherwise valid manual travel.
- Refresh fleet/system observations after relocation. Test disconnected/blocked gates, changed access, insufficient fuel, unsupported ship, transit/cooldown conflicts, lost responses and changed reset. Never automatically repeat an uncertain jump or warp.

### FND-11. Construction participation

Prerequisites: FND-01, FND-02, FND-03. Cover construction detail and supply.

- Show actual required/delivered materials, completion and outstanding quantities for the target waypoint.
- Preview the exact owned supplying ship, site, good and units. Preserve protected/contract cargo and validate co-location/state/material requirements and concurrent progress.
- Update construction and cargo from the authoritative response. Test wrong material/site/state, insufficient cargo, over-supply, already-complete sites, another agent's contribution, reset and uncertain writes. Construction completion cannot be inferred from local intent.

### FND-12. Destructive scrap execution

Prerequisites: FND-03 through FND-11, including the scrap quote/read model from FND-05. Deliver `scrap-ship` last among mutations so deletion-safe history, active-intent checks, equipment/cargo effects, fleet selection and reconciliation are already integrated. Apply the exact-ship destructive preview and acknowledgement specified in FND-05. Use an expendable owned ship only under a separate numeric budget and explicit live authorization; lacking one leaves live acceptance pending. Never claim a missing ship alone proves successful scrap.

### FND-13. Combined foundation acceptance

Prerequisites: FND-01 through FND-12, all integrated into one source/asset set.

- Re-audit every inventory row and all documented request/query variants. Implement all five dedicated ship-state reads, including their response fields, freshness, authorization, user-triggered refresh semantics and cooldown 204 behavior. Aggregate snapshots do not replace their operation acceptance.
- Run locked dependency/platform checks, full backend tests, deterministic builds, affected PHP formatting and whitespace checks, real owned worker/restart/race scenarios, and actual Vue browser journeys. Keep dark responsive layout, focus, keyboard, clear disabled reasons and local-time presentation across new destinations.
- Exercise the real official API on the selected local environment under reviewed numeric budgets and approvals. Record operation/request identity, timestamps, prerequisites, outcome, and secret-free evidence. The normal play environment must have no fixture provider. Existing synthetic tests remain useful regression evidence but cannot stand in for this acceptance.
- Use expendable owned resources and separate explicit acknowledgement for registration, purchases, scrapping, jettison and irreversible construction supply. If equipment, credits, available world targets or authorization are absent, retain the exact pending case and do not label the foundation complete.
- Rehearse backup/restore, additive migration, compatible rollback and process cutover. Preserve the live key, credentials and history. Test Stop/restart and uncertain-write recovery with actual processes; actual Mac sleep/wake is a separately authorized disruptive acceptance case.
- Run the project QA and separate security review on the final revision, fix confirmed defects, then repeat affected checks. Independent final review uses Taylor's Sonnet 5.5 preference when available; do not silently substitute.
- Deliver a capability-to-UI map, complete operation evidence ledger, truthful completion status, live play instructions, sanitized dark report and rollback instructions. A reviewer must be able to reproduce the coverage check.

## Acceptance contract for every operation

1. A named native manual UI/API path exists, with no strategy or AI prerequisite. Ownership and credential role are correct; fields, supported variants, pagination and filters match the pinned schema.
2. Reads preserve freshness/provenance and errors. Writes use an exact expiring preview and shared persistent execution/recovery. Successful and rejected/malformed/unknown responses have documented outcomes; request budgets and HTTP failure handling remain effective.
3. Before/after effects include every relevant ship/cargo/equipment/credit/contract/construction/nav/cooldown change. A successful response is validated, not converted into a false rejection because a downstream bookkeeping write failed.
4. Meaningful contract regressions and a real UI journey pass. Real worker/duplicate-delivery/crash checks cover affected write boundaries. Synthetic transport is confined to owned test resources.
5. Official-API acceptance is recorded with exact source and artifact references. A blocked or missing live case remains visible and prevents blanket completion.
6. The operation row is marked accepted only after the evidence exists. The coverage checker must reject missing operations, request/query drift, unknown tasks, invalid dependency graphs, stale source mappings, and acceptance without evidence.

## Existing work and roadmap precedence

| Existing work | Foundation treatment |
| --- | --- |
| PR #85 core client and six features | Starting implementation; retain existing safeguards and dated evidence. It is not full API acceptance. |
| EXP02 / issue #53 local implementation | Reuse CRUISE restoration and provenance checks in FND-08; implement/validate the remaining mode behavior. |
| EXP03 / issue #55 local implementation | Reuse cargo-refuel preview/validation in FND-08; resolve omitted-unit/rounding/state gaps. |
| EXP04 / issue #57 local itinerary implementation | Deferred strategy convenience. Base manual API coverage does not require automatic multi-leg execution. Preserve the branch; do not count it as integrated. |
| OPT03 / issue #83 local profiling | Retain evidence and regressions when useful; no optimization target blocks manual API completion. |
| ADV02–ADV07 and exploration/fleet investment tasks | Deferred until FND-13. Do not enable paid model calls, automatic policy starts, notifications or speculative purchases. |
| Server hosting/deployment and mobile API/app/TestFlight | Separate delivery surfaces. They are not prerequisites for the local base-API foundation. |
| LIVE01/LIVE03 local cutover and approved real play | Continue already-authorized operational work as early-game acceptance, with accurate scope. The new completion standard does not revoke that authorization. |

Existing plan-task issues are historical tracking, not the authoritative new delivery order. Before issuing implementation assignments, reconcile those issues against this inventory and reuse compatible work rather than duplicating it. This documentation task creates no implementation PRs, changes no remote issue metadata, and starts no new feature workers. Implement FND-01/FND-02 first, then the manual action groups, scrap execution last, followed by FND-13. Defer strategy scope until that gate passes.

## Reproduce the audit

```sh
python3 scripts/verify-base-api-coverage.py
python3 scripts/verify-base-api-coverage.py --refresh
python3 -m unittest discover -s tests/ApiCoverage -v
python3 scripts/verify-base-api-coverage.py --require-complete
```

The default check validates the inventory and pinned source evidence; it does not run gameplay or certify completed features. `--refresh` fetches only the public official specification and reports drift without modifying files. `--require-complete` deliberately fails on this baseline because no operation has full foundation acceptance. Future implementation changes must refresh source mappings and real acceptance evidence instead of weakening the gate.

See [plan verification and review limits](api/base-api-plan-verification.md) for executed checks, the expected negative control, evidence-record format, and the unavailable Sonnet 5.5 final review. The verifier enforces record integrity; it does not replace inspection of real behavior or honest acceptance evidence.

No live credentials, environments, databases, services or game state were read or changed by this planning work. No implementation, deployment, purchase, paid API call, remote publication or strategy enablement is performed.
