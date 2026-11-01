# Operate the deployment gate

OPS03 implements a single-host, version 1 admission and acknowledgement contract. It does not install a deployment service, migrate a production database, switch releases, or implement the remaining deployment transaction in the [server plan](../server-deployment-plan.md).

## Prepare compatible processes

Use PHP 8.5.12 through the explicit wrapper. Configure the same persistent SQLite database, `SPACETRADERS_LOCK_PATH`, `SPACETRADERS_DEPLOYMENT_PATH`, and file `CACHE_PATH` for every web, queue, and scheduler process. Set `CACHE_STORE=file`. These paths must resolve outside immutable release directories. Keep the directory private and retain it across releases. `SPACETRADERS_RELEASE` identifies the immutable release in acknowledgement records.

The gate records the database, queue configuration, and cache identity. A release using different values cannot acknowledge or open a closed gate. Array, null, and other cache drivers are rejected for deployment control. The supported backend is the shared file cache on one host. Network filesystems and multiple hosts are outside this contract.

Current main before OPS03 has no deployment command or admission checks. Its processes cannot participate in this protocol. The first installation requires a separately coordinated stop of that older web/worker/scheduler generation before installing compatible code. Never migrate or switch under uninstrumented processes, and never automatically roll back to a pre-OPS03 artifact. The historical main negative check is retained in the evidence package.

## Close and wait

Use a fresh identifier for each deployment attempt:

```bash
export PHP_BIN="$PWD/scripts/herd-php"
"$PHP_BIN" artisan spacetraders:deployment close --generation=release-attempt-001 --wait
```

The command returns JSON. Exit 0 from `close` means `phase=closed` and `drained=true`. Exit 1 means drain has not completed or has aborted. Without `--wait`, close starts the same durable transaction and returns immediately with exit 1 until ready. Repeating close with the same active generation is idempotent. Another generation cannot take it over. An identifier cannot be reused after open or timeout.

Close blocks every dynamic web request, including reads that can archive agents or write sessions. HTML receives the existing dark error layout, status 503 and `Retry-After: 5`. JSON clients receive status 503 and a short message. Static assets remain available. Existing authentication, ownership, CSRF, integer validation and command execution paths remain in place when admission is open.

The short admission lock serializes close with the actual database queue reservation. Laravel's persistent `database:spacetraders` pause signal handles normal worker polling; the database queue adapter also checks admission inside `pop`, including direct pop and workers that disable pause polling. Already-reserved work finishes normally. The gate does not reject, delete, retry or replay durable intent.

Readiness requires a live idle worker that observed this generation, an actual `schedule:run` or local engine scheduler observation, completion acknowledgements from all admitted web writes and scheduler/dispatcher work, and no reserved rows on the target queue. A direct dispatcher call cannot stand in for the scheduler. Web acknowledgement follows Laravel's termination callbacks. An OS lease establishes process ownership; stale timestamps and vanished busy processes cannot be counted as successful completion.

## Inspect and recover

```bash
"$PHP_BIN" artisan spacetraders:deployment status
"$PHP_BIN" artisan spacetraders:deployment open --generation=release-attempt-001
```

`status` reports version, phase, generation, UTC close/deadline timestamps, release-labelled participants, acknowledgements, blockers and the last outcome. It is an operator CLI, not a public health endpoint. Poll it while controlling a deployment. It can finish a drain, repair an interrupted cache pause, or expire a timed-out drain.

`open` restores admission and the queue's previous pause state. A pre-existing queue pause remains paused. Repeating open for the same generation is safe. A stale controller cannot reopen a newer deployment. Open does not restart services or restore old approvals. Reopening after an aborted attempt leaves a running reserved job alone.

A draining attempt expires at 600 seconds. The waiting controller or the next web/worker/scheduler/status entrance persists `phase=open` and `outcome=aborted_timeout`, then restores pickup. Recovery therefore survives controller loss while cooperating processes remain active. If every process is stopped, recovery occurs on the next entrance. There is no independent timer service. No signal, queue clearing, down migration or uncertain-write replay is part of timeout recovery.

A successfully closed gate has no automatic reopening deadline. Keep it closed during migration, switching and rollback. After drain, the deployment controller must separately quiesce all old processes and prevent their supervisor from respawning them. `drained` becomes false when no live worker remains, while `phase=closed` continues to fence admission. After starting the candidate or rollback release, inspect fresh worker and scheduler acknowledgements carrying that exact release identifier before opening. An old scheduler acknowledgement does not certify a new release's readiness. Do not use the local engine's End session operation as a deployment drain; it intentionally invalidates gameplay approvals.

Unknown protocol versions, corrupt state and runtime identity mismatches fail closed. Restore compatible code and shared paths before retrying. Do not delete gate files or force-release a live process lease to recover a deployment. A process that disappears while busy leaves a blocker until the ten-minute abort. Reconciliation of a lost upstream response remains the durable executor's responsibility.

## Scheduler expiry

The five-second event has a release-independent mutex name, `spacetraders:dispatch-due:v1`, and a 120-second cache expiry. Each dispatcher admits work before taking the existing OS lock. That OS lock prevents concurrent dispatch even if the cache lock expires or is cleared while a process remains alive, and the OS releases it when the owner exits.

The dispatcher stops starting loop iterations after a 15-second monotonic budget. An individual SQLite operation can still consume its configured five-second busy timeout. The 120-second cache lease leaves room for those operations. The owned harness measures an idle pass, a full 50-intent pass with a competing SQLite writer, and real stale-lock expiry. These are local measurements, not server capacity evidence. Re-measure on the target host before deployment. Do not use `schedule:clear-cache` as ordinary recovery.

## Repeat offline verification

```bash
"$PHP_BIN" vendor/bin/phpunit --filter DeploymentGateTest
"$PHP_BIN" scripts/verify-deployment.php
"$PHP_BIN" scripts/verify-deployment.php wall-timeout
PHP_BIN="$PHP_BIN" node scripts/verify-deployment-browser.mjs
"$PHP_BIN" scripts/verify-local-engine.php
```

The process harness owns its SQLite, key, cache, storage, leases, fake upstream and release artifact. The ordinary run takes several minutes because it waits for actual scheduler descendants, finite workers and the real 120-second stale cache expiry. The wall-timeout mode takes ten minutes and lets its active web writer finish afterward. Child processes must use `APP_ENV=local`; Laravel deliberately suppresses console lifecycle events in its `testing` environment. No live credentials or game calls are used.

The browser harness owns a loopback server, browser context, session and synthetic account, then closes them. Set `PLAYWRIGHT_EXECUTABLE` or `PLAYWRIGHT_BROWSERS_PATH` for the locked Playwright tool. Screenshots and results default to `storage/app/mvp-evidence/ops03/browser`. The local engine check creates and removes only its temporary launchd identity; its existing crash-recovery cases deliberately fault owned processes. Deployment control itself never signals a process.
