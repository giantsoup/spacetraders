# Interactive system map

Issue: https://github.com/giantsoup/spacetraders/issues/90

Status: Delivered and locally verified. See [verification evidence and limitations](interactive-system-map-verification.md). Deployment and live gameplay were not performed.

## Requested feature

Add a dedicated, dark, full-page map of the selected ship's current star system. Taylor wants to pan and zoom, click planets and other locations, and inspect a small information card. Use stored observations only. Missing information remains Unknown.

## Approved choices

- Current system first; no galaxy view in this version.
- Stored knowledge only; opening, panning, zooming and selecting must not fetch upstream details or trigger gameplay.
- Generated 8-bit artwork with unique, reproducible designs based on each location's known type, traits and descriptions.

## Acceptance criteria

- [x] Add an authenticated per-agent map route and links from the flight deck and navigation.
- [x] Open the selected ship's current system; preserve ship selection when returning.
- [x] Provide drag pan, wheel and pinch zoom, zoom buttons, Fit system and Center on ship.
- [x] Plot actual coordinates. Manage labels by zoom and make overlapping locations individually selectable.
- [x] Show a compact location card containing identifier, known type, coordinates, traits and descriptions, with market, shipyard and faction information when stored.
- [x] Show observation times and distinguish Unknown from confirmed empty results. Mark partial catalogue coverage.
- [x] List known locations that lack coordinates separately rather than inventing positions.
- [x] Use a small anchored desktop card and compact phone bottom card; support outside click, Escape, keyboard access and touch.
- [x] Generate stable pixel-art families for all published waypoint types, with trait-based visual differences and neutral fallbacks when information is unknown.
- [x] Keep cosmetic variation separate from factual signals. Never invent terrain, services, prices or discoveries.
- [x] Scope observations to the authenticated owner, selected agent and current game reset.
- [x] Keep the page usable with an empty fleet, no observations, stale data, incomplete observations and co-located locations.
- [x] Verify desktop and phone behavior in a real browser, inspect screenshots, exercise keyboard and touch interactions, and verify no upstream reads or gameplay writes while browsing.
- [x] Run relevant automated checks, build frontend assets, and inspect the final diff.

## Implementation direction

Use the existing Vue/Inertia application with an SVG map, reusable deterministic pixel-art generator and location card. Reuse GameObservation, Ship, readable-label helpers and local-time presentation. Keep map projection and knowledge assembly behind a small server-side interface rather than duplicating observation rules in UI components.

Location art is seeded by reset and waypoint identity. Known traits may refine appearance without changing the location's basic visual identity. Render crisp pixel art with readable text and large interaction targets. The selected ship's location is last observed, and in-transit ships must not be presented as confirmed arrivals.

## PR request and authorization

Create a feature branch and one PR for this issue, initially containing this plan, then complete implementation in a separate worktree thread. Taylor explicitly authorized autonomous implementation, browser verification, local commits, pushing this feature branch and opening/updating its PR while unavailable. Make routine reversible decisions without waiting for Taylor. Do not merge, deploy, or mutate live gameplay. Preserve unrelated work and use owned isolated fixtures for verification. Keep reports and review artifacts in dark mode.

Final PR description must describe actual delivered behavior, browser and automated evidence, remaining limitations, and link this issue.

## Delivered implementation

`SystemMapKnowledge` assembles owner- and reset-scoped waypoint, service, catalogue, and saved manual system observations without changing storage. The SVG page shares ship selection with the flight deck. It includes deterministic code-generated pixel sprites, bounded marker groups, exact coordinate dots, keyboard controls, a desktop information card, and a phone bottom card.

The map keeps unknown, observed empty, old, and invalidated information distinct. Directory and quote ages are independent. Unplotted locations remain selectable from the location directory. In-transit ships show their observed departure with arrival unconfirmed.

## Verification and limits

The full repository verification script passes, including 518 PHP tests with 3,041 assertions and 6 map JavaScript tests. T3 preview verified desktop and phone layouts, real drag/click/keyboard workflows, overlap selection, empty/unknown/stale states, and ship-selection round trips. Browser event probes exercised wheel and two-pointer touch handlers. Gameplay table and fake transport hashes stayed unchanged across browser verification.

Follow-up phone coverage used native Chromium input in an emulated viewport. Physical device gestures, Safari, Firefox, screen readers, deployment, and live gameplay remain unverified. Catalogue completeness is historical, and map browsing never requests missing data. See the [verification report](interactive-system-map-verification.md) for exact checks and screenshots.

## Follow-up integration and QA

Merged latest main `f84bd824fe5bc117339d5ece249205ab6c7d54ba`, including the API rate tracker. The follow-up native Chromium suite covers 24 scenarios across desktop, phone, small phone, landscape and reduced viewport layouts. It exercises all stored fixture locations, every marker, native wheel and touch, card/camera combinations, keyboard shortcuts, focus and Escape priority, navigation/history/authentication, tracker interactions and recovery, and the no-gameplay boundary.

Claude Sonnet 5.5 independently reviewed the integration source. Confirmed issues were reproduced, fixed, and covered by the native browser regressions. The [latest QA report](interactive-system-map/qa/qa.html) records exact revision, source-review limits and physical-device/browser coverage gaps.
