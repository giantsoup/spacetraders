# OpenAI adviser for the next ship activity

Status: future-feature plan, incorporating Taylor's answers on 2026-11-01. No application implementation or live acceptance has occurred.

Source review: local `main` at `88bc0262b65b70e611e2fdc37e64a5225591d5c0`. Existing untracked exploration research and its HTML comparison are separate work and remain unchanged.

## Intended behavior

When a ship becomes idle or an automation run blocks, the app gathers a bounded set of game observations. A deterministic evaluator identifies feasible activities and selects its preferred option. The app sends that evidence, the alternatives, and its initial recommendation to GPT-6 Luna for review.

If both select the same activity and every local authorization and safety check passes, the app can proceed automatically within Taylor's enabled policy. Otherwise it waits and presents the decision for review. The app retains a decision history, with Discord notifications added in a later phase.

Luna's useful role is to challenge the proposed choice, identify overlooked tradeoffs, and explain missing evidence. Local code owns feasibility, arithmetic, authorization, and execution. Agreement is an execution condition, not proof that either analysis is correct.

## Decisions Taylor has made

- Assess the next activity for an idle ship. Examples include mining, selling, refueling, waiting, and useful exploration within the configured scope.
- Allow automatic execution only when the deterministic evaluator and Luna agree, within limits Taylor sets.
- Protect a configurable minimum balance, initially **10,000 game credits**.
- Spend no more than **$5 per day** on this feature's OpenAI usage. OpenAI account limits remain an additional control.
- Balance income with useful exploration.
- Trigger assessments automatically when a ship becomes idle or a run blocks. Suppress duplicate assessments.
- Eventually send notifications to Taylor's Discord server.

These are feature requirements. This planning task does not enable automation, authorize paid API experiments, or send Discord messages.

## Current foundation and missing pieces

The existing [MiningPlanner](../app/Gameplay/MiningPlanner.php) chooses deterministic steps within an already selected mining route. It checks equipment, cargo, fuel, prices, and route constraints. It does not search and rank all possible activities. The current mining loop performs one extraction per trip.

[AgentSynchronizer](../app/Gameplay/AgentSynchronizer.php) refreshes the fleet and stores bounded waypoint, market, and shipyard observations. [GameObservation](../app/Models/GameObservation.php) stores the latest observation for each kind and symbol. Historical evidence for a particular assessment therefore needs its own immutable snapshot.

[MiningRunner](../app/Gameplay/MiningRunner.php) already provides preview, start, stop, approved limits, and state revalidation. [ShipCommandExecutor](../app/Gameplay/ShipCommandExecutor.php) provides the shared command path, durable intents, agent locking, and uncertain-write handling. [DispatchDueSpaceTraders](../app/Console/Commands/DispatchDueSpaceTraders.php) recovers pending commands and due mining steps. Its five-second schedule must not become a five-second model-call schedule.

The broader investigator and evaluator described in the [local automation implementation plan](local-automation-implementation-plan.md) are proposals, not implemented services. Reuse those proposed responsibilities instead of building a competing discovery and ranking system. The [exploration research](exploration-fuel-and-opportunity-research.md) supplies planning context, not live fleet evidence.

The adviser can ship before contracts or the proposed local engine manager. It needs bounded investigation, candidate evaluation, a persisted authorization policy, and working queue processing. Contract execution, ship purchases, equipment changes, scanning, charting, and intersystem expansion remain outside the first release.

## User workflow

1. Taylor configures a policy and reviews its scope. It names the eligible ship, permitted activities and system, protected balance, maximum activity spending and duration, exploration allowance, and API cap.
2. Taylor explicitly enables automatic decisions for that policy. This grants bounded authority for subsequent matching activities, rather than requiring approval of every assessment. Starting a worker alone grants no authority.
3. An idle or blocked event creates a decision task. The app shows progress through collecting evidence, evaluating options, and awaiting Luna.
4. The result shows both selections, whether they agree, the reasons, missing information, and the limits that apply.
5. On valid agreement, the app creates and revalidates an ordinary command or mining preview, records the policy grant that authorizes it, and starts only that bounded activity through the existing execution path.
6. On disagreement or failure, the ship waits. Taylor can inspect alternatives, refresh evidence, or manually preview a chosen action. Manual approval cannot bypass safety checks.
7. The app records actual results and reassesses at the next meaningful decision point. A run's internal dock, orbit, travel, sale, and cooldown steps remain deterministic.

Keep **Pause automatic decisions** separate from **Stop current activity**. Pausing prevents new automatic starts. Stop also invokes existing stop behavior for the active activity. An already reserved request can settle, and upstream travel continues. Disabling the policy invalidates pending decisions and prevents late model results from starting work.

The decision history uses the existing dark dashboard style. Show local timestamps and the detected timezone, with UTC stored and used for deadlines. The durable dashboard record remains available even when a Discord notification fails.

## What a decision covers

The first release considers one ship and one bounded activity at a time. Preserve the app's existing agent-wide command ownership because all ships share credits. Broader fleet scheduling is a later feature.

Candidate activities can include:

- A bounded mining-and-sale trip on an eligible, verified route.
- Selling explicitly permitted, unreserved cargo to an observed buyer.
- Refueling a permitted amount at an observed price.
- A bounded scouting navigation within the allowed system, with a verified fuel-safe continuation.
- Waiting for a known deadline or for user intervention.
- Collecting a specific missing observation through supported read requests.

An activity can contain several deterministic commands, but its route, quantity bounds, duration, and maximum spending are fixed before model review. The model selects an option ID. It cannot supply executable commands or modify those bounds.

Protect unrelated starting cargo by default. A sale policy must identify which cargo is eligible. Do not infer permission to liquidate inventory from the goal of earning credits.

Mark every option as feasible, needing information, blocked, or unsupported. Only a feasible and policy-authorized option can start automatically. Unknown prices, travel estimates, yields, or costs stay unknown. Missing market prices can require an approved scout activity; gathering information must not quietly move a ship.

## Income and exploration policy

Use a versioned, deterministic policy rather than asking Luna to invent the balance on each call.

For income, compare defensible net operating contribution and time, including travel, fuel, transaction limits, and relevant yield history. Report uncertainty. If mining yields are unmeasured, offer a bounded trial with unknown earnings instead of inventing credits per hour.

Useful exploration must answer a named question that could change a later choice. For example, a nearby market's missing ore price might change the preferred mining buyer. A generic desire to reveal more map is insufficient for an automatic trip.

Give exploration its own maximum credit cost, fuel use, time allowance, and frequency. It can consume some earnings while respecting the protected balance. Its anticipated information benefit must remain separate from expected cash receipts. Never budget an unknown chart reward or future sale as money available now.

The remaining tuning choice is the exploration allowance. Proposed starting policy: prioritize earning until there is verified surplus above the floor plus recovery costs, then permit a small, explicitly configured scouting allowance. Automatic exploration stays off until Taylor chooses that allowance. Income decisions can operate independently.

Proposed deterministic selection order:

1. Remove unsafe, unauthorized, unsupported, or insufficiently observed activities from the executable set.
2. If the configured exploration interval is due and its cost and time allowances remain available, prefer a scout task that resolves a decision-changing evidence gap. Rank those tasks by the affected opportunity's priority, then lower maximum cost and shorter duration. Do not convert unknown information value into invented credits.
3. Otherwise prefer the income activity with the strongest supported conservative net contribution per unit of time. When that comparison is unavailable, consider an explicitly permitted bounded trial rather than claiming a profitable winner.
4. Break equivalent choices by lower committed capital, shorter duration, and a stable candidate ID. If no activity qualifies, select waiting or a read-only refresh.

This is a proposed policy shape. The exploration interval and allowances remain settings Taylor must choose. Save the score components and the rule that selected the winner so Luna and Taylor can inspect the actual decision.

## Protected 10,000-credit floor

Treat this as an agent-wide spending invariant for actions controlled by this app. Before every purchase or refuel, require:

```text
fresh confirmed credits
  - outstanding maximum debits across all ships
  - this action's maximum debit
  >= protected balance
```

Use fresh server state and reserve the maximum debit atomically when reserving an action. A debit reservation is released only when its outcome is confirmed or reconciled. A preview alone must not reserve credits indefinitely. Pending and uncertain actions retain their conservative reservations.

Also prove that a multi-step activity can retain the floor through its intermediate steps and pay its conservative recovery costs. A projected profitable ending balance does not justify dipping below the floor along the way. Unconfirmed sales do not fund current purchases.

At exactly 10,000 credits, reject positive-debit actions. A verified zero-debit action can remain eligible if it meets the other rules. Below the floor, stop discretionary spending and report the condition. A separately evaluated sale may help recovery when ownership and cargo permissions are resolved.

Refresh balance after confirmed transactions. Price changes, unknown maximum charges, unexplained balance changes, or an uncertain write block new spending. Keep per-activity spending, loss, and duration limits as well. A balance floor alone does not bound all risk.

This cannot guarantee that the upstream balance never falls below 10,000. Another client, an external debit, or an upstream discrepancy can change it. State the enforceable promise accurately: this app does not knowingly authorize a debit whose worst-case checked result breaches the floor, and it halts on a discrepancy. Avoid concurrent gameplay through other clients while automatic decisions are enabled.

## Evidence sent to Luna

Build a compact, allowlisted packet from a persisted investigation. Include:

- Decision ID, world reset, ship identity, scope, and creation and expiry times.
- Planner, policy, prompt, and packet-schema versions.
- The balanced objective, protected balance, remaining spending allowance, and exact permitted activities.
- Ship position, status, equipment, fuel, cargo eligibility, cooldown, and relevant run ownership.
- Credits, outstanding debit reservations, and unresolved command status.
- Relevant waypoints, market quotes, fuel access, and confirmed result history, with observation IDs and timestamps.
- Discovery coverage, limits reached, missing observations, and uncertain estimates.
- Candidate IDs, immutable plan hashes, feasibility results, cost bounds, income estimates when supported, and exploration questions.
- The deterministic selection and its score components, assumptions, and reasons.

Do not export API credentials, account email, cookies, arbitrary logs, or full database records. Treat names and game descriptions as untrusted data. Luna gets no game tools, browsing, shell access, or game credentials.

The prompt asks Luna to assess the supplied evidence, challenge the initial choice, and choose an existing feasible option or abstain. Ask for concise reasons and evidence references, not hidden reasoning. An omitted possibility can become a suggestion for a future investigation, but cannot become an executable option in the returned answer.

Because Luna sees the initial recommendation, agreement can reflect anchoring. Evaluate some saved cases with the baseline choice hidden to measure that effect. Do not describe agreement as independent verification.

## OpenAI integration

Use the documented model ID `gpt-6-luna`, configurable server-side. Its official model page lists Responses and Structured Outputs support. Verify access in Taylor's project before enabling paid use. Model availability here in the coding agent does not establish API-project access. [GPT-6 Luna documentation](https://developers.openai.com/api/docs/models/gpt-6-luna)

For the first version, use one `POST /v1/responses` request with a strict JSON schema through `text.format`. Use `store: false`, a bounded input size, and an output-token ceiling that includes billable reasoning where applicable. Validate the supported schema subset during implementation. Refusals and incomplete responses need separate handling. [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs)

The typed answer needs a decision ID, `choose`, `abstain`, or `request_refresh`, a candidate ID or null, a plan hash or null, short reasons, evidence references, and missing-information references. All fields are required, with nullable values where appropriate and no additional properties. Constrain candidate values to supplied IDs and validate their eligibility locally. Derive agreement locally by comparing the selected candidate and plan hash. Do not trust a model's self-reported agreement or confidence as authority.

