# Local automation data contract

Plan version 1 identifies historical mining runs. Version 2 identifies staged Mining and Contracts previews. Version 3 identifies bounded scout and trade previews. Execution rejects unsupported versions. No historical outcome is rewritten.

| Plan version | Preview and approval contract |
| --- | --- |
| 1 | Historical mining runs and the legacy mining preview path. Start permits this version only when the local engine gate is disabled. Enabling that gate requires a new staged preview. |
| 2 | Mining and Contracts previews pin an owned draft revision, engine session and fresh-state fingerprint. Approval recomposes the draft and rejects changed state or terms. Contract acceptance requires its separate acknowledgment. |
| 3 | Bounded `scout` and `trade` previews use `OperationPlanner` through `AutomationRunner::preview`. They pin initial cargo, fuel, location, equipment and quote signatures, plus the engine session when enabled. Start refreshes and compares those values before any command. They do not use a version-2 draft. |

Scout approves one purposeful market visit and return, subject to fuel, spending, loss, reserve and duration bounds. A fresh remote read can answer the question before departure. Trade approves one bounded purchase, batch sale and return cycle with explicit quantity and margin limits. Unknown benefit, time and wear remain unpriced. Another operation requires a new preview. All versions retain owner/reset checks, expiry, Stop and uncertain-write reconciliation through the shared command executor. Unsupported versions are rejected; saved historical runs keep their original version and outcome.

Mining waits for server cooldown before another extraction. Once an extraction count, cargo-headroom or site-time departure limit is reached, `MiningPlanner::next` departs without waiting for that cooldown. Travel can overlap the remaining extraction cooldown. Transit deadlines and all command preflight rules still apply. No preview wording authorizes another extraction early.

Current implementation and issue/PR pairs: [documentation status index](plan-implementation-audit.md#current-status-index).

An investigation stores owner agent, reset, one system, strategy, status, request and elapsed bounds, validated observations and candidate snapshots. Candidates contain a stable ID, classification, reasons, prerequisites, optional estimates, capital and fuel bounds, and evidence timestamps. Null means unknown. A mining trial never ranks above a priced contract on invented yield.

A draft selects one candidate and stores integer limits plus a monotonically increasing revision. A version-2 preview is an expiring AutomationRun in preview state. It pins the draft revision, engine session, normalized fresh state fingerprint, ordered actions and limits. Approval transitions that exact row once under the agent lock. Stage and preview enqueue nothing.

ShipAction remains the sole mutation intent. Contract acceptance and fulfillment use target_type=contract and target_id=contract ID. Delivery uses the selected ship and explicit contract ID. Response validation precedes atomic confirmation, accounting and strategy progress. A lost response remains uncertain, including fresh contract evidence on reconciliation.

A singleton engine row belongs to this checkout and configured database. Each supervisor start creates a new UUID and invalidates unsent work. Reservation and gate closure contend in a SQLite transaction. Worker and scheduler process liveness is independent of the last job time. The manager accepts no browser-supplied executable, PID, service identity or command.

The first balance observed locally is stored per agent and reset. Confirmed credit entries have a unique action ID. Confirmed operating contribution, total change from the baseline, manual entries and unexplained changes remain separate.
