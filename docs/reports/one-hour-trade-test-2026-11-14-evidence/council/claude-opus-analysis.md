# One-hour GIANTSOUP trading experiment: independent audit and campaign changes

Council member: Claude Opus 5.5. Analysis only. I made no API calls or gameplay actions, and I changed nothing in production or the dev repo. I did not run the helper scripts.
Measured hour: 2026-11-15 02:03:00–03:03:00 UTC (2026-11-14 18:03–19:03 PST). Reset 2026-11-08, system X1-PZ83, faction VOID.

## 1. Verdict

- **The reported numbers are correct.** I took the union of all 105 ship actions (ids 1–105, none missing) across every `checkpoint-*.json`, counted each action once by id, and summed the confirmed receipts. The result matches `accounting-summary.json` exactly: +1,425,093 sales, −753,845 purchases, −864 fuel, net **+670,384**, with no unexplained difference.
- **What worked:** one command ship (40 cargo) ran export→import arbitrage on two goods (SHIP_PARTS from D39, SHIP_PLATING from D40) into two importers (H47, then A2). The pattern was a small trial, then a scale-up, then rotation as our own sales pushed the bids down.
- **What the hour tested:** the operating method under real execution: trial→scale, rotation, sale floors, quote-freshness holds, reserve. The cash total is not something to expect again. It came from a late-wipe system where nobody else traded these pairs (the H47 plating bid stayed at 8,149 from 02:09 to 02:26 until we sold into it).
- **The plan as drafted would have failed this hour.** It would have paused or done nothing at five separate points:
  - A 5,000 CR exposure cap blocks even the 7,400 CR trial.
  - A raw-credits loss check pauses at the first scaled buy: credits fell to 72,353, below the 150,000 floor.
  - A 30-minute probe-only discover phase idles the command ship. In this hour, that half earned about +398k.
  - `conservative_margin_gte: 1` is not in the gate vocabulary. Even if it were, the code always computes the trade "conservative margin" as a negative number (−inventory − fuel), so trade would never be chosen.
  - With mining second in `earn`, every trade-infeasible moment falls through to up to 60 minutes of untested mining.

  Section 9 has the minimal edits.
- **About 57% of realized cash came from manual actions** (sales of 379,707 CR). Four of the eight trade runs needed a takeover for a price-floor hold, a reroute, or two freshness holds. An unattended autopilot must handle those cases itself, or it will stall exactly where this hour needed a human.

## 2. Reconciled accounting

Source: union of actions across checkpoints. Each receipt (action id) is counted once, so repeats across snapshots are not double-counted.

| Item | Credits | Evidence |
|---|---:|---|
| Starting cash (baseline 02:02:25Z; ledger baseline `first_observed_local` 175,000) | 175,000 | baseline.json `agent.credits`, `accounting.baseline` |
| Cargo sales: 33 confirmed sells (117 SHIP_PARTS + 70 SHIP_PLATING) | +1,425,093 | actions 8–101 (sell) |
| Cargo purchases: 14 confirmed buys | −753,845 | actions 4–92 (buy) |
| Fuel before cutoff: 5 refuels, 10 market units @72 | −720 | actions 12, 26, 59, 64, 87 |
| **Cash at 03:03:00Z cutoff** | **845,528** | last cash event before cutoff = sale 101 @03:01:44Z; hour-cutoff-reconciliation.json |
| Final refuel at A1 after cutoff, 2 units @72 (03:04:04Z) | −144 | action 105 |
| **Settled cash** | **845,384** | checkpoint-final.json `agent.credits`; ledger `unexplained_change: 0` |
| Rejected or never-executed actions (75 orbit, 80 buy, 93 buy) | 0 | state `rejected`, no receipt |

Per-good gross margins (goods only, before fuel): SHIP_PARTS sold 886,125 against cost 477,345, margin **408,780**. SHIP_PLATING sold 538,968 against cost 276,500, margin **262,468**. Total 671,248 − 864 fuel = 670,384. ✔

