# Reconcile automation documentation and cooldown preview wording

Plan audit task **DOC01**. Status: documentation drift. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/local-automation-data.md:3](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/local-automation-data.md#L3)
- [docs/opus-qa-security-tasks.md:171](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/opus-qa-security-tasks.md#L171)
- [docs/native-ship-ui-implementation-prompt.md:23](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/native-ship-ui-implementation-prompt.md#L23)

## Verified gap

- AutomationRunner::start accepts plan versions 1, 2 and 3; the data contract incorrectly excludes version 3.
- MiningPlanner::next departs before extraction cooldown when the approved departure limit is reached, but AutomationRunner preview wording still says to wait.
- Historical unchecked T1-T6 and D3 were closed by later review rounds. Preserve those historical records and add a current status index.
- The reviewed server deployment plan is committed locally but absent from origin/main. Publish that plan as a reference without claiming deployment.

## Acceptance

- [ ] Document version-3 scout/trade previews and preserve historical versions.
- [ ] Make preview copy reflect cooldown/travel overlap without changing command rules.
- [ ] Link every document family to current implementation status and task pairs; keep historical findings dated.
- [ ] Include the existing reviewed server deployment plan as a reference; no server changes.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
