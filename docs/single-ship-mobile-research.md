# Single-ship mobile web research

Researched November 1, 2026. Research only. Sources and local source code were read without accessing credentials, live gameplay, or deployment configuration.

For current UI decisions, use the approved [design brief](design-brief.md) and [two-pass redesign report](single-ship-redesigns.html). The layout proposals below record the earlier research and are retained as context.

## Recommendation

Build a dark, phone-first flight deck around one selected ship. Give the ship a small animated scene, a readable destination and ETA, and an obvious “Ship report” button. Tapping the ship opens the same report, starting with cargo and fuel. Keep existing command previews and approvals below the scene. A ship-name selector can expose the existing fleet later without redesigning this screen.

This is a web UI recommendation. The separate [iPhone/TestFlight plan](ios-app-plan.md) describes a future native client and does not establish an installed app or deployed Linode engine.

Throughout this document, **API fact** means verified official documentation or schema, **local capability** means inspected source, and **recommendation** means proposed behavior. No browser or device validation was performed.

## Existing local capabilities

The inspected stack is Laravel 13, PHP 8.4.1, Vue 3.5, Inertia 3, Tailwind 4, and Vite 8, as declared in [composer.json](../composer.json) and [package.json](../package.json). These are dependency constraints, not an installed-runtime audit.

| Local source | What already exists | Implication for this UI |
| --- | --- | --- |
| [App styling](app-styling.md), [AppShell.vue](../resources/js/Layouts/AppShell.vue), [command.css](../resources/css/command.css) | Shared dark shell, navigation, skip link, focus styling. Colors include space `#0a111c`, panel `#101b2b`, ink `#e4edf7`, mint `#a6f3d0`. | Reuse tokens. Keep all reports dark regardless of OS theme. Use readable system text; reserve pixel styling for art and short labels. |
| [AgentDashboardController](../app/Http/Controllers/AgentDashboardController.php), [Ship](../app/Models/Ship.php), [AgentSynchronizer](../app/Gameplay/AgentSynchronizer.php) | Ownership-checked page with ship snapshots, `observed_at`, actions, runs, observations, worker and scheduler health. | Most report data already reaches Vue. Saved snapshots are observations, not guaranteed current upstream state. |
| [Dashboard.vue](../resources/js/Pages/Agent/Dashboard.vue) | Fleet selection persisted in session storage per user, agent, and reset; fuel, cargo, crew, arrival, cooldown, equipment; five-second Inertia polling. | A single-ship view is a presentation change. Future switching already has a data and selection basis. Polling this page reloads local state, not necessarily fresh SpaceTraders data. |
| [SpaceTradersApi](../app/Gameplay/SpaceTradersApi.php), [CommandRules](../app/Gameplay/CommandRules.php), [ShipCommandExecutor](../app/Gameplay/ShipCommandExecutor.php), [routes](../routes/web.php) | Shared preview, approval, execution, and reconciliation; dock, orbit, navigate, mining, surveys, transfer, trade, refuel, limited setup ship purchase. Navigation currently requires CRUISE. | Preserve the command path and automation ownership checks. API availability does not mean this client supports warp, jump, repairs, crew controls, flight-mode changes, or loadout editing. |
| [dateTime.js](../resources/js/dateTime.js) | UTC-aware formatting into the browser's timezone and an exported timezone label. | Reuse it for arrival and observation times. Keep stored values, comparisons, scheduling, and `<time datetime>` in UTC. |

The inspected web entry points have a viewport declaration but no PWA manifest link or service-worker registration. `public/build/manifest.json` is Vite's asset manifest. A cute ship scene, expandable ship report, and live ETA countdown are proposed additions. `GuidedFlight.vue` currently provides a tutorial coach, not a flight sprite animation.

## Verified SpaceTraders models

