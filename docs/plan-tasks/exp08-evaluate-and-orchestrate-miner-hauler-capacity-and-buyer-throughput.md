# Evaluate and orchestrate miner-hauler capacity and buyer throughput

Plan audit task **EXP08**. Status: conditional fleet capability. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:94](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L94)
- [app/Gameplay/CycleAccounting.php:35](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/CycleAccounting.php#L35)
- [app/Gameplay/CommandRules.php:176](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/CommandRules.php#L176)

## Verified gap

- Manual cargo transfer validates both ships and capacities. No multi-ship hauling run, production caller of fleetInvestment or complete incremental-capital planner exists.

## Acceptance

- [ ] Measure output during the complete hauling round trip, transfer/queue waits, free capacity, buyer batching and empty return.
- [ ] Compare net fleet and ship-hour income and best existing alternative before recommending extra capital.
- [ ] Only after justified, implement bounded coordination through shared intents with protected cargo and both ship states, Stop/timeout/reset recovery.
- [ ] Test saturated buyer, slow hauler, full holds and cross-ship spending contention.

## Dependencies

EXP09, LIVE03

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
