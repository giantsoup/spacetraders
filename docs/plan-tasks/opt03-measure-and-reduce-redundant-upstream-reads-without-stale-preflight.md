# Measure and reduce redundant upstream reads without stale preflight

Plan audit task **OPT03**. Status: optional O4 optimization. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/opus-qa-security-tasks.md:366](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/opus-qa-security-tasks.md#L366)
- [app/Services/SpaceTradersClient.php:23](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Services/SpaceTradersClient.php#L23)
- [app/Gameplay/AutomationRunner.php:155](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/AutomationRunner.php#L155)

## Verified gap

- Two-second same-scope GET reuse is implemented and historical calls fell 236 to 227. Further reset/agent reads still occur across dispatches.

## Acceptance

- [ ] Profile exact request paths with synthetic call counts and establish an explicit correctness/freshness boundary before widening reuse.
- [ ] Reduce only proven-redundant reads; maintain reset detection, balance/price/cargo refresh before writes, write invalidation and host/account priorities.
- [ ] Report repeatable before/after counts and tests for reset, external balance change, unknown write and concurrent dispatch. Keep accepted conservative behavior if safe reduction is not supported.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
