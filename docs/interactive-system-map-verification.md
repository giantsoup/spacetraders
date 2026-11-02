# System map verification

Delivered for [issue #90](https://github.com/giantsoup/spacetraders/issues/90) in [PR #91](https://github.com/giantsoup/spacetraders/pull/91).

## Matching flight deck artwork

The flight deck scene and destination brief now use the map's WaypointSprite renderer and saved knowledge projection. In transit, the route's destination selects the artwork even after the reported arrival deadline passes. Docked and orbiting ships show their observed waypoint. Route-only details supply a known type without invented traits; completely unknown destinations use neutral artwork.

The [dark flight deck art report](interactive-system-map/flight-deck/qa.html) records 20 passing browser scenarios, including pixel-for-pixel deck/map comparison for all 14 waypoint families, owner/reset-scoped knowledge, fleet switching, destination brief, ship report, pause, reduced motion, phone/landscape layouts and unchanged gameplay/upstream audits. Full verification passed with 520 PHP tests and 3,081 assertions. A private read-only check through the public live preview also confirmed matching deck/map art without POST requests. No personal live data was committed.

The travel/discovery report below records the earlier verified implementation at commit 7721714. Its recorded asset hashes describe that commit; the flight deck report records this visual follow-up.

## Travel and discovery follow-up

The new flows use the real local Vue/Inertia UI, Laravel handlers, durable actions and database queue with the owned FakeSpaceTraders fixture. No real SpaceTraders API calls or live gameplay tests were added in this follow-up. The earlier live observations and orbit/dock tests below are historical evidence only.

The fixture browser suite verifies explicit waypoint, market and shipyard reads; uncharted details remaining unknown; failed reads and explicit retry after budget cooldown; selected ship and destination through orbit, preview, cancel, Stop, approval, queue execution, transit and refreshed arrival; protected-credit input; unsafe-fuel rejection; expiry; explicit manual fuel/time limits; Stop before send; lost-response uncertainty and read-only reconciliation; missing sensors; wrong-location charting; active cooldown; one scan with a returned target; a scan that misses the target; and charting the current uncharted location. Phone screenshots include 390px and 360px widths. Browser regressions also prove that unrelated commands keep their own return flow, Back restores the selected map card, blank reserves retain the default, and safety inputs survive orbit approval.

A fresh full map browsing run also verifies all 26 existing scenarios with no unexpected browser errors. It retains the map's stored-only audit, all 21 locations, sprite interactions, mobile disclosure, card hierarchy, native wheel/touch, keyboard focus, ship selection, tracker, sign-out and authentication return.

The final action suite has 16 scenarios and passed in a complete fresh run, with no unexpected browser errors. The final browsing suite also passed all 26 scenarios, including short cards with at least 130px of scrollable detail space. Earlier diagnostic runs hit a transient Herd empty GET response and local throttling. The paced final run passed without changing the application throttle.

Run `node scripts/verify-system-map-actions-browser.mjs` on the owned Herd fixture host. The script refuses other checkouts and fixture paths, restricts browser traffic to that origin, and prepares only synthetic scenarios through `scripts/system-map-action-fixture.php`. That helper refuses live databases and enabled engines. `--drain` processes only the owned fixture queue to completion; it installs no service. Use `MAP_QA_ONLY` for a named journey and `MAP_QA_OUTPUT` for private evidence. Do not run two fixture-mutating suites together. Run `scripts/herd-php scripts/system-map-fixture.php` afterward to restore the broad map scenarios.

Opening Get information or following Travel here makes no upstream request. Named fetches use the normal manual-read route and reset guard. Discovery adds the existing question, reserve and cooldown limits, so it uses ExplorationPolicy and the existing approval evidence. No new command implementation or engine authority was introduced.

The new [dark travel/discovery report](interactive-system-map/travel/qa.html) records source hashes, screenshots, browser results, review findings and verification limits. Safari, Firefox, physical devices, screen readers, deployment and new live API travel/discovery remain unverified.

## Latest mobile, zoom and real API checks

The [mobile and zoom report](interactive-system-map/mobile/qa.html) records the final UI source hashes, screenshots and browser checks after Claude Sonnet 5.5’s requested design pass. The phone map fills 743px of a 390×844 viewport, with compact controls and an expandable coverage chip. Artwork grows from 40px to 160px; wheel, keyboard, buttons and emulated touch stop at the close-up limit. Parent QA reproduced and fixed Escape closing the card beneath the coverage panel. The final card pass leads with stored prices, shipyard offers and market goods. Overlap choices expand on demand, and terrain descriptions and lore sit below gameplay information. All 26 native browser scenarios pass.

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
| Native Chromium at desktop, phone and short viewport sizes | 26 scenarios passed, including card information order, mobile coverage disclosure/map area, trusted wheel/touch input, all locations, keyboard, cards, tracker, navigation, and screenshot inspection |
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

This is a current-system view of stored knowledge. Catalogue coverage can be partial, observations can be old, and a complete catalogue describes the time it was observed. The initial delivery had no discovery or travel actions. The follow-up above adds explicit information fetches and guarded command handoffs; browsing remains stored-only, and there is no galaxy view.

The follow-up used fresh Playwright Chromium processes and contexts against the owned Herd host after T3 preview twice failed to emit a working wheel action. Wheel, mouse and CDP touch input were native browser input. Line-mode wheel normalization and API tracker status variants were simulated browser data/events. Physical hardware, Safari, Firefox, and screen-reader behavior remain untested. No deployment was tested. The later bounded live gameplay check is described above.
