# Prepare restricted Linux runtime and deployment configuration

Plan audit task **OPS06**. Status: unimplemented server configuration. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- `docs/server-deployment-plan.md:40`, reviewed local plan published with DOC01.
- `docs/server-deployment-plan.md:124`, reviewed local plan published with DOC01.
- [app/Gameplay/LocalEngineManager.php:14](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/LocalEngineManager.php#L14)
- [config/gameplay.php:7](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/config/gameplay.php#L7)

## Verified gap

- The installed engine manager is launchd-specific and its UI endpoints require loopback/local host. It does not establish a remote supervised engine contract.
- No versioned Nginx/FPM/Supervisor/sudoers templates, forced-command receiver or root-owned deploy helper exists.

## Acceptance

- [ ] Prepare reviewable dedicated-user, immutable releases/current/shared, Nginx realpath, FPM socket and single-worker/scheduler templates.
- [ ] Define remote engine health/control behavior through existing gameplay services without opening local launchd controls to remote users.
- [ ] Restrict artifact receiver paths, archive traversal, size, checksum, protocol, sequence and command execution. Keep root-owned deploy tooling outside mutable artifacts.
- [ ] Specify persistent APP_KEY, environment, lock/budget paths and file-cache ownership; start automation disabled. Rehearse isolated process-tree recycling and clean stop.
- [ ] Document installation and rollback; applying templates to the shared host is a later authorized operation.

## Dependencies

OPS01, OPS03

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
