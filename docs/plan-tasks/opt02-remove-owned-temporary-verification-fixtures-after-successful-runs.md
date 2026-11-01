# Remove owned temporary verification fixtures after successful runs

Plan audit task **OPT02**. Status: optional O3 cleanup. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/opus-qa-security-tasks.md:175](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/opus-qa-security-tasks.md#L175)
- [scripts/verify-automation-browser.mjs:191](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/scripts/verify-automation-browser.mjs#L191)
- [scripts/verify-opportunities-browser.mjs:146](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/scripts/verify-opportunities-browser.mjs#L146)

## Verified gap

- Browser scripts stop their service/server/browser but retain temporary fixture directories on success.

## Acceptance

- [ ] After verified successful process/service shutdown, remove only the exact owned temporary directory.
- [ ] Preserve durable screenshots/results outside the fixture and retain failed-run diagnostics with clear private paths.
- [ ] Test safe path ownership, success/failure/partial setup and no orphan services; never recursively delete a user-supplied path.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
