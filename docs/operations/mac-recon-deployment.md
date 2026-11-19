# Deploy and activate reconnaissance on the Mac

This procedure requires Taylor's approval for the reviewed release, merge, deployment and reconnaissance activation. Recon intent is already authorized. No command in this procedure has been run against production for this change.

Use a maintenance window that permits a short interruption to **all Herd PHP sites**. The installed Herd CLI exposes `stop php` and `start php`; it has no verified per-SpaceTraders PHP stop. Nginx, DNS and Tailscale stay running. If the shared PHP interruption is unacceptable, revise and review this procedure before deployment.

## Prepare the release

1. Finish implementation council review and all checks. From development, inspect `git log origin/main..HEAD` before shipping. Ask before including another session's commits. Use `gh` as `giantsoup` to push and merge the reviewed change. Record the approved merged SHA.
2. Recheck production identity and process ownership at execution time. Saved facts identify `/Users/homemac/Sites/spacetraders`, the user launchd engine, Herd PHP 8.5, and `local.spacetraders.campaign-watchdog`. They do not certify later runtime health.
3. Keep the existing `.env`, encryption key, SQLite, queue, cache, storage, deployment gate, lock and request-budget paths. Do not copy credentials into development or print the private engine plist. Keep Herd Developer Tools off.
4. Use a clean production `main`. Stop on unrelated changes, an unknown worker, uncertainty, an unhealthy engine, or an unreviewed incoming commit. Do not automatically approve, pause, revoke or replace C0's grant.

Set these nonsecret variables in the approved deployment shell:

```bash
set -euo pipefail
umask 077
PROD_ROOT='/Users/homemac/Sites/spacetraders'
export PHP_BIN='/Users/homemac/Library/Application Support/Herd/bin/php85'
HERD_BIN='/Users/homemac/Library/Application Support/Herd/bin/herd'
export DB_SOURCE="$PROD_ROOT/database/database.sqlite"
TARGET_SHA='REPLACE_WITH_APPROVED_MERGED_SHA'
ATTEMPT="mac-recon-$(date -u +%Y%m%dT%H%M%SZ)-$(uuidgen)"
export BACKUP_DIR="$HOME/Library/Application Support/SpaceTraders/deployment-backups/$ATTEMPT"
WATCHDOG_PLIST="$HOME/Library/LaunchAgents/local.spacetraders.campaign-watchdog.plist"
mkdir -m 700 -p "$BACKUP_DIR"
cd "$PROD_ROOT"
test "$(git branch --show-current)" = main
git status --porcelain --untracked-files=all
PREVIOUS_SHA="$(git rev-parse HEAD)"
git fetch origin main
test "$(git rev-parse origin/main)" = "$TARGET_SHA"
git merge-base --is-ancestor HEAD "$TARGET_SHA"
git log --oneline --no-decorate "HEAD..$TARGET_SHA"
"$PHP_BIN" scripts/runtime-preflight.php
"$PHP_BIN" artisan spacetraders:engine-service status
"$PHP_BIN" artisan campaign:status
```

Require the approved commit list, a clean checkout, the pinned PHP, Node and pnpm versions, and a healthy running owned engine. Record C0's grant, revision, episode and pause state privately. Engine status bootstraps the app and can record a stale-engine fault; these are execution steps, not offline probes. If the engine is already off, coordinate its startup within the approved window before attempting the live-worker drain.

## Drain and stop the old generation

Follow the [deployment gate contract](../issue-11/deployment-gates.md). Close while the engine is still alive:

```bash
"$PHP_BIN" artisan spacetraders:deployment close --generation="$ATTEMPT" --wait > "$BACKUP_DIR/closed.json"
"$PHP_BIN" artisan spacetraders:deployment status
```

Require exit 0, the exact generation, `phase=closed`, `drained=true` and no blockers. A live idle worker and a scheduler observation must acknowledge this generation. On failure, inspect status and stop the deployment. Do not migrate, clear queues, delete gate files or force-release leases. An incomplete drain expires after 600 seconds; a successfully closed gate has no automatic reopen deadline.

Then stop only the identified owners:

```bash
"$PHP_BIN" artisan spacetraders:engine-service end
"$PHP_BIN" artisan spacetraders:engine-service status
"$PHP_BIN" artisan spacetraders:engine-service uninstall
launchctl bootout "gui/$(id -u)/local.spacetraders.campaign-watchdog"
"$HERD_BIN" stop php
ps -axo pid,ppid,comm
```

