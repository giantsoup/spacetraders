# Evaluate and orchestrate a dedicated surveyor only when justified

Plan audit task **EXP07**. Status: conditional fleet capability. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:94](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L94)
- [app/Gameplay/SurveyPool.php:1](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/SurveyPool.php#L1)

## Verified gap

- Signed survey creation/use, expiry and exhaustion are implemented. A dedicated surveyor scheduling and incremental-cost/payback flow is absent.

## Acceptance

- [ ] Compare measured surveyed/unsurveyed complete cycles including creation cooldown, expiry, yield mix, sale proceeds and acquisition/positioning costs.
- [ ] After evidence supports the role, coordinate a separately approved surveyor with shared exhaustion and request budgets without treating deposit duplicates as probabilities.
- [ ] Preserve ownership, one-agent credit controls, Stop and unknown outcomes; keep purchase permission separate.

## Dependencies

EXP09, LIVE03

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
