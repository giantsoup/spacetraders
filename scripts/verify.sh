#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

php_bin="${PHP_BIN:-php}"
pnpm_bin="${PNPM_BIN:-pnpm}"
"$php_bin" -r 'exit(PHP_VERSION_ID >= 80401 && PHP_VERSION_ID < 80500 ? 0 : 1);'
[[ "$("$pnpm_bin" --version)" == "12.16.1" ]]
PNPM_BIN="$pnpm_bin" node scripts/verify-build-sources.mjs
node scripts/verify-store.mjs
"$php_bin" vendor/bin/phpunit --display-all-issues
"$php_bin" vendor/bin/pint --test \
    app/Services/SpaceTradersClient.php \
    app/Http/Controllers/AgentController.php \
    app/Http/Controllers/ForwarderController.php \
    app/Models/Agent.php app/Exceptions/Handler.php routes/web.php \
    app/Http/Kernel.php app/Http/Middleware/HandleInertiaRequests.php \
    app/Http/Middleware/PreventRequestForgery.php \
    config/cache.php config/inertia.php config/sanctum.php config/session.php \
    tests/Feature/AgentTest.php tests/Feature/ForwarderTest.php \
    tests/Feature/UpgradeCompatibilityTest.php
git diff --check
