# Ship status graphics on the flight deck

Status: design approved by Taylor on 2026-11-14 (PST). **Not implemented.** This is the plan for a
later session. No code has changed.

## Problem

The flight deck hero (`resources/js/Components/ShipScene.vue`) only knows one thing about the ship:
`transit` true or false. A docked ship, an orbiting ship and a ship with unknown navigation all get
the same pose, floating in the lower left, far from the location. Taylor's report: "this says the
state is docked, but it doesn't look very docked to me floating out there in space."

In transit, the ship also stays at the start of the route while only the progress line grows.

## Approved design

Mockups, built from the app's real ship and location sprites:

- [round-2-centered-phone.html](ship-status-graphics/round-2-centered-phone.html): the approved
  docked and in-orbit renders at iPhone 17 Pro width (402 pt), plus transit at that width.
- [round-1-all-states.html](ship-status-graphics/round-1-all-states.html): all five states. Its
  transit, checking-arrival and navigation-unknown renders are approved. Its docked and in-orbit
  renders are **superseded** by round 2.
- [mockup-generator.mjs](ship-status-graphics/mockup-generator.mjs) regenerates round 2 and holds
  the exact geometry: `node docs/ship-status-graphics/mockup-generator.mjs out.html`.

Taylor will mostly view this on an iPhone 17 Pro. Design for 402 pt wide first.

| State | When | Render |
| --- | --- | --- |
| Docked | `nav.status === 'DOCKED'` | Location and ship centred as one group, as large as the hero allows. Ship nose-in against the location's left edge, held by a two-bar docking clamp, with a blinking mint port light on the location. Engines off. Ship does not bob. |
| In orbit | `nav.status === 'IN_ORBIT'` | Location centred and large. A dashed, tilted orbit ring passes behind and in front of it. The ship sits on the front of the ring at lower left and bobs gently. Ring dashes drift. Engines off. |
| In transit | `IN_TRANSIT`, countdown running | Unchanged layout: destination at the right, route line from lower left. The ship now sits **on the route at its real progress**. Solid mint line behind the ship, dashes ahead. Flame flickers, ship bobs. |
| Checking arrival | `IN_TRANSIT`, countdown ended (`arrival === 'Checking'`) | Ship holds at the end of the route beside the destination. Flame off, no bob, no clamp, no ring: arrival is not confirmed. |
| Navigation unknown | any other or missing `nav.status` | Ship dimmed (45% opacity) and adrift in the lower left, with no route, clamp or ring. This is the only state that keeps today's floating pose. |

Other approved choices:

- In docked and in orbit, the location label ("Observed location" plus the symbol) moves from under
  the art to the **bottom-left corner** of the scene, so the art can use the full height. In transit
  it stays under the destination.
- The hero keeps its heights (230 px, 128 px at viewport height ≤ 719, 72 px at ≤ 600), so the
  status text and buttons below do not move.
- The ship stays in one place in orbit (only the ring moves), because the ship is the button that
  opens the ship report.
- "Pause motion" and `prefers-reduced-motion` stop every animation: bob, flame flicker, ring drift
  and port blink.

## Geometry

Sprites are pixel grids: the location is 24×24 (`waypointSprite()` in `resources/js/waypointSprites.js`),
the ship is 32×24. `S` is CSS pixels per location pixel, `K` is CSS pixels per ship pixel. Use whole
numbers so the pixel art stays crisp.

| Hero height | Docked / in orbit | Transit, checking, unknown |
| --- | --- | --- |
| 230 px | `S = 9` (216 px), `K = 4` (128×96) | location 112 px as today, `K = 2` (64×48) |
| 128 px | `S = 5` (120 px), `K = 2` (64×48) | location 80 px as today, `K = 2` |
| 72 px | **not designed yet**, see open questions | location 48 px as today |

The ship's hull spans columns 8–28 of its 32-wide grid; the nose is at column 28 and the hull's
vertical middle is row 12.

**Location extent.** From the sprite's pixels on rows 10–13, take the smallest and largest `x`
(`minX`, `maxX`). The left edge at `minX` is the docking port. If those rows are empty (sparse
asteroid or debris fields), fall back to `minX = 6`, `maxX = 17`. This is what makes the clamp attach
correctly to a planet, a station arm, an asteroid or a jump-gate ring.

**Docked.** Gap between nose and port: `3K`. Group width `W = 20K + 3K + (maxX + 1 − minX) × S`.
Centre the group horizontally: ship svg left at `50% − W/2 − 8K`, port at `50% − W/2 + 23K`, location
svg left at `port − minX × S`. Everything is vertically centred in the hero. Clamp: two bars `K` thick,
`3K` wide, `5K` apart outer edge to outer edge, colour `#8997be`. Port light: `2K` square, `#a6f3d0`
with a soft glow, blinking with a 2.4 s stepped animation.

**In orbit.** Location centred in the hero. Ring: ellipse with `rx = (maxX + 1 − minX) × S / 2 + 4.6S`,
`ry = 4.4S`, rotated −14°. Draw the upper half in `#526881` **behind** the location and the lower
half in `#a6f3d0` **in front** of it. Stroke `max(1.5, K/2)`, dashes `1.5K` on, `3K` off, drifting one
dash period every 3 s. Ship centre on the ring at parameter 150° (lower left), drawn in front.

