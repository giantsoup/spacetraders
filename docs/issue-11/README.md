# OPS03 local implementation review

Implementation starts from `5c1990696811554d03a752dae2cfec6259fcc36c` on `implement/issue-11-deployment-drain-20261101`. The task brief was copied from existing PR #12 without merging its plan branch. This work is local and remains available for Taylor's review.

The [operator runbook](deployment-gates.md) defines the persistent version 1 close/open/status contract, shared paths, ten-minute recovery, acknowledgement requirements and integration boundaries. The implementation gates dynamic web requests through application termination, actual SQL queue reservations, and dispatch. It retains queued intent and reserved work. Scheduler overlap uses a stable 120-second cache lease plus live OS ownership and a bounded dispatcher pass.

## Verification

The requested base passes 321 tests and 1,711 assertions. Final full verification passes 332 tests and 1,760 assertions, the deterministic production build, frontend checks and Pint. Composer validation, platform requirements and both dependency audits pass. Eleven focused gate tests cover maintenance, acknowledgement ordering, exact deadline boundaries, stale generations, cache repair, direct pop, unknown protocol versions, lost writers, shared-path mismatch, deferred web writes and global pause restoration. Detailed outputs, process measurements and screenshots are retained in [evidence](evidence/). The generated [dark report](report.html) lists final results.

Real process coverage includes a 20-second reserved fake gameplay call, an active HTTP-kernel writer, a pre-close `schedule:run` descendant, two continuous workers, delayed pending rows, exact-once confirmation, a frozen compatible rollback artifact, live-owner overlap exclusion, actual cache-lock expiry, a reservation/close race, and a 50-intent dispatcher pass with real SQLite contention. The separate wall-clock test keeps a writer active through the full ten-minute abort and verifies normal completion afterward.

The ordinary process run passes 23 checks. A subsequent focused process race verifies that a waiting old controller exits with failure when another deployment generation replaces it, without opening the successor. Measured stale-lock recovery is 119.946 seconds for the nominal 120-second integer-time cache lease. The idle dispatcher pass takes 0.013 seconds; the 50-intent pass with a competing one-second SQLite writer takes 1.446 seconds. These measurements are local and do not establish server performance. The fixture retains 51 pending queue rows at the end, with no active worker and no failed jobs.

The browser check uses a real authenticated synthetic session and CSRF token, verifies 503 and `Retry-After`, compares saved preview and queue rows while closed, then resumes one approved fake command through a real worker. Phone and desktop maintenance screenshots were inspected. The existing native ship journey and owned launchd engine lifecycle were also exercised. These are offline results; none demonstrate live API acceptance or production readiness.

Intermediate failures were retained while correcting the fixtures and implementation: nested temporary cache cleanup, the error view namespace, browser CSRF and navigation timing, a missing release-artifact manifest, a Pint formatting check, and Laravel's disabled console lifecycle events under `APP_ENV=testing`. The process fixture now uses `local` and requires a real scheduler observation independently of a dispatcher. A build alone was never used as acceptance.

## Integration with PR #12

Keep the existing PR. Do not merge its old preparation branch into this checkout. After reviewing the local implementation commit, apply that commit to the existing PR branch based on the then-current main, replace the preparation-only PR description with the resulting implementation and evidence, and re-run verification against the integrated head. No remote update is authorized or performed here.

Remote main advanced during this run to `87760a42698384871fce4238bb95b226cdafd121`. The implementation deliberately retains the user-specified base. Newer main changes and any integration conflicts require another verification pass.

The first installation is a deployment gate of its own. Historical main does not know this protocol, so its processes must be quiesced through a separately reviewed bootstrap procedure. Automatic rollback must select a protocol-compatible artifact. The offline frozen rollback check establishes version 1 compatibility; it cannot make historical main compatible retroactively.

The remaining server transaction, supervision changes, backup/migration compatibility, release switch, release-specific health checks, public HTTPS, production capacity, deployment and live gameplay remain outside this local task. No independent delegated review ran because this thread explicitly disallowed subagents. No push, remote merge, PR metadata change, issue closure or external notification occurred.
