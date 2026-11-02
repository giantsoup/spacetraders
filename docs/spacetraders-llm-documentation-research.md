# SpaceTraders documentation access for LLMs

Accessed 2026-11-01. Read-only web research using official documentation and original repositories. Only public documentation, source, and repository metadata were requested. No packages were installed or run, credentials read, or gameplay endpoints called.

## Recommendation

Use the official OpenAPI JSON with existing web/file retrieval first. A small local skill could direct an LLM to the schema, relevant guides, and freshness checks. If an MCP interface is required, the reference Fetch server is a reusable retrieval component. The two SpaceTraders MCP projects investigated concern gameplay; neither is a verified documentation search service. No maintained, dedicated SpaceTraders documentation-only MCP or ready-made documentation skill was verified in the bounded searches below. This is a search result, not proof that none exists. [Official OpenAPI guide][openapi-guide], [Fetch source][fetch], [grantmd source][grant-tools], [TheValverde README snapshot][valverde]

## Official formats and retrieval limits

- The [website bundle][website-spec] returned HTTP 200, OpenAPI `3.0.1`, API version `2.3.0`, and 55 paths. The [API-hosted documentation JSON][api-spec] returned HTTP 200, OpenAPI `3.0.1`, version `v2.3.0`, and 60 paths. These are documentation downloads and require no game token. The API-hosted document additionally lists `/error-codes`, `/my/account`, `/my/agent/events`, `/my/factions`, and `/my/socket.io`. Equal version labels do not establish equal coverage. Record the URL, retrieval date, and hash of whichever snapshot an LLM uses. These observations do not verify endpoint behavior. [Website bundle][website-spec], [API-hosted schema][api-spec]
- The official [api-docs repository][official-source] contains `reference/SpaceTraders.json`, separate model JSON files, and `docs/overview.md`. It provides raw source suitable for file retrieval, but is not a Markdown mirror of all website guides. Its current `main` resolved to `45fbb04130aca3fa0bd9a634ab77b35fa6c468ab`, committed 2025-04-27. Use current hosted documents alongside this older source snapshot. [Official tree][official-source], [commit metadata][official-commit]
- Direct GETs returned **404** for `https://docs.spacetraders.io/llms.txt`, `https://docs.spacetraders.io/llms-full.txt`, `https://spacetraders.io/llms.txt`, and `https://spacetraders.io/llms-full.txt`. The tested Markdown URL, `https://docs.spacetraders.io/quickstart/new-game.md`, also returned 404. This establishes those paths only, not the absence of every possible Markdown export.
- Normal guide URLs returned HTTP 200. However, plain HTML text extraction of the [OpenAPI guide][openapi-guide], [new-game guide][new-game], and [rate-limit guide][rate-limits] yielded navigation and headings without article prose. The prose appeared inside script data. An HTML-to-Markdown fetcher may therefore return incomplete guides. Check the returned content; use rendered retrieval for missing articles, and JSON or raw repository files for machine-readable reference. Search snapshots supplied fuller prose but have separate crawl dates.

## Reusable projects

### Reference Fetch MCP

The MCP project's [Fetch server][fetch] retrieves URLs and converts HTML to Markdown. Its `fetch` tool supports raw content, a character limit, and `start_index` for subsequent chunks. It is a generic reader, without a SpaceTraders index or schema-aware operation lookup. It could retrieve the official JSON and raw Markdown without game credentials. Compatibility here is inferred from its documented interface; it was not installed or exercised. The guide extraction problem above still applies. Inspected `main` was `194cc86e3ae6400958555bd4116849d822d69263`, committed 2026-10-28. [Fetch README][fetch], [commit metadata][fetch-commit]

### grantmd/spacetraders-mcp

This Go server exposes live game resources and tools over stdio. The resource handler for `spacetraders://agent/info` calls the game client. The registry includes navigation, buying, selling, extraction, jettisoning, and contract commands. These resources are game state, not documentation pages. No documentation search/read handler appears in the inspected registries. [Agent handler][grant-agent], [tool registry][grant-tools], [resource registry][grant-resources], [server entry point][grant-main]

