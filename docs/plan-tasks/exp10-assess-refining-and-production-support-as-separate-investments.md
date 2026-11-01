# Assess refining and production support as separate investments

Plan audit task **EXP10**. Status: conditional strategy research. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:120](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L120)
- [docs/exploration-implementation.md:97](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-implementation.md#L97)

## Verified gap

- No refine command or production-support investment workflow exists; the accepted trader uses one bounded buy/sell cycle.

## Acceptance

- [ ] Compare raw-input sale value with supported refined output, equipment/cargo/cooldown requirements, fuel/time and conservative buyer capacity.
- [ ] Separate production-chain effects from private realizable returns and compare the best existing opportunity.
- [ ] Deliver a cited feasibility gate before any proposed command implementation or purchase; keep unsupported or unpriced routes blocked.

## Dependencies

EXP09, LIVE03

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
