# Base API foundation implementation

Issue [86](https://github.com/giantsoup/spacetraders/issues/86), draft PR [87](https://github.com/giantsoup/spacetraders/pull/87). Final implementation source: `659f8aa6aeeef1398ee647a4fd378acb2553fb01`, based on planning `98ff5d7f38f3064f7bab40d629ca3e6359892efc` and integrated baseline `99be3c474dddd7bf9fd1c1aec5bf2147cf9b5868`. Later evidence-only commits identify this source separately from the PR head.

All 59 pinned operations have source implementations: 26 named dedicated reads, 32 manual gameplay actions including explicit PATCH, and the existing account-token registration flow. There is no missing source operation. **Full foundation acceptance remains 0/59**. Official behavior and per-variant calibration remain pending; the unchanged `--require-complete` verifier correctly fails. This PR remains draft and the issue remains open.

The [capability map](foundation-capability-map.md) locates every operation. The [operation ledger](foundation-operation-ledger.json) joins schema fields with actual evidence, and the [variant gates](foundation-variant-gates.md) list the limits of each claim. Source mappings are not progress percentages or acceptance-stage records.

## Delivery units

| Published implementation | Behavior |
| --- | --- |
| `132ff0e48667c8adbaa35cc43d84189596200f1e` | FND-01/02: explicit named reads, bounded query variants, dedicated refresh paths, PATCH preview, generalized manual Stop and raw/derived cooldown separation. |
| `186938bd092000d7bfa180dac077c8d4632d2f3f` | FND-03 through FND-11: general fleet/cargo, independent contracts, repair/equipment, resource actions, travel/refuel, scans/chart, cross-system travel and construction. |
| `b1b0b8922c2174343c876f7a1d0f45fdeb09879e` | FND-12: deletion-safe scrap, response receipts, component/capacity effects, dedicated auth/query/registration boundaries and full combined assets. |
| `308ccb9c86d9180b05fd5d05934d0bc5c069131c` | FND-13 verification fixes: agent-only contract isolation, readable contract terms, server-side reserve floor, conditional Stop, read normalization, numeric arguments, pagination, destructive approvals, cargo conversion maximum, signed survey rejection and unchanged derived deadlines. |

The native **API library** refreshes one official read at a time and presents its full response, including bounded pagination and scalar/array waypoint trait filters. **Manual API** previews and approves one exact action. Neither requires an opportunity, strategy, mining setup or exploration question. General fleet purchases preserve the exact observed configuration; contract accept/fulfill require no ship. Existing bounded gameplay callers retain their approved strategy limits.

Explicit operations use the owner-scoped Laravel request/controller, CommandRules, shared SpaceTraders client/request budget and durable ShipCommandExecutor. No authenticated generic forwarder was introduced. All gameplay uses the agent bearer; registration alone uses a supplied account bearer, which is not persisted. The inherited faction/module auth defect and implemented single-agent credential policy are documented separately from pending server acceptance.

Returned effects include cargo, nav, fuel, cooldown, condition/integrity, equipment multiplicity and capacity, new contract IDs, construction progress, events and modifiers where published. Write status/body hash is retained before validation/bookkeeping; a failure after send or receipt remains uncertain without replay. Reconciliation gathers operation-specific state. Confirmed scrap deletes only the exact owned ship while retaining action history and credit entries; absence alone never confirms an uncertain scrap.

The source-reviewed #53 branch `a1e5f6e` supplied CRUISE-only restoration context; #55 `3d0bd12` supplied partial cargo-refuel semantics. Integration reused the relevant approval/configuration/conversion logic, resolved shared modules and rebuilt one asset set. It did not import EXP04 itineraries or OPT03 optimizations. Old task issues were not closed from this broader work.

## Verification at implementation source

Owned executable `./scripts/herd-php` selected Herd PHP 8.5.12; Node 26.13.0 and pnpm 12.16.1 were used. Locks are unchanged. Dependencies, SQLite, keys, storage, sessions, caches, locks, fake providers, loopback ports and browser profiles were worktree-owned. No real `.env`, key, credential or gameplay database was imported into tests. The primary dirty checkout and live-local cutover were untouched.

| Check | Actual result and evidence |
| --- | --- |
| Baseline | 391 backend tests, 2244 assertions before implementation. |
| Final locked runtime/platform and full verification | **469 tests, 2746 assertions**, frontend/store/gameplay/time/tutorial/lifecycle checks, deterministic double build, environment safety, Pint and whitespace passed. [Full log](evidence/fnd-final-full-verification.txt), [platform](evidence/fnd-qa-platform.txt). |
| Coverage verifier | Integrity passes, all 59 source mappings; 15 verifier regressions pass. Official public schema refresh still matches pinned SHA `c07d04f575df43e4d05eea4ba539f2d33ef8c5d9a3c863d49520193ef5431ad8`. Completion deliberately fails 0/59. [Evidence](evidence/fnd-qa-coverage.txt). |
| Built assets | All 34 manifest-referenced files exist in the committed tree. Browser loaded actual hashed production assets; no alternate source server. |
| Original durable worker boundaries | Real file-backed workers passed duplicate mining/purchase delivery, restart, killed sender, uncertainty/non-replay and offline Stop. [Final repeat](evidence/fnd-qa-mvp-workers.txt). |
| Manual Stop/reservation/PATCH | Separate workers passed Stop before reservation (zero writes), Stop after reservation (one settled action with Stop evidence), duplicate PATCH, lost repair response and restart/no replay. Conditional stale-read regression also passes. [Races](evidence/fnd-qa-stop-races.txt). |
| New lost-response families | 15 independently allocated file-backed scenarios passed actual worker durability settings, lost-response uncertainty, duplicate delivery/restart, fresh reconciliation and exactly one write. Covers navigate/jump/warp/supply/siphon/refine, all equipment actions, all contract transitions, negotiation and scrap. [Log](evidence/fnd-qa-uncertain-workers.txt). |
| Browser reads | All 26 named reads exercised through real Vue controls at the first implementation unit. [Records](evidence/fnd-reads-browser.json). Dedicated HTTP regressions cover malformed responses and preserved planning observations. |
| Browser actions | 31 non-scrap form/approval/real-worker journeys at the second unit, plus scrap at the third. [Actions](evidence/fnd-manual-browser-worker.json), [scrap](evidence/fnd-scrap-browser-worker.json). One representative variant each; no blanket variant claim. |
| Follow-up browser | At final source: selected in-transit ship + agent contract, plain extract, omitted market refuel, standard CRUISE, omitted PATCH, jettison Stop with zero writes, cargo/equipment/scrap approval Stop, frozen pagination, rendered faction enum, long refuel form and destructive scrap panel. [Records](evidence/fnd-qa-followup-browser.json). |
| Empty fleet | Final ship removal preserves history; ship actions disable while agent contract controls still work. [Record](evidence/fnd-final-empty-fleet-browser.json). |

The supplemental platform invocation initially split a Composer path containing spaces and did not execute. Its published log was corrected with a successful quoted run; the earlier platform check had passed. Locked Composer and production pnpm advisory checks also found no known advisories ([logs](evidence/fnd-dependency-audits.txt)); this is not application security sign-off.

The first broad verification at `b1b0b89` exposed a Pint import-order failure in routes; it was fixed. The final full check above passes. Earlier remote CI exposed the obsolete contract-write rejection test; the test now verifies manual preview plus arbitrary-field denial. New QA regressions first reproduced seven real failures, then passed after fixes. A new uncertainty harness initially looked for the wrong evidence key; its assertions were corrected to the actual durable fields and rerun. No production acceptance checker was weakened.

All browser/worker mutations above used synthetic providers. No real-game mutation, purchase, jettison, construction, scrap, registration or spending occurred. No live acceptance is claimed.

## Independent QA and fixes

Claude Sonnet 5.5 reviewed `b1b0b89` independently and inspected the committed screenshots and coverage gate. It did not rerun backend/frontend/workers/browser. Its [full findings](evidence/sonnet-qa-review-b1b0b89.md) remain preserved.

The final source fixes the review's contract actor coupling, missing contract terms, optional reserve, Stop read/write interleaving, canonical read-normalization bypass, unlabeled conservative policies, four numeric casts and form/pagination/destructive-style issues. Tests reproduced the actor/reserve/Stop/malformed-read defects. Additional regressions cover signed plain-extraction rejection, bounded cargo loss versus expected conversion, and non-renewal of unchanged derived cooldowns. The separate Sonnet 5.5 security review completed on 308ccb9, ran 103 targeted tests and confirmed a post-receipt budget defect. Findings 1–4 were fixed and verified at 659f8aa. See the [security review resolution](foundation-security-resolution.md); the fixes were not independently re-reviewed, and no live certification is claimed.

## Visual and cleanup evidence

Inspected actual screenshots: [contract with in-transit actor](evidence/screenshots/contract-approval-transit.png), [390px refuel form](evidence/screenshots/refuel-form-390.png), [refuel approval](evidence/screenshots/refuel-approval-390.png), [equipment approval](evidence/screenshots/install_mount-approval-390.png), [destructive scrap approval](evidence/screenshots/scrap-destructive-approval.png), [empty fleet/history](evidence/screenshots/empty-fleet-contract-history.png), [library](evidence/screenshots/api-library-desktop.png). Narrow layout had viewport/document width 390 and dark `rgb(7,13,23)` despite light OS preference. Modal heading focus, Tab and Escape were inspected; this is not full accessibility certification. The journey helper's `focused:false` compares the panel to the focused dialog heading, not evidence of lost dialog focus.

The last owned tab was closed. The final fixture harness verified server and browser process exit, removed its allocation and reported no lifecycle failures; port 52710 has no listener. Older interrupted fixture allocations and worker diagnostic directories may remain private in the OS temporary directory; they contain only generated synthetic data and were not claimed removed. No shared Herd site/service/default was changed and no server was started for spacetraders.test.

## Remaining gates and rollback

PR87 stays draft and issue86 stays open. Official read/auth acceptance, per-variant mechanics, equipment/world availability, refinery/rounding behavior, warp/non-CRUISE formulas and actual construction/scrap effects remain pending as listed in the variant matrix. Real mutation acceptance requires separately approved agent/resources and numerical limits after read-only preflight; no budget was inferred here. New strategies, adviser execution/AI calls, investments, notifications, mobile/hosting and deployment remain deferred.

No database migration was required. Source rollback can revert the implementation units and restore their matching committed assets; retain durable intents/history and settle pending or uncertain actions before changing a running installation. No installation or live cutover was performed by this task. Do not use rollback as authority to replay an uncertain write or delete gameplay records.


## Final security follow-up at 659f8aa

The original 469-test verification above remains historical at 308ccb9. After independent security review, the full verification script passes 475 tests/2791 assertions with new assets, and the last PHP-only projection/policy corrections pass the full backend 475 tests/2794 assertions. Final format, coverage and whitespace checks pass; all 34 committed manifest files exist. Source hashes now identify 659f8aa.

Post-receipt request-budget deferral can no longer return a paid write to pending or false rejection. It retains its validated response as uncertain and cannot be scheduled/replayed. The four inherited-auth operations no longer poison saved credential state on 401; malformed selectors return 422. Stop updates its JSON field atomically without replacing receipts. Real worker restart/duplicate/Stop regressions passed. Refuel approvals now show source/tank change and protected cargo; uncalibrated partial cargo projections display Unknown. Browser journeys, inspected screenshots, lifecycle cleanup and all review dispositions are linked in the security resolution.