Require the engine to reach `off`, its supervisor/worker/scheduler to exit, and the watchdog label to be absent. Check for an already-running process whose exact arguments invoke production `artisan campaign:watchdog`; wait for it to finish before changing source. Verify that every old Herd PHP-FPM master and child has exited. Inspect nonzero lifecycle results instead of assuming success. Preserve the watchdog plist.

End invalidates pending intents and stops plain runs. Retained receipts remain uncertain and require reconciliation. C0's approved, unpaused grant survives and can recover cargo or create fresh plans after reopening. A campaign safety pause still requires operator review. Plain recon does **not** auto-resume after End; it requires explicit `recon:start` under the same mission ledger.

The closed gate remains closed after workers exit. `drained=false` while no worker exists is expected; do not open to remove that message.

## Back up consistently

Use SQLite's backup API after drain and shutdown. Never copy a live main file while ignoring its WAL, or use `immutable=1` on live SQLite.

```bash
"$PHP_BIN" <<'PHP'
<?php
umask(0077);
$source = new SQLite3(getenv('DB_SOURCE'), SQLITE3_OPEN_READONLY);
$source->enableExceptions(true);
$source->busyTimeout(5000);
$path = getenv('BACKUP_DIR').'/database.sqlite';
if (file_exists($path)) {
	throw new RuntimeException('Refusing to overwrite a backup.');
}
$destination = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
$destination->enableExceptions(true);
if (!$source->backup($destination)) {
	throw new RuntimeException('Backup failed. Keep admission closed.');
}
$destination->close();
$source->close();
chmod($path, 0600);
$check = new SQLite3($path, SQLITE3_OPEN_READONLY);
if ($check->querySingle('PRAGMA integrity_check') !== 'ok') {
	throw new RuntimeException('Backup integrity failed.');
}
$check->close();
echo "SQLite backup integrity: ok\n";
PHP
cp -p .env "$BACKUP_DIR/environment.private"
chmod 600 "$BACKUP_DIR/environment.private"
printf '%s\n' "$PREVIOUS_SHA" > "$BACKUP_DIR/previous-sha.txt"
printf '%s\n' "$TARGET_SHA" > "$BACKUP_DIR/target-sha.txt"
```

Keep both backups private in the mode-0700 directory. They contain credentials or decryption material. Do not attach them to review. This backup does not authorize restoring old state after external game actions.

## Install with reconnaissance disabled

Fast-forward only to the approved SHA. If generated `public/build` files prevent the fast-forward, inspect and clean only that generated path before retrying. Preserve all persistent state.

```bash
git merge --ff-only "$TARGET_SHA"
test "$(git rev-parse HEAD)" = "$TARGET_SHA"
"$PHP_BIN" scripts/runtime-preflight.php
```

Edit only these nonsecret settings in the existing production `.env` through the private configuration editor:

```dotenv
SPACETRADERS_RELEASE=REPLACE_WITH_APPROVED_MERGED_SHA
SPACETRADERS_GATE_RECON_ENABLED=false
```

Keep the same `APP_ENV=local`, credentials and persistent paths. Do not source or dump `.env`.

```bash
"$PHP_BIN" artisan config:clear
PHP_BIN="$PHP_BIN" scripts/composer install --no-interaction --prefer-dist --optimize-autoloader
pnpm install --frozen-lockfile --strict-peer-dependencies
"$PHP_BIN" artisan migrate --force --no-interaction
pnpm build
"$PHP_BIN" artisan optimize:clear --except=cache
"$PHP_BIN" artisan migrate:status --no-interaction
test -s public/build/manifest.json
test ! -e public/hot
"$PHP_BIN" artisan spacetraders:deployment status
```

The migration is additive. Stop on failure without a down migration or database restore. Plain `optimize:clear` also clears shared cache; retain `--except=cache`. Do not run `cache:clear`, `schedule:clear-cache`, `queue:clear` or queue replay. If `public/hot` exists, identify and stop its owned production Vite process before removing only that file. Never start Vite in production.

## Verify the candidate before reopening

Start fresh web and engine processes while the gate stays closed:

```bash
"$HERD_BIN" start php
"$PHP_BIN" artisan spacetraders:engine-service install
"$PHP_BIN" artisan spacetraders:engine-service start
"$PHP_BIN" artisan spacetraders:engine-service status
"$PHP_BIN" artisan spacetraders:deployment status > "$BACKUP_DIR/candidate-status.json"
```

Require new FPM PIDs using the expected 8.5 configuration/socket, no old PHP generation, and engine state `running`. Reinstallation is required: the private engine registration captures release, flag and persistent identity. A restart of an old registration can retain old configuration.

Inspect candidate gate status before every open. Require:

- the same closed generation, `drained=true`, and no blockers;
- a fresh live idle engine worker with `release=$TARGET_SHA` and `ack=$ATTEMPT`;
- a fresh scheduler observation for that same release and generation after candidate startup;
- no live old supervisor, worker, scheduler or web generation.

Correlate worker/scheduler PIDs with the new engine. The scheduler's completed closed-gate observation can have `finished=true`; the worker must still have a live idle lease. `open` itself does not enforce the desired release, so this operator check is required.

Use a narrowly scoped CLI bootstrap to print only the release and flag if needed. Never use `config:show`, `phpinfo`, a plist dump or a public diagnostic endpoint. Fresh FPM processes, cleared configuration, the same checkout and gate responses establish the web generation; CLI `opcache_reset` cannot clear FPM's cache.

Require HTTP 503 with `Retry-After: 5` for `/login` through all configured routes while closed:

```bash
curl --silent --show-error --max-time 60 -D "$BACKUP_DIR/local-closed.headers" -o /dev/null http://spacetraders.test/login
curl --silent --show-error --max-time 60 -D "$BACKUP_DIR/proxy-closed.headers" -o /dev/null -H 'Host: spacetraders.chimp-hamal.ts.net' http://127.0.0.1:8081/login
curl --silent --show-error --max-time 60 -D "$BACKUP_DIR/tailnet-closed.headers" -o /dev/null https://spacetraders.chimp-hamal.ts.net/login
curl --silent --show-error --max-time 60 -D "$BACKUP_DIR/mac-tailnet-closed.headers" -o /dev/null https://homemacs-mac-mini.chimp-hamal.ts.net/login
```

Verify that each name is still configured at execution time. Fetch actual JS/CSS URLs from the candidate build manifest through local and tailnet routes and require 200. Static assets do not prove PHP readiness.

Restore the independent watchdog only after candidate configuration is ready:

```bash
launchctl bootstrap "gui/$(id -u)" "$WATCHDOG_PLIST"
```

Verify its root and interval are unchanged. `RunAtLoad=true` can immediately run the watchdog and send an alert.

## Open, check and activate

```bash
"$PHP_BIN" artisan spacetraders:deployment open --generation="$ATTEMPT"
"$PHP_BIN" artisan spacetraders:deployment status
"$PHP_BIN" artisan spacetraders:engine-service status
"$PHP_BIN" artisan campaign:status
```

Require `phase=open`, `outcome=opened`, healthy engine and the original C0 grant/revision/episode. Check `/login` for exactly HTTP 200 through all four configured routes and fetch its built assets. Reject Vite URLs or unexpected redirects. Reopening permits C0 to act; this is not a game-free health check. Preserve any pre-existing queue pause.

To enable recon, use a fresh generation and repeat drain, End, uninstall and web/watchdog quiescence. Change only `SPACETRADERS_GATE_RECON_ENABLED=true`, clear config, restart fresh FPM, reinstall/start the engine, verify new release/flag and generation acknowledgements, restore watchdog and reopen. This second controlled restart also ends plain runs and lets C0 replan.

Then use the implemented interfaces:

```bash
"$PHP_BIN" artisan recon:approve --agent=MACSOUP --ship=MACSOUP-2 --user=1 --dry-run
"$PHP_BIN" artisan recon:approve --agent=MACSOUP --ship=MACSOUP-2 --user=1
"$PHP_BIN" artisan recon:status
"$PHP_BIN" artisan recon:start MISSION_ID_FROM_APPROVAL
"$PHP_BIN" artisan recon:status MISSION_ID_FROM_APPROVAL
"$PHP_BIN" artisan recon:report MISSION_ID_FROM_APPROVAL
```

