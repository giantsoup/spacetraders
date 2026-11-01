# Local automation data contract

Plan version 1 identifies historical mining runs. Version 2 identifies staged Mining and Contracts previews. Version 3 identifies bounded scout and trade previews. Execution rejects unsupported versions. No historical outcome is rewritten.

An investigation stores owner agent, reset, one system, strategy, status, request and elapsed bounds, validated observations and candidate snapshots. Candidates contain a stable ID, classification, reasons, prerequisites, optional estimates, capital and fuel bounds, and evidence timestamps. Null means unknown. A mining trial never ranks above a priced contract on invented yield.

A draft selects one candidate and stores integer limits plus a monotonically increasing revision. A preview is an expiring AutomationRun in preview state. It pins the draft revision, engine session, normalized fresh state fingerprint, ordered actions and limits. Approval transitions that exact row once under the agent lock. Stage and preview enqueue nothing.

ShipAction remains the sole mutation intent. Contract acceptance and fulfillment use target_type=contract and target_id=contract ID. Delivery uses the selected ship and explicit contract ID. Response validation precedes atomic confirmation, accounting and strategy progress. A lost response remains uncertain, including fresh contract evidence on reconciliation.

A singleton engine row belongs to this checkout and configured database. Each supervisor start creates a new UUID and invalidates unsent work. Reservation and gate closure contend in a SQLite transaction. Worker and scheduler process liveness is independent of the last job time. The manager accepts no browser-supplied executable, PID, service identity or command.

The first balance observed locally is stored per agent and reset. Confirmed credit entries have a unique action ID. Confirmed operating contribution, total change from the baseline, manual entries and unexplained changes remain separate.
