# Muse Wonder — independent audit of the 2026-11-14 one-hour SpaceTraders experiment

Analyst: Muse Wonder (council leg, analysis only). No gameplay, API calls, purchases, or repo/production changes were made by this leg. Helper scripts (`accounting.py`, `ops.py`, `client.py`) were read, not executed; all totals below were recomputed independently from the transaction receipts in `accounting-summary.json` (53 unique transaction ids, no duplicates) and cross-checked against `baseline.json`, `checkpoint-hour-cutoff.json`, and `checkpoint-final.json`.

## 1. Concise verdict

The reported result is **arithmetically exact and receipt-backed**: +670,384 CR settled, zero unexplained difference, all cargo sold, ship home and refueled. The operating method — probe-test small, scale only after a *realized* (sold, not quoted) profit, switch good/buyer when tranche prices decay, refuse stale-quote buys — is validated for one frigate in one mature system over one hour. It is **not** evidence about opening-hour prices, mining, contracts, new ships, or a weekly rate. The single most important transfer lesson is negative: the draft campaign's 5,000 CR exposure cap, 10-cycle trade runs, unconditional mining fallback, and cash-only loss-cap check would each have broken or misdirected this exact winning pattern. Specific minimal edits are in §9.

## 2. Reconciled accounting (independent recomputation)

Starting cash is from `baseline.json` (2026-11-15T02:02:25Z, agent credits 175,000, GIANTSOUP-1 docked A1, 400/400 fuel, empty 40-unit hold; GIANTSOUP-2 satellite at H47, 0 cargo / 0 fuel capacity). Receipts were deduplicated by transaction id across checkpoints — checkpoints repeat the full action ledger, so summing per-checkpoint files would double-count; the 53 transactions below are the unique set.

| Item | CR | Evidence |
|---|---:|---|
| Starting cash (02:02:25Z) | 175,000 | `baseline.json` |
| Cargo sales (33 sell actions; 117 SHIP_PARTS + 70 SHIP_PLATING) | +1,425,093 | tx ids 8,18–22,33–39,49–55,70–74,84–86,97–101 |
| Cargo purchases (14 buy actions) | −753,845 | tx ids 4,13,14,27–29,43–45,65,66,79,91,92 |
| Fuel purchases ≤ cutoff (5 refuels, 720 CR) | −720 | tx ids 12,26,59,64,87 |
| **Cash at 03:03:00Z cutoff** | **845,528** | = 175,000+1,425,093−753,845−720; matches `checkpoint-hour-cutoff.json` (snapshot 03:03:49Z) and `hour-cutoff-reconciliation.json` |
| Final home refuel, after cutoff (03:04:04Z) | −144 | tx id 105 |
| **Settled cash** | **845,384** | `checkpoint-final.json` (03:04:38Z) |
| Settled net | **+670,384 (+383.09% on 175,000)** | 845,384−175,000 |
| Unexplained difference | **0** | independently recomputed, matches `accounting-summary.json:812` |

Per-good margins (before fuel): SHIP_PARTS bought 117 u for 477,345 (avg 4,079.9), sold 117 u for 886,125 (avg 7,573.7) → **+408,780**. SHIP_PLATING bought 70 u for 276,500 (avg 3,950.0), sold 70 u for 538,968 (avg 7,699.5) → **+262,468**. Goods margin 671,248 − total fuel 864 = 670,384. ✔

**Exact-hour vs settled (§3 of brief):** the one-hour window (02:03–03:03Z) result is **+670,528** (175,000 → 845,528, no inventory held at cutoff — the last sale, tx 101 at 03:01:44Z, preceded the deadline). The fully settled result is **+670,384**; the only post-cutoff cash event is the 144 CR home refuel (tx 105, 03:04:04Z), authorized as completion of a shipment already underway. No other post-cutoff transactions exist in the receipt set. Caveat carried from the source files: no charge for wear/depreciation (`lcars-final-report.html`: "no assigned charge for wear"); run accounting in checkpoints flags wear as unmeasured, so this is net *cash*, not full economic profit.

Final state verified in `checkpoint-final.json`: GIANTSOUP-1 DOCKED at X1-PZ83-A1, cargo 0/40, fuel 400/400; GIANTSOUP-2 at X1-PZ83-H47; at cutoff the ship was already docked at A1 with 217/400 fuel and empty hold.

