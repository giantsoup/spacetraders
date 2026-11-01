# Local automation implementation plan

Status: proposed for Taylor's review. Application implementation has not started.

Prepared 2026-11-01 against the current working tree on `feature/early-game-player-guide`, based on commit `6a99cfe`. The checkout contains existing uncommitted frontend, verification, and build changes. Preserve that work.

## Intended result

From the flight deck, Taylor can choose **Mining** or **Contracts**, investigate opportunities, stage a bounded plan, review its preview, and approve execution. The app explains why it recommends an opportunity and reports actual credit results afterward.

The worker and scheduler remain on this Mac. Taylor can start the local engine, stop an automation run while continuing manual play, or end the local session. Starting the engine alone authorizes no game actions.

This document proposes implementation scope and defaults. Reviewing it does not start processes, change configuration, accept contracts, purchase goods, or spend credits.

## Current foundation

The current source already provides:

- Mining previews, explicit Start and Stop, duration and cycle limits, reserves, and spending limits in [MiningRunner](../app/Gameplay/MiningRunner.php).
- One extraction per mining trip, followed by sale and refueling in [MiningPlanner](../app/Gameplay/MiningPlanner.php). Repeated extraction until the hold fills is not implemented.
- Durable action intents, agent locks, confirmation, and uncertainty handling in [ShipCommandExecutor](../app/Gameplay/ShipCommandExecutor.php).
- Fleet and waypoint discovery, market observations, reset handling, and a shared upstream request budget.
- Database jobs on the `spacetraders` queue and scheduled recovery through [DispatchDueSpaceTraders](../app/Console/Commands/DispatchDueSpaceTraders.php).
- Manual previews and approvals, run controls, and recent worker and scheduler activity in the [dashboard](../resources/js/Pages/Agent/Dashboard.vue).

The existing operating instructions require two terminal processes. There is no local engine manager. Contract API operations, contract execution, opportunity ranking, and the unified staging flow are new work.

These are source observations. This planning pass does not establish the current live fleet, process health, available routes, or profitability.

## User workflow

### Choose the automation type

Add **New automated run** with two choices, **Mining** and **Contracts**. Keep the existing manual controls available outside an active run's ownership.

Show engine status independently of the chosen strategy. Investigation and staging work while the engine is off. Approval requires a healthy engine and a current preview.

### Investigate opportunities

**Investigate** makes authenticated read requests through the existing backend client and shared request budget. It refreshes credits, ships, equipment, cargo, fuel, cooldowns, contracts, and relevant local observations.

Investigation never moves a ship, extracts cargo, buys goods, accepts or negotiates a contract, or changes ship equipment. It can update local observations.

Bound discovery by system, request count, pagination, and elapsed time. Show progress, observation times, the area searched, and any limit reached. A cancelled investigation stops further reads. Partial discovery remains visibly incomplete.

When prices require a ship at another location, show **Needs scouting**. Offer a separate movement preview using the manual command path. After an approved move completes, Taylor investigates again. Never quietly send a probe or ship to obtain prices.

### Present opportunities and recommendations

Each candidate shows:

- Strategy, ship, locations, required equipment, and preparation steps.
- Estimated net credits, estimated duration, and credits per hour when the inputs support those estimates.
- Required working capital, fuel needs, spending ceiling, protected reserve, and setup costs.
- Observation times, assumptions, missing information, and reasons the candidate is blocked.
- For contracts, remaining quantities, destinations, acceptance and delivery deadlines, and remaining payments.

Classify candidates as **Ready to stage**, **Needs preparation**, **Needs information**, or **Unsupported**. Do not represent an unknown quote, yield, or duration as zero.

Recommend the feasible candidate with the highest defensible estimated net credits per hour. Allow sorting by estimated total net credits. Use lower required capital and shorter duration to break ties. Retain blocked candidates with useful explanations.

If yield history or other essential inputs are missing, show **Trial required** instead of an invented earnings estimate. Offer a bounded mining trial without claiming it beats a priced contract. Explain the evidence used for each recommendation.

Use the phrase **Best observed opportunity**. The investigation cannot establish the best opportunity across the entire game.