The Decisions API is another documented Luna option. It accepts JSON evidence and fixed choice, predicate, or score questions. Responses is the initial recommendation because the feature also needs written explanations and a custom answer object. Consider Decisions later if fixed-choice validation with templated explanations proves sufficient. Avoid a two-call chain by default. [Decisions guide](https://developers.openai.com/api/docs/guides/decisions)

The current standard short-context Luna rates are $0.10 per million input tokens and $0.50 per million output tokens. Pricing, cache charges, service tiers, and account access must be rechecked before implementation. No latency, quality, or actual cost has been measured for this app. [API pricing](https://developers.openai.com/api/docs/pricing)

Keep the API key in server configuration and out of the browser. An existing Laravel HTTP transport is sufficient for the narrow request; select a PHP package only if it removes useful work. Keep provider-specific parsing in one adapter.

`store: false` does not mean zero retention. OpenAI documents separate abuse-monitoring and prompt-cache rules, and says API data is not used for training unless the customer opts in. Start without hosted tools, files, or conversations. Retain sanitized local evidence and decisions under a documented retention policy. [Data controls](https://developers.openai.com/api/docs/guides/your-data)

## $5 daily API budget

Enforce a separate application ledger for this feature, independent of game credits. Proposed day boundary: midnight in `America/Los_Angeles`, shown explicitly in settings. Store the corresponding period boundaries and all attempt timestamps in UTC. Handle daylight-saving changes as calendar-day changes, not fixed 24-hour windows.

Before sending, atomically reserve a conservative maximum cost based on bounded input, output allowance, verified pricing, and the selected service tier. Include retries and all billable token categories. Unknown pricing or an unbounded request prevents a call. Reconcile the reservation with actual usage after a completed attempt.

Keep reservations for timed-out or otherwise billing-unknown attempts until usage is reconciled. Include reservations from previous periods when later billing attribution is uncertain. Retries must reserve their own cost. Apply an independent request-count ceiling and concurrency limit to contain event storms.

Never dispatch when confirmed spending plus unresolved reservations plus the proposed attempt would exceed $5. OpenAI-side limits are a second control, not a substitute for this ledger. The app ledger covers its own calls, not unrelated tools sharing the API project. Provider billing discrepancies remain detectable limitations rather than grounds to claim an absolute account-wide guarantee.

At the cap, show **API budget reached** and suspend new model-dependent automatic starts. Existing bounded activities can finish through their deterministic runner. Manual previews remain available. Do not silently downgrade to deterministic-only automatic approval.

## Agreement and failure rules

Automatic start requires all of these conditions:

1. An enabled, unexpired policy still authorizes this exact ship and activity.
2. Both systems select the same candidate ID and immutable plan hash.
3. Required evidence is complete and fresh enough for the activity.
4. Reset, policy version, ship state, credits, reservations, cargo, prices, and command ownership pass fresh local checks.
5. The spending floor, activity limits, and fuel-safe continuation remain satisfied.
6. The decision has not expired, been superseded, been consumed, or been cancelled.

Any material change creates a new assessment. Do not attach the old agreement to a revised plan. Model latency does not extend the original evidence deadline.

On disagreement, abstention, unsupported suggestions, invalid references, malformed output, refusal, timeout, exhausted budget, or provider failure, do not start a new automatic activity. Show the deterministic result and the adviser status, with a review item for Taylor.

Blocked-run triggers require classification. A stale observation can justify bounded read-only refresh. Pending or uncertain writes, price review, world reset, unexpected cargo or credits, and unresolved active-run ownership require their existing reconciliation or stop process. Neither Luna nor a new decision task can clear those blocks. Analysis of a blocked run does not grant permission to abandon it or take over its ship.

Waiting is a valid choice. A known future deadline can schedule a local recheck. Unchanged missing evidence or a repeated block must not create an endless model loop.

## Triggering, deduplication, and queue behavior

Define idle as having no active activity or unresolved command ownership, rather than simply being stationary. Generate events after confirmed action or run transitions, and once after enabling a policy with a refreshed idle ship. A blocked event can request analysis even while ownership remains reserved.

Create a semantic fingerprint from agent and reset, ship, policy version, meaningful state, block category, observation revisions, and candidate plans. Exclude changing display timestamps and heartbeat values. A database uniqueness rule coalesces equivalent pending or completed assessments. A bounded refresh interval can make old information eligible for a new read without repeated model calls for unchanged evidence.

Persist a decision state such as collecting, evaluating, awaiting model, awaiting review, approved, consumed, expired, cancelled, or failed. Record budget exhaustion and disagreement as explicit reasons. Persist model attempts separately with request IDs, usage, latency, and sanitized errors.

Capture a snapshot under a short agent lock, then release it before calling OpenAI. Never hold the gameplay lock or a database transaction across model latency. Run adviser jobs on a separate queue with its own worker capacity so they cannot occupy the worker needed for game commands or Stop handling.

Use bounded retries for temporary provider failures, respecting server delay and remaining evidence lifetime. Do not retry billing, authentication, or invalid-schema failures automatically. Client request IDs aid diagnosis; they do not establish billing or action idempotency. [Rate-limit guidance](https://developers.openai.com/api/docs/guides/rate-limits#retrying-with-exponential-backoff), [request IDs](https://developers.openai.com/api/reference/overview#debugging-requests)

At automatic start, atomically claim the decision and link it to one durable command intent or automation run. A unique decision-to-execution reference lets duplicate jobs recover the same result. Late model replies cannot create another action. After restart, refresh state and policy validity before proceeding. Stop, policy disablement, and reset invalidate pending starts.

## Proposed implementation boundaries

These names describe future responsibilities, not existing classes:

- `OpportunityInvestigator` gathers bounded observations and stores coverage and immutable evidence.
- `OpportunityEvaluator` calculates candidates, feasibility, costs, and preference from supplied evidence without network calls.
- `AutomationDecisionService` coordinates the persisted task, trigger suppression, model job, agreement checks, and one-time handoff to existing preview and execution methods.
- `OpenAiAdviser` owns the request, strict response parsing, and usage reporting. It cannot call gameplay execution.
- An API-budget ledger reserves and reconciles dollars for each model attempt. Agent-wide debit reservations protect game credits separately.

Reuse `AgentSynchronizer`, `MiningPlanner`, `MiningRunner`, `CommandRules`, and `ShipCommandExecutor`. Enforce the balance floor at the shared command boundary so automated paths cannot bypass it. Define clearly whether manual spending uses the same floor or requires a separately recorded policy change. The first proposal applies it to all spending while this policy is enabled.

Add persisted policy grants, decisions and candidate snapshots, model attempts and dollar reservations, game-credit reservations, and notification delivery records. References include user, agent, reset, ship, policy version, and the resulting action or run. Do not duplicate the existing `ShipAction` execution ledger.

All routes must enforce ownership and normal CSRF protection. Approval from model text or notification content is never accepted. The server reloads the saved candidate and policy grant instead of trusting browser-supplied arguments.

## Discord delivery as a later phase

Use an incoming webhook for one-way delivery to a chosen server channel. A bot and Discord command permissions are unnecessary for notifications alone. Keep the webhook URL secret in server configuration. [Discord webhook reference](https://docs.discord.com/developers/resources/webhook)

Proposed notification policy: send disagreements, blocked decisions that need intervention, reserve breaches, and API-budget exhaustion immediately. Send successful automatic activity results as a digest to avoid a message for every command. Exact alert types and digest frequency remain a tuning choice.

Include a decision identifier, ship, both recommendations, brief reason, protected balance, and local decision time. Omit credentials and full packets. A local `.test` dashboard link may be unusable from another device, so messages must be understandable without it. Remote dashboard access is a separate feature.

Persist a notification outbox after the decision transaction. Send with `wait=true` and save Discord's returned message ID. Disable mention parsing with `allowed_mentions: {"parse": []}`. Honor rate-limit responses. [Execute webhook](https://docs.discord.com/developers/resources/webhook#execute-webhook), [allowed mentions](https://docs.discord.com/developers/resources/message#allowed-mentions-object), [rate limits](https://docs.discord.com/developers/topics/rate-limits)

Suppress duplicate notifications by decision and event. A send timeout can leave delivery unknown, so retry policy must acknowledge possible duplicate messages. Discord outages do not block safe approved gameplay or erase review items. Reading Discord replies, buttons that approve spending, and two-way bot control are outside this phase.

## Delivery sequence and acceptance evidence

1. Build the deterministic foundation and policy. Implement bounded candidate discovery, protected balances, credit reservations, and fixture-based ranking. Demonstrate supported options and honest missing-information results without OpenAI.
2. Add the adviser in observation mode. Use synthetic or sanitized saved packets and mocked provider replies first. Then run a separately authorized, small paid experiment within the $5 cap. Record baseline and Luna selections without permitting automatic starts.
3. Add policy-authorized execution. Enable it only after the agreement, stale-state, duplicate-job, stop, and balance-floor cases pass. Verify the actual dashboard flow with owned fixtures. An execution decision must reach the same command boundary as manual play.
4. Enable bounded live use under Taylor's configured policy. Record confirmed receipts, costs, fuel changes, and information gained. Separate tested local behavior from actual gameplay outcomes.
5. Add Discord notifications. Verify delivery in Taylor's chosen channel after webhook setup and explicit test-send authorization. Dashboard records remain authoritative.

The test set must cover:

- Same selection, disagreement, abstention, missing evidence, invented options, invalid hashes, refusal, and incomplete output.
- A debit ending exactly at 10,000, a debit crossing the floor, two ships competing for credits, a price change, and a timeout retaining its debit reservation.
- A balance already below the floor and a permitted sale that requires no new spending.
- Duplicate trigger events, duplicate queue jobs, crash recovery around handoff, reset, policy disablement, and Stop during a pending review.
- Late Luna responses after evidence expiry or manual ship changes.
- The $5 boundary with concurrent attempts, retries, unknown usage, and the configured day boundary including daylight-saving changes.
- Blocked-run reconciliation that cannot be bypassed by agreement.
- Missing yield history, exploration without a specific question, and a scout route without verified recovery fuel.
- Discord rate limits, delivery-unknown timeouts, duplicate suppression, and untrusted text containing mentions.

Evaluate whether Luna adds value. Track actionable disagreements, correct abstentions, unnecessary waits, unsupported claims, latency, token cost, and eventual activity outcomes. Include known unsafe cases and cases where the deterministic ranking is intentionally wrong. Compare against the deterministic-only baseline on the same evidence. Two agreeing systems can share the same faulty inputs.

## Remaining choices before implementation

- Exact exploration time, fuel, and spending allowance, plus which system and cargo the policy may use.
- Per-activity spending, loss, duration, and trial limits. The 10,000 floor does not replace these settings.
- Policy lifetime and whether any automatic behavior may resume after a local process restart. Default proposal: require explicit re-enabling after restart.
- Confirmation of the proposed Los Angeles calendar-day API budget boundary.
- Local evidence-retention duration and Discord alert frequency.
- The Discord channel and webhook setup, when that phase is requested.

No key is needed to review this plan. This investigation accessed no credentials, made no authenticated game calls or paid OpenAI calls, started no workers, and sent no notifications. Model quality, account access, live costs, and gameplay results remain unverified.
