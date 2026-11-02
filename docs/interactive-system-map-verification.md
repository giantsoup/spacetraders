# System map verification

Delivered for [issue #90](https://github.com/giantsoup/spacetraders/issues/90) in [PR #91](https://github.com/giantsoup/spacetraders/pull/91).

## Verified boundary

The authenticated map reads saved observations for one owned agent and its reset. Map browsing does not instantiate the upstream client, refresh observations, poll gameplay, or execute commands. Backend tests deny stray HTTP and compare gameplay tables before and after requests. The browser fixture audit also compares table hashes and fake transport calls before and after the browser journeys.

Herd's dedicated `spacetraders-map-90.test` site points to this feature worktree, not the root checkout. The fixture uses its own SQLite database, file sessions, synthetic credentials, and fake upstream transport. Both gameplay automation and the local engine are disabled. No live database, upstream game, deployment, or merge was involved.

## Results

| Check | Result |
| --- | --- |
| `PHP_BIN="$PWD/scripts/herd-php" PNPM_BIN=<pnpm-12.16.1> bash scripts/verify.sh` | Passed, including frontend builds, repository checks, PHP suite, Pint, and diff whitespace checks |
| Full PHP suite | 513 tests, 2,994 assertions passed |
| `SystemMapTest` | 6 tests, 74 assertions passed. Authentication, ownership, ship scope, reset isolation, no writes or HTTP, manual system records, null traits, service-only locations, empty fleet, transit, coverage, and separate directory/quote ages |
| `node --test scripts/verify-system-map.mjs` | 5 tests passed. Projection, fit, anchored zoom, overlap grouping, published sprite families, deterministic identity, and terrain refinements |
| T3 preview at 1440 × 900 and 390 × 844 | Real click, drag, keyboard, ship selection, and screenshot inspection passed |
| Browser event probes at both sizes | 9 checks each passed. Wheel zoom, two-pointer pinch, touch drag/cancel, keyboard pan, layout bounds, and touch target sizes |
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

Run `scripts/herd-php scripts/system-map-fixture.php` to seed synthetic map observations. The script refuses databases or transport files outside the owned fixture directory. Use the generated private `browser.json` to log in. Open `/agents/1/map?ship=LOCAL-1` in T3 preview. Evaluate `scripts/system-map-browser-probes.js` there to rerun the event assertions. Use T3 actions for the click, keyboard, and drag journeys above. Run `scripts/herd-php scripts/system-map-fixture.php --audit` before and after browsing to compare hashes and transport counts. Keep fixture environments, credentials, and raw runtime logs out of Git.

## Limitations

This is a current-system view of stored knowledge. Catalogue coverage can be partial, observations can be old, and a complete catalogue describes the time it was observed. The page deliberately has no discovery, refresh, route execution, or galaxy controls.

T3 preview provided desktop Chromium and a phone-sized viewport. Wheel and two-finger touch checks used synthetic browser events; native pointer capture was exercised through T3 drag actions. Physical trackpad gestures, a physical phone, Safari, and screen-reader behavior were not tested. No deployment or live gameplay was tested.