## 3. Trade-cycle timeline (reconstructed, logical cycles)

Runs in the app do not map 1:1 to economic cycles: runs 4, 7, 8, 9 were stopped/blocked and finished manually. Cycles below are grouped by cargo lot from receipts. "Span" = first buy → last sell (includes outbound travel and sell tranches; excludes positioning to the source, empty return, and refuel — return/refuel legs are shared between consecutive cycles, so per-cycle fuel is **not** separable from receipts alone and is labelled as such).

| # | Cycle (UTC) | Route / good | Qty | Cost (avg/u) | Revenue (avg/u) | Goods profit | Span | Notes |
|---|---|---|---:|---:|---:|---:|---|---|
| T1 | 02:10:36–02:12:09 | D39→H47 SHIP_PARTS (run 1) | 2 | 7,400 (3,700) | 16,902 (8,451) | +9,502 | 93 s | Probe test. Run elapsed 219 s incl. return; run fuel_cost 144 (tx 12, incl. initial scouting fuel per `decisions.tsv:4`) → run net +9,358 |
| T2 | 02:15:41–02:18:33 | D39→H47 SHIP_PARTS (run 3) | 30 | 112,005 (3,733.5) | 247,758 (8,258.6) | +135,753 | 172 s | Scale-up after T1 realized. Run net +135,681 after 72 fuel (tx 26). Sell tranches 8,517→7,941 (−6.8%) |
| T3 | 02:21:27–02:26:53 | D39→H47 SHIP_PARTS (run 4 + manual) | 40 | 159,085 (3,977.1) | 260,130 (6,503.2) | +101,045 | 326 s | Tranches collapsed 7,706→5,413 (−29.8%); run blocked at 5,413 vs 4,800 floor (`checkpoint-1826.json`), final 4 sold manually at 4,593 (tx 39, 02:26:53) |
| T4 | 02:29:43–02:33:20 | D40→H47 SHIP_PLATING (run 5) | 40 | 154,730 (3,868.2) | 307,122 (7,678.1) | +152,392 | 217 s | Product switch beat decayed parts market. Run net +152,248 after 144 fuel. Tranches 8,149→6,930 (−15.0%) |
| — | 02:36–02:40 | Scout A2, no cargo (run 6) | 0 | — | — | −144 (fuel) | 236 s run | Found A2 importing both goods; H47 parts bid had fallen to 3,944 (`decisions.tsv:9`) |
| T5 | 02:40:59–02:44:22 | D40→A2 SHIP_PLATING (run 7, stopped, sold in-run) | 30 | 121,770 (4,059.0) | 231,846 (7,728.2) | +110,076 | 203 s | Buyer switch. Tranches 7,964→7,438 (−6.6%). Refuel tx 64 (144, D40) preceded this lot — shared/positioning fuel, not in run totals |
| T6 | 02:47:52–02:52:30 | D39→A2 SHIP_PARTS (run 8 + manual delivery) | 15 | 63,120 (4,208) | 126,723 (8,448.2) | +63,603 | 278 s | Freshness hold blocked the 2nd 15 (see §4); owned 15 delivered manually. Refuel 216 at A2 (tx 87) after sale |
| T7 | 02:56:09–03:01:44 | D39→A2 SHIP_PARTS (run 9 + manual delivery) | 30 | 135,735 (4,524.5) | 234,612 (7,820.4) | +98,877 | 335 s | Planned 40, hold rejected final 10 **before purchase**; 30 delivered and sold in 5 tranches 8,240→7,305 (−11.3%), all before cutoff |

Sum check: goods profit 9,502+135,753+101,045+152,392+110,076+63,603+98,877 = 671,248; minus all fuel receipts 864 = **670,384** ✔. Source ask drifted upward all hour (parts tranches 3,700 → 4,645, +25.5%; plating 3,805 → 4,116), so each successive cycle started from a worse cost base — returns were earned against a deteriorating spread, not a static one.

Elapsed-time honesty: buy→sell spans (1.5–5.6 min) flatter the true cycle rate. Wall-clock per cycle including positioning, return, preview/approval and manual rescue was roughly 5–10 min; the hour produced 7 cargo cycles plus one scout. Any CR/hour figure derived from spans alone would be an estimate, and I do not annualize it (§8).

## 4. How the mechanics shaped returns

