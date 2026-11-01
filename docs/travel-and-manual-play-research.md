# SpaceTraders travel and manual play research

Accessed 2026-10-31. Research only, using public official documentation and first-party OpenAPI/source. No private tokens, authenticated requests, gameplay actions, or application changes were used. This note does not assess this client's workers or hosting.

## Conclusions

- **Many-hour trips are not established as typical.** Official documentation describes timed travel and much slower DRIFT travel, but gives no representative duration range. A long timer needs its route, flight mode, engine speed and returned timestamps checked before calling it normal or a bug. No particular ship or live trip was inspected. [Navigation][nav], [engine model][engine]
- **Manual play is reasonable for learning and occasional commands.** The official quickstart explicitly supports sending individual requests through the browser or cURL. My judgment is that managing a small fleet intermittently is practical, while repeatedly mining, selling and routing a growing fleet becomes tedious. Automation is a stated purpose of the game, rather than a requirement for issuing commands. [Quickstart][quickstart], [official overview][overview]
- **An always-on personal server is not required just to let an accepted trip finish.** The API returns an arrival timestamp and provides a read endpoint for current navigation state. The architectural inference is that elapsed travel belongs to the game service and does not require an open browser, continuous polling, or a running personal client. The inspected docs do not explicitly promise offline behavior, and this was not tested. Unattended follow-up commands need some running client process to issue them; that can be a local computer or a host, depending on the desired uptime. [Navigation][nav], [OpenAPI][spec]

## Travel modes and speed

The official navigation guide gives these qualitative descriptions. It publishes no numeric multipliers here. Flight mode is configured with `PATCH /my/ships/{shipSymbol}/nav`; the schema defaults to `CRUISE`. [Navigation][nav], [OpenAPI][spec]

| Mode | Documented speed | Documented fuel behavior |
| --- | --- | --- |
| `DRIFT` | Much slower | Least fuel; described as useful when fuel is depleted or scarce |
| `CRUISE` | Normal, default | Normal consumption |
| `BURN` | Faster | Faster consumption |
| `STEALTH` | Reduced | Normal consumption; systems run minimally to reduce detectability |

Higher `engine.speed` makes navigation faster. Coordinates distinguish waypoint positions within a system from system positions at galactic scale. Navigation's fuel requirement explicitly depends on distance. These sources do not establish a numeric distance-to-duration conversion, distance rounding, fixed departure overhead, or mode multiplier. Do not present a community calculator's equation as an official formula. [Engine model][engine], [coordinates][quickstart], [OpenAPI][spec]

## Navigate, warp and jump

The current bundled OpenAPI v2.3.0 describes the following operations. All require orbit. [OpenAPI][spec]

| Operation | Scope and constraints | Timing |
| --- | --- | --- |
| `POST …/navigate` | Destination waypoint in the same system; consumes fuel based on distance | Returns route and expected arrival; most actions unavailable until arrival |
| `POST …/warp` | Destination in another system; requires an installed Warp Drive; consumes fuel | Returns route and expected arrival; most actions unavailable until arrival |
| `POST …/jump` | Destination must be a connected waypoint; purchases and consumes antimatter | Instant movement; returns cooldown, so another jump cannot follow immediately |

The navigation guide identifies jump gates as the intersystem alternative to a warp drive. Its warp/jump examples still show `systemSymbol`, while the current OpenAPI request bodies require `waypointSymbol`. Prefer the current schema for request shape. [Navigation][nav], [OpenAPI][spec]

## Arrival and offline clients

`nav.route.departureTime` and `nav.route.arrival` are date-time strings. The schema describes `arrival` as the arrival time, or expected arrival while in transit. `GET /my/ships/{shipSymbol}/nav` returns current navigation status. A countdown should derive from the server's returned timestamp, then refresh state before the next action. The local countdown reaching zero alone does not prove the server currently permits that action. [Route model][route], [OpenAPI][spec]

For investigation, calculate observed trip length as `arrival - departureTime` and remaining time as `max(0, arrival - current time)`, with correctly parsed time zones. These are timestamp differences, not a prediction formula. Record mode, engine speed and origin/destination alongside them. A stale UI, clock error or time-zone bug must be distinguished from an actual long server ETA. This is a diagnostic recommendation, not an observed defect. [Route model][route], [engine model][engine]

An offline client cannot send the next extract, sell, refuel or navigation request. Thus a ship completing a trip and a bot continuing a trade loop are separate concerns. The former follows the timestamped travel model; the latter requires a process issuing further HTTP commands. This is an inference from the API's command model, not evidence about this repository's implementation. [Official overview][overview], [OpenAPI][spec]

## Automation and request limits

The official overview invites scripts that mine asteroids and automate trade routes, as well as web/mobile fleet clients. Individual manual requests and automation are both supported approaches. [Official overview][overview], [quickstart][quickstart]

The rate-limit guide currently lists **2 requests/second for both IP address and account**, with burst limit **30 requests** and burst duration **60 seconds**. Its old example uses burst 10, so do not treat the example as the current limit. Handle `429` using actual `Retry-After` and `X-RateLimit-*` headers; infrastructure errors can lack the game's rate-limit headers. The guide recommends backoff and waiting a few minutes after DDoS-layer `502` responses. Scheduling a refresh near arrival instead of polling constantly is a client recommendation. [Rate limits][limits]

## Evidence limits

No authoritative duration equation or numeric mode multiplier was found in the inspected navigation guide, current bundled OpenAPI, or relevant first-party schema models. The public `api-docs` repository is documentation/schema source, not proof of the private game server's timing implementation. Its inspected revision was `45fbb04130aca3fa0bd9a634ab77b35fa6c468ab`. No community formula or forum assertion is used as fact here. Typical trip duration and explicit offline guarantees remain unverified. [First-party source][source], [OpenAPI][spec]

[nav]: https://docs.spacetraders.io/game-concepts/ship-navigation
[quickstart]: https://docs.spacetraders.io/quickstart/new-game
[overview]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/README.md
[spec]: https://api.spacetraders.io/v2/documentation/json
[engine]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipEngine.json
[route]: https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/models/ShipNavRoute.json
[limits]: https://docs.spacetraders.io/api-guide/rate-limits
[source]: https://github.com/SpaceTradersAPI/api-docs/tree/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab
