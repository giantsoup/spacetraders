# SpaceTraders client

A Laravel 13, Inertia 3, Vue 3, and Pinia 4 client for SpaceTraders. The existing app registers agents, imports agents with a token, and lists the current user's saved agents. An authenticated forwarder relays requests to the SpaceTraders API.

## API compatibility

The integration targets [SpaceTraders API v2.3.0](https://docs.spacetraders.io/openapi), verified against the [official bundled OpenAPI specification](https://spacetraders.io/SpaceTraders.json).

- New-agent registration sends `POST /v2/register` with an **account token** as the bearer credential. The returned **agent token** is saved locally. The account token is not saved.
- `symbol` must contain 3 to 14 characters. Symbol and faction inputs are uppercased before registration. Email remains optional and is omitted from the request when empty.
- Existing-agent import sends `GET /v2/my/agent` with the agent token and saves the response's `startingFaction`. It does not fetch or infer the faction from headquarters.
- Saved agents are scoped to the signed-in user. Page data contains a masked token preview. Full tokens are hidden from model serialization.
- Upstream errors appear on the active form. Failed or incomplete responses do not create local agents. Registration requests are not automatically retried because an ambiguous failure may already have created an upstream agent.

SpaceTraders resets invalidate agent tokens. An account token and an agent token have different purposes and are not interchangeable. See the [official authentication definitions](https://docs.spacetraders.io/openapi).

## Forward existing API requests

Use authenticated `POST /forward` with a local CSRF token. The JSON body contains `method`, `url`, optional `data`, and optional `headers.Authorization`:

```json
{
    "method": "get",
    "url": "/my/agent",
    "headers": {
        "Authorization": "Bearer YOUR_AGENT_TOKEN"
    }
}
```

`url` is a relative path below `https://api.spacetraders.io/v2`. Put query parameters such as `page` and `limit` in `data`. Supported methods are `get`, `post`, `put`, `patch`, and `delete`. The forwarder preserves the upstream status, response body, pagination metadata, content type, and supported rate-limit headers. Responses are not cached or logged.

`GET /forward` remains available for upstream reads. Use POST for authenticated requests to keep bearer tokens out of URLs. GET cannot trigger an upstream write. Arbitrary hosts, path traversal, and custom headers other than `Authorization` are rejected.

The agent forms submit fields directly to their local routes. Clients using the previous `{method, url, data}` envelope for those forms must use the flat fields shown in `AgentController` instead.

## Local setup

Use Herd's existing PHP 8.4 runtime, Node 26.13.0, and pnpm 12.16.1. The application requires PHP 8.4.1 or later within the 8.4 series. Composer resolves against PHP 8.4.1; verify the actual runtime with `composer check-platform-reqs`.

Install pnpm 12.16.1 through your existing package-manager tooling. For a temporary invocation without changing global Node or pnpm, use `npm exec --yes --package=pnpm@12.16.1 -- pnpm`.

For a new local environment:

```bash
composer install
pnpm install --frozen-lockfile --strict-peer-dependencies
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
pnpm build
```

Copy the environment template and generate a key only for a new environment. For an existing environment, retain its key, database, session cookie, and cache prefixes. The template uses an owned local SQLite database, file sessions/cache, synchronous queues, and log mail. Select and verify your database before migrations. Serve the application through Herd at `http://spacetraders.test`; use `pnpm dev` for Vite HMR. Herd can manage that Vite process.

The upgrade retains the existing kernels, providers, authentication screens, and database schema. Session serialization stays `php` to preserve existing sessions. Cache object unserialization is disabled. Inertia SSR and request-recording devtools are disabled because this application does not use them. Ziggy loads from Composer's package through Vite, with `@routes` providing route metadata.

The frontend uses Tailwind 4 and requires Safari 16.4+, Chrome 111+, or Firefox 128+. Older browsers are outside this build's support. Inertia uses its own HTTP client; `window.axios` remains available separately.

## Verify changes

With locked dependencies installed:

```bash
./scripts/verify.sh
composer validate --strict
composer check-platform-reqs
composer audit
pnpm audit
```

Set `PHP_BIN` or `PNPM_BIN` to the matching executable when it is not on your path. The verification script checks PHP 8.4 and pnpm 12.16.1, builds production assets, checks Pinia's saved-state compatibility, runs the complete backend suite, checks affected PHP files with Pint, and checks whitespace. Source and tracked `public/build` files must stay synchronized.

A focused browser regression is available with `node scripts/verify-modal.mjs`. Use an existing Playwright installation, or set `PLAYWRIGHT_MODULE` to its module path. Set `QA_URL`, `QA_EMAIL`, and `QA_PASSWORD` for an owned local `.test` account. The check waits for modal transitions to finish, verifies pointer access, submits an intentionally incorrect password, and checks Cancel and Escape. Set `QA_ARTIFACT_DIR` to retain screenshots. It does not delete the account.

PHPUnit uses disposable in-memory SQLite and an isolated test key. External API regression tests fake SpaceTraders responses and prevent stray requests. Upgrade regressions exercise normal request-forgery middleware and Sanctum bearer authentication. The suite covers authentication, profiles, registration, import, validation, token handling, owner-only listings, connection failures, malformed responses, forwarding, pagination, and rate limits. It does not prove live authenticated SpaceTraders acceptance.

The approved scope and acceptance requirements are in [the migration plan](docs/laravel13-upgrade-plan.md). The baseline commit `a3ea1d4` remains the rollback point for code and locks. Its old dependencies require PHP 8.2, so it is not a PHP 8.4 fallback. This upgrade requires no schema migration beyond the existing migrations.
