# Fleet groundwork audit

Stage 1 adds operator-approved fleet purchases and permits one active automation run per ship.
The campaign v1 grant still runs its command and probe activities serially. It cannot buy ships or adopt a hauler.

## Accounting and shared writes

A new run records `initial_external_credit_delta` at Start. Both balance guards then add confirmed credit deltas from actions outside that run, minus this snapshot.
Outside actions include manual commands and actions belonging to any other run in the same agent and reset.
This explains concurrent purchases and sales without assigning their profit or spending to the wrong run.
Plans without the new key retain the original `initial_manual_credit_delta` formula. Deployment already stops historical runs before new approvals.

The per-agent lock and `ShipCommandExecutor::clear` still serialize writes. Manual commands refuse while any action is pending, sending, uncertain, or awaiting review.
Automation retries pending or sending contention without rejecting its own action or blocking its run. Uncertain actions and review requirements block all writes for the agent.
The director can make decisions during an unrelated ship's pending action. Starting or executing a write waits for that action to settle.

`CampaignSafety` defers the unexplained-balance verdict while any action for the current agent and reset is pending or sending.
This prevents a balance observed between transport and confirmation from causing a false pause. A discrepancy that remains after settlement still pauses.
Uncertainty takes precedence and pauses immediately.

A general ship purchase records `ship_purchase`. The one-time miner purchase keeps `setup_cost` and its setup prerequisites.
Purchase previews show the type, shipyard, `purchasePrice`, current credits, credits after purchase, and protected commitments.
The existing explicit reserve and configured manual minimum remain. There is no additional fleet credit floor.
For a campaign trade carrying cargo, the purchase protects `CampaignTrade::fuelReserve` for delivery.
Other campaign runs contribute zero. Plain runs retain reserve plus remaining approved spending. Multiple purchase commitments are added together.

## Migration and campaign ownership

The migration adds the unique `(active_agent_id, ship_symbol)` index before dropping the agent-only unique index.
It changes indexes without copying or replacing rows. Existing active rows survive, and null authority slots remain reusable.
Rollback restores the old unique index first. Rollback refuses if several ships are active for an agent rather than discarding live runs.

`CampaignFleet` pins role symbols in `episode.state.role_ships`. Historical started episode runs seed a missing pin before symbol-order fallback.
A pinned ship remains the role ship while it exists. The same original role rules select a replacement if it disappears.
The director handles only its episode's runs. An occupied role ship causes `role_ship_busy`; a manual hauler run is independent.
Pending actions on role ships or episode runs count as campaign work. Other actions only contend at the shared write boundary.

## Audited sites

| Site | Result and reason |
| --- | --- |
| `database/migrations/2026_11_15_000001_allow_one_active_run_per_ship.php` | Added the index migration and guarded rollback order. Tests cover a live row, two ships, duplicate same-ship authority, and repeated null slots. |
| `AutomationRunner::start`, `startCampaign` | Start checks authority for the same ship. Campaign authorization and plan versions remain intact. New runs snapshot external confirmed deltas. |
| `AutomationRunner::advance` | Pending and sending actions defer. Uncertainty and review still block agent-wide. The balance guard uses outside-run deltas. |
| `AutomationRunner::stop`, `finishStop` | Verified unchanged. Both act on a specific run id. Multiple runs complete their stop separately. |
| `CommandRules` | Ownership and transfer-target checks use the actual ship. Both balance guards share the accounting formula. General purchases can originate from an active ship and show protected commitments. Setup takeover rules remain. |
| `ShipCommandExecutor` | Added retryable automation contention to `clear`. Reservation, expiry, stop, lock, receipt, and no-replay checks remain. Confirm already syncs the delivered ship and records its credit delta. |
| `CreditAccounting::record`, `outsideRunDelta` | Added purchase classification and shared outside-run attribution with historical-plan fallback. |
| `CreditAccounting::summary`, `review` | Verified unchanged. Confirmed purchases explain balance changes. Balance review still requires every agent run to be stopped. The UI retains that agent-wide condition. |
| `CampaignDirector` | Scoped active-run handoff, retained previews, preview cancellation, and pending work to the episode and role ships. All probe-positioning and recovery role lookups use pinned roles. |
| `CampaignFleet` | Added common role resolution and campaign pending-action selection for director and grant replacement. |
| `CampaignSafety::reason`, `metrics` | Deferred unexplained balance during pending or sending actions. Metrics already scope runs by episode, so manual haulers do not add campaign cycles. |
| `CampaignControl::busy`, `activatePending`, `stopRuns` | Busy counts episode runs and role or episode actions. A manual hauler cannot indefinitely hold a replacement grant. Stop already selects all grant episode runs. |
| `CampaignStatus`, `CampaignReport` | Status now selects the episode's active run. Report already selects episode runs and uses confirmed action outcomes rather than the setup entry-kind string. |
| `CycleAccounting` | Verified unchanged. Capital comes from confirmed delivered-ship purchases and transaction totals. Run economics use each run's own totals, independent of entry-kind strings. |
| `AgentRegistration::save` | Verified unchanged with two runs. Any active run without a stop request, or any pending or sending action, refuses credential replacement. |
| `AgentSynchronizer::archiveForReset` | Verified unchanged with two runs. It requests stop on all active runs. Their advance jobs finish the stop before further HTTP or gameplay writes. |
| `AgentCommandController::acknowledge` | Kept agent-wide. Reviewing an adjustment invalidates all active run approvals because attribution or the shared balance required operator review. Confirmed review retains its existing behavior too. |
| `EngineGate` | Verified unchanged with two runs. Closing admission invalidates all unsent actions and previews and requests stop for every active run. |
| `SuperviseLocalEngine` | Verified unchanged with two runs. After children exit, it finishes every stop-requested run whose agent has no uncertainty or review hold. |
| `DispatchDueSpaceTraders` | Verified unchanged. It dispatches by run id, with per-run due and dispatch timestamps, across up to 50 due rows. It has no single-agent run slot. |
| `DeploymentGate`, `LocalEngineManager` | Verified unchanged. Deployment drains process and queue leases. Engine end or fault calls the shared gate. Campaign grant authority survives; plain approvals do not. |
| `RequestBudget` | Verified unchanged. Shared host and account buckets use a file lock. Reservations count each HTTP request once in each applicable bucket. Runs do not create extra allowance or reserve by run id. Pacing defaults to 1.9 requests per second. A regression verifies two callers share the same allowance and request counts. Existing deferral and queue delivery let both tested ships progress. |
| `Adviser`, `AdviserSelector`, `AdviserPanel.vue` | Adviser evidence selects the policy ship's run. Selector consumes the corrected evidence. The panel already matches the policy ship when selecting a run. Unsettled actions still gate writes agent-wide. |
| `AgentDashboardController`, `Dashboard.vue`, `AutomationConsole.vue` | Response includes every active run plus recent history. Controls and outcomes use the selected ship. Added the fleet purchase panel, purchase review values, and a list with Stop for every active run. |
| `ManualCommands`, `ManualCommandForm.vue`, `guidedFlight.js` | Verified unchanged. The general command already exists and its warning remains accurate. The guided miner setup keeps its separate path. |
| `scripts/campaign-smoke.php`, `scripts/verify-*.php` | Reviewed for active-agent assumptions. Existing singleton fixtures and idle preconditions remain valid. No production database smoke command was run. |

