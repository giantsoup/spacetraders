# Batch reset-scoped fuel-market listing queries

Plan audit task **OPT01**. Status: optional O2 optimization. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/opus-qa-security-tasks.md:174](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/opus-qa-security-tasks.md#L174)
- [app/Gameplay/FuelPlanner.php:28](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/FuelPlanner.php#L28)

## Verified gap

- FuelPlanner::leg queries market_listing inside the fresh-market loop; OpportunityAssessor invokes it repeatedly. Accepted at current scale, unoptimized.

## Acceptance

- [x] Load bounded agent/reset-scoped listings once per report/planning scope and reuse without refreshing quote age.
- [x] Preserve fuel-seller/freshness/affordability checks and route results; demonstrate query count scaling with a multi-market fixture.
- [x] Avoid a generic repository/cache layer or cross-request stale state.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Implementation handoff

The report reuses its existing reset-scoped observation read. Standalone fuel legs, route options, operation previews and survey assessments load listings once per planning scope. Collections are local variables passed explicitly; FuelPlanner retains no request state. Quote timestamps, freshness, fuel-seller checks, credit reserves and route ordering keep their existing rules.

The owned 2/4/8-market fixture exercises cargo, mining, trade and observation routes. Extra report listing SELECTs fall from 52/552/3664 to zero; standalone options fall from 2/20/104 to one. Full report and route-option hashes match the initial PR baseline at all three sizes.

See the [verification handoff](../issue-79/verification.md), [before performance capture](../issue-79/evidence/20261101-opt01/before.png) and [after performance capture](../issue-79/evidence/20261101-opt01/after.png). Offline acceptance passes. The existing PR remains draft for Taylor's review; there is no live gameplay or deployment claim.
