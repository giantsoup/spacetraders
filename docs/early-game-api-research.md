# Early-game SpaceTraders API research

Accessed 2026-10-31. Read-only research using official documentation, bundled OpenAPI v2.3.0, and unauthenticated server status. No credentials, registration, authenticated reads, or gameplay writes were used. Endpoint behavior below is documented, not tested in gameplay.

## Recommendation and strategy comparison

Start with a bounded, single-ship extraction-and-sale loop **if discovery finds a capable miner, a usable asteroid, a buyer, and a fuel-safe route**. A marketplace on the extraction waypoint reduces travel, but must be discovered. Otherwise report the missing prerequisite. Buying or outfitting a ship is a separate spending decision. Add contract delivery after the basic loop can recover safely from interruptions. Defer automatic trade-route selection until local price observations support it. These are engineering recommendations, not profit forecasts.

| Approach | Documented mechanics | MVP tradeoff, inferred |
| --- | --- | --- |
| Extraction + sale | Extract into cargo, wait for cooldown, dock at a market trading that good, sell. Yields vary. [Extraction][extract], [selling][sell], [schema][spec] | No purchase cost for the extracted cargo. Requires equipment and a buyer; random yields, travel, fuel and extraction restrictions still matter. Simplest conditional starting loop. |
| Starter contract | Accept, obtain the specified goods, deliver to the specified waypoint, then fulfill for final payment. Read quantities, deadlines and payments from the contract. [First mission][mission], [delivery][delivery] | Clear progress target, but adds deadlines, reserved inventory and delivery travel. Estimate feasibility before acceptance; a contract is not evidence of favorable economics. |
| Trade loop | Buy at a market, transport, sell at another market. Prices respond to supply and demand; exports/imports suggest candidates. [Markets][markets] | Requires working capital, usable cargo capacity, price visibility at both ends, fuel budgeting and repeated price checks. An observed spread can disappear before sale. |

## Discover capabilities, never assume the starter fleet

**Verified documentation conflict:** current registration prose describes a command ship, a probe and 175,000 credits. The official October 15, 2023 changelog warns that starting ships and credits may differ; its statement is historical, not proof of today's random distribution. Treat fleet composition, equipment, ship symbols, credits and locations as variable inputs. Do not hard-code a miner, a `-1` ship role, a starting waypoint, or the quickstart's ship purchase. Discover through paginated `GET /my/ships`, `GET /my/agent`, and `GET /my/contracts`. The registration response contains a `ships` array; `/my/agent` alone does not return the fleet or contracts. [Schema][spec], [changelog][changes]

For ore extraction, require installed `MOUNT_MINING_LASER_*`, `MODULE_MINERAL_PROCESSOR_I`, free cargo space, an extractable waypoint, orbit, and expired extraction cooldown. The error reference names missing mineral processor `4242`, missing mining lasers `4243`, invalid extraction waypoint `4205`, permission `4206`, destabilization `4253`, and no yield `4261`. The processor requirement is also recorded in the May 14, 2023 changelog. Asteroid types such as `ASTEROID_FIELD`, `ASTEROID` and `ENGINEERED_ASTEROID` are discovery candidates, not guarantees of accessible deposits. [Errors][errors], [changelog][changes], [schema][spec]

Gas siphoning is separate: gas giant, orbit, siphon mount and gas processor. Survey creation requires a surveyor mount and incurs cooldown; surveys expire or exhaust. Current OpenAPI uses `/extract/survey` with the entire unmodified survey object. The older guide's survey body on `/extract` is deprecated. Exclude surveys and siphoning from the first loop unless deliberately selected. Outfitting must respect power, crew and slots; mount installation requires docking at a shipyard, the mount in cargo, and an installation fee. [Extraction][extract], [outfitting][outfitting], [schema][spec]

## Exact endpoint reference

All paths below are relative to `https://api.spacetraders.io/v2`. Placeholders are runtime symbols/IDs. JSON quantities are integers, despite some guide examples showing strings. Paths and bodies checked against the [bundled schema][spec].

