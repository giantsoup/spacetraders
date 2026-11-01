# Expose a versioned authenticated mobile gameplay API

Plan audit task **MOB02**. Status: unimplemented mobile API. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/ios-app-plan.md:54](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/ios-app-plan.md#L54)
- [routes/api.php:18](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/routes/api.php#L18)
- [routes/web.php:1](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/routes/web.php#L1)

## Verified gap

- The API route file exposes only /user. Gameplay routes currently serve the Inertia/session-based web client; no complete mobile contract is implemented.

## Acceptance

- [ ] Implement the agreed authentication/device revocation and versioned read/preview/approve/status/start/stop API using existing owner-scoped services.
- [ ] Define idempotent request identities, queued versus upstream-completed status, refresh and error semantics, UTC payloads and compatibility with installed clients.
- [ ] Test device expiry/revocation, foreign users, duplicates, stale previews, uncertain writes and simultaneous web/mobile ownership; expose no SpaceTraders credential.

## Dependencies

MOB01, OPS02, OPS03

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