### Stage the selected plan

**Stage plan** saves a local draft. It does not enqueue gameplay.

Taylor selects or edits the ship, candidate, reserve, spending and loss limits, duration, and strategy limits. Recalculate the draft after an edit. Preserve drafts across reloads, but mark stale observations.

Preparation remains separate from the main run. Scouting, a miner purchase, or initial refueling uses its own preview and approval. Completing preparation requires a fresh investigation or refreshed draft before the run can start.

### Preview and approve

**Preview automation** creates an expiring server-side preview from fresh state. Show:

- The ordered actions, including any contract acceptance, purchase batches, deliveries, and fulfillment.
- Planned starting and ending locations, cargo, fuel, and expected cash change.
- Estimated earnings with assumptions, setup costs separately, and unsold cargo separately.
- The reserve, spending and loss limits, duration, cycle or delivery limits, and stopping rules.
- For contracts, an explicit acceptance acknowledgement and a warning that stopping does not remove the game obligation.

**Approve and start** approves that exact preview once. Revalidate its ownership, reset, engine session, expiry, ship, cargo, fuel, credits, prices, contract terms, and route before reserving the first action.

A material change invalidates the preview and explains what changed. Permit routine passage of time only while the plan remains within its approved timing bounds. Do not replace the selected opportunity or expand its scope automatically.

### Monitor, stop, and review

Show the current action, next action time, elapsed time, confirmed receipts and spending, remaining budget, cargo, and the reason for any pause. Display times in the detected local timezone while retaining UTC storage and scheduling.

Keep **Stop automation** visible. Stop prevents new actions from being reserved. An action already reserved may settle, and server-side travel continues. Release manual control only after local command ownership is resolved.

At completion or Stop, retain an outcome report and offer **Investigate again**. Another contract, strategy, or extended budget requires a new preview and approval.

## Strategy scope

### Mining

Reuse the existing runner and conservative route checks. The first release keeps one extraction per trip and a default of one trip. Retain the existing maximum of ten trips within 60 minutes for explicitly approved repeated runs.

Require a stationary, operational miner, empty starting cargo, a usable same-system extraction site, verified buyers, and enough fuel. Retain unknown cargo and block rather than jettisoning it or inventing another route.

Initially rank mining routes using fuel cost, known buyers, travel estimates, and relevant confirmed yield history. History must identify equipment, site, sample count, and age. Without useful history, offer a trial with unknown earnings.

Stop on the approved limits, zero progress, missing buyer, equipment condition, uncertain writes, or observed nonpositive operating contribution. Evaluate each completed cycle separately as well as the cumulative result so a loss-making cycle cannot hide behind earlier gains.

Repeated extraction until a target cargo level is a later optimization. It requires its own capacity, cooldown, no-progress, and safe-return design.

### Contracts

Implement one existing offered or accepted delivery contract per run, using one ship within one system. Support the contract's remaining delivery terms only when every destination and supply route is verified and fits the approved bounds. Otherwise explain the unsupported term.

For the first release, source goods from explicitly selected existing cargo or verified market purchases. Mining goods for a contract is deferred because random yields make completion time and cargo allocation harder to bound.

The planned sequence is:

1. Refresh the contract and prove that the remaining work fits capital, cargo, fuel, timing, and deadline limits.
2. Accept an offered contract only when the preview explicitly includes acceptance. Skip acceptance for an already accepted contract.
3. Buy the approved goods in permitted batches, using fresh quotes and the remaining budget.
4. Travel and deliver no more than the remaining requirement. Repeat only within the approved trip and purchase limits.
5. Fulfill after all required deliveries are confirmed, then report the actual remaining reward and costs.

Reserve contract cargo before allowing other sales. Existing cargo requires explicit inclusion and disclosure of its direct-sale alternative. Keep unrelated cargo protected.

For an accepted contract, exclude the already received acceptance payment from projected future receipts. Show it separately in contract history. Avoid counting delivered goods twice when calculating remaining quantities.

Include a displayed scheduling allowance for travel estimates, queue delays, API limits, and final fulfillment. If completion cannot fit the duration and delivery deadline with that allowance, block approval. Do not promise deadline completion while the Mac is asleep or the engine is off.

