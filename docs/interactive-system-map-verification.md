# System map verification

Delivered for [issue #90](https://github.com/giantsoup/spacetraders/issues/90) in [PR #91](https://github.com/giantsoup/spacetraders/pull/91).

## Latest mobile, zoom and real API checks

The [mobile and zoom report](interactive-system-map/mobile/qa.html) records the final UI source hashes, screenshots and browser checks after Claude Sonnet 5.5’s requested design pass. The phone map fills 743px of a 390×844 viewport, with compact controls and an expandable coverage chip. Artwork grows from 40px to 160px; wheel, keyboard, buttons and emulated touch stop at the close-up limit. Parent QA reproduced and fixed Escape closing the card beneath the coverage panel.

Taylor subsequently authorized real API reads, small commands and spending. Fourteen GET requests returned HTTP 200 and stored a complete 94-location catalogue plus current-location/market/shipyard observations. Two manual commands, orbit and dock, confirmed through the application preview/approval/queue path. The ship returned to its original dock with unchanged fuel, cargo and credits. No spending was needed.

The separate live preview uses this feature’s code and built assets with the existing live database, shared locks, request budget and engine. A private verified SQLite backup was taken first. No second engine or automation run was created. Local map navigation, cards, zoom, knowledge disclosure and tracker refresh left gameplay table hashes and upstream request/write counters unchanged. Authentication and private evidence stay outside Git.

Physical devices, Safari, Firefox and screen readers remain untested. No deployment or PR merge was performed.

## Prior integrated browser QA

Latest main `f84bd824fe5bc117339d5ece249205ab6c7d54ba` is included, with the API rate tracker in the map header. A fresh isolated Chromium run covers 24 browser scenarios, all 21 stored fixture locations, all 19 rendered markers, native wheel and touch input, keyboard/focus, navigation, sign out and intended-login return, and the tracker. The tracker status/failure variants use browser-mocked responses; the normal tracker and map use real local handlers.

QA fixed directory focus restoration, short-viewport card scrolling and header clipping, card behavior during pan/zoom, nearby-button focus, location scroll reset, directory stacking, browser shortcuts, Escape priority, and unplotted card placement. Marker focus is drawn inside its SVG target, wheel units are normalized, and the tracker's countdown is outside its live announcement region.

See the [prior integrated dark QA report](interactive-system-map/qa/qa.html) and [review reconciliation](interactive-system-map/qa/review-reconciliation.md) for final revision, checks, screenshots and limits. The original T3 screenshots below record the initial implementation; the follow-up report contains the current header and responsive evidence.

## Verified boundary

The authenticated map reads saved observations for one owned agent and its reset. Map browsing does not instantiate the upstream client, refresh observations, poll gameplay, or execute commands. Backend tests deny stray HTTP and compare gameplay tables before and after requests. The browser fixture audit also compares table hashes and fake transport calls before and after the browser journeys.

Herd's dedicated `spacetraders-map-90.test` site points to this feature worktree, not the root checkout. The fixture uses its own SQLite database, file sessions, synthetic credentials, and fake upstream transport. Both gameplay automation and the local engine are disabled. That fixture pass did not touch the live database or upstream game. The later authorized live check is described above.

## Results

| Check | Result |
| --- | --- |
| `PHP_BIN="$PWD/scripts/herd-php" PNPM_BIN=<pnpm-12.16.1> bash scripts/verify.sh` | Passed, including frontend builds, repository checks, PHP suite, Pint, and diff whitespace checks |
| Full PHP suite | 518 tests, 3,041 assertions passed |
| `SystemMapTest` | 6 tests, 74 assertions passed. Authentication, ownership, ship scope, reset isolation, no writes or HTTP, manual system records, null traits, service-only locations, empty fleet, transit, coverage, and separate directory/quote ages |
| `node --test scripts/verify-system-map.mjs` | 7 tests passed. Growth/cap, projection, fit, anchored zoom, overlap grouping, published sprite families, deterministic identity, and terrain refinements |
| Native Chromium at desktop, phone and short viewport sizes | 25 scenarios passed, including mobile coverage disclosure/map area, trusted wheel/touch input, all locations, keyboard, cards, tracker, navigation, and screenshot inspection |
| Initial T3 event probes | 9 checks at each size passed; the follow-up native browser suite supersedes the original simulated gesture evidence |
| Browser gameplay audit | Gameplay table hashes and fake transport file unchanged; zero additional upstream calls or gameplay writes |

The map-specific checks are part of `scripts/verify.sh`. The built assets are tracked using the repository's existing convention.

## Browser journeys

- Desktop and phone: native drag pans without selecting text. Zoom controls, fit, and ship centering work. Wheel and two-pointer pinch handlers pass browser event probes.
- Two locations at identical coordinates each open from the grouped marker. Nearby groups split as zoom increases. Small dots and leader lines retain actual positions while artwork avoids overlapping hit targets. Labels hide when they would collide.
- The desktop information card remains inside the map. The phone card stays at the bottom with a scrollable details area. Close, outside click, and Escape dismiss it. Escape restores marker focus. Tab and Enter open a location. Tab and Page Down scroll its details.
- The ocean location shows its description, faction, goods directory, historical zero-credit purchase price, invalidated price warning, and shipyard offers. Trait, directory, quote, shipyard, and catalogue timestamps remain separate and display in local time.
- A second location has confirmed empty traits. A stored moon directory and shipyard have confirmed empty lists. Missing traits, prices, services, and coordinates remain Unknown. An incomplete manual system entry appears in the unplotted list.
- All 14 published waypoint families render. Frozen, ocean, volcanic, jungle, and city observations change appearance. Identity-derived texture and the identity signature remain deterministic as known traits grow. Neutral art carries no terrain claims.
- The in-transit ship centers on its observed departure. Its old arrival deadline does not confirm arrival. The map preserves the selected ship when returning to the flight deck and when following a generic map link.
- A ship with no plotted observations shows the empty-system view. An owned agent without ships shows the empty-fleet view.
- The map and card remain dark when browser appearance is set to light.

## Evidence

[Desktop overview](interactive-system-map/evidence/desktop.png), [desktop location and prices](interactive-system-map/evidence/desktop-card.png), [phone overview](interactive-system-map/evidence/phone.png), [phone information card](interactive-system-map/evidence/phone-card.png), [browser probes and audit](interactive-system-map/evidence/results.json), and [verification output](interactive-system-map/evidence/checks.txt).

## Reproduction

Use a checkout with no existing `.env`. Install the locked dependencies and build assets. Create the owned fixture with `TMPDIR="$PWD/storage/app/system-map-90" scripts/herd-php scripts/mvp-fixture.php --isolated` after creating that private directory. Copy only that generated environment into the otherwise absent checkout `.env`. Set a unique `APP_URL` and `SESSION_COOKIE`, and disable `SPACETRADERS_AUTOMATION_ENABLED` and `SPACETRADERS_LOCAL_ENGINE_ENABLED`. Link that checkout to a unique Herd host with PHP 8.5.

Run `scripts/herd-php scripts/system-map-fixture.php` to seed synthetic map observations. The script refuses databases or transport files outside the owned fixture directory. Use the generated private `browser.json` to log in. Open `/agents/1/map?ship=LOCAL-1` in T3 preview. Evaluate `scripts/system-map-browser-probes.js` there to rerun the event assertions. Run `node scripts/verify-system-map-browser.mjs` to repeat the native browser QA on this owned Herd host. Use `MAP_QA_OUTPUT` for a separate evidence folder and `MAP_QA_ONLY` to rerun a named regression. Run `scripts/herd-php scripts/system-map-fixture.php --audit` before and after browsing to compare hashes and transport counts. Keep fixture environments, credentials, and raw runtime logs out of Git.

## Limitations

This is a current-system view of stored knowledge. Catalogue coverage can be partial, observations can be old, and a complete catalogue describes the time it was observed. The page deliberately has no discovery, refresh, route execution, or galaxy controls.

The follow-up used fresh Playwright Chromium processes and contexts against the owned Herd host after T3 preview twice failed to emit a working wheel action. Wheel, mouse and CDP touch input were native browser input. Line-mode wheel normalization and API tracker status variants were simulated browser data/events. Physical hardware, Safari, Firefox, and screen-reader behavior remain untested. No deployment was tested. The later bounded live gameplay check is described above.