Its generated client reports API `2.3.0`; generation targets the website schema. Current `main` was `b23dba6a8486dd1c464943bbf4869e6c5fd5fd61`, committed 2026-03-16. Configuration requires `SPACETRADERS_API_TOKEN`, while the README example uses `SPACETRADERS_TOKEN`. This is a gameplay integration candidate requiring separate review, not a useful dependency for documentation alone. Buildability, runtime compatibility, and gameplay were not tested. [Generated client][grant-generated], [generator configuration][grant-generator], [commit metadata][grant-commit], [configuration][grant-config], [README][grant-readme]

### TheValverde/spacetraders-mcp

An older first-party GitHub README snapshot describes a Python MCP server for registration, fleet management, contracts, and trading, with SSE configuration and reference schemas. That describes live game operations, not a documentation retrieval tool. Current unauthenticated [repository metadata][valverde-api] and the [raw implementation URL][valverde-raw] returned 404; the GitHub connector also returned 404. Web retrieval still exposed the older README snapshot. Current availability, source behavior, exact API version, and maintenance could not be verified. Do not treat directory copies or the cached README as evidence of a currently usable server. [Original repository snapshot][valverde]

## Practical next step and evidence scope

The smallest useful follow-up is a documentation-only skill that selects the relevant official schema operation and referenced models, links the matching guide, records provenance, and checks discrepancies. A custom MCP could expose `search_docs`, `read_doc`, and `get_operation` over a refreshed local corpus if repeated lookup warrants it. Both are design recommendations, not existing artifacts or completed setup.

Searches covered SpaceTraders with MCP, documentation, skills, `SKILL.md`, `llms.txt`, and Context7, followed by primary-source checks. Context7 coverage was not verified. No installation, MCP handshake, rendered-browser check, or game API compatibility test was performed. The unrelated files already present in this worktree were preserved.

[openapi-guide]: https://docs.spacetraders.io/api-guide/open-api-spec
[website-spec]: https://spacetraders.io/SpaceTraders.json
[api-spec]: https://api.spacetraders.io/v2/documentation/json
[official-source]: https://github.com/SpaceTradersAPI/api-docs/tree/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab
[official-commit]: https://api.github.com/repos/SpaceTradersAPI/api-docs/commits/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab
[new-game]: https://docs.spacetraders.io/quickstart/new-game
[rate-limits]: https://docs.spacetraders.io/api-guide/rate-limits
[fetch]: https://github.com/modelcontextprotocol/servers/blob/194cc86e3ae6400958555bd4116849d822d69263/src/fetch/README.md
[fetch-commit]: https://api.github.com/repos/modelcontextprotocol/servers/commits/194cc86e3ae6400958555bd4116849d822d69263
[grant-readme]: https://github.com/grantmd/spacetraders-mcp/blob/b23dba6a8486dd1c464943bbf4869e6c5fd5fd61/README.md
[grant-main]: https://github.com/grantmd/spacetraders-mcp/blob/b23dba6a8486dd1c464943bbf4869e6c5fd5fd61/main.go
[grant-tools]: https://github.com/grantmd/spacetraders-mcp/blob/b23dba6a8486dd1c464943bbf4869e6c5fd5fd61/pkg/tools/registry.go
[grant-resources]: https://github.com/grantmd/spacetraders-mcp/blob/b23dba6a8486dd1c464943bbf4869e6c5fd5fd61/pkg/resources/registry.go
[grant-agent]: https://github.com/grantmd/spacetraders-mcp/blob/b23dba6a8486dd1c464943bbf4869e6c5fd5fd61/pkg/resources/agent.go
[grant-generated]: https://github.com/grantmd/spacetraders-mcp/blob/b23dba6a8486dd1c464943bbf4869e6c5fd5fd61/generated/spacetraders/README.md
[grant-generator]: https://github.com/grantmd/spacetraders-mcp/blob/b23dba6a8486dd1c464943bbf4869e6c5fd5fd61/openapi-generator-config.yaml
[grant-commit]: https://api.github.com/repos/grantmd/spacetraders-mcp/commits/b23dba6a8486dd1c464943bbf4869e6c5fd5fd61
[grant-config]: https://github.com/grantmd/spacetraders-mcp/blob/b23dba6a8486dd1c464943bbf4869e6c5fd5fd61/pkg/config/config.go
[valverde]: https://github.com/TheValverde/spacetraders-mcp
[valverde-api]: https://api.github.com/repos/TheValverde/spacetraders-mcp
[valverde-raw]: https://raw.githubusercontent.com/TheValverde/spacetraders-mcp/main/src/main.py