## Verification and limits

Final full PHP suite on 2026-11-15: `OK (978 tests, 5871 assertions)`.
Run with `C:/Users/taylo/.config/herd/bin/php85/php.exe vendor/bin/phpunit`.
The JUnit report is available locally at `storage/app/fleet-groundwork-phpunit.xml`.
Pint passes for every changed PHP file, and `git diff --check` passes.


Tests use in-memory SQLite and fake SpaceTraders responses. Dependencies were installed from the lockfiles without Composer scripts or an environment file.
No production checkout, deployment, real SpaceTraders request, Discord post, push, or PR is part of this work.

The application JavaScript checks and `npm run build` pass. The rendered automation selection check also verifies that another ship's run leaves the selected ship's controls enabled.
The POSIX verification-lifecycle suite was attempted and fails on Windows permissions and `process.getuid`.
`scripts/windows/verify.ps1` explicitly skips that suite on Windows for permission, symlink, and signal assumptions. Its syntax check passes.

A concurrent credit change between preview and Start can still invalidate the preview or its fingerprint. Create a fresh preview in that case.
This stage adds no multi-ship campaign scheduling, purchase automation, trade allocation, or fairness scheduler.
The shared budget prevents extra request allowance, but does not promise fairness for a large fleet. The existing dispatcher batch limit is 50 runs.
Uncertainty and operator balance reviews remain agent-wide by design.

Run spending ceilings are not cash allocations. Each action rechecks the shared balance and its run limits; a depleted balance can require a new plan.
Episode credit samples include every agent balance change, including manual fleet investments. Per-run campaign reports remain scoped to campaign actions.

## Changed files

Implementation files:

- `app/Gameplay/Adviser.php`
- `app/Gameplay/AutomationRunner.php`
- `app/Gameplay/CampaignControl.php`
- `app/Gameplay/CampaignDirector.php`
- `app/Gameplay/CampaignFleet.php`
- `app/Gameplay/CampaignSafety.php`
- `app/Gameplay/CampaignStatus.php`
- `app/Gameplay/CommandRules.php`
- `app/Gameplay/CreditAccounting.php`
- `app/Gameplay/ShipCommandExecutor.php`
- `app/Http/Controllers/AgentDashboardController.php`
- `database/migrations/2026_11_15_000001_allow_one_active_run_per_ship.php`
- `resources/js/Components/AutomationConsole.vue`
- `resources/js/Pages/Agent/Dashboard.vue`

Verification and documentation files:

- `scripts/verify-automation-selection.mjs`
- `tests/Feature/AdviserTest.php`
- `tests/Feature/CampaignAutopilotTest.php`
- `tests/Feature/FoundationCommandTest.php`
- `tests/Feature/GameplayTest.php`
- `tests/Feature/OpportunityTest.php`
- `tests/Feature/RequestBudgetTest.php`
- `tests/Unit/Engine/SuperviseLocalEngineTest.php`
- `docs/fleet-groundwork.md`

Tracked Vite output in `public/build/manifest.json` and `public/build/assets` was rebuilt.
The compiled chunks have new hashes. No source styles or guided-flight code were added or changed.
