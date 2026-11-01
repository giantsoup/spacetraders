# Documentation plan implementation audit

Audited November 1, 2026. Local source `221fbae7a1ecbdf5159211316039f016355c900e`. Remote `main` `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb`. This is a source and offline-test audit, not a live deployment or gameplay verification.

## Scope and findings

42 distinct work items have issue and draft-PR handoffs in the table below. Required implementation gaps, conditional future capabilities, optional improvements and unverified operating gates remain separately labeled. A draft contains an implementation brief and does not claim that its feature is finished.

The Laravel 13 migration, core manual/mining MVP, staged Mining/Contracts automation and original QA/security corrections are implemented in their documented local scope. The approved ship redesign, iPhone app, adviser and deployment are not complete. R07 has accounting helpers and honest unresolved displays, but lacks an integrated normalized comparison.

The reviewed deployment plan exists only in the two local commits ahead of remote main. DOC01 publishes that plan as a reference. Every task branch starts from the same audited remote main, so unrelated local commits do not appear repeatedly in the task PRs. The raw server-capacity inventory is retained locally.

## Current verification

- `scripts/verify.sh` used Herd PHP 8.4.27, Node 26.13.0 and the existing cached pnpm 12.16.1 executable through a temporary wrapper. Production assets built twice with identical digests; frontend logic, UTC/local presentation, guide and environment checks passed.
- The full backend run completed 293 tests and 1,523 assertions with one failure at `OpportunityTest.php:561`. An isolated `--repeat 10` failed on repetition 3; subsequent repetitions were skipped. QA01 tracks the timestamp-boundary test correction. No full-suite green claim is made.
- Focused researcher runs passed 108 tests / 575 assertions and 145 tests / 618 assertions. These are separate, overlapping selections, not additive counts. The intermittent failure can still occur.
- Focused Pint checks and `git diff --check` passed. Full `verify.sh` stopped at PHPUnit, so its final full formatting stage was not reached.
- No browser journeys, real worker lifecycle, actual sleep, live game calls, paid model calls, production host inspection, migrations or deployed behavior were rerun by this audit. Retained browser/worker reports are historical evidence only.

## Document coverage