Other checks:

- Fuel consumed was 1,073 ship units over 12 navigations (sum of `fuel.consumed`), refilled for 864 CR. That is 0.13% of gross margin, so fuel is economically irrelevant on this route set.
- Peak inventory exposure was 159,085 CR (C3, run 4). The lowest cash at any moment was 160,954 CR, right after action 29. The 20,000 reserve never came close to binding.
- Origin split: automation sales 1,045,386 and refuel −504; manual sales 379,707 and refuel −360. The ledger's `manual_change` is 379,347.

## 3. One-hour result vs settled result

| | Exact one hour (02:03–03:03Z) | Fully settled (03:04:04Z) |
|---|---:|---:|
| Cash | 845,528 | 845,384 |
| Net vs 175,000 | **+670,528 (+383.2%)** | **+670,384 (+383.1%)** |
| Cargo | 0 (sold out by 03:01:44) | 0 |
| GIANTSOUP-1 | In transit A2→A1 (arrived 03:03:07), fuel 217/400 | Docked at A1, 400/400 |
| Comparable to start state? | No: 183 fuel short | **Yes**: same place, full tank, empty hold |

The settled figure is the like-for-like result. The 144 CR difference is just the tank top-up. Ships were never bought, so no capital assets changed. Neither figure charges anything for ship wear.

## 4. Trade-cycle timeline

Times are UTC. A slot runs from the end of the previous cycle to the end of this one, including idle and approval gaps. Fuel CR is **allocated** at 0.805 CR per ship fuel unit (864 / 1,073). This is an estimate, because refuels covered several legs (for example, the refuel at action 59 covered four legs). "Top-of-book" means units × (first fill bid − first fill ask). "Depth loss" is top-of-book minus the realized goods margin, so it measures our own price impact.

| # | Run | Good / route | Units | Cost | Revenue | Goods margin | Fuel alloc. (est.) | Net (est.) | Slot (min) | Net/min | Top-of-book | Depth loss |
|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| P0 | manual 1–3 | Position A1→D39, observe D39 (02:03–02:10:23) | – | – | – | – | 73 | −73 | 7.4 | – | – | – |
| C1 | run 1 | Parts D39→H47→D39, trial | 2 | 7,400 | 16,902 | 9,502 | 69 | 9,433 | 3.7 | 2.6k | 9,502 | 0 |
| C2 | run 3 (run 2 rejected stale) | Parts D39→H47→D39 | 30 | 112,005 | 247,758 | 135,753 | 69 | 135,684 | 6.4 | 21.2k | 144,660 | 8,907 (6%) |
| C3 | run 4 + manual sale 39, then leg to D40 | Parts D39→H47 | 40 | 159,085 | 260,130 | 101,045 | 69 | 100,976 | 7.2 | 14.0k | 153,520 | **52,475 (34%)** |
| C4 | run 5 | Plating D40→H47→D40 | 40 | 154,730 | 307,122 | 152,392 | 69 | 152,323 | 7.6 | 20.0k | 173,760 | 21,368 (12%) |
| C5 | run 6 | Scout A2 round trip from D40 (no cargo) | – | – | – | – | 147 | −147 | 5.1 | – | – | – |
| C6 | run 7 + manual reroute | Plating D40→A2, then empty A2→D39 | 30 | 121,770 | 231,846 | 110,076 | 147 | 109,929 | 5.4 | 20.4k | 118,860 | 8,784 (7%) |
| C7 | run 8 + manual nav/sells | Parts D39→A2 (**freshness hold after 15**) | 15 | 63,120 | 126,723 | 63,603 | 147 | 63,456 | 8.2 | 7.7k | 64,860 | 1,257 (2%) |
| C8 | run 9 + manual nav/sells, then home | Parts D39→A2 (**hold after 30**) → A1 | 30 | 135,735 | 234,612 | 98,877 | 74 | 98,803 | 7.8 | 12.7k | 115,080 | 16,203 (14%) |
| | | **Total** | **187** | **753,845** | **1,425,093** | **671,248** | **864** | **670,384** | 60 + 1 | 11.2k avg | | 109k |