No automatic contract negotiation, repeated acceptance of new contracts, intersystem routes, or fleet expansion is included.

## Local engine controls

Provide these session controls alongside the run controls:

- **Start local engine** starts exactly one owned worker and scheduler. It leaves automation awaiting a new approval.
- **Stop automation** stops the selected run and leaves the engine available for manual commands.
- **End session** first closes the action gate, records Stop for active runs, cancels unsent approvals with an explanation, waits for reserved actions to settle, and shuts down the owned processes.

Engine states are **Off**, **Starting**, **Running**, **Stopping**, and **Faulted**. API connectivity and run state remain separate. A worker with no recent job is idle, which does not prove it is offline.

Proposed macOS implementation: an on-demand user LaunchAgent starts a small supervisor that owns the worker and scheduler. Configure `RunAtLoad=false` and `KeepAlive=false`. Use a fixed service label scoped to the checkout and database. The supervisor holds an exclusive instance lock and reports child process state and heartbeat.

The dashboard uses authenticated, CSRF-protected local control endpoints. The backend invokes only fixed service operations with argument arrays. Accept no executable paths, shell commands, service labels, or process IDs from the browser. Restrict installation to the configured local operator and verified local host.

Before building that integration, verify that Herd's PHP process can control the intended user launchd domain. Treat failure as a design checkpoint. Do not ship buttons that report success without starting the processes.

Use the verified PHP 8.4 executable, current checkout, database, and lock directory. Preserve Herd as the HTTP server. The process manager must not stop unrelated PHP workers or schedulers.

Persist a fresh engine-session identifier on each start. Bind approved work to that session and check the action gate at dispatch and immediately before reservation. Older pending approvals and active runs require refreshed previews after a restart. Starting either legacy terminal command must not bypass this gate.

During shutdown, show **Stopping** until the processes actually exit. If confirmation is lost, preserve the uncertain action and reconciliation evidence. After a bounded grace period, report the fault and offer an explicit force-stop control with its consequences. Do not claim that an interrupted request was cancelled upstream.

Closing the browser leaves the engine running. Mac sleep pauses new commands. On wake, refresh state and timing before another action. Missed deadlines or expired approvals block execution rather than triggering catch-up writes.

## Credit accounting

Store a baseline for each agent and world reset before new setup spending. Label it as the first observed local balance. If the true original balance is unavailable, allow a clearly labelled user-supplied value instead of claiming to know it.

Report these separately:

- Confirmed run receipts, including sales and contract payments.
- Confirmed acquisition, fuel, and other approved operating costs.
- Net run cash contribution, calculated from those confirmed amounts.
- Total balance change from the recorded baseline, including ship and equipment purchases.
- Unrelated manual transactions and unexplained external changes.
- Remaining cargo and changes in fuel inventory.

Projected contract receipts include only unpaid rewards. Existing cargo has no new purchase debit, but its estimated sale value is an opportunity cost shown separately. Compare equivalent starting and ending fuel levels where possible. Otherwise label the fuel adjustment as an estimate.

Do not label cash contribution as full economic profit. Ship wear, unpriced inventory, and time may remain outside measured costs. An unexplained balance or cargo change blocks attribution and further execution until reviewed.

## Backend structure and data changes

Reuse `AutomationRun`, `ShipAction`, observations, the shared request budget, and the agent lock. Keep a single command execution path for manual and automated writes.

Proposed responsibilities:

- `OpportunityInvestigator` collects a bounded set of validated observations and evaluates candidates. Persist investigation status and coverage so reloads and cancellation work.
- `OpportunityEvaluator` calculates feasibility, economics, and ranking from supplied observations without network calls or writes.
- `AutomationRunner` owns common preview, start, stop, recovery, and scheduling behavior. Migrate mining lifecycle calls from `MiningRunner` when the contract strategy is introduced.
- `MiningPlanner` and a new `ContractPlanner` choose the next action from confirmed state and approved limits. Generalize `MiningStep` to a shared step value only where both strategies need it.
- `ShipCommandExecutor` remains the mutation boundary. Extend its explicit command allowlist with contract acceptance, delivery, and fulfillment. Validate each operation's distinct response shape.
- `LocalEngineManager` controls the fixed local service. A supervisor CLI command owns the worker and scheduler lifecycle.

