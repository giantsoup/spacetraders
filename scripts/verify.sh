#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

php_bin="${PHP_BIN:-php}"
pnpm_bin="${PNPM_BIN:-pnpm}"
"$php_bin" -r 'exit(PHP_VERSION_ID >= 80401 && PHP_VERSION_ID < 80500 ? 0 : 1);'
[[ "$("$pnpm_bin" --version)" == "12.16.1" ]]
PNPM_BIN="$pnpm_bin" node scripts/verify-build-sources.mjs
node scripts/verify-store.mjs
node scripts/verify-gameplay.mjs
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
    tests/Feature/UpgradeCompatibilityTest.php \
    app/Gameplay app/Enums app/Jobs app/Console app/Http/Requests/GameplayRequest.php \
    app/Http/Controllers/AgentDashboardController.php app/Http/Controllers/AgentCommandController.php \
    app/Http/Controllers/ProfileController.php app/Http/Controllers/AutomationRunController.php app/Models app/Providers/AppServiceProvider.php \
    config/gameplay.php config/database.php config/queue.php \
    database/migrations/2026_10_31_00000*_*.php \
    tests/Feature/GameplayTest.php tests/Support tests/TestCase.php \
    scripts/mvp-fixture.php scripts/verify-mvp-worker.php
git diff --check
