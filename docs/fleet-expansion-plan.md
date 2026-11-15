# Fleet expansion plan (campaign v2)

Written 2026-11-15. Work starts Monday 2026-11-16. The goal is a first draft of v2 by
Sunday 2026-11-22.

## Goal

Earn 8 billion credits in a wipe. Over a 168-hour wipe that is an average gain of about
47.6M credits per hour. The one-hour trade test made 670,384 net with one command ship, so
the target needs roughly 70 ships doing that at once.

The local model (`docs/reports/leader-target-model.py`, results in
`docs/reports/weekly-earnings-results/leader-target.json`) reaches 8B only if the fleet
grows fast. These are modelled scenarios, not proven routes:

| Scenario | End of wipe | Haulers | Probes | Last purchase |
| --- | --- | --- | --- | --- |
| Planning (100k/hr per hauler) | 0.96B | 77 | 20 | hour 28 |
| Broader fleet (200k/hr) | 8.05B | 298 | 75 | hour 27 |
| Higher yield (300k/hr) | 8.04B | 182 | 46 | hour 14 |

In both 8B scenarios, nearly all the buying happens in the first 14–27 hours of the wipe.

## Decisions so far

- The wipe starting 2026-11-15T13:00Z runs campaign v1 with one ship while v2 is built.
- There is no hard credit floor. The engine may drop to very low credits when its own
  evaluation says the risk is worth it. "Risk it all" does not mean reckless play: the
  engine always picks the highest expected-value action allowed by that week's playbook.
- Ship purchases are now in scope. Contract acceptance is still out.
- The playbook still changes only through a reviewed, imported revision each week. No
  automatic tuning.

## Why v1 can't grow a fleet

1. **Purchases.** `CommandRules` allows only the one-time setup purchase of a starter miner
   and refuses anything after it ("further expansion is outside this MVP"). A ship bought on
   the SpaceTraders website shows up as an unexplained credit change, which pauses the
   campaign (`CampaignSafety`, `unexplained_change`).
2. **One run per agent.** `AutomationRunner` lets only one run be active per agent
   (`active_agent_id`). A run on a second ship blocks the campaign's run, and the other way
   round.
3. **Roles.** The playbook knows only `command` and `probe`. Other ships are ignored.

## Monday–Tuesday: groundwork

Build two features. Both are needed for the mid-week tests and both are part of v2.

1. **A manual "buy ship" command.** It is previewed and approved like any other command: it
   reads the shipyard, shows the price and the credits left afterwards, then buys. The
   purchase is recorded as a ship action, so credit accounting explains it. Then sync the
   new ship into the fleet.
2. **One active run per ship.** Change the rule from one active run per agent to one active
   run per ship. The campaign keeps its own ship, and manual or test runs can run on the
   other ships. Check whatever assumes one run per agent:
   - campaign handoff and stop
   - `CampaignControl`'s "between runs" check for replacing a grant
   - registration at the reset
   - the run-stop and resume path in deploys
   - the request budget

Tuning the single-ship v1 playbook from the first reports can happen alongside this.

## Wednesday–Friday: measure with a few ships

Taylor buys 2–3 haulers with the manual command. Run them with manual or bounded trade
runs and measure the inputs the model guesses at:

| Model assumption | How we measure it |
| --- | --- |
| Profit per hauler per hour (100k–300k) | Full round trips, fuel included |
| How fast prices fall when ships share a pair | Two ships on one trade pair, then on separate pairs |
| Requests used per ship per hour (12–30) | Count per ship from the request log |
| Ship price rise per purchase (1%) | Shipyard price before and after each buy |
| How much profit one system can absorb | Whether margins hold as more ships trade |
| How long a new ship takes to earn | Time from purchase to its first sale |

Write the results to `docs/reports/` and run the earnings model again with the measured
values.

## Friday–Saturday: council design of v2

Bring the measured numbers to the council (Claude Opus, GPT Sol, Muse Wonder) and decide:

1. **Buying rule.** When to buy a hauler or probe, based on realized profit per ship and the
   current price, for example payback within N hours of the time left in the wipe. Should it
   ever stop buying before the end of the wipe?
2. **Concurrency.** A director that runs one run per ship and hands out work each tick.
3. **Sharing trades between ships.** Ships claim different source/buyer/good pairs. The
   expected sale price accounts for the other ships selling the same good.
4. **Request budget.** The API allows 2 requests per second for the account, about 7,200 an
   hour. Share it out fairly, with trading ahead of scouting. That sets the maximum useful
   fleet size.
5. **Probe coverage.** How many probes per hauler (the model uses one per four), and where
   to place them.
6. **Other systems.** Whether jump gates are needed once one system's margins run out.
7. **Safety per ship.** Park a losing ship and retest its route. Pause the whole fleet only
   for accounting problems or uncertain writes.
8. **Playbook schema v2.** Fleet roles, buying settings, sharing settings and new gate
   metrics (fleet size, profit per hour), plus the weekly report fields that judge them.

## Sunday 2026-11-22: v2 first draft

- A written v2 design and playbook schema, agreed by the council.
- The groundwork from Monday–Tuesday merged and deployed.
- A decision on how much of v2 is built before the next wipe, and what the next wipe runs.

## Open questions

- Does the buying rule need a cap (maximum fleet size or payback limit), or is the payback
  rule alone enough?
- Haulers only, or a mix that includes miners or other ship types?
- Is a dry-run mode needed before v2 spends real credits?
