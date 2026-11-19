# Add policy-authorized one-time decision execution and trigger recovery

Plan audit task **ADV05**. Status: unimplemented automated decision lifecycle. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/openai-automation-adviser-plan.md:156](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L156)
- [docs/openai-automation-adviser-plan.md:177](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L177)
- [docs/openai-automation-adviser-plan.md:228](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L228)

## Verified gap

- No durable adviser decision service, semantic trigger deduplication, agreement gate or unique decision-to-run handoff exists. Existing deterministic runners must remain the only gameplay execution path.

## Acceptance

- [ ] Persist collecting/evaluating/model/review/approved/consumed/expired/cancelled/failed lifecycle with semantic fingerprints and bounded refresh.
- [ ] Require exact candidate/hash agreement, enabled unexpired policy and fresh reset/ship/cargo/credits/price/fuel/ownership checks at automatic start.
- [ ] Atomically claim one decision and hand off to existing preview/executor; recover duplicate jobs and crashes without duplicate writes.
- [ ] Test late replies, Stop/policy disable/restart/reset, disagreement, unknown outcomes and active-run ownership. Neither model agreement nor a new decision clears reconciliation blocks.

## Dependencies

ADV02, ADV03, ADV04

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
