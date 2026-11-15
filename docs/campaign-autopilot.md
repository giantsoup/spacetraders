# Operate the campaign autopilot

The campaign director runs inside the existing dispatcher, at most once per 30 seconds.
It works with an already-running engine and resumes after a healthy restart. No LLM is
in the execution path. These commands do not start the engine.

## Import and approve

After this checkout's migrations have run:

~~~powershell
php artisan campaign:import campaigns/weekly-default.yaml
php artisan campaign:approve <revision-id-or-full-hash> --user=1
php artisan campaign:status
~~~

Approval authorizes unattended gameplay writes while the engine gate is healthy and
automation is enabled. Add --expires=<ISO-8601-time> for an expiring grant. Without
--user, approval uses SPACETRADERS_OPERATOR_ID, or the only local user.

Source bytes, including hypothesis comments, determine the immutable revision hash.
Importing identical bytes reuses the revision. Editing and importing creates a new one.
A replacement grant takes over only between runs and settled actions. There is one
active grant and at most one pending replacement. Plain automation runs still need
their own human approval.

The default agent is GIANTSOUP, faction VOID. Credentials never belong in the playbook.
Registration uses the operator's saved account token and the value on that operator's
most recent agents.email row, when present. Save the account token through the existing
account-token flow before leaving the campaign unattended.

## Pause, resume, and revoke

~~~powershell
php artisan campaign:pause
php artisan campaign:status
php artisan campaign:resume
php artisan campaign:revoke
~~~

Pause prevents further authority and stops pending campaign work. It cannot undo a
request already sent. Resume requires operator review; it does not replay an old preview
or uncertain write. Revocation or expiry requires a new approval.

Uncertain actions, price reviews, and unexplained credit changes pause immediately.
Reconcile through the existing action and balance-review workflows before resuming.
A second consecutive losing load stops buying, sells cargo aboard, then pauses.
Resume acknowledges the old loss streak but the next trade still needs a passing proof.

There is deliberately no credit floor or episode loss cap. Taylor accepted losing the
wipe. A buy must nevertheless leave enough cash for the fuel-safe delivery and refueling
continuation. Batches shrink or are skipped when necessary; that is not a safety fault.
The existing mining planner's verified route/fuel reserve still applies to mining runs.

A blocked or freshness-held campaign run is stopped before the active-run early return.
Pending/sending actions and transit must settle before the replacement starts. The
replacement uses fresh state and a new preview. Deploys close old runs, but an approved,
unpaused campaign resumes automatically once the engine is healthy again.

## Understand the playbook

The v1 schema rejects unknown keys, including obsolete loss/exposure settings. Roles are
command and probe; the latter resolves to a satellite or probe frame. Activities are
scout, trade, mining, and sell_aboard, tried in file order.

Gates contain one metric, or any with a list of metric gates. Supported metrics are
minutes_elapsed_gte, hours_to_reset_lte, credits_gte, markets_observed_gte,
cycles_completed_gte, and minutes_without_trade_gte. Mining is the fallback after fifteen
minutes without a trade. One hour before the confirmed reset, wind_down only sells cargo:
no purchases or mining, including previously queued buys.

Trade settings govern proofs, lot sizes, switching, losses, and quote age:

- Every new good/buyer pair starts with a proof, bounded by max_units_batches and max_credits.
- Scaling requires realized margin at least min_realized_ratio of predicted margin. The
  first scaled load uses first_scaled_max_buyer_batches (three by default), later loads
  max_buyer_batches (five), always within hold capacity and fuel-funded affordability.
- A negative load, posted fuel included, requires another proof. A forced fallback sale
  also counts as losing even if cash proceeds are positive. Two consecutive losing loads
  trigger sell-aboard followed by a pause.
- Selection ranks every observed source/buyer/good combination deterministically by
  predicted margin per travel/trade minute. Bid predictions decay by buyer batch; source
  costs use conservative reciprocal inflation by source batch. Equal-sized realized sale
  batches at that buyer teach decay within the episode; otherwise default_batch_decay applies.
- Keep the current pair unless its bid is at or below bid_below_first_seen times its
  first-seen bid, or another pair scores at least better_pair_ratio times as well.
