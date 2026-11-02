# Interactive system map

Issue: https://github.com/giantsoup/spacetraders/issues/90

Status: Approved plan. Implementation and verification are pending.

## Requested feature

Add a dedicated, dark, full-page map of the selected ship's current star system. Taylor wants to pan and zoom, click planets and other locations, and inspect a small information card. Use stored observations only. Missing information remains Unknown.

## Approved choices

- Current system first; no galaxy view in this version.
- Stored knowledge only; opening, panning, zooming and selecting must not fetch upstream details or trigger gameplay.
- Generated 8-bit artwork with unique, reproducible designs based on each location's known type, traits and descriptions.

## Acceptance criteria

- [ ] Add an authenticated per-agent map route and links from the flight deck and navigation.
- [ ] Open the selected ship's current system; preserve ship selection when returning.
- [ ] Provide drag pan, wheel and pinch zoom, zoom buttons, Fit system and Center on ship.
- [ ] Plot actual coordinates. Manage labels by zoom and make overlapping locations individually selectable.
- [ ] Show a compact location card containing identifier, known type, coordinates, traits and descriptions, with market, shipyard and faction information when stored.
- [ ] Show observation times and distinguish Unknown from confirmed empty results. Mark partial catalogue coverage.
- [ ] List known locations that lack coordinates separately rather than inventing positions.
- [ ] Use a small anchored desktop card and compact phone bottom card; support outside click, Escape, keyboard access and touch.
- [ ] Generate stable pixel-art families for all published waypoint types, with trait-based visual differences and neutral fallbacks when information is unknown.
- [ ] Keep cosmetic variation separate from factual signals. Never invent terrain, services, prices or discoveries.
- [ ] Scope observations to the authenticated owner, selected agent and current game reset.
- [ ] Keep the page usable with an empty fleet, no observations, stale data, incomplete observations and co-located locations.
- [ ] Verify desktop and phone behavior in a real browser, inspect screenshots, exercise keyboard and touch interactions, and verify no upstream reads or gameplay writes while browsing.
- [ ] Run relevant automated checks, build frontend assets, and inspect the final diff.

## Implementation direction

Use the existing Vue/Inertia application with an SVG map, reusable deterministic pixel-art generator and location card. Reuse GameObservation, Ship, readable-label helpers and local-time presentation. Keep map projection and knowledge assembly behind a small server-side interface rather than duplicating observation rules in UI components.

Location art is seeded by reset and waypoint identity. Known traits may refine appearance without changing the location's basic visual identity. Render crisp pixel art with readable text and large interaction targets. The selected ship's location is last observed, and in-transit ships must not be presented as confirmed arrivals.

## PR request and authorization

Create a feature branch and one PR for this issue, initially containing this plan, then complete implementation in a separate worktree thread. Taylor explicitly authorized autonomous implementation, browser verification, local commits, pushing this feature branch and opening/updating its PR while unavailable. Make routine reversible decisions without waiting for Taylor. Do not merge, deploy, or mutate live gameplay. Preserve unrelated work and use owned isolated fixtures for verification. Keep reports and review artifacts in dark mode.

Final PR description must describe actual delivered behavior, browser and automated evidence, remaining limitations, and link this issue.
