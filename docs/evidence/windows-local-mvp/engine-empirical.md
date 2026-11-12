# Windows engine host empirical evidence

Recorded November 11, 2026, 18:30 Pacific (November 12, 2026, 02:30 UTC),
on Windows 11 as the non-elevated user taylo, with Herd PHP 8.5.12.
The test process reports that its token is not an elevated Administrator token.
No operation targets the Herd checkout or its database.

## Reproduction and raw output

From this worktree:

```powershell
& C:/Users/taylo/.config/herd/bin/php85/php.exe docs/evidence/windows-local-mvp/engine-empirical.php
```

The committed runner contains the exact PowerShell scripts, PHP child programs,
argument arrays, and cleanup. The recorded output is
[engine-empirical-output.json](engine-empirical-output.json).
It uses temporary files and creates only randomly named tasks with the
`SpaceTraders-Engine-Test-` prefix. It never registers a production engine task.
The ACL check executes the real host installer with only Task Scheduler calls
replaced; the directory ACL and service files are real, isolated test artifacts.

## Results

| Check | Observation |
| --- | --- |
| E1 | Pending. Requires a Herd request/job-object experiment and quitting Herd. Not attempted. |
| E2 | Non-elevated root-level task registration from trigger-free UTF-16 XML succeeds. `/Create /TN SpaceTraders-Engine-Test-d7ebfb5020810b33 /XML <temporary-directory>/task.xml` exits 0, without `/F`. `/Run` exits 0 and the scheduled php-win process publishes its own PID. The php-cgi caller variant remains pending. |
| E3 | The second `/Run` exits 0 and reports `INFO: scheduled task ... is currently running.` Only one start record exists after 1.5 seconds. The task uses `IgnoreNew`. |
| E4 | Scheduled parent and descendant php-win processes both report `MainWindowHandle: 0`. The descendant's redirected log contains `child`; the direct child's log contains `child stdout`. The configured engine single channel writes `production.INFO: engine channel empirical check`. No interactive desktop/visual console observation was attempted. |
| E5 | `/End` exits 0 and the parent disappears, but its descendant remains after 500 ms with the same CIM identity. `/End` alone does not stop this descendant on this host. Cleanup explicitly terminates it. |
| E6 | `taskkill.exe /PID 26696 /F` exits 0. A previously held empty lease becomes free within the one-second acceptance bound. One recorded observation is 221.915 ms from immediately before launching taskkill through successful free-lease probing. This is an upper bound including command startup/wait overhead, not an isolated lock-release benchmark. This worktree's PHPUnit run had finished before this recorded observation. |
| E7 | Pending. Requires Modern Standby and wake, including an hrtime observation. Not attempted. |
| E8 | Non-elevated `Get-CimInstance Win32_Process` reads both creation times and command lines. Two queries 100 ms apart produce identical UTC creation timestamps and SHA-256 command-line hashes. Exact identities are in the JSON output. |
| E9 | Pending. Requires sign-out, screen-lock, and power-state checks. Not attempted. |
| E10 | `WindowsChild` uses array-form `proc_open` with `bypass_shell`. `proc_get_status` reports PID 26696, exactly matching the child's own `getmypid()` output; CIM identifies the same process. There is no cmd PID wrapper. |

The private engine directory has inheritance disabled and allows only the
current user's SID, SYSTEM (`S-1-5-18`), and Administrators (`S-1-5-32-544`).
This check used a placeholder APP_KEY, not application credentials.

## Findings incorporated into the implementation

- Windows PowerShell 5.1 wraps a JSON array as one pipeline object when
  `ConvertFrom-Json` is wrapped in another array expression. Direct assignment
  avoids nested arrays when building the CIM PID filter.
- An exited process can disappear during CIM inspection. The identity query
  discards processes that `Get-Process` confirms are gone or exited; a live
  process with unreadable identity still fails closed.
- Force stop checks every returned identity before issuing any taskkill. An
  identity mismatch aborts before `/End` as well, because `/End` could otherwise
  terminate an unverified task process.
- ACL hardening precedes writing service.json, avoiding a readable-credentials
  interval while inherited directory permissions are still present.

The launcher derives its base from its own file location. It loads this
checkout's .env before validating the marker against the configured engine
directory, then injects the service environment into all three PHP environment
surfaces. A nondefault lock path must therefore already be present in the
launcher's inherited environment or this checkout's .env; a caller-only config
override cannot authorize an arbitrary marker directory. This is a deliberate
fail-closed boundary to retain when adding isolated Scheduled Task fixtures.

## Cleanup

The recorded task deletion exits 0. The subsequent `/Query` exits 1 with
`ERROR: The system cannot find the file specified.` The recorded process
re-query returns an empty array. A final separate enumeration also finds no
remaining `SpaceTraders-Engine-Test-` tasks or php-win processes referring to
these experiment directories.

Earlier diagnostic runs also used only the test prefix. The first failed run
was cleaned up explicitly after its identity-query exception; subsequent runs
use a finally block with a separate temporary-directory ownership check, so
cleanup does not depend on the identity implementation under test. Temporary
logs and fixtures remain under the OS temporary directory for inspection.

## Verification

The host command tests replace HostCommands in the container; they never
register tasks or terminate real PIDs. Lease tests launch a real php child and
verify that an empty locked file becomes available within one second of its
termination. Watch tests cover all four stop conditions, DB throttling,
Looping refusal, and cleanup for successful and failed jobs. Launcher tests
execute the actual standalone script, including path rejection, all three
environment surfaces, and Artisan argv.

Commands:

```powershell
& C:/Users/taylo/.config/herd/bin/php85/php.exe vendor/bin/phpunit tests/Unit/Engine --display-all-issues --colors=never
& C:/Users/taylo/.config/herd/bin/php85/php.exe vendor/bin/phpunit --display-all-issues --colors=never
& C:/Users/taylo/.config/herd/bin/php85/php.exe vendor/bin/pint --test app/Gameplay/Engine/Windows app/Gameplay/Engine/EngineLease.php app/Gameplay/Engine/EngineChildWatch.php app/Providers/EngineServiceProvider.php tests/Unit/Engine
git diff --check
```

Targeted result: 32 tests, 127 assertions, no failures, errors, warnings, or skips.
Scoped Pint and PHP syntax checks pass. The initial full run passes with
551 tests, 3084 assertions, and six existing Windows/POSIX skips; its output is
[engine-tests-initial.txt](engine-tests-initial.txt). That run precedes the five
additional failure-boundary and launcher tests.

An intermediate full run reports one failed observation in the existing
three-process RequestBudget response-recording stress test; its complete output
is retained in [engine-tests-transient.txt](engine-tests-transient.txt).
The unchanged test then passes alone (1 test, 7 assertions, 17.818 seconds).
No existing test or request-budget source was changed. The final confirmation
run executes without concurrent empirical or targeted PHP test processes and
reproduces the same failure: 556 tests, 3093 assertions, one failure, six skips,
zero errors and warnings. Its output is [engine-tests.txt](engine-tests.txt).
The existing failing test is
`Tests\Feature\RequestBudgetTest::test_three_process_response_recording_has_no_losses_or_lock_failures`:
one child reports one caught observation failure instead of zero. This remains
a full-suite acceptance blocker for the parent implementer; the owned engine
tests all pass, and the unrelated request-budget implementation is outside
this task's edit scope. The six POSIX skips remain for the parallel shared
engine/test migration.
