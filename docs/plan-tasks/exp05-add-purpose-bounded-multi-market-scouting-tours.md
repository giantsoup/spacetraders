# Add purpose-bounded multi-market scouting tours

Plan audit task **EXP05**. Status: deferred scouting capability. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:58](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L58)
- [app/Gameplay/OperationPlanner.php:1](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/OperationPlanner.php#L1)

## Verified gap

- Scouting supports one market/question and a direct home return. Clustered multi-market tours and re-ranking after each useful observation are not implemented.

## Acceptance

- [ ] Generate a route from named missing facts and affected opportunities, with visit, API, fuel, spending and duration limits.
- [ ] Re-evaluate after each observation and stop once answered or exhausted; preserve a fuel-safe continuation and separately bounded rerouting.
- [ ] Disclose unknown information value/displaced earnings; no automatic all-waypoint scan.
- [ ] Test missing prices, early completion, partial discovery, updated quotes, Stop and uncertain travel.

## Dependencies

EXP04

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
