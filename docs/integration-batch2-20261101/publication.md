# Batch-two publication handoff

Publication was authorized after the completed local integration. This handoff supplements the dated [local acceptance report](README.md). That report's local-only limits describe the original verification run; the new authorization permits pushing this integration branch and opening a ready-for-review PR.

## Refreshed base and source

Fetched main is `ddbc1e013a8f3252c7e60d9161816bde38b1468e`. Its changes since the original inspected `d16e9fe` base contain only four independent task briefs for EXP02, EXP03, EXP04, and OPT03. Those briefs were merged without conflicts in `5c0e6c03c47391087cc04bdcf21f7639c8c1ced7`.

Application code, routes, configuration, migrations, tests, scripts, dependency locks, and production asset bytes remain unchanged from the completed local integration at `55c7fd4c2f091dff8ada13df3b00e9167a9b8c40`. No implementation from the independent follow-on tasks was added. Protected implementation branch tips and the original dated reports remain unchanged.

## Refreshed verification

The refreshed integration passed `python3 scripts/verify-batch2.py --only verify` in 37.78 seconds with the existing owned locked installation. It passed 391 backend tests and 2,244 assertions, Pint, frontend checks, environment probes, 18 lifecycle cases, and two deterministic builds. The [sanitized full output](evidence/publication-verify.log) is retained.

The cross-feature browser journey passed again in 4.96 seconds after the builds completed. It exercised private login, real CSRF, protected reserves, deployment admission, queue pickup, adviser review, partial scanning, chart accounting, policy pause, and dark mobile presentation. Browser and server shutdown were verified and the successful owned fixture was removed. See the [refreshed browser result](evidence/publication-combined-results.json) and [publication summary](evidence/publication-summary.json).

The prior complete 16-check acceptance run remains applicable to the unchanged application bytes. That run includes both real worker suites, ten browser and lifecycle journeys, actual deployment reservation races, measured stale-lock recovery, and the real ten-minute abort. Current production assets still match every committed hash in [assets.json](assets.json).

## PR scope and merge review

The new PR targets `main` and delivers the six completed implementations for #9, #11, #37, #51, #67, and #81. The merged preparation PRs #10, #12, #38, #52, #68, and #82 cannot be updated to deliver this code. Issue acceptance stays open for review rather than being closed automatically by the PR body.

The PR includes an additive adviser-policy migration. Existing deployments must rebuild cached routes and configuration under their separately approved deployment process. A rollback must preserve durable policy and decision history. The deployment protocol requires compatible worker and scheduler participants; pre-protocol processes cannot supply readiness acknowledgements.

Local acceptance does not prove remote CI success or reviewer approval. Those are determined from the new PR's checks and reviews. No independent model review was run, and no agents or threads were started. Adviser model calls and policy execution remain disabled. Actual upstream scan range, cooldowns, chart rewards, and authenticated live acceptance remain unverified. Publication does not deploy the app or authorize live gameplay.
