# Add versioned adviser policy grants and deterministic activity selection

Plan audit task **ADV01**. Status: partially implemented foundation. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/openai-automation-adviser-plan.md:17](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L17)
- [docs/openai-automation-adviser-plan.md:46](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L46)
- [docs/openai-automation-adviser-plan.md:75](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L75)

## Verified gap

- OpportunityInvestigator and OpportunityEvaluator already support bounded Mining/Contracts candidates. The cross-activity adviser policy, immutable decision candidates, exploration allowance and deterministic selection history do not exist.

## Acceptance

- [ ] Reuse current discovery/evaluation; add versioned expiring policy grants for one ship and permitted cargo/system/activity bounds.
- [ ] Represent feasible, missing-information, blocked and unsupported options, with stable IDs/hashes and named exploration questions. Include wait/read/refuel/sale/scout/mining where supported.
- [ ] Implement reproducible income/exploration preference, capital/time tie breaks, no fabricated yields, and honest base/conservative inputs.
- [ ] Expose dark policy/decision history with local display, UTC deadlines, separate Pause automatic decisions and Stop current activity. Record unresolved tuning choices before enabling.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
