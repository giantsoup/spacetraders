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
node scripts/verify-rate-limit.mjs
node --check scripts/verify-rate-limit-browser.mjs
node scripts/verify-automation-selection.mjs
node scripts/verify-ship-presentation.mjs
node --test scripts/verify-system-map.mjs
node --check scripts/system-map-browser-probes.js
node --check scripts/verify-system-map-browser.mjs
node --check scripts/verify-system-map-actions-browser.mjs
node --check scripts/verify-native-ship-browser.mjs
node --check scripts/verify-ship-https-browser.mjs
node --check scripts/ship-browser-navigation.mjs
node scripts/verify-tutorial.mjs
node scripts/verify-guided-flight.mjs
node --test scripts/verify-verification-lifecycle.mjs
node --check scripts/browser-verification.mjs
node --check scripts/verify-tutorial-browser.mjs
node --check scripts/verify-guided-browser.mjs
node --check scripts/verify-mvp-browser.mjs
node --check scripts/verify-pages-browser.mjs
node --check scripts/verify-presentation-browser.mjs
node --check scripts/verify-agents-browser.mjs
node --check scripts/verify-private-account-browser.mjs
python3 -c "import ast; ast.parse(open('scripts/verify-operator-terminal.py').read())"
node --check scripts/verify-modal.mjs
node --check scripts/verify-automation-browser.mjs
node --check scripts/verify-opportunities-browser.mjs
node --check scripts/verify-deployment-browser.mjs
node --check scripts/verify-adviser-browser.mjs
node --check scripts/verify-exploration-browser.mjs
node --check scripts/verify-batch2-browser.mjs
"$php_bin" scripts/verify-environment.php
"$php_bin" vendor/bin/phpunit --display-all-issues
"$php_bin" vendor/bin/pint --test \
    app/Services/SpaceTradersClient.php \
    app/Http/Controllers/AgentController.php \
    app/Http/Controllers/ForwarderController.php \
    app/Models/Agent.php app/Exceptions/Handler.php routes/web.php \
    app/Http/Kernel.php app/Http/Middleware/HandleInertiaRequests.php \
    app/Http/Middleware/PreventRequestForgery.php \
    routes/auth.php config/mail.php app/Http/Controllers/Auth/AuthenticatedSessionController.php tests/Feature/Auth/PrivateAccountTest.php tests/Feature/Auth/OperatorAccountTest.php \
    config/cache.php config/inertia.php config/sanctum.php config/session.php \
    tests/Feature/AgentTest.php tests/Feature/ForwarderTest.php \
    tests/Feature/UpgradeCompatibilityTest.php \
    app/Maps app/Http/Controllers/SystemMapController.php tests/Feature/SystemMapTest.php scripts/system-map-fixture.php scripts/system-map-action-fixture.php \
    app/Gameplay app/Enums app/Jobs app/Queue app/Console app/Http/Middleware/DeploymentAdmission.php app/Http/Requests/GameplayRequest.php app/Http/Requests/LocalAutomationRequest.php \
    app/Http/Controllers/AdviserController.php app/Http/Requests/AdviserRequest.php tests/Feature/AdviserTest.php \
    app/Http/Controllers/AgentDashboardController.php app/Http/Controllers/AgentCommandController.php \
    app/Http/Controllers/ProfileController.php app/Http/Controllers/AutomationRunController.php app/Http/Controllers/LocalAutomationController.php app/Models app/Providers/AppServiceProvider.php app/Providers/RouteServiceProvider.php \
    config/gameplay.php config/database.php config/queue.php config/deployment.php \
    database/migrations/2026_10_31_00000*_*.php database/migrations/2026_11_01_*.php \
    tests/Feature/GameplayTest.php tests/Feature/PlayerGuideTest.php tests/Feature/GameConnectionTest.php app/Http/Controllers/PlayerGuideController.php tests/Support tests/TestCase.php \
    tests/Feature/AgentArchiveTest.php tests/Feature/LocalAutomationTest.php tests/Feature/OpportunityTest.php tests/Feature/RequestBudgetTest.php tests/Feature/RateLimitStatusTest.php app/Http/Controllers/RateLimitController.php tests/Feature/SQLiteConcurrencyTest.php tests/Feature/BudgetResponseTest.php \
    tests/Feature/DeploymentGateTest.php scripts/verify-deployment.php \
    tests/Feature/CycleEconomicsTest.php \
    tests/Feature/ExplorationTest.php \
    scripts/mvp-fixture.php scripts/verify-mvp-worker.php scripts/verify-environment.php scripts/verify-local-engine.php scripts/verify-automation-worker.php scripts/runtime-preflight.php
git diff --check
