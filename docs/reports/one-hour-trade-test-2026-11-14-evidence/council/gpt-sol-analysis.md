# GPT Sol independent audit: GIANTSOUP experiment and campaign changes

## Verdict

The cash claim checks out. Unique confirmed receipts reconcile **175,000 CR to 845,528 CR at the hour boundary**, then **845,384 CR after the home refuel**, with **zero unexplained difference**. Seven cargo lots were completely sold: 117 SHIP_PARTS and 70 SHIP_PLATING. No ship or equipment purchases appear in the receipts.

The transferable result is a method: test small, measure settlement, refresh both ends, bound inventory, and change goods/buyers as economics deteriorate. This was a **late-wipe hour**, not an opening-hour benchmark. The agent already had a command ship, a probe stationed at a buyer, and market intelligence.

Two draft correctness issues precede tuning: its positive conservative-margin condition conflicts with the code's negative unsold-cargo downside estimate; its active-run early return can strand freshness-blocked cargo. Do not copy the manual run's six-figure exposure under a 25,000 CR campaign loss cap.

This is analysis only. No gameplay, SpaceTraders API calls, helper-script execution, credential access, development edits or production changes occurred. The only authored file is this report. Recommendations are not gameplay authorization.

## 1. Method and time basis

LIVE/ = C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/

DEV/ = C:/Users/taylo/Projects/spacetraders/

I read the requested final report, accounting/reconciliation files, decisions and baseline/cutoff/final checkpoints first. I then independently traversed the **153 JSON artifacts**. **1,727 repeated action appearances** resolve to **102 distinct confirmed actions** when keyed by action ID within this agent/reset. The latest recorded version was selected; all selected outcomes have response_validated=true. Only confirmed credit deltas/response transactions count. Previews and starts do not prove execution.

Counts: 14 buys, 33 sells, 6 refuels, 17 navigations, 16 docks, 16 orbits. The **53 monetary transaction IDs** independently match accounting-summary.json. Cargo was joined across automated and manual actions, not declared lost because a run stopped.

Times below are **November 15, 2026 UTC**; subtract eight hours for **November 14 PST**. Measurement: **02:03:00–03:03:00 UTC / 18:03–19:03 PST**. Earlier saved status schedules the next reset at 2026-11-15T13:00Z / 05:00 PST. A schedule is not proof of a completed reset.

## 2. Reconciled accounting

| Item | Exact hour | Fully settled | Evidence |
| --- | ---: | ---: | --- |
| Starting cash | 175,000 | 175,000 | baseline.json captured 02:02:25; agent observed 02:00:52 |
| Cargo purchases | -753,845 | -753,845 | 14 confirmed buys |
| Cargo sales | +1,425,093 | +1,425,093 | 33 sells; final #101 at 03:01:44.361 |
| Refuels before cutoff | -720 | -720 | #12, #26, #59, #64, #87 |
| Later home refuel | 0 | -144 | #105 at 03:04:04.584 |
| Closing cash | **845,528** | **845,384** | Receipt reconstruction; final checkpoint |
| Net cash gain | **670,528** | **670,384** | Closing less starting cash |
| Return on starting cash | **383.159%** | **383.077%** | About 383.2% and 383.1%, respectively |
| Unexplained difference | **0** | **0** | Observed change equals unique receipt delta |

Settled identity: 175000 - 753845 + 1425093 - 864 = 845384.

| Good | Bought/sold units | Cost | Revenue | Gross margin before fuel |
| --- | ---: | ---: | ---: | ---: |
| SHIP_PARTS | 117 / 117 | 477,345 | 886,125 | 408,780 |
| SHIP_PLATING | 70 / 70 | 276,500 | 538,968 | 262,468 |
| Total | **187 / 187** | **753,845** | **1,425,093** | **671,248** |

Subtract 864 CR of fuel payments from 671,248 to obtain settled gain 670,384. Wear, depreciation, labor and acquisition basis for imported starter assets are unpriced. This is net cash, not fully costed economic profit.

### Cutoff versus physical settlement

checkpoint-hour-cutoff.json was captured at **03:03:49**, not exactly 03:03:00. Its cash carries back because #101 sold all remaining cargo at 03:01:44.361; subsequent orbit/navigation had zero credit delta; only #105 changed cash afterward. checkpoint-final-sale5.json already shows 845,528 CR and empty cargo at 03:01:46.134.

Do **not** carry back the later ship position: #103 departed A2 at 03:02:51.029 and arrived A1 at **03:03:07.029**. At cutoff the command ship was **in transit home**, empty, with 217 fuel in the navigation receipt. #104 docked at 03:03:33. #105 restored 400/400 at **03:04:04.584**, about 64.6 seconds beyond the hour. The final checkpoint was captured 03:04:38.516.

