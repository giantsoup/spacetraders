# Make market quote invalidation acceptance deterministic

Plan audit task **QA01**. Status: confirmed test failure. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:165](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L165)
- [tests/Feature/OpportunityTest.php:555](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/tests/Feature/OpportunityTest.php#L555)
- [app/Gameplay/AgentSynchronizer.php:141](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/AgentSynchronizer.php#L141)

## Verified gap

- The full suite failed at OpportunityTest.php:561: observed_at equality. Isolated --repeat 10 failed on repetition 3; remaining repetitions were skipped by PHPUnit.
- The command helper performs preview, approval and execution. CommandRules refreshes market prices during preflight, so the earlier sync timestamp is not necessarily the last quote timestamp. invalidateMarket itself only updates data.

## Acceptance

- [ ] Separate a legitimate fresh price observation from mutation invalidation using a controlled clock.
- [ ] Assert invalidation preserves the most recent actual price-observation timestamp across a deliberately crossed second boundary.
- [ ] Cover the complete command path and the invalidation boundary without weakening price freshness or adding sleeps.
- [ ] Pass repeated isolated checks and scripts/verify.sh.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
