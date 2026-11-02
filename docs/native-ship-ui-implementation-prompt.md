# Native ship UI implementation prompt

Status on November 1, 2026: implementation exists on unpublished `feature/native-single-ship-ui` at `da1da847e6c2234331317b98ced76afe0bc2b745`. It is not on audited remote main or this documentation branch. UI01 [issue #5](https://github.com/giantsoup/spacetraders/issues/5) / [PR #6](https://github.com/giantsoup/spacetraders/pull/6) retains integration and acceptance ownership. See the [current status index](plan-implementation-audit.md#current-status-index). The implementation instructions below remain the acceptance contract.

Give the following task to an implementation agent working in this repository. This prompt authorizes the UI implementation when invoked; it does not authorize live gameplay or deployment.

---

Implement the approved phone-first SpaceTraders design in the existing Laravel/Vue application. Deliver a working single-ship experience connected to the current application data and command handlers. Use Horizon as the starting point recommended by the design brief, with Logbook's plain-language status and activity presentation. Keep Voyage as a visual reference for travel and compact fleet rows rather than building three selectable application themes.

## 1. Establish the current baseline

Inspect the branch, working tree, existing changes, runtime, and Herd site before editing. This checkout may contain substantial uncommitted gameplay work. Preserve that work, including untracked files. Any isolated checkout must retain the relevant current baseline; starting from committed HEAD alone can omit existing functionality.

Read these references in order:

1. [Design brief](design-brief.md): the authority for visual direction, interaction patterns, state presentation, and acceptance requirements.
2. [Approved redesign report](single-ship-redesigns.html): inspect the actual Horizon screen and its full-screen preview, then view the four PNG references in `docs/design-reference/`. Read relevant HTML/CSS/JS sections without flooding your context with the entire file.
3. [App styling](app-styling.md) and [README](../README.md): current shell, shared controls, runtime, local setup, and verification conventions.
4. [MVP implementation](mvp-implementation.md), [QA follow-ups](mvp-qa-followups.md), and [security review](mvp-security.md): existing command contracts and failure/recovery behavior to preserve.
5. [Exploration implementation](exploration-implementation.md): current observation, market, fuel, survey, scouting, and trading behavior, plus its remaining gates. Verify against the current source.
6. [Local automation data contract](local-automation-data.md), [implementation plan](local-automation-implementation-plan.md), and [acceptance report](local-automation-verification.html): the newer Mining and Contracts investigation, staging, approval, engine-session, and accounting contracts. The plan contains historical baseline descriptions; current source and the data contract determine existing behavior.

The [mobile research](single-ship-mobile-research.md) provides API and presentation context. Its earlier layout proposals and the first vision board are historical. The native iPhone and Linode hosting plans describe later work, not this task. Historical test counts and audit verdicts are not acceptance evidence for your changes.

Inspect the current `resources/js/Pages/Agent/Dashboard.vue`, `resources/js/Layouts/AppShell.vue`, `resources/css/command.css`, shared controls, `resources/js/Composables/useGuidedFlight.js`, `resources/js/dateTime.js`, and `resources/js/dataPresentation.js`. Trace the affected routes/controllers and shared gameplay services before changing a command flow.

Before implementation, record a short capability-to-destination map: where ship details, manual commands, previews, automation controls, market/intelligence views, guided flight, setup, activity/reconciliation, and account navigation will live. Proceed with implementation after recording this map; routine presentation choices do not need another approval round.

## 2. Build the actual experience

Implement these connected workflows:

- A continuous dark single-ship home screen with ship identity, a restrained pixel scene, readable status/ETA, grouped Fuel and Cargo rows, and one contextual primary action. Systems can move into Report on shorter screens.
- A visible Report entry and a tappable ship that open the same focused report. Fuel and Cargo open their own sections directly. Show real observed inventory, tank units, readiness, crew, components, installed equipment, and observation age. Missing values remain Unknown.
- Focused command and approval flows that reuse existing handlers and validation. Keep supported manual commands, bounded mining/scouting/trading, surveys/transfers, and the restricted setup-purchase path reachable where the current app supports them. Market and intelligence information belongs in focused views rather than another home-screen panel stack.
- A compact entry to recent activity, with complete command history and reconciliation available from it. Preserve truthful queued, sending, confirmed, failed, uncertain, and review-required states.
- An active-run view with its current step, limits/blocker, and reachable Stop control. Display Stop requested while reserved work settles; do not manufacture an immediate completed stop.
- Reachable Mining and Contracts investigation, staging, preview, and approval. Keep local engine health, run Stop, and session shutdown distinct. Starting the engine authorizes no gameplay, and stale previews must not bypass engine-session or draft-revision checks. Preserve observed outcomes, estimates, and unexplained balance changes as separate figures.
- Existing owned-ship selection and its per-user/agent/reset persistence. A small selector is sufficient. Keep guide, agent, account, profile, and sign-out access discoverable.

Integrate the redesign with guided flight. Update step targets, focus destinations, navigation, and browser journeys when controls move into sheets or other views. Completing the tutorial and recovering from interruption must still work.

Use actual ship identity and observed data. Prototype values such as Mochi, TAYLOR-1, its cargo, and its route are illustrative. Reuse the original pixel SVG studies where suitable; keep art independent of capability checks. The demo-state picker, fake approvals, restart-trip control, preview toolbar, and simulated phone bezel stay in the design artifact.

## 3. Preserve the design and gameplay boundaries

Implement the design brief's full state/action matrix. In particular:

- Keep Fuel and Cargo visible at default text size in normal portrait Horizon states at 320 × 667, 375 × 548, and 390 × 844. Reduce art before shrinking essential text. Let long content, enlarged text, and landscape scroll without covering controls. Respect safe areas and changing browser chrome. Clipping content with hidden overflow does not establish fit.
- Use shared semantic colors, spacing, typography, and control styles. Group related rows with separators and spacing. Keep secondary details behind meaningful navigation or disclosure instead of framing every fact as a card.
- Provide 44px minimum hit areas, visible focus, readable contrast, explicit sheet dismissal, Escape, focus containment/return, and working keyboard navigation. Preserve dark mode under a light OS preference.
- Derive remaining travel and cooldown from absolute instants. At zero show Checking until fresh server observations confirm the result. Preserve UTC storage, comparisons, and API data; format presentation through `dateTime.js` with the detected timezone.
- Keep navigation, cooldown, freshness, command execution, and automation as separate facts. A local page reload does not make an upstream observation fresh.
- Make motion decorative and data-driven, with reduced-motion and presentation-pause support. Pausing never moves deadlines or stops server gameplay. Recalculate after background/resume. Animation and countdown ticks generate no API requests and unlock no actions.
- Preserve preview/approval, ownership, CSRF, server-side capability checks, budgets/reserves, duplicate prevention, shared request limits, reset handling, and uncertain-write reconciliation. Confirmed transactions with a review flag remain confirmed. An uncertain write is never silently replayed.

Keep API credentials, gameplay decisions, and execution on Laravel. Use the current stack and shared server command path. Small presentation-data changes are acceptable where required, with ownership/serialization coverage. A UI redesign does not expand supported travel modes, purchases, automation bounds, or investment strategies. Keep explicit unknowns and existing prerequisites visible.

## 4. Implement safely and verify the result

Make small, verifiable changes and reuse existing patterns. Split Vue components or presentation helpers where they create clear responsibilities; avoid duplicating the old interface or rebuilding the gameplay engine. Keep installed dependency constraints and lockfiles unless a demonstrated implementation requirement justifies a minimal change.

Use Herd for the existing site. Read runtime and tooling requirements from the current README and manifests. Run application checks against owned disposable fixtures with fake SpaceTraders responses and blocked stray requests. Established fixture scripts may start their own finite fixture server; they must not use the live site's environment or database.

Complete these checks:

1. Run the relevant deterministic/frontend/backend checks and the repository's `scripts/verify.sh` with the requested runtime. Keep generated tracked build assets synchronized with source. Fix regressions caused by the redesign and distinguish pre-existing failures with evidence.
2. Extend the application browser coverage for the new ship home, report sections, navigation/back behavior, command preview/approval, active-run Stop, and unresolved-action recovery. Update existing selectors to follow the real UI while retaining behavioral assertions. Include the affected guided-flight, agent/archive, market/opportunity, local automation staging/approval and engine controls, and shared-shell journeys.
3. Exercise default, transit, arrival-due, orbit, docked, cooldown, stale/disconnected, queued/sending, uncertain/review, active automation, empty cargo, no-ship, and archived/reset states. Include missing fields, zero-capacity fuel, long symbols, pause/resume, and background/foreground transitions.
4. Verify real rendered layouts at the portrait sizes above, 844 × 390 landscape, a wider viewport, and enlarged text. Check actual bounds and accessible controls, not just document scroll width. Inspect dark screenshots against the approved references. Check keyboard focus and reduced motion as well as pointer interaction.
5. Verify navigation and asset loading on normal local HTTP and forwarded HTTPS, preserving the tunnel fix and its regression coverage. A working tunnel is not a deployment requirement.
6. Review credential exposure, ownership, request-forgery protections, command bounds, and duplicate/uncertain-write behavior after any affected payload or route change.

Use the existing application scripts and fixtures as the starting point. `scripts/verify-single-ship-redesigns.mjs` verifies the HTML prototype only; passing it cannot prove that the implemented Vue workflows work. Match browser claims to the browser actually exercised. Use Chrome for interactive local verification where available, and state whether physical-phone/Safari behavior remains unverified.

The scope is local UI implementation and verification. Preserve the current application key, credentials, live database, and unrelated work. Live game commands/spending, live-local migrations, service installation, enabling automation, new public tunnels, remote Git publication, and Linode deployment require separate user authorization. Continue all independent implementation and fixture verification if one of those boundaries blocks a particular check.

## 5. Deliver a reviewable result

Finish the working UI rather than another mockup or a plan alone. Update `docs/app-styling.md` to describe what is actually implemented and retain `docs/design-brief.md` as the design reference.

Save a concise implementation report at `docs/native-ship-ui-implementation.md` containing:

- The final capability-to-destination map and any intentional design departures.
- Changed files and the reason for material structural choices.
- Actual application screenshots with a tested local URL and the fixture/state each screenshot shows. Keep saved visual reports dark and omit credentials or private game data.
- Commands run, results, and evidence locations, including failures and their disposition.
- Remaining limitations, with local tests, real-browser verification, physical-phone verification, live gameplay, and deployment stated separately.

Before handoff, inspect the real artifact, run the affected workflows, and review the final diff for unrelated changes. Provide the local preview URL, the implementation report, and any concrete unresolved blocker. Do not claim completion from a build or prototype screenshot alone.
