# MACSOUP fuel economics implementation

This development change implements the fuel calculation approved in planning round 3. It
separates economic estimates from the cash needed to keep the ship safe. It does not activate
the change, alter campaign authority, or establish live earnings.

## Calculation and selection

[TradeFuelCost](../../app/Gameplay/TradeFuelCost.php) provides whole-unit consumption pricing
and the bounded forecast and acceptance charges. For conservative outbound plus empty-return
burn, it rounds the aggregate once: `raw = ceil(burn / 100) * price`.

The price is the higher usable endpoint FUEL ask. Seller evidence must be non-invalidated,
positive, and within the existing six-hour planning bound. An endpoint without FUEL sales
adds no requirement. When neither endpoint has usable evidence, both charges retain the
legacy full-tank charge `L`.

- `expected_fuel_cost` is `E = min(raw, L)`. The forecast and proof denominator use E.
- `cycle_fuel_allowance` is `A = min(2E, L)`. Acceptance and ranking use A.
- `fuel_safety_ceiling` retains L. Director spending, cargo credit caps, command cash
  protection, and refueling ceilings retain the full safety amounts.

Ranking and `best_available` / `best_unclaimed` choose by the same conservative CR/minute
score and deterministic tie-breaker. Existing switch limits still apply. The reported best
margin remains the chosen candidate's forecast, explicitly labeled `best_margin_basis`.
Positioning cost and initial contribution are reported separately, without a new rejection
gate. Stale planning evidence doubles the full cash ceiling, without another multiplier on
E or A.

Preview and purchase checks share the calculation, including non-campaign trade previews.
Campaign previews reject E or A that changed since ranking, and grant authorization checks
that route and campaign values agree. Purchase checks recompute fuel economics from refreshed
evidence and block for replanning when E or A changes. Historical campaign
previews missing the new fields need a fresh preview before authorization. Active historical
plans retain the full old margin charge. Cargo remains aboard when further purchases block.

The existing value-floor and discover-exit thresholds also use the A-based score. Their
thresholds are unchanged, but reduced fuel charges can allow an earlier discover exit.
The shadow `optimal_margin` uses A, while `predicted_margin` uses E.

Confirmed purchase and sale receipts, posted fuel bills, and attributable travel consumption
remain authoritative for completed loads. Only whole-unit rounding is shared with the
unrefilled-fuel allowance. Historical accounting keeps its episode reset and does not acquire
a new planning freshness gate.

## Verification

The regression test failed before implementation because the director created no thin-spread
trade. [TradeFuelEconomicsTest](../../tests/Feature/TradeFuelEconomicsTest.php) now includes
26 cases. Its complete fake-HTTP flow starts with a director-created, grant-authorized plan
and uses real command preflight and the automation runner through buy, navigation, sale,
return, refuel, and completed-result accounting. No supplied purchase totals replace the buy
receipt.

The fixture buys 40 FUEL cargo units for 2,880 CR, sells them for 3,120 CR, and posts 72 CR of
refueling. Its forecast is 148 CR, acceptance margin 56 CR, and completed fake-receipt net
168 CR. The proof ratio is `168 / 148`, about 1.1351. Buying FUEL changes cargo without
changing the tank. Full route cash protection remains 576 CR, and buy preflight retains the
736 CR outbound reserve. This proves local integration against a fake server, not game-server
execution or future income.

Additional cases cover non-FUEL buyers, positioning from a non-FUEL starting point, missing
endpoint prices, six-hour and invalidation bounds, stale planning versus execution freshness,
a 396-fuel route capped at the old 576 CR allowance, aggregate rounding, insufficient
spending or fuel, deteriorated quotes, partial loads, historical plans, historical accounting,
and consistent selection. Existing campaign tests cover recoveries and full cash protection.

The coordinator also replayed protected database copies using this worktree's real classes,
isolated storage, disabled automation, blocked stray HTTP, a pinned clock, and rolled-back
transactions. Credential-bearing databases remain outside the repository.

- Before implementation, the November 19 03:24:17Z snapshot had zero candidates: 749
  nonpositive spreads, 55 fuel-continuation failures, and 46 lot-profit failures. Refreshing
  only A4/H49 timestamps still produced zero candidates.
- After implementation, that timestamp-only fixture produces six provisional candidates.
  A4/H49 passes assertions for E=92, A=184, forecast=148, acceptance=56, full route cash=576,
  and a local next-step buy instruction.
- A separate read-only backup taken at 03:57:25Z, replayed at 03:57:26Z with unchanged
  observations, produces six provisional candidates. A4/H49 retains E=92 and A=184, while
  stale planning fuel evidence raises its full cash ceiling to 1,152. Its full preview refuses
  stale executable prices. No game action was sent.

Full-suite and council results are recorded in the final review handoff. Relevant checks are
`vendor/bin/phpunit`, Pint over the changed PHP files, and `git diff --check`.

Implementation council round 1 returned Opus APPROVE, Muse APPROVE, and Sol CHANGES REQUIRED.
Sol proved that preview-time quote refresh could change route E/A while retaining the older
campaign forecast. Two new regressions failed before the correction. Preview now rejects the
changed ranking inputs, and authorization rejects inconsistent route and campaign values.
The corrected version requires a fresh full council round.

Strict replanning on any E/A change also applies to a price decrease or a trade's own earlier
FUEL purchase. A multi-batch load whose source FUEL ask changes can therefore retain partial
cargo and enter the existing recovery path. This conservative behavior follows the approved
plan. The fake server does not establish real within-load price movement. Only the latest
endpoint observation is used; an invalidated latest quote cannot revive an older quote.
These limits do not relax spending or physical continuation checks.

## Remaining activation choices

Production source, database, credentials, campaign, engine, schedule, and ship actions were
not changed by implementation. The existing campaign continues under its prior authority.
The probe/command active-run ownership guard remains in place. No new purchasing or
three-load baseline policy was added.

The remaining choices are separate:

1. Approve a Mac deployment and rollback procedure, then any merge and deployment. The
   repository's Windows deployment instructions do not establish a Mac procedure.
2. Approve any maintenance pause or resume needed for activation. Before activation, obtain
   fresh same-reset observations and require a positive candidate, passing full preview,
   protected cash, and safe physical route on a copy. Stale fixture prices are insufficient.
3. Choose existing-grant activation scope or request an enforceable one-load stop. Ordinary
   resume is not limited to one load, and a passing proof can permit scaling under the grant.
4. Review completed, fuel-inclusive live results before any separately approved ship purchase
   or arm. The proposed three-load plus 60-minute evidence gate still needs Taylor's agreement.

[Relocation](macsoup-relocation-2026-11-19.md) is a distinct fallback. No destination has
verified better markets, and the current local jump guard blocks these ships. No relocation
feature or travel is included. Thin margins and repeat-load price decay remain material
limits even if the fuel correction is later activated.
