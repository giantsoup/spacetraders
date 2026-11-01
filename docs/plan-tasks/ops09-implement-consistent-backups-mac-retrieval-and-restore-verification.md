# Implement consistent backups, Mac retrieval and restore verification

Plan audit task **OPS09**. Status: unimplemented recovery tooling. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- `docs/server-deployment-plan.md:91`, reviewed local plan published with DOC01.
- `docs/server-deployment-plan.md:143`, reviewed local plan published with DOC01.

## Verified gap

- No four-app backup manifest, consistent SQLite backup pipeline or verified manual Mac-download command exists in this repository. Historical server backups do not prove this plan implemented.

## Acceptance

- [ ] Retain existing working backup paths where suitable; add daily four-app DB sets and SpaceTraders pre-migration backups with protected credentials and consistent snapshots.
- [ ] Retain seven verified daily and three verified migration sets per app, independently on server and Mac. Prune only after successful validation; use temporary files and atomic completion.
- [ ] Provide manual restricted-SSH download with manifest/checksum, failure-safe retention and last-success reporting; no scheduled Mac job or notifications.
- [ ] Restore each format in disposable environments and test token decryption with a securely supplied matching key. Escrow/retrieve secrets separately; never activate restored gameplay.

## Dependencies

OPS06

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
