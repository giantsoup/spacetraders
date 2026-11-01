# SpaceTraders server and deployment plan

Status: approved as a plan by Sonnet 5.5 on November 1, 2026, after two review passes. Planning only. No application implementation or server changes are authorized by this document. User policy decisions are recorded below; technical proposals require verification during implementation.

## Objective and scope

Deploy the private SpaceTraders application at `https://spacetraders.tayloroyer.com` on the existing $5/month, 1 GB server, `taylor@tayloroyer.com`. Include shared capacity optimizations while preserving deployment workflows for FishCounts, Print For Me, and the portfolio. Brief interruptions are acceptable at any time, but active jobs must finish and pending jobs must remain durable. Do not buy resources.

Baseline: the November 1 read-only report in `server-capacity-2026-11-01/report.html` measured one CPU, 961 MiB usable RAM, about 350 MiB available, 2.5 GiB swap, and 13 GB free disk. Existing CPU utilization was low. These are historical observations, not deployment acceptance measurements.

## Accepted decisions

- GitHub Actions tests and builds off-server. Pushes to main deploy automatically only after all required checks pass. Failed tests block deployment without an override.
- Temporary CI database and browser environment, no permanent staging server. Backend tests, production asset build, critical browser tests with a fake SpaceTraders API are mandatory.
- Finish an active deployment before deploying the newest passing commit. Superseded tests may be canceled; active server deployments may not be canceled.
- Drain active work for up to ten minutes before deployment. On timeout abort and restore job pickup without killing the active job. Preserve pending jobs.
- Automatically roll back failed post-deployment health checks. Migrations must remain compatible with previous releases. Database restoration is a separate deliberate recovery action.
- Read-only upstream API preflight; defer deployment if unavailable. Retry post-deployment health failures briefly, then roll back. Check HTTPS, application, database, worker and scheduler readiness as well.
- Fresh production database, one private account provisioned by a secure one-time command, no public registration, automation initially stopped. No local gameplay/test state copied.
- One continuous SpaceTraders worker. FishCounts remains continuous. Portfolio and Print For Me email pickup within one minute; other work may wait five minutes or longer.
- Daily database backups and backups before migrations. Keep seven daily and three deployment backups on-server. Manual Mac download with checksum verification and the same retention. Prune only after successful creation/download and verification. No automatic Mac monitoring or stale-backup notifications.
- GitHub workflow notifications for deployment results. Manual backup command reports failures when run.
- Journal retention up to 14 days, capped at 512 MiB. Reduce unused MyISAM cache conservatively before deployment; defer Aria/InnoDB tuning and optional-service removal.
- Dedicated restricted deployment account and key. Application secrets stay in a protected server environment file outside release directories. GitHub holds deployment credentials only.
- Keep current release plus two previous successful releases; prune only after health checks succeed.

## Repository findings and selected runtime

Local origin is `git@github.com:giantsoup/spacetraders.git`. No GitHub Actions workflow was found locally. Do not assume GitHub settings, DNS, runner availability or remote branch state have been verified.

Taylor selected **PHP 8.5, latest available stable patch at implementation time**, including local development. Verify the current patch and package source at implementation; record and pin the tested patch in CI and server provisioning. Use `herd php` for local PHP commands, confirming `herd php -v` reports the intended 8.5 patch in this checkout. Do not assume prefixing selects 8.5 until verified; use project-specific Herd selection if needed and avoid changing unrelated sites. Scripts that currently take a single executable PHP_BIN cannot accept a space-containing command blindly: provide a small executable wrapper that invokes `herd php` or adapt invocation to an argument array.

The repository currently requires PHP `~8.4.1`, pins Composer platform PHP to 8.4.1 and rejects PHP 8.5 in `scripts/verify.sh`. Update constraints, lock/platform metadata, verification checks and CI pins together after checking dependency support. Execute Composer with the selected PHP runtime; never bypass platform requirements. Server PHP is already 8.5, but updating its patch affects the existing apps: verify their dependency/platform compatibility and relevant smoke tests, then drain affected jobs before any package upgrade or service reload. Keep a documented package/config rollback path. No runtime or source changes have been made during planning.

