# OPS01 verification evidence

Captured November 1, 2026 in the designated issue worktree. Before is the untouched PR baseline `54e998d95ee02bb993c4de5d866c6fadf9cfcacf`; after uses the final implementation. Both execute the same fixture definitions with newly owned in-memory or file-backed SQLite databases, keys, locks, storage and fake worlds. No primary environment, game history or live credentials were read. Machine paths are sanitized. Native service and fixture metadata remain private.

The two PNG files are dark screenshots of rendered, captured CLI transcripts. They are not application screenshots or a UI redesign. Both use a 1120 by 760 viewport and identical 1120 by 500 crop. Their text summaries and full relevant logs are retained beside them. The renderer rejects overflow rather than clipping command output.

| Check | Actual result | Evidence |
| --- | --- | --- |
| Original PHP 8.5 verification gate | Rejected, exit 1 | [before-gate.txt](before-gate.txt) |
| Untouched PHP 8.4.27 full verification | Pass, 293 tests / 1,528 assertions, exit 0 | [before-verify.txt](before-verify.txt) |
| Final PHP 8.5.12 full verification | Pass, 293 tests / 1,528 assertions, exit 0 | [final-verify.txt](final-verify.txt) |
| Composer validation, all installed platform requirements and production server preflight | Pass under 8.5.12 | [final-platform.txt](final-platform.txt) |
| Composer security audit | No advisories | [after-audit.txt](after-audit.txt) |
| Before/after real mining workers | Pass, duplicate delivery, durable completion, killed sender, uncertainty, Stop | [before](before-worker.txt), [after](after-worker.txt) |
| Real contract/recovery workers | Pass, five scenarios and 116 checks | [after-automation-worker.txt](after-automation-worker.txt) |
| Native local engine lifecycle | Pass, actual supervisor/worker/scheduler, crashes, non-replay, sentinel preserved | [after-engine.txt](after-engine.txt) |
| Before/after presentation browser | Pass, Chromium 156.0.8078.4, four viewport widths | [before](before-browser.txt), [after](after-browser.txt) |
| Native automation browser | Pass, actual owned engine, mining, contract, validation, End session and Stop; temporary service removed | [after-automation-browser.txt](after-automation-browser.txt) |
| Final tutorial browser | Pass, five journeys, desktop/mobile and dark appearance | [final-tutorial-browser.txt](final-tutorial-browser.txt) |
| Application rollback | Baseline lock installed separately under 8.4.27; platform and real-worker checks pass | [platform](rollback-platform.txt), [worker](rollback-worker.txt) |

The six guided scenarios also pass using Playwright 1.64.1 / Chromium 156.0.8078.4 and fresh owned fixtures. Their sanitized command output is in [guided-browser.txt](guided-browser.txt): mining 44 explicit steps, setup 43, uncertain 17, rejected 17, recovery 44, and preparation 46. Runtime guard, wrapper arguments, shell/JavaScript/Python and workflow YAML checks pass. The Herd FPM probe reports PHP 8.5.12 and confirms removal of its inert temporary service. Herd's global PHP default remains 8.4. No generated production asset changes remain.

Two failures are retained rather than hidden:

- QA01's unchanged quote timestamp assertion fails at `OpportunityTest.php:561` on baseline PHP 8.4 repetition 6 and final PHP 8.5 repetition 4. PHPUnit skips the remaining repetitions after the failure. See [before-qa01-repeat.txt](before-qa01-repeat.txt) and [after-qa01-repeat.txt](after-qa01-repeat.txt). These failures coexist with passing full-suite runs and remain owned by issue #3.
- The first PHP 8.5 tutorial browser run failed with `net::ERR_ABORTED` when immediate harness navigation raced Dashboard's Inertia reload. See [after-tutorial-browser.txt](after-tutorial-browser.txt). An unmodified repeat passed; after adding the explicit UI-ready wait, the final browser journey passed. The POST status assertion remains intact.

The first dependency-resolution pass exposed PHP 8.5's deprecated MySQL PDO constant. It was replaced by the driver-class constant; the final install and full suites have no deprecation. The first native engine run rejected 8.5 through the old 8.4-only guard. Installation now uses the shared runtime preflight, and final native lifecycle and browser checks pass.

Hosted CI has not been established by these local results. Shared-host package source, other apps' deployed locks and smoke checks, FPM/service cutover, and package/configuration restoration remain unverified. The [runbook](../../php85-runtime.md) records the exact approval and evidence gates. No independent model review or server change was performed.
