# Assess wider exploration only after verified local opportunity limits

Plan audit task **EXP11**. Status: conditional strategy research. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-research.md:339](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-research.md#L339)
- [docs/exploration-implementation.md:97](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-implementation.md#L97)
- [docs/early-game-mvp-plan.md:226](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/early-game-mvp-plan.md#L226)

## Verified gap

- Commands and planners restrict navigation to one system. Wider exploration and intersystem economics are conditional recommendations rather than supported execution.

## Acceptance

- [ ] Compare poor verified local opportunities against a specifically observed adjacent-system question, gate connections, access, fuel, jump/cooldown costs and recovery.
- [ ] Record route mechanics, complete exploration cost and uncertainty; do not widen scope simply because local prices are missing.
- [ ] Deliver a bounded future design with separate approvals and shared execution controls before enabling intersystem actions.

## Dependencies

EXP09, LIVE03

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