| Document | Status | Evidence and task boundary |
| --- | --- | --- |
| [app-styling.md](app-styling.md) | Implemented current terminal styling | It accurately says redesigned screens remain prototypes. UI01 owns the replacement. |
| [design-brief.md](design-brief.md) | Completed design reference; Vue implementation open | UI01 implements the approved screen family; prototype approval is not app acceptance. |
| [early-game-api-research.md](early-game-api-research.md) | Completed dated research | Mechanics informed implemented MVP. Refresh relevant primary contracts when implementing new command tasks. |
| [early-game-mvp-plan.md](early-game-mvp-plan.md) | Implemented locally; operating evidence incomplete | Phases 1-6 and synthetic phase 7 exist. LIVE01-LIVE03 cover cutover, real sleep/wake and first-trip measurements. General outfitting and repair are unscheduled later examples. |
| [evidence/live-rate-headers-2026-11-01.json](evidence/live-rate-headers-2026-11-01.json) | Completed sanitized evidence | Supports the bounded D3 closure, not gameplay acceptance. |
| [exploration-fuel-and-opportunity-recommendations.md](exploration-fuel-and-opportunity-recommendations.md) | Partially implemented | R01-R06 foundations and R08 controls exist. R07 is incomplete end to end. EXP01-EXP11 and LIVE03 track deferred capabilities, economic comparison and measurements. |
| [exploration-fuel-and-opportunity-research.md](exploration-fuel-and-opportunity-research.md) | Completed research; roadmap partially implemented | Use the R01-R08 mapping and EXP tasks. ASTEROID_BASE is an unconfirmed capability question, not a proven defect; validate current mechanics before changing whitelists. |
| [exploration-fuel-comparison.html](exploration-fuel-comparison.html) | Completed illustrative calculation | Hypothetical inputs are not measured profitability. |
| [exploration-implementation.md](exploration-implementation.md) | Completed implementation report with explicit gates | Core source and regression coverage exist; conditional capabilities are EXP tasks and live evidence is LIVE03. This report records accepted bounded scope, not full original-roadmap completion. |
| [ios-app-plan.md](ios-app-plan.md) | Plan only | MOB01-MOB04 cover scope, API, client and TestFlight. Its hosting phase shares OPS tasks; no duplicate hosting issue. |
| [laravel13-upgrade-plan.md](laravel13-upgrade-plan.md) | Implemented | All six migration phases have source and dated acceptance. The later PHP 8.5 decision is OPS01, not a reopened Laravel 13 task. |
| [laravel13-upgrade-report.html](laravel13-upgrade-report.html) | Completed dated acceptance artifact | Evidence applies to its reviewed revision and local scope; it is not new deployment acceptance. |
| [linode-capacity.html](linode-capacity.html) | Completed assessment artifact | Historical data, not deployed-runtime proof. |
| [linode-hosting-research.md](linode-hosting-research.md) | Completed historical assessment | The capacity report later supplied read-only measurements. Deployment still needs OPS tasks; old PHP 8.4 recommendation is superseded by the server plan. |
| [live-rate-header-verification.md](live-rate-header-verification.md) | Completed bounded verification | D3 is closed for captured successful-header semantics and synthetic follow-up. It does not prove full live throughput or every limiter combination. |
| [local-automation-data.md](local-automation-data.md) | Implemented contract with documentation drift | DOC01 corrects version 3 wording. No need to reimplement versioned execution. |
| [local-automation-implementation-plan.md](local-automation-implementation-plan.md) | Implemented locally; rollout gates remain | All six phases exist: versioned data, engine gate, investigation, staging, shared runner, contracts and dashboard. LIVE01-LIVE03 retain explicit operating gates. |
| [local-automation-loop-research.md](local-automation-loop-research.md) | Completed research; core recommendations implemented | The staged Mining/Contracts flow, engine controls and accounting exist. Its older one-extraction baseline is historical. |
| [local-automation-verification.html](local-automation-verification.html) | Completed synthetic acceptance artifact | Real fixture worker/browser/lifecycle evidence is historical. Actual sleep and operating cutover remain LIVE gates. |
| [mvp-implementation.md](mvp-implementation.md) | Implemented local MVP contract | Shared execution, credential, discovery, setup, Stop and uncertain-recovery code exists. Current full-check failure is QA01; live gates remain separate. |
| [mvp-qa-followups.md](mvp-qa-followups.md) | Implemented fixes; native sleep evidence remains | F1-F4 and later corrections have source/tests. LIVE02 covers actual sleep/wake rather than simulated time gaps. |
| [mvp-security.md](mvp-security.md) | Completed historical local review | Resolved findings have source/regressions. Live results, multiuser fairness and power-loss testing were explicitly outside that local acceptance. |
| [native-ship-ui-implementation-prompt.md](native-ship-ui-implementation-prompt.md) | Unimplemented | UI01 covers the complete connected web workflow, verification and requested implementation report. Establishing a baseline and verification are acceptance steps within that deliverable. |
| [openai-automation-adviser-plan.md](openai-automation-adviser-plan.md) | Partial foundation; adviser unimplemented | Existing investigation/evaluation is reusable. ADV01-ADV07 cover policy, credit and dollar reservations, model observation, agreement execution, live evaluation and Discord. |
| [opus-qa-security-tasks.md](opus-qa-security-tasks.md) | Required blockers resolved; three optional tasks open | T1-T6 and D3 close in later rounds. D1/D2/N1/N2 are accepted limitations. O1 works, O5-O7 are fixed; OPT01-OPT03 track O2/O3/O4. QA01 is newly reproduced. |
| `server-capacity-2026-11-01/additional-evidence.txt` | Completed read-only evidence | Historical diagnostic output, not proof of later tuning or deployment. |
| `server-capacity-2026-11-01/report.html` | Completed read-only assessment | Historical capacity only. Remains in local baseline; task briefs do not require publishing raw server inventory. |
| [server-deployment-plan.md](server-deployment-plan.md) | Plan only | OPS01-OPS11 cover runtime, private access, drain, CI, transaction, Linux configuration, shared optimizations, backups, provisioning and acceptance. It is locally committed but absent from the audited remote main. |
| [single-ship-mobile-research.md](single-ship-mobile-research.md) | Completed research; recommendations superseded or open | UI01 covers connected web implementation. The later design brief supersedes its earlier navigation layouts. PWA/push/offline behavior is an open design option, not a promised standalone task. |
| [single-ship-redesigns.html](single-ship-redesigns.html) | Completed approved prototype | Connected Vue workflows are UI01. Three reference directions do not require three selectable themes. |
| [single-ship-vision.html](single-ship-vision.html) | Completed historical prototype | Superseded by the approved redesign references; no need to implement all earlier alternatives. |
| [spacetraders-game-loop.html](spacetraders-game-loop.html) | Completed explanatory artifact | A diagram is not a missing implementation task. |
| [travel-and-manual-play-research.md](travel-and-manual-play-research.md) | Completed research | Current manual command and deadline model exists; no live trip guarantee or intersystem capability is implied. |

