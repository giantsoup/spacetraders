# Prepare shared-app queue scheduling changes with email priority

Plan audit task **OPS07**. Status: conditional shared-host work. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- `docs/server-deployment-plan.md:83`, reviewed local plan published with DOC01.
- `docs/server-deployment-plan.md:151`, reviewed local plan published with DOC01.

## Verified gap

- The plan calls for portfolio and Print For Me minute-level email pickup and slower bulk drains. This repo contains no reviewed changes or current execution evidence for those apps.

## Acceptance

- [ ] Inventory actual queue/mail paths read-only before selecting minimal scheduled drains or separate priority/bulk queues.
- [ ] Create reviewed changes in each owning repository, with non-overlap locks, bounded new reservations and graceful active-job completion.
- [ ] Preserve FishCounts continuous processing, app users, durable jobs, retry/timeout contracts and existing deploy workflows.
- [ ] Measure email pickup within one minute and bulk within the agreed cadence after each separately approved shared-host change.

## Dependencies

OPS06

## Execution boundary

This is a cross-repository coordination task. Do not change other repositories or shared-host processes without explicit execution scope and current inspection.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
