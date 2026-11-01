#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php_bin="${PHP_BIN:-php}"
# Every runtime/browser script below creates its own disposable SQLite database and fake provider.
# launchd tests bootstrap only temporary checkout/database identities and remove them on exit.
bash scripts/verify.sh
composer validate --strict
composer check-platform-reqs
python3 scripts/verify-herd-launchd.py
"$php_bin" scripts/verify-mvp-worker.php
"$php_bin" scripts/verify-automation-worker.php
"$php_bin" scripts/verify-local-engine.php
node scripts/verify-presentation-browser.mjs
node scripts/verify-automation-browser.mjs
