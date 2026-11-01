# Provision the private Linode application and first release

Plan audit task **OPS10**. Status: unverified live deployment. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- `docs/server-deployment-plan.md:100`, reviewed local plan published with DOC01.
- [docs/ios-app-plan.md:44](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/ios-app-plan.md#L44)

## Verified gap

- Repository evidence establishes a deployment plan and a historical capacity assessment, not a deployed private SpaceTraders release. No live readiness verification was performed by this audit.

## Acceptance

- [ ] After review and explicit execution authorization, verify host identity, current capacity/DNS ownership/runtime and install restricted configs without disrupting existing apps.
- [ ] Provision DNS/TLS with renewal, protected environment/key escrow, fresh DB and one private account; copy no local gameplay/test state.
- [ ] Deploy through the same verified artifact pipeline used later; keep automation disabled until acceptance.
- [ ] Record actual deployed SHA, HTTPS/private access, persistent storage and healthy worker/scheduler readiness; preserve rollback and baseline backup.

## Dependencies

OPS02, OPS04, OPS05, OPS06, OPS07, OPS08, OPS09

## Execution boundary

Requires explicit live deployment, credential provisioning and shared-host execution authorization. Opening this draft does not deploy anything.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