Notes:

- **Engine run times** (`accounting-summary.json` `elapsed_seconds`): runs 1/3/4/5/6/7/8/9 took 219/299/317/360/236/234/85/75 s. Runs 4 and 7–9 stopped early, and manual steps completed them.
- **Halves of the hour:** cash after C4 at 02:35 was 573,332, so +398k in the first ~32 minutes. The last ~28 minutes earned +272k. Spreads compressed and holds cost time.
- **Per-unit goods margin trend:**

  | Good | Cycle | Margin per unit |
  |---|---|---:|
  | Parts | C1 | 4,751 |
  | Parts | C2 | 4,525 |
  | Parts | C3 | 2,526 |
  | Parts | C7 | 4,240 (new buyer) |
  | Parts | C8 | 3,296 |
  | Plating | C4 | 3,810 |
  | Plating | C6 | 3,669 |

## 5. What drove returns

**Price movement was mostly our own impact, and it did not recover within the hour.**

Sell side (bids per 6-unit batch, `tradeVolume` 6):

- H47 parts: 8,517 → 8,414 → 8,288 → 8,133 → 7,941. Five minutes later, run 4 opened at 7,706, then went 7,416 → 7,059 → 6,620 → 6,079 → 5,413 → 4,593. The decline accelerated from −1.2% to −11% per batch. Supply moved from SCARCE to HIGH (h47-fresh.json vs h47-price-hold.json). By 02:35 the bid was 3,944, below the source ask, so the route was dead.
- H47 plating fell 8,149 → 6,930 over 40 units.
- A2 plating fell 7,964 → 7,438 over 30 units.
- A2 parts went 8,532 → 8,311, then sat at 8,240 from 02:53:38 to 02:59:18 with no recovery, then went to 7,305.

Buy side (asks per 15-unit batch, `tradeVolume` 15):

- D39 parts climbed 3,695 → 4,129 in the first 70 units. Twenty-five minutes later it was 4,208, then 4,404 → 4,645. That is +25% over the hour.
- D40 plating climbed 3,805 → 3,956, then 4,002 → 4,116.

No observed market recovered within 6–25 minutes. The planner prices every unit at top-of-book (`OperationPlanner::preview`, `units*(bid-ask)`), and that overstated realized margins by 0–34% (109k in total).

**Transaction size.** Selling 40 units into a buyer with `tradeVolume` 6 takes 7 batches. Each batch costs about 15 s of engine cadence and steps the bid down. A 40-unit load spends about 1.7 minutes just on sell actions. Buying takes 3 batches of 15. Cargo capacity (40), not cash, was the binding constraint from C2 onward: cash sat idle at 3–5× the peak exposure.

**Travel** was short: 45 s per leg for D39/D40↔H47 and 78 s for D39/D40↔A2, with CRUISE fuel of 43 and 91 units. Travel was a minority of each slot. Actions, docking, approvals and holds dominated.

**Scouting.** The A2 scout took 3.9 minutes and 144 CR. It found a second importer for both goods with fresh, undepleted bids (parts 8,532, SCARCE/STRONG). Cycles C6–C8 then realized about 272k through A2. That was the best information return of the hour. The probe (GIANTSOUP-2) sat at H47 all hour. It was the reason H47 quotes were always fresh, because `AutomationRunner::refreshBuyer` re-observes a market only if one of our ships is there. It stayed parked at H47 after H47 stopped being the buyer.

**Freshness holds** were mechanical, not market-driven. `OperationPlanner::quote` requires the remote buyer quote to be at most `snapshot_seconds = 180` old at every buy batch. A2 had no observer, so its quote aged from the moment the ship left A2:

