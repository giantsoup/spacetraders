# Batch reset-scoped fuel-market listing queries

Plan audit task **OPT01**. Status: optional O2 optimization. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/opus-qa-security-tasks.md:174](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/opus-qa-security-tasks.md#L174)
- [app/Gameplay/FuelPlanner.php:28](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/FuelPlanner.php#L28)

## Verified gap

- FuelPlanner::leg queries market_listing inside the fresh-market loop; OpportunityAssessor invokes it repeatedly. Accepted at current scale, unoptimized.

## Acceptance

- [ ] Load bounded agent/reset-scoped listings once per report/planning scope and reuse without refreshing quote age.
- [ ] Preserve fuel-seller/freshness/affordability checks and route results; demonstrate query count scaling with a multi-market fixture.
- [ ] Avoid a generic repository/cache layer or cross-request stale state.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
