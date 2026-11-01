# Define the first iPhone release workflow and technical decisions

Plan audit task **MOB01**. Status: design completed for Taylor's review; mobile implementation remains unimplemented. Inspected designated PR baseline `f58177bab2cba7d5af69c8443fb6c72f26caa2ae` on 2026-11-01. Original audit used local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb`.

## Source requirements

- [docs/ios-app-plan.md:36](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/ios-app-plan.md#L36)
- [docs/ios-app-plan.md:83](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/ios-app-plan.md#L83)

## Baseline gap and result

- The iPhone plan explicitly leaves framework, auth/API, iOS minimum, navigation, repository layout and release tooling open. There is no mobile project.
- [The first release decision](../adr/0001-first-iphone-release.md) now selects one manual-plus-mining workflow and those technical choices, with source links, recovery cases, and downstream acceptance gates. The [plan](../ios-app-plan.md) reflects the decision. No mobile project is added.

## Acceptance

- [x] Review current capabilities and choose one smallest complete manual-plus-automation workflow, including blocked and expired work. See the decision's capability review, workflow, and recovery matrix.
- [x] Record framework/minimum iOS, endpoints/auth/revocation, refresh/compatibility, navigation, cached-state behavior, repository/signing/release choices. See the decision's client, authentication, API, refresh, and release sections.
- [x] Keep gameplay rules and credentials in Laravel and preserve current run limits; decisions do not authorize deployment or purchases. See the bounds and execution gates.

These checks record design coverage. Taylor's acceptance remains pending; they do not certify mobile runtime behavior.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Review handoff

Existing designated [PR #30](https://github.com/giantsoup/spacetraders/pull/30) remains draft for review. [Verification and rendered before/after document evidence](../issue-29/README.md) describe what was actually checked. MOB02, MOB03, and MOB04 own API, client, and physical-phone/TestFlight delivery. Their declared prerequisites still apply. This design does not certify deployment, live gameplay, paid API access, or external delivery.