API facts below come from the official [OpenAPI repository](https://github.com/SpaceTradersAPI/api-docs). The fetched reference document identifies version `2.3.0`, targeting `/v2`. This verifies the published contract, not every field returned by a live account.

| Model and exact source | Fields relevant to the report | Presentation recommendation |
| --- | --- | --- |
| [Ship](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/Ship.json), [registration](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipRegistration.json) | `symbol`, registration name, faction and role; nav, crew, frame, reactor, engine, cooldown, modules, mounts, cargo, fuel. | Identify by symbol and role. A sprite is a local art choice; the Ship model supplies no image URL. |
| [ShipNav](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipNav.json), [route](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipNavRoute.json), [route waypoint](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipNavRouteWaypoint.json) | System and waypoint symbols, route origin/destination with coordinates, `departureTime`, `arrival`, status, flight mode. | Show origin → destination only during transit. The route may describe an earlier journey while stationary. Coordinates support an illustrative route, not real-time telemetry. |
| [Nav status](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipNavStatus.json), [flight mode](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipNavFlightMode.json) | Status is `IN_TRANSIT`, `IN_ORBIT`, or `DOCKED`. Modes are `DRIFT`, `STEALTH`, `CRUISE`, `BURN`. | Keep navigation status separate from command execution and automation status. |
| [ShipFuel](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipFuel.json) | Tank `current`, `capacity`; optional `consumed.amount` and `consumed.timestamp` for the most recent consumption. | Show tank units numerically. Do not animate a fictional continuously draining tank or confuse cargo FUEL with tank units. Handle capacity zero without division. |
| [ShipCargo](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipCargo.json), [cargo item](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipCargoItem.json) | `capacity`, occupied `units`, inventory with symbol, name, description and units. | Show used/capacity and a vertical item list, with an explicit empty state. |
| [ShipCrew](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipCrew.json) | `current`, `required`, `capacity`, shift `rotation` of STRICT or RELAXED, morale 0–100, hourly wages per crew member. | Label required crew separately from maximum capacity. Keep morale, rotation and wages in the full report. |
| [Condition](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipComponentCondition.json), [integrity](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipComponentIntegrity.json), [quality](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipComponentQuality.json) | Condition and integrity are separate 0–1 values. Condition is repairable; repairs reduce integrity. Integrity represents permanent wear. Quality is another component attribute. | Give frame, engine and reactor separate condition/integrity percentages. An averaged “ship health” would be a UI invention and could conceal a weak component. |
| [Cooldown](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/Cooldown.json) | `shipSymbol`, `totalSeconds`, `remainingSeconds`; `expiration` is optional. | Display readiness separately from arrival. The local adapter synthesizes an expiration when absent; distinguish that estimate from an upstream timestamp when explaining timing. |
| [Frame](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipFrame.json), [engine](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipEngine.json), [reactor](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipReactor.json) | Frame slots, mounting points, fuel capacity; engine speed; reactor power output; component identity, requirements, condition, integrity, quality. | A “Systems” section can render existing nested snapshots. Engine speed is a stat, not a live velocity reading. |
| [Module](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipModule.json), [mount](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipMount.json), [requirements](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipRequirements.json) | Installed arrays; module capacity/range where applicable; mount strength/deposits where applicable; power, crew and slot requirements. Module installations are described as permanent. | Show quantities for duplicate equipment and meaningful optional attributes. Missing attributes are unknown or inapplicable, not automatically zero. |

Schema gap: `ShipNavRoute.required` includes `departure`, although its properties define `origin` and `departureTime`, not a `departure` object. Use documented properties and defensive display fallbacks. Do not manufacture a departure field. The local adapter validates arrival but does not require every upstream report field, so older snapshots can be incomplete.

## Travel timing and animation

API facts: the official [navigation guide](https://spacetraders.io/game-concepts/ship-navigation) describes faster, fuel-hungry BURN, economical slow DRIFT, default CRUISE, and slower STEALTH. The official [endpoint specification](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/reference/SpaceTraders.json) says navigation requires orbit and a destination in the same system. Navigate and warp return arrival information; warp requires a Warp Drive. Jump is instant to a connected waypoint and returns cooldown information. The cooldown endpoint can return HTTP 204 when none exists. Transit and cooldown therefore need distinct displays. No fixed “typical” trip duration or travel-time formula was established by this research.

Recommendations:

- Calculate remaining travel from `arrival − now`, clamped at zero. For valid positive route duration, illustrative progress is `clamp((now − departureTime) / (arrival − departureTime), 0, 1)`. Missing or invalid departure data should produce an ETA without a percentage.
- Use a stationary ship with subtle exhaust/star movement during transit. The progress indicator advances from timestamps. This remains legible for seconds or hours without implying measured position or velocity.
- At zero remaining time, show “Arrival due · awaiting refresh” until a fresh observation confirms the state. Refresh on foreground/resume and after commands. Phone timers and animation completion must never authorize an action.
- Show docked ships beside a station and orbiting ships beside a waypoint. Keep cooldown visible as “Systems ready in…” on either scene. Include stale, offline, no-ship, and reset/archive states.
- Ship report order: cargo and fuel, navigation and cooldown, crew, frame/engine/reactor, installed equipment, then recent activity and observation time. Keep execution failures and automation stop/review controls visible outside the report.

## Three layout options

All three are design recommendations, not implemented screens.

| Layout | Phone arrangement | Strength | Cost |
| --- | --- | --- | --- |
| A. Ship scene with report sheet | Ship name at top; compact scene and ETA; fuel/cargo summaries; primary action; recent activity. Tap ship for a report sheet. | Best fit for one ship, animation, and quick checks. Most controls remain within thumb reach. | Needs accessible dialog behavior and a clear route to the complete report. |
| B. Ship / Actions / Activity tabs | Compact persistent ship summary; bottom navigation changes the main panel. Ship tap opens details. | Clear separation for heavier manual play and later fleet management. | More navigation and hidden context for today's small scope. |
| C. Scrollable instrument cards | Small scene, then cargo, fuel, route, crew, equipment and activity cards. | Straightforward responsive HTML; details are discoverable and linkable. | Long page pushes actions down and gives the ship less presence. |

Recommend A. Keep the scene around 180–220 CSS px high on a typical portrait phone, then let content scroll naturally. Use a short report sheet for cargo/fuel and an expandable “Full report” section with an explicit Back control for long details. On wide screens, report details can become an adjacent column. Future switching belongs behind the ship-name button, listing status and cargo for each owned ship. Do not require swipe gestures to switch.

## Mobile, PWA and accessibility guidance

- Primary-source guidance: Apple's [sheets HIG](https://developer.apple.com/design/human-interface-guidelines/sheets), also available as [Markdown](https://developer.apple.com/design/human-interface-guidelines/sheets.md), supports progressive disclosure and clear dismissal. Recommendation: one report sheet at a time, labelled Close, focus containment and restoration, Escape support, and a button alternative to dragging. Web CSS does not inherit native sheet accessibility automatically.
- Apple's current [accessibility HIG](https://developer.apple.com/design/human-interface-guidelines/accessibility.md) lists 44×44 pt default iOS/iPadOS controls and 28×28 pt minimum controls. WCAG 2.2 [target size AA](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html) specifies 24×24 CSS px with exceptions. These are different units and standards. Recommendation: target at least 44×44 CSS px for web controls, preferably 48 px for primary actions, with spacing. Put a small sprite inside a larger labelled button and provide the visible “Ship report” equivalent.
- Honor [`prefers-reduced-motion`](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/At-rules/@media/prefers-reduced-motion). Recommendation: freeze exhaust, stars, bobbing, zoom and sheet travel when reduction is requested. Preserve static status and ETA. Also offer a persistent motion toggle; WCAG [Pause, Stop, Hide](https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html) covers qualifying automatic motion lasting over five seconds and parallel auto-updating content. Offer a way to hide/pause the live countdown; retain the absolute arrival time and refresh on return. Pausing presentation must not pause gameplay.
- Recommendation: no per-second live-region announcements. Announce state transitions once, keep text outside decorative art, preserve zoom, and check long symbols at 320 px and enlarged text. WCAG [contrast](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html) calls for 4.5:1 normal text and 3:1 large text. Check muted labels and status colors on the actual dark backgrounds.
- WebKit documents Home Screen web apps using manifest `display: standalone` or `fullscreen`, and interaction-triggered push permission for installed iOS/iPadOS web apps in its [PWA guidance](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/). Recommendation: start with HTTPS, icons, stable manifest identity/start URL and `standalone` on the future subdomain. Treat push as later work. Installation alone does not provide offline gameplay or server execution.
- Use [WebKit safe-area guidance](https://webkit.org/blog/7929/designing-websites-for-iphone-x/) for `viewport-fit=cover` and `env(safe-area-inset-*)` if drawing edge to edge. Recommendation: inset bottom actions above the home indicator, allow browser chrome/keyboard resizing, and keep account/logout navigation reachable. Cache decorative assets if adding a service worker; avoid caching authenticated command responses or replaying offline writes.

## Art options and verified limits

Style descriptions below are observations of the official sample images, not claims about every file in an archive.

| Official source | Verified license and dimensions | Actual style and fit |
| --- | --- | --- |
| “Kenney Tiny Spaceships” | The candidate [URL](https://kenney.nl/assets/tiny-spaceships) returned HTTP 404. No matching pack was established through the official catalog/search. License and dimensions remain unverified. | Do not select or attribute an invented pack. This does not prove a similarly named pack cannot exist elsewhere. |
| [Kenney Pixel Shmup](https://kenney.nl/assets/pixel-shmup), [official sample](https://kenney.nl/media/pages/assets/pixel-shmup/43204e8358-1677495743/sample.png) | CC0; page lists 128 files and **16×16 tile size**. This is published grid metadata, not proof that every PNG has that size. | Genuine low-resolution pixel art, dark outlines, bright compact aircraft and projectiles over island terrain. Cute and readable, but the sample depicts planes, not an established spaceship fleet. Best pixel-style reference; select or adapt an appropriate silhouette before committing. |
| [Kenney Space Shooter Remastered](https://kenney.nl/assets/space-shooter-remastered), [official sample](https://kenney.nl/media/pages/assets/space-shooter-remastered/3a292e0d18-1677669436/sample.png) | CC0; 295 files. The page publishes no tile size or individual sprite dimensions. The older [Redux URL](https://kenney.nl/assets/space-shooter-redux) redirected here in the direct fetch. | Angular, smooth-edged illustrated spacecraft, rocks and lasers with shaded polygonal panels. Clearly spaceships, but not low-resolution 8-bit pixel art. `image-rendering: pixelated` alone will not create that style. |

The official Kenney download-link requests returned HTTP 403, so archive contents, native per-sprite dimensions and animation-frame availability were not verified. Do not budget for supplied flight animation frames. Both verified pack pages link [CC0](https://creativecommons.org/publicdomain/zero/1.0/), which permits copying, modification and commercial distribution without permission. Preserve source/license records when assets are eventually imported.

Recommendation: prefer Pixel Shmup's pixel language and verify one suitable ship silhouette, or commission/draw a small original pixel spaceship. Render 16 px art at integer multiples such as 48 or 64 CSS px with nearest-neighbor scaling. A few exhaust frames and CSS transforms can provide flight motion without a game engine. Keep interface typography smooth and selectable. This is a proposed production approach, not evidence of an asset already present.

## Practical next steps and remaining gaps

1. Implement within the current Inertia dashboard and shared dark shell. Separate small Vue scene/report components only where useful. Keep snapshots and selected ship identity as inputs; reuse current actions, ownership, approval and reconciliation handlers.
2. Add a presentation clock for ETA, resynchronize after tab suspension, and use fresh server observations before offering actions. Preserve the existing five-second local polling without adding a second upstream polling loop. Display `observed_at` independently of page refresh time.
3. Confirm report field availability on synthetic and older snapshots. Crew morale/capacity, component quality, optional equipment attributes and route departure need display fallbacks. Resolve the published route schema inconsistency before relying on generated strict types.
4. Verify a phone workflow with synthetic data: transit to arrival, cooldown, empty/full cargo, zero-capacity fuel, long names, offline/resume, uncertain commands, automation control, keyboard/VoiceOver and reduced motion. The existing [page verification approach](app-styling.md) offers 320/390 px fixture-based coverage; it is not proof this proposed UI works yet.
5. Before hosting, confirm subdomain, HTTPS, authentication, process supervision, persistence and available Linode capacity. The existing [hosting research](linode-hosting-research.md) and native plan describe requirements. No deployment or continuous operation was verified here. Laravel's worker and scheduler must carry approved work when the phone closes.

Open decisions are the final spaceship artwork, minimum supported mobile browsers, exact PWA/offline scope, and whether the separate native-app plan remains a later phase. None blocks a first single-ship web screen.

## Vision-board interpretation

The companion [interactive HTML board](single-ship-vision.html) takes recommended layout A and compares three variations of it: Pocket bridge, Captain's ledger, and Route postcard. These narrower visual studies differ from the broader navigation alternatives above. The board recommends Pocket bridge with stable Bridge, Cargo, and Activity destinations. Its compact scene is 148 CSS px to keep the route, resources, and action visible within the 652 px concept canvas. The larger route variation intentionally gives more space to travel art.

The board includes original SVG pixel studies rather than bundling the third-party packs. Its values and command interactions are fictional and have no gameplay connection. The parent task verifies the HTML artifact separately; that verification does not establish native-device behavior, a production deployment, or live API correctness.
