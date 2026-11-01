# Local batch-two integration

Six completed local implementations are combined on `integrate/completed-batch2-20261101` in `/Users/Taylor/.t3/worktrees/spacetraders/integrate-completed-batch2-20261101`. The inspected base is `d16e9fe51e2e6e6512466f7182fddfcb6ce0cda4`. The implementation and rebuilt assets are committed at `a903845aaf9a2b40c87f02e8abd683a24d75ba02`. A later documentation commit contains this combined evidence package.

This is a local recovery integration. PRs #10, #12, #38, #52, #68, and #82 merged task briefs without implementation. Those PRs cannot deliver this code through an update. Issues #9, #11, #37, #51, #67, and #81 remain open for review of actual code acceptance and publication.

The [dark report](report.html) and [combined evidence](evidence/summary.json) accompany this reference. The original per-branch reports and dated evidence remain unchanged under `docs/issue-{9,11,37,51,67,81}`. Their prior success claims describe separate source branches. Their instructions to update the original PRs are historical and superseded here.

## Source provenance

All six branch tips matched the requested commits before integration. Git cherry-picks applied the real implementation commits in this worktree. Conflict resolution retained main's existing brief contents and combined application behavior. No source worktree was modified.

| Issue | Protected source branch suffix | Source commits | Local commits |
| --- | --- | --- | --- |
| #9 OPS02 | `issue-9-private-account-access-20261101` | `f74e3888c8112b423885f144fd32806f11e351f3` | `31c7152` |
| #11 OPS03 | `issue-11-deployment-drain-20261101` | `a87518a96586a1e3be952a0d15e8ade8ef1e027a` | `6239266` |
| #37 ADV01 | `issue-37-adviser-policy-20261101` | `8b58a5dca55f678603760b441e1425507f06063f` | `fb9c495` |
| #67 EXP09 | `issue-67-cycle-economics-20261101` | `a91d749`, `9a68a1a12e29566c708c6d596a4449843eeb206b` | `e2695d8`, `0a28add` |
| #51 EXP01 | `issue-51-scan-chart-commands-20261101` | `2fe309c`, `360e4c658ae10636eb399da09795d0856d7c04f5` | `e45d769`, `6a3b1e8` |
| #81 OPT02 | `issue-81-fixture-cleanup-20261101` | `8e12b91e8bebbe9396941a9c89b8296248eaf7ae` | `3029572` |

Each branch name above has the `implement/` prefix. Every source branch starts at `5c1990696811554d03a752dae2cfec6259fcc36c`. `provenance.json` records full commit identities.

## Integration decisions

The existing six task briefs stay byte-identical to inspected main. Generated asset changes from the source branches were excluded from their cherry-picks. One complete asset set was then rebuilt from the combined Vue source. Removed public-account pages have no compiled entries. Locked dependency files remain unchanged.

The authenticated route group contains adviser policy and history routes, economics comparison, and exploration Stop alongside the existing command routes. Owner authorization, real CSRF middleware, throttling, the scoped durable executor, request budgets, agent locks, and queued intent remain in place. The dashboard carries adviser, exploration, cycle accounting, fleet comparison, and activity views together.

The cleanup callback encloses the added economics assertions in both existing browser journeys. Its successful result is written after browser, server, and owned service shutdown. The new combined journey uses that same lifecycle with a private persistent file cache so the deployment gate is exercised across processes. Failed fixtures remain private. Durable evidence stays outside the fixture.

Two integration defects required source changes:

1. The adviser still derived conservative income from cash-only historical samples. EXP09 supplies comparable closed-cycle net after wear. Selector version 2 consumes that forecast, preserves the adviser requirement for at least three usable samples, and rejects unresolved or nonpositive conservative income. Legacy cash-only history remains unknown. UI labels distinguish cycle net from recovery receipts.
2. The shared evaluator admitted future-dated history. A reproduced failing regression led to a bounded UTC interval from seven days before assessment through the assessment instant. The adviser regression also checks that unresolved cycles do not satisfy its sample threshold.

Stored grants and decisions remain immutable. Model calls and policy execution remain disabled. No later flight modes, cargo refueling, itinerary execution, read-optimization capability, or other work from independent threads #53, #55, #57, or #83 was added.

## Combined verification

The selected stack is Herd PHP 8.5.12 through this checkout's `scripts/herd-php`, Laravel 13.39.0, Node 26.13.0, pnpm 12.16.1, Vue 3, Inertia 3, Playwright 1.64.1, and owned Chromium 156.0.8078.4. Dependencies were installed from unchanged locks into this worktree. No global or primary Herd runtime changed.

Final verification results and measurements are recorded in `evidence/summary.json`. The full entry point passes 391 tests and 2,244 assertions, Pint, frontend checks, 18 lifecycle cases, environment probes, whitespace checks, and two byte-identical production builds. Composer validation, platform requirements, Composer audit, and pnpm audit pass. The current pnpm audit succeeds with zero reported vulnerabilities; older branch reports about audit parser failures are historical.

