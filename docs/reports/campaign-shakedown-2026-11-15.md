# Campaign shakedown, 2026-11-15 (old world, before the wipe)

The campaign autopilot played the last 6.6 hours of the Nov 8–15 wipe on its own. Production ran
cd1c7df (PR #101) from 06:15Z, then 31d6212 (PR #102) from about 07:08Z. Grant 1 (playbook
`weekly-default`, revision 1, hash `e99e76b4…`) was approved at 06:16Z and expired at 12:55Z.
Raw outputs: [campaign-shakedown-2026-11-15/](campaign-shakedown-2026-11-15/) (`campaign:report`
Markdown and JSON, final `campaign:status`, watchdog log).

## Result

| | |
| --- | ---: |
| Hours under the grant | 6.6 (06:16–12:55Z) |
| Start credits | 845,384 |
| End credits | 1,288,056 |
| Net | **+442,672** |
| Hours actually trading | 1.5 (07:11–08:40Z) |
| Uncertain actions, director errors, safety pauses | 0, 0, 0 |
| Losing loads | 0 |

All the money came in the first 90 minutes of trading, about 300k per hour. The remaining 4.3 hours
were idle because of the bug described below.

## Trade loads

Ten loads, all profitable. Realized 442,816 against 412,656 predicted (1.07×). Each new pair started
with a 2-unit proof that passed, then scaled.

| Run | Good | Buyer | Mode | Predicted | Realized |
| --- | --- | --- | --- | ---: | ---: |
| 64 | SHIP_PARTS | H47 | proof | 7,102 | 7,606 |
| 65 | SHIP_PARTS | H47 | scaled | 61,533 | 64,704 |
| 66 | SHIP_PARTS | H47 | full | 75,576 | 60,363 |
| 68 | SHIP_PLATING | H47 | proof | 6,402 | 6,982 |
| 69 | SHIP_PLATING | H47 | scaled | 55,818 | 56,553 |
| 71 | SHIP_PLATING | A2 | proof | 7,034 | 7,466 |
| 72 | SHIP_PLATING | A2 | scaled | 60,897 | 65,091 |
| 73 | SHIP_PLATING | A2 | full | 87,903 | 78,498 |
| 75 | SHIP_PLATING | H47 | full | 34,278 | 36,636 |
| 80 | SHIP_PARTS | H47 | full | 16,113 | 58,917 |

Full-size loads came in 10–20% under prediction (runs 66 and 73), because our own selling pushed the
bid down faster than the default 4% per batch. The proofs and scaled loads were within ±8%. Runs 75
and 80 were handed off mid-run with cargo aboard, and a sell-aboard recovery (runs 77 and 82) sold
it at a profit. That is why the run table in the raw report shows those two trade runs as negative.
The load results above count each trade together with its recovery.

The switching rules worked: the director moved from PARTS to PLATING, and from buyer H47 to A2 and
back, as bids fell. Scouting and probe positioning cost 288 CR in fuel over 61 runs.

## What went wrong

1. **Start starvation, 06:16–07:11Z (fixed).** Each tick spent its 12-second budget on reads, so
   the start command was always deferred and the preview thrown away. That left 53 previews that
   never started. PR #102 retries a deferred start first on the next tick. It was deployed at about
   07:08Z, the campaign resumed on its own after the deploy, and it traded within 3 minutes. This is
   also why `minutes_to_first_profit` (63.6 against ≤45) failed: a code bug, not the strategy.
2. **Fuel-evidence stall, 08:40–12:55Z (not fixed yet).** Both ships ended up docked at A2. Every
   30-second tick then rejected all trades:
   - 35 pairs had no positive spread left;
   - the 3 profitable ones failed with "No fuel-safe continuation to a verified affordable fuel
     seller", because the fuel prices at other markets had gone stale and only a ship standing at a
     market refreshes its fuel price.

   The command ship had 304 of 400 fuel aboard, plenty for any trip inside the system. The mining
   fallback also failed every tick ("Buyer prices are unknown or stale", plus a STRIPPED site). This
   is the same evidence deadlock that PR #102 fixed for cargo recovery, but in the trade path.
   **Expect it in the new wipe too**, once the first easy spreads narrow.
3. **Report noise.** The 53 never-started previews from issue 1 appear as zero-value scout runs in
   the raw report.

## Waits, holds, budget, deploys

- **Pauses:** one, `grant_expired` at 12:55Z, as planned. No freshness holds, no losing-load
  pauses, no director errors.
- **Deploys:** one mid-run deploy (31d6212). The campaign resumed by itself.
- **Requests:** 3,747 between samples over about 6.6 hours, about 0.16 per second on average. That is
  far under the 2 per second limit, so the budget was never the real constraint. The start
  starvation came from the per-tick deadline.
- **Wind-down** began at 12:00Z. No cargo was aboard and no buys were made.

## Watchdog

Alerts went to the log and to Discord. The log has no "Webhook delivery failed" lines.

- 09:47Z and 11:47Z: `campaign_progress_stalled` (no run or load for 60 minutes). This correctly
  caught issue 2, and repeated at the 2-hour interval.
- 12:02Z: recovered (wind-down suppresses the progress check).
- 13:02Z: `campaign_paused:1:grant_expired`.

## Predictions

| Prediction | Target | Actual | Result |
| --- | --- | ---: | --- |
| proof_realized_ratio | ≥ 0.8 | 1.06 | pass |
| minutes_to_first_profit | ≤ 45 | 63.6 | fail (start-starvation bug) |
| freshness_holds | ≤ 2 | 0 | pass |

## Decisions for this wipe

- **Playbook: approve `weekly-default` revision 1 as is.** The proofs, scaling and switching behaved
  as designed, and no setting caused the losses of time. One candidate tweak to watch, not to make
  yet: `trade.lot.default_batch_decay` 0.04. Full loads realized 80–90% of prediction, which
  suggests about 0.06 in a mature market. The new wipe's opening markets have fresh depth, and the
  director learns decay per buyer within the episode anyway.
- **Code fix before or soon after kickoff: the fuel-evidence stall (issue 2).** It will not stop the
  first hours of the new wipe, because fuel prices are fresh while the ship moves between markets.
  But it will idle the ship once it parks somewhere with stale fuel prices, and the watchdog only
  alerts after 60 minutes. The fix:
  - when trade candidates fail only on stale fuel-seller evidence, send the probe (zero-fuel
    navigation already exists) to refresh the fuel price;
  - or allow the trip when fuel aboard already covers the outbound leg, the next leg and the reserve;
  - same pattern as the PR #102 recovery fix, plus a regression test.

  Kickoff does not need to wait for it. The campaign resumes on its own after the deploy that ships
  the fix.
- **Wipe status at 13:02Z:** the public API returns 503 "The universe is being reset". The director
  waits on that and registers GIANTSOUP (faction VOID) once the new reset is live, using the saved
  account token (confirmed present at 06:16Z).