Node is constrained to 26.13.x through the 26.x series and pnpm is pinned to 12.16.1. PHP, Composer, Node and pnpm versions must be pinned and matched to their verified dependencies in CI. Build assets and production vendor dependencies in CI; do not run Node builds or Composer dependency resolution on the server.

`phpunit.xml` forces in-memory SQLite and synchronous queues. Existing tests alone therefore do not establish durable worker behavior. `scripts/verify-mvp-browser.mjs` uses an explicitly owned fake provider and local origin, but Playwright is dynamically imported and is not a package dependency. Pin browser tooling and make a reproducible CI fixture rather than assuming the local replay works unmodified.

`routes/auth.php` currently exposes registration. Existing registration tests expect it. Change both for a closed single-account deployment and test direct registration POSTs, unauthenticated gameplay requests and ownership boundaries. Add secure account provisioning without passwords in arguments, logs, commits or artifacts.

`app/Console/Kernel.php` dispatches due work every five seconds. The database queue is named `spacetraders`, has `retry_after=180`, and dispatches after commit. Build a tested drain/resume protocol; do not assume maintenance mode or queue:restart alone provides the required guarantee.

## Runtime design

Proposed MVP storage: use SQLite for SpaceTraders, matching the current tests and single-user workload, with a persistent database outside releases. Verify concurrent web, worker and scheduler access, busy timeout/WAL behavior, durable queue retry semantics and transaction locks. If these checks fail, stop and revise the storage decision rather than silently switching to MariaDB. Existing apps retain MariaDB.

Use a dedicated application Unix user, Nginx virtual host and FPM socket with one on-demand child initially. The worker and scheduler use the same explicit PHP binary. Run a single supervised database worker with a provisional PHP memory_limit of 128M, Laravel --memory=96 recycling, sleep 1, tries 1 and timeout 120 seconds, below the 180-second retry interval; confirm job-specific overrides. Supervise one `schedule:work` process with a provisional PHP memory_limit of 128M and prevent duplicate dispatchers. It spawns a schedule:run child, which can in turn spawn dispatch-due. Add shared-cache overlap protection to the five-second dispatch command and test its stale-lock recovery; keep per-agent HostLock protection. CLI timeout support and signal handling must be tested with the installed extensions. Keep application state and APP_KEY persistent across all releases.

Proposed layout: `/var/www/spacetraders/releases/<commit>` with `current` symlink and `shared` directory for environment, storage and SQLite. Keep writable runtime data out of versioned releases. Generate config/route/view caches on-server with production environment attached; never upload CI configuration caches or secrets. Cache directories must remain release-specific. Do not expose the environment, SQLite database or storage internals through Nginx.

Host secrets and encrypted agent tokens require stable APP_KEY recovery. Back up APP_KEY and environment securely and separately from ordinary GitHub artifacts. Backups on the Mac require restrictive permissions; no secret values in the plan. The inline database credential found during inspection requires separate rotation and a protected credentials-file arrangement during approved setup.

## CI gate

Run on pull requests for feedback and pushes to main for release eligibility. Use isolated temporary databases and fixture files; forbid production credentials and outbound gameplay writes. Restrict untrusted PR jobs to read-only permissions and no deployment secrets. Pin third-party actions by immutable revision and grant minimum workflow permissions.

Required checks:

1. Locked Composer and frozen pnpm installation, platform checks and existing verification scripts, adapted to the selected runtime.
2. Full backend tests including closed registration, ownership, login and deployment pause behavior; production asset build and existing frontend assertions.
3. Real browser journeys for login, unauthenticated/unauthorized access denial, and automation Start/Stop against a fake API, using the compiled production assets.
4. Integration tests using an on-disk SQLite database, real worker and scheduler processes, including delayed jobs, active-job drain, pending-job preservation, retry/timeout behavior and concurrent access. No real agent actions.
5. Disposable release harness exercising successful deployment, backup failure, upstream preflight failure, drain timeout, failed migrations, failed health checks, rollback and interrupted connections. Verify old releases can read the migrated schema. Reject destructive migration patterns unless delivered through a separately reviewed staged migration sequence.