- Run 8: A2 was last seen about 02:45:06. The ship arrived at D39 at 02:47:02, the first buy went through at 02:47:52, and the second was rejected at 02:48:06 (action 80).
- Run 9: A2 was last seen 02:53:38. Buys went through at 02:56:09 and 02:56:24, and the third was rejected at 02:56:38 (action 93), right at 180 s.

The cost was 25 units not bought (15 + 10). C7 made a full cycle with 15/40 cargo, and both loads needed manual takeover (manual navigation plus manual sales at about 20–40 s each). My estimate of the forgone margin is about 50–80k, using the A2 depth curve with marginal units at about 2,300–3,800 each. The A2 bid itself barely moved while we were away, so the hold protected against a risk that did not materialize this time. It is still the right default when no observer exists.

## 6. Strongest decisions and largest avoidable losses

### Strongest decisions

1. **Trial before scale.** Two parts were bought at 3,700 (action 4, 02:10:36Z) and sold at 8,451 (action 8, 02:12:09Z). That matched the quote exactly (trial-trade-preview.json, checkpoint-1814.json). It proved execution, fuel and accounting for 3.7 minutes.
2. **Rejecting a stale preview.** Run 2 was blocked at 02:14:53Z with "Ship state, equipment or quotes changed" (parts30-start.json). A fresh preview (parts30-preview-v2.json, run 3) produced the best per-minute cycle.
3. **Selling the last 4 parts at 4,593** instead of chasing an unknown buyer. The floor was re-approved at 17,500 (h47-price-hold.json, action 39, 02:26:53Z). That was about +616/unit over the run's average basis of 3,977 (+464 over the dearest batch), and it freed the ship.
4. **Switching to plating.** Plating's bid at H47 (8,149) was untouched while parts had collapsed (plating40-preview.json, run 5, receipts 43–55). C4 was the largest cycle of the hour: +152k.
5. **Scouting A2 for 144 CR** (a2-scout-start.json, run 6, 02:36:24Z). It found the buyer for about 272k of later margin.
6. **Rerouting at A2 straight to D39** for parts instead of returning empty to D40 (a2-plating30-reroute-stop.json at 02:44:37Z, actions 76–77). That saved one empty leg and its approval cycle.
7. **Honouring freshness holds** (actions 80, 93): no inventory was bought on an expired quote, and owned cargo was delivered and sold.

### Largest avoidable delays and losses (estimates labelled)

1. **C3 re-ran parts into H47 after its bid had already slid.** Run 4 opened at 7,706, against 8,517 at the start of C2, while plating at H47 was still 8,149 (h47-price-hold.json, 02:26:16Z). Realized depth loss was 52k (34%) and a price-floor hold followed. Doing plating first would plausibly have earned about 40–50k more (estimate: C4's realized 152k vs C3's 101k, less some unavoidable parts decay later).
2. **Freshness holds on A2** (actions 80 at 02:48:06Z and 93 at 02:56:38Z): 25 units forgone, about 50–80k (estimate), plus about 3–4 minutes of manual handling. The root cause was no observer at the buyer. Moving the probe to A2 after 02:40 would have avoided both holds.
3. **Setup took 7.4 minutes before the first buy.** The ship arrived at D39 at 02:07:25 but docked at 02:10:00 (actions 2–3), about 2.6 minutes idle. At 11k/min that is the largest single block of idle time. This was a human-paced session, and an autopilot should close it.
4. **The price-floor hold** from 02:24:48 to 02:26:53 (about 2.1 minutes). A static floor of 4,800 sat above a still-profitable bid of 4,593 (parts40-stop-hold.json).
5. **Engine cadence** was about 15 s per action. In C2, 5 sell batches took 61 s. The cadence is set by `next_action_at = now()+5s` plus dispatch. At 2 requests/s there is headroom; I did not measure what a faster cadence would gain.
6. **The engine was found faulted** at baseline: heartbeat 2026-11-13T06:58Z, about 43 hours stale (baseline.json `engine`). This is not an in-hour loss. It is the most important reliability fact for a week-long unattended plan.

## 7. Against the researched opening plan

Labels used below:

- **Tested in this hour:** the hour exercised it directly.
- **Partly tested:** the hour exercised it under conditions that may not transfer.
- **Not tested:** the hour gives no evidence.

| Research idea (wipe-strategy-research-2026-11-14.md) | Status after this hour |
|---|---|
| Trade-first with the existing command ship; small export→import test first | **Tested:** it worked, and the trial matched quotes exactly. |
| Measure full-cycle net, then expand the limiting activity | **Tested.** The limiter was cargo capacity and buyer depth, not cash. |
| 20,000 CR reserve | **Tested.** It never bound; minimum cash was 160,954. |
| Refresh prices, change goods or buyers as spreads weaken | **Tested:** worth about 270k in this hour. |
| Time-boxed discovery of 10–15 minutes | **Partly tested.** 7.4 minutes to the first buy, but stored observations and a probe already at a buyer made it a warm start, not a cold one. |
| Useful early price coverage with the probe | **Partly tested.** A static probe gave freshness. Probe *movement* was not tested. |
| Mining as a measured fallback | **Not tested.** No extraction happened, so this hour says nothing about mining returns. |
| Evaluate procurement contracts first | **Not tested:** `contracts.json` total was 0. |
| Hauler or probe purchase and payback | **Not tested.** The hour does not establish new-ship payback. It only shows that cargo, not cash, was binding. |
| Community figures (about 83k/h trade, 12–20k/h mining) | Not comparable. Different systems, fleets and competition. |

## 8. Next-wipe opening (2026-11-15T13:00Z), within the no-purchase/no-contract scope

1. **T+0:** register, sync, and pull the system's waypoints and market directory (imports/exports/exchange, no prices yet).
   - Rank candidate (exporter → importer, same good) pairs by leg time from HQ.
   - Prefer high-value manufactured goods where the importer reads SCARCE/STRONG once observed.
   - Expect a different system and different goods. X1-PZ83 knowledge does not transfer.
2. **Probe goes to the top candidate importer and stays there** as the observer. The command ship goes to the paired exporter. The first trade needs fresh quotes at both ends, 180 s each.
3. **Trial:** one buyer batch, up to one `tradeVolume` (here 6 units). Exposure must fit within the loss headroom (Section 9.2). Proceed only if realized margin ≥ 80% of the quoted margin and receipts reconcile.
4. **Scale:** the next run takes the largest load that both fits the loss headroom and stays above the depth-adjusted break-even (Section 9.3), up to cargo capacity. New pairs after the first trial may start directly at that size. This hour did it three times (plating→H47, plating→A2, parts→A2), each within 2–12% of top-of-book.
5. **Switch good or buyer** before every new run, when either is true:
   - The last pair's current bid is ≤ 90% of its first-observed bid this episode.
   - Another pair's fresh, depth-adjusted margin per minute is ≥ 110% of the current pair's.

   Treat a depleted pair as unavailable until its bid recovers to ≥ 95% of first-seen. No recovery was observed within 25 minutes.
6. **Re-station the probe** at the active buyer whenever the buyer changes, between command runs. Do this before scaling into a buyer with no observer.
7. **Change buyers** when an alternative importer's fresh bid beats the current one by more than the extra leg's time cost: (extra seconds) × (recent net/min).
8. **Mining test:** only when no trade pair passes the gates for ≥ 15 minutes. Run exactly 1 bounded cycle, scored separately. Never let it displace a feasible trade.
9. **Hauler or second probe (human-approved, outside this autopilot):** propose a purchase only when all of these hold:
   - At least 3 consecutive runs were cargo-full while cash ≥ reserve + 3× the ship's price.
   - At least 2 independent pairs keep fresh spreads, so two ships don't just drain one buyer twice as fast. One ship alone moved H47 parts −46% in 76 units.
   - Projected payback, using realized depth rather than top-of-book, is under half the remaining wipe hours.

   Propose a second probe when ≥ 2 freshness holds hit a buyer that has no observer.