**Price movement / tranche decay.** Every load sold in 6-unit tranches (buyer `tradeVolume` = 6 at both H47 and A2 for both goods) and every tranche repriced downward: −6.6% to −29.8% first-to-last within a single load. The first quoted bid is therefore never the load's realized price. T3 is the cautionary case: by tranche 6 the bid (5,413) approached the rising source ask, and the residual 4 units cleared at 4,593 against a 3,977 avg cost — still profitable, but the marginal unit earned ~600 CR versus ~4,700 for the first tranche. Conversely A2's *first* parts tranche (8,532, tx 84) exceeded anything H47 paid after T2, which is why buyer-switching, not just product-switching, mattered.

**tradeVolume limits.** Buys were capped at 15 units/tranche at D39/D40, sells at 6/tranche at buyers. A 40-unit hold is 7 sell decisions spanning ~90 seconds of repricing; the plan's margin check must use a decayed (e.g. last-tranche or tranche-weighted) price, not the top quote. `OpportunityAssessor.php:178` sizes a candidate by `min(cargo, ask tradeVolume, bid tradeVolume, affordable)` — one batch only — while `OperationPlanner.php:50-51` projects the *full* unit count at the *first* bid. That mismatch is the main estimator flaw this experiment exposes.

**Travel.** All routes were intra-system CRUISE legs (D39↔H47 ≈ 43 units, D39↔A2 ≈ 91 units per previews). Fuel was financially trivial (864 CR total, 72 CR/unit, ~0.13% of goods margin) but *time* was not: A2's longer leg is part of why its quote went stale before buying finished (below). Fuel cost should never gate these trades; elapsed ship-time should.

**Scouting.** H47 visibility was free — the starter satellite GIANTSOUP-2 sits there (it has 0 fuel/0 cargo and cannot move; do not plan on repositioning it). A2 visibility cost one empty scout run (run 6: 236 s, 144 CR fuel) and repaid it many times over: A2 took 30 plating (+110,076 goods) and 45 parts (+162,480 goods across T6+T7) at prices H47 no longer offered. But a scouted quote is perishable (next point).

**Freshness holds.** The app requires market observations ≤180 s old (`config/gameplay.php: snapshot_seconds = 180`; enforced in `OperationPlanner::quote()`, `OperationPlanner.php:169`). Twice this blocked *further purchases*: run 8 (planned 30 parts to A2; A2 quote timestamped 02:45:06 in `a2-parts30-preview.json`, blocked in `checkpoint-1848.json` — "Detailed prices at X1-PZ83-A2 for SHIP_PARTS are unknown or stale", buy action 80 rejected) and run 9 (planned 40; blocked in `checkpoint-1856.json`, action 93 rejected). In both cases the already-owned cargo (15 and 30 units) was flown to A2 and sold manually at excellent prices — the hold cost **time and operator attention, zero credits**; the rejected batches were never bought (`decisions.tsv:11-12`). Structurally, the hold is near-unavoidable in the current engine: `AutomationRunner::refreshBuyer()` (`AutomationRunner.php:258-264`) can only refresh a buyer quote if one of your ships is *at* that buyer, so a quote taken by a departing scout decays during the source-side buy tranches. This is an engine-behavior finding, not an operator error.

## 5. Strongest decisions; largest avoidable delays/losses

**Strongest (evidence-tagged):**
1. **2-unit probe before scaling** — `decisions.tsv:3`, tx 4/8: risked 7,400 to prove the full loop (buy, travel, sell, return, refuel) realized +9,358 before committing 112,005. This is the correct template for the autopilot's first trade.
2. **Scaling only on realized, not quoted, results** — `decisions.tsv:5-6`: the 30-unit scale-up followed T1's *settled* cash (184,358), and a stale first preview (run 2) was rejected and re-previewed rather than forced (`parts30-preview.json` → `parts30-preview-v2.json`).
3. **Product switch when parts decayed** — T4 plating earned the hour's largest goods profit (+152,392) immediately after T3's parts tranches collapsed (`decisions.tsv:8`).
4. **Empty scout to A2 instead of another H47 load** — `decisions.tsv:9-10`: cost 144 CR + ~4 min; unlocked the A2 buyer for the rest of the hour (+272,556 goods profit across T5–T7 attributable to loads sold at A2).
5. **Obeying freshness holds and selling owned cargo rather than re-quoting-and-buying blind** — `decisions.tsv:11-12`: no stale-quote purchase was ever made; no cargo was ever stranded unsold.