Final state: GIANTSOUP-1 docked X1-PZ83-A1, empty 40-capacity hold, 400/400 fuel; GIANTSOUP-2 docked H47. Recorded runs are completed/stopped and visible actions confirmed. The engine remained running awaiting approvals; no active run is not the same as engine shutdown.

## 3. Trade-cycle timeline

All waypoint suffixes belong to X1-PZ83. Elapsed to sell = run start to last sale, rounded to nearest second; it includes loading/travel/selling, **not automatically empty return**. Return/settlement timings follow. Fuel posted assigns actual bills by payment block, so shared/deferred bills are **not independent marginal lot costs**.

| Lot / run | Good, source → buyer | Units | Start → last sale UTC | Elapsed to sell | Cost | Revenue | Fuel posted | Net with posted fuel |
| --- | --- | ---: | --- | ---: | ---: | ---: | ---: | ---: |
| 1 / 1 | PARTS D39 → H47 | 2 | 02:10:23 → 02:12:09.672 | 1m47s | 7,400 | 16,902 | 144 | **9,358** |
| 2 / 3 | PARTS D39 → H47 | 30 | 02:15:27 → 02:18:33.591 | 3m07s | 112,005 | 247,758 | 72 | **135,681** |
| 3 / 4 + manual | PARTS D39 → H47 | 40 | 02:21:13 → 02:26:53.590 | 5m41s | 159,085 | 260,130 | 0 deferred | **101,045** |
| 4 / 5 | PLATING D40 → H47 | 40 | 02:29:14 → 02:33:20.738 | 4m07s | 154,730 | 307,122 | 144 shared | **152,248** |
| 5 / 7 | PLATING D40 → A2 | 30 | 02:40:47 → 02:44:22.386 | 3m35s | 121,770 | 231,846 | 0 deferred | **110,076** |
| 6 / 8 + manual | PARTS D39 → A2 | 15 | 02:47:28 → 02:52:30.394 | 5m02s | 63,120 | 126,723 | 216 shared | **63,387** |
| 7 / 9 + manual | PARTS D39 → A2 | 30 | 02:55:46 → 03:01:44.361 | 5m58s | 135,735 | 234,612 | 144 later/shared | **98,733** |
| Scout / 6 | No goods; D40 → A2 → D40 | 0 | 02:36:24 → 02:40:20 completion | 3m56s total | 0 | 0 | 144 | **-144** |
| Total | | **187** | | | **753,845** | **1,425,093** | **864** | **670,384** |

### Execution register and complete-cycle boundaries

