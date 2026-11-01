# Add a sanitized Discord notification outbox

Plan audit task **ADV07**. Status: unimplemented later phase. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/openai-automation-adviser-plan.md:209](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L209)
- [docs/openai-automation-adviser-plan.md:230](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/openai-automation-adviser-plan.md#L230)

## Verified gap

- No Discord webhook adapter, notification outbox, delivery record or alert/digest configuration exists.

## Acceptance

- [ ] Persist decision/event-deduplicated outbox after commit, send with wait=true, retain returned message ID, disable mentions and honor rate limits.
- [ ] Include concise recommendations/reasons/protected balance/local time without credentials or full evidence packets; preserve dashboard authority.
- [ ] Handle unknown delivery and possible duplicate retry, provider outages and digest policy without blocking safe gameplay.
- [ ] Test fake webhook rate limits, unknown timeout, mentions and duplicates; verify actual delivery only after channel setup and explicit test-send authorization.

## Dependencies

ADV04

## Execution boundary

No webhook secret, external message or Discord channel change is authorized by this issue or draft.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
