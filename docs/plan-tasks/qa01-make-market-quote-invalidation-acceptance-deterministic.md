# Make market quote invalidation acceptance deterministic

Plan audit task **QA01**. Status: offline acceptance verified. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/exploration-fuel-and-opportunity-recommendations.md:165](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/exploration-fuel-and-opportunity-recommendations.md#L165)
- [tests/Feature/OpportunityTest.php:555](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/tests/Feature/OpportunityTest.php#L555)
- [app/Gameplay/AgentSynchronizer.php:141](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/app/Gameplay/AgentSynchronizer.php#L141)

## Verified gap

- The full suite failed at OpportunityTest.php:561: observed_at equality. Isolated --repeat 10 failed on repetition 3; remaining repetitions were skipped by PHPUnit.
- The command helper performs preview, approval and execution. CommandRules refreshes market prices during preflight, so the earlier sync timestamp is not necessarily the last quote timestamp. invalidateMarket itself only updates data.

## Acceptance

- [x] Separate a legitimate fresh price observation from mutation invalidation using a controlled clock.
- [x] Assert invalidation preserves the most recent actual price-observation timestamp across a deliberately crossed second boundary.
- [x] Cover the complete command path and the invalidation boundary without weakening price freshness or adding sleeps.
- [x] Pass repeated isolated checks and scripts/verify.sh.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Implementation handoff

The regression now freezes UTC time for the initial sync, advances one second for the full preview/approval/execution path, captures the final price observation immediately before the fake purchase request, and advances another second before invalidation. It asserts that the latest real price observation is newer than the initial sync and that invalidation preserves that timestamp and the price-history count. The confirmed purchase, stale market classification, and existing travel assertions remain covered. Production freshness rules are unchanged.

[Verification and before/after evidence](../issue-3/evidence/qa01-20261101-586a896e/README.md) records the reproduced baseline failure, 25 passing isolated repetitions, 293 passing backend tests through `scripts/verify.sh`, the offline browser journey, finite worker checks, and two deliberately failing negative controls.

PR #4 remains draft for Taylor's review. There is no unresolved offline acceptance gate. No live gameplay, deployment, primary environment migration, or paid API access was performed. Revert the implementation commit to restore the previous test; no data migration is needed.