Extend `SpaceTradersApi` with contract listing and detail reads. Its current pagination helper requires `symbol`, while contracts use `id`. Adapt pagination to validate the correct identity field instead of forcing contracts through ship validation.

Contract acceptance and fulfillment are agent-level operations. Model their target explicitly even though the enclosing run has a selected ship. Avoid fake ship endpoints. Delivery must validate the approved contract, ship, good, units, destination, remaining requirement, and returned cargo and contract progress.

Decouple strategy-specific mining totals and phase changes currently inside command confirmation. Record validated action outcomes once, then update the appropriate strategy's progress in the same transaction. Include fresh contract progress in reconciliation evidence and never blindly replay a timed-out contract write.

Additive migrations introduce:

- Run strategy, plan version, engine session, and optional source-investigation reference. Existing run history is classified as mining without rewriting past outcomes.
- Investigation records with agent and reset identity, scope, status, candidate snapshots, observations used, and timestamps.
- Draft plans and expiring previews with a stable revision and validated state fingerprint.
- Contract observations and contract-aware action outcomes, including remaining requirements and payment attribution.
- Engine state and session identity, with run and action references sufficient to reject stale approvals.
- Agent baseline and confirmed credit accounting records, linked to existing actions to prevent duplicate accounting.

Enforce owner and reset scoping on every new route. Keep the existing one-active-run-per-agent constraint and shared-credit reserve across ships. Use integer credits and quantities, UTC timestamps, bounded JSON validation, and no credentials in browser props, jobs, or logs.

Migrate callers and verification scripts to the shared runner in the same implementation unit. Preserve historical plans with explicit versions and reject unsupported versions for execution. Keep the design limited to these two strategies rather than building a general workflow framework.

## Implementation sequence

### Phase 1: Lock down contracts and fixtures

Confirm the current source, dirty-work boundaries, Herd runtime, and owned test database. Define candidate, draft, preview, action, and outcome shapes. Extend the fake provider with priced routes, varied yields, partial contracts, deadlines, and contract response shapes.

Exit condition: agreed data shapes and deterministic fixtures cover the two strategies without live credentials or game actions.

### Phase 2: Add the engine and action gate

Implement the on-demand service, supervisor, session identity, process status, and graceful shutdown. Add the action gate to both manual and automated command paths. Invalidate old approvals on restart before exposing engine controls.

Exit condition: real local worker processes start once, stop cleanly, and cannot execute old approvals after restart. This phase uses only owned offline fixtures.

### Phase 3: Add investigation and staging

Implement contract reads, bounded discovery, candidate evaluation, ranking, and draft storage. Add read-only investigation progress, missing-data explanations, and separate preparation previews.

Exit condition: investigation causes no game mutations and the same observations produce the same candidates and ranking. Missing inputs cannot produce a false ready recommendation.

### Phase 4: Unify previews and preserve mining behavior

Introduce the shared runner and versioned previews. Connect the existing mining planner, command path, and result accounting. Add per-cycle result checks while retaining one extraction per trip.

Exit condition: existing mining and manual workflows still work, duplicate approval produces one run, and changed state requires a new preview.

### Phase 5: Add contract execution

Implement acceptance, approved acquisition, partial deliveries, fulfillment, cargo reservation, and reconciliation. Use the same budget, ownership, Stop, and engine-session rules as mining.

Exit condition: a synthetic contract completes across multiple batches and worker restarts without double payment, duplicate delivery, overspending, or sale of protected goods. Restart requires an explicit refreshed approval.

### Phase 6: Complete the dashboard and acceptance checks

Finish the two strategy choices, investigation results, staging, preview, engine controls, run monitor, and outcome report. Preserve the dark command-deck style and readable controls on narrow screens. Update the guide and operating instructions.

Exit condition: both complete workflows pass the browser and worker checks below. Deliver a local verification report that separates synthetic evidence from any later live-game results.

