# Native ship UI implementation

Baseline: `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb`, clean branch `feature/native-single-ship-ui`. Local implementation only. No live gameplay, migrations, publication or deployment.

## Capability destinations

| Capability | Destination |
| --- | --- |
| Selected ship, navigation, absolute ETA, fuel and cargo | Continuous Horizon home |
| Ship details, crew, components and equipment | Report sheet; ship and Report share it |
| Tank and observed inventory | Fuel and Cargo links open the matching Report section |
| Manual previews, approvals, surveys and transfers | Commands sheet; approvals open Review |
| Restricted one-time miner purchase | Setup in Commands |
| Mining, scouting and trading limits | Automation sheet |
| Mining and Contracts investigation, drafts and approval | Existing local automation console in Automation |
| Active run, limits, blocker and Stop | Run sheet; Stop also on home |
| Markets, waypoints, opportunities and calibration | Intelligence sheet |
| Commands, unresolved outcomes and reconciliation | Activity sheet |
| Guided flight and lessons | Guide sheet, with step destinations opened automatically |
| Owned-ship selection | Fleet sheet; existing user/agent/reset storage key |
| Agent, guide, profile and sign-out | Shared Menu |

Implemented and verified locally. Source and fixture evidence are described below.

## Implementation choices

`Dashboard.vue` keeps the existing form state and handlers, and selects one focused destination at a time. `FocusSheet.vue` owns dialog dismissal, unique names, keyboard containment and focus return. `ShipReport.vue`, `ShipScene.vue` and `shipPresentation.js` separate observed detail, decoration and timing. There is no second gameplay engine or alternative application theme.

The shared shell adds a compact Menu for the ship home. Shared CSS adopts the approved colors, system text and rounded controls. Automation sections use separators, while candidates and approval groups retain meaningful boundaries. The viewport supports safe-area insets and browser zoom.

The two backend changes are read-only presentation support. Owner-scoped `/agents/{agent}/history?before=…` returns at most 40 earlier commands, with a continuation flag. The client retains history across polling and can reopen older previews. `OpportunityAssessor` reports incomplete planning observations instead of crashing or treating missing inventory/fuel/mode as known values. Existing execution, preview/start approval, engine sessions, ownership, reserves, request budgets, reset handling and reconciliation services remain the authority.

Guidance maps each target to its actual destination, reveals hidden targets before focusing, accounts for the sheet header, and preserves manual scrolling across countdowns and polling. The active-run sheet shows its current phase or contract action, approved limits, outcomes, blocker and Stop. The automation monitor links directly to this sheet. Stop requested remains visible while reserved work settles.

Intentional departures from the illustrative reference are the real authoritative ship symbol, a small shared Menu header, full existing forms inside scrolling sheets, and secondary destination links below the main action. Systems moves into Report on short screens. Scene heights are 230/128/72 CSS pixels so the shared header fits without shrinking essential text. No nickname field or unsupported gameplay capability was added.

Material changed paths include `resources/js/Pages/Agent/Dashboard.vue`, `Layouts/AppShell.vue`, `Components/{FocusSheet,ShipReport,ShipScene,GuidedFlight,AutomationConsole,OpportunityPanel,OperationControls}.vue`, `shipPresentation.js`, `dateTime.js`, `resources/css/command.css`, the viewport metadata, dashboard/history controller and route, observation assessor, targeted backend tests, browser navigation/check scripts, and synchronized `public/build`. Dependency constraints, lockfiles and migrations are unchanged.

## Verification

Runtime: Herd PHP 8.4.27, Node 26.13.0, pnpm 12.16.1. Automated application journeys use installed Google Chrome 156.0.8078.76 through Playwright. External browser requests are blocked and SpaceTraders responses are synthetic. The new browser scripts create private SQLite fixtures and finite loopback servers; the engine suite removes its temporary fixture service.

All browser commands below use:

```sh
export PLAYWRIGHT_MODULE=/Users/Taylor/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs
export PLAYWRIGHT_EXECUTABLE='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
```

