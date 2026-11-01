# Add reproducible CI gates and immutable release artifacts

Plan audit task **OPS04**. Status: unimplemented CI. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- `docs/server-deployment-plan.md:55`, reviewed local plan published with DOC01.
- [package.json:1](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/package.json#L1)
- [scripts/verify-mvp-browser.mjs:1](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/scripts/verify-mvp-browser.mjs#L1)

## Verified gap

- No .github workflow directory exists at the audited remote or local baseline. Playwright is dynamically imported rather than pinned in the app manifest.

## Acceptance

- [ ] Pin supported PHP, Composer, Node, pnpm, browser tooling and third-party action revisions; use least-privilege permissions and no deploy secrets on untrusted PRs.
- [ ] Run locked installs, backend/frontend checks, production build, auth/ownership and fake-provider Start/Stop browser journeys.
- [ ] Run real on-disk SQLite worker/scheduler and deployment-harness cases. Fix QA01 before requiring a green gate.
- [ ] Build one tested SHA/checksummed artifact with production vendor/assets and no environment, credentials, fixtures, local DB or config caches.

## Dependencies

OPS01, OPS02, OPS03, QA01

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
