# One-hour trade test, 2026-11-14: audit and lessons for the campaign

Measured hour: 2026-11-15 02:03–03:03 UTC (6:03–7:03 PM PST, Nov 14), late in the Nov 8–15 wipe,
starter ships only (GIANTSOUP-1 command frigate, GIANTSOUP-2 probe), system X1-PZ83.

Audited independently by Claude Opus 5.5, GPT-6.1 Sol and Muse Wonder 1.0 (analysis only; no
gameplay). Their full reports are in [council/](one-hour-trade-test-2026-11-14-evidence/council/). Raw
artifacts stay in `C:\Users\taylo\AppData\Local\Temp\spacetraders-live-20261114\` (temporary; checkpoint
files are not copied because they contain account details). The small summary files are copied to
[one-hour-trade-test-2026-11-14-evidence/](one-hour-trade-test-2026-11-14-evidence/).

## Verdict

All three agree the reported numbers are exact. Trading SHIP_PARTS and SHIP_PLATING with the command
ship turned 175,000 CR into 845,384 CR settled. No lot lost money on its merchandise. The method
— tiny proof trade, then scale, then switch goods and buyers as prices fall — worked in a mature
market. The hour does **not** establish mining returns, contract value, ship payback, opening-hour
prices or competition, or any hourly or weekly forecast.

## Reconciled accounting

Counted once per unique action (GPT Sol: 102 confirmed actions, 53 money receipts; Opus and Muse
agree on totals).

| Item | Exact hour (cutoff 03:03Z) | Fully settled (03:04:04Z) |
| --- | ---: | ---: |
| Starting cash | 175,000 | 175,000 |
| Cargo purchases (14 buys) | −753,845 | −753,845 |
| Cargo sales (33 sells, last 03:01:44) | +1,425,093 | +1,425,093 |
| Fuel | −720 | −864 (home refuel 144 at 03:04:04) |
| Closing cash | **845,528** | **845,384** |
| Net gain | **+670,528 (+383.2%)** | **+670,384 (+383.1%)** |
| Unexplained difference | 0 | 0 |

At the cutoff the ship was empty and in transit home (arrived 03:03:07, docked 03:03:33). Only the
settled figure matches the starting physical state (same place, full tank). By good: SHIP_PARTS
117 units, cost 477,345, revenue 886,125; SHIP_PLATING 70 units, cost 276,500, revenue 538,968.
Net cash only; wear and labor are unpriced.

## Trade cycles

Fuel is the actual bill posted in that block, so shared and deferred bills are not lot costs
(GPT Sol's report has a prorated estimate). Waypoints are X1-PZ83-*.

| Lot | Good, source → buyer | Units | Start → last sale (UTC) | Cost | Revenue | Net (posted fuel) |
| --- | --- | ---: | --- | ---: | ---: | ---: |
| 1 | PARTS D39 → H47 (proof) | 2 | 02:10:23 → 02:12:10 | 7,400 | 16,902 | 9,358 |
| 2 | PARTS D39 → H47 | 30 | 02:15:27 → 02:18:34 | 112,005 | 247,758 | 135,681 |
| 3 | PARTS D39 → H47 (+manual sale) | 40 | 02:21:13 → 02:26:54 | 159,085 | 260,130 | 101,045 |
| 4 | PLATING D40 → H47 | 40 | 02:29:14 → 02:33:21 | 154,730 | 307,122 | 152,248 |
| — | Scout D40 → A2 → D40 | 0 | 02:36:24 → 02:40:20 | 0 | 0 | −144 |
| 5 | PLATING D40 → A2 | 30 | 02:40:47 → 02:44:22 | 121,770 | 231,846 | 110,076 |
| 6 | PARTS D39 → A2 (freshness hold, manual delivery) | 15 | 02:47:28 → 02:52:30 | 63,120 | 126,723 | 63,387 |
| 7 | PARTS D39 → A2 (freshness hold, manual delivery) | 30 | 02:55:46 → 03:01:44 | 135,735 | 234,612 | 98,733 |

## What drove the result

- **Large spreads on manufactured goods.** Parts bought at ~3,700–4,650 sold at ~7,300–8,500.
- **Our own volume moved prices.** Buyers take 6 units per transaction, sources 15. Each further
  batch sold lower: the H47 parts bid fell from 8,517 to 4,593 across lots 2–3, and the D39 ask rose
  from 3,700 to 4,645. Lot 3 (40 units) made less than lot 2 (30 units); pricing every unit at the
  first quote overstated it by ~48,000 CR.
- **Switching paid.** Parts → plating at H47 (lot 4, the best lot) and H47 → A2 after a 144 CR scout.
- **Travel was short.** 16m40s of flight in the hour; legs of 45–78 s. The rest was batching, dispatch
  cadence, approvals and holds.
- **Freshness holds** (lots 6–7): the A2 buyer quote is valid 180 s, and nobody was parked at A2 after
  the ship left, so it expired mid-loading. 25 units were never bought. That is not a loss. The guard was
  correct, but delivery needed a human because blocked runs have no automatic recovery.
- About 57% of cash moved through manual steps (Opus estimate). An unattended engine must cover those.
- The engine had been **faulted for ~43 hours** before the session without anyone noticing (Opus).

## Strongest decisions and avoidable delays

| Kind | What | Evidence |
| --- | --- | --- |
| Strong | 2-unit proof before scaling | buys #4 02:10:36, sale #8 02:12:09, refuel #12 |
| Strong | Stale approval rejected by fingerprint, re-previewed in 34 s | `parts30-start.json`, `parts30-start-v2.json` |
| Strong | Parts → plating when H47 parts bid collapsed | `h47-price-hold.json` 02:26:16, sales #49–55 |
| Strong | Cheap scout found the better A2 buyer | run 6, refuel #64, sales #70–74 |
| Strong | Delivered owned cargo without buying on stale quotes | `checkpoint-1848.json`, `checkpoint-1856.json` |
| Gap (P0) | A blocked run needs a manual takeover; the draft director would wait forever | holds above; `AutomationRunner.php:151` |
| Gap | 40-unit lot into a falling, low-volume buyer | #27–39, 02:21–02:26 |
| Gap | Fixed 4,800 sale floor held the last 4 units for 2m05s | #38 02:24:48 → #39 02:26:53 |
| Gap | Buying on a remote quote that would expire before loading finished | quote-age table in GPT Sol's report |

## Against the researched opening plan

Tested and confirmed: time-boxed discovery, small proof trade before scaling, full-cycle accounting,
switching when margins fall, keeping the reserve. Untested: mining, contracts, buying a hauler or
probe, opening-hour market behavior, other players competing for the same pairs.

## What this cannot tell us

One hour, one ship, one system, a mature wipe. Opening markets may have different prices, more
competition on the same routes, and a different starting system where these goods may not trade.
We cannot separate our price impact from other players'. No return here should be used as a
forecast.

## Changes made to the campaign plan

Applied to [campaign-autopilot-plan.md](../campaign-autopilot-plan.md) on 2026-11-15:

1. Trade is the earning activity. Mining is a last resort, used only after 15 minutes with no workable trade.
2. The `conservative_margin_gte` gate is removed. The code's conservative margin assumes cargo is
   never sold, so it is always negative and would block every trade
   (`OpportunityAssessor.php:182`, `OperationPlanner.php:56`).
3. A proof trade (≤ one buyer batch, ≤ 10,000 CR) runs before scaling. The proof must realize
   ≥ 80% of its quoted margin.
4. Lot size is capped by buyer volume (≤ 5 buyer batches), the reserve, and the loss headroom. Revenue
   is predicted with per-batch price decay, learned from our own sales.
5. Good or buyer switches when its bid falls to 90% of the first-seen bid this episode, or when
   another pair is predicted to earn ≥ 10% more per minute.
6. The loss check counts cargo at cost, so buying is not a loss. Checks run between transactions.
   A breach stops buying and sells what is aboard before pausing.
7. Freshness stays 180 s. A buy proceeds only if the quote outlives the planned loading. The probe parks
   at the chosen buyer, so its quotes stay fresh. On a hold, the run delivers owned cargo instead of waiting.
8. One load per run (≤ 15 min). The director handles blocked runs before its "run active" early return.
9. Discovery takes 15 minutes or 3 detailed markets. Wind-down starts 1 hour before reset and only sells.
10. Predictions are learning metrics (proof realization, per-batch decay, time to first profit,
    holds, idle minutes), not a credit target.
