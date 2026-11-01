# Validate and implement cargo-based refueling safely

Plan audit task **EXP03**. Status: deferred refueling capability. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:38](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L38)
- [docs/exploration-fuel-and-opportunity-research.md:124](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-research.md#L124)
- [app/Http/Requests/GameplayRequest.php:34](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Http/Requests/GameplayRequest.php#L34)

## Verified gap

- The current command boundary supports tank-unit market refueling, not fromCargo refueling or its validated location/state exceptions.

## Acceptance

- [ ] Verify current cargo-refuel endpoint/state rules and tank-versus-cargo rounding from primary contracts and separately approved evidence.
- [ ] Add explicit from-cargo preview, protected cargo allocation, remaining tank/cargo bounds and safe continuation.
- [ ] Use the existing intent/uncertainty/accounting path; test exact conversion, insufficient cargo, capacity, state restrictions, duplicate approvals and lost responses.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