The four PNGs in `design-reference/` are completed design references for UI01. `.DS_Store` is filesystem metadata. Research notes describe decisions and conditional possibilities; a passing research deliverable does not mean every idea it mentions is approved for execution.

## Requirement mapping

| Plan unit | Implemented or tracked work |
| --- | --- |
| Laravel migration phases 1-6 | Implemented; `UpgradeCompatibilityTest`, manifests, kernels/config and historical upgrade report. |
| MVP phases 1-6 / phase 7 | Implemented local loop and QA/security; QA01 and LIVE01-LIVE03 retain current verification/cutover gaps. |
| Local automation phases 1-6 | Implemented engine/action gate, bounded investigation, drafts/previews, shared runner, contracts and UI. Version-3 contract wording is DOC01. |
| R01 | Catalogue/coverage/history and listing/price separation implemented. Scan/chart commands are EXP01. |
| R02 | Conservative CRUISE continuation and measured-edge support implemented. Additional modes, cargo refuel and executable fuel-stop itineraries are EXP02-EXP04. |
| R03 | One-question, one-market return scouting implemented. Clustered tours/probe investment are EXP05-EXP06. |
| R04 | Bounded extraction/headroom/time/hazard handling and cooldown/travel overlap implemented. Actual improvement measurements are LIVE03. |
| R05 | Signed surveys/exhaustion and manual transfers implemented. Dedicated surveyor and hauling economics/orchestration are EXP07-EXP08; measurements are LIVE03. |
| R06 | One bounded batch-trade cycle and remaining-cargo choices implemented. Refining/production investment is EXP10. |
| R07 | Helpers and unresolved reporting exist; normalized operating comparison and forecasts are EXP09. Live evidence is LIVE03. |
| R08 | Shared ownership, intent, request budgets, preview/Stop, reset and uncertain-write protections implemented within single-host scope. |
| Adviser sequence 1-5 | ADV01-ADV02 foundation; ADV03-ADV04 observation mode; ADV05 policy execution; ADV06 live use; ADV07 Discord. |
| iOS phases 1-5 | MOB01 scope; OPS shared hosting; MOB02 API; MOB03 client; MOB04 physical acceptance and TestFlight. |
| Native ship prompt sections 1-5 | One cohesive UI01 deliverable includes baseline map, implementation, safety, application verification and report. |
| Server implementation steps 1-7 | OPS01 prerequisites/runtime, OPS02-OPS06 app/CI/config, OPS07-OPS09 shared optimizations/recovery, OPS10 first release, OPS11 end-to-end/capacity acceptance. |
| QA tasks T1-T6 / D3 | Closed by current source/tests and later review rounds. Do not reopen historical unchecked boxes. |
| QA O1-O7 | O1 implemented; O5-O7 closed. O2/O3/O4 are OPT01/OPT02/OPT03. D1/D2/N1/N2 are accepted limitations. |