**Transit and checking arrival.** Destination stays at `left: 78.125%`. Route runs from
(12.5%, 75%) to the arrival point: level with the destination's centre and `d + 32` px left of it,
where `d` is the port's distance left of the sprite centre. Ship centre at
`progress` along that line (`progress` is already computed in `shipPresentation.js`, clamped 0–1).
Solid `#a6f3d0` trail from the start to the ship, dashed `#526881` for the whole route underneath,
and a 6 px square origin marker. If `progress` is `null` (route times unknown), keep the ship at the
start with the flame on and no trail. For checking arrival use `progress = 1`.

**Navigation unknown.** Ship at `left: 8%`, `bottom: 12%` as today, `K = 2`, opacity 0.45, bobbing.

## Implementation steps

1. **`resources/js/shipPresentation.js`**: add a `pose` field to what `shipPresentation()` returns:
   `'docked' | 'orbit' | 'transit' | 'arriving' | 'unknown'`. `'arriving'` is transit with
   `arrival === 'Checking'`. Keep `transit` and `progress` as they are; other code reads them.
2. **`resources/js/Pages/Agent/Dashboard.vue`** (line ~324): pass `:pose="presentation.pose"` to
   `ShipScene`. Nothing else on the page changes.
3. **`resources/js/Components/ShipScene.vue`**: rebuild the template around `pose`.
   - Compute `minX`/`maxX` from `waypointSprite(location, reset).pixels` inside the component.
     `ShipScene` is used only by the dashboard, so this keeps shared files
     (`waypointSprites.js`, `WaypointSprite.vue`, which the system map also uses) untouched.
   - Keep the class names existing checks rely on: `.ship-scene`, `.scene-location` (with
     `data-location-symbol`), `.scene-location-label`, `.scene-location-context`, `.scene-ship`.
   - `.scene-location svg` must still match **only** the location sprite. Put the ring, route and
     clamp graphics outside `.scene-location`, or the art checks that read its pixels will break.
   - Keep the ship as a `<button>` with the same `aria-label` and `report` event. Its box must stay
     at least 44 px tall at every size, even where the drawn ship is smaller.
   - All scene art stays `aria-hidden`. The status text below the scene remains the accessible
     source of truth.
   - Sizes switch on the same height breakpoints the component already has (719 and 600).
   - `.still` (Pause motion) pauses every animation; the reduced-motion media query removes them.
4. **No changes** to the status text block, the report sheet, the system map or any PHP.

## Checks to update

- `scripts/verify-flight-deck-art-browser.mjs`
  - Line ~48 asserts `ship.right < art.left` ("ship report control must not overlap destination
    art"). The new docked, orbit and checking-arrival poses overlap that box on purpose. Replace it
    with: the ship button stays inside the hero, stays at least 44 px tall, and never covers the
    location label.
  - The same check asserts the location art and label fit inside the hero; keep it, and run it for
    the corner label too.
  - Add one assertion per pose that the scene reports it (for example a `data-pose` attribute on
    `.ship-scene`) for DOCKED, IN_ORBIT, IN_TRANSIT mid-route, IN_TRANSIT expired, and a ship with
    no `nav.status`. The script's `scene(symbol, type, status)` helper already sets these up.
  - Add: in transit, the ship's centre moves right as `progress` rises.
- `scripts/verify-native-ship-browser.mjs` lines ~157–162 check that `.scene-ship svg` pauses and
  that reduced motion removes its animation. These run on a moving ship and should still pass;
  extend them to the ring and port light.
- `docs/interactive-system-map/flight-deck/source-sha256.json` records hashes of `ShipScene.vue`,
  `Dashboard.vue` and `shipPresentation.js` as dated evidence for an earlier change. Leave it alone.
  Save this change's evidence under `docs/ship-status-graphics/`.

## Verification

1. `pnpm build`, then run both browser scripts above against the development site
   (http://spacetraders-dev.test).
2. Screenshot all five poses at 402×874 and 402×700 (iPhone 17 Pro, tall and short hero), 820×1180
   (iPad) and 1280×800. Check each against the mockups.
3. Repeat docked at a planet, an orbital station, an asteroid and a jump gate to confirm the clamp
   meets the shape's left edge.
4. Check Pause motion and reduced motion in docked, orbit and transit.
5. Have an agent that did not write the change review it (`ui-consistency:verifying`), and show
   Taylor the screenshots before deploying.
6. Full PHP suite must still pass; no PHP is expected to change.

## Out of scope

Taller hero, a ship that physically circles the location, rotating the ship along the route,
different ship art per frame type, and any change to the system map.

## Open questions for Taylor

1. **Smallest hero (72 px, viewport height ≤ 600).** Not mocked. Proposed: `S = 3`, ship drawn at
   `K = 1.5` inside a 44 px button, label on one line in the corner. Show a mockup before building.
2. **Which hero height the iPhone 17 Pro actually gets.** It depends on how much height Safari's
   bars leave; below 720 pt the page uses the 128 px hero. Measure on the device. If it lands on
   128 px, decide whether to move that breakpoint so the phone gets the tall scene.
