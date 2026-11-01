# Build the iPhone client against the shared Laravel engine

Plan audit task **MOB03**. Status: unimplemented mobile app. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/ios-app-plan.md:62](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/ios-app-plan.md#L62)
- [docs/design-brief.md:5](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/design-brief.md#L5)

## Verified gap

- No Xcode/Swift/mobile application project exists in the source tree. Web prototypes do not implement the iPhone app.

## Acceptance

- [ ] Implement secure engine connection, agent/ship choice, observed fleet/cargo/credits/travel/cooldown/activity and explicit manual/automation previews and controls.
- [ ] Use dark readable touch controls, clear freshness/health and truthful request/command states; gameplay logic remains server-side.
- [ ] Persist safe connection/request identity and recover after suspension/offline without replaying writes. Keep UTC in exchange/storage and show phone-local time.
- [ ] Demonstrate the complete first workflow on a physical iPhone against an owned engine fixture before live use.

## Dependencies

MOB01, MOB02

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
