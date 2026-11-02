# Leaderboard implementation plan

Status: proposed. Prepared on November 1, 2026. This work creates a plan and verifies public API support; it does not implement the feature.

## Intended result

Add a dark leaderboard page showing every publicly listed agent ranked by credits. Search by agent symbol, and keep the signed-in user's current agents and their positions visible even when a search or page hides their table rows.

Use credits for the first release. Additional rankings and rank history can follow separately.

## Priority and delivery order

Wishlist item [WISH01, issue #88](https://github.com/giantsoup/spacetraders/issues/88), scheduled in [M11 - Post-MVP wishlist](https://github.com/giantsoup/spacetraders/milestone/11), after the existing M01-M10 sequence.

Do not start implementation until a functioning local MVP is verified. M01 baseline, M02 usable web journeys and M03 controlled local operation must meet their exit criteria. Local operating acceptance issues [#73](https://github.com/giantsoup/spacetraders/issues/73), [#75](https://github.com/giantsoup/spacetraders/issues/75) and [#77](https://github.com/giantsoup/spacetraders/issues/77) are prerequisites. Current manual API foundation work [#86](https://github.com/giantsoup/spacetraders/issues/86) stays ahead of this wishlist.

The accompanying PR records the plan and API evidence. Merging planning documents does not implement the feature or close issue #88. This work must not expand MVP scope or delay existing milestones.

## Verified API support

Direct read-only probes ran on November 1 at 16:13 America/Los_Angeles, November 2 at 00:13 UTC. All six requests returned HTTP 200 without authentication. The [saved verification evidence](leaderboard-api-verification.json) includes timestamps, response summaries, and the relevant live schema.

| Requirement | API support | Application responsibility |
| --- | --- | --- |
| Global leaders | `GET /v2` includes `leaderboards.mostCredits`. The live response contained 15 entries. | Treat this as a limited leaders list. Absence does not imply a global position. |
| All agents and credits | `GET /v2/agents?page=1&limit=20` returns public rows and pagination metadata. Pages 1 and 2 each returned 20 rows and reported 87 agents. | Collect every page before publishing ranks or claiming complete search coverage. |
| Find a known symbol | `GET /v2/agents/{agentSymbol}` returns one public agent. A symbol from page 1 was retrieved successfully. | Useful for diagnostics; one agent's credits alone cannot establish rank. |
| Search and global rank | The live OpenAPI documents only `page` and `limit` for the directory. It documents no rank field or dedicated search, sorting, or leaderboard route. | Search the complete saved directory and calculate rank locally. |

The live `PublicAgent` schema includes symbol, credits, headquarters, starting faction, and ship count. Credits are signed 64-bit integers. Negative balances are valid. Both agent endpoints document anonymous access.

Sources: [live bundled OpenAPI](https://api.spacetraders.io/v2/documentation/json), [official API reference](https://docs.spacetraders.io/openapi), and the [official source specification](https://github.com/SpaceTradersAPI/api-docs/blob/main/reference/SpaceTraders.json). The deployed bundled specification takes precedence where it differs from the source repository.

The endpoints support the requested feature, with locally calculated ranks. The probe sampled two pages rather than collecting a complete board. It establishes endpoint support, not the user's current rank. The API does not document a transactionally consistent directory snapshot. New registrations and credit changes can occur between pages. Label positions as ranks in the latest collected snapshot rather than instantaneous official standings.

## User experience

- Add `Leaderboard` to the shared navigation and link to it from the flight deck with the current agent selected.
- Show a persistent `Your agents` area above the table. Each eligible owned agent shows its snapshot rank, credits, snapshot time, and `Show my position`. Support multiple agents without requiring the user to search for them.
- Show rank, agent symbol, credits, ships, and starting faction in the table. Highlight owned agents and label them `You`; do not rely on color alone.
- Search by case-insensitive literal substring of the agent symbol. Trim the query, allow up to 14 characters, debounce by 300 ms, and store it in the URL. Treat `_` and `-` literally. Search never renumbers ranks.
- Paginate local results in groups of 25. Preserve the query on page changes, reset to page 1 on query changes, and support browser Back and Forward.
- `Show my position` clears the search, opens the page containing that agent, and moves keyboard focus to its row. Use its row index, not its competition rank, to calculate the page.
- Show how many agents were collected, when collection started and finished, and the detected local timezone. Keep timestamps as UTC in storage and API values.
- Provide initial loading, no matches, no imported agents, missing public symbol, stale data, reset detected, and unavailable states. Refresh errors preserve a usable previous snapshot only within the stated freshness and reset limits.

Only associate a local agent with a rank when it belongs to the authenticated user, its known `reset_id` matches the snapshot, and it is not archived. An unconfirmed reset ID prompts synchronization; a missing symbol says `Not found in this snapshot`. Do not interpret either condition as last place. Public ranking does not require decrypting the user's token.

## Ranking rules

Calculate ranks on the complete snapshot before applying a search or pagination:

1. Sort by credits descending, then by symbol ascending for stable display.
2. Use competition ranking for equal credits, such as `1, 2, 2, 4`.
3. Preserve a separate one-based row index for navigation and pagination.
4. Derive each owned agent's rank and credits from that same snapshot. Do not splice its newer private balance into older public standings.

These are application ranking rules. The API does not specify official tie handling. Keep signed credits exact in PHP and send them to JavaScript as decimal strings; format with `BigInt` without converting through an unsafe JavaScript number.

## Existing integration points

The authenticated route renders through Inertia, and the Vue page uses the existing dark `AppShell`. The leaderboard controller passes the current user to a leaderboard service and returns public rows plus an owner-scoped summary.

The service makes public upstream reads through `SpaceTradersClient` at discovery priority, retaining the shared `RequestBudget`, host cooldowns, and `Retry-After` handling. It never supplies an agent token. Keep the legacy forwarder whitelist unchanged and preserve `SpaceTradersApi::status(): string`, which existing reset handling depends on.

Leaderboard reads must not call `AgentSynchronizer::guardReset()`. That method can archive agents and block gameplay runs. Rank display compares identities and reset IDs without changing gameplay state. Reuse `formatLocalTime` and `localTimeZone` for display.

## Refresh and storage design

A shared public snapshot is fresh for five minutes and usable for up to 30 minutes with a stale label. Measure age from the final observation time. Limit each collection to 100 pages or 2,000 agents and 60 cumulative seconds of active work. These are configuration values, not upstream limits. An exceeded cap means no new ranked snapshot, never a partially ranked board.

Use a demand-driven, resumable collector. The page renders immediately from local data, then automatically advances a cold or stale collection while the tab is visible. Each authenticated, CSRF-protected `POST /leaderboard/refresh` performs at most five upstream reads or eight seconds of active work. Check the remaining time before each call and pass the absolute deadline to the existing transport. Count root-status reads toward the five-read cap. If the last directory page uses the remaining allowance, persist a pending-verification stage and perform a fresh final status check in the next work unit before publishing. The POST accepts no client-supplied checkpoint or freshness override; the server decides whether work is due. Return progress and `retry_at`; the browser permits one request at a time and honors server backoff. Closing the tab stops further work, and reopening it resumes a valid checkpoint. Search and pagination read the existing snapshot and never start separate scans.

This works with the gameplay engine, queue worker, and scheduler off. It adds no persistent process. A cold visit shows collection progress until the first complete snapshot is ready. Do not use after-response callbacks as a substitute for durable refresh progress.

Persist one `leaderboard_states` row per upstream environment, containing the last observed reset and time, a complete published snapshot JSON value, and a separate private refresh checkpoint JSON value. Use a unique environment key, read the row inside the existing host lock under a dedicated leaderboard name, and serialize short refresh work units. Map lock contention to a busy response with its retry delay. Use a version-checked update when persisting state. Checkpoint each validated page with a short database write; never keep a transaction open during HTTP. Final publication atomically replaces the complete snapshot. Readers ignore the checkpoint. With the explicit 2,000-row cap, sorting and filtering a decoded snapshot in PHP keeps this smaller than separate generation, entry, and publication tables.

A generation has a unique ID and a five-minute maximum wall-clock age in addition to its 60-second active-work limit. Count time spent executing work units, including transport and budget-reservation waits, toward the active limit; exclude idle browser/backoff time. Abandon over-age checkpoints rather than combining pages collected far apart. Failed units release the lock; a crash can safely replay the last uncommitted public GET. A brief browser pause can resume, while a long upstream cooldown may require a fresh generation.

For every collection, checkpoint progress across these steps:

1. Fetch and validate the root status at the start of each work unit and capture its reset ID. A resumed checkpoint must still match it.
2. Fetch the directory at the upstream maximum of 20 rows per page, sequentially and within the existing request budget. Validate metadata and every public row. Collect only the public fields the page needs.
3. Require consistent directory totals, complete page coverage, unique symbols, and the expected final row count. Reject an oversized directory immediately after reading the first page's total. Use directory metadata as the count, since root statistics differed from directory metadata during the probe. Stop on inconsistencies; retry on a later refresh without publishing the candidate.
4. Recheck root status with a fresh transport read before publication. Ensure the client's two-second scoped read cache cannot reuse the initial status response. A changed reset invalidates the candidate and prevents old-world fallback from appearing as current. Publish a complete immutable generation only after validation.
5. Calculate ranking once per published generation. Return the snapshot ID with table data and owned-agent summaries so the browser cannot combine generations.

Coalesce simultaneous refresh attempts across users and tabs, respect cooldowns, and release refresh ownership after failures. A fresh snapshot returns an idle response without upstream work. Persist retry time rather than sleeping through `Retry-After`. Persist a retry time for every failure class, with exponential backoff starting at five seconds and capped at five minutes, never shorter than the upstream/shared-budget delay. An oversized directory receives a five-minute backoff immediately. Allow at most two automatic restarts after a changing-total failure in a five-minute window, then show `Directory is changing; retry later` and stop automatic restarts. Stop automatic retry after three consecutive failed generations; a user can retry when the stored cooldown permits. Budget pauses report `Waiting for request budget` and do not bypass the gameplay request reserve. After a failed refresh, retain only an eligible same-reset snapshot within its maximum age. When no eligible snapshot exists, show the reason and next retry time without a rank.

Snapshot identity includes the upstream environment, reset ID, and generation ID. Keep shared public rows separate from personalized responses. Personalized responses are private and must not enter shared response caches. A successful observed reset mismatch atomically invalidates the old published snapshot and checkpoint before further directory reads. This invalidation persists even if collection subsequently fails. During an outage, explicitly label the last snapshot and its last reset check; do not imply the world was just verified.

## Proposed module shape

The caller asks for a local view or advances collection. The service hides pagination, checkpoints, reset checks, ranking, freshness, filtering, and owned-agent matching.

```php
// Authenticated GET /leaderboard?q=SOUP&page=1. Local reads only.
$view = $leaderboard->view($request->user(), $validatedQuery);
return Inertia::render('Leaderboard/Index', ['leaderboard' => $view]);

// Authenticated POST /leaderboard/refresh. Only anonymous GETs go upstream.
return response()->json($leaderboard->advance());
```

```php
final class Leaderboard
{
    public function view(User $user, LeaderboardQuery $query): LeaderboardView
    {
        throw new \LogicException('Not implemented');
    }

    public function advance(): RefreshProgress
    {
        throw new \LogicException('Not implemented');
    }
}
```

`LeaderboardQuery` contains the normalized symbol fragment and local page number. `LeaderboardView` contains snapshot ID/reset/observation times, availability, ranked rows, filtered pagination, owned-agent positions, and refresh progress. `RefreshProgress` contains building, paused, complete, or failed state, collected/expected counts, and next permitted retry time. Keep availability separate from refresh state so a stale complete snapshot can remain readable during collection.

Implement the service and small result types under `app/Leaderboard`, one state model/migration, a thin `LeaderboardController`, request validation, and `resources/js/Pages/Leaderboard/Index.vue`. Use a pure ranking function and literal `str_contains` search over normalized symbols. Keep upstream parsing private to the service initially. Add a helper only when it owns distinct behavior; avoid a pass-through repository or a value object for every scalar.

## Design decision

Use the demand-driven candidate as the base. It supports a cold build without a new always-on runtime. Adopt the background candidate's separation of snapshot availability from refresh progress and its literal search rules. Simplify both candidates' three-table designs to a bounded JSON snapshot and checkpoint behind one service.

A scheduled background collector would give fast warm reads but add an independent process to operate while gameplay is off. A synchronous full scan would make a cold web request wait for every page. The chosen short, resumable requests keep the UI responsive and preserve a single snapshot contract. A future independent scheduler can call the same `advance()` method if unattended freshness becomes a requirement.

An independent planning review supported this choice. Its corrections are incorporated above: status reads count toward work caps, final verification can cross a work-unit boundary, locks return a busy response, retries persist shared backoff, and reset invalidation is saved before a failed refresh can return. The existing import and synchronization paths assign the upstream reset string directly to `Agent.reset_id`, so equality with the snapshot reset follows the current identity convention.

## Implementation sequence

### 1. Build and verify the snapshot boundary

Implement the snapshot/checkpoint state, public-directory collector, and rank calculation first. Use HTTP fakes with all unknown requests blocked. Verify several pages, tie ranks spanning pages, signed credits, integer precision, and an owned agent outside the status endpoint's leaders list.

Acceptance: a complete fixture produces the expected global positions, and a missing or failed page cannot publish a partially ranked board.

### 2. Add authenticated page queries and owned-agent summaries

Add authenticated GET and throttled refresh POST routes, query validation, service results, and the independent owned-agent summary. Use a shared five-minute freshness check and shared retry time so extra tabs cannot bypass upstream cooldowns. Test search, local pagination, row navigation, ownership, archived agents, missing symbols, and reset mismatches.

Acceptance: filtering changes the visible rows while the global ranks and owned-agent summaries retain their original values. A second user's agent ID cannot select or expose that user's local data.

### 3. Build the page and navigation

Implement the dark table, persistent owned-agent cards, search, pagination, freshness labels, and error states. Add the navigation link and flight-deck entry point. Keep any flight-deck rank summary separate from its gameplay refresh path.

Acceptance: keyboard users can search and jump to their agent; ties navigate to the correct page; mobile layouts remain readable; stale search responses cannot replace newer results. After publication, offer `New snapshot available` and reload the table and owned-agent summary together while preserving query, page, and focus. Do not replace rows unexpectedly while the user is reading them.

### 4. Exercise refresh failures and reset boundaries

Verify concurrent refreshes, request-budget deferral, 429 and 5xx, timeout, malformed rows, duplicate symbols, missing pages, changing totals, cap exhaustion, process interruption, stale checkpoints, a final verification deferred to the next unit, five-read boundaries, repeated total changes, and a reset during collection. Include a reset between initial and final status reads within two seconds to catch accidental client-cache reuse. Confirm that refresh works with the gameplay engine off and sends only anonymous GET requests upstream.

Acceptance: readers never see a half-built generation, old-world ranks never appear as current-world ranks, and search or pagination does not initiate new upstream scans.

### 5. Verify the completed experience

Run focused PHP tests, frontend verification, production build, and formatting checks. Add deterministic leaderboard cases to the existing verification scripts. Then use an isolated fixture database and browser session on the Herd-served site to exercise the full flow at desktop and mobile widths. Inspect the resulting screenshots and test browser history and keyboard focus.

Finish with a small live anonymous read check through the new service, within its request caps. Report separately what fixtures, browser checks, and live reads prove. No gameplay writes are needed to verify this feature.

## Review boundaries

This plan is grounded in local commit `221fbae7a1ecbdf5159211316039f016355c900e`. At initial grounding, local tracking reported `main` ahead 2 and behind 58 commits. The planning PR is published on remote `main` base `99be3c474dddd7bf9fd1c1aec5bf2147cf9b5868`, without including unrelated local commits. Recheck the integration points against the selected implementation base when this wishlist becomes active. Preserve the existing untracked plan-audit files.

This planning pass changes only documentation and API verification evidence. It makes no application, process, or gameplay changes.