**Largest avoidable delays/losses:**
1. **Selling all 40 parts into a collapsing H47 bid (T3).** Tranches fell 7,706→5,413, then 4,593 (`h47-price-hold.json`, tx 33–39). The last 10 units averaged 5,085 vs 7,706 for the first tranche — roughly **26,000 CR of within-load decay** (estimate: counterfactual prices at another buyer were unobserved at that moment, so this is decay *measured*, not loss *proven*). Earlier buyer/product switching — the move made 10 minutes later — was the fix. The run's own 4,800 floor blocked at 5,413 (`checkpoint-1826.json`) and still required a manual sale.
2. **Manual rescue time after freshness holds:** ~02:48–02:53 (T6) and ~02:56–03:02 (T7) of hand-flown delivery (orbit/dock/sell approvals). No credit loss; the avoidable part is that an *automated* campaign would have sat blocked — see §9 engine items.
3. **Run 2 stale-preview rejection** (`decisions.tsv:6`): ~1–2 min lost re-previewing at 02:14–02:16. Minor; the guard worked as designed.
4. **Initial ~7.5 min** (02:03 sync → first buy 02:10:36) scouting/positioning in a system whose observations already existed from Nov 13. Not avoidable here (state had to be verified), and at a real reset it will be longer, not shorter.

## 6. Tested vs untested against the researched opening plan

The pre-wipe research (`docs/wipe-strategy-research-2026-11-14.md`, `docs/reports/wipe-strategy-2026-11-14.html`) recommended: time-boxed discovery, one small export→import trade on the existing command ship, mining as measured fallback, contract evaluation before acceptance, no ship purchase without measured payback, 20,000 CR reserve, full-cycle accounting.

