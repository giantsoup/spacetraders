# ADV01 local implementation

Issue [#37](https://github.com/giantsoup/spacetraders/issues/37), existing PR [#38](https://github.com/giantsoup/spacetraders/pull/38). Implemented locally from `5c1990696811554d03a752dae2cfec6259fcc36c`. The remote PR remains a preparation brief until a maintainer integrates this work. No remote metadata changed. During verification, the shared remote-tracking ref advanced to `87760a4` with two OPS02 documentation commits. This work remains based on the requested `5c19906`; integration must account for the later main head.

## Implemented behavior

The flight deck's **Adviser policy** destination saves an expiring, versioned grant for one owned ship in its observed system. Grants bind to the agent, world, credential ciphertext fingerprint, and schema version. Every revision requires the previous grant ID, revokes that grant atomically, and retains its terms. Pause is monotonic. Resuming requires an explicit new version. The private credential fingerprint never reaches page data or decision evidence.

Terms include permitted activities, explicit per-activity starting-cargo sale quantities, a protected balance, spending/loss/fuel/time limits, a refuel quantity, trial permission, an observation request cap, and separately configured exploration limits. Amounts are integers. Request validation rejects unknown term keys, fractional quantities, unowned ships, mismatched systems, and exploration limits larger than the activity limits. Expiry is an explicit UTC instant no more than one day away.

**Collect bounded observations** uses `OpportunityInvestigator`, including its request/elapsed-time bounds, cancellation, coverage record, reset checks, and read-only provider guard. **Assess saved evidence** makes no network request. It reuses `OpportunityEvaluator` for mining and `OperationPlanner`/`FuelPlanner` for verified scouting routes. It records the evaluator outputs, parameters, observation IDs/times, coverage, accounting discrepancy, current ownership and unresolved actions in an immutable snapshot. The pure `AdviserSelector` can replay that snapshot without consulting current configuration, discovery or the network.

Candidates include waiting, supported reads, local refueling, explicitly permitted local sale batches, mining trials/measured routes, and purposeful same-system scouting. Contracts are explicitly unsupported in adviser v1; existing contract workflows remain available separately. Each candidate has a stable logical ID, a SHA-256 plan hash bound to its grant and evidence, an explicit feasible/missing-information/blocked/unsupported classification, exact known bounds, unknown values as null, and reasons. Stored decisions cannot be edited or individually deleted through the models, and there is no decision-update endpoint. Account deletion retains the application's existing checks and database cascade behavior. Direct database administration is outside this immutability boundary.

Every mutation candidate requires fresh ship/balance evidence, the permitted system, no agent-owned active activity or unsettled/review-required action, and no unexplained balance discrepancy. Maximum debits and recovery must fit above the reserve without projected receipts. A changed or stale quote is not renewed by assessment. Starting cargo stays protected unless its symbol and quantity are explicitly granted. Scouting currently requires empty cargo. All mining yields need verified buyers. These are review classifications; later execution must perform fresh ordinary previews and reservations.

## Deterministic selection

Only feasible, permitted choices enter ranking. Passive waiting is always retained as the safe fallback, even if absent from the activity list.

1. Prefer due exploration only with explicit credit, fuel, time and interval allowances, a named price question that can change a buyer/mining/fuel choice, and a verified round trip to home fuel. Rank questions by permitted-cargo, mining-yield, then fuel relevance. Information value stays separate from money.
2. Prefer positive historical conservative mining contribution per time. Reuse the evaluator's base mean from at least three comparable confirmed trips. The conservative comparison uses the lowest observed contribution and longest observed trip. Integer cross-multiplication avoids rounded-rate ties.
3. Prefer permitted cargo recovery, then an explicitly allowed one-extraction unknown-income trial, then bounded refuel preparation, supported reads, and waiting. A nonpositive historical conservative result blocks another automatic-choice recommendation.
4. Break ties by lower committed capital, shorter estimated duration or configured hard limit when time is unknown, then stable ID. Save the rule and components.

Historical mining cash contribution excludes wear. It is not a guaranteed lower bound on future income. Sale base receipts exclude sunk acquisition cost; their conservative income remains unknown. Refueling uses a 2x observed-quote local allowance. Travel uses the existing conservative CRUISE planner and a recorded scheduling allowance. Unmeasured yields, future prices, queue waits, chart rewards and information value are never converted into invented earnings.

## Pause, Stop and deadlines

**Pause automatic decisions** invalidates this grant and any assessment associated with it, including results that finish later. It leaves an existing run and upstream travel alone. **Stop current activity** calls the existing owner-scoped run Stop endpoint and durable runner. Reserved/in-flight actions still need to settle. Uncertain writes remain blocked until existing reconciliation resolves them.

Grants and decisions store UTC timestamps. Decisions expire at the earlier of the grant deadline, 120 seconds, and the freshness deadline of the critical ship/balance observations and quotes of feasible candidates. Already stale assessments can be saved as expired evidence and cannot renew authority. History is owner scoped, paginated, and retained across worlds. The dark UI uses `dateTime.js`, identifies the browser timezone, and keeps ISO values in `<time datetime>`.

## Enablement gates and tuning still open

Model calls and policy-executed gameplay are hard-disabled. There is no API key, paid adapter, automatic assessment trigger, policy start endpoint, or scheduler hook in this change. Starting an existing worker grants no adviser authority. This implementation neither spends live game credits nor reserves real API money.

Before any later enablement, Taylor must choose or validate:

- Exploration credit, fuel, time and frequency allowances. All default to off/zero. Frequency currently compares actual same-world scout starts, including separately approved scouts. An assessment does not consume an exploration allowance.
- Whether those exploration caps also need cumulative grant/period limits. This version records per-activity limits and does not implement a cumulative allowance ledger.
- The three-trip sample threshold, seven-day comparability window, worst-observed contribution/longest-duration rule, and whether sale recovery should outrank a measured income route.
- The 2x fuel quote allowance, 120-second scheduling allowance, unknown-duration trial treatment, and conservative recovery margin. Hard activity limits are proposed bounds for later executor previews, not a promise of completion within that time.
- History retention and pruning. Current records persist until account/agent deletion; no automatic pruning is enabled.

ADV02 and later work own atomic cross-ship debit reservations, event deduplication, model observation and paid-call budgeting, agreement checks, grant revalidation at reservation, and policy execution through the existing durable executor. No independent Sonnet review ran; no subagents were used. Live acceptance, profitability, physical-phone behavior and deployment remain unverified.

## Verification and retained artifacts

The [dark evidence report](report.html) records the final checks and screenshots. Raw rerun logs remain in `storage/app/adv01-evidence/`, ignored by Git. Browser and worker cases own disposable SQLite, synthetic credentials, fake worlds, locks, storage, session cookies and loopback ports. They do not use `spacetraders.test`, change Herd settings, or access live credentials.

Run with the README's PHP 8.5.12 wrapper, Node 26.13.0 and locked pnpm 12.16.1:

```bash
export PHP_BIN="$PWD/scripts/herd-php"
export PNPM_BIN=/absolute/path/to/pnpm-12.16.1
bash scripts/verify.sh
"$PHP_BIN" vendor/bin/phpunit tests/Feature/AdviserTest.php --display-all-issues
PHP_BIN="$PHP_BIN" PLAYWRIGHT_EXECUTABLE='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' node scripts/verify-adviser-browser.mjs
```

The adviser browser check proves grant/replacement, native cross-site CSRF rejection, bounded investigation, reload persistence, unknown earnings, exploration selection, dark desktop/390px/320px layouts under a light OS preference, no policy jobs or writes, distinct Pause/Stop, a real worker settling Stop, and a separately approved synthetic manual orbit through the shared executor. Screenshots show the implemented application, not a mockup.

## Corrections during verification

Initial checks exposed a JSON numeric-normalization hash mismatch and a timezone-label render error; both were fixed and replay/browser tests now pass. Visual inspection found a native select with a light background. Policy fields now have explicit dark colors, with a browser assertion for the select. The cross-site CSRF case uses Laravel 13's normal origin-aware middleware; same-origin requests are not incorrectly treated as missing-token failures. A focused regression also proves that assessment cannot extend a nearly expired quote's lifetime.

## Integrate into the existing PR

Retain this worktree for Taylor's review. When remote integration is authorized, update the existing PR branch `plan/adv01-add-versioned-adviser-policy-grants-and-deterministic-activity-selection` with current `main`, then cherry-pick the local implementation commit. Resolve the brief's add/add conflict with this implementation version. Do not merge unrelated preparation branches or create another PR. Re-run locked verification and the adviser browser check on the integrated head before pushing normally to PR #38. Its title/body, draft status, issue acceptance and remote checks remain unchanged by this local task.

The additive migration creates only `adviser_policies` and `adviser_decisions`. For an owned local database, migrate before opening the dashboard. A code rollback must retain a compatible schema or roll back this migration while preserving an appropriate backup of adviser history. No existing gameplay table or shared service is changed.
