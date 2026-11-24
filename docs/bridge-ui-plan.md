# Bridge UI plan

Status: approved by Taylor for the coexistence implementation, 2026-11-23. Grounded in `origin/main` at `13ab398` (PR #135).

Bridge is the redesigned campaign screen, sketched earlier as "Fleet brief". It turns `/campaign` from a full status readout into a short report you can read in ten seconds, in the voice of the Admiral's chief of staff. It covers the credits, whether the fleet is earning, anything that has stopped and needs you with the button that resolves it, and then routes, ships, the plan and the log.

- Decisions and their reasons: [`docs/bridge-ui-decisions.md`](bridge-ui-decisions.md) (11 decisions, 2026-11-23).
- Final wireframes: [`docs/fleet-brief-wireframes.html`](fleet-brief-wireframes.html) (H1–H6, iPad, attention catalogue, data sources).
- Design system: [`docs/app-styling.md`](app-styling.md) and `resources/css/command.css`.

Each section names the code it touches. Read models, endpoints and control changes described here are proposals. Existing code references are at the revision above.

---

## 1. Goals, scope and non-goals

### Goals

1. **Ten-second read on iPhone 17 Pro (402 × 874).** The first screen, above the tab bar and without scrolling, shows the credits box, the Admiral summary and the first Needs-you card with its button.
2. **Only failures ask for the Admiral.** A Needs-you card appears when normal work has stopped, including a drain still settling cargo. Warnings collapse into one Also-watching row. Recorded plan milestones and completed loads go to the log.
3. **Act safely from the phone** with reviewed actions for arm resume, arm retire, fleet pause and main-campaign or fleet resume. Each sits behind a review sheet that states its exact scope and never replays stale state.
4. **Multi-agent aware (Q7).** One Brief per agent, a switcher pill and a badge on the pill when another agent needs you.
5. **Zero upstream calls.** No Bridge page load, poll or sheet contacts SpaceTraders. Everything is read from SQLite and the local request-budget file.
6. **Coexist, then cut over (Q11).** Bridge runs beside `/campaign` through a live wipe. It replaces `/campaign` and becomes the operator's post-login home (Q5) only after the cutover checklist (§7.3) passes.

### In scope (v1)

- The Brief, Routes, Route, Ended routes, Fleet, Ship (H5 with `ShipScene`), Probes and Log pages, on iPhone, narrow phones (360 px), iPad Pro 13" portrait and landscape, and desktop.
- The agent switcher and the cross-agent attention badge.
- Four POST endpoints for arm resume, arm retire, fleet pause and scoped resume (main campaign or fleet).
- The attention catalogue (§4.1) covers main-campaign pauses, arm pauses, kill-switch drains, request-budget warnings, `CampaignHealth` problems and the stale engine heartbeat.
- The actual-credits wipe chart on iPad (Q3).
- A minimal web manifest and Apple web-app meta tags (Q6).
- One additive migration with two indexes (§5.8).
- A separate cutover PR after the live-wipe checklist: `/campaign` renders Bridge and the operator's post-login redirect changes.

### Non-goals (v1)

- **No playbook approval, arm creation, loss-limit change or renewal on the web.** `campaign:approve`, `campaign:arm` and the arm `--hours`/`--max-loss` flags stay CLI-only on the PC (Q2). Bridge only says where to do them.
- **No automatic arm renewal.** It does not exist in the engine. An expired arm is paused with `grant_expired` in `CampaignDirector::runDirectGrant()`, and `CampaignControl::resumeArm()` refuses it. Renewal within an approved playbook's limits is a separate engine task. Bridge v1 shows the expired arm as a Stopped card. Use phone Retire or the CLI to revoke an expired arm before approving a replacement.
- **No plan-pace or target curve** (Q3). The chart shows actual credits only.
- **No mining routes** until an approved primary revision contains a `mining` activity (Q4).
- **No single-ship pause, no engine start or stop, no balance review form and no action reconcile** on Bridge. Those stay on the flight deck (`/agents/{agent}`), and Bridge links to it.
- **No service worker or offline mode.** A cached fleet view would look current when it isn't.
- The action slice updates `CampaignControl` to preserve and clear drain state, extracts reusable read-only safety and pressure checks, and serializes director failure pauses with the actions. The engine keeps its existing drain thresholds, timers, targeting and settlement behavior.
- Classic and Strategy keep their existing URLs and endpoints during coexistence.

---

### Confirmed implementation decisions

1. Block resume of a drained agent while any applicable kill-switch condition is currently active (§4.2).
2. Allow phone Retire for the loss-stop rule, `director_error` after five failures and `grant_expired` (§4.3.2).
3. Keep command-ship stalls at Watching in Bridge v1. Defer the 45-minute escalation to Stopped to a separate engine task (§4.1 and §9).

## 2. Voice, naming and UX contract

### 2.1 Naming

| Thing | Name on screen |
|---|---|
| The product and page kicker | **Bridge** ("Bridge · Day 2 of 7 · Earn phase") |
| The home tab | **Brief** |
| Tabs | `Brief · Routes · Fleet · Log` |
| The command ship's approved weekly playbook | **main campaign** (C0 in Strategy, the CLI and the log) |
| One extra ship under its own approval | **ship** in the UI and **arm** in the developer docs. Its label (e.g. `R2`) appears on the ship page. |
| Everything for the selected agent: main campaign plus all live arms | **the whole fleet** ("all 12 ships of GIANTSOUP") |
| A ship | Display name first, callsign second: **Light Hauler #9** `GIANTSOUP-9` |

### 2.2 Voice rules

The chief of staff is respectful, brief and literal. Character lives in the summary sentence. Facts never take on character.

1. "Admiral," opens the summary sentence under the credits box, and only there. It appears at most once per screen and never in buttons, card titles, row text, `aria-label`s or log rows.
2. Buttons are verb plus object, plain: "Review Light Hauler #9", "Resume Light Hauler #9", "Retire Light Hauler #9", "Pause the whole fleet…", "Resume the fleet". No "Aye", no "Make it so".
3. Numbers are never rounded inside sentences that ask for a decision. Sheets show the exact figure (−18,240). Summaries may use the short form (290k).
4. Severity is shown three ways: by word (Stopped, Watching, Running), by position (stopped first) and by colour. Colour never carries it alone.
5. Errors say what happened and what to do next, in plain words. They never blame the Admiral.

### 2.3 Copy guide by state

| State | Summary line (under the credits box) | Status pill | Main button |
|---|---|---|---|
| All clear, earning | "Admiral, all 12 ships are working. We're earning 290k an hour." | `● All clear` | none |
| Needs you, one item | "Admiral, 1 item needs your attention. We're earning 290k an hour." | `⚠ Needs you` | the first card's Review button |
| Needs you, several | "Admiral, 3 items need your attention. We're earning 190k an hour." | `⚠ Needs you` | the first card's Review button |
| Whole fleet paused by you | "Admiral, the fleet is paused on your order since 3:10 PM PST." | `■ Paused` | "Review and resume" on the Fleet-paused card |
| Main campaign stopped by a safety pause | "Admiral, the main campaign paused after two losing loads." | `⛔ Stopped` | "Review main campaign" |
| Agent drained by the kill switch | "Admiral, SOUPFLEET is held back to protect the request budget." | `⛔ Stopped` | "Review SOUPFLEET" |
| Engine not responding | "Admiral, the engine isn't responding. Nothing is being directed." | `⛔ Stopped` | "Check engine" (opens the flight deck) |
| Stale data (polls failing) | Unchanged. The "Updated" line turns amber: "Not updated since 4:12 PM PST — reconnecting". | unchanged | unchanged |
| No primary campaign | "Admiral, no campaign is currently authorized." | `○ Idle` | none |
| Waiting for the reset | "Admiral, standing by for the new wipe at Sun 5 AM PST." | `○ Waiting` | none |
| Reset boundary passed, awaiting confirmation | "Admiral, we're waiting to confirm the current wipe." | `○ Waiting` | none |

**Empty states.** These are plain, with no character:

- Needs you: "Nothing needs you."
- Routes: "No routes running yet. The plan is choosing its first trade." While the fleet is held, use "No routes are running while the fleet is held."
- Fleet group: no group heading at all.
- Log filter: "No problems logged this wipe."

**Review sheets.** The heading is plain. Use "Resume Light Hauler #9?" and name the scope next to it. Show the consequence and the evidence before the footer. On open, `FocusSheet` focuses the heading. In the footer, place the full-width safe action ("Keep paused" or "Cancel") first and the full-width committing action last.

**Flash messages after an action** are plain: "Light Hauler #9 resumed. Its next decision uses fresh state." Partial results list each target (§4.3.3).

### 2.4 Accessibility and layout contract

- Touch targets are at least 44 × 44 CSS px, including tab-bar items, pill, chevron rows and sheet buttons.
- Every number uses `font-variant-numeric: tabular-nums`. Short money (`4.81M`) carries the exact value in `aria-label` and in a tap-to-reveal `title`.
- Times are local, with the zone (`4:14 PM PST`), and use `formatLocalTime()` from `resources/js/dateTime.js`. Relative times ("22 min ago") always sit next to an absolute time in the sheet.
- Every edge uses `env(safe-area-inset-*)`. Nothing assumes the 34 px home indicator or the Dynamic Island height.
- Text wraps at large text sizes, with no truncation of names or amounts. Long callsigns may wrap onto their own line.
- Landmarks: one `<main id="main-content">`, the tab bar is `<nav aria-label="Bridge sections">`, the current tab has `aria-current="page"` and the badge text is part of the link's accessible name ("Brief, 1 item needs you").
- Sheets use the existing `resources/js/Components/FocusSheet.vue`. It is a native `<dialog>` with `showModal()`, initial focus on the heading, a focus trap, Escape to close and focus returned to the opener.
- The page respects `prefers-reduced-motion`. Sheets and tab changes do not animate when it is set.

### 2.5 Safety rules in the UI

1. **Scope is always named.** Every action sheet heading names its target and scope: "Resume **Light Hauler #9** (arm R2) only", "Pause **the whole fleet** of GIANTSOUP: main campaign and 4 arm ships". Main campaign, one ship and the whole fleet never share a button label.
2. **No stale replay.** A sheet posts its intent, the agent and reset IDs, each target grant's expected state, and the identity of any reviewed budget hold. The server checks those under the locks in §4.3. Fleet pause can cover a changed target set and reports the difference.
3. **Pause is never the main button.** "Pause the whole fleet…" sits at the bottom of the Brief and in Menu, styled `danger-quiet`.
4. **Resume is gated by prerequisites** that the server checks too. A button disabled in the UI is never the only guard.

---

## 3. Information architecture, URLs and navigation

### 3.1 Routes during coexistence (new)

All routes sit in the existing `Route::middleware('auth')` group in `routes/web.php`. They are served by a **new** `App\Http\Controllers\CampaignBridgeController` and guarded by the same operator check as `CampaignStatusController::authorizeOperator()`: 404 unless `user.id === config('gameplay.operator_id')`.

| Method | URI | Name | Inertia page / effect |
|---|---|---|---|
| GET | `/campaign/bridge` | `campaign.bridge.brief` | `Campaign/Bridge/Brief` |
| GET | `/campaign/bridge/routes` | `campaign.bridge.routes` | `Campaign/Bridge/Routes` (list) |
| GET | `/campaign/bridge/routes/ended` | `campaign.bridge.routes.ended` | `Campaign/Bridge/EndedRoutes` |
| GET | `/campaign/bridge/routes/{good}/{source}/{buyer}` | `campaign.bridge.route` | `Campaign/Bridge/Routes` (list + selected) |
| GET | `/campaign/bridge/fleet` | `campaign.bridge.fleet` | `Campaign/Bridge/Fleet` (list) |
| GET | `/campaign/bridge/fleet/probes` | `campaign.bridge.probes` | `Campaign/Bridge/Probes` |
| GET | `/campaign/bridge/fleet/{ship}` | `campaign.bridge.ship` | `Campaign/Bridge/Fleet` (list + selected) |
| GET | `/campaign/bridge/log` | `campaign.bridge.log` | `Campaign/Bridge/Log` (`?filter=all\|problems\|routes\|plan&before={eventId}&route={routeKey}`) |
| POST | `/campaign/bridge/arms/{grant}/resume` | `campaign.bridge.arm.resume` | §4.3.1 |
| POST | `/campaign/bridge/arms/{grant}/retire` | `campaign.bridge.arm.retire` | §4.3.2 |
| POST | `/campaign/bridge/fleet/pause` | `campaign.bridge.fleet.pause` | §4.3.3 |
| POST | `/campaign/bridge/fleet/resume` | `campaign.bridge.fleet.resume` | §4.3.4 |

- With a selected primary, Bridge URLs carry `?agent=SYMBOL`. Parameter rules:
  - `{good}`: `[A-Z0-9_]+`
  - `{source}` and `{buyer}`: `[A-Z0-9-]+`
  - `{ship}`: `[A-Z0-9_]+(?:-[A-Z0-9_]+)*-[A-Z0-9]+`, so the literal `probes` route never matches a ship
  - `{grant}`: a numeric id
- The POST routes use `throttle:20,1`. The read routes are unthrottled, like `/campaign`.
- Extract the selection logic into `App\Http\Controllers\Concerns\SelectsCampaignAgent` (**new**). Bridge resolves a concrete symbol when primaries exist. An unknown symbol then returns 404. Classic retains its current selection behavior.
- If `CampaignGrant::allPrimaries($userId)` is empty, authenticated operator GETs render the Bridge empty state with HTTP 200, including saved URLs carrying `?agent=`. Show engine health, "Admiral, no campaign is currently authorized" and "Approve a playbook on the PC with `campaign:approve`." Omit the agent switcher, per-agent data and campaign actions. POSTs still require an authorized primary.
- The coexistence toggle:
  - `Campaign/Status.vue` gets one link, "Try Bridge ›", next to the `CampaignNavigation` links. It carries the selected agent.
  - Bridge's Menu sheet gets "Classic status", linking to `route('campaign.show', { agent })`.
  - Nothing else on `/campaign` changes.

### 3.2 Routes after cutover

Ship cutover in a separate PR after §7.3 passes.

- `GET /campaign` (`campaign.show`) renders `Campaign/Bridge/Brief`. The other Bridge routes move to `/campaign/routes…`, `/campaign/fleet…` and `/campaign/log`, and the POSTs to `/campaign/arms/{grant}/…` and `/campaign/fleet/…`. Their names keep the `campaign.bridge.*` prefix, so the front end changes nothing.
- Register an explicit `GET /campaign/bridge` redirect named `campaign.bridge.brief` so existing named links resolve. The home helper and new Brief links use `campaign.show` at cutover. Register `GET /campaign/bridge/{rest}` with `->where('rest', '.*')` for nested URLs. Both redirects drop the Bridge prefix, preserve the query string and build the destination under the local `/campaign` path. The catch-all comes after the exact route. Use HTTP 302 for both during the first wipe after cutover so browsers can follow a rollback. Switch to 301 once cutover is permanent.
- Classic moves to `GET /campaign/classic` (`campaign.classic`) for one more wipe, linked from Menu. A following PR then removes it and `Campaign/Status.vue`, unless Taylor asks to keep it.
- The existing `POST /campaign/pause` and `/campaign/resume` stay, because the Classic page and `tests/Feature/CampaignStatusTest.php` use them. Update both `CampaignStatusController` handlers to redirect to `campaign.classic` with the selected `agent` on success. Remove the POSTs with Classic.
- `/campaign/strategy` stays as it is.
- The menu link in `AppShell.vue` reads "Bridge" and goes to `campaign.show`.
- **Post-login home (Q5).** A **new** `App\Support\HomeRoute::for(?User $user): string` returns `route('campaign.show')` for the operator and `RouteServiceProvider::HOME` (`/agents`) for everyone else. Use it in the four existing default-landing paths.
  - `AuthenticatedSessionController::store()`: `redirect()->intended(HomeRoute::for($request->user()))`
  - `ConfirmablePasswordController::store()`: `redirect()->intended(HomeRoute::for($request->user()))`
  - the `/` closure in `routes/web.php`
  - `RedirectIfAuthenticated::handle()`

  `intended()` still wins for a deep link opened before signing in.
- `public/manifest.webmanifest` `start_url` changes from `/campaign/bridge` to `/campaign`.

### 3.3 Agent switcher (Q7)

- **Data.** `campaignAgents` is the list of primaries from `CampaignGrant::allPrimaries($userId)->orderBy('id')`. Each entry has:
  - `symbol`
  - `needs_you`: the count of Stopped items for that agent (§4.1), excluding engine-wide items
  - `state`: `running`, `paused`, `draining`, `waiting` or `idle`
  - `credits` from that agent's `agents.credits`
- **The pill** sits centred in the top bar on every Bridge screen. It shows the selected symbol and a chevron. When `sum(needs_you of other agents) > 0` it shows a fault-coloured badge with that **agent** count. The accessible name reads "Agent GIANTSOUP. 1 other agent needs you. Switch agent". With only one primary, the pill shows the symbol with no chevron and is not interactive.
- **The sheet** (FocusSheet, title "Agents") lists each agent as a 44 px+ row: symbol, state word, credits and "1 needs you". Stopped agents come first, then the order of `allPrimaries`. Choosing an agent visits the same tab's list route with `agent=` replaced. Detail pages (route, ship) fall back to their tab's list, because routes and ships are agent-specific.
- **Persistence.** The selection lives only in the URL (`?agent=`). Every Bridge link, the Strategy link (`route('campaign.strategy', { agent, from: 'bridge' })`), the flight-deck links and the POST redirects carry it. There is no session or local storage, so two tabs can watch two agents.
- **Badge cost.** Use three SQL queries for classification across all symbols: the already loaded primaries, one grouped read of the operator’s live arms, and one grouped read of their open episodes joined to the current unarchived agent and reset. Read all symbols’ hold flags from one local budget snapshot using the same key as `RequestBudget::isDraining()`. If the file is unreadable, keep classifying from grants and episodes and report unavailable telemetry (§5.6). The switcher’s credits come from one bulk current-agent query if those rows are needed separately. Use the same drain classifier as the selected Brief (§4.2), so an episode marker or budget flag by itself produces one Stopped card for the agent. The query counts stay fixed as agents are added.

### 3.4 Navigation and layout by device

| Viewport | Navigation | Brief | Routes / Fleet |
|---|---|---|---|
| Phone (< 768 px; iPhone 17 Pro 402 × 874, narrow 360) | Fixed bottom tab bar: 4 items, labels always shown, `padding-bottom: max(8px, env(safe-area-inset-bottom))`. The top bar holds the short brand "ST //" (`AppShell` `brand` prop; hidden below 380 px), the agent pill and Menu. Detail pages replace the brand with "‹ Back". | One column | A list, or the selected item's page with a Back link |
| Tablet portrait (768–1199 px; iPad Pro 13" portrait 1032 × 1376) | Centred top tabs in the top bar (segmented, 44 px tall), plus the pill and Menu | Two columns: credits, summary and Needs you on the left; chart, Plan, Operations, Fleet and Log on the right | Stacked, as on the phone, at a readable max width of 720 px |
| Tablet landscape and desktop (≥ 1200 px; iPad Pro 13" landscape 1376 × 1032) | Same as tablet portrait | Two columns (400 px left, fluid right) | Split view: the list on the left (400 px), the selected route or ship on the right. The selected row is highlighted with `aria-current="true"` instead of a chevron. |

- Tapping a row opens its page inside the same tab. Back returns to where you came from (a route opened from a ship's page shows "‹ Light Hauler #8"). The return target is passed as a `from=` query: `ship:GIANTSOUP-8`, `brief` or `log`. It is validated against an allow-list, so the Back label never comes from free text.
- Tapping the active tab returns to its list. The Brief's "Routes ›", "Fleet ›" and "Log ›" switch tabs. On a route page, "All N ›" opens the Log tab with `filter=routes` and the encoded `route=good|source|buyer` (§5.7).
- **Plan card → Strategy (Q8).** A row "How C0 decides ›" opens `/campaign/strategy?agent=…&from=bridge`. **Changed:** with `from=bridge`, `Campaign/Strategy.vue` adds a "‹ Bridge" link above its heading. Otherwise the page is unchanged. On iPad it opens full width.
- **Ship page (H5)** (`/campaign/bridge/fleet/{ship}`):
  - Title: `display_name` with no purpose. Under it, the callsign and the purpose pill.
  - `ShipScene` (compact, `:interactive="false"`, `role="img"` with an `aria-label` such as "Light Hauler #8 in transit to D46") at most 160 px tall.
  - Facts: now, arrival, route (links to the route page), cargo and fuel.
  - This wipe: loads, net and last load.
  - Recent: the last two load or recovery results.
  - "Full ship report on the flight deck ›" goes to `route('agents.show', { agent: agentId, ship })` + `#report`, the target the dashboard already opens.
  - A stopped ship's page starts with the same attention card as the Brief.

### 3.5 Web app manifest and Apple meta tags (Q6)

**Changed** in `resources/views/app.blade.php`. These are additive and affect every page. They are harmless for non-operators, because the manifest is only a hint.

```html
<link rel="manifest" href="/manifest.webmanifest">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Bridge">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
```

The `viewport-fit=cover`, `color-scheme` and `theme-color #070d17` tags are already present.

**New** files in `public/`:

- `manifest.webmanifest`:

  ```json
  {"name":"Bridge","short_name":"Bridge","start_url":"/campaign/bridge","scope":"/","display":"standalone","background_color":"#070d17","theme_color":"#070d17","icons":[{"src":"/icons/bridge-192.png","sizes":"192x192","type":"image/png"},{"src":"/icons/bridge-512.png","sizes":"512x512","type":"image/png","purpose":"any maskable"}]}
  ```

- `apple-touch-icon.png` (180 × 180)
- `icons/bridge-192.png` and `icons/bridge-512.png`: the accent `#a6f3d0` mark on `#070d17`, with no text

Notes:

- `black-translucent` puts content under the status bar. Every Bridge top bar already pads with `env(safe-area-inset-top)`, as `.compact-shell .app-topbar` does in `AppShell.vue`.
- A standalone iOS web app keeps its own cookie jar. The operator signs in once inside it and ticks "Remember me" on the existing login form.
- In standalone mode, links to other origins open in Safari. All Bridge links are same-origin.

---

## 4. Attention catalogue and action safety

### 4.1 Catalogue

**Severity.**

- **Stopped**: counts toward Needs you and the badges. It has a card, and the first card supplies the screen's main button.
- **Watching**: collapses into one Also-watching row.
- **Log**: appears in the log only.

**Scope.** A card names one of four scopes:

- **engine**: affects every agent
- **agent**: the main campaign and every arm of one agent
- **main campaign**: the primary grant only
- **one ship**: one arm

**Status tags.**

- Exists: the data or action is on `main`.
- **New**: Bridge adds it.
- CLI: it stays CLI-only by decision.

| Trigger (code) | Source on `main` | Severity · scope | Card title (example) | Button(s) | Status |
|---|---|---|---|---|---|
| `engine_heartbeat_stale` | `CampaignHealth::problems()`, from `local_engines.heartbeat_at` older than 5 min | Stopped · engine | Engine isn't responding (last heartbeat 7 min ago) | Check engine → flight deck | Exists (data); card **New** |
| Primary `pause_reason = unexplained_change` | `CampaignSafety::reason()` → `CampaignControl::pause()` | Stopped · agent¹ | Credits changed without explanation | Review balance → flight deck; Resume (gated, §4.2) | Gate **New** |
| Primary `pause_reason = uncertain_action` | same | Stopped · agent¹ | Not sure whether a command went through | Reconcile on flight deck; Resume (gated) | Gate **New** |
| Primary `pause_reason = consecutive_losing_loads` | `CampaignSafety::reason()` (via `pending_pause` at a safe point) | Stopped · main campaign | Main campaign paused after 2 losing loads | Review → Resume main campaign | Sheet **New** |
| Primary `grant_expired` | `CampaignDirector::runDirectGrant()` | Stopped · main campaign | This week's playbook approval expired | none on the phone: "Approve the week's playbook on the PC" | CLI `campaign:approve` |
| Primary `registration_unknown`, `registration_requires_review`, `account_token_missing` | director registration path | Stopped · main campaign | Couldn't confirm the new agent after the reset | Open Agents (import / token) | Exists (flows) |
| Primary `no_cargo_buyer`, `recovery_stalled` | director recovery | Stopped · main campaign | Cargo has no buyer / recovery sale stuck | See cargo (ship page) → Resume main campaign | Sheet **New** |
| Primary `director_error` (5 consecutive), `state_requires_review`, `episode_already_closed` | `DispatchDueSpaceTraders`, director | Stopped · main campaign | Autopilot hit an error it couldn't handle | Resume main campaign (sheet shows the last `director_error` event) | Sheet **New** |
| Primary `operator_pause` | `/campaign/pause`, `campaign:pause` or Bridge fleet pause | Stopped · agent (when arms are paused too) or main campaign | You paused the fleet at 3:10 PM | Review and resume → main-campaign sheet for a primary-only pause, fleet sheet when the operator chose to resume the fleet | **New** action |
| Kill-switch drain in progress or paused (§4.2) | `CampaignHealth::evaluateKillSwitch()` → `CampaignControl::drainPause()` (primary and its live arms; never the control agent GIANTSOUP) | Stopped · agent | SOUPFLEET held back: host rate limits | Review → Resume the fleet (pressure gate, §4.2) | Card and gate **New** |
| Arm `pause_reason = consecutive_losing_loads` without a loss-cap breach | `CampaignSafety::reason()` for the arm episode | Stopped · one ship | Light Hauler #9 paused after two losing loads | Review → Resume Light Hauler #9 | **New** (was CLI `campaign:arm-resume`) |
| Arm `pause_reason = arm_loss_stop` or recorded episode net below −`max_net_loss` | Shared loss-stop check (§4.2) | Stopped · one ship | Light Hauler #9 hit its loss limit | Review → Retire Light Hauler #9. After retirement, approve a new arm on the PC. | Retire **New** (was CLI `campaign:arm-revoke`) |
| Arm `pause_reason = director_error` (5 consecutive, key `arm_director_failures:{id}`) | `DispatchDueSpaceTraders::tickArms()` | Stopped · one ship | Light Hauler #9 stopped after 5 failed decisions | Review → Resume. Retire through its own review | **New** |
| Arm expired: `expires_at` past (paused `grant_expired` at its next pass, or still unpaused until then) | `CampaignDirector::runDirectGrant()`; `CampaignControl::resumeArm()` refuses | Stopped · one ship | Light Hauler #9's approval expired | Revoke with `campaign:arm-revoke`, then approve with `campaign:arm` on the PC. Phone Retire can replace CLI revocation. | Renewal CLI |
| Arm `unexplained_change` / `uncertain_action` | agent-wide: each arm pauses itself at its next decision; `CampaignHealth::hardPauseAffectedAgents()` pauses all of them at once when the operator has several primaries | folded into the agent card above | (one agent card, not one per ship) | Resume the fleet (gated) | **New** |
| `campaign_decisions_stalled:{grant}` | `CampaignHealth` (15 min, engine alive) | Watching · main campaign | Autopilot hasn't decided in 15 min | Open log | Exists (data) |
| `campaign_progress_stalled:{grant}` | `CampaignHealth` (60 min) | Watching · main campaign | No run started or load sold in 60 min | Open log | Exists (data) |
| `command_ship_stalled:{grant}` | `CampaignHealth::COMMAND_STALL_MINUTES` (25) | Watching · main campaign | Command Frigate #1 idle 27 min | Open ship | Exists (data). The 45-minute escalation is a separate engine task. |
| Wait reasons `awaiting_engine`, `awaiting_automation`, `awaiting_registration`, `awaiting_reset_schedule`, `awaiting_agent`, `awaiting_fresh_state` | `campaign_grants.wait_reason` | Watching · main campaign | Autopilot waiting: engine is off | Fix the named prerequisite (link) | Exists (data) |
| Host 429s | `RequestBudget::usage()['host']['rate_limits_last_15_minutes'] ≥ 1` | Watching · engine | SpaceTraders rate-limited us 2 times in 15 min | none | **New** (read) |
| High deferrals | agent `deferrals_last_hour / (tier2_4_last_hour + tier1_last_hour + deferrals_last_hour) > 0.05` | Watching · agent | 7% of requests waited for budget this hour | none | **New** (read) |
| Near agent cap | agent `tier2_4_last_hour ≥ 0.9 × cap_per_hour` (when `cap_per_hour > 0`) | Watching · agent | Using 94% of GIANTSOUP's request allowance | none | **New** (read) |
| Host operating pressure | host `tier2_4_last_60_seconds ≥ 86.4` (the kill switch's `host_operating_pressure` line) | Watching · engine | Request rate near the host limit | none | **New** (read) |
| Pending replacement grant (`slot = 2`) | `CampaignGrant::pendingFor()` | Log + Plan note | New playbook queued (§5.6) | none | Exists |
| `probe_purchased`, `approval`, `activated`, `superseded`, `phase`, `registration`, `resume`, `revoked` | `campaign_events.kind` | Log | e.g. "Bought Probe #13 at A2 · 24,600" | none | Exists (data); log view **New** |

¹ `unexplained_change` and `uncertain_action` concern the agent's whole balance. The card is agent-scoped, and its Resume is the whole-fleet resume.

**Rules.**

- One card per (scope, reason). Combine the live drain markers into one agent card listing the primary and arms, their reasons and whether they are settling or paused. Arms that share another agent-wide reason fold into that agent card. `CampaignHealth` emits stall warnings only while the engine is alive and the primary is unpaused.
- Ordering: engine, then agent, then main campaign, then one ship. Within a group, newest first.
- The Brief's tab badge and the "Needs you (n)" heading count cards, not ships.
- Arm alerts are **new on Bridge only**. `CampaignHealth::problems()` and `CampaignWatchdog` still read primaries only, so no notification is sent for an arm pause. Watchdog coverage of arms is out of scope and listed in §9.

### 4.2 Resume prerequisites (server-enforced)

**New** `App\Gameplay\Bridge\BridgeGuards::resumeBlockers()` supplies the sheet and the POST with the same blocker codes, messages and links. Build the POST version under both action locks. Evaluate each target separately.

| Blocker | Condition | Where to resolve |
|---|---|---|
| `uncertain_action` | Use the read-only safety checks extracted from `CampaignSafety::reason()`. An uncertain action blocks the agent for this reset. A price review blocks the episode it concerns: an episode fleet ship, an action with an empty ship symbol, or a run belonging to that episode. | Flight deck (`agents.show`), where you reconcile actions |
| `unexplained_change` | Use the same check as `CampaignSafety::reason()`. No `pending` or `sending` action remains for the agent and reset, and `CreditAccounting::summary()` reports a nonzero unexplained change. | Flight deck (`agents.show`), where you review the balance |
| `grant_expired` | `expires_at` has passed. | Approve a playbook on the PC (primary) or revoke the expired arm with `campaign:arm-revoke` before running `campaign:arm` on the PC. Use phone Retire in place of revocation if eligible. |
| `arm_loss_stop` | The arm has `pause_reason = arm_loss_stop` or its current open episode net from `load_result` is below `-max_net_loss`. Share the recorded-net predicate with `CampaignSafety::reason()` and `resumeArm()`. The card, resume guard and Retire endpoint use the same eligibility rule. `resumeArm()` records outstanding results and rechecks the net before changing authority. | Review and retire the arm. |
| `settling` | A target’s stop-requested run still holds `active_agent_id`, including after a transition to `blocked`. A drained target also waits on any active slot or unsettled command. Before drain conversion it must pass the cargo and preview checks below. | Name the unsettled run, command, cargo or preview. For a blocked run, use current action evidence to distinguish a price review from an uncertain outcome. Show any other recorded blocker and link to the flight deck. |
| `reset_unconfirmed` | The selected agent and wipe fail the local reset check below. | Show "Waiting to confirm the current wipe. Reopen this review once it is confirmed." |
| `drain_pressure` | Any applicable condition below is currently breached for a drained agent, or budget telemetry needed for the check is unavailable. | Show the active causes or "Request budget unavailable". |

Share the safety checks with `CampaignSafety::reason()`, preserving its current evaluation order for the director. For resume, use the same read-only checks with resume-cleared state omitted. Skip `pending_pause` and the consecutive-loss streak. The persistent arm loss check must still run when a pending pause would otherwise mask it. If the grant has no open episode but has a confirmed agent and reset, apply the agent-wide uncertainty and balance checks and use the arm's ship and current-reset run associations for scoped reviews. With no confirmed agent and reset, defer phone resume until the scope can be verified. If a pause is blocking registration, show the registration or token fix and point to the existing `campaign:resume --agent=SYMBOL` flow on the PC. The flight deck opens through its GET. Its balance form submits to `game.accounting.review`.

**Local reset evidence.** Under the ordered locks, check the selected agent’s unarchived identity and `reset_id` against the known scheduled boundary for that reset (the matching episode’s `next_reset_at`, or the locally persisted matching schedule when there is no episode). The matching open episode’s stored boundary wins, including when a later cache observation extends the same reset. Only when there is no matching open episode, read the persisted schedule’s `reset_id`, `next_reset` and `observed_at` locally. Validate the values and use the observation time to check freshness at the boundary. Bridge never calls the API to refresh them. At or after the known boundary, a matching old agent row and sheet `reset_id` are insufficient. Require a confirmed current unarchived identity after the stored episode boundary. Without a matching episode, fresh local schedule evidence may confirm the reset and its future boundary. Refuse resume and retire if current reset metadata disagrees with the target agent or if post-boundary evidence is missing. Show the §2.3 Admiral summary while confirmation is pending. When confirmation changes the reset, require a new sheet as in §4.3.

**Drain pressure.** Extract the read-only measurement and predicates from `CampaignHealth::pressureChecks()` for both the evaluator and Bridge. For the operator, read the latest open `GIANTSOUP:C0` episode, the same three-hour control window and `kill_switch:{userId}:{controlResetId}` state (use `unconfirmed` when there is no control episode). Handle the selected primary as the candidate even while paused. Evaluate all applicable raw breaches. The engine keeps its continuous-breach timers, baseline writes and target selection.

| Cause | Raw breach |
|---|---|
| `host_operating_pressure` | Host Tier 2–4 at least 86.4 per minute. |
| `host_total_pressure` | Host Tier 1–4 at least 102 per minute. |
| `host_rate_limits` | At least three 429s. When `last_drain_at` exists, use `RequestBudget::countRateLimitsSince(last_drain_at)`, which also bounds the count to 15 minutes. Otherwise use the host's 15-minute count. |
| `control_degradation` | Control loads/hour or credits/hour below 85% of its positive baseline, with deferrals over 5% of control requests plus deferrals. |
| `shared_system_cannibalization` | Control margin per unit below 80% of its positive baseline, `noMarketDepletion()` passes, and the selected agent matches the existing `tradesInControlSystem()` ship and recent-load predicate for `X1-SK70` in the control reset. |

Missing control comparison data follows the evaluator’s existing predicates. With budgeting enabled, missing request-budget telemetry blocks a drain resume. With `request_budget_enabled = false`, show "Request budget off" and skip the telemetry prerequisite. Settlement and other safety checks still apply. The gate applies whenever the selected agent has live drain markers or the budget flag, regardless of the resume intent. An unreadable hold state, including missing telemetry while budgeting is enabled, blocks every Resume intent even if there is no readable drain marker. The sheet names each current breach, even if a different cause started the drain.

**Drain lifecycle.**

- `drainWithArms()` requests a drain for the primary and every unpaused live arm. While settling, `paused_at` is null and `CampaignGrant::isDraining()` reads the `drain_pause:` wait reason. The open episode also has `state.draining`. One classifier serves the Brief, agent switcher and action checks. It treats a live grant’s drain wait or pause marker, a matching current open episode’s `state.draining` marker, or the symbol’s local budget flag as sufficient evidence. The read-only `RequestBudget::drainingSymbols()` reads held symbols in one pass for the switcher. A flag alone gives one agent card for the budget hold with zero grant targets.
- At the safe point, the director requires usable cargo readings for the command ship and recovery scope, then clears the episode marker and converts the wait reason into `pause_reason = drain_pause:…`. A Bridge fleet pause leaves settling targets to the drain. For other callers, update `CampaignControl::pause()` to retain a `drain_pause:` wait marker when another pause reason interrupts a drain. The new pause reason and its event retain the safety or operator reason. A converted drain has a `drain_pause:` pause reason and no active episode drain marker. A separate pause can interrupt an incomplete drain. A matching open episode marker also counts when a legacy row lost its grant marker. Retaining the wait marker makes `CampaignControl::approve()` treat the primary as busy. `activatePending()` holds the slot-2 replacement while the primary is paused or draining. Resume or expiry lets it activate once any remaining work settles (§5.6).
- A drain is agent-wide. While any live grant is drain-marked or the budget flag is set, Bridge directs primary-only and single-arm resume to a fleet review. That review includes every live drain-marked grant, even when `paused_at` is null. A blocked drained target prevents batch resume. Resolve the blocker or retire that arm via an eligible Retire action or the CLI, then reopen the sheet.
- **Before drain completion.** Determine completion from the converted drain pause and episode state. An interrupted drain may have `paused_at` set without having settled. An incomplete drain may resume only when every ship in its drain recovery scope has a local snapshot showing zero cargo, every run has released its active slot, pending and sending commands have settled, and no current open-episode preview remains. Missing cargo evidence blocks early resume. Empty probe holds cannot substitute for a missing command-ship snapshot, including before a command ship is pinned. Share the recovery ship selection with the director. For an arm it is the arm ship. For the primary it includes its command ship, eligible probes and same-episode started-run ships that remain eligible for drain recovery. A pinned legacy probe with no snapshot remains in scope until cargo or ineligibility is confirmed. The director and Bridge use the same recovery ship scope when checking pending and sending commands before drain completion. Read cargo from local snapshots without pinning roles or writing state. `drainPause()` retains pending `sell_aboard` previews, and the director tries them before recovery and drain completion. A retained preview blocks early resume even with an empty hold. Leave settlement and preview disposal to the director, then reopen the review. A standalone budget flag has zero grant targets and still requires current recovery-scope settlement checks before release.
- Update `CampaignControl::resumeArm()` to clear `state.draining` as primary resume already does. Clear wait and pause markers and open-episode drain state in the same database transaction as the resume event. After resume or retirement commits, reconcile the budget flag under the same locks. `releaseDrainIfUnused()` checks the symbol’s live primary and arms for grant markers and current open-episode markers, including legacy state. Revoked and superseded grants are outside that check. Keep the flag while any live drain remains. Reset archival has its own release path. Preserve `AgentSynchronizer::archiveForReset()` releasing the symbol’s budget hold for the archived wipe, including standalone budget flags and episode-only drains, while retaining the grant markers and authority, as `MultiAgentCampaignTest` requires. Archive records a cleanup intent with the old reset and the time of archival. Agent overview, registration and the director retry transient budget failures. The director does this before attempting to read the reset schedule. A missing budget file while budgeting is enabled keeps the retry intent in place. A retry preserves holds from a different reset or raised after the archived agent was marked. Historical grant markers and archived episode state must never reassert that hold. A new-wipe hold requires a new drain. If the budget write fails, report the reconciliation failure. The persisted flag continues to hold the budget until a later explicit fleet retry checks pressure and clears it. The actual resume state comes from the committed grant rows. In that recovery case, show that the action targets the budget hold and list zero grant changes if every grant is already running. Rechecking makes the reconciliation safe to retry after a process interruption.

### 4.3 Action contracts

**Shared rules for every Bridge POST**

1. Use the Laravel `web` middleware's CSRF token protection, `auth`, operator authorization (404 for other users) and `throttle:20,1`.
2. Resolve the selected symbol and validate the action shape. Use endpoint-implied `arm` scope for the arm routes, `fleet` for fleet pause, and require `intent=primary|fleet` on fleet resume. A missing or unknown scope is invalid.
3. Take `HostLock::scoped('campaign', …)` before `HostLock::scoped("agent-{$agent->id}", …)`. Give both acquisitions one 10-second deadline. Pass the remaining time to the agent lock to stay well within PHP’s 30-second execution limit. Re-read authority, agent identity, archive state, reset and target grants under the locks. Refuse if the identity changes while acquiring them. With no current agent, allow grant-only fleet pause under the campaign lock only if there is no writable agent or live run to stop. Otherwise, ask the operator to reopen the sheet after registration settles. The director, health hard-pause path and `GateRecon` already take campaign then agent. Command approval, balance review and automation advancement hold the agent lock. Their safety pause and `AutomationRunner::stop()` take no campaign lock. Scoped locking on the shared `HostLock` instance allows nested campaign `retry()` calls in resume and revoke. Never acquire an unheld campaign lock under an agent lock. Move the dispatcher’s director failure handling into the director attempt’s campaign critical section after the agent lock unwinds. Preserve the redacted event and the five-failure thresholds. Acquire campaign then agent when failure handling needs the current agent. Continue swallowing `HostLock` contention in `tick()` and `tickArms()` without incrementing failure counters. Record outstanding arm results before opening the SQLite BEGIN IMMEDIATE authority transaction. The order is campaign → agent → campaign-results-{episodeId} → SQLite write, which matches scorecard harvesting. Newly discovered losses persist if Resume refuses. Recheck recorded results and each safety guard in the authority transaction before writing.
4. Send `agent`, `reset_id` and `expected` keyed by the complete target grant ID set for that intent. Each entry includes the latest authority event ID, `paused_at`, `pause_reason` and the relevant drain marker and stage from §4.2. Send the reviewed `budget_hold_id` even when the expected grant set is empty. Clear only that hold under the budget file lock after committing grant changes. The server recomputes the set and compares normalized values and the reset. Keep unrelated running grants and volatile ordinary `wait_reason` out of the payload. A mismatch refuses the whole action with a `bridge` validation error ("This changed since you opened the review. Review it again."). **Fleet pause is the exception for grant-state or target-set changes.** It pauses the current eligible targets and lists changed targets. A different reset or agent still invalidates the sheet.
5. Present `GameException` as a `bridge` validation error, following `CampaignStatusController::resume()`. Redirect successful submissions to the originating Bridge route with the same agent and a plain status message. Record the existing `pause`, `resume` or `revoked` events through `CampaignControl`.
6. A run with an active slot remains unsettled until automation releases `active_agent_id`. Work that still normally advances may show Running or Waiting. Once stopping or blocked, show the run as unsettled and leave it out of Running counts. `AutomationRunner::advance()` can move a stop-requested run from `stopping` to `blocked` while retaining both `active_agent_id` and `stop_requested_at`. For `stopping`, show "Stopping · settling commands". For `blocked`, name the actual blocker, such as "Unsettled · price review required" or "Unsettled · command outcome uncertain". Previews can move straight to `stopped`. Feedback distinguishes paused authority, a requested stop and a settled run. Resume uses a fresh preview.

#### 4.3.1 Resume one ship

`POST /campaign/bridge/arms/{grant}/resume`

- Resolve a slotless arm belonging to the operator and selected agent. Require `revoked_at` and `superseded_at` to be null. Return 404 if the ID fails these checks.
- When the agent is drained or has an agent-wide `unexplained_change` or `uncertain_action` stop, direct single-arm resume to the fleet review card before checking `paused_at`. Episode-scoped price review stays with the affected arm. Evaluate the loss-stop and settlement blockers before returning "Already running" for an ordinary unpaused arm. A paused arm requires an empty blocker list.
- Call `CampaignControl::resumeArm()`. It rechecks expiry and loss, clears the pause and the previous director timestamp, sets `losses_reviewed_at`, resets the arm failure counter and records `resume`.
- The sheet lists the ship by display name, callsign and arm label. Show the reason, time and exact load losses or last director error. Show the loss against its limit. Footer order is Keep paused, then Resume Light Hauler #9.

#### 4.3.2 Retire one ship

`POST /campaign/bridge/arms/{grant}/retire`

- Use the same target ownership and reset checks as arm resume. Apply the shared loss-stop rule (§4.2), including an arm whose recorded net breached the cap while its pause reason remains `consecutive_losing_loads`. Also allow `director_error`, `grant_expired` and an expired arm whose next director pass has yet to pause it.
- With an open episode in the current reset, use `CampaignFleet::pendingForShip($episode, $ship)`. With no open episode, query `pending` and `sending` actions for the current `agent_id` and `reset_id`, matching either the arm's ship symbol or its run IDs on this arm's current-wipe episodes, including closed episodes. Refuse when either query finds an action. A grant with no episode can be retired once the current agent and reset are verified and the pending check passes.
- Call `CampaignControl::revokeArm()` to revoke authority, request run stops (including sell_aboard on a draining arm), close the open episode if one exists and record `revoked`. Retain wipe history. Apply the drain release rule in §4.2.
- The sheet shows the ship and the cargo that stays aboard. Returning the ship to campaign control requires `campaign:arm` on the PC. Footer order is Cancel, then Retire Light Hauler #9.

#### 4.3.3 Pause the whole fleet

`POST /campaign/bridge/fleet/pause`

- Target the selected primary and the operator's live arms for that symbol. From the current set, send each unpaused, non-draining target to `CampaignControl::pause($grant, 'operator_pause')`. Report already-paused and drain-settling targets as such. Draining grants retain their authority to settle cargo and convert at the director's safe point.
- Hold the ordered locks across the loop. Each pause has its own database transaction. If one throws, report that target's failure and continue. For example, inject a `CampaignControl::pause()` exception for R3 after another target succeeds. A failure acquiring the shared agent or campaign lock refuses the request before any target is written.
- The review sheet names the agent, primary and each arm ship with its callsign. State that active runs receive stop requests and that cargo stays aboard. Report scope changes and outcome per target. For example, "Paused main campaign, R1 and R2. Stop requested for 2 runs. R3 could not be paused. Try again." Footer order is Cancel, then Pause the whole fleet.

#### 4.3.4 Resume the whole fleet

`POST /campaign/bridge/fleet/resume` with `intent=fleet`.

- List the selected primary if paused or drain-marked and the live arms eligible to resume. Include all drain-marked grants regardless of `paused_at`, including any with blockers so they are visible in the review. List ordinary blocked arms separately as staying stopped, with their reasons. Ordinary running grants are outside the target set. Fleet resume may include arms separately paused by the operator. The sheet must name those arms explicitly.
- Check pressure and settlement for the drain, and safety for every target. A blocked primary or any blocked drain target refuses the batch. When there is no blocker, resume the primary first if targeted, then the arms. Run the drain resume changes as one database transaction and reconcile the shared flag after commit (§4.2). Any drain-batch write failure rolls the batch back. In an ordinary fleet resume, a primary failure stops the action and per-arm failures are reported alongside successful targets. Reopen the sheet after a refusal that changes eligibility.
- Report resumed grants, grants staying stopped, and any outstanding settlement or budget reconciliation. Footer order is Keep paused, then Resume the fleet.

#### 4.3.5 Resume the main campaign

Use the fleet resume endpoint with `intent=primary`. The expected set holds exactly the selected primary, even when arms are paused. Check its blockers and require an ordinary paused state. When the agent is drained, direct the operator to fleet review. The heading and commit button say "Resume the main campaign". Footer order is Keep paused, then Resume the main campaign.

---

## 5. Backend read models, queries and performance

### 5.1 Structure (new)

New namespace `app/Gameplay/Bridge/`. `app/Gameplay/Engine/` already sets the subfolder precedent. Each class is read-only unless its name says otherwise.

| Class | Responsibility |
|---|---|
| `BridgeScope` | Resolves, once per request: the operator, the selected symbol, all primaries, the selected primary and its revision, the agent row, the current `reset_id`, the agent's episodes in this reset, live arms, active runs, ships and purposes. Everything else reads from it. |
| `BridgeAttention` | Builds the cards and the Also-watching list (§4.1) for the scope, plus `needs_you` counts for the switcher. |
| `BridgeRoutes` | Route aggregation (§5.3) |
| `BridgeFleet` | Ship grouping and rows (§5.4) |
| `BridgeCredits` | Credits box, last-hour delta and the downsampled wipe curve (§5.5) |
| `BridgePlan` | Phase line, day of wipe, pending replacement and budget line (§5.6) |
| `BridgeLog` | Paged, filtered log (§5.7) |
| `BridgeGuards` | `resumeBlockers()` and the retire eligibility rules (§4.2) |
| `BridgeActions` (writes) | The four actions (§4.3), in terms of `CampaignControl` and `HostLock` |

`CampaignBridgeController` composes these into Inertia props. Page props are grouped:

- `bridge`, shared by every Bridge page: `agent`, `campaignAgents`, `creditsStrip`, `needsYouCount`, `engine`, `updatedAt`, `dataAsOf`, `latestSampleId`
- each page's own props, such as `attention`, `routes`, `selectedRoute`, `fleet`, `selectedShip`, `log` and `chart`

### 5.2 Scope resolution and query budget

The canonical wipe key is `(agent_symbol, reset_id)` within the operator's account. Resolve it once per request.

1. Load primaries in ID order. If the collection is empty, take the HTTP 200 path in §3.1 and read `LocalEngine::find(1)` directly. The empty state still shows engine health when no grant exists for `CampaignHealth::problems()`.
2. Load the selected primary's revision. Resolve the newest unarchived agent row for the symbol with a confirmed `reset_id`. Use an open episode only if its owner, agent and reset match the current unarchived row.
3. Load all episodes in this wipe across the operator's matching agent rows and grants. Include closed and superseded primaries and revoked arms. Take the earliest episode by `opened_at, id` as the wipe baseline. Use the selected primary's current episode for its current phase and decisions. Load live arms separately.
4. Load active runs and ships for the current agent. Use current-wipe episodes for campaign ownership. Load purposes with `ShipPurpose::forAgent($agent, $ships)`. It makes one episode query per live arm at the current fleet size.
5. Read `CampaignHealth::problems($primary)` and `LocalEngine::find(1)`.

If the previous agent is archived and registration hasn't created a confirmed current agent, keep the selected authorization and setup status. Show "Waiting for the new wipe", or the registration blocker if one exists. Show engine health and pending approval if present. Omit wipe credits, samples, routes, ships and old log rows. Disable resume and retire until the current identity is confirmed. If a new current agent exists before its first episode, use its own reset and current credits, and show missing baseline, day count and chart as unavailable. Include current-wipe grant events as in §5.7. Never borrow an archived agent's state.

Targets:

- At most **40 queries** for any Bridge GET.
- **p95 under 150 ms** on the Mac mini with a full 7-day wipe database (≈ 3,000 `load_result`, ≈ 700 samples per episode, ≈ 20,000 events).
- Payload: Brief under **60 KB** JSON, Fleet under 80 KB.

Feature tests assert the query cap for both ordinary page loads and a fleet review with six draining arms using `DB::enableQueryLog()`. §8 adds a timing check on a seeded large fixture.

**No upstream calls.**

- No Bridge GET calls `SpaceTradersApi`, runs agent synchronization or sends an HTTP request, and no page load, sheet review or poll calls `CampaignTrade::results()`, `recordResults()` or `harvestArms()`. `harvestArms()` writes under a lock.
- None calls `FleetMetrics::scorecard()`, which calls `harvestArms()`.
- Bridge reads only persisted `load_result` and `recovery_result` events. The director records them on each pass (`CampaignDirector` calls `recordResults()`, and `tickArms()` calls `harvestArms()`). A finished load therefore appears after the next pass for its grant. Arms are directed one per pass, least recently directed first, so with several arms that can take a few passes.
- Tests assert `Http::preventStrayRequests()` and `Http::assertNothingSent()` on every route, as `tests/Feature/CampaignStatusTest.php` does.

### 5.3 Route aggregation (Q4)

**Key.** `good|source|buyer`, read from `automation_runs.plan.symbol`, `plan.site` and `plan.market` for runs, and from `load_result.data.good`, `data.source` and `data.buyer` for results.

**Running routes.** Runs in `BridgeScope.activeRuns` where `strategy = 'trade'`, `state IN ('running', 'waiting')` and `campaign_episode_id ∈ open episodes`. Exclude any run with a stop request from Running counts, even when the active slot remains set. Blocked runs are unsettled while they hold that slot (§4.3). A route's ships are the distinct `ship_symbol`. The owner label comes from the episode's grant: `C0` for the primary, `arm().label` for arms.

**Stopped routes.** Use the newest trade run by ID in the current open episode of each paused or draining grant, even if that run is terminal or stopping. That single run supplies the key. A claim alone or a grant without an open episode supplies no stopped route. A route with a qualifying running run stays Running and lists the other ship as Paused, Stopping or Unsettled, as applicable. Include unsettled active-slot runs even outside a grant pause. With no running ship, use the settlement or blocker status while any active slot remains. After settlement, apply the stopped or ended rule.

**Totals.** One SQL aggregate over `campaign_events`, using the existing `(campaign_episode_id, kind)` index. The example binds two episode IDs.

```sql
WITH loads AS (
    SELECT json_extract(event.data, '$.good') AS good,
           COALESCE(json_extract(event.data, '$.source'),
                    json_extract(run.plan, '$.site')) AS source,
           json_extract(event.data, '$.buyer') AS buyer,
           json_extract(event.data, '$.net') AS net,
           json_extract(event.data, '$.finished_at') AS finished_at
    FROM campaign_events AS event
    LEFT JOIN automation_runs AS run
        ON run.id = json_extract(event.data, '$.run_id')
       AND json_extract(event.data, '$.source') IS NULL
    WHERE event.campaign_episode_id IN (?, ?) AND event.kind = 'load_result'
)
SELECT good, source, buyer, COUNT(*) AS loads, SUM(net) AS net,
       SUM(CASE WHEN finished_at >= ? THEN net ELSE 0 END) AS net_2h,
       MAX(finished_at) AS last_at
FROM loads
GROUP BY good, source, buyer
```

- Use `whereIn('campaign_episode_id', $episodeIds)` to expand the list. For raw SQL, generate and bind one positional placeholder per episode ID and bind the cutoff last. With no episode IDs, return empty totals. Bind the cutoff as `now()->subHours(2)->utc()->toIso8601String()` (`YYYY-MM-DDTHH:mm:ss+00:00`), matching the `CampaignTrade` writer. SQLite compares these timestamp strings in that format.
- Rate (`k/h`) = `net_2h / 2`. It is shown only when the route has a load in the last two hours; otherwise "—".
- Net and load count are wipe totals.
- The "last load" amount comes from a second query ordered by `finished_at DESC, id DESC` with `LIMIT 1` per running route. Running routes are few, so this is at most one query per running route. A single windowed query is acceptable if it stays under the query cap.
- **Legacy events without `source`:** rows with `source IS NULL` are joined to `automation_runs` through `json_extract(data,'$.run_id')` to take `plan.site`. A route that still lacks a source keeps its totals as "Unknown source → D46". Omit its drill-down link until it has a valid route key.

**Ended routes.** These are keys with totals and no running, stopped or still-settling route now. The Brief and Routes list show one "Ended routes · N routes · net +5.90M" row. `/routes/ended` lists them, sorted by net.

**The summary rate** ("Earning 290k an hour") is the sum of `net_2h / 2` over all routes.

**Route page** (H3):

- Ships on the route.
- Prices now, from `game_observations` (`agent_id` and `reset_id` from scope, `kind = 'market'`, `symbol ∈ {source, buyer}`, the latest row per waypoint through its unique `(agent_id, kind, symbol)` key): `purchasePrice` for the good at the source, `sellPrice` at the buyer, and each row's `observed_at` as its age.
- "Sell price when first seen", from `state.first_seen["{good}@{buyer}"]`, which `CampaignTrade::rank()` stores as a price. For an arm-only route, check the open episode of the arm serving the route first, then the primary’s open episode if the arm has no quote. If several arms serve it, check their open episodes in grant ID order before falling back to the primary.
- Show the last loads. "All N ›" opens `campaign.bridge.log` with `agent`, `filter=routes` and the encoded `route=good|source|buyer` (§5.7).

**Mining** is hidden unless `collect($revision->body['phases'])->pluck('activities')->flatten(1)->contains('strategy', 'mining')`. When shown, a mining run's key is `good|site|market` from its plan, and it gets its own "Mining" heading.

**Probes are never routes.**

### 5.4 Fleet grouping

Groups, in order: **Stopped**, **Idle**, **Trading**, **Selling cargo**, **Positioning**, **Probes**, **Other work** (manual runs, manual mining, recon), and **Mining** (only when shown, per §5.3). The group counts add up to `ships.count()`. A test checks this.

- **Stopped:** a paused, expired or drain-marked arm ship, or the command ship of a paused or drain-marked primary. A ship with a stopping or blocked active-slot run stays here and shows its settlement or blocker status (§4.3).
- **Idle:** not a probe, no active run, not in transit and not stopped. The idle time comes from `MAX(automation_runs.updated_at)` per ship, in one grouped query.
- **Trading, selling cargo, positioning:** from a running or waiting run’s `strategy` (`trade`, `sell_aboard`, `scout` on a non-probe) when it has no stop request.
- **Probes:** `CampaignFleet::isProbe($ship->snapshot)`. They are sub-grouped by purpose prefix from `ShipPurpose`: "Market Scout" or "Buyer Watch" counts as *watching markets*; "Stationed", "Stationing", "Gate Watch" (PR #133 `stationed_waypoint`) as *stationed*; "Scout →" as *scouting*; "Recon" as *on recon*. Group "Standby", "Shipyard Buyer" and null or unrecognized purposes under **Other** ("Standby probe" when no more specific label exists). The Brief and Fleet show one "6 probes · 3 watching markets · 2 stationed · 1 scouting" row that opens `/fleet/probes`.

**Row fields:**

- `symbol` (the callsign) and `name` (`$ship->displayName()`, no purpose)
- `purpose`, `group` and `status_text`
- `right`: one number, either an arrival countdown or `cargo units/capacity`
- `arrival_at` (`snapshot.nav.route.arrival`)
- `waypoint`, `cargo` and `fuel`

Lists send compact fields. H5 sends `selectedShip.details.nav` (status, waypoint, route departure, arrival and destination), `observed_at`, and a `selectedShip.locations` prop containing current and destination waypoint metadata from local map knowledge for this agent and reset. Use those fields with `flightDeckLocation(details, locations)` and `shipPresentation(details, observed_at, connection_state, now)`. Bind `pose`, `transit` and `progress` explicitly to `ShipScene` (§6.3). `locations` belongs to H5's ship prop. `ShipScene` receives the resolved `location`.

**Ship page totals.** Filter the wipe's `load_result` rows by `data.ship` for load count, net and last load. **Recent** shows the newest two `load_result` or `recovery_result` rows for the ship by `data.finished_at DESC, id DESC`. Use logged completion time, units and net. A standalone buy is outside this query.

### 5.5 Credits and the wipe curve (Q3)

- **Credits now.** Use the current agent's `credits` and label `synced_at` as ship-data time. "Updated" shows the server response time (`updatedAt`). Add the ship-data age when `synced_at` is more than 10 minutes old.
- **Change this wipe.** Subtract the earliest episode's `start_credits` from the current balance (§5.2). Classic's net uses its selected primary episode and can differ after a replacement.
- **Last hour.** Subtract the newest sample at or before now minus 60 minutes from current credits. Use the whole wipe's samples, returning "—" if that sample is absent or older than 75 minutes. The director samples at most every 15 minutes per episode.
- **Wipe curve (iPad).** Select `id`, `observed_at` and `credits` from `campaign_samples` for all scoped episode IDs. Order by `observed_at, id`. These sample the same shared agent balance. Deduplicate identical timestamps by keeping the highest ID. Split the line at gaps over 45 minutes. Downsample to at most 240 points with Largest-Triangle-Three-Buckets, preserving the first and last points and gap edges within that budget. If all segment edges exceed the budget, omit the oldest interior segments and preserve the first and latest segments. Refresh the chart when the maximum sample ID over the scoped episodes changes.
- **Day N of M.** Use the earliest episode's `opened_at` and `next_reset_at`. `N = floor((now − opened_at) / 24 h) + 1` and `M = ceil((next_reset_at − opened_at) / 24 h)`.

### 5.6 Plan card and budget line (Q8, Q9)

- **Phase line.** Mark the current primary episode's phase among the revision's phases. When no episode exists, show the setup status. A pending replacement (`CampaignGrant::pendingFor($userId, $symbol)->value('id')`) adds "New playbook queued for the next safe point" while the primary continues working. If the primary is paused or draining, show "New playbook queued. Waiting for the main campaign to resume or expire." If it has already expired and still has work to settle, show "New playbook queued. Waiting for the main campaign to settle."
- **Strategy link.** "How C0 decides ›" goes to `/campaign/strategy?agent=…&from=bridge`.
- **Budget line.** Read once through the local `RequestBudget::usage()` path. Add an opt-in required-telemetry read for Bridge. The current `readState()` treats a missing file as empty state, so this option must surface absence as `RequestDeferred` without changing the default used by the engine. Catch `RequestDeferred` for missing, unreadable or invalid state and show "Request budget unavailable". Skip telemetry-based Watching rows when that happens. If budgeting is disabled for fixtures, show "Request budget off" without a telemetry read.
  - For `cap_per_hour > 0`, show "Requests 2,410 / 2,880 an hour" using Tier 2–4 for both the numerator and the 90% warning. For `null`, show Tier 2–4 usage plus "no cap". For zero, show "No Tier 2–4 allowance". Only a positive cap can produce a near-cap warning.
  - Append host Tier 2–4 requests per minute against `config('gameplay.host_tier2_4_per_minute')`, plus the 15-minute rate-limit count. Put the separate Tier 1 reserve and usage in the expandable detail.
- **Kill-switch detail.** The drain review reads the control-reset `kill_switch:*` row as in §4.2. Show the recorded reason and drain time, what is settling, and any currently breached thresholds. The sheet is read-only until the operator confirms an action.
- **FleetMetrics.** Bridge v1 does not show `fleet_metrics` attribution. `FleetMetrics::byAgent()` needs a 6-hour window and runs heavy queries. A later "Budget detail" sheet may read the `fleet_metrics` table directly (`kind = 'requests'`, `agent_symbol`), but must never call `scorecard()`.

### 5.7 Log (paged, filtered)

- **Scope.** Include events from every episode in the wipe (§5.2). For grant-level events, scope grants by operator and symbol. Include explicit `data.reset_id` matches and exclude tags for other resets. For untagged events, use the confirmed interval when the prior wipe’s `next_reset_at` gives the exact start. If the exact start is unavailable, exclude untagged grant events on the UTC date of the current `reset_id`. That date can contain events from both wipes. Start the fallback interval on the following UTC day. Use the current wipe’s recorded `next_reset_at` as the exclusive end when known. Require grant authority to overlap the interval, from `created_at` through the first of `revoked_at`, `superseded_at` or expiry. Allow queued approvals during that interval, including before the first episode. The first episode can establish the original approval of a pre-existing grant as context. Apply the same-date ambiguity exclusion to that approval and exclude approvals already attributed to an earlier wipe. If the reset date itself is ambiguous, use explicit tags and episode evidence. Bridge authority events include the verified reset ID even on reset day. Registration and registration-intent events already carry `data.reset_id`. During the archive-to-registration gap, show no current wipe.
- **Paging.** Keyset on `id DESC`, 50 per page, with `?before={id}`. "Show earlier" appends using the current agent, filter and route. Clear the cursor when the scope changes.
- **Kinds by filter**

  | Filter | Kinds |
  |---|---|
  | **Problems** | `pause`, `drain_pause`, `director_error`, `replacement_refused`, `freshness_hold`, `claim_collision`, and `load_result` with `net < 0` |
  | **Routes** | `load_result`, `recovery_result` |
  | **Plan** | `approval`, `activated`, `superseded`, `phase`, `resume`, `revoked`, `registration`, `registration_intent`, `probe_purchased` |
  | **All** | the union of the above |

  The route drill-down uses `filter=routes&route={encoded key}`. Validate the three key components with §3.1's patterns. With a key present, return matching `load_result` rows using the same route key and legacy source fallback as the totals. Recoveries have no route key. Show a removable route filter above the list. The Brief's Log preview uses the three newest **All** rows. Record completed loads and plan milestones here. Use Strategy for the playbook walkthrough and Classic's existing decision readout for the latest decision. Bridge leaves internal `decision`, `wait`, `claim`, `claim_release`, `commit` and `commit_end` events out of the Log.
- **Row model:**
  - `at` from event `created_at`, and `kind`
  - `severity`: `problem` (shown with an icon and the word "Problem"), `info` or `ok`
  - `title` and `detail` in plain words
  - `ship` (callsign and display name)
  - `route_key`
  - `link`: the ship page, the route page or none

  Titles come from one **new** formatter, `BridgeLog::describe(CampaignEvent)`. It reuses the wording of `CampaignStatus::eventSummary()` and never shows raw exception text. `director_error` already stores a redacted message.
- **Grouping.** Rows are grouped by local day in the browser ("Today · Mon Nov 23").

### 5.8 Indexes (new migration, additive)

`database/migrations/2026_11_24_000001_add_bridge_indexes.php`:

- `campaign_events (campaign_grant_id, id)`, for the log's grant-scoped keyset paging. SQLite does not index foreign keys automatically, and today only `(campaign_episode_id, kind)` exists.
- `campaign_samples (campaign_episode_id, observed_at)`, for the chart and last-hour lookups. Today there is no index on this table.

Both are created with `Schema::table(...)->index(...)`, and `down()` drops them by name. Both are additive and quick on SQLite. `./scripts/mac/deploy.sh` runs migrations after stopping the engine gracefully.

### 5.9 Polling and staleness

- Every Bridge page calls `usePoll(15000, { only: [...] })` with its own prop list. `bridge` and the page's main prop are always included. `chart` and `log` older pages are never included. This matches `Campaign/Status.vue` (`usePoll(15000)`).
- **Client stale banner.** A 1 s ticker compares `Date.now()` with `bridge.updatedAt`. Past 45 s (three missed polls), the credits box's "Updated" line turns amber and reads "Not updated since 4:12 PM PST — reconnecting". This works whatever the failure: network down, 5xx, the device asleep. A successful poll clears it.
- **Session expiry** during a poll follows Inertia's normal redirect to `/login`. After sign-in, `intended()` returns to the Bridge URL.
- **Server-side staleness** is separate and shown in words:
  - the engine heartbeat line in the footer ("Engine heartbeat 9 s ago"), or the `engine_heartbeat_stale` card
  - ship data older than 10 min (§5.5)

---

## 6. Frontend architecture

### 6.1 Files (new unless noted)

```
resources/js/Layouts/BridgeLayout.vue            AppShell compact + kicker + credits strip/box + tabs + stale banner
resources/js/Pages/Campaign/Bridge/Brief.vue
resources/js/Pages/Campaign/Bridge/Routes.vue    list, or list + selected (split ≥ 1200 px)
resources/js/Pages/Campaign/Bridge/EndedRoutes.vue
resources/js/Pages/Campaign/Bridge/Fleet.vue     list, or list + selected ship (H5)
resources/js/Pages/Campaign/Bridge/Probes.vue
resources/js/Pages/Campaign/Bridge/Log.vue
resources/js/Components/Bridge/AgentSwitcher.vue
resources/js/Components/Bridge/BridgeTabs.vue    bottom bar < 768 px, centred top tabs ≥ 768 px
resources/js/Components/Bridge/CreditsBox.vue    full box (Brief) and slim strip (others)
resources/js/Components/Bridge/AttentionCard.vue
resources/js/Components/Bridge/AlsoWatching.vue
resources/js/Components/Bridge/ReviewSheet.vue   wraps FocusSheet; renders evidence + guarded actions
resources/js/Components/Bridge/RouteRow.vue, ShipRow.vue, LogRow.vue, ProbeSummaryRow.vue
resources/js/Components/Bridge/CreditsChart.vue  inline SVG, no chart library
resources/js/Components/Bridge/PlanCard.vue
resources/js/bridgeFormat.js                     short money, rates, "Light Hauler #9" + callsign helpers
```

**Changed:**

- `resources/js/Layouts/AppShell.vue` (**changed**). Add slots for the top-bar agent pill, centered iPad tabs and detail back link. Add a Bridge prop that leaves `RateLimitStatus` unmounted, including its five-second polling timer. Keep the widget on existing pages, including Strategy when opened from Bridge.
- `Campaign/Status.vue`: the "Try Bridge ›" link.
- `Campaign/Strategy.vue`: the `from=bridge` back link.
- `resources/views/app.blade.php`: the manifest and meta tags.
- `resources/css/command.css`: one token, `--color-attn: #f7dc91`. It has the same value as `--color-focus`, kept separate so focus and attention can diverge.
- `scripts/verify-pages-browser.mjs`: coverage entries for the new pages. It fails when an Inertia page has none.

### 6.2 Shell and styling

- `BridgeLayout` renders `<AppShell compact :brand="…">`. The shell keeps the skip link, the brand and the Menu `FocusSheet`. Bridge supplies the `navigation` slot and the top-bar slots in §6.1. Menu links include "Classic status" and "How C0 decides". Pass the prop that keeps the standard rate widget unmounted on Bridge.
- It uses only the shared tokens (`--color-space`, `panel`, `field`, `line`, `ink`, `muted`, `accent`, `fault`, `cargo`, `focus`, and the new `attn`) and the existing button components (`PrimaryButton`, `SecondaryButton`, `DangerButton`).
- Bridge styles are scoped (`<style scoped>`) or prefixed `.bridge-`, so existing pages are untouched.
- Phone layout:
  - The page body pads the bottom by the tab bar height plus `env(safe-area-inset-bottom)`.
  - The tab bar is `position: fixed`, `inset-inline: 0`, `bottom: 0`, with left and right padding of `max(8px, env(safe-area-inset-left/right))`.
  - In Safari the bar sits above the toolbar. In standalone mode it sits above the home indicator.
- Breakpoints: `< 380` (short brand hidden), `< 768` (phone; short brand "ST //" instead of "ST // AGENT COMMAND" so the pill and Menu fit), `768–1199` (tablet portrait; top tabs; two-column Brief at ≥ 1000), `≥ 1200` (split views). They are tested at 360, 402, 1032 and 1376 px (§8.2).
- Text sizes follow the wireframe tokens: summary 22/1.25, credits 44, rows 16/14. Dynamic Type is respected through `rem` and wrapping.

### 6.3 Behaviour

- **Short money** comes from `Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 2 })`. The exact `toLocaleString()` value goes in `aria-label` and `title`.
- **Inertia navigation.** Tabs and rows are `<Link>` with `preserveScroll` off. In the iPad split view, list rows use `preserveState` and `preserveScroll`, so the list keeps its scroll position while the right pane changes.
- **Sheets** request the selected review as an Inertia partial before showing it. They submit with `useForm({ agent, reset_id, intent, budget_hold_id, expected })` to the action routes, with `preserveScroll`. On open, focus the heading. Place Cancel or Keep paused before the committing button in the footer. While submitting, every action button is disabled and labelled "Working…". Errors on `bridge` render inside the sheet above the buttons with `role="alert"`. On success the sheet closes, and the flash shows in a `role="status"` line under the summary.
- **The main button** is the first attention card's Review button. It gets `PrimaryButton`. Every other card's button is secondary.
- **ShipScene on H5.**

  ```vue
  <ShipScene :location="flightDeckLocation(ship.details, ship.locations)"
             :reset="agent.reset_id"
             :pose="presentation.pose"
             :transit="presentation.transit"
             :progress="presentation.progress"
             compact :interactive="false" />
  ```

  Compute `presentation` with `shipPresentation(ship.details, ship.observed_at, agent.connection_state, now)`. Wrap the scene in a labelled `figure` with `role="img"`.
- **The ship name** always renders as `<span class="name">{{ name }}</span> <span class="callsign">{{ symbol }}</span>`. In sheets and cards the callsign is also in the facts line.
- **Reduced motion.** `@media (prefers-reduced-motion: reduce)` turns off sheet and tab transitions.

---

## 7. Phased implementation and cutover

Each slice is one PR. It is deployable on its own through `./scripts/mac/deploy.sh`, adds tests, and leaves `/campaign` working. Every deploy through the script ends and restarts a running or starting engine, including read-only slices. If the engine was already off, the script leaves it off. Batch compatible slices at a safe point to reduce restarts.

| # | PR | Contents | Risk to live campaigns |
|---|---|---|---|
| 1 | **Bridge foundations** | `SelectsCampaignAgent` concern (Classic behaviour unchanged); `BridgeScope`, `BridgeCredits`, `BridgePlan`, `BridgeAttention` (read); routes `campaign.bridge.brief`; `BridgeLayout`, `BridgeTabs`, `CreditsBox`, `AgentSwitcher`, `AttentionCard`, `AlsoWatching`, `PlanCard`; Brief page (no actions; Review opens a read-only sheet with guidance to use Classic for the primary and the CLI for arms before the action slice); "Try Bridge ›" link on Classic; index migration (§5.8); page coverage entries | Restart and additive index migration |
| 2 | **Routes** | `BridgeRoutes`; Routes, Route (H3), Ended routes pages; split view ≥ 1200 px | Restart |
| 3 | **Fleet and ship** | `BridgeFleet`; Fleet (H4), Ship (H5 with `ShipScene`), Probes pages; split view | Restart |
| 4 | **Log and chart** | `BridgeLog`; Log (H6) with filters and paging; `CreditsChart` on iPad Brief | Restart |
| 5 | **Actions** | `BridgeGuards`, `BridgeActions`; the four POST routes; scoped review sheets (A3 pattern); shared safety and pressure predicates; drain bookkeeping in `CampaignControl`; ordered director failure handling with lock contention still ignored | Restart plus changes to pause and resume authority and drain bookkeeping |
| 6 | **Home Screen** | Manifest, icons, Apple meta tags; safe-area pass in standalone | Restart |
| 7 | **Cutover** (after §7.3) | §3.2: `/campaign` renders Bridge, Classic at `/campaign/classic`, redirects from `/campaign/bridge…`, menu "Bridge", `HomeRoute` post-login, manifest `start_url` | Restart and navigation changes |
| 8 | **Retire Classic** (one wipe after 7, if Taylor agrees) | Remove `Campaign/Status.vue`, `/campaign/classic`, `/campaign/pause`, `/campaign/resume`; move their tests to Bridge equivalents | Restart |

Slice 6 can ship any time after 1. Slice 5 needs 1 and 3, because the ship page hosts the arm cards.

### 7.1 Deploy notes

- Run `git log origin/main..HEAD` before shipping. Other sessions commit to the same branches.
- The deploy preview lists the index migration in slice 1, and nothing else.
- After each deploy, confirm that both views render using their URLs for that phase. Verify the engine heartbeat when a restart was expected. Check fresh director and arm progress on approved, unpaused campaigns and arms, or the expected paused, draining or waiting state. Report the deployed commit and the script's `OK:` lines.

### 7.2 Feature flag

None is needed. Bridge is opt-in by URL until cutover, and only the operator can reach it.

### 7.3 Cutover checklist

Use production `/campaign/bridge` through a live wipe for the acceptance checks. Run the isolated rehearsals where specified below.

**Read accuracy**

- [ ] For three spot checks across at least two days, compare Bridge's current credits with the agent balance. Compare change, day count and last hour with a read-only query over the scoped wipe baseline and samples (§5.5). Classic and `campaign:status` use a single episode, so their net can diverge after a replacement.
- [ ] Route totals for at least 2 running routes match a manual sum of `load_result` nets for that `good + source + buyer` (`php artisan tinker`, read-only).
- [ ] Fleet group counts add up to the ship count, and every probe sub-group is right against `ShipPurpose` labels on the flight deck, stationed probes included.
- [ ] Mining stays hidden, because no approved revision has a `mining` activity.
- [ ] Log paging reaches the wipe's first event, and each filter shows only its kinds.

**Attention**

- [ ] At least one real arm pause (`consecutive_losing_loads` or `director_error`) showed as a Needs-you card with the correct ship, reason and evidence. The test-agent rehearsal counts if no live one happens.
- [ ] A kill-switch drain or a forced drain on the MACSOUP rehearsal agent showed on the drained agent's Brief and as the cross-agent badge on GIANTSOUP's pill.
- [ ] In the development or test fixture for MACSOUP, deliberately stop that environment's engine and age its heartbeat past five minutes. Verify that the engine-stale card appears and clears on a fresh, running heartbeat after restart.

**Actions** (on the MACSOUP test agent first, then once on the main fleet when a real need arises)

- [ ] Arm resume from the phone succeeded, and the next decision used fresh state (a new `decision` event after `resume`).
- [ ] Arm resume was refused when the loss-stop rule applied, and the card offered Retire.
- [ ] Arm retire from the phone revoked the arm and kept its history in Routes and Log.
- [ ] Whole-fleet pause paused the primary and every live arm of that agent, and only that agent. Whole-fleet resume resumed them and listed any ship that stayed stopped.
- [ ] A resume sheet changed by the CLI was refused with "This changed since you opened the review." A fleet-pause sheet with a changed grant set paused the current eligible targets and listed the difference.

**Devices and quality**

- [ ] The §8.2 matrix passed on a real iPhone 17 Pro in Safari and standalone, and on the iPad Pro 13" in both orientations.
- [ ] There were no horizontal overflow, console errors or 5xx responses in `storage/logs` from Bridge during the wipe.
- [ ] Polling ran for 8+ hours without a memory or battery problem on the phone. The stale banner appeared with Wi-Fi off and cleared on reconnect.
- [ ] Taylor confirms in writing that Bridge can replace `/campaign` and become the post-login home.

---

## 8. Testing and verification

### 8.1 Backend (PHPUnit, SQLite in memory as in `phpunit.xml`)

New files:

- `tests/Feature/CampaignBridgeTest.php` (pages and props)
- `tests/Feature/CampaignBridgeActionsTest.php`
- `tests/Unit/BridgeRoutesTest.php`
- `tests/Unit/BridgeLogTest.php`

Each feature test starts with `Http::preventStrayRequests()` and ends with `Http::assertNothingSent()`.

**Access**

1. A guest is redirected to login on every Bridge route. A signed-in non-operator gets 404 on every GET and POST. Both are table-driven over the route list.
2. With primaries, an unknown or another user's `agent` returns 404. With one primary, `agent` is optional and resolves to it. With zero primaries after revoke or between wipes, Brief and a saved URL with `agent=` return 200 with engine health, the authorization empty state and `campaign:approve` guidance. Verify the same path through `/campaign` at cutover.

**Multi-agent**

3. Two primaries (GIANTSOUP and SOUPFLEET), with SOUPFLEET draining:
   - GIANTSOUP's Brief shows no SOUPFLEET card.
   - The switcher reports `needs_you = 1` for SOUPFLEET.
   - The badge counts 1 agent.
   - Engine-stale is not counted as cross-agent.
   - Repeat with only the current open episode’s `state.draining` marker, and with only the local budget flag. From GIANTSOUP, both cases show one other agent needing attention. On SOUPFLEET’s Brief, each case shows one matching card.
4. Arm grants of SOUPFLEET never appear in GIANTSOUP's routes, fleet, log or actions. Posting a SOUPFLEET arm id with `agent=GIANTSOUP` returns 404.

**Aggregation**

5. Routes:
   - Running routes come from the primary and arm active trade runs together.
   - Two arms on one route make one row with two ships. A stop-requested run with an active slot does not count as running in either `stopping` or `blocked`. Its settlement status or review blocker stays visible.
   - Stopped route keys come from the newest open-episode trade run. Quotes from another reset are excluded. Verify UTC `+00:00` timestamps at the two-hour boundary.
   - Totals equal the sum of `load_result` nets by `good + source + buyer`. Aggregate two episode IDs in one SQLite query and exclude a third episode.
   - `net_2h / 2` gives the rate.
   - A legacy event without `source` takes it from its run.
   - An arm-only route reads the arm episode’s first-seen sell price and falls back to the primary only when the arm has no value.
   - `recovery_result` is excluded.
   - Revoked arms' loads count toward Ended routes.
6. Mining:
   - Hidden when the revision has no `mining` activity. A manual mining run's ship is in "Other work", and the route count excludes it.
   - Shown when the revision has one.
7. Fleet:
   - Group counts add up to the ship count.
   - A stationed probe (`stationed_waypoint` set) is counted as *stationed*. A JUMP_GATE station counts the same way, labelled gate watch.
   - A paused arm's ship is Stopped. Standby, Shipyard Buyer and unknown probe purposes are visible under Other.
8. Display names:
   - Rows use `displayName()` without purpose, and the callsign is present.
   - A nickname overrides the semantic name.
   - The purpose never appears twice.
9. Chart:
   - 700 samples come back as at most 240 points, keeping the first and last.
   - A 2-hour gap splits segments.
   - Only `id`, `observed_at` and `credits` are selected; the test asserts the column list through the query log.
   - Last-hour credits use the right sample, or are null when the newest older sample is more than 75 min old.
   - A replacement primary opens with a different baseline mid-wipe. Bridge keeps the first episode's baseline and day count, includes both primary and arm samples, and advances chart freshness using any scoped episode.
   - Archive the previous agent before re-registration. The waiting screen contains no previous-wipe values. On new registration, show the new agent's credits and reset with no baseline until an episode opens.
10. Log:
    - Keyset paging is stable across inserts.
    - Each filter returns only its kinds.
    - Include approval before registration and before the earliest episode. Keep the previous reset's grant-level events out after an agent is archived and re-registered, including events from a grant that survives both wipes. Include a mid-wipe replacement's approval and activation.
    - With no exact wipe start, exclude an untagged old-wipe event from earlier on the current reset date and include an explicitly current-tagged event on that date. With the prior wipe’s recorded boundary, exclude the earlier event and include an untagged event after the boundary.
    - A route filter matches only the selected key, including legacy sources, and keeps its key through paging.
    - Internal kinds are excluded.
11. Budget line and Also-watching:
    - `RequestBudget::usage()` is faked through its state file, as `tests/Feature/RequestBudgetPhasesAndAgentCapsTest.php` does.
    - 1 host 429 gives Watching. Deferral share above 5% gives Watching. `cap_per_hour` null gives "no cap".
    - An unlisted agent or an explicit zero cap shows no Tier 2–4 allowance and raises no near-cap warning. Tier 1 traffic does not count toward a positive ordinary cap.
    - Missing and unreadable budget files both render 200 with "Request budget unavailable". Fixture mode gives "Request budget off".
12. Kill switch:
    - A grant with `wait_reason = 'drain_pause:host_rate_limits'` gives a Stopped agent card.
    - A grant with `pause_reason` starting `drain_pause:` gives the same.
    - Archive a drained agent at reset. Assert that Tier 2–4 requests can proceed and the budget hold clears while the grant’s drain markers and authority persist. Reading Bridge or reconciling an old episode must not reapply that hold.
13. Performance:
    - On a seeded fixture (3 agents, 6 arms, 14 ships, 3,000 loads, 700 samples, 20,000 events), each GET stays at 40 queries or fewer. The targeted Fleet Resume review stays within the same cap with pooled probes and an outstanding price review.
    - Brief JSON stays under 60 KB.

**Actions**

14. Arm resume:
    - A `consecutive_losing_loads` pause resumes. It clears `paused_at`, sets `losses_reviewed_at`, resets `arm_director_failures:{id}` and records `resume`.
    - `arm_loss_stop` is refused, with the grant unchanged. Repeat with `consecutive_losing_loads` as the pause reason and recorded net below `-max_net_loss`. The card directs both cases to Retire.
    - An expired arm is refused.
    - A non-arm grant id returns 404.
    - A revoked arm returns 404. For an agent in either drain stage or with an agent-wide balance or uncertainty stop, single-arm resume points to fleet review and changes no state.
15. Arm retire:
    - Allowed for `arm_loss_stop` and for a recorded loss-cap breach with `consecutive_losing_loads`. Include `director_error` and `grant_expired`. Retire sets `revoked_at` and closes any open episode.
    - Refused for a running arm or an `operator_pause` when the loss-stop rule does not apply.
    - Refused while a `pending` or `sending` action exists for the ship. Exercise the current-reset direct action and run association fallback for an arm with no open episode, then allow it after settlement.
16. Fleet pause:
    - Pauses the primary and all unpaused live arms of the selected agent only.
    - Another agent's grants are untouched.
    - Inject a `CampaignControl::pause()` exception for one arm. Other targets pause and the flash names the failed arm. A prior lock acquisition failure writes nothing.
    - A drain in progress keeps its reason, budget hold and settlement authority. A manually requested stop initially renders as Stopping. Advance the runner with an outstanding price review. Assert that it reaches `blocked` with the active slot and stop request intact, and that the ship and route show the review blocker as unsettled.
17. Resume scope and drain lifecycle.
    - Primary intent resumes exactly the primary while an arm deliberately paused via CLI stays paused. Fleet intent names every candidate on the sheet and resumes the primary first if targeted. Test an invalid intent and an incomplete or extra target ID.
    - A failing primary resumes nothing. Ordinary arms past their loss stop stay stopped and are listed.
    - Invoke the real `drainWithArms()` path for a primary and at least two arms. Before any director conversion, open a fleet review while their `paused_at` values are null. Provide fresh in-range telemetry and no unsettled runs or actions. Exercise a cargo-only blocker and, independently, an empty hold with a retained `sell_aboard` preview. Both refuse all writes. After the cargo and preview have settled, submit a fresh review and verify that all three grant markers and all `episode.state.draining` values clear, fresh decisions are eligible, and `RequestBudget::isDraining($symbol)` becomes false. Repeat for mixed wait and pause markers.
    - A remaining active recovery, stopping run or pending command blocks the draining batch until settlement. Drive `stopping → blocked` with price review or uncertainty. Resume still refuses while the active slot and stop request remain, and the sheet names the review blocker. After review clears and the runner releases the slot, resume can proceed. A blocked drained arm keeps the budget hold. A resume write failure rolls the drain batch back. A budget reconciliation failure can be retried without replaying grant changes.
    - An interrupting safety pause preserves the drain marker and still blocks resume while cargo or a retained preview remains. Add flag-only settlement and archival regressions, including archival of episode-only drains. Approve a replacement during that pause. It stays in slot 2 through an activation pass despite settled work, and Plan shows the paused-primary copy. Verify activation after resume or expiry once the primary is idle.
18. Gates. Test agent-wide uncertainty and an episode-scoped price review on a different arm. Test nonzero unexplained balance with nothing pending, a persistent loss stop despite a resume-cleared pending pause, and each of the five drain causes. Include post-`last_drain_at` 429 counting and verify that old breaches with current healthy telemetry do not block. Repeat with unavailable telemetry for a drained agent. With budgeting disabled, a settled and otherwise eligible drain resumes and Plan shows "Request budget off".
19. Stale sheet. Changed target membership, pause or drain state, or reset refuses resume and retire with no writes. Volatile ordinary wait reasons on unrelated running grants do not invalidate a sheet. Open sheets for both resume and retire before the scheduled boundary. Submit at and after `next_reset_at` while the paused primary’s old agent row is still unarchived and the reset ID still matches. Both refuse with the reset-confirmation message and no authority writes. Also test disagreeing local reset metadata, an extension in the same-reset cache after the stored episode boundary, and a fresh sheet after confirmation. Fleet pause keeps its target-set carve-out and refuses a reset change. Verify CSRF middleware with a token-aware browser test.
20. Ordered locking. An independently held campaign lock fails within 10 seconds. Delay campaign lock acquisition, then hold the agent lock and verify that the whole acquisition wait still fits the same 10-second budget. Assert that a sender holding the agent lock can finish without waiting for campaign. Place an action or balance change before it releases the agent lock, then verify that Bridge reads the fresh blocker before any write. Nested `resumeArm()` and `revokeArm()` calls re-enter the outer scoped campaign lock without waiting. Exercise a fifth director failure racing an open resume sheet. The pause is serialized and the stale sheet is refused or the failure is ordered after a completed resume. Hold the campaign lock independently and call `tick()` and `tickArms()`. Contention does not increment a failure counter or emit a failure event. Discover an over-cap loss during POST with the director off and verify it remains visible on Brief and Retire after refusal. Run Resume concurrently with scorecard harvesting to verify that both finish.
21. Cutover (slice 7):
    - The operator logs in and lands on `/campaign`.
    - A non-operator lands on `/agents`.
    - `intended()` still wins.
    - The named legacy Brief route and nested detail URLs redirect 302 during the first wipe with `?agent=` preserved. Classic pause and resume return to `/campaign/classic`. Password confirmation follows `HomeRoute` when there is no intended URL.

The existing suites stay green unchanged, apart from the concern extraction: `CampaignStatusTest`, `CampaignStrategyTest`, `MultiAgentCampaignTest`, `CampaignAutopilotTest` and `RequestBudget*Test`. `bash scripts/verify.sh` must pass.

### 8.2 Browser and device matrix

**New** `tests/Browser/campaign-bridge.mjs` and `tests/Browser/campaign-bridge.php`. They follow the pattern of `tests/Browser/campaign-status.mjs`: a private SQLite fixture, a loopback server, and synthetic data with 2 agents, arms, probes, one paused arm and one drained agent. Playwright 1.64.1 is already a dev dependency. The script saves screenshots to `CAMPAIGN_BRIDGE_EVIDENCE`.

| Viewport | Checks |
|---|---|
| iPhone 17 Pro 402 × 874 (Safari UI simulated with a 402 × 760 viewport, and standalone 402 × 874) | First screen above the tab bar shows the credits box, the summary and the first card's button without scrolling. No horizontal overflow. Tab bar clears `env(safe-area-inset-bottom)` (emulated with a CSS override fixture). All targets are 44 × 44 or larger (scripted bounding-box check). |
| Narrow 360 × 780 | The brand is hidden. The pill and Menu fit. Long names and callsigns wrap. Nothing is clipped at 200% text zoom. |
| iPad Pro 13" portrait 1032 × 1376 | Top tabs; two-column Brief; Routes and Fleet stacked |
| iPad Pro 13" landscape 1376 × 1032 | Split views; the selected row has `aria-current`; the list scroll is kept when changing selection; the chart renders at most 240 points with gaps |
| Desktop 1440 × 900 | Same as landscape, centred at max width |

**Behaviour checks:**

- **Polling and staleness.** Abort `/campaign/bridge*` requests for 50 s. The amber "Not updated since … — reconnecting" line appears. Unblocking clears it.
- **Sheets.**
  - `showModal` is open and focus is on the heading.
  - Tab and Shift+Tab stay inside.
  - Escape closes and focus returns to the opener.
  - The footer places Cancel or Keep paused before the committing button, with both full width.
  - The committing button is disabled while submitting.
  - A server refusal renders in `role="alert"` inside the sheet.
- **Agent switcher.** The badge has text. Choosing an agent keeps the tab. Detail pages fall back to the list. `?agent=` is on every link.
- **Strategy round trip.** The Plan card opens Strategy with "‹ Bridge", and the link returns to the same agent's Brief.
- **No external requests.** All non-loopback requests are blocked and asserted to be zero.
- **Accessibility.** There is exactly one `main`. The tab `nav` is labelled. Duplicate ids fail. Status words are present, not only colour dots.

**Manual device pass** (cutover checklist):

- A real iPhone 17 Pro in Safari, then in a Home Screen install with the standalone status bar and home-indicator insets.
- A real iPad Pro 13" in both orientations.
- VoiceOver reads the summary, the cards, the badge and the sheet in order.

Update `scripts/verify-pages-browser.mjs` with authenticated fixtures for a primary, an arm, a route and usable operator credentials. Add Bridge entries at 1440, 390 and 320 px, plus Classic and Strategy. The script enforces exactly one `<h1>` per page. Use an `<h1>` for each Vue detail page heading, shown as an `<h3>` in the multi-frame wireframe document. At cutover move the Classic entry to `/campaign/classic` and keep Strategy covered. Find each login and re-login assertion currently waiting for `/agents`. At cutover assert `/campaign` for the operator and `/agents` for a non-operator, and retain an intended-URL case. Run from the development checkout with a built frontend and installed Playwright Chromium.

```sh
node scripts/verify-pages-browser.mjs
```

`bash scripts/verify.sh` syntax-checks the script. The Node command runs the authenticated browser checks.

---

## 9. Risks, edge cases and follow-ups

- **Arm alerts are on-screen only.** `CampaignHealth::problems()` and `CampaignWatchdog` read primaries only, so an arm pause sends no notification. Follow-up (engine): include live arms in the watchdog.
- **Automatic arm renewal** is not implemented. Expired arms stop. Follow-up (engine): renew within the approved playbook's limits, with a `renewed` event. Bridge would then add a log row and an "Arm renewal failed" Stopped card.
- **Command-ship 45-minute escalation.** The current health check reports Watching. The escalation to Stopped is deferred to an engine task.
- **Kill-switch control agent is hard-coded.** `evaluateKillSwitch()` treats `GIANTSOUP` as control and prioritises `SOUPFLEET` over `SOUPBURN`. Bridge only reads its effects (`drain_pause:` reasons). The wording names the drained agent, not a role.
- **`ShipPurpose::forAgent()` per-arm queries.** It runs one episode query per live arm. That is fine for ≤ 6 arms. If arms grow, add a batched variant. The Bridge query-cap test will flag it.
- **Load results lag** by up to one director pass, because Bridge never harvests. The route page says "Loads appear once the plan records them, usually within a minute" in its note.
- **Samples stop while paused or with the engine off.** The chart shows a gap, and "last hour" shows "—" rather than a wrong number.
- **Wipe boundary.** The old agent row is archived with its original `reset_id`. Bridge uses the waiting state in §5.2 until a current row exists. Historical reports remain available through `campaign:report`.
- **The brief mentions `app/Models/MarketObservation.php`, which does not exist.** Market quotes live in `game_observations` (`kind = 'market'`, model `App\Models\GameObservation`), with history in `observation_history`.
- **Standalone web app sessions.** iOS keeps a separate cookie jar for Home Screen apps. The operator signs in once there, using "Remember me".
- **Single-ship pause** is not in v1 (Q2 covers resume and retire only). Pausing one ship remains `campaign:arm-pause` on the PC, or whole-fleet pause from the phone.
