#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

npm run build
"${PHP_BIN:-php}" vendor/bin/phpunit
"${PHP_BIN:-php}" vendor/bin/pint --test \
    app/Services/SpaceTradersClient.php \
    app/Http/Controllers/AgentController.php \
    app/Http/Controllers/ForwarderController.php \
    app/Models/Agent.php app/Exceptions/Handler.php routes/web.php \
    tests/Feature/AgentTest.php tests/Feature/ForwarderTest.php
git diff --check