| Method and path | Purpose or body |
| --- | --- |
| `GET /` | Public status, `version`, `resetDate`, `serverResets.next`. |
| `GET /my/agent` | Credits and agent identity. |
| `GET /my/ships` | Paginated fleet and equipment. |
| `GET /my/ships/{shipSymbol}` | Complete ship state for reconciliation. |
| `GET /my/ships/{shipSymbol}/cargo` | Cargo inventory and capacity. |
| `GET /my/ships/{shipSymbol}/nav` | Navigation status and route. |
| `GET /my/ships/{shipSymbol}/cooldown` | Cooldown; `204` with no body when none exists. |
| `GET /systems/{systemSymbol}/waypoints` | Paginated discovery; `type` and `traits` filters. |
| `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}` | Waypoint details; uncharted traits may be hidden. |
| `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}/market` | Goods; prices/transactions when an owned ship is present. |
| `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}/shipyard` | Discover ship availability and visible prices. |
| `POST /my/ships/{shipSymbol}/orbit` | Enter orbit. Explicitly idempotent. |
| `POST /my/ships/{shipSymbol}/dock` | Dock. Explicitly idempotent. |
| `PATCH /my/ships/{shipSymbol}/nav` | `{"flightMode":"CRUISE"}`; also `DRIFT`, `BURN`, `STEALTH`. |
| `POST /my/ships/{shipSymbol}/navigate` | `{"waypointSymbol":"…"}`; same system, orbit required. |
| `POST /my/ships/{shipSymbol}/refuel` | Optional `units` in tank units; optional boolean `fromCargo`. |
| `POST /my/ships/{shipSymbol}/extract` | Basic extraction without a survey. |
| `POST /my/ships/{shipSymbol}/sell` | `{"symbol":"IRON_ORE","units":10}`. |
| `POST /my/ships/{shipSymbol}/purchase` | `{"symbol":"IRON_ORE","units":10}`. |
| `GET /my/contracts` | Paginated contracts. |
| `GET /my/contracts/{contractId}` | Current terms and delivery progress. |
| `POST /my/contracts/{contractId}/accept` | Accept an offered, unaccepted, unexpired contract. |
| `POST /my/contracts/{contractId}/deliver` | `{"shipSymbol":"…","tradeSymbol":"IRON_ORE","units":10}`. |
| `POST /my/contracts/{contractId}/fulfill` | Complete after every delivery requirement is met. |
| `POST /my/ships/{shipSymbol}/negotiate/contract` | Ship at a waypoint with a faction; documented maximum one ongoing/offered contract. |

Optional setup/expansion paths, also verified in OpenAPI: `POST /my/ships` with `shipType` and `waypointSymbol`; `POST /my/ships/{shipSymbol}/mounts/install` with `symbol`; `POST /my/ships/{shipSymbol}/survey`; `POST /my/ships/{shipSymbol}/extract/survey`; `POST /my/ships/{shipSymbol}/siphon`. None were invoked.

## Navigation, fuel, markets and contracts

- **Verified:** navigation consumes fuel according to distance and flight mode, returns `nav.route.arrival`, and leaves most actions unavailable during transit. Extraction returns cooldown and cargo; use server timestamps rather than a fixed mining interval. The extraction guide permits navigation during cooldown, whereas the generic cooldown description is broader. Treat transit and cooldown as separate constraints. `DRIFT` is documented as slower and fuel conserving; exact fuel/time formulas and zero-tank behavior were not established here. [Navigation][nav], [extraction][extract], [schema][spec]
- **Verified:** buying/selling requires docking and `MARKETPLACE`; the market must trade the good. Price visibility requires an owned ship at the waypoint, including an orbiting probe, rather than necessarily docking that observer. Missing `tradeGoods` means unknown prices, not zero prices. `tradeVolume` is the per-transaction maximum for **both purchases and sales**, not total inventory. It also indicates price volatility. Re-read market data between batches; bound purchases by volume, free cargo and credits, and sales by volume and owned inventory. [Markets][markets], [schema][spec]
- **Verified:** market refueling requires docking and fuel offered for sale. One market unit supplies 100 tank units. Request `units` uses tank units; omission fills the tank. `fromCargo: true` uses carried `FUEL`. The endpoint prose says refueling always fills the tank, but its request schema explicitly permits partial fills. Use the returned fuel/cargo/transaction as authoritative. Do not assume every asteroid sells fuel or that zero fuel capacity describes a conventional fueled ship. [Navigation][nav], [schema][spec]
- **Verified:** contract payment has `onAccepted` and `onFulfilled`; `deadlineToAccept` replaces deprecated `expiration`, while `terms.deadline` is the delivery deadline. Read every delivery's `tradeSymbol`, `destinationSymbol`, `unitsRequired` and `unitsFulfilled`. Delivery consumes matching cargo at that destination. Docking is a conservative client policy, not an explicit deliver-endpoint requirement. Reserve needed cargo before selling, and fulfill separately after delivery. The endpoint checks cargo and location, without prescribing how the goods were obtained. [Schema][spec], [delivery][delivery]
- **Recommendation:** reserve enough credits and fuel for travel to a known refueling market, including the return leg when necessary. Record actual transaction totals and fuel expense. Route feasibility, acceptable margins, run budgets and stopping thresholds remain client policy; no local prices, starter fleet or profitability were verified.

