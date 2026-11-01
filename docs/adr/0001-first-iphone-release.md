# First iPhone release

Date: November 1, 2026. Status: selected design, awaiting Taylor's review in [PR #30](https://github.com/giantsoup/spacetraders/pull/30). Scope: [MOB01 #29](https://github.com/giantsoup/spacetraders/issues/29), milestone M06. There are no hard prerequisites for this design issue.

This record settles the choices left open by [the iPhone plan](../ios-app-plan.md). It specifies future work. No mobile API, Xcode project, deployment, Apple account, or TestFlight build is created by MOB01.

## Context and capability review

Inspected baseline `f58177bab2cba7d5af69c8443fb6c72f26caa2ae`, the designated PR head, against the issue's source references at `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb`. Current source takes precedence over older implementation reports.

| Existing capability | Evidence at this baseline | Consequence for release 1 |
| --- | --- | --- |
| Laravel 13, Sanctum 4, Inertia 3, Vue 3, Pinia 4, Tailwind 4 | [Composer](../../composer.json), [frontend manifest](../../package.json), [User](../../app/Models/User.php) | Add a native presentation client. Keep the web runtime and gameplay services. |
| Session-authenticated gameplay, one API user read | [Web routes](../../routes/web.php), [API routes](../../routes/api.php), [GameplayRequest](../../app/Http/Requests/GameplayRequest.php) | Mobile endpoints and device access management are missing. Do not call web routes as a bearer-token shortcut. |
| Owner-scoped, encrypted agent credential and reset archive | [Agent](../../app/Models/Agent.php), [AgentSynchronizer](../../app/Gameplay/AgentSynchronizer.php) | Select existing owned agents. Never export agent or account tokens to the phone. |
| Preview, durable intent, approval, execution, reconciliation | [ShipCommandExecutor](../../app/Gameplay/ShipCommandExecutor.php), [CommandRules](../../app/Gameplay/CommandRules.php) | Every mobile mutation uses this executor and its agent lock. |
| Investigate, classify, stage, versioned preview, bounded mining | [LocalAutomationController](../../app/Http/Controllers/LocalAutomationController.php), [AutomationStaging](../../app/Gameplay/AutomationStaging.php), [AutomationRunner](../../app/Gameplay/AutomationRunner.php) | Use staged plan version 2 for Mining, not the historical direct mining shortcut. |
| Local engine gate, session invalidation, heartbeat and process checks | [EngineGate](../../app/Gameplay/EngineGate.php), [LocalEngineManager](../../app/Gameplay/LocalEngineManager.php) | Current control requires macOS, local mode, operator identity, and loopback. It is not a deployable Linode control API. |
| Saved dashboard state, recent actions, runs, observations, runtime health | [AgentDashboardController](../../app/Http/Controllers/AgentDashboardController.php) | Extract explicit mobile response fields. Add direct status lookup and pagination so old unresolved work never disappears behind the 40-action or 10-run windows. |

The source supports more commands than release 1 needs. It does not contain a mobile project. Existing regression targets include [GameplayTest](../../tests/Feature/GameplayTest.php), [LocalAutomationTest](../../tests/Feature/LocalAutomationTest.php), [AgentArchiveTest](../../tests/Feature/AgentArchiveTest.php), and [UpgradeCompatibilityTest](../../tests/Feature/UpgradeCompatibilityTest.php). Test presence establishes coverage intent, not a new passing run.

## The smallest complete workflow

Release 1 uses one already imported or registered agent and an existing, capable CRUISE miner. The web interface retains registration, credential repair, equipment/setup purchases, Contracts, and the broader command set. A missing prerequisite is a valid visible outcome, never permission to buy a ship or invent prices.

