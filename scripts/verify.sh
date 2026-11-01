#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

php_bin="${PHP_BIN:-php}"
pnpm_bin="${PNPM_BIN:-pnpm}"
export PHP_BIN="$php_bin"
"$php_bin" scripts/runtime-preflight.php
python3 scripts/verify-runtime.py
[[ "$("$pnpm_bin" --version)" == "12.16.1" ]]
PNPM_BIN="$pnpm_bin" node scripts/verify-build-sources.mjs
node scripts/verify-store.mjs
node scripts/verify-gameplay.mjs
node scripts/verify-local-time.mjs
node scripts/verify-tutorial.mjs
node scripts/verify-guided-flight.mjs
node --check scripts/verify-tutorial-browser.mjs
node --check scripts/verify-guided-browser.mjs
node --check scripts/verify-mvp-browser.mjs
node --check scripts/verify-pages-browser.mjs
node --check scripts/verify-presentation-browser.mjs
node --check scripts/verify-agents-browser.mjs
node --check scripts/verify-modal.mjs
node --check scripts/verify-automation-browser.mjs
node --check scripts/verify-opportunities-browser.mjs
"$php_bin" scripts/verify-environment.php
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
    app/Gameplay app/Enums app/Jobs app/Console app/Http/Requests/GameplayRequest.php app/Http/Requests/LocalAutomationRequest.php \
    app/Http/Controllers/AgentDashboardController.php app/Http/Controllers/AgentCommandController.php \
    app/Http/Controllers/ProfileController.php app/Http/Controllers/AutomationRunController.php app/Http/Controllers/LocalAutomationController.php app/Models app/Providers/AppServiceProvider.php app/Providers/RouteServiceProvider.php \
    config/gameplay.php config/database.php config/queue.php \
    database/migrations/2026_10_31_00000*_*.php database/migrations/2026_11_01_*.php \
    tests/Feature/GameplayTest.php tests/Feature/PlayerGuideTest.php tests/Feature/GameConnectionTest.php app/Http/Controllers/PlayerGuideController.php tests/Support tests/TestCase.php \
    tests/Feature/AgentArchiveTest.php tests/Feature/LocalAutomationTest.php tests/Feature/OpportunityTest.php tests/Feature/RequestBudgetTest.php tests/Feature/SQLiteConcurrencyTest.php tests/Feature/BudgetResponseTest.php \
    scripts/mvp-fixture.php scripts/verify-mvp-worker.php scripts/verify-environment.php scripts/verify-local-engine.php scripts/verify-automation-worker.php scripts/runtime-preflight.php
git diff --check
