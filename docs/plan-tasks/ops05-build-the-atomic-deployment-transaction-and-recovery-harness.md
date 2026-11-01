# Build the atomic deployment transaction and recovery harness

Plan audit task **OPS05**. Status: unimplemented deployment tooling. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- `docs/server-deployment-plan.md:69`, reviewed local plan published with DOC01.
- `docs/server-deployment-plan.md:124`, reviewed local plan published with DOC01.
- `docs/server-deployment-plan.md:134`, reviewed local plan published with DOC01.

## Verified gap

- No release receiver, immutable release switch, deployment transaction, rollback or failure-injection harness exists.

## Acceptance

- [ ] Implement an SSH-disconnect-safe serialized state machine with monotonic sequence, SHA/checksum validation, newest-eligible-main selection and idempotent recovery.
- [ ] Preflight upstream read-only availability; stage before outage, drain, quiesce, generate release caches, verify consistent pre-migration backup, apply backward-compatible migrations and atomically switch.
- [ ] Require HTTPS/SHA/database and worker/scheduler readiness before reopening. Roll back code/processes on failure; never auto-restore DB or reverse migrations.
- [ ] Fault-inject all plan stages, rollback failure and interrupted connections; test adjacent retained-release job/session/cache/schema compatibility and actual process signals.

## Dependencies

OPS03, OPS04, OPS06, OPS09

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
