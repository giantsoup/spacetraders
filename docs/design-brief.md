# SpaceTraders design brief

Approved design direction: November 1, 2026. This is the reference for future UI work, based on Taylor's approval of the two-pass [single-ship redesign report](single-ship-redesigns.html).

## Product direction

A dark, phone-first SpaceTraders client that feels like a small native app. A quick visit answers where the selected ship is going, what it is doing, when it will be ready, and what it carries. The ship adds personality; clear state and useful controls make the experience work.

The first complete experience centers on one ship. Fleet management grows from that experience. Laravel owns gameplay and approved automation, including work that continues while the phone is closed.

These are approved design references, not evidence that the screens are implemented in Vue or that the engine is deployed to Linode. Prototype ships, names, routes, quantities, and timings are fictional.

## Approved screen family

| Reference | Role in the design direction | Keep |
| --- | --- | --- |
| [Horizon](design-reference/horizon.png) | Recommended starting point for the single-ship home screen | A small scene, prominent ETA, one grouped resource list, and a contextual action near the thumb |
| [Logbook](design-reference/logbook.png) | Approved alternative for a narrative view and activity history | Plain-language status, clear chronology, and a report page with Back navigation |
| [Voyage](design-reference/voyage.png) | Approved alternative for an expanded travel view; reference for future fleet rows | A continuous space scene and a compact status/fuel/cargo strip |

Horizon is the recommendation, rather than an exclusive screen choice made by Taylor. Use the shared visual language across all three. Borrow Logbook's plain-language status for stale data, arrival checks, and unresolved actions. Use Voyage's compact strip as a reference when fleet rows become necessary.

The PNG references live in `docs/design-reference/` so a checkout retains them without local test output. The HTML report remains the interactive reference; its `?preview=horizon`, `?preview=logbook`, and `?preview=voyage` links open edge-to-edge previews.

## Screen composition

The single-ship home screen follows this hierarchy:

1. Ship identity, a future ship-switching doorway, and a visible Report entry.
2. A restrained pixel scene that communicates the observed navigation state.
3. Current activity, destination, and arrival or readiness information.
4. Fuel and cargo together in grouped rows. Systems appears here when space allows and remains available in Report.
5. One contextual primary action. Active automation and unresolved commands can change which control deserves that position.

Fuel and cargo remain visible in normal portrait layouts at default text size. Scale down decorative art before reducing text or hiding essentials. Recent activity can have a compact entry that opens history. Manual commands, market tables, and automation setup belong in focused flows instead of a stack of home-screen panels.

Group related information with alignment, spacing, shared surfaces, and separators. A rounded container earns its place when it groups related rows, presents a sheet, or holds a compact accessory. Each metric does not need its own card. Avoid nested panels, repeated headings, large decorative shadows, and a border around every fact.

Keep account, agent, guide, and sign-out access reachable through secondary navigation. Add bottom navigation only when there are distinct, useful top-level destinations. The current single-ship design does not need mostly empty Bridge, Cargo, and Activity tabs.

## Visual language

Dark mode is the default across application screens, dialogs, error states, and personal design/review artifacts, regardless of the OS theme. Use smooth system typography for the interface and crisp pixel art for ships and the world.

The approved prototype supplies these target colors. Map them into shared application tokens during implementation; avoid separate palettes inside individual pages.

| Purpose | Reference value |
| --- | --- |
| Page background / app canvas | `#070d17` / `#060b14` |
| Sheet / grouped inset surface | `#0d1524` / `#111b2c` |
| Secondary controls | `#162337` |
| Main text / secondary text | `#e8eef6` / `#97a8bc` |
| Low-emphasis captions | `#6f8197` |
| Primary action / text on primary | `#a6f3d0` / `#0c271d` |
| Cargo accent | `#b8b1ff` |
| Attention and focus | `#f7dc91` |
| Destructive action or confirmed failure | `#ffb4bf` |
| Row separator | `rgba(178,200,230,.11)` |

Mint is the main action color. Lavender distinguishes cargo; amber marks attention; pink marks destructive actions or failures. Text and icons carry the meaning alongside color. Verify contrast on the actual surface, especially for low-emphasis captions.

