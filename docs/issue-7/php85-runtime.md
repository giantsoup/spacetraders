# Verify the PHP 8.5 runtime

OPS01, issue [#7](https://github.com/giantsoup/spacetraders/issues/7), designated draft [PR #8](https://github.com/giantsoup/spacetraders/pull/8). Starting revision: `54e998d95ee02bb993c4de5d866c6fadf9cfcacf`. No hard prerequisites.

## Selected patch and sources

Verified on November 1, 2026:

- The [official PHP release feed](https://www.php.net/releases/index.php?json&version=8.5) identifies **8.5.12**, released October 21, as the latest stable 8.5 patch. PHP 8.5 has [active support through December 31, 2027](https://www.php.net/supported-versions.php).
- The installed macOS arm64 Herd `php85` reports PHP 8.5.12, built October 21 by Laravel Herd. `codesign --verify --strict` passes. Its signing authority is Beyond Code GmbH, team `8Z259RPWAC`. Its SHA-256 is `d6dd0a0250bde49c73a10889548a5a4c9778947c16c15823b39d577fd5f97ec7`. This identifies the tested vendor binary; it is not the checksum of PHP's source archive.
- Herd's [PHP management documentation](https://herd.laravel.com/docs/macos/technology/php-versions) distinguishes global and per-site selection. This work uses an explicit process executable. It does not install or update Herd PHP, change its global default, isolate the primary site, or reload shared FPM.
- The selected runtime follows the approved deployment plan at [immutable PR #2 revision](https://github.com/giantsoup/spacetraders/blob/a4a80d767bf2ffd58ff2d030fc84d2b9f0dda06f/docs/server-deployment-plan.md#L28). The plan is a requirement, not evidence of deployment.

`.php-version` pins 8.5.12. Composer requires `~8.5.12` and sets platform PHP to 8.5.12. The lock records those same values. CI reads `.php-version`; server preflight and native engine installation enforce the exact tested patch and required extensions. A later patch requires updating these pins together and rerunning verification.

Composer 2.10.3 resolved the lock under Herd PHP 8.5.12 with `./scripts/composer update --lock --no-interaction --minimal-changes`. No platform bypass was used. All 80 production and 43 development package records are unchanged. Only the root PHP requirements and content hash changed. Playwright 1.64.1 and its matching core are the only added frontend packages, making the existing browser checks reproducible. Node remains 26.13.0 and pnpm remains 12.16.1.

PHP 8.5 deprecates the old MySQL PDO driver constant. `config/database.php` uses `Pdo\Mysql::ATTR_SSL_CA` under the existing extension guard. No database connection or TLS policy changes. The [PHP 8.5 migration guide](https://www.php.net/manual/en/migration85.php) remains part of runtime upgrade review.

## Run verification in an owned checkout

Use separate dependencies and disposable databases, keys, locks, storage, browser profiles and service identities. Do not copy another checkout's environment or live state.

```bash
export PHP_BIN="$PWD/scripts/herd-php"
export PLAYWRIGHT_BROWSERS_PATH="$PWD/storage/app/mvp-evidence/browsers"
"$PHP_BIN" -v
./scripts/composer install --no-interaction --prefer-dist
./scripts/composer validate --strict
./scripts/composer check-platform-reqs
pnpm install --frozen-lockfile --strict-peer-dependencies
node node_modules/playwright/cli.js install chromium
./scripts/verify.sh
"$PHP_BIN" scripts/verify-mvp-worker.php
"$PHP_BIN" scripts/verify-automation-worker.php
node scripts/verify-presentation-browser.mjs
node scripts/verify-tutorial-browser.mjs
for scenario in mining setup uncertain rejected recovery preparation; do
    QA_GUIDED_SCENARIO="$scenario" node scripts/verify-guided-browser.mjs
done
```

On macOS, also run these owned native checks:

```bash
python3 scripts/verify-herd-launchd.py
"$PHP_BIN" scripts/verify-local-engine.php
node scripts/verify-automation-browser.mjs
```

The FPM probe uses Herd's existing `herd85.sock`, checks the same exact patch, creates one inert temporary launchd service, then verifies its removal. `HERD_PHP_SOCKET` can select another explicitly owned socket. The native engine harness installs and removes only its checkout/database-derived service. It exercises actual workers and schedulers, crashes, Stop and non-replay, while preserving an unrelated sentinel PHP process. No sleep or wake operation is performed on the Mac; a heartbeat gap is modeled in disposable state.

`PHP_BIN` is one executable path. `scripts/herd-php` uses Herd's installed `php85`; it accepts quoted arguments and paths containing spaces. `scripts/composer` invokes the Composer PHAR with that same PHP. Set `COMPOSER_BIN` for a nonstandard PHAR location or `HERD_PHP85_BIN` for a nonstandard Herd binary. Do not pass the string `herd php` as an executable name. The runtime regression check tests real execution, stale lock rejection, patch mismatch, argument preservation and a missing binary.

The Ubuntu and macOS CI jobs use read-only repository permissions, immutable action commits, isolated fake-provider resources and the same PHP patch. macOS also runs native engine and Start/Stop browser checks. They do not deploy or receive application credentials. CI execution on hosted runners must be checked independently of local results. The first workflow failed validation because `runner.temp` is unavailable in job-level environment expressions. The corrected browser path uses `github.workspace`; actionlint 1.7.12 reproduces the original error and passes the correction.

## Read-only server preflight

Run this only in an approved release context with its own dependencies:

```bash
PHP_BIN=/absolute/server/php8.5 COMPOSER_BIN=/absolute/composer.phar ./scripts/server-preflight.sh
```

It checks the actual CLI patch, matching Composer pins, worker/SQLite extensions and installed production platform requirements without booting Laravel, opening a database, migrating, installing packages or contacting the game API. The server's web/FPM, worker and scheduler executables must each match the tested patch; a CLI result alone does not establish FPM or service configuration.

No server package source or installed patch was inspected during this offline task. Before any package change, separately authorize and complete the following shared-host review:

1. Inventory the actual OS, package repository/signing source, installed PHP CLI/FPM/extensions, service executable paths and configuration. Record exact previous package versions and retain their installable packages and configuration backups.
2. Check deployed lockfiles and actual platform requirements for FishCounts, Print For Me, the portfolio and SpaceTraders under the proposed executable. Run each app's relevant HTTP, queue, scheduler and mail smoke checks in approved isolated resources. Local SpaceTraders checks do not establish their compatibility.
3. Review PHP 8.5 backward incompatibilities and deprecations for those apps. Prepare the exact package/configuration restoration commands for the actual host. Rehearse restoration in an isolated matching OS before changing shared packages.
4. Drain affected work without killing active jobs, preserve pending jobs and uncertain intents, and obtain approval for the concrete package/service change. Recheck all affected apps afterward. A failed compatibility or rollback check blocks the change.

## Rollback

No schema migration is added by OPS01. Restore the previous application source, Composer lock and matching PHP 8.4 runtime together. The new lock deliberately refuses PHP 8.4. Retain the existing APP_KEY, database, durable pending/uncertain actions, queue, locks and session state. Do not migrate down, copy fixture state into a live database, clear jobs or replay uncertain writes.

The following application rollback was tested in a separate disposable archive with its own vendor installation. The original baseline also passed the full suite before editing. These commands are a rehearsal, not authorization to change an existing site:

```bash
rollback_dir="$(mktemp -d -t ops01-rollback)"
git archive 54e998d95ee02bb993c4de5d866c6fadf9cfcacf | tar -x -C "$rollback_dir"
cd "$rollback_dir"
php84="${HOME}/Library/Application Support/Herd/bin/php84"
composer_phar="${HOME}/Library/Application Support/Herd/bin/composer"
"$php84" "$composer_phar" install --no-interaction --prefer-dist
"$php84" "$composer_phar" check-platform-reqs
"$php84" scripts/verify-mvp-worker.php
```

The archived lock installs under Herd PHP 8.4.27 and passes actual platform checks. Its real-worker harness confirms duplicate delivery, restart recovery, uncertain-write non-replay and persisted Stop. These checks use fresh synthetic databases. Compatibility of a real shared database, deployed queued payloads, service configuration and server package restoration remains a separate operational gate. No live application or server rollback was performed.

## Evidence and remaining gates

The [evidence directory](evidence/2026-11-01-ops01/) contains sanitized command transcripts and dark rendered CLI images. These are backend runtime evidence, not application UI changes. Both use a 1120 by 760 viewport and the same 1120 by 500 crop. Generate them from the captured summaries with:

```bash
node docs/issue-7/render-evidence.mjs docs/issue-7/evidence/2026-11-01-ops01
```

The original full PHP 8.4 run passed 293 tests and 1,528 assertions. Full PHP 8.5 runs also pass. QA01 remains intermittent: the unchanged repeated quote-invalidation test fails at line 561 on both independently installed runtimes. See the preserved repeat logs. Do not treat one passing full suite as resolution of [QA01 #3](https://github.com/giantsoup/spacetraders/issues/3).

The first tutorial browser run on PHP 8.5 hit `net::ERR_ABORTED` when the harness navigated before Dashboard's Inertia reload finished. A later unmodified run passed. The harness now waits for the visible Synchronize control to become enabled after the reload, rather than treating a POST response as UI completion. Both the original failure and final result are retained.

Keep PR #8 draft while the recorded verification gaps and hosted CI are reviewed. Shared-host package compatibility and restoration, production provisioning, live gameplay and deployment require their own approved execution. No independent model review, live game action, paid model call, primary-site runtime change or server modification was performed.