Build one production artifact for the tested commit, including production vendor dependencies and compiled assets. Record SHA and checksum. Exclude .env, local databases, credentials, test fixtures and config caches. Validate that artifact in the harness. Do not rebuild different code on the server.

## Deployment transaction

Serialize deployments with a server-side lock in addition to CI concurrency. Select the newest eligible passing main commit; verify it is still on main and do not let an older workflow overwrite a newer release. GitHub's default pending-job behavior must not be assumed to guarantee ordering. Cancel redundant CI runs, not an active deployment. A server-side transaction must survive an SSH disconnect and record enough state for safe recovery.

1. Verify target identity, pinned SSH host key, artifact checksum/SHA, runtime/extensions, disk allowance, persistent paths, backup capability and required services. Make a bounded read-only SpaceTraders availability request from the server. No token or agent is required for initial public API readiness. Failed preflight leaves the live release unchanged.
2. Stage and verify artifact files before the outage. Validate writable-path linkage without starting new application processes.
3. Enter an application-wide deployment gate. Block new gameplay writes, scheduler dispatch and new queue reservations while allowing already-reserved jobs to complete. Preserve automation intent and queued jobs. Cover races with reservation/advisory locking and process acknowledgments; test this explicitly. Do not kill jobs or clear queues. If ten minutes elapse, unwind the gate, restore old dispatch/pickup and abort.
4. Quiesce old scheduler and worker after drain; prevent Supervisor from immediately respawning them. No old application process may continue through migration or release switch. Wait for existing web writes too; serve a brief maintenance response while switching. After quiescence, generate configuration/route/view caches from the immutable release path before migration or switching current. Run cache generation and migrations as the application user through the narrow privileged helper; the deploy account cannot read the environment or APP_KEY. Serialize these commands to limit peak memory. Any failure after quiescence and before switching, including cache, backup or migration failure, restores the old processes and reopens the gate only after confirming old-release readiness against the remaining schema. If readiness fails, remain gated and report recovery needed. Never automatically reverse migrations.
5. If migrations are pending, create and verify a consistent database backup before migration. SQLite backup must use its online-backup facility or an equivalent tested consistent procedure, not raw copying a live WAL database. Failure aborts deployment. Apply only backward-compatible migrations, keeping the old release usable. Never auto-run down migrations.
6. Switch current atomically after successful cache generation and migration, and start new processes. Ensure FPM/OPcache serves the new revision without disrupting other pools unnecessarily. New worker/scheduler run readiness-only heartbeats while gameplay remains gated.
7. Validate HTTPS and release SHA, private/read-only database readiness, fresh worker/scheduler heartbeats and read-only upstream API connectivity. Proposed retry budget: three attempts over at most 90 seconds, respecting Retry-After. Health probes may emit local heartbeats but never enqueue gameplay or advance ships. Do not publish database details or credentials in a public health endpoint.
8. On success, open the gameplay gate and restore only automation that was previously enabled. Record the successful release, notify through workflow results, then prune releases. On failure, keep gameplay gated, restore the previous release and processes, verify local readiness, then restore prior operating state. An upstream outage may persist after rollback; report it without a rollback loop. First deployment has no prior release: leave the app unavailable with automation stopped and report failure.
9. If rollback fails, remain gated, retain backups/releases and report a recovery-needed state. Never restore the database automatically. A durable recovery record and tested recovery command must handle interruption at every stage, without blindly restarting or replaying uncertain upstream actions.

## Shared server changes

Perform separately from routine SpaceTraders deployments. Save exact previous configuration and restore procedure. Reinspect the server before acting.

For portfolio and Print For Me, first inventory actual queues and mail dispatch paths. Route time-sensitive email into an explicit priority queue with a non-overlapping scheduled drain every minute; process other jobs every five minutes. Long jobs must not block email pickup. Bound new reservations by batch time, let active jobs complete, and retain pending work. Preserve each application's user, database, retries and timeout contracts. Existing deployment workflows remain in place; adapt worker shutdown integration only where necessary and document it. Do not silently treat synchronous email as queued email or move all jobs into one queue.

