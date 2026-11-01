# Implement the adviser dollar-budget reservation ledger

Plan audit task **ADV03**. Status: unimplemented paid-API safeguard. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/openai-automation-adviser-plan.md:142](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L142)
- [docs/openai-automation-adviser-plan.md:250](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L250)

## Verified gap

- There are no model-attempt, pricing, dollar-reservation or configured calendar-day budget tables/services. Game-credit accounting is a separate existing concern.

## Acceptance

- [ ] Use verified current pricing and bounded input/output/service tier to atomically reserve conservative maximum cost before every paid attempt.
- [ ] Reconcile actual usage and retain timed-out/billing-unknown reservations, including ambiguous prior-period usage. Retries reserve independently.
- [ ] Enforce $5 confirmed-plus-reserved cap, request/concurrency ceilings, and operator-confirmed Los Angeles day boundaries stored in UTC with DST tests.
- [ ] At budget exhaustion suspend new model-dependent starts without silent deterministic fallback. Unknown/unbounded pricing blocks calls.

## Dependencies

ADV01

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
