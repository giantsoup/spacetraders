# Record live-local backup, restore and controlled cutover acceptance

Plan audit task **LIVE01**. Status: unverified operating gate. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/early-game-mvp-plan.md:201](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/early-game-mvp-plan.md#L201)
- [docs/local-automation-implementation-plan.md:265](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/local-automation-implementation-plan.md#L265)
- [docs/exploration-implementation.md:91](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-implementation.md#L91)

## Verified gap

- Reports establish disposable migrations/conversion/recovery and explicitly defer the selected real environment cutover. This audit did not inspect or modify the live database.

## Acceptance

- [ ] Identify actual environment/active work read-only; retain matching key, verified backup and disposable restore/decryption proof before changing anything.
- [ ] Under explicit cutover authority, stop and settle old work, convert plaintext credentials if present, apply required additive migrations and configure the owned engine.
- [ ] Invalidate old approvals, retain uncertain history and require fresh previews; verify rollback with compatible code/database without implying restored DB reverses upstream actions.

## Dependencies

QA01

## Execution boundary

Requires explicit selected-environment cutover authorization. No live database, APP_KEY, credential or service change during audit.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
