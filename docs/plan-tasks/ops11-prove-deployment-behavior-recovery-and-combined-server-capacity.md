# Prove deployment behavior, recovery and combined server capacity

Plan audit task **OPS11**. Status: unverified live acceptance. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- `docs/server-deployment-plan.md:106`, reviewed local plan published with DOC01.
- `docs/server-deployment-plan.md:136`, reviewed local plan published with DOC01.
- [docs/ios-app-plan.md:50](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/ios-app-plan.md#L50)

## Verified gap

- The 961 MiB/350 MiB historical capacity snapshot is not added-runtime PSS, combined-load acceptance or deployment/recovery evidence.

## Acceptance

- [ ] Prove passing-main release delivery and failing-test exclusion; exercise drain, pending-job preservation, health rollback and interrupted recovery in synthetic isolated failure drills.
- [ ] Verify current release/SHA, process supervision, backup restore and independent operation with a disconnected client.
- [ ] Observe representative ordinary combined workload with at least 150 MiB available memory, no sustained swap churn/OOM, prompt scheduling and one-minute email pickup.
- [ ] Record full process-tree/shared-memory measurements and unresolved limits. Do not conduct destructive production fault injection or load testing without separate authorization.

## Dependencies

OPS10

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