1. **Test.** Initial empty A1→D39 positioning (#2) departed 02:06:07.081, arrived 02:07:25.081, consuming 91 tank units. Buy #4: 2×3700; sell #8: 2×8451. D39→H47→D39 burned another 86 tank units. #12 paid 144 at 02:13:52.991 for both positioning and test fuel. Run completed 02:14:02, **3m39s** after start. The 9,358 test result includes earlier positioning fuel.
2. **Thirty parts.** Buys #13–14: 15×3695, 15×3772. Sells #18–22: six each at 8517 / 8414 / 8288 / 8133 / 7941. Return D39 arrival 02:19:49.370; refill #26 at 02:20:17.032; completion 02:20:26, **4m59s**. Run 2 never started: parts30-start.json returned a fingerprint rejection; it is not another purchase.
3. **Forty parts.** Buys #27–29: 15×3868, 15×3985, 10×4129. #33–38 sold six each at 7706 / 7416 / 7059 / 6620 / 6079 / 5413, totaling 241,758. A 4800 floor blocked the last four at a 4593 bid. Manual #39 sold 4×4593 = **18,372** at 02:26:53.590. Total revenue **260,130**, gross **101,045**. Empty H47→D40 reposition arrived 02:28:25.452, **7m12s from start**, still unrefueled. Its 86 tank units of outbound/reposition fuel were billed later.
4. **Forty plating.** Buys #43–45: 15×3805, 15×3873, 10×3956. Sells #49–55: 6/6/6/6/6/6/4 at 8149 / 8035 / 7896 / 7724 / 7512 / 7251 / 6930. Return D40 arrival 02:34:37.299; #59 refill 02:35:04.438; completion 02:35:14, **6m00s**. Its 144 fuel bill covers 172 tank units: this trip's 86 plus lot 3's deferred 86.
5. **Scout and A2 plating.** Scout run 6 consumed 182 tank units; #64 paid 144 at 02:40:11.747. It observed A2 and returned D40 in **236s**; no cargo transactions. Next buys #65–66: 15×4002, 15×4116. Sells #70–74: six each at 7964 / 7871 / 7755 / 7613 / 7438. After sales, run 7 was stopped to redirect empty A2→D39, arriving 02:47:02.294, **6m15s from lot start**. Loaded leg/reposition burned 182 tank units, not immediately refueled.
6. **Fifteen-part partial.** Run 8 proposed 30; only #79, 15×4208, executed. Freshness blocked another buy. Manual #82 departed 02:49:29.721, arrived A2 02:50:47.721. #84–86 sold 6/6/3 at 8532 / 8433 / 8311. #87 paid 216 at 02:53:18.558, covering 273 tank units: prior lot's 182 plus this loaded leg's 91. Empty return #89 arrived D39 02:55:15.921, **7m48s from start**; that return fuel was billed later.
7. **Final thirty parts.** Run 9 proposed 40; only #91–92 executed: 15×4404, 15×4645. Freshness blocked ten more. Manual #95 arrived A2 02:58:56.740; #97–101 sold six each at 8240 / 8073 / 7868 / 7616 / 7305. Gross **98,877**, not the stopped run's apparent 135,735 loss. Home/refuel ended 03:04:04.584, **8m19s from start**. The 144 bill covers 183 tank units: lot 6's return 91, final delivery 91, home leg 1.

### Optional normalized fuel allocation: estimates

Prorating each actual refill bill over physical tank units consumed since the previous full tank assigns initial positioning **74.03 CR**; lots 1–7 **69.97 / 72 / 72 / 72 / 144 / 143.61 / 72.39**; separate scout **144**. Allocations sum to **864**. Normalized lot nets are **9432.03 / 135681 / 100973 / 152320 / 109932 / 63459.39 / 98804.61**, with initial positioning/scout costs separate.

This is an explicit attribution estimate, not a counterfactual independent-trip fuel quote. Actual refills restored **177 / 86 / 172 / 182 / 273 / 183 tank units** for **144 / 72 / 144 / 144 / 216 / 144 CR**. Total navigation burn was **1,073 tank units**; initial/final tanks were full. Market fuel units are not tank units.

## 4. Price movement, batching, travel and information

### Price/size effects

D39 parts asks rose from 3700 in the trial to 4645 in the last buy. H47's 40-part lot sold from 7706 down to 4593. h47-after-plating40.json, observed **02:35:17**, shows parts at **3944**, below the recent 4129 source price. Reusing the original route quote was unsound.

Thirty parts at H47 produced **135,753 gross**, while the following forty produced only **101,045 gross**. Forty units times the first sell price gives 308,240, versus actual 260,130: **48,110 less than flat-price arithmetic**. Purchase cost also exceeded 40×3868 by **4365**. The resulting 52,475 quote-to-realization gap is not proof that all of it was avoidable or caused by our own orders.

Detailed exporter tradeVolume was **15**, buyer tradeVolume **6**. Forty units needed three buys and seven sells. These are **per-transaction limits**, not lifetime buyer demand or total stock. The locally archived first-party OpenAPI defines tradeVolume this way and associates low volume with greater price volatility. Each next batch executed at a new price. Larger loads increase tied capital, queued operations and repricing exposure; select sized lots, not full holds times the first bid.

This sequence demonstrates price-impact risk, but cannot isolate our trades from other players or background production/consumption. There was no control market or world-event trace.

### Travel and scouting

The 17 navigation receipts contain **1000 seconds / 16m40s of flight**, entirely within the measured hour. The remaining 43m20s includes batching, observations, dispatch/polling cadence, preview/approval work, decisions and holds; it is not all avoidable idle time. D39/D40↔H47 took 45s per flight; sources↔A2 took 78s. Better A2 demand came with longer travel and harder quote continuity.

H47 already had a parked probe. AutomationRunner::refreshBuyer() can refresh when **any friendly stored ship is stationary at the market**; a second active run is unnecessary. A2 had no parked observer after the command ship left. a2-fresh-market.json at **02:38:48**, after the 02:38:29 return departure, contains a goods directory without detailed prices. A repeated directory read is not a fresh executable quote.

Scout run 6's **144 CR and 236 seconds** purchased useful information, followed by actual sales at A2. Its exact incremental ROI is unknown: there was no matched alternative route/control. Moving the existing probe to the chosen buyer is a plausible improvement, but the existing scout strategy returns to its origin; persistent placement is not already proven by this run.

### Freshness holds

The source configuration's snapshot_seconds is **180**. These holds were predictable from the remaining quote lifetime:

| Run | A2 detailed buyer quote | Start / age | Actual buys | Next due tick / quote age | Result |
| --- | --- | --- | --- | --- | --- |
| 8 | 02:45:06 | 02:47:28 / 142s | #79 02:47:52.769 / ~167s | 02:48:06 / 180s | 15 of 30 loaded |
| 9 | 02:53:38 | 02:55:46 / 128s | #91 02:56:09.343; #92 02:56:24.760 | 02:56:38 / 180s | 30 of 40 loaded |

Evidence: checkpoint-1848.json at **02:48:19**, checkpoint-1856.json at **02:56:42**; both explicitly report unknown/stale A2 parts prices. Guards correctly stopped new risk. The **25 units not purchased are not realized losses or established forgone profit**. Human intervention was needed because routine partial-load delivery had no automatic recovery path.

Do not fix this by silently widening the global stale-snapshot window. Budget quote life through the last planned buy, reduce quantity or establish observer coverage. Refresh buyer bids on arrival before selling.

A related code issue: OperationPlanner.php:96 reads source/buyer quotes **before** line 101 checks whether all units are already bought. A fully loaded ship could therefore be blocked from departure by an acquisition-only stale quote. The experiment demonstrated partial-load holds, not an executed full-load occurrence of this ordering issue.

## 5. Strongest decisions and avoidable friction

| Priority | Decision/friction | Evidence and UTC timestamp | Assessment |
| --- | --- | --- | --- |
| Strong | Two-unit proof before scaling | #4 02:10:36.522; #8 02:12:09.672; #12 02:13:52.991; checkpoint-1814.json | Real sale and restored-fuel result established with 7400 cargo at risk. The later 30-unit leap is not automatically safe under the campaign cap. |
| Strong | Reject stale approval | parts30-start.json 02:14:53.391; parts30-start-v2.json 02:15:27.225 | Run 2 moved no money. Keep fingerprints; fresh preview took about 34s after rejection. |
| Strong | Switch parts→plating | h47-price-hold.json 02:26:16; #43–55; checkpoint-1836.json | Plating delivered 152,392 gross rather than insisting on depleted parts demand. |
| Strong | Bounded scout, then stronger buyer | Run 6 02:36:24–02:40:20; #64; #70–74 | Information led to actual A2 trades; exact counterfactual benefit is unmeasured. |
| Strong | Deliver already-owned cargo without more buying | checkpoint-1848.json / checkpoint-1856.json; #84–86 / #97–101 | Respected freshness guards and liquidated all cargo. Recovery receipts belong to original lots. |
| P0 | Blocked active run requires manual takeover | Same holds; AutomationRunner.php:151; director step 5 | An active blocked run can prevent director step 6 recovery forever. Most important automation gap. |
| P1 | Oversize low-volume deteriorating buyer | #27–39, 02:21:27–02:26:53 | More cargo produced less gross than preceding 30-unit lot. Start with at most one buyer batch, not full hold. |
| P1 | Historic floor delayed last four | #38 02:24:48.506; checkpoint-1826.json; #39 02:26:53.590 | **2m05s between sales**. Final 4593 still exceeded last buy 4129. Use remaining-cost liquidation; lost-time earnings unknown. |
| P1 | Predictably expiring remote quotes | Run 8/9 quote-age table | Loading-horizon feasibility should reduce size or change observation plan before buying. |
| P2 | Late command scouting and fixed return | Run 6; run 7 stop 02:44:37; navigation #77 | Scout displaced earning time; changing source needed stop/manual navigation. One-way repositioning is optional later optimization. |

No cargo lot realized negative merchandise margin. Measured exploratory expense includes the separate 144 scout plus initial positioning. Slippage against flat quotes and opportunity costs are not interchangeable with actual cash losses.

## 6. Compared with earlier research

DEV/docs/wipe-strategy-research-2026-11-14.md:38 and its HTML recommended short discovery, a small existing-command trade, full-cycle measurement and conditional expansion. They also proposed mining comparison, possible contracts and later fleet options.

| Researched idea | Evidence from this experiment | Next-wipe treatment |
| --- | --- | --- |
| Time-box discovery, trade early | First buy 7m37s into hour; two-unit proof worked | Keep decision-relevant discovery; no 30-minute census |
| Verify returns before scaling | Two initial sell/return/refuel cycles profitable | Keep proof; require two modest settled cycles per new route |
| Change product/buyer on evidence | Parts→plating and H47→A2 executed | Deterministic reranking, not fixed commodity preference |
| Existing miner comparison/fallback | No extraction/mining sale | Untested; remove automatic mining from this trade-led revision |
| Hauler/probe payback | No purchase or incremental-capacity comparison | Untested; human-approved future options only |
| Contracts, gate feeding, refining, logistics | No earning loop measured | Outside this grant |

The earlier **10–15-minute discovery** and **20,000 reserve** were policies, not optimized mechanics. Baseline visibility at H47 and existing map make this unlike a blank opening. The next agent may have different geography, equipment or cash. Actual agent was VOID; draft re-registration selects COSMIC, which was not tested.

## 7. Immediate next-wipe opening and change conditions

These are proposed instructions for the eventual approved campaign, **not actions taken in this audit**.

1. **Confirm the reset.** At/after scheduled 2026-11-15T13:00Z, verify actual reset ID before registration. Rebuild fleet/cash/fuel/market evidence; discard old-reset quotes. Never hardcode X1-PZ83, ship symbols, starter equipment or 175,000 credits.
2. **Discover up to ten minutes initially.** Find source, reachable buyer, fuel recovery and a usable existing observer. Exit initial discovery after three detailed current-reset priced markets or ten minutes. Directories do not count. Earn-phase targeted scouts remain possible if no trade is feasible.
3. **Trial cargo on the existing command ship.** At most **two units / 8000 CR cargo basis**, reduced by legal volumes, capacity, reserve, fuel, quote headroom and loss allowance. If even one unit cannot satisfy bounds, do not force the trade.
4. **Prove and scale modestly.** Require **two completely sold/reconciled positive cycles** for the same reset/source/good/buyer, including fuel and recovery. Then at most **2×** last successful quantity. Cap by source/buyer transaction volumes, hold, **24,000 absolute**, **10% liquid cash**, remaining loss allowance and prospective cash-floor headroom. A new good/buyer resets trial stage.
5. **Keep quotes live.** Both detailed quotes <180s and enough buyer life through final loading plus at least one dispatch interval. Otherwise reduce load, use an existing observer, select another feasible buyer or scout. Refresh each sell batch on arrival.
6. **Change goods/buyers conditionally.** Stop new buying if buffered whole-cycle margin <500 CR, spread nonpositive, freshness infeasible or safety fails. A **20% drop in settled margin/unit** from the last validated route result triggers refresh/rerank and return to trial size. Switch only when a fresh sized alternative, including reposition/fuel/time, scores **10% better on two successive observations**; safety failure needs no hysteresis. These percentages are policy hypotheses, not measured optima. Reliable observation can beat a larger stale spread.
7. **Bound learning.** No feasible trade → one specific scout, no more than **1000 CR spend/loss**, then reevaluate. Repeated fruitless scouting should wait/report, not endlessly move a ship. No automatic mining fallback in this revision.
8. **Settle near reset.** Retain draft's 12-hour no-new-buy wind-down until separately reviewed. Liquidate owned cargo, restore safe fuel and reconcile. Never duplicate pending commands or interrupt transit merely to change phases.

### Future options requiring fresh human approval

- **Hauler:** First approve fleet expansion and a suitable concurrency/cargo-ledger design. Require at least three comparable settled loops showing cargo/transport capacity—not six-unit buyer depth, freshness or one-active-run scheduling—is the bottleneck. Delivered/setup/positioning cost divided by a conservative measured **incremental** net rate must repay within half usable hours before reset, while preserving reserve/loss headroom. This experiment provides no incremental hauler rate. Cash crossing a threshold alone never authorizes buying.
- **Additional probe:** Only after existing probe use/placement is evaluated and holds show a specific profitable route's observation gap. Include deployment time and displaced earnings. No generic scout fleet. Persistent placement itself needs support beyond a scout round trip.
- **Mining test:** Later explicitly approved revision only; verify new-reset equipment, site, buyer and fuel. Suggested future trigger: two bounded discovery attempts or 15 minutes of earning checks fail to find a viable trade. Test one full extraction/sale/return/refuel cycle using existing equipment; repeat comparable observations before routine use. This is not authorization and does not establish mining loses to trading.
- **Contracts:** No acceptance; the earlier research's conditional contract branch is superseded by Taylor's narrower scope.

## 8. Campaign changes: minimal build-contract amendments

These are **proposed edits**, not edits made to DEV/docs/campaign-autopilot-plan.md. Keep five phase-gate metrics; encode trade selection as deterministic feasibility/policy, not a growing expression language. Preserve one active run per agent and no purchases/equipment changes/contracts.

### P0: correctness and safety

1. **Remove the example's when: conservative_margin_gte: 1 condition.** That key is absent from the draft's five gate names. More importantly, OperationPlanner.php:55 and OpportunityAssessor.php:180 define conservative_cash_margin as **negative inventory cost minus fuel**: the adverse case where nothing sells. The successful two-part trial carried **-7976** for this field. A positive threshold would veto normal trades; do not redefine the existing field to hide that mismatch. Require positive **buffered estimated** cycle margin above policy floor, with adverse exposure bounded **separately**.
2. **Reconcile exposure and loss semantics before every buy.** The current draft pauses at credits < start_credits - episode_loss_cap. At a 175,000 start/25,000 cap, hard cash floor is **150,000**, not merely the reserve. The experiment's 30-part buy left **72,353**, so would legitimately stop that campaign despite eventual profit. Require prospective cash after purchase and committed fuel >= max(20,000, start_credits - 25,000). Separately apply the same cap to a receipt-based **cumulative debit of settled losing cycles and exploration costs**, without replenishing that debit with winners. This explicitly implements “cumulative loss”; the raw-credit guard alone is not a monotonic loss counter. The second counter is a small additional ledger requirement, not a silent replacement for the current drawdown guard.
3. **Process safe holds before active-run no-op.** Distinguish freshness/spread/owned-floor holds from uncertain writes, unexplained cash, reset/configuration problems or breached authority. Pending/sending/uncertain commands must settle/reconcile first; never replay. A freshness hold prohibits more buys, then safely transitions or ends that run and recovers attributable cargo under the grant. Do not leave a blocked active run occupying the sole slot forever. If no bounded liquidation is feasible, pause with cargo/buyer evidence.
4. **Explicit liquidation authority.** Compare fresh reachable proceeds minus **remaining** fuel/time costs; purchase basis is sunk, not a reason to hold indefinitely. Selling below an old acquisition floor may realize a loss and must fit remaining cumulative-loss authority and cash/fuel safety. If every alternative exceeds authority, pause; never silently loosen bounds. Profitable in-bounds recovery should not need a human to sell the last four units.
5. **Check fully loaded before acquisition-only quote reads.** Move the no-units-left check ahead of those reads so already-loaded cargo can depart. Partial loads need the sell-only path, not a normal trade preview: AutomationRunner.php:36 requires empty starting cargo.

### P1: operating policy

6. **Retain three phases, change activities.** Discover: **10 minutes or three detailed markets**, not 30/six. Earn: trade, then bounded targeted scout, **not mining**. Wind-down: no earning activity; director recovers cargo and performs safe settlement. Allow an empty activity list. Evaluate wind-down before an active run's next buy, not only after that run completes.
7. **Replace 5000 exposure with bounded test/scale, not 150,000.** The successful two-part test cost 7400, so 5000 cannot reproduce its size. Use **8000 trial ceiling / 24,000 scaled ceiling**, also capped by **10% liquid cash**, legal buyer depth, fuel-inclusive prospective cash-floor headroom and unused cumulative-loss allowance. The 24,000 ceiling leaves 1000 nominal room below 25,000, but actual fuel_ceiling still decides feasibility; 1000 is not a universal fuel guarantee. At 175,000 liquid cash, 10% limits scaled cargo to 17,500 before other constraints. Two units is a maximum, not an entitlement.
8. **One cycle / 15 minutes per run.** Fresh preview/reranking after each cycle. These observed runs fit, including manual recovery, but new geography can differ: reject an infeasible duration. Timeout with cargo must prohibit purchases and recover, not orphan inventory. One-cycle operation already matches the bounded trade planner better than the example's ten-cycle grant.
9. **Keep the 180-second quote window.** Add loading-horizon feasibility, log quantity reductions, and refresh existing stationary-observer prices within the single-run lock. No global freshness weakening. Size/rank using batch counts, positioning, loaded/empty travel, fuel and measured dispatch delay. Existing null per-hour estimates are not evidence that time costs are zero.
10. **Report economic lots and hold time.** Record source/buyer/good, quote ages, batch counts, cargo basis, all originating/recovery action IDs, fuel attribution, settlement and redeployment times, hold reasons/durations. Never score runs 8/9 from stopped buy-only cash deltas without their sales. Distinguish exact-hour and restored balances; mark shared fuel allocations as estimates.

### Proposed YAML replacement

**Schema-change proposal, not currently supported configuration.** Explicitly add trade_policy and its fields to v1 validation/immutable revision/grant. Continue rejecting unknown keys. The example uses only existing phase-gate names and removes the unsupported when predicate.

~~~yaml
name: weekly-trade-first-v1
version: 1
agent: { symbol: GIANTSOUP, faction: COSMIC }
limits:
  reserve_credits: 20000
  episode_loss_cap: 25000
  trade_exposure_credits: 24000
  run_max_minutes: 15
  run_max_cycles: 1
trade_policy:
  probe_units: 2
  probe_exposure_credits: 8000
  successful_cycles_before_scale: 2
  scale_factor: 2
  max_liquid_cash_fraction: 0.10
  quote_max_age_seconds: 180
  sell_price_haircut: 0.10
  min_margin_credits: 500
phases:
  - id: discover
    exit: { any: [ { minutes_elapsed_gte: 10 }, { markets_observed_gte: 3 } ] }
    activities: [ { role: probe, strategy: scout } ]
  - id: earn
    exit: { hours_to_reset_lte: 12 }
    activities:
      - { role: command, strategy: trade }
      - { role: probe, strategy: scout }
  - id: wind_down
    activities: []
predictions: []
~~~

probe_units means a **cargo trial on the command ship**, not freight carried by a probe. Quantity = minimum of trial/scale quantity, source/buyer tradeVolume, free hold, exposure/cash ceilings and prospective fuel-inclusive safety headroom. Cash fraction limits cargo basis; fuel has a separate committed budget. All hard bounds apply together and are never waived by projected profit.

The 10% sell-price haircut applies to expected proceeds before the 500 margin floor. It is an explicit starting **policy hypothesis**, not a guaranteed execution price or replacement for live batch quotes. The other proposed thresholds are likewise not optimized by this hour. The 20% deterioration/10% switching rules can initially be fixed documented selector constants rather than more gate syntax; journal their inputs/decisions.

predictions: [] avoids the meaningless example credits >= 0 assertion. Schema must allow an empty list until meaningful supported predictions are supplied. Preserve learning goals in revision notes/report: record trial completion, bounds/freshness compliance, liquidation latency and estimated-versus-realized margins. Do not invent new prediction syntax or a guaranteed cash forecast. A future baseline-relative cash prediction needs explicit schema/metric support.

Initial discovery still needs a feasible destination chosen from the new world. If the existing probe cannot reach useful evidence within budget/time, use the command ship for a specific observation task before trading or wait; never invent role capabilities. Automatic destination selection and the 1000-per-scout spend bound must be documented/enforced in candidate generation, not assumed from naming scout.

### What requires engine code, beyond wording

| Behavior | Current support | Required treatment |
| --- | --- | --- |
| Sized buy/sell actions, fresh local quotes, one active run | Existing code | Reuse all preview/executor safety guards |
| Unsold worst-case margin | Existing assessor/planner | Separate from buffered positive expected margin |
| Stationary observer refresh | AutomationRunner::refreshBuyer | Reuse; refresh actual observer state after interruptions/reset |
| Sell-only cargo recovery | Draft step 6 promises it; ordinary trade preview requires empty cargo | Implement, including blocked active runs |
| Reason-coded holds and safe automatic transition | Insufficiently specified | Minimal explicit reason codes/transitions; uncertainty still pauses |
| Per-route trial/scale state, loading-horizon check, batch/time-aware selection | Not concrete in current contract | New deterministic code/schema required |
| Monotonic cumulative loss debit, prospective drawdown floor | Current draft has raw-credit pause | Add per-action checks and persistent accounting |
| Empty wind-down activities / active-run buy veto | Not fully specified | Explicit validation and execution tests |
| Park existing probe at buyer | Scout currently returns home | Optional new behavior; cannot assume already implemented |
| One-way reposition to a different source after sale | Manual here; bounded planner returns home | Optional later optimization; not necessary for safe v1 |

### Acceptance-test changes

Replace “trade skipped when margin/exposure fails and mining chosen” with **trade skipped; targeted scouting or waiting chosen**. Add faked-client cases for:

- Negative unsold downside does not wrongly veto positive buffered margin when exposure is safely bounded; no reinterpretation of the existing conservative field.
- A two-unit test costing 7400 fails a 5000 ceiling and can fit 8000 only if all other bounds fit; new route resets proof; only settled positive complete cycles count; quantity never exceeds buyer depth.
- Reserve and episode cash floor checked **before** purchase; cumulative loss debit survives a winner/restart; fuel is reserved and authority cannot be exceeded by optimistic profit.
- A directory-only remote response never refreshes detailed prices; partial-buy freshness hold never buys more and reaches approved liquidation; fully loaded stale acquisition quote does not prevent departure.
- A blocked active run cannot deadlock director recovery; owned-floor recovery liquidates only within explicit authority or pauses; pending/uncertain writes are never duplicated.
- Timeout/wind-down issue no new buys and settle existing cargo; recovered manual actions join the original lot and count once; empty wind-down/scout-no-candidate cases wait cleanly.

Keep reset, grant revocation, discrepancy, engine restart and one-run serialization tests. No fleet buying, mounts, contracts or concurrent runs to make these cases pass.

## 9. Limits and uncertainty

- One hour in a **mature wipe** does not establish next-opening demand, supply, price elasticity, competition, route geometry or loadout. No guaranteed hourly/weekly revenue follows from settled +670,384.
- A human selected routes, approved exceptions and completed recovery. This is not evidence the current no-AI campaign already reproduces those decisions or operates unattended for a week.
- No alternative-route control, mining trial, new-ship trial, failure distribution or multi-reset sample. Trading is not proven universally best; no hauler/probe payback was measured.
- Prices do not identify causal shares of our impact, other players or background production. Delay opportunity cost and counterfactual probe benefit are unknown.
- Baseline captured ~34s before nominal start, with earlier agent sync. Supplied history has no intervening monetary receipt and reconciles exactly; independent absence of all unrecorded upstream activity cannot be proven.
- Cutoff cash is receipt-backed reconstruction, with a pre-deadline sale checkpoint, not an exactly 03:03 snapshot. It does not establish being home/refueled at cutoff.
- Fuel attribution is a chosen estimate; wear, depreciation, future repairs, starter acquisition basis and labor remain unpriced, not asserted to be zero.
- Inspected source is the **development checkout**, not a byte-for-byte production audit. Behavior is consistent with these guards; no production checkout was changed/invoked.
- No live SpaceTraders status/API was fetched. Reset schedule comes from saved first-party evidence; execution must confirm actual reset.

## 10. Evidence paths and receipt index

All evidence below was read-only. Decisions are retrospective narrative, not substitutes for execution times. A file named approval may contain an actual action outcome; its confirmed transaction was inspected before counting money.

### Accounting and terminal state

- [Final HTML](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/lcars-final-report.html): reported claims checked against receipts.
- [Baseline](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/baseline.json): 175,000, empty hold, full tank, already-positioned probe.
- [Accounting summary](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/accounting-summary.json): transaction-ID/totals comparison, not the sole source of recomputation.
- [Cutoff reconciliation](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/hour-cutoff-reconciliation.json), [cutoff checkpoint](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-hour-cutoff.json), [final checkpoint](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-final.json).
- [Decision log](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/decisions.tsv): reasoning narrative with later entry timestamps.

### Execution and holds

- [Trial completion](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-1814.json), [30-part completion](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-1820.json).
- [Floor hold](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-1826.json), [last-four action](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/parts40-final-four-approval.json), [last-four settled checkpoint](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-1827.json).
- [Plating completion](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-1836.json), [A2 scout observations](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/a2-scout-observations.json), [A2 plating sold](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-1844b.json).
- [Fifteen-part freshness hold](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-1848.json), [fifteen-part sale complete](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-parts15-sale3.json).
- [Final-load freshness hold](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-1856.json), [last pre-cutoff sale](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/checkpoint-final-sale5.json), [home refill outcome](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/home-final-refuel-approval.json).

### Markets, contract and code

- [D39 detailed market](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/d39-market-arrived.json), [D40 detailed market](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/d40-market.json), [H47 weakened buyer](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/h47-after-plating40.json).
- [A2 quote 02:45:06](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/a2-before-parts-return.json), [A2 quote 02:53:38](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/a2-final-departure-prices.json), [directory-only A2 read](C:/Users/taylo/AppData/Local/Temp/spacetraders-live-20261114/a2-fresh-market.json).
- [Campaign contract](C:/Users/taylo/Projects/spacetraders/docs/campaign-autopilot-plan.md:1), [planner purchase/quote ordering](C:/Users/taylo/Projects/spacetraders/app/Gameplay/OperationPlanner.php:92), [stale quote guard](C:/Users/taylo/Projects/spacetraders/app/Gameplay/OperationPlanner.php:165).
- [Assessor estimated/adverse margins](C:/Users/taylo/Projects/spacetraders/app/Gameplay/OpportunityAssessor.php:155), [runner hold/refresh behavior](C:/Users/taylo/Projects/spacetraders/app/Gameplay/AutomationRunner.php:138), [stationary observer refresh](C:/Users/taylo/Projects/spacetraders/app/Gameplay/AutomationRunner.php:258), [snapshot configuration](C:/Users/taylo/Projects/spacetraders/config/gameplay.php:1).
- [Earlier research](C:/Users/taylo/Projects/spacetraders/docs/wipe-strategy-research-2026-11-14.md:38), [earlier HTML](C:/Users/taylo/Projects/spacetraders/docs/reports/wipe-strategy-2026-11-14.html), [saved first-party OpenAPI](C:/Users/taylo/Projects/spacetraders/docs/reports/wipe-strategy-evidence-2026-11-14/openapi.json), [saved first-party reset status](C:/Users/taylo/Projects/spacetraders/docs/reports/wipe-strategy-evidence-2026-11-14/status.json).

The execution register in section 3 enumerates all **14 buy / 33 sell / 6 refuel** IDs exactly once as monetary events. References to a deferred bill explain attribution and do not add another payment. All 17 travel receipts are zero-credit actions; the fuel they consume is not counted again as a cash purchase.

**Bottom line:** build a small deterministic trade-first learner with reliable liquidation/accounting, not a weekly extrapolation of a manually rescued late-wipe windfall.