Confirm no MyISAM tables or scheduled imports need the cache, then propose key_buffer_size=8 MiB from 128 MiB. Persist and apply through a reviewed MariaDB procedure. If restart is required, drain dependent workloads and inhibit new database writes first. Keep InnoDB at 128 MiB and Aria unchanged. Verify all existing apps afterward. Do not promise 120 MiB physical savings.

Set journal limits to 14 days and 512 MiB after confirming retention tradeoffs. Leave swap and optional daemons unchanged. Application log retention is separate; propose bounded rotation and verify backup retention does not get conflated with logs.

## Backups and recovery

Daily backup scope is databases for all four apps, retaining existing working backup workflows where possible and adding SpaceTraders. Automated pre-migration backups apply to the new SpaceTraders pipeline only; existing apps’ deployment pipelines are unchanged except separately reviewed worker-control integration needed for scheduling changes. Before migrations, back up the database actually affected. Distinguish seven daily backup sets from three pre-migration backup sets per app. Verify fresh timestamps and nonempty, restorable data before deleting older sets. Stagger daily backups around existing jobs.

Provide a manual Mac download command using restricted SSH read access and atomic temporary downloads. Verify checksum before marking complete or pruning. A failed/partial download cannot remove good backups. Use stable unique names and a manifest. Server and Mac retention are independent; do not use destructive mirror deletion. No scheduled Mac job or notifications. Server-only backups do not protect against server loss while the Mac has not downloaded them; display last successful fetch in command output.

Before declaring ready, restore each backup format in a disposable environment and exercise SpaceTraders account/token decryption using a securely supplied matching key. Never activate restored gameplay automation. Confirm backup and application credentials can be recovered without exposing them in logs.

## Implementation order and acceptance

1. Verify the selected PHP 8.5 latest patch; verify source-control settings, DNS ownership and existing app queue/mail details read-only. Confirm runtime dependencies and backup scope.
2. Implement and pass CI, private account controls, drain/heartbeat protocol and deployment harness locally/in CI. No server changes yet.
3. Prepare reviewable server configuration, restricted deployment commands, backup scripts and rollback procedures. Obtain authorization for implementation/deployment when Taylor chooses to proceed; this conversation authorizes planning and review only.
4. Apply approved shared optimizations sequentially, checking existing sites and mail/background job behavior after each.
5. Provision SpaceTraders, DNS/TLS, account/secrets and database; deploy the first release through the same pipeline as later releases.
6. Prove a normal push deploys, a failing test cannot deploy, a failed health check restores the previous release, active jobs survive drain, and backup restoration works in isolation. Use synthetic fixtures for failure exercises rather than destructive production experiments.
7. Observe combined ordinary workload. Provisional acceptance floor: at least 150 MiB available memory during representative ordinary combined workload, with 200 MiB preferred, no sustained swap churn, no OOM events, prompt SpaceTraders scheduling and email pickup within one minute under normal load. Do not perform production load testing without separate authorization. If headroom is insufficient, reduce concurrency or revisit deferred tuning before adding resources.

Review status and accepted recommendations will be appended below. Passing plan review means the plan is ready for implementation discussion, not that the system is tested or deployed.


## Mechanisms added after Sonnet review

### Process control and drain proof

Use Laravel 13 `queue:pause database:spacetraders` and matching resume with a persistent file cache under shared storage. Extend `scripts/verify-mvp-worker.php`, which already exercises real workers and on-disk SQLite, to use this shared cache rather than array cache. Pause is cooperative, so a race-proof application gate and worker acknowledgment are also required, not just inspection of an empty jobs table.

Close the gameplay/dispatcher gate first, invoke schedule:interrupt for the current sub-minute runner, prevent the schedule:work parent from launching another runner, and wait for scheduler descendants and active dispatch commands to acknowledge completion. Interrupt alone does not stop schedule:work. Do this before stopping workers: the dispatcher can otherwise mark old sending actions uncertain. Pause new reservations, then require all of: no reserved jobs, no sending ship_actions, no held game locks, no active web writes, and an idle/paused acknowledgment from each worker. An existing uncertain action is preserved for reconciliation, never blindly retried.

