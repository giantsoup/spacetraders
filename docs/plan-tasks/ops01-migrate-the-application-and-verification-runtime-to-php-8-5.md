# Migrate the application and verification runtime to PHP 8.5

Plan audit task **OPS01**. Status: implemented and verified offline on PHP 8.5.12. The designated PR remains draft pending the recorded gates. Original audit source `221fbae7a1ecbdf5159211316039f016355c900e`, remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb`, November 1, 2026.

## Source requirements

- `docs/server-deployment-plan.md:28`, reviewed local plan published with DOC01.
- [composer.json:9](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/composer.json#L9)
- [scripts/verify.sh:7](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/scripts/verify.sh#L7)

## Verified gap

- The accepted deployment plan selects PHP 8.5; current Composer requires ~8.4.1, platform is 8.4.1, and verify.sh rejects PHP 8.5.
- The historical Laravel 13/PHP 8.4 upgrade is complete. This is a new runtime requirement.

## Acceptance

- [x] Verify the latest supported stable 8.5 patch and dependency compatibility at implementation time; pin the tested patch across Composer, CI and server preflight. Selected PHP 8.5.12, verified against php.net and the signed installed Herd executable.
- [x] Use a verified project-specific Herd runtime without affecting unrelated sites; support a real executable PHP_BIN wrapper where required. Global Herd remains PHP 8.4.
- [x] Resolve dependencies under the selected PHP without bypasses; run full verification, platform checks and affected browser/worker journeys. All Composer package records are unchanged. Full suite, browser and worker results are recorded below; QA01's separate repeated failure remains reproducible on both runtimes.
- [ ] Retain tested rollback instructions and review shared-host app compatibility before any server package change. Application rollback is tested with independently installed baseline dependencies. Actual shared-host compatibility and package/configuration rollback remain an explicit operational gate; no server change is authorized by this task.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Implementation handoff

See the [runtime and rollback runbook](../issue-7/php85-runtime.md) and [captured evidence](../issue-7/evidence/2026-11-01-ops01/). Full PHP 8.4 and PHP 8.5 runs pass 293 tests and 1,528 assertions. Both runtimes also reproduce the separate QA01 timestamp assertion failure under repetition. Hosted CI, shared-host compatibility and server restoration must be assessed independently. This is not evidence of deployment, live gameplay or shared-site cutover.