1. Sign in to the approved personal engine and select an owned current agent, then a ship. Archived agents show history and a reset notice with gameplay disabled.
2. Inspect credits, fuel, cargo, nav, cooldown, recent activity, observation age, and engine health. Synchronize explicitly before preparing gameplay.
3. Complete manual preparation using separate previews and approvals for `orbit`, `dock`, `navigate`, `refuel`, `mine`, and `sell`. Navigation stays same-system CRUISE. Extraction is unsurveyed. Each sale names the commodity, units, minimum total, and reserve. Each refuel names units, maximum total, and reserve. Review queued, sending, and confirmed outcomes before the next action.
4. Open Mining. Investigate one owned observed system, advance bounded reads, and choose a `ready` candidate with a capable miner, observed buyer and prices, and a fuel-safe route. Partial, stale, or blocked investigations show their reasons. They cannot be staged.
5. Stage limits, then preview the exact draft revision as plan version 2. Show ordered actions, evidence age, expiry, reserve, spending ceiling, cash-loss ceiling, minutes, trips, extraction count, and the quote warning. Default to one trip and one extraction. Require Taylor's explicit numeric bounds. Do not prefill invented credit budgets.
6. Approve that preview once. Laravel checks current state, draft revision, fingerprint, reset, engine session, and ownership. Inspect progress through travel, extraction, fresh-quote sale batches, and bounded refuel. Completion requires server outcomes. Show retained cargo and observed cash contribution separately from profit estimates.
7. Record Stop at any time while connected. Show `stopping` until reserved work settles and Laravel reports `stopped`. Reopen after disconnection to recover the same run, intent, Stop, and blocker records. The phone performs no background gameplay.

Manual preparation and the automated trip are one complete workflow. The six manual commands are the exact first release command set. Ship and cargo purchases, survey commands, transfers, Contracts, automated scouting/trading, push notifications, widgets, and overnight runs are deferred. Existing data remains visible even when the first mobile release cannot initiate its operation.

### Bounds remain in Laravel

[GameplayRequest](../../app/Http/Requests/GameplayRequest.php), [LocalAutomationRequest](../../app/Http/Requests/LocalAutomationRequest.php), and [gameplay configuration](../../config/gameplay.php) remain authoritative. Current previews expire after 120 seconds. Evidence freshness is 180 seconds where the services require it. Requests allow 1–60 minutes, 1–10 trips, and 1–10 extractions per load, with explicit headroom and site time for longer loads. Site time is at most 900 seconds. Preserve these ceilings even if release 1 offers fewer choices.

The staged request also carries `delivery_limit` and `purchase_limit`; use the existing server schema even though release 1 selects Mining. Do not turn those fields into purchase or Contracts UI. Preserve integer amounts, server-enforced reserve, remaining spend and loss checks, attributable cargo, zero-yield blocking, and the stop after nonpositive observed cycle contribution. An unknown buyer retains cargo and blocks work.

SpaceTraders has no guaranteed execution-price ceiling in this command path. Show the server's warning in approvals. A confirmed transaction outside its local quote bound stays confirmed with a review flag and blocks further work. Never claim a hard purchase guarantee or projected mining profit.

### Recovery and blocked work

| Server or connection state | Mobile behavior | Next permitted step |
| --- | --- | --- |
| `preview`, before `expires_at` | Show the exact limits and server deadline. | Explicit approval after server validation. |
| Expired manual preview | Show Expired even if the stored enum remains `preview`; this is a display condition. | Request a fresh preview and approve separately. Never extend the old deadline. |
| Expired or invalidated run preview | Show the server reason. A rejected start can leave a stopped run record. | Investigate again if evidence is stale, stage current limits, and preview again. |
| `pending` or `sending` action | Show Queued or Sending, distinct from Confirmed. | Read that action's status. Do not create a replacement action. |
| Lost mobile response | Retain the request identity as Submission outcome unknown. | Resolve through the server request record before permitting another gameplay write. |
| `uncertain` action or `review_required` | Keep the warning on Home, Activity, and Mining. Block new gameplay writes; keep Stop and reconciliation available. | Reconcile fresh evidence. If still unresolved, use explicit operator review in web. Never replay the intent. |
| `acknowledged` unresolved outcome | Show Unresolved adjustment acknowledged. Do not label it confirmed. | A new preview only after Laravel clears the ownership and review guards. |
| `waiting` run | Show `next_action_at` and the server's reason. | Read state. An elapsed phone countdown authorizes nothing. |
| `blocked` run | Show `blocked_reason`, retained cargo, current limits, and Stop. | Stop and settle, resolve prerequisites or review in web, then create a new preview. No automatic resume. |
| `stopping` run | Show Stop requested; reserved upstream work may still complete. | Keep reading until stopped or review is required. Stop cannot cancel a sending request. |
| Run duration reached | Show server `stopped` and duration reason when observed. | New explicitly approved preview. No extension, rollover, or automatic repeat. |
| `completed`, `failed`, or `rejected` | Keep the actual state, outcome, and reason visible. | Inspect and create a new preview only if the server permits it. |
| Engine unhealthy, restarted, or gate closed | Disable new approvals and show health as unknown or unhealthy. | Restore operation through approved operator tooling; engine-session change requires new previews. Keep uncertain actions intact. |
| Reset or archived agent | Discard active selection and old previews for gameplay; retain history. | Register/import current credentials through web, sync, and start with new previews. Never auto-register. |
| Offline or access expired/revoked | Show the last in-memory state and its timestamp as stale. Disable all server actions, including Stop. | Reconnect or sign in, resolve requests, and reload current state. Explain that offline Stop has not reached Laravel. |