Use a tested wrapper protocol that asks an idle worker to exit normally and waits for process exit before invoking Supervisor stop. Configure numprocs=1, stopsignal=TERM and stopwaitsecs at least 150 as defense in depth, but never rely on its eventual SIGKILL to enforce the ten-minute policy. If acknowledgment or clean exit fails, abort and leave the process alive; supervisorctl restart and SIGKILL are excluded from deployment. Keep autorestart=true for routine memory/max-time recycling; a root-owned wrapper holds in a deployment-stopped state so Supervisor cannot relaunch application code during the transaction. This deliberately avoids autorestart=unexpected, which would leave a normally recycled worker stopped. Test both normal recycling and deploy stop behavior. Alert diagnostically after 150 seconds of drain; abort at ten minutes and reopen pickup only after safely unwinding the gate.

### Deployment trust and ordering

Create a separate `spacetraders-deploy` account, not the existing deploy user that runs another app's scheduler. Reviewed deploy tooling lives outside artifacts, root-owned, with recorded hashes, and is installed/updated separately. CI cannot replace its own rollback implementation. Server configuration templates live in the repo but Nginx, Supervisor, sudoers and FPM installation is a separate reviewed setup operation.

Stream artifacts through a forced-command SSH key with restrict and no shell/forwarding. A fixed receiver validates protocol, SHA, sequence, size, paths and checksum, rejects archive traversal/symlink escapes, stages under the app's releases directory and invokes the transaction service. No GitHub credentials live on the server. The application user writes only shared runtime data and necessary per-release cache paths, never executable releases or deploy tooling. Use a narrow privileged helper rather than arbitrary sudo/supervisorctl: allow only start/stop/status for the two named SpaceTraders programs, gate-wrapper control and owned path operations. Routine deployments require no Nginx reload. Shared FPM reloads and package/config changes remain separate setup operations.

Define a versioned app/deployer contract, such as `spacetraders:gate close|open|status`, with documented drain acknowledgments and heartbeat readiness. Support the contract in current and rollback releases before enabling automatic deployments. Keep gate state in shared persistent storage, independent of disposable caches.

Use one stable workflow identity and its github.run_number as a monotonic sequence, paired with full commit SHA. Persist the last successful sequence and transaction state; reject older sequences and treat an identical completed sequence as an idempotent no-op. Changing the workflow identity requires an explicit migration of this counter. Immediately before transfer, query main HEAD through GitHub using the workflow token; skip superseded commits. Do not cancel an in-progress server transaction. Automatic failure rollback is an internal transaction action and retains the sequence audit; deliberate historical rollback is a separate authorized operation, not rerunning an old workflow. A later push arriving after preflight does not cancel the active release; its passing workflow deploys afterward.

### Cache correctness and compatibility

Use `$realpath_root$fastcgi_script_name` for Nginx SCRIPT_FILENAME and `$realpath_root` for DOCUMENT_ROOT so FPM sees the immutable release path. Verify OPcache timestamp and wasted-memory settings and rehearse switches; no claim of instant OPcache eviction for removed releases. If a graceful shared FPM reload is necessary, drain affected requests/jobs and treat it as shared maintenance. Every worker/scheduler start resolves current once and changes into that immutable release directory; no old process remains after switching.

Queued job payloads, sessions and persistent cache values must remain compatible across current and both retained rollback releases. Extend the harness to produce jobs with each release and consume them with adjacent forward/rollback code; test the second rollback target where it remains eligible. Use versioned disposable cache keys where necessary, and never flush deployment gate, pause state or game locks as part of cache maintenance. Database migrations must support every retained release advertised as a rollback target, not just the immediately previous version.

### Memory budget and bounded scope