## 9. Campaign changes (edits to `docs/campaign-autopilot-plan.md`)

These are minimal changes, kept within the no-purchase/no-contract scope. Changes marked **[code]** need code that the plan does not currently describe.

### 9.1 YAML example: replace the block

```yaml
name: weekly-default
version: 2
agent: { symbol: GIANTSOUP, faction: VOID }   # VOID is the faction this experiment ran in; confirm before import
limits:
  reserve_credits: 20000
  episode_loss_cap: 25000          # stop when credits < start_credits - cap (checked between runs, see 9.2)
  trade_trial_exposure_credits: 25000  # first trade of the episode: one buyer batch, at most this
  trade_exposure_credits: 200000   # hard per-run ceiling; the run is also bounded by loss headroom (9.2)
  run_max_minutes: 15              # one trade round trip took 4-8 min; 15 bounded every run in the experiment
  run_max_cycles: 1                # trade runs are single round trips in OperationPlanner
phases:
  - id: discover                   # hypothesis: idle command time costs more than incomplete coverage
    exit: { any: [ { minutes_elapsed_gte: 15 }, { markets_observed_gte: 2 } ] }
    activities:
      - { role: probe, strategy: scout }     # probe ends parked at the best candidate importer [code: one-way station move]
  - id: earn
    exit: { hours_to_reset_lte: 1 }  # a cycle is ~8 min; 12 h of wind-down gave up income for nothing
    activities:
      - { role: command, strategy: trade }   # selection rules in "Trade selection" below, not a YAML gate
      - { role: command, strategy: mining, when: { minutes_since_feasible_trade_gte: 15 } }  # [code] new metric, optional
  - id: wind_down                  # no new buys; sell or recover owned cargo, then idle
    activities: []
predictions:                       # learning predictions, not income forecasts
  - { metric: first_trade_realized_within_minutes, lte: 30 }
  - { metric: trial_margin_vs_quote_pct, gte: 80 }
  - { metric: pauses_per_day, lte: 2 }
```

What each edit fixes:

- Removes `conservative_margin_gte`. It is not in the gate vocabulary, so validation fails. The trade "conservative margin" is always −inventory − fuel, so the gate could never pass anyway.
- Replaces the 5,000 exposure cap. It would block even the 7,400 CR trial, and 1–2 units per trip forfeits almost all of the measured value.
- Drops mining from `wind_down` and makes it a gated last resort in `earn`. If `minutes_since_feasible_trade_gte` is not built, delete the mining line rather than ordering it unconditionally second.
- Fixes the faction. The example says COSMIC, but this experiment and the agent ran VOID.

### 9.2 Loss cap and exposure (rewrite of the director's step 4 wording)

- The director's step-4 check `credits < start_credits − episode_loss_cap` must not count cargo as a loss. As drafted, it would pause the campaign at the first scaled buy: after C2's buys, credits were 72,353, below the 150,000 floor.
- **Rule:** size every trade run so that `inventory_exposure + fuel_ceiling ≤ credits − max(reserve, start_credits − episode_loss_cap)`. Credits then never cross the floor just by buying. The raw-credits check stays valid at all times, and the engine's existing `inventory ≤ max_loss` semantics hold: set `max_loss` = `max_inventory` = that headroom, clamped to `trade_exposure_credits` (or `trade_trial_exposure_credits` for the first trade).
- The size limit compounds. Starting from 175k with a 25k cap, the hour's actual margins would grow the allowed size from about 6 to 13 to 29 to 40 units in four cycles. The cost is a slower ramp than this hour's (my estimate: about 100k less in the first 30 minutes on a pair this rich). **Taylor decides:** keep the 25k cap, which is the strict reading of the constraint, or raise it (for example to 60k) for a faster ramp. Either way the rule above stays.

### 9.3 New section: "Trade selection" (deterministic, under director step 8)

