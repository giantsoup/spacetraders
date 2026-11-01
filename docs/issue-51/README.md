# EXP01 local implementation and verification

Issue [#51](https://github.com/giantsoup/spacetraders/issues/51), associated draft PR [#52](https://github.com/giantsoup/spacetraders/pull/52). Runtime implementation commit `2fe309c07f02e9102c5cea390479b73bf9747059`. Base `5c1990696811554d03a752dae2cfec6259fcc36c`.

Local fixture acceptance passes. The standard frontend vulnerability audit remains blocked by pnpm parser failures. This report does not certify live gameplay, deployment, or independent review. The [dark visual report](review.html) links the retained screenshots.

## Implemented boundary

Manual commands now offer **Hidden-trait exploration**. The operator selects a known, unresolved same-system waypoint and a marketplace or shipyard question. Each question states how presence or absence changes the next scouting decision. A fresh remote waypoint read rejects questions that are already answered.

`scan_waypoints` and `chart` use the existing owner-scoped preview, approval, durable intent, database queue, request budget, host lock, and confirmation path. There is no second executor or new migration. All command arguments stay local; each provider POST has an empty body.

Each intent permits one action, zero travel, and zero spending. A successful preview, approval, and worker preflight each use at most four GETs: world status, balance, owned ship, and target waypoint. The final POST brings the maximum to 13 requests. A failed approval or worker preflight ends that intent. Existing host throttling can stop it earlier. Reconciliation is a separate explicit read operation outside that 13-request allowance.

The ship must have an affordable, recently priced fuel continuation using existing conservative fuel arithmetic and the protected credit reserve. Travel and refueling require separate approvals. Location, fuel, equipment, ownership, state, question, continuation, and reset are checked again before reservation.

One scan can incur one server cooldown. Its duration and range remain unknown before execution. The operator selects a review threshold from 0 to 900 seconds. An actual duration above that threshold preserves the result and pauses further commands for review. This threshold cannot impose a server-side duration cap. Workers never sleep through a scan cooldown, rescan automatically, or continue a route.

Stop rejects previews and pending exploration intents before reservation. It competes with approval and reservation through database transactions. After reservation, Stop records its request while the action may settle. No subsequent action is authorized. Duplicate delivery cannot replay a settled or uncertain intent.

## Verified official contracts

The official [API reference](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/reference/SpaceTraders.json) reports v2.3.0. Waypoint scanning uses `POST /my/ships/{shipSymbol}/scan/waypoints`, requires a Sensor Array, and returns cooldown and waypoint data. Charting uses `POST /my/ships/{shipSymbol}/chart` at the current location and returns chart, waypoint, and agent data. Charting can reward credits and reveal traits publicly. No reward funds the preview reserve.

The [scanned waypoint](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ScannedWaypoint.json), [cooldown](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/Cooldown.json), [chart](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/Chart.json), and [mount](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/models/ShipMount.json) schemas were checked on 2026-11-01. Their hashes are in [official-contracts.json](evidence/official-contracts.json). Endpoint schemas do not specify a fixed range, duration, or orbit prerequisite. Requiring orbit and an expired cooldown for both commands is a conservative client policy, not a claimed upstream rule.

Only waypoint scans are in EXP01. Ship and system scans do not answer these hidden-trait questions.

## Evidence semantics

Listing, scan, and chart observations retain separate kinds, timestamps, reset IDs, and observer ships. The canonical waypoint records whether traits were observed, when, and by which source. An uncharted listing cannot erase revealed traits or refresh their original trait timestamp. Confirmed empty trait sets establish observed absence; omitted scan targets remain unknown.

A scan never changes catalogue coverage. Partial catalogues can support one known candidate without implying complete geography. A complete listing still does not prove hidden-trait coverage. Reset filtering excludes old evidence from planning.

Malformed, inconsistent, or lost POST responses become uncertain. Neither a cooldown nor a later chart proves that particular intent completed. Explicit reconciliation reads fresh state, preserves uncertainty, and requires operator review before takeover. It never issues another scan or chart. Scan cooldown deadlines are stored in UTC and displayed in the detected local timezone.

## Acceptance evidence

All scenarios use synthetic identities, owned SQLite state, and a provider fake that prevents stray HTTP requests.

| Scenario | Expected and verified result | Evidence |
| --- | --- | --- |
| Owner previews and approves a scan | Question, zero travel/spend, reserve, and continuation precede one POST | 32 focused cases; mobile browser approval |
| Partial catalogue or empty scan | Coverage stays partial; an omitted target remains unresolved | Focused coverage cases; partial-result screenshot |
| Missing sensor, crew, power, fuel, state, or reserve | Rejected before POST | Focused capability cases |
| Foreign owner, foreign target, old reset, or missing CSRF token | Denied without gameplay mutation | Focused ownership and real-middleware cases |
| Malformed response, duplicate waypoint, wrong system, or lost response | Uncertain; no replay or automatic confirmation | Focused cases; real-worker lost chart journey |
| Cooldown exceeds review threshold | Result and UTC deadline retained; further commands require review | Focused threshold case |
| Stop before/during preflight or after reservation | Unreserved work cancelled; reserved work can settle without follow-up | Focused race cases; preview and pending Stop browser journeys |
| Reset before dispatch | Intent rejected and agent archived before POST | Focused reset case; real-worker browser journey |
| Duplicate queue delivery | Two competing database workers produce exactly one fake scan POST | Browser assertions and worker storage checks |
| Adjacent scouting and trade | Purposeful visit, return/refuel, and 4/4/2 trade batches still complete | Adjacent browser results |

Baseline: **321 tests / 1,711 assertions**. Final: **353 tests / 1,869 assertions**, including **32 EXP01 cases / 158 assertions**. The complete verification script passes runtime checks, two deterministic builds, frontend checks, environment probes, PHPUnit, Pint, and whitespace checks. Tracked assets match source. Composer validation, platform requirements, and Composer audit pass with no advisories. PHP 8.5.12, Node 26.13.0, and pnpm 12.16.1 were used without changing shared runtimes or dependency pins.

The EXP01 browser journey passes in Chromium 156.0.8078.4 at 1440px and 390px, using America/Los_Angeles and a light OS preference. The app remains dark, has no horizontal page overflow, and records no browser errors. Real handlers, CSRF, SQLite persistence, and separate database worker processes were exercised. SQLite workers reported FULL synchronization and macOS fullfsync. Screenshots were opened and inspected for readable wrapping, controls, outcomes, and uncertainty feedback.

The existing MVP worker suite passes duplicate delivery, crash recovery, uncertain-write non-replay, and offline Stop. The automation worker suite passes its complete, contract-accept, buy, contract-deliver, and contract-fulfill scenarios. The adjacent opportunity browser suite passes.

Initial browser checks found ambiguous select labels; explicit accessible names fixed them. A subsequent test locator matched both a result paragraph and its evidence copy; it now targets the paragraph. A verification run that rebuilt assets during browser startup failed to find the login fields. The final browser run was sequenced after the build and passed. These failed attempts are not presented as passing evidence.

## Reproduction commands

Use the README's owned PHP and pinned pnpm executables. Do not run browsers while the build is replacing assets.

```bash
export PHP_BIN="$PWD/scripts/herd-php"
export PLAYWRIGHT_BROWSERS_PATH="$PWD/storage/app/exp01-evidence/browsers"
# Set PNPM_BIN to the installed pnpm 12.16.1 executable.
./scripts/verify.sh
"$PHP_BIN" vendor/bin/phpunit tests/Feature/ExplorationTest.php --display-all-issues
python3 scripts/verify-exploration-contracts.py --output storage/app/exp01-evidence/official-contracts.json
node scripts/verify-exploration-browser.mjs
node scripts/verify-opportunities-browser.mjs
"$PHP_BIN" scripts/verify-mvp-worker.php
"$PHP_BIN" scripts/verify-automation-worker.php
```

The browser script creates a private fake world, random test key, file-backed database, sessions, cache, locks, and loopback port. Its finite workers and server close afterward. `--serve-only` provides the same owned fixture for manual browser review until interrupted. No `.env`, database, credentials, or services are copied from the primary site. No additional server for `spacetraders.test` is started.

## Limits and integration

`pnpm audit --json` fails with `ERR_PNPM_BROKEN_LOCKFILE`, rejecting the existing multi-document lockfile. A separate owned copy containing only the unchanged application graph also fails with `reference.startsWith is not a function`. Both outputs are retained. Frontend vulnerability auditing is **unverified**, not green. The tracked dependency files are unchanged from the base; changing package management or dependencies is outside EXP01.

No independent Sonnet review was run because this task prohibits subagents. The final diff, assets, screenshots, and persisted outcomes received a direct self-review. Live sensor range, actual cooldown durations, and gameplay rewards remain untested by design.

The original PR handoff was read through GitHub. Its remote head remains `ad2dae8602505843b91ce82c2c3379190e01965d`; no unrelated plan branches were merged. Main advanced during execution to `87760a42698384871fce4238bb95b226cdafd121`, adding only an unrelated OPS02 brief and merge commit. This implementation retains the requested base. Apply the local implementation and evidence commits to PR #52's existing branch after reconciling its base with current main, then rerun verification. Do not publish the original preparation-only PR as completed implementation.

Nothing was pushed, deployed, remotely merged, or changed on the issue or PR. No live gameplay, real credits, real credentials, or external notifications were used. Local fixture resources and this worktree remain available for Taylor's review.