| Check | Result and evidence |
| --- | --- |
| `PNPM_BIN=/tmp/spacetraders-pnpm-12 bash scripts/verify.sh` | Production build and source-scan determinism, frontend logic, isolated environment controls, 296 backend tests / 1,562 assertions, Pint and whitespace. `storage/app/mvp-evidence/native-ui/verify-final.log`. The temporary pnpm wrapper invokes installed pnpm 12.16.1 through npm exec. |
| `node scripts/verify-native-ship-browser.mjs` | Home/report/deep links, destination brief, Back/Forward, Menu, focus containment/return, manual preview/approval and real worker confirmation, pending/sending/uncertain/review recovery, 95-command pagination across polling, older-preview recovery, real Stop request, unknown/zero/empty/archive states. Twelve measured normal portrait layouts; landscape, wide, long names and 24px text. `native-ui/browser/results.json`, screenshots and `browser-final.log`. |
| `node scripts/verify-ship-https-browser.mjs` | Actual TLS proxy to the fixture, trusted forwarded HTTPS, asset loads, login, ship/report, CSRF-protected preview and Menu-to-guide navigation. No public tunnel. `native-ui/https/results.json` and `https-final.log`. |
| `node scripts/verify-guided-browser.mjs` | Complete mining flight, pause/resume, reload, final focus return and actual narrow-screen target hit testing. `guided-final.log`; detailed steps/screenshots in `storage/app/mvp-evidence/guided/mining`. |
| `QA_GUIDED_SCENARIO=… node scripts/verify-guided-browser.mjs` | `setup`, `uncertain`, `rejected`, `recovery`, `preparation`, and `scroll` passed. Covers separate purchase, no replay, lost responses/saved preview, preparation fuel and freedom to scroll across ticks/polls. `native-ui/guided-<scenario>.log` and `guided/<scenario>/results.json`. |
| `node scripts/verify-automation-browser.mjs` | Mining and Contracts investigation/staging/reload, healthy-session approval, changed-price rejection, explicit contract acceptance, confirmed accounting, Stop, End session and restart. No gameplay from engine start. `native-ui/automation-final.log`; `local-automation/result.json` records service removal. |
| `node scripts/verify-opportunities-browser.mjs` | Purposeful scouting, real worker completion, 4/4/2 trading batches, return/refuel and evidence views at wide/narrow sizes. `native-ui/opportunities-final.log`; `storage/app/opportunity-evidence/results.json`. |
| `node scripts/verify-tutorial-browser.mjs` | Guide entry, lesson links, checklist/storage isolation, selected-ship persistence and real manual mining loop. `native-ui/tutorial-final.log` and `tutorial/`. |
| `node scripts/verify-agents-browser.mjs` | Current/archive same-name agents, reset/import recovery, disabled archived gameplay, account navigation, empty states and logout. `native-ui/agents.log` and `agents/`. |
| `node scripts/verify-pages-browser.mjs` | Shared shell, dark/light OS preferences, responsive pages, auth/profile/reset flows and deletion-dialog recovery. `native-ui/pages-final.log`; `style-refactor/after/`. |
| `node scripts/verify-presentation-browser.mjs` | Equipment, readable purchase/command previews, confirmed review evidence, escaped text, local times and raw UTC instants. `native-ui/presentation.log` and `presentation/`. |
| `node scripts/verify-mvp-browser.mjs` | Actual Herd fixture: explicit sync, manual mining/sales, bounded run, durable Stop, uncertain-write reconciliation and one-time purchase. `native-ui/mvp-herd.log` and `browser-replay/results.json`. |

Evidence paths abbreviated above are under `storage/app/mvp-evidence/` unless stated otherwise. The final home/browser and guided mining checks were rerun after the last presentation changes. Earlier auxiliary scenarios retain the same handlers and guidance transitions; later changes add destination observations, safe-area sizing, the corrected sticky containing layout and readable table widths. All seven guided scenarios and the affected home/intelligence checks were rerun. The complete automation, opportunity and tutorial journeys were rerun with the final application source.

## Corrections found during verification