1. **Candidates.** Candidates are all (source, buyer, good) pairs where both quotes are ≤ 180 s old. A buyer with no observer counts only if `refreshBuyer` can see it at buy time. Without one, cap the load at what fits in the freshness window. In practice that means requiring an observer for any load over one source batch.
2. **Depth-adjusted margin** **[code]:**
   - Project the bid for sell batch k as `bid × (1 − d_sell)^k` and the ask for buy batch k as `ask × (1 + d_buy)^k`.
   - Start from `d_sell = 3%` and `d_buy = 2.5%`. The hour's medians were about 2–4% per batch, rising to 11% when a buyer was saturated.
   - Recalibrate both rates per pair from the episode's own receipts.
   - Choose the unit count where the marginal sell batch still clears its unit cost basis × 1.15. Rank pairs by depth-adjusted margin ÷ (estimated cycle seconds, including positioning).
   - Without this, `OperationPlanner` overstates margins by 0–34%. As a no-code fallback, require top-of-book margin ≥ 1.5 × `min_margin` and keep `min_margin` ≥ 30,000.
3. **Switch trigger.** Re-rank before every run. Leave a pair when its current bid is ≤ 90% of its first-observed bid in this episode, or when another pair ranks ≥ 10% higher. A depleted pair stays out until its bid is ≥ 95% of first-seen.
4. **Trial and scale-up.** The first trade of an episode is one buyer `tradeVolume` batch, and it must fit within `trade_trial_exposure_credits`. Scale only if the realized goods margin is ≥ 80% of the quoted margin and the ledger shows no unexplained difference. Later new pairs need no trial.
5. **Sale floor.** Set `min_sell_price` per run to the run's cost basis per unit plus its fuel share (break-even), not a fixed number. Entry quality is `min_margin`'s job. A fixed 4,800 floor held 18k of profitable cargo for 2 minutes.

### 9.4 Engine behaviours this hour shows are needed

| # | Behaviour | Why (evidence) | In plan? |
|---|---|---|---|
| E1 | **Partial-load continuation.** In the buy phase, a stale remote quote with purchased > 0 should go to `depart` with the owned units instead of `blocked`. | Actions 80 and 93; both runs needed a manual takeover. | **[code]** not in plan |
| E2 | **Probe stationing.** A one-way probe move to the selected buyer, run between command runs. Currently `OperationPlanner::preview` insists on a return to a fuel seller at the start point. | refreshBuyer only refreshes markets where our ship is present. The probe stayed at H47 after the buyer moved to A2. | **[code]** |
| E3 | **Sell-only recovery (step 6):** sell at ≥ cost basis at the best fresh buyer, else go to the alternative buyer, else pause. | parts40-stop-hold.json; four of eight runs left the operator with cargo to resolve. | In plan as a step, but unspecified. Add the floor rule. |
| E4 | **Trade run may end at the buyer** when the next selected source is different, or selection charges the reposition leg. | Manual reroute at actions 76–77 avoided an empty A2→D40 return. | **[code]** (the plan lists multi-stop routes as out of scope; this is single-leg) |
| E5 | **Engine liveness.** `campaign_samples` must record engine state. A faulted engine that stays `awaiting_engine` for over 15 minutes should raise a visible pause reason, plus a supervisor restart if one is possible. | Baseline engine had been faulted for about 43 hours, unnoticed. | Partly. The plan waits on the gate but nothing restarts or flags it. |
| E6 | Optional: cut the per-action cadence (about 15 s now) within the 2 req/s limit. | C2 sell batches took 61 s for 5 sells. | Not in plan; performance only. |
| E7 | Remote-quote window for buying only (local quotes stay at 180 s), e.g. `remote_buy_quote_seconds: 600` used only when E2 isn't possible. The sale floor still guards at the buyer. | A2 bids were unchanged for 6 or more minutes without our sales; H47 plating for 17 minutes. | **[code]/config**. Lower priority than E2. Riskier in an opening-hour crowd. |

### 9.5 Acceptance tests to add

