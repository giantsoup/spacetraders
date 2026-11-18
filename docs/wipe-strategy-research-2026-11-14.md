# SpaceTraders wipe strategy evidence

Research date: November 14, 2026 in America/Los_Angeles. Live captures occurred November 15 UTC. Deliverable: [dark HTML report](reports/wipe-strategy-2026-11-14.html).

This is research and planning. No gameplay, credential use, ship purchases, or production changes were performed. The previous session's GIANTSOUP balance and connection are historical observations, not a refreshed fleet assessment.

## Fresh public observations

Unauthenticated GET `https://api.spacetraders.io/v2`, captured 2026-11-15T01:27:43Z, returned v2.3.0, resetDate `2026-11-08`, and next reset `2026-11-15T13:00:00.000Z`, weekly. That is Sunday, November 15 at 5:00 AM PST. The payload's generic Saturday announcement disagrees with its explicit schedule. A schedule does not prove a completed reset.

Raw evidence is in [wipe-strategy-evidence-2026-11-14](reports/wipe-strategy-evidence-2026-11-14/). The served OpenAPI was captured at 01:27:44Z. The current error catalogue separately confirms 4242, ShipMissingMineralProcessorError. These captures document what was returned, not a gameplay acceptance test.

## Recent community sources

| Publication date | Source | Evidence and limit |
| --- | --- | --- |
| 2026-10-28, updated 2026-11-04 | [V-M-Pioneer-Trading arbitrage roadmap](https://github.com/V-M-Pioneer-Trading/meta/issues/124) | Open at access; body says unbuilt. Supports recent interest, not demonstrated profitability. Body and dates independently checked with gh. |
| 2026-07-19, closed 2026-11-04 | [V-M-Pioneer-Trading yield/price estimate](https://github.com/V-M-Pioneer-Trading/meta/issues/40) | Describes a flat revenue estimate that effectively favors nearby asteroids. Proposes measured yields times current prices. The issue is closed, so the historical diagnosis is not presented as a current bug. Eventual implementation and profit were not verified. Body and dates independently checked with gh. |
| 2026-06-08 | [protoLabsAI route change](https://github.com/protoLabsAI/spacetraders-plugin/commit/192ca039fc2566ac6a993f9c53b7591a1b17866b) | Actual implementation prefers export-to-import routes and ranks margin times source trade volume. Its assertions about spreads disappearing in two trades and permanent replenishment are unmeasured. It omits destination volume, fuel, and travel time. Treat as a candidate idea, not an optimizer or demonstrated best strategy. AI co-authorship disclosed in the commit. Commit and patch inspected with gh. |
| 2026-01-24 | [Pośpiech, starting-system visualization](https://www.pospiech.space/blog/spacetraders-4-visualizing-starting-system) | First-person client work supports initial mapping and reset-separated data. No profit claim. Date verified with datePublished and dateModified in the page metadata. |
| 2026-01-09, updated 2026-01-24 | [Pośpiech, game overview](https://www.pospiech.space/blog/spacetraders-3-game-overview) | Orientation on contracts, markets, and state. Not proof of optimal strategy. Dates verified in page metadata. Prefer the served API for exact operations. |
| 2025-04-21 | [Wigginton, client write-up](https://bwiggs.com/projects/spacetraders/) | Discusses scouts, full-cost accounting, and miner-to-hauler coordination. Losing contracts leading to later windfalls is an anecdote, not a generation guarantee. |

MCP web searches included SpaceTraders strategy in 2025 and 2026, mining, trading, changelog, and indexed Reddit discussions. Results often confused this API game with Palm Space Trader or Star Traders: Frontiers; those were excluded. No reliable public November 2026 consensus was found. Discord was not accessed. Search absence does not establish that relevant discussion does not exist.

## Primary sources

- [Public status](https://api.spacetraders.io/v2) and [reset guidance](https://spacetraders.io/server-resets).
- [Served OpenAPI](https://api.spacetraders.io/v2/documentation/json): market presence, MarketTradeGood.tradeVolume, surveys, transfer, contract payments and deadlines, refining, siphoning, charting, and construction. Survey-based extraction has a dedicated operation; the ordinary extraction survey property is deprecated. Transfers require the same waypoint and matching docking states. The documented refine conversion is 100 raw to 10 processed goods. Construction consumes supplied cargo without a documented direct payment in that operation.
- [Current error catalogue](https://api.spacetraders.io/v2/error-codes): missing mineral processor.
- [Market concepts](https://docs.spacetraders.io/game-concepts/markets): exporter/importer production and consumption, input constraints, and price presence. Full content was available in MCP search-indexed text; direct extraction sometimes exposed headings only. Future-labelled features were excluded from recommendations.
- [Extraction concepts](https://docs.spacetraders.io/game-concepts/extracting-resources): equipment and cooldowns. Older survey examples conflict with the dedicated served operation, so the report uses the latter.
- [Rate limits](https://docs.spacetraders.io/api-guide/rate-limits): 2 requests per second per IP/account; runtime headers and infrastructure backoff remain relevant.
- [Changelog](https://spacetraders.io/resources/changelog): latest visible dated entry March 23, 2025. Direct extraction gave the dated contents list, so no unobserved balance details are asserted.

All were accessed November 14 PST / November 15 UTC, 2026. Undated live documentation is labelled by access date, not presented as published in 2026.

## Recommendation and policy boundaries

Time-box discovery. Test a small export-to-import trade first when fresh prices, costs, and recovery allow it, using an existing command ship. Compare an existing capable miner through extraction, sale, return, and fuel restoration. Mining is the fallback if the trade is infeasible. Evaluate the actual procurement contract before acceptance. A profitable and feasible contract can take priority. No fleet purchase is required for tonight's practice.

Measure full-cycle net income, then expand the limiting activity. Hauling, survey support, trading, siphoning, refining, and exploration are conditional branches. Compare added fleet income with the best use of the same ships. Unsold inventory is not realized revenue, and an already received advance is not a new future payment.

The 20,000 CR provisional reserve, 10–15 minute discovery cap, limited trade exposure, 90-minute practice agenda, and payback within half of usable operating hours are planning policies. They are not game mechanics or demonstrated optimal thresholds. The calculator defaults are fictional, explicitly labelled, and do not claim current ship prices or profits.

## Local capability evidence

Production HEAD was read as `f214d87`. Development source inspected: README, routes/web.php, Dashboard.vue, ManualReadBrowser.vue, GuidedFlight.vue, AutomationConsole.vue. The app exposes Refresh observations, API library, System map, manual previews and approvals, Guided flight, and Mining/Contracts investigation and bounded automation. The report does not imply automatic trade-route or multi-ship hauling support. No new authenticated UI session was used.

A read-only SQLite connection also inspected selected stored ship snapshot fields for production agent 1. Both observations were dated 2026-11-13 00:57:45. GIANTSOUP-1 has a mining laser II, mineral processor, surveyor II, 40 cargo, empty inventory, and a saved full 400-unit tank. GIANTSOUP-2 is a satellite at X1-PZ83-H47 with zero cargo and zero fuel capacity. These observations make GIANTSOUP-1 a concrete candidate for the first test, but do not authorize assuming unchanged state or probe movement. No credential columns or upstream authenticated calls were used.

## Verification record

Initial report rendered with MCP html_preview at 900 and 390 pixels, with no console messages. Mobile table date columns initially wrapped poorly; tables were given a minimum width within labelled, keyboard-focusable scroll regions.

Headless Playwright opened the actual standalone file at 1280, 728, and 390 pixels. Checks found no document overflow, missing fragment links, duplicate h1, unlabelled numeric inputs, or runtime errors. Eight calculator scenarios covered positive payback, excessive payback, zero and negative added income, no time remaining, missing input, negative cost, and zero cost. Screenshots and machine-readable validation were saved outside the checkout at `<windows-user-profile>/AppData/Local/Temp/spacetraders-strategy-council-20261114`.

Council research and one artifact-review round are complete. Claude Opus 5.5 scored 8/10: content ready, phone gutters/background required. GPT Sol 6.1 scored 8/10: strategy sound, invalid calculator states retained a stale meter because author display CSS overrode hidden. Muse Wonder 1.0 scored 7/10: strategy passes, pending council status required final results. All reviewers rendered the actual file and inspected screenshots. Parent applied the small corrections, widened midgame labels, clarified VOID as faction, and updated this receipt. Original scores are preserved; no second score or unqualified independent approval of the edited artifact is implied. Final verification checks actual meter invisibility, mobile gutters, no document overflow, fragment targets, calculator behavior, and rendered output. No gameplay or deployment occurred.

## Additional October–November 2026 evidence and adjudication

Verified with gh: [SPECTER PR 219](https://github.com/Gemberkoekje/projects/pull/219), created October 31; [PR 226](https://github.com/Gemberkoekje/projects/pull/226), November 8; [PR 239](https://github.com/Gemberkoekje/projects/pull/239), November 9. They describe stale market coverage, a gate blocked by the bot’s own material consumption and purchase ordering, and a later decision to favor input feeding only when supply binds. These are one operator’s AI-assisted accounts, not controlled results or consensus.

[PLAN.md pinned at f1fb192705ab2a2847ccc18faca8cbb6873e3492](https://github.com/Gemberkoekje/projects/blob/f1fb192705ab2a2847ccc18faca8cbb6873e3492/SpaceTraders/PLAN.md) was inspected. Its October 5 entry contrasts about 83,000/hour realized SPECTER-1 trading with a stated steady mining baseline of 12,000–20,000/hour. A later entry reports a 278,652 CR hauler, 37,000/hour, 7.5-hour payback in the November 1 run. Original telemetry and comparable accounting were not audited. These figures motivate a trade-first experiment; they do not establish current quotes or GIANTSOUP returns.

Council adjudication: Claude favored aggressive trading, broad early probe coverage and early gate completion. Sol and Muse emphasized observed complete-cycle returns and conditional spending. Adopt trading as first hypothesis, useful early price coverage, measured mining fallback, and conditional gate spending. Reject categorical bans on mining, copying the probe fleet, a universal 24-hour gate target, and leader cash-balance deltas as income. Claude corrected its initial short-window extrapolation; even corrected cash deltas cannot prove compounding or operating earnings and are omitted from the report.


Final verification: actual standalone file passed at 1280, 728, 390 and 320 px, with 16 px side gutters, explicit dark background, no document overflow or runtime errors, valid fragment links and labelled numeric inputs. Twelve calculator scenarios passed, including actual meter invisibility for invalid inputs and reappearance after valid input. Final screenshots were inspected for phone top, midgame labels, zero-income calculator and review receipt. Inline rendering uses a presentation copy with outer background and gutters removed for the host viewer; the standalone report retains them.

### Third-party source capture checksums

SHA-256 of the original local captures

- specter-plan.md 3c1a08a1bb1ae20031c087ffb19f0b3dc05360957a2a9356163091e1edef4e10
- specter-pr-219.json a45229b4fe36d94c243fddd6f513d208e53281d71ac5e6e6598046b2e140f449
- specter-pr-226.json d559e546982827f764479bedf120b3c82a5a259783a1e140660ff0d02fcb49f1
- specter-pr-239.json c66315a0941f7fbb81e37b28aa750e2e73d43f62aabdaa19854401a452ac36ed
