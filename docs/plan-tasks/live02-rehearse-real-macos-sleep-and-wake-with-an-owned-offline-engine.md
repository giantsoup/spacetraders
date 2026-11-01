# Rehearse real macOS sleep and wake with an owned offline engine

Plan audit task **LIVE02**. Status: unverified native acceptance. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/early-game-mvp-plan.md:173](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/early-game-mvp-plan.md#L173)
- [docs/mvp-qa-followups.md:19](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/mvp-qa-followups.md#L19)
- [scripts/verify-local-engine.php:104](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/scripts/verify-local-engine.php#L104)

## Verified gap

- Tests simulate stale heartbeat/time gaps; the local report explicitly excludes an actual macOS sleep/wake trial.

## Acceptance

- [ ] Prepare a disposable fake-provider engine with no real credentials, record process/intent state, then perform a separately authorized real Mac sleep/resume.
- [ ] Verify stale approvals cannot execute, owned process/heartbeat health is truthful and deadlines recalculate after wake.
- [ ] Cover unsent and in-flight uncertainty safely, refresh/reapproval and Stop/session exit, and remove only the owned service/resources.

## Dependencies

None.

## Execution boundary

Requires permission for disruptive actual Mac sleep. Independent fixture preparation is safe; never put live gameplay at risk.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