Budget provisionally for an FPM child at 40–60 MiB, worker at 40 MiB, schedule:work parent at 40 MiB, schedule:run child at 40 MiB and transient dispatch command at 40 MiB. The resulting 200–220 MiB is an estimate derived from other apps, not measured SpaceTraders PSS. It leaves about 130–150 MiB from the historical 350 MiB headroom before other apps burst, so the 150 MiB acceptance floor is not assured. Measure savings and tune until the floor is met before enabling gameplay automation; do not treat the estimate as a passing result. Measure the complete process tree and shared-page accounting. Worker scheduling savings are material; do not claim the MyISAM allowance reduction guarantees physical RAM recovery. Serialize deployment Artisan commands after quiescence. Validate finite PHP limits against actual job peaks; Laravel --memory is checked between jobs, not during a job.

The disposable deployment harness can stub service-control commands with fault injection. Rehearse actual signal behavior and release-path handling with a real process stack in an isolated environment before provisioning; do not build a permanent staging service.

### Private account, secrets and backups

Disable public registration, guest password-reset and email-verification routes for this single-account MVP, along with their UI links; keep login throttling and authenticated password change. Use the secure administrative account command for password recovery. Test direct requests to disabled routes. No reset links/tokens may go to a log mailer. Configure a non-delivering mail transport for SpaceTraders while those mail flows are disabled; existing apps retain email functionality.

At provisioning, escrow APP_KEY and the production environment in Taylor's password manager through a secure entry flow, before storing any agent token. Verify retrieval and restore using a disposable environment without putting values in logs or artifacts. Secret escrow is separate from ordinary Mac database downloads.

“Deployment backup” means a backup before a pending SpaceTraders migration batch. A release without migrations creates none and does not prune that class. A fresh first deployment with no database skips its nonexistent pre-migration backup; create and validate a baseline backup afterward. Backups for existing MariaDB apps use checked exit statuses/pipefail, temporary files, atomic rename, protected credentials files and an appropriate consistent snapshot such as --single-transaction for transactional tables; inventory nontransactional tables and locks before claiming consistency.

For portfolio/Print For Me, prefer minimal existing-queue scheduled drains if inventory shows jobs finish quickly enough for one-minute email pickup. Use a non-overlap lock and bounded reservation duration while allowing an active job to finish. If long jobs can block email, separate email and bulk queues through reviewed changes in their own repositories, with independent locks/workers and five-minute bulk cadence. Update existing deploy scripts that assume removed Supervisor workers still exist. No unreviewed cross-repository code change is authorized by this plan.

Production environment contract: APP_ENV=production, APP_DEBUG=false, correct HTTPS APP_URL, secure cookies, file sessions and persistent file cache under shared storage, database queues, explicit shared game-lock path, fake provider disabled, automation initially disabled. Set SPACETRADERS_LOCK_PATH=/var/www/spacetraders/shared/storage/framework/game-locks; config/gameplay.php confirms this variable. Enable automation deliberately after account setup and acceptance, retaining explicit Start/Stop. Confirm the existing Certbot renewal mechanism includes the new hostname and test renewal without changing unrelated certificates.

## Review record

Sonnet 5.5 pass 1 requested changes. Incorporated B1–B4 and I1–I7 with these corrections: routine worker recycling retains autorestart=true behind a deployment-aware wrapper; cache generation happens after drain but before release switch; scheduler memory includes the schedule:run child. PHP 8.5 was subsequently selected by Taylor, including local `herd php` use. Pass 2 returned APPROVED, with no blocking findings. Its non-blocking clarifications on execution order, application-user permissions and the memory floor were incorporated afterward; those final editorial changes have not received a third review. No application, local runtime, CI or server changes have been made.


The deployment wrapper must stay alive while held and forward signals to its PHP child, or exec when appropriate for its control design; test process-tree exit and normal recycling so Supervisor never enters FATAL from deliberate hold behavior. Choose an explicit short dispatch overlap-lock expiry based on measured dispatch runtime and test expiry after a crash; do not inherit Laravel’s 24-hour default blindly. Pin Composer platform PHP deliberately to the same tested 8.5 patch used by CI and deployment preflight, updating it as part of the reviewed patch-upgrade process.
