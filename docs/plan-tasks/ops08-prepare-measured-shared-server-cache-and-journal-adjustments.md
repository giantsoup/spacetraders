# Prepare measured shared-server cache and journal adjustments

Plan audit task **OPS08**. Status: conditional shared-host work. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- `docs/server-deployment-plan.md:87`, reviewed local plan published with DOC01.
- `docs/server-deployment-plan.md:136`, reviewed local plan published with DOC01.

## Verified gap

- The report contains historical capacity observations and tuning proposals, not proof those changes were applied.

## Acceptance

- [ ] Reinspect current capacity, MyISAM tables/import requirements and config rollback before proposing key_buffer_size=8 MiB. Leave Aria/InnoDB unchanged.
- [ ] Prepare journal retention of 14 days capped at 512 MiB and separate bounded application-log rotation.
- [ ] Apply each approved change separately, drain dependent workloads if required, smoke-test existing apps and measure actual memory savings without assuming allocated cache equals recovered RAM.

## Dependencies

OPS06

## Execution boundary

Prepare and verify proposals first. Shared database/service changes require current execution authorization; no new resources or broad tuning.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
