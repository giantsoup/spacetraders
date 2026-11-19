# MACSOUP reconnaissance implementation

Taylor requested repeated exploration of connected systems until a promising market is found. This change implements a separate mission that lends MACSOUP-2 without replacing C0's grant or moving MACSOUP-1. It defaults off and has not been deployed or activated.

## Behavior

The probe explores a graph of complete gates with verified links in both directions. There is no depth or system-count cutoff. It samples markets, evaluates them through the same `CampaignTrade::projectPair` economics as C0, and confirms a promising pair with later observations. Incomplete sampling or missing route evidence remains unresolved, rather than becoming a negative market result.

Default exploration authority preserves 100,000 CR and fixes the envelope at approval-time credits minus that amount. The saved 174,781 CR state therefore gives 74,781 CR. Each jump uses a 7,500 CR local exposure, with every homeward hop reserved before exploration. Server fees can exceed local caps. Actual receipts determine spend; an overcharge stops recon for review. Rescue approvals are explicit, append-only and homeward-only.

Every spending guard and C0 lot sizing protects the sum of remaining authorized return caps, including higher rescue caps. That protection survives a stopped run or disabled flag. Recon's own return jump uses the post-hop reserve, so it can spend the funds reserved for returning. C0 can continue trading with its own float.

The module-less jump exception applies only to the mission's approved fuel-free probe, equipment, reset and planned gate link. Manual and other strategies retain their drive guards. A known capability rejection at home closes the trial. Uncertain writes are never replayed. Trusted executor and engine-invalidation markers distinguish provably unsent refusals; these require explicit fresh-run resume under the same mission ledger.

Qualification is provisional: a feasible first proof load, a conditional repeat margin of at least 2,000 CR and score of at least 250 CR/min, followed by confirmation reads. Reports retain quote ages, sampling gaps, costs and cooldowns. They do not claim executable prices, live earnings or that the trader can relocate.

## Verification

- Focused mission, lifecycle, integration, projection and fuel suites: **94 tests, 685 assertions** after review fixes.
- Zero-return-exposure ranking was characterized against `bd5d0a0` for normal, long-run and claimed cases.
- Coordinator full suite: **1,357 tests, 8,820 assertions, 19 skipped, no failures**.
- Changed-file Pint: all 25 changed PHP files pass. `git diff --check` passes.
- Standalone real-worker lost-response checks: all 15 foundation commands pass, including jump and navigate. A valid post-receipt module-install deferral also passes; retained responses survive duplicate delivery and reconciliation without replay.
- Protected copied-state replay passed with the clock fixed at 2026-11-19 05:15:04Z. It verifies the 100,000 floor, 74,781 envelope, four complete first-ring neighbors, exclusion of the construction gate, matching dry-run/approval terms, hidden lent probe, unchanged C0 grant/revision/episode and run 44, and refusal to take over the active scout/transit. No game action was created.

The copied replay uses saved gate evidence and a deliberately bounded gate-only catalogue fixture. It does not establish full destination market coverage or fresh destination prices. Private SQLite and credentials remain outside the repository. Synthetic credentials replace the copied credential inside a rolled-back transaction; real credentials are never decrypted or used by the replay.

## Review and activation

Planning took four rounds across two authorized batches:

| Member | Round 1 | Round 2 | Round 3 | Round 4 |
| --- | --- | --- | --- | --- |
| Opus | CHANGES REQUIRED | CHANGES REQUIRED | CHANGES REQUIRED | APPROVE |
| Sol | CHANGES REQUIRED | CHANGES REQUIRED | APPROVE | APPROVE |
| Muse | CHANGES REQUIRED | APPROVE | APPROVE | APPROVE |

Required planning corrections covered lifecycle initialization, settlement ownership, reserves, cash waiting, unresolved evidence, rescue funds, never-sent proof and engine invalidation. No reviewer gave a score for planning.

Implementation council approved the final version unanimously in three rounds:

| Member | Round 1 | Round 2 | Round 3 |
| --- | --- | --- | --- |
| Opus | CHANGES REQUIRED, 7/10 | CHANGES REQUIRED, 8/10 | APPROVE, 9/10 |
| Sol | CHANGES REQUIRED, 8/10 | CHANGES REQUIRED, 8/10 | APPROVE, 9/10 |
| Muse | APPROVE, 9/10 | APPROVE, 9/10 | APPROVE, 9/10 |

All required findings are resolved. The fixes preserve scheduled gate retries, keep stale negative evidence refreshable, and allow an acknowledged uncertain jump to return under a later explicit approval without inventing a receipt. Ordinary temporary-cash resumes use the remaining fixed envelope, while overcharge and rescue recovery remains returning-only. Closure no longer falsely claims frontier exhaustion; expired cash continuations close as time_exhausted. Each correction has a regression observed failing before the fix and passing afterward.

All three final reviewers independently passed the five focused suites, 94 tests and 685 assertions. Sol added two independent boundary checks, 25 assertions. Opus added three, 19 assertions. Muse also ran the five principal boundary regressions separately, 55 assertions. No reviewer reported a required finding in round 3. The reviewed freeze manifest is `27a35f2aa257648a64bfea4928d5e0a057093cbca6fa226e5e909e06fd769387`. After approval, only review-result metadata and the already-reviewed confirmed-home limitation below were added to documentation; PHP and tests remain byte-identical to the freeze.

The [Mac deployment procedure](../operations/mac-recon-deployment.md) requires a concrete merge/deploy/activation approval. It includes a short interruption to all Herd PHP sites, deployment admission drain, graceful End, private backup, additive migration, candidate release acknowledgements, flag-off checks, and controlled activation. End stops plain runs and C0 can create fresh plans under its existing grant. Recon needs explicit start/resume.

No merge, production edit, service change, campaign control, ship purchase, travel or live jump occurred. The approved fuel economics commit `bd5d0a0` remains the base. Current C0 operations continue under their previous authority. Runtime market yield, module-less jump acceptance and relocation viability remain unverified. A catalogue read failure can require explicit run resume. Routine price changes can renew the evidence-version retry allowance, within the fixed deadline and spending authority. Idle home holds release after their window, but the open mission must be cancelled before approving another. Status can retain an old non-blocking hold label. Recon projections conservatively subtract existing return exposure from available capital.

A confirmed-home edge case can require cancellation if End occurs after the last hop consumes the return authority but before the closing tick. Capture the report first: cancellation sets the top-level outcome to cancelled, while promising visits and receipts remain. No additional flight or funds are involved.
