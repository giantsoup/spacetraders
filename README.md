# SpaceTraders client

A Laravel 10, Inertia, Vue 3, and Pinia client for SpaceTraders. The existing app registers agents, imports agents with a token, and lists the current user's saved agents. An authenticated forwarder relays requests to the SpaceTraders API.

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

## Verify changes

The committed dependency locks require PHP 8.1 or 8.2. Install Composer dependencies from `composer.lock` and frontend dependencies with pnpm 8 from `pnpm-lock.yaml`. The API update does not change either lockfile.

Run with locked dependencies installed:

```bash
./scripts/verify.sh
```

Set `PHP_BIN` to a PHP 8.2 executable when your default PHP differs. The script builds the frontend before running PHPUnit, checks the changed PHP files with Pint, and checks whitespace. Build files under `public/build` are tracked by this repository and must stay synchronized with the source.

PHPUnit uses a disposable in-memory SQLite database. The API regression tests fake SpaceTraders responses and prevent stray HTTP requests. They cover registration, import, validation, token handling, owner-only listings, connection failures, malformed responses, forwarding, pagination, and rate limits. They do not prove live authenticated API acceptance.
