#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${PHP_BIN:?Set PHP_BIN to the server executable used by FPM, worker and scheduler.}"
"$PHP_BIN" scripts/runtime-preflight.php
./scripts/composer check-platform-reqs --no-dev