Use a 4px spacing rhythm, usually 16–20px horizontal content insets. Body and controls are typically 16–17px; secondary text is 14–15px. Reserve 12px for short, noncritical captions. Titles are usually 28–34px. An ETA can use 48–72px tabular numerals when the available height supports it.

Grouped lists use approximately 14px corners, primary buttons 16px, and sheets 26px top corners. The rounded phone frame belongs to the comparison report. The app itself fills the available viewport without a simulated device bezel.

## Phone layouts and accessibility

Adapt to the screen's usable height after browser chrome and safe areas, rather than assuming one phone size. Horizon's measured screen-height modes provide a starting point:

| Available app height | Scene | ETA | Resource rows |
| --- | --- | --- | --- |
| At least 720px | About 250px | About 64–72px | Fuel, Cargo, Systems |
| 560–719px | About 164px | About 60px | Fuel, Cargo, Systems |
| Below 560px | About 96px | About 48px | Fuel and Cargo; Systems moves into Report |

The [compact Horizon reference](design-reference/horizon-compact.png) shows a 375 × 548 browser viewport. These dimensions are reference settings, not a substitute for testing content. Large text, landscape, and long names can require scrolling. Keep the contextual action reachable without covering the scrollable content. Do not conceal overflow to make a layout appear to fit.

Controls have at least 44 × 44 CSS px hit areas, with visible keyboard focus and meaningful labels. Preserve browser zoom. Target at least 4.5:1 contrast for normal text and 3:1 for large text; check control boundaries and focus indicators too. Never require a swipe, hover, or animation to discover an action.

Sheets have an explicit Done or Close control, Escape support, focus containment, and focus restoration. Back returns to a parent screen or step. Long details scroll inside the focused view without hiding dismissal controls. Announce meaningful state changes once; do not announce every countdown tick.

The report's demo-state picker, restart control, and preview-return toolbar are design tools. They are not production application navigation.

## Ship report and data presentation

Tapping the ship and tapping Report open the same ship report. Fuel and cargo rows open the relevant section directly. Horizon uses a focused sheet, Logbook a pushed report page, and Voyage an expanded sheet. Preserve those navigation semantics when using each pattern.

Keep cargo, fuel, and readiness easy to find. Deeper details include crew, frame, reactor, engine, modules, mounts, and observation time. Use readable names first, with API symbols and technical detail available on request.

- Show cargo used/capacity, inventory counts, and free space. An empty hold has an explicit empty state.
- Show fuel in tank units. Cargo `FUEL` uses different units; route range and refueling costs need server-side evidence.
- Show crew current, required, and capacity separately. Keep component condition and integrity separate; avoid an invented averaged ship-health score.
- Label missing observations Unknown. Distinguish a real zero from missing or inapplicable data, and handle zero-capacity meters without division.
- A friendly nickname is app metadata. Preserve the authoritative ship symbol and role.
- Market prices, cargo valuation, and route costs require their own dated observations. Quotes are estimates, not guaranteed execution prices or profit.
- A disclosure labelled Selected API fields can be partial. A label promising all fields must match its contents. Keep actual values separate from explanations.

## State and action rules

Navigation state, action cooldown, command execution, automation, and data freshness are separate facts. A single green Ready label must not conceal a blocking condition.

| Situation | Main-screen behavior | Action behavior |
| --- | --- | --- |
| In transit | Destination, remaining time, and local arrival time | Inspect ship or destination; respect server transit restrictions |
| Arrival deadline reached | Checking arrival until a fresh observation confirms it | No client-timer unlock |
| In orbit | Observed waypoint and readiness | Preview docking, navigation, or a supported equipped action |
| Docked | Observed location and relevant resources | Preview trading, refueling, or departure prerequisites |
| Action cooldown | Separate readiness timer alongside navigation | Recheck at expiry; equipment and waypoint prerequisites still apply |
| Stale or disconnected | Last known values, their observation age, and an unconfirmed state | Refresh or reconcile before another write |
| Command queued or sent | Exact execution status | Keep duplicate submissions unavailable while that intent settles |
| Outcome unknown | Persistent Needs review status | Reconcile before retrying; never silently replay an uncertain write |
| Automation active | Current step, relevant limit/blocker, and reachable Stop control | Manual takeover waits for the run and its pending action to settle |
| No ship, archived/reset agent, or missing connection | Explain the missing prerequisite and next useful step | Disable gameplay; provide the relevant setup or account path |

