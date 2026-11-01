# iPhone app and Linode engine plan

Date: November 1, 2026.

## Goal

Create a personal iPhone app, distributed through TestFlight, that connects to our Laravel SpaceTraders engine on Linode. The app provides a mobile way to play manually and control automation. The engine continues approved work while the phone is locked, disconnected, or closed.

This is a general plan. Frameworks, API design, hosting configuration, release tooling, estimates, and detailed screen designs remain open. Linode is the target host. The actual deployment and available capacity need verification before implementation.

## Agreed architecture

The iPhone app displays fleet state, collects instructions, presents approvals, and reports results. It connects to Laravel through an authenticated HTTPS connection. It does not need to run the automation loop in the background.

The Laravel engine owns gameplay rules, command execution, automation plans, credentials for the SpaceTraders API, and persistent activity records. Its scheduler and queue worker advance approved work on Linode. The mobile app uses the same gameplay services as the existing web interface.

SpaceTraders hosts the actual game universe and authoritative game state. Laravel synchronizes with that API and records what it observes. Hosting our engine does not make gameplay independent of SpaceTraders availability or rate limits.

Keep the gameplay logic in one place. The mobile interface will require its own development and releases, but it should not duplicate the engine's rules.

## First release experience

Keep the first release focused on the existing gameplay capabilities:

- Connect securely to the personal engine and select an agent and ship.
- View fleet status, cargo, credits, travel, cooldowns, and recent activity.
- Preview and approve supported manual commands and purchases.
- Preview, start, inspect, and stop supported automation runs.
- See stale data, connection problems, engine health, and actions that need review.
- Reconnect and recover the current state without losing track of submitted commands.

Use a dark interface with readable controls sized for touch. Adapt the existing SpaceTraders visual style for the phone. Detailed layouts and navigation will be designed later.

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

Keep the API compatible with installed app versions during routine server updates. Choose the authentication, API format, and update mechanism during detailed design.

The result is a verified connection that supports viewing state and controlling the existing engine.

### 4. Build the iPhone app

Choose the mobile framework and project structure, then implement the first release workflows. Include secure access, clear approval steps, visible command status, and recovery after app suspension or lost connectivity.

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

## Decisions for later

The detailed design will settle:

- Native framework, minimum iOS version, and support for other devices.
- Screen layouts, navigation, and the exact first release command set.
- Authentication, device access revocation, and network access to Linode.
- API endpoints, compatibility policy, and how the app receives updates.
- Notifications, cached views, and behavior during extended disconnection.
- Linode sizing, database choice, process management, backups, and monitoring.
- Repository organization, build automation, Apple account setup, and release cadence.
- The ongoing role of the web interface and any changes to automation duration limits.

## Completion criteria

The first release is complete when Taylor can install it through TestFlight, inspect the fleet, approve a manual action, and control an automation run. Approved work must continue on Linode when the phone disconnects. Reopening the app must recover current state and clearly show failures or actions awaiting review.

## Existing project context

The [hosting assessment](linode-hosting-research.md) describes the proposed Linode runtime and migration considerations. The [MVP implementation notes](mvp-implementation.md) describe the shared command path and existing execution safeguards. The [app styling notes](app-styling.md) describe the current web presentation.

This plan authorizes no implementation, deployment, credential changes, or live gameplay by itself.
