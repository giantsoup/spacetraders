# Add bounded scan and chart commands for hidden-trait evidence

Plan audit task **EXP01**. Status: deferred exploration capability. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:21](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L21)
- [docs/exploration-fuel-and-opportunity-recommendations.md:58](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L58)
- [app/Gameplay/SpaceTradersApi.php:1](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/SpaceTradersApi.php#L1)

## Verified gap

- Listing/scan/chart observations are distinguished in storage, but named scan/chart commands and purpose-bounded scan discovery are absent.

## Acceptance

- [ ] Verify current endpoint/equipment/state contracts and add explicit owned previews/approvals through the shared durable executor.
- [ ] Keep listing, scan and chart evidence distinct and reset-scoped; preserve unknown traits until observed.
- [ ] Require a decision-changing question, bounded API/cooldown/travel/spend use and safe continuation.
- [ ] Test partial coverage, capability failures, uncertain writes, Stop and reset; never claim a full catalogue reveals hidden traits.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