Preserve the existing preview, approval, execution, and reconciliation path. Movement, purchases, trades, and starting automation retain server checks and explicit authorization. UI simplification does not remove spending limits, reserves, ownership checks, supported-command restrictions, or clear start/stop control.

## Time, motion, and truthfulness

Store and exchange UTC instants. Display times in the browser's local timezone and identify that timezone. Reuse `resources/js/dateTime.js`; keep raw ISO values in data and `<time datetime>`.

Calculate remaining travel from the absolute arrival instant. Recalculate on resume or reconnect. A paused display never changes departure or arrival deadlines, pauses gameplay, or stops server automation. At zero, display Checking until refreshed state establishes the result. Cooldown uses its own deadline and confirmation.

Ship motion is a stylized illustration. Route progress can derive from departure and arrival times when both are valid; missing timestamps produce an ETA or Unknown without fabricated progress. During transit, the navigation waypoint field identifies the destination rather than continuous coordinates.

Use a small consistent pixel grid, recognizable ship silhouettes, quiet starfields, gentle bobbing, and restrained exhaust. Keep interface text selectable and smooth. Preserve crisp edges in SVG; scale raster exports by integer multiples. Do not imply continuous fuel consumption or successful mining through decorative animation.

Honor reduced-motion preferences and offer a still/pause presentation control. Stop decorative motion in hidden views. Keep status and timing understandable with animation off. Animation frames and countdown ticks do not trigger SpaceTraders requests or authorize commands.

## Implementation and maintenance

Use the existing Laravel, Vue, Inertia, and Tailwind structure. Share the application shell, semantic color/type/control tokens, date formatting, selected-ship identity, and server command services. Keep API credentials and gameplay decisions on Laravel. Local polling, API observation age, and upstream completion remain distinct.

[App styling](app-styling.md) describes the current implementation, including its earlier terminal palette and panels. This brief defines the approved direction for new UI work. The [earlier vision board](single-ship-vision.html) and [research notes](single-ship-mobile-research.md) remain historical context where their layout recommendations differ. The approved redesign does not authorize a runtime rewrite, hosting change, or new gameplay capability.

A UI change is ready for review when the affected workflow satisfies these checks:

- Default, stale, pending, uncertain, cooldown, empty, and archived states have clear text and a useful next step.
- Portrait tests include 320 × 667, 375 × 548, and 390 × 844, plus landscape, a wider screen, long names, and enlarged text.
- At default text size, normal portrait Horizon states expose ship identity, status/ETA, Fuel, Cargo, and the contextual action. Large text and landscape preserve access through scrolling.
- Touch targets, focus, sheet dismissal/return, reduced motion, and dark mode under a light OS preference work.
- Pause/resume and background/foreground preserve absolute deadlines; zero does not unlock an action.
- Preview, approval, duplicate prevention, automation Stop, and uncertain-outcome recovery retain the server's rules.
- Screenshots reflect the actual changed artifact. Local tests and browser checks are distinguished from a physical-phone test, deployment, or live-game result.

`node scripts/verify-single-ship-redesigns.mjs` checks the HTML design reference with an existing Playwright installation. `PLAYWRIGHT_MODULE` and `PLAYWRIGHT_EXECUTABLE` can select the local module and browser. Use the fixture-based checks in [app-styling.md](app-styling.md) for implemented application workflows; prototype checks alone do not verify them.

For an intentional design change, update this brief and the relevant reference/prototype together. Preserve a small coherent set of shared patterns instead of adding a page-specific style for each feature.

The [implementation prompt](native-ship-ui-implementation-prompt.md) turns this brief into a scoped application task with workflow-preservation and verification requirements.
