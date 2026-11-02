# Close public account routes and add secure operator provisioning

Plan audit task **OPS02**. Status: unimplemented deployment control. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- `docs/server-deployment-plan.md:36`, reviewed local plan published with DOC01.
- `docs/server-deployment-plan.md:143`, reviewed local plan published with DOC01.
- [routes/auth.php:16](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/routes/auth.php#L16)

## Verified gap

- Public registration, guest password reset and verification routes remain enabled. Existing auth tests expect these flows.
- No secure single-account provisioning/recovery Artisan command exists.

## Acceptance

- [ ] Disable direct and UI access to public registration, guest reset and email verification for private deployment. Preserve login throttling and authenticated password change.
- [ ] Provide one-time secure account creation and password recovery with no secrets in arguments, logs or artifacts.
- [ ] Use a non-delivering mail transport for this app; test direct requests, guests, foreign owners and existing sessions.

## Dependencies

OPS01

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
