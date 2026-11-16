# Ship information QA

## Result

Campaign ships now expose their saved equipment and configuration through phone-sized disclosures. The overview distinguishes no tank or hold from an empty resource and an unknown observation. Mounts and modules retain individual instances and their own requirements, deposits, strength, capacity and range. Core components, crew, navigation, inventory, fuel and cooldown remain reachable, with exact safe JSON for precision and identifiers.

Campaign uses each ship's observation timestamp. Campaign assignment and registration role have separate labels. Dashboard, fleet list, ship reports and map-flight summaries use the same zero-capacity wording. Command gates, polling cadence and gameplay actions are unchanged. Nothing is deployed.

## Coverage

The canonical synthetic matrix is `tests/Fixtures/ship-information.json`. It contains 24 scenarios: all 13 catalog type labels plus 11 edge cases, covering the pinned schema's 15 mount symbols and 20 module symbols. Loadouts are illustrative test inputs, not shipyard specifications. Owned ships are displayed from observations; their catalog type is not inferred.

Cases include probes, empty and unknown resources, partial values, full cargo, missing and empty equipment, long strings, future symbols, different same-symbol equipment instances, partial components, transit/cooldown and excluded nested fields. A consistency check binds cooldown ship symbols to their scenario's ship.

Campaign's recursive allowlist covers every pinned Ship schema leaf and the recognized local cooldown.readyAt extension. Unrecognized or invalid fields are reported by path only, including nested fields; their values are excluded. Tests assert exact safe projection equality, array order, numeric precision, per-ship observation times, nested sentinel exclusion and operator-only access.

## Verification

- Focused PHP campaign suite: 16 tests, 550 assertions passed after the final fixture correction.
- Broader PHP regression: CampaignStatus, CampaignAutopilot, FoundationCommand, Exploration and SystemMap; 254 tests, 1,812 assertions passed. Backend worker additionally passed CampaignWatchdog in its combined run.
- Ship-facts verifier: 49 resource combinations, missing/empty equipment and all canonical symbol coverage passed.
- Vue SSR: 24 campaign/exact-data renders and 72 report renders passed, including raw quality, exact precision, separate equipment requirements and ship-scoped current-waypoint labels. Default preview labels remain unchanged.
- Existing ship-presentation and campaign-resource verifiers passed.
- Production asset build passed; generated build artifacts are restored after browser checks.
- Actual local Laravel campaign matrix: all 24 scenarios at 320, 390 and 820 pixels passed overflow, 44-pixel disclosure targets, keyboard toggling and open-state persistence across a real Inertia poll. No external requests or console errors.
- Existing presentation browser and native-dashboard browser suites passed. Native verification includes report navigation, missing fields, zero-capacity meter absence, small/large text layouts, pending-action safeguards and finite synthetic-worker execution. Its old crew-label and empty-meter assertions were updated to the intentional UI contract.
- Diff whitespace validation passed. MapFlightTask changes are limited to a resource-summary import and display line; its command predicates are untouched.

## Phone evidence

Local artifacts are under `storage/app/mvp-evidence/ship-information/`:

- `campaign/results.json`: viewport, matrix and polling results.
- `campaign/probe-overview-390.png`: compact probe card.
- `campaign/equipment-320.png`, `equipment-390.png` and `equipment-820.png`: expanded equipment at each viewport.
- `campaign/expanded-details-390.png`: exact-value disclosure.
- `native-current/results.json`: dashboard regression results and associated screenshots.

PHP logs: `storage/logs/ship-information-php-tests.log` and `storage/logs/ship-information-final-campaign-tests.log`. Browser logs: `storage/logs/ship-information-campaign-browser.log` and `storage/logs/ship-information-native-browser.log`.

## Payload and limits

The fixed 13-scenario complete ship array serializes to 35,870 bytes; its details array is 28,321 bytes. Individual complete ship records range from 2,026 to 3,080 bytes. The 200 KiB test is a fixed-fixture regression ceiling, not a production fleet limit. Repeating this fixture's average for 50 ships estimates about 138 KB of ship records alone per poll, excluding other props, transport overhead and compression. That is arithmetic, not measured live traffic.

These tests run on isolated local synthetic databases and block upstream requests. No ships were bought, no live game state was changed and production was untouched. Future upstream fields are discoverable as excluded paths, but their values require an explicitly reviewed allowlist addition. There is no claim that synthetic configurations match future-wipe shipyard stock.

## Reproduce

Use Herd PHP 8.5, installed Node dependencies and the project's Playwright Chromium. Build assets before browser checks and keep them stable until those checks finish.

```powershell
$env:PHP_BIN='C:/Users/taylo/.config/herd/bin/php85/php.exe'
node scripts/verify-ship-facts.mjs
node scripts/verify-ship-information.mjs
node scripts/verify-ship-presentation.mjs
node scripts/verify-campaign-resources.mjs
& $env:PHP_BIN artisan test --filter='CampaignStatus|CampaignAutopilot|FoundationCommand|Exploration|SystemMap'
pnpm build
$env:CAMPAIGN_SHIP_MATRIX='1'
$env:CAMPAIGN_STATUS_EVIDENCE=Join-Path (Get-Location) 'storage/app/mvp-evidence/ship-information/campaign'
node tests/Browser/campaign-status.mjs
node scripts/verify-presentation-browser.mjs
$env:QA_ARTIFACT_DIR=Join-Path (Get-Location) 'storage/app/mvp-evidence/ship-information/native-current'
node scripts/verify-native-ship-browser.mjs
git diff --check
```
