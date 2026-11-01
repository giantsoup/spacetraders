# Migrate the application and verification runtime to PHP 8.5

Plan audit task **OPS01**. Status: unimplemented runtime change. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- `docs/server-deployment-plan.md:28`, reviewed local plan published with DOC01.
- [composer.json:9](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/composer.json#L9)
- [scripts/verify.sh:7](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/scripts/verify.sh#L7)

## Verified gap

- The accepted deployment plan selects PHP 8.5; current Composer requires ~8.4.1, platform is 8.4.1, and verify.sh rejects PHP 8.5.
- The historical Laravel 13/PHP 8.4 upgrade is complete. This is a new runtime requirement.

## Acceptance

- [ ] Verify the latest supported stable 8.5 patch and dependency compatibility at implementation time; pin the tested patch across Composer, CI and server preflight.
- [ ] Use a verified project-specific Herd runtime without affecting unrelated sites; support a real executable PHP_BIN wrapper where required.
- [ ] Resolve dependencies under the selected PHP without bypasses; run full verification, platform checks and affected browser/worker journeys.
- [ ] Retain tested rollback instructions and review shared-host app compatibility before any server package change.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
