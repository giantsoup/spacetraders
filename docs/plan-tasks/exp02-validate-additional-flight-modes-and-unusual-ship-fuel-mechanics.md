# Validate additional flight modes and unusual-ship fuel mechanics

Plan audit task **EXP02**. Status: deferred routing capability. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:38](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L38)
- [app/Gameplay/FuelPlanner.php:13](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/FuelPlanner.php#L13)
- [app/Gameplay/CommandRules.php:115](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/CommandRules.php#L115)

## Verified gap

- FuelPlanner rejects non-CRUISE and zero-capacity ships. Community formulas are provenance-tagged estimates, not validated general server mechanics.

## Acceptance

- [ ] Refresh primary API contracts and define supported mode/configuration combinations, including explicit unknown/unsupported behavior for zero-capacity ships.
- [ ] Collect separately approved calibration before enabling a model; preserve exact reset/equipment/directed-edge provenance.
- [ ] Add controlled mode-change/navigation support with continuation, reserve and elapsed-time checks through existing approvals.
- [ ] Test stranding, rescue/replan loops, consumption discrepancy and unsupported ships; keep conservative CRUISE as default until justified.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
