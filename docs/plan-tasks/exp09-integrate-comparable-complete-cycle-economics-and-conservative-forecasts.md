# Integrate comparable complete-cycle economics and conservative forecasts

Plan audit task **EXP09**. Status: partially implemented R07. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:126](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L126)
- [app/Gameplay/CycleAccounting.php:7](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/CycleAccounting.php#L7)
- [app/Gameplay/AutomationRunner.php:298](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/AutomationRunner.php#L298)

## Verified gap

- CycleAccounting detects unmatched position/fuel/inventory/wear and conservatively withholds comparable net/hour. Runner passes sales/fuel/goods only; measured wear, repairs, capital and fleet count are not integrated.
- fleetInvestment has tests but no production caller. Trade has base cash/unsold downside output, but normalized net/hour fields remain null.

## Acceptance

- [ ] Integrate observed operating costs or an explicitly chosen wear allowance, normalized or clearly unresolved fuel/position/inventory and conservative reachable inventory value.
- [ ] Show base/conservative estimates and realized/unresolved outcomes separately without fabricating unknown prices/yields or confidence.
- [ ] Connect fleet capital, ship-hours, throughput and incremental payback against the best current-assets alternative.
- [ ] Exercise the actual report/evaluator/runner path with closed/open cycles, overlapping waits, unsold cargo, unmatched fuel, capital and missing wear.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
