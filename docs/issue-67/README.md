# EXP09 local implementation

Issue [#67](https://github.com/giantsoup/spacetraders/issues/67), associated PR [#68](https://github.com/giantsoup/spacetraders/pull/68).

Implemented locally on `implement/issue-67-cycle-economics-20261101`, from main `5c1990696811554d03a752dae2cfec6259fcc36c`. Implementation commit: `a91d749c4166db0bb86f9e6894815355ba58c066`. The original preparation brief was copied with `git show`; no plan branch was merged.

## Accounting behavior

`CycleAccounting::record()` captures the observed endpoints, operating cash, costs, elapsed time, participating ships and capital snapshot through confirmed command accounting, runner advancement, completion, blocking and Stop. Transactions, execution, reserve checks and reconciliation still use the existing durable executor.

A comparable cycle requires matched position, tank fuel and inventory, settled commands, measurable elapsed time and a wear basis. Different endpoints and retained cargo stay unresolved. A wear allowance is optional and has no default. Its integer amount covers the entire bounded run, including repairs. The allowance changes reporting, not spending authorization. Observed repairs are deducted once; only the allowance above repairs is an additional deduction. A boolean assertion that wear was recorded no longer establishes a monetary wear basis.

The ledger separates observed operating cash from net after wear. A closed cycle using an allowance is `estimated_comparable`; its realized net rate remains null. Missing wear, unmatched endpoints or unsettled execution withhold comparable rates. Existing legacy records without this ledger remain unresolved.

`EvidenceAnalysis` exposes all started strategies in a bounded cycle window, alongside mining policy groups. It keeps failures and retained cargo visible. Its timeline uses interval unions: overlapping travel, cooldown, API waits and parallel ships count once in elapsed time. Ship-hours count each ship's occupied intervals separately. Gaps between runs are excluded and disclosed. Sales throughput and net per ship-hour use those denominators.

Capital comes from confirmed, owner/reset-scoped delivered-ship purchase outcomes. The purchase includes the original loadout. Imported ships and later unobserved equipment costs remain unknown. Current offer prices do not become historical acquisition costs. Capital is separate from operating income.

## Estimates and inventory

Investigation and local intelligence use the same historical ledger. Mining forecasts match equipment, site, buyer, starting location/fuel and quote signature within the existing seven-day window. Base uses observed sample means. The conservative sample envelope combines the lowest observed net and longest duration, or shortest duration for losses. Sample count, exclusions and historical wear assumptions are visible. No confidence percentage or minimum-return policy is invented.

Trade and contract cash estimates are explicitly partial, with base and adverse cases. Unmeasured wear, empty returns, fuel or inventory basis, and timing keep their complete-cycle rates unknown. Contract cash ranking remains a separate cash shortlist; it is not presented as comparable net income.

Remaining inventory is valued only for one currently observed batch per good at one reachable buyer, after the known fuel ceiling. Excess, stale, unpriced, invalidated or unreachable cargo stays uncertain. The report labels this as value after fuel; full net after remaining wear and selling-time exposure stays null. These estimates never become realized income or close an open cycle. Cargo choices also cap quoted proceeds at the observed batch volume.

## Fleet comparison

**Intelligence → Compare a fleet investment** sends a report-only, owner-scoped, CSRF-protected request. Enter base and conservative total fleet net after all operating costs and wear, elapsed and occupied ship-seconds, separate added ship/equipment capital, production during a haul round trip, hauler capacity and buyer capacity over that trip. Unknown inputs can remain blank. Supply the source/assumptions and acknowledge matched cycle boundaries and complete costs.

The evaluator selects the best measured comparable current-assets run in the evidence window with matching endpoints, starting fuel and inventory, and equipment still present on the owned ship. It reports the selected run. Unobserved alternatives remain unknown. Payback divides added capital by incremental income over that alternative, not total fleet income. Missing capital, cost/timing evidence, a baseline, sufficient throughput or positive conservative incremental income withholds payback. The comparison does not save a gameplay policy or authorize buying ships.

## Acceptance and rerun commands

Use this checkout's `scripts/herd-php` (Herd PHP 8.5.12), Node 26.13.0 and pnpm 12.16.1, with the locked dependencies. The worktree owns its generated environment/key, SQLite, storage, sessions, locks, fake worlds, browser and loopback ports. It does not copy a live environment or use `spacetraders.test`.

| Acceptance | Regression evidence |
| --- | --- |
| Closed cycles, explicit/missing wear, repairs without double deduction | `CycleEconomicsTest`, real staged mining and bounded trade browser runs |
| Open position, unmatched fuel, Stop and unsold cargo | `CycleEconomicsTest`, existing worker recovery suites |
| Overlapping waits and ship-hours | Persisted overlapping run intervals through `EvidenceAnalysis` |
| Reachable inventory, batch caps, stale/unpriced cargo | `CycleEconomicsTest`, local intelligence report and download |
| Base/conservative versus realized/unresolved | Investigator/evaluator regression, browser ledger and exported JSON |
| Capital, throughput, incremental payback, missing inputs | Confirmed synthetic ship purchase and report HTTP tests, browser positive/constrained comparisons |
| Ownership, integer inputs and CSRF | Foreign-owner rejection, amount validation, `UpgradeCompatibilityTest` |
| QA01/UI01/DOC01/OPS01/OPT01 preservation | Full verification, runtime/build/frontend checks, query-count regressions and affected browser journeys |

```bash
export PHP_BIN="$PWD/scripts/herd-php"
# Set PNPM_BIN to an executable for the pinned pnpm if it is not on PATH.
./scripts/verify.sh
"$PHP_BIN" vendor/bin/phpunit tests/Feature/CycleEconomicsTest.php tests/Feature/Opt01FuelListingBatchTest.php
"$PHP_BIN" scripts/verify-mvp-worker.php
"$PHP_BIN" scripts/verify-automation-worker.php
export PLAYWRIGHT_BROWSERS_PATH="$PWD/storage/app/mvp-evidence/browsers"
QA_ARTIFACT_DIR="$PWD/storage/app/exp09-evidence/browser" node scripts/verify-opportunities-browser.mjs
QA_ARTIFACT_DIR="$PWD/storage/app/exp09-evidence/automation-browser" node scripts/verify-automation-browser.mjs
./scripts/composer validate --strict
./scripts/composer check-platform-reqs
./scripts/composer audit
pnpm audit
```

The baseline was 321 tests and 1,711 assertions. Final verification passed 334 tests and 1,799 assertions. The focused economics/OPT01 run passed 36 tests and 209 assertions. Both real-worker suites and both affected browser suites passed. Composer validation/platform checks and Composer/pnpm audits passed. Inspected screenshots and sanitized logs are recorded in [the dark local review report](evidence/qa.html).

A concurrent build/browser launch initially returned HTTP 500 at login. An owned reproduction confirmed `ViteManifestNotFoundException` while Vite replaced its build directory; login returned HTTP 200 before and after. Final browser checks ran with stable assets and had no console/server errors. Serialize asset builds and browser checks.

The report page records fixed verification-selector defects and the fixed extra fuel-listing query, rather than hiding the earlier failures.

During verification, the shared `origin/main` reference advanced to `930e5bbc5aa83aa2c87c313ee6168206f08c4cb4` through eight commits containing four unrelated task briefs and their merge commits. `git diff --name-only 5c199069..origin/main` lists only those four documentation files. Runtime source and build assets are unchanged by that advance. This local branch retains the requested implementation base.

## Integration with PR #68

Retain the local branch and review its implementation and evidence commits. When publication is separately authorized, integrate this local branch into the existing PR branch `plan/exp09-integrate-comparable-complete-cycle-economics-and-conservative-forecasts`, preserving the existing remote head in its ancestry so a normal push is possible. The task brief already exists there; resolve its duplicate addition using the annotated historical brief from this branch. Compare the integrated tree with the reviewed local tree and rerun verification for any runtime change. Do not merge unrelated preparation branches or create another PR. Incorporate newer main changes and rerun verification if main advances. The tracked production build must accompany source changes.

This work does not establish live API economics, calibrated yields/confidence, production deployment, remote CI, an independent review or security audit. The fleet comparison uses entered scenarios and measured local evidence; it does not implement coordinated multi-ship gameplay. Actual repair commands and missing historical acquisition costs remain unobserved. No push, remote merge, issue closure, PR metadata change, live gameplay, paid call, external notification or shared-service change was performed.