The compact transit layout initially put its action 3px below the 320 × 667 viewport. Reducing decoration fixed it. Dialog IDs, Tab wrapping across disabled fieldsets, sticky guidance offsets, hidden setup fields and final tutorial focus were corrected through source review and rendered checks. The missing-observation 500 was reproduced and fixed in the presentation boundary. History polling gaps and disappearing older previews now have a 95-command browser regression.

Some initial runner failures came from outdated destinations, screenshots taken before the viewport settled, a late-installed fake clock, and rebuilding assets during an Inertia journey. The runners now use visible navigation and settled rendering; the clock installs before the app; final journeys used stable built assets. Screenshot inspection also caught a wrapper constraining sticky guidance; it now shares the sheet content layout, with assertions that both instructions and the target remain visible. These failures are not counted as passes.

The legacy Herd replay initially inherited the main site's URL from its copied fixture settings. It was stopped before command steps, the owned fixture URL was corrected, and the runner now checks the served offline-provider declaration before login. Its successful rerun used the separate fixture database. No live command or spending stage ran.

## Review and operating limits

Two read-only independent source reviews covered UI/guide integration and the final ownership, history, unknown-data and command boundaries. Their concrete findings were fixed and the affected checks rerun. This is a scoped implementation/boundary review, not a new whole-application security audit.

The retained local preview is [the Herd fixture](http://spacetraders-native-ui.test/agents/1). It uses a disposable synthetic account/world and the temporary `.env` created only in this initially unconfigured worktree. Its fixture includes the replay's recorded commands and setup purchase. No live key or database was copied, migrated or replaced. The main `spacetraders.test` site remains separate.

Local tests and desktop Chrome browser workflows are verified. Hidden-page behavior is exercised through controlled visibility/page-resume events and absolute clock advancement. Physical iPhone, Safari, real mobile browser chrome, OS sleep/wake and assistive-technology use remain unverified. Live SpaceTraders acceptance, profitability, gameplay, deployment and public-tunnel operation are outside this evidence. No push, merge, deployment or live engine activation occurred. No unresolved local implementation blocker remains.

## Application screenshots

These are rendered Vue screens with synthetic fixture observations, not the design prototype. The finite fixture server was tested at `http://127.0.0.1:58704/agents/1`; it is now closed. The retained Herd URL above uses a separate synthetic fixture.

| Screenshot | Fixture state |
| --- | --- |
| [320 × 667 home](native-ship-ui/transit-320x667.png), [375 × 548 compact](native-ship-ui/transit-375x548.png), [390 × 844 home](native-ship-ui/transit-390x844.png) | LOCAL-1 in transit, actual saved tank/cargo, absolute arrival deadline |
| [Destination brief](native-ship-ui/destination-brief.png) | Same observed transit destination, dated waypoint evidence |
| [Systems report](native-ship-ui/report-systems.png), [Cargo report](native-ship-ui/report-cargo.png) | Crew and separate component condition/integrity; empty hold |
| [Manual approval](native-ship-ui/command-approval.png) | Real persisted orbit preview before separate approval |
| [Arrival due](native-ship-ui/arrival-due.png) | Deadline passed, navigation still unconfirmed |
| [Stop requested](native-ship-ui/run-stop-requested.png) | Real Stop handler, reserved ownership still settling |
| [Missing observations](native-ship-ui/missing-fields.png) | Missing crew, fuel, inventory, cooldown, mode and condition remain Unknown |
| [Guided mobile](native-ship-ui/guided-mobile.png) | Actual completed-flight restart, visible highlighted field below instructions |

I inspected the portrait/home, report, approval, Stop, missing-data and guided screenshots against Horizon's hierarchy. Fuel and Cargo remain inside the measured normal portrait bounds; the scene shrinks first. Long content, 24px text and landscape remain scrollable. Reports have visible dismissal, readable groups and no phone bezel. Additional state/layout screenshots and measured rectangles remain in `native-ui/browser/`.

Tested source and build identity: SHA-256 `dc85e02b65c933d77cc1e67e0c8aff04eb4e6ad1a0d22023943de6c5a39e3f29` of the staged binary diff against the baseline for `app resources routes scripts tests public/build`. [Evidence manifest](native-ship-ui/evidence.json) records changed-file and screenshot hashes. Documentation-only edits do not change that identity.