1. The headroom-sized first buy does not trip the loss-cap pause.
2. The trial-then-scale sequence.
3. The switch trigger, using a recorded bid decline.
4. A freshness hold mid-load leads to partial departure (E1) or a recovery step.
5. Without the optional metric, mining is never chosen while a trade candidate is feasible.
6. `wind_down` blocks buys and sells off owned cargo.
7. Validation rejects `conservative_margin_gte`.

## 10. What one late-wipe hour cannot tell us

- **Competition.** Every agent of a faction starts in the same HQ system. In the opening hours, other starter ships will hit the same nearby export→import pairs. This hour shows almost no outside trading on our pairs. Spreads, depth and recovery at a reset are unknown.
- **System generation.** Waypoints, goods and distances regenerate. SHIP_PARTS/SHIP_PLATING at about 2.3× spreads and 45–78 s legs are facts about X1-PZ83 on day 6–7.
- **Recovery rates beyond about 25 minutes**, and whether depleted buyers rebound overnight. That decides whether a week of the same few pairs pays at all.
- **Reliability over 168 hours**: engine faults, rate-limit behaviour, and reset handling.
- **Mining returns, contract value, and new-ship payback.** None was exercised.
- **Any hourly or weekly figure.** 670k/h × 168 is not a forecast: the hour's last 28 minutes earned about 22% less per minute than its first 32 (9.7k vs 12.4k CR/min), as spreads compressed. I make no guaranteed hourly or weekly forecast.

## 11. Evidence paths

All under `C:\Users\taylo\AppData\Local\Temp\spacetraders-live-20261114\` unless stated:

- **Ledger:** `accounting-summary.json`, `hour-cutoff-reconciliation.json`, `baseline.json` (start credits, ship specs, faulted engine), `checkpoint-final.json` (settled credits, ledger), `checkpoint-hour-cutoff.json`. Action union across all `checkpoint-*.json`, recomputed independently.
- **Decisions:** `decisions.tsv`, `lcars-final-report.html`.
- **Holds:**
  - `parts40-stop-hold.json` (floor hold, 02:26:26Z)
  - `a2-parts15-stop.json` (freshness hold, 02:48:49Z)
  - `final-parts30-stop.json` (freshness hold, 02:56:58Z)
  - `parts30-start.json` (stale preview rejected)
  - `a2-plating30-reroute-stop.json`
- **Quotes:** `d39-market-arrived.json`, `h47-fresh.json`, `h47-price-hold.json`, `d40-market.json`, `h47-after-plating40.json`, `a2-before-parts-return.json`, `a2-final-departure-prices.json`, `final-arrival-market.json`.
- **Code read** (`C:\Users\taylo\Projects\spacetraders`, branch `feature/campaign-autopilot`):
  - `app/Gameplay/OperationPlanner.php` (preview margin, buy-phase freshness, static sale floor)
  - `app/Gameplay/AutomationRunner.php` `refreshBuyer`
  - `app/Gameplay/OpportunityAssessor.php` `trade()` (conservative margin = −inventory − fuel)
  - `config/gameplay.php` `snapshot_seconds = 180`
  - `docs/campaign-autopilot-plan.md`

## 12. Missing evidence and uncertainty

- **Fuel allocation per cycle is an estimate**, pro-rated by fuel units, because refuels covered several legs. Totals are exact.
- **The "avoidable loss" figures are counterfactual estimates** (C3 plating-first about 40–50k; freshness holds about 50–80k). They assume the depth curves seen later would have applied, and that the units bought instead would have sold similarly.
- **Some fields are not in the files.** Quote observation times for A2 between visits are inferred from the `at` stamps of the market files and the 180 s rule, not from the database rows. The checkpoint `runs` lack a `progress` field, so the phase timings come from action timestamps.
- **The hour was operated live** (approvals, manual steps), so slot times include operator latency an autopilot would not have. Autopilot code paths (director, recovery) were not exercised.
- **The decay parameters I suggest come from four (pair, buyer) curves in one system.** They are starting values to recalibrate, not constants.