| Acceptance boundary | Combined evidence |
| --- | --- |
| OPS02 private account | Direct account routes closed; real hidden-terminal creation and recovery; redirected input and noninteractive input rejected; no secret echo or logging; cached routes and configuration; existing session retained; authenticated password change; owner and CSRF regressions |
| OPS03 drain | Persistent versioned gates; real SQLite reservations, web-writer and scheduler acknowledgements; delayed jobs preserved; duplicate delivery; frozen protocol-compatible rollback artifact; stale controller rejection; measured 120-second lock expiry; real ten-minute timeout |
| ADV01 policy review | Versioned expiring grants; immutable decisions; owner scope; deterministic candidates and hashes; Pause distinct from Stop; UTC expiry with detected local display; net-after-wear and history-window regression; no model call or policy execution |
| EXP09 economics | Actual runner, worker, report, export, and evaluator paths; 25 CR wear allowance yields 215 CR estimated mining net; incomplete contract cycle stays unresolved; fleet comparison withholds payback when buyer throughput is insufficient; cash reward excluded from comparable cycle income |
| EXP01 exploration | Purpose and reserve validation; partial scan; chart reward; capability failures; real workers; Stop; reset; lost-response uncertainty and no replay; listing, scan, and chart evidence remain distinct |
| OPT02 cleanup | 18 lifecycle cases; six real browser and native-service lifecycle cases with no skips; successful automation, opportunities, and combined fixtures removed after verified shutdown; deliberate failure diagnostics retained privately |

The combined browser journey signs in to a private account, proves real CSRF enforcement, rejects an unfunded reserve, and creates a scan preview. A closed deployment gate blocks approval, adviser pause, economics requests, and worker pickup without changing the saved state. After reopening, a pending scan blocks adviser action selection. Finite workers confirm a partial scan and then a separately approved chart. The 100 CR chart reward reconciles as a manual change and creates no comparable mining history. Pausing the policy invalidates its review decision while execution stays disabled. Successful shutdown removes that exact owned fixture.

Browser screenshots were opened and inspected for dark backgrounds, text wrapping, controls, timezone labels, and outcome wording. Checks cover 1440px, 390px, and 320px where supported. An owned loopback maintenance page was also inspected through the shared T3 preview and its tab closed before fixture release. This does not establish native Safari or real-device behavior.

## Failed attempts retained

Committed log copies remove terminal color escapes and trailing terminal padding. Original command output remains under `storage/app/integration-evidence`. The final evidence package passed a full branch whitespace check after terminal padding was removed from the copied platform log.

The initial combined suite failed the old adviser cash-history expectation. `verify-initial.log` retains that failure. A verification-wrapper placeholder fixture variable then interfered with PHPUnit's environment; `verify-owned-env-failed.log` retains those errors. The corrected runner lets PHPUnit supply its forced test environment. The future-history regression failed before its fix in `adviser-future-before.log`. An additional native ship journey found a single-fieldset locator that became ambiguous with nested exploration controls. `native-ship-before-fix.log` retains that failure. Its replacement checks effective disabled state across all manual fieldsets, including inherited disablement. Accepted fixes were followed by full verification and affected worker and browser reruns.

Lifecycle tests intentionally cause setup, assertion, signal, and shutdown failures. Their overall test result passes only when the expected failure and safe retention behavior are verified. Those negative fixtures are not presented as successful gameplay runs. Generated keys, passwords, environments, databases, and private diagnostics are excluded from committed evidence.

## Limits

All gameplay verification uses synthetic worlds and fake providers with owned SQLite, keys, sessions, caches, locks, storage, processes, service identities, loopback ports, and browser assets. No primary environment, key, database, credential, or shared service was read or copied. No extra server for `spacetraders.test` was started.

Endpoint and schema research retained from EXP01 is dated 2026-11-01. Actual sensor range, cooldown duration, orbit mechanics, rewards, and authenticated upstream acceptance remain unverified. Local client restrictions are explicit policy, not claims about the upstream service. No live gameplay, purchases, paid API calls, deployment, notifications, remote CI, or remote mutations occurred.

Adviser tuning and its three-sample threshold still need measured evaluation before later enablement. Incomplete wear, inventory, fuel, position, and acquisition evidence remain unresolved. The frozen rollback test covers a protocol-compatible artifact; a release predating deployment gates cannot supply readiness acknowledgements. Cutover preparation must account for such processes separately.

No independent Sonnet review was run. This task explicitly prohibited agents and threads. Direct source, asset, regression, and screenshot review does not replace a separately requested independent review.

## Review and publication guidance

The next review target is the complete local diff from `d16e9fe` through this branch, including source, migrations, regenerated assets, and this evidence package. The six issue acceptance decisions remain separate from test success.

After separately authorized publication, use a new PR for the actual integration code. The six merged brief-only PRs cannot be updated to deliver it. Recheck the then-current main, reconcile any new overlaps in a permitted checkout, and rerun combined verification before publication. Keep the six issues open until actual implementation acceptance and publication are reviewed. Deployment, live-local migration, and enabling policy execution require their own authorization.

Reproduction commands are in [reproduce.md](reproduce.md).