An online Stop persists even if workers are offline. Losing phone access does not revoke existing run approval. Revocation and Stop are separate actions. An unresolved operation can prevent takeover after Stop; preserve that warning rather than promising immediate manual control.

## Client framework and navigation

Choose native SwiftUI, Swift 6 language mode, Foundation `URLSession`, `Codable`, and Observation. Set the deployment target to iOS 17.0, iPhone only for release 1. Apple documents SwiftUI Observation from iOS 17 in [Managing model data](https://developer.apple.com/documentation/swiftui/managing-model-data-in-your-app). iPad-specific layouts, Android, Catalyst, and third-party mobile frameworks are outside scope.

SwiftUI avoids another JavaScript bridge, native dependency manager, or web-session wrapper for a small personal iPhone client. Reusing Vue through a wrapper would reduce view rewriting, but would add cookie/bridge handling while still needing release signing and native lifecycle recovery. The web interface remains useful independently. The cost of this choice is a second presentation layer and Swift DTOs; it does not justify moving any gameplay planning to Swift.

Use the approved [Horizon design and interaction rules](../design-brief.md). A `NavigationStack` starts on the selected ship Home. The identity control opens agent/ship selection. Ship art, Report, Fuel, and Cargo open one report sheet at the relevant section. Actions opens a pushed command list, then a parameter form and approval screen. Mining opens investigation, candidate, limits, preview, and active-run pages. Activity opens history and action details, including Reconcile. Keep Stop reachable from Home and the active run, and review warnings visible across those destinations.

The secondary menu holds connection, device access, agent choice, web operator recovery, and sign out. Back returns to the previous step without submitting. Cancel dismisses a preview without approval. Do not put credentials or mutation arguments in web links. No bottom tab bar until separate useful destinations justify it.

Force the product's dark theme. Support Dynamic Type, VoiceOver, reduced motion, safe areas, and 44-point touch targets. Ship artwork and animation are decorative. At arrival or cooldown zero, show Checking until Laravel confirms readiness. Keep UTC instants in DTOs and request tracking; format them in the phone's detected timezone and display its name.

## Authentication and device access

Choose the existing Sanctum package's per-device personal access tokens, not OAuth infrastructure or copied browser cookies. Laravel's [mobile authentication](https://laravel.com/docs/13.x/sanctum#mobile-application-authentication), [expiry](https://laravel.com/docs/13.x/sanctum#token-expiration), and [revocation](https://laravel.com/docs/13.x/sanctum#revoking-tokens) support this approach. The following policies are project decisions, not Sanctum defaults.

- Enter the existing Laravel account identity, password, and a recognizable device name at sign-in. Use a throttled HTTPS token endpoint with generic authentication errors. Keep the password transient and redact request logs. No sign-up, SpaceTraders token input, or credential replacement in mobile release 1.
- Issue a device token with `gameplay:read` and `gameplay:write` abilities and explicit 30-day expiry. Return its expiry and token identifier. Enforce abilities and owner scoping independently. New token issuance never renews old gameplay approvals.
- Store only the application token in Keychain using `kSecAttrAccessibleWhenUnlockedThisDeviceOnly`, with no synchronization. Apple's [Keychain accessibility documentation](https://developer.apple.com/documentation/security/ksecattraccessiblewhenunlockedthisdeviceonly) describes unlock-only access and device-local migration behavior. Never store the password, agent token, or account token there.
- Use `Authorization: Bearer` for the mobile API. Require an actual personal access token on mobile routes; do not accept cookie-session fallback. Existing browser routes retain session auth, CSRF, and password confirmation. Token abilities never replace per-agent and per-child ownership checks.
- Provide online sign out that revokes the current token. Provide a CSRF-protected, password-confirmed device list/revoke screen in web for a lost phone, plus revoke-all on account deletion or password change. These controls are new MOB02 work. Do not expose another owner's token metadata.
- On offline sign out, remove local access and warn that server revocation is pending operator action through web. Do not claim revocation succeeded. On 401, purge the token and cached game state, sign in again, and resolve pending request identities before enabling actions. There is no refresh token or silent token renewal in release 1.
- Revoking device access prevents new requests. It does not undo an approved run or an in-flight upstream action. Offer web Stop independently and explain the distinction before revocation.

Use one Taylor-approved HTTPS origin, system certificate validation, and ordinary application authentication. Do not provision a VPN, gateway account, or new service for this design. Pin the allowed origin in release configuration, and never forward credentials across a cross-origin redirect. Do not add production ATS exceptions or certificate pinning that blocks routine certificate rotation. Fixture HTTP exceptions, if needed, belong only in test builds. The final DNS name, private-access hardening, and controlled Mac-to-Linode authority handover require operations approval.

## Proposed versioned API

All paths below are future `/api/mobile/v1` JSON endpoints. They are selected responsibilities for MOB02, not callable interfaces on this baseline. MOB02 must verify its own hard prerequisites and publish tested schemas before MOB03 uses them.

| Method and path below the prefix | Responsibility and existing service |
| --- | --- |
| `POST /auth/token` | Authenticate existing Laravel account and issue one device token. Only unauthenticated endpoint. |
| `DELETE /auth/token` | Revoke the token authenticating this request. |
| `GET /bootstrap` | Supported API/client versions, permitted commands/strategies, plan versions, feature flags, UTC server time, token expiry, engine health and its observation time. No process controls or private configuration. |
| `GET /agents` | Owned current and archived summaries, explicit reset identity. No credentials. |
| `GET /agents/{agent}/state` | Saved fleet, observations and freshness, credits, bounded recent activity, runs, review flags, and engine health. No upstream synchronization. |
| `POST /agents/{agent}/sync` | Explicit bounded `AgentSynchronizer` refresh through the executor lock. |
| `GET /agents/{agent}/actions` and `GET /agents/{agent}/runs` | Cursor-paginated saved history. |
| `GET /agents/{agent}/actions/{action}` and `GET /agents/{agent}/runs/{run}` | Exact persisted status, limits, deadlines, Stop, outcome, review and blocker details. |
| `GET /agents/{agent}/requests/{request_id}` | Resolve a mobile submission to its action, run, investigation or draft, or to its durable rejection. |
| `POST /agents/{agent}/commands/preview` | Allow the six selected manual commands only. Map `request_id` to the existing preview `key`; use `ShipCommandExecutor::preview`. |
| `POST /agents/{agent}/commands/{action}/approve` and `POST /agents/{agent}/commands/{action}/reconcile` | Approve or inspect the exact owned intent through `ShipCommandExecutor`. No arbitrary forwarder. |
| `POST /agents/{agent}/investigations` | Create a Mining-only investigation in one owned observed system through `OpportunityInvestigator`. |
| `GET /agents/{agent}/investigations/{investigation}` | Read progress, evidence age, candidates, prerequisites, and classification. |
| `POST /agents/{agent}/investigations/{investigation}/advance` and `POST /agents/{agent}/investigations/{investigation}/cancel` | Bounded observation progress or cancellation; no travel approval. |
| `POST /agents/{agent}/drafts`, `POST /agents/{agent}/drafts/{draft}/update`, and `GET /agents/{agent}/drafts/{draft}` | Stage a ready candidate, update integer limits at an exact revision, and retrieve it through `AutomationStaging`. |
| `POST /agents/{agent}/drafts/{draft}/preview` | `AutomationRunner::previewDraft` at the exact draft revision, retaining plan version 2, fingerprint, reset, and engine session. |
| `POST /agents/{agent}/runs/{run}/start` and `POST /agents/{agent}/runs/{run}/stop` | Start that preview once or persist Stop through `AutomationRunner`. |

No direct `/runs/preview` mining endpoint is included. No mobile process start, shutdown, force-stop, arbitrary upstream forwarding, review acknowledgement, or balance-adjustment endpoint is included. Web remains the operator recovery path, including the separate acknowledged unresolved adjustment.

Use explicit allowlisted DTOs, never raw model dumps. Return `data` and `meta` with UTC `server_time`, reset identity, and observation timestamps where relevant. Credits, prices, units, and limits are integers. Missing observations remain null or Unknown. Do not include tokens, email, raw upstream request headers, private paths, PIDs, or exception traces in gameplay responses.

Mutation responses return request identity, resource type/ID, current state, and relevant deadline. HTTP 202 means Laravel accepted or returned the local record; only a `confirmed` action establishes an upstream-confirmed outcome. A run's `completed` state describes its server-verified plan result. Reads return 200. Use 401 for expired/revoked access, 403 for denied ability, 404 for foreign or absent resources, 409 for request-identity or revision conflicts, 422 for validation/prerequisite/expiry rejection, 429 with retry guidance for throttling, and 503 for unavailable service. Include a stable error code and safe message. An upstream uncertainty is persisted and read as `uncertain`, never flattened into a generic retryable HTTP error.

### Request identity and duplicate safety

Before any authenticated mobile POST, persist a UUID plus origin, owner, agent, reset, operation, target resource, and eventual server ID. Persist no password, token, command arguments, cached approval, or unsent action. This is an outcome tracker, not an offline queue. Require one `request_id` per logical submission. Disable transport-level automatic write retries.

MOB02 must reserve request identity durably before dispatch and map it atomically to the existing intent or operation result. Scope uniqueness to owner, agent, reset, and UUID across devices. Check operation and normalized payload hashes. Duplicate matching identity returns the same result without repeating work; conflicting payload returns 409. For manual preview, preserve the executor's existing key and comparison rules. Approval and start additionally target the same stored action/run, whose existing guards remain authoritative. Do not add a second gameplay executor.

A response lost after Laravel accepted approval is different from an uncertain SpaceTraders write. Resolve the former through `/requests/{request_id}` and direct status reads. Reconcile the latter through the existing evidence path. If request lookup is unavailable or absent, keep Submission outcome unknown and disable new gameplay writes until the server can establish that no dispatch happened or an operator resolves it. Never create a new UUID to bypass an unknown result. A new sign-in or device token must still be able to resolve the same owner's retained identities.

Stop is idempotent for the same run and stays available as a safety control even while another submission is unknown, provided the phone is online and authenticated. Retain its request identity too. Repeated Stop cannot start or replay work. Reads and explicit reconciliation remain available while ordinary gameplay approvals are blocked.

## Refresh, compatibility, and cached state

Choose foreground polling rather than push or a socket service. Read saved Laravel state every five seconds while the relevant screen is visible. Use one in-flight poll, stop on suspension, and back off on failure up to 60 seconds, honoring longer `Retry-After` values. Pull to refresh explicitly synchronizes upstream; ordinary state reads never fan out to SpaceTraders. Do not infer upstream observation freshness from HTTP success.

On foreground/resume or reconnect, read bootstrap and current state, resolve retained requests, and reload exact active action/run details before enabling approvals. Require server validation for every preview/approval regardless of local age. Show observation time separately from state-read time, health-heartbeat age, and phone connectivity. Stale worker/scheduler health disables new approval but leaves authenticated Stop available. Do not make health look healthy merely because Laravel answers HTTP.

Keep game snapshots only in memory in release 1. When connectivity drops, the current process can show that snapshot with an Offline label and its actual observation time. After process termination, show an explicit no-cached-state view until connected. Persist only approved engine identity, selection scoped to owner/agent/reset, and unresolved request identifiers in protected local storage. Exclude that metadata from backup. Clear game state on sign out, owner/origin changes, revocation, or reset. Never put authenticated responses in URL caches or a disk-backed offline store; use an ephemeral network session and server `Cache-Control: no-store`.

Keep `/v1` additive for installed clients. Ignore new optional fields. Preserve existing fields, meanings, and state strings. Bootstrap advertises the exact supported plan versions and mobile operations. Unknown states or plan versions display a safe unsupported state and disable new gameplay approval; preserve safe reads and Stop if bootstrap still advertises them. A breaking change requires `/v2` and a migration decision before `/v1` retirement, rather than silently reinterpreting approvals. Maintain `/v1` through the active TestFlight build lifetime and replacement window. A safety-critical minimum-client requirement can block gameplay, with an explicit update message and reachable web Stop/recovery. It must not silently replay or discard existing work.

## Repository, signing, and replacement builds

Choose one future `mobile/ios/SpaceTraders.xcodeproj` in this repository with a shared `SpaceTraders` scheme. Keep app sources, native unit/UI tests, fixture response samples, and an unsigned release configuration template there. Do not create the project in MOB01. Laravel routes, services, and tests stay in their current locations. `docs/adr/` holds this decision and `docs/issue-29/` holds its evidence. No new runtime dependencies are needed for the design.

Use Xcode and `xcodebuild` directly, without Fastlane or paid build services for release 1. Pin the exact stable Xcode/Swift/SDK versions and simulator runtime in the eventual build record. Run native tests on iOS 17 and the current supported iOS runtime, plus a physical iPhone fixture journey. Apple's [Xcode compatibility matrix](https://developer.apple.com/support/xcode/) and [upload requirements](https://developer.apple.com/help/app-store-connect/manage-builds/upload-builds) change independently of the deployment target. Recheck them at signing time. iOS 17 is a product minimum, not a claim that an old SDK remains uploadable.

After separate authorization, use Taylor's approved Developer Program team and bundle ID, Xcode automatic signing, a local Release archive, and Xcode Organizer upload to App Store Connect. Membership enables TestFlight distribution according to [Apple's program description](https://developer.apple.com/programs/). Do not enroll, buy membership, create signing identities, or upload from this issue. Keep signing keys, profiles, account credentials, and any future App Store Connect API key out of Git. Start with Taylor as an internal tester if that role is available. External testing adds Apple's review requirements.

Record source SHA, marketing version, monotonically increasing build number, Xcode/SDK versions, archive checksum, environment, test evidence, compatibility versions, and TestFlight expiry for every release. Reproducible here means repeatable inputs, commands, and attributable output, not byte-identical signed archives. Plan a monthly replacement build with an explicit reminder 30 days before expiry; this is a cadence decision, not a scheduled job created here. Apple limits beta availability to 90 days in its [TestFlight overview](https://developer.apple.com/help/app-store-connect/test-a-beta-version/testflight-overview/). No remote code updates or background client execution are needed. If a build expires, web remains the Stop and recovery interface.

## Acceptance and execution gates

MOB01's design acceptance is covered by the capability table, complete workflow/recovery matrix, technical choices, and unchanged gameplay constraints above. Taylor's acceptance of these decisions remains pending review. The following are future release gates, not reasons to claim the design implemented their dependencies.

| Owner | Required evidence before advancing |
| --- | --- |
| Operations and [MOB02 #31](https://github.com/giantsoup/spacetraders/issues/31) prerequisites | Accept the issue's declared prerequisites before implementation. Prove HTTPS/private access, one engine authority, durable storage/backup restore, healthy supervised worker/scheduler, and engine-session invalidation on the target platform. Replace macOS-only control safely without disabling the gate. |
| MOB02 | Test actual bearer tokens, abilities, expiry, single/all-device revocation, CSRF on web, foreign owner/child IDs, credential omission, duplicate identities and conflicting payloads, lost approval/start responses, stopped/expired drafts, reset, uncertain writes, and concurrent web/mobile locking. Publish fixtures and JSON schemas after tests pass. |
| [MOB03 #33](https://github.com/giantsoup/spacetraders/issues/33) | Use accepted MOB02 schemas. Verify Home/report/actions/mining/activity, dark mode under light OS, enlarged text, VoiceOver, local time, in-memory stale state, no-cache restart, offline Stop wording, access expiry, and retained request recovery on a physical iPhone against owned fixtures. |
| [MOB04 #35](https://github.com/giantsoup/spacetraders/issues/35) | Prove a full manual action and versioned mining trip, expiry/blocker/uncertainty recovery, concurrent web/mobile controls, engine restart, offline-worker Stop, and continued bounded execution while the phone locks/disconnects. Use owned deterministic fixtures first. Publish actual build and physical-device evidence after authorized TestFlight distribution. |

Only these inputs require Taylor before external execution: the production origin/host access and authority handover, approval of any hosting expense, Apple team/bundle identity and signing access, distribution authorization, a compatible physical iPhone, and separate live gameplay environment and numeric budget approvals. iOS 17 compatibility must be checked against the intended device before mobile acceptance; the device was not inspected here. This design authorizes none of those operations.

Roll back the design by reverting its documentation commit before implementation. Later framework or authentication changes need a superseding decision and compatibility review once clients or tokens exist. The highest remaining risks are hosted engine lifecycle parity, uncertain-response recovery across devices, and keeping older TestFlight clients compatible. No dependency upgrade, gameplay limit change, live migration, purchase, or deployment is included.
