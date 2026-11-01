# Reproduce local combined verification

Use this integration worktree with owned locked dependencies. These commands do not configure the primary Herd site.

1. Select the checkout's PHP wrapper and a pinned pnpm executable.

```bash
	export PHP_BIN="$PWD/scripts/herd-php"
	export PNPM_BIN="/absolute/path/to/pnpm-12.16.1"
	"$PHP_BIN" -v
	"$PNPM_BIN" --version
```

2. Install locked dependencies into this checkout.

```bash
	./scripts/composer install --no-interaction --prefer-dist
	"$PNPM_BIN" install --frozen-lockfile --strict-peer-dependencies
	export PLAYWRIGHT_BROWSERS_PATH="$PWD/storage/app/integration-evidence/browsers"
	node node_modules/playwright/cli.js install chromium
```

3. Run the complete local integration check.

```bash
	python3 scripts/verify-batch2.py
```

The runner builds before starting browser journeys. It uses private temporary runtime state and writes durable logs, screenshots, results, and summaries under `storage/app/integration-evidence`. The core phase includes the real ten-minute timeout and measured scheduler-lock expiry. Native service cases require macOS with an active user launchd domain.

To run one phase or one check, select it explicitly.

```bash
	python3 scripts/verify-batch2.py --phase core
	python3 scripts/verify-batch2.py --phase browser
	python3 scripts/verify-batch2.py --only combined-browser
```

Complete builds before starting a standalone browser phase. Do not run a build concurrently with a browser journey. Preserve the printed private diagnostics when a check fails. The runner retains deliberate negative-test diagnostics even when the surrounding test suite passes.

4. Run dependency checks separately.

```bash
	./scripts/composer validate --strict
	./scripts/composer check-platform-reqs
	./scripts/composer audit
	"$PNPM_BIN" audit
```

The report records current local execution. Running these commands does not publish code, close issues, migrate the primary database, or deploy the app.
