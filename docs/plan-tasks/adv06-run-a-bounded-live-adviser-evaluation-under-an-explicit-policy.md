# Run a bounded live adviser evaluation under an explicit policy

Plan audit task **ADV06**. Status: unverified paid/live acceptance. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/openai-automation-adviser-plan.md:229](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L229)
- [docs/openai-automation-adviser-plan.md:246](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L246)

## Verified gap

- No paid API-project access, model quality, live operating-cost or adviser-controlled gameplay acceptance has been demonstrated. Coding-agent model availability does not establish API access.

## Acceptance

- [ ] Confirm project access/current pricing, exploration/spend/loss/time allowances, policy lifetime/day boundary and evidence retention before enabling.
- [ ] Start with observation-only evaluation, then separately authorize bounded live use within the protected credit floor and $5 cap.
- [ ] Record deterministic and model choices, disagreements/abstentions, confirmed receipts/costs/fuel/information gained, latency and actual usage.
- [ ] Compare equivalent cases against the deterministic-only baseline and report shared-input failures and unverified outcomes without a profitability guarantee.

## Dependencies

ADV05, LIVE03

## Execution boundary

Requires explicit paid API and live gameplay authorization under selected numeric bounds; no paid or game actions during audit or draft creation.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