Confirm operator 1 still owns MACSOUP. Compare printed terms and hash before starting. The default envelope uses current credits minus 100,000, fixed at approval; historical 74,781 is not a current balance. Defaults are 7,500 per jump, exploration until reset minus six hours, return until reset minus two hours, 250 CR/min and 2,000 CR per conditional repeat load. A dry-run uses real reads and updates observations, but creates no mission or game action. If terms change between dry-run and approval, inspect them again.

Start refuses any active scout, transit, unresolved action or busy owner. Let the old scout settle; do not bypass ownership. The first module-less jump is a bounded trial with unknown server acceptance. Known 4210/4229 at home ends it without retry or purchases. A local exposure is not a server fee cap. Overcharges halt recon; uncertainty follows the existing global hold and no-replay rules. Existing acknowledgement stops all active agent runs, including C0's, without changing its grant.

## Resume or recover without losing receipts

After End or a provably never-sent refusal, inspect `recon:status` and explicitly run `recon:start MISSION_ID`. The new run retains the mission ledger, fixed envelope, return authorizations and deadlines. No old intent is replayed.

An ordinary temporary-cash return may continue exploration after reaching home and recovering funds. If End interrupted that return, explicit `recon:start` creates a fresh continuation run funded from the remaining fixed mission envelope. It does not enlarge the envelope or alter the authorized home path. Overcharge and explicit rescue resumes are returning-only. They clear `cash_wait`, close without claiming an exhausted frontier, and cannot begin another exploration leg on the same mission.

An uncertain jump can be reconciled without proving its fee. Acknowledgement alone does not resume it. After fresh reconciliation and explicit acknowledgement, review any unexplained credit adjustment through the existing balance-review flow. If the probe is abroad, approve a new homeward-only authorization whose cursor covers that acknowledged action, then start a fresh return run:

```bash
"$PHP_BIN" artisan recon:authorize-return MISSION_ID --jump-cap=7500 --total-exposure=REVIEWED_REMAINING_HOME_CAPS --until=REVIEWED_UTC_DEADLINE --user=1 --dry-run
"$PHP_BIN" artisan recon:authorize-return MISSION_ID --jump-cap=7500 --total-exposure=REVIEWED_REMAINING_HOME_CAPS --until=REVIEWED_UTC_DEADLINE --user=1
"$PHP_BIN" artisan recon:start MISSION_ID
```

Replace the named values with the reviewed home path's caps and deadline. They are not shell variables or automatic permission to enlarge authority. The operator approval must follow the action's reconciliation and acknowledgement. The unresolved jump remains counted at its full cap in the mission ledger; it is never replayed or assigned an invented receipt. This approval permits returning only, including after a temporary-cash hold. If the probe is already confirmed home with no unresolved action, `recon:cancel MISSION_ID` closes the mission instead of authorizing another flight.

A confirmed-home edge case remains: if End occurs after the final hop consumes all return authority but before the closing tick, `recon:start` can report stranded even though the probe is home. Confirm fresh home position and no unsettled action, capture `recon:report MISSION_ID`, then use `recon:cancel MISSION_ID`. Cancellation changes the top-level outcome to `cancelled`; promising visit evidence and all receipts remain. It authorizes no additional flight.

Keep the feature flag on until every mission is closed and the probe is confirmed home. Flag-off abroad blocks homeward jumps too, while loan and protected return funds remain active. Cancellation abroad means returning, not abandonment. Only an explicit operator-approved `recon:authorize-return` can raise homeward caps; it cannot finance new exploration.

If the probe is abroad, state is uncertain, or return funds remain reserved, keep code that understands those records. Hold and roll forward. Do not roll back to pre-recon code, drop mission tables, or restore a pre-action database over real receipts.

A code rollback requires confirmed home position, no open mission/hold/return exposure, no unsettled or review-required action, reconciled receipts, and proven compatibility with the retained additive schema and stopped v5 rows. Ship a reviewed revert on `main`, then repeat this same drain/backup/install/acknowledgement procedure with recon off. Keep all durable receipts and schema. If compatibility is unproved, roll forward instead.

Source checks for this procedure used the installed Herd CLI/SDEF, selected nonsecret LaunchAgent fields, and the repository's deployment and engine code. Host lifecycle, route health and live jump acceptance remain execution-time checks. They were not exercised during implementation.
