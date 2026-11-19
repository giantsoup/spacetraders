# Integrate the OpenAI adviser in observation mode and evaluate its value

Plan audit task **ADV04**. Status: unimplemented model integration. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/openai-automation-adviser-plan.md:113](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L113)
- [docs/openai-automation-adviser-plan.md:128](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L128)
- [docs/openai-automation-adviser-plan.md:226](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L226)

## Verified gap

- No OpenAI transport, strict answer parser, sanitized packet, model-attempt history or observation-mode comparison exists. The suggested model/access/pricing in the plan must be reverified at implementation.

## Acceptance

- [ ] Build an allowlisted immutable evidence packet with versions, timestamps, observation references, candidates and baseline selection; exclude credentials and arbitrary logs.
- [ ] Use a narrow server-side adapter with current supported strict schema, store=false, bounded tokens and choose/abstain/request_refresh results; validate IDs, hashes and references locally.
- [ ] Handle refusal, incomplete output, timeout, invalid schema, unavailable model/project and provider failure without executing gameplay. Use a separate queue and no held gameplay lock during model latency.
- [ ] Evaluate synthetic/sanitized unsafe and deliberately misranked cases plus baseline-hidden cases, cost/latency/abstention and unsupported claims. Any paid experiment needs explicit authority and ADV03.

## Dependencies

ADV01, ADV02, ADV03

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
