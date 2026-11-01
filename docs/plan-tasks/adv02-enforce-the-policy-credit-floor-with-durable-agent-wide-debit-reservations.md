# Enforce the policy credit floor with durable agent-wide debit reservations

Plan audit task **ADV02**. Status: unimplemented adviser safeguard. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/openai-automation-adviser-plan.md:94](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L94)
- [app/Gameplay/CommandRules.php:55](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/CommandRules.php#L55)

## Verified gap

- Existing per-run reserve checks protect current approvals. There is no persisted policy-level 10,000-credit floor or outstanding worst-case debit-reservation ledger across all policy-controlled ships and uncertain actions.

## Acceptance

- [ ] Atomically require fresh confirmed balance minus unresolved maximum debits and proposed worst-case debit to meet the policy floor, with recovery costs included.
- [ ] Keep pending/uncertain debit reservations until confirmed or reconciled; unconfirmed sales never finance purchases. Apply shared rules to manual spending while the policy is enabled.
- [ ] Test exact-floor/over-floor/below-floor, zero-debit recovery sale, two ships, changed prices, external balance change and timeout retention.
- [ ] Preserve the existing ShipAction ledger and document upstream/external-client limitations accurately.

## Dependencies

ADV01

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
