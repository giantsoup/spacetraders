# MVP security review and local delivery

Sonnet 5.5 independently audited `0fa5898`, after the initial QA fixes. Its complete sanitized report is retained at `storage/app/mvp-evidence/sonnet-security.md`. It assessed 38 route rows / 55 method rows, owner and nested-record access, request forgery, credentials, queue payloads, fixed-host transport, resets, retry boundaries, fixtures and static-file exposure. It found no application-code exploit in that revision. It found one Medium defect in the environment template and identified the hardening items below.

## Resolved findings

| Finding | Resolution and proof |
| --- | --- |
| S1: default local/debug Ignition action could rotate APP_KEY without auth/CSRF | The template disables debug and runnable solutions. `scripts/verify-environment.php` checks 404 and unchanged private fixture keys for template/debug/production controls. The isolated vulnerable positive control returns 204 and changes only its own disposable key, establishing that the check exercises the actual action. Root's environment already had debug disabled and was never rotated. |
| S2: foreign-agent POSTs returned 403 while absent IDs returned 404 | Owner-scoped route binding filters both cases before the handler or throttle. Regression asserts matching 404 JSON and rate-limit headers. Form Requests retain an owner check. |
| S3: shared-budget starvation on a multi-user host | Personal single-operator Mac scope is explicit. Game POSTs have a 60/minute per-user limit; the global upstream budget still applies. Public multi-user fairness remains outside this MVP. Existing registration behavior is retained. |
| Fixture ambiguity | A visible offline-fixture banner states that responses are synthetic and no live gameplay occurs. Fixture paths and credentials stay out of props. |
| Missing game-route forgery regressions | The repository's real forgery-middleware test now includes all eight game POST routes. |
| Dense-system discovery omitted the owned location | Owned locations are observed first within the existing 24-request cap. A 31-waypoint regression verifies the current market receives quotes without increasing that cap. |
| SQLite OS-crash durability | WAL now uses FULL synchronization and macOS fullfsync support. Unit and file-backed worker checks verify the effective pragmas. Process SIGKILL and uncertain-write recovery are exercised again. No physical power-loss proof is claimed. |
| Browser replay selector failure | Explicit accessible select names make the controls stable across the browser runner's label handling. Its error path redacts the synthetic login password. Final runtime results are recorded separately. |

## Scope and remaining limits

All game state is synthetic during verification. There is no live-agent, live-credit, profitability, production-deployment, other-database or macOS sleep/wake evidence. Quote checks remain local estimates; the upstream API has no execution-price ceiling. Unknown writes are retained and blocked rather than replayed.

The full audit's remaining informational notes are preserved: existing Bunny Fonts requests and browser-header policy, private backup/key custody, SQLite queue-reservation contention logs, single-host lock-directory requirements and bounded dashboard payloads. They are not represented as production-readiness approval. Existing styling and authentication flows are preserved. Keep environment keys and backups private, retain the key required by encrypted credentials, and run this personal MVP with the documented local processes.

Source, tests, migrations, documentation and matching tracked build assets are committed locally. No push, PR, merge, deployment, live registration/spending or recurring launchd service is authorized by this task.
