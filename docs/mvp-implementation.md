# MVP implementation and acceptance

Baseline: `4883b14`, branch `feature/early-game-mvp`. Initial worktree contained only the two untracked planning documents. Preserve existing frontend, verification and build behavior. No live gameplay is authorized.

Official bundled OpenAPI was downloaded on 2026-10-31, version 2.3.0. Purchase ship requires an observer at its yard; mineral processing and a mining laser are required; trades use per-request volume; refuel requests use tank units. Sources and mechanics are in the API research document. No upstream limit-price parameter exists.

## Dashboard direction

Two CSS layouts considered: a dense terminal with all panels in equal columns, and a bridge with persistent ship/stop status above a fleet sidebar and stacked work panels. Choose the bridge: status and stop remain reachable on narrow screens and long cargo tables can scroll without moving the whole page. Use system monospace, square borders, restrained mint/amber accents, dark backgrounds, explicit text labels and no animation.

## Server contract

HTTP accepts owner-scoped intents. Previews persist approved arguments and expire. Execution revalidates fresh upstream state, records sending before the one write, and saves confirmed results. Jobs carry only integer IDs. The database holds durable actions and runs; duplicate delivery cannot replay sending or confirmed actions. OS file locks serialize each agent and the global request budget on this single Mac. Stop intent writes independently of those locks. An action already reserved can settle after Stop; nothing else starts.

Each run has explicit reserve, spend, duration, extraction and cycle limits. Initial inventory remains protected. Unknown buyers, quotes, equipment, responses, reset identity or write outcomes block automation. Reconciliation refreshes observations but never guesses that a non-idempotent write did or did not happen.

## Acceptance matrix

| Scenario | Actors / fixture | Expected boundary and evidence |
| --- | --- | --- |
| Ownership | Owner, another user, guest | Every dashboard/preview/command/start/stop/reconcile route scopes the agent; HTTP tests |
| Credentials/import | Synthetic legacy and encrypted tokens | Conversion is resumable, import deduplicates canonical identity, no token props/jobs/errors; database and HTTP tests |
| Discovery | Multi-page fleet and waypoints | Bounded complete pagination or explicit failure; persisted reset-scoped observations |
| Manual actions | Docked/orbiting/travelling ships | Named endpoints, valid quantities, fresh quotes, reserve and capacity checks; fake HTTP workflows |
| Setup | Complete miner offer and incomplete offers | Separate single-use purchase approval, configuration/price/reserve checks; uncertain purchase blocks repeat |
| Trip | Mixed ore yields, partial sales, fuel expense | One mutation per job, timestamp waits, attributable cargo, confirmed resource/credit totals |
| Contention | Duplicate delivery and two real workers | One write; persisted stop, reserve across ships, sending recovery; disposable file SQLite and database queue |
| Failure/recovery | Timeout, malformed success, 429, reset, stale intent | No blind replay; retry delay, blocked action/run, explicit reconciliation |
| Dashboard | Owner with fake provider | Real local browser clicks, reload, keyboard, desktop and narrow screenshots; no live credentials |
| Final checks | Final implementation | Repository verification, locked dependency validation/audits, scoped Laravel QA, separate Sonnet security review |

See README for operation and the final local evidence report for executed results and limitations.
