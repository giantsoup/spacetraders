# Record bounded live trip and comparable mining, survey and trade evidence

Plan audit task **LIVE03**. Status: unverified gameplay acceptance. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/early-game-mvp-plan.md:201](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/early-game-mvp-plan.md#L201)
- [docs/exploration-fuel-and-opportunity-recommendations.md:89](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L89)
- [docs/exploration-fuel-and-opportunity-recommendations.md:98](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L98)
- [docs/exploration-implementation.md:113](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-implementation.md#L113)

## Verified gap

- Synthetic loops and telemetry exist, but reports do not establish actual first-trip sale/refuel outcome or improved live net returns from fuller loads, surveys or trading.

## Acceptance

- [ ] With a freshly synchronized owned ship, operator-chosen reserve/spend/loss/time limits and explicit live authority, complete one previewed mining trip and stop.
- [ ] Record observed start/end credits, cargo, position/tank fuel, receipts, costs, deadlines, API version, failures/retained cargo and stop behavior.
- [ ] Run separately approved matched calibration/load/survey/trade cases only when prerequisites and budgets permit. Include unsuccessful cycles and unknown wear.
- [ ] Compare comparable complete cycles and retain conservative defaults unless measurements justify a change. Do not claim fixtures or cash contribution prove economic profit.

## Dependencies

LIVE01, EXP09

## Execution boundary

Every live mutation and numeric experiment budget requires explicit approval. No agent registration, purchase, paid trial or autonomous spending during this audit.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
