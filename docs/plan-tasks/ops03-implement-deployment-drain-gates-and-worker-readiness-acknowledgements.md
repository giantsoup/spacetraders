# Implement deployment drain gates and worker readiness acknowledgements

Plan audit task **OPS03**. Status: unimplemented deployment control. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- `docs/server-deployment-plan.md:45`, reviewed local plan published with DOC01.
- `docs/server-deployment-plan.md:114`, reviewed local plan published with DOC01.
- [app/Console/Kernel.php:17](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Console/Kernel.php#L17)
- [app/Gameplay/EngineGate.php:18](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/EngineGate.php#L18)

## Verified gap

- The current gate controls a local macOS engine session; it is not an application-wide deploy pause/drain protocol.
- The five-second dispatch schedule has no withoutOverlapping protection. Existing heartbeats do not prove every worker and web writer has drained.

## Acceptance

- [ ] Add a versioned persistent close/open/status deployment contract that gates web writes, dispatch and new queue reservations while preserving queued intent.
- [ ] Pause/resume database:spacetraders using shared persistent cache; require worker, scheduler-descendant and active-web-write acknowledgements.
- [ ] Let reserved work finish; abort and unwind at ten minutes without SIGKILL, queue clearing or uncertain-action replay.
- [ ] Add measured-expiry scheduler overlap locks and crash recovery. Test real SQLite workers, races, pending jobs, old/rollback releases and stale locks.

## Dependencies

OPS01

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