General equipment outfitting, repair strategy, contract mining/negotiation/automatic follow-on contracts, broad multiuser fairness, combat and fleet expansion without measured economics are explicitly outside the accepted local release. They have no settled standalone delivery scope here. The scoped conditional EXP tasks retain the recommendations with explicit evidence gates; they are not newly authorized investments.

## Issue and draft-PR pairs

| Task | Classification | Issue | Draft PR |
| --- | --- | --- | --- |
| DOC01: Reconcile automation documentation and cooldown preview wording | documentation drift | [#1](https://github.com/giantsoup/spacetraders/issues/1) | [#2](https://github.com/giantsoup/spacetraders/pull/2) |
| QA01: Make market quote invalidation acceptance deterministic | confirmed test failure | [#3](https://github.com/giantsoup/spacetraders/issues/3) | [#4](https://github.com/giantsoup/spacetraders/pull/4) |
| UI01: Implement the approved native ship web experience | unimplemented feature | [#5](https://github.com/giantsoup/spacetraders/issues/5) | [#6](https://github.com/giantsoup/spacetraders/pull/6) |
| OPS01: Migrate the application and verification runtime to PHP 8.5 | unimplemented runtime change | [#7](https://github.com/giantsoup/spacetraders/issues/7) | [#8](https://github.com/giantsoup/spacetraders/pull/8) |
| OPS02: Close public account routes and add secure operator provisioning | unimplemented deployment control | [#9](https://github.com/giantsoup/spacetraders/issues/9) | [#10](https://github.com/giantsoup/spacetraders/pull/10) |
| OPS03: Implement deployment drain gates and worker readiness acknowledgements | unimplemented deployment control | [#11](https://github.com/giantsoup/spacetraders/issues/11) | [#12](https://github.com/giantsoup/spacetraders/pull/12) |
| OPS04: Add reproducible CI gates and immutable release artifacts | unimplemented CI | [#13](https://github.com/giantsoup/spacetraders/issues/13) | [#14](https://github.com/giantsoup/spacetraders/pull/14) |
| OPS05: Build the atomic deployment transaction and recovery harness | unimplemented deployment tooling | [#15](https://github.com/giantsoup/spacetraders/issues/15) | [#16](https://github.com/giantsoup/spacetraders/pull/16) |
| OPS06: Prepare restricted Linux runtime and deployment configuration | unimplemented server configuration | [#17](https://github.com/giantsoup/spacetraders/issues/17) | [#18](https://github.com/giantsoup/spacetraders/pull/18) |
| OPS07: Prepare shared-app queue scheduling changes with email priority | conditional shared-host work | [#19](https://github.com/giantsoup/spacetraders/issues/19) | [#20](https://github.com/giantsoup/spacetraders/pull/20) |
| OPS08: Prepare measured shared-server cache and journal adjustments | conditional shared-host work | [#21](https://github.com/giantsoup/spacetraders/issues/21) | [#22](https://github.com/giantsoup/spacetraders/pull/22) |
| OPS09: Implement consistent backups, Mac retrieval and restore verification | unimplemented recovery tooling | [#23](https://github.com/giantsoup/spacetraders/issues/23) | [#24](https://github.com/giantsoup/spacetraders/pull/24) |
| OPS10: Provision the private Linode application and first release | unverified live deployment | [#25](https://github.com/giantsoup/spacetraders/issues/25) | [#26](https://github.com/giantsoup/spacetraders/pull/26) |
| OPS11: Prove deployment behavior, recovery and combined server capacity | unverified live acceptance | [#27](https://github.com/giantsoup/spacetraders/issues/27) | [#28](https://github.com/giantsoup/spacetraders/pull/28) |
| MOB01: Define the first iPhone release workflow and technical decisions | unimplemented mobile design | [#29](https://github.com/giantsoup/spacetraders/issues/29) | [#30](https://github.com/giantsoup/spacetraders/pull/30) |
| MOB02: Expose a versioned authenticated mobile gameplay API | unimplemented mobile API | [#31](https://github.com/giantsoup/spacetraders/issues/31) | [#32](https://github.com/giantsoup/spacetraders/pull/32) |
| MOB03: Build the iPhone client against the shared Laravel engine | unimplemented mobile app | [#33](https://github.com/giantsoup/spacetraders/issues/33) | [#34](https://github.com/giantsoup/spacetraders/pull/34) |
| MOB04: Verify phone-to-engine recovery and distribute through TestFlight | unverified mobile release | [#35](https://github.com/giantsoup/spacetraders/issues/35) | [#36](https://github.com/giantsoup/spacetraders/pull/36) |
| ADV01: Add versioned adviser policy grants and deterministic activity selection | partially implemented foundation | [#37](https://github.com/giantsoup/spacetraders/issues/37) | [#38](https://github.com/giantsoup/spacetraders/pull/38) |
| ADV02: Enforce the policy credit floor with durable agent-wide debit reservations | unimplemented adviser safeguard | [#39](https://github.com/giantsoup/spacetraders/issues/39) | [#40](https://github.com/giantsoup/spacetraders/pull/40) |
| ADV03: Implement the adviser dollar-budget reservation ledger | unimplemented paid-API safeguard | [#41](https://github.com/giantsoup/spacetraders/issues/41) | [#42](https://github.com/giantsoup/spacetraders/pull/42) |
| ADV04: Integrate the OpenAI adviser in observation mode and evaluate its value | unimplemented model integration | [#43](https://github.com/giantsoup/spacetraders/issues/43) | [#44](https://github.com/giantsoup/spacetraders/pull/44) |
| ADV05: Add policy-authorized one-time decision execution and trigger recovery | unimplemented automated decision lifecycle | [#45](https://github.com/giantsoup/spacetraders/issues/45) | [#46](https://github.com/giantsoup/spacetraders/pull/46) |
| ADV06: Run a bounded live adviser evaluation under an explicit policy | unverified paid/live acceptance | [#47](https://github.com/giantsoup/spacetraders/issues/47) | [#48](https://github.com/giantsoup/spacetraders/pull/48) |
| ADV07: Add a sanitized Discord notification outbox | unimplemented later phase | [#49](https://github.com/giantsoup/spacetraders/issues/49) | [#50](https://github.com/giantsoup/spacetraders/pull/50) |
| EXP01: Add bounded scan and chart commands for hidden-trait evidence | deferred exploration capability | [#51](https://github.com/giantsoup/spacetraders/issues/51) | [#52](https://github.com/giantsoup/spacetraders/pull/52) |
| EXP02: Validate additional flight modes and unusual-ship fuel mechanics | deferred routing capability | [#53](https://github.com/giantsoup/spacetraders/issues/53) | [#54](https://github.com/giantsoup/spacetraders/pull/54) |
| EXP03: Validate and implement cargo-based refueling safely | deferred refueling capability | [#55](https://github.com/giantsoup/spacetraders/issues/55) | [#56](https://github.com/giantsoup/spacetraders/pull/56) |
| EXP04: Execute approved fuel-stop itineraries through the existing runner | deferred routing capability | [#57](https://github.com/giantsoup/spacetraders/issues/57) | [#58](https://github.com/giantsoup/spacetraders/pull/58) |
| EXP05: Add purpose-bounded multi-market scouting tours | deferred scouting capability | [#59](https://github.com/giantsoup/spacetraders/issues/59) | [#60](https://github.com/giantsoup/spacetraders/pull/60) |
| EXP06: Evaluate probe placement and require a separate investment approval | conditional investment | [#61](https://github.com/giantsoup/spacetraders/issues/61) | [#62](https://github.com/giantsoup/spacetraders/pull/62) |
| EXP07: Evaluate and orchestrate a dedicated surveyor only when justified | conditional fleet capability | [#63](https://github.com/giantsoup/spacetraders/issues/63) | [#64](https://github.com/giantsoup/spacetraders/pull/64) |
| EXP08: Evaluate and orchestrate miner-hauler capacity and buyer throughput | conditional fleet capability | [#65](https://github.com/giantsoup/spacetraders/issues/65) | [#66](https://github.com/giantsoup/spacetraders/pull/66) |
| EXP09: Integrate comparable complete-cycle economics and conservative forecasts | partially implemented R07 | [#67](https://github.com/giantsoup/spacetraders/issues/67) | [#68](https://github.com/giantsoup/spacetraders/pull/68) |
| EXP10: Assess refining and production support as separate investments | conditional strategy research | [#69](https://github.com/giantsoup/spacetraders/issues/69) | [#70](https://github.com/giantsoup/spacetraders/pull/70) |
| EXP11: Assess wider exploration only after verified local opportunity limits | conditional strategy research | [#71](https://github.com/giantsoup/spacetraders/issues/71) | [#72](https://github.com/giantsoup/spacetraders/pull/72) |
| LIVE01: Record live-local backup, restore and controlled cutover acceptance | unverified operating gate | [#73](https://github.com/giantsoup/spacetraders/issues/73) | [#74](https://github.com/giantsoup/spacetraders/pull/74) |
| LIVE02: Rehearse real macOS sleep and wake with an owned offline engine | unverified native acceptance | [#75](https://github.com/giantsoup/spacetraders/issues/75) | [#76](https://github.com/giantsoup/spacetraders/pull/76) |
| LIVE03: Record bounded live trip and comparable mining, survey and trade evidence | unverified gameplay acceptance | [#77](https://github.com/giantsoup/spacetraders/issues/77) | [#78](https://github.com/giantsoup/spacetraders/pull/78) |
| OPT01: Batch reset-scoped fuel-market listing queries | optional O2 optimization | [#79](https://github.com/giantsoup/spacetraders/issues/79) | [#80](https://github.com/giantsoup/spacetraders/pull/80) |
| OPT02: Remove owned temporary verification fixtures after successful runs | optional O3 cleanup | [#81](https://github.com/giantsoup/spacetraders/issues/81) | [#82](https://github.com/giantsoup/spacetraders/pull/82) |
| OPT03: Measure and reduce redundant upstream reads without stale preflight | optional O4 optimization | [#83](https://github.com/giantsoup/spacetraders/issues/83) | [#84](https://github.com/giantsoup/spacetraders/pull/84) |

## Re-run the tracking check

The task manifest records exact sources, evidence, acceptance criteria, dependencies, issue/PR identities and expected file lists. Run the read-only validator from the repository root:

```bash
python3 scripts/verify-plan-tracking.py
python3 scripts/verify-plan-tracking.py --github
```

The GitHub check re-reads every issue, draft flag, base/head/SHA, cross-link and changed file set. DOC01 omits its own head SHA in the committed manifest because a commit cannot contain its own hash; the external publication ledger verifies that head too. It makes no GitHub mutations. New implementation work will legitimately require refreshing the manifest and expected file lists. A tracking check cannot re-audit feature completion.

## Independent review

A requested Sonnet 5.5 review was attempted but did not run because the Claude API returned a rate limit. No substitute independent review model was used. Two scoped source-research passes informed the audit, and the parent checked their artifacts. The publication ledger and read-only validator record post-publication readback. Independent final review remains unavailable; no source implementation or production acceptance is implied.