## Verification and acceptance

Use owned disposable SQLite databases, fake upstream responses, and finite local worker sessions. Preserve the checkout's `.env`, credentials, and existing game history.

Required checks:

- Investigation issues no mutation requests. Cancellation, pagination bounds, missing prices, and stale or partial observations remain visible.
- Candidate ranking handles unknown yields, already paid contract rewards, partial deliveries, setup costs, fuel changes, and unsupported routes correctly.
- Duplicate approvals, duplicate jobs, two workers, Stop races, expired previews, and changed state cause at most one approved write.
- Engine controls are authenticated, operator-scoped, CSRF-protected, and limited to the owned service. An idle healthy worker is reported accurately.
- End session cancels unsent manual and automated work. Restart, sleep and wake, and a crashed supervisor cannot replay stale approvals.
- Contracts reserve cargo, respect purchase volume and spending limits, update remaining requirements, and fulfill only after all terms are met.
- Timeouts after acceptance, purchase, delivery, and fulfillment preserve uncertainty and block further writes until reviewed.
- Guests and other owners cannot access investigations, drafts, previews, runs, contract actions, or engine controls.
- Credential failure, API rate limiting, and world resets stop safely. Local time display preserves UTC comparisons and deadline behavior.

Extend [GameplayTest](../tests/Feature/GameplayTest.php), the [real-worker check](../scripts/verify-mvp-worker.php), and the [browser journey](../scripts/verify-mvp-browser.mjs). Add focused evaluator and engine lifecycle checks. Use the repository's [verification entry point](../scripts/verify.sh) with its required runtime.

Drive the completed UI through both strategy choices, investigation, staging, stale-preview rejection, approval, Stop, End session, and restart. Inspect desktop and narrow screenshots, keyboard focus, validation messages, and reload recovery. Do not substitute a source review or successful build for browser evidence.

Before any enabled local use, review the changed authorization, process controls, and mutation boundaries for security defects. A live profitability trial is a separate explicit approval after synthetic acceptance. Do not register agents, start live runs, or install a recurring service during planning or offline verification.

## Rollout and recovery

Keep the new flow disabled until additive migrations and owned-fixture verification pass. Back up the real SQLite database with its matching application key before applying changes to the live local environment.

Preserve current manual play while the new flow is disabled. At final cutover, stop and settle existing work, invalidate old approvals, and require new previews. Do not delete uncertain actions or credit history to unblock a run.

An engine failure closes permission to send new actions. Reconcile any action already reserved. Recovery starts a fresh engine session and requires explicit approval of refreshed plans.

Code rollback after new data or action formats requires a compatible application and database pair. Stop processes first and retain evidence of upstream actions. Restoring an older local database does not undo game transactions and can create duplicate-action risk. Reconcile against current game state before permitting any further writes.

## Proposed decisions for review

- Ship Mining and Contracts through one investigation, staging, preview, and approval flow.
- Keep execution local and start the engine only on demand, with no automatic login start or automatic run resume.
- Limit the first contract runner to an existing contract, one ship, same-system routes, existing approved cargo, and priced purchases.
- Keep mining at one extraction per trip initially. Treat filling the hold and mining contract goods as later improvements.
- Recommend only from observed evidence. Offer a trial when earnings cannot yet be estimated.
- Require separate approval for scouting and setup. Include contract acceptance explicitly in the run preview and approval.
- Complete all offline acceptance before a separately approved live trial.

## References

- [Local automation research](local-automation-loop-research.md) records the strategy discussion and economic caveats.
- [Existing MVP implementation contract](mvp-implementation.md) records the durable command and recovery design.
- [Current local operating instructions](../README.md#local-operation) describe the two terminal processes being replaced by engine controls.
- [Official API specification](https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/reference/SpaceTraders.json), checked 2026-11-01, defines contract listing, acceptance, delivery, and fulfillment. Delivery consumes cargo, and fulfillment is a separate action after all delivery terms are met.
- The installed macOS `launchd.plist` manual describes `ProgramArguments`, `RunAtLoad`, `KeepAlive`, and child process-group cleanup. Verify the proposed service integration on this Mac during Phase 2.
