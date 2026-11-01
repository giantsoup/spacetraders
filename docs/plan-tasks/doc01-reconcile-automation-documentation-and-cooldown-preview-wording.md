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

- [x] Document version-3 scout/trade previews and preserve historical versions.
- [x] Make preview copy reflect cooldown/travel overlap without changing command rules.
- [x] Link every document family to current implementation status and task pairs; keep historical findings dated.
- [x] Include the existing reviewed server deployment plan as a reference; no server changes.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Review handoff · November 1, 2026

Implemented and verified offline on this dedicated branch. [Current status index](../plan-implementation-audit.md#current-status-index) and [actual before/after and check evidence](../issue-1/evidence/doc01-20261101/README.md) cover the four acceptance items. Versioned execution and command rules are unchanged; only the staged mining preview sentence changes application output.

Final standard verification passed 294 tests / 1,548 assertions. The initial baseline reproduced QA01's quote-timestamp failure; DOC01 does not claim to fix that intermittent test. All 42 tracking pairs remain, with original audit snapshots explicitly labeled. The reviewed server plan remains a reference, with no server changes. PR #2 stays draft for Taylor's review; no new independent review ran. No deployment or live gameplay acceptance is claimed.
