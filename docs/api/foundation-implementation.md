# Foundation implementation evidence

Implementation starts from planning head `98ff5d7` on the isolated `implement/base-api-foundation-20261101` worktree. Issue #86 and draft PR #87 remain open. This record reports synthetic verification separately from official API acceptance.

## FND-01 and FND-02

- All 26 GET operations have named native API library controls, full result inspection, observation times, individual refresh and manual pagination. Waypoint type and scalar/array trait filters are supported. These controls do not use the legacy forwarding form.
- Five dedicated ship-state reads and repair/scrap quotes have distinct operations. HTTP 204 cooldown has no mandatory JSON parser. Omitted raw expiration stays omitted; `readyAt` and `deadlineSource` explicitly identify a derived deadline. Construction flags survive normalization.
- Explicit PATCH navigation dispatch uses the saved agent token and only the approved flightMode field. Mode changes have their own preview. Remaining travel variants belong to FND-08.
- General manual Stop cancels preview/pending actions and preserves sending settlement. Reservation uses the same conditional database transition. Received write evidence is retained before subsequent bookkeeping. Fresh manual approvals reject changed navigation, fuel, cargo, equipment or observed ship offers.
- Baseline: 391 backend tests, 2244 assertions. Focused boundary/read/exploration suite: 65 tests, 371 assertions. Real file-backed workers passed duplicate delivery, full mining restart, killed-sender uncertainty and Stop persistence checks. See the adjacent evidence files.
- Shared T3 preview exercised all 26 named read choices through the built Vue form. Every result rendered and received focus. See [browser outcomes](evidence/fnd-reads-browser.json). The dark desktop screenshot was inspected. Fixture transport does not prove server mechanics.

## Pending gates

Official API mutation acceptance, module/faction inherited authentication clarification, remaining operation groups, per-variant live calibration, combined QA/security, mobile/keyboard cases, and final Sonnet 5.5 review remain pending. No full-foundation acceptance is awarded. Read quotes reserve no prices. No real credentials, live data, gameplay spending, shared site or live cutover were changed.

## FND-03 through FND-11

All 31 non-scrap domain command forms were exercised through the native Manual API destination, exact preview, explicit approval, real SQLite queue and fresh worker processes. Every action confirmed in the owned synthetic provider. [Journey and settlement records](evidence/fnd-manual-browser-worker.json) include actual action IDs and states. One fixture setup initially returned mixed-system waypoint lists; production rejected the mismatch. The provider's system filter was corrected and only the affected jump/warp/supply journeys repeated.

Implemented groups include general non-miner purchases and cargo loss, independent contracts and negotiation, repair and maintenance quotes, equipment multiplicity and full capacity refresh, signed survey variants, siphon/refine results, all four mode choices, omitted/capped/market/cargo refueling, independent scans/charting, cross-system gate/warp state and construction progress. Registration remains its distinct existing account-token operation.

The former #53 CRUISE-only branch `a1e5f6e` and #55 cargo-refuel branch `3d0bd12` were source-reviewed. The integrated code retains exact approvals, configuration provenance and the inherited whole-unit conversion checks. It adds omitted/capped/partial variants. Partial cargo rounding, equipment requirements absent from observations, refinery recipes, drive restrictions and non-CRUISE/zero-capacity/warp formulas remain explicit live gates. Operator fuel/time/cargo review thresholds do not cap the official server. The server's actual results remain authoritative; no formulas were fabricated from fixtures.

All returned documented action effects are retained in history. Received HTTP status and body hash are saved before response validation/bookkeeping, allowing malformed or interrupted success to remain uncertain without replay. Agent-scoped contract accept/fulfill need no ship. Unknown equipment fees use explicit local exposure and reserve checks, with post-send discrepancy review. Repairs replace the full returned ship and preserve non-repairable integrity. Construction contributions re-read demand and protect contract cargo.

The [390px Manual API screenshot](evidence/screenshots/manual-api-390-light-os.png) was inspected: readable single-column controls, no horizontal overflow, visible focus and a dark background with `prefers-color-scheme: light`. Tab moved focus to Done and Escape closed the dialog. These targeted checks do not establish full accessibility compliance. [Desktop library screenshot](evidence/screenshots/api-library-desktop.png) was also inspected.

Scrap dispatch and its disappearance/reconciliation cases are deliberately next. Final combined QA, security, platform/build checks and official-API acceptance remain pending.
