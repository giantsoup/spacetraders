# iPhone app and Linode engine plan

Date: November 1, 2026.

## Goal

Create a personal iPhone app, distributed through TestFlight, that connects to our Laravel SpaceTraders engine on Linode. The app provides a mobile way to play manually and control automation. The engine continues approved work while the phone is locked, disconnected, or closed.

MOB01 now defines the first release scope and technical choices in [the iPhone release decision](adr/0001-first-iphone-release.md). These are design decisions for review, not an implemented mobile API or app. Linode remains the target host. Actual deployment, capacity, and hosted engine safeguards require separate verification.

## Agreed architecture

The iPhone app displays fleet state, collects instructions, presents approvals, and reports results. It connects to Laravel through an authenticated HTTPS connection. It does not need to run the automation loop in the background.

The Laravel engine owns gameplay rules, command execution, automation plans, credentials for the SpaceTraders API, and persistent activity records. Its scheduler and queue worker advance approved work on Linode. The mobile app uses the same gameplay services as the existing web interface.

SpaceTraders hosts the actual game universe and authoritative game state. Laravel synchronizes with that API and records what it observes. Hosting our engine does not make gameplay independent of SpaceTraders availability or rate limits.

Keep the gameplay logic in one place. The mobile interface will require its own development and releases, but it should not duplicate the engine's rules.

## First release experience

The smallest complete workflow uses an already connected agent and capable miner:

- Connect securely to the personal engine and select an agent and ship.
- View fleet status, cargo, credits, travel, cooldowns, and recent activity.
- Preview and approve orbit, dock, same-system CRUISE navigation, refuel, unsurveyed extraction, and cargo sale.
- Investigate Mining opportunities, stage one ready candidate, approve a versioned preview, inspect the trip, and record Stop.
- See stale data, connection problems, engine health, and actions that need review.
- Reconnect and recover the current state without losing track of submitted commands.

Use one extraction and one trip by default, with explicit reserve, spending, loss, and duration limits. Preserve the server's current ceilings. Missing equipment, prices, fuel, or a buyer produces a blocker. Provision agents and equipment through the web interface. Ship purchases, cargo purchases, Contracts, surveys, transfers, scouting, and trading automation remain outside this first mobile release.

Use the dark Horizon ship home, a report sheet, and pushed Actions, Mining, and Activity flows. Keep agent, account, and sign-out access in a secondary menu. The [release decision](adr/0001-first-iphone-release.md) specifies navigation, expired-work handling, recovery, and acceptance scenarios.

## Delivery phases

### 1. Confirm scope and current state

Review the engine's existing capabilities and deployment status. Choose the smallest complete mobile workflow and identify any missing backend support. Confirm what happens when approved work expires, blocks, or needs operator review.

The result is an agreed first release scope and a short list of technical decisions needed to begin.

### 2. Establish reliable Linode operation

Prepare or verify the Laravel engine on Linode, including private access, HTTPS, persistent storage, the scheduler, and the queue worker. Arrange automatic process recovery, backups, and a way to detect stalled execution. Check capacity alongside the box's existing workload.

If moving an existing agent from the Mac, plan a controlled handover of its data and credentials. Use one active engine for that agent to avoid competing executors.

The result is a reachable engine that can continue approved work independently of the phone and recover after service restarts.

### 3. Define and expose the mobile connection

Provide an authenticated API for the agreed mobile workflows. Reuse the existing gameplay services and ownership checks. Define how the app identifies requests, receives command status, refreshes observations, and handles failures.