| Idea | Status after this hour |
|---|---|
| Small trade first, then scale on realized result | **Tested, worked** (T1→T2) |
| Fresh prices at both ends before buying; refresh per batch | **Tested, worked** — and the 180 s guard demonstrably fired (runs 8/9) |
| Export→import route (D39/D40 exports → H47/A2 imports) | **Tested, worked** for SHIP_PARTS/SHIP_PLATING in this system |
| Switch good/buyer when spread weakens | **Tested, worked** (T3→T4 product; H47→A2 buyer) |
| Keep 20,000 CR reserve; bounded exposure per run | **Tested** (all previews carried reserve 20,000; cash never approached it — but see §9: exposure used was 7,400–176,160, far above the draft campaign's 5,000) |
| Mining complete-cycle comparison | **Untested.** Zero extraction actions. GIANTSOUP-1 *is* mining-capable (MOUNT_MINING_LASER_II + MODULE_MINERAL_PROCESSOR_I + MOUNT_SURVEYOR_II in `baseline.json`) but no yield, cooldown, or sale data exists |
| Contract income | **Untested.** `contracts.json` (02:04:34Z) returned an empty list; nothing to evaluate |
| Hauler/probe purchase payback | **Untested.** No prices for ships were even recorded this hour |
| Multi-cycle repeating runs, jump gates, refining, siphoning | **Untested** (all trade previews were `cycles: 1`) |
| SPECTER's ~83k CR/h trading / 12–20k mining figures (research §"Additional evidence") | **Not comparable**: different operator, world state, and accounting; this hour neither confirms nor refutes them |

## 7. Concrete next-wipe opening (2026-11-15T13:00Z reset)

Starter assets, faction, starting credits, and system layout at the new reset are **unknowns to discover, not constants** (this agent was created 2026-11-13 with faction VOID; do not script COSMIC, 175,000 CR, or X1-PZ83 waypoints).

1. **Confirm the reset actually happened**, register/recover the agent, sync agent + all ships; record reset id, credits, each ship's role, cargo/fuel capacity, mounts (mining capability), and position. (~5 min)
2. **Discover listings, not prices**: catalogue the home system, mark marketplaces, fuel sellers, shipyards, asteroid sites. Prices older than 180 s are worthless for execution, so discovery's durable output is the *route shortlist* (which source exports what, which waypoints import it), capped at ~15 min.
3. **Get one fresh source+buyer pair**: position the command ship (or an already-present stationary probe) to observe a buyer that imports a good some reachable source exports. If a starter probe exists somewhere useful, use its free visibility first — check whether it can move before assigning it anywhere (this wipe's could not).
4. **Probe trade**: buy `min(2, source tradeVolume)` units, exposure ≤ 10,000 CR, only if the full-cycle estimate (decayed-bid revenue − ask cost − fuel ceiling − return) is positive and cash after the buy stays ≥ 20,000 reserve + fuel. Sell fully, return, refuel; record realized net and elapsed ship-time.
5. **Scale in steps** (rule in §9): only after a *closed* cycle nets positive; re-preview every cycle (prices moved +25% on asks within this single hour); never repeat a run on autopilot while tranche prices are falling >15% within a load.
6. **Switch triggers**: change buyer when a scouted alternative's fresh first-tranche bid exceeds the current buyer's *last realized tranche* by more than the extra travel time costs; change product when the current good's projected next-load margin (at decayed prices) falls below an alternative good's at the same buyer — exactly the T3→T4 and H47→A2 moves.
7. **Mining test** (learning goal, bounded): only when no trade passes the margin gate, or in one deliberate early slot — one extraction→sale→return→refuel cycle with the existing laser/processor ship, full accounting, compared on CR/ship-hour against the trade cycles. Do not let mining silently become the default earner on zero measurements.
8. **Hauler/probe purchases: human decision only** (out of autopilot scope). Recommend only when measured: a hauler when 40-unit cargo is the binding constraint *and* marginal tranches are still profitable at full-hold decayed prices *and* projected added CR/ship-hour repays the ship within half the remaining week; a (movable) probe when the same buyer needs re-scouting ≥3 times and scout trips' displaced trading exceeds the probe's payback. This hour meets neither test — cargo decay, not cargo size, was the binding constraint.
9. **Contracts: human decision only.** Evaluate any offered contract's total payments against sourcing + fuel + displaced trade income, never counting the advance twice; this hour offers no data.

## 8. What cannot be inferred from one mature-wipe hour

- **Opening conditions.** This was day ~6.5 of the Nov 8–15 wipe, in markets other players (e.g. WHATER ships visible in market transaction histories) had traded all week. Opening-hour spreads, source asks, and competition at a fresh reset may be better or worse; nothing here measures them. The +383% is a percentage of a 175,000 stake in *this* state, not a rate.
- **No hourly/weekly forecast.** Seven cycles, one ship, one system, one good pair, prices drifting adversely throughout (asks +25%). Extrapolating 670k/hour or any weekly total would be fabrication. The campaign's job in week one is to *generate* the distribution, not assume it.
- **Mining, contracts, siphoning, refining, jump gates, multi-ship coordination:** zero observations.
- **Ship/equipment prices and payback:** zero observations; no shipyard data was captured.
- **Wear/repair economics:** explicitly unmeasured in every run's accounting block; long-run net could be lower.
- **Durability of the A2/H47 spreads:** both buyers were being sold into by others before and during the hour; the decay curves suggest spreads compress under volume — including the autopilot's own volume.
- **Failure modes not exercised:** no uncertain writes, no API errors, no unsold-cargo end state, no reserve breach. The loss cap has never been observed firing.
- One operator actively rescued two blocked runs by hand. An unattended campaign facing the same blocks is the untested case — which is precisely what §9 must fix before the reset.

## 9. Campaign changes — specific minimal edits to `docs/campaign-autopilot-plan.md`

All items stay inside Taylor's scope (no ship purchases, no equipment changes, no contract acceptance, one active run per agent, 20,000 reserve, cumulative loss cap, small gate vocabulary). Items marked **[engine]** need code/behavior that the current plan text does not yet specify; items marked **[YAML]** are file-only edits to the example in the plan (lines 40–63).

1. **[YAML] Exposure cap 5,000 → tiered by phase.** `trade_exposure_credits: 5000` (line 47) would have prohibited even the proven 2-unit test (7,400 at 3,700/u). Replace the single limit with per-phase exposure via two earn phases (uses only existing gates):
   - `prove` phase: `trade_exposure_credits: 10000`, exit `{ cycles_completed_gte: 1 }`;
   - `earn` phase: `trade_exposure_credits: 75000` with the existing reserve check (`credits ≥ reserve + max_spend`) still binding at low balances.
   Rationale: 10,000 matches the researched test budget; 75,000 permits a ~15–18-unit load at this hour's prices without allowing the 159–176k single loads a human chose with 320k+ cash in hand. State the hypothesis in the file comment, per the plan's own revision discipline (line 102–103).
2. **[YAML] Trade cycles per run = 1.** Add `cycles: 1` semantics for trade activities (or set `run_max_cycles: 1` for trade; keep higher only where a strategy revalidates). This hour's every preview was `cycles: 1`, and re-previewing between loads is what caught the H47 collapse. Ten auto-repeats of T3 would have kept buying into a −30% tranche decay. The director already re-evaluates between runs (step 8) — make that the loop.
3. **[YAML] Remove mining from `earn` until measured; add one bounded mining trial.** Current YAML (lines 56–58) falls through to mining whenever `conservative_margin_gte: 1` fails — with zero mining measurements, that makes the *unproven* strategy the default. Change `earn` activities to trade-only, and add a single explicit trial: either a `prove`-phase sibling activity `{ role: command, strategy: mining, when: { cycles_completed_gte: 0 } }` capped at 1 cycle, or a director rule "one mining cycle before earn if the ship is mining-capable, for learning." Also fix the gate definition: `conservative_margin_gte` must be defined — in existing code `conservative_cash_margin` is `−inventory − fuel` (`OperationPlanner.php:56`), i.e. always negative, which would make trade permanently infeasible and mining permanently chosen. Define the gate metric as *estimated margin at decayed prices* (item 5) or reuse `min_margin` from trade previews; do not ship the gate undefined.
4. **[YAML] Wind-down: sell/recover only, no new mining.** Line 59–60's "mining and selling only" still creates inventory near reset. Change wind_down activities to sell-only recovery of any cargo (director step 6 already builds this) and drop mining unless a full extraction→sale cycle can close before reset.
5. **[engine] Tranche-decay margin model for trade selection.** Require the trade feasibility check to project revenue across `tradeVolume`-sized tranches with a decay assumption (minimum viable: use the buyer's last realized tranche price from this episode, or a fixed −5%/tranche haircut for tranches beyond the first) instead of `units × first bid` (`OperationPlanner.php:51`). Add the switching triggers as run rules, in the existing blocked-step style: (a) stop buying a good/buyer when the current bid < avg acquisition cost + fuel/unit + a per-unit floor; (b) between runs, the director prefers the buyer/good with the best *last realized* decayed margin — this encodes T3→T4 and H47→A2.
6. **[engine] Freshness-hold handling (the hour's clearest engine gap).** When `OperationPlanner::quote()` throws stale at the buy phase: (a) do not leave the run blocked awaiting a human — end the run safely at the next safe point, sell any owned cargo via the existing sell-only recovery (director step 6, extended to runs blocked for staleness, not only stopped runs); (b) permit the director to immediately create a *fresh* preview under the same grant if a ship/probe can observe the buyer (grant replaces approval — plan line 86–89 already allows this; state explicitly that a staleness block is a re-preview event, not a campaign pause); (c) never widen the 180 s window to make the problem disappear. Also cap units bought per quote to what the known tranche timing supports, so the first buy doesn't strand the second.
7. **[engine] Loss-cap and reserve checks must not fire mid-load.** Director step 4 (plan lines 79–81) runs *before* the active-run check (step 5): with a 135,735 CR load aboard, credits legitimately sit far below `start_credits − episode_loss_cap` and could approach reserve. Specify: evaluate `credits < start − loss_cap` and `credits < reserve` only when no run is active **and** no cargo is held, or on a settled basis (credits + cargo at conservative/decayed value); while a run is active, the run's own `reserve`/`max_loss` checks (already in `AutomationRunner.php:164`) govern. Otherwise the first scaled trade pauses the campaign with cargo aboard.
8. **[YAML] Discover phase: shorter, listing-oriented.** Change exit (line 52) from `{ minutes_elapsed_gte: 30, markets_observed_gte: 6 }` to `{ minutes_elapsed_gte: 15, markets_observed_gte: 3 }`, and note in the file that discovery's output is the source/buyer shortlist — execution quotes are re-observed at trade time because of the 180 s freshness rule (a 30-min discovery's prices are all stale by construction). Also add a comment that the `probe` role ship may be an immovable satellite (0 fuel): if the assigned scout ship cannot move, the director must assign scouting to `command` or use the probe's stationary observations. Do not assume role = capability.
9. **[YAML] Fix the registration example.** Line 43 says `faction: COSMIC`; the actual GIANTSOUP agent is VOID (`baseline.json`). Set the example to the faction Taylor actually intends for the new registration and add a comment that faction/starter fleet are chosen at registration and verified in the episode's first sync — the plan must not carry a wrong constant into a 13:00Z launch.
10. **[YAML] Predictions that can fail.** Line 62 (`credits gte 0` at 24 h) is unfalsifiable. Replace with learning predictions, e.g. `{ metric: cycles_completed, at_hours: 24, gte: 1 }`, `{ metric: idle_minutes, at_hours: 24, lte: 720 }`, and a credits prediction only as a *range recorded after the first closed cycle*, per the plan's "planning session fills these in" note. No profit forecast is justified by this experiment.
11. **[engine, small] Journal realised tranche decay.** `campaign_samples`/report (lines 96–103) should record per-cycle first-vs-last tranche prices and the decayed-vs-quoted margin, so next week's revision can set items 1/5 from data. The run accounting already captures receipts; this is a report field, not new gameplay logic.

**Flagged but out of scope / not in the plan:** ship purchases (hauler/probe), contract acceptance, and multi-ship concurrent runs remain human-approved future options per Taylor's constraints; §7 gives the measured conditions under which to *recommend* them. Nothing in this experiment justifies adding them to v1.

## 10. Evidence paths

- Experiment root: `C:\Users\taylo\AppData\Local\Temp\spacetraders-live-20261114\` — key files: `lcars-final-report.html`, `accounting-summary.json` (transactions ids 4–105), `hour-cutoff-reconciliation.json`, `decisions.tsv`, `baseline.json`, `checkpoint-hour-cutoff.json`, `checkpoint-final.json`, `checkpoint-1826.json` (price-floor block), `checkpoint-1848.json` / `checkpoint-1856.json` (freshness blocks), `a2-parts30-preview.json` (buyer quote 02:45:06Z, preview expiry), `h47-price-hold.json`, `parts40-stop-hold.json`, market snapshots `d39-market.json`, `d40-market.json`, `h47-fresh.json`, `a2-fresh-market.json`, `final-arrival-market.json`.
- Prior research: `C:\Users\taylo\Projects\spacetraders\docs\wipe-strategy-research-2026-11-14.md`, `docs\reports\wipe-strategy-2026-11-14.html`, `docs\reports\wipe-strategy-evidence-2026-11-14\`.
- Build contract under review: `C:\Users\taylo\Projects\spacetraders\docs\campaign-autopilot-plan.md` (branch `feature/campaign-autopilot`, HEAD f3ab23b — read only, unmodified).
- Trade code read: `app\Gameplay\OperationPlanner.php` (esp. :50-56 margin model, :104-106 spread block, :165-174 freshness), `app\Gameplay\OpportunityAssessor.php` (:155-194 one-batch sizing), `app\Gameplay\AutomationRunner.php` (:164 run reserve/loss, :258-264 ship-present-only quote refresh), `config\gameplay.php` (`snapshot_seconds=180`, `preview_seconds=120`).

## 11. Missing evidence / uncertainty

- No mining, contract, shipyard, siphon, refine, or jump-gate data of any kind.
- Wear/repair cost unmeasured (flagged unresolved in every run accounting block); settled net is cash-only.
- Per-cycle fuel allocation is partly shared (positioning/return legs serve consecutive cycles); only the 864 CR total is exact.
- Counterfactual prices (what A2 would have paid during T3, what H47 would have paid later) were unobserved at decision time; decay figures are measured, alternative-buyer losses are estimates.
- Buyer quotes' full `tradeGoods` tables for the exact preview moments are partly in preview JSONs rather than standalone market files; tranche prices from receipts are the harder evidence and were used instead.
- Whether the new reset's starter fleet includes a movable probe, a mining-capable frigate, or 175,000 CR is unknown until registration.
- `accounting-summary.json` was produced by the session's own `accounting.py`; this audit recomputed every total from its transaction list and against checkpoint cash balances (agreement exact), but the underlying checkpoint set itself is taken as the session record — no independent API-side ledger exists in the artifacts.
