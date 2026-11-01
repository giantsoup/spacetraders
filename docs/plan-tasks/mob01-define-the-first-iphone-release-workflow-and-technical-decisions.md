# Define the first iPhone release workflow and technical decisions

Plan audit task **MOB01**. Status: unimplemented mobile design. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/ios-app-plan.md:36](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/ios-app-plan.md#L36)
- [docs/ios-app-plan.md:83](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/ios-app-plan.md#L83)

## Verified gap

- The iPhone plan explicitly leaves framework, auth/API, iOS minimum, navigation, repository layout and release tooling open. There is no mobile project.

## Acceptance

- [ ] Review current capabilities and choose one smallest complete manual-plus-automation workflow, including blocked and expired work.
- [ ] Record framework/minimum iOS, endpoints/auth/revocation, refresh/compatibility, navigation, cached-state behavior, repository/signing/release choices.
- [ ] Keep gameplay rules and credentials in Laravel and preserve current run limits; decisions do not authorize deployment or purchases.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