Keep the API compatible with installed app versions during routine server updates. Use the versioned JSON and per-device Sanctum bearer-token design in [the release decision](adr/0001-first-iphone-release.md#authentication-and-device-access). The existing Inertia routes retain their session and CSRF boundaries.

The result is a verified connection that supports viewing state and controlling the existing engine.

### 4. Build the iPhone app

Build the SwiftUI client in `mobile/ios/` against the accepted mobile API. Include secure access, clear approval steps, visible command status, and recovery after app suspension or lost connectivity.

Make server acceptance and upstream completion distinguishable. Show when information was last refreshed, and display timestamps in the phone's local timezone while preserving UTC in stored and exchanged values.

The result is a usable app on a physical iPhone with all gameplay decisions and execution delegated to Laravel.

### 5. Verify and distribute privately

Exercise the complete mobile-to-engine workflow. Cover duplicate submissions, interrupted connections, expired access, stale previews, uncertain upstream results, and engine restarts. Confirm that opening both the web interface and the app does not bypass shared command controls.

Verify that approved automation continues with the phone locked or disconnected, within the run's configured limits. Confirm that stop requests and blocked states remain visible after reconnecting.

Prepare signing and a private TestFlight release. Confirm Apple's current distribution requirements and establish a repeatable process for replacement builds before beta builds expire.

The result is a personally installed TestFlight app, with evidence of the mobile workflows and server behavior it depends on.

## Operating principles

- Preserve explicit approvals, spending limits, and stop controls across both interfaces.
- Keep SpaceTraders credentials on the engine. Give the phone only the access it needs to our application.
- Persist command intent and status. Resolve uncertain outcomes before sending another command that could repeat a purchase or action.
- Treat loss of connectivity as a recoverable condition. Refresh current state before offering further actions.
- Preserve existing automation bounds. Longer or overnight runs require a separate decision about approval limits and safeguards.
- Keep operations proportionate to personal use, with one engine and a small, repeatable deployment process.

## First release decisions

The [MOB01 decision record](adr/0001-first-iphone-release.md) defines these choices and their release gates. This is document evidence, not an installed iPhone app.

| Area | Decision |
| --- | --- |
| Client | Native SwiftUI and Swift, iPhone on iOS 17.0 or later. Laravel and the JavaScript web stack stay unchanged. |
| Complete workflow | Manual mining preparation and cargo sale, then one investigated, staged, approved mining trip with inspect, Stop, and recovery. |
| Connection | Approved HTTPS engine origin. Per-device Sanctum token, 30-day expiry, Keychain storage, explicit sign-in and revocation. SpaceTraders credentials stay in Laravel. |
| API | Proposed `/api/mobile/v1` reads, previews, approvals, status, mining staging, and Stop reuse owner-scoped services. Durable request identity resolves lost responses. |
| Navigation | Horizon ship home, report sheet, focused Actions, Mining, and Activity pages, and a secondary account menu. No empty bottom tabs. |
| Refresh and offline | Five-second foreground reads of saved server state. Explicit upstream synchronization. Memory-only snapshots, read-only offline view, and no queued offline writes. |
| Compatibility | Additive v1 evolution, capability and plan-version checks, UTC exchange, and local display. Unknown states disable new gameplay approval. |
| Repository and release | Future `mobile/ios/` project in this repository. Xcode and `xcodebuild`; manual signed archive and private TestFlight upload after authorization. Monthly replacement target before 90-day beta expiry. |
| Run limits and web | Default one extraction and one trip. Keep 60-minute, 10-trip, and 10-extraction ceilings and all approved bounds. Web retains setup, full gameplay, and operator recovery. |

## Remaining release gates

MOB02 [#31](https://github.com/giantsoup/spacetraders/issues/31) owns API implementation, MOB03 [#33](https://github.com/giantsoup/spacetraders/issues/33) owns the client, and MOB04 [#35](https://github.com/giantsoup/spacetraders/issues/35) owns device and TestFlight acceptance. Hosting work must preserve engine-session invalidation and Stop while replacing the current macOS-only controls appropriately. None of those interfaces or outcomes exists because this design was written.

Taylor must provide or approve the production origin and controlled host handover, Apple team and bundle identity, signing access, distribution, and any live gameplay budgets. Confirm the intended iPhone supports iOS 17 before device acceptance. Linode capacity, database operation, supervision, backup restoration, and private access remain deployment gates. No purchases or longer runs are authorized.

## Completion criteria

The first release is complete when Taylor can install it through TestFlight, inspect the fleet, approve a manual action, and control an automation run. Approved work must continue on Linode when the phone disconnects. Reopening the app must recover current state and clearly show failures or actions awaiting review.

## Existing project context

The [hosting assessment](linode-hosting-research.md) describes the proposed Linode runtime and migration considerations. The [MVP implementation notes](mvp-implementation.md) describe the shared command path and existing execution safeguards. The [app styling notes](app-styling.md) describe the current web presentation.

This plan authorizes no implementation, deployment, credential changes, or live gameplay by itself.