## Rate limits, resets and uncertain writes

**Verified:** the rate-limit guide lists both IP and account limits of 2 requests/second, with burst 30 and burst duration 60 seconds. Its historical example uses a different burst value. Honor actual `Retry-After` and `X-RateLimit-*` headers on `429`; infrastructure errors can lack those headers. The guide advises backing off for infrastructure errors and waiting minutes after DDoS-layer `502`. Recommendation: one shared backend limiter below the sustained ceiling, including UI reads and workers, with bounded jittered backoff. Vue polling must not bypass it. [Rate limits][limits]

**Live observation:** unauthenticated `GET https://api.spacetraders.io/v2` returned online, version `v2.3.0`, `resetDate: "2026-10-25"`, and `serverResets.next: "2026-11-01T13:00:00.000Z"`, frequency `weekly`. HTTP Date was `Sat, 31 Oct 2026 16:43:10 GMT`. This is a snapshot. Current reset guidance points to `serverResets`; older `/resources/server-resets` guidance and status announcement prose give different weekdays. A schedule is not reset proof. [Status][status], [current reset guide][resets], [older guide][old-resets]

**Recommendation:** persist the observed `resetDate` with agent state and queued intentions. Recheck status on startup/resume and periodically; a changed reset date stops old jobs and invalidates old entity/market caches. Agent tokens are reset scoped. Authentication failure should pause and reconcile status, not trigger blind re-registration. A temporary outage or overdue `serverResets.next` alone does not prove a reset. [Schema][spec], [reset guide][resets]

**Verified boundary:** only dock/orbit are explicitly described as idempotent among these POST operations. No generic idempotency-key or exactly-once guarantee was found in the inspected schema. A timeout, dropped connection or `5xx` after dispatch leaves an extraction, trade, refuel, navigation or contract write uncertain.

**Recommendation:** record an intent and pre-action state before dispatch; serialize each ship's actions and shared-credit spending. After an ambiguous result, fetch ship/cargo/nav, credits and relevant contract progress, and inspect available recent market transactions before considering another write. Those transactions are a bounded recent list without a documented unique request ID, so reconciliation cannot always prove exactly what occurred. Keep unresolved actions paused for review instead of automatically replaying them. Test crash/retry recovery later with authorized gameplay; this research establishes no execution guarantee. [Schema][spec]

## Primary sources

All accessed 2026-10-31. Documentation statements and the live status observation are distinguished above; recommendations are client design judgments.

[spec]: https://api.spacetraders.io/v2/documentation/json
[status]: https://api.spacetraders.io/v2
[extract]: https://docs.spacetraders.io/game-concepts/extracting-resources
[sell]: https://docs.spacetraders.io/quickstart/sell-cargo
[mission]: https://docs.spacetraders.io/quickstart/first-mission
[delivery]: https://docs.spacetraders.io/quickstart/last-steps
[markets]: https://docs.spacetraders.io/game-concepts/markets
[changes]: https://docs.spacetraders.io/resources/changelog
[errors]: https://docs.spacetraders.io/api-guide/response-errors
[outfitting]: https://docs.spacetraders.io/game-concepts/outfitting
[nav]: https://docs.spacetraders.io/game-concepts/ship-navigation
[limits]: https://docs.spacetraders.io/api-guide/rate-limits
[resets]: https://docs.spacetraders.io/server-resets
[old-resets]: https://docs.spacetraders.io/resources/server-resets

- [Official bundled OpenAPI][spec] and [interactive reference](https://docs.spacetraders.io/openapi).
- [Public API status][status].
- [Extraction][extract], [markets][markets], [navigation][nav], [outfitting][outfitting], and [error reference][errors].
- [Starter contract][mission], [sale][sell], and [contract delivery][delivery].
- [Rate limits][limits], [server resets][resets], and [changelog][changes].
