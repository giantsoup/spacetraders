# Evaluate probe placement and require a separate investment approval

Plan audit task **EXP06**. Status: conditional investment. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:58](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L58)
- [docs/exploration-implementation.md:97](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-implementation.md#L97)

## Verified gap

- No reset-horizon probe economics or approved placement planner exists. Current buy_ship is restricted to a one-time complete miner setup.

## Acceptance

- [ ] Compare observed probe purchase/deployment costs with avoided scouting and decision-changing quote freshness over the remaining reset.
- [ ] Retain unknown benefit as unknown; compare the best use of existing ships and available capital.
- [ ] Only after measured benefit passes, design a separate bounded purchase/placement approval with uncertainty recovery. Do not repurpose miner-setup approval.

## Dependencies

EXP09, LIVE03

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