- Both endpoint quotes are refreshed before approval. A buy needs quote lifetime beyond
  the remaining loading allowance (ten seconds per source batch plus ten seconds).
  Remote directories do not fabricate fresh detailed prices. The probe is repositioned
  with a one-way scout run to the selected buyer, where it can refresh detailed quotes.
- Source positioning also uses a bounded one-way scout run. Runs remain serialized.

Discovery is a separate bounded decision, refreshed more often in discover than earn;
large directory sweeps cannot consume every trade-planning tick. Every decision records
its candidates, rejected choices, and selection. Existing preview fingerprints, engine
gates, request budgets, and the command executor still apply.

Sell-aboard chooses the best known bid for each cargo good and refreshes on arrival.
It holds the lot's confirmed average-cost floor. After ten minutes without a qualifying
buyer it accepts the best bid; the timer survives run replacement and restarts. Only a
complete absence of known buyers causes no_cargo_buyer; temporary quote or route gaps wait.
Recovery neither buys goods nor mines.

Ship purchases, equipment changes, contracts, refining, siphoning, jumps, automatic tuning,
and concurrent runs are outside the grant.

## Resets and registration recovery

A new episode requires the public reset id and a future serverResets.next. Unknown,
overdue, or unconfirmed schedules wait. Confirming a new reset closes the old episode.

A durable registration intent is recorded before sending. An ambiguous response or crash
never causes a repeat registration, even after resume. Inspect the official account
and import the current agent credentials through the existing import flow, then resume.
The imported agent is reused. Missing or rejected saved credentials require operator review.

## Monitor independently of the engine

campaign:status reads local state only. It prints the grant, episode, phase, latest decision,
wait and pause reasons. It exits nonzero for a paused campaign or a missing/older-than-five-
minute engine heartbeat. No approved campaign means there is nothing to monitor.

~~~powershell
php artisan campaign:watchdog
.\scripts\windows\watchdog.ps1 -Install
.\scripts\windows\watchdog.ps1 -Uninstall
~~~

Install from the checkout to be monitored. The independent, checkout-specific Windows
Scheduled Task runs every fifteen minutes as the current interactive user with hidden
windows. Like the engine task, it requires that user to remain logged in. Installation
replaces only this checkout's watchdog task; uninstall removes only that task. Neither
operation changes or starts the engine. deploy.ps1 leaves this separate task alone.

On a problem the watchdog always attempts a Windows toast and appends to
storage/logs/campaign-watchdog.log. Optionally configure CAMPAIGN_ALERT_WEBHOOK_URL privately
in .env for a Discord webhook; keep its value out of source control and logs. Rebuild any
cached application configuration after changing the environment. Delivery errors are
redacted and do not suppress local alerts. A toast may be hidden by Windows notification
settings; the file log remains available.

Each distinct problem alerts once, repeats after two hours, and produces one recovery
message when cleared. Deduplication state is stored in storage/app/campaign-watchdog.json,
independently of the engine scheduler. Status and watchdog never call SpaceTraders.

## Read a weekly report

~~~powershell
php artisan campaign:report
php artisan campaign:report <episode-id> --output=storage/app/campaign-reports
~~~

The command writes episode-<id>.md and episode-<id>.json: fifteen-minute samples, confirmed
cash by run/strategy, CR per ship-hour, idle time, shared request-counter deltas, pauses,
and predictions. Load results link recovery sales to their acquisition run; they include
proof eligibility, prediction/realized ratio, forced sales, and fuel still needing replacement.
A proof delivered to a different buyer cannot qualify the originally predicted pair.

Learning predictions support proof_realized_ratio, minutes_to_first_profit, and
freshness_holds; timed credits predictions remain supported. Missing observations are
not_observed, not passed. Live scores are provisional. Cash is not economic profit: wear,
unmatched cargo, fuel replacement estimates, and shared request-counter attribution remain
explicit limitations. No report or status command contacts SpaceTraders.

## Offline verification

~~~powershell
php vendor/bin/phpunit
php scripts/campaign-smoke.php
~~~

The smoke check requires this development checkout's migrated SQLite database, an off
engine, and no active work. It denies HTTP, creates a fake episode in a transaction,
prints status (unhealthy is expected with the engine off), writes a report, then rolls
back its rows. It neither registers an agent nor starts automation. Do not run it on production.
