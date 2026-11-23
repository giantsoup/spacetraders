# Bridge UI decisions

Taylor answered these 11 questions on 2026-11-23. They settle the open items for **Bridge**, the redesigned campaign screen (sketched earlier as "Fleet brief"). The code references were checked against `origin/main` at `13ab398`.

- Wireframes: [`docs/fleet-brief-wireframes.html`](fleet-brief-wireframes.html) (final design H1–H6, iPad, attention catalogue, data sources).
- Engineering and UX plan: [`docs/bridge-ui-plan.md`](bridge-ui-plan.md).

Questions 1–6 are quoted verbatim from the wireframe doc's former "Questions for you" section. The answers below are recorded as given. Each answer lists what it means for the design and the code. Where an answer meets a limit in the code, the limit is stated.

## Original wireframe questions (PR #119, 2026-11-17)

### 1. Voice and naming

> **Voice.** Plain ("1 item needs you") or in character ("Admiral, one item needs your attention")? And the name: Fleet brief, Bridge, or something else? (The menu link reads "Campaign" today.)

**Answer:** In character ("Admiral…"), and the name is **Bridge**. Example: "Admiral, 1 item needs your attention."

**Implications**

- The product name is **Bridge**. Its home tab keeps the name **Brief**, so the tabs read `Brief · Routes · Fleet · Log`. The page kicker reads "Bridge · Day 2 of 7 · Earn phase".
- The chief of staff speaks in the summary line under the credits box, including the authorization and reset summaries in Plan §2.3. Empty sections, the stale-data message, sheet headings, card titles, row text, buttons and errors use plain language. Status words such as Paused, Running and Idle never change into role-play.
- "Admiral" appears at most once per screen, at the start of the summary sentence. It never appears in buttons, `aria-label`s or log rows. Screen readers hear the same sentence a sighted user reads.
- The menu link in `resources/js/Layouts/AppShell.vue` changes from "Campaign" to "Bridge" at cutover. During coexistence the menu keeps "Campaign", and Bridge is reached from the toggle on `/campaign`. See decision 11.
- The copy guide is in [plan §2](bridge-ui-plan.md#2-voice-naming-and-ux-contract).

### 2. Resuming one ship from the phone

> **Resume one ship from the phone.** Resuming a single stopped ship is CLI-only today. Should the phone get that button (after the review sheet)? This design keeps approvals, renewals and loss limits off the phone: the plan renews ships, and a new playbook is approved on the PC during the weekly review.

**Answer:** Yes. Allow single-ship resume and Retire behind review sheets. Taylor confirmed Retire for `arm_loss_stop`, five consecutive director failures, and expired approvals. Playbook approvals, renewals and loss-limit changes stay on the PC.

**Implications**

- Two new operator-only web actions in `routes/web.php` reuse the control methods behind the CLI commands.
  - **Resume ship** calls `CampaignControl::resumeArm()`, which `campaign:arm-resume` also uses. That method already takes `HostLock('campaign')`. It refuses an expired arm ("Arm grant expired. Approve a new arm.") and an arm whose episode net is past `max_net_loss` ("Arm remains past its loss stop…"). Bridge records outstanding results under the campaign, agent and result locks before opening its SQLite authority transaction. A newly discovered loss remains visible on Brief and in the Retire review even when Resume is refused.
  - **Retire ship** calls `CampaignControl::revokeArm()`, which `campaign:arm-revoke` also uses. It closes any open episode and requests run stops, including any active `sell_aboard` recovery for a draining arm. A stop-requested run remains unsettled while it holds its active slot. If the runner blocks on a review, the UI shows the specific reason.
- The guard, card and endpoint use the same rule. An arm qualifies if it has `pause_reason = arm_loss_stop` or its recorded loss exceeds the approved limit (net < −`max_net_loss`), even when the pause reason is `consecutive_losing_loads`. Retire also covers an expired arm or one paused after five director failures (`director_error`). The sheet explains that the ship leaves campaign control and requires `campaign:arm` on the PC to return. With no open episode, look for unsettled actions by ship and run in the verified current reset. At the stored episode reset boundary, resume and retire wait for local confirmation of the current wipe. A later schedule-cache extension for the same reset cannot override that boundary. Agent-wide balance and uncertainty stops go to fleet review.
- New approvals (`campaign:approve`), new arms or new loss limits (`campaign:arm`) and renewals stay CLI-only. The phone says where to do them and never offers a button for them.
- **Automatic arm renewal does not exist in the code today.** `CampaignDirector::runDirectGrant()` pauses an expired grant with `grant_expired`, and `resumeArm()` refuses it. Bridge v1 shows the expired arm as a Stopped card. To renew, first revoke it with `campaign:arm-revoke` or use phone Retire if eligible. Then approve a new arm with `campaign:arm` on the PC. Automatic renewal is a separate engine task and is not part of Bridge.
- The safety contracts are in [plan §4](bridge-ui-plan.md#4-attention-catalogue-and-action-safety).

### 3. Plan pace line

> **Goal pace.** Do you want the "plan pace" line? It needs a target curve, e.g. the 8B model's planning scenario, stored with each playbook.

**Answer:** Skip the target curve for v1. Show only the actual credits curve. Playbook target-pace curves come later.

**Implications**

- The iPad Brief chart draws one line: credits over the wipe, from `campaign_samples` across all episodes for the selected symbol and reset. The earliest episode supplies the baseline and day count, including when a new primary takes over mid-wipe. The final design omits the dashed "Plan pace" line, its legend and the "Behind by ≈310k" text. Earlier concepts remain in the wireframes archive.
- The phone's Plan card shows the phase line, the link to Strategy, a pending replacement note when applicable, and a compact request-budget line. When the primary is paused or draining, the note says what activation is waiting on. It shows no pace text.
- No playbook schema change is needed. A later `pace` block in the playbook can add a second series without changing the chart component.

### 4. What counts as a route

> **What counts as a route.** A distinct good + source + buyer (or mining site) with at least one ship on it right now? Probes counted separately, as drawn? No campaign playbook mines today, so should the mining count include manual mining runs, or wait until a playbook adds mining?

**Answer:** A route is a distinct `good + source + buyer`. Probes are counted separately. Hide mining until a mining playbook exists.

**Implications**

- **Route key** = `good|source|buyer`. A route is **running** when at least one active campaign run with strategy `trade` matches it. A running or waiting run qualifies when `active_agent_id` is set, no stop was requested, and its episode is open in the selected wipe. Stopping or blocked runs that still hold an active slot show settlement or review status. The run fields are `plan.symbol`, `plan.site` and `plan.market`. The main campaign (C0) and every arm count together.
- **Stopped** routes take their key from the newest trade run in the current open episode of a paused or draining grant. A route stays Running if another ship is running it. A grant without an open episode supplies no stopped route. **Ended** routes had `load_result` events this wipe and have nothing running, stopped or settling on them now. They roll up into one "Ended routes" row. "All N ›" on a route opens the Log tab filtered to that route's loads.
- Per-route totals come from stored `load_result` events, whose `data` includes `good`, `source`, `buyer`, `net`, `units`, `ship`, `arm` and `finished_at`. They cover every episode of the agent in the current reset, revoked arms included. Older events without `source` take it from `automation_runs.plan.site` through `data.run_id`.
- `sell_aboard` recovery results (`recovery_result`) appear in the log and the ship's history. They are not routes.
- **Probes** are ships for which `CampaignFleet::isProbe()` is true. They are grouped by `ShipPurpose` label: watching markets, stationed (including gate watch from PR #133's `ships.stationed_waypoint`), scouting, recon, and Other for Standby, Shipyard Buyer or unknown purposes. They never count as routes.
- **Mining is hidden** unless the selected agent's approved primary revision contains an activity with `strategy: mining`. `CampaignPlaybook` already accepts it. Until then, a ship on a manual mining run appears in Fleet under "Other work" with its `ShipPurpose` label ("Miner (B7)"). It is not counted as a route, and the Brief shows no mining text.

### 5. Post-login home

> **Home.** Should the brief replace `/campaign` only, or also become the first page after you log in (today that's Agents)?

**Answer:** Make Bridge the post-login home page for the operator account. Keep the current home during the coexistence rollout, and switch the post-login landing to Bridge at cutover, once it has been proven on a live wipe.

**Implications**

- Today `AuthenticatedSessionController::store()` redirects to `redirect()->intended(RouteServiceProvider::HOME)`, and `HOME` is `/agents`. The `/` route in `routes/web.php` and the `RedirectIfAuthenticated` middleware both send signed-in users to Agents too.
- At cutover only, a small helper returns `route('campaign.show')` for the operator (`config('gameplay.operator_id')`) and `RouteServiceProvider::HOME` for everyone else. The login controller, `ConfirmablePasswordController`, `/` and `RedirectIfAuthenticated` all use it. Non-operators are unaffected.
- `redirect()->intended()` keeps working. A deep link the operator opened before signing in still wins over the default.
- No redirect changes ship during coexistence.

### 6. Safari or Home Screen

> **Safari or Home Screen.** Do you open it in Safari or as a Home Screen web app? A Home Screen app gets the full 874 pt and no browser bar, and would need a small web manifest.

**Answer:** Support both. Add a minimal web manifest and `env(safe-area-inset-*)` handling so Safari and the iOS Home Screen standalone app both work cleanly.

**Implications**

- `resources/views/app.blade.php` already has `viewport-fit=cover`, `color-scheme: dark` and `theme-color #070d17`. Bridge adds `<link rel="manifest">`, `apple-mobile-web-app-capable`, `mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style=black-translucent`, `apple-mobile-web-app-title=Bridge` and `apple-touch-icon`.
- New static files go in `public/`: `manifest.webmanifest` and a 180×180 `apple-touch-icon.png` plus 192 and 512 icons. The manifest uses `display: standalone` and `background_color`/`theme_color #070d17`. Its `start_url` is `/campaign/bridge` during coexistence and `/campaign` after cutover. `scope` is `/`, so the flight deck and the other app pages stay inside the standalone window.
- There is no service worker and no offline mode. Bridge is a live operations view, and a cached page would show stale fleet state as if it were current.
- Every Bridge edge uses `env(safe-area-inset-*)`. That covers the top bar, the bottom tab bar (`padding-bottom: max(8px, env(safe-area-inset-bottom))`), the sheets and the left and right gutters in landscape. Nothing is hard-coded to the 34 pt home indicator.

## Questions added after 2026-11-17

These five were asked in conversation, not in the wireframe doc. Each heading is the topic as asked. The quoted text restates that topic with the code context it referred to.

### 7. Multi-agent support (since PR #134)

> **Multi-agent support.** PR #134 lets one operator run several primary campaigns, one per agent symbol (`campaign_grants.agent_symbol`, unique `(user_id, agent_symbol, slot)`). How should Bridge show more than one agent?

**Answer:** A Brief per agent, with an agent switcher pill at the top. The pill shows an attention badge when another agent needs you.

**Implications**

- The agent list is `CampaignGrant::allPrimaries($userId)->orderBy('id')->pluck('agent_symbol')`, as `CampaignStatusController::selection()` builds it today. Paused primaries are included.
- The selected agent travels as `?agent=SYMBOL` on every Bridge URL, which `/campaign` and `/campaign/strategy` already use. An unknown symbol returns 404 while primaries exist. With zero primaries, Bridge returns HTTP 200 with engine health, "Admiral, no campaign is currently authorized" and `campaign:approve` guidance, including on saved URLs. Between archive and re-registration, an authorized symbol shows its waiting state without archived wipe data. With exactly one primary, Bridge still resolves that symbol explicitly, so links never depend on a missing parameter.
- The pill shows the selected symbol. Its badge counts the **other** agents that have at least one Stopped (Needs-you) item. Engine-wide items, such as a stale heartbeat, are not counted against other agents, because the Brief already shows them. The badge has a text label ("1 other agent needs you"), not only a number.
- Tapping the pill opens a sheet listing each agent with its credits, its state in words and its Needs-you count. Choosing one keeps the current tab and goes to that agent's list. Detail pages are agent-specific, so they fall back to their tab's list.
- Kill-switch drains (`drain_pause`) are by design aimed at the non-control agents, such as SOUPFLEET and SOUPBURN. While GIANTSOUP is selected, the badge uses the same drain checks as the affected agent’s Brief. An open episode marker or a budget flag can raise the badge on its own. The read-only `RequestBudget::drainingSymbols()` accessor reads the held symbols in one pass.

### 8. Where Campaign → Strategy lives (since PR #123)

> **Strategy page.** PR #123 added `/campaign/strategy` ("How C0 decides"). With four bottom tabs, where should it live?

**Answer:** Link to it from the **Plan** card on the Brief. That keeps four clean bottom tabs: `Brief · Routes · Fleet · Log`.

**Implications**

- The Plan card has a row "How C0 decides ›" that links to `route('campaign.strategy', { agent, from: 'bridge' })`.
- `Campaign/Strategy.vue` keeps its own page and `CampaignNavigation`. A `from=bridge` query adds a "‹ Bridge" back link, which returns to the Brief for the same agent. Without it, the page is unchanged.
- On iPad, Strategy opens full width, not in a split pane. Its walkthrough needs the width.

### 9. Request budget and kill switch (since PR #134)

> **Request budget and kill switch.** PR #134 added per-agent request buckets, the 5,760 req/hr Tier 2–4 envelope, the 1,080 req/hr Tier 1 settlement reserve and `CampaignHealth::evaluateKillSwitch()` (`drain_pause`). How much of it belongs on Bridge?

**Answer:** Show it only as exceptions on the Brief, plus a compact summary line under **Plan**. A kill-switch drain (`drain_pause`) is **Needs you**. High deferrals or 429s go under **Also watching**.

**Implications**

- **Needs you:** one card for a drained agent. It lists the grants that are settling or paused and names the reasons in plain words. Bridge preserves drain markers through pauses and uses an explicit fleet review for drain resume. The pressure gate rechecks all five applicable kill-switch causes. A drain must settle cargo, runs, commands and retained previews, including when a pause interrupts settlement. A standalone budget flag still requires settlement checks. At reset, archival releases budget holds, including standalone flag and episode-only drains, and preserves grant markers. See [plan §4.2](bridge-ui-plan.md#42-resume-prerequisites-server-enforced).
- **Also watching** uses `RequestBudget::usage(agentSymbol: …, requireTelemetry: true)`, which reads the local `budget.json` and makes no API calls. Any host 429 in the last 15 minutes, or agent deferrals above 5% of requests in the last hour, raise it. The 5% matches the kill switch's `control_degradation` share. So does Tier 2–4 use at or above 90% of a positive agent cap, or host Tier 2–4 at or above 86.4 per minute (90% of 96).
- **Plan line:** "Requests 2,410 / 2,880 an hour · host 61 / 96 a minute · 0 rate limits (15 min)". The numerator is Tier 2–4 alone. A null cap reads "no cap". A zero cap reads "No Tier 2–4 allowance". Missing or unreadable telemetry reads "Request budget unavailable". Disabled budgeting reads "Request budget off" and needs no telemetry for resume. Bridge suppresses the shell's five-second rate widget.
- Bridge never calls `FleetMetrics::scorecard()` while rendering a page. That method calls `CampaignTrade::harvestArms()`, which writes `load_result` events under a lock.

### 10. Ship display names and graphics (since PRs #131–#132)

> **Ship names and pictures.** PRs #131–#132 added semantic display names (`Ship::semanticName()`, for example "Light Hauler #9"), live purpose (`ShipPurpose`) and nicknames (`ships.nickname`). How should Bridge name and draw ships?

**Answer:** Use the semantic display name, with the callsign secondary. List rows stay compact text. The `ShipScene` illustration appears on the ship detail page (H5).

**Implications**

- The primary name is `Ship::displayName()` with **no** purpose argument. It returns the nickname when one is set, otherwise the semantic name. The purpose from `ShipPurpose::forAgent()` goes on the status line, so it is never shown twice. (`CampaignStatus::read()` today sends `displayName($purpose)`, which joins both with " · ".)
- The callsign (`ships.symbol`, e.g. `GIANTSOUP-9`) is always visible in smaller caption text next to the name. Card buttons and sheets use the display name ("Review Light Hauler #9"). The facts line and the sheet header show the callsign, so the two can be matched to the CLI and the flight deck.
- H5 reuses `resources/js/Components/ShipScene.vue`, with the same `shipPresentation()` and `flightDeckLocation()` inputs that `Campaign/Status.vue` uses, including the detail prop `locations` and explicit scene bindings for `pose`, `transit` and `progress`. It is set to `compact` and `:interactive="false"`, has a text alternative, and never decides the layout of the facts below it.

### 11. Rollout strategy

> **Rollout.** Replace `/campaign` in one step, or ship Bridge alongside it first?

**Answer:** Ship Bridge alongside the current `/campaign` first (e.g. `/campaign/bridge`, plus a toggle on `/campaign`) and use it through a live wipe. Then cut over: Bridge replaces `/campaign` and becomes the operator's post-login home once it has proven itself.

**Implications**

- **Coexistence:** new routes under `/campaign/bridge…` (named `campaign.bridge.*`). A "Try Bridge" link on `/campaign` and a "Classic status" link inside Bridge's Menu let you switch either way. `/campaign`, `/campaign/strategy`, `/campaign/pause` and `/campaign/resume` stay unchanged.
- **Cutover** is a separate PR. It runs only after the checklist in [plan §7](bridge-ui-plan.md#7-phased-implementation-and-cutover) passes on a live wipe. `campaign.show` (`/campaign`) then renders Bridge, Classic stays at `/campaign/classic` for one more wipe, the menu link becomes "Bridge", the manifest `start_url` becomes `/campaign` and the operator's post-login redirect switches (decision 5).
- The named Brief URL and nested Bridge URLs use 302 redirects during the first wipe after cutover. They preserve the query string. Switch to 301 once cutover is permanent. Classic pause and resume return to Classic with the same agent.
- Every Bridge PR ships through `./scripts/mac/deploy.sh`, which gracefully stops and restarts a running or starting engine. Deploy compatible slices together at a safe point and verify campaign and arm progress after each restart. The action slice reuses `CampaignControl`, updates drain bookkeeping and serializes director failure pauses with Bridge actions.

## Confirmed implementation decisions

1. Block resume of a drained agent while any applicable kill-switch condition remains active.
2. Allow phone Retire for a loss stop, `director_error` after five failures, and an expired approval.
3. Keep command-ship stalls at Watching in Bridge v1 and defer the 45-minute escalation to Stopped to a separate engine task.

See the [implementation plan](bridge-ui-plan.md#confirmed-implementation-decisions).
