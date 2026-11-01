# Execute approved fuel-stop itineraries through the existing runner

Plan audit task **EXP04**. Status: deferred routing capability. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:38](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L38)
- [app/Gameplay/FuelPlanner.php:109](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/FuelPlanner.php#L109)

## Verified gap

- FuelPlanner compares direct and one-stop options read-only. OperationPlanner runs one direct out-and-back destination; automatic fuel-stop detour execution is absent.

## Acceptance

- [ ] Preview exact legs/refuel stops with fresh fuel listings/prices, tank and credit state, total cost/time and recovery reserves.
- [ ] Bind itinerary hash and limits to the existing run; revalidate each transition and stop on unavailable fuel or changed bounds.
- [ ] Test outbound-safe-but-stranded, detour overhead, empty return, Stop, restart, unknown write and no repeated rescue route.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
