# OPT02 local implementation and evidence

Issue [#81](https://github.com/giantsoup/spacetraders/issues/81), associated draft [PR #82](https://github.com/giantsoup/spacetraders/pull/82). Implemented locally from main `5c1990696811554d03a752dae2cfec6259fcc36c` on `implement/issue-81-fixture-cleanup-20261101`. The PR remains unchanged. Its preparation brief was read through GitHub; no plan branch was merged.

## Implemented behavior

`verify-automation-browser.mjs` and `verify-opportunities-browser.mjs` now use `browser-verification.mjs` and `verification-lifecycle.mjs`. The lifecycle creates its own mode-0700 `mkdtemp` parent. PHP creates its isolated fixture beneath that parent through its process-local `TMPDIR`. No deletion API accepts a directory from a caller or adopts a path printed by a subprocess.

The lifecycle remembers the parent's device, inode, owner, canonical path, private permissions, and random ownership marker. It checks them before removal. It rejects replaced directories, root symlinks, and changed or symlinked markers. Recursive removal unlinks descendant symlinks without following their targets. The supplied artifact directory is a separate durable destination and is never removed.

Resource cleanup is registered as each resource is acquired. Native service cleanup is registered before installation, including installation that bootstraps successfully and then fails. Shutdown attempts every registered cleanup, even after an earlier cleanup fails. It waits for actual server and Chromium child exit. Native cleanup requests End, waits for the supervisor, worker, and scheduler, then uninstalls the fixture-derived service and verifies removal. A server that needs SIGKILL is reaped and marks the run failed.

Screenshots and JSON results stay outside the fixture. A durable result cannot report a pass before shutdown and cleanup complete. Assertion, setup, reporting, interruption, or shutdown failure retains the private allocation. `failure.log`, server output, SQLite, fake world, generated key, and available failure screenshot remain there. Durable errors redact generated credentials; PHP output stays private. A replaced allocation is never followed for a diagnostic write, and its ownership is reported as unverified.

SIGINT and SIGTERM enter the same failure/shutdown path. SIGKILL, abrupt host loss, an operating-system refusal to stop a process, or a simultaneous same-user filesystem attack cannot be guaranteed recoverable by this harness. A failed shutdown blocks removal and is reported explicitly. No automatic sweep of older retained directories is added.

## Acceptance evidence

- Baseline: full verification passed 321 tests and 1,711 assertions. The opportunities journey passed but left `/var/folders/7d/h5dz__nj3c34c0qjw6rx3v800000gq/T/spacetraders-fixture-fde684eff5b2` present. That baseline fixture is retained as reproduction evidence.
- Final full verification: production assets built twice with identical output, frontend checks passed, 321 backend tests and 1,711 assertions passed, Pint passed, and whitespace checks passed. See [verify.txt](evidence/verify.txt). Composer validation and installed-platform checks passed under PHP 8.5.12.
- Focused lifecycle coverage: 18 passing cases include ownership and marker changes, root and descendant symlinks, normal and failed work, partial setup, shutdown failure, an already exited child, failed spawn, forced reaping, SIGTERM, reporting failure, and user-supplied output protection. See [lifecycle.txt](evidence/lifecycle.txt).
- Actual browser lifecycle: six cases passed, with no skips on macOS. They cover successful removal, missing browser, failed fixture process, assertion and SIGTERM after real native engine startup, and failure after actual service bootstrap. Expected negative cases retain diagnostics and report `passed: false`. See [browser-lifecycle.txt](evidence/browser-lifecycle.txt) and [retained paths](evidence/browser-lifecycle-results.json).
- Actual gameplay verification uses only the fake provider: the original two-worker mining/recovery check and all five staged automation-worker scenarios passed. See [mvp-worker.txt](evidence/mvp-worker.txt) and [automation-worker.txt](evidence/automation-worker.txt).
- Both complete browser journeys passed. [Automation](evidence/automation-final.json) exercised mining, contract fulfillment, validation, Stop, and restart recovery. [Opportunities](evidence/opportunities-final.json) exercised scouting, trading, integer batches, and evidence export. Their fixture parents are absent, while the durable screenshots/results remain.
- A final direct audit confirmed 25 recorded process/service absence checks. See [shutdown-audit.json](evidence/shutdown-audit.json). This is a dated local observation, not a future process-liveness guarantee.
- A separate T3 preview opened the owned loopback login, confirmed dark color scheme and no horizontal overflow, and closed before fixture release. Its server and browser exited and its fixture was removed. See [preview result](evidence/preview.json).

The selected runtime was the checkout's explicit `scripts/herd-php` wrapper, PHP 8.5.12, Node 26.13.0, pnpm 12.16.1, locked dependencies, Playwright 1.64.1, and its owned Chromium 156.0.8078.4 installation. The baseline browser reproduction used the installed Google Chrome executable while that owned browser downloaded. An initial verification invocation pointed to a nonexistent local pnpm executable; the corrected invocation used the pinned npm-exec installation and passed. No dependency version, lockfile, or tracked production asset changed.

Saved mobile screenshots were inspected directly. [Mining](evidence/mining-390.png) and [intelligence](evidence/intelligence-390.png) remain dark even with a light browser preference. Their existing modal/scroll framing is preserved; this task does not claim a separate redesign QA pass.

## Re-run the affected checks

Install locked dependencies in an owned checkout using the current README. Set `PHP_BIN` to that checkout's `scripts/herd-php`, `PNPM_BIN` to pnpm 12.16.1, and `PLAYWRIGHT_BROWSERS_PATH` to an owned browser installation. Then run:

```bash
./scripts/verify.sh
node --test scripts/verify-verification-lifecycle.mjs
node --test scripts/verify-browser-lifecycle.mjs
"$PHP_BIN" scripts/verify-mvp-worker.php
"$PHP_BIN" scripts/verify-automation-worker.php
node scripts/verify-automation-browser.mjs
node scripts/verify-opportunities-browser.mjs
```

`verify.sh` includes the focused lifecycle cases. `verify-local-automation.sh` now includes the browser lifecycle cases and opportunities journey. The commands above were run individually; the entire aggregate, including its separate Herd-FPM probe and presentation journey, was not rerun for OPT02.

## Integrate into the existing PR

Keep PR #82 as the destination. Transfer this local implementation commit onto its branch after incorporating current main through the repository's normal review process. Do not merge another task's plan branch. The preparation-only PR description must be rewritten around the implementation and evidence before publication, and issue acceptance must be reviewed before changing draft status. No remote metadata change is made here.

During the final check, the shared `origin/main` ref advanced to `87760a42698384871fce4238bb95b226cdafd121`. Its two additional commits add only the OPS02 preparation brief, with no runtime or shared-script overlap. This local implementation remains based on the explicitly requested `5c19906` baseline. Recheck current main when integrating PR #82.

Other workers editing either journey must preserve the `withBrowserVerification` callback and place their checks inside it. Keep `QA_ARTIFACT_DIR` outside the allocated fixture. Add every new long-lived resource to the cleanup stack immediately after acquisition. Register service cleanup before installation. Preserve `signal` checks in polling or worker loops. Changes to `verify.sh` or `verify-local-automation.sh` must retain the new lifecycle commands alongside other workers' checks.

QA01, UI01, DOC01, OPS01, and OPT01 behavior remains on the current-main baseline. No gameplay routes, executors, owner checks, CSRF rules, integer limits, UTC storage/deadlines, reserve controls, or Stop/reset/reconciliation behavior changed. The environment, key, databases, storage, cache, sessions, locks, fake worlds, ports, browsers, and service identities used here belong to this worktree or its private fixtures. No live environment or credentials were copied.

## Remaining gates

No local OPT02 acceptance blocker remains. The implementation is retained for Taylor's review. Remote CI, PR publication/integration, deployment, and live gameplay remain unverified and unauthorized. No independent model review was run because this thread prohibits subagents. Failed-run private directories and baseline reproduction evidence are intentionally retained; their private contents are excluded from the report and commit. Public results retain their paths.
